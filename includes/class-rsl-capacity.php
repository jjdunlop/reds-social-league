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
 * division with no slot on any regular night has 0 places (no_slots); a
 * season with no regular nights yet isn't checked (null). Places stop at the
 * ceiling: MAX_PLACES (50, the most the Most teams per division setting
 * takes), or twice the fewest slots on a night when that's more, so allowance
 * 0 always gives 2 × the fewest slots and a bigger allowance never gives
 * fewer places. With an allowance at or above the nights that have a slot the
 * sum never binds, so it's the ceiling there; a large result is cut to it too
 * (12 slots on 3 nights at allowance 2 would be 72). clamped says the ceiling
 * set the places, so no word blames the allowance. The override (Most teams
 * per division above 0) wins.
 *
 * Taken places are the active teams in the season and division. The waiting
 * list is the pending applications there, oldest first (applied_at, id),
 * beyond the places left. Nothing is stored: it's worked out from the teams
 * table on each read, so approving, declining, withdrawing and a team leaving
 * move it on their own. An admin change that takes a place an ordinary
 * application was in line for says so (bumped(), would_bump()). Placement
 * keeps the places the ordinary applications are in line for free (held(),
 * club decision, 5 Nov 2026: first come, first served), so its new teams
 * take only the places left that nobody holds.
 *
 * Pure core (no database, so tests call it directly): places(),
 * places_detail(), ceiling(), sit_outs(), sit_out_numbers(), sit_out_words(),
 * over_words(), over_line(), queue(), bumped(), bumped_words(), would_bump(),
 * held(), held_line(), places_line(), full_words(), waiting_words().
 * season() / seasons() are the one wrapper, and every reader goes through
 * it: placement
 * (RSL_Placement::capacity() and check()), the Start a team form
 * (RSL_Portal::team_fields()), RSL_Teams::apply(), the captain's application
 * card and the admin screens. Nothing works out places another way.
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
        $x = self::places_detail($slots, $allowance);
        return $x['places'];
    }

    /**
     * places() with clamped: true when the ceiling set the places, not the
     * allowance (one more team would still keep every team within it). Returns
     * array(places, clamped).
     */
    public static function places_detail($slots, $allowance) {
        $slots = array_values((array) $slots);
        if (!$slots) {
            return array('places' => null, 'clamped' => false);
        }
        $on = self::nights_with_slots($slots);
        if (!$on) {
            return array('places' => 0, 'clamped' => false);
        }
        $allowance = max(0, intval($allowance));
        $top = self::ceiling($on);
        // Up to twice the fewest slots nobody sits out. The nights sat out minus allowance × n is
        // convex in n and 0 at n = 0, so the n that fit run from 0 up to the first that doesn't.
        $best = 2 * min($on);
        for ($n = $best + 1; $n <= $top; $n++) {
            if (self::sit_outs($n, $on) > $allowance * $n) {
                break;
            }
            $best = $n;
        }
        return array('places' => $best, 'clamped' => $best === $top && self::sit_outs($top + 1, $on) <= $allowance * ($top + 1));
    }

    /**
     * The most places a division's slots give, whatever the allowance:
     * MAX_PLACES, or twice the fewest slots on a night with any when that's
     * more (every team still plays every night there). 0 with no slot at all.
     */
    public static function ceiling($slots) {
        $on = self::nights_with_slots($slots);
        return $on ? max(self::MAX_PLACES, 2 * min($on)) : 0;
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
     * After an admin made a team active in a division or moved one into it
     * ($info: the division's, from season(), after the change): '' while it's
     * within its places (or not checked), else "Ranked, 2027 Pre-season now
     * has 31 teams for 30 places: about …".
     */
    public static function over_line($info) {
        if (!is_array($info) || $info['places'] === null || $info['active'] <= $info['places']) {
            return '';
        }
        return $info['label'] . ', ' . RSL_Seasons::short_label($info['season']) . ' now has ' . self::over_words($info, $info['active']) . '.';
    }

    /**
     * The allowance in words, after "with no team" or "without teams":
     * "sitting out more than 2 nights a season", or "sitting out" at 0.
     */
    public static function limit_words($allowance) {
        $n = intval($allowance);
        return $n > 0 ? 'sitting out more than ' . $n . ' night' . ($n === 1 ? '' : 's') . ' a season' : 'sitting out';
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
     * The applications an admin change put on the waiting list: the ids that
     * were ordinary in $before and are waiting in $after (one division's info
     * from season(), read before and after the change), leaving out $except,
     * the team the change was about. A list of ids.
     */
    public static function bumped($before, $after, $except = 0) {
        if (!is_array($before) || !is_array($after)) {
            return array();
        }
        $ordinary = array_diff(array_map('intval', (array) $before['order']), array_map('intval', (array) $before['waiting']));
        $out = array();
        foreach ((array) $after['waiting'] as $id) {
            if (intval($id) !== intval($except) && in_array(intval($id), $ordinary, true)) {
                $out[] = intval($id);
            }
        }
        return $out;
    }

    /**
     * "2 applications in Ranked, 2027 Pre-season are now on the waiting list."
     * $other: the change was about an application itself ("1 other application").
     * Never names a team.
     */
    public static function bumped_words($info, $n, $other = false) {
        $n = intval($n);
        return $n . ($other ? ' other' : '') . ' application' . ($n === 1 ? '' : 's') . ' in ' . $info['label'] . ', ' . RSL_Seasons::short_label($info['season'])
            . ' ' . ($n === 1 ? 'is' : 'are') . ' now on the waiting list.';
    }

    /**
     * How many of a division's ordinary applications ($info from season())
     * would go on the waiting list once it has $active_after active teams:
     * those the places left then no longer reach. 0 when not checked.
     */
    public static function would_bump($info, $active_after) {
        if (!is_array($info) || $info['places'] === null) {
            return 0;
        }
        return max(0, self::held($info) - max(0, intval($info['places']) - intval($active_after)));
    }

    /**
     * The places left that the ordinary applications hold ($info from
     * season()): one each, oldest first, so at most the places left. An
     * application on the waiting list holds nothing. 0 when not checked.
     * Placement makes new teams only in the places left that nobody holds
     * (club decision, 5 Nov 2026: first come, first served).
     */
    public static function held($info) {
        if (!is_array($info) || !array_key_exists('places', $info) || $info['places'] === null) {
            return 0;
        }
        return max(0, count((array) $info['order']) - count((array) $info['waiting']));
    }

    /**
     * The placement screen's line for one season and division ($info from
     * season()) while applications waiting for approval hold places, else '':
     * "Ranked, 2027 Pre-season: 2 of the 3 places left are held for
     * applications waiting for approval, so placement can make 1 new Ranked
     * team." Admin only: it counts other teams' applications.
     */
    public static function held_line($info) {
        $held = self::held($info);
        if (!$held) {
            return '';
        }
        $left = max($held, isset($info['left']) ? intval($info['left']) : 0);
        $room = $left - $held;
        $label = $info['label'];
        if ($room === 0) {
            $what = $left === 1 ? 'the 1 place left is held for an application'
                : ($left === 2 ? 'both places left are held for applications' : 'all ' . $left . ' places left are held for applications');
        } else {
            $what = $held . ' of the ' . $left . ' places left ' . ($held === 1 ? 'is held for an application' : 'are held for applications');
        }
        return $label . ', ' . RSL_Seasons::short_label($info['season']) . ': ' . $what . ' waiting for approval, so placement '
            . ($room === 0 ? 'can\'t make a new ' . $label . ' team' : 'can make ' . $room . ' new ' . $label . ' team' . ($room === 1 ? '' : 's')) . '.';
    }

    /**
     * The admin's places line for one season and division (info from
     * season()): "Ranked, 2027 Mid-season 2: 28 of 30 places taken, 4
     * applications waiting (2 on the waiting list)." A note in brackets says
     * where the places came from when it wasn't the allowance: set by hand,
     * no game slots this season, or the most a division takes (the ceiling).
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
        $note = '';
        if (!empty($info['set_by_hand'])) {
            $note = ' (set by hand)';
        } elseif (!empty($info['no_slots'])) {
            $note = ' (no game slots this season)';
        } elseif (!empty($info['clamped'])) {
            $note = ' (the most a division takes)';
        }
        return $head . $taken . $note . ', ' . $apps . '.';
    }

    /**
     * What Start a team says for one season ($season_info from season()), or
     * '' when neither division is full (its active teams and applications
     * reach its places): "Ranked is full for 2027 Pre-season. An Unranked team
     * can still get a place; a new Ranked team goes on the waiting list." A
     * division with no game slots all season says that instead of full.
     */
    public static function full_words($season_info) {
        $full = array();
        $none = array();
        $room = array();
        foreach ($season_info['divisions'] as $d => $info) {
            if (!empty($info['no_slots'])) {
                $none[$d] = $info['label'];
            } elseif (!empty($info['full'])) {
                $full[$d] = $info['label'];
            } else {
                $room[$d] = $info['label'];
            }
        }
        if (!$full && !$none) {
            return '';
        }
        $parts = array();
        if ($full) {
            $parts[] = count($full) > 1 ? implode(' and ', $full) . ' are both full' : reset($full) . ' is full';
        }
        if ($none) {
            $parts[] = count($none) > 1 ? implode(' and ', $none) . ' have no game slots' : reset($none) . ' has no game slots';
        }
        $head = implode(' and ', $parts) . ' for ' . RSL_Seasons::short_label($season_info['season']) . '.';
        if (!$room) {
            return $head . ' A new team goes on the waiting list.';
        }
        $f = reset($full) !== false ? reset($full) : reset($none);
        $r = reset($room);
        return $head . ' ' . (preg_match('/^[AEIOU]/', $r) ? 'An ' : 'A ') . $r . ' team can still get a place; a new ' . $f . ' team goes on the waiting list.';
    }

    /**
     * What the captain sees about an application on the waiting list ($info:
     * its division's, from season()). $with_season false leaves the season out,
     * for a message that has just named it.
     */
    public static function waiting_words($info, $with_season = true) {
        $when = $with_season ? ' for ' . RSL_Seasons::short_label($info['season']) : '';
        if (!empty($info['no_slots'])) {
            return $info['label'] . ' has no game slots' . ($with_season ? $when : ' that season') . ', so your application is on the waiting list. '
                . 'If the league adds ' . $info['label'] . ' courts and then approves your application, you\'ll get the approval email with your team\'s invite link.';
        }
        return $info['label'] . ' is full' . $when . ', so your application is on the waiting list. '
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
     * set_by_hand (the override), clamped (the ceiling set the places, not
     * the allowance), no_slots (no slot on any regular night, so 0 places),
     * active, pending (count), order (pending ids, oldest first), waiting
     * (ids on the waiting list), left (places − active, at least 0; null),
     * full (active + pending ≥ places), slots (each regular night's slots),
     * slots_left (the regular nights after today))). A few queries; not
     * memoised, so a read after a change sees it.
     */
    public static function season($season) {
        $all = self::seasons(array($season));
        return $all[(string) $season];
    }

    /**
     * season() for several seasons at once (season => the same array), for a
     * form or screen that shows more than one: the venues and courts, the
     * night exceptions and the teams are each read once for all of them.
     */
    public static function seasons($keys) {
        global $wpdb;
        $keys = array_values(array_unique(array_map('strval', (array) $keys)));
        $out = array();
        if (!$keys) {
            return $out;
        }
        $allowance = self::allowance();
        $fa = RSL_Free_Agents::settings();
        $override = isset($fa['capacity_override']) && is_array($fa['capacity_override']) ? $fa['capacity_override'] : array();
        $today = current_time('Y-m-d');
        $counts = RSL_Courts::seasons_slot_counts($keys);
        $zero = array_fill_keys(array_keys(RSL_Teams::DIVISIONS), 0);
        $none = array_fill_keys(array_keys(RSL_Teams::DIVISIONS), array());
        $active = array_fill_keys($keys, $zero);
        $pending = array_fill_keys($keys, $none);
        $in = implode(', ', array_fill(0, count($keys), '%s'));
        foreach ((array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, season, division, status, applied_at FROM " . RSL_Database::table('teams') . " WHERE season IN ($in) AND status IN ('active', 'pending') ORDER BY applied_at, id",
            $keys
        )) as $t) {
            if (!isset($active[$t->season][$t->division])) {
                continue;
            }
            if ($t->status === 'active') {
                $active[$t->season][$t->division]++;
            } else {
                $pending[$t->season][$t->division][] = $t;
            }
        }
        foreach ($keys as $season) {
            $regular = array();
            $started = false;
            foreach ($counts[$season] as $row) {
                if ($row['night']->type !== 'regular') {
                    continue;
                }
                $regular[] = $row;
                $started = $started || (string) $row['night']->night_date <= $today;
            }
            $one = array('season' => $season, 'allowance' => $allowance, 'checked' => (bool) $regular, 'started' => $started, 'divisions' => array());
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
                $rule = self::places_detail($slots, $allowance);
                $places = $set > 0 ? $set : $rule['places'];
                $act = $active[$season][$d];
                $pend = $pending[$season][$d];
                $q = self::queue($places, $act, $pend);
                $one['divisions'][$d] = array(
                    'division' => $d,
                    'label' => $label,
                    'season' => $season,
                    'allowance' => $allowance,
                    'checked' => (bool) $regular,
                    'started' => $started,
                    'places' => $places,
                    'set_by_hand' => $set > 0,
                    'clamped' => $set <= 0 && $rule['clamped'],
                    'no_slots' => $set <= 0 && $rule['places'] === 0,
                    'active' => $act,
                    'pending' => count($pend),
                    'order' => $q['order'],
                    'waiting' => $q['waiting'],
                    'left' => $places === null ? null : max(0, $places - $act),
                    'full' => $places !== null && $act + count($pend) >= $places,
                    'slots' => $slots,
                    'slots_left' => $left,
                );
            }
            $out[$season] = $one;
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
     * $with_season false leaves the season out, for a message that has just
     * named it.
     */
    public static function waiting_note($team, $with_season = true) {
        if (!$team || !is_object($team) || !isset($team->status) || $team->status !== 'pending' || !isset(RSL_Teams::DIVISIONS[$team->division])) {
            return '';
        }
        $info = self::season($team->season)['divisions'][$team->division];
        return in_array(intval($team->id), $info['waiting'], true) ? self::waiting_words($info, $with_season) : '';
    }

}
