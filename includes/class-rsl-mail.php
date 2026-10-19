<?php
/**
 * Queued email. Anything that can go to many people at once (placement
 * confirmations, request and invite updates) is queued here and sent in
 * small batches by cron, so one click never tries to send a hundred emails
 * inside a page load. Each message has a dedupe key (UNIQUE): queueing the
 * same message twice does nothing, so actions are safe to repeat.
 *
 * Plain text, through wp_mail. Sent rows are pruned after 30 days.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RSL_Mail {

    const BATCH = 25;
    const MAX_ATTEMPTS = 3;
    const KEEP_DAYS = 30;
    const SEND_HOOK = 'rsl_send_outbox';

    /** Last wp_mail failure message (wp_mail_failed), for the outbox row. */
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
        add_action('wp_mail_failed', array(__CLASS__, 'note_failure'));
        foreach ($rows as $row) {
            $claimed = $wpdb->query($wpdb->prepare(
                "UPDATE $table SET attempts = attempts + 1 WHERE id = %d AND sent_at IS NULL AND attempts = %d",
                $row->id, $row->attempts
            ));
            if (!$claimed) {
                continue;
            }
            self::$last_error = '';
            if (wp_mail($row->to_email, $row->subject, $row->body)) {
                $wpdb->update($table, array('sent_at' => current_time('mysql'), 'last_error' => ''), array('id' => $row->id));
                $sent++;
            } else {
                $wpdb->update($table, array('last_error' => substr(self::$last_error !== '' ? self::$last_error : 'wp_mail returned false', 0, 255)), array('id' => $row->id));
                $failed++;
            }
        }
        remove_action('wp_mail_failed', array(__CLASS__, 'note_failure'));
        if (self::counts()['queued'] > 0) {
            self::schedule_send(60);
        }
        return array($sent, $failed);
    }

    public static function note_failure($error) {
        self::$last_error = is_wp_error($error) ? $error->get_error_message() : 'Mail failed';
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
