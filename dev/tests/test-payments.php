<?php
/**
 * Team balances: game fees charged when fixtures are published, "can't
 * make it" before/after the cut-off, not fixtured when owing too much,
 * online top-ups (WooCommerce), admin adjustments, who sees what.
 *
 *   wp eval-file dev/tests/test-payments.php
 */

if (!defined('WP_CLI') || !WP_CLI) {
    exit("Run with wp eval-file.\n");
}
require_once ABSPATH . 'wp-admin/includes/user.php';
global $wpdb;

$season = '2089-pre';
$suffix = time();
$fails = 0;
$check = function ($label, $ok, $detail = '') use (&$fails) {
    if (!$ok) { $fails++; }
    echo ($ok ? "OK: $label\n" : "FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n");
};
$saved = array(
    'current' => get_option(RSL_Seasons::CURRENT_OPTION, '__unset__'),
    'settings' => get_option(RSL_Payments::SETTINGS_OPTION, '__unset__'),
    'product' => get_option(RSL_Payments::PRODUCT_OPTION, '__unset__'),
);
update_option(RSL_Seasons::CURRENT_OPTION, $season);
delete_option(RSL_Payments::SETTINGS_OPTION);
add_filter('pre_wp_mail', '__return_true');
$parked = $wpdb->get_col("SELECT id FROM " . RSL_Database::table('venues') . " WHERE active = 1");
if ($parked) { $wpdb->query("UPDATE " . RSL_Database::table('venues') . " SET active = 0 WHERE id IN (" . implode(',', array_map('intval', $parked)) . ")"); }

$hall = RSL_Courts::create_venue("Pay Hall $suffix", 'ranked');
foreach (array('P1', 'P2') as $c) { RSL_Courts::create_court($hall->id, $c, '19:00', '22:00'); }
$today = current_time('Y-m-d');
RSL_Courts::save_settings($season, array('start' => date('Y-m-d', strtotime($today . ' +5 days')), 'end' => date('Y-m-d', strtotime($today . ' +30 days')), 'weekday' => 0, 'minutes' => array('ranked' => 60, 'unranked' => 60)));
RSL_Courts::generate_nights($season);
$nights = RSL_Courts::nights($season);
$users = array();
$mk = function ($tag, $role = 'subscriber') use ($suffix, &$users) {
    $u = wp_insert_user(array('user_login' => "rslm-$tag-$suffix", 'user_email' => "rslm-$tag-$suffix@example.test", 'user_pass' => wp_generate_password(), 'display_name' => "RSLM $tag", 'role' => $role));
    $users[] = $u;
    return $u;
};
$teams = array();
$caps = array();
foreach (array('A', 'B', 'C', 'D') as $i => $n) {
    $teams[$i] = RSL_Teams::create($season, array('name' => "Pay $n $suffix", 'division' => 'ranked', 'level' => 'solid'));
    $caps[$i] = $mk("cap$i");
    RSL_Teams::add_member($teams[$i]->id, $caps[$i], 'captain');
}
$mate = $mk('mate');
RSL_Teams::add_member($teams[0]->id, $mate);
$outsider = $mk('outsider');
$admin = $mk('admin', 'administrator');
$T = function ($i) use ($teams) { return intval($teams[$i]->id); };
$bal = function ($i) use ($T) { return RSL_Payments::balance($T($i)); };
$c1 = intval(RSL_Courts::courts($hall->id)[0]->id);
$c2 = intval(RSL_Courts::courts($hall->id)[1]->id);
$n1 = $nights[0];

$check('default rules: $75 a game, free to pull out until 48 hours before, fixtured while owing $75 or less',
    RSL_Payments::settings()['game_fee'] == 75 && RSL_Payments::settings()['free_cancel_hours'] === 48 && RSL_Payments::settings()['max_owing'] == 75);

// --- Charged when published ------------------------------------------------------------------
$g1 = RSL_Fixtures::add_game($n1->id, array('home' => $T(0), 'away' => $T(1), 'court_id' => $c1, 'start' => '19:00'));
$g2 = RSL_Fixtures::add_game($n1->id, array('home' => $T(2), 'away' => $T(3), 'court_id' => $c2, 'start' => '19:00'));
$g3 = RSL_Fixtures::add_game($n1->id, array('home' => $T(0), 'away' => $T(2), 'court_id' => $c1, 'start' => '20:00')); // A plays twice
$check('nothing is charged before the fixtures are out', $bal(0) == 0 && $bal(1) == 0);
RSL_Fixtures::publish_night($n1->id);
$check('publishing charges every team $75 a game', $bal(1) == -75 && $bal(3) == -75);
$check('a double-header is two games', $bal(0) == -150 && $bal(2) == -150);
RSL_Fixtures::publish_night($n1->id);
$check('publishing again doesn\'t charge twice', $bal(0) == -150);
RSL_Fixtures::publish_night($n1->id, false);
$check('unpublishing takes the charges off', $bal(0) == 0 && $bal(3) == 0);
RSL_Fixtures::publish_night($n1->id);
RSL_Fixtures::delete_game($g3->id);
$check('removing a published game takes its fees off', $bal(0) == -75 && $bal(2) == -75);

// --- Owing and the fixtures ------------------------------------------------------------------
$check('owing $75 is still fixtured', !RSL_Payments::blocked($T(0)));
RSL_Payments::adjust($T(0), -75, 'Test charge', $admin);
$check('owing more than $75 is not', RSL_Payments::blocked($T(0)) && RSL_Payments::owing($T(0)) == 150);
$plan = RSL_Pairing::plan($nights[1]->id);
$check('the fixtures leave a team owing too much out, and say why', isset($plan['divisions']['ranked']['out'][$T(0)]) && strpos($plan['divisions']['ranked']['out'][$T(0)], 'Owes $150') === 0);
RSL_Pairing::generate($nights[1]->id);
$check('...recorded so it counts like a week off (no bye points or finals round)', isset(RSL_Requests::for_night($nights[1]->id, 'unpaid', array('handled'))[$T(0)]));
RSL_Fixtures::publish_night($nights[1]->id, false);
$check('an adjustment needs a note', is_wp_error(RSL_Payments::adjust($T(0), 50, '', $admin)));
RSL_Payments::adjust($T(0), 150, 'Cash at the venue', $admin);
$check('paying brings it back', !RSL_Payments::blocked($T(0)) && $bal(0) == 0);
RSL_Pairing::generate($nights[1]->id);
$check('...and remaking the fixtures includes them again', !isset(RSL_Requests::for_night($nights[1]->id, 'unpaid', array('handled'))[$T(0)]));
foreach (RSL_Fixtures::games_for_night($nights[1]->id) as $g) { RSL_Fixtures::delete_game($g->id); }

// --- "Can't make it" ------------------------------------------------------------------------------
// Before the cut-off: no fee for either team in that game.
RSL_Payments::save_settings(array('game_fee' => 75, 'free_cancel_hours' => 0, 'max_owing' => 75));
$before = array($bal(2), $bal(3));
$r = RSL_Requests::request($T(2), $n1->id, 'cant_make', $caps[2]);
$check('before the cut-off: free', !is_wp_error($r) && !$r->late && $bal(2) == $before[0] + 75 && $bal(3) == $before[1] + 75);
RSL_Requests::cancel($r->id, $caps[2]);
$check('"we can make it after all" puts the fees back', $bal(2) == $before[0] && $bal(3) == $before[1]);
// After the cut-off: the withdrawing team pays its own fee and the other team's.
RSL_Payments::save_settings(array('game_fee' => 75, 'free_cancel_hours' => 336, 'max_owing' => 75));
$r = RSL_Requests::request($T(2), $n1->id, 'cant_make', $caps[2]);
$check('after the cut-off: late', !is_wp_error($r) && (int) $r->late === 1);
$check('...the withdrawing team pays double ($150 in all)', $bal(2) == $before[0] + 75 - 150, (string) $bal(2));
$check('...the other team pays nothing for that game', $bal(3) == $before[1] + 75);
$check('...and can\'t be charged twice by asking again', is_wp_error(RSL_Requests::request($T(2), $n1->id, 'cant_make', $caps[2])));
RSL_Requests::cancel($r->id, $caps[2]);
$check('withdrawing a late one undoes the late fee', $bal(2) == $before[0] && $bal(3) == $before[1]);
RSL_Payments::save_settings(array('game_fee' => 75, 'free_cancel_hours' => 48, 'max_owing' => 75));

// --- Online top-ups -----------------------------------------------------------------------------------
$check('only the team\'s players can top up', is_wp_error(RSL_Payments::start_topup($T(0), $outsider, 75)));
$check('amounts between $5 and $2,000', is_wp_error($e = RSL_Payments::start_topup($T(0), $mate, 2)) && strpos($e->get_error_message(), '$5') !== false);
$product = new WC_Product_Simple();
$product->set_name("League top-up test $suffix");
$product->set_regular_price('1');
$product->set_virtual(true);
$product->set_status('private');
$product->save();
update_option(RSL_Payments::PRODUCT_OPTION, $product->get_id());
// League amounts include GST: a $75 top-up must cost exactly $75, whatever the shop's tax setting.
$incl = get_option('woocommerce_prices_include_tax');
update_option('woocommerce_prices_include_tax', 'yes');
$check('GST included: prices entered incl. tax — a $75 top-up is priced $75', abs(RSL_Payments::cart_price($product, 75) - 75) < 0.001);
update_option('woocommerce_prices_include_tax', 'no');
$ex = RSL_Payments::cart_price($product, 75);
$tax = array_sum(WC_Tax::calc_tax($ex, WC_Tax::get_rates($product->get_tax_class()), false));
$check('GST included: prices entered excl. tax — priced so the total with GST is still $75', !wc_tax_enabled() || abs(round($ex + $tax, 2) - 75) < 0.011, $ex . ' + ' . $tax);
update_option('woocommerce_prices_include_tax', $incl);
$order = wc_create_order(array('customer_id' => $mate));
$item = new WC_Order_Item_Product();
$item->set_product($product);
$item->set_quantity(1);
$item->set_subtotal(120);
$item->set_total(120);
$item->add_meta_data('_rsl_topup_team', $T(1), true);
$item->add_meta_data('_rsl_topup_user', $mate, true);
$order->add_item($item);
$order->calculate_totals();
$order->save();
$paid = (float) $order->get_total(); // what the customer actually paid (incl. any GST)
$b1 = $bal(1);
$order->update_status('processing');
$check('a paid top-up is credited to the team (what was actually paid)', abs($bal(1) - ($b1 + $paid)) < 0.01, $bal(1) . ' vs ' . ($b1 + $paid));
$order->update_status('completed');
$check('...once (processing then completed)', abs($bal(1) - ($b1 + $paid)) < 0.01);
$who = RSL_Payments::contributions($T(1));
$check('the payer is recorded', $who && intval($who[0]->user_id) === $mate && abs(floatval($who[0]->total) - $paid) < 0.01);

// --- Who sees it ----------------------------------------------------------------------------------------
wp_set_current_user(0);
$check('the public never sees a team\'s money', RSL_Payments::team_card(RSL_Teams::get($T(1))) === '');
wp_set_current_user($outsider);
$check('nor do other players', RSL_Payments::team_card(RSL_Teams::get($T(1))) === '');
wp_set_current_user($mate);
$card = RSL_Payments::team_card(RSL_Teams::get($T(0)));
$check('team players see the balance and can pay', strpos($card, 'Team balance') !== false && strpos($card, 'name="rsl_topup"') !== false);
$check('...but only the captain sees who has paid', strpos($card, 'Who\'s paid') === false);
wp_set_current_user($caps[1]);
$card = RSL_Payments::team_card(RSL_Teams::get($T(1)));
$check('the captain sees who has paid', strpos($card, 'Who\'s paid') !== false && strpos($card, 'RSLM mate') !== false);
RSL_Payments::adjust($T(3), -200, 'Test owing', $admin);
wp_set_current_user($caps[3]);
$check('a team owing too much is told it won\'t be fixtured', strpos(RSL_Payments::team_card(RSL_Teams::get($T(3))), 'won\'t be fixtured') !== false);

// --- Admin ------------------------------------------------------------------------------------------------
wp_set_current_user($admin);
RSL_Admin_Payments::process(array('rsl_nonce' => wp_create_nonce('rsl_payments_action'), 'do' => 'adjust', 'team_id' => $T(3), 'amount' => '200', 'note' => 'Bank transfer'));
$check('admin records a payment with a note', $bal(3) > -200);
$_GET = array('page' => 'rsl-balances');
ob_start(); (new RSL_Admin_Payments())->render(); $html = ob_get_clean();
$check('admin overview lists every team\'s balance and the rules', strpos($html, "Pay A $suffix") !== false && strpos($html, 'Top-up product') !== false);
$_GET = array('page' => 'rsl-balances', 'team' => $T(1));
ob_start(); (new RSL_Admin_Payments())->render(); $html = ob_get_clean();
$check('a team\'s history shows game fees and payments', strpos($html, 'Game fee') !== false && strpos($html, 'Topped up by RSLM mate') !== false);

// --- Cleanup ---------------------------------------------------------------------------------------------
$_GET = array();
wp_set_current_user(0);
remove_filter('pre_wp_mail', '__return_true');
$order->delete(true);
$product->delete(true);
foreach ($teams as $t) {
    $wpdb->delete(RSL_Database::table('requests'), array('team_id' => $t->id));
    $wpdb->delete(RSL_Database::table('team_members'), array('team_id' => $t->id));
    $wpdb->delete(RSL_Database::table('teams'), array('id' => $t->id));
}
$wpdb->delete(RSL_Database::table('ledger'), array('season' => $season));
$wpdb->delete(RSL_Database::table('games'), array('season' => $season));
foreach (RSL_Courts::nights($season) as $n) {
    $wpdb->delete(RSL_Database::table('night_courts'), array('night_id' => $n->id));
}
$wpdb->delete(RSL_Database::table('nights'), array('season' => $season));
$settings = get_option(RSL_Courts::SETTINGS_OPTION, array());
unset($settings[$season]);
update_option(RSL_Courts::SETTINGS_OPTION, $settings, false);
$wpdb->delete(RSL_Database::table('courts'), array('venue_id' => $hall->id));
$wpdb->delete(RSL_Database::table('venues'), array('id' => $hall->id));
if ($parked) { $wpdb->query("UPDATE " . RSL_Database::table('venues') . " SET active = 1 WHERE id IN (" . implode(',', array_map('intval', $parked)) . ")"); }
foreach ($users as $u) {
    delete_transient(RSL_Portal::FLASH_PREFIX . $u);
    delete_transient(RSL_Admin::NOTICE_PREFIX . $u);
    wp_delete_user($u);
}
foreach (array('current' => RSL_Seasons::CURRENT_OPTION, 'settings' => RSL_Payments::SETTINGS_OPTION, 'product' => RSL_Payments::PRODUCT_OPTION) as $k => $opt) {
    if ($saved[$k] === '__unset__') { delete_option($opt); } else { update_option($opt, $saved[$k], false); }
}
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
