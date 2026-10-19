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

$reset = function () use ($wpdb, $fake_option) {
    $team_ids = array_map('intval', (array) get_option($fake_option, array()));
    foreach ($team_ids as $id) {
        $wpdb->delete(RSL_Database::table('team_members'), array('team_id' => $id), array('%d'));
        $wpdb->delete(RSL_Database::table('teams'), array('id' => $id), array('%d'));
    }
    delete_option($fake_option);
    $users = get_users(array('meta_key' => 'rsl_fake', 'meta_value' => '1', 'fields' => 'ID'));
    foreach ($users as $uid) {
        $wpdb->delete(RSL_Database::table('team_members'), array('user_id' => $uid), array('%d'));
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
        $players[] = $uid;
    }
}

$team_ids = array();
$p = 0;
foreach ($names as $n => $name) {
    $team = RSL_Teams::create($season, array(
        'name' => $name,
        'division' => $n < 14 ? 'ranked' : 'unranked',
        'colour' => $colours[$n % count($colours)],
    ));
    if (is_wp_error($team)) {
        echo "Skipped $name: " . $team->get_error_message() . "\n";
        continue;
    }
    $team_ids[] = intval($team->id);
    $size = 6 + mt_rand(0, 3);
    for ($k = 0; $k < $size && $p < count($players) - 10; $k++) { // keep 10 as free agents
        RSL_Teams::add_member($team->id, $players[$p++], $k === 0 ? 'captain' : 'player');
    }
}
update_option($fake_option, $team_ids, false);

echo sprintf("Created %d teams and %d players (%d on teams, %d free agents) in %s.\n",
    count($team_ids), count($players), $p, count($players) - $p, RSL_Seasons::label($season));
