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
 *   wp eval-file dev/simulate-pairing.php courts [rounds=10] [runs=200] [seed=0] [slots=some|every|roomy|tight] [outliers=0] [size=N] [off=7] [join=6] [away=4] [level=0,0.5]
 *
 * courts (0.18.2): who sits out when courts are short. Each season is a
 * Ranked division of 12 to 14 teams over 10 nights: 5 game slots on 30% of
 * nights, 6 on 30%, 8 (room for everyone, a court to spare) on the rest
 * (slots=every: 5 or 6 every night; slots=roomy: 8 every night;
 * slots=tight: 5 every night); each team takes a week off on 7% of nights
 * (off=N for N%); three teams opt into double-headers for the season.
 * outliers=N puts team 1 N points above the field and team 2 N below;
 * size=N fixes the number of teams (size=13 slots=roomy off=0: an odd
 * division with a court to spare every night). join=6 has the last team
 * join on night 6 (join=4,6: the last joins on night 4, the one before it
 * on night 6); away=N takes team 3 away for nights 2 to N + 1; level=X,Y
 * sets the sit_level of the last rows (each at most 0.5; by default 0 and
 * 0.5, the ends of the range, leaving out the live one).
 *
 * The same season is played under each rule from the same teams, strengths,
 * slots, weeks off and joiners: with the rule before 0.18.2 (the odd team
 * by a soft cost, 4 × (1 + nights sat out), +2 on the count for a
 * double-header team; then the dearest games dropped to fit the slots; the
 * fill game with the closest double-header team); with the first 0.18.2
 * build (RSL_Pairing::pair_division() on the nights each team actually sat
 * out); with the live rule (pair_division() on sat_out_counts()'s count,
 * which credits each night away with that night's share of sit-outs, and
 * the twice-in-a-row guard); and the same at the other sit_levels, to show
 * what counting close counts as level buys. Every rule uses the fixtures
 * rating and the link term, plays games rally by rally (dev/lib-sim.php),
 * gives the odd team its fill game when one qualifies, and then fills spare
 * slots with double-headers as plan() does (fairest first). A sit-out is a
 * night a team was available and had no game, as RSL_Pairing::context()
 * counts it; the tables count actual sit-outs, whatever count the rule ran
 * on.
 *
 * Fairness for teams that miss nights is measured on the same nights: a
 * late joiner's or a team with nights away's sit-outs per night it was
 * there, beside the sit-outs per night of the teams there every night on
 * those same nights (nights early in a season can have fewer sit-outs, so
 * a whole-season rate is no yardstick); its excess, the sit-outs it had
 * minus the sum of the shares (sit-outs ÷ teams available) of the nights it
 * was there, which is 0 for a team that sat out exactly its share; and its
 * first night (or first night back), beside the chance a team there every
 * night sat out that same night.
 */

if (!defined('WP_CLI') || !WP_CLI || (wp_get_environment_type() === 'production' && !getenv('RSL_DEV'))) {
    exit("Local copy only: run with wp eval-file, with RSL_DEV=1 set (docker exec -e RSL_DEV=1 ...).\n");
}

$opt = array('teams' => 14, 'rounds' => 10, 'runs' => in_array('courts', (array) $args, true) ? 200 : 40, 'seed' => 0, 'outliers' => 0, 'slots' => 'some', 'size' => 0, 'off' => 7, 'join' => array(), 'away' => 0);
foreach ((array) $args as $a) {
    if (preg_match('/^(teams|rounds|runs|seed|outliers|size|off|away)=(\d+)$/', $a, $m)) {
        $opt[$m[1]] = intval($m[2]);
    } elseif (preg_match('/^slots=(some|every|roomy|tight)$/', $a, $m)) {
        $opt['slots'] = $m[1];
    } elseif (preg_match('/^level=(\d+(\.\d+)?(,\d+(\.\d+)?)*)$/', $a, $m)) {
        $opt['level'] = array_map(function ($x) { return min(0.5, floatval($x)); }, explode(',', $m[1]));
    } elseif (preg_match('/^join=(\d+(,\d+)*)$/', $a, $m)) {
        $opt['join'] = array_values(array_filter(array_map('intval', explode(',', $m[1])), function ($x) { return $x > 1; }));
    }
}

if (in_array('courts', (array) $args, true)) {
    require_once __DIR__ . '/lib-sim.php';
    $w = RSL_Pairing::weights();
    $levels = array(1200, 1350, 1500, 1650, 1800);
    $live_level = min(0.5, max(0.0, (float) $w['sit_level']));
    $alt_levels = isset($opt['level']) ? $opt['level'] : array_values(array_filter(array(0.0, 0.5), function ($x) use ($live_level) { return abs($x - $live_level) > 1e-9; }));
    $level_words = function ($x) { return $x > 0 ? 'counts within ' . rtrim(rtrim(sprintf('%.2f', $x), '0'), '.') . ' level' : 'counts compared exactly'; };
    // Rule key => sit_level for the count with shares (old and raw: none).
    $levels_by = array('old' => null, 'raw' => null, 'credit' => $live_level);
    $rules = array(
        'Before 0.18.2: dearest games sit out' => 'old',
        'Nights sat out only (9aa99da)' => 'raw',
        'Live (0.18.2): with shares, ' . $level_words($live_level) => 'credit',
    );
    foreach ($alt_levels as $i => $x) {
        $levels_by["alt$i"] = $x;
        $rules['With shares, ' . $level_words($x)] = "alt$i";
    }
    $night_date = function ($round) { return date('Y-m-d', mktime(0, 0, 0, 1, 7 * $round, 2030)); };
    $sum = array();
    foreach ($rules as $name => $rule) {
        $sum[$rule] = array('seasons' => 0, 'spread' => 0, 'worst' => 0, 'gap2' => 0, 'most' => 0, 'sat' => 0, 'team_seasons' => 0,
            'games' => 0, 'closeness' => 0.0, 'over' => 0, 'main' => 0, 'main_closeness' => 0.0, 'main_over' => 0, 'third' => 0, 'fills' => 0, 'doubles' => 0,
            'opted_sat' => 0, 'opted_n' => 0, 'other_sat' => 0, 'other_n' => 0, 'both' => 0, 'outlier_sat' => 0, 'dh_spread' => 0, 'dh_spread_n' => 0,
            'rate' => array('aa' => array(0.0, 0, 0.0), 'off' => array(0.0, 0, 0.0), 'late' => array(0.0, 0, 0.0)),
            // Same nights: group => array(its sit-outs per night there, the teams there every night's on those nights, teams).
            'same' => array('off' => array(0.0, 0.0, 0), 'late' => array(0.0, 0.0, 0)),
            // Excess: group => array(sum, teams, lowest, highest) of sit-outs minus the sum of the shares of the nights there.
            'excess' => array('aa' => array(0.0, 0, INF, -INF), 'off' => array(0.0, 0, INF, -INF), 'late' => array(0.0, 0, INF, -INF)),
            'late_first' => 0, 'late_n' => 0, 'late_aa' => 0.0, 'back_first' => 0, 'back_n' => 0, 'back_aa' => 0.0, 'twice' => 0, 'first' => 0);
    }
    for ($run = 1; $run <= $opt['runs']; $run++) {
        $eff = $run + $opt['seed'];
        // The season: teams, strengths, captains' levels, double-header teams, slots and weeks off.
        mt_srand($eff * 7919 + 13);
        $n = mt_rand(12, 14);
        $n = $opt['size'] ? $opt['size'] : $n;
        $true = array();
        $seeds = array();
        for ($t = 1; $t <= $n; $t++) {
            $z = (mt_rand() / mt_getrandmax() + mt_rand() / mt_getrandmax() + mt_rand() / mt_getrandmax() - 1.5) * 2;
            $true[$t] = 1500 + 180 * $z;
            if ($opt['outliers'] && $t <= 2) {
                $true[$t] = 1500 + ($t === 1 ? 1 : -1) * $opt['outliers'];
            }
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
            $room[$round] = $opt['slots'] === 'roomy' ? 8 : ($opt['slots'] === 'tight' ? 5 : ($opt['slots'] === 'every' ? ($x <= 5 ? 5 : 6) : ($x <= 3 ? 5 : ($x <= 6 ? 6 : 8))));
            foreach ($teams as $t) { if (mt_rand(1, 100) <= $opt['off']) { $off[$round][$t] = true; } }
        }
        // Late joiners (join=): the last teams, one for each night listed. A block of nights away (away=N): team 3, nights 2 to N + 1.
        $join = array_fill_keys($teams, 1);
        foreach ($opt['join'] as $i => $night) { $join[$n - $i] = $night; }
        for ($round = 2; $round <= $opt['away'] + 1 && $round <= $opt['rounds']; $round++) { $off[$round][3] = true; }
        $info = array();
        foreach ($teams as $t) { $info[$t] = array('division' => 'ranked', 'joined' => $join[$t] > 1 ? $night_date($join[$t]) : ''); }
        foreach ($rules as $rule) {
            mt_srand($eff * 104729);
            $games = array();
            $meetings = array();
            $byes = array();   // team => nights actually sat out
            $doubles = array(); // team => double-header nights
            $avail = array();  // team => nights available
            $seq = array();    // team => list of P (played) / O (sat out), nights available only
            $status = array(); // team => round => P / O
            $history = array(); // sat_out_counts()'s nights
            $record = array();  // round => array(avail => ids, out => id => true, share => sit-outs ÷ teams available)
            $avail_all = array_fill_keys(array_filter($teams, function ($t) use ($join) { return $join[$t] === 1; }), true);
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
                    if ($join[$t] > $round) { continue; }
                    if (isset($off[$round][$t])) { unset($avail_all[$t]); } else { $ids[] = $t; }
                }
                $opted = array_values(array_filter($ids, function ($t) use ($opted_all) { return isset($opted_all[$t]); }));
                $salt = "c$eff-$round";
                $counts = RSL_Pairing::sat_out_counts($history, $info);
                // Before tonight: the teams that have never sat out, and those that sat out their last night.
                $never = array();
                $last = array();
                foreach ($ids as $t) {
                    if (empty($byes[$t])) { $never[] = $t; } elseif (isset($seq[$t]) && end($seq[$t]) === 'O') { $last[] = $t; }
                }
                $tonight = array(); // list of array(a, b, pair_cost info, kind: main | fill | double)
                if ($rule !== 'old') {
                    $count = $rule === 'raw' ? $byes : $counts['byes'];
                    $guard = $rule === 'raw' ? array() : array('sat' => $counts['sat'], 'last' => $counts['last']);
                    $wr = $levels_by[$rule] !== null ? array('sit_level' => $levels_by[$rule]) + $w : $w;
                    $p = RSL_Pairing::pair_division($ids, $ratings, $now, $count, $wr, $salt, $link, $room[$round], $opted, $doubles, $guard);
                    foreach ($p['pairs'] as $g) { $tonight[] = array($g[0], $g[1], $g[2], 'main'); }
                    if ($p['fill']) { $tonight[] = array($p['bye'], $p['fill'][0], $p['fill'][1], 'fill'); }
                    $sum[$rule]['first'] += count($p['first']);
                } else {
                    // a5101af's plan(): the odd team by a soft cost (+2 on the count for a double-header team),
                    // then trim(): the dearest games sit out; then the odd team's fill game if a slot is left.
                    $soft = $byes;
                    if (count($ids) % 2 === 1) { foreach ($opted as $o) { $soft[$o] = (isset($soft[$o]) ? $soft[$o] : 0) + 2; } }
                    $sorted = $ids;
                    usort($sorted, function ($x, $y) use ($ratings) { return $ratings[$y]['rating'] <=> $ratings[$x]['rating']; });
                    $pinfo = array();
                    $m = RSL_Pairing::match($sorted, function ($a, $b) use (&$pinfo, $ratings, $now, $soft, $w, $salt, $link) {
                        if ($a === 0 || $b === 0) {
                            $t = $a === 0 ? $b : $a;
                            return 4.0 * (1 + (isset($soft[$t]) ? $soft[$t] : 0)) + (crc32($salt . '|bye|' . $t) % 1000) / 100000; // the old 'bye' weight, 4
                        }
                        $c = RSL_Pairing::pair_cost($a, $b, $ratings, $now, $w, $salt, $link);
                        $pinfo[RSL_Pairing::meeting_key($a, $b)] = $c;
                        return $c['cost'];
                    });
                    $pairs = array();
                    $bye = null;
                    foreach ($m['pairs'] as $pr) {
                        if ($pr[0] === 0 || $pr[1] === 0) { $bye = $pr[0] === 0 ? $pr[1] : $pr[0]; continue; }
                        $pairs[] = array($pr[0], $pr[1], $pinfo[RSL_Pairing::meeting_key($pr[0], $pr[1])], 'main');
                    }
                    usort($pairs, function ($x, $y) { return $x[2]['cost'] <=> $y[2]['cost']; });
                    $tonight = array_slice($pairs, 0, $room[$round]);
                    if ($bye !== null && count($tonight) < $room[$round]) {
                        $f = RSL_Pairing::fill_for($bye, $opted, $ratings, $now, $w, $salt); // the closest double-header team
                        if ($f) { $tonight[] = array($bye, $f[0], $f[1], 'fill'); }
                    }
                }
                // Spare slots: double-headers for the opted-in teams, fairest first (plan(), the same under every rule).
                $with = array();
                foreach ($tonight as $g) { $with[$g[0]][] = $g[1]; $with[$g[1]][] = $g[0]; }
                $spare = $room[$round] - count($tonight);
                $cands = array();
                foreach ($opted as $x => $a) {
                    foreach (array_slice($opted, $x + 1) as $b) {
                        if (in_array($b, isset($with[$a]) ? $with[$a] : array(), true)) { continue; }
                        $c = RSL_Pairing::pair_cost($a, $b, $ratings, $now, $w, $salt);
                        if ($c['count'] >= 2 || $c['recent'] >= $w['last_week'] || $c['closeness'] > $w['double_max']) { continue; }
                        $cands[] = array($a, $b, $c, $c['cost'] + 0.5 * ((isset($doubles[$a]) ? $doubles[$a] : 0) + (isset($doubles[$b]) ? $doubles[$b] : 0)));
                    }
                }
                usort($cands, function ($x, $y) { return $x[3] <=> $y[3]; });
                foreach ($cands as $cd) {
                    if ($spare <= 0) { break; }
                    list($a, $b, $c) = $cd;
                    if ((isset($with[$a]) ? count($with[$a]) : 0) > 1 || (isset($with[$b]) ? count($with[$b]) : 0) > 1) { continue; }
                    $tonight[] = array($a, $b, $c, 'double');
                    $with[$a][] = $b;
                    $with[$b][] = $a;
                    $spare--;
                }
                $in = array();
                $played = array();
                foreach ($tonight as $g) {
                    list($a, $b, $c, $kind) = $g;
                    $games[] = array($a, $b, rsl_dev_play_game($true[$a] - $true[$b]), $round);
                    $played[] = array('ranked', $a, $b);
                    $key = RSL_Pairing::meeting_key($a, $b);
                    $meetings[$key] = array('count' => (isset($meetings[$key]) ? $meetings[$key]['count'] : 0) + 1, 'round' => $round);
                    $in[$a] = (isset($in[$a]) ? $in[$a] : 0) + 1;
                    $in[$b] = (isset($in[$b]) ? $in[$b] : 0) + 1;
                    $over = 1 / (1 + pow(10, -abs($true[$a] - $true[$b]) / RSL_Ratings::SCALE)) > 0.80 ? 1 : 0;
                    $sum[$rule]['games']++;
                    $sum[$rule]['closeness'] += $c['closeness'];
                    $sum[$rule]['over'] += $over;
                    if ($kind === 'main') {
                        $sum[$rule]['main']++;
                        $sum[$rule]['main_closeness'] += $c['closeness'];
                        $sum[$rule]['main_over'] += $over;
                    } elseif ($kind === 'fill') {
                        $sum[$rule]['fills']++;
                    } else {
                        $sum[$rule]['doubles']++;
                    }
                }
                // Twice in a row: a team that sat out its last night sits out again while one that has never sat out plays.
                $again = array_filter($last, function ($t) use ($in) { return !isset($in[$t]); });
                $playing_never = array_filter($never, function ($t) use ($in) { return isset($in[$t]); });
                $sum[$rule]['twice'] += $again && $playing_never ? 1 : 0;
                $record[$round] = array('avail' => $ids, 'out' => array(), 'share' => 0.0);
                foreach ($ids as $t) {
                    $avail[$t] = (isset($avail[$t]) ? $avail[$t] : 0) + 1;
                    $seq[$t][] = $status[$t][$round] = isset($in[$t]) ? 'P' : 'O';
                    if (!isset($in[$t])) {
                        $byes[$t] = (isset($byes[$t]) ? $byes[$t] : 0) + 1;
                        $record[$round]['out'][$t] = true;
                    } elseif ($in[$t] > 1) {
                        $doubles[$t] = (isset($doubles[$t]) ? $doubles[$t] : 0) + 1;
                    }
                }
                $record[$round]['share'] = $ids ? count($record[$round]['out']) / count($ids) : 0.0;
                $history[] = array('date' => $night_date($round), 'off' => isset($off[$round]) ? $off[$round] : array(), 'games' => $played);
            }
            $s = &$sum[$rule];
            $s['seasons']++;
            $get = function ($t) use ($byes) { return isset($byes[$t]) ? $byes[$t] : 0; };
            // The nights each team was there, and the teams there every night.
            $there = array();
            foreach ($record as $round => $rec) {
                foreach ($rec['avail'] as $t) { $there[$t][] = $round; }
            }
            $aa_ids = array_keys($avail_all);
            // The teams there every night: the share of them that sat out on these nights, per night.
            $aa_rate = function ($rounds) use ($record, $aa_ids) {
                if (!$aa_ids || !$rounds) { return null; }
                $out = 0;
                foreach ($rounds as $round) {
                    foreach ($aa_ids as $u) { $out += isset($record[$round]['out'][$u]) ? 1 : 0; }
                }
                return $out / count($aa_ids) / count($rounds);
            };
            foreach ($teams as $t) {
                $s['sat'] += $get($t);
                $s['team_seasons']++;
                if (isset($opted_all[$t])) {
                    $s['opted_sat'] += $get($t);
                    $s['opted_n']++;
                    $s['both'] += $get($t) >= 2 && isset($doubles[$t]) && $doubles[$t] >= 2 ? 1 : 0;
                } else {
                    $s['other_sat'] += $get($t);
                    $s['other_n']++;
                }
                // Sit-outs per night available, by group: available every night, joined late, joined at the start and away some nights.
                $group = $join[$t] > 1 ? 'late' : (isset($avail_all[$t]) ? 'aa' : 'off');
                if (!empty($avail[$t])) {
                    $rate = $get($t) / $avail[$t];
                    $s['rate'][$group][0] += $rate;
                    $s['rate'][$group][1]++;
                    $s['rate'][$group][2] = max($s['rate'][$group][2], $rate);
                }
                if (empty($there[$t])) {
                    continue;
                }
                // Excess: its sit-outs minus the sum of the shares of the nights it was there.
                $share_sum = 0.0;
                foreach ($there[$t] as $round) { $share_sum += $record[$round]['share']; }
                $x = $get($t) - $share_sum;
                $s['excess'][$group][0] += $x;
                $s['excess'][$group][1]++;
                $s['excess'][$group][2] = min($s['excess'][$group][2], $x);
                $s['excess'][$group][3] = max($s['excess'][$group][3], $x);
                // On the same nights: its sit-outs per night there, and the teams there every night's.
                if ($group !== 'aa' && ($same = $aa_rate($there[$t])) !== null) {
                    $s['same'][$group][0] += $get($t) / count($there[$t]);
                    $s['same'][$group][1] += $same;
                    $s['same'][$group][2]++;
                }
                if ($group === 'late' && ($same = $aa_rate(array($there[$t][0]))) !== null) {
                    $s['late_n']++;
                    $s['late_first'] += $seq[$t][0] === 'O' ? 1 : 0;
                    $s['late_aa'] += $same;
                }
            }
            if ($opt['away'] && $join[3] === 1) {
                // Team 3's first night back from its block away, beside the teams there every night that night.
                for ($round = $opt['away'] + 2; $round <= $opt['rounds']; $round++) {
                    if (isset($status[3][$round])) {
                        if (($same = $aa_rate(array($round))) !== null) {
                            $s['back_n']++;
                            $s['back_first'] += $status[3][$round] === 'O' ? 1 : 0;
                            $s['back_aa'] += $same;
                        }
                        break;
                    }
                }
            }
            $s['outlier_sat'] += $opt['outliers'] ? $get(1) + $get(2) : 0;
            $s['most'] += max(array_map($get, $teams));
            $always = array_map($get, array_keys($avail_all));
            $spread = count($always) > 1 ? max($always) - min($always) : 0;
            $s['spread'] += $spread;
            $s['worst'] = max($s['worst'], $spread);
            $s['gap2'] += $spread >= 2 ? 1 : 0;
            foreach ($meetings as $mt) { $s['third'] += max(0, $mt['count'] - 2); }
            $dh = array();
            foreach (array_keys($opted_all) as $t) {
                if (isset($avail_all[$t])) { $dh[] = isset($doubles[$t]) ? $doubles[$t] : 0; }
            }
            if (count($dh) > 1) {
                $s['dh_spread'] += max($dh) - min($dh);
                $s['dh_spread_n']++;
            }
            unset($s);
        }
    }
    echo sprintf("Courts short: %s Ranked teams, %d nights (%s), weeks off %d%%, 3 double-header teams%s%s%s; %d seasons from seed %d\n\n",
        $opt['size'] ? $opt['size'] : '12-14', $opt['rounds'],
        $opt['slots'] === 'roomy' ? '8 slots every night' : ($opt['slots'] === 'tight' ? '5 slots every night' : ($opt['slots'] === 'every' ? '5 or 6 slots every night' : '5 slots on 30%, 6 on 30%, 8 on 40%')), $opt['off'],
        $opt['outliers'] ? sprintf(', team 1 %d above the field and team 2 %d below', $opt['outliers'], $opt['outliers']) : '',
        $opt['join'] ? ', a team joining on night ' . implode(', another on night ', $opt['join']) : '',
        $opt['away'] ? sprintf(', team 3 away nights 2 to %d', $opt['away'] + 1) : '', $opt['runs'], $opt['seed']);
    echo sprintf("%-50s %8s %7s %6s %7s %9s %10s %8s %8s %6s %6s %6s\n", 'Rule', 'Sat/team', 'Spread', 'Worst', '2+ gap', 'Most/team', 'Closeness', 'Fav>80%', 'Ordinary', 'Ord>80', 'Fills', '3rd+');
    echo str_repeat('-', 138) . "\n";
    foreach ($rules as $name => $rule) {
        $s = $sum[$rule];
        echo sprintf("%-50s %8.2f %7.2f %6d %6.1f%% %9.2f %10.3f %7.1f%% %8.3f %5.1f%% %6.2f %6.2f\n", $name,
            $s['sat'] / $s['team_seasons'], $s['spread'] / $s['seasons'], $s['worst'], 100 * $s['gap2'] / $s['seasons'], $s['most'] / $s['seasons'],
            $s['closeness'] / $s['games'], 100 * $s['over'] / $s['games'], $s['main_closeness'] / max(1, $s['main']), 100 * $s['main_over'] / max(1, $s['main']),
            $s['fills'] / $s['seasons'], $s['third'] / $s['seasons']);
    }
    echo "\n" . sprintf("%-50s %10s %10s %11s %9s %10s%s\n", 'Rule', 'DH sat', 'Other sat', 'DH 2+ & 2+', 'DH games', 'DH spread', $opt['outliers'] ? '  Outlier sat' : '');
    echo str_repeat('-', 107 + ($opt['outliers'] ? 13 : 0)) . "\n";
    foreach ($rules as $name => $rule) {
        $s = $sum[$rule];
        echo sprintf("%-50s %10.2f %10.2f %10.1f%% %9.2f %10.2f%s\n", $name, $s['opted_sat'] / max(1, $s['opted_n']), $s['other_sat'] / max(1, $s['other_n']),
            100 * $s['both'] / max(1, $s['opted_n']), $s['doubles'] / $s['seasons'], $s['dh_spread'] / max(1, $s['dh_spread_n']), $opt['outliers'] ? sprintf('  %11.2f', $s['outlier_sat'] / (2 * $s['seasons'])) : '');
    }
    $f3 = function ($sum, $n) { return $n ? sprintf('%.3f', $sum / $n) : '-'; };
    $pct = function ($sum, $n) { return $n ? sprintf('%.1f%%', 100 * $sum / $n) : '-'; };
    echo "\n" . sprintf("%-50s %8s %8s %8s %8s %10s %8s %8s %10s\n", 'Rule', 'Always', 'worst', 'Off', 'worst', 'Always on', 'Late', 'worst', 'Always on');
    echo sprintf("%-50s %8s %8s %8s %8s %10s %8s %8s %10s\n", '', '', '', '', '', 'its nights', '', '', 'its nights');
    echo str_repeat('-', 122) . "\n";
    foreach ($rules as $name => $rule) {
        $s = $sum[$rule];
        $r = $s['rate'];
        $sm = $s['same'];
        echo sprintf("%-50s %8s %8s %8s %8s %10s %8s %8s %10s\n", $name,
            $f3($r['aa'][0], $r['aa'][1]), $r['aa'][1] ? sprintf('%.3f', $r['aa'][2]) : '-', $f3($r['off'][0], $r['off'][1]), $r['off'][1] ? sprintf('%.3f', $r['off'][2]) : '-',
            $f3($sm['off'][1], $sm['off'][2]),
            $f3($r['late'][0], $r['late'][1]), $r['late'][1] ? sprintf('%.3f', $r['late'][2]) : '-',
            $f3($sm['late'][1], $sm['late'][2]));
    }
    echo "\n" . sprintf("%-50s %7s %15s %15s %15s %8s %8s %8s %8s %6s %6s\n", 'Rule', 'Ex. aa', 'Excess, off', 'Excess, late', 'aa (low/high)', '1st out', 'aa then', 'Back out', 'aa then', 'Twice', 'First');
    echo str_repeat('-', 140) . "\n";
    foreach ($rules as $name => $rule) {
        $s = $sum[$rule];
        $e = $s['excess'];
        $ex = function ($g) use ($e) { return $e[$g][1] ? sprintf('%+.2f (%+.1f/%+.1f)', $e[$g][0] / $e[$g][1], $e[$g][2], $e[$g][3]) : '-'; };
        echo sprintf("%-50s %7s %15s %15s %15s %8s %8s %8s %8s %6d %6d\n", $name,
            $e['aa'][1] ? sprintf('%+.2f', $e['aa'][0] / $e['aa'][1]) : '-', $ex('off'), $ex('late'), $ex('aa'),
            $pct($s['late_first'], $s['late_n']), $pct($s['late_aa'], $s['late_n']), $pct($s['back_first'], $s['back_n']), $pct($s['back_aa'], $s['back_n']),
            $s['twice'], $s['first']);
    }
    echo "\nSat/team = sit-outs per team per season (nights available with no game). Spread = most minus fewest sit-outs\n";
    echo "among the teams available every night, per season (Worst = the largest in any season; 2+ gap = seasons where\n";
    echo "one of them sat out 2 or more nights more than another). Most/team = the season's highest sit-out count.\n";
    echo "Closeness = mean closeness cost of every game played (fill and double-header games too), by the draw's own\n";
    echo "rating (0 = 50/50, 1 = 67/33); Ordinary = the same for the night's ordinary games only. Fav>80% and Ord>80 =\n";
    echo "games whose favourite had over 0.80 set chance by TRUE strength. Fills = odd-team fill games per season.\n";
    echo "3rd+ = meetings beyond the second per season. DH sat / Other sat = sit-outs per season of a double-header\n";
    echo "team / of any other team. DH 2+ & 2+ = double-header teams' seasons with 2+ nights sat out AND 2+\n";
    echo "double-header nights. DH games = spare-slot double-header games per season. DH spread = most minus fewest\n";
    echo "double-header nights (fill or spare slot) among the double-header teams available every night, per season." . ($opt['outliers'] ? "\nOutlier sat = sit-outs per season of teams 1 and 2 (each)." : '') . "\n";
    echo "Always / Off / Late = sit-outs per night available, the mean over teams available every night, teams there from\n";
    echo "night 1 with a night or more away, and late joiners; worst = the highest of any one such team in any season.\n";
    echo "Always on its nights = the sit-outs per night of the teams available every night, on the nights each team away\n";
    echo "some nights (Off) or late joiner (Late) was there: the yardstick for that team's rate beside it.\n";
    echo "Excess = sit-outs minus the sum of the shares (sit-outs / teams available) of the nights the team was there:\n";
    echo "0 when it sat out exactly its share, below 0 when less (the lowest and highest of any one team in brackets).\n";
    echo "1st out = late joiners that sat out their first night; aa then = the teams available every night that sat\n";
    echo "out that same night. Back out = team 3 sat out its first night back (away=), aa then likewise.\n";
    echo "Twice = nights a team that sat out its last night sat out again while a team that had never sat out played.\n";
    echo "First = sit-outs the guard moved first (a team that had never sat out, ahead of one that sat out its last night).\n";
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
