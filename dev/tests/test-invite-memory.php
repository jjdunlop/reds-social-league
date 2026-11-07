<?php
/**
 * Remembering a captain's invite through sign-up: the code survives the
 * new-account detour (cookie → account), the league page offers it without
 * the link, the person is brought back to the league page (never away from
 * account/profile pages), and it's forgotten once they join or if the
 * captain's link has changed. Since 0.23.3: the browser's code moves onto
 * the account that logs in or is made next and the browser forgets it (a
 * second account on that browser is offered nothing), a link opened while
 * logged in is kept on that account only, someone already on a team in its
 * season keeps nothing, the register form keeps the other ways in, and
 * choosing one or saying No thanks forgets the invite.
 *
 *   wp eval-file dev/tests/test-invite-memory.php
 */

if (!defined('WP_CLI') || !WP_CLI || (wp_get_environment_type() === 'production' && !getenv('RSL_DEV'))) {
    exit("Local copy only: run with wp eval-file, with RSL_DEV=1 set (docker exec -e RSL_DEV=1 ...).\n");
}
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once dirname(__DIR__) . '/lib-profile.php';
require_once dirname(__DIR__) . '/lib-mail-stop.php';
global $wpdb;

$season = '2093-pre';
$suffix = time();
$hold = rsl_dev_hold_mail($suffix);
$fails = 0;
$check = function ($label, $ok, $detail = '') use (&$fails, $hold) {
    rsl_dev_park_mail($hold);
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
    rsl_dev_profile($u);
    return $u;
};

try {

$team = RSL_Teams::create($season, array('name' => "Invite Team $suffix"));
$code = $team->invite_code;
unset($_COOKIE[RSL_Portal::JOIN_COOKIE]);

// --- A brand-new player: link opened while logged out, then creates an account ----
$_COOKIE[RSL_Portal::JOIN_COOKIE] = strtolower($code); // what opening the link leaves in the browser
$new = $mk('new');
$check('new account picks up the invite from the browser', get_user_meta($new, RSL_Portal::JOIN_META, true) === $code);
$check('...and the browser forgets it (0.23.3), so the next account here isn\'t offered it', !isset($_COOKIE[RSL_Portal::JOIN_COOKIE]));
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
$check('league page offers the invite without the link: Join the team, chosen, with the code in the form', strpos($html, "Join Invite Team $suffix") !== false && strpos($html, 'value="' . $code . '"') !== false
    && preg_match('/name="route" value="join" id="[^"]+" checked/', $html) === 1);
$check('...and the other ways in stay for someone who\'d rather not (0.23.3): start a team, find me a team, not yet; the button just says Register',
    strpos($html, 'name="route" value="start"') !== false && strpos($html, 'name="route" value="find"') !== false && strpos($html, 'name="route" value="later"') !== false
    && strpos($html, 'Register and join') === false && strpos($html, 'or choose another way to join') !== false && strpos($html, 'name="join_code" maxlength') === false, $html);
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
$check('...the browser\'s invite moved onto the account and the browser forgot it', get_user_meta($old, RSL_Portal::JOIN_META, true) === $code && !isset($_COOKIE[RSL_Portal::JOIN_COOKIE]));
// A second account logging in on that browser afterwards: offered nothing.
$second = $mk('second');
RSL_Players::register($second, array('level' => 'social', 'years' => 'lt1'));
RSL_Portal::flag_after_login('', get_userdata($second));
wp_set_current_user($second);
$html2 = $portal->render();
$check('a second account logging in on the same browser is offered nothing (0.23.3)', RSL_Portal::remembered_code($second) === '' && get_user_meta($second, RSL_Portal::JOIN_META, true) === ''
    && !get_user_meta($second, RSL_Portal::SEND_META, true) && strpos($html2, "Invite Team $suffix") === false);
// A browser still holding a code while someone is logged in (left by the old rule): never theirs.
$_COOKIE[RSL_Portal::JOIN_COOKIE] = $code;
$check('a logged-in account never takes the browser\'s code just by viewing (only at login or sign-up)', RSL_Portal::remembered_code($second) === '' && strpos($portal->render(), "Invite Team $suffix") === false);
unset($_COOKIE[RSL_Portal::JOIN_COOKIE]);
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
unset($_COOKIE[RSL_Portal::JOIN_COOKIE]);

// --- 0.23.3: opening the link while logged in keeps it on the account only -------------------------
$code2 = RSL_Teams::get($team->id)->invite_code; // the captain made a new link above
$keep_query = isset($GLOBALS['wp_query']) ? $GLOBALS['wp_query'] : null;
$on_page = function ($as, $join) {
    wp_set_current_user($as);
    $_GET = array('join' => $join);
    $GLOBALS['wp_query'] = new WP_Query(array('page_id' => intval(get_option(RSL_Portal::PAGE_OPTION))));
    $GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
};
$opener = $mk('opener');
$on_page($opener, $code2);
$portal->remember_invite_from_link();
$check('a link opened while logged in is kept on that account, never in the browser', get_user_meta($opener, RSL_Portal::JOIN_META, true) === $code2 && !isset($_COOKIE[RSL_Portal::JOIN_COOKIE]));
$on_page($old, $code2); // $old is on the team
$portal->remember_invite_from_link();
$check('...nothing kept for someone already on a team in its season (the captain opening their own link)', get_user_meta($old, RSL_Portal::JOIN_META, true) === '');
$on_page(0, $code2);
$portal->remember_invite_from_link();
$check('...and logged out it goes in the browser, for the account made or logged into next', isset($_COOKIE[RSL_Portal::JOIN_COOKIE]) && $_COOKIE[RSL_Portal::JOIN_COOKIE] === $code2);
unset($_COOKIE[RSL_Portal::JOIN_COOKIE]);
$GLOBALS['wp_query'] = $keep_query;
$GLOBALS['wp_the_query'] = $keep_query;
$_GET = array();
update_user_meta($old, RSL_Portal::JOIN_META, $code2);
$check('an account already on a team in the invite\'s season has the old code forgotten', RSL_Portal::remembered_code($old) === '' && get_user_meta($old, RSL_Portal::JOIN_META, true) === '');

// --- 0.23.3: invited, but they'd rather not ----------------------------------------------------
wp_set_current_user($opener);
RSL_Portal::process(array('rsl_nonce' => wp_create_nonce('rsl_front'), 'do' => 'register', 'level' => 'social', 'years' => 'lt1', 'join_code' => $code2, 'route' => 'later'));
$check('registering by Not yet with an invite in the form: registered, not on the team, and the invite forgotten',
    RSL_Players::is_registered($opener) && !RSL_Teams::is_member($team->id, $opener) && RSL_Portal::remembered_code($opener) === '');
$check('...the league page then offers no Join card', strpos($portal->render(), "Join Invite Team $suffix") === false);
$nope = $mk('nope');
RSL_Players::register($nope, array('level' => 'social', 'years' => 'lt1'));
update_user_meta($nope, RSL_Portal::JOIN_META, $code2);
wp_set_current_user($nope);
$html = $portal->render();
$check('a registered player with an invite waiting sees Join and No thanks', strpos($html, "Join Invite Team $suffix") !== false && strpos($html, 'name="do" value="no_invite"') !== false && strpos($html, '>No thanks</button>') !== false);
RSL_Portal::process(array('rsl_nonce' => wp_create_nonce('rsl_front'), 'do' => 'no_invite', 'join_code' => $code2));
$after = $portal->render();
$check('...No thanks forgets it and says the link still works', RSL_Portal::remembered_code($nope) === '' && !RSL_Teams::is_member($team->id, $nope)
    && strpos($after, "Join Invite Team $suffix") === false && strpos($after, 'If you change your mind, open the captain') !== false, $after);
$_GET = array('join' => $code2);
$check('...opening the captain\'s link again offers it again', strpos($portal->render(), "Join Invite Team $suffix") !== false);
$_GET = array();

} catch (Throwable $thrown) {
    $fails++;
    echo 'FAIL: threw ' . get_class($thrown) . ': ' . $thrown->getMessage() . ' (line ' . $thrown->getLine() . ")\n";
} finally {
// --- Cleanup (runs after a failure too) -------------------------------------------------------
wp_set_current_user(0);
$_GET = array();
unset($_COOKIE[RSL_Portal::JOIN_COOKIE]);
if (isset($keep_query)) {
    $GLOBALS['wp_query'] = $keep_query;
    $GLOBALS['wp_the_query'] = $keep_query;
}
remove_filter('pre_wp_mail', '__return_true');
// Every team in the test's season (however far the test got).
foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM " . RSL_Database::table('teams') . " WHERE season = %s", $season)) as $tid) {
    $wpdb->delete(RSL_Database::table('team_members'), array('team_id' => $tid));
    $wpdb->delete(RSL_Database::table('invites'), array('team_id' => $tid));
    $wpdb->delete(RSL_Database::table('teams'), array('id' => $tid));
}
foreach ($users as $u) {
    if (is_int($u) && $u > 0) {
        $wpdb->delete(RSL_Database::table('players'), array('user_id' => $u));
    }
}
if ($saved_current === '__unset__') { delete_option(RSL_Seasons::CURRENT_OPTION); } else { update_option(RSL_Seasons::CURRENT_OPTION, $saved_current); }
rsl_dev_delete_users($users); // the flash too
rsl_dev_release_mail($hold);
}
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
