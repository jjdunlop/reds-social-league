<?php
/**
 * Courts and game nights: venues and courts with usual hours, season dates
 * and per-division game lengths, generated nights (kept on re-save),
 * per-night exceptions, the slot maths, copy-forward, and the admin screens.
 *
 *   wp eval-file dev/tests/test-courts.php
 */

if (!defined('WP_CLI') || !WP_CLI) {
    exit("Run with wp eval-file.\n");
}
require_once ABSPATH . 'wp-admin/includes/user.php';
global $wpdb;

$season = '2092-mid1';
$suffix = time();
$fails = 0;
$check = function ($label, $ok, $detail = '') use (&$fails) {
    if (!$ok) { $fails++; }
    echo ($ok ? "OK: $label\n" : "FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n");
};
$saved_settings = get_option(RSL_Courts::SETTINGS_OPTION, '__unset__');

// --- Slot maths --------------------------------------------------------------------
$check('60-minute games, 7–11pm: four slots', RSL_Courts::slot_times('19:00', '23:00', 60) === array(array('19:00', '20:00'), array('20:00', '21:00'), array('21:00', '22:00'), array('22:00', '23:00')));
$check('50-minute games only count whole games', count(RSL_Courts::slot_times('18:00', '22:00', 50)) === 4 && RSL_Courts::slot_times('18:00', '22:00', 50)[3] === array('20:30', '21:20'));
$check('too short for a game: no slots', RSL_Courts::slot_times('19:00', '19:30', 60) === array());
$check('pretty times', RSL_Courts::pretty_time('19:00') === '7pm' && RSL_Courts::pretty_time('19:30') === '7:30pm');

// --- Venues and courts ----------------------------------------------------------------
$v1 = RSL_Courts::create_venue("Ranked Venue $suffix", 'ranked');
$v2 = RSL_Courts::create_venue("Unranked Venue $suffix", 'unranked');
$check('venues created with their home division', $v1 && $v1->division === 'ranked' && $v2->division === 'unranked');
$check('venue needs a name', is_wp_error(RSL_Courts::create_venue('  ')));
$c1 = RSL_Courts::create_court($v1->id, 'Court 1', '19:00', '23:00');
$c2 = RSL_Courts::create_court($v1->id, 'Court 2', '19:00', '21:00');
$c3 = RSL_Courts::create_court($v2->id, 'Court A', '18:00', '22:00');
$check('courts with their usual hours', $c2 && RSL_Courts::hm($c2->default_end) === '21:00');
$check('finish must be after start', is_wp_error(RSL_Courts::create_court($v1->id, 'Bad', '21:00', '19:00')));
$check('times must be valid', is_wp_error(RSL_Courts::create_court($v1->id, 'Bad', '7pm', '23:00')));
$check('update a court\'s hours', RSL_Courts::hm(RSL_Courts::update_court($c2->id, array('start' => '19:00', 'end' => '22:00'))->default_end) === '22:00');
RSL_Courts::update_court($c2->id, array('start' => '19:00', 'end' => '21:00'));

// --- Season settings + nights ----------------------------------------------------------
$check('dates required, first before last', is_wp_error(RSL_Courts::save_settings($season, array('start' => '2092-06-30', 'end' => '2092-04-01'))));
$check('game length within bounds', is_wp_error(RSL_Courts::save_settings($season, array('start' => '2092-04-01', 'end' => '2092-06-30', 'minutes' => array('ranked' => 5, 'unranked' => 50)))));
$s = RSL_Courts::save_settings($season, array('start' => '2092-04-01', 'end' => '2092-06-30', 'weekday' => 0, 'minutes' => array('ranked' => 60, 'unranked' => 50)));
$check('settings saved with a game length per division', $s['minutes']['ranked'] === 60 && $s['minutes']['unranked'] === 50);
$g = RSL_Courts::generate_nights($season);
$nights = RSL_Courts::nights($season);
$check('every Sunday between the dates', $g['added'] === 13 && count($nights) === 13 && date('w', strtotime($nights[0]->night_date)) === '0' && $nights[0]->night_date === '2092-04-06', $nights[0]->night_date);

// --- A night's plan -----------------------------------------------------------------------
$plan_of = function ($night_id, $venue_id) {
    foreach (RSL_Courts::night_plan($night_id)['venues'] as $v) { if ((int) $v['venue']->id === (int) $venue_id) { return $v; } }
    return null;
};
$n0 = $nights[0]->id;
$v = $plan_of($n0, $v1->id);
$check('usual night: each court\'s own hours', count($v['courts'][0]['slots']) === 4 && count($v['courts'][1]['slots']) === 2);
$check('slots carry the venue\'s division', count(array_filter(RSL_Courts::slots_for_night($n0), function ($s) use ($v1) { return $s['venue_id'] == $v1->id && $s['division'] === 'ranked'; })) === 6);
$check('unranked venue uses the unranked game length', count($plan_of($n0, $v2->id)['courts'][0]['slots']) === 4 && $plan_of($n0, $v2->id)['minutes'] === 50);

// Exceptions for one night.
RSL_Courts::set_court_night($nights[1]->id, $c1->id, false);
RSL_Courts::set_court_night($nights[1]->id, $c2->id, true, '20:00', '22:00');
$v = $plan_of($nights[1]->id, $v1->id);
$check('a court off for one night', !$v['courts'][0]['available'] && $v['courts'][0]['slots'] === array() && $v['courts'][0]['changed']);
$check('different hours for one night', $v['courts'][1]['start'] === '20:00' && count($v['courts'][1]['slots']) === 2 && $v['courts'][1]['changed']);
$check('other nights unaffected', count($plan_of($nights[2]->id, $v1->id)['courts'][0]['slots']) === 4);
RSL_Courts::set_court_night($nights[1]->id, $c2->id, true, '19:00', '21:00');
$check('setting back to the usual removes the exception', !$plan_of($nights[1]->id, $v1->id)['courts'][1]['changed']
    && !$wpdb->get_var($wpdb->prepare("SELECT id FROM " . RSL_Database::table('night_courts') . " WHERE night_id = %d AND court_id = %d", $nights[1]->id, $c2->id)));
$check('bad hours for a night refused', is_wp_error(RSL_Courts::set_court_night($nights[1]->id, $c2->id, true, '22:00', '20:00')));

RSL_Courts::set_venue_night_division($nights[3]->id, $v2->id, 'ranked');
$v = $plan_of($nights[3]->id, $v2->id);
$check('a venue can host the other division for a night (and its game length)', $v['division'] === 'ranked' && $v['division_changed'] && $v['minutes'] === 60);
RSL_Courts::set_venue_night_division($nights[3]->id, $v2->id, 'unranked');
$check('back to its usual division removes the exception', !$plan_of($nights[3]->id, $v2->id)['division_changed']);

RSL_Courts::set_night_type($nights[4]->id, 'bye', "King's Birthday");
$check('a bye has no slots', RSL_Courts::slots_for_night($nights[4]->id) === array() && RSL_Courts::get_night($nights[4]->id)->note === "King's Birthday");
RSL_Courts::set_night_type($nights[12]->id, 'finals');
$check('finals nights keep their slots', count(RSL_Courts::slots_for_night($nights[12]->id)) > 0);

// Copy forward.
RSL_Courts::set_court_night($nights[6]->id, $c3->id, true, '18:00', '20:00');
$copied = RSL_Courts::copy_night_forward($nights[6]->id);
$check('copy forward reaches every later night', $copied === 6 && $plan_of($nights[12]->id, $v2->id)['courts'][0]['end'] === '20:00');
$check('earlier nights untouched by copy', $plan_of($nights[5]->id, $v2->id)['courts'][0]['end'] === '22:00');
$check('copy keeps byes and finals as they were', RSL_Courts::get_night($nights[12]->id)->type === 'finals');

// Re-saving the season keeps nights and their exceptions; shortening removes the rest.
RSL_Courts::save_settings($season, array('start' => '2092-04-01', 'end' => '2092-06-30', 'weekday' => 0, 'minutes' => array('ranked' => 60, 'unranked' => 50)));
$g = RSL_Courts::generate_nights($season);
$check('re-saving the same dates changes nothing', $g['added'] === 0 && $g['removed'] === 0 && !$plan_of($nights[1]->id, $v1->id)['courts'][0]['available']);
RSL_Courts::save_settings($season, array('start' => '2092-04-01', 'end' => '2092-06-01', 'weekday' => 0, 'minutes' => array('ranked' => 60, 'unranked' => 50)));
$g = RSL_Courts::generate_nights($season);
$check('shorter season: later nights removed with their exceptions', $g['removed'] === 4 && count(RSL_Courts::nights($season)) === 9
    && !$wpdb->get_var($wpdb->prepare("SELECT id FROM " . RSL_Database::table('night_courts') . " WHERE night_id = %d", $nights[12]->id)));
RSL_Courts::update_court($c2->id, array('active' => false));
$check('a court not in use drops out of every night', count($plan_of($nights[0]->id, $v1->id)['courts']) === 1);
RSL_Courts::update_court($c2->id, array('active' => true));

// --- Admin -------------------------------------------------------------------------------
$admin = wp_insert_user(array('user_login' => "rslc-admin-$suffix", 'user_email' => "rslc-admin-$suffix@example.test", 'user_pass' => wp_generate_password(), 'role' => 'administrator'));
$sub = wp_insert_user(array('user_login' => "rslc-sub-$suffix", 'user_email' => "rslc-sub-$suffix@example.test", 'user_pass' => wp_generate_password(), 'role' => 'subscriber'));
wp_set_current_user($admin);
$post = function ($fields) use ($season) { return RSL_Admin_Courts::process(array_merge(array('rsl_nonce' => wp_create_nonce('rsl_courts_action'), 'season' => $season), $fields)); };
$notice = function () { $n = get_transient(RSL_Admin::NOTICE_PREFIX . get_current_user_id()); return is_array($n) ? $n : array('type' => '', 'message' => ''); };
$post(array('do' => 'save_night', 'night_id' => $nights[0]->id, 'courts' => array($c1->id => array('available' => '1', 'start' => '19:00', 'end' => '22:00'), $c2->id => array('start' => '19:00', 'end' => '21:00')), 'venue_division' => array($v1->id => 'ranked')));
$v = $plan_of($nights[0]->id, $v1->id);
$check('admin saves a night (hours changed, unticked court off)', $v['courts'][0]['end'] === '22:00' && !$v['courts'][1]['available'] && $notice()['type'] === 'success');
$post(array('do' => 'save_night', 'night_id' => $nights[0]->id, 'courts' => array($c1->id => array('available' => '1', 'start' => '22:00', 'end' => '19:00'))));
$check('admin: bad hours reported per court', $notice()['type'] === 'error' && strpos($notice()['message'], 'Court 1') !== false);
$post(array('do' => 'set_type', 'night_id' => $nights[1]->id, 'type' => 'finals', 'note' => wp_slash("Grand final's eve")));
$check('admin sets a night type with a note (no slashes)', RSL_Courts::get_night($nights[1]->id)->type === 'finals' && RSL_Courts::get_night($nights[1]->id)->note === "Grand final's eve");
$post(array('do' => 'create_court', 'venue_id' => $v2->id, 'name' => 'Court B', 'start' => '18:00', 'end' => '21:30'));
$check('admin adds a court', count(RSL_Courts::courts($v2->id)) === 2);
RSL_Admin_Courts::process(array('rsl_nonce' => 'bogus', 'do' => 'create_venue', 'name' => "Nope $suffix"));
$check('admin: bad nonce refused', $notice()['type'] === 'error');
wp_set_current_user($sub);
RSL_Admin_Courts::process(array('rsl_nonce' => wp_create_nonce('rsl_courts_action'), 'do' => 'create_venue', 'name' => "Sneaky $suffix"));
$check('admin: non-admins refused', !$wpdb->get_var($wpdb->prepare("SELECT id FROM " . RSL_Database::table('venues') . " WHERE name = %s", "Sneaky $suffix")));
wp_set_current_user($admin);

$_GET = array('page' => 'rsl-courts', 'season' => $season);
ob_start(); (new RSL_Admin_Courts())->render(); $cal = ob_get_clean();
$check('calendar: one row per night with a cell per venue', substr_count($cal, '<tr class="rsl-night-') === 9 && strpos($cal, "Ranked Venue $suffix") !== false);
$check('calendar: summary in each cell', strpos($cal, '1 court') !== false && strpos($cal, 'games') !== false && strpos($cal, 'rsl-changed-mark') !== false);
$_GET = array('page' => 'rsl-courts', 'season' => $season, 'night' => $nights[2]->id);
ob_start(); (new RSL_Admin_Courts())->render(); $night_page = ob_get_clean();
$check('night page: a timeline row per court with its slots', substr_count($night_page, 'class="rsl-court-row') >= 3 && strpos($night_page, 'class="rsl-slot rsl-slot-ranked"') !== false && strpos($night_page, '>7pm<') !== false);
$check('night page: copy forward offered', strpos($night_page, 'value="copy_forward"') !== false);
$_GET = array('page' => 'rsl-courts', 'tab' => 'venues');
ob_start(); (new RSL_Admin_Courts())->render(); $venues_page = ob_get_clean();
$check('venues page: court rows use their own forms', strpos($venues_page, 'form="rsl-court-' . $c1->id . '"') !== false);

// --- Cleanup -------------------------------------------------------------------------------
wp_set_current_user(0);
$_GET = array();
foreach (RSL_Courts::nights($season) as $n) {
    $wpdb->delete(RSL_Database::table('night_courts'), array('night_id' => $n->id));
    $wpdb->delete(RSL_Database::table('night_venues'), array('night_id' => $n->id));
}
$wpdb->delete(RSL_Database::table('nights'), array('season' => $season));
foreach (array($v1->id, $v2->id) as $vid) { $wpdb->delete(RSL_Database::table('courts'), array('venue_id' => $vid)); $wpdb->delete(RSL_Database::table('venues'), array('id' => $vid)); }
foreach (array($admin, $sub) as $u) { delete_transient(RSL_Admin::NOTICE_PREFIX . $u); wp_delete_user($u); }
if ($saved_settings === '__unset__') { delete_option(RSL_Courts::SETTINGS_OPTION); } else { update_option(RSL_Courts::SETTINGS_OPTION, $saved_settings); }
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
