<?php
/**
 * Referees and court supervisors: roles, claiming jobs (with clash checks,
 * nothing already finished), dropping them, scoring (locked once the league
 * enters a result), removing officials, pay and its CSV, and the Ref Jobs /
 * admin pages.
 *
 *   wp eval-file dev/tests/test-officials.php
 */

if (!defined('WP_CLI') || !WP_CLI || (wp_get_environment_type() === 'production' && !getenv('RSL_DEV'))) {
    exit("Local copy only: run with wp eval-file, with RSL_DEV=1 set (docker exec -e RSL_DEV=1 ...).\n");
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
$check('a supervisor already at one venue is told so at another', is_wp_error($e = RSL_Officials::claim_supervisor($next->id, $uni->id, $sup)) && strpos($e->get_error_message(), 'already supervising') !== false, is_wp_error($e) ? $e->get_error_message() : 'claimed');
RSL_Officials::save_settings(array('ref_rate' => 25, 'supervisor_rate' => 30, 'release_hours' => 24, 'max_games' => 2));
$e = RSL_Officials::check_ref($g_same->id, $ref);
$check('a cap on games per night', is_wp_error($e) && strpos($e->get_error_message(), 'most games') !== false);
$check('another ref takes the 7pm game', !is_wp_error(RSL_Officials::claim_ref($g_same->id, $ref2)));
RSL_Officials::save_settings(array('ref_rate' => 25, 'supervisor_rate' => 30, 'release_hours' => 24, 'max_games' => 5));
$mine = RSL_Officials::commitments($ref, $next->id);
$kinds = array_map(function ($c) { return $c['kind'] . '@' . $c['start']; }, $mine);
$check('your night in order: play 7, ref 8, ref 9', $kinds === array('play@19:00', 'ref@20:00', 'ref@21:00'), implode(',', $kinds));
$q = $wpdb->num_queries;
RSL_Officials::commitments($ref, $next->id);
$check('your night takes the same few queries however many jobs you hold', $wpdb->num_queries - $q <= 4, ($wpdb->num_queries - $q) . ' queries');
$gs = RSL_Fixtures::get($g_same->id);
$nn = RSL_Courts::get_night($next->id);
$tm = RSL_Teams::current_team_for_user($ref, $season);
RSL_Officials::settings(); // option cached before counting
$q = $wpdb->num_queries;
$e = RSL_Officials::ref_eligibility($gs, $nn, $tm, $mine);
$check('the per-game checks run without queries (the Ref Jobs page asks about every game)', $wpdb->num_queries === $q && is_wp_error($e) && strpos($e->get_error_message(), 'playing') !== false);
$check('...and say the same as the full check', is_wp_error($e2 = RSL_Officials::check_ref($g_same->id, $ref)) && $e2->get_error_message() === $e->get_error_message());
$check('a job records when it was claimed in site time', abs(strtotime(RSL_Officials::job($j8->id)->claimed_at) - current_time('timestamp')) < 300, RSL_Officials::job($j8->id)->claimed_at . ' vs ' . current_time('mysql'));
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
$big = 3000000000; // past the old INT limit (late 2030, in tenths of a second since 2024)
$check('live: sequence numbers past 2^31 still keep updates in order', RSL_Fixtures::set_live($lg, array('sets' => array(array(25, 20)), 'current' => array(12, 9)), $big) === true
    && RSL_Fixtures::set_live($lg, array('sets' => array(), 'current' => array(1, 1)), $big - 1) === false
    && RSL_Fixtures::live(RSL_Fixtures::get($lg))['current'] === array(12, 9));
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
wp_set_current_user($sup);
$check('...and a supervisor at one venue sees that reason at the other (not refereeing)', strpos($sh = $page->render(), 'already supervising') !== false && strpos($sh, 'refereeing at another venue') === false);
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

// --- On the night: finished games, locked results, removing officials ---------------------------------
// Tonight (the second night, moved to today): games that ended at midnight this morning, and one that
// runs to midnight tonight (24:00) — so it's still on whenever the test runs.
$wpdb->update(RSL_Database::table('nights'), array('night_date' => $today), array('id' => $nights[1]->id));
$tonight = RSL_Courts::get_night($nights[1]->id);
$at = function ($game, $start, $end) use ($wpdb) {
    $wpdb->update(RSL_Database::table('games'), array('start_time' => $start, 'end_time' => $end), array('id' => $game->id));
    return RSL_Fixtures::get($game->id);
};
$t_done = $at($G($tonight, $u0, $u1, $uc, '18:00'), '00:00:00', '00:00:00');
$t_live = $at($G($tonight, $teams[2], $teams[3], $h1, '19:00'), '00:00:00', '24:00:00');
$t_done2 = $at($G($tonight, $teams[0], $teams[1], $h2, '19:00'), '00:00:00', '00:00:00');
RSL_Fixtures::publish_night($tonight->id);
wp_set_current_user($sup);
$_GET = array('night' => $tonight->id);
$tp = $page->render();
$check('the Ref Jobs page counts only games a ref can still take (not the two finished ones)', strpos($tp, ' 1 game still needs a ref') !== false, preg_match('/\d+ games? still needs? a ref/', $tp, $mm) ? $mm[0] : 'no count');
$check('a ref can\'t take a game that has finished', is_wp_error($e = RSL_Officials::claim_ref($t_done->id, $ref2)) && strpos($e->get_error_message(), 'finished') !== false, is_wp_error($e) ? $e->get_error_message() : 'claimed');
$check('...but can take one still being played', !is_wp_error($e = RSL_Officials::claim_ref($t_live->id, $ref2)), is_wp_error($e) ? $e->get_error_message() : '');
$check('an admin can still assign a finished game', !is_wp_error(RSL_Officials::claim_ref($t_done->id, $nobody, $admin)));
$check('a supervisor can\'t take a venue whose games are over', is_wp_error($e = RSL_Officials::claim_supervisor($tonight->id, $uni->id, $sup)) && strpos($e->get_error_message(), 'finished') !== false);
$check('...but can take one with a game still on', !is_wp_error(RSL_Officials::claim_supervisor($tonight->id, $hall->id, $sup)));
wp_set_current_user($sup);
$_GET = array('night' => $tonight->id);
$check('...and the Ref Jobs page shows a supervisor why not', strpos($page->render(), 'have finished') !== false);
$check('...and an admin can still assign the finished venue', !is_wp_error(RSL_Officials::claim_supervisor($tonight->id, $uni->id, $nobody, $admin)));

// Scoring: a ref corrects their own save, but not a result or forfeit the league entered.
$check('the ref scores the game they hold', RSL_Officials::can_score($ref2, $t_live));
RSL_Fixtures::record_result($t_live->id, '25-20, 25-18', $ref2);
$check('...and can correct their own save', RSL_Officials::can_score($ref2, RSL_Fixtures::get($t_live->id)));
RSL_Fixtures::record_result($t_live->id, '25-20, 20-25, 15-10', $admin);
$why = RSL_Officials::why_cant_score($ref2, RSL_Fixtures::get($t_live->id));
$check('a result the league entered is locked to the ref', is_wp_error($why) && $why->get_error_code() === 'rsl_locked' && RSL_Officials::can_score($admin, RSL_Fixtures::get($t_live->id)));
wp_set_current_user($ref2);
$check('...and the Ref Jobs page says so instead of showing the scoreboard', strpos($page->render(), 'rsl-sb-locked') !== false);
$check('an admin-assigned ref scores the game', RSL_Officials::can_score($nobody, $t_done));
RSL_Fixtures::record_forfeit($t_done->id, $u0->id, $admin);
$check('...but not over a forfeit the league recorded', !RSL_Officials::can_score($nobody, RSL_Fixtures::get($t_done->id)));
RSL_Fixtures::clear_result($t_live->id);
RSL_Teams::add_member($teams[2]->id, $ref2);
$check('a ref who has since joined a team in the game can\'t score it', !RSL_Officials::can_score($ref2, RSL_Fixtures::get($t_live->id)));
RSL_Teams::remove_member($teams[2]->id, $ref2);
$check('...and can again once off that team', RSL_Officials::can_score($ref2, RSL_Fixtures::get($t_live->id)));
$check('...and gets the scoreboard on the Ref Jobs page', strpos($page->render(), 'rsl-sb-wrap') !== false);
$sb = new ReflectionMethod('RSL_Ref_Page', 'scoreboard_script');
$sb->setAccessible(true);
$js = $sb->invoke(null);
$check('the scoreboard keeps its undo history on the phone, so Undo works after a reload', strpos($js, 'hist: hist.slice(') !== false && strpos($js, 'saved.hist') !== false);

// Removing a ref or supervisor releases their jobs still to come; jobs already done stay (and pay).
$wpdb->insert(RSL_Database::table('jobs'), array('night_id' => $tonight->id, 'type' => 'ref', 'game_id' => $t_done2->id, 'venue_id' => $hall->id,
    'user_id' => $ref2, 'rate' => 25, 'claimed_at' => current_time('mysql'))); // claimed while it was on
$check('a ref scores a game they took while it was on', RSL_Officials::can_score($ref2, $t_done2));
$coming = 1 + count(array_filter(RSL_Officials::jobs_for_night($next->id)['ref'], function ($j) use ($ref2) { return intval($j->user_id) === $ref2; }));
wp_set_current_user($admin);
RSL_Admin_Officials::process(array('rsl_nonce' => wp_create_nonce('rsl_officials_action'), 'do' => 'remove_ref', 'user_id' => $ref2));
$notice = get_transient(RSL_Admin::NOTICE_PREFIX . $admin);
$left = $wpdb->get_col($wpdb->prepare("SELECT game_id FROM " . RSL_Database::table('jobs') . " WHERE user_id = %d AND type = 'ref'", $ref2));
$check('removing a ref releases their jobs still to come', !in_array((string) $t_live->id, $left, true) && !array_intersect($left, array_map('strval', array_keys(RSL_Officials::jobs_for_night($next->id)['ref']))), implode(',', $left));
$check('...keeps the ones already done', in_array((string) $t_done2->id, $left, true) && in_array((string) $g_past->id, $left, true));
$check('...and says how many', is_array($notice) && strpos($notice['message'], "$coming jobs still to come were released") !== false, is_array($notice) ? $notice['message'] : '');
$why = RSL_Officials::why_cant_score($ref2, RSL_Fixtures::get($t_done2->id));
$check('a removed ref can\'t score a game they took themselves', is_wp_error($why) && strpos($why->get_error_message(), 'no longer a referee') !== false);
RSL_Admin_Officials::process(array('rsl_nonce' => wp_create_nonce('rsl_officials_action'), 'do' => 'remove_supervisor', 'user_id' => $sup));
$sup_nights = $wpdb->get_col($wpdb->prepare("SELECT night_id FROM " . RSL_Database::table('jobs') . " WHERE user_id = %d AND type = 'supervisor'", $sup));
$notice = get_transient(RSL_Admin::NOTICE_PREFIX . $admin);
$check('removing a supervisor releases their nights to come and keeps nights done', $sup_nights === array((string) $past->id) && is_array($notice) && strpos($notice['message'], '2 jobs still to come were released') !== false,
    implode(',', $sup_nights) . ' / ' . (is_array($notice) ? $notice['message'] : ''));

// The pay report CSV never hands a spreadsheet a formula from a name.
wp_update_user(array('ID' => $ref2, 'display_name' => '=SUM(1,2) RSLO ref2'));
$csv = RSL_Admin_Officials::csv_rows($nights[0]->night_date, $today);
$names = array_map(function ($r) { return $r[0]; }, $csv);
$check('pay CSV: a name starting with = is written as text', in_array("'=SUM(1,2) RSLO ref2", $names, true), implode(' | ', $names));
$check('pay CSV: header first, ordinary names untouched', $csv[0][0] === 'Name' && in_array('RSLO sup', $names, true));

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
// Removing a player from a team queues them a "no longer on" email; it goes with the test users.
$wpdb->query($wpdb->prepare("DELETE FROM " . RSL_Database::table('outbox') . " WHERE to_email LIKE %s", '%-' . $suffix . '@example.test'));
if ($saved_settings === '__unset__') { delete_option(RSL_Officials::SETTINGS_OPTION); } else { update_option(RSL_Officials::SETTINGS_OPTION, $saved_settings, false); }
if ($saved_current === '__unset__') { delete_option(RSL_Seasons::CURRENT_OPTION); } else { update_option(RSL_Seasons::CURRENT_OPTION, $saved_current); }
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
