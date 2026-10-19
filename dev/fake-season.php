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
        $wpdb->query($wpdb->prepare("DELETE FROM " . RSL_Database::table('games') . " WHERE home_team_id = %d OR away_team_id = %d", $id, $id));
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

// A 12-week season of Sundays, four of them already played: one bye, two
// finals weeks.
$today = current_time('Y-m-d');
$last_sunday = date('w', strtotime($today)) === '0' ? $today : date('Y-m-d', strtotime('last sunday', strtotime($today)));
$first = date('Y-m-d', strtotime($last_sunday . ' -3 weeks'));
$wpdb->query($wpdb->prepare("UPDATE " . RSL_Database::table('nights') . " SET published_at = NULL WHERE season = %s", $season));
RSL_Courts::save_settings($season, array('start' => $first, 'end' => date('Y-m-d', strtotime($first . ' +11 weeks')), 'weekday' => 0,
    'minutes' => array('ranked' => 60, 'unranked' => 50)));
RSL_Courts::generate_nights($season);
$nights = RSL_Courts::nights($season);
foreach ($nights as $n) {
    RSL_Courts::set_night_type($n->id, 'regular', ''); // nights kept from an earlier run start clean
}
RSL_Courts::set_night_type($nights[5]->id, 'bye', 'Public holiday');
RSL_Courts::set_night_type($nights[10]->id, 'finals');
RSL_Courts::set_night_type($nights[11]->id, 'finals');
$b_courts = RSL_Courts::courts($bundha->id);
RSL_Courts::set_court_night($nights[2]->id, $b_courts[2]->id, false); // Court 3 unavailable one week

// Fixtures for rounds 1–5 (published), results for rounds 1–4. Ranked is
// paired by last week's real ratings (a stand-in for the coming engine);
// Unranked is a plain round robin. Each team has a hidden "true" strength
// the scores are drawn from, so the ratings have something to find.
$teams = array();
foreach ($team_ids as $id) {
    $teams[$id] = RSL_Teams::get($id);
}
$ranked = array_values(array_filter($team_ids, function ($id) use ($teams) { return $teams[$id]->division === 'ranked'; }));
$unranked = array_values(array_filter($team_ids, function ($id) use ($teams) { return $teams[$id]->division === 'unranked'; }));
$true = array();
foreach ($team_ids as $id) {
    $true[$id] = RSL_Teams::level_seed_rating($teams[$id]->est_level) + mt_rand(-150, 150);
}
$sim_sets = function ($home, $away) use ($true) {
    $p = 1 / (1 + pow(10, ($true[$away] - $true[$home]) / 400));
    $gap = abs($p - 0.5);
    $sets = array();
    for ($s = 0; $s < 3; $s++) {
        $loser = max(9, 23 - mt_rand(0, (int) round(3 + $gap * 26)));
        $winner = 25;
        if ($gap < 0.15 && mt_rand(0, 7) === 0) {
            $loser = 24 + mt_rand(0, 3);
            $winner = $loser + 2;
        }
        $sets[] = (mt_rand() / mt_getrandmax()) < $p ? array($winner, $loser) : array($loser, $winner);
    }
    return $sets;
};
// Spread a night's games across its slots (by time, then court).
$place = function ($night, $division, $pairs) {
    $slots = array_values(array_filter(RSL_Courts::slots_for_night($night->id), function ($s) use ($division) { return $s['division'] === $division; }));
    $order = array_keys($slots);
    usort($order, function ($x, $y) use ($slots) { return array($slots[$x]['start'], $x) <=> array($slots[$y]['start'], $y); });
    $made = array();
    foreach (array_values($pairs) as $i => $pair) {
        $slot = $slots[$order[(int) floor($i * count($order) / count($pairs))]];
        $game = RSL_Fixtures::add_game($night->id, array('home' => $pair[0], 'away' => $pair[1], 'court_id' => $slot['court_id'], 'start' => $slot['start']));
        if (is_wp_error($game)) {
            echo 'Skipped a game: ' . $game->get_error_message() . "\n";
            continue;
        }
        $made[] = $game;
    }
    return $made;
};

$last_opponent = array();
$games_made = 0;
$results_in = 0;
for ($round = 0; $round < 5; $round++) {
    $night = $nights[$round];
    // Ranked: strongest-rated first, each paired with the next team it didn't play last week.
    if ($round === 0) {
        $order = array();
        foreach ($ranked as $id) {
            $order[$id] = RSL_Teams::level_seed_rating($teams[$id]->est_level) + mt_rand(-60, 60);
        }
    } else {
        RSL_Ratings::flush();
        $order = array();
        foreach (RSL_Ratings::compute($season, $nights[$round - 1]->night_date) as $id => $r) {
            $order[$id] = $r['rating'];
        }
    }
    arsort($order);
    $queue = array_keys($order);
    $pairs = array();
    while (count($queue) > 1) {
        $a = array_shift($queue);
        $pick = 0;
        foreach ($queue as $qi => $b) {
            if (!isset($last_opponent[$a]) || $last_opponent[$a] !== $b) {
                $pick = $qi;
                break;
            }
        }
        $b = $queue[$pick];
        array_splice($queue, $pick, 1);
        $pairs[] = mt_rand(0, 1) ? array($a, $b) : array($b, $a);
    }
    // Rotate who's on late.
    for ($i = count($pairs) - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        list($pairs[$i], $pairs[$j]) = array($pairs[$j], $pairs[$i]);
    }
    $ranked_games = $place($night, 'ranked', $pairs);

    // Unranked: round robin (circle method).
    $circle = $unranked;
    $fixed = array_shift($circle);
    for ($r = 0; $r < $round; $r++) {
        array_unshift($circle, array_pop($circle));
    }
    $circle = array_merge(array($fixed), $circle);
    $pairs = array();
    for ($i = 0; $i < intdiv(count($circle), 2); $i++) {
        $pairs[] = array($circle[$i], $circle[count($circle) - 1 - $i]);
    }
    $unranked_games = $place($night, 'unranked', $pairs);

    RSL_Fixtures::publish_night($night->id);
    $all = array_merge($ranked_games, $unranked_games);
    $games_made += count($all);
    foreach ($all as $gi => $g) {
        $last_opponent[intval($g->home_team_id)] = intval($g->away_team_id);
        $last_opponent[intval($g->away_team_id)] = intval($g->home_team_id);
        if ($round >= 4) {
            continue; // next week: fixture out, not played yet
        }
        if ($round === 2 && $g->division === 'unranked' && $gi === count($all) - 1) {
            RSL_Fixtures::record_forfeit($g->id, $g->away_team_id); // one forfeit
        } elseif ($round === 3 && $g->division === 'ranked' && $gi === count($ranked_games) - 1) {
            continue; // one result still to come
        } else {
            RSL_Fixtures::record_result($g->id, $sim_sets(intval($g->home_team_id), intval($g->away_team_id)));
        }
        $results_in++;
    }
}
RSL_Ratings::flush();

echo sprintf("Created %d teams and %d players (%d on teams, %d free agents) in %s.\n",
    count($team_ids), count($players), $p, count($players) - $p, RSL_Seasons::label($season));
echo sprintf("Fixtured %d games over rounds 1–5 (published), %d results in.\n", $games_made, $results_in);
