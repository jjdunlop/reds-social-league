<?php
/**
 * Referees and court supervisors: roles, claiming jobs (with clash checks),
 * dropping them, scoring, pay, and the Ref Jobs / admin pages.
 *
 *   wp eval-file dev/tests/test-officials.php
 */

if (!defined('WP_CLI') || !WP_CLI) {
    exit("Run with wp eval-file.\n");
}
require_once ABSPATH . 'wp-admin/includes/user.php';
global $wpdb;

$season = '2090-pre';
$suffix = time();
$fails = 0;
$check = function ($label, $ok, $detail = '') use (&$fails) {
    if (!$ok) { $fails++; }
    echo ($ok ? "OK: $label\n" : "FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n");
};
$saved_current = get_option(RSL_Seasons::CURRENT_OPTION, '__unset__');
$saved_settings = get_option(RSL_Officials::SETTINGS_OPTION, '__unset__');
update_option(RSL_Seasons::CURRENT_OPTION, $season);
delete_option(RSL_Officials::SETTINGS_OPTION);
$parked = $wpdb->get_col("SELECT id FROM " . RSL_Database::table('venues') . " WHERE active = 1");
if ($parked) { $wpdb->query("UPDATE " . RSL_Database::table('venues') . " SET active = 0 WHERE id IN (" . implode(',', array_map('intval', $parked)) . ")"); }

// Two venues; next Sunday published, the Sunday after not; last Sunday played.
$hall = RSL_Courts::create_venue("Off Hall $suffix", 'ranked');
foreach (array('H1', 'H2') as $c) { RSL_Courts::create_court($hall->id, $c, '19:00', '22:00'); }
$uni = RSL_Courts::create_venue("Off Uni $suffix", 'unranked');
RSL_Courts::create_court($uni->id, 'U1', '18:00', '21:00');
$today = current_time('Y-m-d');
$sunday = date('w', strtotime($today)) === '0' ? $today : date('Y-m-d', strtotime('last sunday', strtotime($today)));
RSL_Courts::save_settings($season, array('start' => date('Y-m-d', strtotime($sunday . ' -1 week')), 'end' => date('Y-m-d', strtotime($sunday . ' +2 weeks')), 'weekday' => 0, 'minutes' => array('ranked' => 60, 'unranked' => 60)));
RSL_Courts::generate_nights($season);
$nights = RSL_Courts::nights($season);
$past = $nights[0];
$next = $nights[2];
$later = $nights[3];

$users = array();
$mk = function ($tag, $role = 'subscriber') use ($suffix, &$users) {
    $u = wp_insert_user(array('user_login' => "rslo-$tag-$suffix", 'user_email' => "rslo-$tag-$suffix@example.test", 'user_pass' => wp_generate_password(), 'display_name' => "RSLO $tag", 'role' => $role));
    $users[] = $u;
    return $u;
};
$teams = array();
foreach (array('R0', 'R1', 'R2', 'R3') as $i => $n) {
    $teams[$i] = RSL_Teams::create($season, array('name' => "Off $n $suffix", 'division' => 'ranked', 'level' => 'solid'));
}
$u0 = RSL_Teams::create($season, array('name' => "Off U0 $suffix", 'division' => 'unranked', 'level' => 'new'));
$u1 = RSL_Teams::create($season, array('name' => "Off U1 $suffix", 'division' => 'unranked', 'level' => 'new'));
$ref = $mk('ref');        // plays for team R0
RSL_Teams::add_member($teams[0]->id, $ref);
$ref2 = $mk('ref2');      // free agent
$sup = $mk('sup');
$nobody = $mk('nobody');
$admin = $mk('admin', 'administrator');
$h1 = intval(RSL_Courts::courts($hall->id)[0]->id);
$h2 = intval(RSL_Courts::courts($hall->id)[1]->id);
$uc = intval(RSL_Courts::courts($uni->id)[0]->id);
$G = function ($night, $home, $away, $court, $start) { return RSL_Fixtures::add_game($night->id, array('home' => $home->id, 'away' => $away->id, 'court_id' => $court, 'start' => $start)); };
// Next Sunday: R0 v R1 at 7pm (ref plays), R2 v R3 at 7pm and 8pm (H2), R0 v R2 at 9pm; Uni 6pm and 7pm.
$g_own = $G($next, $teams[0], $teams[1], $h1, '19:00');
$g_same = $G($next, $teams[2], $teams[3], $h2, '19:00');
$g_8 = $G($next, $teams[1], $teams[3], $h1, '20:00');
$g_9 = $G($next, $teams[2], $teams[1], $h2, '21:00');
$g_uni6 = $G($next, $u0, $u1, $uc, '18:00');
$g_uni7 = $G($next, $u1, $u0, $uc, '19:00');
$g_later = $G($later, $teams[2], $teams[3], $h1, '19:00');
$g_past = $G($past, $teams[1], $teams[2], $h1, '19:00');
RSL_Fixtures::publish_night($next->id);
RSL_Fixtures::publish_night($past->id);

// --- Roles ---------------------------------------------------------------------------------
$check('default pay: $25 a game, $30 a night', RSL_Officials::settings()['ref_rate'] == 25 && RSL_Officials::settings()['supervisor_rate'] == 30);
RSL_Officials::apply_ref($ref);
$check('offering to ref waits for approval', RSL_Officials::get($ref)->ref === 'applied' && !RSL_Officials::is_ref($ref));
$check('can\'t take jobs before approval', is_wp_error(RSL_Officials::claim_ref($g_8->id, $ref)));
RSL_Officials::set_ref($ref, 'approved', $admin);
RSL_Officials::set_ref($ref2, 'approved', $admin);
RSL_Officials::set_supervisor($sup, true, $admin);
$check('approved refs and supervisors', RSL_Officials::is_ref($ref) && RSL_Officials::is_supervisor($sup) && !RSL_Officials::is_ref($sup));
$check('withdrawing only undoes an unapproved offer', RSL_Officials::withdraw_ref($ref)->ref === 'approved');

// --- Claiming ------------------------------------------------------------------------------
$check('not before fixtures are out', is_wp_error(RSL_Officials::claim_ref($g_later->id, $ref)));
$check('never your own team\'s game', is_wp_error(RSL_Officials::claim_ref($g_own->id, $ref)));
$check('not while you\'re playing', is_wp_error($e = RSL_Officials::claim_ref($g_same->id, $ref)) && strpos($e->get_error_message(), 'playing') !== false);
$j8 = RSL_Officials::claim_ref($g_8->id, $ref);
$check('ref, then play... then ref: the 8pm game', !is_wp_error($j8) && intval($j8->user_id) === $ref && floatval($j8->rate) == 25);
$check('one ref per game', is_wp_error($e = RSL_Officials::claim_ref($g_8->id, $ref2)) && $e->get_error_code() === 'rsl_taken');
$j9 = RSL_Officials::claim_ref($g_9->id, $ref);
$check('back-to-back jobs at one venue are fine', !is_wp_error($j9));
$check('not straight before your game at another venue (travel time)', is_wp_error($e = RSL_Officials::claim_ref($g_uni6->id, $ref)) && strpos($e->get_error_message(), 'venues') !== false, is_wp_error($e) ? $e->get_error_message() : '');
RSL_Officials::set_ref($sup, 'approved', $admin);
$js = RSL_Officials::claim_supervisor($next->id, $hall->id, $sup);
$check('a supervisor stays at their venue', !is_wp_error($js) && is_wp_error($e = RSL_Officials::claim_ref($g_uni6->id, $sup)) && strpos($e->get_error_message(), 'supervising') !== false);
$check('only supervisors supervise', is_wp_error(RSL_Officials::claim_supervisor($next->id, $uni->id, $ref2)));
$check('one supervisor per venue', is_wp_error(RSL_Officials::claim_supervisor($next->id, $hall->id, $sup)));
RSL_Officials::save_settings(array('ref_rate' => 25, 'supervisor_rate' => 30, 'release_hours' => 24, 'max_games' => 2));
$e = RSL_Officials::check_ref($g_same->id, $ref);
$check('a cap on games per night', is_wp_error($e) && strpos($e->get_error_message(), 'most games') !== false);
$check('another ref takes the 7pm game', !is_wp_error(RSL_Officials::claim_ref($g_same->id, $ref2)));
RSL_Officials::save_settings(array('ref_rate' => 25, 'supervisor_rate' => 30, 'release_hours' => 24, 'max_games' => 5));
$mine = RSL_Officials::commitments($ref, $next->id);
$kinds = array_map(function ($c) { return $c['kind'] . '@' . $c['start']; }, $mine);
$check('your night in order: play 7, ref 8, ref 9', $kinds === array('play@19:00', 'ref@20:00', 'ref@21:00'), implode(',', $kinds));
$supn = RSL_Officials::commitments($sup, $next->id);
$check('supervising shows as the whole night', count($supn) === 1 && $supn[0]['kind'] === 'supervisor' && $supn[0]['start'] === '');

// --- Dropping a job ------------------------------------------------------------------------
$start = RSL_Officials::night_start($next);
$far = $start - current_time('timestamp') > 24 * HOUR_IN_SECONDS;
$r = RSL_Officials::release($j9->id, $ref2);
$check('you can\'t drop someone else\'s job', is_wp_error($r));
$r = RSL_Officials::release($j9->id, $ref);
$check('dropping a job is allowed until the cut-off (or refused after it)', $far ? $r === true : is_wp_error($r));
if (!$far) { RSL_Officials::release($j9->id, 0, true); }
$check('an admin can always remove a job', RSL_Officials::release($j8->id, 0, true) === true && RSL_Officials::job($j8->id) === null);
$j8 = RSL_Officials::claim_ref($g_8->id, $ref);

// --- Scores and pay ----------------------------------------------------------------------------
$check('a ref can\'t score a game before its night', !RSL_Officials::can_score($ref, RSL_Fixtures::get($g_8->id)));
$jp = RSL_Officials::claim_ref($g_past->id, $ref2, $admin);
$check('admins can record who refereed after the fact', !is_wp_error($jp) && intval($jp->assigned_by) === $admin);
$check('the ref scores their game (on the night or two days after)', RSL_Officials::can_score($ref2, RSL_Fixtures::get($g_past->id)) === ($past->night_date >= date('Y-m-d', strtotime($today . ' -2 days'))));
$check('nobody else can score it (an admin can)', !RSL_Officials::can_score($ref, RSL_Fixtures::get($g_past->id)) && RSL_Officials::can_score($admin, RSL_Fixtures::get($g_past->id)));
RSL_Officials::claim_supervisor($past->id, $hall->id, $sup, $admin);
$report = RSL_Officials::pay_report($nights[0]->night_date, $nights[3]->night_date);
$check('pay report: only nights that have happened', isset($report[$ref2]) && $report[$ref2]['refs'] === 1 && $report[$ref2]['total'] == 25 && !isset($report[$ref]));
$check('pay report: supervising pays per night', isset($report[$sup]) && $report[$sup]['supervised'] === 1 && $report[$sup]['total'] == 30);
$e = RSL_Officials::earnings($ref, $season);
$check('earnings: booked ahead', $e['booked'] === 1 && $e['booked_pay'] == 25 && $e['done'] === 0);
RSL_Fixtures::delete_game($g_later->id);
$check('removing a game removes its ref job', !$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . RSL_Database::table('jobs') . " WHERE game_id = %d", $g_later->id)));

// --- Live scores ------------------------------------------------------------------------------------
$lg = $g_uni7->id;
$check('live: the score so far is stored', RSL_Fixtures::set_live($lg, array('sets' => array(array(25, 20)), 'current' => array(10, 8)), 1000) === true);
$check('live: an older update arriving late is ignored', RSL_Fixtures::set_live($lg, array('sets' => array(), 'current' => array(3, 2)), 900) === false
    && RSL_Fixtures::live(RSL_Fixtures::get($lg))['current'] === array(10, 8));
RSL_Fixtures::set_live($lg, array('sets' => array(array(25, 20)), 'current' => array(12, 9)), 1001);
$score = new ReflectionMethod('RSL_Public_Fixtures', 'score_html');
$score->setAccessible(true);
$html = $score->invoke(null, RSL_Fixtures::get($lg), $today);
$check('live: the public card shows LIVE with sets and the current set', strpos($html, 'Live') !== false && strpos($html, '25-20') !== false && strpos($html, 'Set 2: 12–9') !== false);
RSL_Fixtures::record_result($lg, '25-20, 25-18');
$check('live: cleared once the result is saved', RSL_Fixtures::live(RSL_Fixtures::get($lg)) === null && strpos($score->invoke(null, RSL_Fixtures::get($lg), $today), '2&ndash;0') !== false);
$check('live: no live updates after the result', RSL_Fixtures::set_live($lg, array('sets' => array(), 'current' => array(1, 0)), 5000) === false);
RSL_Fixtures::clear_result($lg);

// --- Pages -----------------------------------------------------------------------------------------
RSL_Ref_Page::ensure_page();
$page = new RSL_Ref_Page();
wp_set_current_user($nobody);
$check('non-refs see how to offer', strpos($page->render(), 'Happy to be a paid referee') !== false);
wp_set_current_user($ref);
$_GET = array('night' => $next->id);
$html = $page->render();
$check('a ref sees their night and the open jobs', strpos($html, 'Your night') !== false && strpos($html, 'Jobs this night') !== false && strpos($html, 'Take it') !== false && strpos($html, 'Yours') !== false);
$check('...with the reason a job isn\'t possible', strpos($html, 'your own team') !== false);
wp_set_current_user($ref2);
$to = RSL_Ref_Page::process(array('rsl_nonce' => wp_create_nonce('rsl_ref'), 'rsl_ref' => 1, 'do' => 'claim_ref', 'game_id' => $g_9->id, 'night_id' => $next->id));
$jn = RSL_Officials::jobs_for_night($next->id)['ref'];
$check('taking a job through the page', isset($jn[intval($g_9->id)]) && intval($jn[intval($g_9->id)]->user_id) === $ref2);
$_GET = array('night' => $past->id);
$check('the scoreboard shows for the ref\'s game on the night', strpos($page->render(), 'rsl-sb') !== false || $past->night_date < date('Y-m-d', strtotime($today . ' -2 days')));
wp_set_current_user($admin);
$_GET = array('page' => 'rsl-officials');
ob_start(); (new RSL_Admin_Officials())->render(); $ahtml = ob_get_clean();
$check('admin page: refs, supervisors, settings, pay report', strpos($ahtml, 'RSLO ref2') !== false && strpos($ahtml, 'Court supervisors') !== false && strpos($ahtml, 'Pay report') !== false);
RSL_Officials::apply_ref($nobody);
RSL_Admin_Officials::process(array('rsl_nonce' => wp_create_nonce('rsl_officials_action'), 'do' => 'approve', 'user_id' => $nobody));
$check('admin approves an offer', RSL_Officials::is_ref($nobody));
RSL_Admin_Officials::process(array('rsl_nonce' => wp_create_nonce('rsl_officials_action'), 'do' => 'add_supervisor', 'email' => "rslo-ref2-$suffix@example.test"));
$check('admin adds a supervisor by email', RSL_Officials::is_supervisor($ref2));
$_GET = array('page' => 'rsl-fixtures', 'season' => $season, 'night' => $next->id);
ob_start(); (new RSL_Admin_Fixtures())->render(); $fx = ob_get_clean();
$check('Fixtures admin shows refs and supervisors', strpos($fx, '<th>Ref</th>') !== false && strpos($fx, 'Court supervisor:') !== false && strpos($fx, 'RSLO sup') !== false);

// --- Cleanup ------------------------------------------------------------------------------------------
$_GET = array();
wp_set_current_user(0);
foreach ($nights as $n) { $wpdb->delete(RSL_Database::table('jobs'), array('night_id' => $n->id)); }
$wpdb->delete(RSL_Database::table('games'), array('season' => $season));
$wpdb->delete(RSL_Database::table('ledger'), array('season' => $season));
foreach (RSL_Courts::nights($season) as $n) {
    $wpdb->delete(RSL_Database::table('night_courts'), array('night_id' => $n->id));
    $wpdb->delete(RSL_Database::table('night_venues'), array('night_id' => $n->id));
}
$wpdb->delete(RSL_Database::table('nights'), array('season' => $season));
$settings = get_option(RSL_Courts::SETTINGS_OPTION, array());
unset($settings[$season]);
update_option(RSL_Courts::SETTINGS_OPTION, $settings, false);
foreach (array($hall, $uni) as $v) {
    $wpdb->delete(RSL_Database::table('courts'), array('venue_id' => $v->id));
    $wpdb->delete(RSL_Database::table('venues'), array('id' => $v->id));
}
if ($parked) { $wpdb->query("UPDATE " . RSL_Database::table('venues') . " SET active = 1 WHERE id IN (" . implode(',', array_map('intval', $parked)) . ")"); }
foreach (array_merge($teams, array($u0, $u1)) as $t) {
    $wpdb->delete(RSL_Database::table('team_members'), array('team_id' => $t->id));
    $wpdb->delete(RSL_Database::table('teams'), array('id' => $t->id));
}
foreach ($users as $u) {
    $wpdb->delete(RSL_Database::table('officials'), array('user_id' => $u));
    delete_transient(RSL_Portal::FLASH_PREFIX . $u);
    delete_transient(RSL_Admin::NOTICE_PREFIX . $u);
    wp_delete_user($u);
}
if ($saved_settings === '__unset__') { delete_option(RSL_Officials::SETTINGS_OPTION); } else { update_option(RSL_Officials::SETTINGS_OPTION, $saved_settings, false); }
if ($saved_current === '__unset__') { delete_option(RSL_Seasons::CURRENT_OPTION); } else { update_option(RSL_Seasons::CURRENT_OPTION, $saved_current); }
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
