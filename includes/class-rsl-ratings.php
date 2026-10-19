<?php
/**
 * Ranked ratings and the ladders.
 *
 * Ratings are a Bradley-Terry model on the POINTS of every set plus a small
 * bonus for winning sets (games are timed, so sets can be cut short, third
 * sets can go to 15, and a game can end 1–1 — points count all of that
 * fairly; see fit()). Refitted from the whole season every time they're
 * read, so beating a team that later proves strong counts for more after
 * the fact. On an Elo-style scale: 400 points apart = 10:1 odds per set.
 *
 * - Each team starts from a prior centred on its seed (the captain's level
 *   estimate, RSL_Teams::level_seed_rating), so week one isn't random and a
 *   team's first results can't fling it to the ends of the ladder.
 * - Recent sets weigh a little more (HALF_LIFE_WEEKS), so improving teams
 *   aren't held back by their first month.
 * - Each team gets an uncertainty; above PROVISIONAL_SD it's provisional.
 * - Forfeits and missing results aren't played games — they never count.
 * - Only published nights count (as on every public page), so unpublishing
 *   a night takes its results out of the ratings and ladders too.
 * - A team with no played games has no public rating (showing its seed
 *   would reveal the hidden estimate); it sits under the rated teams.
 *
 * Fitted by maximum a posteriori (Newton steps team by team — the problem
 * is concave, so it always converges). Later: carry ratings across
 * seasons as the prior instead of the seed.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RSL_Ratings {

    const SCALE = 400;            // rating points for 10:1 odds in a set
    const PRIOR_SD = 200;         // how far results can comfortably move a team from its seed
    const HALF_LIFE_WEEKS = 8;    // a set this many weeks old counts half
    const PLACEMENT_GAMES = 3;    // a team is in placements (provisional) for its first games — a clear rule players can follow
    const PROVISIONAL_SD = 95;    // ...and stays provisional after that only if its rating is still unusually uncertain
    const FULL_SET_POINTS = 40;   // a set with this many points (e.g. 25–15) counts as a whole set won
    const RALLY_SCALE = 6;        // a team's edge in a rally is about 1/6 of its edge in a set (race to 25)
    // Chosen by dev/simulate-ratings.php (timed games played rally by rally):
    // points find the true order faster than sets alone; a small set bonus
    // keeps "we won" mattering for little cost in accuracy.
    const SET_WEIGHT = 0.25;      // evidence from who won each set
    const POINT_WEIGHT = 0.3;     // evidence from each rally, discounted (rallies aren't independent)
    const SD_CALIBRATION = 0.6;   // the model's raw uncertainty is ~1.7x too cautious in simulation; this makes it honest

    private static $memo = array();
    private static $data = array();

    /**
     * A season's active teams and games (every division, published or not),
     * read once per request and shared by the ratings, the ladders and
     * RSL_Stats, so every view in a request agrees. flush() forgets it.
     */
    public static function season_data($season) {
        if (!isset(self::$data[$season])) {
            self::$data[$season] = array('teams' => RSL_Teams::list_for_season($season), 'games' => RSL_Fixtures::games_for_season($season));
        }
        return self::$data[$season];
    }

    /**
     * Ranked ratings for a season from games on or before $as_of (Y-m-d,
     * null = everything). team_id => array(rating, sd, provisional, games).
     * Only active Ranked teams, and only published nights.
     */
    public static function compute($season, $as_of = null) {
        $key = $season . '|' . $as_of;
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }
        $data = self::season_data($season);
        $seeds = array();
        foreach ($data['teams'] as $t) {
            if ($t->division === 'ranked') {
                $seeds[intval($t->id)] = RSL_Teams::level_seed_rating($t->est_level);
            }
        }
        $played = array_filter($data['games'], function ($g) use ($as_of, $seeds) {
            return $g->division === 'ranked' && !empty($g->published_at) && $g->status === 'played' && ($as_of === null || $g->night_date <= $as_of)
                && isset($seeds[intval($g->home_team_id)]) && isset($seeds[intval($g->away_team_id)]);
        });
        $latest = $as_of;
        foreach ($played as $g) {
            $latest = ($latest === null || $g->night_date > $latest) ? $g->night_date : $latest;
        }
        $games = array();
        foreach ($played as $g) {
            $sets = RSL_Fixtures::parse_sets($g->sets);
            if (is_wp_error($sets)) {
                continue;
            }
            $weeks = max(0, (strtotime($latest) - strtotime($g->night_date)) / (7 * DAY_IN_SECONDS));
            $games[] = array(intval($g->home_team_id), intval($g->away_team_id), $sets, $weeks);
        }
        return self::$memo[$key] = self::fit($seeds, $games);
    }

    /**
     * The model itself, no database: $seeds = team_id => starting rating;
     * $games = list of array(home_id, away_id, sets (array of [home, away]),
     * weeks_ago). Returns team_id => array(rating, sd, provisional, games).
     *
     * Two kinds of evidence from every set, because games are timed (a set
     * can end when the hour does, a third set may go to 15, a game can end 1–1):
     *  - who won the set, counted in proportion to how complete it was
     *    (points played / FULL_SET_POINTS, at most 1) — a 14–11 set cut off
     *    by the clock counts for less than a 25–23 one;
     *  - the points themselves: each rally is a small contest between the
     *    teams, worth POINT_WEIGHT of a full rally's evidence (rallies aren't
     *    independent — momentum — so they mustn't be over-trusted). A 1–1
     *    draw where one team won far more points still moves the ratings.
     * $opts may override set_weight / point_weight (the simulator compares them).
     */
    public static function fit($seeds, $games, $opts = array()) {
        $set_weight = isset($opts['set_weight']) ? $opts['set_weight'] : self::SET_WEIGHT;
        $point_weight = isset($opts['point_weight']) ? $opts['point_weight'] : self::POINT_WEIGHT;
        $k = log(10) / self::SCALE;
        $theta = array();
        $mu = array();
        $count = array();
        $links = array();
        foreach ($seeds as $id => $seed) {
            $mu[$id] = $seed * $k;
            $theta[$id] = $mu[$id];
            $count[$id] = 0;
            $links[$id] = array();
        }
        foreach ($games as $g) {
            list($h, $a, $sets, $weeks) = $g;
            if (!isset($theta[$h]) || !isset($theta[$a])) {
                continue;
            }
            $w = pow(0.5, $weeks / self::HALF_LIFE_WEEKS);
            $count[$h]++;
            $count[$a]++;
            foreach ($sets as $s) {
                $points = $s[0] + $s[1];
                if ($set_weight > 0 && $s[0] !== $s[1]) {
                    $sw = $w * $set_weight * min(1, $points / self::FULL_SET_POINTS);
                    $home_won = $s[0] > $s[1] ? 1 : 0;
                    $links[$h][] = array($a, 's', $home_won, $sw);
                    $links[$a][] = array($h, 's', 1 - $home_won, $sw);
                }
                if ($point_weight > 0 && $points > 0) {
                    $pw = $w * $point_weight;
                    $links[$h][] = array($a, 'p', $s[0], $pw, $points);
                    $links[$a][] = array($h, 'p', $s[1], $pw, $points);
                }
            }
        }
        $games = $count;

        $prior = 1 / pow(self::PRIOR_SD * $k, 2);
        $hess = array();
        $rs = self::RALLY_SCALE;
        for ($iter = 0; $iter < 300; $iter++) {
            $moved = 0;
            foreach ($theta as $i => $ti) {
                $g = -($ti - $mu[$i]) * $prior;
                $h = $prior;
                foreach ($links[$i] as $l) {
                    if ($l[1] === 's') {
                        $p = 1 / (1 + exp($theta[$l[0]] - $ti));
                        $g += $l[3] * ($l[2] - $p);
                        $h += $l[3] * $p * (1 - $p);
                    } else {
                        // Chance of winning a rally: the set-scale gap shrunk by RALLY_SCALE.
                        $q = 1 / (1 + exp(($theta[$l[0]] - $ti) / $rs));
                        $g += $l[3] / $rs * ($l[2] - $l[4] * $q);
                        $h += $l[3] / ($rs * $rs) * $l[4] * $q * (1 - $q);
                    }
                }
                $step = $g / $h;
                $theta[$i] = $ti + $step;
                $hess[$i] = $h;
                $moved = max($moved, abs($step));
            }
            if ($moved < 1e-7) {
                break;
            }
        }

        $out = array();
        foreach ($theta as $i => $ti) {
            $sd = self::SD_CALIBRATION / sqrt($hess[$i]) / $k;
            $out[$i] = array(
                'rating' => $ti / $k,
                'sd' => $sd,
                'provisional' => $games[$i] < self::PLACEMENT_GAMES || $sd > self::PROVISIONAL_SD,
                'games' => $games[$i],
            );
        }
        return $out;
    }

    /** Forget memoised ratings and season data (after results change in the same request). */
    public static function flush() {
        self::$memo = array();
        self::$data = array();
    }

    /**
     * The ladder for one division: ordered rows of
     * array(team, position, played, won, drawn, lost, forfeits, sets_for,
     * sets_against, rating|null, provisional, move|null, new, placement).
     *
     * Ranked: settled teams by rating; then teams in placements (by name,
     * no position yet); then teams without a played game (position null).
     * move = places gained since the previous round.
     * Unranked: by wins, then set difference, then sets won.
     * Published nights only.
     */
    public static function ladder($season, $division) {
        $data = self::season_data($season);
        $teams = array_values(array_filter($data['teams'], function ($t) use ($division) { return $t->division === $division; }));
        $rows = array();
        foreach ($teams as $t) {
            $rows[intval($t->id)] = array('team' => $t, 'position' => null, 'played' => 0, 'won' => 0, 'drawn' => 0, 'lost' => 0, 'forfeits' => 0,
                'sets_for' => 0, 'sets_against' => 0, 'rating' => null, 'provisional' => false, 'move' => null, 'new' => false, 'placement' => false);
        }
        $nights_played = array();
        foreach ($data['games'] as $g) {
            if ($g->division !== $division || empty($g->published_at) || ($g->status !== 'played' && $g->status !== 'forfeit')) {
                continue;
            }
            $nights_played[$g->night_date] = true;
            foreach (array(intval($g->home_team_id), intval($g->away_team_id)) as $id) {
                if (!isset($rows[$id])) {
                    continue;
                }
                $r = RSL_Fixtures::result_for($g, $id);
                $rows[$id]['played']++;
                $rows[$id]['sets_for'] += $r['for'];
                $rows[$id]['sets_against'] += $r['against'];
                switch ($r['outcome']) {
                    case 'win': case 'forfeit_win': $rows[$id]['won']++; break;
                    case 'loss': $rows[$id]['lost']++; break;
                    case 'forfeit_loss': $rows[$id]['lost']++; $rows[$id]['forfeits']++; break;
                    case 'draw': $rows[$id]['drawn']++; break;
                }
            }
        }

        if ($division === 'ranked') {
            $now = self::compute($season);
            // Settled teams are numbered by rating. Teams in placements are
            // listed (by name) but not numbered until their rating settles,
            // so a newcomer can't push everyone down after a game or two.
            // Teams that haven't played come last.
            $rated = array();
            $placements = array();
            $unrated = array();
            foreach ($rows as $id => $row) {
                if (isset($now[$id]) && $now[$id]['games'] > 0) {
                    $row['rating'] = (int) round($now[$id]['rating']);
                    $row['provisional'] = $now[$id]['provisional'];
                    if ($row['provisional']) {
                        $row['placement'] = true;
                        $placements[$id] = $row;
                    } else {
                        $rated[$id] = $row;
                    }
                } else {
                    $row['new'] = true;
                    $unrated[$id] = $row;
                }
            }
            uasort($rated, function ($x, $y) use ($now) {
                return $now[intval($y['team']->id)]['rating'] <=> $now[intval($x['team']->id)]['rating'];
            });
            uasort($placements, function ($x, $y) { return strcasecmp($x['team']->name, $y['team']->name); });
            // Places moved since the round before the latest one.
            $dates = array_keys($nights_played);
            sort($dates);
            $before = array();
            if (count($dates) > 1) {
                $prev = self::compute($season, $dates[count($dates) - 2]);
                $prev = array_filter($prev, function ($p) { return $p['games'] > 0 && !$p['provisional']; });
                uasort($prev, function ($x, $y) { return $y['rating'] <=> $x['rating']; });
                $before = array_flip(array_keys($prev));
            }
            $pos = 0;
            foreach ($rated as $id => &$row) {
                $row['position'] = ++$pos;
                if ($before) {
                    $row['move'] = isset($before[$id]) ? ($before[$id] + 1) - $pos : null;
                }
            }
            unset($row);
            return array_merge(array_values($rated), array_values($placements), array_values($unrated));
        }

        uasort($rows, function ($x, $y) {
            return array($y['won'], $y['sets_for'] - $y['sets_against'], $y['sets_for'], $x['team']->name)
                <=> array($x['won'], $x['sets_for'] - $x['sets_against'], $x['sets_for'], $y['team']->name);
        });
        $pos = 0;
        foreach ($rows as &$row) {
            $row['position'] = ++$pos;
        }
        unset($row);
        return array_values($rows);
    }
}
