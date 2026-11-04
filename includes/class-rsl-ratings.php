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
 * TWO RATINGS, one model and the same results (0.18.1, the club's rule:
 * the captain's level must have no influence on a team's ladder rating,
 * tier or place, so it can't affect finals). They differ in where each
 * team's prior is centred (priors()) and how wide it is (fit_opts()):
 * - FIXTURES ('fixtures'): centred on each team's seed (the captain's level
 *   estimate, RSL_Teams::level_seed_rating), prior sd PRIOR_SD, so the
 *   draw makes even games from week one. Used only to make fixtures
 *   (RSL_Pairing), to place free agents (RSL_Placement) and for the
 *   admin-only set chances on the Fixtures screen. Never public, never
 *   shown to a player.
 * - LADDER ('ladder', the default): every team centred on ONE start per
 *   season (start_for(): frozen on the season's first published Ranked
 *   night), prior sd LADDER_PRIOR_SD. The ladder never reads a level, for
 *   any team at any game count: a team on 2 games and a team on 9 are
 *   rated the same way, from results alone, so editing a level can't move
 *   anyone. Everything public and everything a player sees: the ladder
 *   order, tiers and their buffer, placements, rating changes, upsets,
 *   close games, hype, season leaders, team pages and RSL_Stats.
 *
 * Why the ladder's prior is 350, not 200: the prior says how far results
 * can comfortably move a team from where it starts. A level seed is
 * usually close to right, so 200 suits the fixtures rating. The shared
 * start knows nothing about a team, and at 200 it held results back: a
 * strong team needed most of the season to pull clear of a weak one.
 * Simulated (dev/simulate-ratings.php: 14 teams, 9 rounds, 600 seasons,
 * the link draw), rank correlation with the true strengths after rounds 4
 * and 9 is 0.76 and 0.895 at 350, against 0.61 and 0.835 at 200 (and 0.89
 * and 0.92 for the old ladder that let the level count); the uncertainty
 * stays honest. The cost is bigger moves in a team's first weeks, all of
 * them from results. With the wider prior nearly every team is over 95
 * after 3 games, so the ladder's provisional line is LADDER_PROVISIONAL_SD
 * (120): in simulation no team was over it after 3 games.
 *
 * The draw helps: from round 3, RSL_Pairing::link() favours a game
 * between groups of teams that no chain of results connects yet, so the
 * ladder can compare them.
 *
 * Both:
 * - The prior keeps a team's first results from flinging it to the ends
 *   of the ladder.
 * - Recent sets weigh a little more (HALF_LIFE_WEEKS), so improving teams
 *   aren't held back by their first month.
 * - Each team gets an uncertainty; a team with PLACEMENT_GAMES games is
 *   provisional only while that's above its kind's line (PROVISIONAL_SD,
 *   LADDER_PROVISIONAL_SD).
 * - Forfeits and missing results aren't played games — they never count.
 * - Only published nights count (as on every public page), so unpublishing
 *   a night takes its results out of the ratings and ladders too.
 * - A team with no played games has no public rating (its ladder rating
 *   would only be the season's start); it sits under the rated teams.
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
    const PRIOR_SD = 200;         // fixtures rating: how far results can comfortably move a team from its level seed
    const LADDER_PRIOR_SD = 350;  // ladder: how far results can comfortably move a team from the shared start (which knows nothing about it)
    const DEFAULT_START = 1500;   // the ladder's start with no Ranked teams (the middle of the level seeds)
    const STARTS_OPTION = 'rsl_ladder_starts'; // season => array(start, night, teams): the ladder's frozen start per season (autoload off)
    const HALF_LIFE_WEEKS = 8;    // a set this many weeks old counts half
    const PLACEMENT_GAMES = 3;    // a team is in placements (provisional) for its first games — a clear rule players can follow
    const PROVISIONAL_SD = 95;    // ...and stays provisional after that only if its rating is still unusually uncertain (fixtures)
    const LADDER_PROVISIONAL_SD = 120; // ...the same line for the ladder's wider prior (after 3 games simulated sd p90 is about 105)
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
    private static $starts = array();

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
     * player sees: every team from the season's frozen start, start_for(),
     * never a level) or 'fixtures' (seeded by the captain's level: making
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
        $start = $kind === 'ladder' ? self::start_for($season) : null;
        return self::$memo[$key] = self::fit(self::priors($seeds, $kind, $start), $games, self::fit_opts($kind));
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
     * 'fixtures' = its own level seed; 'ladder' = one start for every team,
     * $start (the season's frozen start, start_for()), or common_start($seeds)
     * when none is given. team_id => rating.
     */
    public static function priors($seeds, $kind = 'ladder', $start = null) {
        if (self::kind($kind) === 'fixtures') {
            return $seeds;
        }
        $start = $start === null ? self::common_start($seeds) : (int) $start;
        $out = array();
        foreach ($seeds as $id => $seed) {
            $out[$id] = $start;
        }
        return $out;
    }

    /**
     * fit()'s options for one kind of rating (pure): the ladder's wider
     * prior and its provisional line; none for fixtures (fit()'s defaults,
     * PRIOR_SD and PROVISIONAL_SD).
     */
    public static function fit_opts($kind) {
        if (self::kind($kind) === 'fixtures') {
            return array();
        }
        return array('prior_sd' => self::LADDER_PRIOR_SD, 'provisional_sd' => self::LADDER_PROVISIONAL_SD);
    }

    /**
     * The ladder's start formula (pure): the mean of the given level seeds,
     * rounded to 10; DEFAULT_START with none. start_for() applies it once a
     * season, to the Ranked teams with a game on the season's first
     * published Ranked night, and keeps the result.
     *
     * Why the mean: fitted ratings always average exactly their prior
     * centres (a result's evidence pushes its two teams apart by equal and
     * opposite amounts), so starting every team at the mean seed puts the
     * league's average rating where the seeded fixtures rating puts it. The tier
     * thresholds (Bronze up to 1349, Silver 1350, Gold 1500, Platinum 1650,
     * Diamond 1800) were set on that scale, so they keep their meaning: a
     * league of mostly social teams still averages below Gold, a strong
     * league above it. It reveals no team's level — every team starts on
     * the same number. Rounded so a small change usually moves nothing.
     */
    public static function common_start($seeds) {
        if (!$seeds) {
            return self::DEFAULT_START;
        }
        return (int) (10 * round(array_sum($seeds) / count($seeds) / 10));
    }

    /**
     * The first published Ranked night in a season's games (pure; rows as
     * RSL_Fixtures::games_for_season() gives them): array(date, teams =>
     * list of team ids with a Ranked game that night, whatever its status),
     * or null when no Ranked game is on a published night.
     */
    public static function first_ranked_night($games) {
        $date = null;
        foreach ($games as $g) {
            if ($g->division === 'ranked' && !empty($g->published_at) && ($date === null || $g->night_date < $date)) {
                $date = $g->night_date;
            }
        }
        if ($date === null) {
            return null;
        }
        $teams = array();
        foreach ($games as $g) {
            if ($g->division === 'ranked' && !empty($g->published_at) && $g->night_date === $date) {
                $teams[intval($g->home_team_id)] = true;
                $teams[intval($g->away_team_id)] = true;
            }
        }
        return array('date' => $date, 'teams' => array_keys($teams));
    }

    /**
     * The start a season's ladder freezes (pure): common_start() of the
     * level seeds of $team_ids (the teams on its first published Ranked
     * night) among $seeds (the season's active Ranked teams); of every
     * team's seed when none of them is still active.
     */
    public static function frozen_start($seeds, $team_ids) {
        $on = array_intersect_key($seeds, array_flip(array_map('intval', $team_ids)));
        return self::common_start($on ? $on : $seeds);
    }

    /**
     * The ladder's start for a season: one number for every Ranked team,
     * frozen on the season's first published Ranked night (frozen_start()
     * of the teams with a game that night) and kept in STARTS_OPTION, so
     * editing a level later — or a team joining late — never moves it.
     * The first read after a Ranked night is published stores it (and
     * RSL_Fixtures::publish_night() reads it straight away), which also
     * covers a season already running when this arrived. With no published
     * Ranked night it is common_start() of the current seeds, not stored
     * (nothing public shows then), and a stored start is forgotten, so
     * unpublishing every Ranked night lets the next publish freeze it
     * afresh. Memoised per request; flush() forgets it.
     */
    public static function start_for($season) {
        if (isset(self::$starts[$season])) {
            return self::$starts[$season];
        }
        $data = self::season_data($season);
        $seeds = self::seeds($data['teams']);
        $first = self::first_ranked_night($data['games']);
        $all = get_option(self::STARTS_OPTION, array());
        $all = is_array($all) ? $all : array();
        if ($first === null) {
            if (isset($all[$season])) {
                self::forget_start($season);
            }
            return self::$starts[$season] = self::common_start($seeds);
        }
        if (isset($all[$season]['start'])) {
            return self::$starts[$season] = intval($all[$season]['start']);
        }
        $start = self::frozen_start($seeds, $first['teams']);
        $all[$season] = array('start' => $start, 'night' => $first['date'], 'teams' => count(array_intersect_key($seeds, array_flip($first['teams']))));
        update_option(self::STARTS_OPTION, $all, false);
        return self::$starts[$season] = $start;
    }

    /** Forget a season's frozen start (start_for() freezes it again from the first published Ranked night). */
    public static function forget_start($season) {
        unset(self::$starts[$season]);
        $all = get_option(self::STARTS_OPTION, array());
        if (!is_array($all) || !isset($all[$season])) {
            return;
        }
        unset($all[$season]);
        if ($all) {
            update_option(self::STARTS_OPTION, $all, false);
        } else {
            delete_option(self::STARTS_OPTION);
        }
    }

    /**
     * The model itself, no database: $seeds = team_id => prior centre (each
     * team's starting rating: its level seed for the fixtures rating, the
     * season's start for the ladder — priors()); $games = list of
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
     * simulator compares them — and prior_sd (default PRIOR_SD) and
     * provisional_sd (default PROVISIONAL_SD), which the ladder sets
     * (fit_opts()).
     */
    public static function fit($seeds, $games, $opts = array()) {
        $set_weight = isset($opts['set_weight']) ? $opts['set_weight'] : self::SET_WEIGHT;
        $point_weight = isset($opts['point_weight']) ? $opts['point_weight'] : self::POINT_WEIGHT;
        $set_share = isset($opts['set_share']) ? $opts['set_share'] : array(__CLASS__, 'set_share');
        $prior_sd = isset($opts['prior_sd']) ? $opts['prior_sd'] : self::PRIOR_SD;
        $provisional_sd = isset($opts['provisional_sd']) ? $opts['provisional_sd'] : self::PROVISIONAL_SD;
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

        $prior = 1 / pow($prior_sd * $k, 2);
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
                'provisional' => $games[$i] < self::PLACEMENT_GAMES || $sd > $provisional_sd,
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

    /** Forget memoised ratings (both kinds), season data and starts (after results change in the same request). */
    public static function flush() {
        self::$memo = array();
        self::$data = array();
        self::$starts = array();
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
