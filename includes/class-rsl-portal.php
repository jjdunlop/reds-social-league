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
        if ($user && self::remembered_code($user->ID) !== '' && !RSL_Teams::current_team_for_user($user->ID, RSL_Seasons::current())) {
            update_user_meta($user->ID, self::SEND_META, 1);
        }
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
        if (self::remembered_code($user_id) === '' || RSL_Teams::current_team_for_user($user_id, RSL_Seasons::current())) {
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

    public static function ensure_page() {
        $page_id = intval(get_option(self::PAGE_OPTION));
        if ($page_id && get_post_status($page_id)) {
            return $page_id;
        }
        $page_id = wp_insert_post(array(
            'post_title' => 'Social League',
            'post_name' => 'social-league',
            'post_content' => '[rsl_my_league]',
            'post_status' => 'publish',
            'post_type' => 'page',
        ));
        if ($page_id && !is_wp_error($page_id)) {
            update_option(self::PAGE_OPTION, $page_id, false);
        }
        return $page_id;
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
        $season = RSL_Seasons::current();
        $do = isset($post['do']) ? sanitize_key($post['do']) : '';
        $code = isset($post['join_code']) ? sanitize_text_field(wp_unslash($post['join_code'])) : '';

        switch ($do) {
            case 'register':
                $result = RSL_Players::register($user_id, array(
                    'level' => isset($post['level']) ? sanitize_key($post['level']) : '',
                    'years' => isset($post['years']) ? sanitize_key($post['years']) : '',
                    'positions' => isset($post['positions']) ? array_map('sanitize_key', (array) $post['positions']) : array(),
                    'looking' => !empty($post['looking']),
                ));
                if (is_wp_error($result)) {
                    self::flash('error', $result->get_error_message());
                    return $code !== '' ? add_query_arg('join', $code, $back) : $back;
                }
                if (!empty($post['ref'])) {
                    RSL_Officials::apply_ref($user_id);
                }
                if ($code !== '') {
                    $team = RSL_Teams::join_by_code($user_id, $code);
                    if (!is_wp_error($team)) {
                        self::forget_invite($user_id);
                    }
                    self::flash(is_wp_error($team) ? 'error' : 'success', is_wp_error($team)
                        ? 'You\'re registered. ' . $team->get_error_message()
                        : 'You\'re registered and on ' . $team->name . '.');
                    return $back;
                }
                self::flash('success', 'Thanks — you\'re registered for the Reds Social League.');
                return $back;

            case 'update_details':
                $existing = RSL_Players::get($user_id);
                $result = RSL_Players::register($user_id, array(
                    'level' => isset($post['level']) ? sanitize_key($post['level']) : '',
                    'years' => isset($post['years']) ? sanitize_key($post['years']) : '',
                    'positions' => isset($post['positions']) ? array_map('sanitize_key', (array) $post['positions']) : array(),
                    'looking' => $existing ? (bool) $existing->looking : false,
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
                $team = RSL_Teams::apply($season, $user_id, array(
                    'name' => isset($post['name']) ? wp_unslash($post['name']) : '',
                    'division' => isset($post['division']) ? sanitize_key($post['division']) : 'ranked',
                    'colour' => isset($post['colour']) ? wp_unslash($post['colour']) : '',
                    'level' => isset($post['level']) ? sanitize_key($post['level']) : '',
                ));
                self::flash(is_wp_error($team) ? 'error' : 'success', is_wp_error($team) ? $team->get_error_message() : 'Application sent for ' . $team->name . '. We\'ll email you when it\'s approved.');
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
        }

        // Captain tools: the person must captain THIS team.
        $team = RSL_Teams::get(isset($post['team_id']) ? intval($post['team_id']) : 0);
        if (!$team || !RSL_Teams::is_captain($team->id, $user_id)) {
            self::flash('error', 'Only the team captain can do that.');
            return $back;
        }
        // Night requests come from the team page; go back there.
        $back_to = isset($post['back']) ? wp_validate_redirect(esc_url_raw(wp_unslash($post['back'])), $back) : $back;
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
                self::flash(is_wp_error($result) ? 'error' : 'success', is_wp_error($result) ? $result->get_error_message() : $done[$type]);
                return $back_to;
            case 'cancel_request':
                $result = RSL_Requests::cancel(isset($post['request_id']) ? intval($post['request_id']) : 0, $user_id);
                self::flash(is_wp_error($result) ? 'error' : 'success', is_wp_error($result) ? $result->get_error_message() : 'Request withdrawn.');
                return $back_to;
            case 'invite':
                $result = RSL_Teams::invite_by_email($team->id, $user_id, isset($post['email']) ? wp_unslash($post['email']) : '');
                self::flash(is_wp_error($result) ? 'error' : 'success', is_wp_error($result) ? $result->get_error_message() : 'Invite sent. They\'ll join once they accept.');
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
            $this->render_register($invited_to ? $code : '', $invited_to);
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

    private function render_register($code, $invited_to) {
        ?>
        <?php if ($invited_to): ?>
            <div class="rsl-card rsl-card-accent"><p><strong>You've been invited to join <?php echo esc_html($invited_to->name); ?>.</strong> Register below and you'll go straight onto the team.</p></div>
        <?php endif; ?>
        <h2>Register for the Reds Social League</h2>
        <p>Registering is free and takes a minute. To play you'll also need a current social rego (<?php echo esc_html(RSL_Rego::PRICE_LABEL); ?>, lasts 12 months) — unless you're a full or life club member.</p>
        <form method="post" class="rsl-form">
            <?php echo self::fields('register', array('join_code' => $code)); ?>
            <?php echo self::experience_fields(null); ?>
            <?php if (!$invited_to): ?>
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

    private function render_dashboard($user_id, $code, $invited_to) {
        $season = RSL_Seasons::current();
        $player = RSL_Players::get($user_id);
        $team = RSL_Teams::current_team_for_user($user_id, $season);
        $pending = RSL_Teams::pending_application_for_user($user_id, $season);
        $invites = RSL_Teams::pending_invites_for_user($user_id);
        $clear = RSL_Rego::clearance($user_id);
        ?>
        <h2>Reds Social League <span class="rsl-season"><?php echo esc_html(RSL_Seasons::label($season)); ?></span></h2>

        <?php foreach ($invites as $invite): ?>
            <div class="rsl-card rsl-card-accent">
                <p><strong>You're invited to join <?php echo esc_html($invite->team_name); ?>.</strong></p>
                <form method="post" class="rsl-inline-form"><?php echo self::fields('accept_invite', array('invite_id' => $invite->id)); ?><button type="submit" class="rsl-btn">Accept</button></form>
                <form method="post" class="rsl-inline-form"><?php echo self::fields('decline_invite', array('invite_id' => $invite->id)); ?><button type="submit" class="rsl-btn rsl-btn-secondary">Decline</button></form>
            </div>
        <?php endforeach; ?>

        <?php if ($invited_to && !$team): ?>
            <div class="rsl-card rsl-card-accent">
                <p><strong>Join <?php echo esc_html($invited_to->name); ?>?</strong></p>
                <form method="post"><?php echo self::fields('join', array('join_code' => $code)); ?><button type="submit" class="rsl-btn">Join <?php echo esc_html($invited_to->name); ?></button></form>
            </div>
        <?php elseif ($code !== '' && !$invited_to): ?>
            <div class="rsl-flash rsl-flash-error">That invite link isn't valid any more — ask your captain for the current one.</div>
        <?php endif; ?>

        <div class="rsl-card <?php echo $clear['cleared'] ? 'rsl-card-good' : 'rsl-card-warn'; ?>">
            <?php if ($clear['cleared']): ?>
                <p><strong>&#10003; You're cleared to play.</strong>
                    <?php if ($clear['by'] === 'member'): ?>Your full or life club membership covers the social rego<?php echo $clear['until'] < '2099-01-01' ? ' until ' . esc_html(date('j M Y', strtotime($clear['until']))) : ''; ?>.
                    <?php else: ?>Your social rego is paid until <?php echo esc_html(date('j M Y', strtotime($clear['until']))); ?>.<?php endif; ?></p>
                <?php if ($clear['by'] === 'rego' && strtotime($clear['until']) < strtotime('+30 days') && RSL_Rego::pay_url()): ?>
                    <p>It runs out soon — <a href="<?php echo esc_url(RSL_Rego::pay_url()); ?>">renew now</a> (a new 12 months starts when this one ends).</p>
                <?php endif; ?>
            <?php else: ?>
                <p><strong>You need a social rego before your first game.</strong> It's <?php echo esc_html(RSL_Rego::PRICE_LABEL); ?> and lasts 12 months. Full and life club members don't need one.</p>
                <?php if (RSL_Rego::pay_url()): ?>
                    <p><a class="rsl-btn" href="<?php echo esc_url(RSL_Rego::pay_url()); ?>">Pay the <?php echo esc_html(RSL_Rego::PRICE_LABEL); ?> rego</a></p>
                <?php else: ?>
                    <p class="rsl-hint">Online payment isn't set up yet — contact the league to pay.</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if (RSL_Officials::is_official($user_id)): ?>
            <?php $e = RSL_Officials::earnings($user_id, $season); ?>
            <div class="rsl-card"><p><strong>Ref jobs</strong> — <?php echo intval($e['booked']); ?> booked ahead, <?php echo esc_html(RSL_Officials::money($e['earned'])); ?> earned this season. <a href="<?php echo esc_url(RSL_Ref_Page::url()); ?>">See your night and open jobs &rarr;</a></p></div>
        <?php endif; ?>

        <?php if ($team): ?>
            <?php $this->render_my_team($team, $user_id); ?>
        <?php elseif ($pending): ?>
            <div class="rsl-card">
                <p><strong>Your application for <?php echo esc_html($pending->name); ?> is waiting for approval.</strong> We'll email you when it's approved — then you can invite your players.</p>
                <form method="post" onsubmit="return confirm('Withdraw your application for <?php echo esc_js($pending->name); ?>?');"><?php echo self::fields('withdraw', array('team_id' => $pending->id)); ?><button type="submit" class="rsl-btn rsl-btn-secondary">Withdraw application</button></form>
            </div>
        <?php else: ?>
            <?php $declined = RSL_Teams::last_decided_application($user_id, $season); ?>
            <?php if ($declined): ?>
                <div class="rsl-flash rsl-flash-error">Your application for <?php echo esc_html($declined->name); ?> wasn't approved<?php echo $declined->decision_note !== '' ? ': ' . esc_html($declined->decision_note) : '.'; ?></div>
            <?php endif; ?>
            <div class="rsl-card">
                <h3>Join a team</h3>
                <p>Got an invite code from your captain?</p>
                <form method="post" class="rsl-row"><?php echo self::fields('join'); ?><input type="text" name="join_code" placeholder="Invite code" required maxlength="16" autocapitalize="characters"><button type="submit" class="rsl-btn">Join</button></form>
                <form method="post" class="rsl-row"><?php echo self::fields('looking', array('looking' => $player->looking ? '' : '1')); ?>
                    <p><?php echo $player->looking ? 'You\'re on the free agent list.' : 'No team? Join the free agent list and we\'ll help place you.'; ?></p>
                    <button type="submit" class="rsl-btn rsl-btn-secondary"><?php echo $player->looking ? 'Leave the free agent list' : 'Put me on the free agent list'; ?></button>
                </form>
            </div>
            <details class="rsl-card">
                <summary><strong>Captaining a team? Apply for it here</strong></summary>
                <form method="post" class="rsl-form">
                    <?php echo self::fields('apply'); ?>
                    <label class="rsl-field">Team name <input type="text" name="name" required maxlength="100"></label>
                    <label class="rsl-field">Division
                        <select name="division"><?php foreach (RSL_Teams::DIVISIONS as $key => $label): ?><option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option><?php endforeach; ?></select>
                    </label>
                    <label class="rsl-field">Roughly what level does your team play at?
                        <select name="level" required>
                            <option value="">Choose…</option>
                            <?php foreach (RSL_Teams::levels() as $key => $label): ?><option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <p class="rsl-hint">Just a starting guess so your first games are even — it isn't shown anywhere, and your results take over after a couple of weeks.</p>
                    <label class="rsl-field">Team colour <input type="color" name="colour" value="#1d3d6e"></label>
                    <p class="rsl-hint">You'll be the captain. Once the league approves it you'll get an invite link for your players.</p>
                    <button type="submit" class="rsl-btn">Send application</button>
                </form>
            </details>
        <?php endif; ?>

        <details class="rsl-card">
            <summary><strong>My details</strong></summary>
            <form method="post" class="rsl-form"><?php echo self::fields('update_details'); ?><?php echo self::experience_fields($player); ?><?php echo self::ref_field($user_id); ?><button type="submit" class="rsl-btn">Save</button></form>
        </details>
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

            <h4>Players (<?php echo count($members); ?>)</h4>
            <ul class="rsl-roster">
                <?php foreach ($members as $m): ?>
                    <?php $c = RSL_Rego::clearance($m->user_id); ?>
                    <li>
                        <span><?php echo esc_html($m->display_name); ?><?php if ($m->role === 'captain'): ?> <span class="rsl-chip">Captain</span><?php endif; ?></span>
                        <?php if ($is_captain): ?>
                            <span class="rsl-status <?php echo $c['cleared'] ? 'rsl-ok' : 'rsl-no'; ?>"><?php echo $c['cleared'] ? '&#10003; Cleared' : '&#10007; Needs rego'; ?></span>
                            <?php if (intval($m->user_id) !== intval($user_id)): ?>
                                <form method="post" class="rsl-inline-form" onsubmit="return confirm('Remove <?php echo esc_js($m->display_name); ?> from the team?');"><?php echo self::fields('remove_member', array('team_id' => $team->id, 'user_id' => $m->user_id)); ?><button type="submit" class="rsl-link">Remove</button></form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php if ($is_captain): ?>
                <?php $invites = RSL_Teams::pending_invites_for_team($team->id); ?>
                <?php if ($invites): ?>
                    <h4>Waiting to accept</h4>
                    <ul class="rsl-roster">
                        <?php foreach ($invites as $inv): ?>
                            <li><span><?php echo esc_html($inv->email); ?></span><span class="rsl-meta">invited <?php echo esc_html(date('j M', strtotime($inv->created_at))); ?></span>
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
                <p class="rsl-hint">Or invite someone who has already registered, by their email — they'll join once they accept:</p>
                <form method="post" class="rsl-row"><?php echo self::fields('invite', array('team_id' => $team->id)); ?><input type="email" name="email" placeholder="Their email" required><button type="submit" class="rsl-btn">Invite</button></form>
                <p class="rsl-hint">Everyone needs to be cleared (social rego or full/life membership) before their first game.</p>

                <h4>Season schedule</h4>
                <p class="rsl-hint">Your team page shows every week of the season. Need a week off, or fancy two games one night? Ask there before that week's fixtures come out (Mondays). <a href="<?php echo esc_url(RSL_Public::team_url($team)); ?>">Open the season schedule &rarr;</a></p>

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
            .rsl-card > summary { cursor: pointer; }
            .rsl-card-accent { border-left: 4px solid var(--rsl-navy); background: #f5f8fc; }
            .rsl-card-good { border-left: 4px solid #2e8540; background: #f2f9f3; }
            .rsl-card-warn { border-left: 4px solid #d97706; background: #fffaf0; }
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
            .rsl-ok { color: #1e6b2a; } .rsl-no { color: #b32d2e; }
            .rsl-invite { font-family: monospace; }
            .rsl-portal .rsl-badge { display: inline-flex; align-items: center; justify-content: center; flex: 0 0 auto; width: 44px; height: 44px; border-radius: 50%; color: #fff; font-weight: 700; font-size: 16px; }
            .rsl-portal .rsl-team-head { display: flex; align-items: center; gap: 12px; }
            .rsl-portal .rsl-team-head .rsl-title { margin: 0; font-size: 1.2em; }
            .rsl-portal .rsl-team-head a { text-decoration: none; }
            .rsl-portal .rsl-meta { margin: 2px 0 0; color: var(--rsl-muted); }
            .rsl-portal .rsl-chip { display: inline-block; margin-left: 6px; padding: 1px 8px; border-radius: 10px; font-size: 12px; background: #eef2f8; color: var(--rsl-navy); font-weight: 600; }
            .rsl-portal h4 { margin: 16px 0 6px; }
        </style>';
    }
}
