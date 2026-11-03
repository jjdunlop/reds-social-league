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

    /** Every season this year and the next two, in calendar order (the admin season pickers, and the candidates for open_for_signup()). */
    public static function choices() {
        $year = (int) current_time('Y');
        $out = array();
        foreach (array($year, $year + 1, $year + 2) as $y) {
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

    // ------------------------------------------------------------------
    // Spans, date labels and the seasons open for sign-up (0.15.0)
    // ------------------------------------------------------------------

    /** "2027-pre" -> "2027 Pre-season" (label() without the time of year). A bad key: (string) $key. Pure. */
    public static function short_label($key) {
        if (!self::is_valid($key)) {
            return is_scalar($key) ? (string) $key : '';
        }
        list($year, $part) = explode('-', $key, 2);
        return $year . ' ' . trim(preg_replace('/\s*\(.*\)$/', '', self::parts()[$part]));
    }

    /** Calendar position: year * 4 + part (pre 0, mid1 1, mid2 2, post 3); -1 for a bad key. Pure. Never compare keys as strings. */
    public static function ordinal($key) {
        if (!self::is_valid($key)) {
            return -1;
        }
        list($year, $part) = explode('-', $key, 2);
        return (int) $year * 4 + (int) array_search($part, array_keys(self::parts()), true);
    }

    /**
     * The season's calendar quarter, array(first day, last day): pre 1 Jan to 31 Mar,
     * mid1 1 Apr to 30 Jun, mid2 1 Jul to 30 Sep, post 1 Oct to 31 Dec. null for a bad key. Pure.
     */
    public static function quarter($key) {
        if (!self::is_valid($key)) {
            return null;
        }
        list($year, $part) = explode('-', $key, 2);
        $i = (int) array_search($part, array_keys(self::parts()), true);
        return array(
            sprintf('%04d-%02d-01', (int) $year, $i * 3 + 1),
            date('Y-m-t', strtotime(sprintf('%04d-%02d-01', (int) $year, $i * 3 + 3))),
        );
    }

    /** The span of a season with no dates: its quarter, array('start', 'end', 'dated' => false). null for a bad key. Pure. */
    public static function quarter_span($key) {
        $q = self::quarter($key);
        return $q ? array('start' => $q[0], 'end' => $q[1], 'dated' => false) : null;
    }

    /**
     * What a season covers: array('start' => first game night, 'end' => last game night,
     * 'dated' => true) from the season's settings; with no dates set (either one missing or
     * not Y-m-d, or the end before the start) its quarter (quarter_span()). null for a bad key.
     * A season has finished when its span's end is before today, dated or not.
     */
    public static function span($key) {
        if (!self::is_valid($key)) {
            return null;
        }
        if (class_exists('RSL_Courts')) {
            $s = RSL_Courts::settings($key);
            if (self::is_ymd($s['start']) && self::is_ymd($s['end']) && $s['end'] >= $s['start']) {
                return array('start' => $s['start'], 'end' => $s['end'], 'dated' => true);
            }
        }
        return self::quarter_span($key);
    }

    private static function is_ymd($d) {
        return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d);
    }

    /** "24 Jan" when $ymd falls in $year, else "13 Dec 2026". Pure. */
    public static function day_words($ymd, $year) {
        $t = strtotime((string) $ymd);
        if (!$t) {
            return (string) $ymd;
        }
        return date((int) substr((string) $ymd, 0, 4) === (int) $year ? 'j M' : 'j M Y', $t);
    }

    /** "24 Jan to 21 Mar"; a one-night span is just "24 Jan". Pure. */
    public static function dates_words($start, $end, $year) {
        return $start === $end ? self::day_words($start, $year) : self::day_words($start, $year) . ' to ' . self::day_words($end, $year);
    }

    /**
     * The label wherever a season is offered: "2027 Pre-season (24 Jan to 21 Mar)", or with no
     * dates "2027 Pre-season (dates to come; covers 1 Jan to 31 Mar)". $span as span() gives it
     * (anything else counts as no dates: its quarter). Pure.
     */
    public static function label_with_dates($key, $span) {
        if (!self::is_valid($key)) {
            return self::short_label($key);
        }
        if (!is_array($span) || !isset($span['start'], $span['end'])) {
            $span = self::quarter_span($key);
        }
        $words = self::dates_words($span['start'], $span['end'], (int) substr($key, 0, 4));
        return self::short_label($key) . (empty($span['dated']) ? ' (dates to come; covers ' . $words . ')' : ' (' . $words . ')');
    }

    /**
     * Pure core of open_for_signup(). $keys: candidate season keys, any order (bad keys
     * ignored); $spans: key => span() for $signup and the keys (a key missing from it, or not
     * an array, counts as having no dates and gets quarter_span()); $today: Y-m-d.
     * Returns key => label_with_dates(), in calendar order (ordinal()), of:
     *  - $signup, while its span's end is on or after $today, dated or not (with no dates
     *    that's its quarter's last day: the cart check and the payment call a season finished
     *    by the same span, so nothing is offered that can't be paid);
     *  - every other key later than $signup that has dates and whose last night is on or
     *    after $today.
     * An invalid $signup gives array(). No duplicates.
     */
    public static function open_from($signup, $keys, $spans, $today) {
        if (!self::is_valid($signup)) {
            return array();
        }
        $spans = is_array($spans) ? $spans : array();
        $span_of = function ($key) use ($spans) {
            return isset($spans[$key]) && is_array($spans[$key]) && isset($spans[$key]['start'], $spans[$key]['end'])
                ? $spans[$key] : self::quarter_span($key);
        };
        $out = array();
        $s = $span_of($signup);
        if ($s && $s['end'] >= $today) {
            $out[$signup] = self::label_with_dates($signup, $s);
        }
        $base = self::ordinal($signup);
        foreach ((array) $keys as $key) {
            if (!is_string($key) || !self::is_valid($key) || $key === $signup || isset($out[$key]) || self::ordinal($key) <= $base) {
                continue;
            }
            $s = $span_of($key);
            if (!$s || empty($s['dated']) || $s['end'] < $today) {
                continue;
            }
            $out[$key] = self::label_with_dates($key, $s);
        }
        uksort($out, function ($a, $b) {
            return self::ordinal($a) - self::ordinal($b);
        });
        return $out;
    }

    /**
     * The seasons open for sign-up now, key => label with dates, in calendar order: the ONE
     * list wherever the league asks which season something is for (0.15.0: the season rego;
     * later: the registration routes and the captain application).
     */
    public static function open_for_signup() {
        $signup = self::signup();
        // Tests add their far-future keys here (choices() is this year and the next two).
        $keys = (array) apply_filters('rsl_open_for_signup_candidates', array_keys(self::choices()));
        $spans = array();
        foreach (array_merge(array($signup), $keys) as $key) {
            if (is_string($key) && self::is_valid($key)) {
                $spans[$key] = self::span($key);
            }
        }
        return self::open_from($signup, $keys, $spans, current_time('Y-m-d'));
    }

    /**
     * The seasons a player may pick to join the free agent list or apply for a team (0.17.0):
     * open_for_signup(), or, when nothing is open (the sign-up season has finished and no later
     * season has dates yet), the sign-up season alone, as before 0.17.0, so the league page
     * never dead-ends. key => label with dates, calendar order.
     */
    public static function signup_choices() {
        $open = self::open_for_signup();
        if ($open) {
            return $open;
        }
        $signup = self::signup();
        return array($signup => self::label_with_dates($signup, self::span($signup)));
    }

    /**
     * The season a picker over $choices (key => label) starts on: the sign-up season when it's
     * offered, else the first offered; '' with none. RSL_Portal::season_field() preselects it,
     * pick_open('') returns it, and the words beside a picker name it (0.17.0).
     */
    public static function default_pick($choices) {
        $choices = is_array($choices) ? $choices : array();
        if (!$choices) {
            return '';
        }
        $signup = self::signup();
        if (isset($choices[$signup])) {
            return $signup;
        }
        reset($choices);
        return (string) key($choices);
    }

    /**
     * The season a player picked on a form, checked against signup_choices(): the key, or
     * WP_Error 'rsl_season_closed' with a sentence for them. Nothing picked ('' or not a
     * string): default_pick(). Every player-facing route goes through this; admin screens may
     * use choices().
     */
    public static function pick_open($raw) {
        $choices = self::signup_choices();
        $raw = is_string($raw) ? trim($raw) : '';
        if ($raw === '') {
            return self::default_pick($choices);
        }
        if (isset($choices[$raw])) {
            return $raw;
        }
        return new WP_Error('rsl_season_closed', self::is_valid($raw)
            ? self::short_label($raw) . ' isn\'t open for sign-up — pick one of the seasons listed.'
            : 'Pick one of the seasons listed.');
    }

    /**
     * The season whose clearance matters for this player now: the one they play in next.
     * $current, $signup: current() / signup(); $on_current_team: they're on an active team in
     * $current. In order: $current while they're on a team in it and it hasn't finished; else
     * $signup while it hasn't finished; else the first season open for sign-up (both have
     * finished: the gap between seasons with the options still pinned to the old one); else
     * $signup. Never a finished season while a later one can be bought, so a player who paid
     * next season ahead is shown as cleared for it.
     */
    public static function playing_season($current, $signup, $on_current_team) {
        $today = current_time('Y-m-d');
        if ($on_current_team && self::is_valid($current)) {
            $s = self::span($current);
            if ($s && $s['end'] >= $today) {
                return $current;
            }
        }
        if (self::is_valid($signup)) {
            $s = self::span($signup);
            if ($s && $s['end'] >= $today) {
                return $signup;
            }
        }
        $open = self::open_for_signup();
        if ($open) {
            reset($open);
            return (string) key($open);
        }
        return $signup;
    }
}
