<?php
/**
 * Placement engine (pure): RSL_Placement::propose() over hand-made pools.
 * The rules (groups never split, Unranked-only never in Ranked, never over
 * the maximum, new teams within min–max with a volunteer captain when there
 * is one, a team that said no is never used), short teams filled first, an
 * asked-for team preferred, the captain swap, leftover reasons in plain
 * words, the same answer every time (and in any input order), the places,
 * the places held for applications waiting for approval (0.19.0: new teams
 * leave them free, Either players cross when they're all held, an admin's
 * new team past them is kept), locked units.
 *
 *   wp eval-file dev/tests/test-placement.php
 *   php dev/tests/test-placement.php        (standalone — no WordPress needed)
 *
 * No database, no users: every pool is made up here.
 */

if (!defined('ABSPATH')) {
    // Standalone: plain PHP from the command line, no WordPress.
    if (PHP_SAPI !== 'cli') {
        exit;
    }
    define('ABSPATH', __DIR__ . '/');
} elseif (!defined('WP_CLI') || !WP_CLI || (wp_get_environment_type() === 'production' && !getenv('RSL_DEV'))) {
    exit("Local copy only: run with wp eval-file, with RSL_DEV=1 set (docker exec -e RSL_DEV=1 ...), or standalone with php.\n");
}
if (!class_exists('RSL_Placement')) {
    require_once __DIR__ . '/../../includes/class-rsl-placement.php';
}
if (!class_exists('RSL_Capacity')) {
    // The unplaced reason names the allowance through RSL_Capacity::limit_words() (pure).
    require_once __DIR__ . '/../../includes/class-rsl-capacity.php';
}

$fails = 0;
$check = function ($label, $ok, $detail = '') use (&$fails) {
    if (!$ok) { $fails++; }
    echo ($ok ? "OK: $label\n" : "FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n");
};
$S = array('min_roster' => 7, 'target_roster' => 8, 'new_team_target' => 8, 'max_roster' => 10, 'gap_fill' => 1.5, 'gap_short' => 2.0, 'capacity' => array('unranked' => null, 'ranked' => null));
$seq = 0;
/** A unit: $o overrides any field; 'size', 'captain' (the first member volunteers), 'veteran', 'bands' (per member) shape the members. */
$unit = function ($band, $o = array()) use (&$seq) {
    $seq++;
    $size = isset($o['size']) ? $o['size'] : 1;
    $ids = array();
    $members = array();
    for ($i = 0; $i < $size; $i++) {
        $ids[] = $seq * 10 + $i;
        $members[] = array('user_id' => $seq * 10 + $i, 'band' => isset($o['bands'][$i]) ? $o['bands'][$i] : $band, 'captain_ok' => $i === 0 && !empty($o['captain']), 'veteran' => $i === 0 && !empty($o['veteran']));
    }
    $base = array(
        'ref' => 'u' . $seq, 'user_ids' => $ids, 'lead_user_id' => $ids[0], 'size' => $size, 'band' => $band, 'members' => $members,
        'divisions' => array('unranked'), 'time' => 'any', 'positions' => array(), 'away_early' => 0,
        'captain_ok' => !empty($o['captain']), 'captain_user_id' => !empty($o['captain']) ? $ids[0] : 0,
        'requested' => array(), 'declined' => array(), 'created_at' => gmdate('Y-m-d H:i:s', 3660000000 + $seq * 60), 'locked' => '',
    );
    return array_merge($base, array_diff_key($o, array('size' => 1, 'captain' => 1, 'veteran' => 1, 'bands' => 1)));
};
$team = function ($id, $division, $n, $band, $o = array()) {
    return array_merge(array('id' => $id, 'name' => "Team $id", 'division' => $division, 'n' => $n, 'band' => $band, 'pref_time' => 'any', 'lacks' => array(), 'spots_open' => 0), $o);
};
$singles = function ($count, $band, $o = array()) use ($unit) {
    $out = array();
    for ($i = 0; $i < $count; $i++) {
        $out[] = $unit($band, $o);
    }
    return $out;
};
$dest = function ($res, $u) {
    return $res['units'][$u['ref']]['dest'];
};
$why = function ($res, $u) {
    return $res['units'][$u['ref']]['reason'];
};
/** Propose, and also check the rules hold for this result (one FAIL line per broken rule). */
$run = function ($units, $teams, $s = null) use ($S, $check) {
    $s = $s === null ? $S : array_merge($S, $s);
    $res = RSL_Placement::propose($units, $teams, $s);
    $bad = RSL_Placement::violations($units, $teams, $s, $res);
    if ($bad) {
        $check('rules hold (violations())', false, implode('; ', $bad));
    }
    return $res;
};
$new_sizes = function ($res) {
    $out = array();
    foreach ($res['new_teams'] as $d) {
        $out[] = $d['size'];
    }
    sort($out);
    return $out;
};

// --- Short teams are filled first ------------------------------------------------------------
$a = $unit(2.5); $b = $unit(2.5);
$res = $run(array($a, $b), array($team(1, 'unranked', 7, 2.5, array('spots_open' => 1)), $team(2, 'unranked', 5, 2.5)));
$check('short teams are filled first (before teams with open spots, even older ones)', $dest($res, $a) === 't:2' && $dest($res, $b) === 't:2' && $res['teams'][2]['after'] === 7 && $res['teams'][1]['after'] === 7);
$check('the reason says so', strpos($why($res, $a), 'Team was short') === 0, $why($res, $a));
$a = $unit(2.5); $b = $unit(2.5);
$res = $run(array($a, $b), array($team(1, 'unranked', 7, 2.5, array('spots_open' => 1)), $team(2, 'unranked', 5, 3.5)));
$check('a short team takes players within 2 bands ahead of a better fit with open spots', $dest($res, $a) === 't:2' && $dest($res, $b) === 't:2');
$a = $unit(2.5); $b = $unit(2.5); $c = $unit(2.5);
$res = $run(array($a, $b, $c), array($team(1, 'unranked', 5, 2.5, array('spots_open' => 3)), $team(2, 'unranked', 6, 2.5)));
$check('the team needing the most goes first', $res['teams'][1]['after'] === 7 && $res['teams'][2]['after'] === 7);
$res = $run($singles(3, 2.5), array($team(1, 'unranked', 5, 2.5, array('spots_open' => 3))));
$check('then open spots fill to the target', $res['teams'][1]['after'] === 8);
$res = $run($singles(2, 2.5), array($team(1, 'unranked', 8, 2.5), $team(2, 'unranked', 8, 2.5)));
$check('leftovers are spread over teams with room, not piled on one', $res['teams'][1]['after'] === 9 && $res['teams'][2]['after'] === 9);

// --- An asked-for team is preferred ------------------------------------------------------------
$x = $unit(2.5, array('requested' => array(2)));
$res = $run(array($x), array($team(1, 'unranked', 7, 2.5, array('spots_open' => 1)), $team(2, 'unranked', 7, 2.5, array('spots_open' => 1))));
$check('someone who asked a team goes there, not to an older team with the same room', $dest($res, $x) === 't:2', $dest($res, $x));
$check('the reason and flags say they asked', strpos($why($res, $x), 'asked for this team') !== false && in_array('asked', $res['units'][$x['ref']]['flags'], true), $why($res, $x));
$y = $unit(2.5); $x = $unit(2.5, array('requested' => array(2)));
$res = $run(array($y, $x), array($team(1, 'unranked', 7, 2.5, array('spots_open' => 1)), $team(2, 'unranked', 7, 2.5, array('spots_open' => 1))));
$check('the asker gets their team and the other player the other team', $dest($res, $x) === 't:2' && $dest($res, $y) === 't:1');
$x = $unit(2.5, array('requested' => array(2)));
$res = $run(array($x), array($team(1, 'unranked', 7, 2.5, array('spots_open' => 2)), $team(2, 'unranked', 7, 2.5, array('spots_open' => 1))), array('target_roster' => 9));
$check('even when another team has more places left', $dest($res, $x) === 't:2', $dest($res, $x));
$x = $unit(2.5, array('requested' => array(2))); $y = $unit(2.5);
$res = $run(array($x, $y), array($team(1, 'unranked', 6, 2.5), $team(2, 'unranked', 7, 2.5, array('spots_open' => 1))));
$check('a short team takes someone else before a player who asked a team with a spot', $dest($res, $x) === 't:2' && $dest($res, $y) === 't:1', $dest($res, $x) . ' / ' . $dest($res, $y));
$x = $unit(2.5, array('requested' => array(2)));
$res = $run(array($x), array($team(1, 'unranked', 7, 2.5, array('spots_open' => 1)), $team(2, 'unranked', 7, 4.5, array('spots_open' => 1))));
$check('asking doesn\'t stretch the level range', $dest($res, $x) === 't:1');
$x = $unit(3.0, array('requested' => array(2))); $y = $unit(2.5, array('requested' => array(2)));
$res = $run(array($x, $y), array($team(2, 'unranked', 7, 2.5, array('spots_open' => 1))));
$check('two asking for one open spot: the closer level gets it (the other is only topped up later)', $res['units'][$y['ref']]['how'] === 'spots' && $res['units'][$x['ref']]['how'] === 'topup');

// --- Groups are never split ----------------------------------------------------------------------
$g = $unit(2.5, array('size' => 3));
$res = $run(array($g), array($team(1, 'unranked', 8, 2.5, array('spots_open' => 2))));
$check('a group of 3 never goes where only 2 fit', $dest($res, $g) === 'none' && $res['teams'][1]['after'] === 8);
$check('and is told why', $why($res, $g) === 'Group of 3 doesn\'t fit together anywhere' && in_array('group', $res['units'][$g['ref']]['flags'], true), $why($res, $g));
$g = $unit(2.5, array('size' => 2));
$res = $run(array($g), array($team(1, 'unranked', 7, 2.5, array('spots_open' => 1))));
$check('a group may go one over the open spots (never over the maximum)', $dest($res, $g) === 't:1' && $res['teams'][1]['after'] === 9);
$g = $unit(2.5, array('size' => 3)); $rest = $singles(6, 2.5);
$res = $run(array_merge(array($g), $rest), array());
$d = isset($res['new_teams']['U1']) ? $res['new_teams']['U1'] : null;
$check('a group goes into a new team whole', $d && $dest($res, $g) === 'n:U1' && count(array_intersect($g['user_ids'], $d['user_ids'])) === 3 && $d['size'] === 9);

// --- Unranked-only players never go to Ranked ------------------------------------------------------
$b1 = $unit(1.0); $b2 = $unit(1.0); $e = $unit(1.5, array('divisions' => array('unranked', 'ranked')));
$res = $run(array($b1, $b2, $e), array($team(1, 'ranked', 5, 1.5)));
$check('a short Ranked team takes the Either player, never the Unranked-only beginners', $dest($res, $e) === 't:1' && $dest($res, $b1) === 'none' && $dest($res, $b2) === 'none');
$check('the beginners are told why in plain words', $why($res, $b1) === '2 Unranked players left — 5 short of a new team; Unranked teams full', $why($res, $b1));
$res = $run($singles(16, 2.0), array($team(1, 'ranked', 4, 2.0, array('spots_open' => 4))));
$check('a pool of Unranked-only players makes Unranked teams, however short a Ranked team is', $res['teams'][1]['after'] === 4 && count($res['new_teams']) === 2 && $res['new_teams']['U1']['division'] === 'unranked');
$r = $singles(8, 4.0, array('divisions' => array('ranked')));
$res = $run($r, array());
$check('Ranked-only players make a Ranked team', isset($res['new_teams']['R1']) && $res['new_teams']['R1']['size'] === 8 && $dest($res, $r[0]) === 'n:R1');

// --- Never over the maximum ---------------------------------------------------------------------------
$a = $unit(2.5); $b = $unit(2.5);
$res = $run(array($a, $b), array($team(1, 'unranked', 9, 2.5)));
$check('leftovers top a team up to the maximum and no further', $res['teams'][1]['after'] === 10 && ($dest($res, $a) === 't:1') !== ($dest($res, $b) === 't:1'));
$g = $unit(2.5, array('size' => 2, 'requested' => array(1)));
$res = $run(array($g), array($team(1, 'unranked', 9, 2.5, array('spots_open' => 1))));
$check('a pair who asked a team with one place left isn\'t squeezed in', $dest($res, $g) === 'none' && $res['teams'][1]['after'] === 9);
$res = $run($singles(5, 2.5), array($team(1, 'unranked', 10, 2.5, array('spots_open' => 2))));
$check('a full team takes nobody, whatever its open spots say', $res['teams'][1]['after'] === 10);

// --- New teams have min–max players --------------------------------------------------------------------
$six = $singles(6, 2.0);
$res = $run($six, array());
$check('6 players don\'t make a team', !$res['new_teams'] && $why($res, $six[0]) === '6 Unranked players left — 1 short of a new team; Unranked teams full', $why($res, $six[0]));
$res = $run($singles(7, 2.0), array());
$check('7 players make a team of 7', $new_sizes($res) === array(7));
$res = $run($singles(13, 2.0), array());
$check('13 players: one team of 10, three left over', $new_sizes($res) === array(10) && $res['summary']['unplaced'] === 3, json_encode($new_sizes($res)));
$res = $run($singles(25, 2.0), array());
$check('25 players: three teams of 8–9', $new_sizes($res) === array(8, 8, 9), json_encode($new_sizes($res)));
$mixed = array();
foreach (array(1.0, 1.5, 2.0, 2.5, 3.0, 3.5) as $i => $band) {
    $mixed = array_merge($mixed, $singles(4, $band), array($unit($band, array('size' => 2 + $i % 2))));
}
$res = $run($mixed, array());
$ok = (bool) $res['new_teams'];
foreach ($res['new_teams'] as $d) {
    $ok = $ok && $d['size'] >= 7 && $d['size'] <= 10;
}
$check('a mixed pool of singles and groups makes teams of 7–10', $ok, json_encode($new_sizes($res)));
$prev = null;
$ordered = true;
foreach ($res['new_teams'] as $d) {
    $ordered = $ordered && ($prev === null || $d['band'] <= $prev + 1e-9);
    $prev = $d['band'];
}
$check('new teams are cut by level (strongest first)', $ordered);

// --- Volunteer captains ---------------------------------------------------------------------------------
$v1 = $unit(3.5, array('captain' => true)); $v2 = $unit(3.5, array('captain' => true));
$res = $run(array_merge(array($v1, $v2), $singles(14, 2.0)), array());
$t1 = isset($res['new_teams']['U1']) ? $res['new_teams']['U1'] : null;
$t2 = isset($res['new_teams']['U2']) ? $res['new_teams']['U2'] : null;
$check('captain swap: two volunteers who\'d land on one team are split so each new team has one', $t1 && $t2 && $t1['volunteer'] && $t2['volunteer'] && $dest($res, $v1) !== $dest($res, $v2));
$check('each new team\'s captain is its volunteer', $t1 && $t2 && in_array($t1['captain_user_id'], array($v1['user_ids'][0], $v2['user_ids'][0]), true) && in_array($t2['captain_user_id'], array($v1['user_ids'][0], $v2['user_ids'][0]), true));
$v1 = $unit(4.0, array('captain' => true)); $v2 = $unit(4.0, array('captain' => true));
$groups = array($unit(1.0, array('size' => 3)), $unit(1.0, array('size' => 2)), $unit(1.0, array('size' => 2)));
$res = $run(array_merge(array($v1, $v2), $singles(5, 4.0), $groups), array());
$ok = count($res['new_teams']) === 2;
foreach ($res['new_teams'] as $d) {
    $ok = $ok && $d['volunteer'] && $d['size'] === 7;
}
$check('a new team of only friend groups still gets a volunteer (whole units traded, sizes kept)', $ok && $dest($res, $v1) !== $dest($res, $v2), json_encode($new_sizes($res)));
$lo = $unit(2.0, array('captain' => true)); $hi = $unit(3.0, array('captain' => true));
$res = $run(array_merge(array($lo, $hi), $singles(5, 2.5)), array());
$check('the volunteer with the highest level is captain', isset($res['new_teams']['U1']) && $res['new_teams']['U1']['captain_user_id'] === $hi['user_ids'][0]);
$vet = $unit(2.0, array('veteran' => true)); $strong = $unit(3.0);
$res = $run(array_merge($singles(6, 2.0), array($strong, $vet)), array());
$d = isset($res['new_teams']['U1']) ? $res['new_teams']['U1'] : null;
$check('no volunteer: flagged, and the most experienced player is suggested', $d && !$d['volunteer'] && in_array('needs_captain', $d['flags'], true) && $d['captain_user_id'] === $vet['user_ids'][0]);
$s1 = $unit(2.0, array('positions' => array('setter'))); $s2 = $unit(2.0, array('positions' => array('setter')));
$res = $run(array_merge(array($s1, $s2), $singles(14, 2.0, array('positions' => array('outside')))), array());
$sets = array();
foreach ($res['new_teams'] as $ref => $d) {
    $sets[$ref] = !in_array('no_setter', $d['flags'], true);
}
$check('setter swap: two setters end up on different new teams', count($sets) === 2 && !in_array(false, $sets, true) && $dest($res, $s1) !== $dest($res, $s2));
// Volunteers are kept for new teams when they're scarce — judged by where they'd form a team, not the team's division.
$v = $unit(2.5, array('captain' => true, 'divisions' => array('unranked', 'ranked'))); $e = $unit(2.5, array('divisions' => array('unranked', 'ranked')));
$res = $run(array_merge(array($v, $e), $singles(14, 2.5)), array($team(1, 'ranked', 7, 2.5, array('spots_open' => 1))));
$check('a scarce volunteer is kept for a new team; an Either player fills the Ranked spot instead', $dest($res, $e) === 't:1' && strpos($dest($res, $v), 'n:U') === 0, $dest($res, $e) . ' / ' . $dest($res, $v));
// violations() only calls a missing volunteer a broken rule when a trade could have fixed it (else the team is just flagged).
$hand = function ($teams_units) {
    $res = array('units' => array(), 'new_teams' => array());
    foreach ($teams_units as $ref => $list) {
        $size = 0;
        $refs = array();
        foreach ($list as $u) {
            $res['units'][$u['ref']] = array('dest' => 'n:' . $ref);
            $size += $u['size'];
            $refs[] = $u['ref'];
        }
        $res['new_teams'][$ref] = array('division' => 'unranked', 'size' => $size, 'unit_refs' => $refs, 'seeded' => false);
    }
    return $res;
};
$pairs = array($unit(2.0, array('size' => 2)), $unit(2.0, array('size' => 2)), $unit(2.0, array('size' => 2)), $unit(2.0, array('size' => 2)));
$va = $unit(2.0, array('captain' => true)); $vb = $unit(2.0, array('captain' => true));
$two = array($va, $vb, $unit(2.0, array('size' => 3)), $unit(2.0, array('size' => 2)));
$bad = RSL_Placement::violations(array_merge($pairs, $two), array(), $S, $hand(array('U1' => $pairs, 'U2' => $two)));
$check('violations(): an all-pairs team without a volunteer is no broken rule when no trade can give it one (the other team is at the minimum)', !$bad, implode('; ', $bad));
$extra = $unit(2.0);
$bad = RSL_Placement::violations(array_merge($pairs, $two, array($extra)), array(), $S, $hand(array('U1' => $pairs, 'U2' => array_merge($two, array($extra)))));
$check('but it is when the other team could spare a volunteer', (bool) preg_grep('/no volunteer captain/', $bad), implode('; ', $bad));

// --- A team that said no is never used ------------------------------------------------------------------
$a = $unit(2.5, array('declined' => array(1)));
$res = $run(array($a), array($team(1, 'unranked', 5, 2.5)));
$check('a team that said no isn\'t used, even when it\'s short', $dest($res, $a) === 'none' && $why($res, $a) === 'The teams with room have said no this season', $why($res, $a));
$a = $unit(2.5, array('declined' => array(1)));
$res = $run(array($a), array($team(1, 'unranked', 5, 2.5), $team(2, 'unranked', 7, 3.0, array('spots_open' => 1))));
$check('they go to another team instead', $dest($res, $a) === 't:2');
$g = $unit(2.5, array('size' => 2, 'declined' => array(1)));
$res = $run(array_merge(array($g), $singles(6, 2.5)), array($team(1, 'unranked', 8, 2.5, array('spots_open' => 2))));
$check('a group a team said no to is kept away from it', $dest($res, $g) !== 't:1');

// --- Leftover reasons in plain words --------------------------------------------------------------------
$nd = $unit(2.5, array('size' => 2, 'divisions' => array()));
$res = $run(array($nd), array($team(1, 'unranked', 5, 2.5)));
$check('no shared division', $dest($res, $nd) === 'none' && $why($res, $nd) === 'Friend group picked divisions that don\'t overlap' && $res['units'][$nd['ref']]['flags'] === array('no_division'), $why($res, $nd));
$far = $unit(4.5);
$res = $run(array($far), array($team(1, 'unranked', 5, 1.0)));
$check('nobody within range', $dest($res, $far) === 'none' && $why($res, $far) === 'Nobody within range in your division', $why($res, $far));
$r3 = $singles(3, 4.0, array('divisions' => array('ranked')));
$res = $run($r3, array());
$check('a few Ranked players: how many short of a team', $why($res, $r3[0]) === '3 Ranked players left — 4 short of a new team; Ranked teams full', $why($res, $r3[0]));
$one = $singles(1, 2.0);
$res = $run($one, array($team(1, 'unranked', 10, 2.0)));
$check('one player: singular wording', $why($res, $one[0]) === '1 Unranked player left — 6 short of a new team; Unranked teams full', $why($res, $one[0]));
$cap = $singles(8, 2.0);
$res = $run($cap, array($team(1, 'unranked', 10, 2.0)), array('capacity' => array('unranked' => 1, 'ranked' => null)));
$check('no places left (set by hand: the allowance isn\'t named)', !$res['new_teams'] && $why($res, $cap[0]) === '8 Unranked players left — no place for another Unranked team; Unranked teams full' && in_array('capacity', $res['units'][$cap[0]['ref']]['flags'], true), $why($res, $cap[0]));
$res = $run($cap, array($team(1, 'unranked', 10, 2.0)), array('capacity' => array('unranked' => 1, 'ranked' => null), 'allowance' => array('unranked' => 2, 'ranked' => 2)));
$check('no places left: the reason names the allowance they were worked out with (0.19.0)', $why($res, $cap[0]) === '8 Unranked players left — no place for another Unranked team without teams sitting out more than 2 nights a season; Unranked teams full', $why($res, $cap[0]));
$res = $run($cap, array($team(1, 'unranked', 10, 2.0)), array('capacity' => array('unranked' => 1, 'ranked' => null), 'allowance' => array('unranked' => 1, 'ranked' => null)));
$check('...one night', $why($res, $cap[0]) === '8 Unranked players left — no place for another Unranked team without teams sitting out more than 1 night a season; Unranked teams full', $why($res, $cap[0]));
$res = $run($cap, array($team(1, 'unranked', 10, 2.0)), array('capacity' => array('unranked' => 0, 'ranked' => null), 'allowance' => array('unranked' => null, 'ranked' => null), 'no_slots' => array('unranked' => true)));
$check('no game slot for the division on any night: the reason says no court space, as before 0.19.0', $why($res, $cap[0]) === '8 Unranked players left — no court space for another Unranked team; Unranked teams full', $why($res, $cap[0]));

// --- Places -----------------------------------------------------------------------------------------------
$res = $run($singles(30, 2.0), array(), array('capacity' => array('unranked' => 2, 'ranked' => null)));
$check('the places cap new teams (30 players, 2 places)', count($res['new_teams']) === 2 && $res['summary']['placed'] === 20 && $res['summary']['unplaced'] === 10);
$res = $run($singles(30, 2.0), array($team(1, 'unranked', 8, 2.0)), array('capacity' => array('unranked' => 2, 'ranked' => null)));
$check('existing teams take places', count($res['new_teams']) === 1, (string) count($res['new_teams']));
$rk = $singles(10, 4.0, array('divisions' => array('ranked')));
$res = $run($rk, array(), array('capacity' => array('unranked' => null, 'ranked' => 0)));
$check('no Ranked places: no Ranked team', !$res['new_teams'] && $res['summary']['unplaced'] === 10);
$ei = $singles(10, 4.0, array('divisions' => array('unranked', 'ranked')));
$res = $run($ei, array(), array('capacity' => array('unranked' => null, 'ranked' => 0)));
$check('Either players go to Unranked when Ranked is full', count($res['new_teams']) === 1 && isset($res['new_teams']['U1']), json_encode(array_keys($res['new_teams'])));

// --- Places held for applications waiting for approval (0.19.0) -----------------------------------------------
// The club's decision (5 Nov 2026): first come, first served. Placement makes new teams only in the places
// left that no application waiting for approval is in line for (settings held, from RSL_Capacity::held()).
$res = $run($singles(30, 2.0), array(), array('capacity' => array('unranked' => 3, 'ranked' => null), 'held' => array('unranked' => 1, 'ranked' => 0)));
$check('held places stay free: 3 places, 1 held, 30 players make exactly 2 new teams', count($res['new_teams']) === 2 && $res['summary']['placed'] === 20 && $res['summary']['unplaced'] === 10,
    json_encode($new_sizes($res)));
$res = $run($singles(30, 2.0), array($team(1, 'unranked', 8, 2.0)), array('capacity' => array('unranked' => 3, 'ranked' => null), 'held' => array('unranked' => 1, 'ranked' => 0)));
$check('...a team there takes one of the places not held: 1 new team', count($res['new_teams']) === 1, (string) count($res['new_teams']));
$all = $singles(8, 2.0);
$res = $run($all, array($team(1, 'unranked', 10, 2.0)), array('capacity' => array('unranked' => 3, 'ranked' => null), 'held' => array('unranked' => 2, 'ranked' => 0), 'allowance' => array('unranked' => 2, 'ranked' => 2)));
$check('every place left held: no new team, and the free agents get the capacity reason, which never mentions applications', !$res['new_teams']
    && $why($res, $all[0]) === '8 Unranked players left — no place for another Unranked team without teams sitting out more than 2 nights a season; Unranked teams full'
    && in_array('capacity', $res['units'][$all[0]['ref']]['flags'], true) && stripos($why($res, $all[0]), 'application') === false, $why($res, $all[0]));
$res = $run($all, array($team(1, 'unranked', 10, 2.0)), array('capacity' => array('unranked' => 3, 'ranked' => null), 'held' => array('unranked' => 1, 'ranked' => 0)));
$check('...with one of the two left held, the other still makes a team', count($res['new_teams']) === 1 && $dest($res, $all[0]) === 'n:U1', json_encode(array_keys($res['new_teams'])));
$res = $run($singles(16, 2.0), array(), array('held' => array('unranked' => 5, 'ranked' => 5)));
$check('a division whose places aren\'t checked holds nothing (16 players, 2 new teams)', count($res['new_teams']) === 2, (string) count($res['new_teams']));
$ei = $singles(10, 4.0, array('divisions' => array('unranked', 'ranked')));
$res = $run($ei, array($team(1, 'ranked', 10, 4.0)), array('capacity' => array('unranked' => null, 'ranked' => 2), 'held' => array('unranked' => 0, 'ranked' => 1)));
$check('Either players go to Unranked when the Ranked place left is held', count($res['new_teams']) === 1 && isset($res['new_teams']['U1']), json_encode(array_keys($res['new_teams'])));
$res = $run($ei, array($team(1, 'ranked', 10, 4.0)), array('capacity' => array('unranked' => null, 'ranked' => 2), 'held' => array('unranked' => 0, 'ranked' => 0)));
$check('...and stay in Ranked when nobody holds it', count($res['new_teams']) === 1 && isset($res['new_teams']['R1']), json_encode(array_keys($res['new_teams'])));
$l = $unit(2.0, array('locked' => 'n:U1'));
$res = $run(array_merge(array($l), $singles(20, 2.0)), array(), array('capacity' => array('unranked' => 2, 'ranked' => null), 'held' => array('unranked' => 2, 'ranked' => 0)));
$check('an admin\'s new team in a held place is kept and filled (confirming warns), and placement makes no other', isset($res['new_teams']['U1']) && $res['new_teams']['U1']['seeded']
    && count($res['new_teams']) === 1 && $dest($res, $l) === 'n:U1', json_encode(array_keys($res['new_teams'])));
$bad = RSL_Placement::violations($all, array($team(1, 'unranked', 10, 2.0)), array_merge($S, array('capacity' => array('unranked' => 3, 'ranked' => null), 'held' => array('unranked' => 2, 'ranked' => 0))),
    $hand(array('U1' => $all)));
$check('violations(): a new team in a held place is a broken rule', (bool) preg_grep('/1 new unranked teams but places for 0/', $bad), implode('; ', $bad));

// --- Locked units (an admin's moves) -----------------------------------------------------------------------
$l = $unit(4.0, array('locked' => 't:2')); $f = $unit(4.0);
$res = $run(array($l, $f), array($team(1, 'unranked', 6, 4.0, array('spots_open' => 2)), $team(2, 'unranked', 7, 1.0)));
$check('a locked unit stays where the admin put it', $dest($res, $l) === 't:2' && $why($res, $l) === 'Moved by an admin' && in_array('locked', $res['units'][$l['ref']]['flags'], true));
$check('and the others are placed around it', $dest($res, $f) === 't:1' && $res['teams'][2]['after'] === 8);
$l = $unit(2.0, array('locked' => 't:1')); $f = $unit(2.0);
$res = $run(array($l, $f), array($team(1, 'unranked', 9, 2.0)));
$check('locked units take room first', $dest($res, $l) === 't:1' && $dest($res, $f) === 'none' && $res['teams'][1]['after'] === 10);
$h = $unit(2.0, array('locked' => 'none'));
$res = $run(array_merge(array($h), $singles(6, 2.0)), array());
$check('held back by an admin', $dest($res, $h) === 'none' && $why($res, $h) === 'Held back by an admin' && count($res['new_teams']) === 0);
$l = $unit(2.0, array('locked' => 'n:U4'));
$res = $run(array_merge(array($l), $singles(20, 2.0)), array());
$check('an admin-started new team is kept (and filled)', isset($res['new_teams']['U4']) && $res['new_teams']['U4']['seeded'] && $dest($res, $l) === 'n:U4' && $res['new_teams']['U4']['size'] >= 7);
$refs = function ($res) {
    $list = array_keys($res['new_teams']);
    sort($list);
    return $list;
};
$check('the other new teams take the lowest free numbers around it', $refs($res) === array('U1', 'U4'), json_encode($refs($res)));
$pool = $singles(23, 2.0);
$first = $refs($run($pool, array()));
$pool[0]['locked'] = 'n:U4'; // the admin starts another new team ("Start another new Unranked team" offers the next number)
$res = $run($pool, array());
$check('re-run after an admin starts another new team: the draft\'s teams keep their numbers (so their names, levels and captains)', $first === array('U1', 'U2', 'U3') && $refs($res) === array('U1', 'U2', 'U4') && $res['new_teams']['U4']['seeded'],
    json_encode($first) . ' then ' . json_encode($refs($res)));
$res = $run($singles(8, 2.0), array(), array('used_refs' => array('U1', 'U2')));
$check('numbers already made into teams are never reused', $refs($res) === array('U3'));
$res = $run($singles(8, 2.0), array(), array('used_refs' => array('U2')));
$check('a lower number nobody has used is taken first', $refs($res) === array('U1'));
$gone = $unit(2.0, array('locked' => 't:999'));
$res = RSL_Placement::propose(array($gone), array(), $S);
$check('locked to a team no longer in the season: kept, and said', $dest($res, $gone) === 't:999' && in_array('gone', $res['units'][$gone['ref']]['flags'], true));
$l = $unit(2.5, array('locked' => 't:1', 'divisions' => array('unranked')));
$res = $run(array($l), array($team(1, 'ranked', 7, 2.5)));
$check('an admin\'s move is honoured as made (blockers() catch a wrong division)', $dest($res, $l) === 't:1');

// --- Same answer every time ----------------------------------------------------------------------------------
/** A random pool: groups, mixed divisions, volunteers, setters, requests, a few no's and admin moves. */
$random_pool = function ($n, $seed) use ($unit, $team) {
    mt_srand($seed);
    $bands = array(1.0, 1.5, 2.0, 2.5, 3.0, 3.5, 4.0, 4.5, 5.0);
    $teams = array();
    for ($i = 1; $i <= mt_rand(3, 9); $i++) {
        $teams[] = $team(100 + $i, mt_rand(0, 1) ? 'ranked' : 'unranked', mt_rand(3, 10), $bands[mt_rand(1, 7)], array(
            'spots_open' => mt_rand(0, 3), 'pref_time' => array('any', 'early', 'late')[mt_rand(0, 2)],
            'lacks' => mt_rand(0, 1) ? array('setter') : array('libero', 'middle'),
        ));
    }
    $units = array();
    $people = 0;
    $locked = array(); // heads an admin has moved onto each team (destinations() only offers teams with room)
    while ($people < $n) {
        $size = mt_rand(1, 100) <= 15 ? mt_rand(2, 3) : 1;
        $band = $bands[mt_rand(0, 8)];
        $divs = array(array('unranked'), array('ranked'), array('unranked', 'ranked'))[mt_rand(0, 2)];
        if (mt_rand(1, 100) <= 2) {
            $divs = array();
        }
        $member_bands = array();
        for ($m = 0; $m < $size; $m++) {
            $member_bands[] = max(1.0, min(5.0, $band + (mt_rand(-1, 1) * 0.5)));
        }
        $o = array('size' => $size, 'bands' => $member_bands, 'divisions' => $divs, 'captain' => mt_rand(1, 100) <= 12, 'veteran' => mt_rand(0, 1) === 1,
            'time' => array('any', 'any', 'early', 'late')[mt_rand(0, 3)], 'away_early' => mt_rand(0, 9) === 0 ? 2 : 0,
            'positions' => mt_rand(1, 100) <= 25 ? array('setter') : (mt_rand(0, 1) ? array('outside', 'middle') : array()));
        $o['band'] = round(array_sum($member_bands) / $size, 3);
        if ($teams && mt_rand(1, 100) <= 20) {
            $o['requested'] = array($teams[mt_rand(0, count($teams) - 1)]['id']);
        }
        if ($teams && mt_rand(1, 100) <= 8) {
            $o['declined'] = array($teams[mt_rand(0, count($teams) - 1)]['id']);
        }
        if ($teams && mt_rand(1, 100) <= 4) {
            $t = $teams[mt_rand(0, count($teams) - 1)];
            $moved = isset($locked[$t['id']]) ? $locked[$t['id']] : 0;
            $o['locked'] = $t['n'] + $moved + $size <= 10 ? 't:' . $t['id'] : 'none';
            if ($o['locked'] !== 'none') {
                $locked[$t['id']] = $moved + $size;
            }
        }
        $units[] = $unit($o['band'], $o);
        $people += $size;
    }
    return array($units, $teams);
};
$shuffle = function ($list) {
    for ($i = count($list) - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        list($list[$i], $list[$j]) = array($list[$j], $list[$i]);
    }
    return $list;
};
list($units, $teams) = $random_pool(60, 4242);
$one = RSL_Placement::propose($units, $teams, $S);
$two = RSL_Placement::propose($units, $teams, $S);
$check('the same pool gives the identical answer twice', serialize($one) === serialize($two));
$three = RSL_Placement::propose($shuffle($units), $shuffle($teams), $S);
$check('and in any input order', serialize($one) === serialize($three));

// --- The rules over many random pools ---------------------------------------------------------------------------
$broken = array();
$counts = array('runs' => 0, 'placed' => 0, 'players' => 0, 'new' => 0);
foreach (array(20, 40, 60, 80, 120) as $n) {
    for ($i = 1; $i <= 8; $i++) {
        list($units, $teams) = $random_pool($n, $n * 100 + $i);
        $s = $S;
        if ($i % 3 === 0) {
            $s['capacity'] = array('unranked' => mt_rand(2, 8), 'ranked' => mt_rand(2, 8));
        }
        if ($i === 6) {
            // Some of those places held for applications waiting for approval (violations() checks new teams leave them).
            $s['held'] = array('unranked' => mt_rand(0, 3), 'ranked' => mt_rand(0, 3));
        }
        $res = RSL_Placement::propose($units, $teams, $s);
        foreach (RSL_Placement::violations($units, $teams, $s, $res) as $v) {
            $broken[] = "pool $n/$i: $v";
        }
        // Checked here too, independently of violations().
        $by = array();
        foreach ($teams as $t) {
            $by['t:' . $t['id']] = $t;
        }
        $heads = array();
        foreach ($units as $u) {
            $o = $res['units'][$u['ref']];
            if (!is_string($o['reason']) || trim($o['reason']) === '' || preg_match('/\b[tnug]:|_|\bunit\b|\bref\b/i', $o['reason'])) {
                $broken[] = "pool $n/$i: {$u['ref']} reason isn't plain words: {$o['reason']}";
            }
            if ($o['dest'] === 'none' || $u['locked'] !== '') {
                continue;
            }
            $div = isset($by[$o['dest']]) ? $by[$o['dest']]['division'] : (isset($res['new_teams'][substr($o['dest'], 2)]) ? $res['new_teams'][substr($o['dest'], 2)]['division'] : '?');
            if ($u['divisions'] === array('unranked') && $div !== 'unranked') {
                $broken[] = "pool $n/$i: Unranked-only {$u['ref']} placed in $div";
            }
            if (isset($by[$o['dest']]) && in_array($by[$o['dest']]['id'], $u['declined'], true)) {
                $broken[] = "pool $n/$i: {$u['ref']} placed on a team that said no";
            }
        }
        foreach ($units as $u) {
            $o = $res['units'][$u['ref']];
            if ($o['dest'] !== 'none') {
                $heads[$o['dest']] = (isset($heads[$o['dest']]) ? $heads[$o['dest']] : 0) + $u['size'];
            }
        }
        foreach ($by as $key => $t) {
            if (isset($heads[$key]) && $t['n'] + $heads[$key] > $s['max_roster']) {
                $broken[] = "pool $n/$i: {$t['name']} over the maximum";
            }
        }
        foreach ($res['new_teams'] as $ref => $d) {
            $ids = array();
            $vol = false;
            foreach ($d['unit_refs'] as $r) {
                foreach ($units as $u) {
                    if ($u['ref'] === $r) {
                        $ids = array_merge($ids, $u['user_ids']);
                        foreach ($u['members'] as $m) {
                            $vol = $vol || $m['captain_ok'];
                        }
                    }
                }
            }
            sort($ids);
            $listed = $d['user_ids'];
            sort($listed);
            if ($ids !== $listed) {
                $broken[] = "pool $n/$i: new team $ref doesn't hold its units' people exactly (split group?)";
            }
            if ($vol !== $d['volunteer'] || !in_array($d['captain_user_id'], $d['user_ids'], true)) {
                $broken[] = "pool $n/$i: new team $ref captain isn't its volunteer";
            }
        }
        $sum = $res['summary'];
        $locked_gone = 0;
        foreach ($units as $u) {
            $locked_gone += $res['units'][$u['ref']]['dest'] !== 'none' && strpos($res['units'][$u['ref']]['dest'], 't:') === 0 && !isset($by[$res['units'][$u['ref']]['dest']]) ? $u['size'] : 0;
        }
        if ($sum['placed'] + $sum['unplaced'] + $locked_gone !== $sum['players']) {
            $broken[] = "pool $n/$i: summary doesn't add up";
        }
        $counts['runs']++;
        $counts['placed'] += $sum['placed'];
        $counts['players'] += $sum['players'];
        $counts['new'] += $sum['new_teams'];
    }
}
$check('the rules hold over ' . $counts['runs'] . ' random pools (groups whole, divisions, no\'s, maximum, new teams 7–10, volunteers, places, held places, locks)', !$broken, implode(' | ', array_slice($broken, 0, 8)));
$check('and most people are placed (' . round(100 * $counts['placed'] / max(1, $counts['players'])) . '%)', $counts['placed'] > 0.6 * $counts['players']);

// --- Capacity of the engine ------------------------------------------------------------------------------------------
list($units, $teams) = $random_pool(120, 99);
$t0 = microtime(true);
RSL_Placement::propose($units, $teams, $S);
$ms = (microtime(true) - $t0) * 1000;
$check('120 free agents placed in well under a second (' . round($ms, 1) . ' ms)', $ms < 1000);

echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
