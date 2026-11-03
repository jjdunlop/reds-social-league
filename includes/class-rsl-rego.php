<?php
/**
 * Who's cleared to play, worked out live:
 *  - a FULL or LIFE club member on that date (from the MURVC Club Manager)
 *    is covered — no social rego needed; or
 *  - a social rego covering that date: a yearly rego (12 months from
 *    payment) or a season rego (one season, chosen by the player: from its
 *    first game night, or the payment date if later, to its last), bought
 *    through a rego product (or added by an admin).
 * Being on a team doesn't need either; being cleared is what's needed
 * before someone's first game. A membership that lapses stops covering
 * them the day it ends, with no admin work. A refunded or cancelled rego
 * order ends that rego (an admin can also remove one); a cancelled order
 * set back to paid gives it back.
 *
 * The chosen season rides on the add-to-cart (SEASON_ARG), the cart line
 * (CART_SEASON) and the order line (SEASON_META). Clearance for playing in
 * a season goes through clearance_for(), so a season rego paid ahead counts.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RSL_Rego {

    const PRODUCT_OPTION = 'rsl_rego_product_id';          // the yearly rego
    const SEASON_PRODUCT_OPTION = 'rsl_rego_season_product_id';
    const PRICE_LABEL = '$40';         // yearly, shown when the product's price can't be read
    const SEASON_PRICE_LABEL = '$15';  // season, likewise
    const SEASON_ARG = 'rsl_season';           // the add-to-cart query / form field
    const CART_SEASON = 'rsl_rego_season';     // the cart line's data key
    const SEASON_META = '_rsl_rego_season';    // the order line's meta (hidden; shown through show_season_on_order())
    const RENEW_DAYS = 30;                     // the league page's "runs out soon" nudge

    /** Club Manager tiers that cover the social rego. */
    const COVERING_TIERS = array('life-member', 'full-member');

    public function __construct() {
        // One argument only: WooCommerce passes the order object second, which must never
        // reach on_order_paid()'s $from_scan.
        add_action('woocommerce_order_status_processing', array(__CLASS__, 'on_order_paid'));
        add_action('woocommerce_order_status_completed', array(__CLASS__, 'on_order_paid'));
        add_action('woocommerce_order_refunded', array(__CLASS__, 'on_order_refunded'), 10, 2);
        add_action('woocommerce_order_status_refunded', array(__CLASS__, 'on_order_reversed'));
        add_action('woocommerce_order_status_cancelled', array(__CLASS__, 'on_order_reversed'));
        // One rego per checkout: the pay link adds it again on every visit.
        add_filter('woocommerce_is_sold_individually', array(__CLASS__, 'sold_individually'), 10, 2);
        add_filter('woocommerce_add_to_cart_validation', array(__CLASS__, 'check_add_to_cart'), 10, 6);
        add_filter('woocommerce_add_cart_item_data', array(__CLASS__, 'cart_item_season'), 10, 3);
        add_action('woocommerce_add_to_cart', array(__CLASS__, 'keep_one_rego'), 10, 4);
        add_action('woocommerce_cart_loaded_from_session', array(__CLASS__, 'one_rego_after_load'), 5);
        add_filter('woocommerce_order_again_cart_item_data', array(__CLASS__, 'order_again_season'), 10, 3);
        add_filter('woocommerce_get_item_data', array(__CLASS__, 'show_season_in_cart'), 10, 2);
        add_action('woocommerce_check_cart_items', array(__CLASS__, 'check_cart'));
        add_action('woocommerce_checkout_create_order_line_item', array(__CLASS__, 'tag_order_item'), 10, 3);
        add_filter('woocommerce_order_item_get_formatted_meta_data', array(__CLASS__, 'show_season_on_order'), 10, 2);
    }

    // ------------------------------------------------------------------
    // Clearance
    // ------------------------------------------------------------------

    /**
     * Can memberships be checked? Needs the Club Manager's membership API.
     * The filter can only switch it off (tests simulate it missing).
     */
    public static function membership_source_available() {
        return class_exists('TeamOversight_Memberships') && method_exists('TeamOversight_Memberships', 'covered_until')
            && (bool) apply_filters('rsl_membership_source_available', true);
    }

    /**
     * Last day a full/life membership covers them, from $date, or null.
     * Uses the Club Manager when it's active; without it, nobody can be
     * shown as covered by membership (clearance() then says "unknown").
     */
    public static function member_covered_until($user_id, $date = null) {
        $date = $date ?: current_time('Y-m-d');
        if (self::membership_source_available()) {
            return TeamOversight_Memberships::covered_until($user_id, self::COVERING_TIERS, $date);
        }
        return null;
    }

    /**
     * The unbroken run of regos covering $date: array('from' => the start of the row that
     * began it, 'until' => its last day, 'season' => the season key of the row that last
     * moved 'until' ('' for a yearly rego)), or null. Rows' own dates only. While the schema
     * upgrade is still to finish (RSL_Database::schema_current()) the season column may not
     * exist yet, so it isn't read: every row counts by its own dates, with season ''.
     */
    public static function rego_run($user_id, $date = null) {
        global $wpdb;
        $date = $date ?: current_time('Y-m-d');
        $cols = RSL_Database::schema_current() ? 'start_date, end_date, season' : 'start_date, end_date';
        $grants = $wpdb->get_results($wpdb->prepare("
            SELECT $cols FROM " . RSL_Database::table('regos') . "
            WHERE user_id = %d AND end_date >= %s ORDER BY start_date, end_date
        ", $user_id, $date));
        $run = null;
        foreach ($grants as $g) {
            $g_season = isset($g->season) ? (string) $g->season : '';
            if ($run === null) {
                if ($g->start_date <= $date) {
                    $run = array('from' => $g->start_date, 'until' => $g->end_date, 'season' => $g_season);
                }
            } elseif ($g->start_date <= date('Y-m-d', strtotime($run['until'] . ' +1 day')) && $g->end_date >= $run['until']) {
                $run['until'] = $g->end_date;
                $run['season'] = $g_season;
            }
        }
        return $run;
    }

    /** Last day of the unbroken run of regos covering $date, or null. */
    public static function rego_valid_until($user_id, $date = null) {
        $run = self::rego_run($user_id, $date);
        return $run ? $run['until'] : null;
    }

    /**
     * array('cleared' => bool, 'by' => 'member'|'rego'|null, 'until' => date|null,
     * 'unknown' => bool, 'from' => date|null, 'season' => key|''). Membership is
     * checked first: a covered member needs no rego. 'unknown': no rego, and
     * memberships can't be checked right now (Club Manager missing) — they may
     * be a member, so don't ask them to pay; 'cleared' stays false until it can
     * be confirmed. 'from' and 'season' (by rego only): where the run of regos
     * began, and the season of the rego that ends it ('' for a yearly one).
     */
    public static function clearance($user_id, $date = null) {
        $member = self::member_covered_until($user_id, $date);
        if ($member) {
            return array('cleared' => true, 'by' => 'member', 'until' => $member, 'unknown' => false, 'from' => null, 'season' => '');
        }
        $run = self::rego_run($user_id, $date);
        if ($run) {
            return array('cleared' => true, 'by' => 'rego', 'until' => $run['until'], 'unknown' => false, 'from' => $run['from'], 'season' => $run['season']);
        }
        return array('cleared' => false, 'by' => null, 'until' => null, 'unknown' => !self::membership_source_available(), 'from' => null, 'season' => '');
    }

    /**
     * The date clearance for a season is judged on: its first night while that's still to
     * come, else today. A season with no dates is judged on today.
     */
    public static function check_date($season) {
        $today = current_time('Y-m-d');
        $s = RSL_Seasons::span($season);
        return $s && $s['dated'] && $s['start'] > $today ? $s['start'] : $today;
    }

    /**
     * Cleared to play in this season (the league page, both rosters, the emails' rego lines,
     * the free agent season card): clearance() on check_date($season), else cleared by a paid
     * rego for that very season (season_row()): a season with no dates is judged on today
     * while its rego starts on its quarter's first day, and a season's dates can move after
     * the sale. 'until' is then the later of the row's end and the season's last night as it
     * is now; 'from' the earlier of the row's start and the season's first night.
     * Else, like season_covered(): covered for every day from the later of today and the
     * season's first day to its last (a season with no dates whose quarter is still ahead, and
     * cover that starts after today but by then): clearance() on that first day, 'from' never
     * earlier than when that cover starts. So the league page never asks someone to pay for a
     * season the shop would refuse them as already covered.
     */
    public static function clearance_for($user_id, $season) {
        $check = self::check_date($season);
        $c = self::clearance($user_id, $check);
        if ($c['cleared'] || !RSL_Seasons::is_valid($season)) {
            return $c;
        }
        $s = RSL_Seasons::span($season);
        $row = self::season_row($user_id, $season);
        if ($row) {
            return array('cleared' => true, 'by' => 'rego', 'until' => max($row->end_date, $s['end']),
                'unknown' => false, 'from' => min($row->start_date, $s['start']), 'season' => $season);
        }
        $first = max(current_time('Y-m-d'), $s['start']);
        if ($first > $check && $first <= $s['end'] && self::covered_through($user_id, $first, $s['end'])) {
            $later = self::clearance($user_id, $first);
            if ($later['cleared']) {
                $later['from'] = !empty($later['from']) ? $later['from'] : $first; // a membership: from that day at the latest
                return $later;
            }
        }
        return $c;
    }

    /**
     * Is every day from $from to $to covered, by membership or by regos, in any mix? Walks
     * from one cover's end to the next. A gap on a day with no game counts as not covered,
     * which errs towards letting someone pay, never towards refusing someone who needs to.
     */
    public static function covered_through($user_id, $from, $to) {
        $day = $from;
        for ($i = 0; $i < 60 && $day <= $to; $i++) { // a guard; each step moves past one cover
            $until = max((string) self::member_covered_until($user_id, $day), (string) self::rego_valid_until($user_id, $day));
            if ($until === '') {
                return false;
            }
            $day = date('Y-m-d', strtotime($until . ' +1 day'));
        }
        return $day > $to;
    }

    /**
     * Does this rego row still count? Its own dates while they run; a season row also while
     * its season runs (its span's end, as the dates are now), unless it was cut short
     * (end_date below granted_end: refunded, cancelled or removed) or removed. So moving a
     * season's nights never makes someone who paid for it pay again or show as not cleared.
     */
    public static function row_counts($row) {
        if (!$row || self::is_removed($row)) {
            return false;
        }
        $today = current_time('Y-m-d');
        if ($row->end_date >= $today) {
            return true;
        }
        $season = isset($row->season) ? (string) $row->season : '';
        if ($season === '' || !RSL_Seasons::is_valid($season)) {
            return false; // a yearly rego: its dates only
        }
        $granted = !empty($row->granted_end) ? $row->granted_end : $row->end_date;
        if ($row->end_date < $granted) {
            return false; // cut short
        }
        $s = RSL_Seasons::span($season);
        return $s && $s['end'] >= $today;
    }

    /**
     * The player's rego row for this season that still counts (row_counts()), or null. Null
     * while the schema upgrade is still to finish (no season column yet): rego_run() then
     * still counts the row by its own dates.
     */
    public static function season_row($user_id, $season) {
        global $wpdb;
        if (!$user_id || !RSL_Seasons::is_valid($season) || !RSL_Database::schema_current()) {
            return null;
        }
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . RSL_Database::table('regos') . " WHERE user_id = %d AND season = %s ORDER BY end_date DESC, id DESC", $user_id, $season));
        foreach ($rows as $row) {
            if (self::row_counts($row)) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Already covered for every game night of this season? A counting rego for that season,
     * or cover (membership and regos, any mix) for every day from the later of today and its
     * first night to its last. A finished season without a counting row never is.
     */
    public static function season_covered($user_id, $season) {
        $s = RSL_Seasons::span($season);
        if (!$user_id || !$s) {
            return false;
        }
        if (self::season_row($user_id, $season)) {
            return true;
        }
        $from = max(current_time('Y-m-d'), $s['start']);
        return $from <= $s['end'] && self::covered_through($user_id, $from, $s['end']);
    }

    /**
     * Paid ahead past the cover the league page shows for $season ($clear = clearance_for($user_id,
     * $season)): that cover runs to the season's last night and the season after it
     * (RSL_Seasons::next()) is already covered (season_covered(): its own season rego, or cover
     * for all its nights). The runs-out-soon nudge stays quiet then (0.17.0). Cover that ends
     * part-way through $season never counts: they still need the rest of it.
     */
    public static function paid_ahead($user_id, $clear, $season) {
        if (empty($clear['cleared']) || !RSL_Seasons::is_valid($season)) {
            return false;
        }
        $s = RSL_Seasons::span($season);
        if (!$s || (string) $clear['until'] < $s['end']) {
            return false;
        }
        return self::season_covered($user_id, RSL_Seasons::next($season));
    }

    /** The seasons open for sign-up that this player isn't already covered for: what the league page offers. */
    public static function season_choices($user_id) {
        $out = array();
        foreach (RSL_Seasons::open_for_signup() as $key => $label) {
            if (!$user_id || !self::season_covered($user_id, $key)) {
                $out[$key] = $label;
            }
        }
        return $out;
    }

    /** Admin wording: says WHY they're cleared. */
    public static function describe($clearance) {
        if (!$clearance['cleared']) {
            return !empty($clearance['unknown']) ? 'Can\'t check membership right now (no rego)' : 'Needs rego';
        }
        $until = $clearance['until'] >= '2099-01-01' ? 'no expiry' : 'until ' . date('j M Y', strtotime($clearance['until']));
        if ($clearance['by'] === 'member') {
            return 'Full/life member (' . $until . ')';
        }
        $season = isset($clearance['season']) ? (string) $clearance['season'] : '';
        return 'Rego paid (' . (RSL_Seasons::is_valid($season) ? RSL_Seasons::short_label($season) . ', ' : '') . $until . ')';
    }

    /**
     * The league page's words for what covers them, no full stop: "Club membership until
     * 30 Jun 2027" (", no expiry" from 2099), "Yearly rego until 2 Nov 2027", "Season rego,
     * 2027 Pre-season, until 21 Mar". '' when not cleared.
     */
    public static function cover_words($clear) {
        if (empty($clear['cleared'])) {
            return '';
        }
        if ($clear['by'] === 'member') {
            return $clear['until'] >= '2099-01-01' ? 'Club membership, no expiry' : 'Club membership until ' . date('j M Y', strtotime($clear['until']));
        }
        $season = isset($clear['season']) ? (string) $clear['season'] : '';
        if (RSL_Seasons::is_valid($season)) {
            return 'Season rego, ' . RSL_Seasons::short_label($season) . ', until ' . RSL_Seasons::day_words($clear['until'], (int) substr($season, 0, 4));
        }
        return 'Yearly rego until ' . date('j M Y', strtotime($clear['until']));
    }

    // ------------------------------------------------------------------
    // Grants
    // ------------------------------------------------------------------

    /**
     * Add a rego. $args: start (Y-m-d), source, order_id, order_item_id, note, season.
     *  - No season: 12 months. A purchase made while a rego is still running starts the day
     *    after it ends (renewing early never wastes time).
     *  - A season (any source): from the later of its first game night and the start date, to
     *    its last game night (a season with no dates: within its quarter). Never chained (the
     *    player picked the season). The note defaults to season_note().
     * Returns the new grant id, or 0 when that order item was already granted, the season
     * isn't valid, or it had finished before the start date (callers check first and say why).
     */
    public static function grant($user_id, $args = array()) {
        global $wpdb;
        $start = isset($args['start']) && is_string($args['start']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $args['start']) ? $args['start'] : current_time('Y-m-d');
        $source = isset($args['source']) ? $args['source'] : 'purchase';
        $order_item_id = isset($args['order_item_id']) ? intval($args['order_item_id']) : 0;
        $season = isset($args['season']) && is_string($args['season']) ? $args['season'] : '';
        $note = isset($args['note']) ? (string) $args['note'] : '';

        if ($order_item_id && $wpdb->get_var($wpdb->prepare("SELECT id FROM " . RSL_Database::table('regos') . " WHERE order_item_id = %d", $order_item_id))) {
            return 0;
        }
        if ($season !== '') {
            if (!RSL_Seasons::is_valid($season)) {
                return 0;
            }
            $s = RSL_Seasons::span($season);
            $start = max($start, $s['start']);
            $end = $s['end'];
            if ($start > $end) {
                return 0;
            }
            if (trim($note) === '') {
                $note = self::season_note($season, $s);
            }
        } else {
            if ($source === 'purchase') {
                $running = self::rego_valid_until($user_id, $start);
                if ($running) {
                    $start = date('Y-m-d', strtotime($running . ' +1 day'));
                }
            }
            $end = date('Y-m-d', strtotime($start . ' +1 year -1 day'));
        }
        $ok = $wpdb->insert(RSL_Database::table('regos'), array(
            'user_id' => $user_id,
            'start_date' => $start,
            'end_date' => $end,
            'source' => $source,
            'order_id' => isset($args['order_id']) ? intval($args['order_id']) : null,
            'order_item_id' => $order_item_id ?: null,
            'note' => self::note_fit($note),
            'season' => $season,
            'granted_end' => $end,
            'created_by' => get_current_user_id() ?: null,
            'created_at' => current_time('mysql'),
        ));
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /** A season rego row's note: "Season rego: 2027 Pre-season (24 Jan to 21 Mar)". Never an em dash (restore_cancelled() reads the note's last "— " part). */
    public static function season_note($season, $span) {
        return 'Season rego: ' . RSL_Seasons::label_with_dates($season, $span);
    }

    const NOTE_BYTES = 255; // the note column: cut by bytes, so it fits whatever the table's character set

    /** $text cut to at most $bytes, never part of a UTF-8 character (team balance notes use it too). */
    public static function note_fit_to($text, $bytes) {
        $text = (string) $text;
        if (strlen($text) <= $bytes) {
            return $text;
        }
        if (function_exists('mb_strcut')) {
            return mb_strcut($text, 0, $bytes, 'UTF-8');
        }
        // No mbstring: cut by bytes, then drop a trailing lead byte and its continuation bytes
        // (at worst one whole character too).
        return (string) preg_replace('/[\xC0-\xF7][\x80-\xBF]*$/', '', substr($text, 0, $bytes));
    }

    /** A rego note that fits the column (0.17.0: on a character boundary, never a byte cut). */
    private static function note_fit($note) {
        return self::note_fit_to($note, self::NOTE_BYTES);
    }

    /**
     * $note with $add after it (" — " between when $note has text), cut to fit: $add, the newest
     * reason, is always kept whole (restore_cancelled() reads the note's last "— " part); the older
     * text loses its end, marked "…" (never an em dash).
     */
    private static function note_add($note, $add) {
        $note = trim((string) $note);
        $add = trim((string) $add);
        $tail = ($note !== '' ? ' — ' : '') . $add;
        if (strlen($note . $tail) <= self::NOTE_BYTES) {
            return $note . $tail;
        }
        $room = self::NOTE_BYTES - strlen($tail) - strlen('…');
        if ($room < 1) {
            return self::note_fit($add);
        }
        return rtrim(self::note_fit_to($note, $room)) . '…' . $tail;
    }

    public static function get($grant_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . RSL_Database::table('regos') . " WHERE id = %d", $grant_id));
    }

    /**
     * End a rego early (refunded, cancelled, or removed by an admin). It
     * stops covering from today — it still counts for days already gone —
     * and one that hasn't started yet never covers anything. The row stays
     * (with $why in its note), so a re-run of the past-order scan can't
     * grant that order item again. A season rego not cut short yet whose
     * own dates are over has its end moved back one day, below granted_end,
     * whether or not it still counts for its season now: cut short, it never
     * counts for its season again (row_counts()), even if the season's last
     * night moves later afterwards. True when done (or already over), or
     * WP_Error.
     */
    public static function revoke($grant_id, $why = 'Removed') {
        global $wpdb;
        $g = self::get($grant_id);
        if (!$g) {
            return new WP_Error('rsl_rego', 'That rego no longer exists.');
        }
        $today = current_time('Y-m-d');
        $end = min($g->end_date, date('Y-m-d', strtotime($today . ' -1 day')));
        if ($end < $g->start_date) {
            $end = date('Y-m-d', strtotime($g->start_date . ' -1 day')); // covers nothing
        }
        $season = isset($g->season) ? (string) $g->season : '';
        $uncut = !self::is_removed($g) && $g->end_date >= (!empty($g->granted_end) ? $g->granted_end : $g->end_date);
        if ($end >= $g->end_date && $season !== '' && $uncut) {
            $end = date('Y-m-d', strtotime($g->end_date . ' -1 day')); // a day already gone: below granted_end
        }
        if ($end >= $g->end_date) {
            return true; // already over
        }
        $note = self::note_add($g->note, $why . ' ' . date('j M Y', strtotime($today)));
        $wpdb->update(RSL_Database::table('regos'), array('end_date' => $end, 'note' => $note), array('id' => $g->id));
        return true;
    }

    /** Ended before it began (revoke() of a rego that hadn't started). */
    public static function is_removed($grant) {
        return $grant->end_date < $grant->start_date;
    }

    /** The yearly rego product (0 = not set). */
    public static function product_id() {
        return intval(get_option(self::PRODUCT_OPTION));
    }

    /** The season rego product (0 = not set). */
    public static function season_product_id() {
        return intval(get_option(self::SEASON_PRODUCT_OPTION));
    }

    /** 'season' -> the season product, anything else -> the yearly one. */
    public static function product_for($kind) {
        return $kind === 'season' ? self::season_product_id() : self::product_id();
    }

    /**
     * 'year' when either id is the yearly product, else 'season' when either is the season
     * product, else ''. Yearly first, so a yearly line can never carry a season.
     */
    public static function kind_of($product_id, $variation_id = 0) {
        $ids = array_values(array_filter(array(intval($product_id), intval($variation_id))));
        $year = self::product_id();
        $season = self::season_product_id();
        if ($year && in_array($year, $ids, true)) {
            return 'year';
        }
        if ($season && in_array($season, $ids, true)) {
            return 'season';
        }
        return '';
    }

    /** A rego's price as players see it ("$40", "$15"), from its product; the fallback label if it can't be read. */
    public static function price_label($kind = 'year') {
        $id = self::product_for($kind);
        $product = $id && function_exists('wc_get_product') ? wc_get_product($id) : null;
        if ($product && $product->get_price() !== '' && function_exists('wc_get_price_including_tax')) {
            return RSL_Officials::money(wc_get_price_including_tax($product)); // league prices include GST
        }
        return $kind === 'season' ? self::SEASON_PRICE_LABEL : self::PRICE_LABEL;
    }

    /**
     * What a rego costs, inside brackets wherever it's written: "$15 a season or $40 a year",
     * "$15 a season", or (yearly only, or nothing set) "$40, 12 months".
     */
    public static function cost_words() {
        $season = self::season_product_id();
        if ($season && self::product_id()) {
            return self::price_label('season') . ' a season or ' . self::price_label() . ' a year';
        }
        if ($season) {
            return self::price_label('season') . ' a season';
        }
        return self::price_label() . ', 12 months';
    }

    /** The league page's not-cleared sentence: "It's $15 for a season or $40 for a year." and the like. */
    public static function cost_sentence() {
        $season = self::season_product_id();
        if ($season && self::product_id()) {
            return 'It\'s ' . self::price_label('season') . ' for a season or ' . self::price_label() . ' for a year.';
        }
        if ($season) {
            return 'It\'s ' . self::price_label('season') . ' for a season.';
        }
        return 'It\'s ' . self::price_label() . ' and lasts 12 months.';
    }

    /** Either rego product can only be bought one at a time. */
    public static function sold_individually($sold_individually, $product) {
        $ids = array_values(array_filter(array(self::product_id(), self::season_product_id())));
        if ($sold_individually || !$ids || !is_object($product) || !is_callable(array($product, 'get_id'))) {
            return $sold_individually;
        }
        return (bool) array_intersect($ids, array(intval($product->get_id()), intval($product->get_parent_id())));
    }

    // ------------------------------------------------------------------
    // The chosen season through WooCommerce: add-to-cart, cart, checkout, order
    // ------------------------------------------------------------------

    /** A notice, only where WooCommerce has a session (wc_add_notice() fatals without one). */
    private static function notice($text, $type = 'error') {
        if (function_exists('WC') && WC()->session && function_exists('wc_add_notice')) {
            wc_add_notice($text, $type);
        }
    }

    /** The season asked for on this request (rsl_season), else the sign-up season. */
    private static function requested_season() {
        $key = isset($_REQUEST[self::SEASON_ARG]) && is_string($_REQUEST[self::SEASON_ARG]) ? sanitize_key(wp_unslash($_REQUEST[self::SEASON_ARG])) : '';
        return RSL_Seasons::is_valid($key) ? $key : RSL_Seasons::signup();
    }

    /** The kind of rego a cart line is ('year', 'season' or ''). */
    private static function cart_kind($item) {
        return is_array($item) && isset($item['product_id']) ? self::kind_of($item['product_id'], isset($item['variation_id']) ? $item['variation_id'] : 0) : '';
    }

    /**
     * Why this season rego can't go in the cart, or ''. Not open for sign-up; or this player is
     * already covered for every game night of it (a counting row for that season included).
     */
    public static function season_add_error($user_id, $season) {
        $season = is_string($season) ? $season : '';
        $open = RSL_Seasons::open_for_signup();
        if (!isset($open[$season])) {
            return (RSL_Seasons::is_valid($season) ? RSL_Seasons::short_label($season) : 'That season') . ' isn\'t open for sign-up. Choose a season on the league page.';
        }
        if ($user_id && self::season_covered($user_id, $season)) {
            return 'You\'re already covered for every game night of ' . $open[$season] . ', so there\'s nothing to pay. To pay for another season, choose it on the league page.';
        }
        return '';
    }

    /**
     * woocommerce_add_to_cart_validation: a season rego for a season that isn't open, or that
     * the player is already covered for, is refused with a sentence. The same rego already in
     * the cart (a refreshed or re-clicked pay link) is refused quietly. Order again passes its
     * line's season as $cart_item_data, which wins over the request.
     */
    public static function check_add_to_cart($passed, $product_id, $quantity = 1, $variation_id = 0, $variations = array(), $cart_item_data = array()) {
        $kind = self::kind_of($product_id, $variation_id);
        if (!$passed || $kind === '') {
            return $passed;
        }
        $season = '';
        if ($kind === 'season') {
            $season = is_array($cart_item_data) && isset($cart_item_data[self::CART_SEASON]) && RSL_Seasons::is_valid($cart_item_data[self::CART_SEASON])
                ? $cart_item_data[self::CART_SEASON] : self::requested_season();
            $error = self::season_add_error(get_current_user_id(), $season);
            if ($error !== '') {
                self::notice(esc_html($error) . ' <a href="' . esc_url(RSL_Portal::url()) . '">Your league page</a>');
                return false;
            }
        }
        if (function_exists('WC') && WC()->cart) {
            foreach (WC()->cart->get_cart() as $item) {
                if (self::cart_kind($item) === $kind && ($kind === 'year' || (isset($item[self::CART_SEASON]) && $item[self::CART_SEASON] === $season))) {
                    return false;
                }
            }
        }
        return $passed;
    }

    /** woocommerce_add_cart_item_data: a season rego line carries its season (a valid one given wins; else the request's). */
    public static function cart_item_season($cart_item_data, $product_id, $variation_id = 0) {
        if (self::kind_of($product_id, $variation_id) !== 'season') {
            return $cart_item_data;
        }
        $cart_item_data = is_array($cart_item_data) ? $cart_item_data : array();
        if (!isset($cart_item_data[self::CART_SEASON]) || !RSL_Seasons::is_valid($cart_item_data[self::CART_SEASON])) {
            $cart_item_data[self::CART_SEASON] = self::requested_season();
        }
        return $cart_item_data;
    }

    /** Order again: a season line keeps its season while it's open for sign-up, else the sign-up season (named on the cart line). */
    public static function order_again_season($cart_item_data, $item = null, $order = null) {
        if (self::item_kind($item) !== 'season') {
            return $cart_item_data;
        }
        $cart_item_data = is_array($cart_item_data) ? $cart_item_data : array();
        $key = (string) $item->get_meta(self::SEASON_META);
        $open = RSL_Seasons::open_for_signup();
        $cart_item_data[self::CART_SEASON] = $key !== '' && isset($open[$key]) ? $key : RSL_Seasons::signup();
        return $cart_item_data;
    }

    /** One rego line in the cart, the last one chosen: another season, or the other kind, replaces it. */
    public static function keep_one_rego($cart_item_key, $product_id = 0, $quantity = 1, $variation_id = 0) {
        if (self::kind_of($product_id, $variation_id) === '' || !function_exists('WC') || !WC()->cart) {
            return;
        }
        foreach (WC()->cart->get_cart() as $key => $item) {
            if ($key !== $cart_item_key && self::cart_kind($item) !== '') {
                WC()->cart->remove_cart_item($key);
            }
        }
    }

    /**
     * A cart loaded from the session with two rego lines (a saved cart merged at login, which
     * fires no add-to-cart): keep the last, take out the rest, say so. Priority 5, before
     * WooCommerce saves the loaded cart back to the session.
     */
    public static function one_rego_after_load($cart) {
        if (!is_object($cart) || !method_exists($cart, 'get_cart_contents')) {
            return;
        }
        $keys = array();
        foreach ($cart->get_cart_contents() as $key => $item) {
            if (self::cart_kind($item) !== '') {
                $keys[] = $key;
            }
        }
        if (count($keys) < 2) {
            return;
        }
        array_pop($keys); // the last one chosen stays
        foreach ($keys as $key) {
            $cart->remove_cart_item($key);
        }
        self::notice('Only one social rego can be in the cart, so the earlier one was taken out.', 'notice');
    }

    /** Cart and checkout: "Season: 2027 Pre-season (24 Jan to 21 Mar)". */
    public static function show_season_in_cart($data, $cart_item) {
        $data = is_array($data) ? $data : array();
        if (is_array($cart_item) && !empty($cart_item[self::CART_SEASON]) && RSL_Seasons::is_valid($cart_item[self::CART_SEASON])) {
            $key = $cart_item[self::CART_SEASON];
            $data[] = array('key' => 'Season', 'value' => RSL_Seasons::label_with_dates($key, RSL_Seasons::span($key)));
        }
        return $data;
    }

    /**
     * woocommerce_check_cart_items (the cart page, and inside checkout validation, where an
     * error stops the order): one rego line at most; a season line's season must not have
     * finished, and the player must not have become covered for it meanwhile.
     */
    public static function check_cart() {
        if (!function_exists('WC') || !WC()->cart) {
            return;
        }
        $lines = array();
        foreach (WC()->cart->get_cart() as $item) {
            if (self::cart_kind($item) !== '') {
                $lines[] = $item;
            }
        }
        if (count($lines) > 1) {
            self::notice('Only one social rego can be bought at a time. Remove one from your cart to carry on.');
            return;
        }
        $today = current_time('Y-m-d');
        $user_id = get_current_user_id();
        foreach ($lines as $item) {
            if (empty($item[self::CART_SEASON]) || !RSL_Seasons::is_valid($item[self::CART_SEASON])) {
                continue;
            }
            $key = $item[self::CART_SEASON];
            $s = RSL_Seasons::span($key);
            if ($s && $s['end'] < $today) {
                self::notice('The season rego in your cart is for ' . esc_html(RSL_Seasons::short_label($key)) . ', which has finished. Remove it, then choose a season on the league page.');
            } elseif ($user_id && self::season_covered($user_id, $key)) {
                self::notice('You\'re already covered for every game night of ' . esc_html(RSL_Seasons::short_label($key)) . ', so the season rego in your cart isn\'t needed. Remove it to carry on.');
            }
        }
    }

    /** The order line keeps the season (hidden meta). A yearly line never gets one. */
    public static function tag_order_item($item, $cart_item_key = '', $values = array()) {
        if (!is_object($item) || !is_array($values) || !isset($values['product_id'])) {
            return;
        }
        if (self::kind_of($values['product_id'], isset($values['variation_id']) ? $values['variation_id'] : 0) === 'season'
            && !empty($values[self::CART_SEASON]) && RSL_Seasons::is_valid($values[self::CART_SEASON])) {
            $item->add_meta_data(self::SEASON_META, $values[self::CART_SEASON], true);
        }
    }

    /**
     * Orders, receipts and the admin order screen show "Season: 2027 Pre-season" once: the
     * admin screen's own entry for the hidden key is relabelled, else one is added. The short
     * label only (the dates can move after the sale; the rego row has the real ones).
     */
    public static function show_season_on_order($formatted, $item = null) {
        if (!is_object($item) || !is_callable(array($item, 'get_meta'))) {
            return $formatted;
        }
        $key = (string) $item->get_meta(self::SEASON_META);
        if (!RSL_Seasons::is_valid($key)) {
            return $formatted;
        }
        $formatted = is_array($formatted) ? $formatted : array();
        $label = esc_html(RSL_Seasons::short_label($key));
        $found = false;
        foreach ($formatted as $meta) {
            if (is_object($meta) && isset($meta->key) && $meta->key === self::SEASON_META) {
                $meta->display_key = 'Season';
                $meta->display_value = $label;
                $found = true;
            }
        }
        if (!$found) {
            $formatted['rsl_rego_season'] = (object) array('key' => self::SEASON_META, 'value' => $key, 'display_key' => 'Season', 'display_value' => $label);
        }
        return $formatted;
    }

    /** The kind of rego an order item buys ('year', 'season' or ''): simple product, or a variation of it. */
    private static function item_kind($item) {
        return is_object($item) && is_callable(array($item, 'get_product_id')) && is_callable(array($item, 'get_variation_id'))
            ? self::kind_of($item->get_product_id(), $item->get_variation_id()) : '';
    }

    /**
     * WooCommerce paid (processing/completed): grant a rego per rego item. $from_scan: called
     * by the past-order scan, which never guesses a season for a line that doesn't say one.
     */
    public static function on_order_paid($order_id, $from_scan = false) {
        if (!function_exists('wc_get_order')) {
            return;
        }
        $from_scan = $from_scan === true;
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        $items = array_filter($order->get_items(), function ($item) { return self::item_kind($item) !== ''; });
        if (!$items) {
            return;
        }
        if (!$order->get_customer_id()) {
            RSL_Payments::order_note($order, 'Social League: a guest checkout can\'t be tied to a player, so no rego was given — add it by hand on Social League → Registration.', '_rsl_rego_guest_noted');
            return;
        }
        $paid = $order->get_date_paid() ?: $order->get_date_created();
        $paid_ymd = $paid ? $paid->date('Y-m-d') : current_time('Y-m-d');
        foreach ($items as $item_id => $item) {
            if (self::item_kind($item) === 'season') {
                self::season_item_paid($order, intval($item_id), $item, intval($order->get_customer_id()), $paid_ymd, $from_scan);
                continue;
            }
            // One 12-month grant per order item (the dedupe key). Buying
            // more than one in an order is treated as one.
            $id = self::grant($order->get_customer_id(), array(
                'start' => $paid_ymd,
                'source' => 'purchase',
                'order_id' => $order->get_id(),
                'order_item_id' => $item_id,
            ));
            $g = $id ? self::get($id) : null;
            if ($g) {
                RSL_Payments::order_note($order, 'Social League: 12-month social rego given (' . date('j M Y', strtotime($g->start_date)) . ' – ' . date('j M Y', strtotime($g->end_date)) . ').'
                    . ($item->get_quantity() > 1 ? ' Quantity ' . intval($item->get_quantity()) . ' was bought but only one rego is given — refund the extra.' : ''));
            } else {
                self::restore_cancelled($order, intval($item_id), $item); // cancelled and now paid again
                self::note_if_ungranted($order, intval($item_id));
            }
        }
    }

    /**
     * grant() gave nothing and the line still has no rego row: the insert failed (e.g. a
     * payment landing while another request upgrades the schema). Say so on the order, once,
     * so the miss is seen. Nothing gives it by itself: an admin's Pick up rego purchases (the
     * past-order scan) on Social League → Registration does, as the note says.
     */
    private static function note_if_ungranted($order, $item_id) {
        global $wpdb;
        if ($item_id && !$wpdb->get_var($wpdb->prepare("SELECT id FROM " . RSL_Database::table('regos') . " WHERE order_item_id = %d", $item_id))) {
            RSL_Payments::order_note($order, 'Social League: the rego for this line couldn\'t be saved just now, so none was given. Pick up rego purchases on Social League → Registration to give it.', '_rsl_rego_unsaved_' . $item_id);
        }
    }

    /** One paid season rego line: grant it for its season (see on_order_paid()). */
    private static function season_item_paid($order, $item_id, $item, $customer, $paid_ymd, $from_scan) {
        global $wpdb;
        if ($wpdb->get_var($wpdb->prepare("SELECT id FROM " . RSL_Database::table('regos') . " WHERE order_item_id = %d", $item_id))) {
            // Granted already: only a cancelled order paid again changes anything. No "finished"
            // check, so dates shortened after the sale never note "no rego" on an order that has one.
            self::restore_cancelled($order, $item_id, $item);
            return;
        }
        $season = (string) $item->get_meta(self::SEASON_META);
        if (!RSL_Seasons::is_valid($season)) {
            if ($from_scan) {
                RSL_Payments::order_note($order, 'Social League: this season rego line doesn\'t say which season it\'s for, so no rego was given. Add one by hand on Social League → Registration, or refund it.', '_rsl_rego_noseason_' . $item_id);
                return;
            }
            $season = RSL_Seasons::signup(); // e.g. an order made by hand in the admin, paid now
        }
        $s = RSL_Seasons::span($season);
        if (max($s['start'], $paid_ymd) > $s['end']) {
            RSL_Payments::order_note($order, 'Social League: this season rego is for ' . RSL_Seasons::short_label($season) . ', which finished on ' . date('j M Y', strtotime($s['end']))
                . ' before the order was paid, so no rego was given. Refund it, or add a rego by hand on Social League → Registration.', '_rsl_rego_over_' . $item_id);
            return;
        }
        $covered = self::season_covered($customer, $season);
        $id = self::grant($customer, array(
            'start' => $paid_ymd,
            'source' => 'purchase',
            'season' => $season,
            'order_id' => $order->get_id(),
            'order_item_id' => $item_id,
        ));
        $g = $id ? self::get($id) : null;
        if (!$g) {
            self::restore_cancelled($order, $item_id, $item); // a row inserted in between
            self::note_if_ungranted($order, $item_id);
            return;
        }
        RSL_Payments::order_note($order, 'Social League: season rego given for ' . RSL_Seasons::short_label($season) . ' (' . date('j M Y', strtotime($g->start_date)) . ' – ' . date('j M Y', strtotime($g->end_date)) . ').'
            . (empty($s['dated']) ? ' That season has no game nights set yet, so it covers its quarter.' : '')
            . ($covered ? ' They were already covered for every game night of that season, so refund it if it was paid by mistake.' : '')
            . ($item->get_quantity() > 1 ? ' Quantity ' . intval($item->get_quantity()) . ' was bought but only one rego is given — refund the extra.' : ''));
    }

    /**
     * A rego order that was cancelled (ending its rego) and is paid again —
     * set back to Processing or Completed: the rego gets back the end it was
     * granted with (granted_end; 12 months on rows from before schema 11).
     * Only when the order's cancellation is the last thing that ended it
     * (not an admin removing it, not a refund) and WooCommerce lists no
     * refund that would end it.
     */
    private static function restore_cancelled($order, $item_id, $item) {
        global $wpdb;
        $g = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . RSL_Database::table('regos') . " WHERE order_item_id = %d", $item_id));
        if (!$g || !preg_match('/(^|— )Order cancelled [^—]*$/u', (string) $g->note)) {
            return; // never ended, or ended by something other than the cancellation (revoke() adds the reason to the note)
        }
        $refunded = 0.0;
        foreach ($order->get_refunds() as $refund) {
            foreach ($refund->get_items() as $line) {
                if (intval($line->get_meta('_refunded_item_id')) === $item_id) {
                    if (intval($line->get_quantity()) !== 0) {
                        return; // refunded by quantity: on_order_refunded()'s rule ends it
                    }
                    $refunded += -((float) $line->get_total() + (float) $line->get_total_tax());
                }
            }
        }
        $paid = (float) $item->get_total() + (float) $item->get_total_tax();
        if ($refunded >= $paid - 0.01 || ((float) $order->get_total() > 0 && (float) $order->get_total_refunded() >= (float) $order->get_total() - 0.01)) {
            return; // refunded in full: it stays ended
        }
        $end = !empty($g->granted_end) ? $g->granted_end : date('Y-m-d', strtotime($g->start_date . ' +1 year -1 day'));
        if ($g->end_date >= $end) {
            return;
        }
        $note = self::note_add($g->note, 'Order paid again ' . date('j M Y', strtotime(current_time('Y-m-d'))));
        $wpdb->update(RSL_Database::table('regos'), array('end_date' => $end, 'note' => $note), array('id' => $g->id));
        RSL_Payments::order_note($order, 'Social League: social rego given back (the order is paid again) — ' . date('j M Y', strtotime($g->start_date)) . ' – ' . date('j M Y', strtotime($end)) . '.');
    }

    /** A refund in WooCommerce: a rego line refunded in full (or by quantity) ends that rego. */
    public static function on_order_refunded($order_id, $refund_id) {
        if (!function_exists('wc_get_order')) {
            return;
        }
        $order = wc_get_order($order_id);
        $refund = wc_get_order($refund_id);
        if (!$order || !$refund) {
            return;
        }
        foreach ($refund->get_items() as $line) {
            $item = $order->get_item(intval($line->get_meta('_refunded_item_id')));
            if (!$item) {
                continue;
            }
            $refunded = -((float) $line->get_total() + (float) $line->get_total_tax());
            $paid = (float) $item->get_total() + (float) $item->get_total_tax();
            if (intval($line->get_quantity()) !== 0 || $refunded >= $paid - 0.01) {
                self::revoke_order_items($order, array($item->get_id()), 'Refunded');
            }
        }
    }

    /** An order cancelled or fully refunded: its regos end. */
    public static function on_order_reversed($order_id) {
        if (!function_exists('wc_get_order')) {
            return;
        }
        $order = wc_get_order($order_id);
        if ($order) {
            self::revoke_order_items($order, array_keys($order->get_items()), $order->has_status('cancelled') ? 'Order cancelled' : 'Refunded');
        }
    }

    /** End the regos granted for these order items (those that have one). */
    private static function revoke_order_items($order, $item_ids, $why) {
        global $wpdb;
        foreach (array_map('intval', $item_ids) as $item_id) {
            $g = $item_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM " . RSL_Database::table('regos') . " WHERE order_item_id = %d", $item_id)) : null;
            if (!$g) {
                continue;
            }
            $before = $g->end_date;
            self::revoke($g->id, $why);
            $g = self::get($g->id);
            if ($g && $g->end_date !== $before) {
                RSL_Payments::order_note($order, 'Social League: social rego ended (' . strtolower($why) . ').');
            }
        }
    }

    /**
     * Pick up rego purchases from the past $months (dedupe means re-running is safe). A season
     * line that doesn't say its season gets an order note, never a guessed season.
     */
    public static function scan_orders($months = 13) {
        if (!function_exists('wc_get_orders') || (!self::product_id() && !self::season_product_id())) {
            return 0;
        }
        global $wpdb;
        $before = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . RSL_Database::table('regos'));
        $page = 1;
        do {
            $orders = wc_get_orders(array(
                'type' => 'shop_order',
                'status' => array('wc-processing', 'wc-completed'),
                'date_created' => '>' . strtotime("-$months months"),
                'limit' => 100,
                'page' => $page++,
                'return' => 'ids',
            ));
            foreach ($orders as $order_id) {
                self::on_order_paid($order_id, true);
            }
        } while (count($orders) === 100);
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM " . RSL_Database::table('regos')) - $before;
    }

    /**
     * Straight to checkout with a rego in the cart, or '' when that product isn't set.
     *  - 'year': the yearly rego.
     *  - 'season': the season rego for $season (a valid key, else the sign-up season).
     *  - '' (the emails): the yearly link only while the yearly product is the ONLY one set,
     *    else '' (callers then link the league page, where the player chooses the season).
     */
    public static function pay_url($kind = '', $season = '') {
        if (!function_exists('wc_get_checkout_url')) {
            return '';
        }
        if ($kind === '') {
            return self::product_id() && !self::season_product_id() ? self::pay_url('year') : '';
        }
        $product_id = self::product_for($kind);
        if (!$product_id) {
            return '';
        }
        if ($kind === 'season') {
            $season = is_string($season) && RSL_Seasons::is_valid($season) ? $season : RSL_Seasons::signup();
            return add_query_arg(array('add-to-cart' => $product_id, self::SEASON_ARG => $season), wc_get_checkout_url());
        }
        return add_query_arg('add-to-cart', $product_id, wc_get_checkout_url());
    }

    /**
     * Where a GET form to checkout goes: array('action' => the checkout URL without its query
     * string, 'fields' => its query arguments). A GET form drops its action's query string,
     * which matters with plain permalinks, so the form repeats them as hidden fields.
     */
    public static function checkout_form_target() {
        $url = function_exists('wc_get_checkout_url') ? (string) wc_get_checkout_url() : '';
        $fields = array();
        wp_parse_str((string) parse_url($url, PHP_URL_QUERY), $fields);
        $parts = explode('?', $url, 2);
        return array('action' => $parts[0], 'fields' => is_array($fields) ? $fields : array());
    }

    public static function recent($limit = 20) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("
            SELECT r.*, u.display_name, u.user_email FROM " . RSL_Database::table('regos') . " r
            LEFT JOIN {$wpdb->users} u ON u.ID = r.user_id
            ORDER BY r.created_at DESC, r.id DESC LIMIT %d
        ", $limit));
    }

    public static function count_current() {
        global $wpdb;
        $today = current_time('Y-m-d');
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM " . RSL_Database::table('regos') . " WHERE start_date <= %s AND end_date >= %s", $today, $today));
    }
}
