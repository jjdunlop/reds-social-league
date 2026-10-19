<?php
/**
 * WooCommerce refunds and cancellations: a refunded or cancelled top-up
 * comes back off the team (never more than was paid), a refunded or
 * cancelled rego ends it, a cancelled order paid again puts either back,
 * admins can remove a rego, order notes. Also the
 * rego product (one at a time, its price label) and the "can't check
 * memberships" state.
 *
 *   wp eval-file dev/tests/test-refunds.php
 */

if (!defined('WP_CLI') || !WP_CLI || (wp_get_environment_type() === 'production' && !getenv('RSL_DEV'))) {
    exit("Local copy only: run with wp eval-file, with RSL_DEV=1 set (docker exec -e RSL_DEV=1 ...).\n");
}
require_once ABSPATH . 'wp-admin/includes/user.php';
global $wpdb;

$season = '2094-pre';
$suffix = time();
$fails = 0;
$check = function ($label, $ok, $detail = '') use (&$fails) {
    if (!$ok) { $fails++; }
    echo ($ok ? "OK: $label\n" : "FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n");
};
$saved = array(
    RSL_Payments::PRODUCT_OPTION => get_option(RSL_Payments::PRODUCT_OPTION, '__unset__'),
    RSL_Rego::PRODUCT_OPTION => get_option(RSL_Rego::PRODUCT_OPTION, '__unset__'),
    'woocommerce_prices_include_tax' => get_option('woocommerce_prices_include_tax', '__unset__'),
);
add_filter('pre_wp_mail', '__return_true');

$users = array();
$mk = function ($tag, $role = 'subscriber') use ($suffix, &$users) {
    $u = wp_insert_user(array('user_login' => "rslr-$tag-$suffix", 'user_email' => "rslr-$tag-$suffix@example.test", 'user_pass' => wp_generate_password(), 'display_name' => "RSLR $tag", 'role' => $role));
    $users[] = $u;
    return $u;
};
$cap = $mk('cap');
$payer = $mk('payer');
$admin = $mk('admin', 'administrator');
$team = RSL_Teams::create($season, array('name' => "Refund Team $suffix", 'division' => 'ranked'));
RSL_Teams::add_member($team->id, $cap, 'captain');
RSL_Teams::add_member($team->id, $payer);
$bal = function () use ($team) { return RSL_Payments::balance($team->id); };
$products = array();
$make_product = function ($name, $price) use (&$products) {
    $p = new WC_Product_Simple();
    $p->set_name($name);
    $p->set_regular_price((string) $price);
    $p->set_virtual(true);
    $p->set_status('publish');
    $p->save();
    $products[] = $p;
    return $p;
};
$orders = array();
$make_order = function ($product, $customer, $total, $meta = array()) use (&$orders) {
    $order = wc_create_order($customer ? array('customer_id' => $customer) : array());
    $item = new WC_Order_Item_Product();
    $item->set_product($product);
    $item->set_quantity(1);
    $item->set_subtotal($total);
    $item->set_total($total);
    foreach ($meta as $k => $v) { $item->add_meta_data($k, $v, true); }
    $order->add_item($item);
    $order->calculate_totals();
    $order->save();
    $orders[] = $order;
    return $order;
};
$item_of = function ($order) { $items = $order->get_items(); return reset($items); };
$notes = function ($order, $text) {
    return count(array_filter(wc_get_order_notes(array('order_id' => $order->get_id())), function ($n) use ($text) { return strpos($n->content, $text) !== false; }));
};
$line_refund = function ($order, $item, $amount, $qty = 0) {
    return wc_create_refund(array('order_id' => $order->get_id(), 'amount' => $amount, 'reason' => 'Test',
        'line_items' => array($item->get_id() => array('qty' => $qty, 'refund_total' => $amount, 'refund_tax' => array()))));
};

// --- Top-ups ---------------------------------------------------------------------------------------------
$topup = $make_product("Top-up test $suffix", 1);
update_option(RSL_Payments::PRODUCT_OPTION, $topup->get_id());
$order = $make_order($topup, $payer, 100, array('_rsl_topup_team' => $team->id, '_rsl_topup_user' => $payer));
$item = $item_of($order);
$paid = round((float) $item->get_total() + (float) $item->get_total_tax(), 2);
$order->update_status('processing');
$check('a paid top-up is credited, with a note on the order', abs($bal() - $paid) < 0.01 && $notes(wc_get_order($order->get_id()), 'credited to') === 1);
$r1 = $line_refund($order, $item, 30);
$check('a partial refund of the line takes that much back off', !is_wp_error($r1) && abs($bal() - ($paid - 30)) < 0.01, is_wp_error($r1) ? $r1->get_error_message() : (string) $bal());
$check('...as a keyed refund row, noted on the order', (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM " . RSL_Database::table('ledger') . " WHERE team_id = %d AND type = 'refund' AND charge_key LIKE %s", $team->id, 'refund:' . $item->get_id() . ':%'))
    && $notes(wc_get_order($order->get_id()), 'taken back off') === 1);
$who = RSL_Payments::contributions($team->id);
$check('"who\'s paid" counts the refund', $who && abs(floatval($who[0]->total) - ($paid - 30)) < 0.01 && intval($who[0]->payments) === 1);
RSL_Payments::on_order_refunded($order->get_id(), $r1->get_id());
$check('the same refund twice changes nothing', abs($bal() - ($paid - 30)) < 0.01);
wc_get_order($order->get_id())->update_status('refunded'); // the rest, not split by line
$check('fully refunded: the rest comes off too — back to zero, never below', abs($bal()) < 0.01, (string) $bal());
RSL_Payments::on_order_reversed($order->get_id());
$check('...and again changes nothing', abs($bal()) < 0.01);
$check('a refund row reads "Refund to <payer>" for the captain, "Refund" for others', strpos(RSL_Payments::describe(RSL_Payments::ledger($team->id)[0], array()), 'Refund to RSLR payer') === 0
    && RSL_Payments::describe(RSL_Payments::ledger($team->id)[0], array(), false, $cap) === 'Refund');

$order2 = $make_order($topup, $payer, 50, array('_rsl_topup_team' => $team->id, '_rsl_topup_user' => $payer));
$order2->update_status('processing');
$check('set-up: a second top-up', $bal() > 0);
wc_get_order($order2->get_id())->update_status('cancelled');
$check('a cancelled order takes its top-up back off', abs($bal()) < 0.01, (string) $bal());
$order3 = $make_order($topup, $payer, 20, array('_rsl_topup_team' => $team->id, '_rsl_topup_user' => $payer));
wc_get_order($order3->get_id())->update_status('cancelled');
$check('an order cancelled before it was paid changes nothing', abs($bal()) < 0.01);
// Cancelled by mistake, then set back to paid: the top-up goes back on (and can come off again).
$paid2 = round((float) $item_of($order2)->get_total() + (float) $item_of($order2)->get_total_tax(), 2);
wc_get_order($order2->get_id())->update_status('processing');
$check('a cancelled top-up order paid again puts it back, with a note', abs($bal() - $paid2) < 0.01 && $notes(wc_get_order($order2->get_id()), 'put back on') === 1, (string) $bal());
$check('...shown as a cancelled top-up put back', strpos(RSL_Payments::describe(RSL_Payments::ledger($team->id)[0], array()), 'Cancelled top-up put back') === 0);
wc_get_order($order2->get_id())->update_status('completed');
$check('...once (processing then completed)', abs($bal() - $paid2) < 0.01 && $notes(wc_get_order($order2->get_id()), 'put back on') === 1);
wc_get_order($order2->get_id())->update_status('cancelled');
$check('...cancelling it again takes it off again', abs($bal()) < 0.01, (string) $bal());
wc_get_order($order2->get_id())->update_status('processing');
$check('...and paying again puts it back again', abs($bal() - $paid2) < 0.01, (string) $bal());
wc_get_order($order2->get_id())->update_status('cancelled');
$order4 = $make_order($topup, $payer, 60, array('_rsl_topup_team' => $team->id, '_rsl_topup_user' => $payer));
$order4->update_status('processing');
$i4 = $item_of($order4);
$paid4 = round((float) $i4->get_total() + (float) $i4->get_total_tax(), 2);
$line_refund($order4, $i4, 20);
wc_get_order($order4->get_id())->update_status('cancelled');
$check('set-up: a part-refunded top-up, then cancelled', abs($bal()) < 0.01, (string) $bal());
wc_get_order($order4->get_id())->update_status('processing');
$check('...paid again: only what wasn\'t refunded goes back on', abs($bal() - ($paid4 - 20)) < 0.01, (string) $bal());
wc_get_order($order4->get_id())->update_status('cancelled');
$check('set-up: all cancelled again', abs($bal()) < 0.01, (string) $bal());

// --- Regos ------------------------------------------------------------------------------------------------
$rego = $make_product("Social rego test $suffix", 55);
update_option(RSL_Rego::PRODUCT_OPTION, $rego->get_id());
$check('the rego is sold one at a time', wc_get_product($rego->get_id())->is_sold_individually() && !wc_get_product($topup->get_id())->is_sold_individually());
update_option('woocommerce_prices_include_tax', 'yes');
$check('the price shown is the product\'s', RSL_Rego::price_label() === '$55', RSL_Rego::price_label());
update_option(RSL_Rego::PRODUCT_OPTION, 0);
$check('...or the fallback with no product set', RSL_Rego::price_label() === RSL_Rego::PRICE_LABEL);
update_option(RSL_Rego::PRODUCT_OPTION, $rego->get_id());

// A rego and a top-up in one order; only the rego is refunded, so the order stays paid.
$p1 = $mk('p1');
$ro = $make_order($rego, $p1, 55);
$extra = new WC_Order_Item_Product();
$extra->set_product($topup);
$extra->set_quantity(1);
$extra->set_subtotal(20);
$extra->set_total(20);
$extra->add_meta_data('_rsl_topup_team', $team->id, true);
$ro->add_item($extra);
$ro->calculate_totals();
$ro->save();
$ri = $item_of($ro);
$ro->update_status('processing');
$check('a paid rego clears them, with a note on the order', RSL_Rego::clearance($p1)['by'] === 'rego' && $notes(wc_get_order($ro->get_id()), '12-month social rego given') === 1);
$after_topup = $bal();
$line_refund($ro, $ri, round((float) $ri->get_total() + (float) $ri->get_total_tax(), 2), 1);
$grant = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . RSL_Database::table('regos') . " WHERE order_item_id = %d", $ri->get_id()));
$check('refunding the rego line ends it (the top-up in the same order stays)', !RSL_Rego::clearance($p1)['cleared'] && $grant && RSL_Rego::is_removed($grant)
    && strpos($grant->note, 'Refunded') !== false && wc_get_order($ro->get_id())->has_status('processing') && abs($bal() - $after_topup) < 0.01);
$check('...noted on the order', $notes(wc_get_order($ro->get_id()), 'social rego ended') === 1);
RSL_Rego::scan_orders();
$check('the past-order scan doesn\'t give it back', !RSL_Rego::clearance($p1)['cleared']);

$p2 = $mk('p2');
$ro2 = $make_order($rego, $p2, 55);
$ro2->update_status('completed');
$check('set-up: a second paid rego', RSL_Rego::clearance($p2)['cleared']);
wc_get_order($ro2->get_id())->update_status('cancelled');
$check('a cancelled rego order ends it', !RSL_Rego::clearance($p2)['cleared']);
wc_get_order($ro2->get_id())->update_status('processing');
$check('...set back to paid, the rego is given back, with a note', RSL_Rego::clearance($p2)['cleared'] && $notes(wc_get_order($ro2->get_id()), 'social rego given back') === 1);
wc_get_order($ro2->get_id())->update_status('cancelled');
$check('...and cancelling it again ends it again', !RSL_Rego::clearance($p2)['cleared']);
$p5 = $mk('p5');
$ro5 = $make_order($rego, $p5, 55);
$ro5->update_status('processing');
RSL_Rego::revoke(intval($wpdb->get_var($wpdb->prepare("SELECT id FROM " . RSL_Database::table('regos') . " WHERE order_id = %d", $ro5->get_id()))), 'Removed');
wc_get_order($ro5->get_id())->update_status('completed');
$check('a rego an admin removed isn\'t given back when its order is completed', !RSL_Rego::clearance($p5)['cleared']);

$guest = $make_order($rego, 0, 55);
$guest->update_status('processing');
RSL_Rego::on_order_paid($guest->get_id());
$check('a guest checkout gives no rego but says so on the order, once', $notes(wc_get_order($guest->get_id()), 'guest checkout') === 1);

// Admin: remove a rego from Social League → Registration.
$p3 = $mk('p3');
$g3 = RSL_Rego::grant($p3, array('source' => 'manual', 'note' => 'cash'));
$check('set-up: a rego added by hand', RSL_Rego::clearance($p3)['cleared']);
wp_set_current_user($admin);
$_GET = array('page' => RSL_Admin::REG_PAGE);
ob_start(); (new RSL_Admin())->render_registration(); $html = ob_get_clean();
$check('the regos list has a Remove button', strpos($html, 'value="remove_rego"') !== false && strpos($html, 'name="grant_id" value="' . $g3 . '"') !== false);
RSL_Admin::process(array('rsl_nonce' => wp_create_nonce('rsl_team_action'), 'do' => 'remove_rego', 'grant_id' => $g3));
$check('admin removes it: no longer cleared', !RSL_Rego::clearance($p3)['cleared'] && RSL_Rego::revoke($g3) === true);
$check('removing one that isn\'t there says so', is_wp_error(RSL_Rego::revoke(0)));

// --- Memberships can't be checked ---------------------------------------------------------------------------
$p4 = $mk('p4');
$check('normally the state is known when memberships can be checked', RSL_Rego::clearance($p4)['unknown'] === !RSL_Rego::membership_source_available());
add_filter('rsl_membership_source_available', '__return_false');
$c = RSL_Rego::clearance($p4);
$check('without the Club Manager: "unknown", not "needs rego"', $c['cleared'] === false && $c['unknown'] === true && strpos(RSL_Rego::describe($c), 'Can\'t check') === 0);
RSL_Rego::grant($p4, array('source' => 'manual'));
$c = RSL_Rego::clearance($p4);
$check('...while someone with a rego is still cleared', $c['cleared'] && $c['by'] === 'rego' && $c['unknown'] === false);
ob_start(); (new RSL_Admin())->render_registration(); $html = ob_get_clean();
$check('the Registration page warns that memberships can\'t be checked', strpos($html, 'Club memberships can\'t be checked') !== false);
remove_filter('rsl_membership_source_available', '__return_false');

// --- Cleanup ---------------------------------------------------------------------------------------------
$_GET = array();
wp_set_current_user(0);
remove_filter('pre_wp_mail', '__return_true');
foreach ($orders as $o) {
    foreach (wc_get_order($o->get_id())->get_refunds() as $refund) { $refund->delete(true); }
    $wpdb->delete(RSL_Database::table('regos'), array('order_id' => $o->get_id()));
    wc_get_order($o->get_id())->delete(true);
}
foreach ($products as $p) { $p->delete(true); }
$wpdb->delete(RSL_Database::table('ledger'), array('team_id' => $team->id));
$wpdb->delete(RSL_Database::table('team_members'), array('team_id' => $team->id));
$wpdb->delete(RSL_Database::table('teams'), array('id' => $team->id));
foreach ($users as $u) {
    $wpdb->delete(RSL_Database::table('regos'), array('user_id' => $u));
    delete_transient(RSL_Portal::FLASH_PREFIX . $u);
    delete_transient(RSL_Admin::NOTICE_PREFIX . $u);
    wp_delete_user($u);
}
foreach ($saved as $opt => $value) {
    if ($value === '__unset__') { delete_option($opt); } else { update_option($opt, $value); }
}
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
