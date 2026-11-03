<?php
/**
 * Registration, social rego and team-building paths:
 *  - registering (free) vs being cleared to play (full/life member, or a
 *    season or 12-month rego bought through WooCommerce or added by an admin);
 *  - captain applications: pending until an admin approves or declines;
 *  - joining with the invite code (instant) or by email invite (must accept);
 *  - privacy: captains see ✓/✗ only, never why; invitees aren't shown;
 *  - the season rego (0.15.0): the season chosen, carried through the cart
 *    and the order, its cover, the refusals (at add-to-cart and checkout),
 *    the league page, the rosters and the admin screen. Throwaway products,
 *    orders, seasons and players only (the yearly rego too);
 *  - Start a team at registration in one step and the season pickers
 *    (0.17.0): success, a double submit, failures that open the card with
 *    what was typed, seasons that aren't open, the card's old field names,
 *    later seasons on the league page and their invite links;
 *  - (0.18.0) nothing open for sign-up refuses a team application or a free
 *    agent entry, and the runs-out-soon nudge after a season moved later.
 *
 *   wp eval-file dev/tests/test-registration.php
 */

if (!defined('WP_CLI') || !WP_CLI || (wp_get_environment_type() === 'production' && !getenv('RSL_DEV'))) {
    exit("Local copy only: run with wp eval-file, with RSL_DEV=1 set (docker exec -e RSL_DEV=1 ...).\n");
}
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once dirname(__DIR__) . '/lib-profile.php';
global $wpdb;

$season = '2091-pre';
$suffix = time();
$fails = 0;
$check = function ($label, $ok, $detail = '') use (&$fails) {
    if (!$ok) { $fails++; }
    echo ($ok ? "OK: $label\n" : "FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n");
};
$saved = array();
foreach (array(RSL_Seasons::CURRENT_OPTION, RSL_Seasons::SIGNUP_OPTION, RSL_Rego::PRODUCT_OPTION, RSL_Rego::SEASON_PRODUCT_OPTION, RSL_Courts::SETTINGS_OPTION,
    RSL_Payments::PRODUCT_OPTION, 'woocommerce_prices_include_tax') as $opt) { $saved[$opt] = get_option($opt, '__unset__'); }
// The yearly-only checks below mean what they say whatever the site has set.
update_option(RSL_Rego::SEASON_PRODUCT_OPTION, 0);
// Regos made during this test are removed at the end, whatever made them (the
// past-order scan only ever sees this file's throwaway products).
$rego_id_before = (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM " . RSL_Database::table('regos'));
update_option(RSL_Seasons::CURRENT_OPTION, $season);
delete_option(RSL_Seasons::SIGNUP_OPTION);
$sent = array();
$mail = function ($null, $atts) use (&$sent) { $sent[] = $atts; return true; };
add_filter('pre_wp_mail', $mail, 10, 2);

$users = array();
$mk = function ($tag, $role = 'subscriber') use ($suffix, &$users) {
    $u = wp_insert_user(array('user_login' => "rslr-$tag-$suffix", 'user_email' => "rslr-$tag-$suffix@example.test", 'user_pass' => wp_generate_password(),
        'display_name' => "RSLR $tag $suffix", 'role' => $role));
    if (is_wp_error($u)) { // e.g. a tag used twice: stop here (the cleanup only ever sees real account ids)
        throw new Exception("Couldn't make the test account $tag: " . $u->get_error_message());
    }
    $users[] = $u;
    rsl_dev_profile($u);
    return $u;
};
$reg = function ($u) { return RSL_Players::register($u, array('level' => 'social', 'years' => '1to3', 'positions' => array('setter', 'bogus'))); };
$today = current_time('Y-m-d');
// Every new date is worked out from the site's today, never the server clock.
$day = function ($n) use ($today) { return date('Y-m-d', strtotime($today . ' ' . ($n < 0 ? '' : '+') . intval($n) . ' days')); };
// Set before the try, so the cleanup can always run.
$mem = $wpdb->prefix . 'team_memberships';
$order = null;
$orders = array();
$products = array();
$cands = null;
$cand_keys = array();
$test_seasons = array();
$cart_test = false;
$block_rego_insert = null; // section 26's blocked rego INSERT (0.17.0): the cleanup takes it off if it's still on
$quiet_rego = null;
// League mail this test queues must never go out from another request (wp-cron on a page load, the
// hourly job, the Free Agents screen), where the mail stop above isn't in place: the send run is
// held off a day (queue() schedules one only when none is), and so is the hourly job (it drains
// the outbox too, and on a quiet copy is often overdue), and the test's rows are parked
// (attempts at the maximum) as soon as they're queued. The cleanup deletes them and puts the
// send run and the hourly job back.
$send_was = wp_next_scheduled(RSL_Mail::SEND_HOOK);
wp_clear_scheduled_hook(RSL_Mail::SEND_HOOK);
wp_schedule_single_event(time() + DAY_IN_SECONDS, RSL_Mail::SEND_HOOK);
wp_clear_scheduled_hook('rsl_hourly');
wp_schedule_event(time() + DAY_IN_SECONDS, 'hourly', 'rsl_hourly');
$park = function () use ($wpdb, $suffix) {
    return (int) $wpdb->query($wpdb->prepare("UPDATE " . RSL_Database::table('outbox') . " SET attempts = %d WHERE sent_at IS NULL AND to_email LIKE %s",
        RSL_Mail::MAX_ATTEMPTS, '%-' . $suffix . '@example.test'));
};

try {

// --- Registering ------------------------------------------------------------------
$cap = $mk('cap'); $p1 = $mk('p1'); $p2 = $mk('p2'); $p3 = $mk('p3'); $out = $mk('out'); $admin = $mk('admin', 'administrator');
$check('registration needs a level', is_wp_error(RSL_Players::register($cap, array('years' => 'lt1'))));
$check('registration needs years', is_wp_error(RSL_Players::register($cap, array('level' => 'vvl'))));
$check('register', $reg($cap) === true && RSL_Players::is_registered($cap));
$check('unknown positions dropped', RSL_Players::get($cap)->positions === 'setter');
$check('re-registering updates, never duplicates', RSL_Players::register($cap, array('level' => 'vvl', 'years' => 'gt3')) === true
    && RSL_Players::get($cap)->level === 'vvl' && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . RSL_Database::table('players') . " WHERE user_id = %d", $cap)) === 1);
foreach (array($p1, $p2, $p3) as $u) { $reg($u); }

// --- The club profile (0.13.0) ---------------------------------------------------------
$bare = $mk('bare');
foreach (array('mobile_number', 'birth_date', 'gender_dropdown') as $k) { delete_user_meta($bare, $k); }
$no = $reg($bare);
$check('registering needs a complete club profile, and says what\'s missing', is_wp_error($no) && $no->get_error_code() === 'rsl_profile' && !RSL_Players::is_registered($bare)
    && strpos($no->get_error_message(), 'mobile, date of birth and gender') !== false, is_wp_error($no) ? $no->get_error_message() : 'registered');
update_user_meta($bare, 'mobile_number', '0491 570 156');
update_user_meta($bare, 'birth_date', 'not a date');
update_user_meta($bare, 'gender', array('Male')); // the older gender field some accounts still have
$p = RSL_Players::profile($bare);
$check('a date of birth that isn\'t a date counts as missing; the older gender field counts', $p['missing'] === array('Date of birth') && $p['values']['Gender'] === 'Male', implode(', ', $p['missing']));
update_user_meta($bare, 'birth_date', '2001/02/30');
$check('so does 30 February', RSL_Players::profile($bare)['missing'] === array('Date of birth'));
update_user_meta($bare, 'birth_date', rsl_dev_dob(0, -1)); // tomorrow on the site's clock
$check('and a date of birth in the future', RSL_Players::profile($bare)['missing'] === array('Date of birth'));
update_user_meta($bare, 'birth_date', '2001/02/03');
$check('complete: registers, and the date of birth shows back in words', $reg($bare) === true && RSL_Players::profile($bare)['values']['Date of birth'] === '3 February 2001');
delete_user_meta($bare, 'mobile_number');
$check('updating your answers later doesn\'t ask for the profile again', RSL_Players::register($bare, array('level' => 'vvl', 'years' => 'gt3')) === true && RSL_Players::get($bare)->level === 'vvl');
$was_user = get_current_user_id();
$inc = $mk('inc');
delete_user_meta($inc, 'birth_date');
wp_set_current_user($inc);
$page = do_shortcode('[rsl_my_league]');
$check('the league page asks for the missing details instead of showing the form', strpos($page, 'finish your club profile') !== false && strpos($page, 'missing your date of birth') !== false
    && strpos($page, 'name="do" value="register"') === false && strpos($page, 'um_action=edit') !== false);
$ready = $mk('ready');
wp_set_current_user($ready);
$page = do_shortcode('[rsl_my_league]');
$check('with a complete profile it shows their details above the form', strpos($page, 'Your details') !== false && strpos($page, '15 June 1995') !== false && strpos($page, '0491 570 156') !== false
    && strpos($page, 'name="do" value="register"') !== false);
$check('nobody is asked whether they\'re under 18', stripos($page, 'under 18') === false && strpos($page, 'name="under18"') === false);
wp_set_current_user($was_user);

// --- Cleared to play -----------------------------------------------------------------
$check('not cleared with no rego or membership', RSL_Rego::clearance($p1)['cleared'] === false);
$mem = $wpdb->prefix . 'team_memberships';
$have_cm = class_exists('TeamOversight_Memberships') && method_exists('TeamOversight_Memberships', 'covered_until');
$check('Club Manager provides covered_until', $have_cm);
if ($have_cm) {
    $wpdb->insert($mem, array('user_id' => $p1, 'tier' => 'full-member', 'start_date' => date('Y-m-d', strtotime('-30 days')), 'end_date' => date('Y-m-d', strtotime('+60 days')), 'source' => 'manual'));
    $wpdb->insert($mem, array('user_id' => $p1, 'tier' => 'full-member', 'start_date' => date('Y-m-d', strtotime('+61 days')), 'end_date' => date('Y-m-d', strtotime('+200 days')), 'source' => 'manual'));
    $c = RSL_Rego::clearance($p1);
    $check('full member: cleared by membership, no rego', $c['cleared'] && $c['by'] === 'member');
    $check('back-to-back membership grants count as one run', $c['until'] === date('Y-m-d', strtotime('+200 days')), (string) $c['until']);
    $wpdb->insert($mem, array('user_id' => $p2, 'tier' => 'associate-member', 'start_date' => date('Y-m-d', strtotime('-30 days')), 'end_date' => date('Y-m-d', strtotime('+60 days')), 'source' => 'manual'));
    $check('associate member: still needs the rego', RSL_Rego::clearance($p2)['cleared'] === false);
    $wpdb->insert($mem, array('user_id' => $p3, 'tier' => 'life-member', 'start_date' => '2000-01-01', 'end_date' => '2099-12-31', 'source' => 'manual'));
    $check('life member: cleared', RSL_Rego::clearance($p3)['by'] === 'member' && RSL_Rego::describe(RSL_Rego::clearance($p3)) === 'Full/life member (no expiry)');
    $check('lapsed membership: not cleared after it ends', RSL_Rego::clearance($p1, date('Y-m-d', strtotime('+201 days')))['cleared'] === false);
}
$g = RSL_Rego::grant($p2, array('start' => $today, 'source' => 'manual', 'note' => 'cash'));
$c = RSL_Rego::clearance($p2);
$check('manual rego: cleared for 12 months', $g > 0 && $c['by'] === 'rego' && $c['until'] === date('Y-m-d', strtotime($today . ' +1 year -1 day')), (string) $c['until']);
RSL_Rego::grant($p2, array('start' => $today, 'source' => 'purchase'));
$check('renewing early starts when the current rego ends', RSL_Rego::rego_valid_until($p2) === date('Y-m-d', strtotime($today . ' +2 years -1 day')));
$check('rego covers future dates within its run, not after', RSL_Rego::clearance($p2, date('Y-m-d', strtotime($today . ' +18 months')))['cleared']
    && !RSL_Rego::clearance($p2, date('Y-m-d', strtotime($today . ' +25 months')))['cleared']);

// Throwaway products and orders only: the past-order scan below must never see a real
// product's orders (it would note them and give their buyers regos for the run).
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
$make_order = function ($product, $customer, $meta = array()) use (&$orders) {
    $o = wc_create_order(array('customer_id' => $customer));
    $item = new WC_Order_Item_Product();
    $item->set_product($product);
    $item->set_quantity(1);
    $item->set_subtotal((float) $product->get_price());
    $item->set_total((float) $product->get_price());
    foreach ($meta as $k => $v) { $item->add_meta_data($k, $v, true); }
    $o->add_item($item);
    $o->calculate_totals();
    $o->save();
    $orders[] = $o;
    return $o;
};

// WooCommerce purchase -> rego, once.
$yearly = $make_product("Yearly rego test $suffix", 40);
$product_id = $yearly->get_id();
update_option(RSL_Rego::PRODUCT_OPTION, $product_id);
$order = wc_create_order(array('customer_id' => $cap));
$order->add_product(wc_get_product($product_id), 1);
$order->calculate_totals();
$order->set_status('processing');
$order->save();
$check('paid order: 12-month rego for the buyer', RSL_Rego::clearance($cap)['by'] === 'rego' && RSL_Rego::rego_valid_until($cap) === date('Y-m-d', strtotime($today . ' +1 year -1 day')), (string) RSL_Rego::rego_valid_until($cap));
$order->set_status('completed');
$order->save();
$check('processing then completed grants once', (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . RSL_Database::table('regos') . " WHERE user_id = %d", $cap)) === 1);
RSL_Rego::scan_orders();
$check('past-order scan doesn\'t grant the same order twice', (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . RSL_Database::table('regos') . " WHERE user_id = %d", $cap)) === 1);
$check('running the scan again adds nothing', RSL_Rego::scan_orders() === 0);
$check('pay link goes to checkout with the rego', strpos(RSL_Rego::pay_url(), 'add-to-cart=' . $product_id) !== false);

// --- Captain applications ---------------------------------------------------------------
$check('unregistered people can\'t apply', is_wp_error(RSL_Teams::apply($season, $out, array('name' => "X $suffix", 'level' => 'social'))));
$check('applying needs a level', ($e = RSL_Teams::apply($season, $cap, array('name' => "No Level $suffix"))) && is_wp_error($e) && $e->get_error_code() === 'rsl_level');
$app = RSL_Teams::apply($season, $cap, array('name' => "Spikes $suffix", 'division' => 'ranked', 'colour' => '#8c2361', 'level' => 'strong'));
$check('captain applies: team pending, captain on it', !is_wp_error($app) && $app->status === 'pending' && RSL_Teams::is_captain($app->id, $cap));
$check('pending team isn\'t public', !in_array("Spikes $suffix", wp_list_pluck(RSL_Teams::list_for_season($season), 'name'), true));
$check('no invites before approval', is_wp_error(RSL_Teams::invite_by_email($app->id, $cap, "rslr-p1-$suffix@example.test")));
$check('nobody can join a pending team with its code', RSL_Teams::get_by_code($app->invite_code) === null);
$check('a pending captain can\'t join another team', ($e = RSL_Teams::why_cant_join($cap, $season)) && $e->get_error_code() === 'rsl_pending');
$check('the level is stored with the application', $app->est_level === 'strong');
$check('one application at a time', is_wp_error(RSL_Teams::apply($season, $cap, array('name' => "Second $suffix", 'level' => 'social'))));
$check('withdraw frees the captain', RSL_Teams::withdraw($app->id, $cap) === true && RSL_Teams::why_cant_join($cap, $season) === null && !RSL_Teams::is_member($app->id, $cap));
$app = RSL_Teams::apply($season, $cap, array('name' => "Spikes Again $suffix", 'division' => 'ranked', 'level' => 'solid'));
wp_set_current_user($admin);
$sent = array();
RSL_Admin::process(array('rsl_nonce' => wp_create_nonce('rsl_team_action'), 'season' => $season, 'do' => 'approve', 'team_id' => $app->id));
$team = RSL_Teams::get($app->id);
$check('admin approves: team active', $team->status === 'active');
$check('captain emailed the invite link', count($sent) === 1 && $sent[0]['to'] === "rslr-cap-$suffix@example.test" && strpos($sent[0]['message'], 'join=' . $team->invite_code) !== false);
$declined_by = $mk('dec'); $reg($declined_by);
$app2 = RSL_Teams::apply($season, $declined_by, array('name' => "Nope $suffix", 'level' => 'new'));
$sent = array();
RSL_Admin::process(array('rsl_nonce' => wp_create_nonce('rsl_team_action'), 'season' => $season, 'do' => 'decline', 'team_id' => $app2->id, 'note' => wp_slash("Name's already taken")));
$check('admin declines with a reason, captain emailed it', RSL_Teams::get($app2->id)->status === 'declined' && strpos(html_entity_decode($sent[0]['message'], ENT_QUOTES, 'UTF-8'), "Name's already taken") !== false);
$check('declined captain is free and sees why', RSL_Teams::why_cant_join($declined_by, $season) === null && RSL_Teams::last_decided_application($declined_by, $season)->decision_note === "Name's already taken");

// --- Joining with the code ---------------------------------------------------------------
$check('unregistered can\'t join with a code', is_wp_error(RSL_Teams::join_by_code($out, $team->invite_code)));
$check('bad code refused', is_wp_error(RSL_Teams::join_by_code($p3, 'NOPE1234')));
$joined = RSL_Teams::join_by_code($p3, strtolower($team->invite_code));
$check('join with the code (any case): straight onto the team', !is_wp_error($joined) && RSL_Teams::is_member($team->id, $p3));
$old = $team->invite_code;
RSL_Teams::regenerate_code($team->id);
$check('a new code stops the old link', is_wp_error(RSL_Teams::join_by_code($p1, $old)));

// --- Invites by email -----------------------------------------------------------------------
$e = RSL_Teams::invite_by_email($team->id, $cap, "rslr-out-$suffix@example.test");
$check('invite an unregistered email: same answer as a registered one (no way to tell who\'s registered)', is_int($e) && $e > 0);
$check('it waits for them to register (they see it, can\'t accept yet)', count(RSL_Teams::pending_invites_for_user($out)) === 1 && is_wp_error(RSL_Teams::respond_invite($e, $out, true)));
$check('a bad email is refused', is_wp_error(RSL_Teams::invite_by_email($team->id, $cap, 'not an email')));
$check('only the captain can invite', is_wp_error(RSL_Teams::invite_by_email($team->id, $p3, "rslr-p1-$suffix@example.test")));
$sent = array();
$inv = RSL_Teams::invite_by_email($team->id, $cap, "rslr-p1-$suffix@example.test");
$check('invite a registered player: pending, not on the team yet', is_int($inv) && !RSL_Teams::is_member($team->id, $p1));
$check('the player is emailed', count($sent) === 1 && $sent[0]['to'] === "rslr-p1-$suffix@example.test");
$check('no double invites', is_wp_error(RSL_Teams::invite_by_email($team->id, $cap, "rslr-p1-$suffix@example.test")));
$team_invites = RSL_Teams::pending_invites_for_team($team->id);
$check('captain sees only the emails they typed, both the same way', count($team_invites) === 2 && $team_invites[1]->label === "rslr-p1-$suffix@example.test"
    && $team_invites[0]->label === "rslr-out-$suffix@example.test" && !isset($team_invites[1]->display_name));
$check('the player sees the invite', count(RSL_Teams::pending_invites_for_user($p1)) === 1);
$check('someone else can\'t accept it', is_wp_error(RSL_Teams::respond_invite($inv, $p2, true)));
$check('accepting puts them on the team', RSL_Teams::respond_invite($inv, $p1, true) === true && RSL_Teams::is_member($team->id, $p1));
$reg($p2);
$inv2 = RSL_Teams::invite_by_email($team->id, $cap, "rslr-p2-$suffix@example.test");
$check('declining leaves them off', RSL_Teams::respond_invite($inv2, $p2, false) === true && !RSL_Teams::is_member($team->id, $p2));
$inv3 = RSL_Teams::invite_by_email($team->id, $cap, "rslr-p2-$suffix@example.test");
$check('captain can cancel an invite', RSL_Teams::cancel_invite($inv3, $cap) === true && count(RSL_Teams::pending_invites_for_user($p2)) === 0);
$sent = array();
$mate = RSL_Teams::invite_by_email($team->id, $cap, "rslr-p3-$suffix@example.test");
$check('a teammate\'s email gets the same answer too (no way to tell who\'s on the team)', is_int($mate) && $mate > 0 && count($sent) === 0
    && !array_filter(RSL_Teams::pending_invites_for_user($p3), function ($i) use ($mate) { return intval($i->id) === $mate; }));
if (is_int($mate)) { RSL_Teams::cancel_invite($mate, $cap); }

// --- The league page ----------------------------------------------------------------------------
$portal = new RSL_Portal();
RSL_Portal::ensure_page();
$page = function ($as, $join = '') use ($portal) {
    wp_set_current_user($as);
    $_GET = $join !== '' ? array('join' => $join) : array();
    return $portal->render();
};
$code = RSL_Teams::get($team->id)->invite_code;
$html = $page(0, $code);
$check('logged out with an invite: team named, log in offered', strpos($html, "invited to join Spikes Again $suffix") !== false && strpos($html, 'Log in') !== false);
$new = $mk('new');
$html = $page($new, $code);
$check('logged in, not registered: registration form that joins the team', strpos($html, 'name="do" value="register"') !== false && strpos($html, 'Register and join') !== false && strpos($html, 'value="' . $code . '"') !== false);
wp_set_current_user($new);
RSL_Portal::process(array('rsl_nonce' => wp_create_nonce('rsl_front'), 'do' => 'register', 'level' => 'never', 'years' => 'lt1', 'join_code' => $code));
$check('registering through the invite joins the team', RSL_Players::is_registered($new) && RSL_Teams::is_member($team->id, $new));
$html = $page($new);
$check('not cleared: told to pay, with the pay link', strpos($html, 'You need a social rego before your first game') !== false && strpos($html, 'add-to-cart=' . $product_id) !== false);
// On a team this season and on next season's list too: they play now, so
// it's still "pay before your first game", not "no rush".
$next_season = RSL_Seasons::next($season);
RSL_Seasons::set_signup($next_season);
$fa_next = RSL_Free_Agents::join($new, $next_season, array('division_pref' => 'unranked'), true);
$html = $page($new);
RSL_Seasons::set_signup('');
$wpdb->delete(RSL_Database::table('free_agents'), array('season' => $next_season, 'user_id' => $new));
$check('on a team now and on next season\'s list: still told to pay before their first game', !is_wp_error($fa_next)
    && strpos($html, 'You need a social rego before your first game') !== false && strpos($html, 'no rush') === false);
$cap_view = $page($cap);
$roster = substr($cap_view, strpos($cap_view, '<h4>Players'), strpos($cap_view, '<h4>Add players') - strpos($cap_view, '<h4>Players'));
$check('captain: teammates show cleared / needs rego', strpos($roster, '&#10003; Cleared') !== false && strpos($roster, '&#10007; Needs rego') !== false);
$check('captain never sees why (membership vs rego)', stripos($roster, 'Full/life') === false && stripos($roster, 'membership') === false && strpos($roster, 'Rego paid') === false && strpos($roster, 'until') === false);
$check('captain tools: invite link and email invite', strpos($cap_view, 'join=' . $code) !== false && strpos($cap_view, 'name="do" value="invite"') !== false);
$p3_view = $page($p3);
$check('a player (not captain) sees teammates but no statuses or tools', strpos($p3_view, "RSLR p1 $suffix") !== false && strpos($p3_view, 'Needs rego</span>') === false && strpos($p3_view, 'value="invite"') === false);
wp_set_current_user($p3);
RSL_Portal::process(array('rsl_nonce' => wp_create_nonce('rsl_front'), 'do' => 'remove_member', 'team_id' => $team->id, 'user_id' => $p1));
$check('non-captain can\'t remove players (server-side)', RSL_Teams::is_member($team->id, $p1));
wp_set_current_user($cap);
RSL_Portal::process(array('rsl_nonce' => wp_create_nonce('rsl_front'), 'do' => 'remove_member', 'team_id' => $team->id, 'user_id' => $cap));
$check('captain can\'t remove themselves', RSL_Teams::is_member($team->id, $cap));
RSL_Portal::process(array('rsl_nonce' => wp_create_nonce('rsl_front'), 'do' => 'remove_member', 'team_id' => $team->id, 'user_id' => $p1));
$check('captain removes a player', !RSL_Teams::is_member($team->id, $p1));
RSL_Portal::process(array('rsl_nonce' => 'bogus', 'do' => 'new_code', 'team_id' => $team->id));
$check('bad nonce refused', RSL_Teams::get($team->id)->invite_code === $code);
$free = $mk('free'); $reg($free);
wp_set_current_user($free);
RSL_Portal::process(array('rsl_nonce' => wp_create_nonce('rsl_front'), 'do' => 'looking', 'looking' => '1'));
$check('free agent list', (int) RSL_Players::get($free)->looking === 1);
$check('...its email is queued, and parked at once so no other request can send it', $park() >= 1);
$free_view = $page($free);
$check('on the list with no team: the rego can wait until they\'re placed', strpos($free_view, 'no rush') !== false);
$check('...and their season steps include paying the rego', strpos($free_view, 'Your season') !== false && strpos($free_view, 'Pay your rego') !== false && strpos($free_view, 'Cleared to play') === false);

// --- Admin ---------------------------------------------------------------------------------------
wp_set_current_user($admin);
RSL_Teams::apply($season, $declined_by, array('name' => "Pending One $suffix", 'level' => 'social'));
$_GET = array('page' => 'rsl-teams', 'season' => $season);
ob_start(); (new RSL_Admin())->render(); $admin_html = ob_get_clean();
$check('admin sees pending applications to decide', strpos($admin_html, 'Team applications') !== false && strpos($admin_html, "Pending One $suffix") !== false && strpos($admin_html, 'value="approve"') !== false);
$_GET = array('page' => 'rsl-teams', 'season' => $season, 'team' => $team->id);
ob_start(); (new RSL_Admin())->render(); $team_admin = ob_get_clean();
$check('admin roster says why someone is cleared', strpos($team_admin, 'Rego paid') !== false || strpos($team_admin, 'Full/life member') !== false);
RSL_Admin::process(array('rsl_nonce' => wp_create_nonce('rsl_team_action'), 'season' => $season, 'do' => 'grant_rego', 'who' => "rslr-free-$suffix@example.test", 'start' => $today, 'note' => 'cash'));
$check('admin adds a rego by hand', RSL_Rego::clearance($free)['by'] === 'rego');
ob_start(); (new RSL_Admin())->render_registration(); $reg_page = ob_get_clean();
$check('registration page renders with the product chosen', strpos($reg_page, 'Rego product') !== false && strpos($reg_page, 'value="' . $product_id . '"  selected=\'selected\'') !== false, substr($reg_page, strpos($reg_page, 'value="' . $product_id . '"'), 60));
$free_view = $page($free);
$check('a free agent who\'s already cleared isn\'t asked to pay in their season steps', strpos($free_view, 'Your season') !== false && strpos($free_view, 'Cleared to play') !== false && strpos($free_view, 'Pay your rego') === false);
wp_set_current_user($admin);

// --- Season rego (0.15.0) -------------------------------------------------------------------------
// Far-past and far-future seasons only; the candidates filter lets open_for_signup() see them
// (choices() is this year and the next two). Every date from $today.
$S1 = '2091-mid1';   // the sign-up season: today+7 to today+60
$S2 = '2091-mid2';   // a later season: today+70 to today+130
$S3 = '2091-post';   // no dates (covers its quarter)
$RUN = '2090-post';  // running now: today-10 to today+20, earlier than the sign-up season
$GONE = '2090-mid2'; // finished: today-70 to today-5
$OLD = '1991-pre';   // long gone
$test_seasons = array($S1, $S2, $S3, $RUN, $GONE, $OLD);
RSL_Courts::save_settings($S1, array('start' => $day(7), 'end' => $day(60)));
RSL_Courts::save_settings($S2, array('start' => $day(70), 'end' => $day(130)));
RSL_Courts::save_settings($RUN, array('start' => $day(-10), 'end' => $day(20)));
RSL_Courts::save_settings($GONE, array('start' => $day(-70), 'end' => $day(-5)));
RSL_Courts::save_settings($OLD, array('start' => '1991-01-20', 'end' => '1991-03-17'));
RSL_Seasons::set_signup($S1);
$cand_keys = array($S1, $S2, $S3, $RUN, $GONE, $OLD);
$cands = function ($keys) use (&$cand_keys) { return $cand_keys; };
add_filter('rsl_open_for_signup_candidates', $cands);
update_option('woocommerce_prices_include_tax', 'yes');
$season_p = $make_product("Season rego test $suffix", 15); // the yearly one ($yearly) is made above
$topup_p = $make_product("Top-up test $suffix", 1);
$year_id = $yearly->get_id();
$season_id = $season_p->get_id();
update_option(RSL_Rego::PRODUCT_OPTION, $year_id);
update_option(RSL_Rego::SEASON_PRODUCT_OPTION, $season_id);
update_option(RSL_Payments::PRODUCT_OPTION, $topup_p->get_id());
$pay =function ($o) { wc_get_order($o->get_id())->update_status('processing'); };
$item_of = function ($o) { $items = wc_get_order($o->get_id())->get_items(); return reset($items); };
$notes = function ($o, $text) {
    return count(array_filter(wc_get_order_notes(array('order_id' => $o->get_id())), function ($n) use ($text) { return strpos($n->content, $text) !== false; }));
};
$rows_of = function ($o) use ($wpdb) { return $wpdb->get_results($wpdb->prepare("SELECT * FROM " . RSL_Database::table('regos') . " WHERE order_id = %d", $o->get_id())); };
$rows_for = function ($u) use ($wpdb) { return $wpdb->get_results($wpdb->prepare("SELECT * FROM " . RSL_Database::table('regos') . " WHERE user_id = %d", $u)); };
$sp = array();
foreach (array('sa', 'sb', 'sc', 'sd', 'se', 'sf', 'sg', 'sh', 'si', 'sj', 'sk', 'sl', 'sm', 'sn') as $tag) { $sp[$tag] = $mk($tag); $reg($sp[$tag]); }
$sa = $sp['sa']; $sb = $sp['sb']; $sc = $sp['sc']; $sd = $sp['sd']; $se = $sp['se']; $sf = $sp['sf']; $sg = $sp['sg'];
$sh = $sp['sh']; $si = $sp['si']; $sj = $sp['sj']; $sk = $sp['sk']; $sl = $sp['sl']; $sm = $sp['sm']; $sn = $sp['sn'];
$s1_label = RSL_Seasons::label_with_dates($S1, RSL_Seasons::span($S1));

// 1-2: what's on offer, and the words and links.
$open = RSL_Seasons::open_for_signup();
$check('season rego: open for sign-up are the sign-up season and the later one with dates (none undated, earlier or finished)', array_keys($open) === array($S1, $S2), wp_json_encode($open));
$check('prices from the products: $15 a season, $40 a year', RSL_Rego::price_label('season') === '$15' && RSL_Rego::price_label() === '$40', RSL_Rego::price_label('season') . ' / ' . RSL_Rego::price_label());
$both = array(RSL_Rego::cost_words(), RSL_Rego::cost_sentence(), RSL_Rego::pay_url());
update_option(RSL_Rego::SEASON_PRODUCT_OPTION, 0);
$year_only = array(RSL_Rego::cost_words(), RSL_Rego::cost_sentence(), RSL_Rego::pay_url());
update_option(RSL_Rego::SEASON_PRODUCT_OPTION, $season_id);
update_option(RSL_Rego::PRODUCT_OPTION, 0);
$season_only = array(RSL_Rego::cost_words(), RSL_Rego::cost_sentence(), RSL_Rego::pay_url());
update_option(RSL_Rego::PRODUCT_OPTION, $year_id);
$check('the wording follows the products set', $both[0] === '$15 a season or $40 a year' && $both[1] === 'It\'s $15 for a season or $40 for a year.'
    && $year_only[0] === '$40, 12 months' && $year_only[1] === 'It\'s $40 and lasts 12 months.'
    && $season_only[0] === '$15 a season' && $season_only[1] === 'It\'s $15 for a season.', wp_json_encode(array($both, $year_only, $season_only)));
$check('emails link straight to checkout only while the yearly rego is the only one (else the league page, where the player picks the season)',
    $both[2] === '' && $season_only[2] === '' && strpos($year_only[2], 'add-to-cart=' . $year_id) !== false);
$check('pay links: the season one names its season; the yearly one never carries one', strpos(RSL_Rego::pay_url('season', $S2), 'add-to-cart=' . $season_id) !== false
    && strpos(RSL_Rego::pay_url('season', $S2), RSL_Rego::SEASON_ARG . '=' . $S2) !== false
    && strpos(RSL_Rego::pay_url('year'), 'add-to-cart=' . $year_id) !== false && strpos(RSL_Rego::pay_url('year'), RSL_Rego::SEASON_ARG) === false);

// 3-9: paying.
$oa = $make_order($season_p, $sa, array(RSL_Rego::SEASON_META => $S2));
$pay($oa);
$rows = $rows_of($oa);
$check('a paid season rego covers its season, from its first night to its last, with a note on the order', count($rows) === 1 && $rows[0]->season === $S2
    && $rows[0]->start_date === $day(70) && $rows[0]->end_date === $day(130) && $rows[0]->granted_end === $day(130) && $notes($oa, 'season rego given for') === 1, wp_json_encode($rows));
$c = RSL_Rego::clearance_for($sa, $S2);
$check('paid ahead: not cleared today, cleared for that season (judged on its first night)', !RSL_Rego::clearance($sa)['cleared'] && RSL_Rego::check_date($S2) === $day(70)
    && $c['cleared'] && $c['by'] === 'rego' && $c['from'] === $day(70) && $c['season'] === $S2, wp_json_encode($c));
wc_get_order($oa->get_id())->update_status('completed');
RSL_Rego::scan_orders();
$check('processing then completed, and the past-order scan: still one rego', count($rows_of($oa)) === 1);
$ob = $make_order($season_p, $sb);
$pay($ob);
$rows = $rows_of($ob);
$check('a season line that doesn\'t say its season, paid now: the sign-up season', count($rows) === 1 && $rows[0]->season === $S1 && $rows[0]->start_date === $day(7) && $rows[0]->end_date === $day(60), wp_json_encode($rows));
$oc = $make_order($season_p, $sc, array(RSL_Rego::SEASON_META => $RUN));
$pay($oc);
$rows = $rows_of($oc);
$check('bought while its season is running: from the payment date to its last night', count($rows) === 1 && $rows[0]->start_date === $today && $rows[0]->end_date === $day(20), wp_json_encode($rows));
RSL_Rego::grant($sd, array('start' => $today, 'source' => 'manual', 'note' => 'cash'));
$od = $make_order($season_p, $sd, array(RSL_Rego::SEASON_META => $S1));
$pay($od);
$rows = $rows_of($od);
$check('a season rego never chains: it starts on its first night inside a running yearly rego, and the order says they were covered', count($rows) === 1
    && $rows[0]->start_date === $day(7) && $rows[0]->end_date === $day(60) && $notes($od, 'already covered for every game night') === 1, wp_json_encode($rows));
$oe = $make_order($season_p, $se, array(RSL_Rego::SEASON_META => $S3));
$pay($oe);
$rows = $rows_of($oe);
$check('a season with no dates covers its quarter, and the rego and the order say so', count($rows) === 1 && $rows[0]->start_date === '2091-10-01'
    && $rows[0]->end_date === '2091-12-31' && strpos($rows[0]->note, 'dates to come') !== false && $notes($oe, 'no game nights set yet') === 1, wp_json_encode($rows));
$c = RSL_Rego::clearance_for($se, $S3);
$check('...and it counts for that season now (judged on today, through the paid row)', $c['cleared'] && $c['from'] === '2091-10-01' && $c['season'] === $S3, wp_json_encode($c));
$o9 = $make_order($season_p, $sk, array(RSL_Rego::SEASON_META => $OLD));
$pay($o9);
RSL_Rego::on_order_paid($o9->get_id());
$check('a season that finished before the order was paid: no rego, one note', count($rows_of($o9)) === 0 && $notes($o9, 'which finished on') === 1);

// 10: the season on the order line, on order screens, and Order again.
$yl = new WC_Order_Item_Product();
RSL_Rego::tag_order_item($yl, 'k', array('product_id' => $year_id, 'variation_id' => 0, RSL_Rego::CART_SEASON => $S1));
$sl_item = new WC_Order_Item_Product();
RSL_Rego::tag_order_item($sl_item, 'k', array('product_id' => $season_id, 'variation_id' => 0, RSL_Rego::CART_SEASON => $S1));
$check('the order line keeps the season; a yearly line never gets one', $yl->get_meta(RSL_Rego::SEASON_META) === '' && $sl_item->get_meta(RSL_Rego::SEASON_META) === $S1);
$oy = $make_order($yearly, $sk);
$pay($oy);
$rows = $rows_of($oy);
$check('a paid yearly rego has no season', count($rows) === 1 && $rows[0]->season === '' && $rows[0]->granted_end === $rows[0]->end_date, wp_json_encode($rows));
$o10 = $make_order($season_p, $sk, array(RSL_Rego::SEASON_META => $S1));
$i10 = $item_of($o10);
$season_entries = function ($formatted) {
    $n = 0;
    $value = '';
    $raw = false;
    foreach ((array) $formatted as $m) {
        if ($m->display_key === 'Season') { $n++; $value = $m->display_value; }
        if ($m->display_key === RSL_Rego::SEASON_META) { $raw = true; }
    }
    return array($n, $value, $raw);
};
$admin_meta = $season_entries($i10->get_formatted_meta_data(''));
$receipt_meta = $season_entries($i10->get_formatted_meta_data());
$check('the admin order screen and the receipt show the season once, by its short label', $admin_meta === array(1, '2091 Mid-season 1', false)
    && $receipt_meta === array(1, '2091 Mid-season 1', false), wp_json_encode(array($admin_meta, $receipt_meta)));
$o10 = wc_get_order($o10->get_id());
$again = apply_filters('woocommerce_order_again_cart_item_data', array(), $i10, $o10);
$old_line = new WC_Order_Item_Product();
$old_line->set_product($season_p);
$old_line->add_meta_data(RSL_Rego::SEASON_META, $OLD, true);
$again_old = apply_filters('woocommerce_order_again_cart_item_data', array(), $old_line, $o10);
$year_line = new WC_Order_Item_Product();
$year_line->set_product($yearly);
$again_year = apply_filters('woocommerce_order_again_cart_item_data', array(), $year_line, $o10);
$check('Order again keeps the season while it\'s open, else the sign-up season; a yearly line gets none',
    isset($again[RSL_Rego::CART_SEASON]) && $again[RSL_Rego::CART_SEASON] === $S1 && isset($again_old[RSL_Rego::CART_SEASON]) && $again_old[RSL_Rego::CART_SEASON] === $S1
    && !isset($again_year[RSL_Rego::CART_SEASON]), wp_json_encode(array($again, $again_old, $again_year)));

// 11: refused before payment.
$check('a season that isn\'t open for sign-up is refused', strpos(RSL_Rego::season_add_error($sa, $OLD), 'isn\'t open for sign-up') !== false
    && strpos(RSL_Rego::season_add_error($sa, $S3), 'isn\'t open for sign-up') !== false);
$check('a season already paid for is refused, saying so; another season is fine', strpos(RSL_Rego::season_add_error($sa, $S2), 'already covered for every game night of 2091 Mid-season 2') !== false
    && RSL_Rego::season_add_error($sa, $S1) === '', RSL_Rego::season_add_error($sa, $S2));
if (RSL_Rego::membership_source_available()) {
    $wpdb->insert($mem, array('user_id' => $sf, 'tier' => 'full-member', 'start_date' => $day(-30), 'end_date' => $day(60), 'source' => 'manual'));
    $covered = RSL_Rego::season_add_error($sf, $S1);
    $wpdb->update($mem, array('end_date' => $day(30)), array('user_id' => $sf));
    $short = RSL_Rego::season_add_error($sf, $S1);
    RSL_Rego::grant($sf, array('start' => $day(31), 'source' => 'manual'));
    $mixed = RSL_Rego::season_add_error($sf, $S1);
    $check('membership through the season: covered; ending part way: not; membership then a rego, end to end: covered',
        strpos($covered, 'already covered') !== false && $short === '' && strpos($mixed, 'already covered') !== false, wp_json_encode(array($covered, $short, $mixed)));
} else {
    echo "SKIP: membership checks for the season rego need the Club Manager\n";
}

// 12-13: through WooCommerce's add-to-cart and cart, as throwaway players only, with
// WooCommerce's shutdown saves removed so nothing from this cart reaches a session or customer.
if (!function_exists('WC') || !WC()->session || !WC()->cart) {
    echo "SKIP: no WooCommerce session or cart under WP-CLI\n";
} else {
    $cart_test = true;
    remove_action('shutdown', array(WC()->session, 'save_data'), 20);
    if (WC()->customer) {
        remove_action('shutdown', array(WC()->customer, 'save'), 10);
    }
    $was = get_current_user_id();
    $holding = function ($type, $text) {
        return count(array_filter(wc_get_notices($type), function ($n) use ($text) { return strpos(is_array($n) ? (string) $n['notice'] : (string) $n, $text) !== false; }));
    };
    $ask = function ($s) { $_GET = $_REQUEST = $s === null ? array() : array(RSL_Rego::SEASON_ARG => $s); };
    $rego_lines = function () use ($year_id, $season_id) {
        return array_values(array_filter(WC()->cart->get_cart(), function ($line) use ($year_id, $season_id) {
            return in_array(intval($line['product_id']), array($year_id, $season_id), true);
        }));
    };

    wp_set_current_user($sa);
    WC()->cart->empty_cart();
    wc_clear_notices();
    $ask($S2);
    $refused = apply_filters('woocommerce_add_to_cart_validation', true, $season_id, 1);
    $check('add to cart: a season the player is already covered for is refused, with one sentence', $refused === false && wc_notice_count('error') === 1
        && $holding('error', 'already covered') === 1, wp_json_encode(wc_get_notices('error')));
    wc_clear_notices();
    $ask($S1);
    $check('...another season can go in', apply_filters('woocommerce_add_to_cart_validation', true, $season_id, 1) === true && wc_notice_count('error') === 0);
    $check('...and the yearly rego is unchanged (no covered check)', apply_filters('woocommerce_add_to_cart_validation', true, $year_id, 1) === true);
    $ask(null);
    $check('Order again: the season it passes is the one checked', apply_filters('woocommerce_add_to_cart_validation', true, $season_id, 1, 0, array(), array(RSL_Rego::CART_SEASON => $S2)) === false);
    WC()->cart->empty_cart();
    wc_clear_notices();

    wp_set_current_user($sg);
    WC()->cart->empty_cart();
    wc_clear_notices();
    $ask($S1);
    WC()->cart->add_to_cart($season_id);
    $lines = $rego_lines();
    $shown = $lines ? (array) apply_filters('woocommerce_get_item_data', array(), $lines[0]) : array();
    $shows = (bool) array_filter($shown, function ($d) use ($s1_label) { return isset($d['key'], $d['value']) && $d['key'] === 'Season' && $d['value'] === $s1_label; });
    $check('cart: the season rego line carries the season chosen, and shows it with its dates', count($lines) === 1 && isset($lines[0][RSL_Rego::CART_SEASON])
        && $lines[0][RSL_Rego::CART_SEASON] === $S1 && $shows, wp_json_encode($shown));
    $before = wc_notice_count('error');
    $check('...the same season again is refused quietly (a refreshed pay link)', apply_filters('woocommerce_add_to_cart_validation', true, $season_id, 1) === false && wc_notice_count('error') === $before);
    $ask($S2);
    $passes = apply_filters('woocommerce_add_to_cart_validation', true, $season_id, 1);
    WC()->cart->add_to_cart($season_id);
    $lines = $rego_lines();
    $check('...another season replaces it: one rego line', $passes === true && count($lines) === 1 && isset($lines[0][RSL_Rego::CART_SEASON]) && $lines[0][RSL_Rego::CART_SEASON] === $S2);
    WC()->cart->add_to_cart($year_id);
    $lines = $rego_lines();
    $check('...so does the yearly rego, which carries no season', count($lines) === 1 && intval($lines[0]['product_id']) === $year_id && !isset($lines[0][RSL_Rego::CART_SEASON]));
    remove_action('woocommerce_add_to_cart', array('RSL_Rego', 'keep_one_rego'), 10);
    $ask($S1);
    WC()->cart->add_to_cart($season_id);
    add_action('woocommerce_add_to_cart', array('RSL_Rego', 'keep_one_rego'), 10, 4);
    $two = count($rego_lines());
    wc_clear_notices();
    RSL_Rego::check_cart();
    $check('two rego lines (as a saved cart merged at login leaves them): checkout is stopped', $two === 2 && $holding('error', 'Only one social rego can be bought at a time') === 1);
    do_action('woocommerce_cart_loaded_from_session', WC()->cart);
    $lines = $rego_lines();
    $check('...and tidied when the cart loads: the last one chosen stays, with a notice', count($lines) === 1 && isset($lines[0][RSL_Rego::CART_SEASON])
        && $lines[0][RSL_Rego::CART_SEASON] === $S1 && $holding('notice', 'Only one social rego can be in the cart') === 1);
    $before = wc_notice_count('error');
    RSL_Rego::check_cart();
    $check('...then checkout goes ahead', wc_notice_count('error') === $before);
    // The $S1 line stays in the cart: checkout stops when its season has finished meanwhile,
    // or the player has become covered for it meanwhile (no money for a rego not needed).
    RSL_Courts::save_settings($S1, array('start' => $day(-30), 'end' => $day(-1)));
    wc_clear_notices();
    RSL_Rego::check_cart();
    $finished_stop = array(wc_notice_count('error'), $holding('error', 'which has finished'), wp_json_encode(wc_get_notices('error')));
    RSL_Courts::save_settings($S1, array('start' => $day(7), 'end' => $day(60)));
    $check('checkout: a season line whose season has finished meanwhile is stopped, saying so', $finished_stop[0] === 1 && $finished_stop[1] === 1, $finished_stop[2]);
    $late = RSL_Rego::grant($sg, array('source' => 'manual', 'season' => $S1));
    wc_clear_notices();
    RSL_Rego::check_cart();
    $covered_stop = array(wc_notice_count('error'), $holding('error', 'so the season rego in your cart isn'), wp_json_encode(wc_get_notices('error')));
    $wpdb->delete(RSL_Database::table('regos'), array('id' => $late)); // sg pays nothing yet (the league page checks below)
    $check('...and so is one the player has become covered for meanwhile, saying it isn\'t needed', $late > 0 && $covered_stop[0] === 1 && $covered_stop[1] === 1, $covered_stop[2]);
    WC()->cart->empty_cart();
    wc_clear_notices();
    $ask(null);
    wp_set_current_user($was);
}

// The part of a page from $from up to $to (or the end); '' when $from isn't there.
$segment = function ($html, $from, $to) { $a = strpos($html, $from); if ($a === false) { return ''; } $b = strpos($html, $to, $a + strlen($from)); return (string) substr($html, $a, $b === false ? strlen($html) - $a : $b - $a); };
// The season rego form alone: the season pickers (0.17.0) print the same season values and labels.
$rego_form = function ($html) use ($segment) { return $segment($html, 'class="rsl-rego-season"', '</form>'); };

// 14-17: the league page.
$v = $page($sg);
$f = $rego_form($v);
$at_season = strpos($v, 'Pay the $15 season rego');
$at_year = strpos($v, 'Pay the $40 yearly rego');
$check('league page, both products: the price, the season form (the sign-up season chosen, the later one offered), then the yearly rego',
    $f !== '' && strpos($v, 'It\'s $15 for a season or $40 for a year.') !== false && strpos($v, 'name="add-to-cart" value="' . $season_id . '"') !== false
    && strpos($f, '<select name="rsl_season"') !== false && strpos($f, 'value="' . $S1 . '" selected=\'selected\'') !== false && strpos($f, 'value="' . $S2 . '"') !== false
    && strpos($f, esc_html($s1_label)) !== false && $at_season !== false && $at_year !== false && $at_season < $at_year && strpos($v, 'add-to-cart=' . $year_id) !== false);
update_option(RSL_Rego::PRODUCT_OPTION, 0);
$v = $page($sg);
$check('season only: the season form, no yearly rego', strpos($v, 'name="rsl_season"') !== false && strpos($v, 'yearly rego') === false);
update_option(RSL_Rego::PRODUCT_OPTION, $year_id);
update_option(RSL_Rego::SEASON_PRODUCT_OPTION, 0);
$v = $page($sg);
$check('yearly only: as before', strpos($v, 'It\'s $40 and lasts 12 months.') !== false && strpos($v, 'Pay the $40 rego') !== false && strpos($v, 'rsl_season') === false);
update_option(RSL_Rego::SEASON_PRODUCT_OPTION, $season_id);
$v = $page($sa);
$f = $rego_form($v);
$check('paid for the later season: only the sign-up season is offered, named beside the button', $f !== '' && strpos($f, 'type="hidden" name="rsl_season" value="' . $S1 . '"') !== false
    && strpos($f, esc_html($s1_label)) !== false && strpos($f, '<select name="rsl_season"') === false && strpos($f, 'value="' . $S2 . '"') === false);
$v = $page($sb);
$check('paid ahead for the season: cleared from its first night, saying by what; nothing to pay', strpos($v, 'You\'re cleared to play from ' . RSL_Seasons::day_words($day(7), 2091)) !== false
    && strpos($v, 'Season rego, 2091 Mid-season 1, until') !== false && strpos($v, 'name="rsl_season"') === false);
$v = $page($sc);
$f = $rego_form($v);
$check('a rego that runs out before the season ends: it runs out soon, offering the seasons to come; a yearly rego would start when it ends', strpos($v, 'It runs out soon.') !== false
    && $f !== '' && strpos($f, '<select name="rsl_season"') !== false && strpos($f, 'value="' . $S1 . '"') !== false && strpos($f, 'value="' . $S2 . '"') !== false
    && strpos($v, 'Pay for another season, or for a year (a yearly rego starts when this one ends):') !== false);
// The cover on the card starts after today (a hand-added season rego from today+3 to today+20):
// a yearly rego paid today starts today (grant() chains only onto a rego running on the payment
// date), so the nudge never says it starts when this one ends.
$sx = $mk('sx');
$reg($sx);
RSL_Rego::grant($sx, array('start' => $day(3), 'source' => 'manual', 'season' => $RUN));
$v = $page($sx);
$check('...but cover that starts after today: the nudge, without promising the yearly rego starts when it ends', strpos($v, 'It runs out soon.') !== false
    && strpos($v, 'Pay for another season, or for a year:') !== false && strpos($v, 'starts when this one ends') === false
    && RSL_Rego::rego_valid_until($sx, $today) === null, wp_json_encode(RSL_Rego::clearance_for($sx, $S1)));

// 18-19: rosters, judged for the team's season.
$steam = RSL_Teams::create($S1, array('name' => "Season Team $suffix", 'division' => 'ranked'));
RSL_Teams::add_member($steam->id, $sb, 'captain');
RSL_Teams::add_member($steam->id, $sn);
$v = $page($sb);
$roster = (string) substr($v, (int) strpos($v, '<h4>Players'), (int) strpos($v, '<h4>Add players') - (int) strpos($v, '<h4>Players'));
$check('captain\'s roster for the team\'s season: the captain who paid ahead is cleared, the other player isn\'t (both judged on its first night)',
    strpos($roster, '&#10003; Cleared') !== false && strpos($roster, '&#10007; Needs rego') !== false && !RSL_Rego::clearance($sb)['cleared'], $roster);
$described = RSL_Rego::describe(RSL_Rego::clearance_for($sb, $S1));
$check('admin wording for a season rego', $described === 'Rego paid (2091 Mid-season 1, until ' . date('j M Y', strtotime($day(60))) . ')', $described);

// 20: the admin's Registration screen.
wp_set_current_user($admin);
$nonce = wp_create_nonce('rsl_team_action');
$admin_do = function ($fields) use ($nonce, $admin, $season) {
    delete_transient(RSL_Admin::NOTICE_PREFIX . $admin);
    RSL_Admin::process(array_merge(array('rsl_nonce' => $nonce, 'season' => $season), $fields));
    $n = get_transient(RSL_Admin::NOTICE_PREFIX . $admin);
    return is_array($n) ? $n['message'] : '';
};
update_option(RSL_Rego::PRODUCT_OPTION, 0);
update_option(RSL_Rego::SEASON_PRODUCT_OPTION, 0);
$admin_do(array('do' => 'save_rego_product', 'product_id' => $year_id, 'season_product_id' => $season_id));
$check('admin saves both rego products', RSL_Rego::product_id() === $year_id && RSL_Rego::season_product_id() === $season_id);
$msg = $admin_do(array('do' => 'save_rego_product', 'product_id' => $season_id, 'season_product_id' => $season_id));
$check('...one product for both is refused, changing neither', strpos($msg, 'need different products') !== false && RSL_Rego::product_id() === $year_id && RSL_Rego::season_product_id() === $season_id, $msg);
$m1 = $admin_do(array('do' => 'save_rego_product', 'product_id' => $topup_p->get_id(), 'season_product_id' => $season_id));
$m2 = $admin_do(array('do' => 'save_rego_product', 'product_id' => $year_id, 'season_product_id' => $topup_p->get_id()));
$check('...and the team top-up product can\'t be either one', strpos($m1, 'can\'t be a rego product') !== false && strpos($m2, 'can\'t be a rego product') !== false
    && RSL_Rego::product_id() === $year_id && RSL_Rego::season_product_id() === $season_id, $m1 . ' / ' . $m2);
$_GET = array('page' => RSL_Admin::REG_PAGE);
ob_start(); (new RSL_Admin())->render_registration(); $rp = ob_get_clean();
$season_select = (string) substr($rp, (int) strpos($rp, 'name="season_product_id"'));
$season_select = (string) substr($season_select, 0, (int) strpos($season_select, '</select>'));
$open_group = (string) substr($rp, (int) strpos($rp, '<optgroup label="Seasons open for sign-up">'));
$open_group = (string) substr($open_group, 0, (int) strpos($open_group, '</optgroup>'));
$check('the Registration page: both product choices, and the kind of rego (the open seasons, then the others)', strpos($rp, 'name="product_id"') !== false
    && strpos($season_select, 'value="' . $season_id . '"  selected=\'selected\'') !== false && strpos($rp, 'name="kind"') !== false
    && strpos($open_group, 'value="' . $S1 . '"') !== false && strpos($open_group, 'value="' . $S2 . '"') !== false && strpos($rp, '<optgroup label="Other seasons">') !== false);
$msg = $admin_do(array('do' => 'grant_rego', 'who' => "rslr-sl-$suffix@example.test", 'kind' => $S1, 'start' => $today));
$rows = $rows_for($sl);
$check('admin adds a season rego by hand: that season\'s dates', count($rows) === 1 && $rows[0]->season === $S1 && $rows[0]->start_date === $day(7) && $rows[0]->end_date === $day(60)
    && strpos($msg, 'Season rego for 2091 Mid-season 1 added') !== false, $msg);
$msg = $admin_do(array('do' => 'grant_rego', 'who' => "rslr-sm-$suffix@example.test", 'kind' => $S1, 'start' => 'abc'));
$rows = $rows_for($sm);
$check('...a start date that isn\'t a date reads as today', count($rows) === 1 && $rows[0]->start_date === $day(7) && $rows[0]->end_date === $day(60), $msg);
$msg = $admin_do(array('do' => 'grant_rego', 'who' => "rslr-sn-$suffix@example.test", 'kind' => $OLD, 'start' => $today));
$check('...a season outside this year and the next two is refused', $msg === 'Pick 12 months or a season.' && count($rows_for($sn)) === 0, $msg);
$finished = '';
foreach (array_keys(RSL_Seasons::choices()) as $k) {
    $ks = RSL_Seasons::span($k);
    if ($ks && $ks['end'] < $today) { $finished = $k; break; }
}
if ($finished === '') {
    echo "SKIP: no season this year or the next two has finished yet\n";
} else {
    $msg = $admin_do(array('do' => 'grant_rego', 'who' => "rslr-sn-$suffix@example.test", 'kind' => $finished, 'start' => $today));
    $check('...a season that ended before the start date is refused', strpos($msg, 'before the start date') !== false && count($rows_for($sn)) === 0, $msg);
}
$_GET = array('page' => RSL_Admin::REG_PAGE);
ob_start(); (new RSL_Admin())->render_registration(); $rp = ob_get_clean();
$check('the regos list shows the season', strpos($rp, 'Season: 2091 Mid-season 1') !== false);

// 21: the gap between seasons (the current season pinned to one that has finished).
RSL_Seasons::set_current($GONE);
$gteam = RSL_Teams::create($GONE, array('name' => "Gone Team $suffix", 'division' => 'ranked'));
RSL_Teams::add_member($gteam->id, $sh, 'captain');
RSL_Rego::grant($sh, array('source' => 'manual', 'season' => $S1));
$v = $page($sh);
$check('the gap: on a team in the season just finished, paid for the next: judged for the next, so cleared from its first night',
    RSL_Seasons::playing_season($GONE, $S1, true) === $S1 && strpos($v, 'You\'re cleared to play from ' . RSL_Seasons::day_words($day(7), 2091)) !== false
    && strpos($v, 'You need a social rego') === false && strpos($v, 'name="rsl_season"') === false);
RSL_Seasons::set_signup($GONE);
$first_open = array_keys(RSL_Seasons::open_for_signup());
$check('...with the sign-up season pinned to the finished one too: the first season open for sign-up', RSL_Seasons::playing_season(RSL_Seasons::current(), RSL_Seasons::signup(), true) === $RUN
    && $first_open && $first_open[0] === $RUN, wp_json_encode($first_open));
RSL_Seasons::set_signup($S1);
RSL_Seasons::set_current($season);

// 22: the overlap (a running season that isn't open for sign-up).
RSL_Seasons::set_current($RUN);
$rteam = RSL_Teams::create($RUN, array('name' => "Run Team $suffix", 'division' => 'ranked'));
RSL_Teams::add_member($rteam->id, $si, 'captain');
$v = $page($si);
$check('the overlap: on a team in a running season that can\'t be bought: the yearly rego only, and why', RSL_Seasons::playing_season($RUN, $S1, true) === $RUN
    && strpos($v, 'You need a social rego') !== false && strpos($v, 'A season rego for 2090 Post-season can\'t be bought online now. Pay the yearly rego, or contact the league.') !== false
    && strpos($v, 'Pay the $40 yearly rego') !== false && strpos($v, 'name="rsl_season"') === false && strpos($v, 'isn\'t set up yet') === false);
update_option(RSL_Rego::PRODUCT_OPTION, 0);
$v = $page($si);
$check('...with no yearly product: contact the league', strpos($v, 'can\'t be bought online now. Contact the league to pay.') !== false
    && strpos($v, 'add-to-cart=') === false && strpos($v, 'isn\'t set up yet') === false);
update_option(RSL_Rego::PRODUCT_OPTION, $year_id);
RSL_Seasons::set_current($season);

// 23: no season open for sign-up at all.
RSL_Seasons::set_signup($OLD);
$cand_keys = array($OLD);
$v = $page($sj);
$check('no season open for sign-up: says so, and offers the yearly rego', RSL_Seasons::open_for_signup() === array()
    && strpos($v, 'No season is open for sign-up yet. Pay the yearly rego, or contact the league.') !== false && strpos($v, 'add-to-cart=' . $year_id) !== false);
update_option(RSL_Rego::PRODUCT_OPTION, 0);
$v = $page($sj);
$check('...with no yearly product: contact the league', strpos($v, 'No season is open for sign-up yet. Contact the league to pay.') !== false && strpos($v, 'isn\'t set up yet') === false);
update_option(RSL_Rego::SEASON_PRODUCT_OPTION, 0);
$v = $page($sj);
$check('...with neither product: online payment isn\'t set up', strpos($v, 'Online payment isn\'t set up yet') !== false);
update_option(RSL_Rego::PRODUCT_OPTION, $year_id);
update_option(RSL_Rego::SEASON_PRODUCT_OPTION, $season_id);
RSL_Seasons::set_signup($S1);
$cand_keys = array($S1, $S2, $S3, $RUN, $GONE, $OLD);

// 24: a paid season rego keeps counting for its season when the dates move.
RSL_Courts::save_settings($S1, array('start' => $day(-1), 'end' => $day(60)));
$c = RSL_Rego::clearance_for($sb, $S1);
$check('the first night moved earlier after the sale: the paid row still counts for its season (and isn\'t sold again)', !RSL_Rego::clearance($sb)['cleared']
    && $c['cleared'] && $c['by'] === 'rego' && $c['until'] === $day(60) && RSL_Rego::season_covered($sb, $S1)
    && strpos(RSL_Rego::season_add_error($sb, $S1), 'already covered') !== false && !isset(RSL_Rego::season_choices($sb)[$S1]), wp_json_encode($c));
RSL_Courts::save_settings($S1, array('start' => $day(7), 'end' => $day(60)));

// 25: a season with no dates whose quarter is still ahead, and cover that starts after today but
// by the quarter's first day (a hand-added yearly rego from 15 Sep 2091): cleared for that season,
// as the shop's covered check says, so the league page never asks for money it would refuse.
$so_u = $mk('so');
$reg($so_u);
RSL_Rego::grant($so_u, array('start' => '2091-09-15', 'source' => 'manual', 'note' => 'cash'));
$c = RSL_Rego::clearance_for($so_u, $S3);
RSL_Seasons::set_signup($S3);
$v = $page($so_u);
RSL_Seasons::set_signup($S1);
$check('a season with no dates and cover starting by its quarter: cleared for it from when the cover starts, nothing to pay',
    !RSL_Rego::clearance($so_u)['cleared'] && RSL_Rego::check_date($S3) === $today && RSL_Rego::season_covered($so_u, $S3)
    && $c['cleared'] && $c['by'] === 'rego' && $c['from'] === '2091-09-15'
    && strpos($v, 'You\'re cleared to play from ' . RSL_Seasons::day_words('2091-09-15', 2091)) !== false
    && strpos($v, 'You need a social rego') === false && strpos($v, 'name="rsl_season"') === false, wp_json_encode($c));

// 26: Start a team at registration in one step, and a season for every sign-up (0.17.0).
// Here: current $season; signing up $S1 (today+7 to +60), $S2 (today+70 to +130) open too;
// $RUN, $GONE, $S3 and $OLD not open; both products set; the candidates filter on.
$post_as = function ($u, $fields) {
    wp_set_current_user($u);
    delete_transient(RSL_Portal::FLASH_PREFIX . $u);
    $to = RSL_Portal::process(array_merge(array('rsl_nonce' => wp_create_nonce('rsl_front')), $fields));
    $f = get_transient(RSL_Portal::FLASH_PREFIX . $u);
    return array((string) $to, is_array($f) ? $f : array('type' => '', 'message' => ''));
};
$reg_fields = array('do' => 'register', 'level' => 'social', 'years' => '1to3');
$ends_apply = function ($to) { return substr((string) $to, -strlen('#rsl-apply')) === '#rsl-apply'; };
$apps_of = function ($u, $s) use ($wpdb) {
    return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . RSL_Database::table('teams') . " WHERE applied_by = %d AND season = %s AND status = 'pending'", $u, $s));
};
$st = array();
foreach (array('st1', 'st2', 'st3', 'st4', 'st5', 'st6', 'st7') as $tag) { $st[$tag] = $mk($tag); }

// 1: the register form.
$v = $page($st['st1']);
$ts = $segment($v, '<select name="team_season">', '</select>');
$fs = $segment($v, '<select name="fa_season">', '</select>');
$none_closed = function ($seg) use ($GONE, $RUN, $S3) {
    return strpos($seg, '"' . $GONE . '"') === false && strpos($seg, '"' . $RUN . '"') === false && strpos($seg, '"' . $S3 . '"') === false;
};
$check('26.1 the register form: the team fields, and both season pickers offer the seasons open for sign-up (the sign-up season chosen)',
    strpos($v, 'name="team_name"') !== false && strpos($v, 'name="team_division"') !== false && strpos($v, 'name="team_level"') !== false && strpos($v, 'name="team_colour"') !== false
    && strpos($ts, 'value="' . $S1 . '" selected=\'selected\'') !== false && strpos($ts, 'value="' . $S2 . '"') !== false && $none_closed($ts)
    && strpos($fs, 'value="' . $S1 . '" selected=\'selected\'') !== false && strpos($fs, 'value="' . $S2 . '"') !== false && $none_closed($fs), $ts . ' / ' . $fs);
$check('...nothing is required inside the hidden panels, and the legend names the league (more than one season)',
    strpos($segment($v, 'rsl-fa-p-start', 'rsl-fa-p-find'), ' required') === false && strpos($segment($v, 'rsl-fa-p-find', 'rsl-fa-p-later'), ' required') === false
    && strpos($v, 'How are you joining the Reds Social League?') !== false);

// 2: Start a team inline: success, the same form twice, another name.
$start_fields = $reg_fields + array('route' => 'start', 'team_name' => "Startup $suffix", 'team_division' => 'unranked', 'team_level' => 'social', 'team_colour' => '#123456', 'team_season' => $S2);
list($to, $fl) = $post_as($st['st1'], $start_fields);
$app1 = RSL_Teams::pending_application_for_user($st['st1'], $S2);
$want = "You're registered and your application for Startup $suffix (2091 Mid-season 2) is in. Once the league approves it you'll get an invite link for your players.";
$check('26.2 Start a team: one submit registers and sends the application, for the season picked', RSL_Players::is_registered($st['st1']) && $app1
    && $app1->name === "Startup $suffix" && $app1->division === 'unranked' && $app1->est_level === 'social' && $app1->colour === '#123456' && $app1->status === 'pending'
    && !RSL_Teams::pending_application_for_user($st['st1'], $S1) && $to === RSL_Portal::url() && $fl['type'] === 'success' && $fl['message'] === $want, wp_json_encode(array($to, $fl)));
list($to, $fl) = $post_as($st['st1'], $start_fields);
$check('...the same form sent again (a double click): the same success, still one application', $fl['type'] === 'success' && $fl['message'] === $want
    && strpos($to, '#rsl-apply') === false && $apps_of($st['st1'], $S2) === 1, wp_json_encode(array($to, $fl)));
list($to, $fl) = $post_as($st['st1'], $reg_fields + array('route' => 'start', 'team_name' => "Startup Two $suffix", 'team_level' => 'social', 'team_season' => $S2));
$check('...another name for that season: the application waiting is the reason, with nothing to send again', $fl['type'] === 'error'
    && strpos($fl['message'], 'You\'re registered, but your team application didn\'t go through: You have a team application waiting') === 0
    && strpos($fl['message'], 'Check the details below') === false && strpos($to, '#rsl-apply') === false
    && get_transient(RSL_Portal::APPLY_DRAFT_PREFIX . $st['st1']) === false && $apps_of($st['st1'], $S2) === 1, wp_json_encode(array($to, $fl)));

// 3: the page after it.
$v = $page($st['st1']);
$check('26.3 the league page: the application under Later season; the card offers the sign-up season only (the later one is taken)', strpos($v, 'Later season') !== false
    && strpos($v, "Your application for Startup $suffix is waiting for approval") !== false && strpos($v, 'name="team_season" value="' . $S1 . '"') !== false
    && strpos($v, '<select name="team_season">') === false && strpos($v, 'id="rsl-apply" open') === false);

// 4: Start a team inline that fails: registered all the same, and the card opens with what was typed.
list($to, $fl) = $post_as($st['st2'], $reg_fields + array('route' => 'start', 'team_name' => '', 'team_level' => 'social', 'team_colour' => '#654321', 'team_season' => $S1));
$check('26.4 Start a team with no name: still registered, the reason, back to the card', RSL_Players::is_registered($st['st2'])
    && !RSL_Teams::pending_application_for_user($st['st2'], $S1) && $ends_apply($to) && $fl['type'] === 'error'
    && strpos($fl['message'], 'You\'re registered, but your team application didn\'t go through: A team needs a name.') === 0, wp_json_encode(array($to, $fl)));
$v = $page($st['st2']);
$check('...the card opens with what was typed', strpos($v, 'id="rsl-apply" open') !== false
    && strpos($segment($v, '<select name="team_level"', '</select>'), 'value="social" selected=\'selected\'') !== false && strpos($v, 'value="#654321"') !== false);
$check('...once: the next view has no draft', strpos($page($st['st2']), 'id="rsl-apply" open') === false);

// 5: a name already taken that season.
list($to, $fl) = $post_as($st['st3'], $reg_fields + array('route' => 'start', 'team_name' => "Startup $suffix", 'team_level' => 'new', 'team_season' => $S2));
$v = $page($st['st3']);
$check('26.5 a name already taken that season: the reason, and the card with the name and the season typed', $fl['type'] === 'error'
    && strpos($fl['message'], 'already a team called') !== false && $ends_apply($to) && strpos($v, 'value="Startup ' . $suffix . '"') !== false
    && strpos($segment($v, '<select name="team_season">', '</select>'), 'value="' . $S2 . '" selected=\'selected\'') !== false, wp_json_encode(array($to, $fl)));

// 6: Find me a team for a later season.
list($to, $fl) = $post_as($st['st4'], $reg_fields + array('route' => 'find', 'fa_season' => $S2, 'division_pref' => 'either', 'browsable' => '1'));
$park();
$fa4 = RSL_Free_Agents::get($st['st4'], $S2);
$check('26.6 Find me a team for a later season: on that season\'s list, not the sign-up season\'s', $fa4 && RSL_Free_Agents::is_open($fa4)
    && !RSL_Free_Agents::is_open(RSL_Free_Agents::get($st['st4'], $S1)) && strpos($fl['message'], 'on the free agent list for ' . RSL_Seasons::label($S2)) !== false, wp_json_encode($fl));
$v = $page($st['st4']);
$check('...the page shows it under Later season with its own cards, no rush to pay, and the find card offers the sign-up season only', strpos($v, 'Later season') !== false
    && strpos($v, "You're on the free agent list for 2091 Mid-season 2 (Winter)") !== false && strpos($v, 'id="rsl-fa-edit-' . $S2 . '"') !== false
    && strpos($v, 'no rush') !== false && strpos($v, 'name="fa_season" value="' . $S1 . '"') !== false && strpos($v, '<select name="fa_season">') === false);
list($to, $fl) = $post_as($st['st4'], array('do' => 'fa_leave', 'season' => $S2));
$fa4 = RSL_Free_Agents::get($st['st4'], $S2);
$check('...and leaving acts on that season\'s entry', $fa4 && $fa4->status === 'withdrawn', wp_json_encode($fl));

// 7: seasons that aren't open are refused.
list($to, $fl) = $post_as($st['st5'], $reg_fields + array('route' => 'find', 'fa_season' => $GONE, 'division_pref' => 'either'));
$check('26.7 a season that isn\'t open is refused at registration (the registration stands)', RSL_Players::is_registered($st['st5']) && $fl['type'] === 'error'
    && strpos($fl['message'], 'isn\'t open for sign-up') !== false && !RSL_Free_Agents::get($st['st5'], $GONE), wp_json_encode($fl));
list($to, $fl) = $post_as($st['st5'], array('do' => 'apply', 'team_name' => "Gone $suffix", 'team_level' => 'social', 'team_season' => $GONE));
$gone_named = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . RSL_Database::table('teams') . " WHERE season = %s AND name = %s", $GONE, "Gone $suffix"));
$check('...and on the application card, naming the season', $gone_named === 0 && !RSL_Teams::pending_application_for_user($st['st5'], $GONE)
    && $fl['message'] === '2090 Mid-season 2 isn\'t open for sign-up — pick one of the seasons listed.' && $ends_apply($to), wp_json_encode(array($to, $fl)));
list($to, $fl) = $post_as($st['st5'], array('do' => 'apply', 'team_name' => "Gone $suffix", 'team_level' => 'social', 'team_season' => 'nope'));
$check('...and a value that isn\'t a season', $fl['message'] === 'Pick one of the seasons listed.', $fl['message']);

// 8: the card's old field names, for a page opened before the update.
list($to, $fl) = $post_as($st['st5'], array('do' => 'apply', 'name' => "Legacy $suffix", 'division' => 'ranked', 'level' => 'solid', 'colour' => '#00aa00'));
$leg = RSL_Teams::pending_application_for_user($st['st5'], $S1);
$check('26.8 the card\'s old field names still work for one release', $leg && $leg->name === "Legacy $suffix" && $leg->est_level === 'solid'
    && $fl['message'] === "Application sent for Legacy $suffix. We'll email you when it's approved.", wp_json_encode($fl));

// 9: registration never borrows the player's own level (social is a team level too).
list($to, $fl) = $post_as($st['st6'], $reg_fields + array('route' => 'start', 'team_name' => "Borrow $suffix", 'team_season' => $S1));
$check('26.9 Start a team never takes the player\'s own level for the team\'s', $fl['type'] === 'error' && strpos($fl['message'], 'didn\'t go through') !== false
    && strpos($fl['message'], 'Pick roughly what level') !== false && !RSL_Teams::pending_application_for_user($st['st6'], $S1), wp_json_encode($fl));

// 10: a later season's team: its invite link works as soon as it's approved.
wp_set_current_user($admin);
$ok1 = $app1 ? RSL_Teams::approve($app1->id) : null;
$later_code = ($ok1 && !is_wp_error($ok1)) ? (string) $ok1->invite_code : '';
$by_code = $later_code !== '' ? RSL_Teams::get_by_code($later_code) : null;
$reg($st['st7']);
$joined7 = $later_code !== '' ? RSL_Teams::join_by_code($st['st7'], $later_code) : null;
$check('26.10 a later season\'s approved team: its invite link works straight away', $by_code && intval($by_code->id) === intval($app1->id)
    && in_array($S2, RSL_Teams::joinable_seasons(), true) && $joined7 && !is_wp_error($joined7) && RSL_Teams::is_member($app1->id, $st['st7']));
$check('...while a season that\'s neither played nor open still isn\'t joinable', RSL_Teams::get_by_code($rteam->invite_code) === null && !in_array($RUN, RSL_Teams::joinable_seasons(), true));
wp_set_current_user($admin);

// 11: no runs-out-soon nudge when the next season is already paid for.
RSL_Seasons::set_current($RUN);
$sq = $mk('sq'); $sr = $mk('sr');
$reg($sq);
$reg($sr);
$nteam = RSL_Teams::create($RUN, array('name' => "Nudge Team $suffix", 'division' => 'ranked'));
RSL_Teams::add_member($nteam->id, $sq, 'captain');
RSL_Teams::add_member($nteam->id, $sr);
foreach (array($sq, $sr) as $u) { RSL_Rego::grant($u, array('start' => $today, 'source' => 'manual', 'season' => $RUN)); }
RSL_Rego::grant($sq, array('source' => 'manual', 'season' => RSL_Seasons::next($RUN))); // = $season, undated: its quarter
$vq = $page($sq);
$vr = $page($sr);
$check('26.11 a season rego running out with the next season already paid: cleared, no nudge; without it, the nudge', RSL_Seasons::next($RUN) === $season
    && strpos($vq, 'You\'re cleared to play') !== false && strpos($vq, 'It runs out soon.') === false && strpos($vr, 'It runs out soon.') !== false
    && RSL_Rego::paid_ahead($sq, RSL_Rego::clearance_for($sq, $RUN), $RUN) === true && RSL_Rego::paid_ahead($sr, RSL_Rego::clearance_for($sr, $RUN), $RUN) === false,
    wp_json_encode(RSL_Rego::clearance_for($sq, $RUN)));
// 11b (0.18.0): this season's last night moved later after the sale. The season rego still counts for
// all of it, so with the next season paid there's still no nudge (without it, there is).
RSL_Courts::save_settings($RUN, array('start' => $day(-10), 'end' => $day(25)));
$vq = $page($sq);
$vr = $page($sr);
$cq = RSL_Rego::clearance_for($sq, $RUN);
$check('26.11b the season moved later after the sale: still no nudge with the next season paid (the other player still gets it)',
    $cq['until'] === $day(20) && RSL_Rego::paid_ahead($sq, $cq, $RUN) === true && strpos($vq, 'It runs out soon.') === false
    && strpos($vr, 'It runs out soon.') !== false && RSL_Rego::paid_ahead($sr, RSL_Rego::clearance_for($sr, $RUN), $RUN) === false, wp_json_encode($cq));
RSL_Courts::save_settings($RUN, array('start' => $day(-10), 'end' => $day(20)));
RSL_Seasons::set_current($season);

// 12: waiting for a team with nothing to pay online: the card says why.
$sw = $mk('sw');
$reg($sw);
RSL_Seasons::set_signup($OLD);
$cand_keys = array($OLD);
RSL_Free_Agents::join($sw, $OLD, array('division_pref' => 'either'), true);
update_option(RSL_Rego::PRODUCT_OPTION, 0);
$v = $page($sw);
$check('26.12 waiting for a team, no season open and no yearly product: no rush, and why it can\'t be paid online', strpos($v, 'no rush') !== false
    && strpos($v, 'No season is open for sign-up yet. Contact the league to pay.') !== false && strpos($v, 'add-to-cart=') === false);
update_option(RSL_Rego::SEASON_PRODUCT_OPTION, 0);
$v = $page($sw);
$check('...with no product at all: online payment isn\'t set up yet', strpos($v, 'no rush') !== false && strpos($v, 'Online payment isn\'t set up yet') !== false);
update_option(RSL_Rego::PRODUCT_OPTION, $year_id);
update_option(RSL_Rego::SEASON_PRODUCT_OPTION, $season_id);
RSL_Seasons::set_signup($S1);
$cand_keys = array($S1, $S2, $S3, $RUN, $GONE, $OLD);

// 13: the admin's 12-month rego says so only once it's saved, with its dates.
$sy = $mk('sy'); $sz = $mk('sz');
wp_set_current_user($admin);
$msg = $admin_do(array('do' => 'grant_rego', 'who' => "rslr-sy-$suffix@example.test", 'kind' => 'year', 'start' => $today));
$want = '12-month rego added for RSLR sy ' . $suffix . ' (' . date('j M Y', strtotime($today)) . ' – ' . date('j M Y', strtotime($today . ' +1 year -1 day')) . ').';
$check('26.13 the admin\'s 12-month rego: added, naming its dates', $msg === $want && count($rows_for($sy)) === 1, $msg);
$block_rego_insert = function ($q) {
    $t = '`' . RSL_Database::table('regos') . '`';
    return stripos(ltrim((string) $q), 'INSERT INTO ' . $t) === 0 ? str_replace($t, '`' . RSL_Database::table('regos') . '_unsaved`', $q) : $q;
};
$quiet_rego = $wpdb->suppress_errors(true);
add_filter('query', $block_rego_insert);
$msg = $admin_do(array('do' => 'grant_rego', 'who' => "rslr-sz-$suffix@example.test", 'kind' => 'year', 'start' => $today));
remove_filter('query', $block_rego_insert);
$block_rego_insert = null;
$wpdb->suppress_errors($quiet_rego);
$quiet_rego = null;
$check('...one that couldn\'t be saved says so, and adds nothing', $msg === 'That 12-month rego couldn\'t be added — please try again.' && count($rows_for($sz)) === 0, $msg);

// 14 (0.18.0): nothing open for sign-up (the sign-up season pinned to a finished one, no later season
// with dates). No team to start and no list to join: the forms aren't offered, a form sent anyway
// (an old page) is refused with the one sentence, and nothing is made in the finished season.
RSL_Seasons::set_signup($OLD);
$cand_keys = array($OLD);
$closed = 'Sign-ups for 1991 Pre-season have closed and nothing is open yet.';
$su = $mk('su'); // a profile, not registered
$sv = $mk('sv');
$reg($sv);
$v = $page($su);
$check('26.14 nothing open: the register form offers an invite code or Not yet only, names the league and says why',
    strpos($v, 'name="route" value="join"') !== false && strpos($v, 'name="route" value="later"') !== false
    && strpos($v, 'name="route" value="start"') === false && strpos($v, 'name="route" value="find"') === false
    && strpos($v, 'name="team_season"') === false && strpos($v, 'name="fa_season"') === false
    && strpos($v, 'How are you joining the Reds Social League?') !== false && strpos($v, esc_html($closed)) !== false
    && strpos($v, 'No problem — you can join a team with an invite code from this page any time.') !== false && strpos($v, 'start one or ask us to find you one') === false);
list($to, $fl) = $post_as($su, $reg_fields + array('route' => 'find', 'fa_season' => $OLD));
$check('...a Find me a team sent anyway: registered, not on the list, and the sentence', RSL_Players::is_registered($su) && !RSL_Free_Agents::get($su, $OLD)
    && $fl['message'] === 'You\'re registered, but we couldn\'t put you on the free agent list: ' . $closed, $fl['message']);
$v = $page($sv);
$check('...the league page: the sentence where Find me a team was, and no application card',
    strpos($v, esc_html($closed)) !== false && strpos($v, 'id="rsl-apply"') === false && strpos($v, 'Put me on the free agent list') === false);
list($to, $fl) = $post_as($sv, array('do' => 'apply', 'team_name' => "Closed Team $suffix", 'team_level' => 'social', 'team_season' => $OLD));
list($to2, $fl2) = $post_as($sv, array('do' => 'apply', 'team_name' => "Closed Team $suffix", 'team_level' => 'social'));
$check('...an application for the finished season, or with none named: refused with the sentence, no card to go back to, nothing made',
    $fl['message'] === $closed && $fl2['message'] === $closed && !$ends_apply($to) && !$ends_apply($to2) && $apps_of($sv, $OLD) === 0
    && get_transient(RSL_Portal::APPLY_DRAFT_PREFIX . $sv) === false, $fl['message'] . ' / ' . $to);
list($to, $fl) = $post_as($sv, array('do' => 'fa_join', 'fa_season' => $OLD));
$check('...and so is joining the free agent list', $fl['message'] === $closed && !RSL_Free_Agents::get($sv, $OLD), $fl['message']);
// Tag ss, not sk: the season rego section's loop already made an account tagged sk.
$ss = $mk('ss'); // a profile, not registered
list($to, $fl) = $post_as($ss, $reg_fields + array('route' => 'later'));
$check('...registering with Not yet: the thank-you names an invite code only', RSL_Players::is_registered($ss)
    && $fl['message'] === 'Thanks — you\'re registered for the Reds Social League. Whenever you\'re ready, join a team with an invite code from this page.', $fl['message']);
$joined = RSL_Free_Agents::join($sv, $OLD, array('division_pref' => 'either'), true); // directly (no mail), as an admin script could
list($to, $fl) = $post_as($sv, array('do' => 'fa_leave', 'season' => $OLD));
$check('...leaving a list while nothing is open: no promise to rejoin', !is_wp_error($joined) && $fl['type'] === 'success'
    && $fl['message'] === 'You\'ve left the free agent list.', is_wp_error($joined) ? $joined->get_error_message() : $fl['message']);
RSL_Seasons::set_signup($S1);
$cand_keys = array($S1, $S2, $S3, $RUN, $GONE, $OLD);

} catch (Throwable $t) {
    $fails++;
    echo 'FAIL: threw ' . get_class($t) . ': ' . $t->getMessage() . ' (line ' . $t->getLine() . ")\n";
} finally {
// --- Cleanup (runs after a failure too) --------------------------------------------------------------
if ($cart_test && function_exists('WC') && WC()->cart) {
    WC()->cart->empty_cart(false); // in memory only: the shutdown saves were removed, and no one's saved cart is touched
    wc_clear_notices();
}
wp_set_current_user(0);
$_GET = array();
$_REQUEST = array();
if ($block_rego_insert) {
    remove_filter('query', $block_rego_insert);
}
if ($quiet_rego !== null) {
    $wpdb->suppress_errors($quiet_rego);
}
// The mail stop ($mail) stays on to the very end: deleting the accounts below must send nothing.
if ($cands) {
    remove_filter('rsl_open_for_signup_candidates', $cands);
}
foreach (array_merge($order ? array($order) : array(), $orders) as $o) {
    $o = wc_get_order($o->get_id());
    if (!$o) {
        continue;
    }
    foreach ($o->get_refunds() as $refund) { $refund->delete(true); }
    $wpdb->delete(RSL_Database::table('regos'), array('order_id' => $o->get_id()));
    $o->delete(true);
}
foreach ($products as $p) { $p->delete(true); }
foreach (array_unique(array_merge(array($season), $test_seasons)) as $s_key) {
    $team_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM " . RSL_Database::table('teams') . " WHERE season = %s", $s_key));
    foreach ($team_ids as $tid) {
        $wpdb->delete(RSL_Database::table('team_members'), array('team_id' => $tid));
        $wpdb->delete(RSL_Database::table('invites'), array('team_id' => $tid));
    }
    $wpdb->delete(RSL_Database::table('teams'), array('season' => $s_key));
    $wpdb->delete(RSL_Database::table('free_agents'), array('season' => $s_key));
    $wpdb->delete(RSL_Database::table('join_requests'), array('season' => $s_key));
}
$wpdb->query($wpdb->prepare("DELETE FROM " . RSL_Database::table('outbox') . " WHERE to_email LIKE %s", '%-' . $suffix . '@example.test'));
// The send run back as it was: soon when one was due or other mail is waiting.
wp_clear_scheduled_hook(RSL_Mail::SEND_HOOK);
if ($send_was || RSL_Mail::counts()['queued'] > 0) {
    RSL_Mail::schedule_send();
}
wp_clear_scheduled_hook('rsl_hourly');
wp_schedule_event(time() + 300, 'hourly', 'rsl_hourly'); // as rsl_schedule_cron() does
$wpdb->query($wpdb->prepare("DELETE FROM " . RSL_Database::table('regos') . " WHERE id > %d", $rego_id_before));
// Ultimate Member emails an account deleted notice from delete_user: not for throwaway accounts.
if (function_exists('UM')) {
    UM()->user()->send_mail_on_delete = false;
}
foreach ($users as $u) {
    if (!is_int($u) || $u <= 0) {
        continue; // never a WP_Error: as a user_id it would match the rows with user_id 0
    }
    foreach (array('players', 'regos', 'team_members', 'invites') as $t) { $wpdb->delete(RSL_Database::table($t), array('user_id' => $u)); }
    $wpdb->delete($mem, array('user_id' => $u));
    delete_transient(RSL_Portal::FLASH_PREFIX . $u);
    delete_transient(RSL_Portal::APPLY_DRAFT_PREFIX . $u);
    delete_transient(RSL_Admin::NOTICE_PREFIX . $u);
    delete_transient('rsl_mail_failed_' . $u); // approve() / decline() under a mail stop: the email "failed"
    wp_delete_user($u);
}
foreach ($saved as $opt => $value) { if ($value === '__unset__') { delete_option($opt); } else { update_option($opt, $value); } }
remove_filter('pre_wp_mail', $mail, 10);
}
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
