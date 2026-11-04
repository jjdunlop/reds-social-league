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
$check('odd numbers: the team that hasn\'t sat out sits out', $p['bye'] === 5);
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
// chain of games connects. Round 3 links them (2.25); round 2 doesn't yet; unpublished results, forfeits
// and Unranked games never count.
$ctx3 = RSL_Pairing::context($nights[2]);
$g3 = $ctx3['link']['groups'];
$u_ids = array_map(function ($t) { return intval($t->id); }, $u);
$check('context(): round 3 links week one\'s three Ranked pairs (three groups, bonus 2.25); no Unranked team is in a group',
    count($g3) === 6 && count(array_unique($g3)) === 3 && $ctx3['link']['bonus'] === 2.25 && $ctx3['link']['nights'] === 1 && !array_intersect($u_ids, array_keys($g3)),
    wp_json_encode($ctx3['link']));
$check('...round 2 has no link term, and nor does round 3 while week one is unpublished', RSL_Pairing::context($nights[1])['link']['bonus'] === 0.0
    && $ctx_unpub['link']['bonus'] === 0.0 && $ctx_unpub['link']['groups'] === array());
$week1_ranked = array_values(array_filter(RSL_Fixtures::games_for_night($nights[0]->id), function ($g) { return $g->division === 'ranked'; }));
$gave_away = $week1_ranked[0];
RSL_Fixtures::record_forfeit($gave_away->id, $gave_away->home_team_id);
$ctx3f = RSL_Pairing::context($nights[2]);
$check('...a forfeit doesn\'t link: its two teams (no other game) are left out', count($ctx3f['link']['groups']) === 4
    && !isset($ctx3f['link']['groups'][intval($gave_away->home_team_id)]) && !isset($ctx3f['link']['groups'][intval($gave_away->away_team_id)]));
RSL_Fixtures::record_result($gave_away->id, '25-20, 25-20, 20-25');

// Byes: a team only sat out for the fixtures' sake if it was in the league by then (approved,
// not just applied) and hadn't said it couldn't make that night. Three teams that weren't in week one.
$extra = array();
foreach (array('Bye', 'Late', 'Cant') as $tag) {
    $extra[$tag] = RSL_Teams::create($season, array('name' => "Pair $tag $suffix", 'division' => 'ranked', 'level' => 'solid'));
    $wpdb->update(RSL_Database::table('teams'), array('created_at' => date('Y-m-d H:i:s', strtotime($nights[0]->night_date . ' -3 days'))), array('id' => $extra[$tag]->id));
}
$wpdb->update(RSL_Database::table('teams'), array('decided_at' => date('Y-m-d 10:00:00', strtotime($nights[0]->night_date . ' +1 day'))), array('id' => $extra['Late']->id));
$wpdb->insert(RSL_Database::table('requests'), array('team_id' => $extra['Cant']->id, 'night_id' => $nights[0]->id, 'type' => 'cant_make', 'status' => 'handled',
    'requested_by' => 0, 'requested_at' => current_time('mysql')));
$ctx = RSL_Pairing::context($nights[1]);
$check('a team in the league but left out of a night has sat out once', isset($ctx['byes'][intval($extra['Bye']->id)]) && $ctx['byes'][intval($extra['Bye']->id)] === 1);
$check('...but not one approved after that night, or one that said it couldn\'t make it', !isset($ctx['byes'][intval($extra['Late']->id)]) && !isset($ctx['byes'][intval($extra['Cant']->id)]));
foreach ($extra as $t) {
    $wpdb->delete(RSL_Database::table('requests'), array('team_id' => $t->id));
    $wpdb->delete(RSL_Database::table('teams'), array('id' => $t->id));
}

RSL_Ratings::flush();
RSL_Pairing::generate($nights[1]->id);
$week1 = array_map(function ($g) { return RSL_Pairing::meeting_key(intval($g->home_team_id), intval($g->away_team_id)); }, RSL_Fixtures::games_for_night($nights[0]->id));
$week2 = array_map(function ($g) { return RSL_Pairing::meeting_key(intval($g->home_team_id), intval($g->away_team_id)); }, RSL_Fixtures::games_for_night($nights[1]->id));
$check('week two repeats no week-one game', !array_intersect($week1, $week2) && count($week2) >= 3);

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
$check('round 3: games between the groups week one left apart carry the link bonus (-2.25); Unranked games never do',
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
ob_start(); (new RSL_Admin_Fixtures())->render(); $html = ob_get_clean();
$check('admin page shows set chances (not expected scores) and meetings', strpos($html, 'meeting') !== false && preg_match('/\d+% · \d+%/u', $html) === 1
    && strpos($html, 'Set chance') !== false && strpos($html, 'expected one-sided') === false);
$_GET = array('page' => 'rsl-fixtures', 'season' => $season, 'night' => $n3->id);
ob_start(); (new RSL_Admin_Fixtures())->render(); $html = ob_get_clean();
$check('admin page shows the link term on a game it brought about, and explains it', strpos($html, 'Links two groups that haven') !== false
    && strpos($html, ': -2.25</span>') !== false && strpos($html, 'Linking groups') !== false);

} catch (Throwable $thrown) {
    $fails++;
    echo 'FAIL: threw ' . get_class($thrown) . ': ' . $thrown->getMessage() . ' (line ' . $thrown->getLine() . ")\n";
} finally {
// --- Cleanup (runs after a failure too) -------------------------------------------------------------
$_GET = array();
wp_set_current_user(0);
$wpdb->delete(RSL_Database::table('games'), array('season' => $season));
$wpdb->delete(RSL_Database::table('ledger'), array('season' => $season));
foreach (RSL_Courts::nights($season) as $n) {
    $wpdb->delete(RSL_Database::table('jobs'), array('night_id' => $n->id));
    $wpdb->delete(RSL_Database::table('night_courts'), array('night_id' => $n->id));
    $wpdb->delete(RSL_Database::table('night_venues'), array('night_id' => $n->id));
}
$wpdb->delete(RSL_Database::table('nights'), array('season' => $season));
$settings = get_option(RSL_Courts::SETTINGS_OPTION, array());
if (is_array($settings) && isset($settings[$season])) {
    unset($settings[$season]);
    update_option(RSL_Courts::SETTINGS_OPTION, $settings, false);
}
// Every venue the test made (each name ends with the suffix), with its courts; then the real ones back.
foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM " . RSL_Database::table('venues') . " WHERE name LIKE %s", '% ' . $wpdb->esc_like((string) $suffix))) as $vid) {
    $wpdb->delete(RSL_Database::table('courts'), array('venue_id' => $vid));
    $wpdb->delete(RSL_Database::table('venues'), array('id' => $vid));
}
if ($parked) { $wpdb->query("UPDATE " . RSL_Database::table('venues') . " SET active = 1 WHERE id IN (" . implode(',', array_map('intval', $parked)) . ")"); }
// Every team in the test's season (however far the test got).
foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM " . RSL_Database::table('teams') . " WHERE season = %s", $season)) as $tid) {
    $wpdb->delete(RSL_Database::table('requests'), array('team_id' => $tid));
    $wpdb->delete(RSL_Database::table('team_members'), array('team_id' => $tid));
    $wpdb->delete(RSL_Database::table('teams'), array('id' => $tid));
}
RSL_Ratings::forget_start($season);
rsl_dev_delete_users(array($admin)); // the admin notice too
if ($saved_current === '__unset__') { delete_option(RSL_Seasons::CURRENT_OPTION); } else { update_option(RSL_Seasons::CURRENT_OPTION, $saved_current); }
rsl_dev_release_mail($hold);
}
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
