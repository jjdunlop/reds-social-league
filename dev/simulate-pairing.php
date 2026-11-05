<?php
/**
 * DEV ONLY — plays many pretend Ranked seasons through the real pairing and
 * rating code (no database) to compare fixturing strategies.
 *
 *   wp eval-file dev/simulate-pairing.php [teams=14] [rounds=10] [runs=40]
 *
 * Each team has a hidden true strength; its captain's level guess is that
 * plus noise. Each round: refit ratings from results so far, pair, play
 * three sets drawn from the true strengths. Reports how close the games
 * were and how often teams met again.
 *
 *   wp eval-file dev/simulate-pairing.php courts [rounds=10] [runs=200] [seed=0]
 *
 * courts (0.18.2): who sits out when courts are short. Each season is a
 * Ranked division of 12 to 14 teams over 10 nights: 5 game slots on 30% of
 * nights, 6 on 30%, 8 (room for everyone, a court to spare) on the rest;
 * each team takes a week off on 7% of nights; three teams opt into
 * double-headers. The same season is played twice from the same teams,
 * strengths, slots and weeks off: once with the rule before 0.18.2 (the
 * odd team by a soft cost, 4 × (1 + nights sat out), +2 on the count for a
 * double-header team; then the dearest games dropped to fit the slots) and
 * once with the live rule (RSL_Pairing::pair_division() with the slots:
 * fewest nights sat out first, then cost). Both use the fixtures rating and
 * the link term, play games rally by rally (dev/lib-sim.php) and give the
 * odd team its fill game when one qualifies; spare-slot double-headers are
 * left out (they never change who sits out). A sit-out is a night a team
 * was available and had no game, as RSL_Pairing::context() counts it.
 */

if (!defined('WP_CLI') || !WP_CLI || (wp_get_environment_type() === 'production' && !getenv('RSL_DEV'))) {
    exit("Local copy only: run with wp eval-file, with RSL_DEV=1 set (docker exec -e RSL_DEV=1 ...).\n");
}

$opt = array('teams' => 14, 'rounds' => 10, 'runs' => in_array('courts', (array) $args, true) ? 200 : 40, 'seed' => 0);
foreach ((array) $args as $a) {
    if (preg_match('/^(teams|rounds|runs|seed)=(\d+)$/', $a, $m)) {
        $opt[$m[1]] = intval($m[2]);
    }
}

if (in_array('courts', (array) $args, true)) {
    require_once __DIR__ . '/lib-sim.php';
    $w = RSL_Pairing::weights();
    $levels = array(1200, 1350, 1500, 1650, 1800);
    $rules = array('Before 0.18.2: dearest games sit out' => 'old', 'Live: fewest nights sat out first' => 'new');
    $sum = array();
    foreach ($rules as $name => $rule) {
        $sum[$rule] = array('seasons' => 0, 'spread' => 0, 'worst' => 0, 'gap2' => 0, 'most' => 0, 'sat' => 0, 'team_seasons' => 0,
            'games' => 0, 'closeness' => 0.0, 'over' => 0, 'third' => 0, 'fills' => 0);
    }
    for ($run = 1; $run <= $opt['runs']; $run++) {
        $eff = $run + $opt['seed'];
        // The season: teams, strengths, captains' levels, double-header teams, slots and weeks off.
        mt_srand($eff * 7919 + 13);
        $n = mt_rand(12, 14);
        $true = array();
        $seeds = array();
        for ($t = 1; $t <= $n; $t++) {
            $z = (mt_rand() / mt_getrandmax() + mt_rand() / mt_getrandmax() + mt_rand() / mt_getrandmax() - 1.5) * 2;
            $true[$t] = 1500 + 180 * $z;
            $guess = $true[$t] + mt_rand(-150, 150);
            $best = $levels[0];
            foreach ($levels as $l) { if (abs($l - $guess) < abs($best - $guess)) { $best = $l; } }
            $seeds[$t] = $best;
        }
        $teams = range(1, $n);
        $opted_all = array();
        while (count($opted_all) < 3) { $opted_all[mt_rand(1, $n)] = true; }
        $room = array();
        $off = array();
        for ($round = 1; $round <= $opt['rounds']; $round++) {
            $x = mt_rand(1, 10);
            $room[$round] = $x <= 3 ? 5 : ($x <= 6 ? 6 : 8);
            foreach ($teams as $t) { if (mt_rand(1, 100) <= 7) { $off[$round][$t] = true; } }
        }
        foreach ($rules as $rule) {
            mt_srand($eff * 104729);
            $games = array();
            $meetings = array();
            $byes = array();
            $avail_all = array_fill_keys($teams, true);
            for ($round = 1; $round <= $opt['rounds']; $round++) {
                $hist = array_map(function ($g) use ($round) { return array($g[0], $g[1], $g[2], $round - 1 - $g[3]); }, $games);
                $ratings = array();
                foreach (RSL_Ratings::fit(RSL_Ratings::priors($seeds, 'fixtures'), $hist, RSL_Ratings::fit_opts('fixtures')) as $id => $r) {
                    $ratings[$id] = array('rating' => $r['rating'], 'sd' => $r['sd']);
                }
                $now = array();
                foreach ($meetings as $k => $mt) { $now[$k] = array('count' => $mt['count'], 'last' => $round - $mt['round']); }
                $by_round = array();
                foreach ($games as $g) { $by_round[$g[3]][] = array($g[0], $g[1]); }
                $link = RSL_Pairing::link($teams, $by_round, $round, $w);
                $ids = array();
                foreach ($teams as $t) {
                    if (isset($off[$round][$t])) { unset($avail_all[$t]); } else { $ids[] = $t; }
                }
                $opted = array_values(array_filter($ids, function ($t) use ($opted_all) { return isset($opted_all[$t]); }));
                $salt = "c$eff-$round";
                $tonight = array(); // list of array(a, b, pair_cost info)
                if ($rule === 'new') {
                    $p = RSL_Pairing::pair_division($ids, $ratings, $now, $byes, $w, $salt, $link, $room[$round], $opted);
                    $tonight = $p['pairs'];
                    if ($p['fill']) { $tonight[] = array($p['bye'], $p['fill'][0], $p['fill'][1]); $sum[$rule]['fills']++; }
                } else {
                    // a5101af's plan(): the odd team by a soft cost (+2 on the count for a double-header team),
                    // then trim(): the dearest games sit out; then the odd team's fill game if a slot is left.
                    $soft = $byes;
                    if (count($ids) % 2 === 1) { foreach ($opted as $o) { $soft[$o] = (isset($soft[$o]) ? $soft[$o] : 0) + 2; } }
                    $sorted = $ids;
                    usort($sorted, function ($x, $y) use ($ratings) { return $ratings[$y]['rating'] <=> $ratings[$x]['rating']; });
                    $info = array();
                    $m = RSL_Pairing::match($sorted, function ($a, $b) use (&$info, $ratings, $now, $soft, $w, $salt, $link) {
                        if ($a === 0 || $b === 0) {
                            $t = $a === 0 ? $b : $a;
                            return 4.0 * (1 + (isset($soft[$t]) ? $soft[$t] : 0)) + (crc32($salt . '|bye|' . $t) % 1000) / 100000; // the old 'bye' weight, 4
                        }
                        $c = RSL_Pairing::pair_cost($a, $b, $ratings, $now, $w, $salt, $link);
                        $info[RSL_Pairing::meeting_key($a, $b)] = $c;
                        return $c['cost'];
                    });
                    $pairs = array();
                    $bye = null;
                    foreach ($m['pairs'] as $pr) {
                        if ($pr[0] === 0 || $pr[1] === 0) { $bye = $pr[0] === 0 ? $pr[1] : $pr[0]; continue; }
                        $pairs[] = array($pr[0], $pr[1], $info[RSL_Pairing::meeting_key($pr[0], $pr[1])]);
                    }
                    usort($pairs, function ($x, $y) { return $x[2]['cost'] <=> $y[2]['cost']; });
                    $tonight = array_slice($pairs, 0, $room[$round]);
                    if ($bye !== null && count($tonight) < $room[$round]) {
                        $f = RSL_Pairing::fill_for($bye, $opted, $ratings, $now, $w, $salt);
                        if ($f) { $tonight[] = array($bye, $f[0], $f[1]); $sum[$rule]['fills']++; }
                    }
                }
                $in = array();
                foreach ($tonight as $g) {
                    list($a, $b, $c) = $g;
                    $games[] = array($a, $b, rsl_dev_play_game($true[$a] - $true[$b]), $round);
                    $key = RSL_Pairing::meeting_key($a, $b);
                    $meetings[$key] = array('count' => (isset($meetings[$key]) ? $meetings[$key]['count'] : 0) + 1, 'round' => $round);
                    $in[$a] = $in[$b] = true;
                    $sum[$rule]['games']++;
                    $sum[$rule]['closeness'] += $c['closeness'];
                    $sum[$rule]['over'] += 1 / (1 + pow(10, -abs($true[$a] - $true[$b]) / RSL_Ratings::SCALE)) > 0.80 ? 1 : 0;
                }
                foreach ($ids as $t) {
                    if (!isset($in[$t])) { $byes[$t] = (isset($byes[$t]) ? $byes[$t] : 0) + 1; }
                }
            }
            $s = &$sum[$rule];
            $s['seasons']++;
            foreach ($teams as $t) { $s['sat'] += isset($byes[$t]) ? $byes[$t] : 0; $s['team_seasons']++; }
            $s['most'] += max(array_map(function ($t) use ($byes) { return isset($byes[$t]) ? $byes[$t] : 0; }, $teams));
            $always = array_map(function ($t) use ($byes) { return isset($byes[$t]) ? $byes[$t] : 0; }, array_keys($avail_all));
            $spread = count($always) > 1 ? max($always) - min($always) : 0;
            $s['spread'] += $spread;
            $s['worst'] = max($s['worst'], $spread);
            $s['gap2'] += $spread >= 2 ? 1 : 0;
            foreach ($meetings as $mt) { $s['third'] += max(0, $mt['count'] - 2); }
            unset($s);
        }
    }
    echo sprintf("Courts short: 12-14 Ranked teams, %d nights (5 slots on 30%%, 6 on 30%%, 8 on 40%%), weeks off 7%%, 3 double-header teams; %d seasons from seed %d\n\n",
        $opt['rounds'], $opt['runs'], $opt['seed']);
    echo sprintf("%-38s %9s %8s %7s %9s %10s %10s %9s %8s %7s\n", 'Rule', 'Sat/team', 'Spread', 'Worst', '2+ gap', 'Most/team', 'Closeness', 'Fav>80%', '3rd+/s', 'Fills');
    echo str_repeat('-', 125) . "\n";
    foreach ($rules as $name => $rule) {
        $s = $sum[$rule];
        echo sprintf("%-38s %9.2f %8.2f %7d %8.1f%% %10.2f %10.3f %8.1f%% %8.2f %7.2f\n", $name,
            $s['sat'] / $s['team_seasons'], $s['spread'] / $s['seasons'], $s['worst'], 100 * $s['gap2'] / $s['seasons'], $s['most'] / $s['seasons'],
            $s['closeness'] / $s['games'], 100 * $s['over'] / $s['games'], $s['third'] / $s['seasons'], $s['fills'] / $s['seasons']);
    }
    echo "\nSat/team = sit-outs per team per season (nights available with no game). Spread = most minus fewest sit-outs\n";
    echo "among the teams available every night, per season (Worst = the largest in any season; 2+ gap = seasons where\n";
    echo "one of them sat out 2 or more nights more than another). Most/team = the season's highest sit-out count.\n";
    echo "Closeness = mean closeness cost of the games played, by the draw's own rating (0 = 50/50, 1 = 67/33).\n";
    echo "Fav>80% = games whose favourite had over 0.80 set chance by TRUE strength. 3rd+/s = meetings beyond the second\n";
    echo "per season. Fills = odd-team fill games per season.\n";
    return;
}

$default = RSL_Pairing::weights();
$variants = array(
    'Engine (closeness + rematch costs)' => array('weights' => $default),
    'Closeness only (no last-week repeat)' => array('weights' => array_merge($default, array('rematch' => 0, 'extra_meeting' => 0, 'two_weeks' => 0))),
    'Old idea: within 3 ladder places' => array('places' => true),
    'Variety only (round robin)' => array('weights' => array_merge($default, array('closeness' => 0))),
);

if (in_array('sweep', (array) $args, true)) {
    // Try different trade-offs for the engine itself.
    $variants = array();
    foreach (array(0.5, 1.0, 2.0) as $rematch) {
        foreach (array(6.0, 25.0) as $extra) {
            foreach (array(1.0, 3.0) as $two) {
                $variants["rematch $rematch, 3rd+ $extra, 2 wks $two"] = array('weights' => array_merge($default, array('rematch' => $rematch, 'extra_meeting' => $extra, 'two_weeks' => $two)));
            }
        }
    }
}

if (in_array('measures', (array) $args, true)) {
    // Same rematch rules, different ways of measuring "close".
    $variants = array(
        'Rating gap (points, via win chance)' => array('measure' => 'expected'),
        'Rating gap (points, straight)' => array('measure' => 'points'),
        'Ladder places apart' => array('measure' => 'places'),
        'Std devs apart (league spread)' => array('measure' => 'z'),
    );
}

$levels = array(1200, 1350, 1500, 1650, 1800);
$rows = array();
foreach ($variants as $name => $v) {
    $stats = array('games' => 0, 'margin' => 0.0, 'close' => 0, 'whitewash' => 0, 'third' => 0, 'max' => 0, 'distinct' => 0.0, 'corr' => 0.0, 'upsets' => 0);
    for ($run = 1; $run <= $opt['runs']; $run++) {
        mt_srand($run * 7919);
        $true = array();
        $seeds = array();
        for ($t = 1; $t <= $opt['teams']; $t++) {
            // Roughly normal true strengths around 1500.
            $z = (mt_rand() / mt_getrandmax() + mt_rand() / mt_getrandmax() + mt_rand() / mt_getrandmax() - 1.5) * 2;
            $true[$t] = 1500 + 180 * $z;
            if (in_array('lumpy', (array) $args, true)) {
                // A few ex-VVL teams well clear, a bunched middle, a couple of beginners.
                $true[$t] = $t <= 3 ? 1900 + 60 * $z : ($t > $opt['teams'] - 2 ? 1150 + 60 * $z : 1500 + 60 * $z);
            }
            $guess = $true[$t] + mt_rand(-150, 150);
            $best = $levels[0];
            foreach ($levels as $l) { if (abs($l - $guess) < abs($best - $guess)) { $best = $l; } }
            $seeds[$t] = $best;
        }
        $games = array();
        $meetings = array();
        for ($round = 1; $round <= $opt['rounds']; $round++) {
            $hist = array_map(function ($g) use ($round) { return array($g[0], $g[1], $g[2], $round - $g[3]); }, $games);
            $fit = RSL_Ratings::fit($seeds, $hist);
            $ratings = array();
            foreach ($fit as $id => $r) { $ratings[$id] = array('rating' => $r['rating'], 'sd' => $r['sd']); }
            $now = array();
            foreach ($meetings as $k => $mt) { $now[$k] = array('count' => $mt['count'], 'last' => $round - $mt['round']); }

            if (!empty($v['measure'])) {
                // Each measure scaled so "cost 1" means about the same gap in a typical league:
                // 120 rating points ≈ 3 ladder places ≈ 0.5 league standard deviations.
                $order = array_keys($ratings);
                usort($order, function ($x, $y) use ($ratings) { return $ratings[$y]['rating'] <=> $ratings[$x]['rating']; });
                $place = array_flip($order);
                $vals = array_map(function ($r) { return $r['rating']; }, $ratings);
                $mean = array_sum($vals) / count($vals);
                $spread = sqrt(array_sum(array_map(function ($x) use ($mean) { return ($x - $mean) * ($x - $mean); }, $vals)) / count($vals)) ?: 1;
                $measure = $v['measure'];
                $m = RSL_Pairing::match($order, function ($a, $b) use ($measure, $ratings, $place, $spread, $now, $default) {
                    if ($a === 0 || $b === 0) { return 4; }
                    $c = RSL_Pairing::pair_cost($a, $b, $measure === 'expected' ? $ratings : null, $now, $default);
                    $gap = abs($ratings[$a]['rating'] - $ratings[$b]['rating']);
                    if ($measure === 'points') { $c['cost'] += pow($gap / 120, 2); }
                    if ($measure === 'places') { $c['cost'] += pow(abs($place[$a] - $place[$b]) / 3, 2); }
                    if ($measure === 'z') { $c['cost'] += pow($gap / $spread / 0.5, 2); }
                    return $c['cost'];
                });
                $pairs = $m['pairs'];
            } elseif (!empty($v['places'])) {
                $order = array_keys($ratings);
                usort($order, function ($x, $y) use ($ratings) { return $ratings[$y]['rating'] <=> $ratings[$x]['rating']; });
                $place = array_flip($order);
                $m = RSL_Pairing::match($order, function ($a, $b) use ($place, $now) {
                    if ($a === 0 || $b === 0) { return 4; }
                    $gap = abs($place[$a] - $place[$b]);
                    $cost = $gap <= 3 ? 0 : pow($gap - 3, 2);
                    $key = RSL_Pairing::meeting_key($a, $b);
                    if (isset($now[$key])) {
                        $cost += $now[$key]['last'] === 1 ? 1000 : 0.5 * $now[$key]['count'];
                    }
                    return $cost;
                });
                $pairs = $m['pairs'];
            } else {
                $pairs = array_map(function ($p) { return array($p[0], $p[1]); }, RSL_Pairing::pair_division(array_keys($seeds), $ratings, $now, array(), $v['weights'], "sim$run-$round")['pairs']);
            }

            foreach ($pairs as $p) {
                list($a, $b) = $p;
                if ($a === 0 || $b === 0) { continue; }
                $pt = 1 / (1 + pow(10, ($true[$b] - $true[$a]) / 400));
                $stats['margin'] += 3 * abs(2 * $pt - 1);
                $sets = array();
                $won = 0;
                for ($s = 0; $s < 3; $s++) {
                    $a_wins = (mt_rand() / mt_getrandmax()) < $pt;
                    $won += $a_wins ? 1 : 0;
                    $sets[] = $a_wins ? array(25, mt_rand(15, 23)) : array(mt_rand(15, 23), 25);
                }
                $stats['games']++;
                $stats[$won === 0 || $won === 3 ? 'whitewash' : 'close']++;
                if (($won >= 2) !== ($true[$a] >= $true[$b])) { $stats['upsets']++; }
                $games[] = array($a, $b, $sets, $round);
                $key = RSL_Pairing::meeting_key($a, $b);
                if (!isset($meetings[$key])) { $meetings[$key] = array('count' => 0, 'round' => 0); }
                $meetings[$key]['count']++;
                $meetings[$key]['round'] = $round;
            }
        }
        $max = 0;
        $opponents = array();
        foreach ($meetings as $k => $mt) {
            $max = max($max, $mt['count']);
            $stats['third'] += max(0, $mt['count'] - 2);
            list($a, $b) = explode('-', $k);
            $opponents[$a][$b] = $opponents[$b][$a] = true;
        }
        $stats['max'] = max($stats['max'], $max);
        $stats['distinct'] += array_sum(array_map('count', $opponents)) / count($opponents);
        // How well the final ratings found the true order (Spearman).
        $hist = array_map(function ($g) use ($opt) { return array($g[0], $g[1], $g[2], $opt['rounds'] + 1 - $g[3]); }, $games);
        $fit = RSL_Ratings::fit($seeds, $hist);
        $rt = $true; arsort($rt); $rt = array_flip(array_keys($rt));
        $rf = array_map(function ($r) { return $r['rating']; }, $fit); arsort($rf); $rf = array_flip(array_keys($rf));
        $d2 = 0;
        foreach ($rt as $id => $pos) { $d2 += pow($pos - $rf[$id], 2); }
        $n = count($rt);
        $stats['corr'] += 1 - 6 * $d2 / ($n * ($n * $n - 1));
    }
    $runs = $opt['runs'];
    $rows[$name] = sprintf("%-38s %8.2f %8.0f%% %8.0f%% %8.0f%% %8.1f %10.2f %6d %9.2f",
        $name,
        $stats['margin'] / $stats['games'],
        100 * $stats['close'] / $stats['games'],
        100 * $stats['whitewash'] / $stats['games'],
        100 * $stats['upsets'] / $stats['games'],
        $stats['distinct'] / $runs,
        $stats['third'] / $runs,
        $stats['max'],
        $stats['corr'] / $runs);
}

echo sprintf("%d teams, %d rounds, %d simulated seasons each\n\n", $opt['teams'], $opt['rounds'], $opt['runs']);
echo sprintf("%-38s %8s %9s %9s %9s %8s %10s %6s %9s\n", 'Strategy', 'Exp.gap', '2-1 games', '3-0 games', 'Upsets', 'Opp.', '3rd+ meets', 'Max', 'Ratings');
echo str_repeat('-', 116) . "\n";
foreach ($rows as $r) { echo $r . "\n"; }
echo "\nExp.gap = expected set margin from TRUE strengths (0 = coin flip, 3 = certain 3-0)\n";
echo "Opp. = different opponents per team; 3rd+ meets = meetings beyond the second, per season; Max = most times any pair met\n";
echo "Ratings = how well final ratings match the true order (1 = perfectly)\n";
