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
 */

if (!defined('WP_CLI') || !WP_CLI) {
    exit("Run with wp eval-file.\n");
}

$opt = array('teams' => 14, 'rounds' => 10, 'runs' => 40);
foreach ((array) $args as $a) {
    if (preg_match('/^(teams|rounds|runs)=(\d+)$/', $a, $m)) {
        $opt[$m[1]] = intval($m[2]);
    }
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
