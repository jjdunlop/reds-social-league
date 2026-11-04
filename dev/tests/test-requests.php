<?php
/**
 * Captains' night requests (week off, two games, can't make it), how the
 * fixtures and stats treat them, and the team page season schedule.
 *
 *   wp eval-file dev/tests/test-requests.php
 */

if (!defined('WP_CLI') || !WP_CLI || (wp_get_environment_type() === 'production' && !getenv('RSL_DEV'))) {
    exit("Local copy only: run with wp eval-file, with RSL_DEV=1 set (docker exec -e RSL_DEV=1 ...).\n");
}
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once dirname(__DIR__) . '/lib-mail-stop.php';
global $wpdb;

$season = '2091-pre';
$suffix = time();
$hold = rsl_dev_hold_mail($suffix);
$fails = 0;
$check = function ($label, $ok, $detail = '') use (&$fails, $hold) {
    rsl_dev_park_mail($hold);
    if (!$ok) { $fails++; }
    echo ($ok ? "OK: $label\n" : "FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n");
};
$saved_current = get_option(RSL_Seasons::CURRENT_OPTION, '__unset__');
update_option(RSL_Seasons::CURRENT_OPTION, $season);
$mails = 0;
$count_mail = function () use (&$mails) { $mails++; return true; };
add_filter('pre_wp_mail', $count_mail);
$users = array(); // set before the try, so the cleanup can always run
$parked = $wpdb->get_col("SELECT id FROM " . RSL_Database::table('venues') . " WHERE active = 1");
if ($parked) { $wpdb->query("UPDATE " . RSL_Database::table('venues') . " SET active = 0 WHERE id IN (" . implode(',', array_map('intval', $parked)) . ")"); }

try {

// Season: one night gone, then four to come (a bye, regular nights, finals).
$hall = RSL_Courts::create_venue("Req Hall $suffix", 'ranked');
foreach (array('Q1', 'Q2', 'Q3') as $c) { RSL_Courts::create_court($hall->id, $c, '19:00', '22:00'); }
$today = current_time('Y-m-d');
$sunday = date('w', strtotime($today)) === '0' ? $today : date('Y-m-d', strtotime('last sunday', strtotime($today)));
RSL_Courts::save_settings($season, array('start' => date('Y-m-d', strtotime($sunday . ' -1 week')), 'end' => date('Y-m-d', strtotime($sunday . ' +4 weeks')), 'weekday' => 0, 'minutes' => array('ranked' => 60, 'unranked' => 60)));
RSL_Courts::generate_nights($season);
$nights = RSL_Courts::nights($season);
// [0] past, [1] last Sunday (past or today), [2] next week (will publish), [3] bye, [4] regular, [5] finals
RSL_Courts::set_night_type($nights[3]->id, 'bye', 'Long weekend');
RSL_Courts::set_night_type($nights[5]->id, 'finals');
$mk = function ($tag, $role = 'subscriber') use ($suffix, &$users) {
    $u = wp_insert_user(array('user_login' => "rslq-$tag-$suffix", 'user_email' => "rslq-$tag-$suffix@example.test", 'user_pass' => wp_generate_password(), 'display_name' => "RSLQ $tag", 'role' => $role));
    $users[] = $u;
    return $u;
};
$teams = array();
$caps = array();
foreach (array('advanced', 'strong', 'solid', 'social', 'new') as $i => $level) {
    $t = RSL_Teams::create($season, array('name' => "Req T$i $suffix", 'division' => 'ranked', 'level' => $level));
    $caps[$i] = $mk("cap$i");
    RSL_Teams::add_member($t->id, $caps[$i], 'captain');
    $teams[$i] = $t;
}
$mate = $mk('mate');
RSL_Teams::add_member($teams[0]->id, $mate);
$admin = $mk('admin', 'administrator');
$T = function ($i) use ($teams) { return intval($teams[$i]->id); };

// --- Who can ask, and when ------------------------------------------------------------------
$n_next = $nights[2];
$n_later = $nights[4];
$check('a teammate (not captain) can\'t ask', is_wp_error(RSL_Requests::request($T(0), $n_next->id, 'off', $mate)));
$check('no requests for a bye week', is_wp_error(RSL_Requests::request($T(0), $nights[3]->id, 'off', $caps[0])));
$check('finals are arranged separately', is_wp_error(RSL_Requests::request($T(0), $nights[5]->id, 'off', $caps[0])));
$check('not for a night that\'s gone', is_wp_error(RSL_Requests::request($T(0), $nights[0]->id, 'off', $caps[0])));
$check('"can\'t make it" only once fixtures are out', is_wp_error(RSL_Requests::request($T(0), $n_next->id, 'cant_make', $caps[0])));
$r = RSL_Requests::request($T(0), $n_next->id, 'off', $caps[0]);
$check('the captain can ask for a week off', !is_wp_error($r) && $r->type === 'off' && $r->status === 'active');
$r2 = RSL_Requests::request($T(0), $n_next->id, 'double', $caps[0]);
$check('one live request per night: a new one replaces the old', !is_wp_error($r2) && RSL_Requests::get($r->id)->status === 'cancelled' && RSL_Requests::for_team($T(0))[intval($n_next->id)]->type === 'double');
$check('the captain can withdraw it', RSL_Requests::cancel($r2->id, $caps[0]) === true && !isset(RSL_Requests::for_team($T(0))[intval($n_next->id)]));

// --- The fixtures honour them ------------------------------------------------------------------
RSL_Requests::request($T(4), $n_next->id, 'off', $caps[4]);     // 4 teams left
RSL_Requests::request($T(1), $n_next->id, 'double', $caps[1]);  // wants two games
RSL_Requests::request($T(3), $n_next->id, 'double', $caps[3]);
$plan = RSL_Pairing::plan($n_next->id);
$in = array();
foreach ($plan['divisions']['ranked']['games'] as $g) { $in[] = $g['home']; $in[] = $g['away']; }
$counts = array_count_values($in);
$check('a team on a week off isn\'t fixtured', !isset($counts[$T(4)]) && $plan['divisions']['ranked']['out'][$T(4)] === 'Week off (requested)');
$check('teams that asked for two games get them (spare slots allowing)', isset($counts[$T(1)]) && $counts[$T(1)] === 2 && $counts[$T(3)] === 2);
$check('everyone else plays once', $counts[$T(0)] === 1 && $counts[$T(2)] === 1);
RSL_Pairing::generate($n_next->id);
RSL_Fixtures::publish_night($n_next->id);
$check('a week off can\'t be changed once fixtures are out', is_wp_error(RSL_Requests::cancel(RSL_Requests::for_team($T(4))[intval($n_next->id)]->id, $caps[4])));
$check('nor can new week-off requests be made', is_wp_error(RSL_Requests::request($T(0), $n_next->id, 'off', $caps[0])));
$check('"can\'t make it" needs a game that night', is_wp_error(RSL_Requests::request($T(4), $n_next->id, 'cant_make', $caps[4])));
$before_mails = $mails;
$cm = RSL_Requests::request($T(0), $n_next->id, 'cant_make', $caps[0]);
$check('"can\'t make it" goes to the admins (email sent)', !is_wp_error($cm) && $mails === $before_mails + 1);

// --- Stats: a week off is not a bye ---------------------------------------------------------------
// Make last Sunday's night a played round where team 4 took the week off and team 3 was left out.
$n_past = $nights[1];
$wpdb->insert(RSL_Database::table('requests'), array('team_id' => $T(4), 'night_id' => $n_past->id, 'type' => 'off', 'requested_by' => $caps[4]));
$pg = RSL_Fixtures::add_game($n_past->id, array('home' => $T(0), 'away' => $T(1), 'court_id' => intval(RSL_Courts::courts($hall->id)[0]->id), 'start' => '19:00'));
RSL_Fixtures::record_result($pg->id, '25-20, 25-22');
// Team 2's only game that night is one it forfeited (to team 1, whose second game it was).
$fg = RSL_Fixtures::add_game($n_past->id, array('home' => $T(2), 'away' => $T(1), 'court_id' => intval(RSL_Courts::courts($hall->id)[0]->id), 'start' => '20:00'));
if (!is_wp_error($fg)) {
    RSL_Fixtures::record_forfeit($fg->id, $T(2));
}
RSL_Fixtures::publish_night($n_past->id);
$wpdb->query($wpdb->prepare("UPDATE " . RSL_Database::table('teams') . " SET created_at = %s WHERE season = %s", $nights[0]->night_date . ' 00:00:00', $season));
RSL_Ratings::flush();
RSL_Stats::flush();
$st = RSL_Stats::season($season);
$check('left out by the fixtures: turn-up points, but a bye is not a game played for finals (0.18.1)', $st['teams'][$T(3)]['points'] === RSL_Stats::POINTS['played']
    && $st['teams'][$T(3)]['games'] === 0 && $st['teams'][$T(3)]['finals']['games'] === 0);
$check('a week off: no points, no game', $st['teams'][$T(4)]['games'] === 0 && $st['teams'][$T(4)]['points'] === 0);
$check('the two teams that played: one game each towards finals (team 1\'s forfeit win is none)', $st['teams'][$T(0)]['games'] === 1 && $st['teams'][$T(1)]['finals']['games'] === 1);
// Season Points' turn-up rule is once a night and separate from finals: a team that played gets no bye points on top.
$check('turn-up points once a night for the teams that played: team 0 a win (' . (RSL_Stats::POINTS['played'] + RSL_Stats::POINTS['win']) . '), team 1 a loss and a forfeit win (' . RSL_Stats::POINTS['played'] . ')',
    $st['teams'][$T(0)]['points'] === RSL_Stats::POINTS['played'] + RSL_Stats::POINTS['win'] && $st['teams'][$T(1)]['points'] === RSL_Stats::POINTS['played'],
    $st['teams'][$T(0)]['points'] . ' / ' . $st['teams'][$T(1)]['points']);
$check('a night whose only game the team forfeited: no turn-up points, no bye points, no game', !is_wp_error($fg)
    && $st['teams'][$T(2)]['points'] === 0 && $st['teams'][$T(2)]['games'] === 0 && $st['teams'][$T(2)]['finals']['games'] === 0, (string) $st['teams'][$T(2)]['points']);

// --- Team page season schedule -------------------------------------------------------------------
$team0 = RSL_Teams::get($T(0));
wp_set_current_user($caps[0]);
$html = RSL_Public_Fixtures::team_section($team0);
$check('schedule lists every night: TBA, bye, finals', strpos($html, 'Season schedule') !== false && strpos($html, 'TBA') !== false
    && strpos($html, 'No games this week — Long weekend') !== false && strpos($html, '<strong>Finals</strong>') !== false);
$check('captain sees the request buttons on future weeks', strpos($html, 'value="request_off"') !== false && strpos($html, 'value="request_double"') !== false);
$check('...and the can\'t-make-it status on a fixtured week', strpos($html, 'told the league you can') !== false);
wp_set_current_user($mate);
$html = RSL_Public_Fixtures::team_section($team0);
$check('teammates see the schedule but no buttons', strpos($html, 'TBA') !== false && strpos($html, 'value="request_off"') === false);
wp_set_current_user(0);
$html = RSL_Public_Fixtures::team_section($team0);
$check('the public sees the schedule but no buttons or requests', strpos($html, 'value="request_') === false && strpos($html, 'told the league') === false);

// Through the front-end form.
wp_set_current_user($caps[1]);
$back = RSL_Public::team_url(RSL_Teams::get($T(1)));
$to = RSL_Portal::process(array('rsl_nonce' => wp_create_nonce('rsl_front'), 'rsl_front' => 1, 'do' => 'request_off', 'team_id' => $T(1), 'night_id' => $n_later->id, 'back' => $back));
$check('the team page form records the request and comes back to the team page', $to === $back && RSL_Requests::for_team($T(1))[intval($n_later->id)]->type === 'off');
$to = RSL_Portal::process(array('rsl_nonce' => wp_create_nonce('rsl_front'), 'rsl_front' => 1, 'do' => 'request_off', 'team_id' => $T(1), 'night_id' => $n_later->id, 'back' => 'https://example.org/elsewhere'));
$check('...and never sends people off-site', strpos($to, 'example.org') === false);
$html = RSL_Public_Fixtures::team_section(RSL_Teams::get($T(1)));
$check('the request shows on the schedule with a withdraw button', strpos($html, 'Week off requested') !== false && strpos($html, 'value="cancel_request"') !== false);

// Admin marks the can't-make-it handled.
wp_set_current_user($admin);
RSL_Admin_Fixtures::process(array('rsl_nonce' => wp_create_nonce('rsl_fixtures_action'), 'season' => $season, 'night_id' => $n_next->id, 'do' => 'handle_request', 'request_id' => $cm->id));
$check('admin can mark a can\'t-make-it handled', RSL_Requests::get($cm->id)->status === 'handled');
$_GET = array('page' => 'rsl-fixtures', 'season' => $season, 'night' => $n_next->id);
ob_start(); (new RSL_Admin_Fixtures())->render(); $ahtml = ob_get_clean();
$check('admin page lists the night\'s requests', strpos($ahtml, 'Week off:') !== false && strpos($ahtml, 'Can\'t make it:') !== false);

} catch (Throwable $thrown) {
    $fails++;
    echo 'FAIL: threw ' . get_class($thrown) . ': ' . $thrown->getMessage() . ' (line ' . $thrown->getLine() . ")\n";
} finally {
// --- Cleanup (runs after a failure too) -------------------------------------------------------------
$_GET = array();
wp_set_current_user(0);
remove_filter('pre_wp_mail', $count_mail);
// Every team in the test's season (however far the test got).
foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM " . RSL_Database::table('teams') . " WHERE season = %s", $season)) as $tid) {
    $wpdb->delete(RSL_Database::table('requests'), array('team_id' => $tid));
    $wpdb->delete(RSL_Database::table('team_members'), array('team_id' => $tid));
    $wpdb->delete(RSL_Database::table('teams'), array('id' => $tid));
}
$wpdb->delete(RSL_Database::table('games'), array('season' => $season));
$wpdb->delete(RSL_Database::table('ledger'), array('season' => $season));
foreach (RSL_Courts::nights($season) as $n) {
    $wpdb->delete(RSL_Database::table('night_courts'), array('night_id' => $n->id));
    $wpdb->delete(RSL_Database::table('night_venues'), array('night_id' => $n->id));
}
$wpdb->delete(RSL_Database::table('nights'), array('season' => $season));
delete_transient(RSL_Stats::CACHE_PREFIX . $season); // RSL_Stats::season() saved a copy above
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
if ($saved_current === '__unset__') { delete_option(RSL_Seasons::CURRENT_OPTION); } else { update_option(RSL_Seasons::CURRENT_OPTION, $saved_current); }
rsl_dev_release_mail($hold);
}
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
