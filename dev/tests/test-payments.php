<?php
/**
 * Team balances: game fees charged when fixtures are published, "can't
 * make it" before/after the cut-off (a late fee per game, kept in step
 * with results; no withdrawing once the night starts; only the first team
 * out of a game pays), charge keys and their backfill, publish/unpublish
 * guards, not fixtured when owing too much, online top-ups (WooCommerce),
 * "my share of the season", admin adjustments, carrying a balance to next
 * season, who sees what. Refunds: test-refunds.php.
 *
 *   wp eval-file dev/tests/test-payments.php
 */

if (!defined('WP_CLI') || !WP_CLI || (wp_get_environment_type() === 'production' && !getenv('RSL_DEV'))) {
    exit("Local copy only: run with wp eval-file, with RSL_DEV=1 set (docker exec -e RSL_DEV=1 ...).\n");
}
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once dirname(__DIR__) . '/lib-mail-stop.php';
global $wpdb;

$season = '2089-pre';
$suffix = time();
$hold = rsl_dev_hold_mail($suffix);
$fails = 0;
$check = function ($label, $ok, $detail = '') use (&$fails, $hold) {
    rsl_dev_park_mail($hold);
    if (!$ok) { $fails++; }
    echo ($ok ? "OK: $label\n" : "FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n");
};
$saved = array(
    'current' => get_option(RSL_Seasons::CURRENT_OPTION, '__unset__'),
    'settings' => get_option(RSL_Payments::SETTINGS_OPTION, '__unset__'),
    'product' => get_option(RSL_Payments::PRODUCT_OPTION, '__unset__'),
);
// Set before the try, so the cleanup can always run.
$users = array();
$product = null;
$order = null;
$old = null;
$incl = null;
update_option(RSL_Seasons::CURRENT_OPTION, $season);
delete_option(RSL_Payments::SETTINGS_OPTION);
add_filter('pre_wp_mail', '__return_true');
$parked = $wpdb->get_col("SELECT id FROM " . RSL_Database::table('venues') . " WHERE active = 1");
if ($parked) { $wpdb->query("UPDATE " . RSL_Database::table('venues') . " SET active = 0 WHERE id IN (" . implode(',', array_map('intval', $parked)) . ")"); }

try {

$hall = RSL_Courts::create_venue("Pay Hall $suffix", 'ranked');
foreach (array('P1', 'P2') as $c) { RSL_Courts::create_court($hall->id, $c, '19:00', '22:00'); }
$today = current_time('Y-m-d');
RSL_Courts::save_settings($season, array('start' => date('Y-m-d', strtotime($today . ' +5 days')), 'end' => date('Y-m-d', strtotime($today . ' +30 days')), 'weekday' => 0, 'minutes' => array('ranked' => 60, 'unranked' => 60)));
RSL_Courts::generate_nights($season);
$nights = RSL_Courts::nights($season);
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
// Only the first team out of a game pays for it: D pulls out early (free), then C late — the game was already off.
RSL_Payments::save_settings(array('game_fee' => 75, 'free_cancel_hours' => 0, 'max_owing' => 75));
$first = RSL_Requests::request($T(3), $n1->id, 'cant_make', $caps[3]);
RSL_Payments::save_settings(array('game_fee' => 75, 'free_cancel_hours' => 336, 'max_owing' => 75));
$second = RSL_Requests::request($T(2), $n1->id, 'cant_make', $caps[2]);
$check('pulling out late of a game the other team had already left (for free) costs nothing', !is_wp_error($first) && !$first->late && !is_wp_error($second) && (int) $second->late === 1
    && $bal(2) == $before[0] + 75 && $bal(3) == $before[1] + 75
    && !$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . RSL_Database::table('ledger') . " WHERE type = 'late_fee' AND request_id = %d", $second->id)), $bal(2) . ' / ' . $bal(3));
$check('...and the late fee it carries (what the captain is told) is nothing', !is_wp_error($second) && RSL_Payments::late_fees_for_request($second->id) == 0);
wp_set_current_user($admin);
$_GET = array('page' => 'rsl-fixtures', 'season' => $season, 'night' => $n1->id);
ob_start(); (new RSL_Admin_Fixtures())->render(); $fhtml = ob_get_clean();
$check('...and the admin\'s night view says so (not "they pay both teams\' fees")', strpos($fhtml, 'no late fee: the other team was out first') !== false && strpos($fhtml, 'they pay both teams') === false);
$_GET = array();
wp_set_current_user(0);
RSL_Requests::cancel($first->id, $caps[3]);
$check('...but once the first one is withdrawn, the late one is the first out and pays', $bal(2) == $before[0] + 75 - 150 && $bal(3) == $before[1] + 75, $bal(2) . ' / ' . $bal(3));
$check('...and now carries the $150 late fee', RSL_Payments::late_fees_for_request($second->id) == 150);
RSL_Requests::cancel($second->id, $caps[2]);
$check('...and withdrawing it too puts the fees back', $bal(2) == $before[0] && $bal(3) == $before[1]);
RSL_Payments::save_settings(array('game_fee' => 75, 'free_cancel_hours' => 48, 'max_owing' => 75));

// --- Every charge is keyed, so nothing is charged twice -----------------------------------------------
$ledger = RSL_Database::table('ledger');
$keys = $wpdb->get_col($wpdb->prepare("SELECT charge_key FROM $ledger WHERE night_id = %d AND type = 'game'", $n1->id));
$check('every game charge has a charge key (team + game)', count($keys) === 4 && !in_array(null, $keys, true) && in_array('game:' . $T(0) . ':' . $g1->id, $keys, true));
$wpdb->suppress_errors(true);
$dup = $wpdb->insert($ledger, array('team_id' => $T(0), 'season' => $season, 'type' => 'game', 'amount' => -75, 'game_id' => $g1->id, 'night_id' => $n1->id, 'charge_key' => 'game:' . $T(0) . ':' . $g1->id));
$wpdb->suppress_errors(false);
$check('...so an overlapping sync can\'t write the same charge twice (the database refuses it)', $dup === false);
$b0 = $bal(0);
$wpdb->insert($ledger, array('team_id' => $T(0), 'season' => $season, 'type' => 'game', 'amount' => -75, 'game_id' => $g1->id, 'night_id' => $n1->id));
RSL_Payments::sync_night($n1->id);
$check('a double charge from before keys is removed by the next sync', $bal(0) == $b0);
$wpdb->query($wpdb->prepare("UPDATE $ledger SET charge_key = NULL WHERE night_id = %d", $n1->id));
$wpdb->insert($ledger, array('team_id' => $T(1), 'season' => $season, 'type' => 'game', 'amount' => -75, 'game_id' => $g1->id, 'night_id' => $n1->id));
$b1 = $bal(1);
RSL_Payments::backfill_charge_keys();
$unkeyed = function () use ($wpdb, $ledger, $n1) { return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $ledger WHERE night_id = %d AND charge_key IS NULL", $n1->id)); };
$check('backfill: keys older rows and drops the duplicate', $unkeyed() === 0 && $bal(1) == $b1 + 75 && $bal(0) == $b0);
RSL_Payments::backfill_charge_keys();
$check('...and is safe to run again', $unkeyed() === 0 && $bal(1) == $b1 + 75 && $bal(0) == $b0);

// --- Late "can't make it": a fee per game, kept in step with the fixture and results ------------------
$sent_to = array();
$catch_mail = function ($r, $atts) use (&$sent_to) { $sent_to[] = $atts['to']; return $r; };
add_filter('pre_wp_mail', $catch_mail, 5, 2);
RSL_Payments::save_settings(array('free_cancel_hours' => 336, 'contact_emails' => 'league@example.test, not-an-email; fixtures@example.test'));
$check('league contact emails are saved (bad ones dropped), other rules kept', RSL_Payments::settings()['contact_emails'] === 'league@example.test, fixtures@example.test'
    && RSL_Payments::settings()['free_cancel_hours'] === 336 && RSL_Payments::settings()['game_fee'] == 75);
$n3 = $nights[2];
$d1 = RSL_Fixtures::add_game($n3->id, array('home' => $T(0), 'away' => $T(1), 'court_id' => $c1, 'start' => '19:00'));
$d2 = RSL_Fixtures::add_game($n3->id, array('home' => $T(0), 'away' => $T(2), 'court_id' => $c1, 'start' => '20:00')); // A plays twice
RSL_Fixtures::publish_night($n3->id);
$b = array($bal(0), $bal(1), $bal(2));
// Move the night to tomorrow so "can't make it" is past the cut-off (at most 336 hours).
$wpdb->update(RSL_Database::table('nights'), array('night_date' => date('Y-m-d', strtotime($today . ' +1 day'))), array('id' => $n3->id));
$n3 = RSL_Courts::get_night($n3->id);
$late = RSL_Requests::request($T(0), $n3->id, 'cant_make', $caps[0]);
$late_rows = function () use ($wpdb, $ledger, $n3) { return $wpdb->get_col($wpdb->prepare("SELECT charge_key FROM $ledger WHERE night_id = %d AND type = 'late_fee' ORDER BY charge_key", $n3->id)); };
$check('late with a double-header: both games\' fees off, a late fee for each game ($300)', !is_wp_error($late) && (int) $late->late === 1 && $bal(0) == $b[0] + 150 - 300, (string) $bal(0));
$check('...one keyed late fee per game', $late_rows() === array('late:' . $late->id . ':' . $d1->id, 'late:' . $late->id . ':' . $d2->id), implode(', ', $late_rows()));
$check('...neither opponent pays', $bal(1) == $b[1] + 75 && $bal(2) == $b[2] + 75);
$newest = $wpdb->get_var($wpdb->prepare("SELECT created_at FROM $ledger WHERE team_id = %d ORDER BY id DESC LIMIT 1", $T(0)));
$check('charges and requests are stamped in the site\'s local time (not the database server\'s)', abs(strtotime($newest) - current_time('timestamp')) < 300
    && abs(strtotime($late->requested_at) - current_time('timestamp')) < 300, $newest . ' / ' . $late->requested_at . ' vs ' . current_time('mysql'));
$check('the "can\'t make it" email goes to the league contacts', end($sent_to) === array('league@example.test', 'fixtures@example.test'));
RSL_Payments::save_settings(array('contact_emails' => ''));
$check('...or, with none set, the site admin', RSL_Payments::contact_emails() === array(get_option('admin_email')));
remove_filter('pre_wp_mail', $catch_mail, 5);
RSL_Fixtures::publish_night($n3->id, false);
$check('unpublishing takes the late fees off too', $late_rows() === array() && $bal(0) == $b[0] + 150);
$check('a week off can\'t replace a live "can\'t make it"', is_wp_error(RSL_Requests::request($T(0), $n3->id, 'off', $caps[0])) && RSL_Requests::get($late->id)->status === 'active');
RSL_Fixtures::publish_night($n3->id);
$check('republishing brings them back', count($late_rows()) === 2 && $bal(0) == $b[0] - 150);
RSL_Fixtures::record_result($d1->id, '25-20, 25-22');
$check('a game played after all is charged to both teams, with no late fee for it', $bal(0) == $b[0] - 75 && $bal(1) == $b[1] && count($late_rows()) === 1, $bal(0) . ' / ' . $bal(1));
RSL_Fixtures::record_forfeit($d2->id, $T(0));
$check('a forfeit by the team that pulled out never charges its opponent', $bal(2) == $b[2] + 75 && $bal(0) == $b[0] - 75);
$check('the captain can\'t withdraw once a result is in', is_wp_error(RSL_Requests::cancel($late->id, $caps[0])) && RSL_Requests::get($late->id)->status === 'active');
RSL_Fixtures::clear_result($d1->id);
RSL_Fixtures::clear_result($d2->id);
$check('clearing the results puts the late fees back', $bal(0) == $b[0] - 150 && $bal(1) == $b[1] + 75 && count($late_rows()) === 2);
$wpdb->update(RSL_Database::table('nights'), array('night_date' => date('Y-m-d', strtotime($today . ' -1 day'))), array('id' => $n3->id));
$check('...nor after the night (no dodging the late fee)', is_wp_error(RSL_Requests::cancel($late->id, $caps[0])) && count($late_rows()) === 2 && $bal(0) == $b[0] - 150);
$wpdb->update(RSL_Database::table('nights'), array('night_date' => $today), array('id' => $n3->id));
$wpdb->update(RSL_Database::table('games'), array('start_time' => '00:00:00'), array('id' => $d1->id));
$e = RSL_Requests::cancel($late->id, $caps[0]);
$check('...nor once the night has started', is_wp_error($e) && $e->get_error_code() === 'rsl_started' && RSL_Requests::get($late->id)->status === 'active');
$wpdb->update(RSL_Database::table('nights'), array('night_date' => $n3->night_date), array('id' => $n3->id));
$wpdb->update(RSL_Database::table('games'), array('start_time' => '19:00:00'), array('id' => $d1->id));
$check('an admin can still withdraw it: fees back to normal', RSL_Requests::cancel($late->id, $admin) === true && $bal(0) == $b[0] && $bal(1) == $b[1] && $bal(2) == $b[2]);
$h = RSL_Requests::request($T(0), $n3->id, 'cant_make', $caps[0]);
RSL_Requests::mark_handled($h->id, $admin);
$check('a handled "can\'t make it" is locked for the captain', is_wp_error(RSL_Requests::cancel($h->id, $caps[0])) && $bal(0) == $b[0] - 150);
wp_set_current_user($admin);
$fx = function ($do, $night_id, $extra = array()) use ($season) {
    return RSL_Admin_Fixtures::process(array_merge(array('rsl_nonce' => wp_create_nonce('rsl_fixtures_action'), 'season' => $season, 'night_id' => $night_id, 'do' => $do), $extra));
};
$fx_notice = function () use ($admin) { $n = get_transient(RSL_Admin::NOTICE_PREFIX . $admin); delete_transient(RSL_Admin::NOTICE_PREFIX . $admin); return is_array($n) ? $n : array('type' => '', 'message' => ''); };
$fx('withdraw_request', $n3->id, array('request_id' => $h->id));
$check('...but an admin can withdraw it from the Fixtures page (they played after all)', RSL_Requests::get($h->id)->status === 'cancelled' && $bal(0) == $b[0]);
$wpdb->update(RSL_Database::table('nights'), array('type' => 'bye'), array('id' => $n3->id));
RSL_Payments::sync_night($n3->id);
$check('a night made a bye has no charges', $bal(0) == $b[0] + 150 && $bal(1) == $b[1] + 75);
$wpdb->update(RSL_Database::table('nights'), array('type' => 'regular'), array('id' => $n3->id));
RSL_Payments::sync_night($n3->id);
$check('...and they come back with the night', $bal(0) == $b[0] && $bal(1) == $b[1]);

// --- Publishing and unpublishing guards -----------------------------------------------------------------
RSL_Fixtures::record_result($d1->id, '25-20, 25-22');
$fx_notice();
$fx('unpublish', $n3->id);
$n = $fx_notice();
$check('a night with results can\'t be unpublished (it would refund its fees)', RSL_Fixtures::is_published(RSL_Courts::get_night($n3->id)) && $n['type'] === 'error' && strpos($n['message'], 'results') !== false && $bal(1) == $b[1]);
// A night that's been played but has no results in yet (the refs didn't save) is just as protected.
$wpdb->update(RSL_Database::table('nights'), array('night_date' => date('Y-m-d', strtotime($today . ' -1 day'))), array('id' => $n1->id));
$b_played = array($bal(1), $bal(3));
$fx('unpublish', $n1->id);
$n = $fx_notice();
$check('a night that has been played can\'t be unpublished, results or not', RSL_Fixtures::is_published(RSL_Courts::get_night($n1->id)) && $n['type'] === 'error'
    && strpos($n['message'], 'already been played') !== false && $bal(1) == $b_played[0], $n['message']);
$_GET = array('page' => 'rsl-fixtures', 'season' => $season, 'night' => $n1->id);
ob_start(); (new RSL_Admin_Fixtures())->render(); $fhtml = ob_get_clean();
$check('...its night view offers only "Called off? Unpublish"', strpos($fhtml, 'Called off? Unpublish') !== false && strpos($fhtml, '>Unpublish</button>') === false);
$_GET = array();
$fx('unpublish', $n1->id, array('called_off' => 1));
$n = $fx_notice();
$check('...which takes its fees off (the night didn\'t go ahead)', !RSL_Fixtures::is_published(RSL_Courts::get_night($n1->id)) && $n['type'] === 'success'
    && $bal(1) == $b_played[0] + 75 && $bal(3) == $b_played[1] + 75, $bal(1) . ' / ' . $bal(3));
RSL_Fixtures::publish_night($n1->id);
$wpdb->update(RSL_Database::table('nights'), array('night_date' => $n1->night_date), array('id' => $n1->id));
$n2 = $nights[1];
$wg = RSL_Fixtures::add_game($n2->id, array('home' => $T(2), 'away' => $T(3), 'court_id' => $c1, 'start' => '19:00'));
RSL_Requests::request($T(2), $n2->id, 'off', $caps[2]);
$fx('publish', $n2->id);
$n = $fx_notice();
$check('publishing waits while a team on a week off still has a game in the draft', !RSL_Fixtures::is_published(RSL_Courts::get_night($n2->id)) && $n['type'] === 'error' && strpos($n['message'], "Pay C $suffix") !== false, $n['message']);
$_GET = array('page' => 'rsl-fixtures', 'season' => $season, 'night' => $n2->id);
ob_start(); (new RSL_Admin_Fixtures())->render(); $fhtml = ob_get_clean();
$check('...and the night view says it\'s still in the draft', strpos($fhtml, 'still has a game in this draft') !== false);
$_GET = array();
RSL_Fixtures::delete_game($wg->id);
wp_set_current_user(0);
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
$mate2 = $mk('mate2');
RSL_Teams::add_member($teams[1]->id, $mate2);
wp_set_current_user($mate2);
$card = RSL_Payments::team_card(RSL_Teams::get($T(1)));
$check('other players see "Topped up" in the history, not who paid', strpos($card, 'Topped up') !== false && strpos($card, 'RSLM mate') === false);
RSL_Payments::adjust($T(0), 5, 'Kit refund for a player', $admin);
wp_set_current_user($mate);
$card = RSL_Payments::team_card(RSL_Teams::get($T(0)));
$check('...and "Adjustment by the league", not the admin\'s note', strpos($card, 'Adjustment by the league') !== false && strpos($card, 'Kit refund for a player') === false);
wp_set_current_user($caps[0]);
$check('the captain sees the note', strpos(RSL_Payments::team_card(RSL_Teams::get($T(0))), 'Kit refund for a player') !== false);
RSL_Payments::adjust($T(3), -200, 'Test owing', $admin);
wp_set_current_user($caps[3]);
$check('a team owing too much is told it won\'t be fixtured', strpos(RSL_Payments::team_card(RSL_Teams::get($T(3))), 'won\'t be fixtured') !== false);

// --- "My share of the season": a fair part of what's still needed, less what you've put in ---------------
$fs = RSL_Payments::fair_shares(600, array_fill_keys(range(1, 8), 0));
$check('my share: nobody has paid yet — an even split', count(array_filter($fs, function ($s) { return abs($s - 75) < 0.001; })) === 8);
$fs = RSL_Payments::fair_shares(525, array(1 => 75) + array_fill_keys(range(2, 8), 0));
$check('...someone who has paid theirs is asked for nothing, and everyone else\'s stays the same', abs($fs[1]) < 0.001 && abs($fs[2] - 75) < 0.001 && abs($fs[8] - 75) < 0.001);
$put = array_fill_keys(range(1, 8), 0.0);
$need = 600.0;
foreach (array_keys($put) as $u) {
    $s = ceil(round(RSL_Payments::fair_shares($need, $put)[$u], 2));
    $put[$u] += $s;
    $need -= $s;
}
$check('...so everyone paying "my share" in turn covers the season', $need <= 0.001 && array_sum($put) >= 600, (string) $need);
$fs = RSL_Payments::fair_shares(300, array(1 => 300) + array_fill_keys(range(2, 8), 0));
$check('...and one player paying more than their part never has the rest pay in more than the team needs', abs($fs[1]) < 0.001 && abs(array_sum($fs) - 300) < 0.001);
$check('...nothing when the season is covered', array_sum(RSL_Payments::fair_shares(0, array(1 => 0, 2 => 0))) == 0);
$teamE = RSL_Teams::create($season, array('name' => "Pay E $suffix", 'division' => 'ranked', 'level' => 'solid'));
$teams[] = $teamE; // cleaned up with the others
$capE = $mk('capE');
$mateE = $mk('mateE');
RSL_Teams::add_member($teamE->id, $capE, 'captain');
RSL_Teams::add_member($teamE->id, $mateE);
$my_share = function ($uid) use ($teamE) {
    wp_set_current_user($uid);
    return preg_match('/name="amount" value="([\d.]+)">\s*<button[^>]*>[^<]*<\/button><small>My share/', RSL_Payments::team_card(RSL_Teams::get($teamE->id)), $m) ? (float) $m[1] : 0.0;
};
$even = ceil(RSL_Payments::nights_to_come($season) * 75 / 2);
$check('the team card offers each player their share of the rest of the season', $even >= 5 && $my_share($mateE) == $even && $my_share($capE) == $even, $my_share($mateE) . ' vs ' . $even);
RSL_Payments::record_payment($teamE->id, $even, $mateE);
$check('...and once they have paid it, not again — while a teammate\'s share stays the same', $my_share($mateE) == 0 && abs($my_share($capE) - $even) <= 1, $my_share($mateE) . ' / ' . $my_share($capE));

// --- Admin ------------------------------------------------------------------------------------------------
wp_set_current_user($admin);
RSL_Admin_Payments::process(array('rsl_nonce' => wp_create_nonce('rsl_payments_action'), 'do' => 'adjust', 'team_id' => $T(3), 'amount' => '200', 'note' => 'Bank transfer'));
$check('admin records a payment with a note', $bal(3) > -200);
// 0.17.0: a long note is cut on a whole character (a byte cut could leave half of one).
$long_id = RSL_Payments::adjust($T(2), 1, str_repeat('ü', 200), $admin);
$long_note = $long_id && !is_wp_error($long_id) ? (string) $wpdb->get_var($wpdb->prepare("SELECT note FROM " . RSL_Database::table('ledger') . " WHERE id = %d", $long_id)) : '';
$check('a long ledger note fits the column on a whole character', $long_note !== '' && strlen($long_note) <= 255 && strlen($long_note) % 2 === 0 && preg_match('//u', $long_note) === 1,
    strlen($long_note) . ' bytes');
$_GET = array('page' => 'rsl-balances');
ob_start(); (new RSL_Admin_Payments())->render(); $html = ob_get_clean();
$check('admin overview lists every team\'s balance and the rules', strpos($html, "Pay A $suffix") !== false && strpos($html, 'Top-up product') !== false);
$_GET = array('page' => 'rsl-balances', 'team' => $T(1));
ob_start(); (new RSL_Admin_Payments())->render(); $html = ob_get_clean();
$check('a team\'s history shows game fees and payments', strpos($html, 'Game fee') !== false && strpos($html, 'Topped up by RSLM mate') !== false);

// --- Season rollover ----------------------------------------------------------------------------------------
$old = RSL_Teams::create('2088-post', array('name' => "Pay Old $suffix", 'division' => 'ranked'));
RSL_Payments::adjust($old->id, 40, 'Left-over credit', $admin);
$listed = function () use ($season, $old) {
    return (bool) array_filter(RSL_Payments::earlier_balances($season), function ($r) use ($old) { return intval($r->team->id) === intval($old->id) && $r->balance == 40; });
};
$check('an earlier season\'s uncleared balance is listed', $listed());
$_GET = array('page' => 'rsl-balances');
ob_start(); (new RSL_Admin_Payments())->render(); $html = ob_get_clean();
$check('...on the overview, with a carry-over form', strpos($html, 'Earlier seasons') !== false && strpos($html, "Pay Old $suffix") !== false && strpos($html, 'value="carry"') !== false);
$check('a balance only carries on to a later season', is_wp_error(RSL_Payments::carry_balance($T(0), $old->id, $admin)));
$b0 = $bal(0);
RSL_Admin_Payments::process(array('rsl_nonce' => wp_create_nonce('rsl_payments_action'), 'do' => 'carry', 'team_id' => $old->id, 'to_team_id' => $T(0)));
$check('admin carries it to this season\'s team (a row on each)', RSL_Payments::balance($old->id) == 0 && $bal(0) == $b0 + 40, RSL_Payments::balance($old->id) . ' / ' . $bal(0));
$check('...once: a second submit carries nothing', is_wp_error(RSL_Payments::carry_balance($old->id, $T(0), $admin)) && $bal(0) == $b0 + 40);
$check('...and it drops off the list', !$listed());

} catch (Throwable $thrown) {
    $fails++;
    echo 'FAIL: threw ' . get_class($thrown) . ': ' . $thrown->getMessage() . ' (line ' . $thrown->getLine() . ")\n";
} finally {
// --- Cleanup (runs after a failure too) ------------------------------------------------------------------
$_GET = array();
wp_set_current_user(0);
remove_filter('pre_wp_mail', '__return_true');
if ($incl !== null) {
    update_option('woocommerce_prices_include_tax', $incl); // as it was, wherever the test stopped
}
if (is_object($order)) {
    $order->delete(true);
}
if (is_object($product) && $product->get_id()) {
    $product->delete(true);
}
// Every team in the test's seasons (however far the test got): its rows, then the team.
foreach (array($season, '2088-post') as $s_key) {
    foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM " . RSL_Database::table('teams') . " WHERE season = %s", $s_key)) as $tid) {
        $wpdb->delete(RSL_Database::table('ledger'), array('team_id' => $tid));
        $wpdb->delete(RSL_Database::table('requests'), array('team_id' => $tid));
        $wpdb->delete(RSL_Database::table('team_members'), array('team_id' => $tid));
        $wpdb->delete(RSL_Database::table('teams'), array('id' => $tid));
    }
}
$wpdb->delete(RSL_Database::table('ledger'), array('season' => $season));
$wpdb->delete(RSL_Database::table('games'), array('season' => $season));
foreach (RSL_Courts::nights($season) as $n) {
    $wpdb->delete(RSL_Database::table('night_courts'), array('night_id' => $n->id));
}
$wpdb->delete(RSL_Database::table('nights'), array('season' => $season));
RSL_Ratings::forget_start($season); // publishing a Ranked night froze the ladder's start
$settings = get_option(RSL_Courts::SETTINGS_OPTION, array());
if (is_array($settings) && isset($settings[$season])) {
    unset($settings[$season]);
    update_option(RSL_Courts::SETTINGS_OPTION, $settings, false);
}
// Every venue the test made (each name ends with the suffix), with its courts; then the real ones back.
foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM " . RSL_Database::table('venues') . " WHERE name LIKE %s", '% ' . $wpdb->esc_like((string) $suffix))) as $vid) {
    $wpdb->delete(RSL_Database::table('courts'), array('venue_id' => $vid));
    $wpdb->delete(RSL_Database::table('venues'), array('id' => $vid));
}
if ($parked) { $wpdb->query("UPDATE " . RSL_Database::table('venues') . " SET active = 1 WHERE id IN (" . implode(',', array_map('intval', $parked)) . ")"); }
rsl_dev_delete_users($users);
foreach (array('current' => RSL_Seasons::CURRENT_OPTION, 'settings' => RSL_Payments::SETTINGS_OPTION, 'product' => RSL_Payments::PRODUCT_OPTION) as $k => $opt) {
    if ($saved[$k] === '__unset__') { delete_option($opt); } else { update_option($opt, $saved[$k], false); }
}
rsl_dev_release_mail($hold);
}
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
