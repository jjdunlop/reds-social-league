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
 * Then the two ratings (0.18.1). Each season is played twice from the same
 * teams, true strengths and captains' (noisy) levels, as the draw differs:
 * - the draw before 0.18.1 (pairing by the seeded fixtures rating, no link
 *   term), with the LADDER scored two ways on its games: seeded by the
 *   levels (one rating, 0.18.0) and from a common start with the fixtures'
 *   prior sd 200 (the first 0.18.1 draft);
 * - the live draw (the same seeded rating plus RSL_Pairing::link()), with
 *   the live ladder: every team from the frozen start, prior sd
 *   LADDER_PRIOR_SD (RSL_Ratings::priors(..., 'ladder', start) and
 *   fit_opts('ladder')).
 * And the seed influence: the same results with one team's level 200
 * points higher, and how much of that 200 each rating still shows after 0,
 * 3 and 9 games.
 *
 *   wp eval-file dev/simulate-ratings.php [teams=14] [rounds=10] [runs=40] [noise=150] [seed=0] [age=1] [lumpy] [tune] [ladder]
 *
 * noise: captains' guesses are the true strength +/- up to this many
 * points, then rounded to the nearest level. seed: season k uses the
 * random seed of season k + seed (fresh seasons). age: weeks added to how
 * old every game is (1: last week's games are a week old when pairing and
 * the night just played a week old when scoring, as before 0.18.1; 0: the
 * latest night is 0 weeks old, as RSL_Ratings::compute() counts). ladder:
 * only the two-ratings tables (skips the model comparison). The README's
 * 0.18.1 figures: ladder rounds=9 runs=600 seed=3000 age=0.
 */

if (!defined('WP_CLI') || !WP_CLI || (wp_get_environment_type() === 'production' && !getenv('RSL_DEV'))) {
    exit("Local copy only: run with wp eval-file, with RSL_DEV=1 set (docker exec -e RSL_DEV=1 ...).\n");
}
require_once __DIR__ . '/lib-sim.php';

$opt = array('teams' => 14, 'rounds' => 10, 'runs' => 40, 'noise' => 150, 'seed' => 0, 'age' => 1);
foreach ((array) $args as $a) {
    if (preg_match('/^(teams|rounds|runs|noise|seed|age)=(\d+)$/', $a, $m)) {
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
$ladder_only = in_array('ladder', (array) $args, true);
if ($ladder_only) {
    $models = array();
}
$name_width = $models ? max(36, max(array_map('strlen', array_keys($models))) + 1) : 36;
$checkpoints = array_values(array_unique(array(2, 4, 6, $opt['rounds'])));
$score = array();
// The ladders. Fixtures are always made by the seeded rating; the first two ladders are scored on the
// games of the draw before 0.18.1, the live one on the live draw's (the same seeded rating plus the link term).
$ladder_names = array(
    'seeded' => 'Seeded by captain levels (0.18.0)',
    'common' => 'Common start, prior sd ' . RSL_Ratings::PRIOR_SD . ' (0.18.1 draft)',
    'results' => 'Results only, prior sd ' . RSL_Ratings::LADDER_PRIOR_SD . ' (0.18.1)',
);
$ladder_draw = array('seeded' => 'before', 'common' => 'before', 'results' => 'live');
$draw_names = array('before' => 'before 0.18.1', 'live' => 'live (link)');
$ladder_score = array();
// How even the games are (from the true strengths): the favourite's chance of winning a set.
$even = array('before' => array('over' => 0, 'even' => 0, 'games' => 0), 'live' => array('over' => 0, 'even' => 0, 'games' => 0));
// Seed influence: one team's level 200 higher, the same results; after this many of its games.
$seed_bump = 200;
$seed_games = array_values(array_unique(array(0, min(3, $opt['rounds']), min(9, $opt['rounds']))));
$seed_score = array();
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

// One season from run $run's seed: true strengths and captains' levels, then every round rated (the seeded
// fixtures rating), paired and played rally by rally. $link: the live draw (RSL_Pairing::link()) or the draw
// before it. Both draws start from the same teams, strengths and levels; their games differ once a pairing
// does. Returns array(true strengths, level seeds, games: list of array(home, away, sets, round)).
$play = function ($run, $link) use ($opt, $lumpy, $levels, &$shape, &$even) {
    $eff = $run + $opt['seed'];
    mt_srand($eff * 104729);
    $true = array();
    $seeds = array();
    for ($t = 1; $t <= $opt['teams']; $t++) {
        $z = (mt_rand() / mt_getrandmax() + mt_rand() / mt_getrandmax() + mt_rand() / mt_getrandmax() - 1.5) * 2;
        $true[$t] = $lumpy ? ($t <= 3 ? 1900 + 60 * $z : ($t > $opt['teams'] - 2 ? 1150 + 60 * $z : 1500 + 60 * $z)) : 1500 + 180 * $z;
        $guess = $true[$t] + mt_rand(-$opt['noise'], $opt['noise']);
        $best = $levels[0];
        foreach ($levels as $l) { if (abs($l - $guess) < abs($best - $guess)) { $best = $l; } }
        $seeds[$t] = $best;
    }
    $w = RSL_Pairing::weights();
    $which = $link ? 'live' : 'before';
    $games = array();
    $meetings = array();
    $byes = array();
    for ($round = 1; $round <= $opt['rounds']; $round++) {
        $hist = array_map(function ($g) use ($round, $opt) { return array($g[0], $g[1], $g[2], $round - 1 - $g[3] + $opt['age']); }, $games);
        $ratings = array();
        foreach (RSL_Ratings::fit(RSL_Ratings::priors($seeds, 'fixtures'), $hist, RSL_Ratings::fit_opts('fixtures')) as $id => $r) {
            $ratings[$id] = array('rating' => $r['rating'], 'sd' => $r['sd']);
        }
        $now = array();
        foreach ($meetings as $k => $mt) { $now[$k] = array('count' => $mt['count'], 'last' => $round - $mt['round']); }
        $lk = null;
        if ($link) {
            $by_round = array();
            foreach ($games as $g) { $by_round[$g[3]][] = array($g[0], $g[1]); }
            $lk = RSL_Pairing::link(array_keys($seeds), $by_round, $round, $w);
        }
        $pairs = RSL_Pairing::pair_division(array_keys($seeds), $ratings, $now, $byes, $w, "r$eff-$round", $lk)['pairs'];
        $in = array();
        foreach ($pairs as $p) {
            $sets = rsl_dev_play_game($true[$p[0]] - $true[$p[1]]);
            $games[] = array($p[0], $p[1], $sets, $round);
            $key = RSL_Pairing::meeting_key($p[0], $p[1]);
            $meetings[$key] = array('count' => (isset($meetings[$key]) ? $meetings[$key]['count'] : 0) + 1, 'round' => $round);
            $in[$p[0]] = $in[$p[1]] = true;
            $fav = 1 / (1 + pow(10, -abs($true[$p[0]] - $true[$p[1]]) / RSL_Ratings::SCALE));
            $even[$which]['games']++;
            $even[$which]['over'] += $fav > 0.80 ? 1 : 0;
            $even[$which]['even'] += $fav <= 0.65 ? 1 : 0;
            if ($link) {
                continue;
            }
            $shape['games']++;
            $shape['sets'] += count($sets);
            $won = 0;
            foreach ($sets as $i => $s) {
                $won += $s[0] > $s[1] ? 1 : -1;
                if (!RSL_Ratings::set_finished($s, $i)) { $shape['cut']++; }
            }
            if ($won === 0) { $shape['draws']++; }
        }
        foreach (array_keys($seeds) as $t) {
            if (!isset($in[$t])) { $byes[$t] = (isset($byes[$t]) ? $byes[$t] : 0) + 1; }
        }
    }
    return array($true, $seeds, $games);
};
// A season's games up to round $upto, weeks ago counted from it (plus age).
$history = function ($games, $upto, $age) {
    $hist = array();
    foreach ($games as $g) {
        if ($g[3] <= $upto) { $hist[] = array($g[0], $g[1], $g[2], $upto - $g[3] + $age); }
    }
    return $hist;
};
$last = end($checkpoints);

for ($run = 1; $run <= $opt['runs']; $run++) {
    list($true, $seeds, $games) = $play($run, false);
    list(, , $live_games) = $play($run, true);
    // The ladder's frozen start: the teams with a game on the first night (paired by level alone in both draws).
    $first = array();
    foreach ($games as $g) {
        if ($g[3] === 1) { $first[] = $g[0]; $first[] = $g[1]; }
    }
    $start = RSL_Ratings::frozen_start($seeds, $first);
    foreach ($checkpoints as $round) {
        $hist = $history($games, $round, $opt['age']);
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
        // The ladders: seeded and from a common start (prior sd 200) on the games of the draw before
        // 0.18.1; the live ladder (frozen start, its own prior and provisional line) on the live draw's.
        $fits = array(
            'seeded' => RSL_Ratings::fit(RSL_Ratings::priors($seeds, 'fixtures'), $hist, RSL_Ratings::fit_opts('fixtures')),
            'common' => RSL_Ratings::fit(RSL_Ratings::priors($seeds, 'ladder', $start), $hist),
            'results' => RSL_Ratings::fit(RSL_Ratings::priors($seeds, 'ladder', $start), $history($live_games, $round, $opt['age']), RSL_Ratings::fit_opts('ladder')),
        );
        foreach ($fits as $which => $fit) {
            $vals = array_map(function ($r) { return $r['rating']; }, $fit);
            $ladder_score[$which][$round]['rho'][] = $spearman($true, $vals);
            $off = (array_sum($vals) - array_sum($true)) / count($vals);
            $err = 0;
            foreach ($vals as $id => $v) { $err += abs($v - $off - $true[$id]); }
            $ladder_score[$which][$round]['err'][] = $err / count($vals);
            if ($round === $last) {
                // Finals seeding: how many of the true top 4 are in the ladder's top 4.
                $tt = $true; arsort($tt);
                $ll = $vals; arsort($ll);
                $ladder_score[$which]['top4'][] = count(array_intersect(array_slice(array_keys($tt), 0, 4), array_slice(array_keys($ll), 0, 4))) / 4;
            }
        }
    }

    // Seed influence on this season's games (the live draw): teams 1-3 each in turn with their
    // level $seed_bump higher from the start, everything else (the games, every result) the same.
    // Weeks ago counted as compute() does (the latest night is 0).
    $games = $live_games;
    $played_by = array();
    foreach ($games as $g) {
        foreach (array($g[0], $g[1]) as $t) { $played_by[$t][] = $g[3]; }
    }
    foreach (array(1, 2, 3) as $t) {
        foreach ($seed_games as $k) {
            if ($k > 0 && (!isset($played_by[$t]) || count($played_by[$t]) < $k)) {
                continue;
            }
            $upto = $k === 0 ? 0 : $played_by[$t][$k - 1]; // the round of its k-th game
            $hist = array();
            foreach ($games as $g) {
                if ($g[3] <= $upto) { $hist[] = array($g[0], $g[1], $g[2], $upto - $g[3]); }
            }
            $bumped = $seeds;
            $bumped[$t] += $seed_bump;
            foreach (array('fixtures', 'ladder') as $kind) {
                // The ladder's start is frozen from the first night's teams' levels, so a level different
                // from the start moves it; a level edited after the first night moves nothing.
                $a = RSL_Ratings::fit(RSL_Ratings::priors($seeds, $kind, RSL_Ratings::frozen_start($seeds, $first)), $hist, RSL_Ratings::fit_opts($kind));
                $b = RSL_Ratings::fit(RSL_Ratings::priors($bumped, $kind, RSL_Ratings::frozen_start($bumped, $first)), $hist, RSL_Ratings::fit_opts($kind));
                $gap = $b[$t]['rating'] - $a[$t]['rating'];
                // ...and against the rest of the league (its gap less the others' average move).
                $others = 0;
                foreach ($a as $id => $r) {
                    if ($id !== $t) { $others += $b[$id]['rating'] - $r['rating']; }
                }
                $seed_score[$kind][$k]['gap'][] = $gap;
                $seed_score[$kind][$k]['rel'][] = $gap - $others / (count($a) - 1);
            }
        }
    }
}

$avg = function ($xs) { return array_sum($xs) / count($xs); };
$last = end($checkpoints);
printf("%d teams, %d rounds, %d seasons%s%s, captains' levels from the true strength +/- %d, games %d week%s older. Per game: %.2f sets, %.0f%% draws (1–1), %.2f sets cut short by time\n\n",
    $opt['teams'], $opt['rounds'], $opt['runs'], $opt['seed'] ? ' from seed ' . $opt['seed'] : '', $lumpy ? ' (lumpy league)' : '', $opt['noise'], $opt['age'], $opt['age'] === 1 ? '' : 's',
    $shape['sets'] / $shape['games'], 100 * $shape['draws'] / $shape['games'], $shape['cut'] / $shape['games']);
if ($score) {
    printf("%-{$name_width}s", 'Model — order found (1 = perfect)');
    foreach ($checkpoints as $c) { printf(" %9s", "after R$c"); }
    printf(" %12s %12s\n", "avg error R$last", "honesty R$last");
    echo str_repeat('-', $name_width + 10 * count($checkpoints) + 26) . "\n";
    foreach ($score as $name => $by) {
        printf("%-{$name_width}s", $name);
        foreach ($checkpoints as $c) { printf(" %9.3f", $avg($by[$c]['rho'])); }
        printf(" %12.0f %12.2f\n", $avg($by[$last]['err']), $avg($by[$last]['z']));
    }
    echo "\navg error = rating points off the truth; honesty = typical error ÷ claimed uncertainty (1.0 = honest, >1 = overconfident)\n\n";
}

// The two ratings (0.18.1).
$lw = 44;
printf("LADDER accuracy — fixtures made by the seeded rating; rank correlation with the true strengths (1 = perfect)%s\n",
    $opt['seed'] ? ', seasons ' . ($opt['seed'] + 1) . ' to ' . ($opt['seed'] + $opt['runs']) : '');
printf("%-{$lw}s %-14s", 'Ladder', 'Draw');
foreach ($checkpoints as $c) { printf(" %9s", "after R$c"); }
printf(" %12s %9s\n", "avg error R$last", "top 4");
echo str_repeat('-', $lw + 15 + 10 * count($checkpoints) + 23) . "\n";
foreach ($ladder_names as $which => $name) {
    printf("%-{$lw}s %-14s", $name, $draw_names[$ladder_draw[$which]]);
    foreach ($checkpoints as $c) { printf(" %9.3f", $avg($ladder_score[$which][$c]['rho'])); }
    printf(" %12.1f %9.3f\n", $avg($ladder_score[$which][$last]['err']), $avg($ladder_score[$which]['top4']));
}
foreach (array('common', 'seeded') as $base) {
    printf("%-{$lw}s %-14s", 'Difference (0.18.1 − ' . ($base === 'common' ? 'draft' : '0.18.0') . ')', '');
    foreach ($checkpoints as $c) { printf(" %+9.3f", $avg($ladder_score['results'][$c]['rho']) - $avg($ladder_score[$base][$c]['rho'])); }
    printf(" %+12.1f %+9.3f\n", $avg($ladder_score['results'][$last]['err']) - $avg($ladder_score[$base][$last]['err']),
        $avg($ladder_score['results']['top4']) - $avg($ladder_score[$base]['top4']));
}
echo "top 4 = share of the true top 4 in the ladder's top 4 after the last round (finals seeding).\n";
echo "\nGAMES — how even, from the true strengths (the favourite's chance of winning a set)\n";
foreach ($even as $which => $e) {
    printf("Draw %-14s favourite above 0.80 in %.1f%% of games, both inside 0.35–0.65 in %.1f%%\n", $draw_names[$which], 100 * $e['over'] / max(1, $e['games']), 100 * $e['even'] / max(1, $e['games']));
}

printf("\nSEED INFLUENCE — the same results, one team's level %d higher (teams 1-3 of every season, %d cases)\n", $seed_bump, count($seed_score['fixtures'][0]['gap']));
printf("%-14s %22s %22s %22s %22s\n", 'Team\'s games', 'Fixtures: its rating', 'Fixtures: vs the rest', 'Ladder: its rating', 'Ladder: vs the rest');
echo str_repeat('-', 14 + 4 * 23) . "\n";
foreach ($seed_games as $k) {
    if (empty($seed_score['fixtures'][$k])) {
        continue;
    }
    printf("%-14s", "after $k");
    foreach (array('fixtures', 'ladder') as $kind) {
        foreach (array('gap', 'rel') as $m) {
            $v = $avg($seed_score[$kind][$k][$m]);
            $v = abs($v) < 0.05 ? 0.0 : $v; // no -0.0 from rounding noise
            printf(" %22s", sprintf('%+.1f (%.0f%%)', $v, 100 * $v / $seed_bump));
        }
    }
    echo "\n";
}
echo "\nits rating = how far the team's own rating moves; vs the rest = that less the other teams' average move (its place against them).\n"
    . "The ladder's own move is its start's (the mean seed of the first night's teams, rounded to 10): every team moves by exactly that,\n"
    . "so the order never does. A level edited after the first night moves nothing at all: the start is frozen then.\n";
