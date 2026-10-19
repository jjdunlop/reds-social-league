<?php
/**
 * League players: registering for the social league (separate from paying
 * — see RSL_Rego). Registration is a short profile: experience answers that
 * seed ratings and help place free agents, and a "looking for a team" flag.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RSL_Players {

    /** Highest level played, in plain words, lowest first (the keys are stored — never rename them). */
    public static function levels() {
        return array(
            'never' => 'Never played',
            'school' => 'Played at school or uni',
            'social' => 'Social comp regular',
            'vvl' => 'Played club or VVL',
            'state' => 'State League or higher',
        );
    }

    public static function years() {
        return array(
            'lt1' => 'Less than a year',
            '1to3' => '1–3 years',
            'gt3' => 'More than 3 years',
        );
    }

    public static function positions() {
        return array(
            'setter' => 'Setter',
            'outside' => 'Outside',
            'middle' => 'Middle',
            'opposite' => 'Opposite',
            'libero' => 'Libero',
        );
    }

    /**
     * Register (or update) a player. $data: level, years, positions (array),
     * looking (bool — a new registration only: updating someone's details
     * never touches the free agent list, which has its own form). Returns
     * true or WP_Error.
     */
    public static function register($user_id, $data) {
        global $wpdb;
        if (!get_userdata($user_id)) {
            return new WP_Error('rsl_user', 'Please log in first.');
        }
        $level = isset($data['level']) ? (string) $data['level'] : '';
        $years = isset($data['years']) ? (string) $data['years'] : '';
        if (!isset(self::levels()[$level])) {
            return new WP_Error('rsl_level', 'Tell us the highest level you\'ve played.');
        }
        if (!isset(self::years()[$years])) {
            return new WP_Error('rsl_years', 'Tell us how long you\'ve been playing.');
        }
        $positions = array_values(array_intersect((array) (isset($data['positions']) ? $data['positions'] : array()), array_keys(self::positions())));
        $row = array(
            'level' => $level,
            'years' => $years,
            'positions' => implode(',', $positions),
        );
        if (self::get($user_id)) {
            // A stale looking flag (last season's list) must never sign them
            // up for the next one with default choices they didn't see.
            $row['updated_at'] = current_time('mysql');
            $wpdb->update(RSL_Database::table('players'), $row, array('user_id' => $user_id));
            return true;
        }
        $row['looking'] = empty($data['looking']) ? 0 : 1;
        $row['user_id'] = $user_id;
        $row['registered_at'] = current_time('mysql');
        $wpdb->insert(RSL_Database::table('players'), $row);
        if ($row['looking']) {
            self::set_looking($user_id, true);
        }
        return true;
    }

    public static function get($user_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . RSL_Database::table('players') . " WHERE user_id = %d", $user_id));
    }

    public static function is_registered($user_id) {
        return $user_id && (bool) self::get($user_id);
    }

    /**
     * On or off the free agent list for the sign-up season (RSL_Free_Agents
     * keeps the list; the looking column mirrors it for older screens).
     */
    public static function set_looking($user_id, $looking) {
        global $wpdb;
        $season = RSL_Seasons::signup();
        if ($looking) {
            $row = RSL_Free_Agents::get($user_id, $season);
            $result = RSL_Free_Agents::is_open($row) ? $row : RSL_Free_Agents::join($user_id, $season, array('browsable' => 1));
        } else {
            $result = RSL_Free_Agents::is_open(RSL_Free_Agents::get($user_id, $season)) ? RSL_Free_Agents::leave($user_id, $season) : true;
        }
        $wpdb->update(RSL_Database::table('players'), array('looking' => $looking && !is_wp_error($result) ? 1 : 0, 'updated_at' => current_time('mysql')), array('user_id' => $user_id));
        return !is_wp_error($result);
    }

    public static function count() {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM " . RSL_Database::table('players'));
    }
}
