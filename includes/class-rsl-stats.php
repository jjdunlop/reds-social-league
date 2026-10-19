<?php
/**
 * The season's story, for the game-style touches on public pages: tiers,
 * rating changes per night, form, upsets and achievements. Teams only.
 *
 * - Tiers (Ranked): Bronze → Silver → Gold → Platinum → Diamond by rating.
 *   A team still provisional is "In placements" (like ranked modes in
 *   games) — no tier and no rating change shown, which also keeps the
 *   hidden starting estimate from being worked out.
 * - Upset: the winner had less than UPSET_CHANCE to win a set going in.
 * - Only published nights count.
 *
 * Built from RSL_Ratings snapshots after each round (memoised per request).
 */

if (!defined('ABSPATH')) {
    exit;
}

class RSL_Stats {

    // key, label, lowest rating, colour — highest first. Rename freely.
    const TIERS = array(
        array('diamond', 'Diamond', 1800, '#4f5fd6'),
        array('platinum', 'Platinum', 1650, '#16928c'),
        array('gold', 'Gold', 1500, '#b98a00'),
        array('silver', 'Silver', 1350, '#6f7a8a'),
        array('bronze', 'Bronze', 0, '#9a5b27'),
    );
    const UPSET_CHANCE = 0.35;

    private static $memo = array();

    /** array(key, label, colour) for a rating; "In placements" while provisional. */
    public static function tier($rating, $provisional = false) {
        if ($rating === null || $provisional) {
            return array('key' => 'placement', 'label' => 'In placements', 'colour' => '#6b7280');
        }
        foreach (self::TIERS as $t) {
            if ($rating >= $t[2]) {
                return array('key' => $t[0], 'label' => $t[1], 'colour' => $t[3]);
            }
        }
        $tiers = self::TIERS;
        $last = end($tiers);
        return array('key' => $last[0], 'label' => $last[1], 'colour' => $last[3]);
    }

    public static function flush() {
        self::$memo = array();
    }

    /**
     * array(
     *   games => game_id => array(p_home (set chance going in, Ranked), upset_team, delta => team_id => change|null),
     *   teams => team_id => array(form (list, newest last: W/L/D), streak (e.g. 'W3'), now (rating, sd, provisional,
     *            position, tier), history (date => array(position, tier)), achievements (key => label/why/date), peak)
     * )
     */
    public static function season($season) {
        if (isset(self::$memo[$season])) {
            return self::$memo[$season];
        }
        $games = array_values(array_filter(RSL_Fixtures::games_for_season($season), function ($g) {
            return !empty($g->published_at);
        }));
        $done = array_values(array_filter($games, function ($g) { return $g->status === 'played' || $g->status === 'forfeit'; }));
        $dates = array_values(array_unique(array_map(function ($g) { return $g->night_date; }, $done)));
        sort($dates);
        $teams = array();
        foreach (RSL_Teams::list_for_season($season) as $t) {
            $teams[intval($t->id)] = array('team' => $t, 'form' => array(), 'streak' => '', 'history' => array(), 'achievements' => array(), 'peak' => null, 'now' => null);
        }
        $out = array('games' => array(), 'teams' => array(), 'dates' => $dates);

        $before = $dates ? RSL_Ratings::compute($season, date('Y-m-d', strtotime($dates[0] . ' -1 day'))) : array();
        $tier_rank = array();
        foreach (array_reverse(self::TIERS) as $i => $t) {
            $tier_rank[$t[0]] = $i;
        }
        $wins_in_row = array();
        foreach ($dates as $di => $date) {
            $after = RSL_Ratings::compute($season, $date);
            $positions = self::positions($after);
            $tonight = array_filter($done, function ($g) use ($date) { return $g->night_date === $date; });
            $night_wins = array();
            foreach ($tonight as $g) {
                $h = intval($g->home_team_id);
                $a = intval($g->away_team_id);
                $info = array('p_home' => null, 'upset_team' => null, 'delta' => array());
                if ($g->division === 'ranked' && isset($before[$h]) && isset($before[$a])) {
                    $info['p_home'] = RSL_Pairing::set_win_probability($before[$h]['rating'], $before[$h]['sd'], $before[$a]['rating'], $before[$a]['sd']);
                    foreach (array($h, $a) as $t) {
                        // No change shown while a team is in placements.
                        $info['delta'][$t] = (!$before[$t]['provisional'] && isset($after[$t]) && !$after[$t]['provisional'])
                            ? (int) round($after[$t]['rating'] - $before[$t]['rating']) : null;
                    }
                }
                foreach (array($h, $a) as $t) {
                    if (!isset($teams[$t])) {
                        continue;
                    }
                    $r = RSL_Fixtures::result_for($g, $t);
                    $letter = in_array($r['outcome'], array('win', 'forfeit_win'), true) ? 'W' : (in_array($r['outcome'], array('loss', 'forfeit_loss'), true) ? 'L' : 'D');
                    $teams[$t]['form'][] = $letter;
                    $wins_in_row[$t] = $letter === 'W' ? (isset($wins_in_row[$t]) ? $wins_in_row[$t] : 0) + 1 : 0;
                    if ($letter === 'W') {
                        $night_wins[$t] = (isset($night_wins[$t]) ? $night_wins[$t] : 0) + 1;
                    }
                    if ($r['outcome'] === 'win') {
                        $chance = $info['p_home'] === null ? null : ($t === $h ? $info['p_home'] : 1 - $info['p_home']);
                        if ($chance !== null && $chance < self::UPSET_CHANCE) {
                            $info['upset_team'] = $t;
                            self::earn($teams[$t], 'giant_killer', 'Giant killer', 'Beat a team they had a ' . round(100 * $chance) . '% set chance against', $date);
                        }
                        if ($r['sets'] && $r['sets'][0][0] < $r['sets'][0][1]) {
                            self::earn($teams[$t], 'comeback', 'Comeback', 'Won after losing the first set', $date);
                        }
                        $flawless = $r['against'] === 0 && $r['for'] >= 2;
                        foreach ($r['sets'] as $set) {
                            $flawless = $flawless && $set[1] < 15;
                        }
                        if ($flawless) {
                            self::earn($teams[$t], 'flawless', 'Flawless', 'Won 2–0 without letting the other team reach 15', $date);
                        }
                    }
                    if ($wins_in_row[$t] >= 3) {
                        self::earn($teams[$t], 'on_fire', 'On fire', 'Three wins in a row', $date);
                    }
                }
                $out['games'][intval($g->id)] = $info;
            }
            foreach ($night_wins as $t => $n) {
                if ($n >= 2) {
                    self::earn($teams[$t], 'double_trouble', 'Double trouble', 'Won both games of a double-header', $date);
                }
            }
            foreach ($positions as $t => $pos) {
                if (!isset($teams[$t])) {
                    continue;
                }
                $tier = self::tier($after[$t]['rating'], $after[$t]['provisional']);
                $teams[$t]['history'][$date] = array('position' => $pos, 'tier' => $tier);
                if ($pos === 1 && !$after[$t]['provisional']) {
                    self::earn($teams[$t], 'top_spot', 'Top spot', 'Top of the Ranked ladder', $date);
                }
                if ($tier['key'] !== 'placement' && ($teams[$t]['peak'] === null || $tier_rank[$tier['key']] > $tier_rank[$teams[$t]['peak']['key']])) {
                    $teams[$t]['peak'] = $tier;
                }
            }
            $before = $after;
        }

        $now = RSL_Ratings::compute($season);
        $positions = self::positions($now);
        foreach ($teams as $t => &$row) {
            if (isset($wins_in_row[$t]) || $row['form']) {
                $last = end($row['form']);
                $n = 0;
                for ($i = count($row['form']) - 1; $i >= 0 && $row['form'][$i] === $last; $i--) {
                    $n++;
                }
                $row['streak'] = $last . $n;
            }
            $row['form'] = array_slice($row['form'], -5);
            if ($row['team']->division === 'ranked' && isset($now[$t]) && $now[$t]['games'] > 0) {
                $row['now'] = array('rating' => (int) round($now[$t]['rating']), 'provisional' => $now[$t]['provisional'],
                    'position' => isset($positions[$t]) ? $positions[$t] : null, 'tier' => self::tier($now[$t]['rating'], $now[$t]['provisional']));
            } elseif ($row['team']->division === 'ranked') {
                $row['now'] = array('rating' => null, 'provisional' => true, 'position' => null, 'tier' => self::tier(null, true));
            }
        }
        unset($row);
        $out['teams'] = $teams;
        return self::$memo[$season] = $out;
    }

    /** Set-win chance for a game not yet played (Ranked), from the latest ratings. */
    public static function chance_now($season, $home_id, $away_id) {
        $now = RSL_Ratings::compute($season);
        if (!isset($now[$home_id]) || !isset($now[$away_id])) {
            return null;
        }
        return RSL_Pairing::set_win_probability($now[$home_id]['rating'], $now[$home_id]['sd'], $now[$away_id]['rating'], $now[$away_id]['sd']);
    }

    /** Ladder places among teams that have played (team_id => 1, 2, ...). */
    private static function positions($ratings) {
        $rated = array_filter($ratings, function ($r) { return $r['games'] > 0; });
        uasort($rated, function ($x, $y) { return $y['rating'] <=> $x['rating']; });
        $pos = array();
        $i = 0;
        foreach (array_keys($rated) as $t) {
            $pos[$t] = ++$i;
        }
        return $pos;
    }

    private static function earn(&$row, $key, $label, $why, $date) {
        if (!isset($row['achievements'][$key])) {
            $row['achievements'][$key] = array('label' => $label, 'why' => $why, 'date' => $date);
        }
    }
}
