<?php
/**
 * Who's cleared to play, worked out live:
 *  - a FULL or LIFE club member on that date (from the MURVC Club Manager)
 *    is covered — no social rego needed; or
 *  - a social rego covering that date: 12 months from payment, bought
 *    through the rego product (or added by an admin).
 * Being on a team doesn't need either; being cleared is what's needed
 * before someone's first game. A membership that lapses stops covering
 * them the day it ends, with no admin work.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RSL_Rego {

    const PRODUCT_OPTION = 'rsl_rego_product_id';
    const PRICE_LABEL = '$40';

    /** Club Manager tiers that cover the social rego. */
    const COVERING_TIERS = array('life-member', 'full-member');

    public function __construct() {
        add_action('woocommerce_order_status_processing', array(__CLASS__, 'on_order_paid'));
        add_action('woocommerce_order_status_completed', array(__CLASS__, 'on_order_paid'));
    }

    // ------------------------------------------------------------------
    // Clearance
    // ------------------------------------------------------------------

    /**
     * Last day a full/life membership covers them, from $date, or null.
     * Uses the Club Manager when it's active; without it, nobody is
     * covered by membership (they'd need the rego).
     */
    public static function member_covered_until($user_id, $date = null) {
        $date = $date ?: current_time('Y-m-d');
        if (class_exists('TeamOversight_Memberships') && method_exists('TeamOversight_Memberships', 'covered_until')) {
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
     * array('cleared' => bool, 'by' => 'member'|'rego'|null, 'until' => date|null)
     * Membership is checked first: a covered member needs no rego.
     */
    public static function clearance($user_id, $date = null) {
        $member = self::member_covered_until($user_id, $date);
        if ($member) {
            return array('cleared' => true, 'by' => 'member', 'until' => $member);
        }
        $rego = self::rego_valid_until($user_id, $date);
        if ($rego) {
            return array('cleared' => true, 'by' => 'rego', 'until' => $rego);
        }
        return array('cleared' => false, 'by' => null, 'until' => null);
    }

    /** Admin wording: says WHY they're cleared. */
    public static function describe($clearance) {
        if (!$clearance['cleared']) {
            return 'Needs rego';
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
        ));
        return $ok ? (int) $wpdb->insert_id : 0;
    }

    public static function product_id() {
        return intval(get_option(self::PRODUCT_OPTION));
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
        if (!$order || !$order->get_customer_id()) {
            return; // guest orders can't be tied to a player
        }
        $paid = $order->get_date_paid() ?: $order->get_date_created();
        foreach ($order->get_items() as $item_id => $item) {
            if (!self::is_rego_item($item)) {
                continue;
            }
            // One 12-month grant per order item (the dedupe key). Buying
            // more than one in an order is treated as one.
            self::grant($order->get_customer_id(), array(
                'start' => $paid ? $paid->date('Y-m-d') : current_time('Y-m-d'),
                'source' => 'purchase',
                'order_id' => $order->get_id(),
                'order_item_id' => $item_id,
            ));
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
