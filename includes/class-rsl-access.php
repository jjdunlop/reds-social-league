<?php
/**
 * The Social League admin role (0.22.0, club decision 6 Nov 2026): trusted
 * people who run the league (Team Balances included) without being
 * WordPress administrators. The same approach as the Club Manager's Coach
 * bios editor role, copied rather than shared, so neither plugin needs the
 * other.
 *
 *  - CAP is the one capability every Social League screen (its menu
 *    capability), admin-post handler and front-end admin power reads, always
 *    through can(). Administrators (anyone with manage_options) hold it
 *    through grant_admins(), so nothing is stored for them.
 *  - ROLE holds read, CAP and view_admin_dashboard (WooCommerce's wp-admin
 *    gate). Ultimate Member's gate reads can_access_wpadmin, which
 *    um_permissions() gives every CAP holder; while nobody has set the role
 *    up in Ultimate Member its priority is -1 (um_role_default()), so it never
 *    becomes a member's priority role.
 *  - Social League > League admins is for administrators only (manage_options
 *    and promote_users on the screen and on every action): who holds the
 *    role, Add by email or login and Remove, with add_role() / remove_role(),
 *    so a person's other roles stay. The Role dropdown on Users > Edit
 *    replaces every role, so it can take this one away; the screen says so.
 *  - A league admin who opens the Dashboard goes to the Teams screen
 *    (dashboard_target()), as there is nothing else for them there.
 *
 * The role lives in the account's roles, never in a league table, so
 * RSL_Merge has nothing to list; the Club Manager's merge keeps the kept
 * account's roles and won't merge away an account holding a role that isn't
 * a member role. ensure_role() never removes the role, on deactivation
 * included.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RSL_Access {

    /** The capability every league admin check reads, through can(). Administrators hold it through grant_admins(). */
    const CAP = 'rsl_manage_league';
    /** The Social League admin role: read, CAP and view_admin_dashboard. */
    const ROLE = 'rsl_league_admin';
    const ROLE_NAME = 'Social League admin';
    /** Social League > League admins (administrators only). */
    const PAGE = 'rsl-league-admins';
    /** The League admins screen's admin-post action, also its nonce action. */
    const ACTION = 'rsl_access_action';
    const NOT_ALLOWED = 'Only an administrator can change who is a Social League admin.';

    /** Every hook, added once per request (from rsl_init()). */
    public static function register_hooks() {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        add_action('init', array(__CLASS__, 'ensure_role'));
        add_filter('user_has_cap', array(__CLASS__, 'grant_admins'), 10, 1);
        // Ultimate Member's wp-admin block reads can_access_wpadmin; WooCommerce's reads view_admin_dashboard (a role cap).
        add_filter('um_user_permissions_filter', array(__CLASS__, 'um_permissions'), 10, 2);
        add_filter('default_option_um_role_' . self::ROLE . '_meta', array(__CLASS__, 'um_role_default'), 10, 1);
        if (is_admin()) {
            // After the other Social League screens (admin_menu at 10 to 27), so League admins comes last.
            add_action('admin_menu', array(__CLASS__, 'menu'), 30);
            add_action('admin_post_' . self::ACTION, array(__CLASS__, 'handle'));
            add_action('load-index.php', array(__CLASS__, 'dashboard_redirect'));
        }
    }

    // ------------------------------------------------------------------
    // The role, the capability and the two wp-admin gates
    // ------------------------------------------------------------------

    public static function role_caps() {
        return array('read' => true, self::CAP => true, 'view_admin_dashboard' => true);
    }

    /**
     * init: the role exists with its three capabilities. A missing one is
     * added after a has_cap() check, so the roles option is written only
     * when something is missing. The role is never removed.
     */
    public static function ensure_role() {
        $role = get_role(self::ROLE);
        if (!$role) {
            add_role(self::ROLE, self::ROLE_NAME, self::role_caps());
            return;
        }
        foreach (self::role_caps() as $cap => $grant) {
            if (!$role->has_cap($cap)) {
                $role->add_cap($cap, $grant);
            }
        }
    }

    /** user_has_cap: anyone with manage_options holds CAP too. Nothing is written to the database. */
    public static function grant_admins($allcaps) {
        if (is_array($allcaps) && !empty($allcaps['manage_options'])) {
            $allcaps[self::CAP] = true;
        }
        return $allcaps;
    }

    /** um_user_permissions_filter: a CAP holder may enter wp-admin past Ultimate Member's block. */
    public static function um_permissions($perms, $user_id = 0) {
        if (is_array($perms) && intval($user_id) > 0 && user_can(intval($user_id), self::CAP)) {
            $perms['can_access_wpadmin'] = 1;
        }
        return $perms;
    }

    /**
     * default_option_um_role_<ROLE>_meta: while nobody has set the role up in
     * Ultimate Member, give it priority -1, so it never wins over a member
     * role when Ultimate Member picks a user's priority role (a role with no
     * settings counts as 0 and could tie with customer or a member tier).
     */
    public static function um_role_default($default) {
        $meta = is_array($default) ? $default : array();
        $meta['_um_priority'] = -1;
        return $meta;
    }

    /**
     * May this person do league admin work? The ONE check: every Social
     * League screen, admin-post handler, front-end admin power and
     * captain-or-admin rule in the data classes calls it. With no argument,
     * the current user; an argument is always an account id, so a missing id
     * (null, 0) is nobody and is never taken for the current user.
     */
    public static function can($user_id = null) {
        if (func_num_args() === 0) {
            return current_user_can(self::CAP);
        }
        $user_id = intval($user_id);
        return $user_id > 0 && user_can($user_id, self::CAP);
    }

    /**
     * Where the Dashboard sends the current user: the Teams screen for a CAP
     * holder without manage_options and without edit_posts (a league admin
     * with nothing else in wp-admin), else '' (they stay).
     */
    public static function dashboard_target() {
        if (!self::can() || current_user_can('manage_options') || current_user_can('edit_posts')) {
            return '';
        }
        return admin_url('admin.php?page=' . RSL_Admin::PAGE);
    }

    /** load-index.php: a league admin opening the Dashboard goes to the Teams screen. */
    public static function dashboard_redirect() {
        $to = self::dashboard_target();
        if ($to !== '') {
            wp_safe_redirect($to);
            exit;
        }
    }

    // ------------------------------------------------------------------
    // Who holds the role (the League admins screen's data)
    // ------------------------------------------------------------------

    /** May this person change who holds the role? Administrators only: manage_options and promote_users. The current user when null. */
    public static function may_change($user_id = null) {
        $user_id = $user_id === null ? get_current_user_id() : intval($user_id);
        return $user_id > 0 && user_can($user_id, 'manage_options') && user_can($user_id, 'promote_users');
    }

    /** The accounts holding the role, by display name. */
    public static function holders() {
        $users = get_users(array('role' => self::ROLE, 'orderby' => 'display_name', 'order' => 'ASC'));
        return is_array($users) ? $users : array();
    }

    /** An account's name as plain text (WordPress stores display names HTML-escaped): escape it once where it's shown. */
    public static function plain_name($user) {
        $name = trim(wp_specialchars_decode((string) $user->display_name, ENT_QUOTES));
        return $name !== '' ? $name : (string) $user->user_login;
    }

    /**
     * Give the role to the account with that email address, else that login.
     * add_role() keeps every other role. Refuses an unknown account and an
     * administrator (who holds CAP already). Returns the sentence to show, or
     * WP_Error.
     */
    public static function give($who, $actor) {
        if (!self::may_change($actor)) {
            return new WP_Error('rsl_access', self::NOT_ALLOWED);
        }
        $who = trim((string) $who);
        if ($who === '') {
            return new WP_Error('rsl_access', 'Type an email address or login.');
        }
        $user = get_user_by('email', $who);
        if (!$user) {
            $user = get_user_by('login', $who);
        }
        if (!$user) {
            return new WP_Error('rsl_access', 'No account has that email address or login.');
        }
        $name = self::plain_name($user);
        if (user_can($user, 'manage_options')) {
            return new WP_Error('rsl_access', $name . ' is an administrator, so already has the Social League screens.');
        }
        if (in_array(self::ROLE, (array) $user->roles, true)) {
            return $name . ' is already a Social League admin.';
        }
        self::ensure_role();
        $user->add_role(self::ROLE);
        return $name . ' is now a Social League admin. Their other roles are unchanged.';
    }

    /** Take the role off an account. remove_role() keeps every other role. Returns the sentence to show, or WP_Error. */
    public static function revoke($user_id, $actor) {
        if (!self::may_change($actor)) {
            return new WP_Error('rsl_access', self::NOT_ALLOWED);
        }
        $user = get_userdata(intval($user_id));
        if (!$user) {
            return new WP_Error('rsl_access', 'That account no longer exists.');
        }
        $name = self::plain_name($user);
        if (!in_array(self::ROLE, (array) $user->roles, true)) {
            return $name . ' isn\'t a Social League admin.';
        }
        $user->remove_role(self::ROLE);
        return $name . ' is no longer a Social League admin. Their other roles are unchanged.';
    }

    // ------------------------------------------------------------------
    // Social League > League admins (administrators only)
    // ------------------------------------------------------------------

    public static function menu() {
        add_submenu_page(RSL_Admin::PAGE, 'League admins', 'League admins', 'manage_options', self::PAGE, array(__CLASS__, 'render'));
    }

    private static function url() {
        return admin_url('admin.php?page=' . self::PAGE);
    }

    /** admin-post.php?action=rsl_access_action: process, then redirect back. */
    public static function handle() {
        wp_safe_redirect(self::process($_POST));
        exit;
    }

    /**
     * One action from posted fields (add: who; remove: user_id); returns where
     * to go back to. Testable. Someone who isn't an administrator goes back to
     * the Teams screen, which a league admin can open, with the refusal.
     */
    public static function process($post) {
        if (!self::may_change()) {
            self::notice('error', self::NOT_ALLOWED);
            return admin_url('admin.php?page=' . RSL_Admin::PAGE);
        }
        if (!isset($post['rsl_nonce']) || !wp_verify_nonce($post['rsl_nonce'], self::ACTION)) {
            self::notice('error', 'Security check failed — please try again.');
            return self::url();
        }
        $do = isset($post['do']) ? sanitize_key($post['do']) : '';
        if ($do === 'add') {
            $result = self::give(isset($post['who']) ? sanitize_text_field(wp_unslash($post['who'])) : '', get_current_user_id());
        } elseif ($do === 'remove') {
            $result = self::revoke(isset($post['user_id']) ? intval($post['user_id']) : 0, get_current_user_id());
        } else {
            $result = new WP_Error('rsl_access', 'Unknown action.');
        }
        self::notice(is_wp_error($result) ? 'error' : 'success', is_wp_error($result) ? $result->get_error_message() : $result);
        return self::url();
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

    private static function fields($do) {
        return wp_nonce_field(self::ACTION, 'rsl_nonce', true, false)
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '"><input type="hidden" name="do" value="' . esc_attr($do) . '">';
    }

    public static function render() {
        if (!self::may_change()) {
            return;
        }
        $post_url = esc_url(admin_url('admin-post.php'));
        $holders = self::holders();
        echo '<div class="wrap"><h1>Social League — League admins</h1>' . self::take_notice();
        echo '<p>Social League admins have every Social League screen here and the league\'s admin powers on the website, for example a request for any team or any game\'s score. They get nothing else in WordPress. Administrators have all of this already.</p>';
        if (!$holders) {
            echo '<p><strong>Nobody is a Social League admin yet.</strong></p>';
        } else {
            echo '<table class="widefat striped" style="max-width: 760px;"><thead><tr><th>Name</th><th>Email</th><th></th></tr></thead><tbody>';
            foreach ($holders as $u) {
                $name = self::plain_name($u);
                $confirm = 'Remove ' . $name . ' as a Social League admin? Their other roles stay as they are.';
                echo '<tr><td>' . esc_html($name) . '</td><td>' . esc_html($u->user_email) . '</td><td style="text-align: right;">'
                    . '<form method="post" action="' . $post_url . '" onsubmit="return confirm(\'' . esc_js($confirm) . '\');">'
                    . self::fields('remove') . '<input type="hidden" name="user_id" value="' . intval($u->ID) . '">'
                    . '<button type="submit" class="button button-small">Remove</button></form></td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '<h2>Add a league admin</h2>';
        echo '<form method="post" action="' . $post_url . '">' . self::fields('add')
            . '<label for="rsl-access-who">Email or login</label> <input type="text" class="regular-text" id="rsl-access-who" name="who" value="" autocomplete="off"> '
            . '<button type="submit" class="button button-primary">Add</button></form>';
        echo '<p class="description">Changing someone\'s role on Users &gt; Edit can remove this role; add it again here if that happens.</p>';
        echo '<p class="description">League admins don\'t see the admin bar on the website, so send them this link to the Teams screen: <code>' . esc_html(admin_url('admin.php?page=' . RSL_Admin::PAGE)) . '</code></p>';
        echo '</div>';
    }
}
