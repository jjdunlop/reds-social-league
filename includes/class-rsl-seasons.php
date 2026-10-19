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
}
