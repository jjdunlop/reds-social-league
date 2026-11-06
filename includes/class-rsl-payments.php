<?php
/**
 * Team balances.
 *
 * - Each team has a running balance (the sum of its ledger rows): money in
 *   is positive, charges negative.
 * - Game fees are charged when a night's fixtures are PUBLISHED — the
 *   game_fee per game, so a double-header is two. sync_night() is the one
 *   place that writes game and late-fee rows, and keeps them matching the
 *   published fixture, its results and its "can't make it"s: unpublish and
 *   they come off, add or remove a game and they follow. Every row it
 *   writes has a charge_key (UNIQUE), so nothing can be charged twice.
 *   Teams are encouraged to pay before the fixtures come out.
 * - "Can't make it" after fixtures are out: before the cut-off
 *   (free_cancel_hours before the night starts) neither team in that game
 *   is charged; after it, the withdrawing team pays double — its own fee
 *   and the opponent's (a late_fee of 2 × game_fee for each of its games
 *   that night) — and the opponent pays nothing. Only the first team out
 *   of a game pays: pulling out of a game the other team had already left
 *   costs nothing. A game that's played after all is charged to both teams
 *   as normal.
 * - A team owing more than max_owing isn't fixtured (one forgotten week is
 *   fine; two isn't).
 * - Top-ups: any team member pays any amount through WooCommerce (a
 *   placeholder product whose price is set from the amount); each paid
 *   order item is recorded once (UNIQUE order_item_id). A refund or a
 *   cancelled order takes it back off (never more than was credited); a
 *   cancelled order set back to paid puts it back (less any refunds). The
 *   captain sees who has contributed. Admins can add credits or charges
 *   with a note, and carry a team's balance on to its next season.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RSL_Payments {

    const SETTINGS_OPTION = 'rsl_payment_settings';
    const PRODUCT_OPTION = 'rsl_topup_product_id';
    const CART_KEY = 'rsl_team_topup';

    public function __construct() {
        add_action('template_redirect', array($this, 'maybe_start_topup'));
        add_action('woocommerce_before_calculate_totals', array(__CLASS__, 'apply_cart_amount'), 20);
        add_filter('woocommerce_get_item_data', array(__CLASS__, 'show_in_cart'), 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', array(__CLASS__, 'attach_to_order_item'), 10, 3);
        add_action('woocommerce_order_status_processing', array(__CLASS__, 'on_order_paid'));
        add_action('woocommerce_order_status_completed', array(__CLASS__, 'on_order_paid'));
        add_action('woocommerce_order_refunded', array(__CLASS__, 'on_order_refunded'), 10, 2);
        add_action('woocommerce_order_status_refunded', array(__CLASS__, 'on_order_reversed'));
        add_action('woocommerce_order_status_cancelled', array(__CLASS__, 'on_order_reversed'));
    }

    // ------------------------------------------------------------------
    // Settings
    // ------------------------------------------------------------------

    /**
     * game_fee, free_cancel_hours, max_owing (dollars / hours), and
     * contact_emails: who hears about "can't make it"s ('' = the site admin).
     */
    public static function settings() {
        $s = get_option(self::SETTINGS_OPTION, array());
        $s = is_array($s) ? $s : array();
        return array(
            'game_fee' => isset($s['game_fee']) ? (float) $s['game_fee'] : 75.0,
            'free_cancel_hours' => isset($s['free_cancel_hours']) ? intval($s['free_cancel_hours']) : 48,
            'max_owing' => isset($s['max_owing']) ? (float) $s['max_owing'] : 75.0,
            'contact_emails' => isset($s['contact_emails']) ? (string) $s['contact_emails'] : '',
        );
    }

    /** Save the rules; any left out keep their current value. */
    public static function save_settings($args) {
        $args = array_merge(self::settings(), is_array($args) ? $args : array());
        $emails = array_filter(array_map('sanitize_email', preg_split('/[\s,;]+/', (string) $args['contact_emails'])), 'is_email');
        update_option(self::SETTINGS_OPTION, array(
            'game_fee' => round(max(0, (float) $args['game_fee']), 2),
            'free_cancel_hours' => max(0, min(336, intval($args['free_cancel_hours']))),
            'max_owing' => round(max(0, (float) $args['max_owing']), 2),
            'contact_emails' => implode(', ', array_unique($emails)),
        ), true);
        return self::settings();
    }

    /** Who hears about "can't make it"s: the league contact email(s), else the site admin. */
    public static function contact_emails() {
        $emails = array_filter(array_map('trim', explode(',', self::settings()['contact_emails'])));
        return $emails ? array_values($emails) : array_values(array_filter(array((string) get_option('admin_email'))));
    }

    public static function product_id() {
        return intval(get_option(self::PRODUCT_OPTION));
    }

    /**
     * Why a product can't be the top-up product, or '' (0, not set, is fine).
     * The amount a player chooses replaces its price, so it must be a product
     * (not a variation), not a rego product (the past-order scan would read its
     * orders as regos) and not one the Club Manager gives a club membership for
     * (a top-up of any amount would buy the membership). Only an administrator
     * chooses it (RSL_Access::may_choose_products(), 0.22.0).
     */
    public static function topup_product_problem($product_id) {
        $product_id = intval($product_id);
        if ($product_id <= 0) {
            return '';
        }
        if (get_post_type($product_id) !== 'product') {
            return 'Pick the top-up product from the list.';
        }
        if ($product_id === RSL_Rego::product_id() || $product_id === RSL_Rego::season_product_id()) {
            return 'The team top-up product can\'t be a rego product.';
        }
        if (RSL_Rego::grants_membership($product_id)) {
            return get_post_field('post_title', $product_id) . ' (#' . $product_id . ') gives a club membership in the Club Manager, so it can\'t be the top-up product.';
        }
        return '';
    }

    // ------------------------------------------------------------------
    // Balances
    // ------------------------------------------------------------------

    public static function balance($team_id) {
        global $wpdb;
        return round((float) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount), 0) FROM " . RSL_Database::table('ledger') . " WHERE team_id = %d", $team_id)), 2);
    }

    /** Dollars owed (0 when in credit). */
    public static function owing($team_id) {
        return max(0, -self::balance($team_id));
    }

    /** Owing more than the limit: the fixtures leave this team out until it pays. */
    public static function blocked($team_id) {
        return self::owing($team_id) > self::settings()['max_owing'] + 0.001;
    }

    /** A team's ledger, newest first, with payer / night details. */
    public static function ledger($team_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT l.*, u.display_name AS payer, n.night_date FROM " . RSL_Database::table('ledger') . " l
            LEFT JOIN {$wpdb->users} u ON u.ID = l.user_id LEFT JOIN " . RSL_Database::table('nights') . " n ON n.id = l.night_id
            WHERE l.team_id = %d ORDER BY l.created_at DESC, l.id DESC", $team_id));
    }

    /** Who has topped up and how much (less refunds): list of (name, total, count), biggest first. */
    public static function contributions($team_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT l.user_id, u.display_name AS name, SUM(l.amount) AS total, SUM(l.type = 'topup') AS payments
            FROM " . RSL_Database::table('ledger') . " l LEFT JOIN {$wpdb->users} u ON u.ID = l.user_id
            WHERE l.team_id = %d AND l.type IN ('topup', 'refund') GROUP BY l.user_id, u.display_name HAVING ABS(SUM(l.amount)) >= 0.01 ORDER BY total DESC", $team_id));
    }

    /** Every active team in a season with its balance, most owing first. */
    public static function season_balances($season) {
        $rows = array();
        foreach (RSL_Teams::list_for_season($season) as $t) {
            $rows[] = (object) array('team' => $t, 'balance' => self::balance($t->id));
        }
        usort($rows, function ($a, $b) { return $a->balance <=> $b->balance; });
        return $rows;
    }

    /**
     * One ledger row. Returns its id; 0 when it wasn't written (a row with
     * the same charge_key or order item is already there — the dedupe).
     */
    private static function add($team_id, $type, $amount, $fields = array()) {
        global $wpdb;
        $team = RSL_Teams::get($team_id);
        if (!$team) {
            return new WP_Error('rsl_money', 'That team no longer exists.');
        }
        // Local time, like everything the plugin compares and shows (the DB default is the server's clock).
        $ok = $wpdb->insert(RSL_Database::table('ledger'), array_merge(array(
            'team_id' => intval($team_id), 'season' => $team->season, 'type' => $type, 'amount' => round((float) $amount, 2),
            'created_at' => current_time('mysql'),
        ), $fields));
        return $ok ? intval($wpdb->insert_id) : 0;
    }

    /** A ledger note that fits its column, cut on a whole character (0.17.0; never a byte cut). */
    private static function note_fit($note) {
        return RSL_Rego::note_fit_to((string) $note, RSL_Rego::NOTE_BYTES);
    }

    /** A payment that didn't come through the online checkout (e.g. recorded by a script or import). */
    public static function record_payment($team_id, $amount, $user_id, $note = 'Paid') {
        return self::add($team_id, 'topup', round((float) $amount, 2), array('user_id' => $user_id ?: null, 'note' => self::note_fit($note)));
    }

    /** An admin's credit (positive) or charge (negative), with a note. */
    public static function adjust($team_id, $amount, $note, $by) {
        $amount = round((float) $amount, 2);
        if (abs($amount) < 0.01) {
            return new WP_Error('rsl_money', 'Enter an amount (negative for a charge).');
        }
        if (trim($note) === '') {
            return new WP_Error('rsl_money', 'Add a short note saying what it\'s for.');
        }
        return self::add($team_id, 'adjust', $amount, array('note' => self::note_fit(sanitize_text_field($note)), 'created_by' => $by ?: null, 'user_id' => $by ?: null));
    }

    // ------------------------------------------------------------------
    // Season rollover: a team is one row per season, so its balance stays
    // behind until an admin carries it on to the team's next-season row.
    // ------------------------------------------------------------------

    /** A season key's place in time ("2027-mid1" comes after "2027-pre"), for ordering. */
    private static function season_rank($season) {
        $parts = array_keys(RSL_Seasons::parts());
        list($year, $part) = array_pad(explode('-', (string) $season, 2), 2, '');
        $i = array_search($part, $parts, true);
        return intval($year) * 10 + ($i === false ? 0 : $i);
    }

    /** Teams from seasons before $season whose balance isn't cleared yet: list of (team, balance), latest season first. */
    public static function earlier_balances($season) {
        global $wpdb;
        $rows = array();
        foreach ($wpdb->get_results($wpdb->prepare("SELECT team_id, SUM(amount) AS balance FROM " . RSL_Database::table('ledger') . "
            WHERE season <> %s GROUP BY team_id HAVING ABS(SUM(amount)) >= 0.005", $season)) as $r) {
            $team = RSL_Teams::get($r->team_id);
            if ($team && self::season_rank($team->season) < self::season_rank($season)) {
                $rows[] = (object) array('team' => $team, 'balance' => round((float) $r->balance, 2));
            }
        }
        usort($rows, function ($a, $b) {
            return array(self::season_rank($b->team->season), $a->team->name) <=> array(self::season_rank($a->team->season), $b->team->name);
        });
        return $rows;
    }

    /**
     * Move a team's whole balance (credit or debt) to its team in a later
     * season: a paired 'carry' row on each. Keyed by the old team's ledger as
     * it stands, so a double-submitted form carries it once.
     */
    public static function carry_balance($from_id, $to_id, $by) {
        global $wpdb;
        $from = RSL_Teams::get($from_id);
        $to = RSL_Teams::get($to_id);
        if (!$from || !$to) {
            return new WP_Error('rsl_money', 'Pick the team to carry the balance to.');
        }
        if (self::season_rank($to->season) <= self::season_rank($from->season)) {
            return new WP_Error('rsl_money', 'A balance can only be carried on to a later season\'s team.');
        }
        $balance = self::balance($from->id);
        if (abs($balance) < 0.01) {
            return new WP_Error('rsl_money', $from->name . '\'s balance is already clear.');
        }
        $state = intval($wpdb->get_var($wpdb->prepare("SELECT MAX(id) FROM " . RSL_Database::table('ledger') . " WHERE team_id = %d", $from->id)));
        $out = self::add($from->id, 'carry', -$balance, array('note' => self::note_fit('Carried to ' . $to->name . ' (' . RSL_Seasons::label($to->season) . ')'),
            'created_by' => $by ?: null, 'charge_key' => 'carry-out:' . $from->id . ':' . $state));
        if (is_wp_error($out) || !$out) {
            return is_wp_error($out) ? $out : new WP_Error('rsl_money', 'That balance has just been carried over.');
        }
        self::add($to->id, 'carry', $balance, array('note' => self::note_fit('Carried from ' . $from->name . ' (' . RSL_Seasons::label($from->season) . ')'),
            'created_by' => $by ?: null, 'charge_key' => 'carry-in:' . $from->id . ':' . $state));
        return $balance;
    }

    // ------------------------------------------------------------------
    // Game fees follow the published fixtures
    // ------------------------------------------------------------------

    /**
     * Make a night's game and late-fee rows match its published fixture,
     * results and "can't make it"s — the only thing that writes them:
     *  - every team in every game pays the game fee, except in a game where
     *    either team has a live "can't make it" (that game's fees are off);
     *  - a game that was played is charged to both teams whatever the
     *    requests say (so a forfeit by the withdrawing team still never
     *    charges its opponent);
     *  - a late "can't make it" pays a late fee of 2 × game fee for each of
     *    the team's games that night that wasn't played (a double-header is
     *    two) — except a game the other team had already pulled out of (its
     *    live "can't make it" is the earlier one): only the first team out of
     *    a game pays for it;
     *  - an unpublished night, or one made a bye, has no charges.
     * Rows no longer wanted are removed; existing ones keep the fee they were
     * charged at. One sync per night at a time (a database lock), and every
     * row is keyed (charge_key, UNIQUE), so overlapping requests — a
     * double-clicked Publish — can't charge anything twice. Returns false
     * only if another sync of the night held the lock for too long.
     */
    public static function sync_night($night_id) {
        global $wpdb;
        $night_id = intval($night_id);
        $lock = substr($wpdb->prefix . 'rsl_sync_' . $night_id, 0, 64);
        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', $lock));
        if ((string) $got === '0') {
            return false; // another sync of this night is still running
        }
        // (A database without locks returns NULL: carry on — the charge keys still stop doubles.)
        try {
            self::reconcile_night($night_id);
        } finally {
            if ((string) $got === '1') {
                $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            }
        }
        return true;
    }

    /** sync_night()'s work, run while holding the night's lock. */
    private static function reconcile_night($night_id) {
        global $wpdb;
        $night = RSL_Courts::get_night($night_id);
        $table = RSL_Database::table('ledger');
        $fee = self::settings()['game_fee'];
        $want = array(); // charge_key => array(team_id, type, amount, fields)
        if ($night && $night->type !== 'bye' && RSL_Fixtures::is_published($night)) {
            $out = RSL_Requests::for_night($night->id, 'cant_make', array('active', 'handled'));
            foreach (RSL_Fixtures::games_for_night($night->id) as $g) {
                $teams = array(intval($g->home_team_id), intval($g->away_team_id));
                $played = $g->status === 'played';
                if ($played || (!isset($out[$teams[0]]) && !isset($out[$teams[1]]))) {
                    foreach ($teams as $t) {
                        $want['game:' . $t . ':' . $g->id] = array($t, 'game', -$fee, array('game_id' => intval($g->id)));
                    }
                }
                foreach ($teams as $i => $t) {
                    $other = $teams[1 - $i];
                    // Only the first team out of a game pays for it: once the other team
                    // had pulled out (early or late), this game was already off.
                    if (!$played && isset($out[$t]) && $out[$t]->late && !(isset($out[$other]) && intval($out[$other]->id) < intval($out[$t]->id))) {
                        $req = $out[$t];
                        $want['late:' . $req->id . ':' . $g->id] = array($t, 'late_fee', -2 * $fee, array('game_id' => intval($g->id), 'request_id' => intval($req->id),
                            'note' => 'Late "can\'t make it" — your fee and the other team\'s'));
                    }
                }
            }
        }
        $have = array();
        // Keyed rows first, so they keep their key and an older unkeyed copy of the same charge is the one removed.
        foreach ($wpdb->get_results($wpdb->prepare("SELECT id, type, team_id, game_id, request_id, charge_key FROM $table WHERE night_id = %d AND type IN ('game', 'late_fee') ORDER BY charge_key IS NULL, id", $night_id)) as $r) {
            $key = $r->charge_key ? $r->charge_key : self::charge_key_for($r);
            if (!isset($want[$key]) || isset($have[$key])) {
                $wpdb->delete($table, array('id' => $r->id));
                continue;
            }
            if (!$r->charge_key) {
                $wpdb->update($table, array('charge_key' => $key), array('id' => $r->id)); // a row from before keys
            }
            $have[$key] = true;
        }
        foreach ($want as $key => $w) {
            if (!isset($have[$key])) {
                self::add($w[0], $w[1], $w[2], array_merge($w[3], array('night_id' => $night_id, 'charge_key' => $key)));
            }
        }
    }

    /** The charge_key a game or late-fee row would have (rows written before keys existed have none). */
    private static function charge_key_for($row) {
        return $row->type === 'game'
            ? 'game:' . intval($row->team_id) . ':' . intval($row->game_id)
            : 'late:' . intval($row->request_id) . ':' . intval($row->game_id);
    }

    /**
     * One-off, safe to repeat (the schema upgrade runs it): key the game and
     * late-fee rows written before charge_key existed. Duplicate game charges
     * (from overlapping syncs) are removed first, keeping the earliest; a
     * night with an older late fee (one row for the night) is re-synced, so
     * its fee becomes one keyed row per game.
     */
    public static function backfill_charge_keys() {
        global $wpdb;
        $table = RSL_Database::table('ledger');
        $seen = array();
        foreach ($wpdb->get_results("SELECT id, type, team_id, game_id, request_id FROM $table WHERE type = 'game' AND charge_key IS NULL ORDER BY id") as $r) {
            $key = self::charge_key_for($r);
            if (isset($seen[$key]) || $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE charge_key = %s", $key))) {
                $wpdb->delete($table, array('id' => $r->id));
            } else {
                $wpdb->update($table, array('charge_key' => $key), array('id' => $r->id));
                $seen[$key] = true;
            }
        }
        foreach ($wpdb->get_col("SELECT DISTINCT night_id FROM $table WHERE type = 'late_fee' AND charge_key IS NULL") as $night_id) {
            self::sync_night($night_id);
        }
    }

    /** When a "can't make it" counts as late: after this time (null if the night has no games). */
    public static function free_cancel_until($night) {
        $start = RSL_Officials::night_start($night);
        return $start ? $start - self::settings()['free_cancel_hours'] * HOUR_IN_SECONDS : null;
    }

    /** A "can't make it" was made: sync_night() charges the late fee (if it's late) and takes that game's fees off. */
    public static function on_cant_make($request) {
        self::sync_night($request->night_id);
    }

    /** A "can't make it" was withdrawn ("we can make it after all"): sync_night() puts the fees back to normal. */
    public static function on_cant_make_cancelled($request) {
        self::sync_night($request->night_id);
    }

    /**
     * The late fees a "can't make it" carries right now, as a positive amount
     * (0 when it's free: before the cut-off, or every game it touches was
     * already called off by the other team).
     */
    public static function late_fees_for_request($request_id) {
        global $wpdb;
        return -1 * (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM " . RSL_Database::table('ledger') . " WHERE type = 'late_fee' AND request_id = %d",
            $request_id
        ));
    }

    // ------------------------------------------------------------------
    // Top-ups through WooCommerce
    // ------------------------------------------------------------------

    /** Link-free form handling: a team member chose an amount — put it in the cart and go to checkout. */
    public function maybe_start_topup() {
        if (!isset($_POST['rsl_topup']) || !is_user_logged_in()) {
            return;
        }
        // Back to the page the form is on (wp_get_referer() is empty when it posted to itself).
        $here = (is_ssl() ? 'https://' : 'http://') . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '') . (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/');
        $back = wp_validate_redirect(esc_url_raw($here), home_url('/'));
        if (!isset($_POST['rsl_nonce']) || !wp_verify_nonce($_POST['rsl_nonce'], 'rsl_topup')) {
            RSL_Portal::notify('error', 'Your session expired — please try again.');
            wp_safe_redirect($back);
            exit;
        }
        $r = self::start_topup(isset($_POST['team_id']) ? intval($_POST['team_id']) : 0, get_current_user_id(), isset($_POST['amount']) ? $_POST['amount'] : 0);
        if (is_wp_error($r)) {
            RSL_Portal::notify('error', $r->get_error_message());
            wp_safe_redirect($back);
            exit;
        }
        wp_safe_redirect($r);
        exit;
    }

    /** Put a top-up in the cart; returns the checkout URL or WP_Error. Only the team's members (or admins). */
    public static function start_topup($team_id, $user_id, $amount) {
        $team = RSL_Teams::get($team_id);
        if (!$team || (!RSL_Teams::is_member($team->id, $user_id) && !RSL_Access::can($user_id))) {
            return new WP_Error('rsl_money', 'Only players on the team can top up its balance.');
        }
        $amount = round((float) $amount, 2);
        if ($amount < 5 || $amount > 2000) {
            return new WP_Error('rsl_money', 'Enter an amount between $5 and $2,000.');
        }
        $product_id = self::product_id();
        if (!$product_id || !function_exists('WC')) {
            return new WP_Error('rsl_money', 'Online top-ups aren\'t set up yet — talk to the league to pay.');
        }
        if (WC()->cart === null && function_exists('wc_load_cart')) {
            wc_load_cart();
        }
        if (WC()->cart === null) {
            return new WP_Error('rsl_money', 'The checkout isn\'t available right now — please try again.');
        }
        foreach (WC()->cart->get_cart() as $key => $item) {
            if (!empty($item[self::CART_KEY])) {
                WC()->cart->remove_cart_item($key); // one team top-up at a time
            }
        }
        $product = wc_get_product($product_id);
        if (!$product || !$product->is_purchasable()) {
            return new WP_Error('rsl_money', 'Online top-ups aren\'t available right now — talk to the league to pay.');
        }
        $added = WC()->cart->add_to_cart($product_id, 1, 0, array(), array(self::CART_KEY => array('team_id' => intval($team->id), 'user_id' => intval($user_id), 'amount' => $amount)));
        return $added ? wc_get_checkout_url() : new WP_Error('rsl_money', 'Couldn\'t start the payment — please try again.');
    }

    public static function apply_cart_amount($cart) {
        if (!is_object($cart)) {
            return;
        }
        foreach ($cart->get_cart() as $item) {
            if (!empty($item[self::CART_KEY]['amount']) && isset($item['data'])) {
                $item['data']->set_price(self::cart_price($item['data'], (float) $item[self::CART_KEY]['amount']));
            }
        }
    }

    /**
     * League amounts include GST. The cart price that makes the player pay
     * exactly $amount in total, whatever the shop's tax settings: the same
     * amount when prices are entered including tax (as the club's are),
     * or the amount less GST when they're entered excluding it.
     */
    public static function cart_price($product, $amount) {
        if (!function_exists('wc_prices_include_tax') || wc_prices_include_tax() || !wc_tax_enabled() || !$product || !$product->is_taxable()) {
            return $amount;
        }
        $taxes = WC_Tax::calc_tax($amount, WC_Tax::get_rates($product->get_tax_class()), true);
        return round($amount - array_sum($taxes), 4);
    }

    public static function show_in_cart($data, $cart_item) {
        if (!empty($cart_item[self::CART_KEY])) {
            $team = RSL_Teams::get($cart_item[self::CART_KEY]['team_id']);
            $data[] = array('key' => 'Team balance', 'value' => $team ? $team->name : 'Social League team');
        }
        return $data;
    }

    public static function attach_to_order_item($item, $cart_item_key, $values) {
        if (!empty($values[self::CART_KEY])) {
            $item->add_meta_data('_rsl_topup_team', intval($values[self::CART_KEY]['team_id']), true);
            $item->add_meta_data('_rsl_topup_user', intval($values[self::CART_KEY]['user_id']), true);
        }
    }

    /** A paid order with team top-ups: credit each team once per order item. */
    public static function on_order_paid($order_id) {
        if (!function_exists('wc_get_order')) {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        foreach ($order->get_items() as $item_id => $item) {
            $team_id = intval($item->get_meta('_rsl_topup_team'));
            if (!$team_id) {
                continue;
            }
            $amount = round((float) $item->get_total() + (float) $item->get_total_tax(), 2);
            if ($amount <= 0) {
                continue;
            }
            global $wpdb;
            if ($wpdb->get_var($wpdb->prepare("SELECT id FROM " . RSL_Database::table('ledger') . " WHERE order_item_id = %d", $item_id))) {
                self::restore_topup($order, intval($item_id), $item); // cancelled and now paid again
                continue; // processing then completed: credited once
            }
            // UNIQUE order_item_id: a second call for the same item inserts nothing.
            $id = self::add($team_id, 'topup', $amount, array('order_id' => intval($order_id), 'order_item_id' => intval($item_id),
                'user_id' => intval($item->get_meta('_rsl_topup_user')) ?: (intval($order->get_customer_id()) ?: null), 'note' => 'Paid online'));
            if (is_wp_error($id)) {
                self::order_note($order, 'Social League: couldn\'t credit ' . self::money($amount) . ' to team #' . $team_id . ' (' . $id->get_error_message() . ') — record it by hand on Social League → Team Balances.', '_rsl_topup_failed_' . intval($item_id));
            } elseif ($id) {
                $team = RSL_Teams::get($team_id);
                self::order_note($order, 'Social League: ' . self::money($amount) . ' credited to ' . ($team ? $team->name : 'the team') . '\'s balance.');
            }
        }
    }

    /** A refund in WooCommerce: each refunded top-up line comes back off its team (never more than was credited). */
    public static function on_order_refunded($order_id, $refund_id) {
        if (!function_exists('wc_get_order')) {
            return;
        }
        $order = wc_get_order($order_id);
        $refund = wc_get_order($refund_id);
        if (!$order || !$refund) {
            return;
        }
        $topups = array_filter($order->get_items(), function ($item) { return intval($item->get_meta('_rsl_topup_team')) > 0; });
        if (!$topups) {
            return;
        }
        $lines = $refund->get_items();
        foreach ($lines as $line_id => $line) {
            $item_id = intval($line->get_meta('_refunded_item_id'));
            $amount = round(-((float) $line->get_total() + (float) $line->get_total_tax()), 2);
            if (isset($topups[$item_id]) && $amount > 0) {
                self::reverse_topup($order, $item_id, $amount, 'refund:' . $item_id . ':' . intval($line_id), 'Refunded online');
            }
        }
        if (!$lines && !$order->has_status(array('refunded', 'cancelled'))) {
            // Refunded as an amount, not by line: there's no telling which team it was for.
            self::order_note($order, 'Social League: this refund wasn\'t split by line item, so no team balance was changed — adjust it by hand on Social League → Team Balances if it was a top-up.');
        }
    }

    /** An order cancelled or fully refunded: whatever its top-ups still have credited comes back off. */
    public static function on_order_reversed($order_id) {
        if (!function_exists('wc_get_order')) {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        $why = $order->has_status('cancelled') ? 'Order cancelled' : 'Refunded online';
        global $wpdb;
        $table = RSL_Database::table('ledger');
        foreach ($order->get_items() as $item_id => $item) {
            if (intval($item->get_meta('_rsl_topup_team'))) {
                // Keyed per cancellation: one put back by restore_topup() (paid again) can be cancelled again.
                $restored = intval($wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE charge_key LIKE %s ORDER BY id DESC LIMIT 1", $wpdb->esc_like('uncancel:' . intval($item_id) . ':') . '%')));
                self::reverse_topup($order, intval($item_id), null, 'cancel:' . intval($item_id) . ($restored ? ':' . $restored : ''), $why);
            }
        }
    }

    /**
     * What every reversal of one top-up order item adds up to so far
     * (refunds and cancellations negative, cancellations put back positive).
     */
    private static function reversed_total($item_id) {
        global $wpdb;
        $item_id = intval($item_id);
        return (float) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(amount), 0) FROM " . RSL_Database::table('ledger') . " WHERE charge_key = %s OR charge_key LIKE %s OR charge_key LIKE %s OR charge_key LIKE %s",
            'cancel:' . $item_id, $wpdb->esc_like('cancel:' . $item_id . ':') . '%', $wpdb->esc_like('refund:' . $item_id . ':') . '%', $wpdb->esc_like('uncancel:' . $item_id . ':') . '%'));
    }

    /**
     * Take up to $amount (null: everything still credited) of one top-up
     * order item back off its team, as a 'refund' row keyed $key. What's
     * left is the credit less every reversal of that item so far, so a
     * partial refund followed by a full one never takes more than was paid.
     */
    private static function reverse_topup($order, $item_id, $amount, $key, $why) {
        global $wpdb;
        $table = RSL_Database::table('ledger');
        $credit = $wpdb->get_row($wpdb->prepare("SELECT team_id, user_id, amount FROM $table WHERE order_item_id = %d AND type = 'topup'", $item_id));
        if (!$credit || $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE charge_key = %s", $key))) {
            return; // never credited (the order wasn't paid), or this reversal is already recorded
        }
        $left = round((float) $credit->amount + self::reversed_total($item_id), 2);
        $take = round($amount === null ? $left : min($amount, $left), 2);
        if ($take < 0.01) {
            return;
        }
        $id = self::add($credit->team_id, 'refund', -$take, array('order_id' => intval($order->get_id()), 'user_id' => $credit->user_id ? intval($credit->user_id) : null,
            'note' => $why . ' (order #' . intval($order->get_id()) . ')', 'charge_key' => $key));
        if ($id && !is_wp_error($id)) {
            $team = RSL_Teams::get($credit->team_id);
            self::order_note($order, 'Social League: ' . self::money($take) . ' taken back off ' . ($team ? $team->name : 'the team') . '\'s balance (' . strtolower($why) . ').');
        }
    }

    /**
     * A top-up order that was cancelled (taking its credit back off) and is
     * paid again — set back to Processing or Completed: put back what the
     * latest cancellation took, but never more than WooCommerce still counts
     * as paid for the item (its total less its refunds, and no more than the
     * order has left after all its refunds). A 'refund' row keyed by that
     * cancellation, so it's put back once.
     */
    private static function restore_topup($order, $item_id, $item) {
        global $wpdb;
        $table = RSL_Database::table('ledger');
        $credit = $wpdb->get_row($wpdb->prepare("SELECT team_id, user_id, amount FROM $table WHERE order_item_id = %d AND type = 'topup'", $item_id));
        $cancel = $wpdb->get_row($wpdb->prepare("SELECT id, amount FROM $table WHERE charge_key = %s OR charge_key LIKE %s ORDER BY id DESC LIMIT 1",
            'cancel:' . $item_id, $wpdb->esc_like('cancel:' . $item_id . ':') . '%'));
        $key = $cancel ? 'uncancel:' . $item_id . ':' . intval($cancel->id) : '';
        if (!$credit || !$cancel || $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE charge_key = %s", $key))) {
            return; // never cancelled, or this cancellation is already put back
        }
        $refunded = 0.0;
        foreach ($order->get_refunds() as $refund) {
            foreach ($refund->get_items() as $line) {
                if (intval($line->get_meta('_refunded_item_id')) === $item_id) {
                    $refunded += -((float) $line->get_total() + (float) $line->get_total_tax());
                }
            }
        }
        $still = min((float) $item->get_total() + (float) $item->get_total_tax() - $refunded, (float) $order->get_total() - (float) $order->get_total_refunded());
        $give = round(min(-(float) $cancel->amount, $still - ((float) $credit->amount + self::reversed_total($item_id))), 2);
        if ($give < 0.01) {
            return;
        }
        $id = self::add($credit->team_id, 'refund', $give, array('order_id' => intval($order->get_id()), 'user_id' => $credit->user_id ? intval($credit->user_id) : null,
            'note' => 'Order paid again (order #' . intval($order->get_id()) . ')', 'charge_key' => $key));
        if ($id && !is_wp_error($id)) {
            $team = RSL_Teams::get($credit->team_id);
            self::order_note($order, 'Social League: ' . self::money($give) . ' put back on ' . ($team ? $team->name : 'the team') . '\'s balance (the order is paid again).');
        }
    }

    /**
     * A private note on a WooCommerce order (staff only). With $once, a meta
     * flag of that name stops the same note being added again (e.g. a
     * re-run of the past-order scan).
     */
    public static function order_note($order, $text, $once = '') {
        if (!$order || !is_callable(array($order, 'add_order_note'))) {
            return;
        }
        if ($once !== '') {
            if ($order->get_meta($once)) {
                return;
            }
            $order->update_meta_data($once, 1);
            $order->save_meta_data();
        }
        $order->add_order_note($text);
    }

    public static function money($amount) {
        return RSL_Officials::money($amount);
    }

    // ------------------------------------------------------------------
    // The team's money card (team page — the team's players and admins only)
    // ------------------------------------------------------------------

    /** Regular-season nights whose fixtures aren't out yet (what "rest of the season" covers). */
    public static function nights_to_come($season) {
        $today = current_time('Y-m-d');
        return count(array_filter(RSL_Courts::nights($season), function ($n) use ($today) {
            return $n->type === 'regular' && $n->night_date >= $today && !RSL_Fixtures::is_published($n);
        }));
    }

    /**
     * The games a team can expect in the rest of its season, for the pay buttons: one on each
     * regular night still to be drawn (nights_to_come()), none on a night it asked to have off,
     * two on a night it asked for two games. Byes aren't known until each week's draw, so it's an
     * estimate (the card says about).
     */
    public static function games_to_come($team) {
        $asked = array();
        foreach (RSL_Requests::for_team($team->id) as $night_id => $r) {
            if ($r->status === 'active' && in_array($r->type, array('off', 'double'), true)) {
                $asked[intval($night_id)] = $r->type;
            }
        }
        return self::count_games(RSL_Courts::nights($team->season), $asked, current_time('Y-m-d'));
    }

    /** Pure core of games_to_come(). $nights: night rows; $asked: night id => 'off' | 'double'; $today: Y-m-d. */
    public static function count_games($nights, $asked, $today) {
        $games = 0;
        foreach ($nights as $n) {
            if ($n->type !== 'regular' || $n->night_date < $today || RSL_Fixtures::is_published($n)) {
                continue;
            }
            $type = isset($asked[intval($n->id)]) ? $asked[intval($n->id)] : '';
            $games += $type === 'off' ? 0 : ($type === 'double' ? 2 : 1);
        }
        return $games;
    }

    /**
     * Each member's fair part of what the team still needs ($need): the
     * amounts that bring everyone's top-ups up to one common level, so
     * together they come to exactly $need. Someone who has already put in
     * their part is asked for nothing, and paying your part never changes
     * anyone else's. Pure (no database). $paid: user_id => dollars topped
     * up so far, one entry per member. Returns user_id => dollars, unrounded.
     */
    public static function fair_shares($need, $paid) {
        $paid = array_map(function ($p) { return max(0.0, (float) $p); }, $paid);
        $shares = array_fill_keys(array_keys($paid), 0.0);
        $need = (float) $need;
        if ($need < 0.005 || !$paid) {
            return $shares;
        }
        asort($paid);
        $sorted = array_values($paid);
        $sum = 0.0;
        $level = 0.0;
        // Fill from the lowest payer up: the level is reached when the next member has already paid at least that much.
        foreach ($sorted as $k => $p) {
            $sum += $p;
            $level = ($need + $sum) / ($k + 1);
            if (!isset($sorted[$k + 1]) || $level <= $sorted[$k + 1]) {
                break;
            }
        }
        foreach ($paid as $uid => $p) {
            $shares[$uid] = max(0.0, $level - $p);
        }
        return $shares;
    }

    /**
     * A ledger row in words: "Game fee · R5 (Sun 25 Oct)", "Topped up by Kai",
     * or the note. $full (captains and admins) names who paid and shows an
     * admin's note; other players see "Topped up" ("by you" for their own,
     * $viewer) and "Adjustment by the league".
     */
    public static function describe($row, $labels, $full = true, $viewer = 0) {
        $when = $row->night_id && isset($labels[$row->night_id]) ? $labels[$row->night_id] . ($row->night_date ? ' (' . date_i18n('D j M', strtotime($row->night_date)) . ')' : '') : '';
        $own = $viewer && intval($row->user_id) === intval($viewer);
        switch ($row->type) {
            case 'game':
                return 'Game fee · ' . $when;
            case 'late_fee':
                return 'Late "can\'t make it" (both teams\' fees) · ' . $when;
            case 'topup':
                return 'Topped up' . ($full && $row->payer ? ' by ' . $row->payer : ($own ? ' by you' : ''));
            case 'refund':
                if ($row->amount > 0) { // a cancelled top-up put back (the order was paid again)
                    return 'Cancelled top-up put back' . ($full && $row->payer ? ' (' . $row->payer . ')' : ($own ? ' (yours)' : ''));
                }
                return 'Refund' . ($full && $row->payer ? ' to ' . $row->payer : ($own ? ' to you' : ''));
            case 'carry':
                return $row->note; // "Carried from <team> (<season>)" — nothing personal
            default:
                if (!$full) {
                    return 'Adjustment by the league';
                }
                return $row->note !== '' ? $row->note : 'Adjustment';
        }
    }

    public static function team_card($team) {
        $user_id = get_current_user_id();
        $admin = RSL_Access::can();
        if (!$user_id || (!RSL_Teams::is_member($team->id, $user_id) && !$admin)) {
            return '';
        }
        $s = self::settings();
        $balance = self::balance($team->id);
        $owing = max(0, -$balance);
        $blocked = self::blocked($team->id);
        $to_come = self::games_to_come($team);
        $season_need = round($to_come * $s['game_fee'] - $balance, 2);
        $labels = RSL_Fixtures::round_labels($team->season);
        $rows = array_slice(self::ledger($team->id), 0, 6);
        $captain = RSL_Teams::is_captain($team->id, $user_id) || $admin;
        $can_pay = self::product_id() > 0;
        $choices = array();
        if ($owing > 0) {
            $choices[] = array($owing, 'Pay what\'s owing');
        }
        if (abs($owing - $s['game_fee']) > 0.001) {
            $choices[] = array($s['game_fee'], 'Next game');
        }
        if ($season_need > $s['game_fee'] && $season_need > $owing) {
            $choices[] = array($season_need, 'Rest of the season (about ' . $to_come . ' game' . ($to_come === 1 ? '' : 's') . ')');
        }
        // Each player's fair part of what the rest of the season still needs —
        // so someone who joined through the free agent list knows what to pay.
        // Less what they've already put in, so it isn't asked of them again.
        $contributions = self::contributions($team->id);
        $members = RSL_Teams::members($team->id);
        $roster = count($members);
        if ($roster > 1 && RSL_Teams::is_member($team->id, $user_id)) {
            $put_in = array();
            foreach ($members as $m) {
                $put_in[intval($m->user_id)] = 0.0;
            }
            foreach ($contributions as $c) {
                if (isset($put_in[intval($c->user_id)])) {
                    $put_in[intval($c->user_id)] = (float) $c->total;
                }
            }
            $shares = self::fair_shares($season_need, $put_in);
            $share = isset($shares[$user_id]) ? ceil(round($shares[$user_id], 2)) : 0;
            if ($share >= 5) {
                $choices[] = array($share, 'My share of the season (split across the ' . $roster . ' of you)');
            }
        }
        ob_start();
        ?>
        <section class="rsl-money">
            <div class="rsl-money-head">
                <div>
                    <small>Team balance</small>
                    <strong class="<?php echo $balance < 0 ? 'is-owing' : 'is-ok'; ?>"><?php echo $balance < 0 ? 'Owes ' . esc_html(self::money($owing)) : esc_html(self::money($balance)) . ($balance > 0 ? ' credit' : ''); ?></strong>
                </div>
                <p class="rsl-hint">Each game is <?php echo esc_html(self::money($s['game_fee'])); ?> (incl. GST), charged when that week's fixtures come out (Mondays) — so pay before then. Anyone on the team can pay any amount.</p>
            </div>
            <?php if ($blocked): ?>
                <p class="rsl-money-warn">Your team won't be fixtured until it owes <?php echo esc_html(self::money($s['max_owing'])); ?> or less.</p>
            <?php elseif ($owing > 0): ?>
                <p class="rsl-money-note">Pay before Monday: a team owing more than <?php echo esc_html(self::money($s['max_owing'])); ?> isn't fixtured.</p>
            <?php elseif ($balance >= $s['game_fee']): ?>
                <p class="rsl-money-note is-good">That covers your next <?php echo intval(floor($balance / $s['game_fee'])); ?> game<?php echo floor($balance / $s['game_fee']) == 1 ? '' : 's'; ?>.</p>
            <?php endif; ?>
            <?php if ($can_pay): ?>
                <div class="rsl-money-pay">
                    <?php foreach ($choices as $c): ?>
                        <form method="post"><?php echo self::topup_fields($team->id); ?><input type="hidden" name="amount" value="<?php echo esc_attr($c[0]); ?>">
                            <button type="submit" class="rsl-req-btn rsl-take">Pay <?php echo esc_html(self::money($c[0])); ?></button><small><?php echo esc_html($c[1]); ?></small></form>
                    <?php endforeach; ?>
                    <form method="post" class="rsl-money-other"><?php echo self::topup_fields($team->id); ?>
                        <label>Other amount $<input type="number" name="amount" min="5" max="2000" step="1" required></label> <button type="submit" class="rsl-req-btn">Pay</button></form>
                </div>
                <?php if ($season_need > $s['game_fee']): ?>
                    <p class="rsl-hint">Season amounts count your week off and two-game requests. Byes aren't known until each week's fixtures come out.</p>
                <?php endif; ?>
            <?php else: ?>
                <p class="rsl-hint">Online payment isn't set up yet — pay the league directly for now.</p>
            <?php endif; ?>
            <?php if ($captain && $contributions): ?>
                <p class="rsl-money-who"><strong>Who's paid:</strong> <?php echo esc_html(implode(' · ', array_map(function ($p) { return ($p->name ?: 'Someone') . ' ' . self::money($p->total); }, $contributions))); ?></p>
            <?php endif; ?>
            <?php if ($rows): ?>
                <details class="rsl-money-history"><summary>Recent activity</summary>
                    <table><tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr><td><?php echo esc_html(date_i18n('j M', strtotime($r->created_at))); ?></td><td><?php echo esc_html(self::describe($r, $labels, $captain, $user_id)); ?></td>
                            <td class="<?php echo $r->amount < 0 ? 'is-owing' : 'is-ok'; ?>"><?php echo ($r->amount < 0 ? '&minus;' : '+') . esc_html(self::money(abs($r->amount))); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody></table>
                </details>
            <?php endif; ?>
        </section>
        <style>
            .rsl-money { margin: 16px 0; padding: 14px 16px; border: 1px solid var(--rsl-border); border-radius: 10px; background: #fff; }
            .rsl-money-head { display: flex; flex-wrap: wrap; gap: 6px 20px; align-items: center; }
            .rsl-money-head small { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: .4px; color: var(--rsl-muted); font-weight: 700; }
            .rsl-money-head strong { font-size: 24px; }
            .rsl-money-head .rsl-hint { flex: 1 1 260px; margin: 0; }
            .rsl-money .is-owing { color: #b32d2e; }
            .rsl-money .is-ok { color: #2e7d32; }
            .rsl-money-warn { margin: 10px 0 0; padding: 8px 12px; border-radius: 6px; background: #fcf0f1; color: #8a1f1f; font-weight: 700; }
            .rsl-money-note { margin: 10px 0 0; font-size: 13px; color: #8a4600; }
            .rsl-money-note.is-good { color: #2e7d32; }
            .rsl-money-pay { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 12px; align-items: flex-start; }
            .rsl-money-pay form { display: flex; flex-direction: column; align-items: flex-start; gap: 2px; margin: 0; }
            .rsl-money-pay small { font-size: 11px; color: var(--rsl-muted); }
            .rsl-money-other { flex-direction: row !important; align-items: center !important; gap: 6px !important; }
            .rsl-money-other input { width: 80px; margin-left: 4px; padding: 3px 6px; }
            .rsl-money-who { font-size: 13px; margin: 12px 0 0; }
            .rsl-money-history { margin-top: 10px; font-size: 13px; }
            .rsl-money-history summary { cursor: pointer; font-weight: 700; color: var(--rsl-navy); }
            .rsl-money-history table { width: 100%; border-collapse: collapse; margin-top: 6px; }
            .rsl-money-history td { padding: 4px 6px; border-bottom: 1px solid var(--rsl-border); }
            .rsl-money-history td:last-child { text-align: right; white-space: nowrap; font-weight: 700; }
        </style>
        <?php
        return ob_get_clean();
    }

    private static function topup_fields($team_id) {
        return wp_nonce_field('rsl_topup', 'rsl_nonce', true, false) . '<input type="hidden" name="rsl_topup" value="1"><input type="hidden" name="team_id" value="' . intval($team_id) . '">';
    }
}
