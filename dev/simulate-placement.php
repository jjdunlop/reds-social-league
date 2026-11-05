<?php
/**
 * DEV ONLY — runs the placement engine (RSL_Placement::propose(), pure, no
 * database) over pretend free-agent pools and reports how well it places
 * people. Re-run it before changing RSL_Placement::weights() or the rules.
 *
 *   wp eval-file dev/simulate-placement.php [seeds=5] [pools=40,80] [seed=N] [verbose]
 *   php dev/simulate-placement.php [seeds=5] [pools=40,80] [seed=N] [verbose]   (no WordPress needed)
 *
 * seed=N runs only that seed; verbose lists the first seed run, team by team
 * and unit by unit (pretend ids, no people).
 *
 * Each pool: a realistic level mix (about 20% never played, 20% school,
 * 25% social, 25% club/VVL, 10% State League; years varied), about 30% in
 * friend groups of 2–3, about 10% happy to captain, a few under-18s,
 * Unranked-only beginners, Ranked-only and Either players, some requests to
 * teams (a few turned down); plus a handful of existing teams — some short,
 * some with open spots, one full. Everything is generated from a seed
 * (mt_srand), so a run is repeatable. Nobody real: no names, no emails.
 *
 * Reports % placed, the level gap (a player's band vs their team's), % of
 * those who asked a team who got it, new teams without a volunteer captain
 * or a setter, short teams left, and the placement rules
 * (RSL_Placement::violations() plus the checks below) — which must be zero.
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
    require_once __DIR__ . '/../includes/class-rsl-placement.php';
}
if (!class_exists('RSL_Free_Agents')) {
    require_once __DIR__ . '/../includes/class-rsl-free-agents.php'; // band() and group_divisions() are pure
}

$cli = isset($args) ? (array) $args : (isset($argv) ? array_slice($argv, 1) : array());
$opt = array('seeds' => 5, 'pools' => array(40, 80), 'verbose' => false, 'only' => 0);
foreach ($cli as $a) {
    if (preg_match('/^seeds=(\d+)$/', $a, $m)) {
        $opt['seeds'] = max(1, intval($m[1]));
    } elseif (preg_match('/^seed=(\d+)$/', $a, $m)) {
        $opt['only'] = max(1, intval($m[1])); // just this one (with verbose: its detail)
    } elseif (preg_match('/^pools=([\d,]+)$/', $a, $m)) {
        $opt['pools'] = array_values(array_filter(array_map('intval', explode(',', $m[1]))));
    } elseif ($a === 'verbose') {
        $opt['verbose'] = true;
    }
}

$settings = array(
    'min_roster' => 7, 'target_roster' => 8, 'new_team_target' => 8, 'max_roster' => 10,
    'gap_fill' => 1.5, 'gap_short' => 2.0,
    'capacity' => array('unranked' => 12, 'ranked' => 12), // places: 6 game slots a night with no team sitting out (RSL_Capacity, allowance 0)
);

/** A weighted choice: key => integer weight. */
$pick = function ($weights) {
    $r = mt_rand(1, array_sum($weights));
    foreach ($weights as $k => $w) {
        $r -= $w;
        if ($r <= 0) {
            return $k;
        }
    }
    return key($weights);
};

/** One pretend free agent, the shape of a pool row (level and years as on the registration form). */
$person = function ($uid, $minute, $near = null) use ($pick) {
    $levels = array('never', 'school', 'social', 'vvl', 'state');
    if ($near === null) {
        $level = $pick(array('never' => 20, 'school' => 20, 'social' => 25, 'vvl' => 25, 'state' => 10));
    } else {
        // Friends tend to play at about the same level.
        $i = array_search($near, $levels, true) + $pick(array(0 => 60, -1 => 20, 1 => 20));
        $level = $levels[max(0, min(4, $i))];
    }
    $years = $pick(array(
        'never' => array('lt1' => 75, '1to3' => 25, 'gt3' => 0),
        'school' => array('lt1' => 20, '1to3' => 50, 'gt3' => 30),
        'social' => array('lt1' => 10, '1to3' => 50, 'gt3' => 40),
        'vvl' => array('lt1' => 0, '1to3' => 40, 'gt3' => 60),
        'state' => array('lt1' => 0, '1to3' => 20, 'gt3' => 80),
    )[$level]);
    // Beginners default to Unranked (and mostly keep it); experienced players lean Either or Ranked.
    $pref = $pick(array(
        'never' => array('unranked' => 90, 'either' => 10, 'ranked' => 0),
        'school' => array('unranked' => 70, 'either' => 30, 'ranked' => 0),
        'social' => array('unranked' => 40, 'either' => 45, 'ranked' => 15),
        'vvl' => array('unranked' => 10, 'either' => 50, 'ranked' => 40),
        'state' => array('unranked' => 0, 'either' => 40, 'ranked' => 60),
    )[$level]);
    $positions = array();
    $setter_chance = array('never' => 5, 'school' => 15, 'social' => 20, 'vvl' => 25, 'state' => 30)[$level];
    if (mt_rand(1, 100) <= $setter_chance) {
        $positions[] = 'setter';
    }
    if ($level !== 'never' || mt_rand(1, 100) <= 30) {
        foreach (array('outside' => 35, 'middle' => 25, 'opposite' => 15, 'libero' => 10) as $p => $chance) {
            if (mt_rand(1, 100) <= $chance) {
                $positions[] = $p;
            }
        }
    }
    $captain = mt_rand(1, 100) <= array('never' => 2, 'school' => 6, 'social' => 10, 'vvl' => 15, 'state' => 20)[$level];
    return (object) array(
        'user_id' => $uid,
        'level' => $level,
        'years' => $years,
        'division_pref' => $pref,
        'time_pref' => $pick(array('any' => 60, 'early' => 20, 'late' => 20)),
        'positions' => implode(',', $positions),
        'captain_ok' => $captain ? 1 : 0,
        'under18' => mt_rand(1, 100) <= array('never' => 10, 'school' => 10, 'social' => 2, 'vvl' => 0, 'state' => 0)[$level] ? 1 : 0,
        'away_early' => $pick(array(0 => 80, 1 => 15, 2 => 4, 3 => 1)),
        'band_override' => null,
        'created_at' => gmdate('Y-m-d H:i:s', 3660000000 + $minute * 60), // a far-future January
        'group_code' => '',
    );
};

/** Units from rows, the way RSL_Placement::build() makes them from the database. */
$unit_from = function ($list, $ref) {
    $members = array();
    $ids = array();
    $times = array('early' => 0, 'late' => 0);
    $positions = array();
    $away = 0;
    $band_sum = 0.0;
    $under18 = 0;
    foreach ($list as $r) {
        $band = (float) RSL_Free_Agents::band($r, $r);
        $ids[] = $r->user_id;
        $band_sum += $band;
        $members[] = array('user_id' => $r->user_id, 'band' => $band, 'captain_ok' => (bool) $r->captain_ok, 'veteran' => $r->years === 'gt3');
        if (isset($times[$r->time_pref])) {
            $times[$r->time_pref]++;
        }
        foreach (array_filter(explode(',', $r->positions)) as $p) {
            $positions[$p] = true;
        }
        $away = max($away, $r->away_early);
        $under18 += $r->under18;
    }
    $captain = 0;
    $captain_band = -1.0;
    foreach ($members as $m) {
        if ($m['captain_ok'] && $m['band'] > $captain_band) {
            $captain = $m['user_id'];
            $captain_band = $m['band'];
        }
    }
    return array(
        'ref' => $ref, 'user_ids' => $ids, 'lead_user_id' => $ids[0], 'size' => count($ids),
        'band' => round($band_sum / count($ids), 3), 'members' => $members,
        'divisions' => RSL_Free_Agents::group_divisions($list),
        'time' => $times['early'] > $times['late'] ? 'early' : ($times['late'] > $times['early'] ? 'late' : 'any'),
        'positions' => array_keys($positions), 'away_early' => $away,
        'captain_ok' => $captain > 0, 'captain_user_id' => $captain,
        'requested' => array(), 'declined' => array(),
        'created_at' => $list[0]->created_at, 'locked' => '', 'under18' => $under18,
    );
};

/**
 * A pretend season: $n free agents and a handful of existing teams (some
 * short, some with open spots, one full). Returns [$units, $teams, $meta].
 */
$make_pool = function ($n, $seed) use ($pick, $person, $unit_from, $settings) {
    mt_srand($seed);
    $team_count = max(5, (int) round($n / 8));
    $teams = array();
    $full_at = mt_rand(0, $team_count - 1);
    for ($i = 0; $i < $team_count; $i++) {
        $division = mt_rand(1, 100) <= 55 ? 'unranked' : 'ranked';
        $shape = $i === $full_at ? 'full' : $pick(array('short' => 35, 'spots' => 45, 'settled' => 20));
        if ($shape === 'full') {
            $members = $settings['max_roster'];
            $spots = 0;
        } elseif ($shape === 'short') {
            $members = mt_rand(3, $settings['min_roster'] - 1);
            $spots = mt_rand(0, 2);
        } elseif ($shape === 'spots') {
            $members = mt_rand($settings['min_roster'] - 1, $settings['target_roster']);
            $spots = mt_rand(1, 3);
        } else {
            $members = mt_rand($settings['target_roster'], $settings['max_roster'] - 1);
            $spots = 0;
        }
        // Ranked teams' bands come from results (any value); others from the captain's level (whole bands).
        $band = $division === 'ranked' ? round(mt_rand(250, 450) / 100, 2) : (float) $pick(array(1 => 15, 2 => 40, 3 => 35, 4 => 10));
        $lacks = array();
        foreach (array('setter' => 30, 'middle' => 20, 'outside' => 10, 'opposite' => 25, 'libero' => 40) as $p => $chance) {
            if (mt_rand(1, 100) <= $chance) {
                $lacks[] = $p;
            }
        }
        $teams[] = array(
            'id' => 101 + $i, 'name' => 'Team ' . (101 + $i), 'division' => $division, 'n' => $members, 'band' => $band,
            'pref_time' => $pick(array('any' => 60, 'early' => 20, 'late' => 20)), 'lacks' => $lacks, 'spots_open' => $spots,
        );
    }

    // People, in sign-up order; about 15% of units are friend groups (≈ 30% of people).
    $rows_by_unit = array();
    $uid = 1000;
    $minute = 0;
    $people = 0;
    while ($people < $n) {
        $size = mt_rand(1, 100) <= 15 ? $pick(array(2 => 65, 3 => 35)) : 1;
        $size = min($size, $n - $people);
        $minute += mt_rand(5, 600);
        $lead = $person(++$uid, $minute);
        $list = array($lead);
        for ($f = 1; $f < $size; $f++) {
            $friend = $person(++$uid, $minute + $f, $lead->level);
            // A group must share a division (group_join refuses otherwise): the friend changes theirs.
            if (!RSL_Free_Agents::group_divisions(array_merge($list, array($friend)))) {
                $friend->division_pref = 'either';
            }
            $list[] = $friend;
        }
        if ($size > 1) {
            foreach ($list as $r) {
                $r->group_code = 'G' . $lead->user_id;
            }
        }
        $rows_by_unit[] = $list;
        $people += $size;
    }
    $units = array();
    foreach ($rows_by_unit as $list) {
        $units[] = $unit_from($list, $list[0]->group_code !== '' ? 'g:' . $list[0]->group_code : 'u:' . $list[0]->user_id);
    }

    // Some ask a team or two that's looking (pending or lapsed requests); a few were turned down.
    foreach ($units as $k => $u) {
        if (mt_rand(1, 100) <= 20) {
            $choices = array();
            foreach ($teams as $t) {
                $looking = $t['spots_open'] > 0 || $t['n'] < $settings['min_roster'];
                if ($looking && in_array($t['division'], $u['divisions'], true) && $t['n'] + $u['size'] <= $settings['max_roster'] && abs($t['band'] - $u['band']) <= 1.5) {
                    $choices[] = $t['id'];
                }
            }
            $asks = min(count($choices), mt_rand(1, 2));
            for ($a = 0; $a < $asks; $a++) {
                $id = $choices[mt_rand(0, count($choices) - 1)];
                if (in_array($id, $units[$k]['requested'], true) || in_array($id, $units[$k]['declined'], true)) {
                    continue;
                }
                if (mt_rand(1, 100) <= 15) {
                    $units[$k]['declined'][] = $id;
                } else {
                    $units[$k]['requested'][] = $id;
                }
            }
        }
        if (mt_rand(1, 100) <= 3) {
            // Turned down a pool invite from a team (declines go both ways).
            $id = $teams[mt_rand(0, count($teams) - 1)]['id'];
            if (!in_array($id, $units[$k]['requested'], true) && !in_array($id, $units[$k]['declined'], true)) {
                $units[$k]['declined'][] = $id;
            }
        }
    }
    $meta = array('people' => $n, 'units' => count($units), 'in_groups' => 0, 'volunteers' => 0, 'under18' => 0, 'unranked_only' => 0, 'ranked_only' => 0, 'either' => 0);
    foreach ($units as $u) {
        $meta['in_groups'] += $u['size'] > 1 ? $u['size'] : 0;
        $meta['under18'] += $u['under18'];
        foreach ($u['members'] as $m) {
            $meta['volunteers'] += $m['captain_ok'] ? 1 : 0;
        }
        $key = count($u['divisions']) === 2 ? 'either' : ($u['divisions'] === array('ranked') ? 'ranked_only' : 'unranked_only');
        $meta[$key] += $u['size'];
    }
    return array($units, $teams, $meta);
};

/**
 * The rules, checked independently of RSL_Placement::violations(): every
 * unit placed once and whole, nobody in a division they didn't choose or on
 * a team that said no, nobody over the maximum, new teams min–max with
 * their units' people exactly, a volunteer captain where the team has one,
 * reasons in plain words.
 */
$own_checks = function ($units, $teams, $s, $res) {
    $bad = array();
    $team_by = array();
    foreach ($teams as $t) {
        $team_by['t:' . $t['id']] = $t;
    }
    $new = $res['new_teams'];
    $heads = array();
    $seen_users = array();
    $unit_by = array();
    foreach ($units as $u) {
        $unit_by[$u['ref']] = $u;
        foreach ($u['user_ids'] as $id) {
            if (isset($seen_users[$id])) {
                $bad[] = "user $id is in two units";
            }
            $seen_users[$id] = true;
        }
        if (!isset($res['units'][$u['ref']])) {
            $bad[] = "{$u['ref']} has no result";
            continue;
        }
        $o = $res['units'][$u['ref']];
        if (!is_string($o['reason']) || trim($o['reason']) === '' || preg_match('/\b[tnug]:|_|\bref\b|\bunit\b/i', $o['reason'])) {
            $bad[] = "{$u['ref']} has a reason that isn't plain words: '{$o['reason']}'";
        }
        if ($o['dest'] === 'none') {
            continue;
        }
        if (isset($team_by[$o['dest']])) {
            $div = $team_by[$o['dest']]['division'];
            if (in_array($team_by[$o['dest']]['id'], $u['declined'], true)) {
                $bad[] = "{$u['ref']} placed on a team that said no";
            }
        } elseif (preg_match('/^n:(\w+)$/', $o['dest'], $m) && isset($new[$m[1]])) {
            $div = $new[$m[1]]['division'];
        } else {
            $bad[] = "{$u['ref']} went to an unknown place {$o['dest']}";
            continue;
        }
        if (!in_array($div, $u['divisions'], true)) {
            $bad[] = "{$u['ref']} (" . implode('/', $u['divisions']) . ") placed in $div";
        }
        $heads[$o['dest']] = (isset($heads[$o['dest']]) ? $heads[$o['dest']] : 0) + $u['size'];
    }
    foreach ($team_by as $key => $t) {
        $after = $t['n'] + (isset($heads[$key]) ? $heads[$key] : 0);
        if ($after > $s['max_roster']) {
            $bad[] = "{$t['name']} over the maximum ($after)";
        }
        if (isset($res['teams'][$t['id']]) && $res['teams'][$t['id']]['after'] !== $after) {
            $bad[] = "{$t['name']} reports {$res['teams'][$t['id']]['after']} players but has $after";
        }
    }
    $levels = array('new', 'social', 'solid', 'strong', 'advanced');
    foreach ($new as $ref => $d) {
        $h = isset($heads['n:' . $ref]) ? $heads['n:' . $ref] : 0;
        if (empty($d['seeded']) && ($h < $s['min_roster'] || $h > $s['max_roster'])) {
            $bad[] = "new team $ref has $h players";
        }
        $ids = array();
        $volunteer = false;
        foreach ($d['unit_refs'] as $r) {
            if ($res['units'][$r]['dest'] !== 'n:' . $ref) {
                $bad[] = "new team $ref lists $r, which went elsewhere";
            }
            foreach ($unit_by[$r]['members'] as $m) {
                $ids[] = $m['user_id'];
                $volunteer = $volunteer || $m['captain_ok'];
            }
        }
        $listed = $d['user_ids'];
        sort($ids);
        sort($listed);
        if ($ids !== $listed) {
            $bad[] = "new team $ref's people don't match its units (a group split?)";
        }
        if (!in_array($d['captain_user_id'], $d['user_ids'], true)) {
            $bad[] = "new team $ref's captain isn't in the team";
        }
        if ($volunteer !== $d['volunteer']) {
            $bad[] = "new team $ref has a volunteer but the captain isn't one (or the reverse)";
        }
        if (!in_array($d['est_level'], $levels, true)) {
            $bad[] = "new team $ref has level '{$d['est_level']}'";
        }
    }
    return $bad;
};

/** The same pool in a different order (units and teams), shuffled from the current seed. */
$shuffle = function ($list) {
    for ($i = count($list) - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        $tmp = $list[$i];
        $list[$i] = $list[$j];
        $list[$j] = $tmp;
    }
    return $list;
};

$problems = 0;
$scenarios = array();
foreach ($opt['pools'] as $n) {
    $scenarios[] = array('n' => $n, 'label' => "Pool of $n free agents", 'tight' => false);
}
if ($opt['pools']) {
    $scenarios[] = array('n' => max($opt['pools']), 'label' => 'Pool of ' . max($opt['pools']) . ' free agents, courts tight (room for one new team a division)', 'tight' => true);
}

echo "Placement simulator — RSL_Placement::propose() over pretend pools (no database)\n";
echo "Teams of {$settings['min_roster']}–{$settings['max_roster']} (existing teams filled to {$settings['target_roster']}, new teams of {$settings['new_team_target']}); "
    . "level gap ≤ {$settings['gap_fill']} filling open spots, ≤ {$settings['gap_short']} for short teams and top-ups\n";

foreach ($scenarios as $sc) {
    $tot = array();
    $why = array();
    $lines = array();
    $first = null;
    for ($i = $opt['only'] ?: 1; $i <= ($opt['only'] ?: $opt['seeds']); $i++) {
        $seed = $sc['n'] * 1000 + $i * 7919;
        list($units, $teams, $meta) = $make_pool($sc['n'], $seed);
        $s = $settings;
        if ($sc['tight']) {
            foreach (array('unranked', 'ranked') as $d) {
                $have = 0;
                foreach ($teams as $t) {
                    $have += $t['division'] === $d ? 1 : 0;
                }
                $s['capacity'][$d] = $have + 1;
            }
        }
        $t0 = microtime(true);
        $res = RSL_Placement::propose($units, $teams, $s);
        $ms = (microtime(true) - $t0) * 1000;
        $viol = array_merge(RSL_Placement::violations($units, $teams, $s, $res), $own_checks($units, $teams, $s, $res));
        $same = serialize(RSL_Placement::propose($units, $teams, $s)) === serialize($res)
            && serialize(RSL_Placement::propose($shuffle($units), $shuffle($teams), $s)) === serialize($res);
        if (!$same) {
            $viol[] = 'not deterministic: a re-run (or the same pool in another order) gave a different answer';
        }
        $problems += count($viol);
        $sum = $res['summary'];
        $short_before = $sum['short_before'];
        $row = array(
            'units' => $meta['units'], 'groups' => $meta['in_groups'], 'vols' => $meta['volunteers'], 'u18' => $meta['under18'],
            'placed' => 100 * $sum['placed'] / max(1, $sum['players']), 'existing' => $sum['placed_existing'], 'new' => $sum['placed_new'],
            'teams' => $sum['new_teams'], 'none' => $sum['unplaced'], 'gap' => $sum['gap_mean'], 'gapmax' => $sum['gap_max'],
            'asked' => $sum['asked_players'] ? 100 * $sum['asked_got'] / $sum['asked_players'] : null,
            'nocap' => $sum['new_no_captain'], 'noset' => $sum['new_no_setter'],
            'short0' => $short_before, 'short1' => $sum['short_after'], 'viol' => count($viol), 'ms' => $ms,
        );
        foreach ($row as $k => $v) {
            if ($v !== null) {
                $tot[$k] = (isset($tot[$k]) ? $tot[$k] : 0) + $v;
                $tot[$k . '_n'] = (isset($tot[$k . '_n']) ? $tot[$k . '_n'] : 0) + 1;
            }
        }
        $tot['gapmax_all'] = max(isset($tot['gapmax_all']) ? $tot['gapmax_all'] : 0, $sum['gap_max']);
        foreach ($res['units'] as $o) {
            if ($o['dest'] === 'none') {
                $key = preg_replace('/\d+/', 'N', $o['reason']);
                $why[$key] = (isset($why[$key]) ? $why[$key] : 0) + 1;
            }
        }
        $lines[] = sprintf("%5d  %5d %6d %4d %3d | %5.0f%% %5d %4d %5d %4d | %4.2f %4.2f | %5s | %4d %5d | %3d → %-3d | %4d %4s | %5.1f",
            $i, $row['units'], $row['groups'], $row['vols'], $row['u18'], $row['placed'], $row['existing'], $row['new'], $row['teams'], $row['none'],
            $row['gap'], $row['gapmax'], $row['asked'] === null ? '–' : sprintf('%.0f%%', $row['asked']), $row['nocap'], $row['noset'],
            $row['short0'], $row['short1'], $row['viol'], $same ? 'yes' : 'NO', $ms);
        foreach ($viol as $v) {
            $lines[] = "       ! $v";
        }
        if ($first === null) {
            $first = array($units, $teams, $res, $meta);
        }
    }
    $avg = function ($k) use ($tot) {
        return isset($tot[$k . '_n']) && $tot[$k . '_n'] ? $tot[$k] / $tot[$k . '_n'] : 0;
    };
    echo "\n{$sc['label']}, " . count($first[1]) . ' existing teams, ' . ($opt['only'] ? "seed {$opt['only']}" : "{$opt['seeds']} seeds")
        . ($sc['tight'] ? '' : " — places for {$settings['capacity']['unranked']} Unranked / {$settings['capacity']['ranked']} Ranked teams") . "\n";
    echo " seed  units groups vols u18 | placed exist  new teams none |  gap  max | asked | nocap noset | short     | viol same |    ms\n";
    echo str_repeat('-', 118) . "\n";
    foreach ($lines as $l) {
        echo $l . "\n";
    }
    echo str_repeat('-', 118) . "\n";
    echo sprintf(" mean  %5.1f %6.1f %4.1f %3.1f | %5.1f%% %5.1f %4.1f %5.1f %4.1f | %4.2f %4.2f | %4.0f%% | %4.1f %5.1f | %3.1f → %-3.1f | %4d      | %5.1f\n",
        $avg('units'), $avg('groups'), $avg('vols'), $avg('u18'), $avg('placed'), $avg('existing'), $avg('new'), $avg('teams'), $avg('none'),
        $avg('gap'), $tot['gapmax_all'], $avg('asked'), $avg('nocap'), $avg('noset'), $avg('short0'), $avg('short1'), $tot['viol'], $avg('ms'));
    if ($why) {
        arsort($why);
        echo " Not placed, and why (people, all seeds):\n";
        foreach ($why as $reason => $count) {
            echo sprintf("   %3d × %s\n", $count, $reason);
        }
    }
    if ($opt['verbose']) {
        list($units, $teams, $res, $meta) = $first;
        echo " Seed 1 in detail — " . json_encode($meta) . "\n";
        foreach ($teams as $t) {
            $r = $res['teams'][$t['id']];
            echo sprintf("   %-9s %-8s band %.1f  %2d → %-2d spots %d\n", $t['name'], $t['division'], $t['band'], $r['before'], $r['after'], $t['spots_open']);
        }
        foreach ($res['new_teams'] as $ref => $d) {
            echo sprintf("   New %-5s %-8s band %.2f  %2d players  captain %s%s  %s\n", $ref, $d['division'], $d['band'], $d['size'], $d['captain_user_id'],
                $d['volunteer'] ? ' (volunteer)' : '', implode(' ', $d['flags']));
        }
        foreach ($units as $u) {
            $o = $res['units'][$u['ref']];
            $vols = 0;
            foreach ($u['members'] as $m) {
                $vols += $m['captain_ok'] ? 1 : 0;
            }
            $notes = ($vols ? " [captain x$vols]" : '') . ($u['requested'] ? ' [asked ' . implode(',', $u['requested']) . ']' : '') . ($u['declined'] ? ' [no from ' . implode(',', $u['declined']) . ']' : '');
            echo sprintf("   %-10s x%d band %.2f %-15s → %-7s %s%s\n", $u['ref'], $u['size'], $u['band'], implode('/', $u['divisions']), $o['dest'], $o['reason'], $notes);
        }
    }
}

echo "\nplaced = % of free agents given a team; exist/new = people into existing / new teams; gap = |player band − team band| (mean, max)\n";
echo "asked = of people who asked a team, % placed on one they asked; nocap/noset = new teams without a volunteer captain / a setter\n";
echo "short = existing teams below the minimum, before → after; viol = rule breaks (must be 0); same = identical on a re-run and in another input order\n";
echo $problems ? "\nPROBLEMS: $problems rule breaks — see the ! lines above\n" : "\nINVARIANTS OK\n";
