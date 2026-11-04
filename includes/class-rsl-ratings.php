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
 * TWO RATINGS, one model and the same results (0.18.1, the club's
 * decision: the captain's starting level must not shape the ladder). They
 * differ only in where each team's prior is centred (priors()):
 * - FIXTURES ('fixtures'): centred on each team's seed (the captain's level
 *   estimate, RSL_Teams::level_seed_rating), so week one's fixtures aren't
 *   random. Used only to make fixtures (RSL_Pairing), to place free agents
 *   (RSL_Placement) and for the admin-only set chances on the Fixtures
 *   screen. Never public, never shown to a player.
 * - LADDER ('ladder', the default): every team centred on ONE common start
 *   (common_start()), so results alone separate teams. Everything public
 *   and everything a player sees: the ladder order, tiers and their buffer,
 *   placements, rating changes, upsets, close games, hype, season leaders,
 *   team pages and RSL_Stats. With a 200-point difference in seeds, the
 *   seeded rating still kept ~59% of it after 3 games and ~32% after 9; the
 *   ladder rating keeps none. The cost, simulated (dev/simulate-ratings.php):
 *   the ladder finds the true order more slowly (rank correlation 0.59 vs
 *   0.87 after 4 rounds, 0.85 vs 0.93 after 10), as the fixtures pair teams
 *   the seeded rating calls even, so early results say little about teams
 *   that haven't met.
 *
 * Both:
 * - The prior (PRIOR_SD) keeps a team's first results from flinging it to
 *   the ends of the ladder.
 * - Recent sets weigh a little more (HALF_LIFE_WEEKS), so improving teams
 *   aren't held back by their first month.
 * - Each team gets an uncertainty; above PROVISIONAL_SD it's provisional.
 * - Forfeits and missing results aren't played games — they never count.
 * - Only published nights count (as on every public page), so unpublishing
 *   a night takes its results out of the ratings and ladders too.
 * - A team with no played games has no public rating (its ladder rating
 *   would only be the common start); it sits under the rated teams.
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
    const PRIOR_SD = 200;         // how far results can comfortably move a team from its prior centre (both ratings)
    const DEFAULT_START = 1500;   // the ladder's common start with no Ranked teams (the middle of the level seeds)
    const HALF_LIFE_WEEKS = 8;    // a set this many weeks old counts half
    const PLACEMENT_GAMES = 3;    // a team is in placements (provisional) for its first games — a clear rule players can follow
    const PROVISIONAL_SD = 95;    // ...and stays provisional after that only if its rating is still unusually uncertain
    const SET_TARGET = 25;        // sets one and two are to 25, two clear (set_share())
    const THIRD_SET_TARGET = 15;  // the third set is to 15 or 25 (the ref picks); 15 two clear finishes it for the set bonus
    const RALLY_SCALE = 6;       // a team's edge in a rally is about 1/6 of its edge in a set (race to 25)
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
     *
     * $kind: 'ladder' (the default — everything public and everything a
     * player sees) or 'fixtures' (seeded by the captain's level: making
     * fixtures, placing free agents, the admin set chances). Anything else
     * is read as 'ladder', so a caller that forgets is public-safe. Each
     * kind is memoised separately; flush() forgets both.
     */
    public static function compute($season, $as_of = null, $kind = 'ladder') {
        $kind = self::kind($kind);
        $key = $kind . '|' . $season . '|' . $as_of;
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }
        $data = self::season_data($season);
        $seeds = self::seeds($data['teams']);
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
        return self::$memo[$key] = self::fit(self::priors($seeds, $kind), $games);
    }

    /** 'fixtures' or 'ladder' — anything that isn't 'fixtures' is the (public-safe) ladder. */
    public static function kind($kind) {
        return $kind === 'fixtures' ? 'fixtures' : 'ladder';
    }

    /** Level seeds of a list of teams' Ranked ones: team_id => RSL_Teams::level_seed_rating(est_level). Pure. */
    public static function seeds($teams) {
        $seeds = array();
        foreach ($teams as $t) {
            if ($t->division === 'ranked') {
                $seeds[intval($t->id)] = RSL_Teams::level_seed_rating($t->est_level);
            }
        }
        return $seeds;
    }

    /**
     * Where each team's prior is centred for one kind of rating (pure):
     * 'fixtures' = its own level seed; 'ladder' = common_start($seeds) for
     * every team. team_id => rating.
     */
    public static function priors($seeds, $kind = 'ladder') {
        if (self::kind($kind) === 'fixtures') {
            return $seeds;
        }
        $start = self::common_start($seeds);
        $out = array();
        foreach ($seeds as $id => $seed) {
            $out[$id] = $start;
        }
        return $out;
    }

    /**
     * The ladder's common start (pure): the mean of the season's Ranked
     * teams' level seeds, rounded to 10; DEFAULT_START with none.
     *
     * Why the mean: fitted ratings always average exactly their prior
     * centres (a result's evidence pushes its two teams apart by equal and
     * opposite amounts), so starting every team at the mean seed puts the
     * league's average rating where the seeded fixtures rating puts it. The tier
     * thresholds (Bronze up to 1349, Silver 1350, Gold 1500, Platinum 1650,
     * Diamond 1800) were set on that scale, so they keep their meaning: a
     * league of mostly social teams still averages below Gold, a strong
     * league above it. It reveals no team's level — every team starts on
     * the same number. One team's level moves the start by its share of the
     * mean (a 150-point change in a 14-team league: about 10) and every
     * team's ladder rating with it by the same amount, so it never changes
     * the order. Rounded so a small change usually moves nothing.
     */
    public static function common_start($seeds) {
        if (!$seeds) {
            return self::DEFAULT_START;
        }
        return (int) (10 * round(array_sum($seeds) / count($seeds) / 10));
    }

    /**
     * The model itself, no database: $seeds = team_id => prior centre (each
     * team's starting rating: its level seed for the fixtures rating, the
     * common start for the ladder — priors()); $games = list of
     * array(home_id, away_id, sets (array of [home, away]), weeks_ago).
     * Returns team_id => array(rating, sd, provisional, games).
     *
     * Each game's sets are in the order played (the first set first).
     *
     * Two kinds of evidence from every set, because games are timed (a set
     * can end when the hour does, a third set may go to 15, a game can end 1–1):
     *  - who won the set, worth set_share() of a whole set: a set that
     *    finished counts whole (25–10, 25–23 and a third set 15–8 alike), a
     *    set the clock cut short counts as far as the leader got (a 14–11
     *    first set 14/25, a 10–8 third set 10/15), a tied set not at all;
     *  - the points themselves: each rally is a small contest between the
     *    teams, worth POINT_WEIGHT of a full rally's evidence (rallies aren't
     *    independent — momentum — so they mustn't be over-trusted). A 1–1
     *    draw where one team won far more points still moves the ratings.
     * $opts may override set_weight / point_weight, and set_share (a
     * callable($set, $index) giving 0..1, in place of set_share()) — the
     * simulator compares them.
     */
    public static function fit($seeds, $games, $opts = array()) {
        $set_weight = isset($opts['set_weight']) ? $opts['set_weight'] : self::SET_WEIGHT;
        $point_weight = isset($opts['point_weight']) ? $opts['point_weight'] : self::POINT_WEIGHT;
        $set_share = isset($opts['set_share']) ? $opts['set_share'] : array(__CLASS__, 'set_share');
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
            foreach (array_values($sets) as $i => $s) {
                $points = $s[0] + $s[1];
                $share = ($set_weight > 0 && $s[0] !== $s[1]) ? call_user_func($set_share, $s, $i) : 0;
                if ($share > 0) {
                    $sw = $w * $set_weight * $share;
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

    /**
     * Did set $index of a game (0 = the first set) finish, rather than being
     * cut short by the clock? Games are best of three: the first and second
     * sets are to SET_TARGET; the ref picks 15 or 25 for the third, and the
     * score doesn't say which, so a third set counts as finished once the
     * winner has THIRD_SET_TARGET or more, two clear (a third set to 25 that
     * finished passes too). Every set needs a two-point lead to finish. A
     * set after the third (league games have none) is judged like the third.
     */
    public static function set_finished($set, $index) {
        $a = intval($set[0]);
        $b = intval($set[1]);
        $target = $index >= 2 ? self::THIRD_SET_TARGET : self::SET_TARGET;
        return max($a, $b) >= $target && abs($a - $b) >= 2;
    }

    /**
     * How much of a whole set's win evidence set $index (0 = the first set)
     * is worth, 0..1 — pure, used by fit():
     *  - a set that finished (set_finished()) counts whole, close or not:
     *    25–10, 25–23 and a third set 15–8 are each one whole set won;
     *  - a set the clock cut short counts as far as the leader got: the
     *    leader's points / the set's target (SET_TARGET, or THIRD_SET_TARGET
     *    for the third set), at most 1 — a 14–11 first set 14/25, a 10–8
     *    third set 10/15;
     *  - a tied set says nothing about who wins sets: 0.
     */
    public static function set_share($set, $index) {
        $a = intval($set[0]);
        $b = intval($set[1]);
        if ($a === $b) {
            return 0.0;
        }
        if (self::set_finished($set, $index)) {
            return 1.0;
        }
        $target = $index >= 2 ? self::THIRD_SET_TARGET : self::SET_TARGET;
        return min(1.0, max($a, $b) / (float) $target);
    }

    /** Forget memoised ratings (both kinds) and season data (after results change in the same request). */
    public static function flush() {
        self::$memo = array();
        self::$data = array();
    }

    /**
     * The ladder for one division: ordered rows of
     * array(team, position, played, won, drawn, lost, forfeits, sets_for,
     * sets_against, rating|null, provisional, move|null, new, placement).
     *
     * Ranked: settled teams by the LADDER rating (never the seeded one);
     * then teams in placements (by name, no position yet); then teams
     * without a played game (position null).
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
            $now = self::compute($season, null, 'ladder');
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
                $prev = self::compute($season, $dates[count($dates) - 2], 'ladder');
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
