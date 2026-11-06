<?php
/**
 * Paid officials: referees and court supervisors.
 *
 * - A player ticks "happy to be a paid referee" when they register (ref =
 *   applied); an admin approves or declines. Court supervisors are added
 *   by an admin.
 * - Once a night's fixtures are published, approved refs claim games one
 *   at a time (so a night can be ref, ref, play, ref) and supervisors
 *   claim a venue for the night. Checks: never your own team's game, never
 *   two places at once, time to travel between venues, a cap on games per
 *   night, a supervisor stays at the venue they supervise, and nothing
 *   that has already finished (an admin assigns those).
 * - Pay is a flat rate per game refereed and per night supervised (admin
 *   settings), recorded on the job when claimed. The pay report lists
 *   jobs on nights that have happened; admins remove no-shows. Removing a
 *   ref or supervisor releases their jobs still to come.
 * - The ref of a game enters its score (the scoreboard on the Ref jobs
 *   page); a result the league entered is locked to them.
 *
 * Data only — returns rows or WP_Error.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RSL_Officials {

    const SETTINGS_OPTION = 'rsl_officials_settings';
    const TRAVEL_MINUTES = 45;   // between commitments at different venues on one night

    // ------------------------------------------------------------------
    // Settings
    // ------------------------------------------------------------------

    /** ref_rate, supervisor_rate (dollars), release_hours (refs can drop a job until this long before the night starts), max_games (per ref per night). */
    public static function settings() {
        $s = get_option(self::SETTINGS_OPTION, array());
        $s = is_array($s) ? $s : array();
        return array(
            'ref_rate' => isset($s['ref_rate']) ? (float) $s['ref_rate'] : 25.0,
            'supervisor_rate' => isset($s['supervisor_rate']) ? (float) $s['supervisor_rate'] : 30.0,
            'release_hours' => isset($s['release_hours']) ? intval($s['release_hours']) : 24,
            'max_games' => isset($s['max_games']) ? intval($s['max_games']) : 5,
        );
    }

    public static function save_settings($args) {
        $s = array(
            'ref_rate' => round(max(0, (float) (isset($args['ref_rate']) ? $args['ref_rate'] : 25)), 2),
            'supervisor_rate' => round(max(0, (float) (isset($args['supervisor_rate']) ? $args['supervisor_rate'] : 30)), 2),
            'release_hours' => max(0, min(168, intval(isset($args['release_hours']) ? $args['release_hours'] : 24))),
            'max_games' => max(1, min(12, intval(isset($args['max_games']) ? $args['max_games'] : 5))),
        );
        update_option(self::SETTINGS_OPTION, $s, false);
        return self::settings();
    }

    // ------------------------------------------------------------------
    // Who's an official
    // ------------------------------------------------------------------

    public static function get($user_id) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . RSL_Database::table('officials') . " WHERE user_id = %d", $user_id));
        return $row ?: (object) array('user_id' => intval($user_id), 'ref' => '', 'supervisor' => 0);
    }

    public static function is_ref($user_id) {
        return $user_id && self::get($user_id)->ref === 'approved';
    }

    public static function is_supervisor($user_id) {
        return $user_id && (int) self::get($user_id)->supervisor === 1;
    }

    public static function is_official($user_id) {
        return self::is_ref($user_id) || self::is_supervisor($user_id);
    }

    private static function save($user_id, $data, $by = 0) {
        global $wpdb;
        $table = RSL_Database::table('officials');
        $data['updated_at'] = current_time('mysql');
        $data['updated_by'] = $by ?: null;
        if ($wpdb->get_var($wpdb->prepare("SELECT user_id FROM $table WHERE user_id = %d", $user_id))) {
            $wpdb->update($table, $data, array('user_id' => $user_id));
        } else {
            $wpdb->insert($table, array_merge(array('user_id' => $user_id), $data));
        }
        return self::get($user_id);
    }

    /** The player says they're happy to ref (from registration / their details). */
    public static function apply_ref($user_id) {
        $o = self::get($user_id);
        if ($o->ref === 'approved' || $o->ref === 'applied') {
            return $o;
        }
        return self::save($user_id, array('ref' => 'applied'), $user_id);
    }

    /** They untick it before being approved. */
    public static function withdraw_ref($user_id) {
        return self::get($user_id)->ref === 'applied' ? self::save($user_id, array('ref' => ''), $user_id) : self::get($user_id);
    }

    /**
     * Admin: approved, declined, or '' (not a ref). Someone who stops being
     * a ref gives up their ref jobs still to come (jobs already done stay
     * on the pay report); the returned row's ->released says how many.
     */
    public static function set_ref($user_id, $status, $by) {
        if (!in_array($status, array('approved', 'declined', ''), true) || !get_userdata($user_id)) {
            return new WP_Error('rsl_official', 'That person or status isn\'t valid.');
        }
        $released = $status === 'approved' ? 0 : self::release_jobs_to_come($user_id, 'ref');
        $o = self::save($user_id, array('ref' => $status), $by);
        $o->released = $released;
        return $o;
    }

    /** Admin: add or remove a court supervisor. Removing gives up their nights still to come (->released, as set_ref). */
    public static function set_supervisor($user_id, $on, $by) {
        if (!get_userdata($user_id)) {
            return new WP_Error('rsl_official', 'No account found for that person.');
        }
        $released = $on ? 0 : self::release_jobs_to_come($user_id, 'supervisor');
        $o = self::save($user_id, array('supervisor' => $on ? 1 : 0), $by);
        $o->released = $released;
        return $o;
    }

    /** Officials with names: everyone in the table with a ref status or the supervisor role. */
    public static function people() {
        global $wpdb;
        return $wpdb->get_results("SELECT o.*, u.display_name, u.user_email FROM " . RSL_Database::table('officials') . " o
            JOIN {$wpdb->users} u ON u.ID = o.user_id WHERE o.ref <> '' OR o.supervisor = 1 ORDER BY FIELD(o.ref, 'applied', 'approved', 'declined', ''), u.display_name");
    }

    // ------------------------------------------------------------------
    // Jobs
    // ------------------------------------------------------------------

    public static function job($job_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT j.*, u.display_name FROM " . RSL_Database::table('jobs') . " j LEFT JOIN {$wpdb->users} u ON u.ID = j.user_id WHERE j.id = %d", $job_id));
    }

    /** A night's claimed jobs: array('ref' => game_id => job, 'supervisor' => venue_id => job). */
    public static function jobs_for_night($night_id) {
        global $wpdb;
        $out = array('ref' => array(), 'supervisor' => array());
        foreach ($wpdb->get_results($wpdb->prepare("SELECT j.*, u.display_name FROM " . RSL_Database::table('jobs') . " j LEFT JOIN {$wpdb->users} u ON u.ID = j.user_id WHERE j.night_id = %d", $night_id)) as $j) {
            if ($j->type === 'ref') {
                $out['ref'][intval($j->game_id)] = $j;
            } else {
                $out['supervisor'][intval($j->venue_id)] = $j;
            }
        }
        return $out;
    }

    /** Claim refereeing one game. $by_admin skips "is a ref" and timing rules (an admin assigning). */
    public static function claim_ref($game_id, $user_id, $by_admin = 0) {
        $game = self::check_ref($game_id, $user_id, $by_admin);
        if (is_wp_error($game)) {
            return $game;
        }
        return self::insert_job(intval($game->night_id), 'ref', intval($game->id), intval($game->venue_id), $user_id, self::settings()['ref_rate'], $by_admin);
    }

    /** Could this person referee this game? The game, or WP_Error saying why not. */
    public static function check_ref($game_id, $user_id, $by_admin = 0) {
        $game = RSL_Fixtures::get($game_id);
        if (!$game) {
            return new WP_Error('rsl_job', 'That game no longer exists.');
        }
        if (!$by_admin && !self::is_ref($user_id)) {
            return new WP_Error('rsl_job', 'Only approved referees can take ref jobs.');
        }
        $e = self::ref_eligibility($game, RSL_Courts::get_night($game->night_id), RSL_Teams::current_team_for_user($user_id, $game->season),
            self::commitments($user_id, $game->night_id), $by_admin);
        return $e ?: $game;
    }

    /**
     * The rules for refereeing a game, given everything already loaded (no
     * queries, so the Ref Jobs page can ask about every game of a night):
     * null if it's fine, else WP_Error saying why not. $team: the person's
     * team this season (or null); $mine: their commitments() that night.
     * A game in progress can still be taken (a ref arriving late covers
     * it); once it has finished, only an admin can assign it.
     */
    public static function ref_eligibility($game, $night, $team, $mine, $by_admin = 0) {
        if (!$by_admin) {
            if ($e = self::open_for_claims($night)) {
                return $e;
            }
            if ($game->status !== 'scheduled') {
                return new WP_Error('rsl_job', 'That game already has a result.');
            }
            if (self::finished($game->night_date, $game->end_time)) {
                return new WP_Error('rsl_job', 'That game has finished — ask the league to assign it.');
            }
        }
        if ($team && in_array(intval($team->id), array(intval($game->home_team_id), intval($game->away_team_id)), true)) {
            return new WP_Error('rsl_job', 'You can\'t referee your own team\'s game.');
        }
        $refs = count(array_filter($mine, function ($c) { return $c['kind'] === 'ref'; }));
        if ($refs >= self::settings()['max_games']) {
            return new WP_Error('rsl_job', 'That\'s the most games one person can referee in a night (' . self::settings()['max_games'] . ').');
        }
        return self::clash($mine, RSL_Courts::hm($game->start_time), RSL_Courts::hm($game->end_time), intval($game->venue_id));
    }

    /** Claim supervising a venue for a night. */
    public static function claim_supervisor($night_id, $venue_id, $user_id, $by_admin = 0) {
        $night = RSL_Courts::get_night($night_id);
        if (!$night) {
            return new WP_Error('rsl_job', 'That night no longer exists.');
        }
        if (!$by_admin && !self::is_supervisor($user_id)) {
            return new WP_Error('rsl_job', 'Only court supervisors can take this job.');
        }
        $hosts = array_filter(RSL_Fixtures::games_for_night($night->id), function ($g) use ($venue_id) { return intval($g->venue_id) === intval($venue_id); });
        if ($e = self::supervisor_eligibility($night, intval($venue_id), $hosts, self::commitments($user_id, $night->id), $by_admin)) {
            return $e;
        }
        return self::insert_job($night->id, 'supervisor', 0, intval($venue_id), $user_id, self::settings()['supervisor_rate'], $by_admin);
    }

    /**
     * The rules for supervising a venue, given everything already loaded
     * (no queries): null if it's fine, else WP_Error. $hosts: the night's
     * games at that venue; $mine: the person's commitments() that night.
     * Like ref jobs, it can't be claimed once the venue's last game is over.
     */
    public static function supervisor_eligibility($night, $venue_id, $hosts, $mine, $by_admin = 0) {
        if (!$by_admin && ($e = self::open_for_claims($night))) {
            return $e;
        }
        if (!$hosts) {
            return new WP_Error('rsl_job', 'There are no games at that venue that night.');
        }
        if (!$by_admin && self::finished($night->night_date, max(array_map(function ($g) { return RSL_Courts::hm($g->end_time); }, $hosts)))) {
            return new WP_Error('rsl_job', 'The games at that venue have finished — ask the league to assign it.');
        }
        foreach ($mine as $c) {
            if ($c['venue_id'] && $c['venue_id'] !== intval($venue_id)) {
                if ($c['kind'] === 'supervisor') {
                    return new WP_Error('rsl_job', 'You\'re already supervising ' . $c['venue'] . ' that night.');
                }
                return new WP_Error('rsl_job', 'You\'re ' . ($c['kind'] === 'play' ? 'playing' : 'refereeing') . ' at another venue that night — a supervisor stays at their venue.');
            }
        }
        return null;
    }

    private static function insert_job($night_id, $type, $game_id, $venue_id, $user_id, $rate, $by_admin) {
        global $wpdb;
        $ok = $wpdb->insert(RSL_Database::table('jobs'), array('night_id' => $night_id, 'type' => $type, 'game_id' => $game_id, 'venue_id' => $venue_id,
            'user_id' => $user_id, 'rate' => $rate, 'claimed_at' => current_time('mysql'), 'assigned_by' => $by_admin ?: null));
        if (!$ok) {
            return new WP_Error('rsl_taken', 'Someone else has just taken that job.');
        }
        return self::job($wpdb->insert_id);
    }

    /**
     * Give up a person's jobs of one type that are still to come: a game
     * not yet over, or a venue whose games aren't all over (e.g. when they
     * stop being a ref). Jobs already done stay on the pay report. Returns
     * how many were released.
     */
    private static function release_jobs_to_come($user_id, $type) {
        global $wpdb;
        $jobs = RSL_Database::table('jobs');
        $rows = $wpdb->get_results($wpdb->prepare("SELECT j.id, n.night_date, MAX(g.end_time) AS last_end FROM $jobs j
            JOIN " . RSL_Database::table('nights') . " n ON n.id = j.night_id
            LEFT JOIN " . RSL_Database::table('games') . " g ON (j.type = 'ref' AND g.id = j.game_id) OR (j.type = 'supervisor' AND g.night_id = j.night_id AND g.venue_id = j.venue_id)
            WHERE j.user_id = %d AND j.type = %s AND n.night_date >= %s GROUP BY j.id, n.night_date", $user_id, $type, current_time('Y-m-d')));
        $released = 0;
        foreach ($rows as $r) {
            if (!$r->last_end || !self::finished($r->night_date, $r->last_end)) {
                $released += (int) $wpdb->delete($jobs, array('id' => $r->id));
            }
        }
        return $released;
    }

    /**
     * Can a ref still take this game themselves: no result yet and not over
     * (the same two rules as ref_eligibility())? The Ref Jobs page counts
     * these, not finished games only an admin can assign.
     */
    public static function open_to_refs($game) {
        return $game->status === 'scheduled' && !self::finished($game->night_date, $game->end_time);
    }

    /** Is a game (or the last game at a venue) that ends at $end on $night_date over? */
    private static function finished($night_date, $end) {
        return strtotime($night_date . ' ' . RSL_Courts::hm($end)) <= current_time('timestamp');
    }

    /** Drop a job. Refs can until release_hours before the night starts; admins any time. */
    public static function release($job_id, $user_id, $by_admin = false) {
        global $wpdb;
        $job = self::job($job_id);
        if (!$job) {
            return new WP_Error('rsl_job', 'That job no longer exists.');
        }
        if (!$by_admin) {
            if (intval($job->user_id) !== intval($user_id)) {
                return new WP_Error('rsl_job', 'That isn\'t your job.');
            }
            $start = self::night_start(RSL_Courts::get_night($job->night_id));
            if ($start && current_time('timestamp') > $start - self::settings()['release_hours'] * HOUR_IN_SECONDS) {
                return new WP_Error('rsl_job', 'It\'s too close to the night to drop this job yourself — please contact the league.');
            }
        }
        $wpdb->delete(RSL_Database::table('jobs'), array('id' => $job->id));
        return true;
    }

    /** Claims are open once fixtures are published, until the night is over (each job also closes once its games finish). */
    private static function open_for_claims($night) {
        if (!$night || !RSL_Fixtures::is_published($night)) {
            return new WP_Error('rsl_job', 'Jobs open once that week\'s fixtures are out.');
        }
        if ($night->night_date < current_time('Y-m-d')) {
            return new WP_Error('rsl_job', 'That night has already happened.');
        }
        return null;
    }

    /** First game time of the night as a timestamp (null without games). */
    public static function night_start($night) {
        if (!$night) {
            return null;
        }
        $first = null;
        foreach (RSL_Fixtures::games_for_night($night->id) as $g) {
            $t = RSL_Courts::hm($g->start_time);
            $first = $first === null || $t < $first ? $t : $first;
        }
        return $first ? strtotime($night->night_date . ' ' . $first) : null;
    }

    /**
     * Everything a person is doing on a night, in time order:
     * list of array(kind: play|ref|supervisor, start, end, venue_id, venue, court, game, job).
     * Supervising has no time (it's the whole night) and sorts first.
     */
    public static function commitments($user_id, $night_id) {
        global $wpdb;
        $night = RSL_Courts::get_night($night_id);
        if (!$night || !$user_id) {
            return array();
        }
        $out = array();
        $team = RSL_Teams::current_team_for_user($user_id, $night->season);
        $games = array();   // the night's games by id — a ref job's game is one of them, so no query per job
        $venues = array();  // venue_id => name, from the same rows
        foreach (RSL_Fixtures::games_for_night($night->id) as $g) {
            $games[intval($g->id)] = $g;
            $venues[intval($g->venue_id)] = $g->venue_name;
            if ($team && in_array(intval($team->id), array(intval($g->home_team_id), intval($g->away_team_id)), true)) {
                $out[] = array('kind' => 'play', 'start' => RSL_Courts::hm($g->start_time), 'end' => RSL_Courts::hm($g->end_time), 'venue_id' => intval($g->venue_id),
                    'venue' => $g->venue_name, 'court' => $g->court_name, 'game' => $g, 'job' => null);
            }
        }
        $jobs = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . RSL_Database::table('jobs') . " WHERE night_id = %d AND user_id = %d", $night->id, $user_id));
        foreach ($jobs as $j) {
            if ($j->type === 'ref') {
                $g = isset($games[intval($j->game_id)]) ? $games[intval($j->game_id)] : null;
                if ($g) {
                    $out[] = array('kind' => 'ref', 'start' => RSL_Courts::hm($g->start_time), 'end' => RSL_Courts::hm($g->end_time), 'venue_id' => intval($g->venue_id),
                        'venue' => $g->venue_name, 'court' => $g->court_name, 'game' => $g, 'job' => $j);
                }
            } else {
                if (isset($venues[intval($j->venue_id)])) {
                    $venue = $venues[intval($j->venue_id)];
                } else {
                    $v = RSL_Courts::get_venue($j->venue_id);
                    $venue = $v ? $v->name : '';
                }
                $out[] = array('kind' => 'supervisor', 'start' => '', 'end' => '', 'venue_id' => intval($j->venue_id), 'venue' => $venue, 'court' => '', 'game' => null, 'job' => $j);
            }
        }
        usort($out, function ($a, $b) { return array($a['start'], $a['kind']) <=> array($b['start'], $b['kind']); });
        return $out;
    }

    /** A WP_Error if a new commitment (start–end at a venue) clashes with $mine, else null. */
    private static function clash($mine, $start, $end, $venue_id) {
        $m = function ($hm) { list($h, $i) = array_map('intval', explode(':', $hm)); return $h * 60 + $i; };
        foreach ($mine as $c) {
            if ($c['kind'] === 'supervisor') {
                if ($c['venue_id'] !== $venue_id) {
                    return new WP_Error('rsl_job', 'You\'re supervising ' . $c['venue'] . ' that night, so your jobs need to be there.');
                }
                continue;
            }
            if ($m($c['start']) < $m($end) && $m($start) < $m($c['end'])) {
                return new WP_Error('rsl_job', 'You\'re ' . ($c['kind'] === 'play' ? 'playing' : 'refereeing') . ' then (' . RSL_Courts::pretty_time($c['start']) . ', ' . $c['court'] . ').');
            }
            if ($c['venue_id'] !== $venue_id) {
                $gap = $m($start) >= $m($c['end']) ? $m($start) - $m($c['end']) : $m($c['start']) - $m($end);
                if ($gap < self::TRAVEL_MINUTES) {
                    return new WP_Error('rsl_job', 'That\'s too close to your ' . ($c['kind'] === 'play' ? 'game' : 'other job') . ' at ' . $c['venue'] . ' to get between venues.');
                }
            }
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Scores, pay
    // ------------------------------------------------------------------

    /** Can this person enter this game's score? See why_cant_score(). $jobs: jobs_for_night() if already loaded. */
    public static function can_score($user_id, $game, $jobs = null) {
        return !self::why_cant_score($user_id, $game, $jobs);
    }

    /**
     * Why this person can't enter this game's score (WP_Error), or null if
     * they can. An admin always can. The ref of a game can on the night and
     * for two days after, while they're still a ref (or the league assigned
     * them the game) and not on either team — and not over a forfeit or a
     * result someone else (the league) entered: a ref corrects only their
     * own save (code rsl_locked). $jobs: jobs_for_night() if already loaded.
     */
    public static function why_cant_score($user_id, $game, $jobs = null) {
        if (!$game) {
            return new WP_Error('rsl_score', 'That game no longer exists.');
        }
        if (!$user_id) {
            return new WP_Error('rsl_score', 'Log in to enter scores.');
        }
        if (RSL_Access::can($user_id)) {
            return null;
        }
        $jobs = $jobs !== null ? $jobs : self::jobs_for_night($game->night_id);
        $job = isset($jobs['ref'][intval($game->id)]) ? $jobs['ref'][intval($game->id)] : null;
        if (!$job || intval($job->user_id) !== intval($user_id)) {
            return new WP_Error('rsl_score', 'Only this game\'s referee can enter its score.');
        }
        $today = current_time('Y-m-d');
        if ($game->night_date > $today || $game->night_date < date('Y-m-d', strtotime($today . ' -2 days'))) {
            return new WP_Error('rsl_score', 'Scores can be entered on the night, or the two days after.');
        }
        if ($game->status === 'forfeit' || ($game->status === 'played' && intval($game->result_by) !== intval($user_id))) {
            return new WP_Error('rsl_locked', 'The league has entered this result, so it\'s locked — contact the league if it\'s wrong.');
        }
        if (empty($job->assigned_by) && !self::is_ref($user_id)) {
            return new WP_Error('rsl_score', 'You\'re no longer a referee — contact the league.');
        }
        $team = RSL_Teams::current_team_for_user($user_id, $game->season);
        if ($team && in_array(intval($team->id), array(intval($game->home_team_id), intval($game->away_team_id)), true)) {
            return new WP_Error('rsl_score', 'You can\'t score your own team\'s game.');
        }
        return null;
    }

    /**
     * Jobs on nights from $from to $to (Y-m-d), nights not yet happened
     * excluded: user_id => array(name, email, refs, ref_pay, supervised, supervisor_pay, total, jobs).
     */
    public static function pay_report($from, $to) {
        global $wpdb;
        $to = min($to, current_time('Y-m-d'));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT j.*, n.night_date, u.display_name, u.user_email FROM " . RSL_Database::table('jobs') . " j
            JOIN " . RSL_Database::table('nights') . " n ON n.id = j.night_id JOIN {$wpdb->users} u ON u.ID = j.user_id
            WHERE n.night_date BETWEEN %s AND %s ORDER BY u.display_name, n.night_date", $from, $to));
        $out = array();
        foreach ($rows as $r) {
            $u = intval($r->user_id);
            if (!isset($out[$u])) {
                $out[$u] = array('name' => $r->display_name, 'email' => $r->user_email, 'refs' => 0, 'ref_pay' => 0.0, 'supervised' => 0, 'supervisor_pay' => 0.0, 'total' => 0.0, 'jobs' => array());
            }
            if ($r->type === 'ref') {
                $out[$u]['refs']++;
                $out[$u]['ref_pay'] += (float) $r->rate;
            } else {
                $out[$u]['supervised']++;
                $out[$u]['supervisor_pay'] += (float) $r->rate;
            }
            $out[$u]['total'] += (float) $r->rate;
            $out[$u]['jobs'][] = $r;
        }
        return $out;
    }

    /** A person's jobs and pay this season so far (nights that have happened) and booked ahead. */
    public static function earnings($user_id, $season) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT j.type, j.rate, n.night_date FROM " . RSL_Database::table('jobs') . " j
            JOIN " . RSL_Database::table('nights') . " n ON n.id = j.night_id WHERE j.user_id = %d AND n.season = %s", $user_id, $season));
        $today = current_time('Y-m-d');
        $out = array('done' => 0, 'earned' => 0.0, 'booked' => 0, 'booked_pay' => 0.0);
        foreach ($rows as $r) {
            if ($r->night_date <= $today) {
                $out['done']++;
                $out['earned'] += (float) $r->rate;
            } else {
                $out['booked']++;
                $out['booked_pay'] += (float) $r->rate;
            }
        }
        return $out;
    }

    public static function money($amount) {
        $amount = (float) $amount;
        return '$' . (floor($amount) == $amount ? number_format($amount, 0) : number_format($amount, 2));
    }
}
