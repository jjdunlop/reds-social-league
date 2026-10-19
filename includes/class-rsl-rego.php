<?php
/**
 * Who's cleared to play, worked out live:
 *  - a FULL or LIFE club member on that date (from the MURVC Club Manager)
 *    is covered — no social rego needed; or
 *  - a social rego covering that date: 12 months from payment, bought
 *    through the rego product (or added by an admin).
 * Being on a team doesn't need either; being cleared is what's needed
 * before someone's first game. A membership that lapses stops covering
 * them the day it ends, with no admin work. A refunded or cancelled rego
 * order ends that rego (an admin can also remove one); a cancelled order
 * set back to paid gives it back.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RSL_Rego {

    const PRODUCT_OPTION = 'rsl_rego_product_id';
    const PRICE_LABEL = '$40'; // shown when the product's price can't be read

    /** Club Manager tiers that cover the social rego. */
    const COVERING_TIERS = array('life-member', 'full-member');

    public function __construct() {
        add_action('woocommerce_order_status_processing', array(__CLASS__, 'on_order_paid'));
        add_action('woocommerce_order_status_completed', array(__CLASS__, 'on_order_paid'));
        add_action('woocommerce_order_refunded', array(__CLASS__, 'on_order_refunded'), 10, 2);
        add_action('woocommerce_order_status_refunded', array(__CLASS__, 'on_order_reversed'));
        add_action('woocommerce_order_status_cancelled', array(__CLASS__, 'on_order_reversed'));
        // One rego per checkout: the pay link adds it again on every visit.
        add_filter('woocommerce_is_sold_individually', array(__CLASS__, 'sold_individually'), 10, 2);
        add_filter('woocommerce_add_to_cart_validation', array(__CLASS__, 'one_in_cart'), 10, 2);
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

    /** Last day of the unbroken run of regos covering $date, or null. */
    public static function rego_valid_until($user_id, $date = null) {
        global $wpdb;
        $date = $date ?: current_time('Y-m-d');
        $grants = $wpdb->get_results($wpdb->prepare("
            SELECT start_date, end_date FROM " . RSL_Database::table('regos') . "
            WHERE user_id = %d AND end_date >= %s ORDER BY start_date
        ", $user_id, $date));
        $until = null;
        foreach ($grants as $g) {
            if ($until === null) {
                if ($g->start_date <= $date) {
                    $until = $g->end_date;
                }
            } elseif ($g->start_date <= date('Y-m-d', strtotime($until . ' +1 day'))) {
                $until = max($until, $g->end_date);
            }
        }
        return $until;
    }

    /**
     * array('cleared' => bool, 'by' => 'member'|'rego'|null, 'until' => date|null,
     * 'unknown' => bool). Membership is checked first: a covered member needs
     * no rego. 'unknown': no rego, and memberships can't be checked right
     * now (Club Manager missing) — they may be a member, so don't ask them
     * to pay; 'cleared' stays false until it can be confirmed.
     */
    public static function clearance($user_id, $date = null) {
        $member = self::member_covered_until($user_id, $date);
        if ($member) {
            return array('cleared' => true, 'by' => 'member', 'until' => $member, 'unknown' => false);
        }
        $rego = self::rego_valid_until($user_id, $date);
        if ($rego) {
            return array('cleared' => true, 'by' => 'rego', 'until' => $rego, 'unknown' => false);
        }
        return array('cleared' => false, 'by' => null, 'until' => null, 'unknown' => !self::membership_source_available());
    }

    /** Admin wording: says WHY they're cleared. */
    public static function describe($clearance) {
        if (!$clearance['cleared']) {
            return !empty($clearance['unknown']) ? 'Can\'t check membership right now (no rego)' : 'Needs rego';
        }
        $until = $clearance['until'] >= '2099-01-01' ? 'no expiry' : 'until ' . date('j M Y', strtotime($clearance['until']));
        return ($clearance['by'] === 'member' ? 'Full/life member' : 'Rego paid') . ' (' . $until . ')';
    }

    // ------------------------------------------------------------------
    // Grants
    // ------------------------------------------------------------------

    /**
     * Add a 12-month rego. A purchase made while a rego is still running
     * starts the day after it ends (renewing early never wastes time).
     * $args: start (Y-m-d), source, order_id, order_item_id, note.
     * Returns the new grant id, or 0 when that order item was already granted.
     */
    public static function grant($user_id, $args = array()) {
        global $wpdb;
        $start = isset($args['start']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $args['start']) ? $args['start'] : current_time('Y-m-d');
        $source = isset($args['source']) ? $args['source'] : 'purchase';
        $order_item_id = isset($args['order_item_id']) ? intval($args['order_item_id']) : 0;

        if ($order_item_id && $wpdb->get_var($wpdb->prepare("SELECT id FROM " . RSL_Database::table('regos') . " WHERE order_item_id = %d", $order_item_id))) {
            return 0;
        }
        if ($source === 'purchase') {
            $running = self::rego_valid_until($user_id, $start);
            if ($running) {
                $start = date('Y-m-d', strtotime($running . ' +1 day'));
            }
        }
        $end = date('Y-m-d', strtotime($start . ' +1 year -1 day'));
        $ok = $wpdb->insert(RSL_Database::table('regos'), array(
            'user_id' => $user_id,
            'start_date' => $start,
            'end_date' => $end,
            'source' => $source,
            'order_id' => isset($args['order_id']) ? intval($args['order_id']) : null,
            'order_item_id' => $order_item_id ?: null,
            'note' => isset($args['note']) ? substr((string) $args['note'], 0, 255) : '',
            'created_by' => get_current_user_id() ?: null,
            'created_at' => current_time('mysql'),
        ));
        return $ok ? (int) $wpdb->insert_id : 0;
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
     * grant that order item again. True when done (or already over), or
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
        if ($end >= $g->end_date) {
            return true; // already over
        }
        $note = trim($g->note . ($g->note !== '' ? ' — ' : '') . $why . ' ' . date('j M Y', strtotime($today)));
        $wpdb->update(RSL_Database::table('regos'), array('end_date' => $end, 'note' => substr($note, 0, 255)), array('id' => $g->id));
        return true;
    }

    /** Ended before it began (revoke() of a rego that hadn't started). */
    public static function is_removed($grant) {
        return $grant->end_date < $grant->start_date;
    }

    public static function product_id() {
        return intval(get_option(self::PRODUCT_OPTION));
    }

    /** The rego's price as players see it ("$40"), from the product; PRICE_LABEL if it can't be read. */
    public static function price_label() {
        $product = self::product_id() && function_exists('wc_get_product') ? wc_get_product(self::product_id()) : null;
        if ($product && $product->get_price() !== '' && function_exists('wc_get_price_including_tax')) {
            return RSL_Officials::money(wc_get_price_including_tax($product)); // league prices include GST
        }
        return self::PRICE_LABEL;
    }

    /** The rego product can only be bought one at a time. */
    public static function sold_individually($sold_individually, $product) {
        $id = self::product_id();
        return $sold_individually || ($id && is_object($product) && in_array($id, array(intval($product->get_id()), intval($product->get_parent_id())), true));
    }

    /** Already in the cart (a refreshed or re-clicked pay link): leave it at one, quietly. */
    public static function one_in_cart($passed, $product_id) {
        $id = self::product_id();
        if (!$passed || !$id || intval($product_id) !== $id || !function_exists('WC') || !WC()->cart) {
            return $passed;
        }
        foreach (WC()->cart->get_cart() as $item) {
            if (intval($item['product_id']) === $id || (isset($item['variation_id']) && intval($item['variation_id']) === $id)) {
                return false;
            }
        }
        return $passed;
    }

    /** Does this order item buy the rego? (simple product, or a variation of it) */
    private static function is_rego_item($item) {
        $product_id = self::product_id();
        return $product_id && is_callable(array($item, 'get_product_id'))
            && (intval($item->get_product_id()) === $product_id || intval($item->get_variation_id()) === $product_id);
    }

    /** WooCommerce paid (processing/completed): grant a rego per rego item. */
    public static function on_order_paid($order_id) {
        if (!function_exists('wc_get_order')) {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        $items = array_filter($order->get_items(), function ($item) { return self::is_rego_item($item); });
        if (!$items) {
            return;
        }
        if (!$order->get_customer_id()) {
            RSL_Payments::order_note($order, 'Social League: a guest checkout can\'t be tied to a player, so no rego was given — add it by hand on Social League → Registration.', '_rsl_rego_guest_noted');
            return;
        }
        $paid = $order->get_date_paid() ?: $order->get_date_created();
        foreach ($items as $item_id => $item) {
            // One 12-month grant per order item (the dedupe key). Buying
            // more than one in an order is treated as one.
            $id = self::grant($order->get_customer_id(), array(
                'start' => $paid ? $paid->date('Y-m-d') : current_time('Y-m-d'),
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
            }
        }
    }

    /**
     * A rego order that was cancelled (ending its rego) and is paid again —
     * set back to Processing or Completed: the rego runs its full 12 months
     * again. Only when the order's cancellation is the last thing that ended
     * it (not an admin removing it, not a refund) and WooCommerce lists no
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
        $end = date('Y-m-d', strtotime($g->start_date . ' +1 year -1 day'));
        if ($g->end_date >= $end) {
            return;
        }
        $note = trim($g->note . ' — Order paid again ' . date('j M Y', strtotime(current_time('Y-m-d'))));
        $wpdb->update(RSL_Database::table('regos'), array('end_date' => $end, 'note' => substr($note, 0, 255)), array('id' => $g->id));
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

    /** Pick up rego purchases from the past $months (dedupe means re-running is safe). */
    public static function scan_orders($months = 13) {
        if (!function_exists('wc_get_orders') || !self::product_id()) {
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
                self::on_order_paid($order_id);
            }
        } while (count($orders) === 100);
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM " . RSL_Database::table('regos')) - $before;
    }

    /** Straight to checkout with the rego in the cart, or '' when no product is set. */
    public static function pay_url() {
        $product_id = self::product_id();
        if (!$product_id || !function_exists('wc_get_checkout_url')) {
            return '';
        }
        return add_query_arg('add-to-cart', $product_id, wc_get_checkout_url());
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
