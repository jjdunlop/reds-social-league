<?php
/**
 * Queued email. Anything that can go to many people at once (placement
 * confirmations, request and invite updates) is queued here and sent in
 * small batches by cron, so one click never tries to send a hundred emails
 * inside a page load. Each message has a dedupe key (UNIQUE): queueing the
 * same message twice does nothing, so actions are safe to repeat.
 *
 * Stored as plain text. Sent in the club's email look (the Club Manager's
 * TeamOversight_Email) when it's there, else as plain text; send_now() is
 * the one send path, also used by the few emails sent straight away.
 * Sent rows are pruned after 30 days.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RSL_Mail {

    const BATCH = 25;
    const MAX_ATTEMPTS = 3;
    const KEEP_DAYS = 30;
    const SEND_HOOK = 'rsl_send_outbox';

    /** Shown above the heading in the club look. */
    const PROGRAM = 'Reds Social League';
    /** Dropped from the start of a subject for the heading. */
    const SUBJECT_PREFIX = '[Social League] ';
    /** A "Label:" line longer than this stays as text above the button. */
    const BUTTON_LABEL_MAX = 40;
    const PREHEADER_MAX = 120;

    /** The last send_now() failure message, for the outbox row ('' after a success). */
    private static $last_error = '';

    /**
     * Queue one email. $to is a user id or an email address. Returns the row
     * id, 0 when it was already queued (same dedupe key), or WP_Error.
     */
    public static function queue($to, $subject, $body, $dedupe) {
        global $wpdb;
        $user_id = null;
        if (is_numeric($to)) {
            $user = get_userdata(intval($to));
            if (!$user) {
                return new WP_Error('rsl_mail_user', 'No account to email.');
            }
            $user_id = (int) $user->ID;
            $email = $user->user_email;
        } else {
            $email = sanitize_email($to);
        }
        if (!$email || !is_email($email)) {
            return new WP_Error('rsl_mail_email', 'No email address to send to.');
        }
        $dedupe = (string) $dedupe;
        if ($dedupe === '') {
            $dedupe = 'once:' . wp_generate_uuid4();
        }
        if (strlen($dedupe) > 120) {
            $dedupe = substr($dedupe, 0, 80) . ':' . md5($dedupe);
        }
        $done = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO " . RSL_Database::table('outbox') . " (user_id, to_email, subject, body, dedupe, created_at) VALUES (%d, %s, %s, %s, %s, %s)",
            (int) $user_id, $email, wp_strip_all_tags(substr($subject, 0, 255)), $body, $dedupe, current_time('mysql')
        ));
        if (!$done) {
            return 0;
        }
        self::schedule_send();
        return (int) $wpdb->insert_id;
    }

    /** Make sure a send run is on its way (soon, not inside this request). */
    public static function schedule_send($delay = 0) {
        if (!wp_next_scheduled(self::SEND_HOOK)) {
            wp_schedule_single_event(time() + max(0, intval($delay)), self::SEND_HOOK);
        }
    }

    /**
     * Send up to $limit waiting emails. Each row is claimed (attempts + 1)
     * before sending, so two overlapping runs never send the same one.
     * Returns array(sent, failed). Schedules another run while mail waits.
     */
    public static function drain($limit = self::BATCH) {
        global $wpdb;
        $table = RSL_Database::table('outbox');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE sent_at IS NULL AND attempts < %d ORDER BY id LIMIT %d",
            self::MAX_ATTEMPTS, max(1, intval($limit))
        ));
        $sent = 0;
        $failed = 0;
        foreach ($rows as $row) {
            $claimed = $wpdb->query($wpdb->prepare(
                "UPDATE $table SET attempts = attempts + 1 WHERE id = %d AND sent_at IS NULL AND attempts = %d",
                $row->id, $row->attempts
            ));
            if (!$claimed) {
                continue;
            }
            if (self::send_now($row->to_email, $row->subject, $row->body)) {
                $wpdb->update($table, array('sent_at' => current_time('mysql'), 'last_error' => ''), array('id' => $row->id));
                $sent++;
            } else {
                $wpdb->update($table, array('last_error' => substr(self::$last_error !== '' ? self::$last_error : 'wp_mail returned false', 0, 255)), array('id' => $row->id));
                $failed++;
            }
        }
        if (self::counts()['queued'] > 0) {
            self::schedule_send(60);
        }
        return array($sent, $failed);
    }

    public static function note_failure($error) {
        self::$last_error = is_wp_error($error) ? $error->get_error_message() : 'Mail failed';
    }

    // ------------------------------------------------------------------
    // Sending (the club look)
    // ------------------------------------------------------------------

    /**
     * Can mail go out in the club look? The Club Manager's email class must
     * be there; the filter can only switch it off (tests use it for the
     * plain path).
     */
    public static function club_look_available() {
        return class_exists('TeamOversight_Email')
            && method_exists('TeamOversight_Email', 'render')
            && method_exists('TeamOversight_Email', 'send')
            && (bool) apply_filters('rsl_mail_club_look', true);
    }

    /**
     * Send one email now: the club look when available, else wp_mail()
     * plain text exactly as before. $to is an address or an array of them.
     * Never throws: false on failure, with the reason in last_error().
     */
    public static function send_now($to, $subject, $body) {
        self::$last_error = '';
        add_action('wp_mail_failed', array(__CLASS__, 'note_failure'));
        try {
            if (self::club_look_available()) {
                $email = TeamOversight_Email::render(self::club_email($subject, $body, array(
                    'labels' => self::button_labels(),
                    'why' => self::why($to),
                )));
                $ok = TeamOversight_Email::send($to, $subject, $email, self::club_headers($to));
            } else {
                $ok = wp_mail($to, $subject, $body);
            }
        } catch (Throwable $t) {
            $ok = false;
            self::$last_error = 'Mail failed: ' . $t->getMessage();
        }
        remove_action('wp_mail_failed', array(__CLASS__, 'note_failure'));
        if (!$ok && self::$last_error === '') {
            self::$last_error = 'wp_mail returned false';
        }
        if ($ok) {
            self::$last_error = '';
        }
        return (bool) $ok;
    }

    /** The last send_now() failure message, '' after a success. */
    public static function last_error() {
        return self::$last_error;
    }

    /**
     * Pure: one plain league email (paragraphs at blank lines, addresses on
     * their own lines) as TeamOversight_Email::render() arguments. The
     * first address alone on its line becomes the one button (never an
     * invite link: it's for sharing, so it stays visible), named by a short
     * "Label:" line just above it, else by $args['labels'][address], else
     * "Open". The heading is the subject without the [Social League]
     * prefix. No escaping here: render() does it all.
     *
     * $args: labels (address => button label), why (the footer line).
     */
    public static function club_email($subject, $body, $args = array()) {
        $args = array_merge(array('labels' => array(), 'why' => ''), is_array($args) ? $args : array());
        $labels = is_array($args['labels']) ? $args['labels'] : array();

        $text = trim(str_replace(array("\r\n", "\r"), "\n", (string) $body));
        $lines = $text === '' ? array() : explode("\n", $text);
        $button = null;
        foreach ($lines as $i => $line) {
            $address = trim($line);
            if (!preg_match('/^https?:\/\/\S+$/i', $address) || preg_match('/[?&]join=/i', $address)) {
                continue;
            }
            $label = '';
            $label_line = -1;
            if ($i > 0 && trim($lines[$i - 1]) !== '') {
                $before = trim($lines[$i - 1]);
                if (substr($before, -1) === ':') {
                    $words = trim(substr($before, 0, -1));
                    if ($words !== '' && self::length($words) <= self::BUTTON_LABEL_MAX) {
                        $label = $words;
                        $label_line = $i - 1;
                    }
                }
            }
            if ($label === '') {
                $label = isset($labels[$address]) && trim((string) $labels[$address]) !== '' ? (string) $labels[$address] : 'Open';
            }
            $lines[$i] = '{button}';
            if ($label_line >= 0) {
                unset($lines[$label_line]);
            }
            $button = array('url' => $address, 'label' => $label);
            break;
        }
        $text = implode("\n", $lines);

        $subject = (string) $subject;
        $heading = strpos($subject, self::SUBJECT_PREFIX) === 0 ? substr($subject, strlen(self::SUBJECT_PREFIX)) : $subject;

        return array(
            'program' => self::PROGRAM,
            'heading' => trim($heading),
            'body' => $text,
            'vars' => array(),
            'blocks' => array(),
            'button' => $button,
            'preheader' => self::preheader($text),
            'why' => (string) $args['why'],
        );
    }

    /**
     * The line inboxes show after the subject: the first paragraph that
     * isn't the greeting ("Hi Alex,"), the button or an address, on one
     * line, cut at a space.
     */
    private static function preheader($text) {
        foreach (preg_split('/\n[ \t]*\n/', $text) as $paragraph) {
            $lines = array();
            foreach (explode("\n", trim($paragraph)) as $line) {
                if (trim($line) !== '' && trim($line) !== '{button}') {
                    $lines[] = trim($line);
                }
            }
            if (!$lines || stripos($lines[0], 'http') === 0) {
                continue;
            }
            if (count($lines) === 1 && strpos($lines[0], 'Hi ') === 0 && substr($lines[0], -1) === ',') {
                continue;
            }
            $words = trim(preg_replace('/\s+/u', ' ', implode(' ', $lines)));
            if (self::length($words) <= self::PREHEADER_MAX) {
                return $words;
            }
            $cut = function_exists('mb_substr') ? mb_substr($words, 0, self::PREHEADER_MAX - 1, 'UTF-8') : substr($words, 0, self::PREHEADER_MAX - 1);
            $space = strrpos($cut, ' ');
            if ($space !== false && $space > 0) {
                $cut = substr($cut, 0, $space);
            }
            return rtrim($cut, " ,;:-") . '…';
        }
        return '';
    }

    private static function length($text) {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    /** The league's own addresses and what their button says. */
    private static function button_labels() {
        $labels = array();
        if (class_exists('RSL_Portal')) {
            $labels[(string) RSL_Portal::url()] = 'Open your league page';
        }
        if (class_exists('RSL_Ref_Page')) {
            $labels[(string) RSL_Ref_Page::url()] = 'Open Ref Jobs';
        }
        if (class_exists('RSL_Rego') && method_exists('RSL_Rego', 'pay_url')) {
            // The yearly link only while it's the one product on offer (emails never pick a season).
            $pay = (string) RSL_Rego::pay_url();
            if ($pay !== '') {
                $labels[$pay] = 'Pay your rego';
            }
        }
        unset($labels['']);
        return $labels;
    }

    /** The footer's "why you're getting this" line. */
    private static function why($to) {
        return self::all_contacts($to)
            ? 'You\'re getting this as a Reds Social League contact.'
            : 'You\'re getting this because you\'re registered for the Reds Social League.';
    }

    /** Is every recipient a league contact (as RSL_Free_Agents::league_contacts() says)? */
    private static function all_contacts($to) {
        $recipients = self::recipients($to);
        if (!$recipients || !class_exists('RSL_Free_Agents')) {
            return false;
        }
        $contacts = array_map('strtolower', array_map('trim', RSL_Free_Agents::league_contacts()));
        foreach ($recipients as $address) {
            if (!in_array(strtolower($address), $contacts, true)) {
                return false;
            }
        }
        return true;
    }

    /** The bare addresses in a wp_mail() $to (a string with commas, or an array). */
    private static function recipients($to) {
        $out = array();
        foreach (is_array($to) ? $to : explode(',', (string) $to) as $one) {
            $one = trim((string) $one);
            if (preg_match('/<([^<>]+)>\s*$/', $one, $m)) {
                $one = trim($m[1]);
            }
            if ($one !== '') {
                $out[] = $one;
            }
        }
        return $out;
    }

    /**
     * The club look's headers: the Club Manager's From line (never its
     * Reply-To; the league names no sender of its own), then Reply-To the
     * league contact emails as set on Team Balances → Rules (not the site
     * admin fallback), left off when every recipient is a league contact.
     */
    private static function club_headers($to) {
        $headers = array();
        if (class_exists('TeamOversight_Payments') && method_exists('TeamOversight_Payments', 'get_email_headers')) {
            foreach ((array) TeamOversight_Payments::get_email_headers() as $header) {
                if (stripos((string) $header, 'From:') === 0) {
                    $headers[] = (string) $header;
                }
            }
        }
        $reply_to = array();
        if (class_exists('RSL_Payments')) {
            foreach (explode(',', (string) RSL_Payments::settings()['contact_emails']) as $email) {
                $email = sanitize_email(trim($email));
                if ($email !== '' && is_email($email)) {
                    $reply_to[] = $email;
                }
            }
        }
        $reply_to = array_values(array_unique($reply_to));
        if ($reply_to && !self::all_contacts($to)) {
            $headers[] = 'Reply-To: ' . implode(', ', $reply_to);
        }
        return $headers;
    }

    /** Delete sent emails older than KEEP_DAYS (they hold names and emails). */
    public static function prune() {
        global $wpdb;
        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM " . RSL_Database::table('outbox') . " WHERE sent_at IS NOT NULL AND sent_at < %s",
            date('Y-m-d H:i:s', current_time('timestamp') - self::KEEP_DAYS * DAY_IN_SECONDS)
        ));
    }

    /** queued (still to try), failed (gave up), sent (kept for KEEP_DAYS). */
    public static function counts() {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT SUM(sent_at IS NULL AND attempts < %d) AS queued, SUM(sent_at IS NULL AND attempts >= %d) AS failed, SUM(sent_at IS NOT NULL) AS sent FROM " . RSL_Database::table('outbox'),
            self::MAX_ATTEMPTS, self::MAX_ATTEMPTS
        ));
        return array('queued' => (int) ($row ? $row->queued : 0), 'failed' => (int) ($row ? $row->failed : 0), 'sent' => (int) ($row ? $row->sent : 0));
    }

    /** Emails that gave up, newest first (admin outbox panel). */
    public static function failed($limit = 20) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, to_email, subject, attempts, last_error, created_at FROM " . RSL_Database::table('outbox') . " WHERE sent_at IS NULL AND attempts >= %d ORDER BY id DESC LIMIT %d",
            self::MAX_ATTEMPTS, max(1, intval($limit))
        ));
    }

    /** Give failed emails another go. */
    public static function retry_failed() {
        global $wpdb;
        $n = (int) $wpdb->query($wpdb->prepare(
            "UPDATE " . RSL_Database::table('outbox') . " SET attempts = 0 WHERE sent_at IS NULL AND attempts >= %d",
            self::MAX_ATTEMPTS
        ));
        if ($n) {
            self::schedule_send();
        }
        return $n;
    }
}
