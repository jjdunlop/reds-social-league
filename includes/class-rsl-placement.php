<?php
/**
 * Placement: once the team-building deadline passes, everyone still on the
 * free agent list is proposed a team; an admin reviews and adjusts the
 * proposal on one screen, then confirms it.
 *
 * The proposal (propose()) is pure — no database, no WordPress calls, no
 * randomness, the same answer every time — like RSL_Pairing, so it's tested
 * over hand-made pools (dev/tests/test-placement.php) and simulated over
 * pretend ones (dev/simulate-placement.php). In order:
 *
 *  1. Existing teams. Short teams (below the minimum) are filled first, with
 *     players within 2 bands of their level; then teams that opened spots,
 *     within 1.5 bands. Each time, the team with the most places left takes
 *     its cheapest unit — unless that unit asked another team that has a
 *     place for it, which takes it instead. Cost: the level gap, early vs
 *     late, "asked for this team" (and asked another team that could still
 *     take them), bringing a position the team lacks, weeks away (for teams
 *     that can't spare anyone), and keeping volunteer captains back for new
 *     teams when they'll be scarce in the division they'd play in.
 *  2. New teams, per division, from whoever's left: "either" players go
 *     where their level fits (or across, when that division can't make a
 *     team or has no court space), everyone's sorted by level and cut into
 *     teams of near-equal size (friend groups kept whole), then each team
 *     gets a volunteer captain (and a setter) swapped in where one can be —
 *     trading whole units when a team is all friend groups.
 *  3. Leftovers top up any team in their division to the maximum (within 2
 *     bands), "either" players can cross divisions, and anyone still left
 *     gets a plain reason.
 *
 * A unit is a friend group (never split) or one person. Units an admin moved
 * are locked: fixed first, and they take room.
 *
 * Around it: context() reads a season from the database, suggest() saves a
 * proposal as the draft (the proposed_* columns plus draft new teams in
 * RSL_Free_Agents::building()), move() and update_new() are the admin's
 * edits (checked against the draft version, so a stale tab is refused),
 * blockers() says what has to be fixed first, and confirm() makes it real —
 * safe to run twice. The four take turns on one lock per season. New-team
 * numbers are the lowest free ones, so a re-run gives the draft's teams
 * their numbers (and the names, levels and captains set on them) back.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RSL_Placement {

    /** Positions, in the order a "team needs a …" reason names them. */
    const POSITIONS = array('setter', 'middle', 'outside', 'opposite', 'libero');
    /** New teams sort after every real team (ties go to the oldest team). */
    const NEW_ORDER = 1000000000;

    // ------------------------------------------------------------------
    // Weights and words
    // ------------------------------------------------------------------

    /**
     * The trade-offs, in "bands of level gap" (1 band ≈ social comp vs club
     * volleyball), and the widest gaps allowed. Re-run
     * dev/simulate-placement.php before changing them.
     */
    public static function weights() {
        $w = self::default_weights();
        return function_exists('apply_filters') ? apply_filters('rsl_placement_weights', $w) : $w;
    }

    private static function default_weights() {
        return array(
            'gap' => 2.0,           // × the level gap between the unit and the team, in bands
            'time' => 1.0,          // wants early games and the team prefers late (or the reverse)
            'asked' => -1.5,        // the unit asked this team to take them (and costs as much elsewhere while that team can still take them)
            'position' => -0.5,     // brings a position the team lacks
            'away' => 0.25,         // per night missed of the first three, for a team at or below the minimum
            'keep_captain' => 1.0,  // a volunteer captain going to an existing team while new teams will need every volunteer
            'crowd' => 0.1,         // leftovers only: per player a team already has over the target (spreads the top-ups)
            'gap_short' => 2.0,     // widest level gap filling a short team, and topping up with leftovers
            'gap_fill' => 1.5,      // widest level gap filling open spots
        );
    }

    private static function setting_defaults() {
        return array('min_roster' => 7, 'target_roster' => 8, 'new_team_target' => 8, 'max_roster' => 10, 'capacity' => array(), 'used_refs' => array());
    }

    /** A draft new team's name until the admin names it: "Unranked New 1". */
    public static function placeholder($ref) {
        return (substr((string) $ref, 0, 1) === 'R' ? 'Ranked' : 'Unranked') . ' New ' . substr((string) $ref, 1);
    }

    public static function is_placeholder($name) {
        $name = trim((string) $name);
        return $name === '' || (bool) preg_match('/^(un)?ranked\s+new\s+\d+$/i', $name);
    }

    public static function division_of_ref($ref) {
        return substr((string) $ref, 0, 1) === 'R' ? 'ranked' : 'unranked';
    }

    /** A band (1–5) as a team level key, rounded: 1 new … 5 advanced. */
    public static function level_for_band($band) {
        $map = array(1 => 'new', 2 => 'social', 3 => 'solid', 4 => 'strong', 5 => 'advanced');
        return $map[max(1, min(5, (int) round((float) $band)))];
    }

    /** A team level key as a band, or null. */
    public static function band_for_level($level) {
        $map = array('new' => 1.0, 'social' => 2.0, 'solid' => 3.0, 'strong' => 4.0, 'advanced' => 5.0);
        return isset($map[$level]) ? $map[$level] : null;
    }

    // ------------------------------------------------------------------
    // The proposal (pure)
    // ------------------------------------------------------------------

    /**
     * Propose a place for every unit. No database, no WordPress, no
     * randomness: the same input always gives the same answer.
     *
     * $units: list of arrays — ref (unique), user_ids, lead_user_id, size,
     *   band (mean), members (each: user_id, band, captain_ok, veteran =
     *   played 3+ years), divisions (allowed: unranked/ranked; empty = the
     *   group's choices don't overlap), time (early/late/any), positions,
     *   away_early (nights missed of the first 3), captain_ok,
     *   captain_user_id, requested (team ids asked), declined (team ids that
     *   said no either way), created_at, locked ('' or a dest an admin chose).
     * $teams: list of arrays — id, name, division, n (members now), band,
     *   pref_time, lacks (positions), spots_open.
     * $settings: min_roster, target_roster, new_team_target, max_roster,
     *   gap_fill, gap_short, capacity (division => teams the courts can
     *   take, null = unknown), weights, used_refs (new-team refs already
     *   made into teams — never reused).
     *
     * Returns array(
     *   units => ref => array(dest 't:<id>'|'n:<ref>'|'none', reason, flags, gap, how),
     *   new_teams => ref => array(ref, name, division, est_level, band, pref_time,
     *                captain_user_id, volunteer, size, unit_refs, user_ids, flags, seeded),
     *   teams => team id => array(before, after, added),
     *   summary => counts (see summarise()).
     * )
     */
    public static function propose($units, $teams, $settings) {
        list($U, $T, $s) = self::prepare($units, $teams, $settings);
        $order = array_keys($U);
        $pos = array_flip($order);
        $assign = array();
        // New-team numbers already spoken for (made into teams, or a team an admin started); step 2 takes the lowest free ones,
        // so a re-run gives the draft's teams their numbers back (and the names, levels and captains set on them).
        $taken = array('unranked' => array(), 'ranked' => array());
        foreach ($s['used_refs'] as $used) {
            if (preg_match('/^([UR])(\d+)$/', (string) $used, $m)) {
                $taken[self::division_of_ref($m[1])][(int) $m[2]] = true;
            }
        }

        // Locked units (an admin's moves) are fixed first and take room.
        foreach ($order as $ref) {
            $dest = $U[$ref]['locked'];
            if ($dest === '') {
                continue;
            }
            if (preg_match('/^n:([UR])(\d+)$/', $dest, $m)) {
                $d = self::division_of_ref($m[1]);
                $taken[$d][(int) $m[2]] = true;
                if (!isset($T[$dest])) {
                    $T[$dest] = self::blank_team($m[1] . $m[2], $d, true);
                }
            }
            if (isset($T[$dest])) {
                $T[$dest]['n'] += $U[$ref]['size'];
                $T[$dest]['units'][] = $ref;
            }
            $assign[$ref] = array('dest' => $dest, 'how' => 'locked', 'asked' => false, 'pos' => '');
        }
        foreach ($T as $key => $t) {
            if ($t['new']) {
                $T[$key] = self::team_profile($t, $U, true);
            } else {
                foreach ($t['units'] as $ref) {
                    foreach ($U[$ref]['positions'] as $p) {
                        unset($T[$key]['lacks'][$p]);
                    }
                }
            }
        }
        uasort($T, array(__CLASS__, 'by_order'));

        // How many each team needs (to the minimum) and wants (to its target).
        foreach ($T as $key => $t) {
            $need = max(0, $s['min_roster'] - $t['n']);
            $want = $t['new'] ? $s['new_team_target'] - $t['n'] : min($t['spots'], $s['target_roster'] - $t['n']);
            $T[$key]['need'] = $need;
            $T[$key]['want'] = max($need, $want);
        }
        foreach ($order as $ref) {
            if (!isset($assign[$ref]) && !$U[$ref]['divisions']) {
                $assign[$ref] = array('dest' => 'none', 'how' => 'none', 'asked' => false, 'pos' => '');
            }
        }

        // Step 1: short teams, then open spots.
        $keep = self::keep_captains($U, $T, $assign, $order, $s);
        self::fill('A', $U, $T, $assign, $order, $s, $keep);
        self::fill('B', $U, $T, $assign, $order, $s, $keep);

        // Step 2: new teams from whoever's left.
        $home = self::form_new_teams($U, $T, $assign, $order, $pos, $s, $taken);

        // Step 3: leftovers top up any team with room; "either" units may cross.
        self::top_up($U, $T, $assign, $order, $s, $home);
        self::final_captain_pass($U, $T, $assign, $pos, $s);

        // Output.
        foreach ($T as $key => $t) {
            if ($t['new'] && $t['units']) {
                $T[$key] = self::team_profile($t, $U, false);
            }
        }
        $left = array('unranked' => 0, 'ranked' => 0);
        foreach ($order as $ref) {
            if (!isset($assign[$ref]) && $U[$ref]['divisions']) {
                $left[isset($home[$ref]) ? $home[$ref] : self::home($U[$ref])] += $U[$ref]['size'];
            }
        }
        $full = array();
        foreach (array('unranked', 'ranked') as $d) {
            $count = 0;
            foreach ($T as $t) {
                $count += $t['division'] === $d && ($t['new'] ? (bool) $t['units'] : true) ? 1 : 0;
            }
            $full[$d] = $s['capacity'][$d] !== null && $count >= $s['capacity'][$d];
        }
        $out = array('units' => array(), 'new_teams' => array(), 'teams' => array(), 'summary' => array());
        foreach ($order as $ref) {
            $u = $U[$ref];
            if (isset($assign[$ref]) && $assign[$ref]['dest'] !== 'none') {
                $a = $assign[$ref];
                $t = isset($T[$a['dest']]) ? $T[$a['dest']] : null;
                if (!$t) {
                    $out['units'][$ref] = array('dest' => $a['dest'], 'reason' => 'Moved by an admin (that team is no longer in the season)', 'flags' => array('locked', 'gone'), 'gap' => null, 'how' => 'locked');
                    continue;
                }
                $gap = round(abs($u['band'] - $t['band']), 2);
                $out['units'][$ref] = array('dest' => $a['dest'], 'reason' => self::reason($u, $t, $a), 'flags' => self::flags($u, $t, $a, $gap), 'gap' => $gap, 'how' => $a['how']);
            } elseif (isset($assign[$ref]) && $assign[$ref]['how'] === 'locked') {
                $out['units'][$ref] = array('dest' => 'none', 'reason' => 'Held back by an admin', 'flags' => array('locked'), 'gap' => null, 'how' => 'locked');
            } else {
                $h = isset($home[$ref]) ? $home[$ref] : ($u['divisions'] ? self::home($u) : 'unranked');
                list($why, $flags) = self::leftover_reason($u, $T, $s, $h, $left, $full);
                $out['units'][$ref] = array('dest' => 'none', 'reason' => $why, 'flags' => $flags, 'gap' => null, 'how' => 'none');
            }
        }
        foreach ($T as $key => $t) {
            if ($t['new']) {
                if ($t['units']) {
                    $out['new_teams'][$t['ref']] = self::draft_for($t, $U, $pos, $s);
                }
            } else {
                $out['teams'][$t['id']] = array('before' => $t['n0'], 'after' => $t['n'], 'added' => $t['n'] - $t['n0']);
            }
        }
        $out['summary'] = self::summarise($U, $T, $s, $out);
        return $out;
    }

    /** Fill in defaults and put units (oldest first) and teams (oldest first) in a fixed order. */
    private static function prepare($units, $teams, $settings) {
        $settings = is_array($settings) ? $settings : array();
        $s = array_merge(self::setting_defaults(), $settings);
        $s['weights'] = array_merge(self::default_weights(), isset($settings['weights']) && is_array($settings['weights']) ? $settings['weights'] : array());
        foreach (array('min_roster', 'target_roster', 'new_team_target', 'max_roster') as $k) {
            $s[$k] = max(1, intval($s[$k]));
        }
        $s['gap_fill'] = isset($settings['gap_fill']) ? (float) $settings['gap_fill'] : (float) $s['weights']['gap_fill'];
        $s['gap_short'] = isset($settings['gap_short']) ? (float) $settings['gap_short'] : (float) $s['weights']['gap_short'];
        $cap = array();
        foreach (array('unranked', 'ranked') as $d) {
            $v = is_array($s['capacity']) && isset($s['capacity'][$d]) ? $s['capacity'][$d] : null;
            $cap[$d] = $v === null || $v === '' ? null : max(0, intval($v));
        }
        $s['capacity'] = $cap;
        $s['used_refs'] = (array) $s['used_refs'];

        $U = array();
        $i = 0;
        foreach ((array) $units as $u) {
            $u = self::norm_unit($u, $i++);
            $U[$u['ref']] = $u;
        }
        uasort($U, function ($a, $b) {
            if ($a['created_at'] !== $b['created_at']) {
                return strcmp($a['created_at'], $b['created_at']);
            }
            if ($a['lead_user_id'] !== $b['lead_user_id']) {
                return $a['lead_user_id'] < $b['lead_user_id'] ? -1 : 1;
            }
            return strcmp($a['ref'], $b['ref']);
        });
        $T = array();
        foreach ((array) $teams as $t) {
            $t = self::norm_team($t);
            if ($t['id'] > 0) {
                $T[$t['key']] = $t;
            }
        }
        uasort($T, array(__CLASS__, 'by_order'));
        return array($U, $T, $s);
    }

    private static function by_order($a, $b) {
        return $a['order'] === $b['order'] ? 0 : ($a['order'] < $b['order'] ? -1 : 1);
    }

    private static function norm_unit($u, $i) {
        $u = (array) $u;
        $get = function ($k, $default) use ($u) {
            return isset($u[$k]) ? $u[$k] : $default;
        };
        $ids = array_values(array_unique(array_map('intval', (array) $get('user_ids', array()))));
        $band = max(1.0, min(5.0, (float) $get('band', 3.0)));
        $captain = intval($get('captain_user_id', 0));
        $members = array();
        foreach ((array) $get('members', array()) as $m) {
            $m = (array) $m;
            // An under-18 is never counted as (or picked as) a captain.
            $members[] = array(
                'user_id' => intval(isset($m['user_id']) ? $m['user_id'] : 0),
                'band' => isset($m['band']) ? max(1.0, min(5.0, (float) $m['band'])) : $band,
                'captain_ok' => !empty($m['captain_ok']) && empty($m['minor']),
                'veteran' => !empty($m['veteran']),
                'minor' => !empty($m['minor']),
            );
        }
        if (!$members) {
            foreach ($ids as $uid) {
                $members[] = array('user_id' => $uid, 'band' => $band, 'captain_ok' => false, 'veteran' => false);
            }
            if (!empty($u['captain_ok']) && $members) {
                $hit = false;
                foreach ($members as $k => $m) {
                    if ($captain && $m['user_id'] === $captain) {
                        $members[$k]['captain_ok'] = $hit = true;
                    }
                }
                if (!$hit) {
                    $members[0]['captain_ok'] = true;
                }
            }
        }
        if (!$ids) {
            foreach ($members as $m) {
                $ids[] = $m['user_id'];
            }
        }
        // With members given, a unit volunteers only through an adult member who does.
        $captain_ok = !empty($u['captain_ok']) && !array_filter($members, function ($m) { return !empty($m['minor']); });
        foreach ($members as $m) {
            $captain_ok = $captain_ok || $m['captain_ok'];
            if ($captain && $m['user_id'] === $captain && !empty($m['minor'])) {
                $captain = 0;
            }
        }
        $locked = (string) $get('locked', '');
        if ($locked !== 'none' && !preg_match('/^(t:\d+|n:[UR]\d+)$/', $locked)) {
            $locked = '';
        }
        return array(
            'ref' => (string) $get('ref', 'unit' . $i),
            'user_ids' => $ids,
            'lead_user_id' => intval($get('lead_user_id', $ids ? $ids[0] : 0)),
            'size' => isset($u['size']) ? max(1, intval($u['size'])) : max(1, count($ids)),
            'band' => $band,
            'members' => $members,
            'divisions' => array_values(array_intersect(array('unranked', 'ranked'), (array) $get('divisions', array()))),
            'time' => in_array($get('time', 'any'), array('early', 'late'), true) ? $u['time'] : 'any',
            'positions' => array_values(array_intersect(self::POSITIONS, (array) $get('positions', array()))),
            'away_early' => max(0, min(3, intval($get('away_early', 0)))),
            'captain_ok' => $captain_ok,
            'captain_user_id' => $captain,
            'requested' => array_values(array_unique(array_map('intval', (array) $get('requested', array())))),
            'declined' => array_values(array_unique(array_map('intval', (array) $get('declined', array())))),
            'created_at' => (string) $get('created_at', ''),
            'locked' => $locked,
        );
    }

    private static function norm_team($t) {
        $t = (array) $t;
        $id = intval(isset($t['id']) ? $t['id'] : 0);
        $n = max(0, intval(isset($t['n']) ? $t['n'] : 0));
        $lacks = array();
        foreach (array_intersect(self::POSITIONS, (array) (isset($t['lacks']) ? $t['lacks'] : array())) as $p) {
            $lacks[$p] = true;
        }
        return array(
            'key' => 't:' . $id, 'id' => $id, 'ref' => '', 'new' => false, 'seed' => false,
            'name' => isset($t['name']) ? (string) $t['name'] : 'Team ' . $id,
            'division' => isset($t['division']) && $t['division'] === 'unranked' ? 'unranked' : 'ranked',
            'n0' => $n, 'n' => $n,
            'band' => isset($t['band']) && $t['band'] !== null ? max(1.0, min(5.0, (float) $t['band'])) : 3.0,
            'time' => isset($t['pref_time']) && in_array($t['pref_time'], array('early', 'late'), true) ? $t['pref_time'] : 'any',
            'lacks' => $lacks,
            'spots' => max(0, intval(isset($t['spots_open']) ? $t['spots_open'] : 0)),
            'need' => 0, 'want' => 0, 'given' => 0,
            'order' => $id,
            'units' => array(),
        );
    }

    private static function blank_team($ref, $division, $seed) {
        return array(
            'key' => 'n:' . $ref, 'id' => 0, 'ref' => $ref, 'new' => true, 'seed' => $seed,
            'name' => self::placeholder($ref), 'division' => $division,
            'n0' => 0, 'n' => 0, 'band' => 3.0, 'time' => 'any', 'lacks' => array_fill_keys(self::POSITIONS, true),
            'spots' => 0, 'need' => 0, 'want' => 0, 'given' => 0,
            'order' => self::NEW_ORDER + ($division === 'ranked' ? 100000 : 0) + intval(substr($ref, 1)),
            'units' => array(),
        );
    }

    /** A new team's level, game time and missing positions from its members. */
    private static function team_profile($t, $U, $with_lacks) {
        $sum = 0.0;
        $n = 0;
        $times = array('early' => 0, 'late' => 0);
        $have = array();
        foreach ($t['units'] as $ref) {
            $u = $U[$ref];
            foreach ($u['members'] as $m) {
                $sum += $m['band'];
                $n++;
            }
            if ($u['time'] !== 'any') {
                $times[$u['time']] += $u['size'];
            }
            foreach ($u['positions'] as $p) {
                $have[$p] = true;
            }
        }
        if ($n) {
            $t['band'] = $sum / $n;
        }
        $t['time'] = $times['early'] > $times['late'] ? 'early' : ($times['late'] > $times['early'] ? 'late' : 'any');
        if ($with_lacks) {
            $t['lacks'] = array();
            foreach (self::POSITIONS as $p) {
                if (!isset($have[$p])) {
                    $t['lacks'][$p] = true;
                }
            }
        }
        return $t;
    }

    /** The division a unit is pointed at for new teams: its only one, or (either) by level. */
    private static function home($u) {
        if (count($u['divisions']) === 1) {
            return $u['divisions'][0];
        }
        return $u['band'] < 3 ? 'unranked' : 'ranked';
    }

    /** How many new teams N players make (0 below the minimum). */
    private static function team_count($n, $s) {
        if ($n < $s['min_roster']) {
            return 0;
        }
        return (int) min(floor($n / $s['min_roster']), max(round($n / $s['new_team_target']), ceil($n / $s['max_roster'])));
    }

    /**
     * The cost of unit $u joining team $t, or null when it's not allowed:
     * wrong division, no room, the team said no, or further apart in level
     * than $cap. Rounded so equal options tie exactly (ties: oldest unit).
     */
    private static function cost($u, $t, $cap, $s, $keep, $crowd = false) {
        if (!in_array($t['division'], $u['divisions'], true) || $u['size'] > $s['max_roster'] - $t['n']) {
            return null;
        }
        if ($t['id'] && in_array($t['id'], $u['declined'], true)) {
            return null;
        }
        $gap = abs($u['band'] - $t['band']);
        if ($gap > $cap + 1e-9) {
            return null;
        }
        $w = $s['weights'];
        $c = $w['gap'] * $gap;
        if ($u['time'] !== 'any' && $t['time'] !== 'any' && $u['time'] !== $t['time']) {
            $c += $w['time'];
        }
        if ($t['id'] && in_array($t['id'], $u['requested'], true)) {
            $c += $w['asked'];
        }
        if (array_intersect($u['positions'], array_keys($t['lacks']))) {
            $c += $w['position'];
        }
        if ($t['n'] <= $s['min_roster']) {
            $c += $w['away'] * $u['away_early'];
        }
        // Judged by where the volunteer would make a new team (an Either volunteer can be scarce in Unranked yet fill a Ranked spot).
        if ($u['captain_ok'] && !$t['new'] && !empty($keep[self::home($u)])) {
            $c += $w['keep_captain'];
        }
        if ($crowd) {
            $c += $w['crowd'] * max(0, $t['n'] - $s['target_roster']);
        }
        return round($c, 6);
    }

    /**
     * Has this unit asked another team that could still take it? ($open:
     * team key => the widest level gap that team is taking.) Then going
     * anywhere else costs as much as the "asked" bonus — so the team picking
     * first doesn't take someone who wanted a different team.
     */
    private static function asked_elsewhere($u, $t, $T, $open, $s) {
        if (!$u['requested'] || ($t['id'] && in_array($t['id'], $u['requested'], true))) {
            return false;
        }
        foreach ($u['requested'] as $id) {
            $key = 't:' . $id;
            if (isset($open[$key], $T[$key]) && self::cost($u, $T[$key], $open[$key], $s, array()) !== null) {
                return true;
            }
        }
        return false;
    }

    /** Volunteer captains are scarce: keep them for new teams when there won't be enough. */
    private static function keep_captains($U, $T, $assign, $order, $s) {
        $players = array('unranked' => 0, 'ranked' => 0);
        $vols = array('unranked' => 0, 'ranked' => 0);
        foreach ($order as $ref) {
            if (isset($assign[$ref])) {
                continue;
            }
            $d = self::home($U[$ref]);
            $players[$d] += $U[$ref]['size'];
            $vols[$d] += $U[$ref]['captain_ok'] ? 1 : 0;
        }
        $wanted = array('unranked' => 0, 'ranked' => 0);
        foreach ($T as $t) {
            if (!$t['new']) {
                $wanted[$t['division']] += max(0, min($t['want'], $s['max_roster'] - $t['n']));
            }
        }
        $keep = array();
        foreach ($players as $d => $n) {
            $k = self::team_count(max(0, $n - $wanted[$d]), $s);
            $keep[$d] = $k > 0 && $vols[$d] <= $k;
        }
        return $keep;
    }

    /**
     * Step 1, one pass: A fills short teams to the minimum (gap ≤ gap_short),
     * B fills open spots to the target (gap ≤ gap_fill). Each round the team
     * with the most places left (ties: fewest units it could take, then the
     * oldest team) takes its cheapest unit — unless that unit asked another
     * team with a place for it in this pass, which then takes it instead. A
     * group may go one over what the team wants, never over the maximum.
     */
    private static function fill($pass, $U, &$T, &$assign, $order, $s, $keep) {
        $cap = $pass === 'A' ? $s['gap_short'] : $s['gap_fill'];
        $w = $s['weights'];
        while (true) {
            $open = array();
            $asking = array(); // teams a unit may have asked that can still take it: this pass's, plus (in A) open spots still to fill in B
            foreach ($T as $key => $t) {
                if ($s['max_roster'] - $t['n'] <= 0) {
                    continue;
                }
                $left = ($pass === 'A' ? $t['need'] : $t['want']) - $t['given'];
                if ($left > 0) {
                    $open[$key] = $left;
                    $asking[$key] = $cap;
                } elseif ($t['want'] - $t['given'] > 0) {
                    $asking[$key] = $s['gap_fill'];
                }
            }
            $pick = null;
            foreach ($open as $key => $left) {
                $t = $T[$key];
                $room = min($s['max_roster'] - $t['n'], $left + 1);
                $best = null;
                $eligible = 0;
                foreach ($order as $ref) {
                    if (isset($assign[$ref]) || $U[$ref]['size'] > $room) {
                        continue;
                    }
                    $c = self::cost($U[$ref], $t, $cap, $s, $keep);
                    if ($c === null) {
                        continue;
                    }
                    if (self::asked_elsewhere($U[$ref], $t, $T, $asking, $s)) {
                        $c = round($c - $w['asked'], 6);
                    }
                    $eligible++;
                    if ($best === null || $c < $best[0]) {
                        $best = array($c, $ref);
                    }
                }
                if ($best === null) {
                    continue;
                }
                if ($pick === null || $left > $pick[0] || ($left === $pick[0] && ($eligible < $pick[1] || ($eligible === $pick[1] && $t['order'] < $pick[2])))) {
                    $pick = array($left, $eligible, $t['order'], $key, $best[1]);
                }
            }
            if ($pick === null) {
                return;
            }
            $ref = $pick[4];
            $key = $pick[3];
            $u = $U[$ref];
            if ($u['requested'] && !in_array($T[$key]['id'], $u['requested'], true)) {
                // They asked another team that has a place for them in this pass: it takes them (cheapest, then most places left, then oldest).
                $alt = null;
                foreach ($u['requested'] as $id) {
                    $k2 = 't:' . $id;
                    if (!isset($open[$k2]) || $u['size'] > min($s['max_roster'] - $T[$k2]['n'], $open[$k2] + 1)) {
                        continue;
                    }
                    $c = self::cost($u, $T[$k2], $cap, $s, $keep);
                    if ($c === null) {
                        continue;
                    }
                    $rank = array($c, -$open[$k2], $T[$k2]['order']);
                    if ($alt === null || self::lex_less($rank, $alt[0])) {
                        $alt = array($rank, $k2);
                    }
                }
                if ($alt !== null) {
                    $key = $alt[1];
                }
            }
            self::put($ref, $key, $pass === 'A' ? 'short' : 'spots', $U, $T, $assign, true);
        }
    }

    /** Put a unit on a team, noting whether it asked for it and the position it fills. */
    private static function put($ref, $key, $how, $U, &$T, &$assign, $count_given) {
        $u = $U[$ref];
        $asked = $T[$key]['id'] && in_array($T[$key]['id'], $u['requested'], true);
        $fills = '';
        foreach (self::POSITIONS as $p) {
            if (isset($T[$key]['lacks'][$p]) && in_array($p, $u['positions'], true)) {
                $fills = $p;
                break;
            }
        }
        $assign[$ref] = array('dest' => $key, 'how' => $how, 'asked' => $asked, 'pos' => $fills);
        $T[$key]['n'] += $u['size'];
        if ($count_given) {
            $T[$key]['given'] += $u['size'];
        }
        foreach ($u['positions'] as $p) {
            unset($T[$key]['lacks'][$p]);
        }
        $T[$key]['units'][] = $ref;
    }

    /**
     * Step 2: new teams from the units nobody took. Returns each remaining
     * unit's division for new teams (its "home"), for step 3 and the reasons.
     */
    private static function form_new_teams($U, &$T, &$assign, $order, $pos, $s, &$taken) {
        // (a) Single-division units stay put; "either" units go by level, and
        // move across if their division can't make a team on its own or has
        // no court space for another (and the other division does).
        $home = array();
        $count = array('unranked' => 0, 'ranked' => 0);
        foreach ($order as $ref) {
            if (isset($assign[$ref])) {
                continue;
            }
            $home[$ref] = self::home($U[$ref]);
            $count[$home[$ref]] += $U[$ref]['size'];
        }
        $room = array(); // new teams each division's courts can still take (null = not checked)
        foreach (array('unranked', 'ranked') as $d) {
            $room[$d] = null;
            if ($s['capacity'][$d] !== null) {
                $have = 0;
                foreach ($T as $t) {
                    $have += $t['division'] === $d ? 1 : 0;
                }
                $room[$d] = max(0, $s['capacity'][$d] - $have);
            }
        }
        $moved = array();
        foreach (array('ranked' => 'unranked', 'unranked' => 'ranked') as $d => $other) {
            if ($count[$d] > 0 && ($count[$d] < $s['min_roster'] || $room[$d] === 0) && $room[$other] !== 0) {
                foreach ($home as $ref => $h) {
                    if ($h === $d && count($U[$ref]['divisions']) === 2 && empty($moved[$ref])) {
                        $home[$ref] = $other;
                        $moved[$ref] = true;
                        $count[$d] -= $U[$ref]['size'];
                        $count[$other] += $U[$ref]['size'];
                    }
                }
            }
        }
        foreach (array('unranked', 'ranked') as $d) {
            $refs = array();
            foreach ($home as $ref => $h) {
                if ($h === $d) {
                    $refs[] = $ref;
                }
            }
            // (b) How many teams: as near new_team_target as the minimum and maximum allow, within court space.
            $k = self::team_count($count[$d], $s);
            if ($room[$d] !== null) {
                $k = min($k, $room[$d]);
            }
            if ($k < 1 || !$refs) {
                continue;
            }
            // (c) Cut by level; (d) a volunteer captain each; (e) a setter each if possible.
            $chunks = self::cut($refs, $U, $k, $s);
            $chunks = self::fit_sizes($chunks, $U, $pos, $s);
            self::captain_swaps($chunks, $U, $pos, $s);
            self::setter_swaps($chunks, $U, $pos);
            // (f) Draft teams U1, U2 … / R1 … — the lowest numbers not already taken.
            foreach ($chunks as $chunk) {
                $n = 1;
                while (isset($taken[$d][$n])) {
                    $n++;
                }
                $taken[$d][$n] = true;
                $ref = ($d === 'ranked' ? 'R' : 'U') . $n;
                $key = 'n:' . $ref;
                $T[$key] = self::blank_team($ref, $d, false);
                foreach ($chunk as $uref) {
                    $assign[$uref] = array('dest' => $key, 'how' => 'new', 'asked' => false, 'pos' => '');
                    $T[$key]['units'][] = $uref;
                    $T[$key]['n'] += $U[$uref]['size'];
                }
                $T[$key] = self::team_profile($T[$key], $U, true);
            }
        }
        return $home;
    }

    private static function heads($refs, $U) {
        $n = 0;
        foreach ($refs as $r) {
            $n += $U[$r]['size'];
        }
        return $n;
    }

    private static function mean_band($refs, $U) {
        $sum = 0.0;
        $n = 0;
        foreach ($refs as $r) {
            foreach ($U[$r]['members'] as $m) {
                $sum += $m['band'];
                $n++;
            }
        }
        return $n ? $sum / $n : 3.0;
    }

    private static function unit_volunteers($u) {
        $n = 0;
        foreach ($u['members'] as $m) {
            $n += $m['captain_ok'] ? 1 : 0;
        }
        return $n;
    }

    private static function volunteers($refs, $U) {
        $n = 0;
        foreach ($refs as $r) {
            $n += self::unit_volunteers($U[$r]);
        }
        return $n;
    }

    private static function setters($refs, $U) {
        $n = 0;
        foreach ($refs as $r) {
            $n += in_array('setter', $U[$r]['positions'], true) ? 1 : 0;
        }
        return $n;
    }

    /** Is tuple $a before tuple $b? (Numbers, compared left to right.) */
    private static function lex_less($a, $b) {
        foreach ($a as $i => $x) {
            if ($x != $b[$i]) {
                return $x < $b[$i];
            }
        }
        return false;
    }

    private static function without($list, $ref) {
        return array_values(array_diff($list, array($ref)));
    }

    /**
     * (c) Sort by level (high first), then game time, then sign-up, and cut
     * into $k runs of near-equal head-count. A group that would overflow a
     * team starts the next one and later units are pulled back to fill the
     * gap (one over is allowed). Then neighbours swap a single while their
     * sizes differ by more than one.
     */
    private static function cut($refs, $U, $k, $s) {
        $rank = array('early' => 0, 'any' => 1, 'late' => 2);
        usort($refs, function ($a, $b) use ($U, $rank) {
            $x = $U[$a];
            $y = $U[$b];
            if ($x['band'] != $y['band']) {
                return $x['band'] > $y['band'] ? -1 : 1;
            }
            if ($rank[$x['time']] !== $rank[$y['time']]) {
                return $rank[$x['time']] - $rank[$y['time']];
            }
            if ($x['created_at'] !== $y['created_at']) {
                return strcmp($x['created_at'], $y['created_at']);
            }
            if ($x['lead_user_id'] !== $y['lead_user_id']) {
                return $x['lead_user_id'] < $y['lead_user_id'] ? -1 : 1;
            }
            return strcmp($a, $b);
        });
        $total = self::heads($refs, $U);
        $target = array();
        for ($i = 0; $i < $k; $i++) {
            $target[$i] = intdiv($total, $k) + ($i < $total % $k ? 1 : 0);
        }
        $chunks = array_fill(0, $k, array());
        $queue = $refs;
        $i = 0;
        while ($queue) {
            if ($i >= $k - 1) {
                $chunks[$k - 1] = array_merge($chunks[$k - 1], $queue);
                break;
            }
            $space = $target[$i] - self::heads($chunks[$i], $U);
            $first = $queue[0];
            if ($U[$first]['size'] <= $space) {
                $chunks[$i][] = array_shift($queue);
                if ($U[$first]['size'] === $space) {
                    $i++;
                }
                continue;
            }
            foreach ($queue as $j => $r) {
                if ($space <= 0) {
                    break;
                }
                if ($j > 0 && $U[$r]['size'] <= $space) {
                    $chunks[$i][] = $r;
                    $space -= $U[$r]['size'];
                    unset($queue[$j]);
                }
            }
            $queue = array_values($queue);
            if ($space > 0 && $U[$first]['size'] - $space <= 1) {
                $chunks[$i][] = array_shift($queue);
            }
            $i++;
        }
        for ($round = 0; $round < 2 * $k; $round++) {
            $moved = false;
            for ($i = 0; $i < $k - 1; $i++) {
                $a = self::heads($chunks[$i], $U);
                $b = self::heads($chunks[$i + 1], $U);
                if ($a - $b > 1 && ($r = self::edge_single($chunks[$i], $U, true)) !== null) {
                    $chunks[$i] = self::without($chunks[$i], $r);
                    array_unshift($chunks[$i + 1], $r);
                    $moved = true;
                } elseif ($b - $a > 1 && ($r = self::edge_single($chunks[$i + 1], $U, false)) !== null) {
                    $chunks[$i + 1] = self::without($chunks[$i + 1], $r);
                    $chunks[$i][] = $r;
                    $moved = true;
                }
            }
            if (!$moved) {
                break;
            }
        }
        return $chunks;
    }

    /** The last (or first) single in a run — the one nearest the neighbouring run in level. */
    private static function edge_single($chunk, $U, $last) {
        $list = $last ? array_reverse($chunk) : $chunk;
        foreach ($list as $r) {
            if ($U[$r]['size'] === 1) {
                return $r;
            }
        }
        return null;
    }

    /**
     * New teams must have min–max players. Too big: drop the unit that best
     * removes the excess, furthest from the team's level (never its only
     * volunteer). Too small: borrow singles from a neighbour that can spare
     * them, else the run breaks up and its units are left for step 3.
     */
    private static function fit_sizes($chunks, $U, $pos, $s) {
        foreach (array_keys($chunks) as $i) {
            while (self::heads($chunks[$i], $U) > $s['max_roster']) {
                $excess = self::heads($chunks[$i], $U) - $s['max_roster'];
                $mean = self::mean_band($chunks[$i], $U);
                $vols = self::volunteers($chunks[$i], $U);
                $best = null;
                foreach ($chunks[$i] as $r) {
                    $u = $U[$r];
                    $uv = self::unit_volunteers($u);
                    if ($uv > 0 && $vols - $uv < 1) {
                        continue;
                    }
                    $whole = $u['size'] >= $excess;
                    $key = array($whole ? 0 : 1, $whole ? $u['size'] : -$u['size'], -abs($u['band'] - $mean), -$pos[$r]);
                    if ($best === null || self::lex_less($key, $best[0])) {
                        $best = array($key, $r);
                    }
                }
                if ($best === null) {
                    break;
                }
                $chunks[$i] = self::without($chunks[$i], $best[1]);
            }
        }
        foreach (array_keys($chunks) as $i) {
            while (self::heads($chunks[$i], $U) < $s['min_roster']) {
                $mean = self::mean_band($chunks[$i], $U);
                $best = null;
                foreach (array($i - 1, $i + 1) as $j) {
                    if (!isset($chunks[$j]) || self::heads($chunks[$j], $U) <= $s['min_roster']) {
                        continue;
                    }
                    $vols = self::volunteers($chunks[$j], $U);
                    foreach ($chunks[$j] as $r) {
                        if ($U[$r]['size'] !== 1 || ($U[$r]['captain_ok'] && $vols < 2)) {
                            continue;
                        }
                        $key = array(abs($U[$r]['band'] - $mean), $pos[$r]);
                        if ($best === null || self::lex_less($key, $best[0])) {
                            $best = array($key, $j, $r);
                        }
                    }
                }
                if ($best === null) {
                    break;
                }
                $chunks[$best[1]] = self::without($chunks[$best[1]], $best[2]);
                $chunks[$i][] = $best[2];
            }
        }
        $out = array();
        foreach ($chunks as $c) {
            $n = self::heads($c, $U);
            if ($n >= $s['min_roster'] && $n <= $s['max_roster']) {
                $out[] = $c;
            }
        }
        return $out;
    }

    /**
     * (d) A team with no volunteer captain swaps its single closest in level
     * for a volunteer single from a team that has two or more (nearest team
     * first). With no single to swap, the volunteer moves over if both teams
     * stay within the limits; failing that, whole units are traded
     * (captain_exchange()). Repeated until nothing changes — a trade can open
     * up one for a team already passed over — so afterwards no team without
     * a volunteer could get one from a team with two (what violations() checks).
     * Each trade leaves one team fewer without a volunteer, so it ends.
     */
    private static function captain_swaps(&$chunks, $U, $pos, $s) {
        $k = count($chunks);
        do {
            $changed = false;
            for ($i = 0; $i < $k; $i++) {
                if (self::volunteers($chunks[$i], $U) > 0) {
                    continue;
                }
                if (self::captain_swap($chunks, $i, $U, $pos, $s) || self::captain_exchange($chunks, $i, $U, $pos)) {
                    $changed = true;
                }
            }
        } while ($changed);
    }

    /** One team ($i) with no volunteer: a volunteer single swapped in for its closest single, or moved over. */
    private static function captain_swap(&$chunks, $i, $U, $pos, $s) {
        $mean = self::mean_band($chunks[$i], $U);
        $candidates = array();
        foreach (array_keys($chunks) as $j) {
            if ($j === $i || self::volunteers($chunks[$j], $U) < 2) {
                continue;
            }
            foreach ($chunks[$j] as $c) {
                if ($U[$c]['size'] === 1 && self::unit_volunteers($U[$c]) > 0) {
                    $candidates[] = array(array(abs($j - $i), abs($U[$c]['band'] - $mean), $pos[$c]), $j, $c);
                }
            }
        }
        usort($candidates, function ($a, $b) {
            return self::lex_less($a[0], $b[0]) ? -1 : (self::lex_less($b[0], $a[0]) ? 1 : 0);
        });
        foreach ($candidates as $cand) {
            list(, $j, $c) = $cand;
            $partner = null;
            foreach ($chunks[$i] as $p) {
                if ($U[$p]['size'] !== 1) {
                    continue;
                }
                $key = array(abs($U[$p]['band'] - $U[$c]['band']), $pos[$p]);
                if ($partner === null || self::lex_less($key, $partner[0])) {
                    $partner = array($key, $p);
                }
            }
            if ($partner) {
                $chunks[$j] = self::without($chunks[$j], $c);
                $chunks[$j][] = $partner[1];
                $chunks[$i] = self::without($chunks[$i], $partner[1]);
                $chunks[$i][] = $c;
                return true;
            }
            if (self::heads($chunks[$i], $U) < $s['max_roster'] && self::heads($chunks[$j], $U) - 1 >= $s['min_roster']) {
                $chunks[$j] = self::without($chunks[$j], $c);
                $chunks[$i][] = $c;
                return true;
            }
        }
        return false;
    }

    /**
     * A team with no volunteer and no single to swap (it's all friend
     * groups, or the volunteers elsewhere are in groups): trade one of its
     * units for whole units of the same head-count from a team with two or
     * more volunteers — including a volunteer, and leaving that team one.
     * Sizes don't change and no group is split. Nearest team first, then the
     * fewest units moved, then closest in level, volunteers spared.
     */
    private static function captain_exchange(&$chunks, $i, $U, $pos) {
        $best = null;
        foreach (array_keys($chunks) as $j) {
            $vols = self::volunteers($chunks[$j], $U);
            if ($j === $i || $vols < 2) {
                continue;
            }
            foreach ($chunks[$i] as $p) {
                foreach (self::same_heads(array_values($chunks[$j]), $U, $U[$p]['size'], 0) as $q) {
                    $qv = self::volunteers($q, $U);
                    if ($qv < 1 || $vols - $qv < 1) {
                        continue;
                    }
                    $key = array(abs($j - $i), count($q), abs($U[$p]['band'] - self::mean_band($q, $U)), $qv, $pos[$p]);
                    if ($best === null || self::lex_less($key, $best[0])) {
                        $best = array($key, $j, $q, array($p));
                    }
                }
            }
        }
        if ($best === null) {
            return false;
        }
        list(, $j, $from_j, $from_i) = $best;
        $chunks[$j] = array_values(array_merge(array_diff($chunks[$j], $from_j), $from_i));
        $chunks[$i] = array_values(array_merge(array_diff($chunks[$i], $from_i), $from_j));
        return true;
    }

    /** Every set of whole units (in list order) from $refs[$from …] with exactly $heads people. Groups are small, so these are few. */
    private static function same_heads($refs, $U, $heads, $from) {
        $out = array();
        for ($x = $from, $n = count($refs); $x < $n; $x++) {
            $size = $U[$refs[$x]]['size'];
            if ($size === $heads) {
                $out[] = array($refs[$x]);
            } elseif ($size < $heads) {
                foreach (self::same_heads($refs, $U, $heads - $size, $x + 1) as $rest) {
                    $out[] = array_merge(array($refs[$x]), $rest);
                }
            }
        }
        return $out;
    }

    /**
     * (e) Soft: a team with no setter swaps one in from a neighbouring team
     * that has two, for its closest non-setter single. Captains and groups
     * never move.
     */
    private static function setter_swaps(&$chunks, $U, $pos) {
        $k = count($chunks);
        for ($i = 0; $i < $k; $i++) {
            if (self::setters($chunks[$i], $U) > 0) {
                continue;
            }
            $done = false;
            foreach (array($i - 1, $i + 1) as $j) {
                if ($done || !isset($chunks[$j]) || self::setters($chunks[$j], $U) < 2) {
                    continue;
                }
                $best = null;
                foreach ($chunks[$j] as $c) {
                    $u = $U[$c];
                    if ($u['size'] !== 1 || $u['captain_ok'] || !in_array('setter', $u['positions'], true)) {
                        continue;
                    }
                    foreach ($chunks[$i] as $p) {
                        $v = $U[$p];
                        if ($v['size'] !== 1 || $v['captain_ok'] || in_array('setter', $v['positions'], true)) {
                            continue;
                        }
                        $key = array(abs($u['band'] - $v['band']), $pos[$c], $pos[$p]);
                        if ($best === null || self::lex_less($key, $best[0])) {
                            $best = array($key, $c, $p);
                        }
                    }
                }
                if ($best) {
                    $chunks[$j] = self::without($chunks[$j], $best[1]);
                    $chunks[$j][] = $best[2];
                    $chunks[$i] = self::without($chunks[$i], $best[2]);
                    $chunks[$i][] = $best[1];
                    $done = true;
                }
            }
        }
    }

    /**
     * Step 3: leftovers join the cheapest team with room — first in their
     * own division, then (either units) the other one. Up to the maximum,
     * within gap_short.
     */
    private static function top_up($U, &$T, &$assign, $order, $s, $home) {
        foreach (array(true, false) as $home_only) {
            while (true) {
                $best = null;
                $open = array();
                foreach ($T as $key => $t) {
                    if ($s['max_roster'] - $t['n'] > 0 && ($t['new'] ? (bool) $t['units'] : true)) {
                        $open[$key] = $s['gap_short'];
                    }
                }
                foreach ($order as $ref) {
                    if (isset($assign[$ref])) {
                        continue;
                    }
                    $u = $U[$ref];
                    $h = isset($home[$ref]) ? $home[$ref] : self::home($u);
                    foreach ($open as $key => $yes) {
                        $t = $T[$key];
                        if ($home_only && $t['division'] !== $h) {
                            continue;
                        }
                        $c = self::cost($u, $t, $s['gap_short'], $s, array(), true);
                        if ($c === null) {
                            continue;
                        }
                        if (self::asked_elsewhere($u, $t, $T, $open, $s)) {
                            $c = round($c - $s['weights']['asked'], 6);
                        }
                        if ($best === null || $c < $best[0]) {
                            $best = array($c, $ref, $key);
                        }
                    }
                }
                if ($best === null) {
                    break;
                }
                self::put($best[1], $best[2], $home_only ? 'topup' : 'cross', $U, $T, $assign, false);
            }
        }
    }

    /** Top-ups can land a second volunteer on one new team while another has none (or open the room to move one): once more. */
    private static function final_captain_pass($U, &$T, &$assign, $pos, $s) {
        foreach (array('unranked', 'ranked') as $d) {
            $keys = array();
            $chunks = array();
            foreach ($T as $key => $t) {
                if ($t['new'] && !$t['seed'] && $t['division'] === $d && $t['units']) {
                    $keys[] = $key;
                    $chunks[] = $t['units'];
                }
            }
            if (count($chunks) < 2) {
                continue;
            }
            self::captain_swaps($chunks, $U, $pos, $s);
            foreach ($keys as $i => $key) {
                $T[$key]['units'] = $chunks[$i];
                $T[$key]['n'] = self::heads($chunks[$i], $U); // a volunteer may have moved over (both teams stay within min–max)
                foreach ($chunks[$i] as $ref) {
                    $assign[$ref]['dest'] = $key;
                }
            }
        }
    }

    /** Why a unit wasn't placed, in plain words, and flags. */
    private static function leftover_reason($u, $T, $s, $home, $left, $full) {
        if (!$u['divisions']) {
            return array('Friend group picked divisions that don\'t overlap', array('no_division'));
        }
        $any = false;
        $whole = false;
        $declined = false;
        foreach ($T as $t) {
            if (!in_array($t['division'], $u['divisions'], true) || ($t['new'] && !$t['units'])) {
                continue;
            }
            $room = $s['max_roster'] - $t['n'];
            $any = $any || $room >= 1;
            if ($room >= $u['size']) {
                if ($t['id'] && in_array($t['id'], $u['declined'], true)) {
                    $declined = true;
                } else {
                    $whole = true;
                }
            }
        }
        $label = $home === 'ranked' ? 'Ranked' : 'Unranked';
        if ($whole) {
            return array('Nobody within range in your division', array('range'));
        }
        if ($declined) {
            return array('The teams with room have said no this season', array('declined'));
        }
        if ($u['size'] > 1 && $any) {
            return array('Group of ' . $u['size'] . ' doesn\'t fit together anywhere', array('group'));
        }
        $n = $left[$home];
        $players = $n . ' ' . $label . ' player' . ($n === 1 ? '' : 's') . ' left';
        if ($full[$home]) {
            return array($players . ' — no court space for another ' . $label . ' team; ' . $label . ' teams full', array('capacity'));
        }
        if ($n < $s['min_roster']) {
            return array($players . ' — ' . ($s['min_roster'] - $n) . ' short of a new team; ' . $label . ' teams full', array('short'));
        }
        return array($label . ' teams full', array('full'));
    }

    /** "Team was short · Level fit · wants early · team needs a setter · asked for this team". */
    private static function reason($u, $t, $a) {
        if ($a['how'] === 'locked') {
            return 'Moved by an admin';
        }
        $parts = array();
        $first = array('short' => 'Team was short', 'spots' => 'Team asked for players', 'new' => 'New team', 'topup' => 'Topped up a team with room',
            'cross' => ($t['division'] === 'ranked' ? 'Ranked' : 'Unranked') . ' (happy to play either)');
        if (isset($first[$a['how']])) {
            $parts[] = $first[$a['how']];
        }
        $gap = abs($u['band'] - $t['band']);
        $parts[] = $gap <= 0.5 + 1e-9 ? 'Level fit' : ($gap <= 1.0 + 1e-9 ? 'Close level' : 'Level gap ' . number_format($gap, 1));
        if ($u['time'] !== 'any') {
            if ($t['time'] === $u['time']) {
                $parts[] = 'wants ' . $u['time'];
            } elseif ($t['time'] !== 'any') {
                $parts[] = 'wants ' . $u['time'] . ', team prefers ' . $t['time'];
            }
        }
        if ($a['pos'] !== '') {
            $parts[] = 'team needs a' . (in_array($a['pos'], array('outside', 'opposite'), true) ? 'n ' : ' ') . $a['pos'];
        }
        if ($a['asked']) {
            $parts[] = 'asked for this team';
        }
        if ($u['size'] > 1) {
            $parts[] = 'with ' . ($u['size'] - 1) . ' friend' . ($u['size'] > 2 ? 's' : '');
        }
        if ($u['away_early'] >= 2) {
            $parts[] = 'away ' . $u['away_early'] . ' of the first 3 nights';
        }
        return implode(' · ', $parts);
    }

    private static function flags($u, $t, $a, $gap) {
        $flags = array();
        if ($a['how'] === 'locked') {
            $flags[] = 'locked';
        }
        if ($gap > 1.0 + 1e-9) {
            $flags[] = 'gap';
        }
        if ($u['time'] !== 'any' && $t['time'] !== 'any' && $u['time'] !== $t['time']) {
            $flags[] = 'time';
        }
        if ($u['away_early'] >= 2) {
            $flags[] = 'away';
        }
        if ($a['asked']) {
            $flags[] = 'asked';
        }
        if ($a['how'] === 'cross') {
            $flags[] = 'cross';
        }
        return $flags;
    }

    /**
     * A new team's draft: placeholder name, level from its members, the
     * volunteer with the highest band as captain (ties: earliest sign-up).
     * With no volunteer, the most experienced member (3+ years, then
     * highest band) is suggested and the team is flagged.
     */
    private static function draft_for($t, $U, $pos, $s) {
        $members = array();
        $ids = array();
        foreach ($t['units'] as $ref) {
            foreach ($U[$ref]['members'] as $m) {
                $m['pos'] = $pos[$ref];
                $members[] = $m;
                $ids[] = $m['user_id'];
            }
        }
        $pick = function ($test) use ($members) {
            $best = null;
            foreach ($members as $m) {
                if (!$test($m)) {
                    continue;
                }
                if ($best === null || $m['band'] > $best['band'] || ($m['band'] == $best['band'] && ($m['pos'] < $best['pos'] || ($m['pos'] === $best['pos'] && $m['user_id'] < $best['user_id'])))) {
                    $best = $m;
                }
            }
            return $best;
        };
        $volunteer = $pick(function ($m) { return $m['captain_ok'] && empty($m['minor']); });
        $captain = $volunteer ? $volunteer : $pick(function ($m) { return $m['veteran'] && empty($m['minor']); });
        $captain = $captain ? $captain : $pick(function ($m) { return empty($m['minor']); });
        $flags = array();
        if (!$volunteer) {
            $flags[] = 'needs_captain';
        }
        if (self::setters($t['units'], $U) === 0) {
            $flags[] = 'no_setter';
        }
        if ($t['n'] < $s['min_roster']) {
            $flags[] = 'small';
        }
        if ($t['n'] > $s['max_roster']) {
            $flags[] = 'over';
        }
        return array(
            'ref' => $t['ref'],
            'name' => self::placeholder($t['ref']),
            'division' => $t['division'],
            'est_level' => self::level_for_band($t['band']),
            'band' => round($t['band'], 2),
            'pref_time' => $t['time'],
            'captain_user_id' => $captain ? $captain['user_id'] : 0,
            'volunteer' => (bool) $volunteer,
            'size' => $t['n'],
            'unit_refs' => $t['units'],
            'user_ids' => $ids,
            'flags' => $flags,
            'seeded' => $t['seed'],
        );
    }

    /** The numbers the admin screen and the simulator report. */
    private static function summarise($U, $T, $s, $out) {
        $sum = array(
            'units' => count($U), 'players' => 0, 'locked' => 0,
            'placed' => 0, 'placed_existing' => 0, 'teams_filled' => 0, 'placed_new' => 0, 'new_teams' => count($out['new_teams']), 'unplaced' => 0,
            'short_before' => 0, 'short_after' => 0, 'gap_mean' => 0.0, 'gap_max' => 0.0,
            'asked_players' => 0, 'asked_got' => 0, 'new_no_captain' => 0, 'new_no_setter' => 0,
            'by_division' => array('unranked' => array('placed' => 0, 'new_teams' => 0, 'unplaced' => 0), 'ranked' => array('placed' => 0, 'new_teams' => 0, 'unplaced' => 0)),
        );
        $gap_total = 0.0;
        $filled = array();
        foreach ($U as $ref => $u) {
            $o = $out['units'][$ref];
            $sum['players'] += $u['size'];
            if ($u['locked'] !== '') {
                $sum['locked'] += $u['size'];
            }
            if ($u['requested']) {
                $sum['asked_players'] += $u['size'];
            }
            if ($o['dest'] === 'none' || !isset($T[$o['dest']])) {
                $sum['unplaced'] += $o['dest'] === 'none' ? $u['size'] : 0;
                if ($o['dest'] === 'none') {
                    $sum['by_division'][$u['divisions'] ? self::home($u) : 'unranked']['unplaced'] += $u['size'];
                }
                continue;
            }
            $t = $T[$o['dest']];
            $sum['placed'] += $u['size'];
            $sum['by_division'][$t['division']]['placed'] += $u['size'];
            if ($t['new']) {
                $sum['placed_new'] += $u['size'];
            } else {
                $sum['placed_existing'] += $u['size'];
                $filled[$t['id']] = true;
                if (in_array($t['id'], $u['requested'], true)) {
                    $sum['asked_got'] += $u['size'];
                }
            }
            if ($o['gap'] !== null) {
                $gap_total += $o['gap'] * $u['size'];
                $sum['gap_max'] = max($sum['gap_max'], $o['gap']);
            }
        }
        $sum['teams_filled'] = count($filled);
        $sum['gap_mean'] = $sum['placed'] ? round($gap_total / $sum['placed'], 2) : 0.0;
        foreach ($T as $t) {
            if (!$t['new']) {
                $sum['short_before'] += $t['n0'] < $s['min_roster'] ? 1 : 0;
                $sum['short_after'] += $t['n'] < $s['min_roster'] ? 1 : 0;
            }
        }
        foreach ($out['new_teams'] as $d) {
            $sum['by_division'][$d['division']]['new_teams']++;
            $sum['new_no_captain'] += in_array('needs_captain', $d['flags'], true) ? 1 : 0;
            $sum['new_no_setter'] += in_array('no_setter', $d['flags'], true) ? 1 : 0;
        }
        return $sum;
    }

    /**
     * The placement rules, checked against a result (tests and the
     * simulator): every unit placed once (groups whole), nobody in a
     * division they didn't choose or on a team that said no, no team over
     * the maximum, new teams min–max (except ones an admin started), a
     * volunteer captain on every new team when another new team in the
     * division could have spared one without splitting a group or breaking
     * min–max (could_share_captain()), court space respected, locked units
     * where the admin put them. Returns a list of problems (empty = fine).
     */
    public static function violations($units, $teams, $settings, $result) {
        list($U, $T, $s) = self::prepare($units, $teams, $settings);
        $bad = array();
        $ru = isset($result['units']) && is_array($result['units']) ? $result['units'] : array();
        $new = isset($result['new_teams']) && is_array($result['new_teams']) ? $result['new_teams'] : array();
        $heads = array();
        $in_new = array();
        foreach ($U as $ref => $u) {
            if (!isset($ru[$ref]['dest'])) {
                $bad[] = "$ref has no destination";
                continue;
            }
            $dest = $ru[$ref]['dest'];
            if ($u['locked'] !== '' && $dest !== $u['locked']) {
                $bad[] = "$ref was locked to {$u['locked']} but went to $dest";
            }
            if ($dest === 'none') {
                continue;
            }
            $heads[$dest] = (isset($heads[$dest]) ? $heads[$dest] : 0) + $u['size'];
            if (strpos($dest, 't:') === 0) {
                if (!isset($T[$dest])) {
                    if ($u['locked'] === '') {
                        $bad[] = "$ref went to unknown team $dest";
                    }
                    continue;
                }
                $div = $T[$dest]['division'];
                if ($u['locked'] === '' && in_array($T[$dest]['id'], $u['declined'], true)) {
                    $bad[] = "$ref went to $dest, which said no to them";
                }
            } else {
                $r = substr($dest, 2);
                if (!isset($new[$r])) {
                    $bad[] = "$ref went to $dest, which isn't a new team in the result";
                    continue;
                }
                $div = $new[$r]['division'];
                $in_new[$r][] = $ref;
            }
            if ($u['locked'] === '' && !in_array($div, $u['divisions'], true)) {
                $bad[] = "$ref (" . ($u['divisions'] ? implode('/', $u['divisions']) : 'no division') . ") was placed in $div";
            }
        }
        foreach ($T as $key => $t) {
            $h = isset($heads[$key]) ? $heads[$key] : 0;
            if ($h > 0 && $t['n0'] + $h > $s['max_roster']) {
                $bad[] = "{$t['name']} goes over the maximum (" . ($t['n0'] + $h) . ')';
            }
        }
        $vols = array();
        $count_new = array('unranked' => 0, 'ranked' => 0);
        $seeded = array('unranked' => 0, 'ranked' => 0);
        foreach ($new as $r => $d) {
            $h = isset($heads['n:' . $r]) ? $heads['n:' . $r] : 0;
            if (intval($d['size']) !== $h) {
                $bad[] = "new team $r says {$d['size']} players but has $h";
            }
            $listed = array_values((array) $d['unit_refs']);
            $got = isset($in_new[$r]) ? $in_new[$r] : array();
            sort($listed);
            sort($got);
            if ($listed !== $got) {
                $bad[] = "new team $r lists different units from the ones sent to it";
            }
            if (!empty($d['seeded'])) {
                $seeded[$d['division']]++;
                if ($h > $s['max_roster']) {
                    $bad[] = "new team $r has $h players";
                }
                continue;
            }
            $count_new[$d['division']]++;
            if ($h < $s['min_roster'] || $h > $s['max_roster']) {
                $bad[] = "new team $r has $h players";
            }
            $vols[$d['division']][] = array('ref' => $r, 'v' => self::volunteers($got, $U), 'h' => $h, 'units' => $got);
        }
        foreach ($vols as $list) {
            foreach ($list as $a) {
                if ($a['v'] > 0) {
                    continue;
                }
                foreach ($list as $b) {
                    if ($b['v'] >= 2 && self::could_share_captain($a, $b, $U, $s)) {
                        $bad[] = "new team {$a['ref']} has no volunteer captain but {$b['ref']} has {$b['v']} and could give it one";
                        break;
                    }
                }
            }
        }
        foreach ($count_new as $div => $c) {
            if ($s['capacity'][$div] === null) {
                continue;
            }
            $have = $seeded[$div];
            foreach ($T as $t) {
                $have += $t['division'] === $div ? 1 : 0;
            }
            $room = max(0, $s['capacity'][$div] - $have);
            if ($c > $room) {
                $bad[] = "$c new $div teams but court space for $room";
            }
        }
        return $bad;
    }

    /**
     * Could new team $b (two or more volunteers) give new team $a (none) a
     * volunteer and keep one, with no group split and both teams within
     * min–max? A volunteer single swapped for one of $a's singles or moved
     * over, or whole units of the same head-count traded — what
     * captain_swaps() tries. When none of these can, $a is only flagged
     * (needs_captain), as the spec says.
     */
    private static function could_share_captain($a, $b, $U, $s) {
        $volunteer_single = false;
        foreach ($b['units'] as $r) {
            $volunteer_single = $volunteer_single || ($U[$r]['size'] === 1 && self::unit_volunteers($U[$r]) > 0);
        }
        $single = false;
        foreach ($a['units'] as $r) {
            $single = $single || $U[$r]['size'] === 1;
        }
        if ($volunteer_single && ($single || ($a['h'] < $s['max_roster'] && $b['h'] - 1 >= $s['min_roster']))) {
            return true;
        }
        foreach ($a['units'] as $p) {
            foreach (self::same_heads(array_values($b['units']), $U, $U[$p]['size'], 0) as $q) {
                $qv = self::volunteers($q, $U);
                if ($qv >= 1 && $b['v'] - $qv >= 1) {
                    return true;
                }
            }
        }
        return false;
    }

    // ------------------------------------------------------------------
    // The season, from the database
    // ------------------------------------------------------------------

    /** [$units, $teams, $settings] for propose(), from the season's list and teams. */
    public static function context($season) {
        $built = self::build($season, RSL_Free_Agents::pool($season));
        return array($built[0], $built[1], $built[2]);
    }

    /**
     * Units from the waiting rows (RSL_Free_Agents::pool(): looking and
     * unplaced), teams from the season's active teams, settings with court
     * space. Also returns the team list it read.
     */
    private static function build($season, $rows) {
        global $wpdb;
        $fa = RSL_Free_Agents::settings();
        $w = self::weights();
        $first3 = array();
        foreach (RSL_Courts::nights($season) as $n) {
            if ($n->type === 'regular' && count($first3) < 3) {
                $first3[] = $n->night_date;
            }
        }
        // Who asked whom (a lapsed request still says where they'd like to go), and who said no.
        $asked = array();
        $asked_group = array();
        $declined = array();
        $declined_group = array();
        foreach ($wpdb->get_results($wpdb->prepare("SELECT team_id, user_id, group_code, status FROM " . RSL_Database::table('join_requests') . " WHERE season = %s AND status IN ('pending', 'expired', 'declined')", $season)) as $r) {
            $tid = intval($r->team_id);
            if ($r->status === 'declined') {
                $declined[intval($r->user_id)][$tid] = true;
                if ($r->group_code !== '') {
                    $declined_group[$r->group_code][$tid] = true;
                }
            } else {
                $asked[intval($r->user_id)][$tid] = true;
                if ($r->group_code !== '') {
                    $asked_group[$r->group_code][$tid] = true;
                }
            }
        }
        foreach ($wpdb->get_results($wpdb->prepare("SELECT i.team_id, i.user_id FROM " . RSL_Database::table('invites') . " i JOIN " . RSL_Database::table('teams') . " t ON t.id = i.team_id WHERE t.season = %s AND i.status = 'declined'", $season)) as $r) {
            $declined[intval($r->user_id)][intval($r->team_id)] = true;
        }
        // Draft new teams an earlier confirm made real: their numbers are never reused, and a move onto one means the real team.
        $b = RSL_Free_Agents::building($season);
        $made = array();
        foreach ((array) $b['new_teams'] as $ref => $d) {
            if (is_array($d) && !empty($d['team_id'])) {
                $made[(string) $ref] = intval($d['team_id']);
            }
        }

        $groups = array();
        foreach ($rows as $r) {
            $groups[$r->group_code !== '' ? 'g:' . $r->group_code : 'u:' . intval($r->user_id)][] = $r;
        }
        $units = array();
        foreach ($groups as $key => $list) {
            usort($list, function ($a, $b) {
                return $a->created_at === $b->created_at ? intval($a->user_id) - intval($b->user_id) : strcmp((string) $a->created_at, (string) $b->created_at);
            });
            $members = array();
            $ids = array();
            $times = array('early' => 0, 'late' => 0);
            $positions = array();
            $away = 0;
            $requested = array();
            $dec = array();
            $locked = '';
            $band_sum = 0.0;
            $under18 = 0;
            $code = (string) $list[0]->group_code;
            foreach ($list as $r) {
                $uid = intval($r->user_id);
                $band = (float) RSL_Free_Agents::band($r, $r);
                $ids[] = $uid;
                $band_sum += $band;
                $members[] = array('user_id' => $uid, 'band' => $band, 'captain_ok' => intval($r->captain_ok) && !intval($r->under18), 'veteran' => $r->years === 'gt3', 'minor' => (bool) intval($r->under18));
                if (isset($times[$r->time_pref])) {
                    $times[$r->time_pref]++;
                }
                foreach (array_filter(explode(',', (string) $r->positions)) as $p) {
                    $positions[$p] = true;
                }
                $away = max($away, count(array_intersect(array_filter(explode(',', (string) $r->away_dates)), $first3)));
                $requested += isset($asked[$uid]) ? $asked[$uid] : array();
                $dec += isset($declined[$uid]) ? $declined[$uid] : array();
                if (intval($r->proposed_locked) && $locked === '' && $r->proposed_dest !== '') {
                    $locked = $r->proposed_dest;
                }
                $under18 += intval($r->under18) ? 1 : 0;
            }
            if ($code !== '') {
                $requested += isset($asked_group[$code]) ? $asked_group[$code] : array();
                $dec += isset($declined_group[$code]) ? $declined_group[$code] : array();
            }
            if (preg_match('/^n:([UR]\d+)$/', $locked, $m) && isset($made[$m[1]])) {
                $locked = 't:' . $made[$m[1]];
            }
            $captain = 0;
            $captain_band = -1.0;
            foreach ($members as $m) {
                if ($m['captain_ok'] && $m['band'] > $captain_band) {
                    $captain = $m['user_id'];
                    $captain_band = $m['band'];
                }
            }
            $units[] = array(
                'ref' => $key,
                'user_ids' => $ids,
                'lead_user_id' => $ids[0],
                'size' => count($ids),
                'band' => round($band_sum / count($ids), 3),
                'members' => $members,
                'divisions' => RSL_Free_Agents::group_divisions($list),
                'time' => $times['early'] > $times['late'] ? 'early' : ($times['late'] > $times['early'] ? 'late' : 'any'),
                'positions' => array_keys($positions),
                'away_early' => $away,
                'captain_ok' => $captain > 0,
                'captain_user_id' => $captain,
                'requested' => array_keys($requested),
                'declined' => array_keys($dec),
                'created_at' => (string) $list[0]->created_at,
                'locked' => $locked,
                'group_code' => $code,
                'under18' => $under18,
            );
        }

        $list = RSL_Teams::list_for_season($season);
        $ranked = false;
        foreach ($list as $t) {
            $ranked = $ranked || $t->division === 'ranked';
        }
        $ratings = $ranked && class_exists('RSL_Ratings') ? RSL_Ratings::compute($season) : array();
        $roster = array();
        foreach ($wpdb->get_results($wpdb->prepare("
            SELECT m.team_id, p.level, p.years, p.positions
            FROM " . RSL_Database::table('team_members') . " m
            JOIN " . RSL_Database::table('teams') . " t ON t.id = m.team_id
            LEFT JOIN " . RSL_Database::table('players') . " p ON p.user_id = m.user_id
            WHERE t.season = %s AND t.status = 'active' AND m.left_at IS NULL
        ", $season)) as $m) {
            $roster[intval($m->team_id)][] = $m;
        }
        $teams = array();
        foreach ($list as $t) {
            $id = intval($t->id);
            // Band: a Ranked team's results once it has some, else its level, else its players'.
            $band = null;
            if ($t->division === 'ranked' && isset($ratings[$id]) && intval($ratings[$id]['games']) >= 3) {
                $band = max(1.0, min(5.0, 1 + ($ratings[$id]['rating'] - 1200) / 150));
            }
            if ($band === null) {
                $band = self::band_for_level($t->est_level);
            }
            $have = array();
            $bands = array();
            foreach (isset($roster[$id]) ? $roster[$id] : array() as $m) {
                foreach (array_filter(explode(',', (string) $m->positions)) as $p) {
                    $have[$p] = true;
                }
                if ($m->level !== null) {
                    $bands[] = RSL_Free_Agents::band($m);
                }
            }
            if ($band === null && $bands) {
                $band = array_sum($bands) / count($bands);
            }
            $lacks = array_filter(explode(',', (string) $t->spots_positions));
            foreach (self::POSITIONS as $p) {
                if (!isset($have[$p])) {
                    $lacks[] = $p;
                }
            }
            $teams[] = array(
                'id' => $id,
                'name' => $t->name,
                'division' => $t->division,
                'n' => intval($t->member_count),
                'band' => $band === null ? 3.0 : round($band, 3),
                'pref_time' => $t->pref_time,
                'lacks' => array_values(array_unique($lacks)),
                'spots_open' => intval($t->spots_open),
            );
        }

        $used = array_map('strval', array_keys($made));
        $settings = array_merge($fa, array(
            'gap_fill' => isset($fa['gap_fill']) ? (float) $fa['gap_fill'] : (float) $w['gap_fill'],
            'gap_short' => isset($fa['gap_short']) ? (float) $fa['gap_short'] : (float) $w['gap_short'],
            'capacity' => self::capacity($season, $fa),
            'weights' => $w,
            'used_refs' => $used,
        ));
        return array($units, $teams, $settings, $list);
    }

    /**
     * Teams each division's courts can take: an override, else the game
     * slots of the season's first regular night × 2. null = no nights yet
     * (not checked).
     */
    private static function capacity($season, $fa) {
        $override = isset($fa['capacity_override']) && is_array($fa['capacity_override']) ? $fa['capacity_override'] : array();
        $slots = null;
        foreach (RSL_Courts::nights($season) as $n) {
            if ($n->type !== 'regular') {
                continue;
            }
            $slots = array('unranked' => 0, 'ranked' => 0);
            foreach (RSL_Courts::slots_for_night($n->id) as $slot) {
                if (isset($slots[$slot['division']])) {
                    $slots[$slot['division']]++;
                }
            }
            break;
        }
        $out = array();
        foreach (array('unranked', 'ranked') as $d) {
            if (!empty($override[$d])) {
                $out[$d] = intval($override[$d]);
            } else {
                $out[$d] = $slots === null ? null : 2 * $slots[$d];
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // The draft (admin)
    // ------------------------------------------------------------------

    /**
     * Make a proposal and save it as the draft: proposed_dest/reason on every
     * waiting row (rows an admin moved keep their place, and a friend who has
     * joined their group since goes there too), draft new teams
     * (names, levels and captains the admin set are kept for a team that
     * survives the re-run), a new draft version. $keep_moves false starts
     * again from scratch. Returns the summary: plain-English lines.
     */
    public static function suggest($season, $keep_moves = true) {
        if (!RSL_Seasons::is_valid($season)) {
            return new WP_Error('rsl_season', 'Pick a valid season.');
        }
        return self::with_lock($season, function () use ($season, $keep_moves) {
            return self::suggest_locked($season, $keep_moves);
        });
    }

    /**
     * Run $fn holding the season's placement lock, so suggest(), move(),
     * update_new() and confirm() take turns: each reads the draft (and checks
     * its version) and saves it with nobody else changing it in between.
     */
    private static function with_lock($season, $fn, $busy = 'Someone else is changing the placement draft — try again in a moment.') {
        global $wpdb;
        $lock = 'rsl_place_' . $season;
        if (!$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock, 10))) {
            return new WP_Error('rsl_place_busy', $busy);
        }
        try {
            return $fn();
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    private static function suggest_locked($season, $keep_moves) {
        global $wpdb;
        $table = RSL_Database::table('free_agents');
        if (!$keep_moves) {
            $wpdb->query($wpdb->prepare("UPDATE $table SET proposed_locked = 0 WHERE season = %s AND status IN ('looking', 'unplaced')", $season));
        }
        list($units, $teams, $settings) = self::context($season);
        $result = self::propose($units, $teams, $settings);
        $now = current_time('mysql');
        $map = array();
        foreach ($units as $u) {
            $o = $result['units'][$u['ref']];
            foreach ($u['user_ids'] as $uid) {
                $map[$uid] = $o['dest'];
            }
            $in = implode(',', array_map('intval', $u['user_ids']));
            if ($u['locked'] !== '') {
                // The unit stays where the admin put it — including a friend who joined the group since (or one moved
                // elsewhere before the group formed), so the group isn't left split with no way to re-run it whole.
                $wpdb->query($wpdb->prepare(
                    "UPDATE $table SET proposed_dest = %s, proposed_reason = %s, proposed_locked = 1, updated_at = %s WHERE season = %s AND user_id IN ($in) AND status IN ('looking', 'unplaced') AND (proposed_dest <> %s OR proposed_locked = 0)",
                    $o['dest'], substr($o['reason'], 0, 255), $now, $season, $o['dest']
                ));
                continue;
            }
            $wpdb->query($wpdb->prepare(
                "UPDATE $table SET proposed_dest = %s, proposed_reason = %s, updated_at = %s WHERE season = %s AND user_id IN ($in) AND status IN ('looking', 'unplaced') AND proposed_locked = 0",
                $o['dest'], substr($o['reason'], 0, 255), $now, $season
            ));
        }
        $b = RSL_Free_Agents::building($season);
        $old = is_array($b['new_teams']) ? $b['new_teams'] : array();
        $drafts = array();
        foreach ($result['new_teams'] as $ref => $d) {
            $draft = array('name' => $d['name'], 'division' => $d['division'], 'est_level' => $d['est_level'], 'captain_user_id' => $d['captain_user_id'], 'name_set' => 0, 'level_set' => 0, 'captain_set' => 0);
            $o = isset($old[$ref]) && is_array($old[$ref]) && empty($old[$ref]['team_id']) ? $old[$ref] : null;
            if ($o && !empty($o['name_set'])) {
                $draft['name'] = $o['name'];
                $draft['name_set'] = 1;
            }
            if ($o && !empty($o['level_set'])) {
                $draft['est_level'] = $o['est_level'];
                $draft['level_set'] = 1;
            }
            if ($o && !empty($o['captain_set']) && in_array(intval($o['captain_user_id']), $d['user_ids'], true)) {
                $draft['captain_user_id'] = intval($o['captain_user_id']);
                $draft['captain_set'] = 1;
            }
            $drafts[$ref] = $draft;
        }
        foreach ($old as $ref => $o) {
            if (!empty($o['team_id']) && !isset($drafts[$ref])) {
                $drafts[$ref] = $o; // already made into a team by an earlier confirm
            }
        }
        $b['new_teams'] = $drafts;
        $b['draft_version'] = intval($b['draft_version']) + 1;
        $b['proposed_at'] = $now;
        $b['summary'] = self::summary_lines($result['summary']);
        $b['stats'] = $result['summary'];
        $b['proposed_map'] = $map;
        RSL_Free_Agents::save_building($season, $b);
        return $b['summary'];
    }

    /** "22 into 5 existing teams", "16 in 2 new teams", "2 not placed" … */
    private static function summary_lines($sum) {
        $plural = function ($n, $word) {
            return $n . ' ' . $word . ($n === 1 ? '' : 's');
        };
        $lines = array(
            $sum['placed_existing'] . ' into ' . $plural($sum['teams_filled'], 'existing team'),
            $sum['placed_new'] . ' in ' . $plural($sum['new_teams'], 'new team'),
            $sum['unplaced'] . ' not placed',
        );
        if ($sum['locked']) {
            $lines[] = $sum['locked'] . ' kept where you moved them';
        }
        if ($sum['asked_players']) {
            $lines[] = $sum['asked_got'] . ' of ' . $sum['asked_players'] . ' who asked a team are on it';
        }
        if ($sum['short_after']) {
            $lines[] = $plural($sum['short_after'], 'team') . ' still short';
        }
        if ($sum['new_no_captain']) {
            $lines[] = $plural($sum['new_no_captain'], 'new team') . ' without a volunteer captain';
        }
        return $lines;
    }

    /**
     * Everything the season's draft needs in one read: the waiting rows
     * (each with ->band, ->unit_ref, ->unit_size, ->first_name added), units,
     * teams, team bands, rows by destination, and who is on a team already.
     */
    private static function state($season) {
        global $wpdb;
        $rows = RSL_Free_Agents::pool($season);
        list($units, $teams, $settings, $list) = self::build($season, $rows);
        $st = array('season' => $season, 'b' => RSL_Free_Agents::building($season), 's' => $settings, 'rows' => $rows, 'units' => array(), 'unit_of' => array(),
            'row_of' => array(), 'team_band' => array(), 'teams' => array(), 'by_dest' => array(), 'on_team' => array());
        foreach ($units as $u) {
            $st['units'][$u['ref']] = $u;
            foreach ($u['user_ids'] as $uid) {
                $st['unit_of'][$uid] = $u['ref'];
            }
        }
        foreach ($teams as $t) {
            $st['team_band'][$t['id']] = $t['band'];
        }
        foreach ($list as $t) {
            $st['teams'][intval($t->id)] = $t;
        }
        foreach ($rows as $r) {
            $uid = intval($r->user_id);
            $u = $st['units'][$st['unit_of'][$uid]];
            foreach ($u['members'] as $m) {
                if ($m['user_id'] === $uid) {
                    $r->band = $m['band'];
                }
            }
            $r->unit_ref = $u['ref'];
            $r->unit_size = $u['size'];
            $r->first_name = self::row_first_name($r);
            $st['row_of'][$uid] = $r;
            $st['by_dest'][(string) $r->proposed_dest][] = $r;
        }
        if ($rows) {
            $in = implode(',', array_map('intval', array_keys($st['row_of'])));
            foreach ($wpdb->get_results($wpdb->prepare("
                SELECT m.user_id, t.name FROM " . RSL_Database::table('team_members') . " m
                JOIN " . RSL_Database::table('teams') . " t ON t.id = m.team_id
                WHERE t.season = %s AND t.status = 'active' AND m.left_at IS NULL AND m.user_id IN ($in)
            ", $season)) as $m) {
                $st['on_team'][intval($m->user_id)] = $m->name;
            }
        }
        return $st;
    }

    /** First name from a pool row (no extra queries). Never an email. */
    private static function row_first_name($r) {
        $first = isset($r->first_name_meta) ? trim((string) $r->first_name_meta) : '';
        if ($first === '' && isset($r->display_name)) {
            $first = trim((string) strtok((string) $r->display_name, ' '));
        }
        return $first === '' || strpos($first, '@') !== false ? 'Player' : $first;
    }

    /** New-team refs that have people in the draft: Ranked first, then by number. */
    private static function draft_refs($st) {
        $refs = array();
        foreach (array_keys($st['by_dest']) as $dest) {
            if (preg_match('/^n:([UR]\d+)$/', $dest, $m)) {
                $refs[] = $m[1];
            }
        }
        usort($refs, function ($a, $b) {
            if ($a[0] !== $b[0]) {
                return $a[0] === 'R' ? -1 : 1;
            }
            return intval(substr($a, 1)) - intval(substr($b, 1));
        });
        return $refs;
    }

    /** A draft new team's record with defaults filled in (and n, band). */
    private static function draft_record($st, $ref) {
        $rows = isset($st['by_dest']['n:' . $ref]) ? $st['by_dest']['n:' . $ref] : array();
        $band = 0.0;
        foreach ($rows as $r) {
            $band += $r->band;
        }
        $band = $rows ? $band / count($rows) : 3.0;
        $d = isset($st['b']['new_teams'][$ref]) && is_array($st['b']['new_teams'][$ref]) ? $st['b']['new_teams'][$ref] : array();
        return array_merge(array(
            'name' => self::placeholder($ref), 'division' => self::division_of_ref($ref), 'est_level' => self::level_for_band($band),
            'captain_user_id' => 0, 'name_set' => 0, 'level_set' => 0, 'captain_set' => 0,
        ), $d, array('n' => count($rows), 'band' => round($band, 2), 'missing' => !$d));
    }

    /**
     * The draft for the admin screen: version; existing teams with the people
     * proposed for each (team, n = members now, rows); new teams (ref, draft,
     * rows); not placed; waiting but not in the proposal; blockers and
     * warnings. Rows come from RSL_Free_Agents::pool() with ->band added.
     */
    public static function draft($season) {
        $st = self::state($season);
        $out = array('version' => intval($st['b']['draft_version']), 'existing' => array(), 'new' => array(), 'none' => array(), 'unproposed' => array(), 'blockers' => array(), 'warnings' => array());
        foreach ($st['teams'] as $id => $t) {
            if (!empty($st['by_dest']['t:' . $id])) {
                $out['existing'][] = array('team' => $t, 'n' => intval($t->member_count), 'rows' => $st['by_dest']['t:' . $id]);
            }
        }
        foreach (self::draft_refs($st) as $ref) {
            $out['new'][] = array('ref' => $ref, 'draft' => self::draft_record($st, $ref), 'rows' => $st['by_dest']['n:' . $ref]);
        }
        foreach ($st['by_dest'] as $dest => $rows) {
            if ($dest === 'none') {
                $out['none'] = $rows;
            } elseif ($dest === '' || (preg_match('/^t:(\d+)$/', $dest, $m) && !isset($st['teams'][intval($m[1])]))) {
                $out['unproposed'] = array_merge($out['unproposed'], $rows);
            }
        }
        foreach (self::check($st) as $item) {
            $out[$item['kind'] === 'blocker' ? 'blockers' : 'warnings'][] = $item['message'];
        }
        return $out;
    }

    /**
     * Where an admin may move a person's unit (their friend group moves with
     * them): teams in a division every member agreed to, with room up to the
     * maximum counting everyone else proposed there, that haven't said no to
     * them; the draft's new teams; a fresh new team; or not placed.
     * dest => label, e.g. 't:12' => 'Spike Club (Unranked) 6 → 8'.
     */
    public static function destinations($season, $user_id) {
        $row = RSL_Free_Agents::get($user_id, $season);
        if (!RSL_Free_Agents::is_open($row)) {
            return array();
        }
        return self::destinations_for(RSL_Free_Agents::unit_rows($row), self::destination_context($season));
    }

    /**
     * destinations() for everyone open on the list at once — user_id =>
     * (dest => label), the same answers — reading the pool, the teams, the
     * draft and the declined requests/invites once for the whole season
     * (the placement screen asks about every unit).
     */
    public static function destinations_all($season) {
        $ctx = self::destination_context($season);
        $units = array();
        foreach ($ctx['pool'] as $r) {
            $units[$r->group_code !== '' ? 'g:' . $r->group_code : 'u:' . intval($r->user_id)][] = $r;
        }
        $out = array();
        foreach ($units as $unit) {
            $dest = self::destinations_for($unit, $ctx);
            foreach ($unit as $r) {
                $out[intval($r->user_id)] = $dest;
            }
        }
        return $out;
    }

    /** What destinations_for() reads, loaded once per season. */
    private static function destination_context($season) {
        global $wpdb;
        $declined_requests = $wpdb->get_results($wpdb->prepare("SELECT team_id, user_id, group_code FROM " . RSL_Database::table('join_requests') . " WHERE season = %s AND status = 'declined'", $season));
        $declined_invites = $wpdb->get_results($wpdb->prepare("SELECT i.team_id, i.user_id FROM " . RSL_Database::table('invites') . " i JOIN " . RSL_Database::table('teams') . " t ON t.id = i.team_id WHERE t.season = %s AND i.status = 'declined' AND i.user_id <> 0", $season));
        return array(
            'pool' => RSL_Free_Agents::pool($season),
            'teams' => RSL_Teams::list_for_season($season),
            'building' => RSL_Free_Agents::building($season),
            'max' => RSL_Free_Agents::settings()['max_roster'],
            'declined_requests' => $declined_requests,
            'declined_invites' => $declined_invites,
        );
    }

    /** destinations() for one unit (its open rows), from destination_context(). */
    private static function destinations_for($unit, $ctx) {
        $ids = array_map(function ($r) { return intval($r->user_id); }, $unit);
        $size = count($unit);
        $allowed = RSL_Free_Agents::group_divisions($unit);
        $max = $ctx['max'];
        $b = $ctx['building'];
        $count = array();
        $next = array('U' => 0, 'R' => 0);
        foreach ($ctx['pool'] as $r) {
            if ($r->proposed_dest === '' || in_array(intval($r->user_id), $ids, true)) {
                continue;
            }
            $count[$r->proposed_dest] = (isset($count[$r->proposed_dest]) ? $count[$r->proposed_dest] : 0) + 1;
            if (preg_match('/^n:([UR])(\d+)$/', $r->proposed_dest, $m)) {
                $next[$m[1]] = max($next[$m[1]], intval($m[2]));
            }
        }
        // Teams that said no to anyone in the unit (or that they turned down).
        $declined = array();
        $code = $unit ? (string) $unit[0]->group_code : '';
        foreach ($ctx['declined_requests'] as $d) {
            if (in_array(intval($d->user_id), $ids, true) || ($code !== '' && (string) $d->group_code === $code)) {
                $declined[intval($d->team_id)] = true;
            }
        }
        foreach ($ctx['declined_invites'] as $d) {
            if (in_array(intval($d->user_id), $ids, true)) {
                $declined[intval($d->team_id)] = true;
            }
        }
        $out = array();
        foreach ($ctx['teams'] as $t) {
            if (!in_array($t->division, $allowed, true) || isset($declined[intval($t->id)])) {
                continue;
            }
            $n = intval($t->member_count) + (isset($count['t:' . $t->id]) ? $count['t:' . $t->id] : 0);
            if ($n + $size <= $max) {
                $out['t:' . $t->id] = $t->name . ' (' . RSL_Teams::DIVISIONS[$t->division] . ') ' . $n . ' → ' . ($n + $size);
            }
        }
        foreach ((array) $b['new_teams'] as $ref => $d) {
            if (!preg_match('/^([UR])(\d+)$/', (string) $ref, $m)) {
                continue;
            }
            $next[$m[1]] = max($next[$m[1]], intval($m[2]));
            $div = self::division_of_ref($ref);
            if (!empty($d['team_id']) || !in_array($div, $allowed, true)) {
                continue;
            }
            $n = isset($count['n:' . $ref]) ? $count['n:' . $ref] : 0;
            if ($n + $size <= $max) {
                $label = !empty($d['name_set']) ? $d['name'] . ' (new ' . RSL_Teams::DIVISIONS[$div] . ' team)' : 'New ' . RSL_Teams::DIVISIONS[$div] . ' team ' . $m[2];
                $out['n:' . $ref] = $label . ' ' . $n . ' → ' . ($n + $size);
            }
        }
        foreach (array('R' => 'ranked', 'U' => 'unranked') as $p => $div) {
            if (in_array($div, $allowed, true) && $size <= $max) {
                $out['n:' . $p . ($next[$p] + 1)] = 'Start another new ' . RSL_Teams::DIVISIONS[$div] . ' team';
            }
        }
        $out['none'] = 'Not placed';
        return $out;
    }

    private static function stale_error() {
        return new WP_Error('rsl_place_stale', 'Someone else changed the draft — reload the page and try again.');
    }

    /**
     * An admin moves a person — with their friend group — to $dest (one of
     * destinations()). The rows are locked, so a re-run keeps them there.
     * Refused for a stale $version. Returns true or WP_Error.
     */
    public static function move($season, $user_id, $dest, $version) {
        return self::with_lock($season, function () use ($season, $user_id, $dest, $version) {
            return self::move_locked($season, $user_id, $dest, $version);
        });
    }

    private static function move_locked($season, $user_id, $dest, $version) {
        global $wpdb;
        $b = RSL_Free_Agents::building($season);
        if (intval($version) !== intval($b['draft_version'])) {
            return self::stale_error();
        }
        $row = RSL_Free_Agents::get($user_id, $season);
        if (!RSL_Free_Agents::is_open($row)) {
            return new WP_Error('rsl_place_gone', 'They\'re not waiting to be placed any more.');
        }
        $dest = (string) $dest;
        if (!isset(self::destinations($season, $user_id)[$dest])) {
            return new WP_Error('rsl_place_dest', 'They can\'t go there — that team is full, has said no to them, or is in a division they (or their friends) haven\'t agreed to.');
        }
        $unit = RSL_Free_Agents::unit_rows($row);
        $ids = array_map(function ($r) { return intval($r->user_id); }, $unit);
        if (preg_match('/^n:([UR]\d+)$/', $dest, $m) && (!isset($b['new_teams'][$m[1]]) || !is_array($b['new_teams'][$m[1]]))) {
            $b['new_teams'][$m[1]] = array('name' => self::placeholder($m[1]), 'division' => self::division_of_ref($m[1]), 'est_level' => '', 'captain_user_id' => 0, 'name_set' => 0, 'level_set' => 0, 'captain_set' => 0);
        }
        $in = implode(',', $ids);
        $wpdb->query($wpdb->prepare(
            "UPDATE " . RSL_Database::table('free_agents') . " SET proposed_dest = %s, proposed_reason = %s, proposed_locked = 1, updated_at = %s WHERE season = %s AND user_id IN ($in) AND status IN ('looking', 'unplaced')",
            $dest, $dest === 'none' ? 'Held back by an admin' : 'Moved by an admin', current_time('mysql'), $season
        ));
        self::tidy_drafts($season, $b);
        $b['draft_version'] = intval($b['draft_version']) + 1;
        $map = isset($b['proposed_map']) && is_array($b['proposed_map']) ? $b['proposed_map'] : array();
        foreach ($ids as $uid) {
            $map[$uid] = $dest;
        }
        $b['proposed_map'] = $map;
        RSL_Free_Agents::save_building($season, $b);
        return true;
    }

    /**
     * After a move: a draft team's captain who moved out is replaced (the
     * best volunteer left), levels the admin didn't set follow the members,
     * and unnamed drafts nobody's in are dropped.
     */
    private static function tidy_drafts($season, &$b) {
        $by = array();
        foreach (RSL_Free_Agents::pool($season) as $r) {
            if (preg_match('/^n:([UR]\d+)$/', (string) $r->proposed_dest, $m)) {
                $by[$m[1]][] = $r;
            }
        }
        foreach ((array) $b['new_teams'] as $ref => $d) {
            if (!empty($d['team_id'])) {
                continue;
            }
            if (empty($by[$ref])) {
                if (empty($d['name_set'])) {
                    unset($b['new_teams'][$ref]);
                }
                continue;
            }
            $ids = array_map(function ($r) { return intval($r->user_id); }, $by[$ref]);
            if (!in_array(intval($d['captain_user_id']), $ids, true)) {
                $b['new_teams'][$ref]['captain_user_id'] = self::best_captain($by[$ref]);
                $b['new_teams'][$ref]['captain_set'] = 0;
            }
            if (empty($d['level_set'])) {
                $sum = 0.0;
                foreach ($by[$ref] as $r) {
                    $sum += RSL_Free_Agents::band($r, $r);
                }
                $b['new_teams'][$ref]['est_level'] = self::level_for_band($sum / count($by[$ref]));
            }
        }
    }

    /**
     * The volunteer with the highest band; else the most experienced, highest
     * band (ties: earliest sign-up). Never an under-18 (0 if there's only them).
     */
    private static function best_captain($rows) {
        $best = null;
        foreach ($rows as $r) {
            if (!empty($r->under18)) {
                continue;
            }
            $key = array(intval($r->captain_ok) ? 0 : 1, $r->years === 'gt3' ? 0 : 1, -RSL_Free_Agents::band($r, $r), strtotime((string) $r->created_at), intval($r->user_id));
            if ($best === null || self::lex_less($key, $best[0])) {
                $best = array($key, intval($r->user_id));
            }
        }
        return $best ? $best[1] : 0;
    }

    /**
     * Name a draft new team, set its level (RSL_Teams::levels() key) or pick
     * its captain (someone proposed for it). $data: name, est_level,
     * captain_user_id — any of them. Returns true or WP_Error.
     */
    public static function update_new($season, $ref, $data, $version) {
        return self::with_lock($season, function () use ($season, $ref, $data, $version) {
            return self::update_new_locked($season, $ref, $data, $version);
        });
    }

    private static function update_new_locked($season, $ref, $data, $version) {
        global $wpdb;
        $b = RSL_Free_Agents::building($season);
        if (intval($version) !== intval($b['draft_version'])) {
            return self::stale_error();
        }
        $ref = (string) $ref;
        if (!isset($b['new_teams'][$ref]) || !is_array($b['new_teams'][$ref]) || !empty($b['new_teams'][$ref]['team_id'])) {
            return new WP_Error('rsl_place_ref', 'That new team isn\'t in the draft any more — reload the page.');
        }
        $d = $b['new_teams'][$ref];
        if (isset($data['name'])) {
            $name = trim(sanitize_text_field((string) $data['name']));
            if ($name === '') {
                return new WP_Error('rsl_name', 'A team needs a name.');
            }
            if (mb_strlen($name) > 100) {
                return new WP_Error('rsl_name', 'Team names can be up to 100 characters.');
            }
            if (self::is_placeholder($name)) {
                return new WP_Error('rsl_name', 'Give the team a real name — "' . $name . '" is only a placeholder.');
            }
            if ($wpdb->get_var($wpdb->prepare("SELECT id FROM " . RSL_Database::table('teams') . " WHERE season = %s AND name = %s", $season, $name))) {
                return new WP_Error('rsl_duplicate', 'There\'s already a team called "' . $name . '" this season.');
            }
            foreach ($b['new_teams'] as $other => $o) {
                if ((string) $other !== $ref && empty($o['team_id']) && !empty($o['name_set']) && strcasecmp((string) $o['name'], $name) === 0) {
                    return new WP_Error('rsl_duplicate', 'Another new team in the draft is already called "' . $name . '".');
                }
            }
            $d['name'] = $name;
            $d['name_set'] = 1;
        }
        if (isset($data['est_level'])) {
            if (!isset(RSL_Teams::levels()[$data['est_level']])) {
                return new WP_Error('rsl_level', 'Pick a level.');
            }
            $d['est_level'] = (string) $data['est_level'];
            $d['level_set'] = 1;
        }
        if (isset($data['captain_user_id']) && intval($data['captain_user_id']) !== intval($d['captain_user_id'])) {
            $uid = intval($data['captain_user_id']);
            $row = $uid ? RSL_Free_Agents::get($uid, $season) : null;
            if (!RSL_Free_Agents::is_open($row) || $row->proposed_dest !== 'n:' . $ref) {
                return new WP_Error('rsl_place_captain', 'The captain has to be one of the players in this team.');
            }
            $d['captain_user_id'] = $uid;
            $d['captain_set'] = 1;
        }
        $b['new_teams'][$ref] = $d;
        $b['draft_version'] = intval($b['draft_version']) + 1;
        RSL_Free_Agents::save_building($season, $b);
        return true;
    }

    /**
     * What stops confirming (blockers) and what's worth a look (warnings),
     * as plain sentences: array(blockers => [...], warnings => [...]).
     */
    public static function blockers($season) {
        $out = array('blockers' => array(), 'warnings' => array());
        foreach (self::check(self::state($season)) as $item) {
            $out[$item['kind'] === 'blocker' ? 'blockers' : 'warnings'][] = $item['message'];
        }
        return $out;
    }

    /**
     * Each problem with the block it belongs to ('t:<id>', 'n:<ref>',
     * 'none', or '' for the whole draft — or a list of blocks), so
     * confirming one ready block isn't held up by another.
     */
    private static function check($st) {
        global $wpdb;
        $items = array();
        $add = function ($kind, $block, $message) use (&$items) {
            $items[] = array('kind' => $kind, 'block' => $block, 'message' => $message);
        };
        $who = function ($rows) {
            $names = array();
            foreach ($rows as $r) {
                $names[] = isset($r->display_name) && $r->display_name !== '' ? $r->display_name : $r->first_name;
            }
            return implode(', ', $names);
        };
        $s = $st['s'];
        $max = intval($s['max_roster']);
        $min = intval($s['min_roster']);
        $b = $st['b'];
        $drafts = is_array($b['new_teams']) ? $b['new_teams'] : array();
        $divs = RSL_Teams::DIVISIONS;

        // New teams' level and time, from who's in them.
        $new_band = array();
        $new_time = array();
        foreach (self::draft_refs($st) as $ref) {
            $rows = $st['by_dest']['n:' . $ref];
            $sum = 0.0;
            $times = array('early' => 0, 'late' => 0);
            foreach ($rows as $r) {
                $sum += $r->band;
                if (isset($times[$r->time_pref])) {
                    $times[$r->time_pref]++;
                }
            }
            $new_band[$ref] = $sum / count($rows);
            $new_time[$ref] = $times['early'] > $times['late'] ? 'early' : ($times['late'] > $times['early'] ? 'late' : 'any');
        }
        $name_of = function ($dest) use ($st, $drafts) {
            if (preg_match('/^t:(\d+)$/', $dest, $m)) {
                return isset($st['teams'][intval($m[1])]) ? $st['teams'][intval($m[1])]->name : 'a team';
            }
            $ref = substr($dest, 2);
            return isset($drafts[$ref]['name']) && !self::is_placeholder($drafts[$ref]['name']) ? $drafts[$ref]['name'] : 'new team ' . $ref;
        };

        // Each unit: kept together, in a division they agreed to, on a team that hasn't said no.
        foreach ($st['units'] as $ref => $u) {
            $rows = array();
            $dests = array();
            foreach ($u['user_ids'] as $uid) {
                $rows[] = $st['row_of'][$uid];
                $dests[(string) $st['row_of'][$uid]->proposed_dest] = true;
            }
            if (count($dests) > 1) {
                // Held against each block they're split across (not the whole draft), so the other teams can still be confirmed.
                $add('blocker', array_values(array_diff(array_map('strval', array_keys($dests)), array(''))), 'A friend group is split up (' . $who($rows) . ') — move them together, or re-run the proposal.');
                continue;
            }
            $dest = (string) key($dests);
            if ($dest === '' || $dest === 'none') {
                continue;
            }
            if (preg_match('/^t:(\d+)$/', $dest, $m)) {
                $id = intval($m[1]);
                if (!isset($st['teams'][$id])) {
                    continue;
                }
                $div = $st['teams'][$id]->division;
                $band = $st['team_band'][$id];
                $time = $st['teams'][$id]->pref_time;
                if (in_array($id, $u['declined'], true)) {
                    $add('blocker', $dest, $st['teams'][$id]->name . ' has said no to ' . $who($rows) . ' this season — move them.');
                }
            } else {
                $r2 = substr($dest, 2);
                $div = self::division_of_ref($r2);
                $band = isset($new_band[$r2]) ? $new_band[$r2] : $u['band'];
                $time = isset($new_time[$r2]) ? $new_time[$r2] : 'any';
            }
            if (!in_array($div, $u['divisions'], true)) {
                $add('blocker', $dest, $who($rows) . ($u['size'] > 1 ? ' haven\'t' : ' hasn\'t') . ' agreed to play ' . $divs[$div] . ' — move them.');
            }
            $gap = abs($u['band'] - $band);
            if ($gap > 1.0 + 1e-9) {
                $add('warning', $dest, $who($rows) . ': level gap of ' . number_format($gap, 1) . ' with ' . $name_of($dest) . '.');
            }
            if ($u['time'] !== 'any' && $time !== 'any' && $u['time'] !== $time) {
                $add('warning', $dest, $who($rows) . ' asked for ' . $u['time'] . ' games; ' . $name_of($dest) . ' prefers ' . $time . '.');
            }
            if ($u['away_early'] >= 2) {
                $add('warning', $dest, $who($rows) . ' will miss ' . $u['away_early'] . ' of the first 3 nights.');
            }
        }

        // Someone on a team already whose list entry wasn't closed.
        foreach ($st['rows'] as $r) {
            $uid = intval($r->user_id);
            if (isset($st['on_team'][$uid])) {
                $dest = (string) $r->proposed_dest;
                $add($dest === '' ? 'warning' : 'blocker', $dest, $who(array($r)) . ' has joined ' . $st['on_team'][$uid] . ' since — re-run the proposal.');
            }
        }

        // Each block.
        $names = array();
        foreach ($st['by_dest'] as $dest => $rows) {
            $dest = (string) $dest;
            if ($dest === '' || $dest === 'none') {
                continue;
            }
            $n = count($rows);
            if (preg_match('/^t:(\d+)$/', $dest, $m)) {
                $id = intval($m[1]);
                if (!isset($st['teams'][$id])) {
                    $add('blocker', $dest, $who($rows) . ($n > 1 ? ' are' : ' is') . ' proposed for a team that\'s no longer active — move them or re-run the proposal.');
                    continue;
                }
                $after = intval($st['teams'][$id]->member_count) + $n;
                if ($after > $max) {
                    $add('blocker', $dest, $st['teams'][$id]->name . ' would have ' . $after . ' players (the maximum is ' . $max . ').');
                }
            } elseif (preg_match('/^n:([UR]\d+)$/', $dest, $m)) {
                $ref = $m[1];
                $d = isset($drafts[$ref]) && is_array($drafts[$ref]) ? $drafts[$ref] : null;
                if (!$d || !empty($d['team_id'])) {
                    $add('blocker', $dest, 'New team ' . $ref . ($d ? ' has been made into a team already' : ' is missing from the draft') . ' — re-run the proposal.');
                    continue;
                }
                $name = (string) $d['name'];
                if ($n > $max) {
                    $add('blocker', $dest, $name_of($dest) . ' would have ' . $n . ' players (the maximum is ' . $max . ').');
                }
                if (self::is_placeholder($name)) {
                    $add('blocker', $dest, 'Name new team ' . $ref . ' — "' . ($name === '' ? self::placeholder($ref) : $name) . '" is only a placeholder.');
                } else {
                    $key = strtolower($name);
                    if (isset($names[$key])) {
                        $add('blocker', $dest, 'Two new teams are both called "' . $name . '".');
                    }
                    $names[$key] = true;
                    if ($wpdb->get_var($wpdb->prepare("SELECT id FROM " . RSL_Database::table('teams') . " WHERE season = %s AND name = %s", $st['season'], $name))) {
                        $add('blocker', $dest, 'There\'s already a team called "' . $name . '" this season — rename new team ' . $ref . '.');
                    }
                }
                $captain = null;
                foreach ($rows as $r) {
                    if (intval($r->user_id) === intval($d['captain_user_id'])) {
                        $captain = $r;
                    }
                }
                if (!$captain) {
                    $add('blocker', $dest, 'Pick a captain for ' . $name_of($dest) . '.');
                } elseif (intval($captain->under18)) {
                    $add('warning', $dest, $name_of($dest) . '\'s captain, ' . $who(array($captain)) . ', is under 18 — only confirm if the club\'s rules allow it.');
                } elseif (!intval($captain->captain_ok)) {
                    $add('warning', $dest, $name_of($dest) . ' has no volunteer captain — ' . $who(array($captain)) . ' is picked; check they\'re happy to.');
                }
                if ($n < $min) {
                    $add('warning', $dest, $name_of($dest) . ' would have only ' . $n . ' players (the minimum is ' . $min . ').');
                }
            } else {
                $add('blocker', $dest, $who($rows) . ' have a destination that isn\'t recognised — re-run the proposal.');
            }
        }

        // Teams still short after the draft.
        foreach ($st['teams'] as $id => $t) {
            $rows = isset($st['by_dest']['t:' . $id]) ? count($st['by_dest']['t:' . $id]) : 0;
            $after = intval($t->member_count) + $rows;
            if ($after < $min) {
                $add('warning', $rows ? 't:' . $id : '', $t->name . ' will have ' . $after . ' player' . ($after === 1 ? '' : 's') . ' (the minimum is ' . $min . ').');
            }
        }

        // Court space.
        foreach (array_keys($divs) as $div) {
            $cap = isset($s['capacity'][$div]) ? $s['capacity'][$div] : null;
            if ($cap === null) {
                continue;
            }
            $count = 0;
            foreach ($st['teams'] as $t) {
                $count += $t->division === $div ? 1 : 0;
            }
            foreach (self::draft_refs($st) as $ref) {
                $count += self::division_of_ref($ref) === $div ? 1 : 0;
            }
            if ($count > $cap) {
                $add('warning', '', $divs[$div] . ' would have ' . $count . ' teams but the first night has game slots for ' . $cap . '.');
            }
        }
        if ($s['capacity']['unranked'] === null && $s['capacity']['ranked'] === null) {
            $add('warning', '', 'This season has no game nights yet, so court space isn\'t checked.');
        }
        if (!empty($st['by_dest'][''])) {
            $n = count($st['by_dest']['']);
            $add('warning', '', $n . ' waiting ' . ($n === 1 ? 'person isn\'t' : 'people aren\'t') . ' in the proposal yet — re-run it, or move them.');
        }
        $dates = RSL_Free_Agents::dates($st['season']);
        if (!$dates) {
            $add('warning', '', 'This season has no first game night, so players haven\'t been given dates.');
        } elseif (in_array(RSL_Free_Agents::phase($st['season']), array('before', 'open'), true)) {
            $add('warning', '', 'The deadline (' . $dates['deadline'] . ') hasn\'t passed — captains and free agents can still match up.');
        }
        return $items;
    }

    // ------------------------------------------------------------------
    // Confirming
    // ------------------------------------------------------------------

    /**
     * Make the draft real: new teams are created (formed_by 'placement')
     * with their captain, everyone is added to their team and marked placed
     * (with a "Not for me" window), anyone not placed is told why, and the
     * emails are queued. $only: null (everything), 't:<id>', 'n:<ref>' or
     * 'none' — one block. Each person is checked first; anyone who joined a
     * team another way since is skipped. Safe to run twice: placed people
     * aren't waiting any more, a new team is made once, people already told
     * they weren't placed aren't counted or told again, and emails have
     * dedupe keys. When nobody is left waiting, the season's placements are
     * marked confirmed. Returns array(placed, new_teams, unplaced, skipped,
     * emails) — plus errors when a team couldn't be made — or WP_Error.
     */
    public static function confirm($season, $only = null, $admin_id = 0) {
        $only = $only === null || $only === '' ? null : (string) $only;
        if ($only !== null && !preg_match('/^(t:\d+|n:[UR]\d+|none)$/', $only)) {
            return new WP_Error('rsl_place_block', 'That part of the draft isn\'t there any more — reload and try again.');
        }
        return self::with_lock($season, function () use ($season, $only, $admin_id) {
            return self::confirm_locked($season, $only, intval($admin_id));
        }, 'Placements are being confirmed or changed in another tab — try again in a moment.');
    }

    private static function confirm_locked($season, $only, $admin_id) {
        global $wpdb;
        $st = self::state($season);
        $blocking = array();
        foreach (self::check($st) as $item) {
            $blocks = (array) $item['block'];
            if ($item['kind'] === 'blocker' && ($only === null || in_array('', $blocks, true) || in_array($only, $blocks, true))) {
                $blocking[] = $item['message'];
            }
        }
        if ($blocking) {
            return new WP_Error('rsl_place_blocked', 'Fix these first: ' . implode(' ', $blocking));
        }
        $s = RSL_Free_Agents::settings();
        $now = current_time('mysql');
        $optout = date('Y-m-d H:i:s', current_time('timestamp') + intval($s['optout_days']) * DAY_IN_SECONDS);
        $out = array('placed' => 0, 'new_teams' => 0, 'unplaced' => 0, 'skipped' => 0, 'emails' => 0, 'errors' => array());

        // People in the proposal who've since joined a team another way, or left the list.
        $map = isset($st['b']['proposed_map']) && is_array($st['b']['proposed_map']) ? $st['b']['proposed_map'] : array();
        if ($map) {
            $status = array();
            foreach ($wpdb->get_results($wpdb->prepare("SELECT user_id, status, placed_via FROM " . RSL_Database::table('free_agents') . " WHERE season = %s", $season)) as $r) {
                $status[intval($r->user_id)] = $r;
            }
            foreach ($map as $uid => $dest) {
                $r = isset($status[intval($uid)]) ? $status[intval($uid)] : null;
                if ($dest === 'none' || ($only !== null && $dest !== $only) || ($r && (RSL_Free_Agents::is_open($r) || $r->placed_via === 'placement'))) {
                    continue;
                }
                $out['skipped']++;
            }
        }

        $dests = array_keys($st['by_dest']);
        usort($dests, function ($a, $b) {
            $rank = function ($d) {
                return strpos($d, 'n:') === 0 ? 0 : (strpos($d, 't:') === 0 ? 1 : 2);
            };
            return $rank((string) $a) === $rank((string) $b) ? strcmp((string) $a, (string) $b) : $rank((string) $a) - $rank((string) $b);
        });
        foreach ($dests as $dest) {
            $dest = (string) $dest;
            if ($dest === '' || ($only !== null && $dest !== $only)) {
                continue;
            }
            $rows = $st['by_dest'][$dest];
            if ($dest === 'none') {
                self::confirm_none($season, $rows, $out);
            } elseif (strpos($dest, 'n:') === 0) {
                self::confirm_new($season, substr($dest, 2), $rows, $optout, $out);
            } else {
                self::confirm_existing($season, intval(substr($dest, 2)), $rows, $optout, intval($st['b']['draft_version']), $out);
            }
        }

        $left = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . RSL_Database::table('free_agents') . " WHERE season = %s AND status = 'looking'", $season));
        $b = RSL_Free_Agents::building($season);
        if ($left === 0 && $b['confirmed_at'] === '') {
            $b['confirmed_at'] = $now;
            $b['confirmed_by'] = $admin_id;
            RSL_Free_Agents::save_building($season, $b);
        }
        if (!$out['errors']) {
            unset($out['errors']);
        }
        return $out;
    }

    /** Rows whose person is still free to join $team_id (anyone on another team, or applying, is skipped). */
    private static function free_rows($rows, $season, $team_id, &$out) {
        $ok = array();
        foreach ($rows as $r) {
            $uid = intval($r->user_id);
            $current = RSL_Teams::current_team_for_user($uid, $season);
            if (($current && intval($current->id) !== intval($team_id)) || (!$current && RSL_Teams::pending_application_for_user($uid, $season))) {
                $out['skipped']++;
                continue;
            }
            $ok[] = $r;
        }
        return $ok;
    }

    /** Add rows to a team (the captain first, then each unit together) and mark them placed. Returns the user ids placed. */
    private static function place_rows($team, $rows, $captain_uid, $optout, &$out) {
        global $wpdb;
        if ($captain_uid) {
            if (!RSL_Teams::is_member($team->id, $captain_uid)) {
                RSL_Teams::add_member($team->id, $captain_uid, 'captain', false, array('admin' => true, 'how' => 'placement'));
            } elseif (!RSL_Teams::is_captain($team->id, $captain_uid)) {
                RSL_Teams::set_captain($team->id, $captain_uid);
            }
        }
        $units = array();
        foreach ($rows as $r) {
            $units[isset($r->unit_ref) ? $r->unit_ref : 'u:' . $r->user_id][] = intval($r->user_id);
        }
        foreach ($units as $ids) {
            $todo = array();
            foreach ($ids as $uid) {
                if (!RSL_Teams::is_member($team->id, $uid)) {
                    $todo[] = $uid;
                }
            }
            if (!$todo) {
                continue;
            }
            if (is_wp_error(RSL_Free_Agents::add_unit($team->id, $todo, 'placement', true))) {
                // add_unit stops at the first problem; give everyone else in the unit their own go.
                foreach ($todo as $uid) {
                    if (!RSL_Teams::is_member($team->id, $uid)) {
                        RSL_Teams::add_member($team->id, $uid, 'player', false, array('admin' => true, 'how' => 'placement'));
                    }
                }
            }
        }
        $placed = array();
        $now = current_time('mysql');
        foreach ($rows as $r) {
            $uid = intval($r->user_id);
            if (!RSL_Teams::is_member($team->id, $uid)) {
                $out['skipped']++;
                continue;
            }
            // Set explicitly — the rsl_member_added hook marks them placed too, but not the opt-out window.
            $wpdb->update(RSL_Database::table('free_agents'), array(
                'status' => 'placed', 'team_id' => (int) $team->id, 'placed_via' => 'placement', 'placed_at' => $now, 'optout_until' => $optout,
                'unplaced_reason' => '', 'proposed_dest' => '', 'proposed_locked' => 0, 'updated_at' => $now,
            ), array('id' => $r->id));
            $placed[] = $uid;
        }
        $out['placed'] += count($placed);
        return $placed;
    }

    /** One new team: made once (a run that stopped part-way is picked up by name), captain set, players added, everyone emailed. */
    private static function confirm_new($season, $ref, $rows, $optout, &$out) {
        global $wpdb;
        $b = RSL_Free_Agents::building($season);
        $d = isset($b['new_teams'][$ref]) && is_array($b['new_teams'][$ref]) ? $b['new_teams'][$ref] : null;
        if (!$d) {
            $out['errors'][] = 'New team ' . $ref . ' is missing from the draft.';
            return;
        }
        $team = !empty($d['team_id']) ? RSL_Teams::get($d['team_id']) : null;
        if (!$team) {
            $level = isset(RSL_Teams::levels()[$d['est_level']]) ? $d['est_level'] : '';
            $made = RSL_Teams::create($season, array('name' => $d['name'], 'division' => self::division_of_ref($ref), 'level' => $level, 'status' => 'active'));
            if (is_wp_error($made)) {
                // A run that stopped part-way may already have made it.
                $team = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . RSL_Database::table('teams') . " WHERE season = %s AND name = %s AND formed_by = 'placement'", $season, $d['name']));
                if (!$team) {
                    $out['errors'][] = $d['name'] . ': ' . $made->get_error_message();
                    return;
                }
            } else {
                $team = $made;
                $wpdb->update(RSL_Database::table('teams'), array('formed_by' => 'placement'), array('id' => $team->id));
                $out['new_teams']++;
            }
            $b['new_teams'][$ref]['team_id'] = (int) $team->id;
            RSL_Free_Agents::save_building($season, $b);
        }
        $free = self::free_rows($rows, $season, $team->id, $out);
        $ids = array_map(function ($r) { return intval($r->user_id); }, $free);
        $captain_uid = in_array(intval($d['captain_user_id']), $ids, true) ? intval($d['captain_user_id']) : self::best_captain($free);
        $placed = self::place_rows($team, $free, $captain_uid, $optout, $out);
        // Anyone in this block who couldn't be added now waits for the real team: the draft shows them there (a confirm
        // of that team adds them), and a re-run can't draft this team a second time.
        $moved = $wpdb->query($wpdb->prepare(
            "UPDATE " . RSL_Database::table('free_agents') . " SET proposed_dest = %s WHERE season = %s AND proposed_dest = %s AND status IN ('looking', 'unplaced')",
            't:' . $team->id, $season, 'n:' . $ref
        ));
        if ($moved) {
            $b = RSL_Free_Agents::building($season);
            if (isset($b['proposed_map']) && is_array($b['proposed_map'])) {
                foreach ($b['proposed_map'] as $uid => $dest) {
                    if ($dest === 'n:' . $ref) {
                        $b['proposed_map'][$uid] = 't:' . $team->id;
                    }
                }
                RSL_Free_Agents::save_building($season, $b);
            }
        }
        if ($placed && !RSL_Teams::captain($team->id)) {
            $fallback = $placed[0];
            foreach ($free as $r) {
                if (in_array(intval($r->user_id), $placed, true) && empty($r->under18)) {
                    $fallback = intval($r->user_id);
                    break;
                }
            }
            RSL_Teams::set_captain($team->id, in_array($captain_uid, $placed, true) ? $captain_uid : $fallback);
        }
        $captain = RSL_Teams::captain($team->id);
        $volunteer = false;
        foreach ($free as $r) {
            if ($captain && intval($r->user_id) === intval($captain->user_id)) {
                $volunteer = (bool) intval($r->captain_ok);
            }
        }
        foreach ($placed as $uid) {
            if (!$captain || intval($captain->user_id) !== $uid) {
                $out['emails'] += self::mail_placed($uid, $team, $captain, $optout, $season);
            }
        }
        if ($captain && in_array(intval($captain->user_id), $placed, true)) {
            $out['emails'] += self::mail_new_captain($team, $captain, $season, $volunteer);
        }
    }

    /** People joining an existing team: added, emailed, and the captain gets their details. */
    private static function confirm_existing($season, $team_id, $rows, $optout, $version, &$out) {
        $team = RSL_Teams::get($team_id);
        if (!$team || $team->status !== 'active' || $team->season !== $season) {
            $out['skipped'] += count($rows);
            return;
        }
        $placed = self::place_rows($team, self::free_rows($rows, $season, $team->id, $out), 0, $optout, $out);
        $captain = RSL_Teams::captain($team->id);
        foreach ($placed as $uid) {
            $out['emails'] += self::mail_placed($uid, $team, $captain, $optout, $season);
        }
        if ($captain && $placed) {
            $out['emails'] += self::mail_receiving_captain($team, $captain, $placed, $optout, $season, $version);
        }
    }

    /** Not placed: marked with the reason (still first in line — they stay on the list), and told. */
    private static function confirm_none($season, $rows, &$out) {
        global $wpdb;
        $now = current_time('mysql');
        foreach (self::free_rows($rows, $season, 0, $out) as $r) {
            $reason = trim((string) $r->proposed_reason);
            $stored = substr($reason !== '' ? $reason : 'We couldn\'t fit you onto a team this time', 0, 255);
            if ($r->status === 'unplaced' && (string) $r->unplaced_reason === $stored) {
                continue; // told already, by an earlier confirm
            }
            $wpdb->update(RSL_Database::table('free_agents'), array(
                'status' => 'unplaced', 'unplaced_reason' => $stored, 'updated_at' => $now,
            ), array('id' => $r->id));
            $out['unplaced']++;
            $out['emails'] += self::mail_unplaced(intval($r->user_id), $reason, $season);
        }
    }

    // ------------------------------------------------------------------
    // Email (queued through RSL_Free_Agents::mail — greeting, sign-off, dedupe)
    // Contact details go only between a player and their own captain.
    // ------------------------------------------------------------------

    private static function queued($result) {
        return !is_wp_error($result) && intval($result) > 0 ? 1 : 0;
    }

    private static function day($ymd) {
        return date_i18n('D j M', strtotime($ymd));
    }

    /** "First night: Sun 2 Feb at Hall X." — whatever is known. */
    private static function first_night_line($season, $division) {
        $d = RSL_Free_Agents::dates($season);
        if (!$d) {
            return '';
        }
        $venue = '';
        foreach (RSL_Courts::venues() as $v) {
            if ($v->division === $division) {
                $venue = $v->name;
                break;
            }
        }
        return 'First night: ' . self::day($d['first_night']) . ($venue !== '' ? ' at ' . $venue : '') . ".\n";
    }

    private static function rego_line($user_id) {
        $clear = RSL_Rego::clearance($user_id);
        if (!empty($clear['cleared'])) {
            return '';
        }
        return 'Before your first game, pay your social rego (' . RSL_Rego::price_label() . ", 12 months) unless you're a full or life club member:\n" . (RSL_Rego::pay_url() ?: RSL_Portal::url()) . "\n";
    }

    private static function fee_words() {
        return RSL_Payments::money(RSL_Payments::settings()['game_fee']);
    }

    /**
     * To a placed player [placed:{season}:{uid}:{team}]: team, captain's name and email, teammates' first names, dates, rego,
     * "Not for me". Keyed by team too: someone who steps back and is placed again on another team hears about that one.
     */
    private static function mail_placed($uid, $team, $captain, $optout, $season) {
        $mates = array();
        foreach (RSL_Teams::members($team->id) as $m) {
            if (intval($m->user_id) !== $uid && (!$captain || intval($m->user_id) !== intval($captain->user_id))) {
                $mates[] = RSL_Free_Agents::first_name($m->user_id);
            }
        }
        $text = "The league has placed you on {$team->name} (" . RSL_Teams::DIVISIONS[$team->division] . ') for the ' . RSL_Seasons::label($season) . " Reds Social League.\n\n"
            . ($captain ? "Your captain is {$captain->display_name} — {$captain->user_email}. Say hi, and they'll add you to the team chat.\n" : '')
            . ($mates ? 'Your teammates: ' . implode(', ', $mates) . ".\n" : '')
            . "\n" . self::first_night_line($season, $team->division)
            . self::rego_line($uid)
            . 'Your team pays the game fee (' . self::fee_words() . " a game) from its team balance — chip in with \"Pay my share\" on your league page.\n\n"
            . 'Not for you? You can step back at no cost until ' . self::day($optout) . " on your league page:\n" . RSL_Portal::url() . "\n\n"
            . 'Your team page: ' . RSL_Public::team_url($team);
        return self::queued(RSL_Free_Agents::mail($uid, 'You\'re on ' . $team->name, $text, 'placed:' . $season . ':' . $uid . ':' . $team->id));
    }

    /**
     * To an existing team's captain [placed-team:{season}:{team}:{version}:{who}]: the new players' names and emails.
     * Keyed by who too, so a later confirm at the same draft version that adds someone else still tells the captain.
     */
    private static function mail_receiving_captain($team, $captain, $uids, $optout, $season, $version) {
        $who = array_map('intval', $uids);
        sort($who);
        $lines = array();
        foreach ($uids as $uid) {
            $u = get_userdata($uid);
            if ($u) {
                $lines[] = $u->display_name . ' <' . $u->user_email . '>';
            }
        }
        if (!$lines) {
            return 0;
        }
        $text = 'The league has placed ' . (count($lines) > 1 ? 'these players' : 'this player') . " on {$team->name} for the " . RSL_Seasons::label($season) . ":\n\n- " . implode("\n- ", $lines) . "\n\n"
            . "Please say hi and add them to your team chat. Their emails are for team contact only.\n"
            . 'Players placed by the league can step back at no cost until ' . self::day($optout) . " — we'll let you know if anyone does.\n\n"
            . "Your league page:\n" . RSL_Portal::url();
        return self::queued(RSL_Free_Agents::mail($captain->user_id, 'New player' . (count($lines) > 1 ? 's' : '') . ' for ' . $team->name, $text, 'placed-team:' . $season . ':' . $team->id . ':' . $version . ':' . substr(md5(implode(',', $who)), 0, 12)));
    }

    /** To a new team's captain [newcap:{team}]: the roster with emails, three jobs, a suggested share, the invite link. */
    private static function mail_new_captain($team, $captain, $season, $volunteer) {
        $members = RSL_Teams::members($team->id);
        $lines = array();
        foreach ($members as $m) {
            $lines[] = $m->display_name . ' <' . $m->user_email . '>' . (intval($m->user_id) === intval($captain->user_id) ? ' (you)' : '');
        }
        $n = max(1, count($members));
        $fee = RSL_Payments::settings()['game_fee'];
        $d = RSL_Free_Agents::dates($season);
        $text = "You're the captain of {$team->name} (" . RSL_Teams::DIVISIONS[$team->division] . '), a new team made from the free agent list for the ' . RSL_Seasons::label($season) . ' Reds Social League.'
            . ($volunteer ? " Thanks for volunteering!\n\n" : " We picked you as the most experienced player — if you'd rather not captain, reply and let us know.\n\n")
            . "Your team:\n- " . implode("\n- ", $lines) . "\n\n"
            . 'Three jobs before the first night' . ($d ? ' (' . self::day($d['first_night']) . ')' : '') . ":\n"
            . "1. Start a group chat and add everyone.\n"
            . "2. Make sure everyone is cleared to play (social rego or full/life club membership) — your league page shows who still needs it.\n"
            . '3. Top up the team balance. Games are ' . RSL_Payments::money($fee) . ' each, paid from the balance — about ' . RSL_Payments::money(ceil($fee / $n)) . " each a game with $n players. Everyone can chip in with \"Pay my share\".\n\n"
            . self::rego_line($captain->user_id) . ($d ? '' : '')
            . "Want to bring someone else? Share your team's invite link:\n" . RSL_Portal::invite_url($team) . "\n\n"
            . 'Your league page: ' . RSL_Portal::url() . "\nYour team page: " . RSL_Public::team_url($team);
        return self::queued(RSL_Free_Agents::mail($captain->user_id, 'You\'re captain of ' . $team->name, $text, 'newcap:' . $team->id));
    }

    /** To someone not placed [unplaced:{season}:{uid}]: why, not charged, first in line. */
    private static function mail_unplaced($uid, $reason, $season) {
        $words = RSL_Free_Agents::unplaced_words($reason); // admin holds stay off the email, as on their page
        $why = $words !== '' ? ' (' . $words . ')' : '';
        $text = "We couldn't fit you onto a team for the " . RSL_Seasons::label($season) . ' Reds Social League this time' . $why . ".\n\n"
            . "You haven't been charged anything. You're first in line if a spot opens on a team, and we'll be in touch if one does. "
            . "Soon you'll also be able to join the sub list and fill in for teams that are short on a night.\n\n"
            . "Your league page:\n" . RSL_Portal::url();
        return self::queued(RSL_Free_Agents::mail($uid, 'Your place in the ' . RSL_Seasons::label($season) . ' Reds Social League', $text, 'unplaced:' . $season . ':' . $uid));
    }
}
