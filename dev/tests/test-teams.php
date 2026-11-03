<?php
/**
 * Teams and rosters: data rules, admin actions (nonce + capability), the
 * captain application card's season choice and later-season invite links
 * (0.17.0), a team that couldn't be saved, and the public pages' privacy
 * (teams public, rosters only for members and admins).
 *
 *   wp eval-file dev/tests/test-teams.php
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

$season = '2090-pre';
$suffix = time();
$hold = rsl_dev_hold_mail($suffix);
$fails = 0;
$check = function ($label, $ok, $detail = '') use (&$fails, $hold) {
    rsl_dev_park_mail($hold);
    if (!$ok) { $fails++; }
    echo ($ok ? "OK: $label\n" : "FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n");
};
$saved_current = get_option(RSL_Seasons::CURRENT_OPTION, '__unset__');
// Set before the try, so the cleanup can always run (the application card section, 0.17.0).
$saved_signup = get_option(RSL_Seasons::SIGNUP_OPTION, '__unset__');
$saved_courts = get_option(RSL_Courts::SETTINGS_OPTION, '__unset__');
$saved_portal_sec = get_option(RSL_Portal::PAGE_OPTION, '__unset__');
$made_portal = 0;
$cands_sec = null;
$block_teams = null;
$quiet = null;
$users = array();
$mk = function ($tag, $role = 'subscriber') use ($suffix, &$users) {
    $u = wp_insert_user(array('user_login' => "rslt-$tag-$suffix", 'user_email' => "rslt-$tag-$suffix@example.test", 'user_pass' => wp_generate_password(),
        'display_name' => "RSL $tag $suffix", 'role' => $role));
    $users[] = $u;
    return $u;
};

try {

// --- Seasons ------------------------------------------------------------------------
$check('season keys validate', RSL_Seasons::is_valid('2027-pre') && RSL_Seasons::is_valid('2027-mid2') && !RSL_Seasons::is_valid('2027-xmas') && !RSL_Seasons::is_valid('27-pre'));
$check('season labels', RSL_Seasons::label('2027-post') === '2027 Post-season (Spring)');
$check('four seasons a year offered, this year and the next two', count(RSL_Seasons::choices()) === 12);
$check('season for a date', RSL_Seasons::for_date('2027-02-10') === '2027-pre' && RSL_Seasons::for_date('2027-05-01') === '2027-mid1'
    && RSL_Seasons::for_date('2027-09-30') === '2027-mid2' && RSL_Seasons::for_date('2027-10-01') === '2027-post');
delete_option(RSL_Seasons::CURRENT_OPTION);
$check('until one is set, the current season is today\'s', RSL_Seasons::current() === RSL_Seasons::for_date(current_time('Y-m-d')));

// --- Teams ----------------------------------------------------------------------------
$t1 = RSL_Teams::create($season, array('name' => "Block Party $suffix", 'division' => 'ranked', 'colour' => '#8c2361'));
$check('create a team', !is_wp_error($t1) && $t1->division === 'ranked' && $t1->colour === '#8c2361');
$check('slug is URL-safe', !is_wp_error($t1) && $t1->slug === sanitize_title("Block Party $suffix"));
$dup = RSL_Teams::create($season, array('name' => "Block Party $suffix"));
$check('duplicate name in a season refused', is_wp_error($dup) && $dup->get_error_code() === 'rsl_duplicate');
$check('blank name refused', is_wp_error(RSL_Teams::create($season, array('name' => '  '))));
$check('bad season refused', is_wp_error(RSL_Teams::create('2027-xmas', array('name' => 'X'))));
$t2 = RSL_Teams::create($season, array('name' => "Spike Lee $suffix", 'division' => 'unranked', 'colour' => 'not-a-colour'));
$check('bad colour falls back to navy', !is_wp_error($t2) && $t2->colour === '#1d3d6e');
$t1b = RSL_Teams::update($t1->id, array('name' => "Block Party Renamed $suffix"));
$check('rename keeps the slug (public link stays)', !is_wp_error($t1b) && $t1b->slug === $t1->slug && $t1b->name === "Block Party Renamed $suffix");
$check('initials', RSL_Teams::initials('Block Party') === 'BP' && RSL_Teams::initials('notorious d.i.g.') === 'ND' && RSL_Teams::initials('Six') === 'S');

// --- Rosters --------------------------------------------------------------------------
$a = $mk('a'); $b = $mk('b'); $c = $mk('c');
$check('add a captain', RSL_Teams::add_member($t1->id, $a, 'captain') === true && RSL_Teams::captain($t1->id)->user_id == $a);
$check('add a player', RSL_Teams::add_member($t1->id, $b) === true);
$again = RSL_Teams::add_member($t1->id, $b);
$check('adding twice refused', is_wp_error($again) && $again->get_error_code() === 'rsl_already');
$other = RSL_Teams::add_member($t2->id, $b);
$check('one team per person per season', is_wp_error($other) && $other->get_error_code() === 'rsl_elsewhere');
$check('set captain moves the armband', RSL_Teams::set_captain($t1->id, $b) === true && RSL_Teams::captain($t1->id)->user_id == $b && count(array_filter(RSL_Teams::members($t1->id), function ($m) { return $m->role === 'captain'; })) === 1);
$check('captain must be on the team', is_wp_error(RSL_Teams::set_captain($t1->id, $c)));
$check('remove a player', RSL_Teams::remove_member($t1->id, $a) === true && !RSL_Teams::is_member($t1->id, $a));
$check('removed row kept for history', (bool) $wpdb->get_var($wpdb->prepare("SELECT left_at FROM " . RSL_Database::table('team_members') . " WHERE team_id = %d AND user_id = %d", $t1->id, $a)));
$check('removed player can join another team', RSL_Teams::add_member($t2->id, $a) === true);
$check('member counts', (int) array_values(array_filter(RSL_Teams::list_for_season($season), function ($t) use ($t1) { return $t->id == $t1->id; }))[0]->member_count === 1);
$check('ranked teams listed before unranked', RSL_Teams::list_for_season($season)[0]->division === 'ranked');

// --- Admin actions --------------------------------------------------------------------
$admin = $mk('admin', 'administrator');
wp_set_current_user($admin);
$post = function ($fields) use ($season) {
    return RSL_Admin::process(array_merge(array('rsl_nonce' => wp_create_nonce('rsl_team_action'), 'season' => $season), $fields));
};
$notice = function () {
    $n = get_transient(RSL_Admin::NOTICE_PREFIX . get_current_user_id());
    return is_array($n) ? $n : array('type' => '', 'message' => '');
};
$url = $post(array('do' => 'create_team', 'name' => wp_slash("O'Brien's $suffix"), 'division' => 'unranked', 'colour' => '#2e8540'));
$t3 = RSL_Teams::get_by_slug($season, sanitize_title("O'Brien's $suffix"));
$check('admin: create team (apostrophes kept, no slashes)', $t3 && $t3->name === "O'Brien's $suffix" && strpos($url, 'team=' . $t3->id) !== false, $t3 ? $t3->name : 'none');
$post(array('do' => 'add_member', 'team_id' => $t3->id, 'who' => "rslt-c-$suffix@example.test", 'role' => 'captain'));
$check('admin: add player by email', RSL_Teams::is_member($t3->id, $c) && $notice()['type'] === 'success');
$post(array('do' => 'add_member', 'team_id' => $t3->id, 'who' => 'nobody-here@example.test'));
$check('admin: unknown account explained', $notice()['type'] === 'error' && strpos($notice()['message'], 'No account found') !== false);
$post(array('do' => 'remove_member', 'team_id' => $t3->id, 'user_id' => $c));
$check('admin: remove player', !RSL_Teams::is_member($t3->id, $c));
RSL_Admin::process(array('rsl_nonce' => 'bogus', 'season' => $season, 'do' => 'create_team', 'name' => "Nope $suffix"));
$check('admin: bad nonce refused', !RSL_Teams::get_by_slug($season, sanitize_title("Nope $suffix")) && $notice()['type'] === 'error');
wp_set_current_user($c);
RSL_Admin::process(array('rsl_nonce' => wp_create_nonce('rsl_team_action'), 'season' => $season, 'do' => 'create_team', 'name' => "Sneaky $suffix"));
$check('admin: non-admins refused', !RSL_Teams::get_by_slug($season, sanitize_title("Sneaky $suffix")));
wp_set_current_user($admin);
$post(array('do' => 'set_season'));
$check('admin: set the current season', RSL_Seasons::current() === $season);

// --- The captain application card picks the season (0.17.0) ------------------------------------
// The current season is $season (2090-pre, undated); signing up 2090-mid1, with 2090-mid2 open
// too (both dated from today). Far-future seasons only, through the candidates filter.
$today = current_time('Y-m-d');
$day = function ($n) use ($today) { return date('Y-m-d', strtotime($today . ' ' . ($n < 0 ? '' : '+') . intval($n) . ' days')); };
RSL_Courts::save_settings('2090-mid1', array('start' => $day(5), 'end' => $day(60)));
RSL_Courts::save_settings('2090-mid2', array('start' => $day(70), 'end' => $day(130)));
RSL_Seasons::set_signup('2090-mid1');
$cands_sec = function ($keys) { return array('2090-mid1', '2090-mid2', '2090-pre'); };
add_filter('rsl_open_for_signup_candidates', $cands_sec);
$had = intval(get_option(RSL_Portal::PAGE_OPTION));
$portal_id = intval(RSL_Portal::ensure_page()); // the existing page when there is one
$made_portal = $portal_id !== $had ? $portal_id : 0;
$cp = $mk('cp'); $cq = $mk('cq');
foreach (array($cp, $cq) as $u) { rsl_dev_profile($u); RSL_Players::register($u, array('level' => 'social', 'years' => '1to3')); }
$portal = new RSL_Portal();
$view = function ($as) use ($portal) { wp_set_current_user($as); $_GET = array(); return $portal->render(); };
$front = function ($as, $fields) {
    wp_set_current_user($as);
    delete_transient(RSL_Portal::FLASH_PREFIX . $as);
    $to = RSL_Portal::process(array_merge(array('rsl_nonce' => wp_create_nonce('rsl_front')), $fields));
    $f = get_transient(RSL_Portal::FLASH_PREFIX . $as);
    return array((string) $to, is_array($f) ? (string) $f['message'] : '');
};
$team_select = function ($html) {
    $a = strpos($html, '<select name="team_season">');
    if ($a === false) { return ''; }
    $b = strpos($html, '</select>', $a);
    return (string) substr($html, $a, $b === false ? strlen($html) - $a : $b - $a);
};
$v = $view($cp);
$sel = $team_select($v);
$check('the application card asks which season: the sign-up season chosen, the later open one offered, an earlier one not', strpos($v, 'id="rsl-apply"') !== false
    && $sel !== '' && strpos($sel, 'value="2090-mid1" selected=\'selected\'') !== false && strpos($sel, 'value="2090-mid2"') !== false && strpos($sel, '2090-pre') === false, $sel);
list($to, $msg) = $front($cp, array('do' => 'apply', 'team_name' => "Card Team $suffix", 'team_level' => 'social', 'team_season' => '2090-mid2'));
$card_team = RSL_Teams::pending_application_for_user($cp, '2090-mid2');
$check('applying for the later season: pending there, and the flash names the season', $card_team && $card_team->name === "Card Team $suffix"
    && $msg === "Application sent for Card Team $suffix (2090 Mid-season 2). We'll email you when it's approved.", $msg);
$v = $view($cp);
$check('...the page shows it under Later season, and the card offers only the season left (named, not a choice)', strpos($v, 'Later season') !== false
    && strpos($v, "Your application for Card Team $suffix is waiting for approval") !== false
    && strpos($v, 'name="team_season" value="2090-mid1"') !== false && strpos($v, '<select name="team_season">') === false);
list($to, $msg) = $front($cp, array('do' => 'apply', 'team_name' => "Too Early $suffix", 'team_level' => 'social', 'team_season' => '2090-pre'));
$check('a season that isn\'t open for sign-up is refused, back to the card', $msg === '2090 Pre-season isn\'t open for sign-up — pick one of the seasons listed.'
    && substr($to, -strlen('#rsl-apply')) === '#rsl-apply' && !RSL_Teams::pending_application_for_user($cp, '2090-pre'), $msg . ' / ' . $to);
$v = $view($cp);
$check('...and the card opens with what was typed', strpos($v, 'id="rsl-apply" open') !== false && strpos($v, 'value="Too Early ' . $suffix . '"') !== false);
wp_set_current_user($admin);
$approved = $card_team ? RSL_Teams::approve($card_team->id) : null;
$code_team = ($approved && !is_wp_error($approved)) ? RSL_Teams::get_by_code($approved->invite_code) : null;
$joined = $code_team ? RSL_Teams::join_by_code($cq, $approved->invite_code) : null;
$check('the approved later-season team\'s invite link works at once', $code_team && intval($code_team->id) === intval($card_team->id)
    && $joined && !is_wp_error($joined) && RSL_Teams::is_member($card_team->id, $cq) && in_array('2090-mid2', RSL_Teams::joinable_seasons(), true));
$old_team = RSL_Teams::create('2089-post', array('name' => "Old Post $suffix", 'status' => 'active'));
$check('a team in a season the league page doesn\'t show isn\'t joinable by its code', $old_team && !is_wp_error($old_team)
    && RSL_Teams::get_by_code($old_team->invite_code) === null && !in_array('2089-post', RSL_Teams::joinable_seasons(), true));
// A team that couldn't be saved (its INSERT sent to a table that doesn't exist): an error, and nobody becomes a captain.
$members_before = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . RSL_Database::table('team_members') . " WHERE user_id = %d", $cp));
$block_teams = function ($q) {
    $t = '`' . RSL_Database::table('teams') . '`';
    return stripos(ltrim((string) $q), 'INSERT INTO ' . $t) === 0 ? str_replace($t, '`' . RSL_Database::table('teams') . '_unsaved`', $q) : $q;
};
$quiet = $wpdb->suppress_errors(true);
add_filter('query', $block_teams);
$unsaved = RSL_Teams::apply('2090-mid1', $cp, array('name' => "Unsaved $suffix", 'level' => 'social'));
remove_filter('query', $block_teams);
$block_teams = null;
$wpdb->suppress_errors($quiet);
$quiet = null;
$check('a team that couldn\'t be saved is an error (rsl_save), and no membership is added', is_wp_error($unsaved) && $unsaved->get_error_code() === 'rsl_save'
    && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . RSL_Database::table('team_members') . " WHERE user_id = %d", $cp)) === $members_before
    && !$wpdb->get_var($wpdb->prepare("SELECT id FROM " . RSL_Database::table('teams') . " WHERE name = %s", "Unsaved $suffix")), is_wp_error($unsaved) ? $unsaved->get_error_code() : wp_json_encode($unsaved));
wp_set_current_user($admin);
$_GET = array();

// --- Public pages ------------------------------------------------------------------------
$public = new RSL_Public();
RSL_Public::ensure_pages();
$render = function ($as, $slug = '') use ($public) {
    wp_set_current_user($as);
    set_query_var(RSL_Public::QUERY_VAR, $slug);
    return $public->render_teams();
};
$list = $render(0);
$check('list: teams by division with badges', strpos($list, "Block Party Renamed $suffix") !== false && strpos($list, 'Ranked') !== false && strpos($list, 'class="rsl-badge"') !== false);
$check('list: no player names in public', strpos($list, "RSL b $suffix") === false);
$team_page = $render(0, $t1->slug);
$check('team page: name and size for anyone', strpos($team_page, "Block Party Renamed $suffix") !== false && strpos($team_page, '1 player') !== false);
$check('team page: roster hidden when logged out', strpos($team_page, 'Roster') === false && strpos($team_page, "RSL b $suffix") === false);
$check('team page: roster hidden from other logged-in people', strpos($render($c, $t1->slug), "RSL b $suffix") === false);
$member_view = $render($b, $t1->slug);
$check('team page: members see the roster and captain', strpos($member_view, "RSL b $suffix") !== false && strpos($member_view, 'Captain') !== false);
$check('team page: admins see the roster', strpos($render($admin, $t1->slug), "RSL b $suffix") !== false);
$check('unknown team handled', strpos($render(0, 'no-such-team-' . $suffix), "isn't playing this season") !== false);
$check('team link uses the team slug', strpos(RSL_Public::team_url($t1), $t1->slug) !== false);
// A trashed Social League page is made again, so emails never link to the
// trash (a throwaway page stands in for the real one, which isn't touched).
$saved_portal = get_option(RSL_Portal::PAGE_OPTION, '__unset__');
$stand_in = wp_insert_post(array('post_title' => "RSL portal $suffix", 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '[rsl_my_league]'));
update_option(RSL_Portal::PAGE_OPTION, $stand_in, false);
$check('the Social League page that exists is kept', intval(RSL_Portal::ensure_page()) === intval($stand_in));
wp_trash_post($stand_in);
$remade = RSL_Portal::ensure_page();
$check('a trashed Social League page is made again', $remade && !is_wp_error($remade) && intval($remade) !== intval($stand_in) && get_post_status($remade) === 'publish');
wp_delete_post($stand_in, true);
if ($remade && !is_wp_error($remade) && intval($remade) !== intval($stand_in)) { wp_delete_post($remade, true); }
if ($saved_portal === '__unset__') { delete_option(RSL_Portal::PAGE_OPTION); } else { update_option(RSL_Portal::PAGE_OPTION, $saved_portal, false); }

} catch (Throwable $t) {
    $fails++;
    echo 'FAIL: threw ' . get_class($t) . ': ' . $t->getMessage() . ' (line ' . $t->getLine() . ")\n";
} finally {
// --- Cleanup (runs after a failure too) ----------------------------------------------------
wp_set_current_user(0);
set_query_var(RSL_Public::QUERY_VAR, '');
$_GET = array();
if ($block_teams) {
    remove_filter('query', $block_teams);
}
if ($quiet !== null) {
    $wpdb->suppress_errors($quiet);
}
if ($cands_sec) {
    remove_filter('rsl_open_for_signup_candidates', $cands_sec);
}
foreach (array($season, '2090-mid1', '2090-mid2', '2089-post') as $s_key) {
    foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM " . RSL_Database::table('teams') . " WHERE season = %s", $s_key)) as $tid) {
        $wpdb->delete(RSL_Database::table('team_members'), array('team_id' => $tid));
        $wpdb->delete(RSL_Database::table('invites'), array('team_id' => $tid));
    }
    $wpdb->delete(RSL_Database::table('teams'), array('season' => $s_key));
}
foreach ($users as $u) {
    if (is_int($u) && $u > 0) {
        $wpdb->delete(RSL_Database::table('team_members'), array('user_id' => $u));
        $wpdb->delete(RSL_Database::table('players'), array('user_id' => $u));
        $wpdb->delete(RSL_Database::table('free_agents'), array('user_id' => $u));
    }
}
if ($made_portal) {
    wp_delete_post($made_portal, true);
    if ($saved_portal_sec === '__unset__') { delete_option(RSL_Portal::PAGE_OPTION); } else { update_option(RSL_Portal::PAGE_OPTION, $saved_portal_sec, false); }
}
foreach (array(RSL_Seasons::SIGNUP_OPTION => $saved_signup, RSL_Courts::SETTINGS_OPTION => $saved_courts) as $opt => $was) {
    if ($was === '__unset__') { delete_option($opt); } else { update_option($opt, $was); }
}
// Removing a player queues them a "no longer on" email; it goes with the test users.
$wpdb->query($wpdb->prepare("DELETE FROM " . RSL_Database::table('outbox') . " WHERE to_email LIKE %s", '%-' . $suffix . '@example.test'));
if (isset($admin) && is_int($admin)) { delete_transient(RSL_Admin::NOTICE_PREFIX . $admin); }
if ($saved_current === '__unset__') { delete_option(RSL_Seasons::CURRENT_OPTION); } else { update_option(RSL_Seasons::CURRENT_OPTION, $saved_current); }
rsl_dev_delete_users($users);
rsl_dev_release_mail($hold);
}
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
