<?php
/**
 * Ranked ratings and the ladders.
 *
 * Ratings are a Bradley-Terry model on SET results (a 25-23 loss says more
 * than a bare "L"), refitted from the whole season every time they're
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
    const PROVISIONAL_SD = 110;   // provisional until the uncertainty drops below this (about 3 games)

    private static $memo = array();

    /**
     * Ranked ratings for a season from games on or before $as_of (Y-m-d,
     * null = everything). team_id => array(rating, sd, provisional, games).
     * Only active Ranked teams.
     */
    public static function compute($season, $as_of = null) {
        $key = $season . '|' . $as_of;
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }
        $k = log(10) / self::SCALE;
        $teams = array_filter(RSL_Teams::list_for_season($season), function ($t) { return $t->division === 'ranked'; });
        $theta = array();
        $mu = array();
        $games = array();
        $links = array();
        foreach ($teams as $t) {
            $id = intval($t->id);
            $mu[$id] = RSL_Teams::level_seed_rating($t->est_level) * $k;
            $theta[$id] = $mu[$id];
            $games[$id] = 0;
            $links[$id] = array();
        }

        $played = array_filter(RSL_Fixtures::games_for_season($season, 'ranked'), function ($g) use ($as_of, $theta) {
            return $g->status === 'played' && ($as_of === null || $g->night_date <= $as_of)
                && isset($theta[intval($g->home_team_id)]) && isset($theta[intval($g->away_team_id)]);
        });
        $latest = $as_of;
        foreach ($played as $g) {
            $latest = ($latest === null || $g->night_date > $latest) ? $g->night_date : $latest;
        }
        foreach ($played as $g) {
            $h = intval($g->home_team_id);
            $a = intval($g->away_team_id);
            $weeks = max(0, (strtotime($latest) - strtotime($g->night_date)) / (7 * DAY_IN_SECONDS));
            $w = pow(0.5, $weeks / self::HALF_LIFE_WEEKS);
            $sets = RSL_Fixtures::parse_sets($g->sets);
            if (is_wp_error($sets)) {
                continue;
            }
            $games[$h]++;
            $games[$a]++;
            foreach ($sets as $s) {
                $home_won = $s[0] > $s[1] ? 1 : 0;
                $links[$h][] = array($a, $home_won, $w);
                $links[$a][] = array($h, 1 - $home_won, $w);
            }
        }

        $prior = 1 / pow(self::PRIOR_SD * $k, 2);
        $hess = array();
        for ($iter = 0; $iter < 200; $iter++) {
            $moved = 0;
            foreach ($theta as $i => $ti) {
                $g = -($ti - $mu[$i]) * $prior;
                $h = $prior;
                foreach ($links[$i] as $l) {
                    $p = 1 / (1 + exp($theta[$l[0]] - $ti));
                    $g += $l[2] * ($l[1] - $p);
                    $h += $l[2] * $p * (1 - $p);
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
            $sd = 1 / sqrt($hess[$i]) / $k;
            $out[$i] = array(
                'rating' => $ti / $k,
                'sd' => $sd,
                'provisional' => $games[$i] === 0 || $sd > self::PROVISIONAL_SD,
                'games' => $games[$i],
            );
        }
        return self::$memo[$key] = $out;
    }

    /** Forget memoised ratings (after results change in the same request). */
    public static function flush() {
        self::$memo = array();
    }

    /**
     * The ladder for one division: ordered rows of
     * array(team, position, played, won, drawn, lost, forfeits, sets_for,
     * sets_against, rating|null, provisional, move|null, new).
     *
     * Ranked: by rating (teams without a played game last, by name; their
     * position is null). move = places gained since the previous round.
     * Unranked: by wins, then set difference, then sets won.
     */
    public static function ladder($season, $division) {
        $teams = array_values(array_filter(RSL_Teams::list_for_season($season), function ($t) use ($division) { return $t->division === $division; }));
        $rows = array();
        foreach ($teams as $t) {
            $rows[intval($t->id)] = array('team' => $t, 'position' => null, 'played' => 0, 'won' => 0, 'drawn' => 0, 'lost' => 0, 'forfeits' => 0,
                'sets_for' => 0, 'sets_against' => 0, 'rating' => null, 'provisional' => false, 'move' => null, 'new' => false);
        }
        $nights_played = array();
        foreach (RSL_Fixtures::games_for_season($season, $division) as $g) {
            if ($g->status !== 'played' && $g->status !== 'forfeit') {
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
            $rated = array();
            $unrated = array();
            foreach ($rows as $id => $row) {
                if (isset($now[$id]) && $now[$id]['games'] > 0) {
                    $row['rating'] = (int) round($now[$id]['rating']);
                    $row['provisional'] = $now[$id]['provisional'];
                    $rated[$id] = $row;
                } else {
                    $row['new'] = true;
                    $unrated[$id] = $row;
                }
            }
            uasort($rated, function ($x, $y) use ($now) {
                return $now[intval($y['team']->id)]['rating'] <=> $now[intval($x['team']->id)]['rating'];
            });
            // Places moved since the round before the latest one.
            $dates = array_keys($nights_played);
            sort($dates);
            $before = array();
            if (count($dates) > 1) {
                $prev = self::compute($season, $dates[count($dates) - 2]);
                $prev = array_filter($prev, function ($p) { return $p['games'] > 0; });
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
            return array_merge(array_values($rated), array_values($unrated));
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
