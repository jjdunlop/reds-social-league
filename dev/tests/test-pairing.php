<?php
/**
 * Automatic fixtures: the pairing engine (pure), slot assignment, a real
 * night end to end, and the admin Fixtures page actions.
 *
 *   wp eval-file dev/tests/test-pairing.php
 */

if (!defined('WP_CLI') || !WP_CLI || (wp_get_environment_type() === 'production' && !getenv('RSL_DEV'))) {
    exit("Local copy only: run with wp eval-file, with RSL_DEV=1 set (docker exec -e RSL_DEV=1 ...).\n");
}
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once dirname(__DIR__) . '/lib-mail-stop.php';
global $wpdb;

$suffix = time();
$hold = rsl_dev_hold_mail($suffix);
$fails = 0;
$check = function ($label, $ok, $detail = '') use (&$fails, $hold) {
    rsl_dev_park_mail($hold);
    if (!$ok) { $fails++; }
    echo ($ok ? "OK: $label\n" : "FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n");
};
// Set before the try, so the cleanup can always run.
$season = '2092-pre';
$season_past = '2091-post'; // a season with nights gone, for the called-off night
$saved_current = get_option(RSL_Seasons::CURRENT_OPTION, '__unset__');
$parked = array();
$admin = 0;

try {

$w = RSL_Pairing::weights();
$r = function ($list) { $out = array(); foreach ($list as $id => $rating) { $out[$id] = array('rating' => $rating, 'sd' => 0); } return $out; };
$has_pair = function ($pairs, $a, $b) { foreach ($pairs as $p) { if (($p[0] == $a && $p[1] == $b) || ($p[0] == $b && $p[1] == $a)) { return true; } } return false; };

// --- Set-win chances and costs -------------------------------------------------------
$check('even teams: 50/50 a set', abs(RSL_Pairing::set_win_probability(1500, 0, 1500, 0) - 0.5) < 1e-9);
$check('400 points apart: about 10 to 1', abs(RSL_Pairing::set_win_probability(1900, 0, 1500, 0) - 10 / 11) < 1e-6);
$check('uncertain ratings are pulled towards 50/50', RSL_Pairing::set_win_probability(1900, 200, 1500, 200) < RSL_Pairing::set_win_probability(1900, 0, 1500, 0));
$gap = 400 * log10(2); // a 67/33 set-win chance
$c = RSL_Pairing::pair_cost(1, 2, $r(array(1 => 1500 + $gap, 2 => 1500)), array(), $w);
$check('a 67/33 matchup costs 1', abs($c['closeness'] - 1) < 1e-6 && abs($c['p'] - 2 / 3) < 1e-6 && abs($c['edge'] - 1 / 3) < 1e-6);
$c = RSL_Pairing::pair_cost(1, 2, $r(array(1 => 1500 + 400 * log10(3), 2 => 1500)), array(), $w);
$check('a 75/25 matchup costs 2.25', abs($c['closeness'] - 2.25) < 1e-6);
$check('an even first meeting costs (almost) nothing', RSL_Pairing::pair_cost(1, 2, $r(array(1 => 1500, 2 => 1500)), array(), $w)['cost'] < 0.01);
$m = array('1-2' => array('count' => 1, 'last' => 4));
$check('a second meeting costs the same as a 67/33 game', abs(RSL_Pairing::pair_cost(1, 2, null, $m, $w)['repeat'] - 1) < 1e-9);
$m = array('1-2' => array('count' => 2, 'last' => 4));
$check('a third meeting costs far more than any lopsided game', RSL_Pairing::pair_cost(1, 2, null, $m, $w)['repeat'] > 9);
$m = array('1-2' => array('count' => 1, 'last' => 1));
$check('last week\'s opponent is effectively barred', RSL_Pairing::pair_cost(1, 2, null, $m, $w)['cost'] >= 1000);

// --- Matching ---------------------------------------------------------------------------
$brute = function ($ids, $cost) use (&$brute) {
    if (!$ids) { return 0.0; }
    $a = array_shift($ids);
    $best = INF;
    foreach ($ids as $i => $b) {
        $rest = $ids;
        unset($rest[$i]);
        $best = min($best, $cost($a, $b) + $brute(array_values($rest), $cost));
    }
    return $best;
};
$ok = true;
mt_srand(42);
for ($trial = 0; $trial < 15; $trial++) {
    $matrix = array();
    for ($i = 1; $i <= 8; $i++) { for ($j = $i + 1; $j <= 8; $j++) { $matrix[$i][$j] = $matrix[$j][$i] = mt_rand(0, 1000) / 10; } }
    $cost = function ($a, $b) use ($matrix) { return $matrix[$a][$b]; };
    $res = RSL_Pairing::match(range(1, 8), $cost);
    $ok = $ok && abs($res['total'] - $brute(range(1, 8), $cost)) < 1e-6 && count($res['pairs']) === 4;
}
$check('matching is optimal (checked against brute force)', $ok);
$search = new ReflectionMethod('RSL_Pairing', 'match_search');
$search->setAccessible(true);
$exact = new ReflectionMethod('RSL_Pairing', 'match_exact');
$exact->setAccessible(true);
$ok = true;
for ($trial = 0; $trial < 5; $trial++) {
    $c = array();
    for ($i = 0; $i < 14; $i++) { for ($j = $i + 1; $j < 14; $j++) { $c[$i][$j] = $c[$j][$i] = mt_rand(0, 1000) / 10; } }
    $sum = function ($pairs) use ($c) { $t = 0; foreach ($pairs as $p) { $t += $c[$p[0]][$p[1]]; } return $t; };
    $s = $search->invoke(null, $c, 14);
    $e = $exact->invoke(null, $c, 14);
    $ok = $ok && $sum($s) <= $sum($e) * 1.05 + 1e-6;
}
$check('large-league search finds (near-)optimal pairings', $ok);
$res = RSL_Pairing::match(range(1, 24), function ($a, $b) { return abs($a - $b); });
$seen = array();
foreach ($res['pairs'] as $p) { $seen[$p[0]] = $seen[$p[1]] = true; }
$check('24 teams: everyone paired once, neighbours together', count($res['pairs']) === 12 && count($seen) === 24 && abs($res['total'] - 12) < 1e-9);

// --- Pairing a division ------------------------------------------------------------------
$ratings = $r(array(1 => 1800, 2 => 1790, 3 => 1500, 4 => 1490));
$p = RSL_Pairing::pair_division(array(1, 2, 3, 4), $ratings, array(), array(), $w);
$check('closest teams are paired', $has_pair($p['pairs'], 1, 2) && $has_pair($p['pairs'], 3, 4) && $p['bye'] === null);
$p = RSL_Pairing::pair_division(array(1, 2, 3, 4), $ratings, array('1-2' => array('count' => 1, 'last' => 1)), array(), $w);
$check('never last week\'s opponent', !$has_pair($p['pairs'], 1, 2));
$ratings = $r(array(1 => 1600, 2 => 1590, 3 => 1400, 4 => 1390));
$p = RSL_Pairing::pair_division(array(1, 2, 3, 4), $ratings, array('1-2' => array('count' => 1, 'last' => 4)), array(), $w);
$check('a close rematch beats two lopsided first meetings', $has_pair($p['pairs'], 1, 2));
$p = RSL_Pairing::pair_division(array(1, 2, 3, 4), $ratings, array('1-2' => array('count' => 2, 'last' => 4), '3-4' => array('count' => 2, 'last' => 4)), array(), $w);
$check('but never a third meeting when there\'s another option', !$has_pair($p['pairs'], 1, 2) && !$has_pair($p['pairs'], 3, 4));
$p = RSL_Pairing::pair_division(array(1, 2, 3, 4, 5), $r(array(1 => 1500, 2 => 1500, 3 => 1500, 4 => 1500, 5 => 1500)), array(), array(1 => 1, 2 => 1, 3 => 1, 4 => 1), $w);
$check('odd numbers: the team that hasn\'t sat out sits out', $p['bye'] === 5 && $p['out'] === array(5 => 'odd') && $p['fill'] === null && count($p['pairs']) === 2);
$meet = array('1-2' => array('count' => 1, 'last' => 3), '3-4' => array('count' => 1, 'last' => 3), '1-3' => array('count' => 1, 'last' => 2), '2-4' => array('count' => 1, 'last' => 2));
$p = RSL_Pairing::pair_division(array(1, 2, 3, 4), null, $meet, array(), $w);
$check('Unranked: the pairs that haven\'t met play', $has_pair($p['pairs'], 1, 4) && $has_pair($p['pairs'], 2, 3));

// --- Linking groups the results haven't connected (0.18.1, Ranked) ----------------------------------
// Eight teams that, after two rounds, are two groups of four that haven't met: 1-4 about 120 points above 5-8.
$check('link weights: 2.25 (a 75/25 game) from round 3', $w['link'] === 2.25 && $w['link_from'] === 3);
$ids8 = range(1, 8);
$within = array(1 => array(array(1, 2), array(3, 4), array(5, 6), array(7, 8)), 2 => array(array(1, 3), array(2, 4), array(5, 7), array(6, 8)));
$meet8 = function ($by_round, $this_round) {
    $m = array();
    foreach ($by_round as $round => $games) {
        foreach ($games as $g) {
            $k = RSL_Pairing::meeting_key($g[0], $g[1]);
            $m[$k] = array('count' => (isset($m[$k]) ? $m[$k]['count'] : 0) + 1, 'last' => $this_round - $round);
        }
    }
    return $m;
};
$r8 = $r(array(1 => 1600, 2 => 1595, 3 => 1590, 4 => 1585, 5 => 1480, 6 => 1475, 7 => 1470, 8 => 1465));
$crossing = function ($pairs) { $n = 0; foreach ($pairs as $p) { $n += (($p[0] <= 4) !== ($p[1] <= 4)) ? 1 : 0; } return $n; };
$l3 = RSL_Pairing::link($ids8, $within, 3, $w);
$check('two groups that haven\'t met: from round 3 each team is in its group (by its lowest id), bonus 2.25 for one night split',
    $l3['groups'] === array(1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 5, 6 => 5, 7 => 5, 8 => 5) && $l3['bonus'] === 2.25 && $l3['nights'] === 1);
$plain = RSL_Pairing::pair_division($ids8, $r8, $meet8($within, 3), array(), $w, 's3');
$linked = RSL_Pairing::pair_division($ids8, $r8, $meet8($within, 3), array(), $w, 's3', $l3);
$check('...the closest games keep the groups apart, and the link term brings at least one game between them',
    $crossing($plain['pairs']) === 0 && $crossing($linked['pairs']) >= 1, $crossing($plain['pairs']) . ' / ' . $crossing($linked['pairs']));
$cross_cost = RSL_Pairing::pair_cost(2, 6, $r8, array(), $w, 's3', $l3);
$check('...a game between the groups costs 2.25 less; within a group nothing changes', $cross_cost['link'] === -2.25
    && abs($cross_cost['cost'] - RSL_Pairing::pair_cost(2, 6, $r8, array(), $w, 's3')['cost'] + 2.25) < 1e-12
    && RSL_Pairing::pair_cost(1, 4, $r8, array(), $w, 's3', $l3) === RSL_Pairing::pair_cost(1, 4, $r8, array(), $w, 's3'));
$still = $within + array(3 => array(array(1, 4), array(2, 3), array(5, 8), array(6, 7)));
$l4 = RSL_Pairing::link($ids8, $still, 4, $w);
$check('still split the next night: the bonus doubles (2.25 for each night in a row)', $l4['bonus'] === 4.5 && $l4['nights'] === 2);
$check('before round 3 there is no link term, split or not', RSL_Pairing::link($ids8, array(1 => $within[1]), 2, $w) === array('groups' => array(), 'bonus' => 0.0, 'nights' => 0));
$joined = $within + array(3 => array(array(1, 5), array(2, 6), array(3, 7), array(4, 8)));
$l4j = RSL_Pairing::link($ids8, $joined, 4, $w);
$check('one group: bonus 0 and the pairings are exactly the ones without the link term', $l4j['bonus'] === 0.0 && $l4j['groups'] === array()
    && RSL_Pairing::pair_division($ids8, $r8, $meet8($joined, 4), array(), $w, 's4', $l4j) === RSL_Pairing::pair_division($ids8, $r8, $meet8($joined, 4), array(), $w, 's4'));
$l3n = RSL_Pairing::link(range(1, 9), $within, 3, $w);
$check('a team with no games yet is left out (no group, no link bonus either way)', !isset($l3n['groups'][9]) && $l3n['bonus'] === 2.25
    && RSL_Pairing::pair_cost(9, 1, $r8, array(), $w, '', $l3n)['link'] === 0.0 && RSL_Pairing::pair_cost(9, 5, $r8, array(), $w, '', $l3n)['link'] === 0.0);
$check('pair_cost: link is 0 without groups (no link, or one group)', RSL_Pairing::pair_cost(2, 6, $r8, array(), $w)['link'] === 0.0
    && RSL_Pairing::pair_cost(2, 6, $r8, array(), $w, '', $l4j)['link'] === 0.0);
$check('a filter can switch the link term off (link 0)', RSL_Pairing::link($ids8, $within, 3, array('link' => 0.0) + $w)['bonus'] === 0.0);
// Nights are the division's own: only rounds with its results count. A round without them (no Ranked
// courts, called off, results not in yet) or a late start neither brings the term on early nor raises it.
$late3 = RSL_Pairing::link($ids8, array(2 => $within[1]), 3, $w);
$late4 = RSL_Pairing::link($ids8, array(2 => $within[1], 3 => $within[2]), 4, $w);
$check('a division whose first games are in round 2: no link on its 2nd night (round 3); on its 3rd (round 4) 2.25 for one night split, not 4.5',
    $late3['bonus'] === 0.0 && $late3['groups'] === array() && $late4['bonus'] === 2.25 && $late4['nights'] === 1 && count(array_unique($late4['groups'])) === 2);
$gap4 = RSL_Pairing::link($ids8, $within, 4, $w);
$gap6 = RSL_Pairing::link($ids8, $within + array(4 => $still[3]), 6, $w);
$check('a round with no results (round 3, then 5) doesn\'t count: round 4 gets 2.25, and still split on round 6 it is 4.5 (two of its nights), not 9',
    $gap4['bonus'] === 2.25 && $gap4['nights'] === 1 && $gap6['bonus'] === 4.5 && $gap6['nights'] === 2);
$check('round 3 with only round 1\'s results in (round 2\'s not entered yet): one night of results, so no link term yet',
    RSL_Pairing::link($ids8, array(1 => $within[1]), 3, $w) === array('groups' => array(), 'bonus' => 0.0, 'nights' => 0));
$check('a round whose only game involves a team outside the division doesn\'t count either',
    RSL_Pairing::link($ids8, array(1 => $within[1], 2 => array(array(1, 99)), 3 => $within[2]), 4, $w)['bonus'] === 2.25);

// --- Sitting out (0.18.2): by the sit-out count, then by cost ---------------------------------------
// More teams than the night's games can hold: the teams with the fewest nights sat out sit out, and
// among teams tied on that count the draw's own cost decides (link term included), so the link only
// breaks ties. Ten teams, two groups (1-6 strong, 7-10 weaker) that have only met inside them in rounds
// 1 and 2; three courts, so four teams sit out.
$by10 = array(1 => array(array(1, 2), array(3, 4), array(5, 6), array(7, 8), array(9, 10)), 2 => array(array(1, 3), array(2, 5), array(4, 6), array(7, 9), array(8, 10)));
$r10 = array_map(function ($x) { return array('rating' => $x['rating'], 'sd' => 80); },
    $r(array(1 => 1660, 2 => 1640, 3 => 1620, 4 => 1600, 5 => 1590, 6 => 1580, 7 => 1420, 8 => 1400, 9 => 1380, 10 => 1360)));
$l10 = RSL_Pairing::link(range(1, 10), $by10, 3, $w);
$cross10 = function ($pairs) { $n = 0; foreach ($pairs as $p) { $n += (($p[0] <= 6) !== ($p[1] <= 6)) ? 1 : 0; } return $n; };
$p10 = RSL_Pairing::pair_division(range(1, 10), $r10, $meet8($by10, 3), array(), $w, 's3', $l10, 3);
$n10 = RSL_Pairing::pair_division(range(1, 10), $r10, $meet8($by10, 3), array(), $w, 's3', null, 3);
$check('three courts for ten teams, none sat out yet: three games, four teams out for courts, and the link term keeps a game between the groups that the cost alone leaves out',
    $l10['bonus'] === 2.25 && count($p10['pairs']) === 3 && count($p10['out']) === 4 && array_values(array_unique($p10['out'])) === array('courts')
    && $p10['bye'] === null && $p10['fill'] === null && $cross10($p10['pairs']) >= 1 && $cross10($n10['pairs']) === 0,
    $cross10($p10['pairs']) . ' / ' . $cross10($n10['pairs']) . ' cross');
$sat10 = array(1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1);
$q10 = RSL_Pairing::pair_division(range(1, 10), $r10, $meet8($by10, 3), $sat10, $w, 's3', $l10, 3);
$check('...but the link term never outweighs the count: with 1-6 on one night sat out and 7-10 on none, 7-10 sit out and 1-6 play among themselves',
    array_keys($q10['out']) == array(7, 8, 9, 10) && $cross10($q10['pairs']) === 0, wp_json_encode(array_keys($q10['out'])));
$check('...room for every game: nothing sits out, and the draw is the one with no limit',
    RSL_Pairing::pair_division(range(1, 10), $r10, $meet8($by10, 3), array(), $w, 's3', $l10, 5) === RSL_Pairing::pair_division(range(1, 10), $r10, $meet8($by10, 3), array(), $w, 's3', $l10)
    && RSL_Pairing::pair_division(range(1, 10), $r10, $meet8($by10, 3), array(), $w, 's3', $l10, 6)['out'] === array()
    && count(RSL_Pairing::pair_division(range(1, 10), $r10, $meet8($by10, 3), array(), $w, 's3', $l10, 5)['pairs']) === 5);

// Ties go by cost: six teams, none sat out, two courts: the two far from the rest sit out. When one of
// them has sat out before, it plays (the closest game it has) and someone on 0 sits out instead.
$r6 = $r(array(1 => 1500, 2 => 1510, 3 => 1490, 4 => 1505, 5 => 1900, 6 => 1100));
$p6 = RSL_Pairing::pair_division(range(1, 6), $r6, array(), array(), $w, 't', null, 2);
$check('ties go by cost: the two teams far from the rest sit out (none sat out yet)', array_keys($p6['out']) == array(5, 6) || array_keys($p6['out']) == array(6, 5));
$p6 = RSL_Pairing::pair_division(range(1, 6), $r6, array(), array(5 => 1), $w, 't', null, 2);
$check('...a team that has sat out before plays, though its game is the dearest: 5 plays 2, 6 and one more sit out',
    !isset($p6['out'][5]) && isset($p6['out'][6]) && count($p6['out']) === 2 && $has_pair($p6['pairs'], 5, 2));

// Rotation: ten teams on four courts, so two sit out every night. Every team sits out once before any
// team sits out twice, and two teams' counts never differ by more than one, whatever the ratings.
mt_srand(7);
$rot = array();
foreach (range(1, 10) as $t) { $rot[$t] = array('rating' => 1500 + mt_rand(-250, 250), 'sd' => 60); }
$sat = array();
$met = array();
$ok = true;
$after5 = null;
for ($night = 1; $night <= 8; $night++) {
    $m = array();
    foreach ($met as $k => $x) { $m[$k] = array('count' => $x['count'], 'last' => $night - $x['round']); }
    $p = RSL_Pairing::pair_division(range(1, 10), $rot, $m, $sat, $w, "rot$night", null, 4);
    foreach ($p['pairs'] as $g) {
        $k = RSL_Pairing::meeting_key($g[0], $g[1]);
        $met[$k] = array('count' => (isset($met[$k]) ? $met[$k]['count'] : 0) + 1, 'round' => $night);
    }
    foreach (array_keys($p['out']) as $t) { $sat[$t] = (isset($sat[$t]) ? $sat[$t] : 0) + 1; }
    $now = array_map(function ($t) use ($sat) { return isset($sat[$t]) ? $sat[$t] : 0; }, range(1, 10));
    $ok = $ok && count($p['pairs']) === 4 && count($p['out']) === 2 && max($now) - min($now) <= 1;
    if ($night === 5) { $after5 = $now; }
}
$check('rotation: ten teams, four courts, eight nights: two sit out each night, each team once before any team twice (all on 1 after five nights)',
    $ok && $after5 === array_fill(0, 10, 1), wp_json_encode($sat));

// Odd night with a spare court: the odd team plays a double-header team when one qualifies, and
// fairness never costs that game. 5 has sat out fewest but met the double-header team 6 last week;
// 4 and the others can play 6, so one of them is the odd team, plays 6, and nobody sits out.
$r7 = $r(array_fill_keys(range(1, 7), 1500));
$m7 = array('5-6' => array('count' => 1, 'last' => 1));
$s7 = array(1 => 1, 2 => 1, 3 => 1, 4 => 1, 6 => 1, 7 => 1);
$p7 = RSL_Pairing::pair_division(range(1, 7), $r7, $m7, $s7, $w, 'odd', null, 4, array(6));
$check('odd night, spare court: the team on fewest nights sat out can\'t play the double-header team, another can, so that one plays it and nobody sits out',
    $p7['out'] === array() && $p7['fill'] !== null && $p7['fill'][0] === 6 && $p7['bye'] !== 5 && $p7['bye'] !== 6 && count($p7['pairs']) === 3
    && in_array(5, array_merge(array_column($p7['pairs'], 0), array_column($p7['pairs'], 1)), true), wp_json_encode(array($p7['bye'], $p7['out'])));
$p7 = RSL_Pairing::pair_division(range(1, 7), $r7, $m7, $s7, $w, 'odd', null, 4);
$check('...with no double-header team, 5 (fewest nights sat out) sits out', $p7['out'] === array(5 => 'odd') && $p7['fill'] === null);
$p7 = RSL_Pairing::pair_division(range(1, 7), $r7, $m7, $s7, $w, 'odd', null, 3, array(6));
$check('...and with no spare court (seven teams, three courts) it is a court sit-out for 5, with no fill game', $p7['out'] === array(5 => 'courts') && $p7['fill'] === null && $p7['bye'] === null);
// A double-header team is picked last among tied teams to be the odd one out, unless that saves more than
// odd_double (8) in the night's games: 1 is about 306 points above four even teams, so its game costs 4.5,
// over the fill limit (4) but under 8.
$r5 = $r(array(1 => 1500 + 400 * log10((1 + sqrt(0.5)) / (1 - sqrt(0.5))), 2 => 1500, 3 => 1500, 4 => 1500, 5 => 1500));
$p5 = RSL_Pairing::pair_division(range(1, 5), $r5, array(), array(), $w, 'dh', null, 3, array(1));
$q5 = RSL_Pairing::pair_division(range(1, 5), $r5, array(), array(), array('odd_double' => 0.0) + $w, 'dh', null, 3, array(1));
$check('a double-header team is picked last among tied teams to be the odd one out: 1 plays its 4.5 game rather than sit out (with odd_double 0 it would sit out)',
    $p5['fill'] === null && count($p5['out']) === 1 && !isset($p5['out'][1]) && $q5['out'] === array(1 => 'odd'),
    wp_json_encode(array($p5['out'], $q5['out'])));

// A fill game is never worth a third meeting or last week's opponent elsewhere in the night (odd_sit, 20).
// Unranked 1, 2, 3 on two courts, 1 the double-header team, 1 and 3 met last week: giving 2 the fill game
// against 1 leaves 1-3, so 3 sits out (it is on the fewest nights sat out) and 1 plays 2.
$c1 = RSL_Pairing::pair_division(array(1, 2, 3), null, array('1-3' => array('count' => 1, 'last' => 1)), array(), $w, 'f1', null, 2, array(1));
$check('odd night: a fill game that would leave last week\'s game is not taken; the team on the fewest nights sat out sits out instead',
    $c1['fill'] === null && $c1['out'] === array(3 => 'odd') && count($c1['pairs']) === 1 && $has_pair($c1['pairs'], 1, 2), wp_json_encode($c1));
// Five Unranked teams, three courts, 1 the double-header team. A fill game for 2 or 3 would force a
// third meeting (1-4) or last week's game, so 4 or 5 sits out and every game is a first meeting.
$m5 = array('1-5' => array('count' => 1, 'last' => 1), '2-3' => array('count' => 1, 'last' => 1), '4-5' => array('count' => 1, 'last' => 1), '1-4' => array('count' => 2, 'last' => 3));
$c2 = RSL_Pairing::pair_division(range(1, 5), null, $m5, array(), $w, 'f2', null, 3, array(1));
$c2_ok = $c2['fill'] === null && count($c2['out']) === 1 && (isset($c2['out'][4]) || isset($c2['out'][5])) && count($c2['pairs']) === 2;
foreach ($c2['pairs'] as $g) { $c2_ok = $c2_ok && $g[2]['count'] === 0; }
$check('...nor one that would force a third meeting: 4 or 5 sits out, and both games are first meetings', $c2_ok, wp_json_encode($c2));
$c3 = RSL_Pairing::pair_division(range(1, 5), null, array('1-5' => array('count' => 1, 'last' => 1)), array(), $w, 'f3', null, 3, array(1));
$check('...but a fill game that costs nothing extra is still taken: nobody sits out', $c3['out'] === array() && $c3['fill'] !== null && $c3['fill'][0] === 1);

// Fill games are shared: of two double-header teams with equal games, the one with fewer double-header
// nights so far is the partner (0.5 a night, as spare-slot double-headers are shared).
$check('fill_for(): the partner with fewer double-header nights so far, each way round',
    RSL_Pairing::fill_for(1, array(6, 7), null, array(), $w, 's', array(6 => 3))[0] === 7 && RSL_Pairing::fill_for(1, array(6, 7), null, array(), $w, 's', array(7 => 3))[0] === 6);
$f5 = RSL_Pairing::pair_division(array(1, 2, 3, 6, 7), null, array(), array(), $w, 'share', null, 3, array(6, 7), array(6 => 2));
$check('...and pair_division() passes the double-header nights on: the odd team plays 7, not 6', $f5['fill'] !== null && $f5['fill'][0] === 7 && $f5['out'] === array(), wp_json_encode($f5['fill']));

// Nobody can play twice when every slot starts at the same time: plan() then gives no fill game and no
// double-header (can_play_twice()).
$at7 = array(array('start' => '19:00', 'end' => '20:00'), array('start' => '19:00', 'end' => '20:00'), array('start' => '19:00', 'end' => '20:00'));
$check('can_play_twice(): not with every slot at 19:00, nor with overlapping times; yes with a slot after another',
    !RSL_Pairing::can_play_twice($at7) && !RSL_Pairing::can_play_twice(array(array('start' => '19:00', 'end' => '20:00'), array('start' => '19:30', 'end' => '20:30')))
    && RSL_Pairing::can_play_twice(array_merge($at7, array(array('start' => '20:00', 'end' => '21:00')))) && !RSL_Pairing::can_play_twice(array()));

// The count (sat_out_counts(), behind context()'s byes). Team 10 played Unranked on night 1, moved to
// Ranked and played there on night 4. Night 2 had Ranked games only (no Unranked courts): 10 was Unranked
// then, so it did not sit out, though it's Ranked now. Night 3 had Unranked games only and 10 had none:
// a sit-out, judged by its division then. Team 11 (Ranked, no game yet) sat out nights 1, 2 and 4, not
// 3 (no Ranked games). Team 12 had a double-header on night 1 (one night, not a sit-out) and a week off
// on night 2: away, so it gets night 2's Ranked share (1 of the 3 available sat out). Team 13 joined
// after night 2: nights 1 and 2's Ranked shares (1 of 4, 1 of 3), then a sit-out on night 4. Team 21
// joined after night 1, an Unranked night with no sit-outs: nothing. Teams 20, 30 and 31 played every
// night their division had games.
$near = function ($got, $want) {
    if (!is_array($got) || count($got) !== count($want)) { return false; }
    foreach ($want as $k => $v) {
        if (!isset($got[$k]) || abs($got[$k] - $v) > 1e-6) { return false; }
    }
    return true;
};
$sc = RSL_Pairing::sat_out_counts(array(
    array('date' => '2030-01-01', 'off' => array(), 'games' => array(array('unranked', 10, 20), array('ranked', 12, 30), array('ranked', 12, 31))),
    array('date' => '2030-01-08', 'off' => array(12 => true), 'games' => array(array('ranked', 30, 31))),
    array('date' => '2030-01-15', 'off' => array(), 'games' => array(array('unranked', 20, 21))),
    array('date' => '2030-01-22', 'off' => array(), 'games' => array(array('ranked', 10, 30), array('ranked', 12, 31))),
), array(10 => array('division' => 'ranked', 'joined' => ''), 11 => array('division' => 'ranked', 'joined' => ''),
    12 => array('division' => 'ranked', 'joined' => ''), 13 => array('division' => 'ranked', 'joined' => '2030-01-10'),
    20 => array('division' => 'unranked', 'joined' => ''), 21 => array('division' => 'unranked', 'joined' => '2030-01-02'),
    30 => array('division' => 'ranked', 'joined' => ''), 31 => array('division' => 'ranked', 'joined' => '')));
$check('sat_out_counts(): a moved team is judged by its division at the time (10: night 3 only); 11 on its division\'s nights; a double-header is one night; a week off and nights before joining count the night\'s share',
    $near($sc['byes'], array(10 => 1, 11 => 3, 12 => 1 / 3, 13 => 0.25 + 1 / 3 + 1)) && $sc['sat'] === array(11 => 3, 10 => 1, 13 => 1)
    && $sc['last'] === array(11 => true, 13 => true) && $sc['doubles'] === array(12 => 1), wp_json_encode($sc));

// The credited count: every kind of night away gets that night's share of sit-outs in the team's division
// (sit-outs ÷ teams available), and a night with no sit-outs gives 0. Ranked 1-6 and 12; 7 a week off on
// night 1, 8 can't make night 1, 9 left out for owing on night 3 (all three recorded as off), 10 joins
// after night 2, 11 is away on night 1 and then plays Unranked on night 2 and Ranked on night 3 (a moved
// team: night 1 is judged by its first game after it, Unranked). Unranked 20-22, 22 away on night 3.
// Night 1: Ranked 2 of 8 sat out (1/4), Unranked 1 of 3 (1/3). Night 2: nobody sat out anywhere.
// Night 3: Ranked 3 of 11 sat out (3/11), Unranked none.
$cc = RSL_Pairing::sat_out_counts(array(
    array('date' => '2030-02-01', 'off' => array(7 => true, 8 => true, 11 => true),
        'games' => array(array('ranked', 1, 2), array('ranked', 3, 4), array('ranked', 5, 6), array('unranked', 20, 21))),
    array('date' => '2030-02-08', 'off' => array(),
        'games' => array(array('ranked', 1, 3), array('ranked', 2, 4), array('ranked', 5, 9), array('ranked', 6, 12), array('ranked', 7, 8),
            array('unranked', 11, 22), array('unranked', 20, 21))),
    array('date' => '2030-02-15', 'off' => array(9 => true, 22 => true),
        'games' => array(array('ranked', 1, 10), array('ranked', 2, 11), array('ranked', 3, 4), array('ranked', 5, 6), array('unranked', 20, 21))),
), array_fill_keys(array(1, 2, 3, 4, 5, 6, 7, 8, 9, 11, 12), array('division' => 'ranked', 'joined' => ''))
    + array(10 => array('division' => 'ranked', 'joined' => '2030-02-10'))
    + array_fill_keys(array(20, 21, 22), array('division' => 'unranked', 'joined' => '')));
$check('credited count: a week off, a can\'t-make-it and an owing night add the night\'s share (7, 8: 1/4 then a sit-out; 9: a sit-out, then 3/11); a late joiner the shares before it joined (10: 1/4, then 0 for a night with no sit-outs); a moved team its old division\'s share (11: Unranked 1/3, not Ranked 1/4)',
    $near($cc['byes'], array(7 => 1.25, 8 => 1.25, 9 => 1 + 3 / 11, 10 => 0.25, 11 => 1 / 3, 12 => 2, 22 => 1))
    && $cc['sat'] === array(9 => 1, 12 => 2, 22 => 1, 7 => 1, 8 => 1) && $cc['last'] === array(7 => true, 8 => true, 12 => true),
    wp_json_encode($cc));
$check('...a team available every night has a whole count (the nights it sat out), and one that never sat out none',
    !isset($cc['byes'][1]) && !isset($cc['byes'][20]) && $cc['byes'][12] === 2.0);
// A team approved after a night's fixtures were made, though before the night itself: the draw couldn't have
// had it, so that night is a night before it joined (the night's share), not a sit-out. Ranked 1-6; 5 joined
// on 2030-03-02 at 10:00, after night 1's fixtures (made 2030-03-01) and before its date (2030-03-03); 6 was
// in the league and sat out. Night 2 was made after 5 joined, and 5 sat out it: a sit-out. Team 4, approved
// before night 1 was made, sat out it as usual.
$ja = RSL_Pairing::sat_out_counts(array(
    array('date' => '2030-03-03', 'made' => '2030-03-01 09:00:00', 'off' => array(), 'games' => array(array('ranked', 1, 2))),
    array('date' => '2030-03-10', 'made' => '2030-03-08 09:00:00', 'off' => array(), 'games' => array(array('ranked', 1, 3), array('ranked', 2, 6))),
), array(1 => array('division' => 'ranked', 'joined' => '', 'joined_at' => '2030-02-01 09:00:00'), 2 => array('division' => 'ranked', 'joined' => ''),
    3 => array('division' => 'ranked', 'joined' => ''), 4 => array('division' => 'ranked', 'joined' => '2030-02-28', 'joined_at' => '2030-02-28 18:00:00'),
    5 => array('division' => 'ranked', 'joined' => '2030-03-02', 'joined_at' => '2030-03-02 10:00:00'), 6 => array('division' => 'ranked', 'joined' => '')));
$check('a team approved after a night\'s fixtures were made (before its date) is away that night, not sat out: 5 gets night 1\'s share (3 of 5 sat out), then a sit-out on night 2 (made after it joined); 4, approved before night 1 was made, sat out it',
    $near($ja['byes'], array(3 => 1, 4 => 2, 5 => 0.6 + 1, 6 => 1)) && $ja['sat'] === array(3 => 1, 4 => 2, 6 => 1, 5 => 1)
    && $ja['last'] === array(4 => true, 5 => true), wp_json_encode($ja));
$check('count_words(): whole counts as they are, fractions to one decimal place',
    RSL_Pairing::count_words(2.0) === '2' && RSL_Pairing::count_words(0) === '0' && RSL_Pairing::count_words(1.25) === '1.3'
    && RSL_Pairing::count_words(1 / 7) === '0.1' && RSL_Pairing::count_words(1.96) === '2'
    && RSL_Pairing::sit_out_words('courts', 1.25, 1.0) === 'Not enough courts (nights sat out before: this team 1.3, the teams playing 1 or more)'
    && RSL_Pairing::sit_out_words('odd', 0.5, 1.0, true) === 'Bye, odd number of teams (nights sat out before: this team 0.5, the teams playing 1 or more; it has never sat out, so it goes before a team that sat out its last night)');

$subsets = function ($list, $k) use (&$subsets) {
    if ($k === 0) { return array(array()); }
    if (count($list) < $k) { return array(); }
    $first = array_shift($list);
    $with = array_map(function ($s) use ($first) { array_unshift($s, $first); return $s; }, $subsets($list, $k - 1));
    return array_merge($with, $subsets($list, $k));
};

// The rule on fractional counts: a team sits out only when its count is at most sit_level (0.3) above
// every team that plays. Six teams on two courts (two sit out): 1 on 0, 2 on 0.3, 3 on 0.55, 4-6 on 1.
// 1 and 2 may sit out, or 1 and 3 (0.55 is within 0.3 of 2's 0.3); never 2 and 3 (1 would play on 0,
// more than 0.3 below 3), nor any of 4-6 while 1 plays. With sit_level 0.2, or 0, 1 and 2 only.
$frac = array(1 => 0, 2 => 0.3, 3 => 0.55, 4 => 1, 5 => 1, 6 => 1);
$family = function ($rule) use (&$subsets) {
    $sets = array();
    foreach ($rule['options'] as $o) {
        foreach ($subsets(array_keys($o['may']), $rule['k'] - count($o['must'])) as $s) {
            $s = array_merge($o['must'], $s);
            sort($s);
            $sets[implode(',', $s)] = true;
        }
    }
    ksort($sets);
    return array_keys($sets);
};
$check('fractional counts: sit_level is 0.3; 1 and 2, or 1 and 3 may sit out (counts within 0.3 are level); with sit_level 0.2 or 0, 1 and 2 only',
    $w['sit_level'] === 0.3 && $family(RSL_Pairing::sit_out(range(1, 6), $frac, 2, array(), array(), $w, 'fr')) === array('1,2', '1,3')
    && $family(RSL_Pairing::sit_out(range(1, 6), $frac, 2, array(), array(), array('sit_level' => 0.2) + $w, 'fr')) === array('1,2')
    && $family(RSL_Pairing::sit_out(range(1, 6), $frac, 2, array(), array(), array('sit_level' => 0.0) + $w, 'fr')) === array('1,2'));
// Whole counts are never level: with 1 on 0 and 2-6 on 1, 1 always sits out.
$check('...whole counts are never level with each other: 1 (on 0) always sits out first, whatever sit_level allows',
    $family(RSL_Pairing::sit_out(range(1, 6), array(1 => 0, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1), 2, array(), array(), array('sit_level' => 9) + $w, 'fr'))
    === array('1,2', '1,3', '1,4', '1,5', '1,6'));
// And the draw takes the cheaper of them: 2 is far from everyone and 3 is not, so 1 and 2 sit out.
$rf = $r(array(1 => 1500, 2 => 1950, 3 => 1505, 4 => 1500, 5 => 1495, 6 => 1510));
$pf = RSL_Pairing::pair_division(range(1, 6), $rf, array(), $frac, $w, 'fr', null, 2);
$rf2 = $r(array(1 => 1500, 2 => 1505, 3 => 1950, 4 => 1500, 5 => 1495, 6 => 1510));
$pf2 = RSL_Pairing::pair_division(range(1, 6), $rf2, array(), $frac, $w, 'fr', null, 2);
$keys = function ($out) { $k = array_keys($out); sort($k); return $k; };
$check('...and among them the draw\'s cost decides: the far team sits out (2 in one draw, 3 in the other), with 1',
    $keys($pf['out']) === array(1, 2) && $keys($pf2['out']) === array(1, 3),
    wp_json_encode(array($pf['out'], $pf2['out'])));

// The guard: a team that sat out its last night doesn't sit out again while a team that has never sat out
// plays. Seven teams, three courts (one sits out). 7 sat out last night (count 1); 6 has never sat out but
// was away a lot (credited 0.9, level with 7); 1-5 on 1 as well. 7 is far from everyone, so the cost alone
// would put it out again; the guard keeps it in and another team on the line sits out.
$g7 = array(1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 0.9, 7 => 1);
$sat7 = array(1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 7 => 1);
$r7g = $r(array(1 => 1500, 2 => 1505, 3 => 1495, 4 => 1510, 5 => 1490, 6 => 1500, 7 => 1950));
$free = RSL_Pairing::pair_division(range(1, 7), $r7g, array(), $g7, $w, 'gd', null, 3);
$kept = RSL_Pairing::pair_division(range(1, 7), $r7g, array(), $g7, $w, 'gd', null, 3, array(), array(), array('sat' => $sat7, 'last' => array(7 => true)));
$check('twice in a row: without the guard the far team 7 (sat out last night) would sit out again while 6 (never sat out) plays; with it 7 plays and another team level on the count sits out',
    array_keys($free['out']) === array(7) && count($kept['out']) === 1 && !isset($kept['out'][7]) && $kept['first'] === array(),
    wp_json_encode(array($free['out'], $kept['out'])));
// When the count alone would put 7 out (its 1 is the lowest by more than sit_level) and 6, never sat out, is on
// 1.6: 6 goes first (first), 7 plays, and the reason says why.
$g7b = array(1 => 2, 2 => 2, 3 => 2, 4 => 2, 5 => 2, 6 => 1.6, 7 => 1);
$sat7b = array(1 => 2, 2 => 2, 3 => 2, 4 => 2, 5 => 2, 7 => 1);
$first = RSL_Pairing::pair_division(range(1, 7), $r7g, array(), $g7b, $w, 'gd', null, 3, array(), array(), array('sat' => $sat7b, 'last' => array(7 => true)));
$check('...and when the count would put 7 out again, the team that has never sat out goes first instead (6 out, marked first; 7 plays)',
    array_keys($first['out']) === array(6) && $first['first'] === array(6 => true)
    && array_keys(RSL_Pairing::pair_division(range(1, 7), $r7g, array(), $g7b, $w, 'gd', null, 3)['out']) === array(7), wp_json_encode($first));
// An odd night: five teams, room for all, 5 sat out last night, 4 has never sat out (credited 0.8).
$odd5 = RSL_Pairing::pair_division(range(1, 5), $r(array(1 => 1500, 2 => 1500, 3 => 1500, 4 => 1500, 5 => 1900)), array(), array(1 => 1, 2 => 1, 3 => 1, 4 => 0.8, 5 => 1), $w, 'g5',
    null, null, array(), array(), array('sat' => array(1 => 1, 2 => 1, 3 => 1, 5 => 1), 'last' => array(5 => true)));
$check('...on an odd night too: the team that sat out last night is never the bye while one that has never sat out plays', $odd5['bye'] !== 5 && count($odd5['out']) === 1, wp_json_encode($odd5['out']));
// Not playing this night (explain_night()'s _first, went_first()) names the guard as the generate notice does: a
// resting team that has never sat out, above the line by more than sit_level while a team that sat out its last
// night plays. The count alone never puts a team out that far above one that plays, so that is the guard.
$wl = array('sit_level' => 0.5) + $w;
$check('went_first(): never sat out, 0.8 above the lowest team playing, and a team that sat out its last night plays; not within sit_level, not a team that has sat out, not with no such team playing',
    RSL_Pairing::went_first(1.8, 1.0, true, true, $wl) && !RSL_Pairing::went_first(1.5, 1.0, true, true, $wl) && !RSL_Pairing::went_first(1.8, 1.0, false, true, $wl)
    && !RSL_Pairing::went_first(1.8, 1.0, true, false, $wl) && !RSL_Pairing::went_first(1.8, null, true, true, $wl)
    && RSL_Pairing::went_first(1.3, 1.0, true, true, array('sit_level' => 0.25) + $w));
$check('resting_words(): the count against the teams playing, and the guard\'s reason when it put the team first',
    RSL_Pairing::resting_words(1.0, 0.0) === 'bye — odd numbers or not enough courts; nights sat out before: 1, the teams playing 0 or more'
    && RSL_Pairing::resting_words(1.8, 1.0, true) === 'bye — odd numbers or not enough courts; nights sat out before: 1.8, the teams playing 1 or more; it has never sat out, so it goes before a team that sat out its last night'
    && RSL_Pairing::resting_words(0.4, null) === 'bye — odd numbers or not enough courts; nights sat out before: 0.4');

// The rule against brute force: 400 small random nights (3 to 9 teams, 1 to 5 courts or no limit, counts
// whole or with a share of nights away, random ratings, meetings, double-header teams and their
// double-header nights, and which teams have never sat out or sat out their last night; a quarter with no
// guard). Every choice of who sits out is tried with every pairing of the rest. A choice keeps the rule
// when each team out has a count at most sit_level above every team that plays and, with the guard, no
// team that sat out its last night is out while one that has never sat out plays; when no choice keeps
// both, the teams that have never sat out are taken as just below the lowest of those that sat out their
// last night (and marked first). On an odd night with a spare court, when some team could play a fill
// game, the odd team is one of those within sit_level of the lowest among them (it plays its fill game;
// its rank counts) or a team without one within sit_level of the division's lowest (it sits out, at
// odd_sit). Each team out adds its tie-break. The draw keeps the rule and costs exactly the best choice.
$level = min(0.5, $w['sit_level']);
$jit = function ($salt, $t) { return (crc32($salt . '|bye|' . $t) % 1000) / 100000; };
$of = function ($c, $list) { return array_map(function ($x) use ($c) { return $c[$x]; }, $list); };
mt_srand(2026);
$bad = array();
$used_first = 0;
for ($trial = 0; $trial < 400 && count($bad) < 3; $trial++) {
    $n = mt_rand(3, 9);
    $ids = range(1, $n);
    $room = mt_rand(0, 5) ?: null;
    $cnt = $raw = $last = $rt = $mt = $opt = $dbl = array();
    foreach ($ids as $t) {
        $raw[$t] = mt_rand(0, 2);
        $cnt[$t] = $raw[$t] + (mt_rand(0, 2) === 0 ? mt_rand(1, 20) / 10 : 0); // a share of nights away, now and then a lot
        if ($raw[$t] > 0 && mt_rand(0, 1) === 0) { $last[$t] = true; }
        $rt[$t] = array('rating' => 1500 + mt_rand(-300, 300), 'sd' => mt_rand(0, 150));
        if (mt_rand(0, 3) === 0) { $opt[] = $t; $dbl[$t] = mt_rand(0, 3); }
        foreach ($ids as $u) { if ($t < $u && mt_rand(0, 4) === 0) { $mt["$t-$u"] = array('count' => mt_rand(1, 2), 'last' => mt_rand(1, 4)); } }
    }
    $salt = "bf$trial";
    $guard = mt_rand(0, 3) === 0 ? array() : array('sat' => $raw, 'last' => $last);
    $p = RSL_Pairing::pair_division($ids, $rt, $mt, $cnt, $w, $salt, null, $room, $opt, $dbl, $guard);
    $game = function ($a, $b) use ($rt, $mt, $w, $salt) { return RSL_Pairing::pair_cost($a, $b, $rt, $mt, $w, $salt)['cost']; };
    $short = $room !== null && $n > 2 * $room;
    $k = $short ? $n - 2 * $room : $n % 2;
    $odd = $k === 1 && !$short;
    $fills = array();
    if ($odd) {
        foreach ($ids as $t) { $f = RSL_Pairing::fill_for($t, $opt, $rt, $mt, $w, $salt, $dbl); if ($f) { $fills[$t] = $f; } }
    }
    $never = $guard ? array_values(array_filter($ids, function ($t) use ($raw) { return $raw[$t] === 0; })) : array();
    $lastly = $guard ? array_keys($last) : array();
    // Whether choice $s (who is out, or the odd team) keeps the rule on counts $c, with or without the guard.
    $keeps = function ($s, $c, $with_guard) use ($ids, $level, $odd, $fills, $never, $lastly, $of) {
        $rest = array_values(array_diff($ids, $s));
        if ($odd && $fills) {
            $t = $s[0];
            $low = isset($fills[$t]) ? min($of($c, array_keys($fills))) : min($c);
            if ($c[$t] > $low + $level + 1e-9) { return false; }
            return isset($fills[$t]) || !$with_guard || !$never || !in_array($t, $lastly, true);
        }
        if ($s && $rest && max($of($c, $s)) > min($of($c, $rest)) + $level + 1e-9) {
            return false;
        }
        return !$with_guard || !$never || !array_intersect($s, $lastly) || !array_diff($never, $s);
    };
    $choices = $subsets($ids, $k);
    $allowed = array_filter($choices, function ($s) use ($keeps, $cnt) { return $keeps($s, $cnt, true); });
    $c2 = $cnt;
    $want_first = array();
    if (!$allowed) {
        $floor = min($of($cnt, $lastly)) - $level - 0.01;
        foreach ($never as $t) { if ($c2[$t] > $floor) { $c2[$t] = $floor; $want_first[$t] = true; } }
        $allowed = array_filter($choices, function ($s) use ($keeps, $c2) { return $keeps($s, $c2, false); });
        $used_first++;
    }
    $extra = function ($t) use ($odd, $opt, $fills, $w, $jit, $salt) {
        if (!$odd) { return $jit($salt, $t); }
        return (in_array($t, $opt, true) ? $w['odd_double'] : 0) + (isset($fills[$t]) ? $fills[$t][2] : ($fills ? $w['odd_sit'] : 0)) + $jit($salt, $t);
    };
    $best = INF;
    foreach ($allowed as $s) {
        $v = $brute(array_values(array_diff($ids, $s)), $game);
        foreach ($s as $t) { $v += $extra($t); }
        $best = min($best, $v);
    }
    // The draw's choice.
    $left = $p['bye'] !== null ? array($p['bye']) : array_keys($p['out']);
    $paired = array();
    $mine = 0.0;
    foreach ($p['pairs'] as $g) { $paired[$g[0]] = $paired[$g[1]] = true; $mine += $g[2]['cost']; }
    foreach ($left as $t) { $mine += $extra($t); }
    sort($left);
    $holds = count($p['pairs']) === ($n - $k) / 2 && count($left) === $k && count($paired) + $k === $n && !array_intersect($left, array_keys($paired))
        && in_array($left, array_map(function ($s) { sort($s); return $s; }, array_values($allowed)), true)
        && array_intersect_key($want_first, $p['out']) == $p['first']
        && ($odd ? (isset($fills[$p['bye']]) ? $p['out'] === array() && $p['fill'] && $p['fill'][0] === $fills[$p['bye']][0]
                                             : $p['fill'] === null && $p['out'] === array($p['bye'] => 'odd'))
                 : $p['fill'] === null && $p['bye'] === null && (!$p['out'] || array_values(array_unique($p['out'])) === array('courts')));
    if (!$holds || abs($mine - $best) > 1e-6 || abs($p['total'] - $mine) > 1e-6) {
        $bad[] = "trial $trial: n $n room " . var_export($room, true) . ($holds ? '' : ' rule broken') . " draw $mine (total {$p['total']}) best $best";
    }
}
$check("the rule against brute force (400 random nights with fractional counts and the guard; $used_first needed the guard's fallback): the draw keeps the rule and is the cheapest choice that does",
    !$bad && $used_first > 0, implode('; ', $bad));

// The spread among teams available every night, against brute force: 60 small random seasons (4 to 7
// teams, 4 or 5 nights, one or two courts or none, weeks off on 20% of nights, a late joiner in half of them).
// On every night every choice the rule allows is followed (sit_out()'s options on sat_out_counts() of the
// nights so far, with the guard), so every season the draw could make is walked. Each night the rule's
// choices are exactly those that keep it (each team out within sit_level of every team that plays, and
// the guard, or its fallback). At the end of every season the teams available every night are at most
// one sit-out apart, and no team sat out twice in a row (its nights available) while a team that had
// never sat out played.
mt_srand(77);
$bad = array();
$walked = 0;
for ($trial = 0; $trial < 60 && count($bad) < 3; $trial++) {
    $n = mt_rand(4, 7);
    $rounds = mt_rand(4, 5);
    $rooms = $offs = array();
    for ($i = 1; $i <= $rounds; $i++) {
        $rooms[$i] = mt_rand(0, 2) ?: null;
        foreach (range(1, $n) as $t) { if (mt_rand(1, 100) <= 20) { $offs[$i][$t] = true; } }
    }
    $join = array_fill_keys(range(1, $n), 1);
    if (mt_rand(0, 1)) { $join[$n] = mt_rand(2, $rounds); }
    $info = array();
    foreach (range(1, $n) as $t) { $info[$t] = array('division' => 'ranked', 'joined' => $join[$t] > 1 ? sprintf('2030-03-%02d', 7 * $join[$t] - 1) : ''); }
    $every = array_values(array_filter(range(1, $n), function ($t) use ($join, $offs) {
        if ($join[$t] > 1) { return false; }
        foreach ($offs as $o) { if (isset($o[$t])) { return false; } }
        return true;
    }));
    $walk = function ($i, $history, $seq) use (&$walk, &$bad, &$walked, $rounds, $rooms, $offs, $join, $info, $every, $n, $w, $subsets, $level, $of) {
        if (count($bad) >= 3) { return; }
        if ($i > $rounds) {
            $walked++;
            $sat = array_map(function ($t) use ($seq) { return isset($seq[$t]) ? substr_count(implode('', $seq[$t]), 'O') : 0; }, $every);
            if (count($sat) > 1 && max($sat) - min($sat) > 1) { $bad[] = 'spread ' . wp_json_encode($seq); }
            return;
        }
        $ids = array();
        foreach (range(1, $n) as $t) { if ($join[$t] <= $i && !isset($offs[$i][$t])) { $ids[] = $t; } }
        $counts = RSL_Pairing::sat_out_counts($history, $info);
        $rule = RSL_Pairing::sit_out($ids, $counts['byes'], $rooms[$i], array(), array(), $w, "s$i", array('sat' => $counts['sat'], 'last' => $counts['last']));
        $ruled = array('' => array());
        if ($rule['k'] > 0) {
            $ruled = array();
            foreach ($rule['options'] as $o) {
                foreach ($subsets(array_keys($o['may']), $rule['k'] - count($o['must'])) as $s) {
                    $s = array_merge($o['must'], $s);
                    sort($s);
                    $ruled[implode(',', $s)] = $s;
                }
            }
        }
        // The same choices from the definition, with who has never sat out and who sat out its last night read from the walk itself.
        $c = array();
        $never = $lastly = array();
        foreach ($ids as $t) {
            $c[$t] = isset($counts['byes'][$t]) ? $counts['byes'][$t] : 0.0;
            $mine = isset($seq[$t]) ? $seq[$t] : array();
            if (!in_array('O', $mine, true)) { $never[] = $t; } elseif (end($mine) === 'O') { $lastly[] = $t; }
        }
        $keeps = function ($s, $c, $with_guard) use ($ids, $level, $never, $lastly, $of) {
            $rest = array_values(array_diff($ids, $s));
            if ($s && $rest && max($of($c, $s)) > min($of($c, $rest)) + $level + 1e-9) {
                return false;
            }
            return !$with_guard || !$never || !array_intersect($s, $lastly) || !array_diff($never, $s);
        };
        $defined = array_values(array_filter($subsets($ids, $rule['k']), function ($s) use ($keeps, $c) { return $keeps($s, $c, true); }));
        if (!$defined) {
            $floor = min($of($c, $lastly)) - $level - 0.01;
            foreach ($never as $t) { $c[$t] = min($c[$t], $floor); }
            $defined = array_values(array_filter($subsets($ids, $rule['k']), function ($s) use ($keeps, $c) { return $keeps($s, $c, false); }));
        }
        $want = array_map(function ($s) { sort($s); return implode(',', $s); }, $defined);
        sort($want);
        $got = array_map('strval', array_keys($ruled)); // a single team's key is an int
        sort($got);
        if ($got !== $want) { $bad[] = "night $i choices " . implode(';', $got) . ' want ' . implode(';', $want); return; }
        foreach ($ruled as $s) {
            $play = array_values(array_diff($ids, $s));
            if (array_intersect($s, $lastly) && array_intersect($play, $never)) { $bad[] = 'twice in a row ' . wp_json_encode($seq); return; }
            $games = array();
            for ($x = 0; $x + 1 < count($play); $x += 2) { $games[] = array('ranked', $play[$x], $play[$x + 1]); }
            $next = $seq;
            if ($games) { // a night with no games doesn't count
                foreach ($ids as $t) { $next[$t][] = in_array($t, $s, true) ? 'O' : 'P'; }
            }
            $walk($i + 1, array_merge($history, array(array('date' => sprintf('2030-03-%02d', 7 * $i), 'off' => isset($offs[$i]) ? $offs[$i] : array(), 'games' => $games))), $next);
        }
    };
    $walk(1, array(), array());
}
$check("the spread against brute force ($walked seasons walked, every choice the rule allows each night): the rule's choices are exactly those that keep it, teams available every night end at most one sit-out apart, and nobody sits out twice in a row while a team that has never sat out plays",
    !$bad && $walked > 1000, implode('; ', $bad));

// Above EXACT_LIMIT (teams plus sit-out places), the local search keeps the rule and comes close to exact.
$exact = new ReflectionMethod('RSL_Pairing', 'match_exact');
$exact->setAccessible(true);
$ok = true;
$worst = 0.0;
for ($trial = 0; $trial < 4; $trial++) {
    $big = array();
    foreach (range(1, 16) as $t) { $big[$t] = array('rating' => 1500 + mt_rand(-300, 300), 'sd' => 80); }
    $bsat = array();
    foreach (range(1, 16) as $t) { $bsat[$t] = mt_rand(0, 1); }
    $p = RSL_Pairing::pair_division(range(1, 16), $big, array(), $bsat, $w, "big$trial", null, 5); // 16 teams + 6 places: the search
    $out = array_keys($p['out']);
    $in = array_values(array_diff(range(1, 16), $out));
    $ok = $ok && count($p['pairs']) === 5 && count($out) === 6 && max(array_map(function ($t) use ($bsat) { return $bsat[$t]; }, $out)) <= min(array_map(function ($t) use ($bsat) { return $bsat[$t]; }, $in));
    // The exact answer for the same rule (match_out()'s matrix through the exact solver).
    $rule = RSL_Pairing::sit_out(range(1, 16), $bsat, 5, array(), array(), $w, "big$trial");
    $play = array_values(array_diff(range(1, 16), $rule['must']));
    $kk = $rule['k'] - count($rule['must']);
    $real = count($play);
    $cm = array();
    for ($i = 0; $i < $real + $kk; $i++) {
        for ($j = $i + 1; $j < $real + $kk; $j++) {
            $cm[$i][$j] = $cm[$j][$i] = $j < $real ? RSL_Pairing::pair_cost($play[$i], $play[$j], $big, array(), $w, "big$trial")['cost']
                : ($i < $real ? (isset($rule['may'][$play[$i]]) ? $rule['may'][$play[$i]] : RSL_Pairing::NEVER) : RSL_Pairing::NEVER);
        }
    }
    $e = $rule['options'][0]['base']; // the tie-breaks of the teams that must sit out
    foreach ($exact->invoke(null, $cm, $real + $kk, $real) as $pr) { $e += $cm[$pr[0]][$pr[1]]; }
    $worst = max($worst, $p['total'] - $e);
    $ok = $ok && $p['total'] <= $e * 1.05 + 0.05;
}
$check('above EXACT_LIMIT the search keeps the rule and comes within 5% of exact', $ok, 'worst gap ' . round($worst, 3));

// --- Slots ---------------------------------------------------------------------------------
$slots = array(
    array('court_id' => 1, 'start' => '19:00', 'end' => '20:00'), array('court_id' => 2, 'start' => '19:00', 'end' => '20:00'),
    array('court_id' => 1, 'start' => '20:00', 'end' => '21:00'), array('court_id' => 2, 'start' => '20:00', 'end' => '21:00'),
    array('court_id' => 1, 'start' => '21:00', 'end' => '22:00'), array('court_id' => 2, 'start' => '21:00', 'end' => '22:00'),
);
$at = RSL_Pairing::assign_slots(array(array(1, 2), array(3, 4), array(5, 6), array(7, 8)), $slots,
    array(1 => array('time' => 'late'), 3 => array('time' => 'early'), 5 => array('time' => 'late'), 7 => array('time' => 'early')));
$check('the night is compact (no empty 9pm when 7 and 8 fit everyone)', max(array_map(function ($i) use ($slots) { return $slots[$i]['start']; }, $at)) === '20:00');
$check('early teams play first, late teams last', $slots[$at[1]]['start'] === '19:00' && $slots[$at[3]]['start'] === '19:00' && $slots[$at[0]]['start'] === '20:00' && $slots[$at[2]]['start'] === '20:00');
$at = RSL_Pairing::assign_slots(array(array(1, 2), array(1, 3), array(4, 5)), $slots, array(1 => array('shape' => 'back')));
$starts = array($slots[$at[0]]['start'], $slots[$at[1]]['start']);
sort($starts);
$check('a double-header never overlaps, and back-to-back is back-to-back', $starts[0] !== $starts[1] && strtotime("2000-01-01 $starts[1]") - strtotime("2000-01-01 $starts[0]") === 3600);
$at = RSL_Pairing::assign_slots(array(array(1, 2), array(1, 3), array(4, 5)), $slots, array(1 => array('shape' => 'break')));
$starts = array($slots[$at[0]]['start'], $slots[$at[1]]['start']);
sort($starts);
$check('with a break means a slot in between', $starts === array('19:00', '21:00'));

// --- A real night -------------------------------------------------------------------------------
update_option(RSL_Seasons::CURRENT_OPTION, $season);
// Only our venues for this test: park any others for the duration.
$parked = $wpdb->get_col("SELECT id FROM " . RSL_Database::table('venues') . " WHERE active = 1");
if ($parked) { $wpdb->query("UPDATE " . RSL_Database::table('venues') . " SET active = 0 WHERE id IN (" . implode(',', array_map('intval', $parked)) . ")"); }
$hall = RSL_Courts::create_venue("Pairing Hall $suffix", 'ranked');
foreach (array('P1', 'P2', 'P3') as $c) { RSL_Courts::create_court($hall->id, $c, '19:00', '22:00'); }
$uni = RSL_Courts::create_venue("Pairing Uni $suffix", 'unranked');
RSL_Courts::create_court($uni->id, 'U1', '18:00', '21:00');
$today = current_time('Y-m-d');
RSL_Courts::save_settings($season, array('start' => date('Y-m-d', strtotime($today . ' +7 days')), 'end' => date('Y-m-d', strtotime($today . ' +41 days')), 'weekday' => 0, 'minutes' => array('ranked' => 60, 'unranked' => 60)));
RSL_Courts::generate_nights($season);
$nights = RSL_Courts::nights($season);
$levels = array('advanced', 'strong', 'solid', 'solid', 'social', 'new');
$teams = array();
foreach ($levels as $i => $level) {
    $teams[] = RSL_Teams::create($season, array('name' => "Pair R$i $suffix", 'division' => 'ranked', 'level' => $level));
}
$u = array();
foreach (array(0, 1, 2) as $i) {
    $u[] = RSL_Teams::create($season, array('name' => "Pair U$i $suffix", 'division' => 'unranked', 'level' => 'new'));
}
$wpdb->update(RSL_Database::table('teams'), array('pref_double' => 1, 'pref_double_shape' => 'back'), array('id' => $u[0]->id));
$ids = array_map(function ($t) { return intval($t->id); }, $teams);

$res = RSL_Pairing::generate($nights[0]->id);
$games = RSL_Fixtures::games_for_night($nights[0]->id);
$ranked_games = array_filter($games, function ($g) { return $g->division === 'ranked'; });
$in = array();
foreach ($ranked_games as $g) { $in[] = intval($g->home_team_id); $in[] = intval($g->away_team_id); }
sort($in);
$sorted = $ids;
sort($sorted);
$check('every Ranked team gets one game', !is_wp_error($res) && count($ranked_games) === 3 && $in === $sorted);
$top = $has_pair(array_map(function ($g) { return array($g->home_team_id, $g->away_team_id); }, $ranked_games), $ids[0], $ids[1]);
$check('week one pairs by the level estimates (top two together)', $top);
// 0.18.1: fixtures use the FIXTURES rating (seeded by level); the ladder rating, which starts every
// team on the same number, would see six identical teams in week one.
$ctx1 = RSL_Pairing::context($nights[0]);
$seeded = true;
foreach ($teams as $i => $t) {
    $seeded = $seeded && isset($ctx1['ratings'][intval($t->id)]) && abs($ctx1['ratings'][intval($t->id)]['rating'] - RSL_Teams::level_seed_rating($levels[$i])) < 0.01;
}
$ladder1 = RSL_Ratings::compute($season, date('Y-m-d', strtotime($nights[0]->night_date . ' -1 day')), 'ladder');
$flat = count($ladder1) === count($teams) && max(array_column($ladder1, 'rating')) - min(array_column($ladder1, 'rating')) < 0.01;
$check('pairing uses the seeded rating in week one: each team at its level seed, while the ladder rating has every team on the same start', $seeded && $flat);
$top_game = null;
foreach ($ranked_games as $g) {
    if (in_array(intval($g->home_team_id), array($ids[0], $ids[1]), true) && in_array(intval($g->away_team_id), array($ids[0], $ids[1]), true)) {
        $top_game = $g;
    }
}
$why1 = RSL_Pairing::explain_night($nights[0]->id);
$p_adv = $top_game && isset($why1[intval($top_game->id)]) ? $why1[intval($top_game->id)]['p'] : null;
if ($p_adv !== null && intval($top_game->home_team_id) !== $ids[0]) {
    $p_adv = 1 - $p_adv; // the advanced team's chance, whichever side it's on
}
$check('...and the admin set chance comes from it (advanced over strong: above 50%, where the ladder rating would say 50/50)', $p_adv !== null && $p_adv > 0.55,
    $p_adv === null ? 'no top game' : (string) round($p_adv, 3));
$ug = array_values(array_filter($games, function ($g) { return $g->division === 'unranked'; }));
$check('odd Unranked: the spare team gets a game against the double-header team', count($ug) === 2
    && count(array_filter($ug, function ($g) use ($u) { return in_array(intval($u[0]->id), array(intval($g->home_team_id), intval($g->away_team_id)), true); })) === 2);
$why = RSL_Pairing::explain_night($nights[0]->id);
$first = reset($ranked_games);
$check('each game explains itself (first meeting, set chance)', isset($why[intval($first->id)]) && $why[intval($first->id)]['count'] === 0 && $why[intval($first->id)]['p'] !== null);

$again = RSL_Pairing::generate($nights[0]->id);
$check('remaking replaces the night\'s games', !is_wp_error($again) && count(RSL_Fixtures::games_for_night($nights[0]->id)) === count($games));
RSL_Fixtures::publish_night($nights[0]->id);
$check('a published night can\'t be remade', is_wp_error(RSL_Pairing::generate($nights[0]->id)));
foreach (RSL_Fixtures::games_for_night($nights[0]->id) as $g) {
    RSL_Fixtures::record_result($g->id, '25-20, 25-20, 20-25');
}
RSL_Fixtures::publish_night($nights[0]->id, false);
$check('nor one with results in', is_wp_error(RSL_Pairing::generate($nights[0]->id)) && RSL_Pairing::generate($nights[0]->id)->get_error_code() === 'rsl_results');
$ctx_unpub = RSL_Pairing::context($nights[2]);
RSL_Fixtures::publish_night($nights[0]->id);

// The link term from a real season (0.18.1): week one's three Ranked games leave three pairs that no
// chain of games connects, but that is one night of the division's results, so round 3 has no link
// term while week two's aren't in (nights without results don't count); round 2 has none either, nor
// round 3 while week one is unpublished.
$no_link = array('groups' => array(), 'bonus' => 0.0, 'nights' => 0);
$check('context(): round 3 with only week one\'s results has no link term (one night of the division\'s results); round 2 none; round 3 none while week one is unpublished',
    RSL_Pairing::context($nights[2])['link'] === $no_link && RSL_Pairing::context($nights[1])['link'] === $no_link && $ctx_unpub['link'] === $no_link,
    wp_json_encode(RSL_Pairing::context($nights[2])['link']));

// Byes: a team only sat out for the fixtures' sake if it was in the league by then (approved, not
// just applied) and wasn't away: a week off, left out for owing, or said it couldn't make that night.
// A night away counts its share of sit-outs instead (0.18.2). Six teams that weren't in week one's draw,
// all in the league before it was made except Late (approved the day after the night) and Drawn (approved
// after week one's fixtures were made, before the night: the draw couldn't have had it).
$extra = array();
foreach (array('Bye', 'Late', 'Cant', 'Off', 'Owes', 'Drawn') as $tag) {
    $extra[$tag] = RSL_Teams::create($season, array('name' => "Pair $tag $suffix", 'division' => 'ranked', 'level' => 'solid'));
    $wpdb->update(RSL_Database::table('teams'), array('created_at' => date('Y-m-d H:i:s', strtotime($today . ' -3 days'))), array('id' => $extra[$tag]->id));
}
$wpdb->update(RSL_Database::table('teams'), array('decided_at' => date('Y-m-d 10:00:00', strtotime($nights[0]->night_date . ' +1 day'))), array('id' => $extra['Late']->id));
$week1_made = $wpdb->get_var($wpdb->prepare("SELECT MIN(created_at) FROM " . RSL_Database::table('games') . " WHERE night_id = %d", $nights[0]->id));
$wpdb->update(RSL_Database::table('teams'), array('decided_at' => date('Y-m-d H:i:s', strtotime($week1_made . ' +1 minute'))), array('id' => $extra['Drawn']->id));
foreach (array('Cant' => array('cant_make', 'handled'), 'Off' => array('off', 'active'), 'Owes' => array('unpaid', 'handled')) as $tag => $req) {
    $wpdb->insert(RSL_Database::table('requests'), array('team_id' => $extra[$tag]->id, 'night_id' => $nights[0]->id, 'type' => $req[0], 'status' => $req[1],
        'requested_by' => 0, 'requested_at' => current_time('mysql')));
}
$ctx = RSL_Pairing::context($nights[1]);
$bye_id = intval($extra['Bye']->id);
$check('a team in the league but left out of a night has sat out once (and sat out its last night)', isset($ctx['byes'][$bye_id]) && abs($ctx['byes'][$bye_id] - 1) < 1e-9
    && $ctx['sat'][$bye_id] === 1 && !empty($ctx['last'][$bye_id]), wp_json_encode($ctx['byes']));
$away_ok = true;
foreach (array('Late', 'Cant', 'Off', 'Owes', 'Drawn') as $tag) {
    $id = intval($extra[$tag]->id);
    $away_ok = $away_ok && isset($ctx['byes'][$id]) && abs($ctx['byes'][$id] - 1 / 7) < 1e-6 && !isset($ctx['sat'][$id]) && empty($ctx['last'][$id]);
}
$check('...one away that night (approved after it, approved after its fixtures were made, can\'t make it, a week off, left out for owing) gets the night\'s share instead: 1/7, as one of the seven Ranked teams available sat out',
    $away_ok, wp_json_encode($ctx['byes']));
// A game in any division counts (0.18.2). A team moved from Unranked to Ranked after week one played
// that night: before, Ranked was judged first and on its own games only, so the move counted week one
// as a sit-out. And a double-header night is one night played, not a sit-out.
$moved = intval($u[1]->id);
$wpdb->update(RSL_Database::table('teams'), array('division' => 'ranked'), array('id' => $moved));
$ctx_moved = RSL_Pairing::context($nights[1]);
$wpdb->update(RSL_Database::table('teams'), array('division' => 'unranked'), array('id' => $moved));
$check('context(): a team moved from Unranked to Ranked has not sat out the night it played in Unranked; the double-header team has one double and no sit-out',
    !isset($ctx_moved['byes'][$moved]) && !isset($ctx_moved['byes'][intval($u[0]->id)]) && isset($ctx_moved['doubles'][intval($u[0]->id)]) && $ctx_moved['doubles'][intval($u[0]->id)] === 1
    && isset($ctx_moved['byes'][intval($extra['Bye']->id)]), wp_json_encode($ctx_moved['byes']));
foreach ($extra as $t) {
    $wpdb->delete(RSL_Database::table('requests'), array('team_id' => $t->id));
    $wpdb->delete(RSL_Database::table('teams'), array('id' => $t->id));
}
// A called-off night (0.18.2): one that was never published and has started never happened, so its
// draft adds no sit-outs and no meetings; a draft for a night still to come counts. A season of five
// weekly nights, three gone (the first drafted 1-2 and never published) and two to come (the first
// drafted 1-3). Every team was in the league from the start.
$past_start = date('Y-m-d', strtotime($today . ' -15 days'));
RSL_Courts::save_settings($season_past, array('start' => $past_start, 'end' => date('Y-m-d', strtotime($today . ' +13 days')),
    'weekday' => intval(date('w', strtotime($past_start))), 'minutes' => array('ranked' => 60, 'unranked' => 60)));
RSL_Courts::generate_nights($season_past);
$pn = array_values(array_filter(RSL_Courts::nights($season_past), function ($n) { return $n->type !== 'bye'; }));
$pt = array();
foreach (array(1, 2, 3, 4) as $i) {
    $pt[$i] = intval(RSL_Teams::create($season_past, array('name' => "Pair Past$i $suffix", 'division' => 'ranked', 'level' => 'solid'))->id);
}
$wpdb->query($wpdb->prepare("UPDATE " . RSL_Database::table('teams') . " SET created_at = %s, decided_at = NULL WHERE season = %s", $past_start . ' 09:00:00', $season_past));
$past_game = function ($night, $home, $away) {
    $s = array_values(array_filter(RSL_Courts::slots_for_night($night->id), function ($s) { return $s['division'] === 'ranked'; }));
    return RSL_Fixtures::add_game($night->id, array('home' => $home, 'away' => $away, 'court_id' => $s[0]['court_id'], 'start' => $s[0]['start']));
};
$called = count($pn) === 5 ? $past_game($pn[0], $pt[1], $pt[2]) : null;
$ahead = count($pn) === 5 ? $past_game($pn[3], $pt[1], $pt[3]) : null;
$cx = count($pn) === 5 ? RSL_Pairing::context($pn[4]) : array('byes' => null, 'meetings' => null);
$cx_byes = (array) $cx['byes'];
ksort($cx_byes);
$check('context(): a called-off night\'s draft (never published, now past) adds no sit-outs and no meeting; a draft for a night to come counts (2 and 4 sat out, 1 met 3)',
    $called && !is_wp_error($called) && $ahead && !is_wp_error($ahead) && $cx_byes === array($pt[2] => 1.0, $pt[4] => 1.0)
    && !isset($cx['meetings'][RSL_Pairing::meeting_key($pt[1], $pt[2])]) && isset($cx['meetings'][RSL_Pairing::meeting_key($pt[1], $pt[3])]),
    wp_json_encode(array(count($pn), $cx['byes'], $cx['meetings'])));
if (count($pn) === 5) {
    RSL_Fixtures::publish_night($pn[0]->id);
    $cx = RSL_Pairing::context($pn[4]);
}
$cx_byes = (array) $cx['byes'];
ksort($cx_byes);
$check('...published, the same past night counts: 3 and 4 sat out it too, and 1 met 2',
    $cx_byes === array($pt[2] => 1.0, $pt[3] => 1.0, $pt[4] => 2.0) && isset($cx['meetings'][RSL_Pairing::meeting_key($pt[1], $pt[2])]), wp_json_encode($cx['byes']));
// plan() with too few courts (0.18.2): five more Unranked teams for three Unranked slots. The five new
// teams were in the league for week one with no game (one night sat out each); the three that played
// week one are on none (the double-header team owes for its two games, so it's out for that), so the
// court sit-outs are teams that played week one, and each reason gives the counts.
$ux = array();
foreach (array(1, 2, 3, 4, 5) as $i) {
    $ux[] = intval(RSL_Teams::create($season, array('name' => "Pair UX$i $suffix", 'division' => 'unranked', 'level' => 'new'))->id);
}
// In the league before week one's fixtures were made, so it counts as a night they sat out.
$wpdb->query("UPDATE " . RSL_Database::table('teams') . " SET created_at = '" . esc_sql(date('Y-m-d H:i:s', strtotime($today . ' -3 days'))) . "' WHERE id IN (" . implode(',', $ux) . ")");
$plan2 = RSL_Pairing::plan($nights[1]->id);
$uout = isset($plan2['divisions']['unranked']) ? $plan2['divisions']['unranked']['out'] : array();
$ugames = isset($plan2['divisions']['unranked']) ? $plan2['divisions']['unranked']['games'] : array();
$courts_out = array_filter($uout, function ($why) { return strpos($why, 'Not enough courts') === 0; });
$u_week1 = array_map(function ($t) { return intval($t->id); }, $u);
$check('plan(): eight or more Unranked teams on three slots: three games; the court sit-outs are teams that played week one, never one that sat it out, with both counts in the reason',
    count($ugames) === 3 && $courts_out && !array_diff(array_keys($courts_out), $u_week1)
    && array_values(array_unique($courts_out)) === array('Not enough courts (nights sat out before: this team 0, the teams playing 0 or more)'), wp_json_encode($uout));
foreach ($ux as $tid) {
    $wpdb->delete(RSL_Database::table('teams'), array('id' => $tid));
}

RSL_Ratings::flush();
RSL_Pairing::generate($nights[1]->id);
$week1 = array_map(function ($g) { return RSL_Pairing::meeting_key(intval($g->home_team_id), intval($g->away_team_id)); }, RSL_Fixtures::games_for_night($nights[0]->id));
$week2 = array_map(function ($g) { return RSL_Pairing::meeting_key(intval($g->home_team_id), intval($g->away_team_id)); }, RSL_Fixtures::games_for_night($nights[1]->id));
$check('week two repeats no week-one game', !array_intersect($week1, $week2) && count($week2) >= 3);
$check('...and while week two is drafted, not played, round 3 still has no link term', RSL_Pairing::context($nights[2])['link'] === $no_link);

// Week two by hand, so the results stay split: week one's pairs (a b), (c d), (e f); week two a-c, b-d
// and e-f again. Published and played, that's {a b c d} and {e f}: round 3 (the division's third
// night) links them, 2.25 for one night split. (e and f met last week, so the round-3 draw below pairs
// each with the other group whatever the results, which keeps its checks the same every run.) Every
// team gets $150 credit, so two published nights' fees don't keep anyone out of the later draws.
$week1_ranked = array_values(array_filter(RSL_Fixtures::games_for_night($nights[0]->id), function ($g) { return $g->division === 'ranked'; }));
foreach (RSL_Fixtures::games_for_night($nights[1]->id) as $g) {
    if ($g->division === 'ranked') {
        RSL_Fixtures::delete_game($g->id);
    }
}
$pa = array(intval($week1_ranked[0]->home_team_id), intval($week1_ranked[0]->away_team_id));
$pc = array(intval($week1_ranked[1]->home_team_id), intval($week1_ranked[1]->away_team_id));
$pe = array(intval($week1_ranked[2]->home_team_id), intval($week1_ranked[2]->away_team_id));
$slots2 = array_values(array_filter(RSL_Courts::slots_for_night($nights[1]->id), function ($s) { return $s['division'] === 'ranked'; }));
$w2 = array();
foreach (array(array($pa[0], $pc[0]), array($pa[1], $pc[1]), $pe) as $i => $pair) {
    $w2[] = RSL_Fixtures::add_game($nights[1]->id, array('home' => $pair[0], 'away' => $pair[1], 'court_id' => $slots2[$i]['court_id'], 'start' => $slots2[$i]['start']));
}
RSL_Fixtures::publish_night($nights[1]->id);
foreach ($w2 as $g) {
    if (!is_wp_error($g)) {
        RSL_Fixtures::record_result($g->id, '25-20, 25-20, 20-25');
    }
}
foreach (array_merge($teams, $u) as $t) {
    RSL_Payments::adjust($t->id, 150, 'Test credit', 0);
}
RSL_Ratings::flush();
$ctx3 = RSL_Pairing::context($nights[2]);
$g3 = $ctx3['link']['groups'];
$u_ids = array_map(function ($t) { return intval($t->id); }, $u);
$check('context(): week two played and still split, round 3 links {a b c d} and {e f} (two groups, bonus 2.25, one night); no Unranked team is in a group',
    count($w2) === 3 && !is_wp_error($w2[0]) && !is_wp_error($w2[1]) && !is_wp_error($w2[2]) && count($g3) === 6 && count(array_unique($g3)) === 2
    && $g3[$pa[0]] === $g3[$pc[1]] && $g3[$pe[0]] === $g3[$pe[1]] && $g3[$pa[0]] !== $g3[$pe[0]]
    && $ctx3['link']['bonus'] === 2.25 && $ctx3['link']['nights'] === 1 && !array_intersect($u_ids, array_keys($g3)),
    wp_json_encode($ctx3['link']));
// Week two's automatic draw left the Unranked double-header team out for owing (its two week-one games);
// that is recorded like a week off, so it isn't a night sat out.
$check('context(): a night the automatic draw left a team out for owing money is not a night sat out (recorded like a week off)',
    isset(RSL_Requests::for_night($nights[1]->id, 'unpaid', array('handled'))[$u_ids[0]]) && !isset($ctx3['byes'][$u_ids[0]]), wp_json_encode($ctx3['byes']));
$ef_games = array($week1_ranked[2], $w2[2]);
foreach ($ef_games as $ef) {
    RSL_Fixtures::record_forfeit($ef->id, $ef->home_team_id);
}
$ctx3f = RSL_Pairing::context($nights[2]);
$check('...a forfeit doesn\'t link: with both e-f games forfeited, e and f (no other game) are left out and the rest are one group, so no link term',
    $ctx3f['link'] === $no_link, wp_json_encode($ctx3f['link']));
foreach ($ef_games as $ef) {
    RSL_Fixtures::record_result($ef->id, '25-20, 25-20, 20-25');
}

// Court supervisors: a remake keeps a venue's supervisor when the venue still has games;
// removing a venue's last game takes its supervisor off (one of two games doesn't).
$n5 = $nights[4];
$jobs_t = RSL_Database::table('jobs');
RSL_Pairing::generate($n5->id);
foreach (array($hall, $uni) as $v) {
    $wpdb->insert($jobs_t, array('night_id' => $n5->id, 'type' => 'supervisor', 'venue_id' => $v->id, 'user_id' => 1, 'rate' => 30));
}
$sup_jobs = function ($venue_id = 0) use ($wpdb, $jobs_t, $n5) {
    return array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT id FROM $jobs_t WHERE night_id = %d AND type = 'supervisor'" . ($venue_id ? ' AND venue_id = ' . intval($venue_id) : '') . " ORDER BY id", $n5->id)));
};
$before = $sup_jobs();
$re = RSL_Pairing::generate($n5->id);
$check('a remake keeps the supervisors of venues that still have games (the same jobs)', !is_wp_error($re) && count($before) === 2 && $sup_jobs() === $before, implode(',', $sup_jobs()) . ' vs ' . implode(',', $before));
$at_venue = function ($venue) use ($n5) {
    return array_values(array_filter(RSL_Fixtures::games_for_night($n5->id), function ($g) use ($venue) { return intval($g->venue_id) === intval($venue->id); }));
};
$hall_games = $at_venue($hall);
RSL_Fixtures::delete_game($hall_games[0]->id);
$check('removing one of a venue\'s games keeps its supervisor', count($hall_games) === 3 && count($sup_jobs($hall->id)) === 1, count($hall_games) . ' hall games');
$uni_games = $at_venue($uni);
foreach ($uni_games as $g) { RSL_Fixtures::delete_game($g->id); }
$check('removing a venue\'s last game takes its supervisor off (the other venue\'s stays)', count($uni_games) >= 1 && count($sup_jobs($uni->id)) === 0 && count($sup_jobs($hall->id)) === 1);
foreach (RSL_Fixtures::games_for_night($n5->id) as $g) { RSL_Fixtures::delete_game($g->id); }
$check('clearing the night leaves no supervisor', $sup_jobs() === array());

// --- Admin page ----------------------------------------------------------------------------------
$admin = wp_insert_user(array('user_login' => "rslpa-$suffix", 'user_email' => "rslpa-$suffix@example.test", 'user_pass' => wp_generate_password(), 'role' => 'administrator'));
wp_set_current_user($admin);
$post = function ($do, $night, $extra = array()) use ($season) {
    return RSL_Admin_Fixtures::process(array_merge(array('rsl_nonce' => wp_create_nonce('rsl_fixtures_action'), 'season' => $season, 'night_id' => $night->id, 'do' => $do), $extra));
};
$n3 = $nights[2];
$post('generate', $n3);
$check('admin: make fixtures automatically', count(RSL_Fixtures::games_for_night($n3->id)) >= 3);
$why3 = RSL_Pairing::explain_night($n3->id);
$ranked_links = array();
$unranked_links = array();
foreach (RSL_Fixtures::games_for_night($n3->id) as $x) {
    if ($x->division === 'ranked') {
        $ranked_links[] = $why3[intval($x->id)]['link'];
    } else {
        $unranked_links[] = $why3[intval($x->id)]['link'];
    }
}
$check('round 3: games between the groups weeks one and two left apart carry the link bonus (-2.25); Unranked games never do',
    in_array(-2.25, $ranked_links, true) && $unranked_links && max(array_map('abs', $unranked_links)) === 0.0,
    wp_json_encode(array($ranked_links, $unranked_links)));
$g = RSL_Fixtures::games_for_night($n3->id)[0];
$post('delete_game', $n3, array('game_id' => $g->id));
$check('admin: remove a game', RSL_Fixtures::get($g->id) === null);
$post('add_game', $n3, array('slot' => $g->court_id . '|' . RSL_Courts::hm($g->start_time), 'home' => $g->home_team_id, 'away' => $g->away_team_id));
$g2 = null;
foreach (RSL_Fixtures::games_for_night($n3->id) as $x) { if (intval($x->court_id) === intval($g->court_id) && RSL_Courts::hm($x->start_time) === RSL_Courts::hm($g->start_time)) { $g2 = $x; } }
$check('admin: add a game by hand', $g2 && intval($g2->home_team_id) === intval($g->home_team_id));
$post('publish', $n3);
$check('admin: publish', RSL_Fixtures::is_published(RSL_Courts::get_night($n3->id)));
$post('delete_game', $n3, array('game_id' => $g2->id));
$check('admin: games are locked once published', RSL_Fixtures::get($g2->id) !== null);
$post('save_result', $n3, array('game_id' => $g2->id, 'sets' => '25-18, 22-25, 15-10'));
$check('admin: enter a result', RSL_Fixtures::get($g2->id)->status === 'played' && intval(RSL_Fixtures::get($g2->id)->home_sets) === 2);
$post('save_result', $n3, array('game_id' => $g2->id, 'sets' => '', 'forfeit' => $g2->away_team_id));
$check('admin: record a forfeit', RSL_Fixtures::get($g2->id)->status === 'forfeit' && intval(RSL_Fixtures::get($g2->id)->forfeit_team_id) === intval($g2->away_team_id));
$post('clear_result', $n3, array('game_id' => $g2->id));
$check('admin: clear a result', RSL_Fixtures::get($g2->id)->status === 'scheduled');
$bad = RSL_Admin_Fixtures::process(array('rsl_nonce' => 'nope', 'season' => $season, 'night_id' => $n3->id, 'do' => 'unpublish'));
$check('admin: bad nonce does nothing', RSL_Fixtures::is_published(RSL_Courts::get_night($n3->id)));
$_GET = array('page' => 'rsl-fixtures', 'season' => $season, 'night' => $nights[3]->id);
ob_start(); (new RSL_Admin_Fixtures())->render(); $html = ob_get_clean();
$check('admin page offers automatic fixtures on an empty night', strpos($html, 'Make fixtures automatically') !== false && strpos($html, 'How automatic fixtures are made') !== false);
$_GET = array('page' => 'rsl-fixtures', 'season' => $season, 'night' => $nights[1]->id);
// A team with no game that night (in the league for week one too, with no game then either).
$rest = RSL_Teams::create($season, array('name' => "Pair Rest $suffix", 'division' => 'unranked', 'level' => 'new'));
$wpdb->update(RSL_Database::table('teams'), array('created_at' => date('Y-m-d H:i:s', strtotime($today . ' -3 days'))), array('id' => $rest->id)); // in the league before week one was drawn
// And one that said it can't make that night: its own choice, so no count.
$cant_team = RSL_Teams::create($season, array('name' => "Pair Cant2 $suffix", 'division' => 'unranked', 'level' => 'new'));
$wpdb->insert(RSL_Database::table('requests'), array('team_id' => $cant_team->id, 'night_id' => $nights[1]->id, 'type' => 'cant_make', 'status' => 'handled',
    'requested_by' => 0, 'requested_at' => current_time('mysql')));
ob_start(); (new RSL_Admin_Fixtures())->render(); $html = ob_get_clean();
$wpdb->delete(RSL_Database::table('teams'), array('id' => $rest->id));
$wpdb->delete(RSL_Database::table('requests'), array('team_id' => $cant_team->id));
$wpdb->delete(RSL_Database::table('teams'), array('id' => $cant_team->id));
$check('admin page: Not playing this night calls a team that can\'t make it just that, with no count',
    strpos($html, esc_html("Pair Cant2 $suffix (can't make it)")) !== false && strpos($html, esc_html("Pair Cant2 $suffix (bye")) === false);
$check('admin page shows set chances (not expected scores) and meetings', strpos($html, 'meeting') !== false && preg_match('/\d+% · \d+%/u', $html) === 1
    && strpos($html, 'Set chance') !== false && strpos($html, 'expected one-sided') === false);
$check('admin page: Not playing this night gives a resting team\'s nights sat out before, against the teams playing in its division',
    strpos($html, esc_html("Pair Rest $suffix (bye — odd numbers or not enough courts; nights sat out before: 1, the teams playing 0 or more)")) !== false
    && strpos($html, 'Sitting out:') !== false);
$_GET = array('page' => 'rsl-fixtures', 'season' => $season, 'night' => $n3->id);
ob_start(); (new RSL_Admin_Fixtures())->render(); $html = ob_get_clean();
$check('admin page shows the link term on a game it brought about, and explains it', strpos($html, 'Links two groups that haven') !== false
    && strpos($html, ': -2.25</span>') !== false && strpos($html, 'Linking groups') !== false);
// The Make fixtures automatically notice names who isn't playing and why: five more Unranked teams make
// seven or more for the night's three Unranked slots, so at least one sits out for courts.
foreach (array(1, 2, 3, 4, 5) as $i) {
    RSL_Teams::create($season, array('name' => "Pair UN$i $suffix", 'division' => 'unranked', 'level' => 'new'));
}
delete_transient(RSL_Admin::NOTICE_PREFIX . $admin);
$post('generate', $nights[3]);
$note = get_transient(RSL_Admin::NOTICE_PREFIX . $admin);
$msg = is_array($note) && isset($note['message']) ? $note['message'] : '';
delete_transient(RSL_Admin::NOTICE_PREFIX . $admin);
$check('admin: the Make fixtures automatically notice lists who isn\'t playing, with a court sit-out\'s counts',
    strpos($msg, 'Not playing: ') !== false && preg_match('/Pair U[^:;]* ' . $suffix . ': Not enough courts \(nights sat out before: this team \d+(\.\d)?, the teams playing \d+(\.\d)? or more\)/', $msg) === 1, $msg);

} catch (Throwable $thrown) {
    $fails++;
    echo 'FAIL: threw ' . get_class($thrown) . ': ' . $thrown->getMessage() . ' (line ' . $thrown->getLine() . ")\n";
} finally {
// --- Cleanup (runs after a failure too) -------------------------------------------------------------
$_GET = array();
wp_set_current_user(0);
foreach (array($season, $season_past) as $sn) {
    $wpdb->delete(RSL_Database::table('games'), array('season' => $sn));
    $wpdb->delete(RSL_Database::table('ledger'), array('season' => $sn));
    foreach (RSL_Courts::nights($sn) as $n) {
        $wpdb->delete(RSL_Database::table('jobs'), array('night_id' => $n->id));
        $wpdb->delete(RSL_Database::table('night_courts'), array('night_id' => $n->id));
        $wpdb->delete(RSL_Database::table('night_venues'), array('night_id' => $n->id));
    }
    $wpdb->delete(RSL_Database::table('nights'), array('season' => $sn));
    $settings = get_option(RSL_Courts::SETTINGS_OPTION, array());
    if (is_array($settings) && isset($settings[$sn])) {
        unset($settings[$sn]);
        update_option(RSL_Courts::SETTINGS_OPTION, $settings, false);
    }
}
// Every venue the test made (each name ends with the suffix), with its courts; then the real ones back.
foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM " . RSL_Database::table('venues') . " WHERE name LIKE %s", '% ' . $wpdb->esc_like((string) $suffix))) as $vid) {
    $wpdb->delete(RSL_Database::table('courts'), array('venue_id' => $vid));
    $wpdb->delete(RSL_Database::table('venues'), array('id' => $vid));
}
if ($parked) { $wpdb->query("UPDATE " . RSL_Database::table('venues') . " SET active = 1 WHERE id IN (" . implode(',', array_map('intval', $parked)) . ")"); }
// Every team in the test's seasons (however far the test got).
foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM " . RSL_Database::table('teams') . " WHERE season IN (%s, %s)", $season, $season_past)) as $tid) {
    $wpdb->delete(RSL_Database::table('requests'), array('team_id' => $tid));
    $wpdb->delete(RSL_Database::table('team_members'), array('team_id' => $tid));
    $wpdb->delete(RSL_Database::table('teams'), array('id' => $tid));
}
RSL_Ratings::forget_start($season);
RSL_Ratings::forget_start($season_past);
rsl_dev_delete_users(array($admin)); // the admin notice too
if ($saved_current === '__unset__') { delete_option(RSL_Seasons::CURRENT_OPTION); } else { update_option(RSL_Seasons::CURRENT_OPTION, $saved_current); }
rsl_dev_release_mail($hold);
}
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
