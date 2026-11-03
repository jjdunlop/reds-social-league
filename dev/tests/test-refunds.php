<?php
/**
 * WooCommerce refunds and cancellations: a refunded or cancelled top-up
 * comes back off the team (never more than was paid), a refunded or
 * cancelled rego ends it, a cancelled order paid again puts either back,
 * admins can remove a rego, order notes. Also the
 * rego product (one at a time, its price label) and the "can't check
 * memberships" state. Season regos (0.15.0): refunds, cancellations and an
 * order paid again restore the end they were granted with; a row kept
 * counting by its season's moved dates can still be ended, and one refunded
 * after its season ended stays ended when the dates move; the past-order
 * scan never guesses a season; a rego that can't be saved leaves an order
 * note.
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
    RSL_Rego::SEASON_PRODUCT_OPTION => get_option(RSL_Rego::SEASON_PRODUCT_OPTION, '__unset__'),
    RSL_Seasons::SIGNUP_OPTION => get_option(RSL_Seasons::SIGNUP_OPTION, '__unset__'),
    RSL_Courts::SETTINGS_OPTION => get_option(RSL_Courts::SETTINGS_OPTION, '__unset__'),
    'woocommerce_prices_include_tax' => get_option('woocommerce_prices_include_tax', '__unset__'),
);
// Before anything runs: the past-order scans below must only ever see this file's products
// (a real season product's paid orders would otherwise be granted to real accounts).
update_option(RSL_Rego::SEASON_PRODUCT_OPTION, 0);
// Regos made during this test are removed at the end, whatever made them.
$rego_id_before = (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM " . RSL_Database::table('regos'));
$today = current_time('Y-m-d');
$day = function ($n) use ($today) { return date('Y-m-d', strtotime($today . ' ' . ($n < 0 ? '' : '+') . intval($n) . ' days')); };
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
// Makes a rego insert fail (as against the old table mid-upgrade); set before the try, so the
// cleanup can always take it off.
$block_regos = function ($q) {
    $t = '`' . RSL_Database::table('regos') . '`';
    return stripos(ltrim((string) $q), 'INSERT INTO ' . $t) === 0 ? str_replace($t, '`' . RSL_Database::table('regos') . '_unsaved`', $q) : $q;
};
$quiet = null;

try {

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

// --- Season regos (0.15.0) --------------------------------------------------------------------------------
// Far-future seasons, every date from $today.
$AHEAD = '2094-mid1';  // today+5 to today+50
$NOW = '2093-post';    // today-10 to today+40
$MOVE = '2093-mid2';   // today-30 to today-2 (its dates move later below)
$SHRINK = '2094-mid2'; // today+5 to today+50 (shortened below)
RSL_Courts::save_settings($AHEAD, array('start' => $day(5), 'end' => $day(50)));
RSL_Courts::save_settings($NOW, array('start' => $day(-10), 'end' => $day(40)));
RSL_Courts::save_settings($MOVE, array('start' => $day(-30), 'end' => $day(-2)));
RSL_Courts::save_settings($SHRINK, array('start' => $day(5), 'end' => $day(50)));
$season_rego = $make_product("Season rego test $suffix", 15);
update_option(RSL_Rego::SEASON_PRODUCT_OPTION, $season_rego->get_id());
$row_for = function ($item) use ($wpdb) { return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . RSL_Database::table('regos') . " WHERE order_item_id = %d", $item->get_id())); };
$rows_of = function ($order) use ($wpdb) { return $wpdb->get_results($wpdb->prepare("SELECT * FROM " . RSL_Database::table('regos') . " WHERE order_id = %d", $order->get_id())); };
$full = function ($item) { return round((float) $item->get_total() + (float) $item->get_total_tax(), 2); };

$check('the season rego is sold one at a time, at its own price', wc_get_product($season_rego->get_id())->is_sold_individually() && RSL_Rego::price_label('season') === '$15', RSL_Rego::price_label('season'));

$p6 = $mk('p6');
$so = $make_order($season_rego, $p6, 15, array(RSL_Rego::SEASON_META => $AHEAD));
$si = $item_of($so);
$so->update_status('processing');
$g = $row_for($si);
$check('set-up: a season rego bought ahead', $g && $g->season === $AHEAD && $g->start_date === $day(5) && $g->end_date === $day(50), wp_json_encode($g));
$line_refund($so, $si, $full($si), 1);
$g = $row_for($si);
$check('refunding a season rego bought ahead ends it whole (it hadn\'t started), noted on the order', $g && RSL_Rego::is_removed($g) && strpos($g->note, 'Refunded') !== false
    && $notes(wc_get_order($so->get_id()), 'social rego ended') === 1 && !RSL_Rego::clearance_for($p6, $AHEAD)['cleared'], wp_json_encode($g));

$p7 = $mk('p7');
$so = $make_order($season_rego, $p7, 15, array(RSL_Rego::SEASON_META => $NOW));
$si = $item_of($so);
$so->update_status('processing');
$g = $row_for($si);
$check('set-up: a running season rego, from today to its last night', $g && $g->start_date === $today && $g->end_date === $day(40) && RSL_Rego::clearance($p7)['cleared'], wp_json_encode($g));
wc_get_order($so->get_id())->update_status('cancelled');
$g = $row_for($si);
$check('cancelled: it ends before today, not cleared', $g->end_date < $today && !RSL_Rego::clearance($p7)['cleared'] && !RSL_Rego::clearance_for($p7, $NOW)['cleared'], wp_json_encode($g));
wc_get_order($so->get_id())->update_status('processing');
$g = $row_for($si);
$check('paid again: the end it was granted comes back (not 12 months), with a note', $g->end_date === $day(40) && $g->granted_end === $day(40) && RSL_Rego::clearance($p7)['cleared']
    && $notes(wc_get_order($so->get_id()), 'social rego given back') === 1, wp_json_encode($g));
wc_get_order($so->get_id())->update_status('cancelled');
$check('...and cancelled again, it ends again', !RSL_Rego::clearance($p7)['cleared']);

$p8 = $mk('p8');
$so = $make_order($rego, $p8, 55);
$sline = new WC_Order_Item_Product();
$sline->set_product($season_rego);
$sline->set_quantity(1);
$sline->set_subtotal(15);
$sline->set_total(15);
$sline->add_meta_data(RSL_Rego::SEASON_META, $AHEAD, true);
$so->add_item($sline);
$so->calculate_totals();
$so->save();
$yi = $item_of($so);
$so->update_status('processing');
$gy = $row_for($yi);
$gs = $row_for($sline);
$check('one order, a yearly and a season line: two regos, the yearly from today for 12 months, the season from its first night (no chaining)',
    $gy && $gs && $gy->season === '' && $gy->start_date === $today && $gy->end_date === date('Y-m-d', strtotime($today . ' +1 year -1 day'))
    && $gs->season === $AHEAD && $gs->start_date === $day(5) && $gs->end_date === $day(50), wp_json_encode(array($gy, $gs)));
$line_refund($so, $sline, $full($sline), 1);
$check('refunding the season line ends only that rego', RSL_Rego::is_removed($row_for($sline)) && !RSL_Rego::is_removed($row_for($yi))
    && $row_for($yi)->end_date === $gy->end_date && RSL_Rego::clearance($p8)['cleared']);

$p9 = $mk('p9');
$so = $make_order($rego, $p9, 55);
$si = $item_of($so);
$so->update_status('processing');
$g = $row_for($si);
$wpdb->update(RSL_Database::table('regos'), array('granted_end' => null), array('id' => $g->id)); // as on a row from before schema 11
wc_get_order($so->get_id())->update_status('cancelled');
wc_get_order($so->get_id())->update_status('processing');
$g = $row_for($si);
$check('a yearly rego from before schema 11 (no granted end), cancelled and paid again: 12 months back', $g->granted_end === null
    && $g->end_date === date('Y-m-d', strtotime($g->start_date . ' +1 year -1 day')), wp_json_encode($g));

// A row kept counting by its season's moved dates can still be ended (and given back).
$p10 = $mk('p10');
$so = $make_order($season_rego, $p10, 15, array(RSL_Rego::SEASON_META => $MOVE));
$si = $item_of($so);
$so = wc_get_order($so->get_id());
$so->set_date_paid($day(-20) . ' 12:00:00'); // site time
$so->save();
$so->update_status('processing');
$g = $row_for($si);
$check('set-up: a season rego paid during a season that is now over', $g && $g->start_date === $day(-20) && $g->end_date === $day(-2) && $g->granted_end === $day(-2)
    && !RSL_Rego::clearance_for($p10, $MOVE)['cleared'], wp_json_encode($g));
RSL_Courts::save_settings($MOVE, array('start' => $day(-30), 'end' => $day(10)));
$c = RSL_Rego::clearance_for($p10, $MOVE);
$check('a later last night set after the sale: the paid row counts until the season\'s new last night', $c['cleared'] && $c['until'] === $day(10)
    && RSL_Rego::row_counts($row_for($si)), wp_json_encode($c));
wc_get_order($so->get_id())->update_status('cancelled');
$g = $row_for($si);
$check('cancelled: ended by moving its end back a day (below its granted end), not cleared, noted', $g->end_date === $day(-3) && $g->granted_end === $day(-2)
    && !RSL_Rego::clearance_for($p10, $MOVE)['cleared'] && $notes(wc_get_order($so->get_id()), 'social rego ended') === 1, wp_json_encode($g));
wc_get_order($so->get_id())->update_status('processing');
$g = $row_for($si);
$check('paid again: its granted end back, cleared again, noted', $g->end_date === $day(-2) && $g->granted_end === $day(-2) && RSL_Rego::clearance_for($p10, $MOVE)['cleared']
    && $notes(wc_get_order($so->get_id()), 'social rego given back') === 1, wp_json_encode($g));

// No false "no rego was given" when the dates shrink after the sale.
$p11 = $mk('p11');
$so = $make_order($season_rego, $p11, 15, array(RSL_Rego::SEASON_META => $SHRINK));
$si = $item_of($so);
$so->update_status('processing');
$was = $row_for($si);
RSL_Courts::save_settings($SHRINK, array('start' => $day(-20), 'end' => $day(-1)));
wc_get_order($so->get_id())->update_status('completed');
$g = $row_for($si);
$check('dates shortened after the sale: completing the order changes nothing and notes nothing', $was && $was->start_date === $day(5) && $was->end_date === $day(50)
    && count($rows_of($so)) === 1 && $g->start_date === $was->start_date && $g->end_date === $was->end_date && $notes(wc_get_order($so->get_id()), 'which finished on') === 0);

// The past-order scan never guesses a season; a live payment uses the sign-up season.
update_option(RSL_Seasons::SIGNUP_OPTION, $AHEAD);
$p12 = $mk('p12');
$so = $make_order($season_rego, $p12, 15);
remove_action('woocommerce_order_status_processing', array('RSL_Rego', 'on_order_paid'));
wc_get_order($so->get_id())->update_status('processing');
add_action('woocommerce_order_status_processing', array('RSL_Rego', 'on_order_paid'));
RSL_Rego::on_order_paid($so->get_id(), true);
RSL_Rego::on_order_paid($so->get_id(), true);
$check('a season line that doesn\'t say its season, from the scan: no rego, one note', count($rows_of($so)) === 0 && $notes(wc_get_order($so->get_id()), 'say which season') === 1);
RSL_Rego::scan_orders();
$check('...and the scan itself gives none', count($rows_of($so)) === 0);
RSL_Rego::on_order_paid($so->get_id());
$rows = $rows_of($so);
$check('...while a live payment uses the sign-up season', count($rows) === 1 && $rows[0]->season === $AHEAD && $rows[0]->start_date === $day(5), wp_json_encode($rows));

// A season rego refunded after its season's last night is cut short anyway, so it stays ended
// when that last night moves later afterwards (postponed finals).
$DONE = '2093-mid1'; // today-40 to today-5 (its end moves later below)
RSL_Courts::save_settings($DONE, array('start' => $day(-40), 'end' => $day(-5)));
$p13 = $mk('p13');
$so = $make_order($season_rego, $p13, 15, array(RSL_Rego::SEASON_META => $DONE));
$si = $item_of($so);
$so = wc_get_order($so->get_id());
$so->set_date_paid($day(-30) . ' 12:00:00'); // site time
$so->save();
$so->update_status('processing');
$g = $row_for($si);
$check('set-up: a season rego for a season that has finished', $g && $g->start_date === $day(-30) && $g->end_date === $day(-5) && $g->granted_end === $day(-5), wp_json_encode($g));
$line_refund($so, $si, $full($si), 1);
$g = $row_for($si);
$check('refunded after its season\'s last night: cut a day (below its granted end), noted on the row and the order', $g->end_date === $day(-6) && $g->granted_end === $day(-5)
    && strpos($g->note, 'Refunded') !== false && $notes(wc_get_order($so->get_id()), 'social rego ended') === 1, wp_json_encode($g));
RSL_Courts::save_settings($DONE, array('start' => $day(-40), 'end' => $day(10)));
$check('...then the season\'s last night moves later: the refunded rego still doesn\'t count (not cleared, not covered)', !RSL_Rego::row_counts($row_for($si))
    && !RSL_Rego::clearance_for($p13, $DONE)['cleared'] && !RSL_Rego::season_covered($p13, $DONE));

// A rego that can't be saved (a payment while another request upgrades the tables): none given,
// one order note saying how to give it; the past-order scan then gives it.
$p14 = $mk('p14');
$ro14 = $make_order($rego, $p14, 55);
$quiet = $wpdb->suppress_errors(true);
add_filter('query', $block_regos);
wc_get_order($ro14->get_id())->update_status('processing');
wc_get_order($ro14->get_id())->update_status('completed');
remove_filter('query', $block_regos);
$wpdb->suppress_errors($quiet);
$quiet = null;
$check('a rego that can\'t be saved: none given, one note on the order saying how to give it', count($rows_of($ro14)) === 0
    && $notes(wc_get_order($ro14->get_id()), 'none was given. Pick up rego purchases') === 1);
RSL_Rego::on_order_paid($ro14->get_id(), true);
$check('...and the past-order scan gives it', count($rows_of($ro14)) === 1 && RSL_Rego::clearance($p14)['cleared']
    && $notes(wc_get_order($ro14->get_id()), '12-month social rego given') === 1);
wp_set_current_user($admin);

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

} catch (Throwable $t) {
    $fails++;
    echo 'FAIL: threw ' . get_class($t) . ': ' . $t->getMessage() . ' (line ' . $t->getLine() . ")\n";
} finally {
// --- Cleanup (runs after a failure too) ----------------------------------------------------------------------
$_GET = array();
wp_set_current_user(0);
remove_filter('pre_wp_mail', '__return_true');
remove_filter('rsl_membership_source_available', '__return_false');
remove_filter('query', $block_regos);
if ($quiet !== null) {
    $wpdb->suppress_errors($quiet);
}
if (!has_action('woocommerce_order_status_processing', array('RSL_Rego', 'on_order_paid'))) {
    add_action('woocommerce_order_status_processing', array('RSL_Rego', 'on_order_paid'));
}
foreach ($orders as $o) {
    $o = wc_get_order($o->get_id());
    if (!$o) {
        continue;
    }
    foreach ($o->get_refunds() as $refund) { $refund->delete(true); }
    $wpdb->delete(RSL_Database::table('regos'), array('order_id' => $o->get_id()));
    $o->delete(true);
}
foreach ($products as $p) { $p->delete(true); }
if ($team && !is_wp_error($team)) {
    $wpdb->delete(RSL_Database::table('ledger'), array('team_id' => $team->id));
    $wpdb->delete(RSL_Database::table('team_members'), array('team_id' => $team->id));
    $wpdb->delete(RSL_Database::table('teams'), array('id' => $team->id));
}
foreach ($users as $u) {
    $wpdb->delete(RSL_Database::table('regos'), array('user_id' => $u));
    delete_transient(RSL_Portal::FLASH_PREFIX . $u);
    delete_transient(RSL_Admin::NOTICE_PREFIX . $u);
    wp_delete_user($u);
}
$wpdb->query($wpdb->prepare("DELETE FROM " . RSL_Database::table('regos') . " WHERE id > %d", $rego_id_before));
foreach ($saved as $opt => $value) {
    if ($value === '__unset__') { delete_option($opt); } else { update_option($opt, $value); }
}
}
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
