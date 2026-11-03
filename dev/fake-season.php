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
 * exactly that. Refuses to run outside WP-CLI. No mail leaves while it runs
 * (dev/lib-mail-stop.php: Ultimate Member's account deleted email off, and
 * the pretend mail it queued deleted at the end).
 */

if (!defined('WP_CLI') || !WP_CLI || (wp_get_environment_type() === 'production' && !getenv('RSL_DEV'))) {
    exit("Local copy only: run with wp eval-file, with RSL_DEV=1 set (docker exec -e RSL_DEV=1 ...).\n");
}

require_once ABSPATH . 'wp-admin/includes/user.php';
require_once __DIR__ . '/lib-profile.php';
require_once __DIR__ . '/lib-mail-stop.php';
global $wpdb;

$hold = rsl_dev_hold_mail('fake'); // released at the end (or on shutdown, if the run dies first)

$fake_option = 'rsl_fake_team_ids';
$fake_venues_option = 'rsl_fake_venue_ids';

$reset = function () use ($wpdb, $fake_option, $fake_venues_option) {
    $team_ids = array_map('intval', (array) get_option($fake_option, array()));
    foreach ($team_ids as $id) {
        $wpdb->query($wpdb->prepare("DELETE FROM " . RSL_Database::table('games') . " WHERE home_team_id = %d OR away_team_id = %d", $id, $id));
        $wpdb->delete(RSL_Database::table('requests'), array('team_id' => $id), array('%d'));
        $wpdb->delete(RSL_Database::table('ledger'), array('team_id' => $id), array('%d'));
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
    $users = array_map('intval', get_users(array('meta_key' => 'rsl_fake', 'meta_value' => '1', 'fields' => 'ID')));
    foreach ($users as $uid) {
        $wpdb->delete(RSL_Database::table('jobs'), array('user_id' => $uid), array('%d'));
        $wpdb->delete(RSL_Database::table('officials'), array('user_id' => $uid), array('%d'));
        $wpdb->delete(RSL_Database::table('team_members'), array('user_id' => $uid), array('%d'));
        $wpdb->delete(RSL_Database::table('players'), array('user_id' => $uid), array('%d'));
        $wpdb->delete(RSL_Database::table('regos'), array('user_id' => $uid), array('%d'));
    }
    rsl_dev_delete_users($users); // Ultimate Member's account deleted email off first
    return array(count($team_ids), count($users));
};

list($teams_removed, $users_removed) = $reset();
echo "Removed $teams_removed fake teams and $users_removed fake players.\n";
if (isset($args[0]) && $args[0] === 'reset') {
    rsl_dev_release_mail($hold);
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
        // Registering needs a club profile; adults, born 1978–2004 (not from
        // mt_rand, so the pretend league stays the same).
        rsl_dev_profile($uid, sprintf('%04d/%02d/%02d', 1978 + ($i * 7) % 27, ($i * 5) % 12 + 1, ($i * 11) % 28 + 1), $i % 2 ? 'Male' : 'Female');
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

// Fixtures for rounds 1–5 made by the real engine (RSL_Pairing) and
// published; results for rounds 1–4. Each team has a hidden "true"
// strength the scores are drawn from, so the ratings have something to find.
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
// Timed games played rally by rally: best of three, third set to 15 or 25,
// an hour on the clock (the score stands when time's up).
require_once __DIR__ . '/lib-sim.php';
$sim_sets = function ($home, $away) use ($true) {
    return rsl_dev_play_game($true[$home] - $true[$away], array('rallies' => mt_rand(95, 125)));
};
// Team payments: most teams pay for the season up front, every third team
// pays week by week. One weekly team stops paying after round 2 (so it
// owes more than $75 and drops out of the fixtures), another after round 4
// (owes one game: a warning, still fixtured).
$fee = RSL_Payments::settings()['game_fee'];
$team_pos = array_flip($team_ids);
$payer = function ($team_id, $k) {
    $m = RSL_Teams::members($team_id);
    return $m ? intval($m[$k % count($m)]->user_id) : 0;
};
foreach ($team_ids as $i => $tid) {
    if ($i % 3 !== 1) {
        RSL_Payments::record_payment($tid, 10 * $fee, $payer($tid, 0), 'Paid for the season');
    }
}
$games_made = 0;
$results_in = 0;
for ($round = 0; $round < 5; $round++) {
    $night = $nights[$round];
    foreach ($team_ids as $i => $tid) {
        if ($i % 3 === 1 && !($i === 4 && $round >= 2) && !($i === 7 && $round >= 4)) {
            // Weekly payers top up what they owe, just before the fixtures come out.
            $owe = RSL_Payments::owing($tid);
            RSL_Payments::record_payment($tid, max($fee, $owe + $fee), $payer($tid, $round + 1), 'Paid online');
        }
    }
    RSL_Ratings::flush();
    $made = RSL_Pairing::generate($night->id);
    if (is_wp_error($made)) {
        echo 'Round ' . ($round + 1) . ': ' . $made->get_error_message() . "\n";
        continue;
    }
    foreach ($made['notes'] as $note) {
        echo 'Round ' . ($round + 1) . ': ' . $note . "\n";
    }
    RSL_Fixtures::publish_night($night->id);
    $all = RSL_Fixtures::games_for_night($night->id);
    $games_made += count($all);
    if ($round >= 4) {
        continue; // next week: fixtures out, not played yet
    }
    $ranked_games = array_values(array_filter($all, function ($g) { return $g->division === 'ranked'; }));
    $unranked_games = array_values(array_filter($all, function ($g) { return $g->division === 'unranked'; }));
    foreach ($all as $g) {
        if ($round === 2 && $unranked_games && intval($g->id) === intval(end($unranked_games)->id)) {
            RSL_Fixtures::record_forfeit($g->id, $g->away_team_id); // one forfeit
        } elseif ($round === 3 && $ranked_games && intval($g->id) === intval(end($ranked_games)->id)) {
            continue; // one result still to come
        } else {
            RSL_Fixtures::record_result($g->id, $sim_sets(intval($g->home_team_id), intval($g->away_team_id)));
        }
        $results_in++;
    }
}
RSL_Ratings::flush();

// Captains' requests: a week off and a "two games please" for round 6,
// and a late "can't make it" for round 5 (admin-only email silenced).
add_filter('pre_wp_mail', '__return_true');
$cap = function ($team_id) { $c = RSL_Teams::captain($team_id); return $c ? intval($c->user_id) : 0; };
$round6 = $nights[6]; // nights[5] is the bye
RSL_Requests::request($ranked[2], $round6->id, 'off', $cap($ranked[2]));
RSL_Requests::request($ranked[5], $round6->id, 'double', $cap($ranked[5]));
foreach (RSL_Fixtures::games_for_night($nights[4]->id) as $g) {
    if ($g->division === 'unranked') {
        RSL_Requests::request(intval($g->home_team_id), $nights[4]->id, 'cant_make', $cap(intval($g->home_team_id)));
        break;
    }
}
remove_filter('pre_wp_mail', '__return_true');

// Officials: six approved refs (four free agents, two team players), one
// waiting for approval, two court supervisors (free agents, so they can
// stay at their venue all night). Past rounds refereed and
// supervised (for the pay report); round 5 part-claimed, some jobs open.
$ref_ids = array($players[140], $players[141], $players[142], $players[143], $players[3], $players[20]);
foreach ($ref_ids as $uid) { RSL_Officials::set_ref($uid, 'approved', 0); }
RSL_Officials::apply_ref($players[144]);
RSL_Officials::set_supervisor($players[145], true, 0);
RSL_Officials::set_supervisor($players[147], true, 0);
$jobs_made = 0;
for ($round = 0; $round < 5; $round++) {
    $night = $nights[$round];
    $games = RSL_Fixtures::games_for_night($night->id);
    $sup_done = array();
    foreach ($games as $gi => $g) {
        if ($round === 4 && $gi % 3 === 0) {
            continue; // leave some round 5 games needing a ref
        }
        foreach ($ref_ids as $k => $uid) {
            $r = RSL_Officials::claim_ref($g->id, $ref_ids[($gi + $k + $round) % count($ref_ids)], 1);
            if (!is_wp_error($r)) { $jobs_made++; break; }
        }
        if (empty($sup_done[$g->venue_id]) && ($round < 4 || $g->division === 'ranked')) {
            $r = RSL_Officials::claim_supervisor($night->id, $g->venue_id, $g->division === 'ranked' ? $players[145] : $players[147], 1);
            $sup_done[$g->venue_id] = true;
            if (!is_wp_error($r)) { $jobs_made++; }
        }
    }
}
// The refs saved those results on their scoreboards (so the results are
// theirs to correct, not locked as the league's).
$wpdb->query($wpdb->prepare("
    UPDATE " . RSL_Database::table('games') . " g
    JOIN " . RSL_Database::table('jobs') . " j ON j.game_id = g.id AND j.type = 'ref'
    SET g.result_by = j.user_id
    WHERE g.season = %s AND g.status = 'played'
", $season));

echo sprintf("Created %d teams and %d players (%d on teams, %d free agents) in %s.\n",
    count($team_ids), count($players), $p, count($players) - $p, RSL_Seasons::label($season));
echo sprintf("Fixtured %d games over rounds 1–5 (published), %d results in.\n", $games_made, $results_in);
echo sprintf("Officials: %d refs, 2 supervisors, %d jobs taken. Ref login to try: %s\n", count($ref_ids), $jobs_made, get_userdata($ref_ids[0])->user_login);
$blocked = array_filter($team_ids, function ($tid) { return RSL_Payments::blocked($tid); });
echo sprintf("Balances: %d teams owing, %d owing too much to be fixtured (%s).\n",
    count(array_filter($team_ids, function ($tid) { return RSL_Payments::balance($tid) < 0; })), count($blocked),
    implode(', ', array_map(function ($tid) { return RSL_Teams::get($tid)->name . ' owes $' . RSL_Payments::owing($tid); }, $blocked)));
rsl_dev_release_mail($hold); // the pretend mail queued above goes too
