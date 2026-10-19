<?php
/**
 * DEV ONLY — fills the current season with a pretend league so every page
 * can be seen and clicked through on a local copy.
 *
 *   wp eval-file dev/fake-season.php          # create (replaces any earlier fake data)
 *   wp eval-file dev/fake-season.php reset    # remove the fake data only
 *
 * Everything it makes is tagged — users carry the `rsl_fake` meta and
 * @example.test emails, team ids are kept in an option — and reset removes
 * exactly that. Refuses to run outside WP-CLI.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    exit("Run this with wp eval-file, on a local copy only.\n");
}

require_once ABSPATH . 'wp-admin/includes/user.php';
global $wpdb;

$fake_option = 'rsl_fake_team_ids';
$fake_venues_option = 'rsl_fake_venue_ids';

$reset = function () use ($wpdb, $fake_option, $fake_venues_option) {
    $team_ids = array_map('intval', (array) get_option($fake_option, array()));
    foreach ($team_ids as $id) {
        $wpdb->delete(RSL_Database::table('team_members'), array('team_id' => $id), array('%d'));
        $wpdb->delete(RSL_Database::table('teams'), array('id' => $id), array('%d'));
    }
    delete_option($fake_option);
    foreach (array_map('intval', (array) get_option($fake_venues_option, array())) as $vid) {
        foreach (RSL_Courts::courts($vid, false) as $court) {
            $wpdb->delete(RSL_Database::table('night_courts'), array('court_id' => $court->id));
        }
        $wpdb->delete(RSL_Database::table('courts'), array('venue_id' => $vid));
        $wpdb->delete(RSL_Database::table('night_venues'), array('venue_id' => $vid));
        $wpdb->delete(RSL_Database::table('venues'), array('id' => $vid));
    }
    delete_option($fake_venues_option);
    $users = get_users(array('meta_key' => 'rsl_fake', 'meta_value' => '1', 'fields' => 'ID'));
    foreach ($users as $uid) {
        $wpdb->delete(RSL_Database::table('team_members'), array('user_id' => $uid), array('%d'));
        $wpdb->delete(RSL_Database::table('players'), array('user_id' => $uid), array('%d'));
        $wpdb->delete(RSL_Database::table('regos'), array('user_id' => $uid), array('%d'));
        wp_delete_user($uid);
    }
    return array(count($team_ids), count($users));
};

list($teams_removed, $users_removed) = $reset();
echo "Removed $teams_removed fake teams and $users_removed fake players.\n";
if (isset($args[0]) && $args[0] === 'reset') {
    return;
}

$season = RSL_Seasons::current();
$first = array('Alex', 'Sam', 'Jordan', 'Taylor', 'Morgan', 'Riley', 'Casey', 'Jamie', 'Charlie', 'Avery', 'Quinn', 'Harper',
    'Rowan', 'Emerson', 'Finley', 'Hayden', 'Kai', 'Lee', 'Max', 'Noah', 'Olivia', 'Priya', 'Ravi', 'Sofia', 'Tom', 'Uma', 'Vik', 'Wren', 'Yuki', 'Zara');
$last = array('Nguyen', 'Smith', 'Chen', 'Patel', 'Brown', 'Wilson', 'Kim', 'Singh', 'Taylor', 'Lee', 'Martin', 'Garcia', 'Wong', 'Jones', 'Kaur', 'Tran');
$names = array('Block Party', 'Spike Lee', 'Net Results', 'Dig Deep', 'Set to Kill', 'Ace Ventura', 'Bump n Grind', 'Sets on the Beach',
    'Kiss My Ace', 'The Dinkers', 'Hit Happens', 'Serve-ivors', 'Volley Llamas', 'Notorious D.I.G.', 'Return to Sender', 'Free Ballers',
    'Six Pack', 'Court Jesters', 'Rotation Station', 'Side Out Squad');
$colours = array('#1d3d6e', '#b32d2e', '#2e8540', '#8c2361', '#d97706', '#0e7490', '#6d28d9', '#475569');

mt_srand(2027); // the same pretend league every time
$players = array();
for ($i = 1; $i <= 150; $i++) {
    $display = $first[mt_rand(0, count($first) - 1)] . ' ' . $last[mt_rand(0, count($last) - 1)];
    $uid = wp_insert_user(array(
        'user_login' => sprintf('rsl-fake-%03d', $i),
        'user_email' => sprintf('rsl-fake-%03d@example.test', $i),
        'user_pass' => wp_generate_password(24),
        'display_name' => $display,
        'role' => 'subscriber',
    ));
    if (!is_wp_error($uid)) {
        update_user_meta($uid, 'rsl_fake', '1');
        update_user_meta($uid, 'profile_confirmed_year', current_time('Y')); // past the site's yearly profile check
        $players[] = $uid;
    }
}

// Everyone registered for the league; the last 10 are free agents looking
// for a team. About 1 in 8 still needs to pay rego so ✓/✗ both show.
$player_levels = array_keys(RSL_Players::levels());
$player_years = array_keys(RSL_Players::years());
$player_positions = array_keys(RSL_Players::positions());
foreach ($players as $i => $uid) {
    RSL_Players::register($uid, array(
        'level' => $player_levels[mt_rand(0, count($player_levels) - 1)],
        'years' => $player_years[mt_rand(0, count($player_years) - 1)],
        'positions' => array($player_positions[mt_rand(0, count($player_positions) - 1)]),
        'looking' => $i >= count($players) - 10,
    ));
    if ($i % 8 !== 3) {
        RSL_Rego::grant($uid, array('source' => 'manual', 'note' => 'Fake season'));
    }
}

$team_ids = array();
$p = 0;
foreach ($names as $n => $name) {
    $levels = array_keys(RSL_Teams::levels());
    $team = RSL_Teams::create($season, array(
        'name' => $name,
        'division' => $n < 14 ? 'ranked' : 'unranked',
        'colour' => $colours[$n % count($colours)],
        'level' => $n < 14 ? $levels[1 + ($n % 4)] : $levels[$n % 2],
    ));
    if (is_wp_error($team)) {
        echo "Skipped $name: " . $team->get_error_message() . "\n";
        continue;
    }
    $team_ids[] = intval($team->id);
    $size = 6 + mt_rand(0, 3);
    $captain = $players[$p];
    for ($k = 0; $k < $size && $p < count($players) - 10; $k++) { // keep 10 as free agents
        RSL_Teams::add_member($team->id, $players[$p++], $k === 0 ? 'captain' : 'player');
    }
    $times = array_keys(RSL_Teams::time_preferences());
    $shapes = array_keys(RSL_Teams::double_shapes());
    RSL_Teams::set_preferences($team->id, $captain, array(
        'time' => $times[$n % count($times)],
        'double' => $n % 3 === 0,
        'shape' => $shapes[$n % 2],
        'standby' => $n % 4 === 1,
    ));
}
update_option($fake_option, $team_ids, false);

// Two venues as the league runs: Ranked at one, Unranked at the other, with
// one court finishing early to show a per-court difference.
$bundha = RSL_Courts::create_venue('Bundha Sports Centre', 'ranked');
foreach (array('Court 1', 'Court 2', 'Court 3') as $c) { RSL_Courts::create_court($bundha->id, $c, '19:00', '23:00'); }
RSL_Courts::create_court($bundha->id, 'Court 4', '19:00', '21:00');
$uni = RSL_Courts::create_venue('Melbourne Uni (Nona Lee)', 'unranked');
foreach (array('Court A', 'Court B', 'Court C') as $c) { RSL_Courts::create_court($uni->id, $c, '18:00', '22:00'); }
update_option($fake_venues_option, array(intval($bundha->id), intval($uni->id)), false);

// A 12-week season of Sundays from the next Sunday: one bye, two finals weeks.
$first = date('Y-m-d', strtotime('next sunday', strtotime(current_time('Y-m-d'))));
RSL_Courts::save_settings($season, array('start' => $first, 'end' => date('Y-m-d', strtotime($first . ' +11 weeks')), 'weekday' => 0,
    'minutes' => array('ranked' => 60, 'unranked' => 50)));
RSL_Courts::generate_nights($season);
$nights = RSL_Courts::nights($season);
RSL_Courts::set_night_type($nights[5]->id, 'bye', 'Public holiday');
RSL_Courts::set_night_type($nights[10]->id, 'finals');
RSL_Courts::set_night_type($nights[11]->id, 'finals');
$b_courts = RSL_Courts::courts($bundha->id);
RSL_Courts::set_court_night($nights[2]->id, $b_courts[2]->id, false); // Court 3 unavailable one week

echo sprintf("Created %d teams and %d players (%d on teams, %d free agents) in %s.\n",
    count($team_ids), count($players), $p, count($players) - $p, RSL_Seasons::label($season));
