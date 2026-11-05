<?php
/**
 * Admin: Social League → Free Agents. One season at a time (default: the
 * sign-up season — the one people are joining), in three tabs:
 *  - Overview: where the season is (dates, phase), counts, everyone on the
 *    list with the admin-only fields (band override, under 18, a note) and
 *    "Place now", how full each team is, and the email outbox.
 *  - Placement: make a proposal (RSL_Placement), adjust it — move people (a
 *    friend group moves together, and a move is kept on re-runs until
 *    "Start over"), name the new teams and pick their captains — then
 *    confirm it all, or one block at a time (a team, or telling those not
 *    placed). Every change carries the draft version, so a second admin's
 *    stale tab is refused instead of quietly overwriting the first.
 *  - Settings: the free agent rules, this season's date overrides, and the
 *    sign-up season pin.
 * Every change posts to admin-post.php (nonce + manage_options) and
 * redirects back with a notice. Names, emails and the admin-only fields
 * appear here because only admins see this screen — none of it reaches
 * captains (they only ever get RSL_Free_Agents::browse_for_captain()).
 */

if (!defined('ABSPATH')) {
    exit;
}

class RSL_Admin_Free_Agents {

    const PAGE = 'rsl-free-agents';
    const ACTION = 'rsl_fa_action';
    const TABS = array('overview' => 'Overview', 'placement' => 'Placement', 'settings' => 'Settings');
    const STALE = 'Someone else changed the draft — reload. This page now shows the latest version; your change wasn\'t saved, so make it again if it\'s still needed.';

    public function __construct() {
        add_action('admin_menu', array($this, 'menu'), 11);
        add_action('admin_post_' . self::ACTION, array($this, 'handle'));
    }

    public function menu() {
        $n = current_user_can('manage_options') ? self::waiting_count() : 0;
        $bubble = $n ? ' <span class="awaiting-mod">' . intval($n) . '</span>' : '';
        add_submenu_page(RSL_Admin::PAGE, 'Free Agents', 'Free Agents' . $bubble, 'manage_options', self::PAGE, array($this, 'render'));
    }

    /**
     * Free agents waiting on an admin, for the menu bubble: everyone still
     * looking once the deadline has passed, and late joiners once the season
     * is on. Nothing before the deadline — captains and players are still
     * matching themselves, so there's nothing for an admin to do yet.
     */
    public static function waiting_count() {
        $season = RSL_Seasons::signup();
        if (!in_array(RSL_Free_Agents::phase($season), array('placing', 'season'), true)) {
            return 0;
        }
        $counts = RSL_Free_Agents::counts($season);
        return isset($counts['looking']) ? (int) $counts['looking'] : 0;
    }

    public function handle() {
        wp_safe_redirect(self::process($_POST));
        exit;
    }

    public static function url($args = array()) {
        return add_query_arg($args, admin_url('admin.php?page=' . self::PAGE));
    }

    // ------------------------------------------------------------------
    // Actions
    // ------------------------------------------------------------------

    /**
     * Do one action from posted fields; returns the URL to go back to.
     * Separate from handle() (which exits) so it can be tested.
     */
    public static function process($post) {
        $season = isset($post['season']) && is_scalar($post['season']) ? sanitize_text_field(wp_unslash($post['season'])) : '';
        $season = RSL_Seasons::is_valid($season) ? $season : RSL_Seasons::signup();
        $tab = isset($post['tab']) && is_scalar($post['tab']) ? sanitize_key($post['tab']) : '';
        $tab = isset(self::TABS[$tab]) ? $tab : 'overview';
        $back = self::url(array('season' => $season, 'tab' => $tab));

        if (!current_user_can('manage_options') || !isset($post['rsl_nonce']) || !wp_verify_nonce($post['rsl_nonce'], self::ACTION)) {
            self::notice('error', 'Security check failed — please try again.');
            return $back;
        }

        $me = get_current_user_id();
        $do = isset($post['do']) ? sanitize_key($post['do']) : '';
        $user_id = isset($post['user_id']) ? intval($post['user_id']) : 0;
        $text = function ($key) use ($post) {
            return isset($post[$key]) && is_scalar($post[$key]) ? trim(sanitize_text_field(wp_unslash($post[$key]))) : '';
        };
        if (in_array($do, array('suggest', 'move', 'update_new', 'confirm'), true) && !class_exists('RSL_Placement')) {
            self::notice('error', 'The placement engine isn\'t loaded, so placements can\'t be changed here.');
            return $back;
        }

        switch ($do) {
            case 'save_agent':
                $row = RSL_Free_Agents::get_by_id(isset($post['fa_id']) ? intval($post['fa_id']) : 0);
                if (!$row || $row->season !== $season) {
                    self::notice('error', 'That free agent isn\'t on this season\'s list any more.');
                    return $back;
                }
                $band = $text('band_override');
                if ($band !== '' && (!is_numeric($band) || (float) $band < 1 || (float) $band > 5)) {
                    self::notice('error', 'The band is a number from 1 to 5 (halves allowed) — or leave it blank to use their answers.');
                    return $back . '#fa-' . intval($row->id);
                }
                $r = RSL_Free_Agents::admin_update($row->id, array(
                    'band_override' => $band,
                    'admin_note' => $text('admin_note'),
                    'under18' => empty($post['under18']) ? 0 : 1,
                ));
                self::result($r, 'Saved ' . self::name($row->user_id) . '.');
                return $back . '#fa-' . intval($row->id);

            case 'place_now':
                $team = RSL_Teams::get(isset($post['team_id']) ? intval($post['team_id']) : 0);
                if (!$team) {
                    self::notice('error', 'Pick a team to place them on.');
                    return $back . '#rsl-fa-pool';
                }
                $friends = max(0, count(RSL_Free_Agents::unit_rows(RSL_Free_Agents::get($user_id, $team->season))) - 1);
                $r = RSL_Free_Agents::place_now($user_id, $team->id, $me);
                if (is_wp_error($r)) {
                    self::notice('error', $r->get_error_message());
                } elseif ($r === 'invited') {
                    // The invite goes to whoever hasn't agreed to the division — in a group, maybe a friend.
                    self::notice('success', ($friends > 0 ? 'Someone in ' . self::name($user_id) . '\'s friend group hasn\'t' : self::name($user_id) . ' hasn\'t')
                        . ' agreed to play in ' . self::division_label($team->division) . ', so instead of being placed '
                        . ($friends > 0 ? 'that person has' : 'they\'ve') . ' been sent an invite to ' . $team->name . ' to accept'
                        . ($friends > 0 ? ' (accepting brings the group)' : '') . '.');
                } else {
                    self::notice('success', self::name($user_id) . self::with_friends($friends) . ' is now on ' . $team->name . '. They and the captain have been emailed each other\'s details.');
                }
                return $back . '#rsl-fa-pool';

            case 'send_mail':
                list($sent, $failed) = RSL_Mail::drain();
                self::notice($failed ? 'error' : 'success', self::mail_words($sent, $failed));
                return $back . '#rsl-fa-outbox';

            case 'retry_failed':
                $n = RSL_Mail::retry_failed();
                if (!$n) {
                    self::notice('success', 'There are no failed emails to retry.');
                    return $back . '#rsl-fa-outbox';
                }
                list($sent, $failed) = RSL_Mail::drain();
                self::notice($failed ? 'error' : 'success', 'Trying ' . self::plural($n, 'failed email') . ' again. ' . self::mail_words($sent, $failed));
                return $back . '#rsl-fa-outbox';

            case 'suggest':
                // fresh = "Start over": forget every admin move (the locks) as well.
                $fresh = !empty($post['fresh']);
                $summary = RSL_Placement::suggest($season, !$fresh);
                if (is_wp_error($summary)) {
                    self::notice('error', $summary->get_error_message());
                    return $back;
                }
                $words = self::summary_text($summary);
                self::notice('success', ($fresh ? 'Started over — your moves are forgotten. Proposal made' : 'Proposal made') . ($words !== '' ? ' — ' . $words : '') . '. Check it, adjust it, then confirm.');
                return $back;

            case 'move':
                $dest = $text('dest');
                if (!preg_match('/^(t:\d+|n:[A-Za-z0-9_-]{1,20}|none)$/', $dest)) {
                    self::notice('error', 'Pick where to move them.');
                    return $back . '#fa-u' . $user_id;
                }
                $version = self::posted_version($post);
                if (self::stale($season, $version)) {
                    self::notice('error', self::STALE);
                    return $back;
                }
                $friends = max(0, count(RSL_Free_Agents::unit_rows(RSL_Free_Agents::get($user_id, $season))) - 1);
                $r = RSL_Placement::move($season, $user_id, $dest, $version);
                if (is_wp_error($r)) {
                    self::notice('error', self::stale($season, $version) ? self::STALE : $r->get_error_message());
                    return $back . '#fa-u' . $user_id;
                }
                self::notice('success', 'Moved ' . self::name($user_id) . self::with_friends($friends) . '. The move is kept if you re-run the proposal ("Start over" forgets it).');
                return $back . '#fa-u' . $user_id;

            case 'update_new':
                $ref = $text('ref');
                $name = $text('name');
                $slug = function ($k) use ($post) {
                    return isset($post[$k]) && is_scalar($post[$k]) ? sanitize_key(wp_unslash($post[$k])) : '';
                };
                $level = $slug('est_level');
                if (!preg_match('/^[A-Za-z0-9_-]{1,20}$/', $ref)) {
                    self::notice('error', 'That new team isn\'t in the draft any more.');
                    return $back;
                }
                if ($name === '') {
                    self::notice('error', 'Give the new team a name.');
                    return $back . '#fa-new-' . $ref;
                }
                if (!isset(RSL_Teams::levels()[$level])) {
                    self::notice('error', 'Pick the new team\'s level.');
                    return $back . '#fa-new-' . $ref;
                }
                $version = self::posted_version($post);
                if (self::stale($season, $version)) {
                    self::notice('error', self::STALE);
                    return $back;
                }
                // Only what the admin changed from what the form showed: an untouched
                // placeholder name doesn't block picking a captain, an untouched level
                // keeps following the players, and no radio means no captain change.
                $data = array();
                if ($name !== $text('name_was')) {
                    $data['name'] = $name;
                }
                if ($level !== $slug('level_was')) {
                    $data['est_level'] = $level;
                }
                $captain = isset($post['captain_user_id']) && is_scalar($post['captain_user_id']) ? intval($post['captain_user_id']) : 0;
                if ($captain > 0 && $captain !== (isset($post['captain_was']) && is_scalar($post['captain_was']) ? intval($post['captain_was']) : 0)) {
                    $data['captain_user_id'] = $captain;
                }
                if (!$data) {
                    self::notice('success', 'Nothing to save — change the name, the level or the captain first.');
                    return $back . '#fa-new-' . $ref;
                }
                $r = RSL_Placement::update_new($season, $ref, $data, $version);
                if (is_wp_error($r)) {
                    self::notice('error', self::stale($season, $version) ? self::STALE : $r->get_error_message());
                } else {
                    $what = array_intersect_key(array('name' => 'name', 'est_level' => 'level', 'captain_user_id' => 'captain'), $data);
                    self::notice('success', 'Saved the ' . self::and_list(array_values($what)) . ' of ' . $name . '.');
                }
                return $back . '#fa-new-' . $ref;

            case 'confirm':
                $only = $text('only');
                if ($only !== '' && !preg_match('/^(t:\d+|n:[A-Za-z0-9_-]{1,20}|none)$/', $only)) {
                    self::notice('error', 'That part of the draft isn\'t there any more — reload and try again.');
                    return $back;
                }
                // Confirm the draft the admin was looking at, not one someone changed since.
                if (self::stale($season, self::posted_version($post))) {
                    self::notice('error', self::STALE);
                    return $back;
                }
                $r = RSL_Placement::confirm($season, $only === '' ? null : $only, $me);
                if (is_wp_error($r)) {
                    self::notice('error', implode(' ', $r->get_error_messages()));
                    return $back;
                }
                // A new team that couldn't be made (say, a captain took the name a moment ago)
                // comes back in 'errors' — say so plainly, never a green "Confirmed".
                $errors = is_array($r) && !empty($r['errors']) ? array_filter(array_map('strval', (array) $r['errors'])) : array();
                if ($errors) {
                    self::notice('error', 'Not everything was confirmed. ' . implode(' ', $errors) . ' Fix that and confirm again. ' . self::confirm_words($r, 'What was done') . self::over_words($season, $r));
                    return $back;
                }
                self::notice('success', self::confirm_words($r) . self::over_words($season, $r));
                return $back;

            case 'save_settings':
                $input = array();
                foreach (RSL_Free_Agents::settings() as $key => $value) {
                    if (!isset($post[$key])) {
                        continue;
                    }
                    if (is_array($post[$key])) {
                        $input[$key] = array();
                        foreach ($post[$key] as $k => $v) {
                            if (is_scalar($v)) {
                                $input[$key][sanitize_key($k)] = sanitize_text_field(wp_unslash($v));
                            }
                        }
                    } else {
                        $input[$key] = $text($key);
                    }
                }
                $r = RSL_Free_Agents::save_settings($input);
                $changed = is_wp_error($r) ? array() : self::adjusted_settings($input, $r);
                if ($changed) {
                    // save_settings() keeps each number in its allowed range; say which ones it moved (red, so it isn't skimmed past).
                    self::notice('error', 'Rules saved, but not quite as typed — some numbers were outside what\'s allowed, so the nearest allowed value was saved: ' . implode('; ', $changed) . '. Check them below.');
                    return $back;
                }
                self::result($r, 'Rules saved. Each season\'s dates are worked out from its first game night, unless you set them for that season.');
                return $back;

            case 'save_dates':
                $clear = !empty($post['clear']);
                $r = RSL_Free_Agents::save_dates($season, $clear ? '' : $text('opens'), $clear ? '' : $text('deadline'), $clear ? '' : $text('confirm_by'));
                self::result($r, $clear
                    ? 'Dates for ' . RSL_Seasons::label($season) . ' are worked out from its first game night again.'
                    : 'Dates saved for ' . RSL_Seasons::label($season) . '.');
                return $back;

            case 'set_signup':
                $key = $text('signup');
                if ($key !== '' && !RSL_Seasons::is_valid($key)) {
                    self::notice('error', 'Pick a season.');
                    return $back;
                }
                RSL_Seasons::set_signup($key);
                self::notice('success', $key === ''
                    ? 'The sign-up season is automatic again — right now it\'s ' . RSL_Seasons::label(RSL_Seasons::signup()) . '.'
                    : RSL_Seasons::label($key) . ' is now the sign-up season: joining, applying for a team and the free agent list all use it.');
                return $back;
        }

        self::notice('error', 'Unknown action.');
        return $back;
    }

    private static function posted_version($post) {
        return isset($post['version']) && is_scalar($post['version']) ? intval($post['version']) : -1;
    }

    /** Has the draft moved on since this form was drawn? */
    private static function stale($season, $version) {
        return intval($version) !== intval(RSL_Free_Agents::building($season)['draft_version']);
    }

    private static function result($r, $ok) {
        self::notice(is_wp_error($r) ? 'error' : 'success', is_wp_error($r) ? $r->get_error_message() : $ok);
    }

    private static function notice($type, $message) {
        set_transient(RSL_Admin::NOTICE_PREFIX . get_current_user_id(), array('type' => $type, 'message' => $message), 60);
    }

    private static function take_notice() {
        $key = RSL_Admin::NOTICE_PREFIX . get_current_user_id();
        $n = get_transient($key);
        delete_transient($key);
        return is_array($n) ? '<div class="notice notice-' . ($n['type'] === 'error' ? 'error' : 'success') . ' is-dismissible"><p>' . esc_html($n['message']) . '</p></div>' : '';
    }

    private static function fields($do, $season, $tab, $extra = array()) {
        $html = wp_nonce_field(self::ACTION, 'rsl_nonce', true, false)
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
            . '<input type="hidden" name="do" value="' . esc_attr($do) . '">'
            . '<input type="hidden" name="season" value="' . esc_attr($season) . '">'
            . '<input type="hidden" name="tab" value="' . esc_attr($tab) . '">';
        foreach ($extra as $k => $v) {
            $html .= '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
        }
        return $html;
    }

    // ------------------------------------------------------------------
    // Words
    // ------------------------------------------------------------------

    private static function plural($n, $one, $many = '') {
        return intval($n) . ' ' . (intval($n) === 1 ? $one : ($many !== '' ? $many : $one . 's'));
    }

    /** "name", "name and level", "name, level and captain". */
    private static function and_list($words) {
        $last = array_pop($words);
        return $words ? implode(', ', $words) . ' and ' . $last : (string) $last;
    }

    private static function name($user_id) {
        $u = get_userdata($user_id);
        return $u ? $u->display_name : 'That player';
    }

    private static function with_friends($n) {
        return $n > 0 ? ' (and ' . ($n === 1 ? 'their friend' : 'their ' . $n . ' friends') . ')' : '';
    }

    private static function division_label($key) {
        return isset(RSL_Teams::DIVISIONS[$key]) ? RSL_Teams::DIVISIONS[$key] : (string) $key;
    }

    /** A free agent's division choice, short. Ranked and Either are consent to Ranked. */
    private static function pref_label($key) {
        $short = array('unranked' => 'Unranked only', 'ranked' => 'Ranked only', 'either' => 'Either');
        return isset($short[$key]) ? $short[$key] : (string) $key;
    }

    private static function time_label($key) {
        $short = array('any' => 'Any time', 'early' => 'Earlier', 'late' => 'Later');
        return isset($short[$key]) ? $short[$key] : (string) $key;
    }

    private static function years_label($key) {
        $years = RSL_Players::years();
        return isset($years[$key]) ? $years[$key] : '';
    }

    private static function via_label($key) {
        $via = array(
            'code' => 'Invite code', 'invite' => 'Captain\'s email invite', 'pool' => 'Invited from the list', 'request' => 'Asked the team',
            'placement' => 'Placed by the league', 'admin' => 'Placed by an admin', 'apply' => 'Started a team',
        );
        return isset($via[$key]) ? $via[$key] : ($key !== '' ? (string) $key : '—');
    }

    private static function band_text($band) {
        return rtrim(rtrim(number_format((float) $band, 1, '.', ''), '0'), '.');
    }

    /** The admin's band override as typed ('' when none). */
    private static function override_value($r) {
        return isset($r->band_override) && $r->band_override !== null && $r->band_override !== '' ? self::band_text($r->band_override) : '';
    }

    private static function day($mysql) {
        return $mysql ? date_i18n('j M', strtotime($mysql)) : '';
    }

    private static function day_long($ymd) {
        return $ymd ? date_i18n('D j M Y', strtotime($ymd)) : '';
    }

    private static function when($mysql) {
        return $mysql ? date_i18n('D j M, g:ia', strtotime($mysql)) : '';
    }

    /** First name from the pool row (no extra queries): the first_name field, else the display name's first word. */
    private static function first_of($r) {
        $first = isset($r->first_name_meta) ? trim((string) $r->first_name_meta) : '';
        if ($first === '' && isset($r->display_name)) {
            $first = trim((string) strtok((string) $r->display_name, ' '));
        }
        return $first !== '' && strpos($first, '@') === false ? $first : 'Player';
    }

    /** Joined the list after the deadline (placements may already be drawn up without them). */
    private static function is_late($r, $dates) {
        return $dates && !empty($r->created_at) && substr((string) $r->created_at, 0, 10) > $dates['deadline'];
    }

    private static function phase_words($phase, $dates) {
        if (!$dates) {
            return 'Open, with no deadline — the season has no first game night yet';
        }
        switch ($phase) {
            case 'before':
                return 'Not open yet — the team-building window opens ' . self::day_long($dates['opens']);
            case 'open':
                return 'Team-building window — captains and free agents can match until ' . self::day_long($dates['deadline']);
            case 'placing':
                return 'Deadline passed — placements to make and confirm';
        }
        return 'Season — placements confirmed, or the season has started';
    }

    /** suggest()'s summary as one line, whatever its keys. */
    private static function summary_text($summary) {
        if (!is_array($summary)) {
            return '';
        }
        $parts = array();
        foreach ($summary as $k => $v) {
            if (!is_scalar($v) || (string) $v === '') {
                continue;
            }
            $v = is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v;
            $parts[] = is_int($k) ? $v : ucfirst(str_replace('_', ' ', (string) $k)) . ': ' . $v;
        }
        return implode(' · ', $parts);
    }

    private static function confirm_words($r, $lead = 'Confirmed') {
        $n = function ($key) use ($r) {
            return is_array($r) && isset($r[$key]) ? intval($r[$key]) : 0;
        };
        $parts = array($n('placed') . ' placed' . ($n('new_teams') ? ' (' . self::plural($n('new_teams'), 'new team') . ' made)' : ''));
        $parts[] = $n('unplaced') . ' not placed';
        if ($n('skipped')) {
            $parts[] = $n('skipped') . ' skipped (joined a team another way since)';
        }
        $parts[] = self::plural($n('emails'), 'email') . ' queued';
        return $lead . ': ' . implode(', ', $parts) . '. Emails go out in batches — the outbox is on the Overview tab.';
    }

    /**
     * After a confirm that made new teams (0.19.0): for each division they went into that is now
     * over its places, " Ranked, 2027 Pre-season now has 31 teams for 30 places: …"
     * (RSL_Capacity::over_notice()); else ''.
     */
    private static function over_words($season, $r) {
        $out = '';
        foreach (array_keys(RSL_Teams::DIVISIONS) as $d) {
            if (is_array($r) && !empty($r['new_in'][$d])) {
                $over = RSL_Capacity::over_notice($season, $d);
                $out .= $over !== '' ? ' ' . $over : '';
            }
        }
        return $out;
    }

    private static function mail_words($sent, $failed) {
        $words = 'Sent ' . self::plural($sent, 'email') . '.';
        if ($failed) {
            $words .= ' ' . self::plural($failed, 'email') . ' didn\'t go — each is tried up to ' . RSL_Mail::MAX_ATTEMPTS . ' times; check the site\'s mail setup if they keep failing.';
        }
        $left = RSL_Mail::counts();
        if ($left['queued']) {
            $words .= ' ' . intval($left['queued']) . ' still waiting (they go out in batches).';
        }
        return $words;
    }

    // ------------------------------------------------------------------
    // Screen
    // ------------------------------------------------------------------

    public function render() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $season = isset($_GET['season']) && is_scalar($_GET['season']) ? sanitize_text_field(wp_unslash($_GET['season'])) : '';
        $season = RSL_Seasons::is_valid($season) ? $season : RSL_Seasons::signup();
        $tab = isset($_GET['tab']) && is_scalar($_GET['tab']) ? sanitize_key($_GET['tab']) : '';
        $tab = isset(self::TABS[$tab]) ? $tab : 'overview';
        // A few waiting emails go on every visit: WP-cron on a quiet site can run late.
        $sent = RSL_Mail::drain(10);

        echo '<div class="wrap rsl-fa"><h1>Free Agents <span class="rsl-fa-season">' . esc_html(RSL_Seasons::label($season)) . '</span></h1>';
        echo self::take_notice();
        echo self::season_picker($season, $tab);
        echo '<nav class="nav-tab-wrapper wp-clearfix">';
        foreach (self::TABS as $key => $label) {
            echo '<a href="' . esc_url(self::url(array('season' => $season, 'tab' => $key))) . '" class="nav-tab' . ($key === $tab ? ' nav-tab-active" aria-current="page' : '') . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
        if ($tab === 'placement') {
            echo self::placement_tab($season);
        } elseif ($tab === 'settings') {
            echo self::settings_tab($season);
        } else {
            echo self::overview_tab($season, $sent);
        }
        echo self::styles() . '</div>';
    }

    private static function season_picker($season, $tab) {
        $signup = RSL_Seasons::signup();
        $current = RSL_Seasons::current();
        $choices = RSL_Seasons::choices();
        foreach (array($signup, $current, $season) as $key) {
            if (!isset($choices[$key])) {
                $choices[$key] = RSL_Seasons::label($key);
            }
        }
        $h = '<form method="get" class="rsl-fa-pick"><input type="hidden" name="page" value="' . esc_attr(self::PAGE) . '"><input type="hidden" name="tab" value="' . esc_attr($tab) . '">'
            . '<label for="rsl-fa-season"><strong>Season</strong></label> <select id="rsl-fa-season" name="season" onchange="this.form.submit()">';
        foreach ($choices as $key => $label) {
            $tags = array();
            if ($key === $signup) {
                $tags[] = 'sign-up';
            }
            if ($key === $current) {
                $tags[] = 'current';
            }
            $h .= '<option value="' . esc_attr($key) . '"' . selected($season, $key, false) . '>' . esc_html($label . ($tags ? ' — ' . implode(', ', $tags) : '')) . '</option>';
        }
        return $h . '</select> <noscript><button class="button">Show</button></noscript></form>';
    }

    /** What the row renderers share: open friend groups, the first three regular nights, the dates. */
    private static function context($season, $rows, $dates) {
        $groups = array();
        foreach ($rows as $r) {
            $status = isset($r->status) ? $r->status : 'looking';
            if (isset($r->group_code) && $r->group_code !== '' && in_array($status, array('looking', 'unplaced'), true)) {
                $groups[$r->group_code][] = $r;
            }
        }
        $first3 = array();
        foreach (RSL_Courts::nights($season) as $night) {
            if ($night->type === 'regular' && count($first3) < 3) {
                $first3[] = $night->night_date;
            }
        }
        return array('groups' => $groups, 'first3' => $first3, 'dates' => $dates, 'settings' => RSL_Free_Agents::settings());
    }

    /** A person's unit as far as this page knows: their open friend group, or just them. */
    private static function unit_of($r, $ctx) {
        return $r->group_code !== '' && isset($ctx['groups'][$r->group_code]) ? $ctx['groups'][$r->group_code] : array($r);
    }

    /** The first names of the rest of their friend group. */
    private static function mates($r, $unit) {
        $out = array();
        foreach ($unit as $m) {
            if (intval($m->user_id) !== intval($r->user_id)) {
                $out[] = self::first_of($m);
            }
        }
        return $out;
    }

    /** Weeks away as dates, the first three regular nights in bold (they matter most for a new team). */
    private static function away_words($csv, $first3) {
        $dates = array_values(array_filter(explode(',', (string) $csv)));
        if (!$dates) {
            return '<span class="rsl-fa-sub">—</span>';
        }
        $out = array();
        foreach ($dates as $d) {
            $label = esc_html(date_i18n('j M', strtotime($d)));
            $out[] = in_array($d, $first3, true) ? '<strong title="One of the first three nights">' . $label . '</strong>' : $label;
        }
        return count($dates) . ': ' . implode(', ', $out);
    }

    // ------------------------------------------------------------------
    // Overview
    // ------------------------------------------------------------------

    private static function overview_tab($season, $sent) {
        $dates = RSL_Free_Agents::dates($season);
        $phase = RSL_Free_Agents::phase($season);
        $by = array_fill_keys(array_keys(RSL_Free_Agents::statuses()), array());
        $all = RSL_Free_Agents::pool($season, array_keys(RSL_Free_Agents::statuses()));
        foreach ($all as $r) {
            if (isset($by[$r->status])) {
                $by[$r->status][] = $r;
            }
        }
        $ctx = self::context($season, $all, $dates);
        $teams = RSL_Teams::list_for_season($season);

        $h = self::phase_box($season, $phase, $dates);
        if ($phase === 'placing' && $by['looking']) {
            $h .= '<div class="notice notice-info inline"><p><strong>The deadline has passed.</strong> ' . esc_html(self::plural(count($by['looking']), 'free agent')) . ' waiting to be placed. '
                . '<a href="' . esc_url(self::url(array('season' => $season, 'tab' => 'placement'))) . '">Make placements &rarr;</a></p></div>';
        }
        $h .= self::stats($season, $by, $ctx);

        $h .= '<h2 id="rsl-fa-pool">Looking for a team <span class="count">(' . count($by['looking']) . ')</span></h2>';
        $h .= '<p class="description">Admin-only: the band (1 never played … 5 State League; blank = worked out from their answers), age, weeks away and notes are never shown to captains. Under 18 comes from the date of birth on their club profile (no date of birth counts as under 18); tick <em>Treat as under 18</em> when an account\'s date of birth is wrong. '
            . '"Place now" puts them (with their friend group) straight onto a team — into a division they haven\'t agreed to, it sends them an invite to accept instead.</p>';
        $h .= self::pool_table($by['looking'], $season, $teams, $ctx, 'Nobody is waiting on the list.');

        $h .= '<details class="rsl-fa-more"><summary>Not placed <span class="count">(' . count($by['unplaced']) . ')</span></summary>'
            . '<p class="description">Left over by a placement run. They haven\'t been charged and are first in line when a spot opens.</p>'
            . self::pool_table($by['unplaced'], $season, $teams, $ctx, 'Nobody.') . '</details>';
        $h .= '<details class="rsl-fa-more"><summary>On a team <span class="count">(' . count($by['placed']) . ')</span></summary>' . self::placed_table($by['placed']) . '</details>';
        $h .= '<details class="rsl-fa-more"><summary>Left the list <span class="count">(' . count($by['withdrawn']) . ')</span></summary>' . self::left_table($by['withdrawn']) . '</details>';

        $h .= self::teams_table($season, $teams, $ctx['settings']);
        $h .= self::outbox($season, $sent);
        return $h;
    }

    private static function courts_link($season, $text) {
        $page = class_exists('RSL_Admin_Courts') ? RSL_Admin_Courts::PAGE : 'rsl-courts';
        return '<a href="' . esc_url(admin_url('admin.php?page=' . $page . '&season=' . rawurlencode($season))) . '">' . esc_html($text) . '</a>';
    }

    private static function phase_box($season, $phase, $dates) {
        $h = '<div class="rsl-fa-box"><p class="rsl-fa-phase"><strong>' . esc_html(self::phase_words($phase, $dates)) . '</strong></p>';
        $signup = RSL_Seasons::signup();
        if ($signup !== $season) {
            $h .= '<p class="rsl-fa-sub">Players are signing up for <a href="' . esc_url(self::url(array('season' => $signup))) . '">' . esc_html(RSL_Seasons::label($signup)) . '</a> right now.</p>';
        }
        if (!$dates) {
            $h .= '<div class="notice notice-warning inline"><p>This season has no first game night yet, so there\'s no deadline: the list and the "teams looking for players" board stay open and players see no dates. '
                . self::courts_link($season, 'Set the season\'s dates in Courts & Nights') . '.</p></div>';
            return $h . '</div>';
        }
        $today = current_time('Y-m-d');
        $h .= '<table class="rsl-fa-dates">';
        foreach (array('opens' => 'Team-building window opens', 'deadline' => 'Deadline — last day for requests and list invites', 'confirm_by' => 'Players hear about their team by', 'first_night' => 'First game night') as $key => $label) {
            $h .= '<tr' . ($dates[$key] < $today ? ' class="is-past"' : '') . '><th>' . esc_html($label) . '</th><td>' . esc_html(self::day_long($dates[$key])) . '</td></tr>';
        }
        return $h . '</table></div>';
    }

    private static function stats($season, $by, $ctx) {
        $counts = RSL_Free_Agents::counts($season);
        $h = '<div class="rsl-fa-box"><ul class="rsl-fa-stats">';
        foreach (RSL_Free_Agents::statuses() as $key => $label) {
            $h .= '<li><strong>' . intval(isset($counts[$key]) ? $counts[$key] : 0) . '</strong> ' . esc_html(strtolower($label)) . '</li>';
        }
        $h .= '</ul>';
        $open = array_merge($by['looking'], $by['unplaced']);
        if (!$open) {
            return $h . '</div>';
        }
        $division = array('unranked' => 0, 'ranked' => 0, 'either' => 0);
        $bands = array('1–1.5' => 0, '2–2.5' => 0, '3–3.5' => 0, '4–5' => 0);
        $volunteers = 0;
        $under18 = 0;
        $late = 0;
        $is_under18 = RSL_Free_Agents::under18_for($open, $season);
        foreach ($open as $r) {
            if (isset($division[$r->division_pref])) {
                $division[$r->division_pref]++;
            }
            $b = RSL_Free_Agents::band($r, $r);
            $bands[$b < 2 ? '1–1.5' : ($b < 3 ? '2–2.5' : ($b < 4 ? '3–3.5' : '4–5'))]++;
            $volunteers += $r->captain_ok ? 1 : 0;
            $under18 += !empty($is_under18[intval($r->user_id)]) ? 1 : 0;
            $late += self::is_late($r, $ctx['dates']) ? 1 : 0;
        }
        $groups = 0;
        $grouped = 0;
        foreach ($ctx['groups'] as $members) {
            if (count($members) > 1) {
                $groups++;
                $grouped += count($members);
            }
        }
        $parts = array();
        foreach ($division as $key => $n) {
            $parts[] = self::pref_label($key) . ' ' . $n;
        }
        $band_parts = array();
        foreach ($bands as $key => $n) {
            $band_parts[] = $key . ': ' . $n;
        }
        $h .= '<p><strong>Still to place (' . count($open) . '):</strong> ' . esc_html(implode(' · ', $parts)) . '</p>';
        $h .= '<p><strong>Band:</strong> ' . esc_html(implode(' · ', $band_parts)) . '</p>';
        $h .= '<p>' . esc_html(self::plural($groups, 'friend group') . ' (' . self::plural($grouped, 'person', 'people') . ') · ' . $volunteers . ' happy to captain · ' . $under18 . ' under 18'
            . ($ctx['dates'] ? ' · ' . $late . ' joined late' : '')) . '</p>';
        return $h . '</div>';
    }

    private static function pool_table($rows, $season, $teams, $ctx, $empty) {
        if (!$rows) {
            return '<p class="description">' . esc_html($empty) . '</p>';
        }
        $h = '<div class="rsl-fa-scroll"><table class="widefat striped rsl-fa-table"><thead><tr>'
            . '<th>Player</th><th>Level</th><th>Band</th><th>Division</th><th>Time</th><th>Weeks away</th><th>Friend group</th><th>Captain?</th><th>Under 18</th><th>Requests</th><th>Admin note</th><th>Place now</th>'
            . '</tr></thead><tbody>';
        $ids = array();
        foreach ($rows as $r) {
            $ids[] = intval($r->user_id);
        }
        $ctx['dob_under18'] = RSL_Players::under18_map($ids, $season);
        $ctx['birth_dates'] = RSL_Players::birth_dates($ids);
        foreach ($rows as $r) {
            $h .= self::pool_row($r, $season, $teams, $ctx);
        }
        return $h . '</tbody></table></div>';
    }

    /**
     * One person on the list. The band, "treat as under 18" box and note belong to the
     * Save form in the note cell (HTML form="" ties them to it across cells);
     * Place now is its own form.
     */
    private static function pool_row($r, $season, $teams, $ctx) {
        $id = intval($r->id);
        $save = 'rsl-fa-save-' . $id;
        $post = admin_url('admin-post.php');
        $unit = self::unit_of($r, $ctx);
        $mates = self::mates($r, $unit);
        $own = RSL_Free_Agents::band($r);
        $override = self::override_value($r);
        $positions = RSL_Free_Agents::position_labels($r->positions);

        $h = '<tr id="fa-' . $id . '">';
        $h .= '<td><strong>' . esc_html($r->display_name) . '</strong><br><span class="rsl-fa-sub">' . esc_html($r->user_email) . '</span><br>'
            . '<span class="rsl-fa-sub">Signed up ' . esc_html(self::day($r->created_at)) . '</span>'
            . (self::is_late($r, $ctx['dates']) ? ' <span class="rsl-fa-pill warn" title="Joined after the deadline">Late</span>' : '')
            . ($r->status === 'unplaced' && $r->unplaced_reason !== '' ? '<br><span class="rsl-fa-sub">Not placed: ' . esc_html($r->unplaced_reason) . '</span>' : '')
            . '</td>';
        $h .= '<td>' . esc_html(RSL_Free_Agents::level_label($r->level)) . '<br><span class="rsl-fa-sub">' . esc_html(self::years_label($r->years))
            . ($positions ? ' · ' . esc_html(implode(', ', $positions)) : '') . '</span></td>';
        $h .= '<td><input type="number" form="' . esc_attr($save) . '" name="band_override" min="1" max="5" step="0.5" value="' . esc_attr($override) . '" placeholder="' . esc_attr(self::band_text($own)) . '" class="rsl-fa-band" title="Blank = from their answers (' . esc_attr(self::band_text($own)) . ')">'
            . ($override !== '' ? '<br><span class="rsl-fa-sub">answers: ' . esc_html(self::band_text($own)) . '</span>' : '') . '</td>';
        $h .= '<td>' . esc_html(self::pref_label($r->division_pref)) . '</td>';
        $h .= '<td>' . esc_html(self::time_label($r->time_pref)) . '</td>';
        $h .= '<td>' . self::away_words($r->away_dates, $ctx['first3']) . '</td>';
        $h .= '<td>' . ($r->group_code === '' ? '<span class="rsl-fa-sub">—</span>'
            : ($mates ? 'With ' . esc_html(implode(', ', $mates)) : '<span class="rsl-fa-sub">Nobody has joined yet</span>') . '<br><code>' . esc_html($r->group_code) . '</code>') . '</td>';
        $h .= '<td>' . ($r->captain_ok ? '<span class="rsl-fa-star">&#9733;</span> Yes' : '<span class="rsl-fa-sub">—</span>') . '</td>';
        $dob = isset($ctx['birth_dates'][intval($r->user_id)]) ? $ctx['birth_dates'][intval($r->user_id)] : '';
        if ($dob === '') {
            $age = '<span class="rsl-fa-pill warn" title="Counts as under 18 until their profile has a date of birth">No date of birth</span>';
        } else {
            $age = (!empty($ctx['dob_under18'][intval($r->user_id)]) ? '<span class="rsl-fa-pill info">Under 18</span><br>' : '')
                . '<span class="rsl-fa-sub">age ' . intval(RSL_Players::age_on($dob, current_time('Y-m-d'))) . '</span>';
        }
        $h .= '<td>' . $age . '<br><label class="rsl-fa-sub" title="For an account whose date of birth is wrong: hidden from captains browsing the list, like any under-18"><input type="checkbox" form="' . esc_attr($save) . '" name="under18" value="1"' . checked((int) $r->under18, 1, false) . '> Treat as under 18</label></td>';
        $h .= '<td>' . (intval($r->requests_waiting) ? intval($r->requests_waiting) . ' waiting' : '<span class="rsl-fa-sub">—</span>') . '</td>';
        $h .= '<td><form id="' . esc_attr($save) . '" method="post" action="' . esc_url($post) . '" class="rsl-fa-inline">' . self::fields('save_agent', $season, 'overview', array('fa_id' => $id))
            . '<input type="text" name="admin_note" maxlength="255" value="' . esc_attr($r->admin_note) . '" class="rsl-fa-note" placeholder="Only admins see this"> <button class="button button-small">Save</button></form></td>';
        if ($teams) {
            $question = 'Put ' . $r->display_name . self::with_friends(count($unit) - 1) . ' on the chosen team now? They and the captain are emailed each other\'s details.';
            $h .= '<td><form method="post" action="' . esc_url($post) . '" class="rsl-fa-inline rsl-fa-place" onsubmit="return confirm(\'' . esc_js($question) . '\');">'
                . self::fields('place_now', $season, 'overview', array('user_id' => intval($r->user_id)))
                . '<select name="team_id" required>' . self::team_options($season, $teams, RSL_Free_Agents::group_divisions($unit), count($unit), $ctx['settings']['max_roster']) . '</select> '
                . '<button class="button button-small">Place</button></form></td>';
        } else {
            $h .= '<td><span class="rsl-fa-sub">No active teams yet</span></td>';
        }
        return $h . '</tr>';
    }

    /** The "Place now" team list for a unit (cached: it only varies by the unit's divisions and size). */
    private static function team_options($season, $teams, $allowed, $size, $max) {
        static $cache = array();
        $key = $season . '|' . implode(',', $allowed) . '|' . $size;
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $h = '<option value="">Team…</option>';
        foreach (RSL_Teams::DIVISIONS as $division => $label) {
            $options = '';
            foreach ($teams as $t) {
                if ($t->division !== $division) {
                    continue;
                }
                $n = intval($t->member_count);
                $note = '';
                if (!in_array($division, $allowed, true)) {
                    $note = ' — sends an invite (not agreed to ' . $label . ')';
                } elseif ($n + $size > $max) {
                    $note = ' — goes over the maximum';
                }
                $options .= '<option value="' . intval($t->id) . '">' . esc_html($t->name . ' · ' . $n . '/' . $max . $note) . '</option>';
            }
            if ($options !== '') {
                $h .= '<optgroup label="' . esc_attr($label) . '">' . $options . '</optgroup>';
            }
        }
        return $cache[$key] = $h;
    }

    private static function placed_table($rows) {
        if (!$rows) {
            return '<p class="description">Nobody yet.</p>';
        }
        $now = current_time('mysql');
        $h = '<table class="widefat striped rsl-fa-list"><thead><tr><th>Player</th><th>Team</th><th>How</th><th>When</th><th>"Not for me" until</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $h .= '<tr><td>' . esc_html($r->display_name) . '<br><span class="rsl-fa-sub">' . esc_html($r->user_email) . '</span></td>'
                . '<td>' . esc_html($r->team_name ? $r->team_name : '—') . '</td>'
                . '<td>' . esc_html(self::via_label($r->placed_via)) . '</td>'
                . '<td>' . esc_html(self::day($r->placed_at)) . '</td>'
                . '<td>' . ($r->optout_until && $r->optout_until > $now ? esc_html(self::when($r->optout_until)) : '<span class="rsl-fa-sub">—</span>') . '</td></tr>';
        }
        return $h . '</tbody></table>';
    }

    private static function left_table($rows) {
        if (!$rows) {
            return '<p class="description">Nobody.</p>';
        }
        $h = '<table class="widefat striped rsl-fa-list"><thead><tr><th>Player</th><th>Signed up</th><th>Left</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $h .= '<tr><td>' . esc_html($r->display_name) . '<br><span class="rsl-fa-sub">' . esc_html($r->user_email) . '</span></td>'
                . '<td>' . esc_html(self::day($r->created_at)) . '</td><td>' . esc_html(self::day($r->updated_at)) . '</td></tr>';
        }
        return $h . '</tbody></table>';
    }

    private static function teams_table($season, $teams, $s) {
        $h = '<h2>Teams <span class="count">(' . count($teams) . ')</span></h2>';
        $pending = array_filter(RSL_Teams::pending_applications(), function ($t) use ($season) {
            return $t->season === $season;
        });
        if ($pending) {
            $h .= '<div class="notice notice-warning inline"><p>' . esc_html(self::plural(count($pending), 'captain application') . (count($pending) === 1 ? ' is' : ' are')) . ' still waiting for approval this season. '
                . 'Until approved, those teams can\'t take players or be filled at placement. '
                . '<a href="' . esc_url(admin_url('admin.php?page=' . RSL_Admin::PAGE . '&season=' . rawurlencode($season))) . '">Review them on the Teams screen &rarr;</a></p></div>';
        }
        if (!$teams) {
            return $h . '<p class="description">No active teams this season yet.</p>';
        }
        // Which requests count as waiting is RSL_Free_Agents' rule (pending, not expired, player still looking).
        $requests = RSL_Free_Agents::request_counts($season);
        $h .= '<p class="description">Teams need ' . intval($s['min_roster']) . ' players; placement fills existing teams to ' . intval($s['target_roster'])
            . ', and nobody joins past ' . intval($s['max_roster']) . ' (except when an admin adds them).</p>';
        $h .= '<div class="rsl-fa-scroll"><table class="widefat striped rsl-fa-list"><thead><tr><th>Team</th><th>Division</th><th>Players</th><th></th><th>Looking for</th><th>Requests waiting</th></tr></thead><tbody>';
        $spots_levels = RSL_Free_Agents::spots_levels();
        foreach ($teams as $t) {
            $n = intval($t->member_count);
            if ($n >= $s['max_roster']) {
                $pill = '<span class="rsl-fa-pill info">Full</span>';
            } elseif ($n < $s['min_roster']) {
                $pill = '<span class="rsl-fa-pill warn">Short by ' . intval($s['min_roster'] - $n) . '</span>';
            } elseif ($n >= $s['target_roster']) {
                $pill = '<span class="rsl-fa-pill good">At target</span>';
            } else {
                $pill = '<span class="rsl-fa-pill">OK</span>';
            }
            $spots = '<span class="rsl-fa-sub">—</span>';
            if (intval($t->spots_open) > 0) {
                $bits = array(self::plural(intval($t->spots_open), 'spot'));
                $positions = RSL_Free_Agents::position_labels($t->spots_positions);
                if ($positions) {
                    $bits[] = implode(', ', $positions);
                }
                if (isset($spots_levels[$t->spots_level])) {
                    $bits[] = $spots_levels[$t->spots_level];
                }
                $spots = esc_html(implode(' · ', $bits)) . ($t->spots_note !== '' ? '<br><span class="rsl-fa-sub">"' . esc_html($t->spots_note) . '"</span>' : '');
            }
            $link = admin_url('admin.php?page=' . RSL_Admin::PAGE . '&season=' . rawurlencode($season) . '&team=' . intval($t->id));
            $h .= '<tr><td><a href="' . esc_url($link) . '"><strong>' . esc_html($t->name) . '</strong></a>' . ($t->formed_by === 'placement' ? ' <span class="rsl-fa-pill info">Made at placement</span>' : '') . '</td>'
                . '<td>' . esc_html(self::division_label($t->division)) . '</td>'
                . '<td>' . $n . ' <span class="rsl-fa-sub">of ' . intval($s['min_roster']) . ' / ' . intval($s['target_roster']) . ' / ' . intval($s['max_roster']) . '</span></td>'
                . '<td>' . $pill . '</td><td>' . $spots . '</td>'
                . '<td>' . (isset($requests[intval($t->id)]) ? intval($requests[intval($t->id)]) : '<span class="rsl-fa-sub">—</span>') . '</td></tr>';
        }
        return $h . '</tbody></table></div><p class="rsl-fa-sub">Players: now, of minimum / target / maximum.</p>';
    }

    private static function outbox($season, $sent) {
        $post = admin_url('admin-post.php');
        $c = RSL_Mail::counts();
        $h = '<h2 id="rsl-fa-outbox">Emails</h2><div class="rsl-fa-box">';
        $h .= '<p><strong>' . intval($c['queued']) . '</strong> waiting to send · <strong' . ($c['failed'] ? ' class="rsl-fa-bad"' : '') . '>' . intval($c['failed']) . '</strong> failed · <strong>' . intval($c['sent']) . '</strong> sent (kept ' . intval(RSL_Mail::KEEP_DAYS) . ' days)';
        if (is_array($sent) && isset($sent[0], $sent[1]) && ($sent[0] || $sent[1])) {
            $h .= ' <span class="rsl-fa-sub">— opening this page just sent ' . intval($sent[0]) . ($sent[1] ? ' (' . intval($sent[1]) . ' failed)' : '') . '</span>';
        }
        $h .= '</p><p class="description">League emails (joining the list, invites, requests, placements) are queued and sent in batches by the hourly job; opening this page sends a few too. Each email goes once, however often an action is repeated.</p>';
        $h .= '<div class="rsl-fa-actions"><form method="post" action="' . esc_url($post) . '">' . self::fields('send_mail', $season, 'overview')
            . '<button class="button"' . ($c['queued'] ? '' : ' disabled') . '>Send queued emails now</button></form>';
        if ($c['failed']) {
            $h .= '<form method="post" action="' . esc_url($post) . '">' . self::fields('retry_failed', $season, 'overview') . '<button class="button">Retry failed</button></form>';
        }
        $h .= '</div>';
        if ($c['failed']) {
            $h .= '<table class="widefat striped rsl-fa-list"><thead><tr><th>To</th><th>Subject</th><th>Tries</th><th>Error</th><th>Queued</th></tr></thead><tbody>';
            foreach (RSL_Mail::failed() as $f) {
                $h .= '<tr><td>' . esc_html($f->to_email) . '</td><td>' . esc_html($f->subject) . '</td><td>' . intval($f->attempts) . '</td><td>' . esc_html($f->last_error) . '</td><td>' . esc_html(self::when($f->created_at)) . '</td></tr>';
            }
            $h .= '</tbody></table>';
        }
        return $h . '</div>';
    }

    // ------------------------------------------------------------------
    // Placement
    // ------------------------------------------------------------------

    private static function list_of($draft, $key) {
        return isset($draft[$key]) && is_array($draft[$key]) ? array_values($draft[$key]) : array();
    }

    private static function rows_of($block) {
        return is_array($block) && isset($block['rows']) && is_array($block['rows']) ? array_values(array_filter($block['rows'], 'is_object')) : array();
    }

    /** The draft's blocker or warning lines, each once. */
    private static function strings_of($draft, $key) {
        $out = array();
        if (is_array($draft) && isset($draft[$key]) && is_array($draft[$key])) {
            foreach ($draft[$key] as $line) {
                if (is_scalar($line) && (string) $line !== '') {
                    $out[(string) $line] = true;
                }
            }
        }
        return array_keys($out);
    }

    /**
     * Where a person's unit may be moved — everyone's worked out at once
     * (RSL_Placement::destinations_all(), one pass over the pool and teams).
     */
    private static function destinations($season, $r) {
        if (!array_key_exists($season, self::$destinations)) {
            self::$destinations[$season] = RSL_Placement::destinations_all($season);
        }
        $uid = intval($r->user_id);
        return isset(self::$destinations[$season][$uid]) ? self::$destinations[$season][$uid] : array();
    }

    /** destinations_all() for the placement tab being drawn (cleared at the start of each draw). */
    private static $destinations = array();

    /** A draft new team still on the placeholder name (the placement run's "Unranked New 1"). */
    private static function is_placeholder($name) {
        return trim($name) === '' || preg_match('/^(Ranked|Unranked)\s+New\s+\d+$/i', trim($name));
    }

    private static function mean_band($rows) {
        if (!$rows) {
            return 0;
        }
        $sum = 0;
        foreach ($rows as $r) {
            $sum += RSL_Free_Agents::band($r, $r);
        }
        return $sum / count($rows);
    }

    private static function placement_tab($season) {
        if (!class_exists('RSL_Placement')) {
            return '<div class="notice notice-error inline"><p>The placement engine isn\'t loaded, so placements can\'t be made here yet.</p></div>';
        }
        self::$destinations = array(); // worked out afresh for each draw
        $post = admin_url('admin-post.php');
        $b = RSL_Free_Agents::building($season);
        $version = intval($b['draft_version']);
        $dates = RSL_Free_Agents::dates($season);
        $phase = RSL_Free_Agents::phase($season);
        $s = RSL_Free_Agents::settings();
        $draft = RSL_Placement::draft($season);
        $draft = is_array($draft) ? $draft : array();
        // draft() already carries blockers() — same state, same checks — so it isn't asked twice.
        $blockers = self::strings_of($draft, 'blockers');
        $warnings = self::strings_of($draft, 'warnings');
        $existing = self::list_of($draft, 'existing');
        $new = self::list_of($draft, 'new');
        $none = array_values(array_filter(self::list_of($draft, 'none'), 'is_object'));
        $unproposed = array_values(array_filter(self::list_of($draft, 'unproposed'), 'is_object'));

        $proposed_rows = $none;
        foreach (array_merge($existing, $new) as $block) {
            $proposed_rows = array_merge($proposed_rows, self::rows_of($block));
        }
        $ctx = self::context($season, array_merge($proposed_rows, $unproposed), $dates);
        $proposed = $b['proposed_at'] !== '' || $version > 0;

        $h = '<p class="description">The site proposes where everyone still looking should go: short teams are filled first, then teams with open spots, then new teams are made from whoever is left. '
            . 'Friend groups always stay together, and nobody who chose Unranked is put in Ranked. Adjust anything, then confirm — players and captains are only told when you confirm.</p>';

        if (!$dates) {
            $h .= '<div class="notice notice-warning inline"><p>This season has no first game night, so there\'s no deadline — captains and free agents can still match while you work. '
                . self::courts_link($season, 'Set the dates in Courts & Nights') . '.</p></div>';
        } elseif (in_array($phase, array('before', 'open'), true)) {
            $h .= '<div class="notice notice-info inline"><p>The deadline is ' . esc_html(self::day_long($dates['deadline'])) . '. Until then captains can still invite people and free agents can ask teams, so a proposal made now will change — make one to see where things stand, and re-run after the deadline.</p></div>';
        }

        $h .= '<div class="rsl-fa-box">';
        if ($b['confirmed_at'] !== '') {
            $by = $b['confirmed_by'] ? get_userdata($b['confirmed_by']) : null;
            $h .= '<p><span class="rsl-fa-pill good">Confirmed</span> ' . esc_html(self::when($b['confirmed_at']) . ($by ? ' by ' . $by->display_name : ''))
                . '. Anyone joining the list from now on can be placed with a new proposal, or with Place now on the Overview tab.</p>';
        }
        if ($proposed) {
            $h .= '<p>Proposal made ' . esc_html(self::when($b['proposed_at'])) . ' <span class="rsl-fa-sub">· draft version ' . $version . '</span></p>';
            $summary = self::summary_text($b['summary']);
            if ($summary !== '') {
                $h .= '<p class="rsl-fa-sub">' . esc_html($summary) . '</p>';
            }
        } else {
            $h .= '<p>No proposal yet. ' . esc_html(self::plural(count($unproposed), 'free agent')) . ' waiting.</p>';
        }
        $h .= '<div class="rsl-fa-actions"><form method="post" action="' . esc_url($post) . '"'
            . ($proposed ? ' onsubmit="return confirm(\'' . esc_js('Re-run the proposal? People you moved stay where you put them; everyone else is proposed again and new teams may be redrawn — check their names and captains afterwards.') . '\');"' : '') . '>'
            . self::fields('suggest', $season, 'placement')
            . '<button class="button' . ($proposed ? '' : ' button-primary') . '">' . ($proposed ? 'Re-run, keep my moves' : 'Make proposal') . '</button></form>';
        if ($proposed) {
            // Every move fixes that person on re-runs; this is the way to undo a muddle.
            $h .= '<form method="post" action="' . esc_url($post) . '" onsubmit="return confirm(\'' . esc_js('Start over? Every move you made is forgotten and everyone still waiting is proposed again from scratch. Names you gave new teams are kept where those teams are drawn again — check names and captains afterwards.') . '\');">'
                . self::fields('suggest', $season, 'placement', array('fresh' => 1))
                . '<button class="button">Start over (forget my moves)</button></form>';
            $h .= '<a href="#rsl-fa-confirm">Jump to confirm &darr;</a>';
        }
        $h .= '</div></div>';

        if (!$proposed) {
            // Everyone waiting is on the Overview tab; moves start from a proposal.
            return $h . ($unproposed ? '<p class="description">Make a proposal to see where everyone would go. The people waiting are listed on the <a href="' . esc_url(self::url(array('season' => $season, 'tab' => 'overview'))) . '#rsl-fa-pool">Overview tab</a>.</p>' : '');
        }

        if ($blockers) {
            $h .= '<div class="notice notice-error inline"><p><strong>Fix these before confirming everything:</strong></p><ul class="rsl-fa-ul">';
            foreach ($blockers as $line) {
                $h .= '<li>' . esc_html($line) . '</li>';
            }
            $h .= '</ul></div>';
        }
        if ($warnings) {
            $h .= '<div class="notice notice-warning inline"><p><strong>Worth a look</strong> (these don\'t stop you confirming):</p><ul class="rsl-fa-ul">';
            foreach ($warnings as $line) {
                $h .= '<li>' . esc_html($line) . '</li>';
            }
            $h .= '</ul></div>';
        }

        // (A) Existing teams.
        $h .= '<h2>Joining existing teams</h2>';
        $any = false;
        foreach ($existing as $block) {
            $rows = self::rows_of($block);
            $team = isset($block['team']) && is_object($block['team']) ? $block['team'] : null;
            if (!$team || !$rows) {
                continue;
            }
            $any = true;
            $n = isset($block['n']) ? intval($block['n']) : 0;
            $to = $n + count($rows);
            $flag = $to > $s['max_roster'] ? ' <span class="rsl-fa-pill bad">over the maximum (' . intval($s['max_roster']) . ')</span>'
                : ($to < $s['min_roster'] ? ' <span class="rsl-fa-pill warn">still short</span>' : '');
            $h .= '<div class="rsl-fa-block" id="fa-team-' . intval($team->id) . '"><h3>' . esc_html($team->name . ' · ' . self::division_label($team->division) . ' · ' . $n . ' → ' . $to) . $flag . '</h3>';
            $h .= self::draft_table($rows, $season, $version, $ctx);
            $h .= self::confirm_form($season, $version, 't:' . intval($team->id), 'Confirm ' . $team->name . ' only',
                'Confirm ' . self::plural(count($rows), 'player') . ' into ' . $team->name . ' now? They\'re added to the team and emailed their captain\'s details, and the captain gets theirs.', false);
            $h .= '</div>';
        }
        if (!$any) {
            $h .= '<p class="description">Nobody is proposed for an existing team.</p>';
        }

        // (B) New teams.
        $h .= '<h2>New teams</h2>';
        $any = false;
        $levels = RSL_Teams::levels();
        foreach ($new as $block) {
            $rows = self::rows_of($block);
            $ref = is_array($block) && isset($block['ref']) ? (string) $block['ref'] : '';
            if ($ref === '' || !preg_match('/^[A-Za-z0-9_-]{1,20}$/', $ref) || !$rows) {
                continue;
            }
            $any = true;
            $d = isset($block['draft']) && is_array($block['draft']) ? $block['draft'] : array();
            $form = 'rsl-fa-new-' . $ref;
            $name = isset($d['name']) ? (string) $d['name'] : '';
            $division = isset($d['division']) && isset(RSL_Teams::DIVISIONS[$d['division']]) ? $d['division'] : (strtoupper(substr($ref, 0, 1)) === 'R' ? 'ranked' : 'unranked');
            // The level the select actually shows (the first one when the draft's isn't a level).
            $level = isset($d['est_level']) && isset($levels[(string) $d['est_level']]) ? (string) $d['est_level'] : (string) key($levels);
            $captain = isset($d['captain_user_id']) ? intval($d['captain_user_id']) : 0;
            $picked = 0; // the drafted captain, only while they're still in this team
            foreach ($rows as $r) {
                $picked = intval($r->user_id) === $captain ? $captain : $picked;
            }
            $placeholder = self::is_placeholder($name);
            $h .= '<div class="rsl-fa-block" id="fa-new-' . esc_attr($ref) . '"><h3>' . esc_html('New ' . self::division_label($division) . ' team (' . $ref . ') · ' . self::plural(count($rows), 'player')) . '</h3>';
            // The *_was fields are what this form shows, so only what the admin changes is saved.
            $h .= '<form id="' . esc_attr($form) . '" method="post" action="' . esc_url($post) . '" class="rsl-fa-newteam">'
                . self::fields('update_new', $season, 'placement', array('ref' => $ref, 'version' => $version, 'name_was' => $name, 'level_was' => $level, 'captain_was' => $picked))
                . '<label>Name <input type="text" name="name" required maxlength="100" value="' . esc_attr($name) . '" class="regular-text' . ($placeholder ? ' is-placeholder' : '') . '"></label> '
                . '<label>Level <select name="est_level">';
            foreach ($levels as $key => $label) {
                $h .= '<option value="' . esc_attr($key) . '"' . selected($level, $key, false) . '>' . esc_html($label) . '</option>';
            }
            $h .= '</select></label> <span class="rsl-fa-sub">average band ' . esc_html(self::band_text(self::mean_band($rows)))
                . (empty($d['level_set']) ? ' · the level follows the players until you change it' : ' · level set by an admin') . '</span> '
                . '<button class="button">Save name, level and captain</button></form>';
            if ($placeholder) {
                $h .= '<p class="rsl-fa-bad">Give this team a real name before confirming — it becomes the public team name and its web address, which never changes.</p>';
            }
            if (!$picked) {
                $h .= '<p class="rsl-fa-warnline">' . ($captain ? 'The captain picked earlier isn\'t in this team any more' : 'No captain picked yet') . ' — choose one below (&#9733; = happy to captain), then save.</p>';
            }
            $h .= self::draft_table($rows, $season, $version, $ctx, $form, $picked);
            $h .= self::confirm_form($season, $version, 'n:' . $ref, 'Confirm this new team only',
                'Create ' . ($name !== '' ? $name : 'this team') . ' and add ' . (count($rows) === 1 ? 'this player' : 'these ' . count($rows) . ' players') . ' now? Everyone is emailed, and the captain gets the roster with emails.', false);
            $h .= '</div>';
        }
        if (!$any) {
            $h .= '<p class="description">No new teams in this proposal.</p>';
        }

        // (C) Not placed.
        $h .= '<h2>Not placed</h2>';
        if ($none) {
            // Status 'unplaced' = already told by an earlier confirm (they stay here until placed).
            $untold = count(array_filter($none, function ($r) {
                return isset($r->status) && $r->status === 'looking';
            }));
            $h .= '<p class="description">On confirming, these players are told they couldn\'t be placed this time and why, that they haven\'t been charged, and that they\'re first in line when a spot opens.'
                . ($untold < count($none) ? ' ' . esc_html(self::plural(count($none) - $untold, 'of them has', 'of them have')) . ' been told already.' : '') . '</p>';
            $h .= '<div class="rsl-fa-block">' . self::draft_table($none, $season, $version, $ctx)
                . self::confirm_form($season, $version, 'none', 'Tell these players only',
                    'Tell ' . self::plural($untold, 'player') . ' now that they couldn\'t be placed this time? They\'re emailed why, and stay first in line when a spot opens.', !$untold)
                . '</div>';
        } else {
            $h .= '<p class="description">Everyone has a place in this proposal.</p>';
        }

        if ($unproposed) {
            $h .= '<h2>Not in the proposal yet</h2><p class="description">Joined (or came back to the list) after the proposal was made. Re-run to include them, or move them yourself.</p>';
            $h .= '<div class="rsl-fa-block">' . self::draft_table($unproposed, $season, $version, $ctx) . '</div>';
        }

        $count = count($proposed_rows);
        $h .= '<div class="rsl-fa-box" id="rsl-fa-confirm"><h2>Confirm</h2>'
            . '<p>Confirming adds everyone to their team, creates the new teams, tells anyone not placed, and queues the emails (players get their captain\'s name and email; captains get their new players\'). '
            . 'It\'s safe to run again: anyone already placed, or who has joined a team another way since, is skipped, and nobody is emailed twice.</p>';
        if ($blockers) {
            $h .= '<p class="rsl-fa-bad">Confirm all is off until the problems at the top are fixed. You can still confirm a team that\'s ready — it\'s checked first.</p>';
        }
        $h .= self::confirm_form($season, $version, '', 'Confirm all placements',
            'Confirm all placements now? ' . self::plural($count, 'player') . ' are placed or told they couldn\'t be, and the emails go out.', (bool) $blockers || !$count);
        return $h . '</div>';
    }

    private static function confirm_form($season, $version, $only, $label, $question, $disabled) {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="rsl-fa-confirm" onsubmit="return confirm(\'' . esc_js($question) . '\');">'
            . self::fields('confirm', $season, 'placement', array('only' => $only, 'version' => $version))
            . '<button class="button button-primary"' . ($disabled ? ' disabled' : '') . '>' . esc_html($label) . '</button></form>';
    }

    /**
     * People in one block of the draft. With $captain_form (a new team), each
     * row gets a captain radio tied to that team's save form.
     */
    private static function draft_table($rows, $season, $version, $ctx, $captain_form = '', $captain_id = 0) {
        $h = '<div class="rsl-fa-scroll"><table class="widefat striped rsl-fa-draft"><thead><tr>' . ($captain_form !== '' ? '<th>Captain</th>' : '')
            . '<th>Player</th><th>Level</th><th>Wants</th><th>Weeks away</th><th>Why here</th><th>Move to</th></tr></thead><tbody>';
        $under18 = RSL_Free_Agents::under18_for($rows, $season);
        foreach ($rows as $r) {
            $uid = intval($r->user_id);
            $unit = self::unit_of($r, $ctx);
            $mates = self::mates($r, $unit);
            $override = self::override_value($r);
            $positions = RSL_Free_Agents::position_labels(isset($r->positions) ? $r->positions : '');
            $h .= '<tr id="fa-u' . $uid . '">';
            if ($captain_form !== '') {
                $h .= '<td><label><input type="radio" form="' . esc_attr($captain_form) . '" name="captain_user_id" value="' . $uid . '"' . checked($captain_id, $uid, false) . '>'
                    . (!empty($r->captain_ok) ? ' <span class="rsl-fa-star" title="Happy to captain">&#9733;</span>' : '') . '</label></td>';
            }
            $tags = '';
            if ($captain_form === '' && !empty($r->captain_ok)) {
                $tags .= ' <span class="rsl-fa-star" title="Happy to captain">&#9733;</span>';
            }
            if (!empty($under18[$uid])) {
                $tags .= ' <span class="rsl-fa-pill info">Under 18</span>';
            }
            if (self::is_late($r, $ctx['dates'])) {
                $tags .= ' <span class="rsl-fa-pill warn">Late</span>';
            }
            $h .= '<td><strong>' . esc_html(isset($r->display_name) ? $r->display_name : '') . '</strong>' . $tags
                . '<br><span class="rsl-fa-sub">' . esc_html(isset($r->user_email) ? $r->user_email : '') . '</span>'
                . ($mates ? '<br><span class="rsl-fa-pill">With ' . esc_html(implode(', ', $mates)) . ' — move together</span>' : '')
                . (!empty($r->admin_note) ? '<br><em class="rsl-fa-sub">Note: ' . esc_html($r->admin_note) . '</em>' : '') . '</td>';
            $h .= '<td>' . esc_html(RSL_Free_Agents::level_label(isset($r->level) ? $r->level : '')) . '<br><span class="rsl-fa-sub">band ' . esc_html(self::band_text(RSL_Free_Agents::band($r, $r)))
                . ($override !== '' ? ' (set by an admin)' : '') . ($positions ? ' · ' . esc_html(implode(', ', $positions)) : '') . '</span></td>';
            $h .= '<td>' . esc_html(self::pref_label($r->division_pref)) . '<br><span class="rsl-fa-sub">' . esc_html(self::time_label($r->time_pref)) . '</span></td>';
            $h .= '<td>' . self::away_words($r->away_dates, $ctx['first3']) . '</td>';
            $chips = '';
            foreach (preg_split('/\s*·\s*/u', (string) (isset($r->proposed_reason) ? $r->proposed_reason : ''), -1, PREG_SPLIT_NO_EMPTY) as $reason) {
                $chips .= '<span class="rsl-fa-chip">' . esc_html($reason) . '</span>';
            }
            if (!empty($r->proposed_locked)) {
                $chips .= '<span class="rsl-fa-chip locked" title="Moved or kept by an admin — re-running won\'t change it">Fixed by an admin</span>';
            }
            $h .= '<td>' . ($chips !== '' ? $chips : '<span class="rsl-fa-sub">—</span>') . '</td>';
            $h .= '<td>' . self::move_form($season, $version, $r, count($unit)) . '</td>';
            $h .= '</tr>';
        }
        return $h . '</tbody></table></div>';
    }

    /** "Move to": only destinations the whole unit agreed to and that have room. Picking where they are now fixes them there. */
    private static function move_form($season, $version, $r, $unit_size) {
        $options = self::destinations($season, $r);
        $current = isset($r->proposed_dest) ? (string) $r->proposed_dest : '';
        $locked = !empty($r->proposed_locked);
        $h = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="rsl-fa-move">'
            . self::fields('move', $season, 'placement', array('user_id' => intval($r->user_id), 'version' => $version))
            . '<select name="dest" required' . ($unit_size > 1 ? ' title="Moves the whole friend group"' : '') . '><option value="">Move to…</option>';
        foreach ($options as $value => $label) {
            $value = (string) $value;
            $here = $value === $current;
            $h .= '<option value="' . esc_attr($value) . '">' . esc_html($label . ($here ? ($locked ? ' (here now)' : ' (here now — keep on re-run)') : '')) . '</option>';
        }
        return $h . '</select> <button class="button button-small">' . ($unit_size > 1 ? 'Move all ' . intval($unit_size) : 'Move') . '</button></form>';
    }

    // ------------------------------------------------------------------
    // Settings
    // ------------------------------------------------------------------

    /** Every rule with plain words: section => key => array(label, unit, hint, type). */
    private static function setting_fields() {
        $days = 'days before the first game night';
        return array(
            'Dates (counted back from each season\'s first game night)' => array(
                'window_opens_days' => array('Team-building window opens', $days, 'From then captains can list open spots and browse the free agent list, and free agents can ask teams to take them. Players can join the list any time.', 'number'),
                'deadline_days' => array('Deadline', $days, 'The last day for requests and invites from the list; after it, placements can be made. Anyone joining the list after this is marked Late.', 'number'),
                'confirm_by_days' => array('Players hear about their team by', $days, 'The date free agents are told to expect their team — confirm placements by then so week-1 fixtures can be made.', 'number'),
            ),
            'Team sizes' => array(
                'min_roster' => array('Minimum players', 'per team', 'A team with fewer is short: it\'s filled first at placement and its captain sees a warning. It never stops a team being fixtured.', 'number'),
                'target_roster' => array('Fill existing teams to', 'players', 'Placement tops existing teams up to this many (a captain\'s open spots can ask for fewer).', 'number'),
                'new_team_target' => array('New teams of', 'players', 'The size of teams made from free agents at placement.', 'number'),
                'max_roster' => array('Maximum players', 'per team', 'Nobody joins past this — by code, invite, request or placement. Only an admin adding someone by hand can go over.', 'number'),
            ),
            'Requests and invites' => array(
                'max_requests' => array('Requests at a time', 'per free agent', 'How many teams a free agent can ask at once. A friend group shares one allowance.', 'number'),
                'request_days' => array('Requests and list invites last', 'days', 'They lapse after this if nobody answers — never later than the deadline.', 'number'),
                'pool_invites_extra' => array('Extra invites from the list', 'on top of the team\'s open spots', 'A captain can have their open spots plus this many invites to free agents waiting at once.', 'number'),
            ),
            'Friend groups and stepping back' => array(
                'group_max' => array('Friend group size', 'people at most', 'Friends who sign up together are always placed together.', 'number'),
                'optout_days' => array('"Not for me" window', 'days after being placed', 'Someone placed by the league can step back from their team at no cost for this long.', 'number'),
            ),
            'Placement' => array(
                'gap_fill' => array('Biggest level gap when filling open spots', 'bands', 'How far a free agent\'s level can be from a team\'s when topping up a team that isn\'t short.', 'decimal'),
                'gap_short' => array('Biggest level gap when filling a short team', 'bands', 'A little more room, so short teams get their players.', 'decimal'),
            ),
            'Places in each division' => array(
                'sit_out_nights' => array('Most nights a team sits out in a season', 'nights (0 to ' . RSL_Capacity::MAX_ALLOWANCE . ')',
                    'A division\'s places are the most teams its game slots fit with no team sitting out more than this many of the season\'s regular nights. '
                    . 'With 12 game slots a night, 2 gives 32 places over 8 nights and 29 over 11. '
                    . 'Start a team tells captains when a division is full. Approving a team past the places shows the nights it costs first, and placement makes no new team past them.', 'number'),
                'capacity_override' => array('Most teams per division', '0 = worked out from the nights above', 'A number here replaces the worked-out places for that division (up to ' . RSL_Capacity::MAX_PLACES . '). Start a team, team approvals and placement go by it.', 'number'),
            ),
            'Privacy' => array(
                'under18_in_pool' => array('Under-18s in the list captains browse', 'Show them to captains', 'Off (recommended): under-18s never appear when captains browse. They\'re still placed by the admin run, and can ask teams themselves.', 'checkbox'),
            ),
        );
    }

    /**
     * Numbers save_settings() moved into its allowed range, in plain words
     * ("Requests at a time 1 (not 0)"), so the notice doesn't claim the typed value stuck.
     */
    private static function adjusted_settings($input, $saved) {
        $labels = array();
        foreach (self::setting_fields() as $fields) {
            foreach ($fields as $key => $f) {
                $labels[$key] = $f[0];
            }
        }
        $out = array();
        foreach ((array) $saved as $key => $value) {
            $label = isset($labels[$key]) ? $labels[$key] : ucfirst(str_replace('_', ' ', (string) $key));
            // A rule with parts (the per-division team cap) is checked part by part.
            $pairs = is_array($value) ? $value : array('' => $value);
            $typed_all = is_array($value) ? (isset($input[$key]) && is_array($input[$key]) ? $input[$key] : array()) : array('' => isset($input[$key]) ? $input[$key] : null);
            foreach ($pairs as $part => $v) {
                if (!is_scalar($v) || !isset($typed_all[$part]) || !is_scalar($typed_all[$part]) || trim((string) $typed_all[$part]) === '') {
                    continue;
                }
                $typed = trim((string) $typed_all[$part]);
                if (!is_numeric($typed) || (float) $typed != (float) $v) {
                    $out[] = $label . ($part !== '' ? ' (' . ucfirst(str_replace('_', ' ', (string) $part)) . ')' : '') . ' ' . $v . ' (not ' . $typed . ')';
                }
            }
        }
        return $out;
    }

    private static function setting_row($key, $value, $label, $unit, $hint, $type) {
        $id = 'rsl-fa-' . $key;
        if ($type === 'checkbox') {
            $field = '<input type="hidden" name="' . esc_attr($key) . '" value="0"><label><input type="checkbox" id="' . esc_attr($id) . '" name="' . esc_attr($key) . '" value="1"' . checked(!empty($value), true, false) . '> ' . esc_html($unit) . '</label>';
        } elseif (is_array($value)) {
            $field = '';
            foreach ($value as $k => $v) {
                $field .= '<label class="rsl-fa-sublabel">' . esc_html(ucfirst(str_replace('_', ' ', (string) $k))) . ' <input type="number" name="' . esc_attr($key . '[' . $k . ']') . '" value="' . esc_attr(is_scalar($v) ? $v : '') . '" min="0" step="any" class="small-text"></label> ';
            }
            $field .= $unit !== '' ? '<span class="rsl-fa-sub">' . esc_html($unit) . '</span>' : '';
        } else {
            $step = $type === 'decimal' || is_float($value) ? '0.1' : '1';
            $field = '<input type="number" id="' . esc_attr($id) . '" name="' . esc_attr($key) . '" value="' . esc_attr(is_scalar($value) ? $value : '') . '" min="0" step="' . $step . '" class="small-text">' . ($unit !== '' ? ' ' . esc_html($unit) : '');
        }
        return '<tr><th scope="row"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label></th><td>' . $field . ($hint !== '' ? '<p class="description">' . esc_html($hint) . '</p>' : '') . '</td></tr>';
    }

    private static function settings_tab($season) {
        $post = admin_url('admin-post.php');
        $s = RSL_Free_Agents::settings();
        $h = '<h2>Rules</h2><p class="description">These apply to every season.</p>';
        $h .= '<form method="post" action="' . esc_url($post) . '">' . self::fields('save_settings', $season, 'settings');
        $shown = array();
        foreach (self::setting_fields() as $section => $fields) {
            $rows = '';
            foreach ($fields as $key => $f) {
                if (!array_key_exists($key, $s)) {
                    continue;
                }
                $shown[$key] = true;
                $rows .= self::setting_row($key, $s[$key], $f[0], $f[1], $f[2], $f[3]);
            }
            if ($rows !== '') {
                $h .= '<h3>' . esc_html($section) . '</h3><table class="form-table" role="presentation">' . $rows . '</table>';
            }
        }
        $other = '';
        foreach ($s as $key => $value) {
            if (!isset($shown[$key])) {
                $other .= self::setting_row($key, $value, ucfirst(str_replace('_', ' ', $key)), '', '', 'number');
            }
        }
        if ($other !== '') {
            $h .= '<h3>Other</h3><table class="form-table" role="presentation">' . $other . '</table>';
        }
        $h .= '<p class="submit"><button class="button button-primary">Save rules</button></p></form>';
        return $h . self::dates_form($season) . self::signup_form($season);
    }

    /** This season's own dates (blank = worked out from its first game night and the rules). */
    private static function dates_form($season) {
        $b = RSL_Free_Agents::building($season);
        $s = RSL_Free_Agents::settings();
        $first = (string) RSL_Courts::settings($season)['start'];
        $has_first = (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $first);
        $h = '<h2>' . esc_html(RSL_Seasons::label($season)) . ' dates</h2>';
        if (!$has_first) {
            $h .= '<div class="notice notice-warning inline"><p>This season has no first game night yet, so its dates can\'t be worked out: the list stays open with no deadline, and players see no dates. '
                . self::courts_link($season, 'Set the season up in Courts & Nights') . '.</p></div>';
        } else {
            $h .= '<p class="description">First game night: <strong>' . esc_html(self::day_long($first)) . '</strong>. Leave a date blank to work it out from the rules above, or set one to change it for this season only.</p>';
        }
        $h .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . self::fields('save_dates', $season, 'settings') . '<table class="form-table" role="presentation">';
        foreach (array('opens' => array('Team-building window opens', 'window_opens_days'), 'deadline' => array('Deadline', 'deadline_days'), 'confirm_by' => array('Players hear about their team by', 'confirm_by_days')) as $key => $f) {
            $auto = $has_first ? date('Y-m-d', strtotime($first . ' -' . intval($s[$f[1]]) . ' days')) : '';
            $h .= '<tr><th scope="row"><label for="rsl-fa-d-' . esc_attr($key) . '">' . esc_html($f[0]) . '</label></th><td><input type="date" id="rsl-fa-d-' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_attr($b[$key]) . '">'
                . ($auto !== '' ? ' <span class="rsl-fa-sub">worked out: ' . esc_html(self::day_long($auto)) . '</span>' : '') . '</td></tr>';
        }
        $h .= '</table><p class="submit"><button class="button button-primary">Save dates</button> <button class="button" name="clear" value="1">Use the worked-out dates</button></p></form>';
        return $h;
    }

    private static function signup_form($season) {
        $pinned = get_option(RSL_Seasons::SIGNUP_OPTION, '');
        $pinned = RSL_Seasons::is_valid($pinned) ? $pinned : '';
        $signup = RSL_Seasons::signup();
        $current = RSL_Seasons::current();
        $choices = RSL_Seasons::choices();
        foreach (array($signup, $current, $pinned) as $key) {
            if ($key !== '' && !isset($choices[$key])) {
                $choices[$key] = RSL_Seasons::label($key);
            }
        }
        $h = '<h2>Sign-up season</h2>'
            . '<p class="description">The season people sign up for: joining a team, applying to start one, and the free agent list. Automatic means the current season (' . esc_html(RSL_Seasons::label($current)) . ', the one the public pages show) '
            . 'until next season\'s first game night is set and its team-building window opens — then next season. Pin one only if that isn\'t right.</p>'
            . '<p>Right now players are signing up for <strong>' . esc_html(RSL_Seasons::label($signup)) . '</strong> ' . ($pinned !== '' ? '(pinned)' : '(automatic)') . '.</p>'
            . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="rsl-fa-actions">' . self::fields('set_signup', $season, 'settings')
            . '<select name="signup"><option value="">Automatic</option>';
        foreach ($choices as $key => $label) {
            $h .= '<option value="' . esc_attr($key) . '"' . selected($pinned, $key, false) . '>' . esc_html($label) . '</option>';
        }
        return $h . '</select> <button class="button">Save</button></form>';
    }

    private static function styles() {
        return '<style>
            .rsl-fa .rsl-fa-season { color: #646970; font-weight: 400; font-size: 15px; margin-left: 6px; }
            .rsl-fa .count { color: #646970; font-weight: 400; font-size: 14px; }
            .rsl-fa-pick { margin: 10px 0 4px; }
            .rsl-fa-box { background: #fff; border: 1px solid #c3c4c7; padding: 10px 14px; margin: 14px 0; max-width: 1100px; }
            .rsl-fa-box p { margin: 6px 0; }
            .rsl-fa-phase { font-size: 14px; }
            .rsl-fa-dates th, .rsl-fa-dates td { text-align: left; padding: 2px 18px 2px 0; font-weight: 400; }
            .rsl-fa-dates th { color: #50575e; }
            .rsl-fa-dates tr.is-past { color: #8c8f94; }
            .rsl-fa-stats { display: flex; flex-wrap: wrap; gap: 6px 24px; margin: 4px 0 8px; }
            .rsl-fa-stats li { margin: 0; }
            .rsl-fa-stats strong { font-size: 18px; }
            .rsl-fa-scroll { overflow-x: auto; max-width: 100%; }
            .rsl-fa-table { min-width: 1250px; }
            .rsl-fa-table td, .rsl-fa-draft td, .rsl-fa-list td { vertical-align: top; }
            .rsl-fa-list { max-width: 1100px; }
            .rsl-fa-draft { min-width: 900px; }
            .rsl-fa-sub { color: #646970; font-size: 12px; }
            .rsl-fa-bad { color: #b32d2e; font-weight: 600; }
            .rsl-fa-warnline { color: #8a6100; }
            .rsl-fa-pill { display: inline-block; padding: 1px 8px; border-radius: 9px; font-size: 11px; font-weight: 600; background: #f0f0f1; color: #3c434a; }
            .rsl-fa-pill.warn { background: #fcf9e8; color: #8a6100; }
            .rsl-fa-pill.bad { background: #fcf0f1; color: #8a1f1f; }
            .rsl-fa-pill.good { background: #edfaef; color: #00762a; }
            .rsl-fa-pill.info { background: #e9f0fb; color: #1d3d6e; }
            .rsl-fa-chip { display: inline-block; margin: 0 4px 3px 0; padding: 1px 7px; border-radius: 3px; background: #f0f6fc; border: 1px solid #c5d9ed; font-size: 11px; }
            .rsl-fa-chip.locked { background: #fff8e5; border-color: #f0c33c; }
            .rsl-fa-star { color: #dba617; }
            .rsl-fa-band { width: 64px; }
            .rsl-fa-note { width: 190px; }
            .rsl-fa-inline { display: flex; gap: 4px; align-items: center; margin: 0; }
            .rsl-fa-place select, .rsl-fa-move select { max-width: 240px; }
            .rsl-fa-move { display: flex; gap: 4px; align-items: center; margin: 0; }
            .rsl-fa-more > summary { cursor: pointer; font-weight: 600; font-size: 14px; margin: 16px 0 6px; }
            .rsl-fa-block { background: #fff; border: 1px solid #c3c4c7; margin: 12px 0; padding: 8px 14px 12px; max-width: 1250px; }
            .rsl-fa-block h3 { margin: 6px 0 8px; }
            .rsl-fa-newteam { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin: 4px 0 8px; }
            .rsl-fa-newteam input.is-placeholder { border-color: #d63638; box-shadow: 0 0 0 1px #d63638; }
            .rsl-fa-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin: 10px 0; }
            .rsl-fa-actions form { margin: 0; }
            .rsl-fa-confirm { margin: 8px 0 2px; }
            .rsl-fa-ul { list-style: disc; margin: 4px 0 6px 20px; }
            .rsl-fa-sublabel { margin-right: 12px; }
        </style>';
    }
}
