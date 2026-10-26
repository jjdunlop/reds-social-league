<?php
/**
 * DEV ONLY — next season's team-building window, so the free agent screens
 * can be seen and clicked through on a local copy: next season's dates (the
 * window open now, the deadline a few days away), eight teams already
 * entered (some short of players, some looking), and about 45 free agents
 * with friend groups, captain volunteers, a few under-18s, requests and
 * invites.
 *
 *   wp eval-file dev/fake-signup.php          # create (replaces any earlier run)
 *   wp eval-file dev/fake-signup.php reset    # remove it
 *
 * Run dev/fake-season.php first (it makes the venues). Everything here is
 * tagged — users carry the `rsl_fake_fa` meta and @example.test emails, team
 * ids are kept in an option — and reset removes exactly that, plus next
 * season's dates, nights and list. Refuses to run outside WP-CLI.
 */

if (!defined('WP_CLI') || !WP_CLI || (wp_get_environment_type() === 'production' && !getenv('RSL_DEV'))) {
    exit("Local copy only: run with wp eval-file, with RSL_DEV=1 set (docker exec -e RSL_DEV=1 ...).\n");
}

require_once ABSPATH . 'wp-admin/includes/user.php';
require_once __DIR__ . '/lib-profile.php';
global $wpdb;

$season = RSL_Seasons::next(RSL_Seasons::current());
$team_option = 'rsl_fake_signup_team_ids';

$reset = function () use ($wpdb, $season, $team_option) {
    $team_ids = array_map('intval', (array) get_option($team_option, array()));
    foreach ($team_ids as $id) {
        $wpdb->delete(RSL_Database::table('invites'), array('team_id' => $id), array('%d'));
        $wpdb->delete(RSL_Database::table('join_requests'), array('team_id' => $id), array('%d'));
        $wpdb->delete(RSL_Database::table('ledger'), array('team_id' => $id), array('%d'));
        $wpdb->delete(RSL_Database::table('team_members'), array('team_id' => $id), array('%d'));
        $wpdb->delete(RSL_Database::table('teams'), array('id' => $id), array('%d'));
    }
    delete_option($team_option);
    // Teams a placement run made from the pretend free agents.
    foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM " . RSL_Database::table('teams') . " WHERE season = %s AND formed_by = 'placement'", $season)) as $id) {
        $wpdb->delete(RSL_Database::table('team_members'), array('team_id' => $id), array('%d'));
        $wpdb->delete(RSL_Database::table('invites'), array('team_id' => $id), array('%d'));
        $wpdb->delete(RSL_Database::table('teams'), array('id' => $id), array('%d'));
    }
    $wpdb->delete(RSL_Database::table('free_agents'), array('season' => $season));
    $wpdb->delete(RSL_Database::table('join_requests'), array('season' => $season));
    $users = get_users(array('meta_key' => 'rsl_fake_fa', 'meta_value' => '1', 'fields' => 'ID'));
    foreach ($users as $uid) {
        $wpdb->delete(RSL_Database::table('team_members'), array('user_id' => $uid), array('%d'));
        $wpdb->delete(RSL_Database::table('invites'), array('user_id' => $uid), array('%d'));
        $wpdb->delete(RSL_Database::table('free_agents'), array('user_id' => $uid), array('%d'));
        $wpdb->delete(RSL_Database::table('players'), array('user_id' => $uid), array('%d'));
        $wpdb->delete(RSL_Database::table('regos'), array('user_id' => $uid), array('%d'));
        wp_delete_user($uid);
    }
    foreach (RSL_Courts::nights($season) as $n) {
        $wpdb->delete(RSL_Database::table('night_courts'), array('night_id' => $n->id));
        $wpdb->delete(RSL_Database::table('night_venues'), array('night_id' => $n->id));
        $wpdb->delete(RSL_Database::table('nights'), array('id' => $n->id));
    }
    $all = get_option(RSL_Courts::SETTINGS_OPTION, array());
    if (is_array($all) && isset($all[$season])) {
        unset($all[$season]);
        update_option(RSL_Courts::SETTINGS_OPTION, $all, false);
    }
    $building = get_option(RSL_Free_Agents::BUILDING_OPTION, array());
    if (is_array($building) && isset($building[$season])) {
        unset($building[$season]);
        update_option(RSL_Free_Agents::BUILDING_OPTION, $building, false);
    }
    $wpdb->query("DELETE FROM " . RSL_Database::table('outbox') . " WHERE to_email LIKE '%@example.test'");
    return array(count($team_ids), count($users));
};

list($teams_removed, $users_removed) = $reset();
echo "Removed $teams_removed pretend next-season teams and $users_removed pretend players.\n";
if (isset($args[0]) && $args[0] === 'reset') {
    return;
}
if (!RSL_Courts::venues()) {
    exit("Run dev/fake-season.php first — there are no venues.\n");
}

// Next season starts on the Sunday at least two weeks away, so its window
// (21 days before) is open today and its deadline (10 days before) is a few
// days off.
$today = current_time('Y-m-d');
$first_night = date('Y-m-d', strtotime($today . ' +14 days'));
while (date('w', strtotime($first_night)) !== '0') {
    $first_night = date('Y-m-d', strtotime($first_night . ' +1 day'));
}
$minutes = RSL_Courts::settings(RSL_Seasons::current())['minutes'];
RSL_Courts::save_settings($season, array('start' => $first_night, 'end' => date('Y-m-d', strtotime($first_night . ' +11 weeks')), 'weekday' => 0, 'minutes' => $minutes));
RSL_Courts::generate_nights($season);
$nights = RSL_Courts::nights($season);
RSL_Courts::set_night_type($nights[10]->id, 'finals');
RSL_Courts::set_night_type($nights[11]->id, 'finals');

$first = array('Ash', 'Bea', 'Cal', 'Dee', 'Eli', 'Fin', 'Gus', 'Hana', 'Ira', 'Jo', 'Kit', 'Lou', 'Mia', 'Ned', 'Ola', 'Pip',
    'Rae', 'Sid', 'Tess', 'Ugo', 'Val', 'Wes', 'Xan', 'Yas', 'Zed', 'Amir', 'Bo', 'Cleo', 'Dev', 'Esme');
$last = array('Abbott', 'Baker', 'Cruz', 'Dao', 'Evans', 'Fox', 'Gill', 'Ho', 'Ivanov', 'Jensen', 'Khan', 'Lam', 'Moss', 'Ng', 'Ortiz', 'Park');
mt_srand(2028); // the same pretend sign-ups every time
$levels = array('never', 'never', 'school', 'school', 'social', 'social', 'social', 'vvl', 'vvl', 'state');
$years = array_keys(RSL_Players::years());
$positions = array_keys(RSL_Players::positions());
$make_user = function ($i) use ($first, $last, $levels, $years, $positions) {
    $f = $first[mt_rand(0, count($first) - 1)];
    $l = $last[mt_rand(0, count($last) - 1)];
    $uid = wp_insert_user(array(
        'user_login' => sprintf('rsl-fa-%03d', $i),
        'user_email' => sprintf('rsl-fa-%03d@example.test', $i),
        'user_pass' => wp_generate_password(24),
        'display_name' => $f . ' ' . $l,
        'first_name' => $f,
        'last_name' => $l,
        'role' => 'subscriber',
    ));
    if (is_wp_error($uid)) {
        return 0;
    }
    update_user_meta($uid, 'rsl_fake_fa', '1');
    update_user_meta($uid, 'profile_confirmed_year', current_time('Y'));
    // Registering needs a club profile; adults, born 1980–2004 (not from
    // mt_rand, so the rest of the pretend sign-ups stay the same).
    rsl_dev_profile($uid, sprintf('%04d/%02d/%02d', 1980 + ($i * 7) % 25, ($i * 5) % 12 + 1, ($i * 11) % 28 + 1), $i % 2 ? 'Male' : 'Female');
    $level = $levels[mt_rand(0, count($levels) - 1)];
    $mine = array($positions[mt_rand(0, count($positions) - 1)]);
    if (mt_rand(0, 2) === 0) {
        $mine[] = $positions[mt_rand(0, count($positions) - 1)];
    }
    RSL_Players::register($uid, array(
        'level' => $level,
        'years' => in_array($level, array('never'), true) ? 'lt1' : $years[mt_rand(0, count($years) - 1)],
        'positions' => array_unique($mine),
    ));
    if ($i % 6 !== 2) {
        RSL_Rego::grant($uid, array('source' => 'manual', 'note' => 'Fake sign-up'));
    }
    return $uid;
};

// Eight teams already entered for next season — some short of players and
// looking, one full.
$plan = array(
    array('Kill Bill', 'ranked', 'strong', 9, 0, '', 'any', ''),
    array('Spiked Punch', 'ranked', 'solid', 5, 3, 'setter,middle', 'some', 'Friendly crew, Sunday regulars. Need a setter!'),
    array('Dig It', 'ranked', 'social', 7, 1, 'libero', 'any', ''),
    array('Blockbusters', 'ranked', 'advanced', 8, 0, '', 'experienced', ''),
    array('Ace of Spades', 'ranked', 'solid', 4, 4, '', 'any', 'New team — all welcome, we just want a good night.'),
    array('Bump Set Fun', 'unranked', 'new', 6, 2, '', 'beginners', 'Total beginners welcome, we are learning too.'),
    array('Net Ninjas', 'unranked', 'social', 3, 4, 'setter', 'beginners', ''),
    array('Sets and the City', 'unranked', 'social', 10, 0, '', 'any', ''),
);
$colours = array('#1d3d6e', '#b32d2e', '#2e8540', '#8c2361', '#d97706', '#0e7490', '#6d28d9', '#475569');
$u = 1;
$team_ids = array();
$captains = array();
foreach ($plan as $k => $t) {
    list($name, $division, $level, $size, $spots, $spot_pos, $spot_level, $note) = $t;
    $team = RSL_Teams::create($season, array('name' => $name, 'division' => $division, 'colour' => $colours[$k], 'level' => $level, 'status' => 'active'));
    if (is_wp_error($team)) {
        echo "Skipped $name: " . $team->get_error_message() . "\n";
        continue;
    }
    $team_ids[] = intval($team->id);
    for ($m = 0; $m < $size; $m++) {
        $uid = $make_user($u++);
        if ($uid) {
            RSL_Teams::add_member($team->id, $uid, $m === 0 ? 'captain' : 'player', false, array('admin' => true, 'how' => 'code'));
            if ($m === 0) {
                $captains[intval($team->id)] = $uid;
            }
        }
    }
    if ($spots) {
        RSL_Free_Agents::set_spots($team->id, $captains[intval($team->id)], array('spots' => $spots, 'positions' => array_filter(explode(',', $spot_pos)), 'level' => $spot_level, 'note' => $note));
    }
    RSL_Teams::set_preferences($team->id, $captains[intval($team->id)], array('time' => array('any', 'early', 'late')[$k % 3]));
}
update_option($team_option, $team_ids, false);

// 45 free agents. Beginners default to Unranked; most others are happy with
// either division, a few only want Ranked. Some are away a week, a few
// offered to captain, three are under 18, two would rather not be browsed.
$agents = array();
$regular = array_values(array_filter($nights, function ($n) { return $n->type === 'regular'; }));
for ($i = 0; $i < 45; $i++) {
    $uid = $make_user($u++);
    if (!$uid) {
        continue;
    }
    $player = RSL_Players::get($uid);
    if (in_array($i, array(8, 23, 40), true)) {
        update_user_meta($uid, 'birth_date', rsl_dev_dob(16, $i)); // three under-18s (from the date of birth)
    }
    $pref = RSL_Free_Agents::default_division($player);
    if ($pref === 'either' && $i % 5 === 0) {
        $pref = 'ranked';
    }
    $away = array();
    if ($i % 4 === 1) {
        $away[] = $regular[mt_rand(0, 2)]->night_date;
    }
    if ($i % 9 === 3) {
        $away[] = $regular[mt_rand(3, 8)]->night_date;
    }
    $row = RSL_Free_Agents::join($uid, $season, array(
        'division_pref' => $pref,
        'time_pref' => array('any', 'any', 'early', 'late')[$i % 4],
        'away_dates' => $away,
        'captain_ok' => in_array($i, array(4, 11, 19, 27, 36), true),
        'browsable' => !in_array($i, array(14, 31), true),
    ), true);
    if (is_wp_error($row)) {
        echo "Free agent $i: " . $row->get_error_message() . "\n";
        continue;
    }
    $agents[] = $uid;
}

// Friend groups: five pairs and two threes (same division choices).
$grouped = 0;
foreach (array(array(0, 1), array(5, 6), array(12, 13), array(20, 21), array(29, 30), array(15, 16, 17), array(33, 34, 35)) as $g) {
    $lead = $agents[$g[0]];
    $code = RSL_Free_Agents::group_create($lead, $season);
    if (is_wp_error($code)) {
        continue;
    }
    foreach (array_slice($g, 1) as $idx) {
        // Line up division choices first so the group can share a division.
        $lead_row = RSL_Free_Agents::get($lead, $season);
        $wpdb->update(RSL_Database::table('free_agents'), array('division_pref' => $lead_row->division_pref), array('season' => $season, 'user_id' => $agents[$idx]));
        $r = RSL_Free_Agents::group_join($agents[$idx], $season, $code);
        if (!is_wp_error($r)) {
            $grouped++;
        }
    }
}

// The free agents left over from the current pretend season go on the list too.
$carried = RSL_Free_Agents::backfill();

// Requests and invites during the window: a few asks waiting, one declined,
// one accepted; two captains inviting from the list.
$by_name = array();
foreach ($team_ids as $tid) {
    $by_name[RSL_Teams::get($tid)->name] = $tid;
}
$asks = 0;
foreach (array(array(2, 'Spiked Punch'), array(3, 'Ace of Spades'), array(7, 'Bump Set Fun'), array(9, 'Net Ninjas'), array(10, 'Spiked Punch'), array(18, 'Ace of Spades'), array(22, 'Bump Set Fun')) as $ask) {
    $r = RSL_Free_Agents::request_join($by_name[$ask[1]], $agents[$ask[0]]);
    if (!is_wp_error($r)) {
        $asks++;
    }
}
$declined = $accepted = 0;
foreach (RSL_Free_Agents::requests_for_team($by_name['Net Ninjas']) as $req) {
    $r = RSL_Free_Agents::respond_request($req['request_id'], $captains[$by_name['Net Ninjas']], true);
    $accepted += is_wp_error($r) ? 0 : 1;
    break;
}
foreach (RSL_Free_Agents::requests_for_team($by_name['Ace of Spades']) as $req) {
    $r = RSL_Free_Agents::respond_request($req['request_id'], $captains[$by_name['Ace of Spades']], false);
    $declined += is_wp_error($r) ? 0 : 1;
    break;
}
$invited = 0;
foreach (array('Spiked Punch' => 1, 'Bump Set Fun' => 2) as $team_name => $n) {
    $tid = $by_name[$team_name];
    foreach (array_slice(RSL_Free_Agents::browse_for_captain($tid, $captains[$tid]), 3, $n) as $card) {
        $r = RSL_Free_Agents::invite_from_pool($tid, $captains[$tid], $card['fa_id']);
        $invited += is_wp_error($r) ? 0 : 1;
    }
}

// None of this pretend mail goes anywhere.
$wpdb->query("DELETE FROM " . RSL_Database::table('outbox') . " WHERE to_email LIKE '%@example.test'");

$d = RSL_Free_Agents::dates($season);
$c = RSL_Free_Agents::counts($season);
echo sprintf("%s: first night %s, window opened %s, deadline %s (phase: %s; sign-up season now %s).\n",
    RSL_Seasons::label($season), $d['first_night'], $d['opens'], $d['deadline'], RSL_Free_Agents::phase($season), RSL_Seasons::label(RSL_Seasons::signup()));
echo sprintf("Created %d teams and %d players; %d free agents looking (%d carried over from this season), %d in friend groups.\n",
    count($team_ids), $u - 1, $c['looking'], $carried, $grouped);
echo sprintf("Requests: %d sent, %d accepted, %d declined. Pool invites: %d.\n", $asks, $accepted, $declined, $invited);
echo 'Free agent login to try: ' . get_userdata($agents[0])->user_login . '; captain with open spots: ' . get_userdata($captains[$by_name['Spiked Punch']])->user_login . "\n";
