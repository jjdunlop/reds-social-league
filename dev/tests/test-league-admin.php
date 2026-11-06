<?php
/**
 * The Social League admin role (0.22.0): the role and its capabilities,
 * administrators holding the capability through the grant (nothing stored),
 * giving and removing the role keeps an account's other roles, the Ultimate
 * Member filters (called directly, so the test passes without Ultimate
 * Member), the Dashboard rule, real league admin work through the entry
 * points the screens use (a member refused each), the League admins screen
 * and its actions (administrators only), and a source check that nothing in
 * includes/ reads manage_options but RSL_Access's grant filter, its
 * Dashboard rule and the League admins checks.
 *
 *   wp eval-file dev/tests/test-league-admin.php
 *
 * Uses a far-future season and throwaway @example.test users; cleans up.
 */

if (!defined('WP_CLI') || !WP_CLI || (wp_get_environment_type() === 'production' && !getenv('RSL_DEV'))) {
    exit("Local copy only: run with wp eval-file, with RSL_DEV=1 set (docker exec -e RSL_DEV=1 ...).\n");
}
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once dirname(__DIR__) . '/lib-profile.php';
require_once dirname(__DIR__) . '/lib-mail-stop.php';
global $wpdb;

$season = '2085-pre';
$suffix = time();
$hold = rsl_dev_hold_mail($suffix);
$fails = 0;
$check = function ($label, $ok, $detail = '') use (&$fails, $hold) {
    rsl_dev_park_mail($hold);
    if (!$ok) { $fails++; }
    echo ($ok ? "OK: $label\n" : "FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n");
};
// Set before the try, so the cleanup can always run.
$users = array();
$no_promote = null;
$mk = function ($tag, $role = 'subscriber', $name = '') use ($suffix, &$users) {
    $u = wp_insert_user(array('user_login' => "rsla-$tag-$suffix", 'user_email' => "rsla-$tag-$suffix@example.test", 'user_pass' => wp_generate_password(),
        'display_name' => $name !== '' ? $name : "RSLA $tag $suffix", 'role' => $role));
    if (is_int($u)) {
        $users[] = $u;
        rsl_dev_profile($u);
    }
    return $u;
};
$roles_of = function ($user_id) {
    $r = array_values((array) get_userdata($user_id)->roles);
    sort($r);
    return $r;
};
$notice = function () {
    $n = get_transient(RSL_Admin::NOTICE_PREFIX . get_current_user_id());
    delete_transient(RSL_Admin::NOTICE_PREFIX . get_current_user_id());
    return is_array($n) ? $n : array('type' => '', 'message' => '');
};
$flash = function () {
    $f = get_transient(RSL_Portal::FLASH_PREFIX . get_current_user_id());
    delete_transient(RSL_Portal::FLASH_PREFIX . get_current_user_id());
    return is_array($f) ? $f : array('type' => '', 'message' => '');
};
$screen = function ($callable) {
    ob_start();
    call_user_func($callable);
    return (string) ob_get_clean();
};

try {

// --- The role and the capability ------------------------------------------------------------------
RSL_Access::ensure_role();
$role = get_role(RSL_Access::ROLE);
$check('the role exists with read, the league capability and view_admin_dashboard', $role && $role->has_cap('read') && $role->has_cap(RSL_Access::CAP) && $role->has_cap('view_admin_dashboard'));
$check('...and nothing that reaches the rest of WordPress', $role && !$role->has_cap('manage_options') && !$role->has_cap('edit_posts') && !$role->has_cap('promote_users') && !$role->has_cap('list_users'));
$names = wp_roles()->get_names();
$check('its name is Social League admin', isset($names[RSL_Access::ROLE]) && $names[RSL_Access::ROLE] === 'Social League admin');
$check('the capability is rsl_manage_league and the role rsl_league_admin', RSL_Access::CAP === 'rsl_manage_league' && RSL_Access::ROLE === 'rsl_league_admin');
$role->remove_cap('view_admin_dashboard');
RSL_Access::ensure_role();
$check('ensure_role() puts back a capability the role lost', get_role(RSL_Access::ROLE)->has_cap('view_admin_dashboard'));
$check('the hooks are on: the grant, Ultimate Member\'s gate and the role\'s Ultimate Member default, ensure_role on init',
    has_filter('user_has_cap', array('RSL_Access', 'grant_admins')) !== false && has_filter('um_user_permissions_filter', array('RSL_Access', 'um_permissions')) !== false
    && has_filter('default_option_um_role_' . RSL_Access::ROLE . '_meta', array('RSL_Access', 'um_role_default')) !== false && has_action('init', array('RSL_Access', 'ensure_role')) !== false);

// --- Administrators hold it through the grant -------------------------------------------------------
$admin = $mk('admin', 'administrator');
$check('an administrator holds the league capability', user_can($admin, RSL_Access::CAP) && RSL_Access::can($admin));
$stored = get_user_meta($admin, $wpdb->get_blog_prefix() . 'capabilities', true);
$check('...with nothing stored: not on the administrator role, not on the account', !get_role('administrator')->has_cap(RSL_Access::CAP) && is_array($stored) && !isset($stored[RSL_Access::CAP]) && !isset($stored[RSL_Access::ROLE]));
$granted = RSL_Access::grant_admins(array('manage_options' => true, 'read' => true));
$plain = RSL_Access::grant_admins(array('read' => true));
$check('the grant filter adds it for manage_options only', !empty($granted[RSL_Access::CAP]) && !isset($plain[RSL_Access::CAP]) && RSL_Access::grant_admins('x') === 'x');

// --- Giving and removing the role keeps the account's other roles ------------------------------------------
$la = $mk('la', 'subscriber', "RSLA Bea & O'Neil $suffix");
$other = $mk('other');
$member = $mk('member');
$cap_a = $mk('capa');
$cap_b = $mk('capb');
if (get_role('customer')) {
    (new WP_User($la))->add_role('customer');
    (new WP_User($other))->add_role('customer');
}
$la_before = $roles_of($la);
$other_before = $roles_of($other);
$la_name = RSL_Access::plain_name(get_userdata($la));
$check('a plain member isn\'t a league admin', !RSL_Access::can($la) && !RSL_Access::can($member));
$r = RSL_Access::give("rsla-la-$suffix@example.test", $admin);
$check('give by email: the role is added', is_string($r) && in_array(RSL_Access::ROLE, $roles_of($la), true), is_wp_error($r) ? $r->get_error_message() : '');
$check('...the account\'s other roles stay', array_values(array_diff($roles_of($la), array(RSL_Access::ROLE))) === $la_before, implode(',', $roles_of($la)));
$check('...and the words name them as plain text', $r === $la_name . ' is now a Social League admin. Their other roles are unchanged.', (string) (is_string($r) ? $r : ''));
$check('a league admin passes RSL_Access::can() and has no manage_options, edit_posts or promote_users',
    RSL_Access::can($la) && !user_can($la, 'manage_options') && !user_can($la, 'edit_posts') && !user_can($la, 'promote_users'));
$check('...and has view_admin_dashboard (WooCommerce lets them into wp-admin)', user_can($la, 'view_admin_dashboard'));
wp_set_current_user($la);
$check('RSL_Access::can() with no argument is the current user', RSL_Access::can() && !current_user_can('manage_options'));
$check('...and a missing id is nobody, never the current user', !RSL_Access::can(null) && !RSL_Access::can(0));
wp_set_current_user($admin);
$check('giving it again says so', RSL_Access::give("rsla-la-$suffix", $admin) === $la_name . ' is already a Social League admin.');
$r = RSL_Access::revoke($la, $admin);
$check('revoke: the role goes and every other role stays', $r === $la_name . ' is no longer a Social League admin. Their other roles are unchanged.' && $roles_of($la) === $la_before && !RSL_Access::can($la), implode(',', $roles_of($la)));
$check('revoking someone without it says so', RSL_Access::revoke($la, $admin) === $la_name . ' isn\'t a Social League admin.');
$r = RSL_Access::give("rsla-la-$suffix", $admin);
$check('give by login', is_string($r) && RSL_Access::can($la) && array_values(array_diff($roles_of($la), array(RSL_Access::ROLE))) === $la_before);
$e = RSL_Access::give('', $admin);
$check('nothing typed: a plain sentence', is_wp_error($e) && $e->get_error_message() === 'Type an email address or login.');
$e = RSL_Access::give("nobody-$suffix@example.test", $admin);
$check('an unknown account: a plain sentence', is_wp_error($e) && $e->get_error_message() === 'No account has that email address or login.');
$e = RSL_Access::give("rsla-admin-$suffix@example.test", $admin);
$admin_name = RSL_Access::plain_name(get_userdata($admin));
$check('an administrator is refused: X is an administrator, so already has the Social League screens.', is_wp_error($e) && $e->get_error_message() === $admin_name . ' is an administrator, so already has the Social League screens.'
    && !in_array(RSL_Access::ROLE, $roles_of($admin), true));
$e = RSL_Access::revoke(999999999, $admin);
$check('removing an account that has gone: a plain sentence', is_wp_error($e) && $e->get_error_message() === 'That account no longer exists.');
$e1 = RSL_Access::give("rsla-other-$suffix", $la);
$e2 = RSL_Access::revoke($la, $la);
$check('a league admin can\'t change who holds the role (give or revoke)', is_wp_error($e1) && is_wp_error($e2) && $e1->get_error_message() === RSL_Access::NOT_ALLOWED
    && !in_array(RSL_Access::ROLE, $roles_of($other), true) && RSL_Access::can($la));
$check('the holders list has them', in_array($la, array_map(function ($u) { return (int) $u->ID; }, RSL_Access::holders()), true));

// --- Ultimate Member (the filter functions called directly) --------------------------------------------
$p = RSL_Access::um_permissions(array('can_access_wpadmin' => 0, 'can_edit_everyone' => 0), $la);
$check('Ultimate Member: a league admin may enter wp-admin', $p['can_access_wpadmin'] === 1 && $p['can_edit_everyone'] === 0);
$p = RSL_Access::um_permissions(array('can_access_wpadmin' => 0), $member);
$check('...a plain member may not (unchanged)', $p['can_access_wpadmin'] === 0);
$p = RSL_Access::um_permissions(array('can_access_wpadmin' => 0), 0);
$check('...nor nobody, and a value that isn\'t an array passes through', $p['can_access_wpadmin'] === 0 && RSL_Access::um_permissions('x', $la) === 'x');
$p = RSL_Access::um_permissions(array('can_access_wpadmin' => 0), $admin);
$check('...an administrator may (they hold the capability)', $p['can_access_wpadmin'] === 1);
$d = RSL_Access::um_role_default(false);
$check('the role\'s Ultimate Member default has priority -1', is_array($d) && $d['_um_priority'] === -1);
$d = RSL_Access::um_role_default(array('_um_priority' => 5, '_um_can_access_wpadmin' => 0));
$check('...over any other default, keeping the rest', $d['_um_priority'] === -1 && $d['_um_can_access_wpadmin'] === 0);
$d = apply_filters('default_option_um_role_' . RSL_Access::ROLE . '_meta', false, 'um_role_' . RSL_Access::ROLE . '_meta', false);
$check('...through the hook too', is_array($d) && $d['_um_priority'] === -1);

// --- The Dashboard ----------------------------------------------------------------------------------
wp_set_current_user($la);
$check('a league admin opening the Dashboard goes to the Teams screen', RSL_Access::dashboard_target() === admin_url('admin.php?page=' . RSL_Admin::PAGE));
wp_set_current_user($admin);
$check('...an administrator stays', RSL_Access::dashboard_target() === '');
wp_set_current_user($member);
$check('...and a member is left alone', RSL_Access::dashboard_target() === '');

// --- Real league admin work through the screens' entry points --------------------------------------------
$today = current_time('Y-m-d');
$start = date('Y-m-d', strtotime($today . ' +7 days'));
RSL_Courts::save_settings($season, array('start' => $start, 'end' => date('Y-m-d', strtotime($start . ' +3 weeks')), 'weekday' => intval(date('w', strtotime($start)))));
RSL_Courts::generate_nights($season);
$nights = RSL_Courts::nights($season);
$night = $nights ? $nights[0] : null;
$app = RSL_Teams::create($season, array('name' => "LA App $suffix", 'division' => 'unranked', 'level' => 'social', 'status' => 'pending', 'applied_by' => $cap_a));
RSL_Teams::add_member($app->id, $cap_a, 'captain', true);
$team = RSL_Teams::create($season, array('name' => "LA Team $suffix", 'division' => 'unranked', 'level' => 'social'));
RSL_Teams::add_member($team->id, $cap_b, 'captain');
$check('set-up: a pending application, an active team and a regular night to come', $night && !is_wp_error($app) && $app->status === 'pending' && !is_wp_error($team) && RSL_Teams::is_captain($team->id, $cap_b));

$approve = function () use ($season, $app) {
    return RSL_Admin::process(array('rsl_nonce' => wp_create_nonce('rsl_team_action'), 'season' => $season, 'do' => 'approve', 'team_id' => $app->id));
};
wp_set_current_user($member);
$approve();
$n = $notice();
$check('Teams screen: a member can\'t approve an application', RSL_Teams::get($app->id)->status === 'pending' && $n['type'] === 'error' && $n['message'] === 'Security check failed — please try again.');
wp_set_current_user($la);
$approve();
$n = $notice();
$check('...a league admin can (RSL_Admin::process)', RSL_Teams::get($app->id)->status === 'active' && strpos($n['message'], "LA App $suffix approved") === 0, $n['message']);

$adjust = function ($amount) use ($team) {
    return RSL_Admin_Payments::process(array('rsl_nonce' => wp_create_nonce('rsl_payments_action'), 'do' => 'adjust', 'team_id' => $team->id, 'amount' => $amount, 'note' => 'Cash at the venue'));
};
$b0 = RSL_Payments::balance($team->id);
wp_set_current_user($member);
$adjust('20');
$n = $notice();
$check('Team Balances: a member can\'t adjust a balance', abs(RSL_Payments::balance($team->id) - $b0) < 0.001 && $n['type'] === 'error');
wp_set_current_user($la);
$adjust('20');
$n = $notice();
$check('...a league admin can (RSL_Admin_Payments::process)', abs(RSL_Payments::balance($team->id) - ($b0 + 20)) < 0.001 && $n['message'] === 'Recorded.', (string) RSL_Payments::balance($team->id));

$ask = function () use ($team, $night) {
    return RSL_Portal::process(array('rsl_nonce' => wp_create_nonce('rsl_front'), 'rsl_front' => 1, 'do' => 'request_off', 'team_id' => $team->id, 'night_id' => $night->id, 'back' => RSL_Public::team_url($team)));
};
wp_set_current_user($member);
$ask();
$f = $flash();
$reqs = RSL_Requests::for_team($team->id);
$check('Team page: a member can\'t ask for a week off for a team they don\'t captain', !isset($reqs[intval($night->id)]) && $f['message'] === 'Only the team captain can do that.', $f['message']);
wp_set_current_user($la);
$ask();
$f = $flash();
$reqs = RSL_Requests::for_team($team->id);
$check('...a league admin can (RSL_Portal::process)', isset($reqs[intval($night->id)]) && $reqs[intval($night->id)]->type === 'off' && intval($reqs[intval($night->id)]->requested_by) === $la && $f['type'] === 'success', $f['message']);

$team_now = RSL_Teams::get($team->id);
wp_set_current_user($la);
$html_la = RSL_Public_Fixtures::team_section($team_now);
$tools_la = RSL_Portal::team_tools($team_now, $la);
wp_set_current_user($member);
$html_m = RSL_Public_Fixtures::team_section($team_now);
$tools_m = RSL_Portal::team_tools($team_now, $member);
$check('the team page gives a league admin the request buttons and the roster, a member neither', strpos($html_la, 'value="request_double"') !== false && $tools_la !== ''
    && strpos($html_m, 'value="request_') === false && $tools_m === '');
$game = (object) array('id' => 0, 'night_id' => $night->id, 'night_date' => $night->night_date, 'status' => 'scheduled', 'result_by' => 0);
$check('a league admin may enter any game\'s score, a member only as its referee', RSL_Officials::why_cant_score($la, $game) === null && is_wp_error(RSL_Officials::why_cant_score($member, $game)));

$_GET = array('page' => RSL_Admin::PAGE, 'season' => $season);
wp_set_current_user($la);
$teams_la = $screen(array(new RSL_Admin(), 'render'));
$reg_la = $screen(array(new RSL_Admin(), 'render_registration'));
$_GET = array('page' => RSL_Admin_Payments::PAGE);
$pay_la = $screen(array(new RSL_Admin_Payments(), 'render'));
wp_set_current_user($member);
$_GET = array('page' => RSL_Admin::PAGE, 'season' => $season);
$teams_m = $screen(array(new RSL_Admin(), 'render'));
$_GET = array();
$check('the Teams, Registration and Team Balances screens open for a league admin, never for a member',
    strpos($teams_la, 'Social League — Teams') !== false && strpos($reg_la, 'Social League — Registration') !== false && strpos($pay_la, '<h1>Team Balances</h1>') !== false && $teams_m === '');

// --- The League admins screen: administrators only ---------------------------------------------------------
$act = function ($fields) {
    return RSL_Access::process(array_merge(array('rsl_nonce' => wp_create_nonce(RSL_Access::ACTION)), $fields));
};
wp_set_current_user($la);
$to = $act(array('do' => 'add', 'who' => wp_slash("rsla-other-$suffix")));
$n = $notice();
$check('League admins: a league admin\'s Add is refused, back to the Teams screen with a sentence', !in_array(RSL_Access::ROLE, $roles_of($other), true)
    && $to === admin_url('admin.php?page=' . RSL_Admin::PAGE) && $n['type'] === 'error' && $n['message'] === 'Only an administrator can change who is a Social League admin.', $n['message']);
$act(array('do' => 'remove', 'user_id' => $la));
$n = $notice();
$check('...and so is their Remove (they keep the role)', RSL_Access::can($la) && $n['type'] === 'error');
$check('...and the screen shows them nothing', $screen(array('RSL_Access', 'render')) === '');
wp_set_current_user($member);
$act(array('do' => 'add', 'who' => "rsla-member-$suffix"));
$check('...a member is refused too', !RSL_Access::can($member));

wp_set_current_user($admin);
RSL_Access::process(array('rsl_nonce' => 'bogus', 'do' => 'add', 'who' => "rsla-other-$suffix"));
$n = $notice();
$check('an administrator with a stale form: the security check fails, nothing changes', !in_array(RSL_Access::ROLE, $roles_of($other), true) && $n['message'] === 'Security check failed — please try again.');
$to = $act(array('do' => 'add', 'who' => wp_slash("rsla-other-$suffix@example.test")));
$n = $notice();
$other_name = RSL_Access::plain_name(get_userdata($other));
$check('an administrator adds a league admin (back to the screen, with a notice)', in_array(RSL_Access::ROLE, $roles_of($other), true) && $to === admin_url('admin.php?page=' . RSL_Access::PAGE)
    && $n['type'] === 'success' && $n['message'] === $other_name . ' is now a Social League admin. Their other roles are unchanged.', $n['message']);
$check('...the other roles stay', array_values(array_diff($roles_of($other), array(RSL_Access::ROLE))) === $other_before);
$act(array('do' => 'add', 'who' => "rsla-admin-$suffix"));
$n = $notice();
$check('...an administrator can\'t be added', $n['type'] === 'error' && $n['message'] === $admin_name . ' is an administrator, so already has the Social League screens.');
$act(array('do' => 'add', 'who' => "nobody-$suffix"));
$n = $notice();
$check('...nor an unknown account', $n['type'] === 'error' && $n['message'] === 'No account has that email address or login.');
$html = $screen(array('RSL_Access', 'render'));
$check('the screen lists each league admin by name and email, with Remove', strpos($html, esc_html($la_name)) !== false && strpos($html, "rsla-la-$suffix@example.test") !== false
    && strpos($html, esc_html($other_name)) !== false && strpos($html, 'value="remove"') !== false && strpos($html, '&amp;amp;') === false);
$check('...the Add form and the line about Users > Edit', strpos($html, 'name="who"') !== false && strpos($html, 'value="' . RSL_Access::ACTION . '"') !== false
    && strpos($html, "Changing someone's role on Users &gt; Edit can remove this role; add it again here if that happens.") !== false);
$act(array('do' => 'remove', 'user_id' => $other));
$n = $notice();
$check('an administrator removes one; their other roles stay', !in_array(RSL_Access::ROLE, $roles_of($other), true) && $roles_of($other) === $other_before
    && $n['message'] === $other_name . ' is no longer a Social League admin. Their other roles are unchanged.', $n['message']);
$act(array('do' => 'bogus'));
$n = $notice();
$check('an unknown action is refused', $n['type'] === 'error' && $n['message'] === 'Unknown action.');
$no_promote = function ($allcaps) { unset($allcaps['promote_users']); return $allcaps; };
add_filter('user_has_cap', $no_promote, 99);
$act(array('do' => 'add', 'who' => "rsla-other-$suffix"));
$n = $notice();
$refused = !RSL_Access::may_change($admin) && !in_array(RSL_Access::ROLE, $roles_of($other), true) && $n['message'] === RSL_Access::NOT_ALLOWED;
remove_filter('user_has_cap', $no_promote, 99);
$no_promote = null;
$check('manage_options alone isn\'t enough: changing who holds the role needs promote_users too', $refused && RSL_Access::may_change($admin));

// --- Source check: league work never reads manage_options --------------------------------------------------
$dir = RSL_PLUGIN_DIR . 'includes/';
$named = array();
foreach (glob($dir . '*.php') as $file) {
    if (basename($file) !== 'class-rsl-access.php' && strpos((string) file_get_contents($file), 'manage_options') !== false) {
        $named[] = basename($file);
    }
}
$check('no file in includes/ but class-rsl-access.php contains manage_options (comments included)', !$named, implode(', ', $named));
$spans = array();
foreach ((new ReflectionClass('RSL_Access'))->getMethods() as $m) {
    $spans[$m->getName()] = array($m->getStartLine(), $m->getEndLine());
}
$where = array();
foreach (token_get_all((string) file_get_contents($dir . 'class-rsl-access.php')) as $tok) {
    if (is_array($tok) && $tok[0] === T_CONSTANT_ENCAPSED_STRING && trim($tok[1], '\'"') === 'manage_options') {
        $in = '(outside a method)';
        foreach ($spans as $name => $s) {
            if ($tok[2] >= $s[0] && $tok[2] <= $s[1]) { $in = $name; }
        }
        $where[] = $in;
    }
}
sort($where);
$check('...and in class-rsl-access.php only the grant filter, the Dashboard rule and the League admins checks (menu, may_change, give) read it',
    $where === array('dashboard_target', 'give', 'grant_admins', 'may_change', 'menu'), implode(', ', $where));
$body = function ($method) {
    $m = new ReflectionMethod('RSL_Access', $method);
    return implode('', array_slice(file($m->getFileName()), $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
};
$check('...the screen and its process check manage_options and promote_users (may_change), the data writers too',
    strpos($body('may_change'), "'promote_users'") !== false && strpos($body('process'), 'self::may_change()') !== false && strpos($body('render'), 'self::may_change()') !== false
    && strpos($body('give'), 'self::may_change($actor)') !== false && strpos($body('revoke'), 'self::may_change($actor)') !== false);
$menus = array();
foreach (glob($dir . '*.php') as $file) {
    foreach (file($file) as $i => $line) {
        if (preg_match('/\badd_(sub)?menu_page\(/', $line)) {
            $menus[basename($file) . ':' . ($i + 1)] = strpos($line, 'RSL_Access::CAP') !== false
                || (basename($file) === 'class-rsl-access.php' && strpos($line, "'manage_options'") !== false);
        }
    }
}
$check('every Social League menu uses RSL_Access::CAP; League admins alone uses manage_options (' . count($menus) . ' menus)', count($menus) >= 9 && !in_array(false, $menus, true),
    implode(', ', array_keys(array_filter($menus, function ($ok) { return !$ok; }))));

} catch (Throwable $thrown) {
    $fails++;
    echo 'FAIL: threw ' . get_class($thrown) . ': ' . $thrown->getMessage() . ' (line ' . $thrown->getLine() . ")\n";
} finally {
// --- Cleanup (runs after a failure too) ------------------------------------------------------------------
$_GET = array();
wp_set_current_user(0);
if ($no_promote !== null) {
    remove_filter('user_has_cap', $no_promote, 99);
}
RSL_Access::ensure_role(); // a capability the role lost mid-test is back
foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM " . RSL_Database::table('teams') . " WHERE season = %s", $season)) as $tid) {
    $wpdb->delete(RSL_Database::table('ledger'), array('team_id' => $tid));
    $wpdb->delete(RSL_Database::table('requests'), array('team_id' => $tid));
    $wpdb->delete(RSL_Database::table('invites'), array('team_id' => $tid));
    $wpdb->delete(RSL_Database::table('team_members'), array('team_id' => $tid));
    $wpdb->delete(RSL_Database::table('teams'), array('id' => $tid));
}
$wpdb->delete(RSL_Database::table('ledger'), array('season' => $season));
foreach (RSL_Courts::nights($season) as $n) {
    $wpdb->delete(RSL_Database::table('night_courts'), array('night_id' => $n->id));
    $wpdb->delete(RSL_Database::table('night_venues'), array('night_id' => $n->id));
}
$wpdb->delete(RSL_Database::table('nights'), array('season' => $season));
delete_transient(RSL_Stats::CACHE_PREFIX . $season); // the team page read the season's stats
RSL_Ratings::forget_start($season); // nothing was published, so nothing should be stored; forget it anyway
$settings = get_option(RSL_Courts::SETTINGS_OPTION, array());
if (is_array($settings) && isset($settings[$season])) {
    unset($settings[$season]);
    update_option(RSL_Courts::SETTINGS_OPTION, $settings, false);
}
rsl_dev_delete_users($users);
rsl_dev_release_mail($hold);
}
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
