<?php
/**
 * Remembering a captain's invite through sign-up: the code survives the
 * new-account detour (cookie → account), the league page offers it without
 * the link, the person is brought back to the league page (never away from
 * account/profile pages), and it's forgotten once they join or if the
 * captain's link has changed.
 *
 *   wp eval-file dev/tests/test-invite-memory.php
 */

if (!defined('WP_CLI') || !WP_CLI) {
    exit("Run with wp eval-file.\n");
}
require_once ABSPATH . 'wp-admin/includes/user.php';
global $wpdb;

$season = '2093-pre';
$suffix = time();
$fails = 0;
$check = function ($label, $ok, $detail = '') use (&$fails) {
    if (!$ok) { $fails++; }
    echo ($ok ? "OK: $label\n" : "FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n");
};
$saved_current = get_option(RSL_Seasons::CURRENT_OPTION, '__unset__');
update_option(RSL_Seasons::CURRENT_OPTION, $season);
add_filter('pre_wp_mail', '__return_true');
$users = array();
$mk = function ($tag) use ($suffix, &$users) {
    $u = wp_insert_user(array('user_login' => "rsli-$tag-$suffix", 'user_email' => "rsli-$tag-$suffix@example.test", 'user_pass' => wp_generate_password(), 'display_name' => "RSLI $tag $suffix"));
    $users[] = $u;
    return $u;
};

$team = RSL_Teams::create($season, array('name' => "Invite Team $suffix"));
$code = $team->invite_code;
unset($_COOKIE[RSL_Portal::JOIN_COOKIE]);

// --- A brand-new player: link opened while logged out, then creates an account ----
$_COOKIE[RSL_Portal::JOIN_COOKIE] = strtolower($code); // what opening the link leaves in the browser
$new = $mk('new');
$check('new account picks up the invite from the browser', get_user_meta($new, RSL_Portal::JOIN_META, true) === $code);
$check('and is flagged to come back to the league page', (bool) get_user_meta($new, RSL_Portal::SEND_META, true));

// Elsewhere on the site (another device: no cookie), the account still has it.
unset($_COOKIE[RSL_Portal::JOIN_COOKIE]);
$check('remembered on the account without the cookie', RSL_Portal::remembered_code($new) === $code);
$check('sent to the league page from an ordinary page', RSL_Portal::portal_redirect_for_request($new, false, false) === RSL_Portal::url());
$check('never pulled away from account / profile pages (profile update first)', RSL_Portal::portal_redirect_for_request($new, false, true) === '');
$check('not redirected when already on the league page', RSL_Portal::portal_redirect_for_request($new, true, false) === '');

// The league page, without the link, offers the remembered invite.
RSL_Portal::ensure_page();
$portal = new RSL_Portal();
wp_set_current_user($new);
$_GET = array();
$html = $portal->render();
$check('league page offers "Register and join" without the link', strpos($html, "Register and join Invite Team $suffix") !== false && strpos($html, 'value="' . $code . '"') !== false);
$check('arriving clears the redirect flag', !get_user_meta($new, RSL_Portal::SEND_META, true));
RSL_Portal::process(array('rsl_nonce' => wp_create_nonce('rsl_front'), 'do' => 'register', 'level' => 'social', 'years' => 'lt1', 'join_code' => $code));
$check('registering joins the team', RSL_Teams::is_member($team->id, $new));
$check('the invite is forgotten once they\'ve joined', get_user_meta($new, RSL_Portal::JOIN_META, true) === '' && RSL_Portal::remembered_code($new) === '');

// --- An existing account logs in with an invite waiting -----------------------------------
$old = $mk('old');
RSL_Players::register($old, array('level' => 'vvl', 'years' => 'gt3'));
$_COOKIE[RSL_Portal::JOIN_COOKIE] = $code;
RSL_Portal::flag_after_login('', get_userdata($old));
$check('logging in with an invite waiting flags a trip to the league page', (bool) get_user_meta($old, RSL_Portal::SEND_META, true));
wp_set_current_user($old);
$html = $portal->render();
$check('registered player sees "Join" for the remembered team', strpos($html, "Join Invite Team $suffix") !== false);
RSL_Portal::process(array('rsl_nonce' => wp_create_nonce('rsl_front'), 'do' => 'join', 'join_code' => $code));
$check('joining by the remembered code works and clears it', RSL_Teams::is_member($team->id, $old) && !isset($_COOKIE[RSL_Portal::JOIN_COOKIE]));
RSL_Portal::flag_after_login('', get_userdata($old));
$check('no redirect for someone already on a team', !get_user_meta($old, RSL_Portal::SEND_META, true));

// --- The captain made a new link since: the old one is forgotten, not nagged ----------------
$late = $mk('late');
update_user_meta($late, RSL_Portal::JOIN_META, $code);
update_user_meta($late, RSL_Portal::SEND_META, 1);
RSL_Teams::regenerate_code($team->id);
$check('a stale code is dropped', RSL_Portal::remembered_code($late) === '' && get_user_meta($late, RSL_Portal::JOIN_META, true) === '');
$check('and the person isn\'t redirected for it', RSL_Portal::portal_redirect_for_request($late, false, false) === '');

// --- Cookie only for real codes --------------------------------------------------------------
$_COOKIE[RSL_Portal::JOIN_COOKIE] = 'NOTACODE';
$bogus = $mk('bogus');
$check('a made-up code in the browser isn\'t carried to the account', get_user_meta($bogus, RSL_Portal::JOIN_META, true) === '');

// --- Cleanup ----------------------------------------------------------------------------------
wp_set_current_user(0);
$_GET = array();
unset($_COOKIE[RSL_Portal::JOIN_COOKIE]);
remove_filter('pre_wp_mail', '__return_true');
$wpdb->delete(RSL_Database::table('team_members'), array('team_id' => $team->id));
$wpdb->delete(RSL_Database::table('teams'), array('id' => $team->id));
foreach ($users as $u) {
    $wpdb->delete(RSL_Database::table('players'), array('user_id' => $u));
    delete_transient(RSL_Portal::FLASH_PREFIX . $u);
    wp_delete_user($u);
}
if ($saved_current === '__unset__') { delete_option(RSL_Seasons::CURRENT_OPTION); } else { update_option(RSL_Seasons::CURRENT_OPTION, $saved_current); }
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
