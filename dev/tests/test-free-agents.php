<?php
/**
 * Free agents: the sign-up season, the team-building window, the list,
 * friend groups, what captains can see, pool invites, requests, joining by
 * any route closing the rest, the roster maximum, "not for me", leaving,
 * pool invites never speaking for a friend who hasn't agreed, applying to
 * captain and back, the back-fill, league notices.
 *
 *   wp eval-file dev/tests/test-free-agents.php
 *
 * Uses far-future seasons and throwaway @example.test users; cleans up.
 */

if (!defined('WP_CLI') || !WP_CLI || (wp_get_environment_type() === 'production' && !getenv('RSL_DEV'))) {
    exit("Local copy only: run with wp eval-file, with RSL_DEV=1 set (docker exec -e RSL_DEV=1 ...).\n");
}
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once dirname(__DIR__) . '/lib-profile.php';
require_once dirname(__DIR__) . '/lib-mail-stop.php';
global $wpdb;

$season = '2088-pre';
$suffix = time();
$hold = rsl_dev_hold_mail($suffix);
$fails = 0;
$check = function ($label, $ok, $detail = '') use (&$fails, $hold) {
    rsl_dev_park_mail($hold);
    if (!$ok) { $fails++; }
    echo ($ok ? "OK: $label\n" : "FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n");
};
$saved_current = get_option(RSL_Seasons::CURRENT_OPTION, '__unset__');
$saved_signup = get_option(RSL_Seasons::SIGNUP_OPTION, '__unset__');
$saved_fa = get_option(RSL_Free_Agents::SETTINGS_OPTION, '__unset__');
delete_option(RSL_Free_Agents::SETTINGS_OPTION);
$users = array();
$mk = function ($tag, $level = 'social', $years = '1to3', $display = '') use ($suffix, &$users) {
    $u = wp_insert_user(array('user_login' => "rslfa-$tag-$suffix", 'user_email' => "rslfa-$tag-$suffix@example.test", 'user_pass' => wp_generate_password(),
        'display_name' => $display !== '' ? $display : "Fa$tag Tester", 'first_name' => $display === '' ? "Fa$tag" : '', 'role' => 'subscriber'));
    $users[] = $u;
    rsl_dev_profile($u);
    if ($level !== '') {
        RSL_Players::register($u, array('level' => $level, 'years' => $years, 'positions' => array('setter')));
    }
    return $u;
};
$set_start = function ($season, $start) {
    $all = get_option(RSL_Courts::SETTINGS_OPTION, array());
    $all = is_array($all) ? $all : array();
    if ($start === '') {
        unset($all[$season]);
    } else {
        $all[$season] = array('start' => $start, 'end' => date('Y-m-d', strtotime($start . ' +11 weeks')), 'weekday' => (int) date('w', strtotime($start)), 'minutes' => array('ranked' => 60, 'unranked' => 50));
    }
    update_option(RSL_Courts::SETTINGS_OPTION, $all, false);
};
$set_building = function ($season, $changes) {
    $b = RSL_Free_Agents::building($season);
    RSL_Free_Agents::save_building($season, array_merge($b, $changes));
};
$today = current_time('Y-m-d');
$days = function ($n) use ($today) { return date('Y-m-d', strtotime($today . ' ' . ($n >= 0 ? '+' : '') . $n . ' days')); };
$cands_fa = null; // the season choice section's candidates filter (0.17.0), removed in the cleanup

try {

// --- Seasons: next and the sign-up season ---------------------------------------------
$check('next season wraps the year', RSL_Seasons::next('2027-post') === '2028-pre' && RSL_Seasons::next('2027-pre') === '2027-mid1' && RSL_Seasons::next('2027-mid2') === '2027-post');
update_option(RSL_Seasons::CURRENT_OPTION, '2087-post');
delete_option(RSL_Seasons::SIGNUP_OPTION);
$set_start($season, $days(60));
$check('sign-up season stays current until next season\'s window opens', RSL_Seasons::signup() === '2087-post');
$set_start($season, $days(14));
$check('sign-up season moves to next season once its window is open', RSL_Seasons::signup() === $season);
RSL_Seasons::set_signup('2087-mid1');
$check('an admin can pin the sign-up season', RSL_Seasons::signup() === '2087-mid1');
RSL_Seasons::set_signup('');
$check('and unpin it', RSL_Seasons::signup() === $season);

// --- The window -------------------------------------------------------------------------
$d = RSL_Free_Agents::dates($season);
$check('dates count back from night 1 (21 / 10 / 7 days)', $d && $d['first_night'] === $days(14) && $d['opens'] === $days(-7) && $d['deadline'] === $days(4) && $d['confirm_by'] === $days(7));
$check('phase: open inside the window', RSL_Free_Agents::phase($season) === 'open');
$set_start($season, $days(40));
$check('phase: before the window opens', RSL_Free_Agents::phase($season) === 'before');
$set_start($season, '');
$check('phase: open (no deadline) when the season has no first night', RSL_Free_Agents::phase($season) === 'open' && RSL_Free_Agents::dates($season) === null);
$set_start($season, $days(14));
$set_building($season, array('deadline' => $days(-1)));
$check('phase: placing after the deadline', RSL_Free_Agents::phase($season) === 'placing');
$set_building($season, array('deadline' => '', 'confirmed_at' => current_time('mysql')));
$check('phase: season once placements are confirmed', RSL_Free_Agents::phase($season) === 'season');
$set_building($season, array('confirmed_at' => ''));
RSL_Courts::generate_nights($season);
$nights = RSL_Courts::nights($season);
$bad = RSL_Free_Agents::save_settings(array('min_roster' => 9, 'target_roster' => 8));
$check('settings: team sizes must run min ≤ target ≤ max', is_wp_error($bad));

// --- Joining the list ---------------------------------------------------------------------
$nobody = $mk('nobody', '');
$check('unregistered people can\'t join', is_wp_error(RSL_Free_Agents::join($nobody, $season, array())));
$beg = $mk('beg', 'never', 'lt1');
$row = RSL_Free_Agents::join($beg, $season, array('away_dates' => array($nights[1]->night_date, '2001-01-01'), 'browsable' => 1), true);
$check('a beginner joins; Unranked by default', !is_wp_error($row) && $row->status === 'looking' && $row->division_pref === 'unranked');
$check('weeks away keep only real night dates', !is_wp_error($row) && $row->away_dates === $nights[1]->night_date);
$check('players.looking follows the list', (int) RSL_Players::get($beg)->looking === 1);
$again = RSL_Free_Agents::join($beg, $season, array('division_pref' => 'unranked', 'time_pref' => 'early', 'browsable' => 1), true);
$check('joining again updates the one row', !is_wp_error($again) && intval($again->id) === intval($row->id) && $again->time_pref === 'early');
$vvl = $mk('vvl', 'vvl', 'gt3');
RSL_Free_Agents::join($vvl, $season, array('browsable' => 1), true);
$check('experienced players default to Either', RSL_Free_Agents::get($vvl, $season)->division_pref === 'either');
$check('bands: beginner 1 … VVL 4', RSL_Free_Agents::band(RSL_Players::get($beg)) == 1.0 && RSL_Free_Agents::band(RSL_Players::get($vvl)) == 4.0);
// Registered (so with a profile), then the first name taken off it again.
$noname = function ($tag, $display) use ($mk) { $u = $mk($tag, 'social', '1to3', $display); delete_user_meta($u, 'first_name'); return $u; };
$check('first names never show an email', RSL_Free_Agents::first_name($noname('emailname', 'someone@example.test')) === 'Player');
$check('first names never show a login name (WordPress\'s default display name)', RSL_Free_Agents::first_name($noname('loginname', "rslfa-loginname-$suffix")) === 'Player');
$twoname = $mk('twoname');
update_user_meta($twoname, 'first_name', 'Fatwo Surname');
$check('first names never show a surname typed into the first name field', RSL_Free_Agents::first_name($twoname) === 'Fatwo');

// --- Friend groups ---------------------------------------------------------------------------
$f1 = $mk('f1'); $f2 = $mk('f2'); $f3 = $mk('f3'); $f4 = $mk('f4'); $rk = $mk('rk', 'state', 'gt3');
foreach (array($f1, $f2, $f3, $f4) as $f) { RSL_Free_Agents::join($f, $season, array('division_pref' => 'either', 'browsable' => 1), true); }
RSL_Free_Agents::join($rk, $season, array('division_pref' => 'ranked', 'browsable' => 1), true);
$code = RSL_Free_Agents::group_create($f1, $season);
$check('start a friend group', is_string($code) && strlen($code) === 8);
$check('a friend joins with the code', RSL_Free_Agents::group_join($f2, $season, strtolower($code)) === true);
$check('a bad code is refused', is_wp_error(RSL_Free_Agents::group_join($f3, $season, 'NOPE1234')));
RSL_Free_Agents::group_join($f3, $season, $code);
$full = RSL_Free_Agents::group_join($f4, $season, $code);
$check('groups stop at 3', is_wp_error($full) && $full->get_error_code() === 'rsl_fa_full');
$bcode = RSL_Free_Agents::group_create($beg, $season);
$clash = RSL_Free_Agents::group_join($rk, $season, $bcode);
$check('a Ranked-only player can\'t join an Unranked-only group', is_wp_error($clash));
$narrow = RSL_Free_Agents::update($f2, $season, array('division_pref' => 'unranked'));
$check('a group member can narrow to a division the group shares', !is_wp_error($narrow) && $narrow->division_pref === 'unranked' && (int) $narrow->browsable === 1);
$check('but not to one that leaves the group nothing in common', is_wp_error(RSL_Free_Agents::update($f1, $season, array('division_pref' => 'ranked'))));
RSL_Free_Agents::update($f2, $season, array('division_pref' => 'either'));

// --- Teams and what captains see ---------------------------------------------------------------
$cap_r = $mk('capr'); $cap_u = $mk('capu');
$tr = RSL_Teams::create($season, array('name' => "FA Ranked $suffix", 'division' => 'ranked', 'status' => 'active'));
$tu = RSL_Teams::create($season, array('name' => "FA Unranked $suffix", 'division' => 'unranked', 'status' => 'active'));
RSL_Teams::add_member($tr->id, $cap_r, 'captain');
RSL_Teams::add_member($tu->id, $cap_u, 'captain');
RSL_Free_Agents::set_spots($tr->id, $cap_r, array('spots' => 2, 'positions' => array('setter'), 'level' => 'some', 'note' => str_repeat('x', 150)));
RSL_Free_Agents::set_spots($tu->id, $cap_u, array('spots' => 3, 'level' => 'beginners'));
$tr = RSL_Teams::get($tr->id);
$check('captain sets open spots (note capped at 100)', intval($tr->spots_open) === 2 && $tr->spots_level === 'some' && mb_strlen($tr->spots_note) === 100);
$check('only the captain sets spots', is_wp_error(RSL_Free_Agents::set_spots($tr->id, $cap_u, array('spots' => 4))));
$teen = $mk('teen'); $shy = $mk('shy');
update_user_meta($teen, 'birth_date', rsl_dev_dob(16)); // under 18 comes from the account (0.13.0)
RSL_Free_Agents::join($teen, $season, array('division_pref' => 'either', 'under18' => 1, 'browsable' => 1), true);
RSL_Free_Agents::join($shy, $season, array('division_pref' => 'either', 'browsable' => 0), true);
$pool_r = RSL_Free_Agents::browse_for_captain($tr->id, $cap_r);
$keys_ok = true;
foreach ($pool_r as $card) {
    $k = array_keys($card);
    sort($k);
    $keys_ok = $keys_ok && $k === array('fa_id', 'first_name', 'friends_n', 'level_label', 'positions');
}
$check('captains see only first name, level, positions and friends', $pool_r && $keys_ok);
$names_r = array_map(function ($c) { return $c['first_name']; }, $pool_r);
$check('under-18s and people who opted out of browsing are hidden', !in_array('Fateen', $names_r, true) && !in_array('Fashy', $names_r, true));

// --- Under 18, from the account's date of birth (0.13.0) -------------------------------------
$first_night = RSL_Free_Agents::dates($season)['first_night'];
$check('(the test season starts in the future)', $first_night > $today, $first_night);
$fa_teen = RSL_Free_Agents::get($teen, $season);
$check('a player can\'t set the under-18 mark themselves (it\'s admin-only now)', (int) $fa_teen->under18 === 0);
$check('the teen counts as under 18 from their date of birth', RSL_Players::is_under18($teen, $season) && !empty(RSL_Free_Agents::under18_for(array($fa_teen))[$teen]));
$nodob = $mk('nodob'); $adult = $mk('adult18'); $marked = $mk('marked');
delete_user_meta($nodob, 'birth_date');
update_user_meta($adult, 'birth_date', rsl_dev_dob(18, 1)); // turned 18 yesterday (the exact birthday is checked with age_on() below; 29 February would roll over)
foreach (array($nodob, $adult, $marked) as $u) {
    RSL_Free_Agents::join($u, $season, array('division_pref' => 'either', 'browsable' => 1), true);
}
RSL_Free_Agents::update($adult, $season, array('under18' => 1));
$check('updating your choices never sets the mark either', (int) RSL_Free_Agents::get($adult, $season)->under18 === 0);
RSL_Free_Agents::admin_update(RSL_Free_Agents::get($marked, $season)->id, array('under18' => 1, 'admin_note' => ''));
$names_r = array_map(function ($c) { return $c['first_name']; }, RSL_Free_Agents::browse_for_captain($tr->id, $cap_r));
$check('no date of birth counts as under 18, so they\'re hidden', !in_array('Fanodob', $names_r, true));
$check('an admin\'s "treat as under 18" hides an adult', !in_array('Famarked', $names_r, true));
$check('someone who has just turned 18 is shown', in_array('Faadult18', $names_r, true));
RSL_Free_Agents::update($marked, $season, array('time_pref' => 'early'));
$check('the player updating their choices keeps the admin\'s mark', (int) RSL_Free_Agents::get($marked, $season)->under18 === 1);
$soon = $mk('soon', '');
update_user_meta($soon, 'birth_date', rsl_dev_dob(18, -10)); // 18 in ten days, before the first night
$check('17 today counts while captains are browsing, even if 18 by the first night', RSL_Players::is_under18($soon, $season) && RSL_Players::is_under18($soon));
$past = '2088-mid1';
$set_start($past, $days(-20)); // that season's first night was 20 days ago
$turned = $mk('turned', '');
update_user_meta($turned, 'birth_date', rsl_dev_dob(18, 10)); // turned 18 ten days ago
$check('17 on the season\'s first night counts as under 18 all that season', RSL_Players::is_under18($turned, $past) && !RSL_Players::is_under18($turned, $season) && !RSL_Players::is_under18($turned));
$set_start($past, '');
$check('ages in whole years, birthdays on the day', RSL_Players::age_on('2008-03-01', '2026-02-28') === 17 && RSL_Players::age_on('2008-03-01', '2026-03-01') === 18 && RSL_Players::age_on('2008-02-29', '2026-02-28') === 17);
$rules_u18 = RSL_Free_Agents::settings();
RSL_Free_Agents::save_settings(array_merge($rules_u18, array('under18_in_pool' => 1)));
$names_open = array_map(function ($c) { return $c['first_name']; }, RSL_Free_Agents::browse_for_captain($tr->id, $cap_r));
RSL_Free_Agents::save_settings($rules_u18);
$check('with under-18s allowed in the list (admin setting), captains see them', in_array('Fateen', $names_open, true) && in_array('Fanodob', $names_open, true));
foreach (array($nodob, $adult, $marked) as $u) {
    RSL_Free_Agents::leave($u, $season);
}
$check('Unranked-only players aren\'t shown to Ranked teams', !in_array('Fabeg', $names_r, true));
$grp = array_values(array_filter($pool_r, function ($c) { return $c['first_name'] === 'Faf1'; }));
$check('a friend group is one card with +2 friends', count($grp) === 1 && $grp[0]['friends_n'] === 2 && !in_array('Faf2', $names_r, true));
$check('nobody but the captain can browse', RSL_Free_Agents::browse_for_captain($tr->id, $cap_u) === array());
$names_u = array_map(function ($c) { return $c['first_name']; }, RSL_Free_Agents::browse_for_captain($tu->id, $cap_u));
$check('the Unranked team sees the beginner', in_array('Fabeg', $names_u, true) && !in_array('Fark', $names_u, true));
$left = $mk('left');
RSL_Free_Agents::join($left, $season, array('division_pref' => 'either', 'browsable' => 1), true);
$wpdb->update(RSL_Database::table('free_agents'), array('status' => 'unplaced'), array('season' => $season, 'user_id' => $left));
$set_building($season, array('confirmed_at' => current_time('mysql')));
$seen_left = in_array('Faleft', array_map(function ($c) { return $c['first_name']; }, RSL_Free_Agents::browse_for_captain($tu->id, $cap_u)), true);
$set_building($season, array('confirmed_at' => ''));
$check('players a placement run couldn\'t fit are shown to captains once the season is on (first in line)', $seen_left);
RSL_Free_Agents::leave($left, $season);

// --- Requests --------------------------------------------------------------------------------------
$looking_r = RSL_Free_Agents::teams_looking($season, $rk);
$check('a Ranked-only player is offered Ranked teams only', count($looking_r) === 1 && intval($looking_r[0]['team']->id) === intval($tr->id) && $looking_r[0]['can_ask']);
$check('a beginner is never offered a Ranked team', !array_filter(RSL_Free_Agents::teams_looking($season, $beg), function ($i) use ($tr) { return intval($i['team']->id) === intval($tr->id); }));
$req = RSL_Free_Agents::request_join($tr->id, $rk);
$check('ask to join', is_int($req) && $req > 0);
$check('while they wait, that team answers the request instead of seeing them in the list', !in_array('Fark', array_map(function ($c) { return $c['first_name']; }, RSL_Free_Agents::browse_for_captain($tr->id, $cap_r)), true));
$check('asking twice is refused', is_wp_error(RSL_Free_Agents::request_join($tr->id, $rk)));
$check('the requester can\'t accept their own request', is_wp_error(RSL_Free_Agents::respond_request($req, $rk, true)));
$check('another team\'s captain can\'t answer it', is_wp_error(RSL_Free_Agents::respond_request($req, $cap_u, true)));
$seen = RSL_Free_Agents::requests_for_team($tr->id);
$check('the captain sees the request card', count($seen) === 1 && $seen[0]['first_name'] === 'Fark' && isset($seen[0]['request_id']) && !isset($seen[0]['email']));
$check('declining works', RSL_Free_Agents::respond_request($req, $cap_r, false) === true);
$after = RSL_Free_Agents::teams_looking($season, $rk);
$check('a decline is final for that team', !$after[0]['can_ask'] && $after[0]['why'] === 'Not this time' && is_wp_error(RSL_Free_Agents::request_join($tr->id, $rk)));
$check('and the team no longer sees them in the list', !in_array('Fark', array_map(function ($c) { return $c['first_name']; }, RSL_Free_Agents::browse_for_captain($tr->id, $cap_r)), true));
// The request limit (3) is shared by a group.
$extra = array();
for ($i = 0; $i < 3; $i++) {
    $c = $mk("xc$i");
    $t = RSL_Teams::create($season, array('name' => "FA Extra $i $suffix", 'division' => 'unranked', 'status' => 'active'));
    RSL_Teams::add_member($t->id, $c, 'captain');
    RSL_Free_Agents::set_spots($t->id, $c, array('spots' => 4));
    $extra[] = array($t, $c);
}
$g1 = RSL_Free_Agents::request_join($extra[0][0]->id, $f1);
$g2 = RSL_Free_Agents::request_join($extra[1][0]->id, $f2);
$g3 = RSL_Free_Agents::request_join($extra[2][0]->id, $f3);
$g4 = RSL_Free_Agents::request_join($tu->id, $f1);
$check('a group shares 3 requests', is_int($g1) && is_int($g2) && is_int($g3) && is_wp_error($g4));
$check('withdrawing frees one up', RSL_Free_Agents::withdraw_request($g3, $f1) === true && is_int(RSL_Free_Agents::request_join($tu->id, $f1)));
$set_building($season, array('deadline' => $days(-1)));
$check('requests can\'t be accepted while placements are made', is_wp_error(RSL_Free_Agents::respond_request($g1, $extra[0][1], true)));
$set_building($season, array('deadline' => ''));
$check('accepting a group request adds the whole group', RSL_Free_Agents::respond_request($g1, $extra[0][1], true) === true
    && RSL_Teams::is_member($extra[0][0]->id, $f1) && RSL_Teams::is_member($extra[0][0]->id, $f2) && RSL_Teams::is_member($extra[0][0]->id, $f3));
$check('their list entries are marked placed by request', RSL_Free_Agents::get($f2, $season)->status === 'placed' && RSL_Free_Agents::get($f2, $season)->placed_via === 'request');
$open_left = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . RSL_Database::table('join_requests') . " WHERE season = %s AND status = 'pending' AND (user_id IN (%d, %d, %d) OR group_code = %s)", $season, $f1, $f2, $f3, $code));
$check('their other requests close', $open_left === 0);

// --- Pool invites ---------------------------------------------------------------------------------------
$card = null;
foreach (RSL_Free_Agents::browse_for_captain($tu->id, $cap_u) as $c) { if ($c['first_name'] === 'Fabeg') { $card = $c; } }
$inv = $card ? RSL_Free_Agents::invite_from_pool($tu->id, $cap_u, $card['fa_id']) : 0;
$check('a captain invites from the list', is_int($inv) && $inv > 0);
$inv_row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . RSL_Database::table('invites') . " WHERE id = %d", $inv));
$check('a pool invite has no email and expires', $inv_row && $inv_row->source === 'pool' && $inv_row->email === '' && $inv_row->expires_at > current_time('mysql'));
$check('the invited player drops out of that team\'s list', !in_array('Fabeg', array_map(function ($c) { return $c['first_name']; }, RSL_Free_Agents::browse_for_captain($tu->id, $cap_u)), true));
$check('the player sees the invite', (bool) array_filter(RSL_Teams::pending_invites_for_user($beg), function ($i) use ($inv) { return intval($i->id) === $inv; }));
$wpdb->update(RSL_Database::table('invites'), array('expires_at' => date('Y-m-d H:i:s', current_time('timestamp') - 60)), array('id' => $inv));
$check('an expired invite can\'t be accepted', is_wp_error(RSL_Teams::respond_invite($inv, $beg, true)));
$check('and isn\'t listed', !array_filter(RSL_Teams::pending_invites_for_user($beg), function ($i) use ($inv) { return intval($i->id) === $inv; }));
$inv2 = RSL_Free_Agents::invite_from_pool($tu->id, $cap_u, $card['fa_id']);
$check('accepting a pool invite joins the team', is_int($inv2) && RSL_Teams::respond_invite($inv2, $beg, true) === true && RSL_Teams::is_member($tu->id, $beg)
    && RSL_Free_Agents::get($beg, $season)->placed_via === 'pool');
$tu = RSL_Teams::get($tu->id);
$cap = intval($tu->spots_open) + RSL_Free_Agents::settings()['pool_invites_extra'];
$made = 0;
for ($i = 0; $i < $cap + 1; $i++) {
    $p = $mk("pool$i");
    $r = RSL_Free_Agents::join($p, $season, array('division_pref' => 'either', 'browsable' => 1), true);
    $r = RSL_Free_Agents::invite_from_pool($tu->id, $cap_u, $r->id);
    if (!is_wp_error($r)) { $made++; }
}
$check('pool invites stop at open spots + 2', $made === $cap, "made $made, cap $cap");

// --- Any route closes the rest; the roster maximum -----------------------------------------------------
$vv_req = RSL_Free_Agents::request_join($tr->id, $vvl);
$code_join = RSL_Teams::join_by_code($vvl, RSL_Teams::invite_code($extra[1][0]));
$check('joining by code marks them placed and closes their requests', !is_wp_error($code_join) && RSL_Free_Agents::get($vvl, $season)->status === 'placed'
    && $wpdb->get_var($wpdb->prepare("SELECT status FROM " . RSL_Database::table('join_requests') . " WHERE id = %d", $vv_req)) === 'closed');
$big = RSL_Teams::create($season, array('name' => "FA Full $suffix", 'division' => 'unranked', 'status' => 'active'));
$max = RSL_Free_Agents::settings()['max_roster'];
for ($i = 0; $i < $max; $i++) { RSL_Teams::add_member($big->id, $mk("full$i"), $i === 0 ? 'captain' : 'player'); }
$late = $mk('late');
$refused = RSL_Teams::join_by_code($late, RSL_Teams::invite_code($big));
$check('a full team refuses joins by code', is_wp_error($refused));
$check('an admin can still add someone', RSL_Teams::add_member($big->id, $late, 'player', false, array('admin' => true, 'how' => 'admin')) === true);
// Two people taking a team's last spot at once: the count and the insert run
// under the team's lock, so while another connection holds it a join waits
// (about 5 seconds) and is refused rather than going over the maximum.
$other_db = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$lock_team = $extra[1][0];
$held = (string) $other_db->get_var($other_db->prepare('SELECT GET_LOCK(%s, 0)', RSL_Teams::team_lock_name($lock_team->id))) === '1';
$racer = $mk('racer');
$busy = RSL_Teams::join_by_code($racer, RSL_Teams::invite_code($lock_team));
$check('a join waits for the team\'s lock (two people can\'t both take the last spot)', $held && is_wp_error($busy) && $busy->get_error_code() === 'rsl_busy' && !RSL_Teams::is_member($lock_team->id, $racer));
$other_db->query($other_db->prepare('SELECT RELEASE_LOCK(%s)', RSL_Teams::team_lock_name($lock_team->id)));
$other_db->close();
$check('and goes through once it\'s free', !is_wp_error(RSL_Teams::join_by_code($racer, RSL_Teams::invite_code($lock_team))) && RSL_Teams::is_member($lock_team->id, $racer));

// --- Placing by an admin, "not for me", leaving -------------------------------------------------------------
$p1 = $mk('p1', 'never', 'lt1');
RSL_Free_Agents::join($p1, $season, array('browsable' => 1), true);
$check('an admin placing a beginner into Ranked sends an invite instead', RSL_Free_Agents::place_now($p1, $tr->id, 1) === 'invited' && !RSL_Teams::is_member($tr->id, $p1));
$p2 = $mk('p2');
RSL_Free_Agents::join($p2, $season, array('division_pref' => 'either', 'browsable' => 1), true);
$check('an admin places someone', RSL_Free_Agents::place_now($p2, $extra[2][0]->id, 1) === 'placed' && RSL_Teams::is_member($extra[2][0]->id, $p2));
$check('"not for me" only when placed by the league inside the window', is_wp_error(RSL_Free_Agents::not_for_me($p2, $season)));
$wpdb->update(RSL_Database::table('free_agents'), array('optout_until' => date('Y-m-d H:i:s', current_time('timestamp') + DAY_IN_SECONDS)), array('season' => $season, 'user_id' => $p2));
$check('"not for me" takes them off the team and back on the list', RSL_Free_Agents::not_for_me($p2, $season) === true
    && !RSL_Teams::is_member($extra[2][0]->id, $p2) && RSL_Free_Agents::get($p2, $season)->status === 'looking');
$lv = RSL_Free_Agents::request_join($extra[2][0]->id, $p2);
$check('leaving the list withdraws requests', RSL_Free_Agents::leave($p2, $season) === true && RSL_Free_Agents::get($p2, $season)->status === 'withdrawn'
    && $wpdb->get_var($wpdb->prepare("SELECT status FROM " . RSL_Database::table('join_requests') . " WHERE id = %d", $lv)) === 'withdrawn'
    && (int) RSL_Players::get($p2)->looking === 0);
add_filter('pre_wp_mail', '__return_true');
$by_email = RSL_Teams::invite_by_email($tr->id, $cap_r, "rslfa-p1-$suffix@example.test");
remove_filter('pre_wp_mail', '__return_true');
$check('a pool invite doesn\'t make "invite by email" confirm that free agent\'s email', is_int($by_email) && $by_email > 0);
if (is_int($by_email)) { RSL_Teams::cancel_invite($by_email, $cap_r); }

// --- Pool invites never speak for a friend who hasn't agreed -------------------------------------------------
$ga = $mk('ga'); $gb = $mk('gb');
RSL_Free_Agents::join($ga, $season, array('division_pref' => 'either', 'browsable' => 1), true);
RSL_Free_Agents::join($gb, $season, array('division_pref' => 'unranked', 'browsable' => 1), true);
RSL_Free_Agents::group_join($gb, $season, RSL_Free_Agents::group_create($ga, $season));
$placed = RSL_Free_Agents::place_now($ga, $tr->id, 1);
$ask = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . RSL_Database::table('invites') . " WHERE team_id = %d AND source = 'pool' AND status = 'pending' AND user_id IN (%d, %d)", $tr->id, $ga, $gb));
$check('placing a group into Ranked asks the friend who hasn\'t agreed (not the one who has)', $placed === 'invited' && $ask && intval($ask->user_id) === $gb);
$check('and their yes brings the group', $ask && RSL_Teams::respond_invite($ask->id, $gb, true) === true && RSL_Teams::is_member($tr->id, $ga) && RSL_Teams::is_member($tr->id, $gb));
$gu1 = $mk('gu1'); $gu2 = $mk('gu2');
RSL_Free_Agents::join($gu1, $season, array('division_pref' => 'unranked', 'browsable' => 1), true);
RSL_Free_Agents::join($gu2, $season, array('division_pref' => 'unranked', 'browsable' => 1), true);
RSL_Free_Agents::group_join($gu2, $season, RSL_Free_Agents::group_create($gu1, $season));
$two_no = RSL_Free_Agents::place_now($gu1, $tr->id, 1);
$check('two who haven\'t agreed can\'t be asked with one invite', is_wp_error($two_no) && !RSL_Teams::is_member($tr->id, $gu1));
$gx = $mk('gx'); $gy = $mk('gy'); $gz = $mk('gz');
foreach (array($gx, $gy, $gz) as $g) { RSL_Free_Agents::join($g, $season, array('division_pref' => 'either', 'browsable' => 1), true); }
$xcode = RSL_Free_Agents::group_create($gx, $season);
RSL_Free_Agents::group_join($gy, $season, $xcode);
$card_of = function ($first) use ($tr, $cap_r) {
    foreach (RSL_Free_Agents::browse_for_captain($tr->id, $cap_r) as $c) { if ($c['first_name'] === $first) { return $c; } }
    return null;
};
$xcard = $card_of('Fagx');
$xinv = $xcard ? RSL_Free_Agents::invite_from_pool($tr->id, $cap_r, $xcard['fa_id']) : 0;
RSL_Free_Agents::update($gy, $season, array('division_pref' => 'unranked'));
$narrowed = is_int($xinv) ? RSL_Teams::respond_invite($xinv, $gx, true) : null;
$check('a friend who narrowed to Unranked after the invite isn\'t taken into Ranked', is_int($xinv) && is_wp_error($narrowed) && $narrowed->get_error_code() === 'rsl_fa_division'
    && !RSL_Teams::is_member($tr->id, $gx) && !RSL_Teams::is_member($tr->id, $gy));
RSL_Free_Agents::update($gy, $season, array('division_pref' => 'either'));
RSL_Free_Agents::group_leave($gx, $season);
$left_group = is_int($xinv) ? RSL_Teams::respond_invite($xinv, $gx, true) : null;
$check('leaving the group closes invites sent for it — friends are never brought along unasked', is_wp_error($left_group) && !RSL_Teams::is_member($tr->id, $gy)
    && $wpdb->get_var($wpdb->prepare("SELECT status FROM " . RSL_Database::table('invites') . " WHERE id = %d", $xinv)) === 'closed');
$zcard = $card_of('Fagz');
$zinv = $zcard ? RSL_Free_Agents::invite_from_pool($tr->id, $cap_r, $zcard['fa_id']) : 0;
RSL_Free_Agents::group_join($gz, $season, $xcode);
$check('joining a group closes invites sent to you alone (the group never split or added unasked)', is_int($zinv) && is_wp_error(RSL_Teams::respond_invite($zinv, $gz, true))
    && !RSL_Teams::is_member($tr->id, $gz) && !RSL_Teams::is_member($tr->id, $gy));

// --- Applying to captain, then not ------------------------------------------------------------------------------
$ap = $mk('ap');
RSL_Free_Agents::join($ap, $season, array('division_pref' => 'either', 'browsable' => 1), true);
$ap_team = RSL_Teams::apply($season, $ap, array('name' => "FA Applied $suffix", 'level' => 'social'));
$check('applying to captain takes them off the list', !is_wp_error($ap_team) && RSL_Free_Agents::get($ap, $season)->status === 'placed');
$withdrawn = !is_wp_error($ap_team) ? RSL_Teams::withdraw($ap_team->id, $ap) : null;
$ap_row = RSL_Free_Agents::get($ap, $season);
$check('a withdrawn or declined application puts them back on it', $withdrawn === true && $ap_row->status === 'looking' && intval($ap_row->team_id) === 0);

// --- The old "looking" flag ---------------------------------------------------------------------------------------
$old = $mk('old', 'school');
RSL_Players::set_looking($old, true);
$check('set_looking puts them on the sign-up season\'s list', RSL_Free_Agents::is_open(RSL_Free_Agents::get($old, $season)));
RSL_Players::set_looking($old, false);
$check('and takes them off', RSL_Free_Agents::get($old, $season)->status === 'withdrawn');
$wpdb->update(RSL_Database::table('players'), array('looking' => 1), array('user_id' => $old));
$wpdb->delete(RSL_Database::table('free_agents'), array('season' => $season, 'user_id' => $old));
$n1 = RSL_Free_Agents::backfill();
$n2 = RSL_Free_Agents::backfill();
$check('the back-fill adds older sign-ups once', $n1 >= 1 && $n2 === 0 && RSL_Free_Agents::is_open(RSL_Free_Agents::get($old, $season)));
$stale = $mk('stale');
$wpdb->update(RSL_Database::table('players'), array('looking' => 1), array('user_id' => $stale));
RSL_Players::register($stale, array('level' => 'vvl', 'years' => 'gt3', 'looking' => true));
$check('saving your details never signs you up from a stale "looking" flag', !RSL_Free_Agents::get($stale, $season) && RSL_Players::get($stale)->level === 'vvl');
$fresh = $mk('fresh', '');
RSL_Players::register($fresh, array('level' => 'social', 'years' => '1to3', 'looking' => true));
$check('a new registration with "no team yet" ticked still joins the list', RSL_Free_Agents::is_open(RSL_Free_Agents::get($fresh, $season)));

// --- What a player may see of a placement reason -------------------------------------------------------------------
$check('admin placement reasons stay off the player page and email', RSL_Free_Agents::unplaced_words('Held back by an admin') === ''
    && RSL_Free_Agents::unplaced_words('Moved by an admin (that team is no longer in the season)') === ''
    && RSL_Free_Agents::unplaced_words(' No team in your division had room ') === 'No team in your division had room'
    && RSL_Free_Agents::unplaced_words('') === '');

// --- League notices ----------------------------------------------------------------------------------------------
$saved_pay = get_option(RSL_Payments::SETTINGS_OPTION, '__unset__');
update_option(RSL_Payments::SETTINGS_OPTION, array_merge(is_array($saved_pay) ? $saved_pay : array(), array('contact_emails' => "rslfa-league-$suffix@example.test")));
$check('league notices go to the league contact emails (Team Balances → Rules)', RSL_Free_Agents::league_contacts() === array("rslfa-league-$suffix@example.test"));
if ($saved_pay === '__unset__') { delete_option(RSL_Payments::SETTINGS_OPTION); } else { update_option(RSL_Payments::SETTINGS_OPTION, $saved_pay); }

// --- Admin: the rules form, requests waiting -------------------------------------------------------------------------
$admin = wp_insert_user(array('user_login' => "rslfa-admin-$suffix", 'user_email' => "rslfa-admin-$suffix@example.test", 'user_pass' => wp_generate_password(), 'role' => 'administrator'));
$users[] = $admin;
wp_set_current_user($admin);
$fa_post = function ($do, $extra = array()) use ($season) {
    return RSL_Admin_Free_Agents::process(array_merge(array('rsl_nonce' => wp_create_nonce(RSL_Admin_Free_Agents::ACTION), 'season' => $season, 'tab' => 'settings', 'do' => $do), $extra));
};
$fa_notice = function () use ($admin) {
    $n = get_transient(RSL_Admin::NOTICE_PREFIX . $admin);
    delete_transient(RSL_Admin::NOTICE_PREFIX . $admin);
    return is_array($n) ? $n : array('type' => '', 'message' => '');
};
$before_rules = RSL_Free_Agents::settings();
$fa_post('save_settings', array_merge($before_rules, array('max_requests' => 0)));
$n = $fa_notice();
$check('admin: a rule typed outside its range is saved as the nearest allowed value, and the notice says so', $n['type'] === 'error'
    && strpos($n['message'], 'Requests at a time 1 (not 0)') !== false && RSL_Free_Agents::settings()['max_requests'] === 1, $n['message']);
$fa_post('save_settings', array_merge($before_rules, array('gap_fill' => '1', 'gap_short' => '2.5', 'capacity_override' => array('ranked' => '6', 'unranked' => ''))));
$n = $fa_notice();
$s = RSL_Free_Agents::settings();
$check('admin: placement\'s level gaps and team cap are kept as rules (not dropped)', $n['type'] === 'success' && $s['gap_fill'] == 1.0 && $s['gap_short'] == 2.5
    && $s['capacity_override'] === array('ranked' => 6, 'unranked' => 0), json_encode(array($s['gap_fill'], $s['gap_short'], $s['capacity_override'], $n['message'])));
$fa_post('save_settings', array_merge($before_rules, array('gap_fill' => '2', 'gap_short' => '1.5')));
$n = $fa_notice();
$check('admin: the short-team gap can\'t be narrower than the open-spot gap', $n['type'] === 'error' && RSL_Free_Agents::settings()['gap_short'] == 2.5, $n['message']);
$fa_post('save_settings', array_merge($before_rules, array('capacity_override' => array('ranked' => '60', 'unranked' => ''))));
$n = $fa_notice();
$check('admin: a team cap over the limit is saved as 50 and the notice names it', $n['type'] === 'error' && strpos($n['message'], 'Most teams per division (Ranked) 50 (not 60)') !== false
    && RSL_Free_Agents::settings()['capacity_override']['ranked'] === 50, $n['message']);
$tab = new ReflectionMethod('RSL_Admin_Free_Agents', 'settings_tab');
$tab->setAccessible(true);
$html = $tab->invoke(null, $season);
$check('admin: the Placement rules show on the Settings tab', strpos($html, 'Biggest level gap when filling a short team') !== false
    && strpos($html, 'name="capacity_override[ranked]"') !== false && strpos($html, '<h3>Other</h3>') === false);
RSL_Free_Agents::save_settings($before_rules);
$cnt_team = $extra[2][0];
$was_n = RSL_Free_Agents::request_counts($season);
$was_n = isset($was_n[intval($cnt_team->id)]) ? $was_n[intval($cnt_team->id)] : 0;
$cr = $mk('cnt');
RSL_Free_Agents::join($cr, $season, array('division_pref' => 'either', 'browsable' => 1), true);
$crid = RSL_Free_Agents::request_join($cnt_team->id, $cr);
$now_n = RSL_Free_Agents::request_counts($season);
$check('admin teams table: requests waiting per team, the same ones its captain sees', is_int($crid) && isset($now_n[intval($cnt_team->id)])
    && $now_n[intval($cnt_team->id)] === $was_n + 1 && count(RSL_Free_Agents::requests_for_team($cnt_team->id)) === $now_n[intval($cnt_team->id)], json_encode(array($crid, $was_n, $now_n)));
wp_set_current_user(0);

// --- Expiry housekeeping ---------------------------------------------------------------------------------------------
$e = $mk('exp');
RSL_Free_Agents::join($e, $season, array('division_pref' => 'either', 'browsable' => 1), true);
$er = RSL_Free_Agents::request_join($extra[2][0]->id, $e);
$wpdb->update(RSL_Database::table('join_requests'), array('expires_at' => date('Y-m-d H:i:s', current_time('timestamp') - 60)), array('id' => $er));
RSL_Free_Agents::expire_stale();
$check('stale requests are marked expired', $wpdb->get_var($wpdb->prepare("SELECT status FROM " . RSL_Database::table('join_requests') . " WHERE id = %d", $er)) === 'expired');

// --- Find me a team picks the season (0.17.0) ----------------------------------------------------------------------
// Current 2087-post, signing up $season (2088-pre, unpinned, its window open); 2088-mid1 dated
// and offered through the candidates filter.
$set_start('2088-mid1', $days(100));
$cands_fa = function ($keys) { return array('2088-mid1'); };
add_filter('rsl_open_for_signup_candidates', $cands_fa);
$cs = $mk('cs');
$row_of = function ($s) use ($cs) { return RSL_Free_Agents::get($cs, $s); };
$html = RSL_Portal_FA::dashboard($cs, $season);
$a = strpos($html, '<select name="fa_season">');
$fa_select = $a === false ? '' : (string) substr($html, $a, (int) strpos($html, '</select>', $a) - $a);
$check('the Find me a team card asks which season: the sign-up season chosen, the later open one offered', $fa_select !== ''
    && strpos($fa_select, 'value="2088-pre" selected=\'selected\'') !== false && strpos($fa_select, 'value="2088-mid1"') !== false, $fa_select);
$r = RSL_Portal_FA::process('fa_join', array('fa_season' => '2088-mid1', 'division_pref' => 'either', 'browsable' => '1'), $cs);
$check('joining for the later season: on that season\'s list, not the sign-up season\'s', is_array($r) && $r['type'] === 'success'
    && strpos($r['message'], RSL_Seasons::label('2088-mid1')) !== false && RSL_Free_Agents::is_open($row_of('2088-mid1')) && !RSL_Free_Agents::is_open($row_of($season)), wp_json_encode($r));
$html = RSL_Portal_FA::dashboard($cs, '2088-mid1', array(), false);
$check('the later season\'s cards: their own ids, and their forms carry the season', strpos($html, 'id="rsl-fa-edit-2088-mid1"') !== false
    && strpos($html, 'name="season" value="2088-mid1"') !== false);
$html = RSL_Portal_FA::dashboard($cs, $season, array('2088-pre' => 'x'));
$check('a card narrowed to one season names it in a hidden field', strpos($html, 'name="fa_season" value="2088-pre"') !== false && strpos($html, '<select name="fa_season">') === false);
$r = RSL_Portal_FA::process('fa_update', array('season' => '2088-mid1', 'time_pref' => 'late', 'division_pref' => 'either', 'browsable' => '1'), $cs);
$check('updating that season\'s row', is_array($r) && $r['type'] === 'success' && $row_of('2088-mid1')->time_pref === 'late', wp_json_encode($r));
$r = RSL_Portal_FA::process('fa_leave', array('season' => '2088-mid1'), $cs);
$check('leaving that season\'s list', is_array($r) && $r['type'] === 'success' && $row_of('2088-mid1')->status === 'withdrawn', wp_json_encode($r));
$r = RSL_Portal_FA::process('fa_join', array('fa_season' => '2087-post'), $cs);
$check('a season that isn\'t open for sign-up is refused', is_array($r) && $r['type'] === 'error' && strpos($r['message'], 'isn\'t open for sign-up') !== false, wp_json_encode($r));
$r = RSL_Portal_FA::process('fa_join', array('fa_season' => $season, 'division_pref' => 'either', 'browsable' => '1'), $cs);
$check('(on the sign-up season\'s list)', is_array($r) && $r['type'] === 'success' && $row_of($season) && $row_of($season)->status === 'looking', wp_json_encode($r));
$time_was = $row_of($season)->time_pref;
$r = RSL_Portal_FA::process('fa_leave', array('season' => '1999-pre'), $cs);
$check('a form for a season the page no longer shows is refused, never swapped for the sign-up season', is_array($r) && $r['type'] === 'error'
    && $r['message'] === 'That season isn\'t open any more — reload the page.' && $row_of($season)->status === 'looking', wp_json_encode($r));
$r = RSL_Portal_FA::process('fa_update', array('season' => '1999-pre', 'time_pref' => 'early', 'division_pref' => 'either'), $cs);
$check('...the same for saving details', is_array($r) && $r['type'] === 'error' && $r['message'] === 'That season isn\'t open any more — reload the page.'
    && $row_of($season)->time_pref === $time_was, wp_json_encode($r));
$r = RSL_Portal_FA::process('fa_leave', array(), $cs);
$check('a form from before 0.17.0 (no season) still acts on the sign-up season', is_array($r) && $r['type'] === 'success' && $row_of($season)->status === 'withdrawn', wp_json_encode($r));

} catch (Throwable $t) {
    $fails++;
    echo 'FAIL: threw ' . get_class($t) . ': ' . $t->getMessage() . ' (line ' . $t->getLine() . ")\n";
} finally {
// --- Cleanup (runs after a failure too) ------------------------------------------------------------------------------------------
wp_set_current_user(0);
foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM " . RSL_Database::table('teams') . " WHERE season = %s", $season)) as $tid) {
    $wpdb->delete(RSL_Database::table('team_members'), array('team_id' => $tid));
    $wpdb->delete(RSL_Database::table('invites'), array('team_id' => $tid));
}
$wpdb->delete(RSL_Database::table('teams'), array('season' => $season));
$wpdb->delete(RSL_Database::table('free_agents'), array('season' => $season));
$wpdb->delete(RSL_Database::table('join_requests'), array('season' => $season));
if ($cands_fa) {
    remove_filter('rsl_open_for_signup_candidates', $cands_fa);
}
$set_start('2088-mid1', '');
$wpdb->delete(RSL_Database::table('free_agents'), array('season' => '2088-mid1'));
foreach (isset($nights) ? $nights : array() as $n) { $wpdb->delete(RSL_Database::table('nights'), array('id' => $n->id)); }
$set_start($season, '');
$all = get_option(RSL_Free_Agents::BUILDING_OPTION, array());
unset($all[$season]);
update_option(RSL_Free_Agents::BUILDING_OPTION, $all, false);
foreach ($users as $u) {
    if (!is_int($u) || $u <= 0) {
        continue; // never a WP_Error: as a user_id it would match the rows with user_id 0
    }
    $wpdb->delete(RSL_Database::table('team_members'), array('user_id' => $u));
    $wpdb->delete(RSL_Database::table('players'), array('user_id' => $u));
    $wpdb->delete(RSL_Database::table('free_agents'), array('user_id' => $u));
    $wpdb->delete(RSL_Database::table('invites'), array('user_id' => $u));
}
// "Not for me" also tells the league contacts (not test addresses).
$wpdb->query($wpdb->prepare("DELETE FROM " . RSL_Database::table('outbox') . " WHERE to_email LIKE %s OR dedupe LIKE %s", '%-' . $suffix . '@example.test', 'optout:' . $season . ':%'));
foreach (array(RSL_Seasons::CURRENT_OPTION => $saved_current, RSL_Seasons::SIGNUP_OPTION => $saved_signup, RSL_Free_Agents::SETTINGS_OPTION => $saved_fa) as $opt => $v) {
    if ($v === '__unset__') { delete_option($opt); } else { update_option($opt, $v); }
}
rsl_dev_delete_users($users);
rsl_dev_release_mail($hold);
}
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
