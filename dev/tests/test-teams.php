<?php
/**
 * Teams and rosters: data rules, admin actions (nonce + capability), and the
 * public pages' privacy (teams public, rosters only for members and admins).
 *
 *   wp eval-file dev/tests/test-teams.php
 *
 * Uses a far-future season and throwaway @example.test users; cleans up.
 */

if (!defined('WP_CLI') || !WP_CLI || (wp_get_environment_type() === 'production' && !getenv('RSL_DEV'))) {
    exit("Local copy only: run with wp eval-file, with RSL_DEV=1 set (docker exec -e RSL_DEV=1 ...).\n");
}
require_once ABSPATH . 'wp-admin/includes/user.php';
global $wpdb;

$season = '2090-pre';
$suffix = time();
$fails = 0;
$check = function ($label, $ok, $detail = '') use (&$fails) {
    if (!$ok) { $fails++; }
    echo ($ok ? "OK: $label\n" : "FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n");
};
$saved_current = get_option(RSL_Seasons::CURRENT_OPTION, '__unset__');
$users = array();
$mk = function ($tag, $role = 'subscriber') use ($suffix, &$users) {
    $u = wp_insert_user(array('user_login' => "rslt-$tag-$suffix", 'user_email' => "rslt-$tag-$suffix@example.test", 'user_pass' => wp_generate_password(),
        'display_name' => "RSL $tag $suffix", 'role' => $role));
    $users[] = $u;
    return $u;
};

// --- Seasons ------------------------------------------------------------------------
$check('season keys validate', RSL_Seasons::is_valid('2027-pre') && RSL_Seasons::is_valid('2027-mid2') && !RSL_Seasons::is_valid('2027-xmas') && !RSL_Seasons::is_valid('27-pre'));
$check('season labels', RSL_Seasons::label('2027-post') === '2027 Post-season (Spring)');
$check('four seasons a year offered, this year and next', count(RSL_Seasons::choices()) === 8);
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

// --- Cleanup -----------------------------------------------------------------------------
wp_set_current_user(0);
set_query_var(RSL_Public::QUERY_VAR, '');
foreach (RSL_Teams::list_for_season($season) as $t) { $wpdb->delete(RSL_Database::table('team_members'), array('team_id' => $t->id)); }
$wpdb->delete(RSL_Database::table('teams'), array('season' => $season));
foreach ($users as $u) { $wpdb->delete(RSL_Database::table('team_members'), array('user_id' => $u)); wp_delete_user($u); }
// Removing a player queues them a "no longer on" email; it goes with the test users.
$wpdb->query($wpdb->prepare("DELETE FROM " . RSL_Database::table('outbox') . " WHERE to_email LIKE %s", '%-' . $suffix . '@example.test'));
delete_transient(RSL_Admin::NOTICE_PREFIX . $admin);
if ($saved_current === '__unset__') { delete_option(RSL_Seasons::CURRENT_OPTION); } else { update_option(RSL_Seasons::CURRENT_OPTION, $saved_current); }
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
