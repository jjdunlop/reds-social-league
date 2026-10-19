<?php
/**
 * League seasons: four a year, timed around the VVL (see the design doc):
 * Pre-season (Summer), Mid-season 1 (Autumn), Mid-season 2 (Winter),
 * Post-season (Spring). A season is identified by a key like "2027-pre".
 * Dates come later (fixtures phase); for now a season is just a label and
 * the admin picks which one is current.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RSL_Seasons {

    const CURRENT_OPTION = 'rsl_current_season';
    const SIGNUP_OPTION = 'rsl_signup_season';

    public static function parts() {
        return array(
            'pre' => 'Pre-season (Summer)',
            'mid1' => 'Mid-season 1 (Autumn)',
            'mid2' => 'Mid-season 2 (Winter)',
            'post' => 'Post-season (Spring)',
        );
    }

    /** Is this a well-formed season key? e.g. 2027-pre */
    public static function is_valid($key) {
        if (!is_string($key) || !preg_match('/^(\d{4})-([a-z0-9]+)$/', $key, $m)) {
            return false;
        }
        return isset(self::parts()[$m[2]]);
    }

    /** "2027-pre" -> "2027 Pre-season (Summer)" */
    public static function label($key) {
        if (!self::is_valid($key)) {
            return (string) $key;
        }
        list($year, $part) = explode('-', $key, 2);
        return $year . ' ' . self::parts()[$part];
    }

    /** Every season this year and next, in calendar order. */
    public static function choices() {
        $year = (int) current_time('Y');
        $out = array();
        foreach (array($year, $year + 1) as $y) {
            foreach (array_keys(self::parts()) as $part) {
                $out[$y . '-' . $part] = self::label($y . '-' . $part);
            }
        }
        return $out;
    }

    /** The season the public pages show; until an admin sets one, the season for today. */
    public static function current() {
        $key = get_option(self::CURRENT_OPTION, '');
        return self::is_valid($key) ? $key : self::for_date(current_time('Y-m-d'));
    }

    /**
     * The season a date falls in, by quarter (Jan–Mar pre, Apr–Jun mid1,
     * Jul–Sep mid2, Oct–Dec post) — roughly the calendar in the design doc,
     * until real season dates arrive with fixtures.
     */
    public static function for_date($date) {
        $month = (int) substr($date, 5, 2);
        $parts = array_keys(self::parts());
        return substr($date, 0, 4) . '-' . $parts[intdiv(max(1, $month) - 1, 3)];
    }

    public static function set_current($key) {
        if (!self::is_valid($key)) {
            return false;
        }
        update_option(self::CURRENT_OPTION, $key, false);
        return true;
    }

    /** The season after this one: "2027-post" -> "2028-pre". */
    public static function next($key) {
        if (!self::is_valid($key)) {
            return self::next(self::current());
        }
        list($year, $part) = explode('-', $key, 2);
        $parts = array_keys(self::parts());
        $i = array_search($part, $parts, true);
        return $i === count($parts) - 1 ? ((int) $year + 1) . '-' . $parts[0] : $year . '-' . $parts[$i + 1];
    }

    /**
     * The season people are signing up for — joining, applying, the free
     * agent list. Usually the current season, but next season's sign-ups
     * open (its team-building window) while this one is still finishing, so
     * once next season's first night is set and its window has opened, it's
     * next season. An admin can pin it (SIGNUP_OPTION) either way.
     */
    public static function signup() {
        $key = get_option(self::SIGNUP_OPTION, '');
        if (self::is_valid($key)) {
            return $key;
        }
        $current = self::current();
        $next = self::next($current);
        if (class_exists('RSL_Free_Agents')) {
            $dates = RSL_Free_Agents::dates($next);
            if ($dates && current_time('Y-m-d') >= $dates['opens']) {
                return $next;
            }
        }
        return $current;
    }

    /** Pin (or with '' unpin) the sign-up season. */
    public static function set_signup($key) {
        if ($key === '') {
            delete_option(self::SIGNUP_OPTION);
            return true;
        }
        if (!self::is_valid($key)) {
            return false;
        }
        update_option(self::SIGNUP_OPTION, $key, false);
        return true;
    }
}
