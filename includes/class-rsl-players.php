<?php
/**
 * League players: registering for the social league (separate from paying
 * — see RSL_Rego). Registration is a short profile: experience answers that
 * seed ratings and help place free agents, and a "looking for a team" flag.
 * It needs a complete club profile first (name, mobile, date of birth and
 * gender, as for VVL trials), read from the account and never copied here;
 * whether someone is under 18 comes from that date of birth (is_under18()).
 */

if (!defined('ABSPATH')) {
    exit;
}

class RSL_Players {

    /**
     * Highest level played, in plain words, lowest first (the keys are stored — never rename them).
     * 0.22.0 (club decision, 7 Nov 2026): the top two read Played State League and Played Premier League
     * (before, Played club or VVL and State League or higher, which overlapped: State League is VVL); the
     * keys and their order are unchanged, so the free agent level scores keep their meaning.
     */
    public static function levels() {
        return array(
            'never' => 'Never played',
            'school' => 'Played at school or uni',
            'social' => 'Social comp regular',
            'vvl' => 'Played State League',
            'state' => 'Played Premier League',
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
        // A new registration needs the club profile; updating experience
        // answers later doesn't ask again.
        if (!self::get($user_id)) {
            $missing = self::profile($user_id)['missing'];
            if ($missing) {
                return new WP_Error('rsl_profile', 'Add your ' . self::list_words(array_map('strtolower', $missing)) . ' to your club profile first, then register.');
            }
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
     * Joining is a season the player picks, so it goes through
     * RSL_Seasons::pick_open(): with the sign-up season not open (finished,
     * nothing open) it's refused, never a finished season's list (0.18.0).
     * true, or the WP_Error that refused it.
     */
    public static function set_looking($user_id, $looking) {
        global $wpdb;
        $season = RSL_Seasons::signup();
        if ($looking) {
            $row = RSL_Free_Agents::get($user_id, $season);
            $pick = RSL_Seasons::pick_open($season);
            if (RSL_Free_Agents::is_open($row)) {
                $result = $row;
            } elseif (is_wp_error($pick)) {
                $result = $pick;
            } else {
                $result = RSL_Free_Agents::join($user_id, $pick, array('browsable' => 1));
            }
        } else {
            $result = RSL_Free_Agents::is_open(RSL_Free_Agents::get($user_id, $season)) ? RSL_Free_Agents::leave($user_id, $season) : true;
        }
        $wpdb->update(RSL_Database::table('players'), array('looking' => $looking && !is_wp_error($result) ? 1 : 0, 'updated_at' => current_time('mysql')), array('user_id' => $user_id));
        return is_wp_error($result) ? $result : true;
    }

    public static function count() {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM " . RSL_Database::table('players'));
    }

    // ------------------------------------------------------------------
    // The club profile (the account's own details)
    // ------------------------------------------------------------------

    /**
     * What registering needs from the club profile: the same details as a
     * VVL trial application. Keys are the account's own (Ultimate Member)
     * fields; the league reads them and never stores a copy.
     */
    public static function profile_fields() {
        return array(
            'first_name' => 'First name',
            'last_name' => 'Last name',
            'mobile_number' => 'Mobile',
            'birth_date' => 'Date of birth',
            'gender' => 'Gender',
        );
    }

    /**
     * The player's profile for showing back to them: 'values' (label =>
     * value, '' when missing) and 'missing' (labels). A date of birth that
     * isn't a real date counts as missing.
     */
    public static function profile($user_id) {
        $values = array();
        $missing = array();
        foreach (self::profile_fields() as $key => $label) {
            if ($key === 'gender') {
                $value = self::gender($user_id);
            } elseif ($key === 'birth_date') {
                $dob = self::birth_date($user_id);
                $value = $dob === '' ? '' : DateTime::createFromFormat('!Y-m-d', $dob)->format('j F Y');
            } else {
                $value = trim((string) get_user_meta($user_id, $key, true));
            }
            $values[$label] = $value;
            if ($value === '') {
                $missing[] = $label;
            }
        }
        return array('values' => $values, 'missing' => $missing);
    }

    /** Where a player fills in their club profile (the page VVL trials send them to). */
    public static function profile_edit_url() {
        $page = get_page_by_path('um-member-profile-custom');
        if ($page && $page->post_status === 'publish') {
            return add_query_arg(array('profiletab' => 'main', 'um_action' => 'edit'), get_permalink($page));
        }
        if (function_exists('um_user_profile_url') && get_current_user_id()) {
            return add_query_arg('um_action', 'edit', um_user_profile_url(get_current_user_id()));
        }
        return admin_url('profile.php');
    }

    /** The account's date of birth as Y-m-d, or '' when it's missing or not a real date. */
    public static function birth_date($user_id) {
        $dates = self::birth_dates(array($user_id));
        return isset($dates[(int) $user_id]) ? $dates[(int) $user_id] : '';
    }

    /** Dates of birth for several accounts in one query: user id => Y-m-d or ''. */
    public static function birth_dates($user_ids) {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $user_ids))));
        $out = array_fill_keys($ids, '');
        if (!$ids) {
            return $out;
        }
        $rows = $wpdb->get_results("SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'birth_date' AND user_id IN (" . implode(',', $ids) . ')');
        $today = current_time('Y-m-d');
        foreach ($rows as $r) {
            // The profile form saves YYYY/MM/DD.
            if (preg_match('/^(\d{4})[\/-](\d{1,2})[\/-](\d{1,2})$/', trim((string) $r->meta_value), $m)
                && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && (int) $m[1] >= 1900) {
                $dob = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
                $out[(int) $r->user_id] = $dob <= $today ? $dob : '';
            }
        }
        return $out;
    }

    /** Whole years old on $date (both Y-m-d). */
    public static function age_on($dob, $date) {
        list($y, $m, $d) = array_map('intval', explode('-', $dob));
        list($y2, $m2, $d2) = array_map('intval', explode('-', $date));
        return $y2 - $y - (($m2 < $m || ($m2 === $m && $d2 < $d)) ? 1 : 0);
    }

    /**
     * Under 18 for a season, from the account's date of birth, judged on the
     * earlier of today and the season's first night: someone who is 17 while
     * captains are browsing counts, and so does someone still 17 when the
     * season starts, all season. With no date of birth on the account we
     * can't tell, so they count as under 18 (the safe side).
     */
    public static function is_under18($user_id, $season = '') {
        $map = self::under18_map(array($user_id), $season);
        return !empty($map[(int) $user_id]);
    }

    /** is_under18() for several accounts in one query: user id => bool. */
    public static function under18_map($user_ids, $season = '') {
        $on = current_time('Y-m-d');
        $dates = $season !== '' && class_exists('RSL_Free_Agents') && RSL_Seasons::is_valid($season) ? RSL_Free_Agents::dates($season) : null;
        if ($dates && $dates['first_night'] < $on) {
            $on = $dates['first_night'];
        }
        $out = array();
        foreach (self::birth_dates($user_ids) as $id => $dob) {
            $out[$id] = $dob === '' || self::age_on($dob, $on) < 18;
        }
        return $out;
    }

    /** Gender from the profile: the form's field first, then the older one some accounts still have. */
    private static function gender($user_id) {
        if (class_exists('TeamOversight_Database') && method_exists('TeamOversight_Database', 'get_member_gender')) {
            return trim((string) TeamOversight_Database::get_member_gender($user_id));
        }
        $gender = trim((string) get_user_meta($user_id, 'gender_dropdown', true));
        if ($gender === '') {
            $older = maybe_unserialize(get_user_meta($user_id, 'gender', true));
            $gender = is_array($older) ? (string) reset($older) : (string) $older;
        }
        return trim($gender);
    }

    /** "a", "a and b", "a, b and c". */
    public static function list_words($items) {
        $items = array_values($items);
        if (count($items) < 2) {
            return implode('', $items);
        }
        $last = array_pop($items);
        return implode(', ', $items) . ' and ' . $last;
    }
}
