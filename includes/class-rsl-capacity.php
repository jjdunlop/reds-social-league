<?php
/**
 * Places in each division (0.19.0): how many teams a division takes in a
 * season, which applications are on the waiting list, and the nights teams
 * would sit out when an admin goes over.
 *
 * The club's rule (5 Nov 2026): a division takes at most as many teams as
 * lets every team sit out at most the allowance of the season's nights, with
 * every team there every night. The allowance is the free agent setting
 * sit_out_nights (Most nights a team sits out in a season, default 2), the
 * same for every season length: a team that comes in mid-season accepts it
 * might not make finals. With n teams and S game slots on a night, n − 2S
 * teams sit out that night when that's above 0, and 0.18.2 shares the
 * sit-outs out (RSL_Pairing::sit_out()), so the places are the largest n with
 *
 *   the sum, over the season's regular nights where the division has a slot,
 *   of max(0, n − 2S)  ≤  allowance × n.
 *
 * A night where the division has no slot isn't a sit-out (0.18.2 counts only
 * nights its division had games); bye and finals nights don't count. A
 * division with no slot on any regular night has 0 places; a season with no
 * regular nights yet isn't checked (null). Places never go above MAX_PLACES
 * (50, the most the Most teams per division setting takes): with an allowance
 * at or above the nights that have a slot the sum never binds, so it's 50
 * there. The override (Most teams per division above 0) wins.
 *
 * Taken places are the active teams in the season and division. The waiting
 * list is the pending applications there, oldest first (applied_at, id),
 * beyond the places left. Nothing is stored: it's worked out from the teams
 * table on each read, so approving, declining, withdrawing and a team leaving
 * move it on their own.
 *
 * Pure core (no database, so tests call it directly): places(), sit_outs(),
 * sit_out_numbers(), sit_out_words(), over_words(), queue(), places_line(),
 * full_words(), waiting_words(). season() is the one wrapper, and every reader
 * goes through it: placement (RSL_Placement::capacity()), the Start a team
 * form (RSL_Portal::team_fields()), RSL_Teams::apply(), the captain's
 * application card and the admin screens. Nothing works out places another way.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RSL_Capacity {

    /** The most places a division gets: the Most teams per division setting's maximum. */
    const MAX_PLACES = 50;
    /** The allowance setting's range. */
    const MAX_ALLOWANCE = 10;
    const DEFAULT_ALLOWANCE = 2;

    // ------------------------------------------------------------------
    // The rule (pure)
    // ------------------------------------------------------------------

    /**
     * A division's places from its game slots on each of the season's regular
     * nights ($slots: a list of counts, 0 for a night it has none) and the
     * allowance. null with no nights (not checked); 0 when no night has a slot.
     */
    public static function places($slots, $allowance) {
        $slots = array_values((array) $slots);
        if (!$slots) {
            return null;
        }
        $on = self::nights_with_slots($slots);
        if (!$on) {
            return 0;
        }
        $allowance = max(0, intval($allowance));
        // The nights sat out minus allowance × n is convex in n and 0 at n = 0, so the n that fit
        // run from 0 up to the first that doesn't.
        $best = 0;
        for ($n = 1; $n <= self::MAX_PLACES; $n++) {
            if (self::sit_outs($n, $on) > $allowance * $n) {
                break;
            }
            $best = $n;
        }
        return $best;
    }

    /** Nights sat out in all by $teams teams over the nights in $slots that have a slot: the sum of max(0, teams − 2 × slots). */
    public static function sit_outs($teams, $slots) {
        $teams = max(0, intval($teams));
        $total = 0;
        foreach (self::nights_with_slots($slots) as $s) {
            $total += max(0, $teams - 2 * $s);
        }
        return $total;
    }

    /**
     * The sit-out numbers for $teams teams over $slots (the nights still to
     * come): nights (with a slot), total, average (to one decimal, as words
     * show it), whole (the average is a whole number) and most (the average
     * rounded up: the most a team there every night sits out, since 0.18.2
     * keeps those teams within one sit-out of each other).
     */
    public static function sit_out_numbers($teams, $slots) {
        $teams = max(0, intval($teams));
        $total = self::sit_outs($teams, $slots);
        $whole = $teams === 0 || $total % $teams === 0;
        return array(
            'teams' => $teams,
            'nights' => count(self::nights_with_slots($slots)),
            'total' => $total,
            'average' => $teams ? round($total / $teams, 1) : 0.0,
            'whole' => $whole,
            'most' => $teams ? intdiv($total + $teams - 1, $teams) : 0,
        );
    }

    /**
     * The numbers in words: "about 2.3 nights each sat out this season, so some
     * sit out 3" ("in the 6 nights left" once the season has started; no "so
     * some" when the average is whole). "About", because teams away and odd
     * numbers move it.
     */
    public static function sit_out_words($teams, $slots, $started) {
        $x = self::sit_out_numbers($teams, $slots);
        $when = $started ? 'in the ' . $x['nights'] . ' night' . ($x['nights'] === 1 ? '' : 's') . ' left' : 'this season';
        if ($x['total'] === 0) {
            return 'there are game slots for every team on every night ' . ($started ? 'left' : 'this season');
        }
        $avg = $x['whole'] ? (string) intdiv($x['total'], $x['teams']) : number_format($x['average'], 1, '.', '');
        // A few sit-outs among many teams round to 0.0: say under 0.1 instead.
        $about = $avg === '0.0' ? 'under 0.1' : 'about ' . $avg;
        return $about . ' night' . ($avg === '1' ? '' : 's') . ' each sat out ' . $when
            . ($x['whole'] ? '' : ', so some sit out ' . $x['most']);
    }

    /**
     * "31 teams for 30 places: about 2.3 nights each sat out this season, so
     * some sit out 3", from a division's info (season()'s, or one made up in
     * a test: label, places, slots_left, started, checked) and the team count.
     */
    public static function over_words($info, $teams) {
        $teams = intval($teams);
        $places = isset($info['places']) ? $info['places'] : null;
        $head = $teams . ' team' . ($teams === 1 ? '' : 's') . ($places === null ? '' : ' for ' . intval($places) . ' place' . (intval($places) === 1 ? '' : 's'));
        $started = !empty($info['started']);
        $left = isset($info['slots_left']) ? (array) $info['slots_left'] : array();
        if (empty($info['checked'])) {
            return $head . ': this season has no game nights yet, so nights sat out aren\'t counted';
        }
        if (!$left) {
            return $head . ': no game nights are left this season';
        }
        if (!self::nights_with_slots($left)) {
            return $head . ': ' . (isset($info['label']) ? $info['label'] : 'the division') . ' has no game slots ' . ($started ? 'on the nights left' : 'this season');
        }
        return $head . ': ' . self::sit_out_words($teams, $left, $started);
    }

    /**
     * The applications in one season and division, oldest first by applied_at
     * then id (as RSL_Teams::pending_applications() lists them): the first
     * places − active are ordinary, the rest are on the waiting list. $pending:
     * rows or arrays with id and applied_at. $places null (not checked): none
     * waiting. Returns array(order, ordinary, waiting): lists of team ids.
     */
    public static function queue($places, $active, $pending) {
        $rows = array();
        foreach ((array) $pending as $p) {
            $p = (array) $p;
            $rows[] = array('id' => intval($p['id']), 'at' => isset($p['applied_at']) ? (string) $p['applied_at'] : '');
        }
        usort($rows, function ($a, $b) {
            return $a['at'] === $b['at'] ? $a['id'] - $b['id'] : strcmp($a['at'], $b['at']);
        });
        $order = array_map(function ($r) { return $r['id']; }, $rows);
        $room = $places === null ? count($order) : max(0, intval($places) - intval($active));
        return array('order' => $order, 'ordinary' => array_slice($order, 0, $room), 'waiting' => array_slice($order, $room));
    }

    /**
     * The admin's places line for one season and division (info from
     * season()): "Ranked, 2027 Mid-season 2: 28 of 30 places taken, 4
     * applications waiting (2 on the waiting list)."
     */
    public static function places_line($info) {
        $head = $info['label'] . ', ' . RSL_Seasons::short_label($info['season']) . ': ';
        $n = intval($info['pending']);
        $apps = $n . ' application' . ($n === 1 ? '' : 's') . ' waiting' . ($info['waiting'] ? ' (' . count($info['waiting']) . ' on the waiting list)' : '');
        $active = intval($info['active']);
        if ($info['places'] === null) {
            return $head . $active . ' team' . ($active === 1 ? '' : 's') . ', ' . $apps . '. This season has no game nights yet, so court space isn\'t checked.';
        }
        $places = intval($info['places']);
        $taken = $active > $places
            ? $active . ' teams for ' . $places . ' place' . ($places === 1 ? '' : 's')
            : $active . ' of ' . $places . ' place' . ($places === 1 ? '' : 's') . ' taken';
        return $head . $taken . (!empty($info['set_by_hand']) ? ' (set by hand)' : '') . ', ' . $apps . '.';
    }

    /**
     * What Start a team says for one season ($season_info from season()), or
     * '' when neither division is full (its active teams and applications
     * reach its places): "Ranked is full for 2027 Pre-season. An Unranked team
     * can still get a place; a new Ranked team goes on the waiting list."
     */
    public static function full_words($season_info) {
        $full = array();
        $room = array();
        foreach ($season_info['divisions'] as $d => $info) {
            if (!empty($info['full'])) {
                $full[$d] = $info['label'];
            } else {
                $room[$d] = $info['label'];
            }
        }
        if (!$full) {
            return '';
        }
        $when = RSL_Seasons::short_label($season_info['season']);
        if (!$room) {
            return implode(' and ', $full) . ' are both full for ' . $when . '. A new team goes on the waiting list.';
        }
        $f = reset($full);
        $r = reset($room);
        return $f . ' is full for ' . $when . '. ' . (preg_match('/^[AEIOU]/', $r) ? 'An ' : 'A ') . $r . ' team can still get a place; a new ' . $f . ' team goes on the waiting list.';
    }

    /** What the captain sees about an application on the waiting list ($info: its division's, from season()). */
    public static function waiting_words($info) {
        return $info['label'] . ' is full for ' . RSL_Seasons::short_label($info['season']) . ', so your application is on the waiting list. '
            . 'If a place opens and the league approves it, you\'ll get the approval email with your team\'s invite link.';
    }

    /** The counts of the nights that have a slot. */
    private static function nights_with_slots($slots) {
        $on = array();
        foreach ((array) $slots as $s) {
            $s = intval($s);
            if ($s > 0) {
                $on[] = $s;
            }
        }
        return $on;
    }

    // ------------------------------------------------------------------
    // The wrapper (database)
    // ------------------------------------------------------------------

    /** The allowance: Most nights a team sits out in a season (RSL_Free_Agents settings). */
    public static function allowance() {
        $s = RSL_Free_Agents::settings();
        return isset($s['sit_out_nights']) && is_numeric($s['sit_out_nights'])
            ? max(0, min(self::MAX_ALLOWANCE, intval($s['sit_out_nights'])))
            : self::DEFAULT_ALLOWANCE;
    }

    /**
     * One season's places, division by division: array(season, allowance,
     * checked (it has regular nights), started (its first regular night is
     * today or earlier), divisions => division => array(division, label,
     * season, allowance, checked, started, places (null = not checked),
     * set_by_hand (the override), active, pending (count), order (pending ids,
     * oldest first), waiting (ids on the waiting list), left (places − active,
     * at least 0; null), full (active + pending ≥ places), slots (each regular
     * night's slots), slots_left (the regular nights after today))). A few
     * queries; not memoised, so a read after a change sees it.
     */
    public static function season($season) {
        global $wpdb;
        $allowance = self::allowance();
        $fa = RSL_Free_Agents::settings();
        $override = isset($fa['capacity_override']) && is_array($fa['capacity_override']) ? $fa['capacity_override'] : array();
        $today = current_time('Y-m-d');
        $regular = array();
        $started = false;
        foreach (RSL_Courts::season_slot_counts($season) as $row) {
            if ($row['night']->type !== 'regular') {
                continue;
            }
            $regular[] = $row;
            $started = $started || (string) $row['night']->night_date <= $today;
        }
        $active = array_fill_keys(array_keys(RSL_Teams::DIVISIONS), 0);
        $pending = array_fill_keys(array_keys(RSL_Teams::DIVISIONS), array());
        foreach ((array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, division, status, applied_at FROM " . RSL_Database::table('teams') . " WHERE season = %s AND status IN ('active', 'pending') ORDER BY applied_at, id",
            $season
        )) as $t) {
            if (!isset($active[$t->division])) {
                continue;
            }
            if ($t->status === 'active') {
                $active[$t->division]++;
            } else {
                $pending[$t->division][] = $t;
            }
        }
        $out = array('season' => $season, 'allowance' => $allowance, 'checked' => (bool) $regular, 'started' => $started, 'divisions' => array());
        foreach (RSL_Teams::DIVISIONS as $d => $label) {
            $slots = array();
            $left = array();
            foreach ($regular as $row) {
                $slots[] = intval($row['slots'][$d]);
                if ((string) $row['night']->night_date > $today) {
                    $left[] = intval($row['slots'][$d]);
                }
            }
            $set = isset($override[$d]) && is_numeric($override[$d]) ? intval($override[$d]) : 0;
            $places = $set > 0 ? $set : self::places($slots, $allowance);
            $q = self::queue($places, $active[$d], $pending[$d]);
            $out['divisions'][$d] = array(
                'division' => $d,
                'label' => $label,
                'season' => $season,
                'allowance' => $allowance,
                'checked' => (bool) $regular,
                'started' => $started,
                'places' => $places,
                'set_by_hand' => $set > 0,
                'active' => $active[$d],
                'pending' => count($pending[$d]),
                'order' => $q['order'],
                'waiting' => $q['waiting'],
                'left' => $places === null ? null : max(0, $places - $active[$d]),
                'full' => $places !== null && $active[$d] + count($pending[$d]) >= $places,
                'slots' => $slots,
                'slots_left' => $left,
            );
        }
        return $out;
    }

    /** Each division's places for a season: division => int, or null (not checked). Placement's court space. */
    public static function places_by_division($season) {
        $out = array();
        foreach (self::season($season)['divisions'] as $d => $info) {
            $out[$d] = $info['places'];
        }
        return $out;
    }

    /** Is this pending application on the waiting list? */
    public static function on_waiting_list($team) {
        return self::waiting_note($team) !== '';
    }

    /**
     * The captain's words for their pending application while it's on the
     * waiting list (waiting_words()), else '': the flash after applying and
     * the application card on the league page. Only ever about their own.
     */
    public static function waiting_note($team) {
        if (!$team || !is_object($team) || !isset($team->status) || $team->status !== 'pending' || !isset(RSL_Teams::DIVISIONS[$team->division])) {
            return '';
        }
        $info = self::season($team->season)['divisions'][$team->division];
        return in_array(intval($team->id), $info['waiting'], true) ? self::waiting_words($info) : '';
    }

    /**
     * After an admin made a team active in a division or moved one into it:
     * '' while it's within its places (or not checked), else "Ranked, 2027
     * Pre-season now has 31 teams for 30 places: about …".
     */
    public static function over_notice($season, $division) {
        if (!isset(RSL_Teams::DIVISIONS[$division])) {
            return '';
        }
        $info = self::season($season)['divisions'][$division];
        if ($info['places'] === null || $info['active'] <= $info['places']) {
            return '';
        }
        return $info['label'] . ', ' . RSL_Seasons::short_label($season) . ' now has ' . self::over_words($info, $info['active']) . '.';
    }
}
