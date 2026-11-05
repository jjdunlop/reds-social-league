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
 *   link        (Ranked, from its third night with results) while the
 *               season's results split the teams into groups that no chain
 *               of games connects, a game between two groups costs 2.25 (a
 *               75/25 game) less for each of the division's nights in a row
 *               they've been split — so the ladder, which only has results
 *               to go on, gets to compare them (link()).
 *
 * Sitting out is not a cost; it's a rule (sit_out(), 0.18.2). When a
 * division has more teams than the night's games can hold (an odd number,
 * or not enough courts), the teams that sit out are the ones with the
 * lowest sit-out count (sat_out_counts()): each night sat out counts 1,
 * and each night away (a week off, a can't-make-it, a night left out for
 * owing, a night before the team joined) counts that night's share of
 * sit-outs in its division, so the count measures sit-outs per night
 * available. A team that sits out has a count no more than $w['sit_level']
 * (half a night) above any team in its division that plays: counts that
 * close are level. For teams available every night the counts are whole
 * numbers, so among them a team out is never above a team that plays. A
 * team that sat out its last night doesn't sit out again while a team in
 * its division that has never sat out plays. A night with a double-header
 * is one night played. Among level teams, the draw's whole cost for the
 * teams that play decides, link term included: for who sits out, the link
 * term only decides between level teams, and never puts a team out ahead
 * of one whose count is lower by more than sit_level.
 * On an odd night with a spare court the odd team plays a team that opted
 * into double-headers when one qualifies (the fill game), and then nobody
 * sits out, unless every way to give that game needs a third meeting or
 * last week's opponent elsewhere ($w['odd_sit']). Among level teams a
 * double-header team is picked last to be the odd one out, unless that
 * costs more than $w['odd_double'] in the night's games.
 *
 * Unranked has no ratings, so only variety counts: least-played, longest
 * ago — the usual social-league round robin, but robust to late joiners
 * and weeks off.
 *
 * Then slots: games go into the night's courts and times, honouring
 * early/late preferences (a team that missed out gets priority next time),
 * sharing the last slot around, and double-header shapes (back-to-back or
 * with a break). Spare slots become double-headers for teams that opted in;
 * they are never handed out to make up for a sit-out. When the division's
 * slots that night all overlap (one start time), nobody can play twice, so
 * there is no fill game and no double-header (can_play_twice()).
 *
 * The core (pair_division, sit_out, fill_for, match, match_out,
 * sat_out_counts, can_play_twice, assign_slots, set_win_probability) is
 * pure — no database — so it can be tested and
 * simulated over whole seasons (dev/simulate-pairing.php). plan()/generate()
 * wrap it for a real night.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RSL_Pairing {

    const EDGE_UNIT = 1 / 3;  // a 67/33 set-win chance: the favourite's edge (0.67 − 0.33) that costs 1
    const EXACT_LIMIT = 18;   // up to this many teams (plus sit-out places) the matching is exact; above, a strong local search
    const NEVER = 1e9;        // the cost of a pairing that may not happen (match_out(): a team that must play, sitting out)

    /** The trade-offs, in "2-to-1 matchups" (1.0 = as bad as a 67/33 game). */
    public static function weights() {
        return apply_filters('rsl_pairing_weights', array(
            'closeness' => 1.0,       // × (edge ÷ EDGE_UNIT)², edge = |chance A − chance B| of winning a set
            'rematch' => 1.0,         // a second meeting
            'extra_meeting' => 25.0,  // each meeting after the second (simulated: removes third meetings at almost no cost in closeness)
            'two_weeks' => 1.0,       // met two rounds ago
            'last_week' => 1000.0,    // met last round
            'odd_double' => 8.0,      // odd night, among teams tied on nights sat out: a double-header team costs this much more to be the odd one out (the old +2 on its count)
            'odd_sit' => 20.0,        // odd night with a spare court where some team could have a fill game: the odd team sitting out instead costs this much, above any normal game but below a third meeting or last week's opponent, so a fill game is taken unless it needs one of those
            'sit_level' => 0.5,       // who sits out: sit-out counts within this of each other count as level (a night away adds a fraction); at most 0.5, so whole counts are never level (simulated against 0 and 0.25: fairer per night available and closer games, README 0.18.2)
            'double_max' => 2.25,     // a spare-slot double-header only if its closeness cost is at most this (75/25)
            'double_max_asked' => 4.0, // ...or this when a team asked for two games that week (about 83/17); also the limit for the odd team's fill game
            'link' => 2.25,           // Ranked: a game between groups the results haven't connected costs this much less (a 75/25 game), × nights in a row split; for who sits out it only decides between teams level on the sit-out count, never putting a team out ahead of one whose count is lower by more than sit_level
            'link_from' => 3,         // ...from the division's 3rd night (nights without its results don't count; after one night every game is a group of its own; simulated)
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
     * $link: link()'s array(groups, bonus), or null (Unranked, or none).
     * Returns array(cost, p (A wins a set), edge, closeness, repeat, recent,
     * link (minus the bonus when A and B are in different groups, else 0), count, last).
     */
    public static function pair_cost($a, $b, $ratings, $meetings, $w, $salt = '', $link = null) {
        $out = array('cost' => 0.0, 'p' => null, 'edge' => null, 'closeness' => 0.0, 'repeat' => 0.0, 'recent' => 0.0, 'link' => 0.0, 'count' => 0, 'last' => null);
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
        if ($link && !empty($link['bonus']) && isset($link['groups'][$a]) && isset($link['groups'][$b]) && $link['groups'][$a] !== $link['groups'][$b]) {
            $out['link'] = -$link['bonus'];
        }
        // A tiny, repeatable tie-break so equal options don't always fall the same way.
        $jitter = (crc32($salt . '|' . $key) % 1000) / 100000;
        $out['cost'] = $out['closeness'] + $out['repeat'] + $out['recent'] + $jitter + $out['link'];
        return $out;
    }

    /**
     * The link term for one Ranked night (pure). $ids: the division's team
     * ids; $games_by_round: round => list of array(home, away), the
     * published, PLAYED games of earlier rounds (forfeits don't link);
     * $this_round: the season round being paired (context()'s numbering;
     * only the rounds before it are read).
     *
     * groups(): the teams with at least one of those games, joined into
     * groups by any chain of them; a team with no game yet is left out.
     * Nights are the division's own: the earlier rounds with at least one
     * of those games, then this one. A round without any — no Ranked courts
     * that night, a night called off, results not in yet, a division that
     * started late — doesn't count, so it never adds to the bonus or brings
     * the term on early. From the division's night $w['link_from'], k = its
     * nights in a row, counting back from this one to link_from, paired
     * while those groups were 2 or more. Returns array(groups => team =>
     * group id (this night's; empty unless split), bonus => $w['link'] × k,
     * nights => k). k = 0 (one group, or before link_from) changes nothing.
     */
    public static function link($ids, $games_by_round, $this_round, $w) {
        $out = array('groups' => array(), 'bonus' => 0.0, 'nights' => 0);
        $from = max(1, isset($w['link_from']) ? intval($w['link_from']) : 3);
        if (empty($w['link'])) {
            return $out;
        }
        $in = array_flip(array_map('intval', $ids));
        $before = array(); // the division's night n is paired from the games of rounds before $before[n - 1]
        foreach ($games_by_round as $round => $games) {
            if (intval($round) >= $this_round) {
                continue;
            }
            foreach ($games as $g) {
                if (isset($in[intval($g[0])]) && isset($in[intval($g[1])])) {
                    $before[] = intval($round);
                    break;
                }
            }
        }
        sort($before);
        $before[] = intval($this_round);
        $nights = count($before);
        $k = 0;
        for ($n = $nights; $n >= $from; $n--) {
            $groups = self::groups($ids, $games_by_round, $before[$n - 1]);
            if (count(array_unique($groups)) < 2) {
                break;
            }
            if ($n === $nights) {
                $out['groups'] = $groups;
            }
            $k++;
        }
        $out['bonus'] = $w['link'] * $k;
        $out['nights'] = $k;
        return $out;
    }

    /**
     * Groups of teams joined by a chain of games in rounds before $before
     * (pure, union-find): team => group id (the group's lowest team id), for
     * the teams in $ids with at least one such game against another of $ids.
     */
    public static function groups($ids, $games_by_round, $before) {
        $parent = array();
        foreach ($ids as $t) {
            $parent[intval($t)] = intval($t);
        }
        $find = function ($x) use (&$parent) {
            while ($parent[$x] !== $x) {
                $parent[$x] = $parent[$parent[$x]];
                $x = $parent[$x];
            }
            return $x;
        };
        $played = array();
        foreach ($games_by_round as $round => $games) {
            if ($round >= $before) {
                continue;
            }
            foreach ($games as $g) {
                $a = intval($g[0]);
                $b = intval($g[1]);
                if (!isset($parent[$a]) || !isset($parent[$b])) {
                    continue;
                }
                $played[$a] = $played[$b] = true;
                $ra = $find($a);
                $rb = $find($b);
                if ($ra !== $rb) {
                    // The lower id becomes the root, so a group's id is its lowest team id.
                    if ($ra < $rb) {
                        $parent[$rb] = $ra;
                    } else {
                        $parent[$ra] = $rb;
                    }
                }
            }
        }
        $out = array();
        foreach (array_keys($played) as $t) {
            $out[$t] = $find($t);
        }
        ksort($out);
        return $out;
    }

    /**
     * Pair one division's teams for a night (pure). $ids: team ids (Ranked:
     * any order; strongest first helps the search). $byes: id => sit-out
     * count so far (sat_out_counts()'s byes; may be fractions). $link:
     * link()'s result for Ranked (null: no link term). $room: the night's
     * slots for the division (null: no limit). $opted: the teams happy to
     * play two games tonight (asked this week, or opted in for the season),
     * for the odd team's fill game; plan() passes none when nobody can play
     * twice (can_play_twice()). $doubles: id => double-header nights so far,
     * so fill games are shared (fill_for()). $guard: array(sat => id =>
     * nights actually sat out, last => id => true when it sat out its last
     * night), sat_out_counts()'s, for sit_out()'s twice-in-a-row guard
     * (empty: no guard, as for counts that are nights sat out alone).
     *
     * Who sits out follows sit_out(): of its options (each a set of teams
     * that must sit out and teams that may), the one whose best matching
     * costs least. Returns array(pairs => list of array(a, b, info), bye =>
     * the odd team on a night with a spare slot (with or without a fill
     * game) or null, fill => fill_for()'s array(partner, info, rank) when
     * the odd team plays a double-header team, out => id => 'odd' (the odd
     * team, no fill game) or 'courts' (not enough courts), first => id =>
     * true for a team out that went first under the guard although its
     * count was higher (sit_out()'s first), total => the matching's total:
     * its games, plus each sit-out's tie-break cost).
     */
    public static function pair_division($ids, $ratings, $meetings, $byes, $w, $salt = '', $link = null, $room = null, $opted = array(), $doubles = array(), $guard = array()) {
        $ids = array_values(array_map('intval', $ids));
        if ($ratings !== null) {
            usort($ids, function ($x, $y) use ($ratings) {
                return (isset($ratings[$y]) ? $ratings[$y]['rating'] : 0) <=> (isset($ratings[$x]) ? $ratings[$x]['rating'] : 0);
            });
        }
        $info = array();
        $cost = function ($a, $b) use (&$info, $ratings, $meetings, $w, $salt, $link) {
            $c = self::pair_cost($a, $b, $ratings, $meetings, $w, $salt, $link);
            $info[self::meeting_key($a, $b)] = $c;
            return $c['cost'];
        };
        $n = count($ids);
        $odd_night = $n % 2 === 1 && ($room === null || $n < 2 * intval($room));
        $fills = array();
        if ($odd_night) {
            $opted = array_values(array_intersect(array_map('intval', $opted), $ids));
            foreach ($ids as $t) {
                $f = self::fill_for($t, $opted, $ratings, $meetings, $w, $salt, $doubles);
                if ($f) {
                    $fills[$t] = $f;
                }
            }
        }
        $rule = self::sit_out($ids, $byes, $room, $fills, $odd_night ? $opted : array(), $w, $salt, $guard);
        $out = array('pairs' => array(), 'bye' => null, 'fill' => null, 'out' => array(), 'first' => array(), 'total' => 0.0);
        if ($rule['k'] === 0) {
            $m = self::match($ids, $cost); // an even number with room for every game
            $m['out'] = array();
        } else {
            // The cheapest of the rule's options (one, unless counts a little apart are level, or the guard splits it).
            $m = null;
            foreach ($rule['options'] as $o) {
                $play = array_values(array_diff($ids, $o['must']));
                $try = self::match_out($play, $cost, $rule['k'] - count($o['must']), $o['may']);
                $try['out'] = array_merge($o['must'], $try['out']);
                $try['total'] += $o['base'];
                if ($m === null || $try['total'] < $m['total'] - 1e-9) {
                    $m = $try;
                }
            }
        }
        foreach ($m['pairs'] as $p) {
            $out['pairs'][] = array($p[0], $p[1], $info[self::meeting_key($p[0], $p[1])]);
        }
        $out['total'] = $m['total'];
        $sitting = $m['out'];
        if ($rule['why'] === 'courts') {
            foreach ($sitting as $t) {
                $out['out'][$t] = 'courts';
            }
        } elseif ($sitting) {
            $out['bye'] = $sitting[0];
            // A team with a fill game only ever stands as the odd team with that game (sit_out()).
            if ($rule['why'] === 'fill' && isset($fills[$sitting[0]])) {
                $out['fill'] = $fills[$sitting[0]];
            } else {
                $out['out'][$sitting[0]] = 'odd';
            }
        }
        $out['first'] = array_intersect_key($rule['first'], $out['out']);
        return $out;
    }

    /**
     * Who may sit out tonight (pure; the rule since 0.18.2). $ids: the
     * division's available teams; $byes: id => sit-out count so far
     * (sat_out_counts()'s byes: 1 a night sat out, a night is one night
     * however many games, plus a share for each night away, so it may be a
     * fraction); $room: slots (null: no limit); $fills: id => fill_for()'s
     * game, for the teams the odd team could be with a fill game (odd nights
     * with a spare slot only); $opted: the double-header teams; $guard:
     * array(sat => id => nights actually sat out, last => id => true when
     * the team sat out its last night), or empty for no guard.
     *
     * k = n − 2 × room teams sit out when that's more than 0 (not enough
     * courts), else n mod 2 (an odd number). The k who sit out are the teams
     * with the lowest counts: a team sits out only when its count is at most
     * $w['sit_level'] above every team that plays (counts that close are
     * level; at most 0.5, so whole counts are never level with each other,
     * and a team available every night, whose count is whole, never sits out
     * while one with a lower count plays). Each option is a top count T at
     * or above the line (the k-th lowest count): the teams more than
     * sit_level below T must sit out, the teams from there up to T may (they
     * share the places left), the rest play. With sit_level 0 that is the
     * one option, the line itself.
     *
     * An odd night with a spare slot is different, as no one need sit out.
     * When some team could be the odd team with a fill game, the odd team
     * may be one of those with a count within sit_level of the lowest among
     * them (it plays its fill game; its rank, fill_for(), is its cost), or
     * a team without one within sit_level of the division's lowest (it sits
     * out), at $w['odd_sit']: more than any normal game, less than a third
     * meeting or last week's opponent. So a fill game is taken unless every
     * way to give it needs one of those elsewhere in the night. With no fill
     * game for anyone, the rule above applies (k = 1). On either kind of odd
     * night a double-header team costs $w['odd_double'] more to be the odd
     * one out.
     *
     * The guard: a team that sat out its last night doesn't sit out again
     * while a team in the division that has never sat out plays (a count
     * with shares of nights away can put a team that has never sat out
     * above one that just did). Within the rule, an option keeps it either
     * with none of those teams sitting out, or with every team that has
     * never sat out sitting out too. When no option can, the teams that have
     * never sat out go first: their counts are taken as just below the
     * lowest of the teams that sat out their last night (first => those
     * teams), and the rule runs on those counts.
     *
     * Returns array(k, options => list of array(must => ids that sit out,
     * may => id => tie-break cost for the teams that may sit out (or be the
     * odd team), base => the must teams' tie-break costs), must / may => the
     * first option's, first => id => true for a team moved first by the
     * guard, why => 'courts' | 'odd' | 'fill' (some team has a fill game:
     * the odd team plays its fill game when it has one, else it sits out)).
     */
    public static function sit_out($ids, $byes, $room, $fills, $opted, $w, $salt = '', $guard = array()) {
        $ids = array_values(array_map('intval', $ids));
        $n = count($ids);
        $short = $room !== null && $n > 2 * max(0, intval($room));
        $k = $short ? $n - 2 * max(0, intval($room)) : $n % 2;
        $out = array('k' => $k, 'options' => array(), 'must' => array(), 'may' => array(), 'first' => array(), 'why' => $short ? 'courts' : 'odd');
        if ($k === 0) {
            return $out;
        }
        if (!$short && $fills) {
            $out['why'] = 'fill';
        }
        $level = isset($w['sit_level']) ? min(0.5, max(0.0, (float) $w['sit_level'])) : 0.0;
        $c = array();
        foreach ($ids as $t) {
            $c[$t] = isset($byes[$t]) ? (float) $byes[$t] : 0.0;
        }
        $never = array();
        $last = array();
        if ($guard) {
            foreach ($ids as $t) {
                if (empty($guard['sat'][$t])) {
                    $never[] = $t;
                } elseif (!empty($guard['last'][$t])) {
                    $last[] = $t;
                }
            }
        }
        $options = self::sit_options($ids, $c, $k, $short, $fills, $opted, $w, $salt, $level, $never, $last);
        if (!$options && $never && $last) {
            // Every option would put out a team that sat out its last night while one that has never sat out plays.
            $floor = min(array_map(function ($t) use ($c) { return $c[$t]; }, $last)) - $level - 0.01;
            foreach ($never as $t) {
                if ($c[$t] > $floor) {
                    $c[$t] = $floor;
                    $out['first'][$t] = true;
                }
            }
            $options = self::sit_options($ids, $c, $k, $short, $fills, $opted, $w, $salt, $level, array(), array());
        }
        $out['options'] = $options;
        $out['must'] = $options[0]['must'];
        $out['may'] = $options[0]['may'];
        return $out;
    }

    /**
     * sit_out()'s options for these counts ($c: id => count), with the
     * guard when $never (teams that have never sat out) and $last (teams
     * that sat out their last night) are both given. Empty when the guard
     * leaves none.
     */
    private static function sit_options($ids, $c, $k, $short, $fills, $opted, $w, $salt, $level, $never, $last) {
        $eps = 1e-9; // sums of shares that should be equal compare equal
        $jitter = function ($t) use ($salt) { return (crc32($salt . '|bye|' . $t) % 1000) / 100000; }; // equal options don't always fall the same way
        $options = array();
        $seen = array();
        $add = function ($must, $may) use (&$options, &$seen, $k, $jitter) {
            if (count($must) > $k || count($must) + count($may) < $k) {
                return;
            }
            $key = $must;
            sort($key);
            $keys = array_keys($may);
            sort($keys);
            $key = implode(',', $key) . '|' . implode(',', $keys);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $options[] = array('must' => array_values($must), 'may' => $may, 'base' => array_sum(array_map($jitter, $must)));
        };
        if ($short) {
            $values = array_values($c);
            sort($values);
            $line = $values[$k - 1];
            $tops = array();
            foreach ($values as $v) {
                if ($v >= $line - $eps && (!$tops || $v > end($tops) + $eps)) {
                    $tops[] = $v;
                }
            }
            foreach ($tops as $top) {
                $must = array();
                $may = array();
                foreach ($ids as $t) {
                    if ($c[$t] < $top - $level - $eps) {
                        $must[] = $t;
                    } elseif ($c[$t] <= $top + $eps) {
                        $may[$t] = $jitter($t);
                    }
                }
                if (count($must) >= $k) {
                    break; // a higher top only forces more out
                }
                $risk = $last ? array_intersect($last, array_merge($must, array_keys($may))) : array();
                if ($risk && array_diff($never, $must)) {
                    // A: no team that sat out its last night sits out.
                    if (!array_intersect($last, $must)) {
                        $add($must, array_diff_key($may, array_flip($last)));
                    }
                    // B: every team that has never sat out sits out too (each within the count).
                    if (!array_diff($never, $must, array_keys($may))) {
                        $add(array_merge($must, array_values(array_intersect($never, array_keys($may)))), array_diff_key($may, array_flip($never)));
                    }
                    continue;
                }
                $add($must, $may);
            }
            return $options;
        }
        $line = min($c);
        $fill_line = null;
        foreach (array_keys($fills) as $t) {
            $fill_line = $fill_line === null ? $c[intval($t)] : min($fill_line, $c[intval($t)]);
        }
        $opted = array_flip(array_map('intval', $opted));
        $may = array();
        foreach ($ids as $t) {
            $double = isset($opted[$t]) ? $w['odd_double'] : 0;
            if (isset($fills[$t])) {
                if ($c[$t] <= $fill_line + $level + $eps) {
                    $may[$t] = $double + $fills[$t][2] + $jitter($t); // the odd team, with its fill game
                }
            } elseif ($c[$t] <= $line + $level + $eps) {
                $may[$t] = $double + ($fills ? $w['odd_sit'] : 0) + $jitter($t); // the odd team, sitting out
            }
        }
        if ($never && $last) {
            // One team is out, so every team that has never sat out plays: none that sat out its last night may sit out.
            foreach ($last as $t) {
                if (!isset($fills[$t])) {
                    unset($may[$t]);
                }
            }
        }
        if ($may) {
            $add(array(), $may);
        }
        return $options;
    }

    /**
     * The fill game for team $t if it were the odd team (pure): a game
     * against one of $opted (not itself) that is never a third meeting,
     * last week's opponent, or more lopsided than $w['double_max_asked']
     * (judged without the link term, like every double-header check). Of
     * those, the lowest rank: the game's cost plus 0.5 × the partner's
     * double-header nights so far ($doubles: id => nights), as spare-slot
     * double-headers are shared, so the extra games don't all go to the
     * closest double-header team. Returns array(partner, pair_cost(), rank)
     * or null.
     */
    public static function fill_for($t, $opted, $ratings, $meetings, $w, $salt = '', $doubles = array()) {
        $best = null;
        foreach ($opted as $o) {
            if (intval($o) === intval($t)) {
                continue;
            }
            $c = self::pair_cost(intval($t), intval($o), $ratings, $meetings, $w, $salt);
            $rank = $c['cost'] + 0.5 * (isset($doubles[intval($o)]) ? intval($doubles[intval($o)]) : 0);
            // Never a mismatch just to avoid a bye.
            if ($c['count'] < 2 && $c['recent'] < $w['last_week'] && $c['closeness'] <= $w['double_max_asked']
                && ($best === null || $rank < $best[2])) {
                $best = array(intval($o), $c, $rank);
            }
        }
        return $best;
    }

    /**
     * Whether a team could play twice in these slots (pure): two of them
     * don't overlap in time. $slots: list of array(start 'H:i', end 'H:i',
     * ...). With one start time for the division, a fill game or
     * double-header would clash with the team's other game.
     */
    public static function can_play_twice($slots) {
        $mins = function ($hm) { list($h, $m) = array_map('intval', explode(':', $hm)); return $h * 60 + $m; };
        $slots = array_values($slots);
        for ($i = 0; $i < count($slots); $i++) {
            for ($j = $i + 1; $j < count($slots); $j++) {
                if ($mins($slots[$i]['end']) <= $mins($slots[$j]['start']) || $mins($slots[$j]['end']) <= $mins($slots[$i]['start'])) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * The sit-out count before a night (pure; the count behind sit_out(),
     * built by context()). $nights: the earlier nights that count, in date
     * order, each array(date 'Y-m-d', games => list of array(division, home,
     * away), off => team id => true for a week off, an automatic owing
     * record or a can't-make-it). $teams: id => array(division => its
     * division now, joined => 'Y-m-d' or '').
     *
     * Each night a team is in a division: the one of its last game on or
     * before that night, else of its first game after it, else its division
     * now. So a team that moved is judged by the division it was in, except
     * on a night between its last game in the old division and its first in
     * the new, which is judged by the old; a night before a team joined is
     * judged by the division it joined into. A night its division had no
     * games counts nothing. Otherwise the team was away (not in the league
     * by then, or off) or available, and an available team played (a game
     * in any division; two games are one double-header night) or sat out.
     * The night's share, per division: its teams that sat out ÷ its teams
     * available.
     *
     * The count: 1 for each night sat out, and for each night away that
     * night's share in the team's division, as if it had sat out its share
     * of the nights it missed. So it measures sit-outs per night available:
     * a team that joins late or comes back from weeks off isn't first to sit
     * out for having been away. A night with no sit-outs adds nothing, so a
     * team available every night has a whole number, the nights it sat out.
     *
     * Returns array(byes => id => the count (rounded to 6 places; teams on 0
     * left out), sat => id => nights actually sat out, last => id => true
     * when the team sat out the last night it was available (in a division
     * with games), doubles => id => double-header nights).
     */
    public static function sat_out_counts($nights, $teams) {
        $out = array('byes' => array(), 'sat' => array(), 'last' => array(), 'doubles' => array());
        $nights = array_values($nights);
        $seen = array(); // team => night index => the division it played in
        foreach ($nights as $i => $n) {
            foreach ($n['games'] as $g) {
                $seen[intval($g[1])][$i] = $g[0];
                $seen[intval($g[2])][$i] = $g[0];
            }
        }
        $count = array();
        foreach ($nights as $i => $n) {
            $played = array();
            $running = array();
            foreach ($n['games'] as $g) {
                $running[$g[0]] = true;
                foreach (array(intval($g[1]), intval($g[2])) as $t) {
                    $played[$t] = (isset($played[$t]) ? $played[$t] : 0) + 1;
                }
            }
            foreach ($played as $t => $games) {
                if ($games > 1) {
                    $out['doubles'][$t] = (isset($out['doubles'][$t]) ? $out['doubles'][$t] : 0) + 1;
                }
            }
            $state = array(); // team => array(division, played | sat | away)
            $avail = array(); // division => teams available
            $sat = array();   // division => teams that sat out
            foreach ($teams as $id => $t) {
                $id = intval($id);
                $division = $t['division'];
                if (isset($seen[$id])) {
                    $before = null;
                    $after = null;
                    foreach ($seen[$id] as $j => $d) {
                        if ($j <= $i) {
                            $before = $d;
                        } elseif ($after === null) {
                            $after = $d;
                        }
                    }
                    $division = $before !== null ? $before : $after;
                }
                if (!isset($running[$division])) {
                    continue;
                }
                if (isset($played[$id])) {
                    $s = 'played';
                } elseif (!empty($n['off'][$id]) || ($t['joined'] !== '' && $t['joined'] > $n['date'])) {
                    $s = 'away';
                } else {
                    $s = 'sat';
                    $sat[$division] = (isset($sat[$division]) ? $sat[$division] : 0) + 1;
                }
                if ($s !== 'away') {
                    $avail[$division] = (isset($avail[$division]) ? $avail[$division] : 0) + 1;
                }
                $state[$id] = array($division, $s);
            }
            foreach ($state as $id => $st) {
                list($division, $s) = $st;
                if ($s === 'sat') {
                    $count[$id] = (isset($count[$id]) ? $count[$id] : 0) + 1;
                    $out['sat'][$id] = (isset($out['sat'][$id]) ? $out['sat'][$id] : 0) + 1;
                    $out['last'][$id] = true;
                } elseif ($s === 'played') {
                    unset($out['last'][$id]);
                } elseif (!empty($sat[$division])) {
                    $count[$id] = (isset($count[$id]) ? $count[$id] : 0) + $sat[$division] / $avail[$division];
                }
            }
        }
        foreach ($count as $id => $x) {
            $out['byes'][$id] = round($x, 6);
        }
        return $out;
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

    /**
     * Minimum-cost matching in which $k of the teams sit out (pure). $ids:
     * team ids, count($ids) − $k even; $cost(a, b) -> float for a game;
     * $may: id => the cost of that team sitting out, for the teams that may
     * (every other team plays; at least $k of them). The k sit-out places
     * are extra nodes that pair only with $may teams. Exact up to
     * EXACT_LIMIT teams and places together, the local search above, as
     * match(). Returns array(pairs, out => ids, total).
     */
    public static function match_out($ids, $cost, $k, $may) {
        $ids = array_values(array_map('intval', $ids));
        $k = max(0, intval($k));
        if ($k === 0) {
            return self::match($ids, $cost) + array('out' => array());
        }
        $real = count($ids);
        $n = $real + $k;
        $c = array();
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                if ($j < $real) {
                    $v = (float) $cost($ids[$i], $ids[$j]);
                } elseif ($i < $real) {
                    $v = isset($may[$ids[$i]]) ? (float) $may[$ids[$i]] : self::NEVER;
                } else {
                    $v = self::NEVER; // two places together would leave a team out twice over
                }
                $c[$i][$j] = $c[$j][$i] = $v;
            }
        }
        $choice = $n <= self::EXACT_LIMIT ? self::match_exact($c, $n, $real) : self::match_search($c, $n, $real);
        $out = array('pairs' => array(), 'out' => array(), 'total' => 0.0);
        foreach ($choice as $p) {
            $out['total'] += $c[$p[0]][$p[1]];
            if ($p[0] >= $real || $p[1] >= $real) {
                $t = $p[0] < $real ? $p[0] : $p[1];
                if ($t < $real) {
                    $out['out'][] = $ids[$t];
                }
                continue;
            }
            $out['pairs'][] = array($ids[$p[0]], $ids[$p[1]]);
        }
        return $out;
    }

    /**
     * $real: nodes from $real up are interchangeable sit-out places
     * (match_out()); a team is only ever tried with the lowest one still
     * free, so they don't multiply the work.
     */
    private static function match_exact($c, $n, $real = null) {
        $real = $real === null ? $n : $real;
        $full = (1 << $n) - 1;
        $memo = array($full => 0.0);
        $next = array();
        $solve = function ($mask) use (&$solve, &$memo, &$next, $c, $n, $real) {
            if (isset($memo[$mask])) {
                return $memo[$mask];
            }
            $i = 0;
            while ($mask & (1 << $i)) {
                $i++;
            }
            $best = INF;
            $pick = -1;
            $place = false;
            for ($j = $i + 1; $j < $n; $j++) {
                if ($mask & (1 << $j)) {
                    continue;
                }
                if ($j >= $real) {
                    if ($place) {
                        break; // places are alike: the lowest free one stands for all
                    }
                    $place = true;
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

    /**
     * $real: as match_exact(). With sit-out places, every start puts them
     * on teams that may sit out, and a swap never makes a pairing that may
     * not happen (NEVER), so the search stays within the rule.
     */
    private static function match_search($c, $n, $real = null) {
        $real = $real === null ? $n : $real;
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
            if ($real < $n) {
                $teams = array_values(array_filter($order, function ($i) use ($real) { return $i < $real; }));
                $rest = array();
                $place = $real;
                foreach ($teams as $i) {
                    if ($place < $n && $c[$i][$real] < self::NEVER) {
                        $pairs[] = array($i, $place++);
                    } else {
                        $rest[] = $i;
                    }
                }
                $order = $rest;
            }
            for ($i = 0; $i + 1 < count($order); $i += 2) {
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
     * preferences, double-headers, and (Ranked) ratings as of the night
     * before and the link term (link(), from the published played games).
     *
     * An earlier night that was never published and has started was called
     * off (or never happened): its draft games are left out of everything
     * here, as they were never played. A draft for a night still to come
     * counts.
     *
     * byes: team => its sit-out count (sat_out_counts()): 1 for each
     * earlier night its division at the time had games, it was in the
     * league (approved by then), it had no game in any division, and it
     * wasn't away; for each night away, that night's share of sit-outs in
     * its division. Away: not in the league yet, a week off, a night the
     * automatic draw left it out for owing (both RSL_Requests::weeks_off()),
     * or a can't-make-it. A double-header night is one night played. sat:
     * team => nights it actually sat out; last: team => true when it sat
     * out its last night (sit_out()'s guard). The draw can't tell other
     * reasons afterwards: a team taken out of a night by hand counts as
     * having sat out.
     */
    public static function context($night) {
        $season = $night->season;
        $round = array();
        $nights = array();
        $i = 0;
        foreach (RSL_Courts::nights($season) as $n) {
            if ($n->type !== 'bye') {
                $round[intval($n->id)] = ++$i;
                $nights[intval($n->id)] = $n;
            }
        }
        $this_round = isset($round[intval($night->id)]) ? $round[intval($night->id)] : $i + 1;
        $teams = array();
        foreach (RSL_Teams::list_for_season($season) as $t) {
            $teams[intval($t->id)] = $t;
        }
        $ctx = array('night' => $night, 'round' => $this_round, 'teams' => $teams, 'meetings' => array(), 'byes' => array(), 'sat' => array(), 'last' => array(), 'home' => array(),
            'late' => array(), 'misses' => array(), 'doubles' => array(), 'ratings' => null, 'link' => null);

        $by_night = array();
        $linked = array(); // round => the published, played Ranked games (the ladder's evidence), for link()
        $called_off = array(); // night id => true: never published, and started
        foreach (RSL_Fixtures::games_for_season($season) as $g) {
            if ($g->night_date >= $night->night_date || !isset($round[intval($g->night_id)])) {
                continue;
            }
            if (!isset($called_off[intval($g->night_id)])) {
                $called_off[intval($g->night_id)] = empty($g->published_at) && RSL_Fixtures::has_started($nights[intval($g->night_id)]);
            }
            if ($called_off[intval($g->night_id)]) {
                continue;
            }
            $by_night[intval($g->night_id)][] = $g;
            if ($g->division === 'ranked' && $g->status === 'played' && !empty($g->published_at)) {
                $linked[$round[intval($g->night_id)]][] = array(intval($g->home_team_id), intval($g->away_team_id));
            }
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
        $counted = array(); // sat_out_counts()'s nights
        foreach ($by_night as $night_id => $games) {
            $off = isset($weeks_off[$games[0]->night_date]) ? $weeks_off[$games[0]->night_date] : array();
            // A team that said it couldn't make it (its game since removed) didn't sit out for the fixtures' sake either.
            $off += RSL_Requests::for_night($night_id, 'cant_make', array('active', 'handled'));
            $counted[] = array('date' => $games[0]->night_date, 'off' => array_fill_keys(array_keys($off), true),
                'games' => array_map(function ($g) { return array($g->division, intval($g->home_team_id), intval($g->away_team_id)); }, $games));
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
                        if (count($starts) > 1 && $r === 1) {
                            $ctx['late'][$t] = (isset($ctx['late'][$t]) ? $ctx['late'][$t] : 0) + 1;
                        }
                        $pref = isset($teams[$t]) ? $teams[$t]->pref_time : 'any';
                        if (($pref === 'early' && $r > 0.5) || ($pref === 'late' && $r < 0.5)) {
                            $ctx['misses'][$t] = (isset($ctx['misses'][$t]) ? $ctx['misses'][$t] : 0) + 1;
                        }
                    }
                }
            }
        }
        $joined = array();
        foreach ($teams as $t) {
            // Approved (or created) by then — not just applied.
            $joined[intval($t->id)] = array('division' => $t->division, 'joined' => RSL_Stats::joined_date($t));
        }
        $counts = self::sat_out_counts($counted, $joined);
        $ctx['byes'] = $counts['byes'];
        $ctx['sat'] = $counts['sat'];
        $ctx['last'] = $counts['last'];
        $ctx['doubles'] = $counts['doubles'];
        // The FIXTURES rating (seeded by the captain's level), never the
        // ladder's: week one is paired by level, and explain_night()'s set
        // chances (admin only) come from the same numbers.
        $ratings = RSL_Ratings::compute($season, date('Y-m-d', strtotime($night->night_date . ' -1 day')), 'fixtures');
        $ctx['ratings'] = array();
        foreach ($ratings as $id => $r) {
            $ctx['ratings'][$id] = array('rating' => $r['rating'], 'sd' => $r['sd']);
        }
        // Ranked only: groups the ladder can't compare yet, and what a game between them is worth.
        $ranked = array_keys(array_filter($teams, function ($t) { return $t->division === 'ranked'; }));
        $ctx['link'] = self::link($ranked, $linked, $this_round, self::weights());
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
            // Double-header teams: asked for two games this week, or happy to all season. None when
            // every slot overlaps every other (one start time): nobody can play twice, so no fill
            // game and no double-header.
            $opted = !self::can_play_twice($slots) ? array()
                : array_values(array_filter($ids, function ($id) use ($ctx, $asked_double) { return isset($asked_double[$id]) || !empty($ctx['teams'][$id]->pref_double); }));
            $link = $division === 'ranked' ? $ctx['link'] : null;
            // Who sits out, if anyone: the teams with the lowest sit-out counts (sit_out()).
            $result = self::pair_division($ids, $ratings, $ctx['meetings'], $ctx['byes'], $w, $salt, $link, count($slots), $opted, $ctx['doubles'],
                array('sat' => $ctx['sat'], 'last' => $ctx['last']));
            $games = array();
            $tonight = array();
            foreach ($result['pairs'] as $p) {
                $games[] = array($p[0], $p[1], 'single', $p[2]);
                $tonight[$p[0]][] = $p[1];
                $tonight[$p[1]][] = $p[0];
            }
            // Odd numbers: the odd team plays a double-header team when one qualifies.
            if ($result['fill']) {
                list($o, $c) = $result['fill'];
                $games[] = array($result['bye'], $o, 'fill', $c);
                $tonight[$result['bye']][] = $o;
                $tonight[$o][] = $result['bye'];
            }
            if ($result['out']) {
                $fewest = null;
                foreach (array_keys($tonight) as $t) {
                    $sat = isset($ctx['byes'][$t]) ? (float) $ctx['byes'][$t] : 0.0;
                    $fewest = $fewest === null ? $sat : min($fewest, $sat);
                }
                foreach ($result['out'] as $id => $why) {
                    $d['out'][$id] = self::sit_out_words($why, isset($ctx['byes'][$id]) ? $ctx['byes'][$id] : 0, $fewest, isset($result['first'][$id]));
                }
            }
            $spare = count($slots) - count($games);

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
     * Why a team sits out, for admins: the reason and its count against the
     * teams that play, so the rule can be seen to hold. $why: 'courts' or
     * 'odd' (pair_division()'s out); $before: the team's sit-out count
     * before tonight; $fewest: the lowest among the division's teams with a
     * game tonight (null: none play); $first: the team went first under the
     * twice-in-a-row guard (pair_division()'s first).
     */
    public static function sit_out_words($why, $before, $fewest, $first = false) {
        return ($why === 'courts' ? 'Not enough courts' : 'Bye, odd number of teams')
            . ' (nights sat out before: this team ' . self::count_words($before)
            . ($fewest === null ? '' : ', the teams playing ' . self::count_words($fewest) . ' or more')
            . ($first ? '; it has never sat out, so it goes before a team that sat out its last night' : '') . ')';
    }

    /**
     * A sit-out count for admins: a whole number as it is, else to one
     * decimal place (1.3). Counts with a share of nights away are fractions.
     */
    public static function count_words($x) {
        $x = round((float) $x, 1);
        return abs($x - round($x)) < 1e-9 ? (string) intval(round($x)) : number_format($x, 1, '.', '');
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
     * array(p (home wins a set), edge, count (earlier meetings), last (rounds ago),
     * link (the link bonus it got, negative, or 0), doubles, time_ok per team),
     * plus _resting (teams with no game), _sat_out (resting team => its
     * sit-out count before this night, context()'s byes, a float) and
     * _fewest (division => the lowest count before this night among its
     * teams with a game).
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
            $ranked = $g->division === 'ranked';
            $c = self::pair_cost($h, $a, $ranked ? $ctx['ratings'] : null, $ctx['meetings'], $w, '', $ranked ? $ctx['link'] : null);
            $s = array_values(array_unique($starts[$g->division]));
            sort($s);
            $pos = array_search(RSL_Courts::hm($g->start_time), $s, true);
            $r = count($s) > 1 ? $pos / (count($s) - 1) : 0.5;
            $time_ok = array();
            foreach (array($h, $a) as $t) {
                $pref = isset($ctx['teams'][$t]) ? $ctx['teams'][$t]->pref_time : 'any';
                $time_ok[$t] = $pref === 'any' ? null : (($pref === 'early' && $r <= 0.5) || ($pref === 'late' && $r >= 0.5));
            }
            $out[intval($g->id)] = array('p' => $c['p'], 'edge' => $c['edge'], 'count' => $c['count'], 'last' => $c['last'], 'link' => $c['link'],
                'double' => array($h => $count[$h] > 1, $a => $count[$a] > 1), 'time_ok' => $time_ok);
        }
        $playing = array_keys($count);
        $out['_resting'] = array_values(array_diff(array_keys($ctx['teams']), $playing));
        // Sit-out counts before this night: each resting team's, and the lowest among each division's teams with a game.
        $out['_sat_out'] = array();
        foreach ($out['_resting'] as $t) {
            $out['_sat_out'][$t] = isset($ctx['byes'][$t]) ? (float) $ctx['byes'][$t] : 0.0;
        }
        $out['_fewest'] = array();
        foreach ($games as $g) {
            foreach (array(intval($g->home_team_id), intval($g->away_team_id)) as $t) {
                $sat = isset($ctx['byes'][$t]) ? (float) $ctx['byes'][$t] : 0.0;
                $out['_fewest'][$g->division] = isset($out['_fewest'][$g->division]) ? min($out['_fewest'][$g->division], $sat) : $sat;
            }
        }
        return $out;
    }
}
