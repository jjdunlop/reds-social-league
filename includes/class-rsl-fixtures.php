<?php
/**
 * Fixtures and results. A game fills one slot of a game night (court +
 * start time, from RSL_Courts::slots_for_night) between two teams of the
 * division that court hosts that night. Fixtures go public a night at a
 * time (publish_night). Results are set scores; a forfeit is recorded but
 * isn't a played game, so it never moves ratings.
 *
 * Data only — no output. Returns rows, or WP_Error with a readable message.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RSL_Fixtures {

    const MAX_SETS = 5;

    // ------------------------------------------------------------------
    // Games
    // ------------------------------------------------------------------

    /**
     * Put a game in a slot. $args: home, away (team ids), court_id, start ('19:00').
     * Checks: a real game night, both teams active this season and in the
     * division the court hosts that night, the slot exists, and neither the
     * court nor either team is already busy then.
     */
    public static function add_game($night_id, $args) {
        global $wpdb;
        $night = RSL_Courts::get_night($night_id);
        if (!$night || $night->type === 'bye') {
            return new WP_Error('rsl_night', 'There are no games on that night.');
        }
        $home = RSL_Teams::get(isset($args['home']) ? intval($args['home']) : 0);
        $away = RSL_Teams::get(isset($args['away']) ? intval($args['away']) : 0);
        foreach (array($home, $away) as $team) {
            if (!$team || $team->status !== 'active' || $team->season !== $night->season) {
                return new WP_Error('rsl_team', 'Both teams must be playing this season.');
            }
        }
        if (intval($home->id) === intval($away->id)) {
            return new WP_Error('rsl_team', 'A team can\'t play itself.');
        }
        if ($home->division !== $away->division) {
            return new WP_Error('rsl_division', 'Both teams must be in the same division.');
        }

        $court_id = isset($args['court_id']) ? intval($args['court_id']) : 0;
        $start = isset($args['start']) ? RSL_Courts::hm($args['start']) : '';
        $slot = null;
        foreach (RSL_Courts::slots_for_night($night->id) as $s) {
            if ($s['court_id'] === $court_id && $s['start'] === $start) {
                $slot = $s;
                break;
            }
        }
        if (!$slot) {
            return new WP_Error('rsl_slot', 'That court isn\'t running a game at that time on this night.');
        }
        if ($slot['division'] !== $home->division) {
            return new WP_Error('rsl_slot', 'That court hosts ' . RSL_Teams::DIVISIONS[$slot['division']] . ' games that night.');
        }

        foreach (self::games_for_night($night->id) as $g) {
            $overlaps = RSL_Courts::hm($g->start_time) < $slot['end'] && $slot['start'] < RSL_Courts::hm($g->end_time);
            if (!$overlaps) {
                continue;
            }
            if (intval($g->court_id) === $court_id) {
                return new WP_Error('rsl_clash', 'That court already has a game then.');
            }
            foreach (array($home, $away) as $team) {
                if (in_array(intval($team->id), array(intval($g->home_team_id), intval($g->away_team_id)), true)) {
                    return new WP_Error('rsl_clash', $team->name . ' is already playing then.');
                }
            }
        }

        $wpdb->insert(RSL_Database::table('games'), array(
            'season' => $night->season,
            'night_id' => $night->id,
            'division' => $home->division,
            'venue_id' => $slot['venue_id'],
            'court_id' => $court_id,
            'start_time' => $slot['start'],
            'end_time' => $slot['end'],
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'status' => 'scheduled',
            'created_at' => current_time('mysql'),
        ));
        $id = $wpdb->insert_id;
        if (self::is_published($night)) {
            RSL_Payments::sync_night($night->id);
        }
        return self::get($id);
    }

    /**
     * Remove a game (its ref job goes with it). The venue's court supervisor
     * goes with its last game that night, so no one is paid to supervise empty
     * courts (and the night isn't left "in use"). A remake passes
     * $tidy_supervisors = false and calls drop_idle_supervisors() once the new
     * games are in, so a supervisor whose venue still has games keeps the job.
     */
    public static function delete_game($game_id, $tidy_supervisors = true) {
        global $wpdb;
        $game = self::get($game_id);
        $wpdb->delete(RSL_Database::table('jobs'), array('type' => 'ref', 'game_id' => $game_id));
        $deleted = (bool) $wpdb->delete(RSL_Database::table('games'), array('id' => $game_id));
        if ($game) {
            if ($tidy_supervisors) {
                self::drop_idle_supervisors($game->night_id);
            }
            RSL_Payments::sync_night($game->night_id); // its fees come off
        }
        return $deleted;
    }

    /** Court supervisors on a venue with no games left that night come off. */
    public static function drop_idle_supervisors($night_id) {
        global $wpdb;
        $jobs = RSL_Database::table('jobs');
        $games = RSL_Database::table('games');
        $wpdb->query($wpdb->prepare(
            "DELETE j FROM $jobs j WHERE j.night_id = %d AND j.type = 'supervisor'
             AND NOT EXISTS (SELECT 1 FROM $games g WHERE g.night_id = j.night_id AND g.venue_id = j.venue_id)",
            $night_id
        ));
    }

    public static function get($game_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(self::select_sql() . " WHERE g.id = %d", $game_id));
    }

    /** A night's games, venue by venue, then by time and court. */
    public static function games_for_night($night_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(self::select_sql() . " WHERE g.night_id = %d ORDER BY v.sort, v.id, g.start_time, c.sort, c.id", $night_id));
    }

    /** A team's games in date order. $published_only hides nights not yet released. */
    public static function games_for_team($team_id, $published_only = true) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(self::select_sql() . " WHERE (g.home_team_id = %d OR g.away_team_id = %d)"
            . ($published_only ? " AND n.published_at IS NOT NULL" : '') . " ORDER BY n.night_date, g.start_time", $team_id, $team_id));
    }

    /** A season's games (optionally one division) in date order. */
    public static function games_for_season($season, $division = null) {
        global $wpdb;
        $sql = self::select_sql() . " WHERE g.season = %s";
        $params = array($season);
        if ($division) {
            $sql .= " AND g.division = %s";
            $params[] = $division;
        }
        return $wpdb->get_results($wpdb->prepare($sql . " ORDER BY n.night_date, g.start_time", $params));
    }

    private static function select_sql() {
        return "SELECT g.*, n.night_date, n.type AS night_type, n.published_at,
                h.name AS home_name, h.slug AS home_slug, h.colour AS home_colour,
                a.name AS away_name, a.slug AS away_slug, a.colour AS away_colour,
                c.name AS court_name, v.name AS venue_name
            FROM " . RSL_Database::table('games') . " g
            JOIN " . RSL_Database::table('nights') . " n ON n.id = g.night_id
            JOIN " . RSL_Database::table('teams') . " h ON h.id = g.home_team_id
            JOIN " . RSL_Database::table('teams') . " a ON a.id = g.away_team_id
            LEFT JOIN " . RSL_Database::table('courts') . " c ON c.id = g.court_id
            LEFT JOIN " . RSL_Database::table('venues') . " v ON v.id = g.venue_id";
    }

    // ------------------------------------------------------------------
    // Results
    // ------------------------------------------------------------------

    /**
     * Record set scores, home first: "25-20, 23-25, 15-12" or
     * array(array(25, 20), ...). Replaces any earlier result. Like a forfeit
     * or clearing a result, it re-syncs the night's fees (a game played
     * after a "can't make it" is charged as normal).
     */
    public static function record_result($game_id, $sets, $user_id = 0) {
        global $wpdb;
        $game = self::get($game_id);
        if (!$game) {
            return new WP_Error('rsl_game', 'That game no longer exists.');
        }
        $sets = self::parse_sets($sets);
        if (is_wp_error($sets)) {
            return $sets;
        }
        $home_sets = 0;
        $away_sets = 0;
        foreach ($sets as $s) {
            $s[0] > $s[1] ? $home_sets++ : $away_sets++;
        }
        $wpdb->update(RSL_Database::table('games'), array(
            'status' => 'played',
            'forfeit_team_id' => null,
            'sets' => self::format_sets($sets, ','),
            'home_sets' => $home_sets,
            'away_sets' => $away_sets,
            'result_by' => $user_id ?: null,
            'result_at' => current_time('mysql'),
            'live' => null,
        ), array('id' => $game->id));
        RSL_Payments::sync_night($game->night_id);
        return self::get($game->id);
    }

    /**
     * The score in progress from the ref's scoreboard. $state: sets (list
     * of [home, away]) and current ([home, away]). $seq must be higher than
     * the last update stored, so an older update arriving late (slow
     * signal) never overwrites a newer one. Returns true if stored.
     */
    public static function set_live($game_id, $state, $seq) {
        global $wpdb;
        $clean = array('sets' => array(), 'current' => array(0, 0));
        foreach ((array) (isset($state['sets']) ? $state['sets'] : array()) as $s) {
            $s = array_values((array) $s);
            if (count($s) === 2) {
                $clean['sets'][] = array(max(0, min(99, intval($s[0]))), max(0, min(99, intval($s[1]))));
            }
        }
        $clean['sets'] = array_slice($clean['sets'], 0, self::MAX_SETS);
        $cur = isset($state['current']) ? array_values((array) $state['current']) : array(0, 0);
        $clean['current'] = array(max(0, min(99, intval(isset($cur[0]) ? $cur[0] : 0))), max(0, min(99, intval(isset($cur[1]) ? $cur[1] : 0))));
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE " . RSL_Database::table('games') . " SET live = %s, live_seq = %d, live_at = %s WHERE id = %d AND status = 'scheduled' AND live_seq < %d",
            wp_json_encode($clean), intval($seq), current_time('mysql'), intval($game_id), intval($seq)
        ));
        return (bool) $updated;
    }

    /** A game's live score (array(sets, current, at)) if one is in progress and recent, else null. */
    public static function live($game) {
        if (!$game || $game->status !== 'scheduled' || empty($game->live) || empty($game->live_at)) {
            return null;
        }
        if (strtotime($game->live_at) < current_time('timestamp') - 3 * HOUR_IN_SECONDS) {
            return null; // left over from a game nobody finished saving
        }
        $state = json_decode($game->live, true);
        return is_array($state) ? array('sets' => $state['sets'], 'current' => $state['current'], 'at' => $game->live_at) : null;
    }

    /** One team gave the game away. */
    public static function record_forfeit($game_id, $team_id, $user_id = 0) {
        global $wpdb;
        $game = self::get($game_id);
        if (!$game) {
            return new WP_Error('rsl_game', 'That game no longer exists.');
        }
        if (!in_array(intval($team_id), array(intval($game->home_team_id), intval($game->away_team_id)), true)) {
            return new WP_Error('rsl_team', 'Only a team in the game can forfeit it.');
        }
        $wpdb->update(RSL_Database::table('games'), array(
            'status' => 'forfeit',
            'live' => null,
            'forfeit_team_id' => intval($team_id),
            'sets' => '',
            'home_sets' => 0,
            'away_sets' => 0,
            'result_by' => $user_id ?: null,
            'result_at' => current_time('mysql'),
        ), array('id' => $game->id));
        RSL_Payments::sync_night($game->night_id);
        return self::get($game->id);
    }

    /** Back to "no result yet". */
    public static function clear_result($game_id) {
        global $wpdb;
        $wpdb->update(RSL_Database::table('games'), array('status' => 'scheduled', 'forfeit_team_id' => null, 'sets' => '',
            'home_sets' => 0, 'away_sets' => 0, 'result_by' => null, 'result_at' => null, 'live' => null, 'live_seq' => 0), array('id' => intval($game_id)));
        $game = self::get($game_id);
        if ($game) {
            RSL_Payments::sync_night($game->night_id);
        }
        return $game;
    }

    /**
     * Set scores as array(array(home, away), ...), or WP_Error. Accepts
     * "25-20, 23-25" (any of - – : between, commas/semicolons/new lines
     * between sets). Timed games mean a set can end short, so the only
     * rules are whole numbers, no tied sets, 1 to MAX_SETS sets.
     */
    public static function parse_sets($input) {
        $sets = array();
        if (is_array($input)) {
            foreach ($input as $s) {
                $s = array_values((array) $s);
                $sets[] = array(isset($s[0]) ? $s[0] : '', isset($s[1]) ? $s[1] : '');
            }
        } else {
            foreach (preg_split('/[,;\n]+/', (string) $input) as $chunk) {
                if (trim($chunk) === '') {
                    continue;
                }
                if (!preg_match('/^\s*(\d{1,2})\s*(?:-|–|—|:)\s*(\d{1,2})\s*$/u', $chunk, $m)) {
                    return new WP_Error('rsl_sets', 'Write each set like 25-20, home team first.');
                }
                $sets[] = array($m[1], $m[2]);
            }
        }
        if (count($sets) < 1 || count($sets) > self::MAX_SETS) {
            return new WP_Error('rsl_sets', 'Enter between 1 and ' . self::MAX_SETS . ' sets.');
        }
        $out = array();
        foreach ($sets as $s) {
            if (!is_numeric($s[0]) || !is_numeric($s[1]) || intval($s[0]) != $s[0] || intval($s[1]) != $s[1]
                || $s[0] < 0 || $s[1] < 0 || $s[0] > 99 || $s[1] > 99) {
                return new WP_Error('rsl_sets', 'Set scores must be whole numbers.');
            }
            if (intval($s[0]) === intval($s[1])) {
                return new WP_Error('rsl_sets', 'A set can\'t be a tie.');
            }
            $out[] = array(intval($s[0]), intval($s[1]));
        }
        return $out;
    }

    /** array(array(25, 20), ...) or "25-20,23-25" -> "25-20, 23-25" (or with $glue). */
    public static function format_sets($sets, $glue = ', ') {
        if (!is_array($sets)) {
            $sets = $sets === '' ? array() : self::parse_sets($sets);
            if (is_wp_error($sets)) {
                return '';
            }
        }
        return implode($glue, array_map(function ($s) { return $s[0] . '-' . $s[1]; }, $sets));
    }

    /**
     * How a game went for one team: array(outcome, for, against, sets)
     * where outcome is win, loss, draw, forfeit_win, forfeit_loss, or
     * pending (no result). Sets are flipped to that team's view.
     */
    public static function result_for($game, $team_id) {
        $is_home = intval($game->home_team_id) === intval($team_id);
        if ($game->status === 'forfeit') {
            $lost = intval($game->forfeit_team_id) === intval($team_id);
            return array('outcome' => $lost ? 'forfeit_loss' : 'forfeit_win', 'for' => 0, 'against' => 0, 'sets' => array());
        }
        if ($game->status !== 'played') {
            return array('outcome' => 'pending', 'for' => 0, 'against' => 0, 'sets' => array());
        }
        $for = $is_home ? intval($game->home_sets) : intval($game->away_sets);
        $against = $is_home ? intval($game->away_sets) : intval($game->home_sets);
        $sets = self::parse_sets($game->sets);
        $sets = is_wp_error($sets) ? array() : $sets;
        if (!$is_home) {
            $sets = array_map(function ($s) { return array($s[1], $s[0]); }, $sets);
        }
        return array('outcome' => $for > $against ? 'win' : ($for < $against ? 'loss' : 'draw'), 'for' => $for, 'against' => $against, 'sets' => $sets);
    }

    // ------------------------------------------------------------------
    // Publishing
    // ------------------------------------------------------------------

    /** Release (or pull back) a night's fixtures. Already in that state: the publish time is kept. */
    public static function publish_night($night_id, $publish = true) {
        global $wpdb;
        $night = RSL_Courts::get_night($night_id);
        if (!$night) {
            return new WP_Error('rsl_night', 'That night no longer exists.');
        }
        if ((bool) $publish !== self::is_published($night)) {
            $wpdb->update(RSL_Database::table('nights'), array('published_at' => $publish ? current_time('mysql') : null), array('id' => intval($night_id)));
        }
        RSL_Payments::sync_night($night_id); // game fees are charged when fixtures are published
        return RSL_Courts::get_night($night_id);
    }

    /**
     * Whether a night may be published (or unpublished) now: true, or
     * WP_Error saying why not. Publishing needs games, and none for a team
     * that asked for the week off (the draft was made before they asked —
     * remake it). Unpublishing isn't for a night with results: it would
     * refund the night's fees and hide results the ladder still counts. Nor
     * for one that has started (its results may just not be in yet), unless
     * $called_off: the admin says it didn't go ahead, so its fees come off.
     */
    public static function check_publish($night_id, $publish = true, $called_off = false) {
        $night = RSL_Courts::get_night($night_id);
        if (!$night) {
            return new WP_Error('rsl_night', 'That night no longer exists.');
        }
        $games = self::games_for_night($night->id);
        if (!$publish) {
            foreach ($games as $g) {
                if ($g->status !== 'scheduled') {
                    return new WP_Error('rsl_results', 'This night has results in, so it can\'t be unpublished (that would refund its fees and hide its results). Edit or clear the results instead.');
                }
            }
            if ($games && !$called_off && self::has_started($night)) {
                return new WP_Error('rsl_played', 'This night has already been played — enter its results instead of unpublishing (that would take every team\'s fees for it off). If it was called off, use "Called off? Unpublish".');
            }
            return true;
        }
        if (!$games) {
            return new WP_Error('rsl_publish', 'There are no games to publish yet.');
        }
        $off = RSL_Requests::for_night($night->id, 'off', array('active', 'handled'));
        $still = array();
        foreach ($games as $g) {
            foreach (array(intval($g->home_team_id), intval($g->away_team_id)) as $t) {
                if (isset($off[$t])) {
                    $still[$t] = $off[$t]->team_name;
                }
            }
        }
        if ($still) {
            return new WP_Error('rsl_week_off', implode(', ', $still) . (count($still) === 1 ? ' has' : ' have') . ' asked for the week off but still '
                . (count($still) === 1 ? 'has a game' : 'have games') . ' in this draft — remake or adjust the fixtures before publishing.');
        }
        return true;
    }

    public static function is_published($night) {
        return $night && !empty($night->published_at);
    }

    /** The night is past, or its first game has started. */
    public static function has_started($night) {
        if (!$night) {
            return false;
        }
        $start = RSL_Officials::night_start($night);
        return $night->night_date < current_time('Y-m-d') || ($start && current_time('timestamp') >= $start);
    }

    /**
     * Round labels for a season's nights: night_id => "Round 3", "Finals 1",
     * "Bye". Regular nights are numbered in order; byes don't use a number.
     */
    public static function round_labels($season) {
        $labels = array();
        $round = 0;
        $finals = 0;
        $nights = RSL_Courts::nights($season);
        $finals_total = count(array_filter($nights, function ($n) { return $n->type === 'finals'; }));
        foreach ($nights as $n) {
            if ($n->type === 'bye') {
                $labels[$n->id] = 'Bye';
            } elseif ($n->type === 'finals') {
                $finals++;
                $labels[$n->id] = $finals_total > 1 ? 'Finals ' . $finals : 'Finals';
            } else {
                $labels[$n->id] = 'Round ' . (++$round);
            }
        }
        return $labels;
    }
}
