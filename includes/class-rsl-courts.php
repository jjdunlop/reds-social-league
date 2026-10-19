<?php
/**
 * Courts and game nights — what fixtures are made from.
 *
 *  - Venues have courts; each court has its usual game-night hours. A
 *    venue has a home division (Ranked or Unranked).
 *  - A season has settings: first and last game night, the weekday
 *    (Sunday), and a game length per division.
 *  - Game nights are generated for the season (every weekday between the
 *    dates) and can be regular, finals or a bye.
 *  - Exceptions are stored per night: a court off or on different hours,
 *    or a venue hosting the other division. No row = the usual.
 *  - slots_for_night() turns all that into game slots: court, start, end,
 *    division — what fixtures fill (and, later, refs claim).
 *
 * Data only — no output. Times are 'HH:MM'.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RSL_Courts {

    const SETTINGS_OPTION = 'rsl_season_settings';
    const NIGHT_TYPES = array('regular' => 'Regular', 'finals' => 'Finals', 'bye' => 'Bye');
    const DEFAULT_GAME_MINUTES = 60;

    // ------------------------------------------------------------------
    // Venues and courts
    // ------------------------------------------------------------------

    public static function create_venue($name, $division = 'ranked') {
        global $wpdb;
        $name = trim(sanitize_text_field($name));
        if ($name === '') {
            return new WP_Error('rsl_venue', 'A venue needs a name.');
        }
        $division = isset(RSL_Teams::DIVISIONS[$division]) ? $division : 'ranked';
        $sort = (int) $wpdb->get_var("SELECT COALESCE(MAX(sort), 0) + 1 FROM " . RSL_Database::table('venues'));
        $wpdb->insert(RSL_Database::table('venues'), array('name' => $name, 'division' => $division, 'sort' => $sort));
        return self::get_venue($wpdb->insert_id);
    }

    public static function update_venue($venue_id, $args) {
        global $wpdb;
        $data = array();
        if (isset($args['name']) && trim(sanitize_text_field($args['name'])) !== '') {
            $data['name'] = trim(sanitize_text_field($args['name']));
        }
        if (isset($args['division']) && isset(RSL_Teams::DIVISIONS[$args['division']])) {
            $data['division'] = $args['division'];
        }
        if (isset($args['active'])) {
            $data['active'] = $args['active'] ? 1 : 0;
        }
        if ($data) {
            $wpdb->update(RSL_Database::table('venues'), $data, array('id' => $venue_id));
        }
        return self::get_venue($venue_id);
    }

    public static function get_venue($venue_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . RSL_Database::table('venues') . " WHERE id = %d", $venue_id));
    }

    public static function venues($active_only = true) {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM " . RSL_Database::table('venues') . ($active_only ? " WHERE active = 1" : '') . " ORDER BY sort, id");
    }

    /** Add a court with its usual hours. */
    public static function create_court($venue_id, $name, $start, $end) {
        global $wpdb;
        if (!self::get_venue($venue_id)) {
            return new WP_Error('rsl_venue', 'That venue no longer exists.');
        }
        $name = trim(sanitize_text_field($name));
        if ($name === '') {
            return new WP_Error('rsl_court', 'A court needs a name (e.g. Court 1).');
        }
        if ($error = self::check_hours($start, $end)) {
            return $error;
        }
        $sort = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(sort), 0) + 1 FROM " . RSL_Database::table('courts') . " WHERE venue_id = %d", $venue_id));
        $wpdb->insert(RSL_Database::table('courts'), array('venue_id' => $venue_id, 'name' => $name, 'default_start' => $start, 'default_end' => $end, 'sort' => $sort));
        return self::get_court($wpdb->insert_id);
    }

    public static function update_court($court_id, $args) {
        global $wpdb;
        $court = self::get_court($court_id);
        if (!$court) {
            return new WP_Error('rsl_court', 'That court no longer exists.');
        }
        $data = array();
        if (isset($args['name']) && trim(sanitize_text_field($args['name'])) !== '') {
            $data['name'] = trim(sanitize_text_field($args['name']));
        }
        $start = isset($args['start']) ? $args['start'] : self::hm($court->default_start);
        $end = isset($args['end']) ? $args['end'] : self::hm($court->default_end);
        if (isset($args['start']) || isset($args['end'])) {
            if ($error = self::check_hours($start, $end)) {
                return $error;
            }
            $data['default_start'] = $start;
            $data['default_end'] = $end;
        }
        if (isset($args['active'])) {
            $data['active'] = $args['active'] ? 1 : 0;
        }
        if ($data) {
            $wpdb->update(RSL_Database::table('courts'), $data, array('id' => $court->id));
        }
        return self::get_court($court->id);
    }

    public static function get_court($court_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . RSL_Database::table('courts') . " WHERE id = %d", $court_id));
    }

    public static function courts($venue_id, $active_only = true) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM " . RSL_Database::table('courts') . " WHERE venue_id = %d" . ($active_only ? " AND active = 1" : '') . " ORDER BY sort, id", $venue_id));
    }

    // ------------------------------------------------------------------
    // Season settings
    // ------------------------------------------------------------------

    /** array(start, end, weekday (0 = Sunday), minutes => array(ranked, unranked)) */
    public static function settings($season) {
        $all = get_option(self::SETTINGS_OPTION, array());
        $s = is_array($all) && isset($all[$season]) ? $all[$season] : array();
        $minutes = isset($s['minutes']) && is_array($s['minutes']) ? $s['minutes'] : array();
        foreach (array_keys(RSL_Teams::DIVISIONS) as $division) {
            $minutes[$division] = isset($minutes[$division]) ? intval($minutes[$division]) : self::DEFAULT_GAME_MINUTES;
        }
        return array(
            'start' => isset($s['start']) ? $s['start'] : '',
            'end' => isset($s['end']) ? $s['end'] : '',
            'weekday' => isset($s['weekday']) ? intval($s['weekday']) : 0,
            'minutes' => $minutes,
        );
    }

    public static function save_settings($season, $args) {
        if (!RSL_Seasons::is_valid($season)) {
            return new WP_Error('rsl_season', 'Pick a valid season.');
        }
        $start = isset($args['start']) ? (string) $args['start'] : '';
        $end = isset($args['end']) ? (string) $args['end'] : '';
        if (!self::is_date($start) || !self::is_date($end) || $end < $start) {
            return new WP_Error('rsl_dates', 'Give the first and last game night, the first before the last.');
        }
        $minutes = array();
        foreach (array_keys(RSL_Teams::DIVISIONS) as $division) {
            $m = isset($args['minutes'][$division]) ? intval($args['minutes'][$division]) : self::DEFAULT_GAME_MINUTES;
            if ($m < 15 || $m > 180) {
                return new WP_Error('rsl_minutes', 'Game length must be between 15 and 180 minutes.');
            }
            $minutes[$division] = $m;
        }
        $all = get_option(self::SETTINGS_OPTION, array());
        $all = is_array($all) ? $all : array();
        $all[$season] = array(
            'start' => $start,
            'end' => $end,
            'weekday' => isset($args['weekday']) ? max(0, min(6, intval($args['weekday']))) : 0,
            'minutes' => $minutes,
        );
        update_option(self::SETTINGS_OPTION, $all, false);
        return self::settings($season);
    }

    // ------------------------------------------------------------------
    // Game nights
    // ------------------------------------------------------------------

    /**
     * Make the season's nights match its dates: every chosen weekday from
     * the first to the last game night. Existing nights (and their
     * exceptions) are kept; nights now outside the dates are removed.
     * Returns array(added, removed).
     */
    public static function generate_nights($season) {
        global $wpdb;
        $s = self::settings($season);
        if (!self::is_date($s['start']) || !self::is_date($s['end'])) {
            return new WP_Error('rsl_dates', 'Set the season\'s first and last game night first.');
        }
        $wanted = array();
        $day = strtotime($s['start']);
        while (intval(date('w', $day)) !== $s['weekday']) {
            $day = strtotime('+1 day', $day);
        }
        for (; $day <= strtotime($s['end']); $day = strtotime('+7 days', $day)) {
            $wanted[] = date('Y-m-d', $day);
        }

        $existing = $wpdb->get_results($wpdb->prepare("SELECT id, night_date FROM " . RSL_Database::table('nights') . " WHERE season = %s", $season));
        $have = array();
        $removed = 0;
        foreach ($existing as $n) {
            if (in_array($n->night_date, $wanted, true)) {
                $have[] = $n->night_date;
            } else {
                self::delete_night($n->id);
                $removed++;
            }
        }
        $added = 0;
        foreach (array_diff($wanted, $have) as $date) {
            $wpdb->insert(RSL_Database::table('nights'), array('season' => $season, 'night_date' => $date, 'type' => 'regular'));
            $added++;
        }
        return array('added' => $added, 'removed' => $removed);
    }

    private static function delete_night($night_id) {
        global $wpdb;
        $wpdb->delete(RSL_Database::table('night_courts'), array('night_id' => $night_id));
        $wpdb->delete(RSL_Database::table('night_venues'), array('night_id' => $night_id));
        $wpdb->delete(RSL_Database::table('nights'), array('id' => $night_id));
    }

    public static function nights($season) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM " . RSL_Database::table('nights') . " WHERE season = %s ORDER BY night_date", $season));
    }

    public static function get_night($night_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . RSL_Database::table('nights') . " WHERE id = %d", $night_id));
    }

    public static function set_night_type($night_id, $type, $note = null) {
        global $wpdb;
        if (!isset(self::NIGHT_TYPES[$type]) || !self::get_night($night_id)) {
            return new WP_Error('rsl_night', 'That night or type isn\'t valid.');
        }
        $data = array('type' => $type);
        if ($note !== null) {
            $data['note'] = substr(sanitize_text_field($note), 0, 255);
        }
        $wpdb->update(RSL_Database::table('nights'), $data, array('id' => $night_id));
        return self::get_night($night_id);
    }

    // ------------------------------------------------------------------
    // Per-night exceptions
    // ------------------------------------------------------------------

    /**
     * Set one court's plan for one night. Matching the court's usual hours
     * (and available) removes the exception, so "the usual" stays the usual.
     */
    public static function set_court_night($night_id, $court_id, $available, $start = null, $end = null) {
        global $wpdb;
        $court = self::get_court($court_id);
        if (!$court || !self::get_night($night_id)) {
            return new WP_Error('rsl_night', 'That night or court no longer exists.');
        }
        $start = $start ?: self::hm($court->default_start);
        $end = $end ?: self::hm($court->default_end);
        if ($available && ($error = self::check_hours($start, $end))) {
            return $error;
        }
        $table = RSL_Database::table('night_courts');
        $wpdb->delete($table, array('night_id' => $night_id, 'court_id' => $court_id));
        $usual = $available && $start === self::hm($court->default_start) && $end === self::hm($court->default_end);
        if (!$usual) {
            $wpdb->insert($table, array('night_id' => $night_id, 'court_id' => $court_id, 'available' => $available ? 1 : 0,
                'start_time' => $available ? $start : null, 'end_time' => $available ? $end : null));
        }
        return true;
    }

    /** A venue hosts this division on this night ('' = its usual division). */
    public static function set_venue_night_division($night_id, $venue_id, $division) {
        global $wpdb;
        $venue = self::get_venue($venue_id);
        if (!$venue || !self::get_night($night_id)) {
            return new WP_Error('rsl_night', 'That night or venue no longer exists.');
        }
        $table = RSL_Database::table('night_venues');
        $wpdb->delete($table, array('night_id' => $night_id, 'venue_id' => $venue_id));
        if (isset(RSL_Teams::DIVISIONS[$division]) && $division !== $venue->division) {
            $wpdb->insert($table, array('night_id' => $night_id, 'venue_id' => $venue_id, 'division' => $division));
        }
        return true;
    }

    /**
     * Copy one night's exceptions (courts and venue divisions) onto every
     * later night in the season, replacing theirs. Night types aren't copied.
     * Returns how many nights were updated.
     */
    public static function copy_night_forward($night_id) {
        global $wpdb;
        $night = self::get_night($night_id);
        if (!$night) {
            return 0;
        }
        $courts = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . RSL_Database::table('night_courts') . " WHERE night_id = %d", $night->id));
        $venues = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . RSL_Database::table('night_venues') . " WHERE night_id = %d", $night->id));
        $later = $wpdb->get_col($wpdb->prepare("SELECT id FROM " . RSL_Database::table('nights') . " WHERE season = %s AND night_date > %s", $night->season, $night->night_date));
        foreach ($later as $id) {
            $wpdb->delete(RSL_Database::table('night_courts'), array('night_id' => $id));
            $wpdb->delete(RSL_Database::table('night_venues'), array('night_id' => $id));
            foreach ($courts as $c) {
                $wpdb->insert(RSL_Database::table('night_courts'), array('night_id' => $id, 'court_id' => $c->court_id, 'available' => $c->available, 'start_time' => $c->start_time, 'end_time' => $c->end_time));
            }
            foreach ($venues as $v) {
                $wpdb->insert(RSL_Database::table('night_venues'), array('night_id' => $id, 'venue_id' => $v->venue_id, 'division' => $v->division));
            }
        }
        return count($later);
    }

    // ------------------------------------------------------------------
    // The plan for a night, and its game slots
    // ------------------------------------------------------------------

    /**
     * Everything about one night, venue by venue: the division it hosts,
     * the game length, and each court's plan (available, start, end, its
     * slots, whether that's an exception).
     */
    public static function night_plan($night_id) {
        global $wpdb;
        $night = self::get_night($night_id);
        if (!$night) {
            return null;
        }
        $settings = self::settings($night->season);
        $court_ex = array();
        foreach ($wpdb->get_results($wpdb->prepare("SELECT * FROM " . RSL_Database::table('night_courts') . " WHERE night_id = %d", $night->id)) as $row) {
            $court_ex[$row->court_id] = $row;
        }
        $venue_ex = array();
        foreach ($wpdb->get_results($wpdb->prepare("SELECT * FROM " . RSL_Database::table('night_venues') . " WHERE night_id = %d", $night->id)) as $row) {
            $venue_ex[$row->venue_id] = $row->division;
        }

        $venues = array();
        foreach (self::venues() as $venue) {
            $division = isset($venue_ex[$venue->id]) ? $venue_ex[$venue->id] : $venue->division;
            $minutes = $settings['minutes'][$division];
            $courts = array();
            foreach (self::courts($venue->id) as $court) {
                $ex = isset($court_ex[$court->id]) ? $court_ex[$court->id] : null;
                $available = $night->type !== 'bye' && (!$ex || (int) $ex->available === 1);
                $start = $ex && $ex->start_time ? self::hm($ex->start_time) : self::hm($court->default_start);
                $end = $ex && $ex->end_time ? self::hm($ex->end_time) : self::hm($court->default_end);
                $courts[] = array(
                    'court' => $court,
                    'available' => $available,
                    'start' => $start,
                    'end' => $end,
                    'changed' => (bool) $ex,
                    'slots' => $available ? self::slot_times($start, $end, $minutes) : array(),
                );
            }
            $venues[] = array('venue' => $venue, 'division' => $division, 'division_changed' => isset($venue_ex[$venue->id]), 'minutes' => $minutes, 'courts' => $courts);
        }
        return array('night' => $night, 'venues' => $venues);
    }

    /** Flat list of the night's game slots: venue, court, start, end, division. */
    public static function slots_for_night($night_id) {
        $plan = self::night_plan($night_id);
        $slots = array();
        if (!$plan) {
            return $slots;
        }
        foreach ($plan['venues'] as $v) {
            foreach ($v['courts'] as $c) {
                foreach ($c['slots'] as $t) {
                    $slots[] = array('venue_id' => (int) $v['venue']->id, 'court_id' => (int) $c['court']->id, 'start' => $t[0], 'end' => $t[1], 'division' => $v['division']);
                }
            }
        }
        return $slots;
    }

    /** Back-to-back games that fit fully between start and end. */
    public static function slot_times($start, $end, $minutes) {
        $out = array();
        $t = self::minutes_of($start);
        $stop = self::minutes_of($end);
        $minutes = max(1, intval($minutes));
        while ($t + $minutes <= $stop) {
            $out[] = array(self::hm_of($t), self::hm_of($t + $minutes));
            $t += $minutes;
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    public static function is_time($t) {
        return is_string($t) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
    }

    private static function is_date($d) {
        return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d);
    }

    private static function check_hours($start, $end) {
        if (!self::is_time($start) || !self::is_time($end)) {
            return new WP_Error('rsl_time', 'Times must look like 19:00.');
        }
        if ($end <= $start) {
            return new WP_Error('rsl_time', 'The finish time must be after the start time.');
        }
        return null;
    }

    /** '19:00:00' -> '19:00' */
    public static function hm($time) {
        return substr((string) $time, 0, 5);
    }

    private static function minutes_of($hm) {
        list($h, $m) = array_map('intval', explode(':', $hm));
        return $h * 60 + $m;
    }

    private static function hm_of($minutes) {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** "19:00" -> "7pm", "19:30" -> "7:30pm" */
    public static function pretty_time($hm) {
        $ts = strtotime('2000-01-01 ' . $hm);
        return date('i', $ts) === '00' ? date('ga', $ts) : date('g:ia', $ts);
    }
}
