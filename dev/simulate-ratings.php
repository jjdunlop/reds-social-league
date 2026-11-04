<?php
/**
 * DEV ONLY — how well do different rating models find the true order when
 * games are timed (sets cut off by the hour, third sets to 15 or 25, 1–1
 * draws)? Plays pretend seasons rally by rally (dev/lib-sim.php), pairs
 * them with the real engine, then scores each model on the SAME games.
 * The set-win bonus rules compared: every set whole, the live rule (a
 * finished set whole, a cut-short set leader / target) and the old one
 * before 0.18.1 (points played / 40).
 *
 *   wp eval-file dev/simulate-ratings.php [teams=14] [rounds=10] [runs=40] [lumpy]
 */

if (!defined('WP_CLI') || !WP_CLI || (wp_get_environment_type() === 'production' && !getenv('RSL_DEV'))) {
    exit("Local copy only: run with wp eval-file, with RSL_DEV=1 set (docker exec -e RSL_DEV=1 ...).\n");
}
require_once __DIR__ . '/lib-sim.php';

$opt = array('teams' => 14, 'rounds' => 10, 'runs' => 40);
foreach ((array) $args as $a) {
    if (preg_match('/^(teams|rounds|runs)=(\d+)$/', $a, $m)) {
        $opt[$m[1]] = intval($m[2]);
    }
}
$lumpy = in_array('lumpy', (array) $args, true);
// Set-win bonus rules (fit()'s set_share option). With none given, fit() uses
// the live rule, RSL_Ratings::set_share(): a finished set counts whole, a set
// the clock cut short counts leader's points / target (25; 15 for the third).
$every_set_whole = function () { return 1; };
$old_40_points = function ($s) { return min(1, ($s[0] + $s[1]) / 40); }; // before 0.18.1: a set needed 40 points in all to count whole
$live = array('set_weight' => RSL_Ratings::SET_WEIGHT, 'point_weight' => RSL_Ratings::POINT_WEIGHT);
$live_name = sprintf('Live: points %s + set bonus %s', RSL_Ratings::POINT_WEIGHT, RSL_Ratings::SET_WEIGHT);
$models = array(
    'Sets only (every set whole)' => array('set_weight' => 1, 'point_weight' => 0, 'set_share' => $every_set_whole),
    'Sets only (cut short: leader / target)' => array('set_weight' => 1, 'point_weight' => 0),
    'Sets only (old: points / 40)' => array('set_weight' => 1, 'point_weight' => 0, 'set_share' => $old_40_points),
    'Points only' => array('set_weight' => 0, 'point_weight' => 0.3),
    'Sets + points (0.15)' => array('set_weight' => 1, 'point_weight' => 0.15),
    'Sets + points (0.3)' => array('set_weight' => 1, 'point_weight' => 0.3),
    'Sets + points (0.6)' => array('set_weight' => 1, 'point_weight' => 0.6),
    $live_name => $live,
    $live_name . ', old 40-point rule' => $live + array('set_share' => $old_40_points),
);
if (in_array('tune', (array) $args, true)) {
    $models = array(
        'Sets only (every set whole)' => array('set_weight' => 1, 'point_weight' => 0, 'set_share' => $every_set_whole),
        'Points only (0.3)' => array('set_weight' => 0, 'point_weight' => 0.3),
        'Points only (0.6)' => array('set_weight' => 0, 'point_weight' => 0.6),
        'Points only (1.0)' => array('set_weight' => 0, 'point_weight' => 1.0),
        'Points (0.6) + small set bonus (0.25)' => array('set_weight' => 0.25, 'point_weight' => 0.6),
        'Points (1.0) + small set bonus (0.25)' => array('set_weight' => 0.25, 'point_weight' => 1.0),
        'Points (0.3) + small set bonus (0.25)' => array('set_weight' => 0.25, 'point_weight' => 0.3),
        'Points (0.3) + set bonus (0.5)' => array('set_weight' => 0.5, 'point_weight' => 0.3),
        'Points (0.3) + set bonus (0.25), old 40-point rule' => array('set_weight' => 0.25, 'point_weight' => 0.3, 'set_share' => $old_40_points),
    );
}
$name_width = max(36, max(array_map('strlen', array_keys($models))) + 1);
$checkpoints = array(2, 4, 6, $opt['rounds']);
$score = array();
$shape = array('sets' => 0, 'draws' => 0, 'cut' => 0, 'games' => 0);
$spearman = function ($true, $fit) {
    $rt = $true; arsort($rt); $rt = array_flip(array_keys($rt));
    $rf = $fit; arsort($rf); $rf = array_flip(array_keys($rf));
    $d2 = 0;
    foreach ($rt as $id => $pos) { $d2 += pow($pos - $rf[$id], 2); }
    $n = count($rt);
    return 1 - 6 * $d2 / ($n * ($n * $n - 1));
};
$levels = array(1200, 1350, 1500, 1650, 1800);

for ($run = 1; $run <= $opt['runs']; $run++) {
    mt_srand($run * 104729);
    $true = array();
    $seeds = array();
    for ($t = 1; $t <= $opt['teams']; $t++) {
        $z = (mt_rand() / mt_getrandmax() + mt_rand() / mt_getrandmax() + mt_rand() / mt_getrandmax() - 1.5) * 2;
        $true[$t] = $lumpy ? ($t <= 3 ? 1900 + 60 * $z : ($t > $opt['teams'] - 2 ? 1150 + 60 * $z : 1500 + 60 * $z)) : 1500 + 180 * $z;
        $guess = $true[$t] + mt_rand(-150, 150);
        $best = $levels[0];
        foreach ($levels as $l) { if (abs($l - $guess) < abs($best - $guess)) { $best = $l; } }
        $seeds[$t] = $best;
    }
    $games = array();
    $meetings = array();
    for ($round = 1; $round <= $opt['rounds']; $round++) {
        $hist = array_map(function ($g) use ($round) { return array($g[0], $g[1], $g[2], $round - $g[3]); }, $games);
        $ratings = array();
        foreach (RSL_Ratings::fit($seeds, $hist) as $id => $r) { $ratings[$id] = array('rating' => $r['rating'], 'sd' => $r['sd']); }
        $now = array();
        foreach ($meetings as $k => $mt) { $now[$k] = array('count' => $mt['count'], 'last' => $round - $mt['round']); }
        $pairs = RSL_Pairing::pair_division(array_keys($seeds), $ratings, $now, array(), RSL_Pairing::weights(), "r$run-$round")['pairs'];
        foreach ($pairs as $p) {
            $sets = rsl_dev_play_game($true[$p[0]] - $true[$p[1]]);
            $games[] = array($p[0], $p[1], $sets, $round);
            $key = RSL_Pairing::meeting_key($p[0], $p[1]);
            $meetings[$key] = array('count' => (isset($meetings[$key]) ? $meetings[$key]['count'] : 0) + 1, 'round' => $round);
            $shape['games']++;
            $shape['sets'] += count($sets);
            $won = 0;
            foreach ($sets as $i => $s) {
                $won += $s[0] > $s[1] ? 1 : -1;
                if (!RSL_Ratings::set_finished($s, $i)) { $shape['cut']++; }
            }
            if ($won === 0) { $shape['draws']++; }
        }
        if (!in_array($round, $checkpoints, true)) {
            continue;
        }
        $hist = array_map(function ($g) use ($round) { return array($g[0], $g[1], $g[2], $round + 1 - $g[3]); }, $games);
        foreach ($models as $name => $m) {
            $fit = RSL_Ratings::fit($seeds, $hist, $m);
            $vals = array_map(function ($r) { return $r['rating']; }, $fit);
            $score[$name][$round]['rho'][] = $spearman($true, $vals);
            // Calibration: are the uncertainties honest? (z-scores after removing the overall offset)
            $off = (array_sum($vals) - array_sum($true)) / count($vals);
            $zs = array();
            $err = 0;
            foreach ($fit as $id => $r) {
                $e = $r['rating'] - $off - $true[$id];
                $err += abs($e);
                $zs[] = $e / $r['sd'];
            }
            $score[$name][$round]['err'][] = $err / count($fit);
            $score[$name][$round]['z'][] = sqrt(array_sum(array_map(function ($z) { return $z * $z; }, $zs)) / count($zs));
        }
    }
}

$avg = function ($xs) { return array_sum($xs) / count($xs); };
printf("%d teams, %d rounds, %d seasons%s. Per game: %.2f sets, %.0f%% draws (1–1), %.2f sets cut short by time\n\n",
    $opt['teams'], $opt['rounds'], $opt['runs'], $lumpy ? ' (lumpy league)' : '', $shape['sets'] / $shape['games'], 100 * $shape['draws'] / $shape['games'], $shape['cut'] / $shape['games']);
printf("%-{$name_width}s", 'Model — order found (1 = perfect)');
foreach ($checkpoints as $c) { printf(" %9s", "after R$c"); }
printf(" %12s %12s\n", "avg error R$c", "honesty R$c");
echo str_repeat('-', $name_width + 10 * count($checkpoints) + 26) . "\n";
foreach ($score as $name => $by) {
    printf("%-{$name_width}s", $name);
    foreach ($checkpoints as $c) { printf(" %9.3f", $avg($by[$c]['rho'])); }
    $last = end($checkpoints);
    printf(" %12.0f %12.2f\n", $avg($by[$last]['err']), $avg($by[$last]['z']));
}
echo "\navg error = rating points off the truth; honesty = typical error ÷ claimed uncertainty (1.0 = honest, >1 = overconfident)\n";
