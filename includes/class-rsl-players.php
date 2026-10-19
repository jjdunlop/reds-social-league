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

    public static function levels() {
        return array(
            'never' => 'Never played competitively',
            'social' => 'Social / casual only',
            'school' => 'School or uni volleyball',
            'vvl' => 'VVL (any division)',
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
     * looking (bool). Returns true or WP_Error.
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
            'looking' => empty($data['looking']) ? 0 : 1,
        );
        if (self::get($user_id)) {
            $row['updated_at'] = current_time('mysql');
            $wpdb->update(RSL_Database::table('players'), $row, array('user_id' => $user_id));
        } else {
            $row['user_id'] = $user_id;
            $wpdb->insert(RSL_Database::table('players'), $row);
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

    public static function set_looking($user_id, $looking) {
        global $wpdb;
        return (bool) $wpdb->update(RSL_Database::table('players'), array('looking' => $looking ? 1 : 0, 'updated_at' => current_time('mysql')), array('user_id' => $user_id));
    }

    public static function count() {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM " . RSL_Database::table('players'));
    }
}
