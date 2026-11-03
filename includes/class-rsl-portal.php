<?php
/**
 * The player's league page ([rsl_my_league], on the auto-created "Social
 * League" page): register, see whether you're cleared to play (and pay the
 * rego), join a team with an invite code, accept invites, apply for a team
 * as captain — and, for captains, the team tools.
 *
 * Forms post back to the page and are handled on template_redirect, then
 * redirect with a one-off notice (Post/Redirect/Get). Every action checks
 * the nonce and, server-side, that this person may do it.
 *
 * Privacy: a captain sees teammates' names and ✓ cleared / ✗ needs rego —
 * never WHY (membership vs paid rego), and nothing about people they've
 * invited by email beyond the email they typed.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RSL_Portal {

    const PAGE_OPTION = 'rsl_portal_page_id';
    const FLASH_PREFIX = 'rsl_flash_';

    // A captain application that failed: what was typed, for the #rsl-apply card on the next
    // page view (taken and deleted there). Per user, short-lived; not league data (like the flash,
    // not in RSL_Merge::uses()). As long as the flash (60 seconds), so the card never opens
    // filled in without the message that says why.
    const APPLY_DRAFT_PREFIX = 'rsl_apply_draft_';
    const APPLY_DRAFT_TTL = 60;

    // Remembering a captain's invite through sign-up: the code is kept in a
    // cookie when the link is opened, copied onto the account when it's
    // created (covers switching devices), and offered on the league page
    // until they join. Only the invite code is stored.
    const JOIN_COOKIE = 'rsl_join';
    const JOIN_META = 'rsl_pending_join';
    const SEND_META = 'rsl_send_to_portal';
    const JOIN_DAYS = 30;

    public function __construct() {
        add_shortcode('rsl_my_league', array($this, 'render'));
        add_action('template_redirect', array($this, 'remember_invite_from_link'), 5);
        add_action('template_redirect', array($this, 'maybe_send_to_portal'), 6);
        add_action('template_redirect', array($this, 'maybe_handle_post'));
        add_action('user_register', array(__CLASS__, 'carry_invite_to_new_account'));
        add_action('wp_login', array(__CLASS__, 'flag_after_login'), 10, 2);
        add_filter('wp_nav_menu_objects', array(__CLASS__, 'menu_labels'), 10, 2);
    }

    // ------------------------------------------------------------------
    // The league page's menu item (0.17.0)
    // ------------------------------------------------------------------

    /** menu_state() by user id, for this request (flush_menu_state() empties it; tests use it). */
    private static $menu_states = array();

    /**
     * The league page's menu item follows the person (0.17.0, wp_nav_menu_objects): "Register
     * for 2027" (the year of menu_season(): the sign-up season's; once the sign-up season has
     * finished, the first open season's, or with none open the next season's, 0.18.0) until
     * they've registered, "My team" while they're on an active team or have a team application
     * waiting in a season the league page shows, else "My league". Logged-in viewers only: a
     * logged-out visitor's item is static (and cached pages serve it), so it is never touched,
     * nor is an item Nav Menu Roles keeps for logged-out
     * visitors (_nav_menu_role 'out'; that plugin has already removed it for logged-in viewers on
     * wp_get_nav_menu_items, this is belt and braces). Only items for the page itself (type
     * post_type, object_id = the page), and never a dropdown heading (an item other items sit
     * under: its words head the links below it). A menu without one reads nothing. One query
     * per page view at most (menu_state(), memoised).
     */
    public static function menu_labels($items, $args = null) {
        if (!is_array($items) || !$items || !is_user_logged_in()) {
            return $items;
        }
        $page_id = intval(get_option(self::PAGE_OPTION));
        if (!$page_id) {
            return $items;
        }
        $parents = array();
        foreach ($items as $item) {
            if (is_object($item) && !empty($item->menu_item_parent)) {
                $parents[intval($item->menu_item_parent)] = true;
            }
        }
        $label = null;
        foreach ($items as $item) {
            if (!is_object($item) || !isset($item->type, $item->object_id) || $item->type !== 'post_type' || intval($item->object_id) !== $page_id) {
                continue;
            }
            $db_id = intval(isset($item->db_id) ? $item->db_id : (isset($item->ID) ? $item->ID : 0));
            if ($db_id && isset($parents[$db_id])) {
                continue; // a dropdown heading keeps its words (checked before any meta read)
            }
            if (!empty($item->ID) && get_post_meta($item->ID, '_nav_menu_role', true) === 'out') {
                continue;
            }
            if ($label === null) {
                $label = self::menu_label(self::menu_state(get_current_user_id()), self::menu_season());
            }
            $item->title = $label;
        }
        return $items;
    }

    /**
     * The words for a state: 'team' My team, 'league' My league, else "Register for <the year of
     * $signup>" (menu_labels() passes menu_season(): the sign-up season's; once the sign-up season
     * has finished, the first open season's, or with none open the next season's). Pure.
     */
    public static function menu_label($state, $signup) {
        if ($state === 'team') {
            return 'My team';
        }
        if ($state === 'league') {
            return 'My league';
        }
        return 'Register for ' . (RSL_Seasons::is_valid($signup) ? substr($signup, 0, 4) : current_time('Y'));
    }

    /**
     * The season whose year "Register for" names (0.18.0): the sign-up season, or, once it has
     * finished (a pin left on a season that's over), the first season open for sign-up (calendar
     * order); with nothing open, the season after the finished one (RSL_Seasons::next()), never
     * the finished season itself. Options only, which the page has read by then, so the menu
     * still costs one query at most.
     */
    public static function menu_season() {
        $signup = RSL_Seasons::signup();
        $span = RSL_Seasons::span($signup);
        if ($span && $span['end'] < current_time('Y-m-d')) {
            $open = RSL_Seasons::open_for_signup();
            if ($open) {
                reset($open);
                return (string) key($open);
            }
            return RSL_Seasons::next($signup);
        }
        return $signup;
    }

    /**
     * 'register' (no league registration), 'team' (on an active team, or a team application of
     * theirs waiting, in a season the league page shows) or 'league'. One query; memoised.
     */
    public static function menu_state($user_id) {
        global $wpdb;
        $user_id = intval($user_id);
        if (!$user_id) {
            return 'register';
        }
        if (isset(self::$menu_states[$user_id])) {
            return self::$menu_states[$user_id];
        }
        $seasons = self::page_seasons();
        $in = implode(',', array_fill(0, count($seasons), '%s'));
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT EXISTS(SELECT 1 FROM " . RSL_Database::table('players') . " WHERE user_id = %d) AS registered,
                EXISTS(SELECT 1 FROM " . RSL_Database::table('team_members') . " m JOIN " . RSL_Database::table('teams') . " t ON t.id = m.team_id
                    WHERE m.user_id = %d AND m.left_at IS NULL AND t.status = 'active' AND t.season IN ($in)) AS on_team,
                EXISTS(SELECT 1 FROM " . RSL_Database::table('teams') . " WHERE applied_by = %d AND status = 'pending' AND season IN ($in)) AS applied",
            array_merge(array($user_id, $user_id), $seasons, array($user_id), $seasons)
        ));
        $state = !$row || !(int) $row->registered ? 'register' : (((int) $row->on_team || (int) $row->applied) ? 'team' : 'league');
        return self::$menu_states[$user_id] = $state;
    }

    /** Forget the memoised menu states (tests change a person's state within one request). */
    public static function flush_menu_state() {
        self::$menu_states = array();
    }

    /** The seasons the league page shows: the one being played, the sign-up season and those open for sign-up. Options only. */
    private static function page_seasons() {
        return array_values(array_unique(array_merge(array(RSL_Seasons::current(), RSL_Seasons::signup()), array_keys(RSL_Seasons::signup_choices()))));
    }

    // ------------------------------------------------------------------
    // Remembered invite
    // ------------------------------------------------------------------

    public static function clean_code($code) {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $code));
    }

    private static function set_cookie($code, $days) {
        if ($code === '') {
            unset($_COOKIE[self::JOIN_COOKIE]);
        } else {
            $_COOKIE[self::JOIN_COOKIE] = $code;
        }
        if (!headers_sent()) {
            setcookie(self::JOIN_COOKIE, $code, $days > 0 ? time() + $days * DAY_IN_SECONDS : time() - HOUR_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
        }
    }

    /** Opening an invite link on the league page remembers its code. */
    public function remember_invite_from_link() {
        $page_id = intval(get_option(self::PAGE_OPTION));
        if (!$page_id || !is_page($page_id) || empty($_GET['join'])) {
            return;
        }
        $code = self::clean_code(wp_unslash($_GET['join']));
        if ($code === '' || !RSL_Teams::get_by_code($code)) {
            return;
        }
        self::set_cookie($code, self::JOIN_DAYS);
        if (is_user_logged_in()) {
            update_user_meta(get_current_user_id(), self::JOIN_META, $code);
        }
    }

    /** A new account made in a browser holding an invite keeps the invite. */
    public static function carry_invite_to_new_account($user_id) {
        $code = isset($_COOKIE[self::JOIN_COOKIE]) ? self::clean_code(wp_unslash($_COOKIE[self::JOIN_COOKIE])) : '';
        if ($code !== '' && RSL_Teams::get_by_code($code)) {
            update_user_meta($user_id, self::JOIN_META, $code);
            update_user_meta($user_id, self::SEND_META, 1);
        }
    }

    /** Logging in with an invite waiting (and no team yet): bring them to the league page. */
    public static function flag_after_login($login, $user) {
        if ($user && self::remembered_code($user->ID) !== '' && !self::team_for_code($user->ID, self::remembered_code($user->ID))) {
            update_user_meta($user->ID, self::SEND_META, 1);
        }
    }

    /** The team this person already has in the remembered invite's season, or null. */
    private static function team_for_code($user_id, $code) {
        $team = $code !== '' ? RSL_Teams::get_by_code($code) : null;
        return RSL_Teams::current_team_for_user($user_id, $team ? $team->season : RSL_Seasons::signup());
    }

    /**
     * The invite this person is part-way through using: from their account,
     * else this browser's cookie. '' when none, or when the team's link has
     * changed since (then it's forgotten).
     */
    public static function remembered_code($user_id = 0) {
        $code = $user_id ? self::clean_code(get_user_meta($user_id, self::JOIN_META, true)) : '';
        if ($code === '' && isset($_COOKIE[self::JOIN_COOKIE])) {
            $code = self::clean_code(wp_unslash($_COOKIE[self::JOIN_COOKIE]));
        }
        if ($code !== '' && !RSL_Teams::get_by_code($code)) {
            self::forget_invite($user_id);
            return '';
        }
        return $code;
    }

    public static function forget_invite($user_id = 0) {
        if ($user_id) {
            delete_user_meta($user_id, self::JOIN_META);
            delete_user_meta($user_id, self::SEND_META);
        }
        self::set_cookie('', 0);
    }

    /**
     * Where to send this request instead, or '' to leave it be: someone
     * flagged (new account / login with an invite waiting) goes to the
     * league page — but never away from the club's account, profile, login
     * or registration pages, so the profile-update step can finish first.
     */
    public static function portal_redirect_for_request($user_id, $on_portal, $on_account_page) {
        if (!$user_id || !get_user_meta($user_id, self::SEND_META, true) || $on_portal || $on_account_page) {
            return '';
        }
        $code = self::remembered_code($user_id);
        if ($code === '' || self::team_for_code($user_id, $code)) {
            delete_user_meta($user_id, self::SEND_META);
            return '';
        }
        return self::url();
    }

    public function maybe_send_to_portal() {
        if (!is_user_logged_in() || is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || $_SERVER['REQUEST_METHOD'] !== 'GET') {
            return;
        }
        $page_id = intval(get_option(self::PAGE_OPTION));
        if ($page_id && is_page($page_id)) {
            return; // already here — the flag clears when the page actually renders
        }
        $on_account = false;
        if (function_exists('um_is_core_page')) {
            foreach (array('account', 'profile', 'user', 'login', 'register', 'password-reset', 'logout') as $core) {
                if (um_is_core_page($core)) {
                    $on_account = true;
                }
            }
        }
        $to = self::portal_redirect_for_request(get_current_user_id(), false, $on_account);
        if ($to !== '') {
            wp_safe_redirect($to);
            exit;
        }
    }

    /** The Social League page (a trashed or deleted one is made again — every email links here). */
    public static function ensure_page() {
        return RSL_Public::ensure_page(self::PAGE_OPTION, 'Social League', 'social-league', '[rsl_my_league]');
    }

    public static function url() {
        $page_id = intval(get_option(self::PAGE_OPTION));
        return $page_id ? get_permalink($page_id) : home_url('/');
    }

    public static function invite_url($team) {
        return add_query_arg('join', RSL_Teams::invite_code($team), self::url());
    }

    // ------------------------------------------------------------------
    // Actions
    // ------------------------------------------------------------------

    public function maybe_handle_post() {
        if (!isset($_POST['rsl_front'])) {
            return;
        }
        $back = self::process($_POST);
        wp_safe_redirect($back);
        exit;
    }

    /** Do one front-end action; returns where to go next. Testable (no exit). */
    public static function process($post) {
        $user_id = get_current_user_id();
        $back = self::url();
        if (!$user_id || !isset($post['rsl_nonce']) || !wp_verify_nonce($post['rsl_nonce'], 'rsl_front')) {
            self::flash('error', 'Your session expired — please try again.');
            return $back;
        }
        // Applying and the free agent list are for the season the player picks
        // (RSL_Seasons::pick_open()); joining by code is for the team's own season.
        $do = isset($post['do']) ? sanitize_key($post['do']) : '';
        $code = isset($post['join_code']) ? sanitize_text_field(wp_unslash($post['join_code'])) : '';

        // Free agent and captain "looking for players" actions.
        if (class_exists('RSL_Portal_FA')) {
            $fa = RSL_Portal_FA::process($do, $post, $user_id);
            if (is_array($fa)) {
                self::flash($fa['type'], $fa['message']);
                return !empty($fa['back']) ? wp_validate_redirect($fa['back'], $back) : $back;
            }
        }

        switch ($do) {
            case 'register':
                $route = isset($post['route']) ? sanitize_key($post['route']) : '';
                $result = RSL_Players::register($user_id, array(
                    'level' => isset($post['level']) ? sanitize_key($post['level']) : '',
                    'years' => isset($post['years']) ? sanitize_key($post['years']) : '',
                    'positions' => isset($post['positions']) ? array_map('sanitize_key', (array) $post['positions']) : array(),
                    'looking' => $route === '' && !empty($post['looking']),
                ));
                if (is_wp_error($result)) {
                    self::flash('error', $result->get_error_message());
                    return $code !== '' ? add_query_arg('join', $code, $back) : $back;
                }
                if (!empty($post['ref'])) {
                    RSL_Officials::apply_ref($user_id);
                }
                if ($code !== '' && in_array($route, array('', 'join'), true)) {
                    $team = RSL_Teams::join_by_code($user_id, $code);
                    if (!is_wp_error($team)) {
                        self::forget_invite($user_id);
                    }
                    self::flash(is_wp_error($team) ? 'error' : 'success', is_wp_error($team)
                        ? 'You\'re registered. ' . $team->get_error_message()
                        : 'You\'re registered and on ' . $team->name . '.');
                    return $back;
                }
                if (class_exists('RSL_Portal_FA') && $route !== '') {
                    $next = RSL_Portal_FA::after_register($user_id, $post);
                    if (is_array($next)) {
                        self::flash($next['type'], $next['message']); // each message already says they're registered
                        return !empty($next['open_apply']) ? $back . '#rsl-apply' : $back;
                    }
                }
                self::flash('success', 'Thanks — you\'re registered for the Reds Social League.');
                return $back;

            case 'update_details':
                // Experience only: saving details never puts anyone on (or
                // takes them off) a free agent list.
                $result = RSL_Players::register($user_id, array(
                    'level' => isset($post['level']) ? sanitize_key($post['level']) : '',
                    'years' => isset($post['years']) ? sanitize_key($post['years']) : '',
                    'positions' => isset($post['positions']) ? array_map('sanitize_key', (array) $post['positions']) : array(),
                ));
                if (!is_wp_error($result) && !empty($post['ref_shown'])) {
                    !empty($post['ref']) ? RSL_Officials::apply_ref($user_id) : RSL_Officials::withdraw_ref($user_id);
                }
                self::flash(is_wp_error($result) ? 'error' : 'success', is_wp_error($result) ? $result->get_error_message() : 'Your details are saved.');
                return $back;

            case 'join':
                $team = RSL_Teams::join_by_code($user_id, $code);
                if (!is_wp_error($team)) {
                    self::forget_invite($user_id);
                }
                self::flash(is_wp_error($team) ? 'error' : 'success', is_wp_error($team) ? $team->get_error_message() : 'You\'re on ' . $team->name . '.');
                return $back;

            case 'looking':
                RSL_Players::set_looking($user_id, !empty($post['looking']));
                self::flash('success', !empty($post['looking']) ? 'You\'re on the free agent list — we\'ll be in touch when a team needs players.' : 'You\'re off the free agent list.');
                return $back;

            case 'apply':
                $team = self::apply_from_post($user_id, $post, true);
                if (is_wp_error($team)) {
                    self::flash('error', $team->get_error_message());
                    return self::apply_retry($team) ? $back . '#rsl-apply' : $back;
                }
                self::flash('success', 'Application sent for ' . $team->name . self::season_aside($team->season) . '. We\'ll email you when it\'s approved.');
                return $back;

            case 'withdraw':
                $result = RSL_Teams::withdraw(isset($post['team_id']) ? intval($post['team_id']) : 0, $user_id);
                self::flash(is_wp_error($result) ? 'error' : 'success', is_wp_error($result) ? $result->get_error_message() : 'Application withdrawn.');
                return $back;

            case 'accept_invite':
            case 'decline_invite':
                $accept = $do === 'accept_invite';
                $result = RSL_Teams::respond_invite(isset($post['invite_id']) ? intval($post['invite_id']) : 0, $user_id, $accept);
                self::flash(is_wp_error($result) ? 'error' : 'success', is_wp_error($result) ? $result->get_error_message() : ($accept ? 'You\'ve joined the team.' : 'Invite declined.'));
                return $back;

            case 'leave_team':
                $result = RSL_Teams::leave(isset($post['team_id']) ? intval($post['team_id']) : 0, $user_id);
                self::flash(is_wp_error($result) ? 'error' : 'success', is_wp_error($result) ? $result->get_error_message() : 'You\'ve left the team. You can join another one, or go on the free agent list.');
                return $back;
        }

        // Night requests come from the team page; go back there, even on a refusal.
        $back_to = isset($post['back']) ? wp_validate_redirect(esc_url_raw(wp_unslash($post['back'])), $back) : $back;
        // Captain tools: the person must captain THIS team (or be a league admin).
        $team = RSL_Teams::get(isset($post['team_id']) ? intval($post['team_id']) : 0);
        if (!$team || !(RSL_Teams::is_captain($team->id, $user_id) || user_can($user_id, 'manage_options'))) {
            self::flash('error', 'Only the team captain can do that.');
            return $back_to;
        }
        $night_id = isset($post['night_id']) ? intval($post['night_id']) : 0;
        switch ($do) {
            case 'request_off':
            case 'request_double':
            case 'cant_make':
                $type = array('request_off' => 'off', 'request_double' => 'double', 'cant_make' => 'cant_make')[$do];
                $result = RSL_Requests::request($team->id, $night_id, $type, $user_id);
                $done = array(
                    'off' => 'Week off requested — you won\'t be fixtured that night.',
                    'double' => 'Two games requested — you\'ll get a double-header if there\'s a spare slot.',
                    'cant_make' => 'Thanks for letting us know — the league will sort it out with the other team and be in touch.',
                );
                if (!is_wp_error($result) && $type === 'cant_make') {
                    // Say what sync_night() actually charged: a late "can't make it" is free for a game
                    // the other team had already pulled out of.
                    $late_fee = empty($result->late) ? 0 : RSL_Payments::late_fees_for_request($result->id);
                    $done['cant_make'] .= $late_fee > 0
                        ? ' It\'s past the free cut-off, so a late fee of ' . RSL_Payments::money($late_fee) . ' (both teams\' game fees) is on your team balance — it comes off again if you withdraw before the league sorts it out.'
                        : (empty($result->late) ? ' No charge — it\'s before the cut-off.' : ' No charge — the other team had already pulled out.');
                }
                self::flash(is_wp_error($result) ? 'error' : 'success', is_wp_error($result) ? $result->get_error_message() : $done[$type]);
                return $back_to;
            case 'cancel_request':
                $result = RSL_Requests::cancel(isset($post['request_id']) ? intval($post['request_id']) : 0, $user_id);
                self::flash(is_wp_error($result) ? 'error' : 'success', is_wp_error($result) ? $result->get_error_message() : 'Request withdrawn.');
                return $back_to;
            case 'invite':
                $result = RSL_Teams::invite_by_email($team->id, $user_id, isset($post['email']) ? wp_unslash($post['email']) : '');
                self::flash(is_wp_error($result) ? 'error' : 'success', is_wp_error($result) ? $result->get_error_message() : 'Invite saved. If they\'ve registered for the league they\'ll see it now; if not, it\'ll be waiting when they register with that email — or send them your invite link.');
                return $back;
            case 'cancel_invite':
                $result = RSL_Teams::cancel_invite(isset($post['invite_id']) ? intval($post['invite_id']) : 0, $user_id);
                self::flash(is_wp_error($result) ? 'error' : 'success', is_wp_error($result) ? $result->get_error_message() : 'Invite cancelled.');
                return $back;
            case 'remove_member':
                $member_id = isset($post['user_id']) ? intval($post['user_id']) : 0;
                if ($member_id === $user_id) {
                    self::flash('error', 'Captains can\'t remove themselves — ask the league admin to hand over the captaincy.');
                    return $back;
                }
                $result = RSL_Teams::remove_member($team->id, $member_id);
                self::flash(is_wp_error($result) ? 'error' : 'success', is_wp_error($result) ? $result->get_error_message() : 'Player removed from the team.');
                return $back;
            case 'new_code':
                RSL_Teams::regenerate_code($team->id);
                self::flash('success', 'New invite link made — the old one no longer works.');
                return $back;
            case 'save_prefs':
                $result = RSL_Teams::set_preferences($team->id, $user_id, array(
                    'time' => isset($post['pref_time']) ? sanitize_key($post['pref_time']) : 'any',
                    'double' => !empty($post['pref_double']),
                    'shape' => isset($post['pref_double_shape']) ? sanitize_key($post['pref_double_shape']) : 'back',
                    'standby' => !empty($post['pref_standby']),
                ));
                self::flash(is_wp_error($result) ? 'error' : 'success', is_wp_error($result) ? $result->get_error_message() : 'Team preferences saved — fixtures use them from the next run.');
                return $back;
        }

        self::flash('error', 'Unknown action.');
        return $back;
    }

    private static function flash($type, $message) {
        set_transient(self::FLASH_PREFIX . get_current_user_id(), array('type' => $type, 'message' => $message), 60);
    }

    /** Leave a message for the signed-in person's next page (used by other front-end pages). */
    public static function notify($type, $message) {
        self::flash($type, $message);
    }

    /** The waiting message for the signed-in person (shown on the team page too). */
    public static function flash_html() {
        return get_current_user_id() ? self::take_flash() : '';
    }

    /** Hidden fields for a front-end action form (used by the team page too). */
    public static function form_fields($do, $extra = array()) {
        return self::fields($do, $extra);
    }

    private static function take_flash() {
        $key = self::FLASH_PREFIX . get_current_user_id();
        $flash = get_transient($key);
        delete_transient($key);
        return is_array($flash) ? '<div class="rsl-flash rsl-flash-' . esc_attr($flash['type']) . '">' . esc_html($flash['message']) . '</div>' : '';
    }

    // ------------------------------------------------------------------
    // The season picker and the captain application (0.17.0)
    // ------------------------------------------------------------------

    /**
     * The season picker, the one markup wherever a player says which season (0.17.0): the
     * registration form's Find me a team and Start a team panels, the Find me a team card and
     * the captain application card. A select named $name over $choices (key => label with dates,
     * from RSL_Seasons::signup_choices(), maybe narrowed), $selected chosen when offered, else
     * RSL_Seasons::default_pick(). One choice: a hidden field and the season named. '' with no
     * choices. Never required (it sits in CSS-hidden panels too): RSL_Seasons::pick_open()
     * checks it.
     */
    public static function season_field($name, $choices, $selected = '') {
        $choices = is_array($choices) ? $choices : array();
        if (!$choices) {
            return '';
        }
        if (!isset($choices[$selected])) {
            $selected = RSL_Seasons::default_pick($choices);
        }
        if (count($choices) === 1) {
            return '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($selected) . '">'
                . '<p class="rsl-hint">For ' . esc_html($choices[$selected]) . '.</p>';
        }
        $html = '<label class="rsl-field">Which season? <select name="' . esc_attr($name) . '">';
        foreach ($choices as $key => $label) {
            $html .= '<option value="' . esc_attr($key) . '"' . selected($key, $selected, false) . '>' . esc_html($label) . '</option>';
        }
        return $html . '</select></label>';
    }

    /**
     * The team application's fields (0.17.0), one markup for the registration form's Start a team
     * panel and the #rsl-apply card: which season (season_field(), team_season), team name,
     * division, roughly what level, colour. $v: values to show again (an apply draft: name,
     * division, level, colour, season). $required: the card's browser checks; never in the
     * registration panel, which CSS hides while another route is chosen (a hidden required field
     * would block the form). The server checks either way (RSL_Teams::apply(), pick_open()).
     */
    public static function team_fields($v, $choices, $required) {
        $v = array_merge(array('name' => '', 'division' => 'ranked', 'level' => '', 'colour' => '', 'season' => ''), is_array($v) ? $v : array());
        $req = $required ? ' required' : '';
        ob_start();
        ?>
        <?php echo self::season_field('team_season', $choices, (string) $v['season']); ?>
        <label class="rsl-field">Team name <input type="text" name="team_name" maxlength="100" value="<?php echo esc_attr($v['name']); ?>"<?php echo $req; ?>></label>
        <label class="rsl-field">Division
            <select name="team_division"><?php foreach (RSL_Teams::DIVISIONS as $key => $label): ?><option value="<?php echo esc_attr($key); ?>"<?php selected($v['division'], $key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select>
        </label>
        <label class="rsl-field">Roughly what level does your team play at?
            <select name="team_level"<?php echo $req; ?>>
                <option value="">Choose…</option>
                <?php foreach (RSL_Teams::levels() as $key => $label): ?><option value="<?php echo esc_attr($key); ?>"<?php selected($v['level'], $key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
            </select>
        </label>
        <p class="rsl-hint">Just a starting guess so your first games are even — it isn't shown anywhere, and your results take over after a couple of weeks.</p>
        <label class="rsl-field">Team colour <input type="color" name="team_colour" value="<?php echo esc_attr($v['colour'] !== '' ? $v['colour'] : '#1d3d6e'); ?>"></label>
        <p class="rsl-hint">You'll be the captain. Once the league approves it you'll get an invite link for your players.</p>
        <?php
        return ob_get_clean();
    }

    /** A scalar POST value, unslashed and trimmed; null when absent. */
    private static function post_value($post, $name) {
        return isset($post[$name]) && is_scalar($post[$name]) ? trim((string) wp_unslash($post[$name])) : null;
    }

    /**
     * The team fields from a form, cleaned (RSL_Teams::apply() and pick_open() check them).
     * $legacy (do=apply only): the card's names before 0.17.0 too, for a page opened before the
     * update. Never at registration, whose form posts the player's own level as `level`.
     */
    private static function team_values($post, $legacy) {
        $get = function ($new, $old) use ($post, $legacy) {
            $v = self::post_value($post, $new);
            if ($v === null && $legacy && $old !== '') {
                $v = self::post_value($post, $old);
            }
            return (string) $v;
        };
        $division = sanitize_key($get('team_division', 'division'));
        return array(
            'name' => sanitize_text_field($get('team_name', 'name')),
            'division' => isset(RSL_Teams::DIVISIONS[$division]) ? $division : 'ranked',
            'level' => sanitize_key($get('team_level', 'level')),
            'colour' => (string) sanitize_hex_color($get('team_colour', 'colour')),
            'season' => sanitize_key($get('team_season', '')),
        );
    }

    /**
     * Whether a failed application can be sent again from the #rsl-apply card (0.17.0): not
     * when the card won't be there for that season or can't succeed (already on a team, an
     * application waiting, too many withdrawn this season), nor while nothing is open for
     * sign-up (the card isn't there, 0.18.0). Decides the draft, the "send it again" sentence
     * and the #rsl-apply redirect.
     */
    public static function apply_retry($error) {
        return !(is_wp_error($error) && in_array($error->get_error_code(), array('rsl_pending', 'rsl_elsewhere', 'rsl_withdrawn', 'rsl_signup_closed'), true));
    }

    /**
     * The captain application from a form (0.17.0): the #rsl-apply card ($legacy true) or Start
     * a team at registration. The season through RSL_Seasons::pick_open(), then RSL_Teams::apply().
     * Returns the pending team (always an object) or WP_Error. A second submit of the same
     * application (a double click, a re-sent POST) returns the application the first one made.
     * On an error worth retrying what was typed is kept for the card (APPLY_DRAFT_PREFIX); on
     * success any old draft goes.
     */
    public static function apply_from_post($user_id, $post, $legacy = false) {
        $user_id = intval($user_id);
        $v = self::team_values(is_array($post) ? $post : array(), $legacy);
        $season = RSL_Seasons::pick_open($v['season']);
        $team = is_wp_error($season) ? $season : RSL_Teams::apply($season, $user_id, array(
            'name' => $v['name'],
            'division' => $v['division'],
            'colour' => $v['colour'],
            'level' => $v['level'],
        ));
        if (!is_wp_error($team) && !is_object($team)) {
            $team = new WP_Error('rsl_save', 'That team couldn\'t be saved — please try again.');
        }
        // The same application sent twice: the first went in, so the second is that success,
        // not "you have an application waiting" over the first one's flash.
        if (is_wp_error($team) && !is_wp_error($season) && $v['name'] !== ''
            && in_array($team->get_error_code(), array('rsl_pending', 'rsl_duplicate', 'rsl_save'), true)) {
            $mine = RSL_Teams::pending_application_for_user($user_id, $season);
            if ($mine && $mine->name === $v['name']) {
                $team = $mine;
            }
        }
        if (!is_wp_error($team)) {
            delete_transient(self::APPLY_DRAFT_PREFIX . $user_id);
        } elseif (self::apply_retry($team)) {
            set_transient(self::APPLY_DRAFT_PREFIX . $user_id, $v, self::APPLY_DRAFT_TTL);
        }
        return $team;
    }

    /** The draft for the #rsl-apply card (array, maybe empty), taken: the next page view shows it once. */
    private static function take_apply_draft($user_id) {
        $key = self::APPLY_DRAFT_PREFIX . intval($user_id);
        $draft = get_transient($key);
        if ($draft !== false) {
            delete_transient($key);
        }
        return is_array($draft) ? $draft : array();
    }

    /** " (2027 Mid-season 1)" after a team name when its season isn't the sign-up season, else ''. */
    public static function season_aside($season) {
        return $season !== RSL_Seasons::signup() ? ' (' . RSL_Seasons::short_label($season) . ')' : '';
    }

    // ------------------------------------------------------------------
    // Page
    // ------------------------------------------------------------------

    public function render($atts = array()) {
        $user_id = get_current_user_id();
        $code = isset($_GET['join']) ? self::clean_code(wp_unslash($_GET['join'])) : '';
        if ($code === '') {
            // No link this time: offer the invite they opened before signing up.
            $code = self::remembered_code($user_id);
        }
        $invited_to = $code !== '' ? RSL_Teams::get_by_code($code) : null;
        if ($user_id) {
            delete_user_meta($user_id, self::SEND_META); // they've arrived
        }
        ob_start();
        echo '<div class="rsl rsl-portal">';
        if (!$user_id) {
            $this->render_logged_out($invited_to);
        } elseif (!RSL_Players::is_registered($user_id)) {
            echo self::take_flash();
            $this->render_register($invited_to ? $code : '', $invited_to, $user_id);
        } else {
            echo self::take_flash();
            $this->render_dashboard($user_id, $code, $invited_to);
        }
        echo '</div>';
        echo self::styles();
        return ob_get_clean();
    }

    private static function fields($do, $extra = array()) {
        $html = wp_nonce_field('rsl_front', 'rsl_nonce', true, false)
            . '<input type="hidden" name="rsl_front" value="1"><input type="hidden" name="do" value="' . esc_attr($do) . '">';
        foreach ($extra as $name => $value) {
            $html .= '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
        }
        return $html;
    }

    private function render_logged_out($invited_to) {
        $here = $invited_to ? self::invite_url($invited_to) : self::url();
        $login = function_exists('um_get_core_page') ? add_query_arg('redirect_to', rawurlencode($here), um_get_core_page('login')) : wp_login_url($here);
        $register = function_exists('um_get_core_page') ? um_get_core_page('register') : wp_registration_url();
        ?>
        <?php if ($invited_to): ?>
            <div class="rsl-card rsl-card-accent"><p><strong>You've been invited to join <?php echo esc_html($invited_to->name); ?>.</strong> Log in, or create a club account — we'll remember this invite and bring you back here to join.</p></div>
        <?php endif; ?>
        <h2>Reds Social League</h2>
        <p>Log in to register for the league, join a team or apply to captain one.</p>
        <p><a class="rsl-btn" href="<?php echo esc_url($login); ?>">Log in</a> <a class="rsl-btn rsl-btn-secondary" href="<?php echo esc_url($register); ?>">Create an account</a></p>
        <?php
    }

    private function render_register($code, $invited_to, $user_id) {
        $profile = RSL_Players::profile($user_id);
        $edit = RSL_Players::profile_edit_url();
        ?>
        <?php if ($invited_to): ?>
            <div class="rsl-card rsl-card-accent"><p><strong>You've been invited to join <?php echo esc_html($invited_to->name); ?>.</strong> Register below and you'll go straight onto the team.</p></div>
        <?php endif; ?>
        <h2>Register for the Reds Social League</h2>
        <p>Registering is free and takes a minute. To play you'll also need a current social rego (<?php echo esc_html(RSL_Rego::cost_words()); ?>) before your first game — unless you're a full or life club member.</p>
        <?php if ($profile['missing']): ?>
            <div class="rsl-card rsl-card-warn">
                <p><strong>First, finish your club profile.</strong> It's missing your <?php echo esc_html(RSL_Players::list_words(array_map('strtolower', $profile['missing']))); ?>. The league uses the same profile as VVL trials, so we can reach you and place you on the right team.</p>
                <p><a class="rsl-btn" href="<?php echo esc_url($edit); ?>">Edit your profile</a></p>
                <p class="rsl-hint">Save it, then come back to this page to register<?php echo $invited_to ? ' — we\'ll remember your invite' : ''; ?>.</p>
            </div>
            <?php return; ?>
        <?php endif; ?>
        <div class="rsl-card">
            <p><strong>Your details</strong> <span class="rsl-hint">— from your club profile. <a href="<?php echo esc_url($edit); ?>">Edit your profile</a> to change them.</span></p>
            <dl class="rsl-profile">
                <dt>Name</dt><dd><?php echo esc_html($profile['values']['First name'] . ' ' . $profile['values']['Last name']); ?></dd>
                <dt>Mobile</dt><dd><?php echo esc_html($profile['values']['Mobile']); ?></dd>
                <dt>Date of birth</dt><dd><?php echo esc_html($profile['values']['Date of birth']); ?></dd>
                <dt>Gender</dt><dd><?php echo esc_html($profile['values']['Gender']); ?></dd>
            </dl>
        </div>
        <form method="post" class="rsl-form">
            <?php echo self::fields('register', array('join_code' => $code)); ?>
            <?php echo self::experience_fields(null); ?>
            <?php if (class_exists('RSL_Portal_FA')): ?>
                <?php echo RSL_Portal_FA::route_fields(null, RSL_Seasons::signup(), $invited_to); ?>
            <?php elseif (!$invited_to): ?>
                <label class="rsl-check"><input type="checkbox" name="looking" value="1"> I don't have a team yet — put me on the free agent list</label>
            <?php endif; ?>
            <?php echo self::ref_field(0); ?>
            <button type="submit" class="rsl-btn"><?php echo $invited_to ? 'Register and join ' . esc_html($invited_to->name) : 'Register'; ?></button>
        </form>
        <?php
    }

    /** "Happy to be a paid referee" — or where they stand if they've already offered. */
    private static function ref_field($user_id) {
        $status = $user_id ? RSL_Officials::get($user_id)->ref : '';
        $rate = RSL_Officials::money(RSL_Officials::settings()['ref_rate']);
        if ($status === 'approved') {
            return '<p class="rsl-hint">You\'re an approved referee — <a href="' . esc_url(RSL_Ref_Page::url()) . '">see ref jobs</a>.</p>';
        }
        if ($status === 'declined') {
            return '';
        }
        return '<label class="rsl-check"><input type="checkbox" name="ref" value="1"' . checked($status === 'applied', true, false) . '> Happy to be a paid referee (' . esc_html($rate) . ' a game) — the league will approve you</label>'
            . '<input type="hidden" name="ref_shown" value="1">';
    }

    private static function experience_fields($player) {
        $positions = $player ? explode(',', $player->positions) : array();
        ob_start();
        ?>
        <label class="rsl-field">Highest level you've played
            <select name="level" required>
                <option value="">Choose…</option>
                <?php foreach (RSL_Players::levels() as $key => $label): ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($player ? $player->level : '', $key); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="rsl-field">How long you've been playing
            <select name="years" required>
                <option value="">Choose…</option>
                <?php foreach (RSL_Players::years() as $key => $label): ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($player ? $player->years : '', $key); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <fieldset class="rsl-field"><legend>Positions you like (optional)</legend>
            <?php foreach (RSL_Players::positions() as $key => $label): ?>
                <label class="rsl-check rsl-inline"><input type="checkbox" name="positions[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $positions, true)); ?>> <?php echo esc_html($label); ?></label>
            <?php endforeach; ?>
        </fieldset>
        <?php
        return ob_get_clean();
    }

    /**
     * The pay buttons: the season rego as a small GET form to checkout (a choice of the
     * seasons open for sign-up that this player isn't covered for, $card_season preselected;
     * one choice is named beside the button), then the yearly rego as a plain link. $season_form
     * false: the yearly button alone. '' when there's nothing to offer.
     */
    private static function rego_pay($user_id, $first_class, $card_season, $season_form = true) {
        $html = '';
        $form = false;
        $season_id = RSL_Rego::season_product_id();
        if ($season_form && $season_id) {
            $choices = RSL_Rego::season_choices($user_id);
            if ($choices) {
                reset($choices);
                $selected = isset($choices[$card_season]) ? $card_season : (string) key($choices);
                $target = RSL_Rego::checkout_form_target();
                $html .= '<form method="get" action="' . esc_url($target['action']) . '" class="rsl-rego-season">';
                foreach ($target['fields'] as $name => $value) {
                    if (is_scalar($value) && $name !== 'add-to-cart' && $name !== RSL_Rego::SEASON_ARG) {
                        $html .= '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
                    }
                }
                $html .= '<input type="hidden" name="add-to-cart" value="' . intval($season_id) . '">';
                if (count($choices) > 1) {
                    $html .= '<label class="rsl-season-pick">Season <select name="' . esc_attr(RSL_Rego::SEASON_ARG) . '">';
                    foreach ($choices as $key => $label) {
                        $html .= '<option value="' . esc_attr($key) . '"' . selected($key, $selected, false) . '>' . esc_html($label) . '</option>';
                    }
                    $html .= '</select></label>';
                } else {
                    $html .= '<input type="hidden" name="' . esc_attr(RSL_Rego::SEASON_ARG) . '" value="' . esc_attr($selected) . '">'
                        . '<span class="rsl-season-pick">' . esc_html($choices[$selected]) . '</span>';
                }
                $html .= '<button type="submit" class="' . esc_attr($first_class) . '">Pay the ' . esc_html(RSL_Rego::price_label('season')) . ' season rego</button></form>';
                $form = true;
            }
        }
        if (RSL_Rego::product_id()) {
            $label = $season_id ? 'Pay the ' . RSL_Rego::price_label() . ' yearly rego' : 'Pay the ' . RSL_Rego::price_label() . ' rego';
            $html .= '<a class="' . esc_attr($form ? 'rsl-btn rsl-btn-secondary' : $first_class) . '" href="' . esc_url(RSL_Rego::pay_url('year')) . '">' . esc_html($label) . '</a>';
        }
        return $html;
    }

    /**
     * The not-cleared card's pay part for $card_season: no product set; yearly only; the season
     * form (with the yearly as second choice) when the card's season is open for sign-up; else
     * no season form (paying another season wouldn't clear them for this one), only the
     * yearly button with why.
     */
    private static function rego_pay_needed($user_id, $card_season) {
        $has_season = (bool) RSL_Rego::season_product_id();
        $has_year = (bool) RSL_Rego::product_id();
        if (!$has_season && !$has_year) {
            return '<p class="rsl-hint">Online payment isn\'t set up yet — contact the league to pay.</p>';
        }
        if (!$has_season) {
            return '<p>' . self::rego_pay($user_id, 'rsl-btn', $card_season) . '</p>';
        }
        $open = RSL_Seasons::open_for_signup();
        if (isset($open[$card_season])) {
            $pay = self::rego_pay($user_id, 'rsl-btn', $card_season);
            return $pay !== '' ? '<p>' . $pay . '</p>' : '';
        }
        $why = self::rego_why($card_season);
        if (!$has_year) {
            return '<p>' . $why . ' Contact the league to pay.</p>';
        }
        return '<p>' . $why . ' Pay the yearly rego, or contact the league.</p><p>' . self::rego_pay($user_id, 'rsl-btn', $card_season, false) . '</p>';
    }

    /** Why no season rego can be bought for $card_season now (rego_pay_needed() and the waiting card). Escaped HTML. */
    private static function rego_why($card_season) {
        return RSL_Seasons::open_for_signup()
            ? 'A season rego for ' . esc_html(RSL_Seasons::short_label($card_season)) . ' can\'t be bought online now.'
            : 'No season is open for sign-up yet.';
    }

    private function render_dashboard($user_id, $code, $invited_to) {
        $current = RSL_Seasons::current();
        $signup = RSL_Seasons::signup();
        $player = RSL_Players::get($user_id);
        $now_team = RSL_Teams::current_team_for_user($user_id, $current);
        $next_team = $signup === $current ? $now_team : RSL_Teams::current_team_for_user($user_id, $signup);
        // Two seasons overlap while next season signs up: show this season's team first.
        $two = $signup !== $current && $now_team;
        $invites = RSL_Teams::pending_invites_for_user($user_id);
        $today = current_time('Y-m-d');
        $fa_row = class_exists('RSL_Free_Agents') ? RSL_Free_Agents::get($user_id, $signup) : null;
        // Later seasons open for sign-up (0.17.0): what they have there (shown under Later
        // season), and so which seasons the pickers still offer them.
        $open = RSL_Seasons::signup_choices();
        $others = self::other_seasons($user_id, $open, $signup, $two ? $current : '');
        $pending_signup = RSL_Teams::pending_application_for_user($user_id, $signup);
        $draft = self::take_apply_draft($user_id);
        $taken = array();
        $listed = array();
        foreach ($others as $key => $o) {
            if ($o['team'] || $o['pending']) {
                $taken[$key] = true;
            } else {
                $listed[$key] = true;
            }
        }
        $apply_choices = array_diff_key($open, $taken);
        $fa_choices = array_diff_key($open, $taken, $listed);
        // The season they play in next: a rego paid ahead for it counts, and a finished
        // current season is never the one judged.
        $card_season = RSL_Seasons::playing_season($current, $signup, (bool) $now_team);
        // Nothing in the season being played or signing up, but something in a later one: that's
        // the season they play in next, so the card judges it (and preselects it to pay).
        if (!$now_team && !$next_team && !$pending_signup && !RSL_Free_Agents::is_open($fa_row) && $others) {
            reset($others);
            $card_season = (string) key($others);
        }
        $clear = RSL_Rego::clearance_for($user_id, $card_season);
        $other_team = (bool) array_filter($others, function ($o) { return (bool) $o['team']; });
        $other_fa = (bool) array_filter($others, function ($o) { return RSL_Free_Agents::is_open($o['fa']); });
        // Only someone on no team at all can leave the rego until they're placed.
        $waiting_for_team = !$now_team && !$next_team && !$other_team && (RSL_Free_Agents::is_open($fa_row) || $other_fa);
        ?>
        <h2>Reds Social League <span class="rsl-season"><?php echo esc_html(RSL_Seasons::label($two || !$now_team ? $signup : $current)); ?></span></h2>

        <?php foreach ($invites as $invite): ?>
            <?php $friends = $invite->source === 'pool' && $invite->group_code !== '' ? count(RSL_Free_Agents::group_members($invite->season, $invite->group_code)) - 1 : 0; ?>
            <div class="rsl-card rsl-card-accent">
                <p><strong><?php echo esc_html($invite->team_name); ?> would like <?php echo $friends > 0 ? 'you and your friend' . ($friends > 1 ? 's' : '') : 'you'; ?> to join.</strong>
                    <span class="rsl-meta"><?php echo esc_html(RSL_Teams::DIVISIONS[$invite->division]); ?> &middot; <?php echo esc_html(RSL_Seasons::label($invite->season)); ?>
                    <?php if ($invite->expires_at): ?> &middot; answer by <?php echo esc_html(date_i18n('D j M', strtotime($invite->expires_at))); ?><?php endif; ?>
                    &middot; <a href="<?php echo esc_url(RSL_Public::team_url((object) array('slug' => $invite->slug, 'season' => $invite->season))); ?>">see the team</a></span></p>
                <form method="post" class="rsl-inline-form"><?php echo self::fields('accept_invite', array('invite_id' => $invite->id)); ?><button type="submit" class="rsl-btn">Accept</button></form>
                <form method="post" class="rsl-inline-form"><?php echo self::fields('decline_invite', array('invite_id' => $invite->id)); ?><button type="submit" class="rsl-btn rsl-btn-secondary">Decline</button></form>
            </div>
        <?php endforeach; ?>

        <?php if ($invited_to && !RSL_Teams::current_team_for_user($user_id, $invited_to->season)): ?>
            <div class="rsl-card rsl-card-accent">
                <p><strong>Join <?php echo esc_html($invited_to->name); ?>?</strong> <span class="rsl-meta"><?php echo esc_html(RSL_Seasons::label($invited_to->season)); ?></span></p>
                <form method="post"><?php echo self::fields('join', array('join_code' => $code)); ?><button type="submit" class="rsl-btn">Join <?php echo esc_html($invited_to->name); ?></button></form>
            </div>
        <?php elseif ($code !== '' && !$invited_to): ?>
            <div class="rsl-flash rsl-flash-error">That invite link isn't valid any more — ask your captain for the current one.</div>
        <?php endif; ?>

        <?php if (!$clear['cleared'] && !empty($clear['unknown'])): ?>
            <div class="rsl-card rsl-card-warn">
                <p><strong>We can't check club memberships right now.</strong> If you're a full or life member you don't need a social rego — don't pay; the league will sort it out. Otherwise you'll need one (<?php echo esc_html(RSL_Rego::cost_words()); ?>) before your first game.</p>
            </div>
        <?php elseif (!$clear['cleared'] && $waiting_for_team): ?>
            <div class="rsl-card">
                <p><strong>Social rego:</strong> you'll need one (<?php echo esc_html(RSL_Rego::cost_words()); ?>) before your first game — pay once you're on a team, no rush. Full and life club members don't need one.</p>
                <?php if (RSL_Rego::season_product_id()): ?>
                    <?php $pay = self::rego_pay($user_id, 'rsl-btn rsl-btn-secondary', $card_season); ?>
                    <?php if ($pay !== ''): ?><p><?php echo $pay; ?></p>
                    <?php else: ?><p><?php echo self::rego_why($card_season); ?> Contact the league to pay.</p><?php endif; ?>
                <?php elseif (RSL_Rego::pay_url('year')): ?>
                    <p><a class="rsl-btn rsl-btn-secondary" href="<?php echo esc_url(RSL_Rego::pay_url('year')); ?>">Pay the rego now</a></p>
                <?php else: ?>
                    <p class="rsl-hint">Online payment isn't set up yet — contact the league to pay.</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
        <div class="rsl-card <?php echo $clear['cleared'] ? 'rsl-card-good' : 'rsl-card-warn'; ?>">
            <?php if ($clear['cleared']): ?>
                <?php
                $from_later = !empty($clear['from']) && $clear['from'] > $today;
                $words_year = (int) substr(RSL_Seasons::is_valid($clear['season']) ? $clear['season'] : $card_season, 0, 4);
                ?>
                <p><strong>&#10003; You're cleared to play<?php echo $from_later ? ' from ' . esc_html(RSL_Seasons::day_words($clear['from'], $words_year)) : ''; ?>.</strong>
                    <?php echo esc_html(RSL_Rego::cover_words($clear)); ?>.</p>
                <?php if ($clear['by'] === 'rego' && $clear['until'] < date('Y-m-d', strtotime($today . ' +' . RSL_Rego::RENEW_DAYS . ' days')) && !RSL_Rego::paid_ahead($user_id, $clear, $card_season)): ?>
                    <?php
                    // A yearly rego starts after the run of regos covering the day it's paid
                    // (grant()), so "when this one ends" only while the run on this card covers
                    // today with this end: not a run that starts later, nor a season row kept
                    // counting past its own end by its season's moved dates.
                    $chains = RSL_Rego::rego_valid_until($user_id, $today) === $clear['until'];
                    ?>
                    <?php if (!RSL_Rego::season_product_id()): ?>
                        <?php if (RSL_Rego::pay_url('year')): ?>
                            <p>It runs out soon — <a href="<?php echo esc_url(RSL_Rego::pay_url('year')); ?>">renew now</a><?php echo $chains ? ' (a new 12 months starts when this one ends)' : ''; ?>.</p>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php $pay = self::rego_pay($user_id, 'rsl-btn rsl-btn-secondary', $card_season); ?>
                        <?php if ($pay !== ''): ?>
                            <p>It runs out soon. <?php echo RSL_Rego::product_id() ? 'Pay for another season, or for a year' . ($chains ? ' (a yearly rego starts when this one ends)' : '') . ':' : 'Pay for another season:'; ?></p>
                            <p><?php echo $pay; ?></p>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
            <?php else: ?>
                <p><strong>You need a social rego before your first game.</strong> <?php echo wp_kses_post(RSL_Rego::cost_sentence()); ?> Full and life club members don't need one.</p>
                <?php $now_clear = RSL_Rego::clearance($user_id); ?>
                <?php if ($now_clear['cleared'] && RSL_Seasons::is_valid($card_season)): ?>
                    <?php $card_span = RSL_Seasons::span($card_season); ?>
                    <p>What covers you now (<?php echo esc_html(RSL_Rego::cover_words($now_clear)); ?>) ends before <?php echo esc_html(RSL_Seasons::short_label($card_season)); ?>'s first game night (<?php echo esc_html(RSL_Seasons::day_words($card_span['start'], (int) substr($card_season, 0, 4))); ?>).</p>
                <?php endif; ?>
                <?php echo self::rego_pay_needed($user_id, $card_season); ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (RSL_Officials::is_official($user_id)): ?>
            <?php $e = RSL_Officials::earnings($user_id, $current); ?>
            <div class="rsl-card"><p><strong>Ref jobs</strong> — <?php echo intval($e['booked']); ?> booked ahead, <?php echo esc_html(RSL_Officials::money($e['earned'])); ?> earned this season. <a href="<?php echo esc_url(RSL_Ref_Page::url()); ?>">See your night and open jobs &rarr;</a></p></div>
        <?php endif; ?>

        <?php if ($two): ?>
            <h3 class="rsl-season-head">This season <span class="rsl-season"><?php echo esc_html(RSL_Seasons::label($current)); ?></span></h3>
            <?php $this->render_my_team($now_team, $user_id); ?>
            <h3 class="rsl-season-head">Next season <span class="rsl-season"><?php echo esc_html(RSL_Seasons::label($signup)); ?></span></h3>
        <?php endif; ?>
        <?php $this->render_season($user_id, $signup, $next_team, $player, $pending_signup, $fa_choices, $apply_choices, $draft); ?>

        <?php foreach ($others as $key => $o): ?>
            <h3 class="rsl-season-head">Later season <span class="rsl-season"><?php echo esc_html(RSL_Seasons::label($key)); ?></span></h3>
            <?php if ($o['team']): ?>
                <?php $this->render_my_team($o['team'], $user_id); ?>
            <?php elseif ($o['pending']): ?>
                <?php echo self::pending_card($o['pending']); ?>
            <?php elseif (class_exists('RSL_Portal_FA')): ?>
                <?php echo RSL_Portal_FA::dashboard($user_id, $key, array(), false); ?>
            <?php endif; ?>
        <?php endforeach; ?>

        <details class="rsl-card">
            <summary><strong>My details</strong></summary>
            <form method="post" class="rsl-form"><?php echo self::fields('update_details'); ?><?php echo self::experience_fields($player); ?><?php echo self::ref_field($user_id); ?><button type="submit" class="rsl-btn">Save</button></form>
        </details>
        <?php
    }

    /**
     * What this person has in each season open for sign-up besides the sign-up season (and, while
     * two seasons overlap, the one being played): key => array('team', 'pending', 'fa') where
     * they're on an active team, have an application waiting or are on the free agent list.
     * Calendar order. At most three small queries a season, on the league page only.
     */
    private static function other_seasons($user_id, $open, $signup, $skip) {
        $out = array();
        foreach (array_keys($open) as $key) {
            if ($key === $signup || $key === $skip) {
                continue;
            }
            $team = RSL_Teams::current_team_for_user($user_id, $key);
            $pending = $team ? null : RSL_Teams::pending_application_for_user($user_id, $key);
            $fa = ($team || $pending) ? null : RSL_Free_Agents::get($user_id, $key);
            if ($team || $pending || RSL_Free_Agents::is_open($fa)) {
                $out[$key] = array('team' => $team, 'pending' => $pending, 'fa' => $fa);
            }
        }
        return $out;
    }

    /** Their team application waiting for approval, with a way to withdraw it. */
    private static function pending_card($pending) {
        ob_start();
        ?>
        <div class="rsl-card">
            <p><strong>Your application for <?php echo esc_html($pending->name); ?> is waiting for approval.</strong> We'll email you when it's approved — then you can invite your players.</p>
            <form method="post" onsubmit="return confirm('Withdraw your application for <?php echo esc_js($pending->name); ?>?');"><?php echo self::fields('withdraw', array('team_id' => $pending->id)); ?><button type="submit" class="rsl-btn rsl-btn-secondary">Withdraw application</button></form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * One season's part of the page: their team, their application waiting,
     * or — with neither — the ways onto a team: the free agent list, an
     * invite code, applying to captain. $pending: their application waiting in
     * $season (or null); $fa_choices / $apply_choices: the seasons the Find me a
     * team card and the application card offer (0.17.0: the seasons open for
     * sign-up they aren't in yet; a card with nothing to offer isn't shown);
     * $draft: what they typed into an application that failed (it opens the card).
     */
    private function render_season($user_id, $season, $team, $player, $pending, $fa_choices, $apply_choices, $draft) {
        ?>
        <?php if ($team): ?>
            <?php $this->render_my_team($team, $user_id); ?>
        <?php elseif ($pending): ?>
            <?php echo self::pending_card($pending); ?>
        <?php else: ?>
            <?php $declined = RSL_Teams::last_decided_application($user_id, $season); ?>
            <?php if ($declined): ?>
                <div class="rsl-flash rsl-flash-error">Your application for <?php echo esc_html($declined->name); ?> wasn't approved<?php echo $declined->decision_note !== '' ? ': ' . esc_html($declined->decision_note) : '.'; ?></div>
            <?php endif; ?>
            <?php if (class_exists('RSL_Portal_FA')): ?>
                <?php echo RSL_Portal_FA::dashboard($user_id, $season, $fa_choices); ?>
            <?php endif; ?>
            <div class="rsl-card">
                <h3>Got an invite code?</h3>
                <p class="rsl-hint">If a captain gave you a code or link, use it to join their team straight away.</p>
                <form method="post" class="rsl-row"><?php echo self::fields('join'); ?><input type="text" name="join_code" placeholder="Invite code" required maxlength="16" autocapitalize="characters"><button type="submit" class="rsl-btn">Join</button></form>
                <?php if (!class_exists('RSL_Portal_FA')): ?>
                    <form method="post" class="rsl-row"><?php echo self::fields('looking', array('looking' => $player->looking ? '' : '1')); ?>
                        <p><?php echo $player->looking ? 'You\'re on the free agent list.' : 'No team? Join the free agent list and we\'ll help place you.'; ?></p>
                        <button type="submit" class="rsl-btn rsl-btn-secondary"><?php echo $player->looking ? 'Leave the free agent list' : 'Put me on the free agent list'; ?></button>
                    </form>
                <?php endif; ?>
            </div>
            <?php if ($apply_choices): ?>
                <details class="rsl-card" id="rsl-apply"<?php echo $draft ? ' open' : ''; ?>>
                    <summary><strong>Captaining a team? Apply for it here</strong></summary>
                    <form method="post" class="rsl-form">
                        <?php echo self::fields('apply'); ?>
                        <?php echo self::team_fields($draft, $apply_choices, true); ?>
                        <button type="submit" class="rsl-btn">Send application</button>
                    </form>
                </details>
                <script>if (location.hash === '#rsl-apply') { document.getElementById('rsl-apply').open = true; }</script>
            <?php endif; ?>
        <?php endif; ?>
        <?php
    }

    private function render_my_team($team, $user_id) {
        $is_captain = RSL_Teams::is_captain($team->id, $user_id);
        $members = RSL_Teams::members($team->id);
        ?>
        <div class="rsl-card">
            <div class="rsl-team-head">
                <?php echo RSL_Public::badge($team); ?>
                <div><h3 class="rsl-title"><a href="<?php echo esc_url(RSL_Public::team_url($team)); ?>"><?php echo esc_html($team->name); ?></a></h3>
                    <p class="rsl-meta"><?php echo esc_html(RSL_Teams::DIVISIONS[$team->division]); ?> &middot; <?php echo $is_captain ? 'You\'re the captain' : 'You\'re on this team'; ?></p></div>
            </div>
            <?php $bal = RSL_Payments::balance($team->id); ?>
            <p class="rsl-balance-line <?php echo RSL_Payments::blocked($team->id) ? 'is-blocked' : ($bal < 0 ? 'is-owing' : ''); ?>">
                <strong>Team balance: <?php echo $bal < 0 ? 'owes ' . esc_html(RSL_Payments::money(-$bal)) : esc_html(RSL_Payments::money($bal)) . ($bal > 0 ? ' credit' : ''); ?></strong>
                <?php if (RSL_Payments::blocked($team->id)): ?> — not fixtured until it's paid down.<?php endif; ?>
                <a href="<?php echo esc_url(RSL_Public::team_url($team)); ?>">Top up &rarr;</a>
            </p>

            <h4>Players (<?php echo count($members); ?>)</h4>
            <ul class="rsl-roster">
                <?php foreach ($members as $m): ?>
                    <?php $c = RSL_Rego::clearance_for($m->user_id, $team->season); // judged for the team's season: a rego paid ahead counts ?>
                    <li>
                        <span><?php echo esc_html($m->display_name); ?><?php if ($m->role === 'captain'): ?> <span class="rsl-chip">Captain</span><?php endif; ?></span>
                        <?php if ($is_captain): ?>
                            <?php if (!$c['cleared'] && !empty($c['unknown'])): ?>
                                <span class="rsl-status rsl-unknown">? Checking</span>
                            <?php else: ?>
                                <span class="rsl-status <?php echo $c['cleared'] ? 'rsl-ok' : 'rsl-no'; ?>"><?php echo $c['cleared'] ? '&#10003; Cleared' : '&#10007; Needs rego'; ?></span>
                            <?php endif; ?>
                            <?php if (intval($m->user_id) !== intval($user_id)): ?>
                                <form method="post" class="rsl-inline-form" onsubmit="return confirm('Remove <?php echo esc_js($m->display_name); ?> from the team?');"><?php echo self::fields('remove_member', array('team_id' => $team->id, 'user_id' => $m->user_id)); ?><button type="submit" class="rsl-link">Remove</button></form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if (class_exists('RSL_Portal_FA')): ?>
                <?php echo RSL_Portal_FA::placed_notice($user_id, $team->season); ?>
            <?php endif; ?>
            <?php if (!$is_captain): ?>
                <form method="post" class="rsl-leave" onsubmit="return confirm('Leave <?php echo esc_js($team->name); ?>? Your captain will need to invite you again to come back.');"><?php echo self::fields('leave_team', array('team_id' => $team->id)); ?><button type="submit" class="rsl-link">Leave this team</button></form>
            <?php endif; ?>

            <?php if ($is_captain): ?>
                <?php $invites = RSL_Teams::pending_invites_for_team($team->id); ?>
                <?php if ($invites): ?>
                    <h4>Waiting to accept</h4>
                    <ul class="rsl-roster">
                        <?php foreach ($invites as $inv): ?>
                            <li><span><?php echo esc_html($inv->label); ?></span><span class="rsl-meta">invited <?php echo esc_html(date('j M', strtotime($inv->created_at))); ?><?php if ($inv->expires_at): ?> &middot; until <?php echo esc_html(date_i18n('j M', strtotime($inv->expires_at))); ?><?php endif; ?></span>
                                <form method="post" class="rsl-inline-form"><?php echo self::fields('cancel_invite', array('team_id' => $team->id, 'invite_id' => $inv->id)); ?><button type="submit" class="rsl-link">Cancel</button></form></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <h4>Add players</h4>
                <p class="rsl-hint">Share your invite link — anyone who opens it can register and join straight away:</p>
                <div class="rsl-row">
                    <input type="text" readonly value="<?php echo esc_attr(self::invite_url($team)); ?>" class="rsl-invite" onclick="this.select()">
                    <button type="button" class="rsl-btn rsl-btn-secondary" onclick="var i=this.previousElementSibling;i.select();navigator.clipboard&&navigator.clipboard.writeText(i.value);this.textContent='Copied';">Copy</button>
                </div>
                <div class="rsl-hint">Code: <strong><?php echo esc_html(RSL_Teams::invite_code($team)); ?></strong>
                    &middot; <form method="post" class="rsl-inline-form" onsubmit="return confirm('Make a new invite link? The current one will stop working.');"><?php echo self::fields('new_code', array('team_id' => $team->id)); ?><button type="submit" class="rsl-link">Make a new link</button></form></div>
                <p class="rsl-hint">Or invite someone by their email — they'll join once they accept:</p>
                <form method="post" class="rsl-row"><?php echo self::fields('invite', array('team_id' => $team->id)); ?><input type="email" name="email" placeholder="Their email" required><button type="submit" class="rsl-btn">Invite</button></form>
                <p class="rsl-hint">Everyone needs to be cleared (social rego or full/life membership) before their first game.</p>

                <?php if (class_exists('RSL_Portal_FA') && $team->status === 'active'): ?>
                    <?php echo RSL_Portal_FA::captain_tools($team, $user_id); ?>
                <?php endif; ?>

                <h4>Season schedule</h4>
                <p class="rsl-hint">Your team page shows every week of the season. Need a week off, or fancy two games one night? Ask there before that week's fixtures come out. <a href="<?php echo esc_url(RSL_Public::team_url($team)); ?>">Open the season schedule &rarr;</a></p>

                <h4>Team preferences</h4>
                <p class="rsl-hint">Used when the weekly fixtures are made. Even games come first, so preferences decide between otherwise equal options.</p>
                <form method="post" class="rsl-form rsl-prefs">
                    <?php echo self::fields('save_prefs', array('team_id' => $team->id)); ?>
                    <label class="rsl-field">Game time
                        <select name="pref_time"><?php foreach (RSL_Teams::time_preferences() as $k => $l): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($team->pref_time, $k); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?></select>
                    </label>
                    <label class="rsl-check"><input type="checkbox" name="pref_double" value="1" <?php checked((int) $team->pref_double, 1); ?> onchange="this.form.querySelector('.rsl-shape').hidden = !this.checked;"> Happy to play two games in a night (double-header)</label>
                    <label class="rsl-field rsl-shape" <?php echo (int) $team->pref_double ? '' : 'hidden'; ?>>If you play twice
                        <select name="pref_double_shape"><?php foreach (RSL_Teams::double_shapes() as $k => $l): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($team->pref_double_shape, $k); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?></select>
                    </label>
                    <label class="rsl-check"><input type="checkbox" name="pref_standby" value="1" <?php checked((int) $team->pref_standby, 1); ?>> We can be on standby to fill in if another team pulls out late</label>
                    <button type="submit" class="rsl-btn">Save preferences</button>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    private static function styles() {
        static $done = false;
        if ($done) {
            return '';
        }
        $done = true;
        return '<style>
            .rsl-portal { --rsl-navy: #1d3d6e; --rsl-muted: #6b7280; --rsl-border: #dcdcde; max-width: 720px; }
            .rsl-season { font-size: 0.55em; font-weight: 400; color: var(--rsl-muted); }
            .rsl-card { border: 1px solid var(--rsl-border); border-radius: 8px; padding: 14px 16px; margin: 14px 0; background: #fff; }
            .rsl-balance-line { margin: 10px 0 0; padding: 8px 12px; border-radius: 6px; background: #f2f8f2; }
            .rsl-balance-line.is-owing { background: #fff6e8; }
            .rsl-balance-line.is-blocked { background: #fcf0f1; color: #8a1f1f; }
            .rsl-card > summary { cursor: pointer; }
            .rsl-card-accent { border-left: 4px solid var(--rsl-navy); background: #f5f8fc; }
            .rsl-card-good { border-left: 4px solid #2e8540; background: #f2f9f3; }
            .rsl-card-warn { border-left: 4px solid #d97706; background: #fffaf0; }
.rsl-portal .rsl-profile { display: grid; grid-template-columns: max-content 1fr; gap: 2px 14px; margin: 8px 0 2px; }
.rsl-portal .rsl-profile dt { color: var(--rsl-muted); }
.rsl-portal .rsl-profile dd { margin: 0; }
            .rsl-card p { margin: 6px 0; }
            .rsl-flash { padding: 10px 14px; border-radius: 6px; margin: 12px 0; }
            .rsl-flash-success { background: #e8f5ea; border: 1px solid #2e8540; color: #1e6b2a; }
            .rsl-flash-error { background: #fdf0f0; border: 1px solid #c94b4b; color: #8a1f1f; }
            .rsl-btn { display: inline-block; padding: 8px 14px; border-radius: 4px; background: var(--rsl-navy); color: #fff !important; border: 1px solid var(--rsl-navy); font-weight: 600; text-decoration: none; cursor: pointer; font-size: 14px; text-transform: none; }
            .rsl-btn-secondary { background: #fff; color: var(--rsl-navy) !important; }
            .rsl-link { background: none; border: 0; padding: 0; color: #b32d2e; text-decoration: underline; cursor: pointer; font-size: 13px; text-transform: none; font-weight: 400; }
            .rsl-form .rsl-field { display: block; margin: 10px 0; font-weight: 600; }
            .rsl-form select, .rsl-form input[type=text], .rsl-row input[type=text], .rsl-row input[type=email] { display: block; width: 100%; max-width: 420px; padding: 7px 9px; margin-top: 4px; font-weight: 400; border: 1px solid #c3c8d0; border-radius: 4px; box-sizing: border-box; }
            .rsl-form fieldset { border: 0; padding: 0; }
            .rsl-check { display: block; margin: 8px 0; font-weight: 400; }
            .rsl-inline { display: inline-block; margin-right: 12px; }
            .rsl-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 8px 0; }
            .rsl-row input { flex: 1 1 200px; margin-top: 0 !important; }
            .rsl-row p { flex: 1 1 100%; margin: 4px 0; }
            .rsl-inline-form { display: inline; margin: 0 4px 0 0; }
            .rsl-hint { color: var(--rsl-muted); font-size: 14px; }
            .rsl-roster { list-style: none; margin: 0; padding: 0; }
            .rsl-roster li { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; padding: 8px 0; border-bottom: 1px solid var(--rsl-border); }
            .rsl-roster li > span:first-child { flex: 1 1 auto; }
            .rsl-status { font-size: 13px; font-weight: 600; }
            .rsl-ok { color: #1e6b2a; } .rsl-no { color: #b32d2e; } .rsl-unknown { color: var(--rsl-muted); }
            .rsl-invite { font-family: monospace; }
            .rsl-portal .rsl-badge { display: inline-flex; align-items: center; justify-content: center; flex: 0 0 auto; width: 44px; height: 44px; border-radius: 50%; color: #fff; font-weight: 700; font-size: 16px; }
            .rsl-portal .rsl-team-head { display: flex; align-items: center; gap: 12px; }
            .rsl-portal .rsl-team-head .rsl-title { margin: 0; font-size: 1.2em; }
            .rsl-portal .rsl-team-head a { text-decoration: none; }
            .rsl-portal .rsl-meta { margin: 2px 0 0; color: var(--rsl-muted); }
            .rsl-portal .rsl-chip { display: inline-block; margin-left: 6px; padding: 1px 8px; border-radius: 10px; font-size: 12px; background: #eef2f8; color: var(--rsl-navy); font-weight: 600; }
            .rsl-portal h4 { margin: 16px 0 6px; }
            .rsl-portal .rsl-season-head { margin: 22px 0 4px; }
            .rsl-portal .rsl-leave { margin: 8px 0 0; text-align: right; }
            .rsl-rego-season { display: inline-flex; flex-wrap: wrap; gap: 6px; align-items: center; margin: 0 6px 6px 0; }
            .rsl-season-pick { font-weight: 600; }
            .rsl-season-pick select { margin-left: 4px; max-width: 100%; }
        </style>' . (class_exists('RSL_Portal_FA') ? RSL_Portal_FA::styles() : '');
    }
}
