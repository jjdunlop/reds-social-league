<?php
/**
 * Courts and game nights: venues and courts with usual hours, season dates
 * and per-division game lengths, generated nights (kept on re-save, and
 * never removed while they have fixtures, results, jobs, can't-make-its or
 * charges; week-off requests go with them), night types (no bye over
 * games), per-night exceptions, the slot maths, copy-forward, and the admin
 * screens.
 *
 *   wp eval-file dev/tests/test-courts.php
 */

if (!defined('WP_CLI') || !WP_CLI || (wp_get_environment_type() === 'production' && !getenv('RSL_DEV'))) {
    exit("Local copy only: run with wp eval-file, with RSL_DEV=1 set (docker exec -e RSL_DEV=1 ...).\n");
}
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once dirname(__DIR__) . '/lib-mail-stop.php';
global $wpdb;

$season = '2092-mid1';
$suffix = time();
$hold = rsl_dev_hold_mail($suffix);
$fails = 0;
$check = function ($label, $ok, $detail = '') use (&$fails, $hold) {
    rsl_dev_park_mail($hold);
    if (!$ok) { $fails++; }
    echo ($ok ? "OK: $label\n" : "FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n");
};
$saved_settings = get_option(RSL_Courts::SETTINGS_OPTION, '__unset__');
// Set before the try, so the cleanup can always run.
$admin = 0;
$sub = 0;

try {

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

// --- Nights in use are kept ----------------------------------------------------------------
// A published, played night (a result, ref and supervisor jobs, game fees) and a
// night with a late fee are never removed when the dates change; empty nights are,
// with the week-off and withdrawn requests on them (a week off is only a wish for
// that date — the admin is told which teams to let know).
$ta = RSL_Teams::create($season, array('name' => "Courts Aces $suffix", 'division' => 'ranked'));
$tb = RSL_Teams::create($season, array('name' => "Courts Blockers $suffix", 'division' => 'ranked'));
$played = $nights[2];
$game = RSL_Fixtures::add_game($played->id, array('home' => $ta->id, 'away' => $tb->id, 'court_id' => $c1->id, 'start' => '19:00'));
RSL_Fixtures::publish_night($played->id);
$ref_job = RSL_Officials::claim_ref($game->id, $sub, $admin);
$sup_job = RSL_Officials::claim_supervisor($played->id, $v1->id, $sub, $admin);
RSL_Fixtures::record_result($game->id, '25-20, 25-22', $admin);
$fees = function ($night_id) use ($wpdb) { return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . RSL_Database::table('ledger') . " WHERE night_id = %d AND type = 'game'", $night_id)); };
$jobs = function ($night_id) use ($wpdb) { return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . RSL_Database::table('jobs') . " WHERE night_id = %d", $night_id)); };
$check('set-up: a published, played night with two jobs and two game fees', !is_wp_error($game) && !is_wp_error($ref_job) && !is_wp_error($sup_job) && $jobs($played->id) === 2 && $fees($played->id) === 2);

$e = RSL_Courts::set_night_type($played->id, 'bye');
$check('a night with games can\'t become a bye (games, fees and jobs untouched)', is_wp_error($e) && strpos($e->get_error_message(), 'Fixtures page') !== false
    && RSL_Courts::get_night($played->id)->type === 'regular' && $fees($played->id) === 2 && $jobs($played->id) === 2);
$check('… or finals', is_wp_error(RSL_Courts::set_night_type($played->id, 'finals')) && RSL_Courts::get_night($played->id)->type === 'regular');
$check('its note can still change', !is_wp_error(RSL_Courts::set_night_type($played->id, 'regular', 'Venue till 11')) && RSL_Courts::get_night($played->id)->note === 'Venue till 11');

$off = RSL_Requests::request($ta->id, $nights[5]->id, 'off', $admin);
$withdrawn = RSL_Requests::request($tb->id, $nights[6]->id, 'off', $admin);
RSL_Requests::cancel($withdrawn->id, $admin);
$wpdb->insert(RSL_Database::table('ledger'), array('team_id' => $ta->id, 'season' => $season, 'type' => 'late_fee', 'amount' => -150, 'night_id' => $nights[7]->id, 'note' => 'Test late fee', 'created_at' => current_time('mysql')));
$check('set-up: a week off, a withdrawn request and a late fee', !is_wp_error($off) && !is_wp_error($withdrawn) && RSL_Requests::get($withdrawn->id)->status === 'cancelled');

// Mondays instead of Sundays: every existing night is now outside the dates.
RSL_Courts::save_settings($season, array('start' => '2092-04-01', 'end' => '2092-06-01', 'weekday' => 1, 'minutes' => array('ranked' => 60, 'unranked' => 50)));
$g = RSL_Courts::generate_nights($season);
$kept_dates = array_keys($g['kept']);
sort($kept_dates);
$check('changing the weekday keeps the played night with its result, jobs and fees', RSL_Courts::get_night($played->id) && RSL_Fixtures::get($game->id)
    && RSL_Fixtures::get($game->id)->status === 'played' && $jobs($played->id) === 2 && $fees($played->id) === 2);
$check('… and the night with a charge', $kept_dates === array($played->night_date, $nights[7]->night_date), implode(', ', $kept_dates));
$check('kept nights say what\'s on them', in_array('published', $g['kept'][$played->night_date], true) && in_array('games', $g['kept'][$played->night_date], true)
    && $g['kept'][$nights[7]->night_date] === array('team charges'));
$check('empty nights outside the dates removed, withdrawn requests with them', $g['removed'] === 7 && $g['added'] === 8 && !RSL_Courts::get_night($nights[0]->id) && !RSL_Courts::get_night($nights[6]->id)
    && !$wpdb->get_var($wpdb->prepare("SELECT id FROM " . RSL_Database::table('requests') . " WHERE night_id = %d", $nights[6]->id)), $g['removed'] . ' removed, ' . $g['added'] . ' added');
$check('a night with only a week off is removed too, the week off with it (and listed)', !RSL_Courts::get_night($nights[5]->id) && !RSL_Requests::get($off->id)
    && $g['dropped'] === array(array('team_id' => (int) $ta->id, 'type' => 'off', 'night_date' => $nights[5]->night_date)), wp_json_encode($g['dropped']));

// A two-games request on the last Monday; the admin then ends the season a day earlier.
$last_monday = $wpdb->get_var($wpdb->prepare("SELECT id FROM " . RSL_Database::table('nights') . " WHERE season = %s AND night_date = %s", $season, '2092-05-26'));
$double = RSL_Requests::request($tb->id, $last_monday, 'double', $admin);
$post(array('do' => 'save_settings', 'start' => '2092-04-01', 'end' => '2092-05-25', 'weekday' => 1, 'minutes' => array('ranked' => 60, 'unranked' => 50)));
$check('admin: kept nights named in a warning', $notice()['type'] === 'warning' && strpos($notice()['message'], 'Kept 2 nights') !== false
    && strpos($notice()['message'], date('D j M', strtotime($played->night_date)) . ' (published, games') !== false, $notice()['message']);
$check('admin: the teams whose requests went with a removed night are named', !is_wp_error($double) && !RSL_Courts::get_night($last_monday)
    && strpos($notice()['message'], "Courts Blockers $suffix (two games, Mon 26 May)") !== false, $notice()['message']);
$_GET = array('page' => 'rsl-courts', 'season' => $season);
ob_start(); (new RSL_Admin_Courts())->render(); $cal = ob_get_clean();
$check('calendar: kept nights marked outside the season dates', substr_count($cal, 'outside the season dates</span>') === 2);
$check('calendar: a published night asks before its type changes', substr_count($cal, 'fixtures are published. Change its type anyway?') === 1);
$check('calendar: saving dates over existing nights asks first', strpos($cal, 'Empty nights outside the new dates are removed') !== false);
$post(array('do' => 'set_type', 'night_id' => $played->id, 'type' => 'bye', 'note' => ''));
$check('admin: a bye over games refused, saying what to do', $notice()['type'] === 'error' && strpos($notice()['message'], 'Unpublish and clear') !== false && RSL_Courts::get_night($played->id)->type === 'regular');

// A team on the played night said they can't make it (it could have carried a late fee).
add_filter('pre_wp_mail', '__return_true');
$cant = RSL_Requests::request($tb->id, $played->id, 'cant_make', $admin);
remove_filter('pre_wp_mail', '__return_true');

// Cleared as on the Fixtures page (clear the result, unpublish, remove the game): no
// fees, and the court supervisor goes with the venue's last game (nothing to
// supervise). The can't make it still holds the night (the Fixtures page can
// withdraw it). Made a bye, nothing is left to miss, so it's withdrawn too; then the
// next save of the dates removes the now-empty night.
RSL_Fixtures::clear_result($game->id);
RSL_Fixtures::publish_night($played->id, false);
RSL_Fixtures::delete_game($game->id);
$check('set-up: cleared, no fees, and the supervisor went with the last game', !is_wp_error($cant) && $fees($played->id) === 0 && $jobs($played->id) === 0);
$g = RSL_Courts::generate_nights($season);
$check('… but a can\'t make it not yet withdrawn still keeps it', $g['removed'] === 0 && RSL_Courts::get_night($played->id)
    && isset($g['kept'][$played->night_date]) && $g['kept'][$played->night_date] === array('can\'t make it requests'), wp_json_encode($g['kept']));
$check('a cleared night can be a bye, with no fees, jobs or can\'t make it left', !is_wp_error(RSL_Courts::set_night_type($played->id, 'bye')) && $fees($played->id) === 0 && $jobs($played->id) === 0
    && !is_wp_error($cant) && RSL_Requests::get($cant->id)->status === 'cancelled');
$g = RSL_Courts::generate_nights($season);
$check('once cleared, the next save removes it', $g['removed'] === 1 && !RSL_Courts::get_night($played->id) && count($g['kept']) === 1);

} catch (Throwable $thrown) {
    $fails++;
    echo 'FAIL: threw ' . get_class($thrown) . ': ' . $thrown->getMessage() . ' (line ' . $thrown->getLine() . ")\n";
} finally {
// --- Cleanup (runs after a failure too) ----------------------------------------------------
wp_set_current_user(0);
$_GET = array();
$wpdb->delete(RSL_Database::table('games'), array('season' => $season));
$wpdb->delete(RSL_Database::table('ledger'), array('season' => $season));
foreach (RSL_Courts::nights($season) as $n) {
    $wpdb->delete(RSL_Database::table('night_courts'), array('night_id' => $n->id));
    $wpdb->delete(RSL_Database::table('night_venues'), array('night_id' => $n->id));
    $wpdb->delete(RSL_Database::table('jobs'), array('night_id' => $n->id));
}
$wpdb->delete(RSL_Database::table('nights'), array('season' => $season));
// Every team in the test's season (however far the test got).
foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM " . RSL_Database::table('teams') . " WHERE season = %s", $season)) as $tid) {
    $wpdb->delete(RSL_Database::table('requests'), array('team_id' => $tid));
    $wpdb->delete(RSL_Database::table('team_members'), array('team_id' => $tid));
    $wpdb->delete(RSL_Database::table('teams'), array('id' => $tid));
}
if (is_int($sub) && $sub > 0) {
    $wpdb->delete(RSL_Database::table('jobs'), array('user_id' => $sub));
}
// Every venue the test made (each name ends with the suffix), with its courts.
foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM " . RSL_Database::table('venues') . " WHERE name LIKE %s", '% ' . $wpdb->esc_like((string) $suffix))) as $vid) {
    $wpdb->delete(RSL_Database::table('courts'), array('venue_id' => $vid));
    $wpdb->delete(RSL_Database::table('venues'), array('id' => $vid));
}
rsl_dev_delete_users(array($admin, $sub));
if ($saved_settings === '__unset__') { delete_option(RSL_Courts::SETTINGS_OPTION); } else { update_option(RSL_Courts::SETTINGS_OPTION, $saved_settings); }
rsl_dev_release_mail($hold);
}
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
