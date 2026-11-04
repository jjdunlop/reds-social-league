<?php
/**
 * Automatic weekly fixtures.
 *
 * Every possible pairing gets a COST, and the engine picks the set of
 * pairings with the lowest total for the night (so it never gets stuck
 * with a bad last pair). Costs share one unit — "a 2-to-1 matchup" — so
 * closeness and variety trade off openly:
 *
 *   closeness   from the two ratings, each team's chance of winning a set
 *               (independent of how many sets fit in the hour). 50/50
 *               costs 0, 67/33 (a 2-to-1 favourite) costs 1, 75/25 costs
 *               2.25, 80/20 costs 3.2, 90/10 costs 5.8 — squared, so
 *               slightly uneven is cheap and lopsided is expensive.
 *               Uncertain (new) ratings are pulled towards 50/50, so the
 *               engine doesn't over-trust a guess.
 *   rematch     a second meeting costs as much as a 67/33 game;
 *               a third costs far more than any lopsided game — so twice
 *               a season is the cap unless there's truly no alternative.
 *   recency     last week's opponent: never (unless nothing else works);
 *               two weeks ago: costs another 1.
 *   bye         odd numbers: someone sits out (or fills a double-header),
 *               dearer each time the same team has sat out.
 *
 * Unranked has no ratings, so only variety counts: least-played, longest
 * ago — the usual social-league round robin, but robust to late joiners
 * and weeks off.
 *
 * Then slots: games go into the night's courts and times, honouring
 * early/late preferences (a team that missed out gets priority next time),
 * sharing the last slot around, and double-header shapes (back-to-back or
 * with a break). Spare slots become double-headers for teams that opted in.
 *
 * The core (pair_division, match, assign_slots, set_win_probability) is
 * pure — no database — so it can be tested and simulated over whole
 * seasons (dev/simulate-pairing.php). plan()/generate() wrap it for a real night.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RSL_Pairing {

    const EDGE_UNIT = 1 / 3;  // a 67/33 set-win chance: the favourite's edge (0.67 − 0.33) that costs 1
    const EXACT_LIMIT = 18;   // up to this many teams the matching is exact; above, a strong local search

    /** The trade-offs, in "2-to-1 matchups" (1.0 = as bad as a 67/33 game). */
    public static function weights() {
        return apply_filters('rsl_pairing_weights', array(
            'closeness' => 1.0,       // × (edge ÷ EDGE_UNIT)², edge = |chance A − chance B| of winning a set
            'rematch' => 1.0,         // a second meeting
            'extra_meeting' => 25.0,  // each meeting after the second (simulated: removes third meetings at almost no cost in closeness)
            'two_weeks' => 1.0,       // met two rounds ago
            'last_week' => 1000.0,    // met last round
            'bye' => 4.0,             // sitting out, × (times sat out + 1)
            'double_max' => 2.25,     // a spare-slot double-header only if its closeness cost is at most this (75/25)
            'double_max_asked' => 4.0, // ...or this when a team asked for two games that week (about 83/17)
        ));
    }

    // ------------------------------------------------------------------
    // Pure core
    // ------------------------------------------------------------------

    /**
     * Chance team A wins a set against B. Each team's uncertainty (sd)
     * pulls the odds towards 50/50 (the Glicko adjustment), so a new team's
     * guessed rating isn't trusted like a settled one.
     */
    public static function set_win_probability($ra, $sda, $rb, $sdb) {
        $q = log(10) / RSL_Ratings::SCALE;
        $g = 1 / sqrt(1 + 3 * $q * $q * ($sda * $sda + $sdb * $sdb) / (M_PI * M_PI));
        return 1 / (1 + pow(10, -$g * ($ra - $rb) / RSL_Ratings::SCALE));
    }

    public static function meeting_key($a, $b) {
        return min($a, $b) . '-' . max($a, $b);
    }

    /**
     * What pairing two teams would cost, and why.
     * $ratings: id => array(rating, sd), or null (Unranked: no closeness).
     * $meetings: meeting_key => array(count, last) where last = rounds ago.
     * Returns array(cost, p (A wins a set), edge, closeness, repeat, recent, count, last).
     */
    public static function pair_cost($a, $b, $ratings, $meetings, $w, $salt = '') {
        $out = array('cost' => 0.0, 'p' => null, 'edge' => null, 'closeness' => 0.0, 'repeat' => 0.0, 'recent' => 0.0, 'count' => 0, 'last' => null);
        if ($ratings !== null && isset($ratings[$a]) && isset($ratings[$b])) {
            $p = self::set_win_probability($ratings[$a]['rating'], $ratings[$a]['sd'], $ratings[$b]['rating'], $ratings[$b]['sd']);
            $edge = abs(2 * $p - 1);
            $out['p'] = $p;
            $out['edge'] = $edge;
            $out['closeness'] = $w['closeness'] * pow($edge / self::EDGE_UNIT, 2);
        }
        $key = self::meeting_key($a, $b);
        if (isset($meetings[$key])) {
            $m = $meetings[$key];
            $out['count'] = intval($m['count']);
            $out['last'] = intval($m['last']);
            $out['repeat'] = $w['rematch'] + max(0, $out['count'] - 1) * $w['extra_meeting'];
            if ($out['last'] === 1) {
                $out['recent'] = $w['last_week'];
            } elseif ($out['last'] === 2) {
                $out['recent'] = $w['two_weeks'];
            }
        }
        // A tiny, repeatable tie-break so equal options don't always fall the same way.
        $jitter = (crc32($salt . '|' . $key) % 1000) / 100000;
        $out['cost'] = $out['closeness'] + $out['repeat'] + $out['recent'] + $jitter;
        return $out;
    }

    /**
     * Pair one division's teams for a night. $ids: team ids (Ranked: any
     * order; strongest first helps the search). $byes: id => times sat out.
     * Returns array(pairs => list of array(a, b, info), bye => id|null, total).
     */
    public static function pair_division($ids, $ratings, $meetings, $byes, $w, $salt = '') {
        $ids = array_values(array_map('intval', $ids));
        if ($ratings !== null) {
            usort($ids, function ($x, $y) use ($ratings) {
                return (isset($ratings[$y]) ? $ratings[$y]['rating'] : 0) <=> (isset($ratings[$x]) ? $ratings[$x]['rating'] : 0);
            });
        }
        $info = array();
        $cost = function ($a, $b) use (&$info, $ratings, $meetings, $byes, $w, $salt) {
            if ($a === 0 || $b === 0) {
                $t = $a === 0 ? $b : $a;
                return $w['bye'] * (1 + (isset($byes[$t]) ? $byes[$t] : 0)) + (crc32($salt . '|bye|' . $t) % 1000) / 100000;
            }
            $c = self::pair_cost($a, $b, $ratings, $meetings, $w, $salt);
            $info[self::meeting_key($a, $b)] = $c;
            return $c['cost'];
        };
        $m = self::match($ids, $cost);
        $pairs = array();
        $bye = null;
        foreach ($m['pairs'] as $p) {
            if ($p[0] === 0 || $p[1] === 0) {
                $bye = $p[0] === 0 ? $p[1] : $p[0];
                continue;
            }
            $pairs[] = array($p[0], $p[1], $info[self::meeting_key($p[0], $p[1])]);
        }
        return array('pairs' => $pairs, 'bye' => $bye, 'total' => $m['total']);
    }

    /**
     * Minimum-cost perfect matching. $ids: list of ints (an odd count gets
     * a bye, id 0). $cost(a, b) -> float. Exact (dynamic programming over
     * subsets) up to EXACT_LIMIT teams; above that, pair-swapping local
     * search from several starts. Returns array(pairs, total).
     */
    public static function match($ids, $cost) {
        $ids = array_values($ids);
        if (count($ids) % 2 === 1) {
            $ids[] = 0;
        }
        $n = count($ids);
        if ($n === 0) {
            return array('pairs' => array(), 'total' => 0.0);
        }
        $c = array();
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $c[$i][$j] = $c[$j][$i] = (float) $cost($ids[$i], $ids[$j]);
            }
        }
        $choice = $n <= self::EXACT_LIMIT ? self::match_exact($c, $n) : self::match_search($c, $n);
        $pairs = array();
        $total = 0.0;
        foreach ($choice as $p) {
            $pairs[] = array($ids[$p[0]], $ids[$p[1]]);
            $total += $c[$p[0]][$p[1]];
        }
        return array('pairs' => $pairs, 'total' => $total);
    }

    private static function match_exact($c, $n) {
        $full = (1 << $n) - 1;
        $memo = array($full => 0.0);
        $next = array();
        $solve = function ($mask) use (&$solve, &$memo, &$next, $c, $n) {
            if (isset($memo[$mask])) {
                return $memo[$mask];
            }
            $i = 0;
            while ($mask & (1 << $i)) {
                $i++;
            }
            $best = INF;
            $pick = -1;
            for ($j = $i + 1; $j < $n; $j++) {
                if ($mask & (1 << $j)) {
                    continue;
                }
                $v = $c[$i][$j] + $solve($mask | (1 << $i) | (1 << $j));
                if ($v < $best) {
                    $best = $v;
                    $pick = $j;
                }
            }
            $next[$mask] = array($i, $pick);
            return $memo[$mask] = $best;
        };
        $solve(0);
        $pairs = array();
        for ($mask = 0; $mask !== $full;) {
            list($i, $j) = $next[$mask];
            $pairs[] = array($i, $j);
            $mask |= (1 << $i) | (1 << $j);
        }
        return $pairs;
    }

    private static function match_search($c, $n) {
        $best = null;
        $best_total = INF;
        $seed = 12345;
        for ($start = 0; $start < 24; $start++) {
            $order = range(0, $n - 1);
            if ($start > 0) {
                for ($i = $n - 1; $i > 0; $i--) {
                    $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
                    $j = $seed % ($i + 1);
                    list($order[$i], $order[$j]) = array($order[$j], $order[$i]);
                }
            }
            $pairs = array();
            for ($i = 0; $i < $n; $i += 2) {
                $pairs[] = array($order[$i], $order[$i + 1]);
            }
            do {
                $improved = false;
                $count = count($pairs);
                for ($x = 0; $x < $count; $x++) {
                    for ($y = $x + 1; $y < $count; $y++) {
                        list($a, $b) = $pairs[$x];
                        list($d, $e) = $pairs[$y];
                        $now = $c[$a][$b] + $c[$d][$e];
                        $alt1 = $c[$a][$d] + $c[$b][$e];
                        $alt2 = $c[$a][$e] + $c[$b][$d];
                        if ($alt1 < $now - 1e-9 && $alt1 <= $alt2) {
                            $pairs[$x] = array($a, $d);
                            $pairs[$y] = array($b, $e);
                            $improved = true;
                        } elseif ($alt2 < $now - 1e-9) {
                            $pairs[$x] = array($a, $e);
                            $pairs[$y] = array($b, $d);
                            $improved = true;
                        }
                    }
                }
            } while ($improved);
            $total = 0.0;
            foreach ($pairs as $p) {
                $total += $c[$p[0]][$p[1]];
            }
            if ($total < $best_total) {
                $best_total = $total;
                $best = $pairs;
            }
        }
        return $best;
    }

    /**
     * Put games into slots. $games: list of array(home, away).
     * $slots: list of array(court_id, start 'H:i', end 'H:i', ...) for the
     * division. $teams: id => array(time: any|early|late, shape: back|break,
     * misses: times a time preference wasn't met, late: games already in
     * the last slot). Returns game index => slot index (null if no room).
     *
     * Cost per game: time preference (early wants the first slot, late the
     * last; weighted up by past misses), the night's last slot shared
     * around, a nudge towards earlier slots so the night finishes sooner,
     * and for a team playing twice: never overlapping, and back-to-back or
     * with a break as it asked.
     */
    public static function assign_slots($games, $slots, $teams) {
        $g_count = count($games);
        $s_count = count($slots);
        if ($g_count === 0) {
            return array();
        }
        // The night runs from the first slot until the games are done: the
        // earliest slots (all courts at each time) are the night; "later"
        // means the last games of that night, not an empty 10pm court. Any
        // later slot costs extra, used only if a double-header needs it.
        $order = array_keys($slots);
        usort($order, function ($x, $y) use ($slots) { return array($slots[$x]['start'], $x) <=> array($slots[$y]['start'], $y); });
        $night_end = $slots[$order[min($g_count, $s_count) - 1]]['start'];
        $starts = array_values(array_unique(array_map(function ($s) { return $s['start']; }, $slots)));
        sort($starts);
        $night_starts = array_values(array_filter($starts, function ($s) use ($night_end) { return $s <= $night_end; }));
        $last_start = end($night_starts);
        $rank = array();
        $beyond = array();
        foreach ($slots as $i => $s) {
            $pos = array_search($s['start'], $starts, true);
            $rank[$i] = count($night_starts) > 1 ? min(1, $pos / (count($night_starts) - 1)) : 0;
            $beyond[$i] = max(0, $pos - (count($night_starts) - 1));
        }
        $mins = function ($hm) { list($h, $m) = array_map('intval', explode(':', $hm)); return $h * 60 + $m; };

        $slot_cost = array();
        foreach ($games as $gi => $game) {
            foreach ($slots as $si => $s) {
                $cost = 2.5 * $beyond[$si];
                foreach (array($game[0], $game[1]) as $t) {
                    $info = isset($teams[$t]) ? $teams[$t] : array();
                    $time = isset($info['time']) ? $info['time'] : 'any';
                    $weight = 1 + 0.5 * (isset($info['misses']) ? $info['misses'] : 0);
                    if ($time === 'early') {
                        $cost += 2 * $rank[$si] * $weight;
                    } elseif ($time === 'late') {
                        $cost += 2 * (1 - $rank[$si]) * $weight;
                    }
                    if ($s['start'] >= $last_start && $time !== 'late' && count($night_starts) > 1) {
                        $cost += 0.6 * (isset($info['late']) ? $info['late'] : 0);
                    }
                }
                $slot_cost[$gi][$si] = $cost;
            }
        }
        $total = function ($at) use ($games, $slots, $teams, $slot_cost, $mins) {
            $cost = 0.0;
            $by_team = array();
            foreach ($at as $gi => $si) {
                if ($si === null) {
                    $cost += 1000;
                    continue;
                }
                $cost += $slot_cost[$gi][$si];
                $by_team[$games[$gi][0]][] = $si;
                $by_team[$games[$gi][1]][] = $si;
            }
            foreach ($by_team as $t => $list) {
                for ($x = 0; $x < count($list); $x++) {
                    for ($y = $x + 1; $y < count($list); $y++) {
                        $a = $slots[$list[$x]];
                        $b = $slots[$list[$y]];
                        if ($mins($a['start']) < $mins($b['end']) && $mins($b['start']) < $mins($a['end'])) {
                            $cost += 1000; // can't be in two places
                            continue;
                        }
                        $len = max($mins($a['end']) - $mins($a['start']), $mins($b['end']) - $mins($b['start']));
                        $gap = abs($mins($a['start']) - $mins($b['start']));
                        $shape = isset($teams[$t]['shape']) ? $teams[$t]['shape'] : 'back';
                        if ($shape === 'back' && $gap !== $len) {
                            $cost += 3;
                        } elseif ($shape === 'break' && $gap < 2 * $len) {
                            $cost += 3;
                        }
                    }
                }
            }
            return $cost;
        };

        // Start: slots in time order, games in the order given.
        $at = array();
        foreach ($games as $gi => $game) {
            $at[$gi] = isset($order[$gi]) ? $order[$gi] : null;
        }
        $best = $total($at);
        for ($pass = 0; $pass < 60; $pass++) {
            $improved = false;
            for ($x = 0; $x < $g_count; $x++) {
                for ($y = $x + 1; $y < $g_count; $y++) {
                    $try = $at;
                    list($try[$x], $try[$y]) = array($at[$y], $at[$x]);
                    $v = $total($try);
                    if ($v < $best - 1e-9) {
                        $at = $try;
                        $best = $v;
                        $improved = true;
                    }
                }
                $used = array_flip(array_filter($at, function ($s) { return $s !== null; }));
                for ($si = 0; $si < $s_count; $si++) {
                    if (isset($used[$si])) {
                        continue;
                    }
                    $try = $at;
                    $try[$x] = $si;
                    $v = $total($try);
                    if ($v < $best - 1e-9) {
                        $at = $try;
                        $best = $v;
                        $improved = true;
                        $used = array_flip(array_filter($at, function ($s) { return $s !== null; }));
                    }
                }
            }
            if (!$improved) {
                break;
            }
        }
        return $at;
    }

    // ------------------------------------------------------------------
    // A real night
    // ------------------------------------------------------------------

    /**
     * Everything the engine needs to know about the season before this
     * night: meetings, byes, home games, late slots, missed time
     * preferences, double-headers, and (Ranked) ratings as of the night before.
     */
    public static function context($night) {
        $season = $night->season;
        $round = array();
        $i = 0;
        foreach (RSL_Courts::nights($season) as $n) {
            if ($n->type !== 'bye') {
                $round[intval($n->id)] = ++$i;
            }
        }
        $this_round = isset($round[intval($night->id)]) ? $round[intval($night->id)] : $i + 1;
        $teams = array();
        foreach (RSL_Teams::list_for_season($season) as $t) {
            $teams[intval($t->id)] = $t;
        }
        $ctx = array('night' => $night, 'round' => $this_round, 'teams' => $teams, 'meetings' => array(), 'byes' => array(), 'home' => array(),
            'late' => array(), 'misses' => array(), 'doubles' => array(), 'ratings' => null);

        $by_night = array();
        foreach (RSL_Fixtures::games_for_season($season) as $g) {
            if ($g->night_date >= $night->night_date || !isset($round[intval($g->night_id)])) {
                continue;
            }
            $by_night[intval($g->night_id)][] = $g;
            $ago = $this_round - $round[intval($g->night_id)];
            $key = self::meeting_key(intval($g->home_team_id), intval($g->away_team_id));
            if (!isset($ctx['meetings'][$key])) {
                $ctx['meetings'][$key] = array('count' => 0, 'last' => $ago);
            }
            $ctx['meetings'][$key]['count']++;
            $ctx['meetings'][$key]['last'] = min($ctx['meetings'][$key]['last'], $ago);
            $ctx['home'][intval($g->home_team_id)] = (isset($ctx['home'][intval($g->home_team_id)]) ? $ctx['home'][intval($g->home_team_id)] : 0) + 1;
        }
        $weeks_off = RSL_Requests::weeks_off($season);
        foreach ($by_night as $night_id => $games) {
            $played = array();
            $off = isset($weeks_off[$games[0]->night_date]) ? $weeks_off[$games[0]->night_date] : array();
            // A team that said it couldn't make it (its game since removed) didn't sit out for the fixtures' sake either.
            $off += RSL_Requests::for_night($night_id, 'cant_make', array('active', 'handled'));
            foreach (array_keys(RSL_Teams::DIVISIONS) as $division) {
                $in = array_filter($games, function ($g) use ($division) { return $g->division === $division; });
                if (!$in) {
                    continue;
                }
                $starts = array_values(array_unique(array_map(function ($g) { return RSL_Courts::hm($g->start_time); }, $in)));
                sort($starts);
                foreach ($in as $g) {
                    $pos = array_search(RSL_Courts::hm($g->start_time), $starts, true);
                    $r = count($starts) > 1 ? $pos / (count($starts) - 1) : 0.5;
                    foreach (array(intval($g->home_team_id), intval($g->away_team_id)) as $t) {
                        $played[$t] = (isset($played[$t]) ? $played[$t] : 0) + 1;
                        if (count($starts) > 1 && $r === 1) {
                            $ctx['late'][$t] = (isset($ctx['late'][$t]) ? $ctx['late'][$t] : 0) + 1;
                        }
                        $pref = isset($teams[$t]) ? $teams[$t]->pref_time : 'any';
                        if (($pref === 'early' && $r > 0.5) || ($pref === 'late' && $r < 0.5)) {
                            $ctx['misses'][$t] = (isset($ctx['misses'][$t]) ? $ctx['misses'][$t] : 0) + 1;
                        }
                    }
                }
                foreach ($teams as $t) {
                    $joined = RSL_Stats::joined_date($t); // approved (or created) by then — not just applied
                    if ($t->division === $division && empty($played[intval($t->id)]) && empty($off[intval($t->id)]) && ($joined === '' || $joined <= $games[0]->night_date)) {
                        $ctx['byes'][intval($t->id)] = (isset($ctx['byes'][intval($t->id)]) ? $ctx['byes'][intval($t->id)] : 0) + 1;
                    }
                }
            }
            foreach ($played as $t => $count) {
                if ($count > 1) {
                    $ctx['doubles'][$t] = (isset($ctx['doubles'][$t]) ? $ctx['doubles'][$t] : 0) + 1;
                }
            }
        }
        // The FIXTURES rating (seeded by the captain's level), never the
        // ladder's: week one is paired by level, and explain_night()'s set
        // chances (admin only) come from the same numbers.
        $ratings = RSL_Ratings::compute($season, date('Y-m-d', strtotime($night->night_date . ' -1 day')), 'fixtures');
        $ctx['ratings'] = array();
        foreach ($ratings as $id => $r) {
            $ctx['ratings'][$id] = array('rating' => $r['rating'], 'sd' => $r['sd']);
        }
        return $ctx;
    }

    /**
     * The proposed fixtures for a night, without saving anything:
     * array(divisions => division => array(games => list of array(home,
     * away, slot, kind, info), out => team_id => reason, notes => list)).
     */
    public static function plan($night_id) {
        $night = RSL_Courts::get_night($night_id);
        if (!$night || $night->type === 'bye') {
            return new WP_Error('rsl_night', 'There are no games on that night.');
        }
        $w = self::weights();
        $ctx = self::context($night);
        $all_slots = RSL_Courts::slots_for_night($night->id);
        $salt = $night->night_date;
        $plan = array('night' => $night, 'divisions' => array());
        $off = RSL_Requests::for_night($night->id, 'off');
        $asked_double = RSL_Requests::for_night($night->id, 'double');

        foreach (array_keys(RSL_Teams::DIVISIONS) as $division) {
            $all_ids = array_keys(array_filter($ctx['teams'], function ($t) use ($division) { return $t->division === $division; }));
            $d = array('games' => array(), 'out' => array(), 'notes' => array());
            $ids = array();
            foreach ($all_ids as $id) {
                if (isset($off[$id])) {
                    $d['out'][$id] = 'Week off (requested)';
                } elseif (RSL_Payments::blocked($id)) {
                    $d['out'][$id] = 'Owes ' . RSL_Payments::money(RSL_Payments::owing($id)) . ' — not fixtured until paid';
                } else {
                    $ids[] = $id;
                }
            }
            if (!$ids) {
                if ($d['out']) {
                    $plan['divisions'][$division] = $d;
                }
                continue;
            }
            $slots = array_values(array_filter($all_slots, function ($s) use ($division) { return $s['division'] === $division; }));
            if (!$slots) {
                $d['notes'][] = 'No courts host ' . RSL_Teams::DIVISIONS[$division] . ' games this night.';
                foreach ($ids as $id) { $d['out'][$id] = 'No courts'; }
                $plan['divisions'][$division] = $d;
                continue;
            }
            $ratings = $division === 'ranked' ? $ctx['ratings'] : null;
            // Odd numbers: the spare team should be one a double-header team
            // can play, so double-header teams are the last to get the bye.
            $byes = $ctx['byes'];
            if (count($ids) % 2 === 1) {
                foreach ($ids as $id) {
                    if (isset($asked_double[$id]) || !empty($ctx['teams'][$id]->pref_double)) {
                        $byes[$id] = (isset($byes[$id]) ? $byes[$id] : 0) + 2;
                    }
                }
            }
            $result = self::pair_division($ids, $ratings, $ctx['meetings'], $byes, $w, $salt);
            $pairs = $result['pairs'];

            // More games than slots: the most expensive pairings sit out.
            usort($pairs, function ($x, $y) { return $x[2]['cost'] <=> $y[2]['cost']; });
            while (count($pairs) > count($slots)) {
                $drop = array_pop($pairs);
                $d['out'][$drop[0]] = $d['out'][$drop[1]] = 'Not enough courts';
            }
            $games = array();
            $tonight = array();
            foreach ($pairs as $p) {
                $games[] = array($p[0], $p[1], 'single', $p[2]);
                $tonight[$p[0]][] = $p[1];
                $tonight[$p[1]][] = $p[0];
            }
            $spare = count($slots) - count($games);
            // Double-header teams: asked for two games this week, or happy to all season.
            $opted = array_values(array_filter($ids, function ($id) use ($ctx, $asked_double) { return isset($asked_double[$id]) || !empty($ctx['teams'][$id]->pref_double); }));

            // Odd numbers: the team without a game gets one against a double-header team, if any.
            if ($result['bye'] !== null) {
                $bye = $result['bye'];
                $best = null;
                if ($spare > 0) {
                    foreach ($opted as $o) {
                        if ($o === $bye || (isset($tonight[$o]) && count($tonight[$o]) > 1)) {
                            continue;
                        }
                        $c = self::pair_cost($bye, $o, $ratings, $ctx['meetings'], $w, $salt);
                        // Never a mismatch just to avoid a bye: sitting out is shared around instead.
                        if ($c['count'] < 2 && $c['recent'] < $w['last_week'] && $c['closeness'] <= $w['double_max_asked']
                            && ($best === null || $c['cost'] < $best[1]['cost'])) {
                            $best = array($o, $c);
                        }
                    }
                }
                if ($best) {
                    $games[] = array($bye, $best[0], 'fill', $best[1]);
                    $tonight[$bye][] = $best[0];
                    $tonight[$best[0]][] = $bye;
                    $spare--;
                } else {
                    $d['out'][$bye] = 'Bye (odd number of teams)';
                }
            }

            // Spare slots: extra games for double-header teams, fairest first.
            $candidates = array();
            foreach ($opted as $x => $a) {
                foreach (array_slice($opted, $x + 1) as $b) {
                    if (in_array($b, isset($tonight[$a]) ? $tonight[$a] : array(), true)) {
                        continue;
                    }
                    $c = self::pair_cost($a, $b, $ratings, $ctx['meetings'], $w, $salt);
                    // An optional extra game is never a third meeting, a repeat of last week, or lopsided
                    // (a team that asked for two games this week accepts a wider gap).
                    $max = isset($asked_double[$a]) || isset($asked_double[$b]) ? $w['double_max_asked'] : $w['double_max'];
                    if ($c['count'] >= 2 || $c['recent'] >= $w['last_week'] || $c['closeness'] > $max) {
                        continue;
                    }
                    $fair = 0.5 * ((isset($ctx['doubles'][$a]) ? $ctx['doubles'][$a] : 0) + (isset($ctx['doubles'][$b]) ? $ctx['doubles'][$b] : 0));
                    // Teams that asked for two games this week go first.
                    $fair -= 3 * ((isset($asked_double[$a]) ? 1 : 0) + (isset($asked_double[$b]) ? 1 : 0));
                    $candidates[] = array($a, $b, $c, $c['cost'] + $fair);
                }
            }
            usort($candidates, function ($x, $y) { return $x[3] <=> $y[3]; });
            foreach ($candidates as $cand) {
                if ($spare <= 0) {
                    break;
                }
                list($a, $b, $c) = $cand;
                if ((isset($tonight[$a]) ? count($tonight[$a]) : 0) > 1 || (isset($tonight[$b]) ? count($tonight[$b]) : 0) > 1) {
                    continue;
                }
                $games[] = array($a, $b, 'double', $c);
                $tonight[$a][] = $b;
                $tonight[$b][] = $a;
                $spare--;
            }

            // Home/away: whoever has had fewer home games is home.
            foreach ($games as &$g) {
                $ha = isset($ctx['home'][$g[0]]) ? $ctx['home'][$g[0]] : 0;
                $hb = isset($ctx['home'][$g[1]]) ? $ctx['home'][$g[1]] : 0;
                if ($hb < $ha || ($hb === $ha && (crc32($salt . $g[0] . '-' . $g[1]) % 2))) {
                    list($g[0], $g[1]) = array($g[1], $g[0]);
                    if ($g[3]['p'] !== null) {
                        $g[3]['p'] = 1 - $g[3]['p'];
                    }
                }
            }
            unset($g);

            $team_info = array();
            foreach ($ids as $id) {
                $t = $ctx['teams'][$id];
                $team_info[$id] = array('time' => $t->pref_time, 'shape' => $t->pref_double_shape,
                    'misses' => isset($ctx['misses'][$id]) ? $ctx['misses'][$id] : 0, 'late' => isset($ctx['late'][$id]) ? $ctx['late'][$id] : 0);
            }
            $at = self::assign_slots(array_map(function ($g) { return array($g[0], $g[1]); }, $games), $slots, $team_info);
            foreach ($games as $gi => $g) {
                $d['games'][] = array('home' => $g[0], 'away' => $g[1], 'kind' => $g[2], 'info' => $g[3],
                    'slot' => $at[$gi] === null ? null : $slots[$at[$gi]]);
            }
            usort($d['games'], function ($x, $y) {
                return array($x['slot'] ? $x['slot']['start'] : '99', $x['slot'] ? $x['slot']['court_id'] : 0) <=> array($y['slot'] ? $y['slot']['start'] : '99', $y['slot'] ? $y['slot']['court_id'] : 0);
            });
            $plan['divisions'][$division] = $d;
        }
        return $plan;
    }

    /**
     * Make (or remake) a night's fixtures automatically. Refused once the
     * night is published or any result is in. Returns array(created, out, notes) or WP_Error.
     */
    public static function generate($night_id) {
        $night = RSL_Courts::get_night($night_id);
        if (!$night || $night->type === 'bye') {
            return new WP_Error('rsl_night', 'There are no games on that night.');
        }
        if (RSL_Fixtures::is_published($night)) {
            return new WP_Error('rsl_published', 'This night\'s fixtures are already out — unpublish them first to remake them.');
        }
        $existing = RSL_Fixtures::games_for_night($night->id);
        foreach ($existing as $g) {
            if ($g->status !== 'scheduled') {
                return new WP_Error('rsl_results', 'Results are already in for this night, so its fixtures can\'t be remade.');
            }
        }
        RSL_Requests::clear_unpaid($night->id);
        $plan = self::plan($night->id);
        if (is_wp_error($plan)) {
            return $plan;
        }
        // Supervisors are tidied once the new games are in, so a venue that
        // still has games after the remake keeps its supervisor.
        foreach ($existing as $g) {
            RSL_Fixtures::delete_game($g->id, false);
        }
        foreach ($plan['divisions'] as $d) {
            foreach ($d['out'] as $team_id => $why) {
                if (strpos($why, 'Owes ') === 0) {
                    RSL_Requests::record_unpaid($team_id, $night->id);
                }
            }
        }
        $created = 0;
        $out = array();
        $notes = array();
        foreach ($plan['divisions'] as $division => $d) {
            $notes = array_merge($notes, $d['notes']);
            $out += $d['out'];
            foreach ($d['games'] as $g) {
                if (!$g['slot']) {
                    $notes[] = 'No slot left for one ' . RSL_Teams::DIVISIONS[$division] . ' game.';
                    continue;
                }
                $r = RSL_Fixtures::add_game($night->id, array('home' => $g['home'], 'away' => $g['away'], 'court_id' => $g['slot']['court_id'], 'start' => $g['slot']['start']));
                if (is_wp_error($r)) {
                    $notes[] = $r->get_error_message();
                } else {
                    $created++;
                }
            }
        }
        RSL_Fixtures::drop_idle_supervisors($night->id);
        return array('created' => $created, 'out' => $out, 'notes' => $notes);
    }

    /**
     * Why a saved game looks the way it does (for the admin fixture board):
     * array(p (home wins a set), edge, count (earlier meetings), last (rounds ago), doubles, time_ok per team).
     */
    public static function explain_night($night_id) {
        $night = RSL_Courts::get_night($night_id);
        if (!$night) {
            return array();
        }
        $ctx = self::context($night);
        $w = self::weights();
        $games = RSL_Fixtures::games_for_night($night->id);
        $count = array();
        $starts = array();
        foreach ($games as $g) {
            $count[intval($g->home_team_id)] = (isset($count[intval($g->home_team_id)]) ? $count[intval($g->home_team_id)] : 0) + 1;
            $count[intval($g->away_team_id)] = (isset($count[intval($g->away_team_id)]) ? $count[intval($g->away_team_id)] : 0) + 1;
            $starts[$g->division][] = RSL_Courts::hm($g->start_time);
        }
        $out = array();
        foreach ($games as $g) {
            $h = intval($g->home_team_id);
            $a = intval($g->away_team_id);
            $c = self::pair_cost($h, $a, $g->division === 'ranked' ? $ctx['ratings'] : null, $ctx['meetings'], $w);
            $s = array_values(array_unique($starts[$g->division]));
            sort($s);
            $pos = array_search(RSL_Courts::hm($g->start_time), $s, true);
            $r = count($s) > 1 ? $pos / (count($s) - 1) : 0.5;
            $time_ok = array();
            foreach (array($h, $a) as $t) {
                $pref = isset($ctx['teams'][$t]) ? $ctx['teams'][$t]->pref_time : 'any';
                $time_ok[$t] = $pref === 'any' ? null : (($pref === 'early' && $r <= 0.5) || ($pref === 'late' && $r >= 0.5));
            }
            $out[intval($g->id)] = array('p' => $c['p'], 'edge' => $c['edge'], 'count' => $c['count'], 'last' => $c['last'],
                'double' => array($h => $count[$h] > 1, $a => $count[$a] > 1), 'time_ok' => $time_ok);
        }
        $playing = array_keys($count);
        $out['_resting'] = array_values(array_diff(array_keys($ctx['teams']), $playing));
        return $out;
    }
}
