<?php
/**
 * Team balances.
 *
 * - Each team has a running balance (the sum of its ledger rows): money in
 *   is positive, charges negative.
 * - Game fees are charged when a night's fixtures are PUBLISHED — the
 *   game_fee per game, so a double-header is two. sync_night() keeps the
 *   charges matching the published fixture: unpublish and they come off,
 *   add or remove a game and they follow. Teams are encouraged to pay
 *   before the fixtures come out.
 * - "Can't make it" after fixtures are out: before the cut-off
 *   (free_cancel_hours before the night starts) neither team in that game
 *   is charged; after it, the withdrawing team pays double — its own fee
 *   and the opponent's (a late_fee of 2 × game_fee) — and the opponent
 *   pays nothing.
 * - A team owing more than max_owing isn't fixtured (one forgotten week is
 *   fine; two isn't).
 * - Top-ups: any team member pays any amount through WooCommerce (a
 *   placeholder product whose price is set from the amount); each paid
 *   order item is recorded once (UNIQUE order_item_id). The captain sees
 *   who has contributed. Admins can add credits or charges with a note.
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
    }

    // ------------------------------------------------------------------
    // Settings
    // ------------------------------------------------------------------

    /** game_fee, free_cancel_hours, max_owing (dollars / hours). */
    public static function settings() {
        $s = get_option(self::SETTINGS_OPTION, array());
        $s = is_array($s) ? $s : array();
        return array(
            'game_fee' => isset($s['game_fee']) ? (float) $s['game_fee'] : 75.0,
            'free_cancel_hours' => isset($s['free_cancel_hours']) ? intval($s['free_cancel_hours']) : 48,
            'max_owing' => isset($s['max_owing']) ? (float) $s['max_owing'] : 75.0,
        );
    }

    public static function save_settings($args) {
        update_option(self::SETTINGS_OPTION, array(
            'game_fee' => round(max(0, (float) (isset($args['game_fee']) ? $args['game_fee'] : 75)), 2),
            'free_cancel_hours' => max(0, min(336, intval(isset($args['free_cancel_hours']) ? $args['free_cancel_hours'] : 48))),
            'max_owing' => round(max(0, (float) (isset($args['max_owing']) ? $args['max_owing'] : 75)), 2),
        ), false);
        return self::settings();
    }

    public static function product_id() {
        return intval(get_option(self::PRODUCT_OPTION));
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

    /** Who has topped up and how much: list of (name, total, count), biggest first. */
    public static function contributions($team_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT l.user_id, u.display_name AS name, SUM(l.amount) AS total, COUNT(*) AS payments
            FROM " . RSL_Database::table('ledger') . " l LEFT JOIN {$wpdb->users} u ON u.ID = l.user_id
            WHERE l.team_id = %d AND l.type = 'topup' GROUP BY l.user_id, u.display_name ORDER BY total DESC", $team_id));
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

    private static function add($team_id, $type, $amount, $fields = array()) {
        global $wpdb;
        $team = RSL_Teams::get($team_id);
        if (!$team) {
            return new WP_Error('rsl_money', 'That team no longer exists.');
        }
        $ok = $wpdb->insert(RSL_Database::table('ledger'), array_merge(array(
            'team_id' => intval($team_id), 'season' => $team->season, 'type' => $type, 'amount' => round((float) $amount, 2),
        ), $fields));
        return $ok ? intval($wpdb->insert_id) : 0;
    }

    /** A payment that didn't come through the online checkout (e.g. recorded by a script or import). */
    public static function record_payment($team_id, $amount, $user_id, $note = 'Paid') {
        return self::add($team_id, 'topup', round((float) $amount, 2), array('user_id' => $user_id ?: null, 'note' => substr($note, 0, 255)));
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
        return self::add($team_id, 'adjust', $amount, array('note' => substr(sanitize_text_field($note), 0, 255), 'created_by' => $by ?: null, 'user_id' => $by ?: null));
    }

    // ------------------------------------------------------------------
    // Game fees follow the published fixtures
    // ------------------------------------------------------------------

    /**
     * Make a night's game charges match its published fixture: every team
     * in every game is charged the game fee — except in a game where either
     * team has said "can't make it" (that game's fees are off; a late
     * withdrawal pays its own late_fee instead). Unpublished nights have no
     * game charges. Existing charges keep the fee they were charged at.
     */
    public static function sync_night($night_id) {
        global $wpdb;
        $night = RSL_Courts::get_night($night_id);
        $table = RSL_Database::table('ledger');
        $want = array();
        if ($night && RSL_Fixtures::is_published($night)) {
            $out = RSL_Requests::for_night($night->id, 'cant_make', array('active', 'handled'));
            foreach (RSL_Fixtures::games_for_night($night->id) as $g) {
                $h = intval($g->home_team_id);
                $a = intval($g->away_team_id);
                if (isset($out[$h]) || isset($out[$a])) {
                    continue;
                }
                $want[$h . '|' . $g->id] = $h;
                $want[$a . '|' . $g->id] = $a;
            }
        }
        $have = array();
        foreach ($wpdb->get_results($wpdb->prepare("SELECT id, team_id, game_id FROM $table WHERE night_id = %d AND type = 'game'", $night_id)) as $r) {
            $key = intval($r->team_id) . '|' . intval($r->game_id);
            if (!isset($want[$key]) || isset($have[$key])) {
                $wpdb->delete($table, array('id' => $r->id));
            } else {
                $have[$key] = true;
            }
        }
        $fee = self::settings()['game_fee'];
        foreach ($want as $key => $team_id) {
            if (!isset($have[$key])) {
                list(, $game_id) = explode('|', $key);
                self::add($team_id, 'game', -$fee, array('game_id' => intval($game_id), 'night_id' => intval($night_id)));
            }
        }
    }

    /** When a "can't make it" counts as late: after this time (null if the night has no games). */
    public static function free_cancel_until($night) {
        $start = RSL_Officials::night_start($night);
        return $start ? $start - self::settings()['free_cancel_hours'] * HOUR_IN_SECONDS : null;
    }

    /** A "can't make it" was made: charge the late fee if it's past the cut-off, then re-sync the night. */
    public static function on_cant_make($request) {
        $night = RSL_Courts::get_night($request->night_id);
        if ($request->late) {
            $fee = self::settings()['game_fee'];
            self::add($request->team_id, 'late_fee', -2 * $fee, array('night_id' => intval($request->night_id), 'request_id' => intval($request->id),
                'note' => 'Late "can\'t make it" — your fee and the other team\'s'));
        }
        self::sync_night($night->id);
    }

    /** A "can't make it" was withdrawn ("we can make it after all"): undo its late fee and re-sync. */
    public static function on_cant_make_cancelled($request) {
        global $wpdb;
        $wpdb->delete(RSL_Database::table('ledger'), array('type' => 'late_fee', 'request_id' => intval($request->id)));
        self::sync_night($request->night_id);
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
        if (!$team || (!RSL_Teams::is_member($team->id, $user_id) && !user_can($user_id, 'manage_options'))) {
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
                continue; // processing then completed: credited once
            }
            // UNIQUE order_item_id: a second call for the same item inserts nothing.
            self::add($team_id, 'topup', $amount, array('order_id' => intval($order_id), 'order_item_id' => intval($item_id),
                'user_id' => intval($item->get_meta('_rsl_topup_user')) ?: (intval($order->get_customer_id()) ?: null), 'note' => 'Paid online'));
        }
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

    /** A ledger row in words: "Game fee · R5 (Sun 25 Oct)", "Topped up by Kai", or the note. */
    public static function describe($row, $labels) {
        $when = $row->night_id && isset($labels[$row->night_id]) ? $labels[$row->night_id] . ($row->night_date ? ' (' . date_i18n('D j M', strtotime($row->night_date)) . ')' : '') : '';
        switch ($row->type) {
            case 'game':
                return 'Game fee · ' . $when;
            case 'late_fee':
                return 'Late "can\'t make it" (both teams\' fees) · ' . $when;
            case 'topup':
                return 'Topped up' . ($row->payer ? ' by ' . $row->payer : '');
            default:
                return $row->note !== '' ? $row->note : 'Adjustment';
        }
    }

    public static function team_card($team) {
        $user_id = get_current_user_id();
        $admin = current_user_can('manage_options');
        if (!$user_id || (!RSL_Teams::is_member($team->id, $user_id) && !$admin)) {
            return '';
        }
        $s = self::settings();
        $balance = self::balance($team->id);
        $owing = max(0, -$balance);
        $blocked = self::blocked($team->id);
        $to_come = self::nights_to_come($team->season);
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
            $choices[] = array($season_need, 'Rest of the season (' . $to_come . ' game' . ($to_come === 1 ? '' : 's') . ')');
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
            <?php else: ?>
                <p class="rsl-hint">Online payment isn't set up yet — pay the league directly for now.</p>
            <?php endif; ?>
            <?php if ($captain && ($paid = self::contributions($team->id))): ?>
                <p class="rsl-money-who"><strong>Who's paid:</strong> <?php echo esc_html(implode(' · ', array_map(function ($p) { return ($p->name ?: 'Someone') . ' ' . self::money($p->total); }, $paid))); ?></p>
            <?php endif; ?>
            <?php if ($rows): ?>
                <details class="rsl-money-history"><summary>Recent activity</summary>
                    <table><tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr><td><?php echo esc_html(date_i18n('j M', strtotime($r->created_at))); ?></td><td><?php echo esc_html(self::describe($r, $labels)); ?></td>
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
