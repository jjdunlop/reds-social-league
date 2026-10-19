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
 *   night, and a supervisor stays at the venue they supervise.
 * - Pay is a flat rate per game refereed and per night supervised (admin
 *   settings), recorded on the job when claimed. The pay report lists
 *   jobs on nights that have happened; admins remove no-shows.
 * - The ref of a game enters its score (the scoreboard on the Ref jobs page).
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

    /** Admin: approved, declined, or '' (not a ref). */
    public static function set_ref($user_id, $status, $by) {
        if (!in_array($status, array('approved', 'declined', ''), true) || !get_userdata($user_id)) {
            return new WP_Error('rsl_official', 'That person or status isn\'t valid.');
        }
        return self::save($user_id, array('ref' => $status), $by);
    }

    public static function set_supervisor($user_id, $on, $by) {
        if (!get_userdata($user_id)) {
            return new WP_Error('rsl_official', 'No account found for that person.');
        }
        return self::save($user_id, array('supervisor' => $on ? 1 : 0), $by);
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

    /** Could this person referee this game? The game, or WP_Error saying why not (for showing on the page too). */
    public static function check_ref($game_id, $user_id, $by_admin = 0) {
        $game = RSL_Fixtures::get($game_id);
        if (!$game) {
            return new WP_Error('rsl_job', 'That game no longer exists.');
        }
        $night = RSL_Courts::get_night($game->night_id);
        if (!$by_admin) {
            if (!self::is_ref($user_id)) {
                return new WP_Error('rsl_job', 'Only approved referees can take ref jobs.');
            }
            if ($e = self::open_for_claims($night)) {
                return $e;
            }
        }
        if ($game->status !== 'scheduled' && !$by_admin) {
            return new WP_Error('rsl_job', 'That game already has a result.');
        }
        $team = RSL_Teams::current_team_for_user($user_id, $game->season);
        if ($team && in_array(intval($team->id), array(intval($game->home_team_id), intval($game->away_team_id)), true)) {
            return new WP_Error('rsl_job', 'You can\'t referee your own team\'s game.');
        }
        $mine = self::commitments($user_id, $night->id);
        $refs = count(array_filter($mine, function ($c) { return $c['kind'] === 'ref'; }));
        if ($refs >= self::settings()['max_games']) {
            return new WP_Error('rsl_job', 'That\'s the most games one person can referee in a night (' . self::settings()['max_games'] . ').');
        }
        if ($e = self::clash($mine, RSL_Courts::hm($game->start_time), RSL_Courts::hm($game->end_time), intval($game->venue_id))) {
            return $e;
        }
        return $game;
    }

    /** Claim supervising a venue for a night. */
    public static function claim_supervisor($night_id, $venue_id, $user_id, $by_admin = 0) {
        $night = RSL_Courts::get_night($night_id);
        if (!$night) {
            return new WP_Error('rsl_job', 'That night no longer exists.');
        }
        if (!$by_admin) {
            if (!self::is_supervisor($user_id)) {
                return new WP_Error('rsl_job', 'Only court supervisors can take this job.');
            }
            if ($e = self::open_for_claims($night)) {
                return $e;
            }
        }
        $hosts = array_filter(RSL_Fixtures::games_for_night($night->id), function ($g) use ($venue_id) { return intval($g->venue_id) === intval($venue_id); });
        if (!$hosts) {
            return new WP_Error('rsl_job', 'There are no games at that venue that night.');
        }
        foreach (self::commitments($user_id, $night->id) as $c) {
            if ($c['venue_id'] && $c['venue_id'] !== intval($venue_id)) {
                return new WP_Error('rsl_job', 'You\'re ' . ($c['kind'] === 'play' ? 'playing' : 'refereeing') . ' at another venue that night — a supervisor stays at their venue.');
            }
        }
        return self::insert_job($night->id, 'supervisor', 0, intval($venue_id), $user_id, self::settings()['supervisor_rate'], $by_admin);
    }

    private static function insert_job($night_id, $type, $game_id, $venue_id, $user_id, $rate, $by_admin) {
        global $wpdb;
        $ok = $wpdb->insert(RSL_Database::table('jobs'), array('night_id' => $night_id, 'type' => $type, 'game_id' => $game_id, 'venue_id' => $venue_id,
            'user_id' => $user_id, 'rate' => $rate, 'assigned_by' => $by_admin ?: null));
        if (!$ok) {
            return new WP_Error('rsl_taken', 'Someone else has just taken that job.');
        }
        return self::job($wpdb->insert_id);
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

    /** Claims are open once fixtures are published, until the night is over. */
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
        foreach (RSL_Fixtures::games_for_night($night->id) as $g) {
            if ($team && in_array(intval($team->id), array(intval($g->home_team_id), intval($g->away_team_id)), true)) {
                $out[] = array('kind' => 'play', 'start' => RSL_Courts::hm($g->start_time), 'end' => RSL_Courts::hm($g->end_time), 'venue_id' => intval($g->venue_id),
                    'venue' => $g->venue_name, 'court' => $g->court_name, 'game' => $g, 'job' => null);
            }
        }
        $jobs = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . RSL_Database::table('jobs') . " WHERE night_id = %d AND user_id = %d", $night->id, $user_id));
        foreach ($jobs as $j) {
            if ($j->type === 'ref') {
                $g = RSL_Fixtures::get($j->game_id);
                if ($g) {
                    $out[] = array('kind' => 'ref', 'start' => RSL_Courts::hm($g->start_time), 'end' => RSL_Courts::hm($g->end_time), 'venue_id' => intval($g->venue_id),
                        'venue' => $g->venue_name, 'court' => $g->court_name, 'game' => $g, 'job' => $j);
                }
            } else {
                $v = RSL_Courts::get_venue($j->venue_id);
                $out[] = array('kind' => 'supervisor', 'start' => '', 'end' => '', 'venue_id' => intval($j->venue_id), 'venue' => $v ? $v->name : '', 'court' => '', 'game' => null, 'job' => $j);
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

    /** The ref of a game (or an admin) can enter its score on the night and for two days after. */
    public static function can_score($user_id, $game) {
        if (!$user_id || !$game) {
            return false;
        }
        if (user_can($user_id, 'manage_options')) {
            return true;
        }
        $today = current_time('Y-m-d');
        if ($game->night_date > $today || $game->night_date < date('Y-m-d', strtotime($today . ' -2 days'))) {
            return false;
        }
        $jobs = self::jobs_for_night($game->night_id);
        return isset($jobs['ref'][intval($game->id)]) && intval($jobs['ref'][intval($game->id)]->user_id) === intval($user_id);
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
