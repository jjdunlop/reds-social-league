<?php
/**
 * The league page's menu item follows the person (0.17.0): RSL_Portal::menu_labels() on
 * wp_nav_menu_objects reads "Register for <the sign-up season's year>" until they've
 * registered, "My team" on an active team or with a team application waiting (in a season
 * the league page shows), and "My league" otherwise. Logged-out viewers, other items, an item
 * kept for logged-out visitors (Nav Menu Roles' "out") and a dropdown heading are left alone,
 * and it costs one query at most. Made-up menu items only (no menu is changed), far-future
 * (and far-past) seasons, a throwaway @example.test account; cleans up.
 *
 *   wp eval-file dev/tests/test-menu-label.php
 */

if (!defined('WP_CLI') || !WP_CLI || (wp_get_environment_type() === 'production' && !getenv('RSL_DEV'))) {
    exit("Local copy only: run with wp eval-file, with RSL_DEV=1 set (docker exec -e RSL_DEV=1 ...).\n");
}
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once dirname(__DIR__) . '/lib-profile.php';
require_once dirname(__DIR__) . '/lib-mail-stop.php';
global $wpdb;

$suffix = time();
$hold = rsl_dev_hold_mail($suffix);
$fails = 0;
$check = function ($label, $ok, $detail = '') use (&$fails, $hold) {
    rsl_dev_park_mail($hold);
    if (!$ok) { $fails++; }
    echo ($ok ? "OK: $label\n" : "FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n");
};
// Set before the try, so the cleanup can always run.
$saved = array();
foreach (array(RSL_Seasons::CURRENT_OPTION, RSL_Seasons::SIGNUP_OPTION) as $opt) { $saved[$opt] = get_option($opt, '__unset__'); }
$saved_page = get_option(RSL_Portal::PAGE_OPTION, '__unset__');
$made_page = 0;
$out_id = 0;
$cands = null;
$test_seasons = array('2092-pre', '2092-mid1', '1992-pre');
$users = array();
$mk = function ($tag) use ($suffix, &$users) {
    $u = wp_insert_user(array('user_login' => "rslml-$tag-$suffix", 'user_email' => "rslml-$tag-$suffix@example.test", 'user_pass' => wp_generate_password(),
        'display_name' => "RSLML $tag $suffix", 'role' => 'subscriber'));
    if (is_wp_error($u)) { // stop here (the cleanup only ever sees real account ids)
        throw new Exception("Couldn't make the test account $tag: " . $u->get_error_message());
    }
    $users[] = $u;
    rsl_dev_profile($u);
    return $u;
};

try {

// --- Setup: the page, the seasons, a made-up menu ------------------------------------------------
$had_page = intval(get_option(RSL_Portal::PAGE_OPTION));
$page_id = intval(RSL_Portal::ensure_page()); // the existing page when there is one
$made_page = $page_id !== $had_page ? $page_id : 0;
// Both undated, so their quarters are still to come: the page shows 2092-pre (being played)
// and 2092-mid1 (signing up).
RSL_Seasons::set_current('2092-pre');
RSL_Seasons::set_signup('2092-mid1');
$cands = function ($keys) { return array('2092-mid1'); };
add_filter('rsl_open_for_signup_candidates', $cands);
// An item kept for logged-out visitors (Nav Menu Roles' meta), in no menu.
$out_id = (int) wp_insert_post(array('post_type' => 'nav_menu_item', 'post_status' => 'publish', 'post_title' => "RSL out $suffix"));
update_post_meta($out_id, '_nav_menu_role', 'out');
$teams_page = intval(get_option(RSL_Public::PAGE_OPTION)) ?: 999999999;
// The properties wp_setup_nav_menu_item() sets; fresh objects each time (the filter changes titles in place).
$item = function ($id, $type, $object, $object_id, $title, $parent = '0') {
    return (object) array('ID' => $id, 'db_id' => $id, 'menu_item_parent' => (string) $parent, 'object_id' => (string) $object_id, 'object' => $object,
        'type' => $type, 'type_label' => $type === 'custom' ? 'Custom Link' : 'Page', 'title' => $title, 'url' => '', 'target' => '', 'attr_title' => '',
        'description' => '', 'classes' => array(), 'xfn' => '');
};
$items = function () use ($item, $page_id, $out_id, $teams_page) {
    return array(
        $item(0, 'post_type', 'page', $page_id, 'Register for 2027'),
        $item(0, 'post_type', 'page', $teams_page, 'Teams'),
        $item(0, 'custom', 'custom', $page_id, 'Custom'),
        $item($out_id, 'post_type', 'page', $page_id, 'Social League'),
    );
};
$titles = function ($list) { return array_map(function ($i) { return $i->title; }, (array) $list); };
$label = function () use ($items, $titles) {
    RSL_Portal::flush_menu_state();
    return $titles(RSL_Portal::menu_labels($items(), (object) array()));
};

// 1-2: the wiring and the words.
$check('1 wired to wp_nav_menu_objects at priority 10', has_filter('wp_nav_menu_objects', array('RSL_Portal', 'menu_labels')) === 10);
$check('2 the words: Register for <the sign-up season\'s year>, My team, My league', RSL_Portal::menu_label('register', '2092-mid1') === 'Register for 2092'
    && RSL_Portal::menu_label('team', '2092-mid1') === 'My team' && RSL_Portal::menu_label('league', '2092-mid1') === 'My league');

// 3: logged out: nothing touched, nothing read.
wp_set_current_user(0);
RSL_Portal::flush_menu_state();
$n = $wpdb->num_queries;
$t = $titles(RSL_Portal::menu_labels($items(), (object) array()));
$q = $wpdb->num_queries - $n;
$check('3 logged out: every title as set up, and no query', $t === array('Register for 2027', 'Teams', 'Custom', 'Social League') && $q === 0, wp_json_encode($t) . " / $q");

// 4: logged in, not registered.
$u = $mk('u');
wp_set_current_user($u);
$t = $label();
$check('4 logged in, not registered: Register for the sign-up season\'s year; other pages, custom links and the logged-out item untouched',
    $t === array('Register for 2092', 'Teams', 'Custom', 'Social League'), wp_json_encode($t));

// 5: one query, memoised (options and the out item's meta read first, as the page has).
RSL_Seasons::current();
RSL_Seasons::signup();
RSL_Seasons::signup_choices();
get_option(RSL_Portal::PAGE_OPTION);
get_post_meta($out_id, '_nav_menu_role', true);
RSL_Portal::flush_menu_state();
$n = $wpdb->num_queries;
RSL_Portal::menu_labels($items(), (object) array());
$one = $wpdb->num_queries - $n;
RSL_Portal::menu_labels($items(), (object) array());
$two = $wpdb->num_queries - $n;
$check('5 one query for the menu, and none the second time (memoised)', $one === 1 && $two === 1, "$one / $two");

// 6: a menu without the league page's item reads nothing.
RSL_Portal::flush_menu_state();
$others = function () use ($items) { $all = $items(); return array($all[1], $all[2]); };
$n = $wpdb->num_queries;
$t = $titles(RSL_Portal::menu_labels($others(), (object) array()));
$q = $wpdb->num_queries - $n;
$check('6 a menu without the league page reads nothing and changes nothing', $t === array('Teams', 'Custom') && $q === 0, wp_json_encode($t) . " / $q");

// 7-11: the three states.
RSL_Players::register($u, array('level' => 'social', 'years' => '1to3'));
$t = $label();
$check('7 registered, on no team: My league', $t[0] === 'My league', wp_json_encode($t));
$app = RSL_Teams::apply('2092-mid1', $u, array('name' => "Menu Team $suffix", 'level' => 'social'));
$t = $label();
$check('8 a team application waiting: My team', !is_wp_error($app) && $app && $t[0] === 'My team', is_wp_error($app) ? $app->get_error_message() : wp_json_encode($t));
$gone = (!is_wp_error($app) && $app) ? RSL_Teams::withdraw($app->id, $u) : null;
$t = $label();
$check('9 withdrawn: back to My league', $gone === true && $t[0] === 'My league', wp_json_encode($t));
$active = RSL_Teams::create('2092-pre', array('name' => "Menu Active $suffix"));
$added = (!is_wp_error($active) && $active) ? RSL_Teams::add_member($active->id, $u) : null;
$t = $label();
$check('10 on an active team: My team', $added === true && $t[0] === 'My team', wp_json_encode($t));
$off = (!is_wp_error($active) && $active) ? RSL_Teams::remove_member($active->id, $u) : null;
$old = RSL_Teams::create('1992-pre', array('name' => "Menu Old $suffix"));
$old_added = (!is_wp_error($old) && $old) ? RSL_Teams::add_member($old->id, $u) : null;
$t = $label();
$check('11 only the seasons the league page shows count: a long-finished season\'s team is My league', $off === true && $old_added === true && $t[0] === 'My league'
    && !in_array('1992-pre', array(RSL_Seasons::current(), RSL_Seasons::signup()), true), wp_json_encode($t));

// 12: through the hook, as a theme's menu runs it (other plugins' filters too).
RSL_Portal::flush_menu_state();
$through = apply_filters('wp_nav_menu_objects', $items(), (object) array('menu' => 0));
$found = null;
foreach ((array) $through as $i) {
    if (is_object($i) && isset($i->object_id, $i->type, $i->ID) && intval($i->object_id) === $page_id && $i->type === 'post_type' && intval($i->ID) === 0) {
        $found = $i;
    }
}
if ($found) {
    $check('12 through wp_nav_menu_objects: the league page\'s item reads My league', $found->title === 'My league', $found->title);
} else {
    echo "SKIP: another plugin's menu filter removed the test item\n";
}

// 13: a dropdown heading (an item other items sit under) keeps its words.
$pair = function ($child_parent) use ($item, $page_id) {
    return array(
        $item(900000001, 'post_type', 'page', $page_id, 'Social League'),
        $item(900000002, 'custom', 'custom', 0, 'Fixtures', $child_parent),
    );
};
RSL_Portal::flush_menu_state();
$t = $titles(RSL_Portal::menu_labels($pair('900000001'), (object) array()));
RSL_Portal::flush_menu_state();
$t2 = $titles(RSL_Portal::menu_labels($pair('0'), (object) array()));
$check('13 a dropdown heading keeps its words (and only that holds it: as a plain item it follows the person)',
    $t === array('Social League', 'Fixtures') && $t2 === array('My league', 'Fixtures'), wp_json_encode(array($t, $t2)));

} catch (Throwable $e) {
    $fails++;
    echo 'FAIL: threw ' . get_class($e) . ': ' . $e->getMessage() . ' (line ' . $e->getLine() . ")\n";
} finally {
// --- Cleanup (runs after a failure too) ------------------------------------------------------------
wp_set_current_user(0);
RSL_Portal::flush_menu_state();
if ($cands) {
    remove_filter('rsl_open_for_signup_candidates', $cands);
}
foreach ($test_seasons as $s_key) {
    foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM " . RSL_Database::table('teams') . " WHERE season = %s", $s_key)) as $tid) {
        $wpdb->delete(RSL_Database::table('team_members'), array('team_id' => $tid));
        $wpdb->delete(RSL_Database::table('invites'), array('team_id' => $tid));
    }
    $wpdb->delete(RSL_Database::table('teams'), array('season' => $s_key));
}
foreach ($users as $uid) {
    if (!is_int($uid) || $uid <= 0) {
        continue;
    }
    foreach (array('players', 'team_members', 'free_agents') as $t_name) { $wpdb->delete(RSL_Database::table($t_name), array('user_id' => $uid)); }
}
if ($out_id) {
    wp_delete_post($out_id, true);
}
if ($made_page) {
    wp_delete_post($made_page, true);
    if ($saved_page === '__unset__') { delete_option(RSL_Portal::PAGE_OPTION); } else { update_option(RSL_Portal::PAGE_OPTION, $saved_page, false); }
}
foreach ($saved as $opt => $value) {
    if ($value === '__unset__') { delete_option($opt); } else { update_option($opt, $value, false); }
}
rsl_dev_delete_users($users);
rsl_dev_release_mail($hold);
wp_set_current_user(0);
}
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
