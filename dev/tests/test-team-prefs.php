<?php
/**
 * Team level estimate (admin-only seed for first-week Ranked matchmaking)
 * and the captain's fixturing preferences.
 *
 *   wp eval-file dev/tests/test-team-prefs.php
 */

if (!defined('WP_CLI') || !WP_CLI || (wp_get_environment_type() === 'production' && !getenv('RSL_DEV'))) {
    exit("Local copy only: run with wp eval-file, with RSL_DEV=1 set (docker exec -e RSL_DEV=1 ...).\n");
}
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once dirname(__DIR__) . '/lib-profile.php';
require_once dirname(__DIR__) . '/lib-mail-stop.php';
global $wpdb;

$season = '2094-pre';
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
add_filter('pre_wp_mail', '__return_true');
$users = array();
$mk = function ($tag, $role = 'subscriber') use ($suffix, &$users) {
    $u = wp_insert_user(array('user_login' => "rslp-$tag-$suffix", 'user_email' => "rslp-$tag-$suffix@example.test", 'user_pass' => wp_generate_password(), 'display_name' => "RSLP $tag $suffix", 'role' => $role));
    $users[] = $u;
    rsl_dev_profile($u);
    return $u;
};

try {

// --- Levels ------------------------------------------------------------------------
$check('five levels, worded by how a team plays (no old grades)', count(RSL_Teams::levels()) === 5 && strpos(implode(' ', RSL_Teams::levels()), 'grade') === false);
$check('levels seed ratings from low to high around 1500', RSL_Teams::level_seed_rating('new') < RSL_Teams::level_seed_rating('solid')
    && RSL_Teams::level_seed_rating('solid') === 1500 && RSL_Teams::level_seed_rating('advanced') > RSL_Teams::level_seed_rating('strong'));
$check('unknown level seeds at the average', RSL_Teams::level_seed_rating('') === 1500);

$cap = $mk('cap'); $mate = $mk('mate'); $admin = $mk('admin', 'administrator');
foreach (array($cap, $mate) as $u) { RSL_Players::register($u, array('level' => 'social', 'years' => '1to3')); }
$app = RSL_Teams::apply($season, $cap, array('name' => "Prefs Team $suffix", 'division' => 'ranked', 'level' => 'strong'));
wp_set_current_user($admin);
RSL_Teams::approve($app->id);
$team = RSL_Teams::get($app->id);
RSL_Teams::add_member($team->id, $mate);

// Never public.
$public = new RSL_Public();
wp_set_current_user(0);
set_query_var(RSL_Public::QUERY_VAR, '');
$list = $public->render_teams();
set_query_var(RSL_Public::QUERY_VAR, $team->slug);
$page = $public->render_teams();
set_query_var(RSL_Public::QUERY_VAR, '');
$strong = RSL_Teams::levels()['strong'];
$check('level never on the public team list or team page', strpos($list, $strong) === false && strpos($page, $strong) === false && strpos($page, 'Strong') === false);
RSL_Portal::ensure_page();
$portal = new RSL_Portal();
wp_set_current_user($cap);
$_GET = array();
$cap_view = $portal->render();
$cap_tools = RSL_Portal::team_tools(RSL_Teams::get($team->id), $cap) . RSL_Portal::team_prefs(RSL_Teams::get($team->id), $cap);
$check('not shown to the captain after applying either (league page or team page tools)', strpos($cap_view, $strong) === false && $cap_tools !== '' && strpos($cap_tools, $strong) === false);

// Admin sees and corrects it.
wp_set_current_user($admin);
$check('admin can correct the level', RSL_Teams::set_level($team->id, 'solid')->est_level === 'solid');
$check('bad level refused', is_wp_error(RSL_Teams::set_level($team->id, 'elite')));
RSL_Admin::process(array('rsl_nonce' => wp_create_nonce('rsl_team_action'), 'season' => $season, 'do' => 'update_team', 'team_id' => $team->id, 'name' => $team->name, 'division' => 'ranked', 'colour' => '#1d3d6e', 'level' => 'advanced'));
$check('admin team form saves the level', RSL_Teams::get($team->id)->est_level === 'advanced');
$_GET = array('page' => 'rsl-teams', 'season' => $season, 'team' => $team->id);
ob_start(); (new RSL_Admin())->render(); $admin_team = ob_get_clean();
$check('admin team page shows the level and preferences', strpos($admin_team, 'name="level"') !== false && strpos($admin_team, 'Fixturing preferences:') !== false);
$_GET = array('page' => 'rsl-teams', 'season' => $season);
ob_start(); (new RSL_Admin())->render(); $admin_list = ob_get_clean();
$check('admin team list has a level column', strpos($admin_list, 'Level (estimate)') !== false && strpos($admin_list, 'Advanced') !== false);

// --- Preferences --------------------------------------------------------------------------
$check('defaults: no preference, one game a night, not on standby', RSL_Teams::preferences_summary(RSL_Teams::get($team->id)) === 'No preference · One game a night');
wp_set_current_user($cap);
RSL_Portal::process(array('rsl_nonce' => wp_create_nonce('rsl_front'), 'do' => 'save_prefs', 'team_id' => $team->id,
    'pref_time' => 'late', 'pref_double' => '1', 'pref_double_shape' => 'break', 'pref_standby' => '1'));
$t = RSL_Teams::get($team->id);
$check('captain saves preferences', $t->pref_time === 'late' && (int) $t->pref_double === 1 && $t->pref_double_shape === 'break' && (int) $t->pref_standby === 1);
$check('summary reads well', RSL_Teams::preferences_summary($t) === 'Later games · Happy to double-header (with a break between) · On standby for late games', RSL_Teams::preferences_summary($t));
$cap_view = RSL_Portal::team_prefs($t, $cap);
$check('captain sees the preferences form with their choices (on the team page, 0.20.0)', strpos($cap_view, 'value="save_prefs"') !== false && preg_match('/value="late"\s+selected/', $cap_view) === 1);
wp_set_current_user($mate);
RSL_Portal::process(array('rsl_nonce' => wp_create_nonce('rsl_front'), 'do' => 'save_prefs', 'team_id' => $team->id, 'pref_time' => 'early'));
$check('a teammate (not captain) can\'t change them', RSL_Teams::get($team->id)->pref_time === 'late');
$check('teammates don\'t see the preferences form', RSL_Portal::team_prefs(RSL_Teams::get($team->id), $mate) === '' && strpos($portal->render(), 'value="save_prefs"') === false);
$check('bad values fall back to safe defaults', RSL_Teams::set_preferences($team->id, $cap, array('time' => 'midnight', 'double' => 0, 'shape' => 'sideways'))->pref_time === 'any');

} catch (Throwable $thrown) {
    $fails++;
    echo 'FAIL: threw ' . get_class($thrown) . ': ' . $thrown->getMessage() . ' (line ' . $thrown->getLine() . ")\n";
} finally {
// --- Cleanup (runs after a failure too) --------------------------------------------------
wp_set_current_user(0);
$_GET = array();
remove_filter('pre_wp_mail', '__return_true');
// Every team in the test's season (however far the test got).
foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM " . RSL_Database::table('teams') . " WHERE season = %s", $season)) as $tid) {
    $wpdb->delete(RSL_Database::table('team_members'), array('team_id' => $tid));
    $wpdb->delete(RSL_Database::table('invites'), array('team_id' => $tid));
    $wpdb->delete(RSL_Database::table('teams'), array('id' => $tid));
}
foreach ($users as $u) {
    if (is_int($u) && $u > 0) {
        $wpdb->delete(RSL_Database::table('players'), array('user_id' => $u));
    }
}
if ($saved_current === '__unset__') { delete_option(RSL_Seasons::CURRENT_OPTION); } else { update_option(RSL_Seasons::CURRENT_OPTION, $saved_current); }
rsl_dev_delete_users($users); // the flash and the admin notice too
rsl_dev_release_mail($hold);
}
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
