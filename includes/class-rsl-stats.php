<?php
/**
 * The season's story, for the game-style touches on public pages. Teams only.
 *
 * Every rating here is the LADDER rating (RSL_Ratings::compute(..., 'ladder'):
 * every team starts on the season's frozen start and moves on results
 * alone; it never reads a level, for any team at any game count). So
 * earned tiers and their buffer, first ratings, changes, upsets and leaders
 * hold nothing of the captain's level, and editing a level moves none of
 * them. The seeded fixtures rating is never read here.
 *
 * Two kinds of progress, kept apart on purpose:
 *  - The RATING stays honest (it orders the Ranked ladder). It can go down.
 *  - What a team EARNS:
 *    - Tier badge (Ranked): Bronze → Silver → Gold → Platinum → Diamond.
 *      Tiers follow the rating with a small buffer so a single result
 *      doesn't flip a team between tiers: up as soon as the rating crosses
 *      into a tier; down one tier after DEMOTE_ROUNDS rounds in a row more
 *      than DEMOTE_BUFFER below the tier's floor (the first such round
 *      shows "at risk"). While provisional (under PLACEMENT_GAMES games,
 *      or its ladder sd still above RSL_Ratings::LADDER_PROVISIONAL_SD), a
 *      team is "In placements" — no tier and no rating change shown: a
 *      rating from a game or two can still swing a long way.
 *    - Season Points (both divisions): points for turning up, winning,
 *      close games, upsets and achievements. Only ever go up; every
 *      LEVEL_POINTS is a new level.
 *
 * Also: per-night rating changes, form, streaks, upsets (winner had under
 * UPSET_CHANCE to win a set), close games, achievements, season leaders,
 * and finals eligibility: finals_min_games() regular-season games played
 * in the team's own division (0.18.1; a double-header is two, a bye or a
 * forfeit none).
 * No set chance, upset or rating change involves a team in placements: its
 * rating is still too unsettled to call a result an upset or to show a
 * change. (Before 0.18.1 this also hid the captain's level estimate, which
 * the one rating then held; the ladder rating no longer holds it, and the
 * rules are unchanged.)
 * Only published nights count. Built from RSL_Ratings snapshots after
 * each round; memoised per request and saved between page views under a
 * fingerprint of the rows it's built from (any change makes a new key).
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
    const DEMOTE_BUFFER = 50;       // rating points below a tier's floor before a round counts against it
    const DEMOTE_ROUNDS = 2;        // rounds in a row below that before dropping a tier
    const UPSET_CHANCE = 0.35;
    const CLOSE_POINTS = 6;         // a game within this many points overall is a close one
    // 'played' is once per night (a double-header doesn't double it; a bye
    // the fixtures gave a team still counts); the rest are per game.
    const POINTS = array('played' => 10, 'win' => 5, 'draw' => 3, 'close' => 3, 'upset' => 5, 'achievement' => 10);
    const LEVEL_POINTS = 40;
    const FINALS_MIN_GAMES = 5;     // default; option rsl_finals_min_games / filter rsl_finals_min_games
    const CACHE_PREFIX = 'rsl_stats_'; // transient per season: array(key => fingerprint, data => season())

    /**
     * Regular-season games a Ranked team must have PLAYED to make finals
     * (0.18.1; before, regular-season rounds, option rsl_finals_min_rounds).
     * A game counts once it has a result and isn't a forfeit; a
     * double-header is two games; a bye counts nothing; only games in the
     * team's own division count, so a team moved to Ranked starts again
     * there (build()).
     */
    public static function finals_min_games() {
        $n = intval(get_option('rsl_finals_min_games', self::FINALS_MIN_GAMES));
        return (int) apply_filters('rsl_finals_min_games', $n > 0 ? $n : self::FINALS_MIN_GAMES);
    }

    private static $memo = array();

    /** array(key, label, colour, floor, rank) for a rating; "In placements" while provisional. */
    public static function tier($rating, $provisional = false) {
        if ($rating === null || $provisional) {
            return self::placement();
        }
        foreach (self::TIERS as $t) {
            if ($rating >= $t[2]) {
                return self::tier_by_key($t[0]);
            }
        }
        $tiers = self::TIERS;
        $last = end($tiers);
        return self::tier_by_key($last[0]);
    }

    public static function tier_by_key($key) {
        $count = count(self::TIERS);
        foreach (self::TIERS as $i => $t) {
            if ($t[0] === $key) {
                return array('key' => $t[0], 'label' => $t[1], 'colour' => $t[3], 'floor' => $t[2], 'rank' => $count - 1 - $i);
            }
        }
        return self::placement();
    }

    private static function tier_by_rank($rank) {
        $tiers = array_reverse(self::TIERS);
        $rank = max(0, min(count($tiers) - 1, $rank));
        return self::tier_by_key($tiers[$rank][0]);
    }

    private static function placement() {
        return array('key' => 'placement', 'label' => 'In placements', 'colour' => '#6b7280', 'floor' => null, 'rank' => -1);
    }

    /** The tier above (or null at the top). */
    public static function next_tier($tier) {
        if ($tier['rank'] < 0 || $tier['rank'] >= count(self::TIERS) - 1) {
            return null;
        }
        return self::tier_by_rank($tier['rank'] + 1);
    }

    /** Season Points -> array(level, into (points into this level), need (per level)). */
    public static function level($points) {
        return array('level' => intdiv(max(0, $points), self::LEVEL_POINTS) + 1, 'into' => max(0, $points) % self::LEVEL_POINTS, 'need' => self::LEVEL_POINTS);
    }

    /** Forget this request's copy. (The copy saved between page views is keyed by the data, so it never needs flushing.) */
    public static function flush() {
        self::$memo = array();
    }

    /**
     * The day a team joined the league (Y-m-d): when it was approved, or
     * created if it never needed approving. Nights before that aren't its byes.
     */
    public static function joined_date($team) {
        return substr((string) (!empty($team->decided_at) ? $team->decided_at : $team->created_at), 0, 10);
    }

    /**
     * array(
     *   games => game_id => array(p_home, upset_team, close, delta => team_id => change|null),
     *   teams => team_id => array(form, streak, history, achievements, peak, upsets, points, now => array(rating,
     *            provisional, position, tier (earned), at_risk, progress (0–1 to next tier), next),
     *            games (regular-season games played in its own division), finals => array(games, need,
     *            eligible, possible (true while its games played and its games to come can reach the minimum))),
     *   leaders => array(improved, giant_killer, points => division => row),
     *   ratings => the latest ladder ratings, RSL_Ratings::compute($season, null, 'ladder'),
     *   meetings => meeting_key => list of array(date, status, winner (team id, 0 = draw), sets (home, away)), oldest first
     * )
     */
    public static function season($season) {
        if (isset(self::$memo[$season])) {
            return self::$memo[$season];
        }
        // Worked out before the build, so a change made meanwhile still
        // makes a new key next time.
        $key = self::fingerprint($season);
        $saved = $key !== null ? get_transient(self::CACHE_PREFIX . $season) : false;
        if (is_array($saved) && isset($saved['key']) && $saved['key'] === $key) {
            return self::$memo[$season] = $saved['data'];
        }
        // This request may already hold an RSL_Ratings snapshot read before
        // the fingerprint; building from it could save old data under the new
        // key. Read it afresh (misses are rare, so two extra queries).
        RSL_Ratings::flush();
        $out = self::build($season);
        if ($key !== null) {
            set_transient(self::CACHE_PREFIX . $season, array('key' => $key, 'data' => $out), HOUR_IN_SECONDS);
        }
        return self::$memo[$season] = $out;
    }

    /**
     * A cheap fingerprint of everything season() is built from: the season's
     * games (results, not live scores), nights, active teams and night
     * requests, plus the finals rule (named, finals-games:5, so stats saved
     * under 0.18.1's earlier count of rounds are never reused), the plugin
     * version and the ladder model's settings (RSL_Ratings::ladder_model(),
     * so a same-version build that changes the ladder gets a fresh key).
     * One query; null if it fails (then nothing is saved). Each row is hashed with MD5
     * (64 bits of it), not CRC32: CRC32 is linear over XOR, so two rows
     * swapping values (scores entered against the wrong court, then fixed)
     * would cancel out and keep the old key.
     */
    private static function fingerprint($season) {
        global $wpdb;
        $nights = RSL_Database::table('nights');
        $row = $wpdb->get_row($wpdb->prepare("
            SELECT COUNT(*) AS n, BIT_XOR(CAST(CONV(SUBSTRING(MD5(x), 1, 16), 16, 10) AS UNSIGNED)) AS h FROM (
                SELECT CONCAT_WS('|', 'g', id, night_id, division, start_time, home_team_id, away_team_id, status, sets, home_sets, away_sets, IFNULL(forfeit_team_id, 0)) AS x
                    FROM " . RSL_Database::table('games') . " WHERE season = %s
                UNION ALL
                SELECT CONCAT_WS('|', 'n', id, night_date, type, IFNULL(published_at, '')) FROM $nights WHERE season = %s
                UNION ALL
                SELECT CONCAT_WS('|', 't', id, status, division, est_level, name, slug, colour, IFNULL(created_at, ''), IFNULL(decided_at, ''))
                    FROM " . RSL_Database::table('teams') . " WHERE season = %s AND status = 'active'
                UNION ALL
                SELECT CONCAT_WS('|', 'r', r.id, r.team_id, r.night_id, r.type, r.status)
                    FROM " . RSL_Database::table('requests') . " r JOIN $nights rn ON rn.id = r.night_id WHERE rn.season = %s
            ) f", $season, $season, $season, $season));
        if (!$row) {
            return null;
        }
        return implode('|', array($row->n, $row->h, 'finals-games:' . self::finals_min_games(), defined('RSL_VERSION') ? RSL_VERSION : '', RSL_Ratings::ladder_model()));
    }

    private static function build($season) {
        $data = RSL_Ratings::season_data($season);
        $games = array_values(array_filter($data['games'], function ($g) {
            return !empty($g->published_at);
        }));
        $done = array_values(array_filter($games, function ($g) { return $g->status === 'played' || $g->status === 'forfeit'; }));
        $dates = array_values(array_unique(array_map(function ($g) { return $g->night_date; }, $done)));
        sort($dates);
        $teams = array();
        foreach ($data['teams'] as $t) {
            $teams[intval($t->id)] = array('team' => $t, 'form' => array(), 'streak' => '', 'history' => array(), 'achievements' => array(),
                'peak' => null, 'now' => null, 'upsets' => 0, 'points' => 0, 'earned' => null, 'below' => 0, 'first_rating' => null,
                'games' => 0, 'finals' => null);
        }
        $out = array('games' => array(), 'teams' => array(), 'dates' => $dates, 'leaders' => array(), 'ratings' => array(), 'meetings' => array());

        // The LADDER rating throughout (never the seeded fixtures one): this is all public.
        $before = $dates ? RSL_Ratings::compute($season, date('Y-m-d', strtotime($dates[0] . ' -1 day')), 'ladder') : array();
        $weeks_off = RSL_Requests::weeks_off($season);
        $wins_in_row = array();
        foreach ($dates as $date) {
            $after = RSL_Ratings::compute($season, $date, 'ladder');
            $positions = self::positions($after);
            $tonight = array_filter($done, function ($g) use ($date) { return $g->night_date === $date; });
            $first = reset($tonight);
            $cant_make = RSL_Requests::for_night(intval($first->night_id), 'cant_make', array('active', 'handled'));
            $night_wins = array();
            $turned_up = array();   // team => true once it has a game (not a forfeit loss) tonight
            $had_game = array();    // team => true if it had any game tonight
            $divisions_tonight = array();
            foreach ($tonight as $g) {
                $h = intval($g->home_team_id);
                $a = intval($g->away_team_id);
                $divisions_tonight[$g->division] = true;
                $info = array('p_home' => null, 'upset_team' => null, 'close' => false, 'delta' => array());
                if ($g->status === 'played') {
                    $sets = RSL_Fixtures::parse_sets($g->sets);
                    $sets = is_wp_error($sets) ? array() : $sets;
                    $pts = array(0, 0);
                    foreach ($sets as $s) {
                        $pts[0] += $s[0];
                        $pts[1] += $s[1];
                    }
                    $info['close'] = count($sets) >= 3 || intval($g->home_sets) === intval($g->away_sets) || abs($pts[0] - $pts[1]) <= self::CLOSE_POINTS;
                }
                // For hype(): who won the last time these two met.
                $out['meetings'][RSL_Pairing::meeting_key($h, $a)][] = array('date' => $date, 'status' => $g->status,
                    'winner' => intval($g->home_sets) === intval($g->away_sets) ? 0 : (intval($g->home_sets) > intval($g->away_sets) ? $h : $a),
                    'sets' => array(intval($g->home_sets), intval($g->away_sets)));
                if ($g->division === 'ranked' && isset($before[$h]) && isset($before[$a])) {
                    // No set chance (so no upset) while either team is in placements.
                    if (!$before[$h]['provisional'] && !$before[$a]['provisional']) {
                        $info['p_home'] = RSL_Pairing::set_win_probability($before[$h]['rating'], $before[$h]['sd'], $before[$a]['rating'], $before[$a]['sd']);
                    }
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
                    // Finals count regular-season games played: a double-header
                    // is two; a forfeit, won or lost, is none. Only games in the
                    // team's own division count, as in its ladder record (P):
                    // a team moved to Ranked starts its finals count there.
                    if ($g->status === 'played' && $g->night_type !== 'finals' && $g->division === $teams[$t]['team']->division) {
                        $teams[$t]['games']++;
                    }
                    // Season Points: turning up counts most — once a night.
                    $had_game[$t] = true;
                    if ($r['outcome'] !== 'forfeit_loss' && empty($turned_up[$t])) {
                        $turned_up[$t] = true;
                        $teams[$t]['points'] += self::POINTS['played'];
                    }
                    if ($r['outcome'] === 'win') {
                        $teams[$t]['points'] += self::POINTS['win'];
                    } elseif ($r['outcome'] === 'draw') {
                        $teams[$t]['points'] += self::POINTS['draw'];
                    }
                    if ($info['close']) {
                        $teams[$t]['points'] += self::POINTS['close'];
                    }
                    if ($r['outcome'] === 'win') {
                        $chance = $info['p_home'] === null ? null : ($t === $h ? $info['p_home'] : 1 - $info['p_home']);
                        if ($chance !== null && $chance < self::UPSET_CHANCE) {
                            $info['upset_team'] = $t;
                            $teams[$t]['upsets']++;
                            $teams[$t]['points'] += self::POINTS['upset'];
                            self::earn($teams[$t], 'giant_killer', 'Giant killer', 'Beat a higher-rated team', $date);
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
            // A team the fixtures left out (odd numbers) isn't penalised: it
            // gets the turn-up points. A week off the team asked for isn't a
            // bye, and nor is a night it said it couldn't make (its game
            // unplayed). No bye is a game played, so none counts towards
            // finals (since 0.18.1; before, a bye counted as a round).
            foreach ($teams as $t => &$row) {
                if (empty($divisions_tonight[$row['team']->division]) || !empty($had_game[$t])) {
                    continue;
                }
                $joined = self::joined_date($row['team']);
                if (($joined !== '' && $joined > $date) || isset($weeks_off[$date][$t]) || isset($cant_make[$t])) {
                    continue; // not in the league yet that night, took the week off, or couldn't make it
                }
                $row['points'] += self::POINTS['played'];
            }
            unset($row);

            // Earned tiers: the rating's tier, with the demotion buffer.
            foreach ($positions as $t => $pos) {
                if (!isset($teams[$t])) {
                    continue;
                }
                $row = &$teams[$t];
                $rating = $after[$t]['rating'];
                if ($after[$t]['provisional']) {
                    $tier = self::placement();
                } else {
                    if ($row['first_rating'] === null) {
                        $row['first_rating'] = $rating;
                    }
                    $step = self::step_tier($row['earned'], $row['below'], $rating);
                    if ($step['promoted']) {
                        self::earn($row, 'promoted', 'Promoted', 'Moved up a tier', $date);
                    }
                    $row['earned'] = $step['earned'];
                    $row['below'] = $step['below'];
                    $tier = $row['earned'];
                    if ($row['peak'] === null || $tier['rank'] > $row['peak']['rank']) {
                        $row['peak'] = $tier;
                    }
                }
                $row['history'][$date] = array('position' => $pos, 'tier' => $tier);
                if ($pos === 1 && !$after[$t]['provisional']) {
                    self::earn($row, 'top_spot', 'Top spot', 'Top of the Ranked ladder', $date);
                }
                unset($row);
            }
            $before = $after;
        }

        $now = RSL_Ratings::compute($season, null, 'ladder');
        $positions = self::positions($now);
        // Finals: games played (above) against finals_min_games(). A team can
        // still get there if its games played, plus its games to come, reach
        // it. Games to come: its games in published regular-season fixtures
        // with no result yet, in its own division (a double-header there is
        // two, a night it isn't in is none, and a game either team has said
        // it can't make is none: the league calls it off or records a
        // forfeit), plus two for each regular night after the last night
        // with a result whose fixtures aren't out yet. Two, not one: until
        // the fixtures are out any team can ask for two games, and the draw
        // gives a team at most two a night, so a blank mark (can't get
        // there) never turns into a tick.
        $min_games = self::finals_min_games();
        $most_a_night = 2;
        $last_date = $dates ? end($dates) : '';
        $to_play = array();
        $called_off = array(); // night id => team id => true: a can't-make-it, active or handled
        foreach ($games as $g) {
            if ($g->status !== 'scheduled' || $g->night_type === 'finals') {
                continue;
            }
            $night_id = intval($g->night_id);
            if (!isset($called_off[$night_id])) {
                $called_off[$night_id] = RSL_Requests::for_night($night_id, 'cant_make', array('active', 'handled'));
            }
            $pair = array(intval($g->home_team_id), intval($g->away_team_id));
            if (isset($called_off[$night_id][$pair[0]]) || isset($called_off[$night_id][$pair[1]])) {
                continue;
            }
            foreach ($pair as $t) {
                if (isset($teams[$t]) && $g->division === $teams[$t]['team']->division) {
                    $to_play[$t] = (isset($to_play[$t]) ? $to_play[$t] : 0) + 1;
                }
            }
        }
        $nights_left = count(array_filter(RSL_Courts::nights($season), function ($n) use ($last_date) {
            return $n->type === 'regular' && empty($n->published_at) && $n->night_date > $last_date;
        }));
        foreach ($teams as $t => &$row) {
            $could = $row['games'] + (isset($to_play[$t]) ? $to_play[$t] : 0) + $most_a_night * $nights_left;
            $row['finals'] = array('games' => $row['games'], 'need' => max(0, $min_games - $row['games']),
                'eligible' => $row['games'] >= $min_games, 'possible' => $could >= $min_games);
            if ($row['form']) {
                $last = end($row['form']);
                $n = 0;
                for ($i = count($row['form']) - 1; $i >= 0 && $row['form'][$i] === $last; $i--) {
                    $n++;
                }
                $row['streak'] = $last . $n;
            }
            $row['form'] = array_slice($row['form'], -5);
            $row['points'] += self::POINTS['achievement'] * count($row['achievements']);
            if ($row['team']->division !== 'ranked') {
                continue;
            }
            if (isset($now[$t]) && $now[$t]['games'] > 0 && !$now[$t]['provisional'] && $row['earned']) {
                $tier = $row['earned'];
                $next = self::next_tier($tier);
                $rating = $now[$t]['rating'];
                $row['now'] = array('rating' => (int) round($rating), 'provisional' => false,
                    'position' => isset($positions[$t]) ? $positions[$t] : null, 'tier' => $tier, 'next' => $next,
                    'at_risk' => $row['below'] > 0,
                    'progress' => $next ? max(0, min(1, ($rating - $tier['floor']) / ($next['floor'] - $tier['floor']))) : 1);
            } else {
                $row['now'] = array('rating' => isset($now[$t]) && $now[$t]['games'] > 0 ? (int) round($now[$t]['rating']) : null, 'provisional' => true,
                    'position' => isset($positions[$t]) ? $positions[$t] : null, 'tier' => self::placement(), 'next' => null, 'at_risk' => false, 'progress' => 0);
            }
        }
        unset($row);

        // Season leaders.
        $leaders = array('improved' => null, 'giant_killer' => null, 'points' => array());
        foreach ($teams as $t => $row) {
            if ($row['team']->division === 'ranked' && $row['first_rating'] !== null && isset($now[$t]) && !$now[$t]['provisional']) {
                $gain = (int) round($now[$t]['rating'] - $row['first_rating']);
                if ($gain > 0 && ($leaders['improved'] === null || $gain > $leaders['improved']['value'])) {
                    $leaders['improved'] = array('team' => $row['team'], 'value' => $gain);
                }
            }
            if ($row['upsets'] > 0 && ($leaders['giant_killer'] === null || $row['upsets'] > $leaders['giant_killer']['value'])) {
                $leaders['giant_killer'] = array('team' => $row['team'], 'value' => $row['upsets']);
            }
            $d = $row['team']->division;
            if ($row['points'] > 0 && (!isset($leaders['points'][$d]) || $row['points'] > $leaders['points'][$d]['value'])) {
                $leaders['points'][$d] = array('team' => $row['team'], 'value' => $row['points']);
            }
        }
        $out['leaders'] = $leaders;
        $out['teams'] = $teams;
        $out['ratings'] = $now;
        return $out;
    }

    /**
     * One round of the earned-tier rules (pure). $earned: the tier held
     * (null before the first settled round); $below: rounds in a row it has
     * been more than DEMOTE_BUFFER below that tier's floor. Up as soon as
     * the rating is in a higher tier; down one tier after DEMOTE_ROUNDS
     * such rounds in a row. Returns array(earned, below, promoted).
     */
    public static function step_tier($earned, $below, $rating) {
        $raw = self::tier($rating);
        if ($earned === null || $earned['rank'] < 0) {
            return array('earned' => $raw, 'below' => 0, 'promoted' => false);
        }
        if ($raw['rank'] > $earned['rank']) {
            return array('earned' => $raw, 'below' => 0, 'promoted' => true);
        }
        // Holding the tier: not far below it, or it's the bottom tier.
        if ($rating >= $earned['floor'] - self::DEMOTE_BUFFER || $earned['rank'] === 0) {
            return array('earned' => $earned, 'below' => 0, 'promoted' => false);
        }
        $below++;
        if ($below < self::DEMOTE_ROUNDS) {
            return array('earned' => $earned, 'below' => $below, 'promoted' => false);
        }
        return array('earned' => self::tier_by_rank(max($earned['rank'] - 1, $raw['rank'])), 'below' => 0, 'promoted' => false);
    }

    /**
     * Set-win chance for a game not yet played (Ranked), from the latest
     * ladder ratings — null while either team is in placements (its rating
     * is still too unsettled to say). Public pages use it only for the
     * Even match tag (RSL_Public_Fixtures::SHOW_SET_CHANCES is off); the admin set chances come
     * from the fixtures rating (RSL_Pairing::explain_night()).
     */
    public static function chance_now($season, $home_id, $away_id) {
        $now = self::season($season)['ratings'];
        if (!isset($now[$home_id]) || !isset($now[$away_id]) || $now[$home_id]['provisional'] || $now[$away_id]['provisional']) {
            return null;
        }
        return RSL_Pairing::set_win_probability($now[$home_id]['rating'], $now[$home_id]['sd'], $now[$away_id]['rating'], $now[$away_id]['sd']);
    }

    /**
     * Hype for a game still to come — no odds: Top-of-table clash, Even
     * match (both teams out of placements), Rematch (with last time's
     * result), First meeting, Win streak on the line. Returns a list of
     * short labels. No queries once season() is loaded.
     */
    public static function hype($g) {
        $stats = self::season($g->season);
        $h = intval($g->home_team_id);
        $a = intval($g->away_team_id);
        $tags = array();
        $ph = isset($stats['teams'][$h]['now']['position']) ? $stats['teams'][$h]['now']['position'] : null;
        $pa = isset($stats['teams'][$a]['now']['position']) ? $stats['teams'][$a]['now']['position'] : null;
        if ($g->division === 'ranked' && $ph && $pa && $ph <= 3 && $pa <= 3) {
            $tags[] = 'Top-of-table clash';
        } elseif ($g->division === 'ranked') {
            $p = self::chance_now($g->season, $h, $a);
            if ($p !== null && abs($p - 0.5) <= 0.07) {
                $tags[] = 'Even match';
            }
        }
        // The latest meeting before this game's night (season() lists them oldest first).
        $last = null;
        $key = RSL_Pairing::meeting_key($h, $a);
        foreach (isset($stats['meetings'][$key]) ? $stats['meetings'][$key] : array() as $m) {
            if ($m['date'] < $g->night_date) {
                $last = $m;
            }
        }
        if ($last) {
            if ($last['status'] === 'forfeit') {
                $tags[] = 'Rematch';
            } elseif ($last['winner'] === 0) {
                $tags[] = 'Rematch — last time it finished ' . $last['sets'][0] . '–' . $last['sets'][1];
            } else {
                $winner = $last['winner'] === $h ? $g->home_name : $g->away_name;
                $tags[] = 'Rematch — ' . $winner . ' won ' . max($last['sets']) . '–' . min($last['sets']) . ' last time';
            }
        } else {
            $tags[] = 'First meeting';
        }
        foreach (array($h => $g->home_name, $a => $g->away_name) as $t => $name) {
            $streak = isset($stats['teams'][$t]['streak']) ? $stats['teams'][$t]['streak'] : '';
            if ($streak !== '' && $streak[0] === 'W' && intval(substr($streak, 1)) >= 3) {
                $tags[] = $name . ' on a ' . intval(substr($streak, 1)) . '-game win streak';
            }
        }
        return $tags;
    }

    /**
     * Ladder places among teams out of placements (team_id => 1, 2, ...).
     * A team still in placements has no place yet, so a newcomer can't
     * push everyone down after one or two games.
     */
    private static function positions($ratings) {
        $rated = array_filter($ratings, function ($r) { return $r['games'] > 0 && !$r['provisional']; });
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
