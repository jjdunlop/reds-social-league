<?php
/**
 * Places in each division (0.19.0, RSL_Capacity): the rule, the sit-out
 * numbers, the waiting list, and every place that reads them.
 *
 * Pure (no database): places() against brute force on random per-night slot
 * lists (nights with 0 slots included, allowances 0 to 4, the clamp at 50),
 * the 2027 table, sit_out_numbers() / sit_out_words() / over_words(), the
 * waiting-list queue (ties on applied_at go by id), places_line(),
 * full_words(), waiting_words().
 *
 * Database: a far-future season whose nights have exactly the slots the test
 * sets (two throwaway venues, one court each; every other court off on those
 * nights only), allowance 0. RSL_Courts::season_slot_counts() against
 * slots_for_night(); applying into a full division (the flash, the card, the
 * Start a team words, at registration too); the both-full words; nothing on
 * the season preview or for a logged-out visitor; the admin places line and
 * the waiting-list labels; a withdrawal and a decline moving the queue;
 * approving over the cap (the row's words, the button, the notice); Add a
 * team and a division change over the cap; an application moved into a full
 * division; placement's capacity and its warning; a season with no nights;
 * a season that has started; the override; the allowance setting (saves,
 * clamps, moves the places).
 *
 *   wp eval-file dev/tests/test-capacity.php
 *   php dev/tests/test-capacity.php        (standalone: the pure part only)
 *
 * Throwaway @example.test users, venues, seasons and options only; cleans up.
 */

if (!defined('ABSPATH')) {
    // Standalone: plain PHP from the command line, no WordPress; the pure part only.
    if (PHP_SAPI !== 'cli') {
        exit;
    }
    define('ABSPATH', __DIR__ . '/');
    require_once __DIR__ . '/../../includes/class-rsl-seasons.php';
    require_once __DIR__ . '/../../includes/class-rsl-teams.php';
    require_once __DIR__ . '/../../includes/class-rsl-capacity.php';
    $standalone = true;
} elseif (!defined('WP_CLI') || !WP_CLI || (wp_get_environment_type() === 'production' && !getenv('RSL_DEV'))) {
    exit("Local copy only: run with wp eval-file, with RSL_DEV=1 set (docker exec -e RSL_DEV=1 ...), or standalone with php.\n");
} else {
    $standalone = false;
}

$fails = 0;
$hold = null;
if (!$standalone) {
    require_once ABSPATH . 'wp-admin/includes/user.php';
    require_once dirname(__DIR__) . '/lib-profile.php';
    require_once dirname(__DIR__) . '/lib-mail-stop.php';
    $suffix = time();
    $hold = rsl_dev_hold_mail($suffix);
}
$check = function ($label, $ok, $detail = '') use (&$fails, $hold) {
    if ($hold) {
        rsl_dev_park_mail($hold);
    }
    if (!$ok) { $fails++; }
    echo ($ok ? "OK: $label\n" : "FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n");
};

// ======================================================================================================
// Pure
// ======================================================================================================

// --- The rule against brute force -----------------------------------------------------------------------
$brute = function ($slots, $a) {
    if (!$slots) { return null; }
    $on = array_values(array_filter($slots, function ($s) { return $s > 0; }));
    if (!$on) { return 0; }
    $best = 0;
    for ($n = 0; $n <= 400; $n++) {
        $sum = 0;
        foreach ($on as $s) { $sum += max(0, $n - 2 * $s); }
        if ($sum <= $a * $n) { $best = $n; }
    }
    return min(RSL_Capacity::MAX_PLACES, $best); // 400 fits only when it never binds: the clamp
};
mt_srand(19019);
$bad = array();
$runs = 0;
for ($i = 0; $i < 3000; $i++) {
    $len = mt_rand(0, 12);
    $slots = array();
    for ($k = 0; $k < $len; $k++) {
        $slots[] = mt_rand(0, 4) === 0 ? 0 : mt_rand(1, 14);
    }
    for ($a = 0; $a <= 4; $a++) {
        $runs++;
        $want = $brute($slots, $a);
        $got = RSL_Capacity::places($slots, $a);
        if ($want !== $got) {
            $bad[] = json_encode($slots) . " a=$a want " . var_export($want, true) . ' got ' . var_export($got, true);
        }
    }
}
$check("places() matches brute force on $runs random seasons (nights with 0 slots, allowances 0 to 4, the clamp)", !$bad, implode(' | ', array_slice($bad, 0, 5)));
$table = array();
foreach (array(8, 9, 10, 11) as $n) {
    foreach (array(0, 1, 2) as $a) {
        $table[$n][$a] = RSL_Capacity::places(array_fill(0, $n, 12), $a);
    }
}
$check('12 slots a night: 8 to 11 nights give 32, 30, 30, 29 at 2; 27, 27, 26, 26 at 1; 24 at 0', $table === array(
    8 => array(24, 27, 32), 9 => array(24, 27, 30), 10 => array(24, 26, 30), 11 => array(24, 26, 29)), json_encode($table));
$check('no nights: null (not checked)', RSL_Capacity::places(array(), 2) === null);
$check('no slot on any night: 0 places', RSL_Capacity::places(array(0, 0, 0), 2) === 0);
$check('allowance 0: twice the fewest slots on a night with any', RSL_Capacity::places(array(6, 0, 4, 9), 0) === 8);
$check('a night with no slot is no sit-out (5 slots, then none: 10 at allowance 0, not 0)', RSL_Capacity::places(array(5, 0), 0) === 10);
$check('an allowance at or above the nights with a slot never binds: 50', RSL_Capacity::places(array(3, 0, 3), 2) === 50 && RSL_Capacity::places(array(12, 12, 12), 3) === 50);
$check('...and nothing goes above 50 (12 slots on 3 nights at allowance 2 would be 72)', RSL_Capacity::places(array(12, 12, 12), 2) === 50);
$check('one more night lowers the places or keeps them', RSL_Capacity::places(array_fill(0, 10, 12), 2) <= RSL_Capacity::places(array_fill(0, 9, 12), 2));
$check('sit_outs(): the sum of max(0, n − 2 × slots) over nights with a slot', RSL_Capacity::sit_outs(31, array(12, 0, 12, 15)) === 15);

// --- The numbers ----------------------------------------------------------------------------------------
$x = RSL_Capacity::sit_out_numbers(31, array_fill(0, 9, 12));
$check('31 teams over 9 nights of 12 slots: 63 in all, 2.0 each, the most 3', $x['total'] === 63 && $x['average'] == 2.0 && !$x['whole'] && $x['most'] === 3 && $x['nights'] === 9, json_encode($x));
$x = RSL_Capacity::sit_out_numbers(30, array_fill(0, 10, 12));
$check('30 teams over 10 nights: 2 each exactly, the most 2', $x['total'] === 60 && $x['whole'] && $x['most'] === 2, json_encode($x));
$w = RSL_Capacity::sit_out_words(31, array_fill(0, 10, 12), false);
$check('words: about 2.3 nights each this season, so some sit out 3', $w === 'about 2.3 nights each sat out this season, so some sit out 3', $w);
$w = RSL_Capacity::sit_out_words(30, array_fill(0, 10, 12), false);
$check('words: a whole average has no "so some"', $w === 'about 2 nights each sat out this season', $w);
$w = RSL_Capacity::sit_out_words(36, array(12, 12, 12, 12, 12, 12), true);
$check('words once started: in the nights left', $w === 'about 2 nights each sat out in the 6 nights left', $w);
$w = RSL_Capacity::sit_out_words(24, array(6, 6), true);
$check('words: one night each', $w === 'about 1 night each sat out in the 2 nights left', $w);
$w = RSL_Capacity::sit_out_words(25, array(12), true);
$check('words: one night left, and an average that rounds to 0.0 says under 0.1', $w === 'under 0.1 nights each sat out in the 1 night left, so some sit out 1', $w);
$w = RSL_Capacity::sit_out_words(3, array(2, 1, 1), false);
$check('words: a fraction under 1', $w === 'about 0.7 nights each sat out this season, so some sit out 1', $w);
$w = RSL_Capacity::sit_out_words(20, array(12, 12), false);
$check('words: no sit-outs at all', $w === 'there are game slots for every team on every night this season', $w);
$info = array('label' => 'Ranked', 'places' => 30, 'slots_left' => array_fill(0, 10, 12), 'started' => false, 'checked' => true);
$w = RSL_Capacity::over_words($info, 31);
$check('over_words(): teams for places, then the numbers', $w === '31 teams for 30 places: about 2.3 nights each sat out this season, so some sit out 3', $w);
$w = RSL_Capacity::over_words(array_merge($info, array('places' => 0, 'slots_left' => array(0, 0))), 1);
$check('over_words(): a division with no slots says so', $w === '1 team for 0 places: Ranked has no game slots this season', $w);
$w = RSL_Capacity::over_words(array_merge($info, array('slots_left' => array(), 'started' => true)), 31);
$check('over_words(): no nights left', $w === '31 teams for 30 places: no game nights are left this season', $w);
$w = RSL_Capacity::over_words(array_merge($info, array('checked' => false, 'slots_left' => array())), 31);
$check('over_words(): no nights yet', $w === '31 teams for 30 places: this season has no game nights yet, so nights sat out aren\'t counted', $w);

// --- The queue ------------------------------------------------------------------------------------------
$apps = array(array('id' => 41, 'applied_at' => '2026-11-03 10:00:00'), array('id' => 12, 'applied_at' => '2026-11-04 09:00:00'),
    array('id' => 30, 'applied_at' => '2026-11-03 10:00:00'), array('id' => 7, 'applied_at' => '2026-11-05 08:00:00'));
$q = RSL_Capacity::queue(30, 28, $apps);
$check('28 active of 30, 4 applications: the two oldest are ordinary, the two newest wait (a tie on applied_at goes by id)',
    $q['order'] === array(30, 41, 12, 7) && $q['ordinary'] === array(30, 41) && $q['waiting'] === array(12, 7), json_encode($q));
$q = RSL_Capacity::queue(null, 28, $apps);
$check('not checked: nobody waits', $q['waiting'] === array() && count($q['ordinary']) === 4);
$q = RSL_Capacity::queue(30, 31, $apps);
$check('over the places already: everyone waits', count($q['waiting']) === 4 && $q['ordinary'] === array());
$q = RSL_Capacity::queue(0, 0, array((object) array('id' => 5, 'applied_at' => '2026-11-01')));
$check('no places: rows (objects) wait too', $q['waiting'] === array(5));

// --- Words for people -------------------------------------------------------------------------------------
$di = function ($o = array()) {
    return array_merge(array('label' => 'Ranked', 'season' => '2027-pre', 'places' => 30, 'set_by_hand' => false, 'active' => 28, 'pending' => 4, 'waiting' => array(12, 7)), $o);
};
$check('places line', RSL_Capacity::places_line($di()) === 'Ranked, 2027 Pre-season: 28 of 30 places taken, 4 applications waiting (2 on the waiting list).', RSL_Capacity::places_line($di()));
$check('places line: one application, none waiting, set by hand', RSL_Capacity::places_line($di(array('pending' => 1, 'waiting' => array(), 'set_by_hand' => true)))
    === 'Ranked, 2027 Pre-season: 28 of 30 places taken (set by hand), 1 application waiting.');
$check('places line: over the places', RSL_Capacity::places_line($di(array('active' => 31))) === 'Ranked, 2027 Pre-season: 31 teams for 30 places, 4 applications waiting (2 on the waiting list).');
$check('places line: not checked keeps today\'s words', RSL_Capacity::places_line($di(array('places' => null, 'waiting' => array())))
    === 'Ranked, 2027 Pre-season: 28 teams, 4 applications waiting. This season has no game nights yet, so court space isn\'t checked.');
$si = function ($r, $u) {
    return array('season' => '2027-pre', 'divisions' => array('ranked' => array('label' => 'Ranked', 'full' => $r), 'unranked' => array('label' => 'Unranked', 'full' => $u)));
};
$check('full words: Ranked full', RSL_Capacity::full_words($si(true, false)) === 'Ranked is full for 2027 Pre-season. An Unranked team can still get a place; a new Ranked team goes on the waiting list.');
$check('full words: Unranked full', RSL_Capacity::full_words($si(false, true)) === 'Unranked is full for 2027 Pre-season. A Ranked team can still get a place; a new Unranked team goes on the waiting list.');
$check('full words: both full', RSL_Capacity::full_words($si(true, true)) === 'Ranked and Unranked are both full for 2027 Pre-season. A new team goes on the waiting list.');
$check('full words: neither full, nothing', RSL_Capacity::full_words($si(false, false)) === '');
$check('the captain\'s waiting words', RSL_Capacity::waiting_words($di()) === 'Ranked is full for 2027 Pre-season, so your application is on the waiting list. If a place opens and the league approves it, you\'ll get the approval email with your team\'s invite link.');

if ($standalone) {
    echo "SKIP: the database part needs wp eval-file\n";
    echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
    return;
}

// ======================================================================================================
// Database
// ======================================================================================================
global $wpdb;
$A = '2097-pre';   // nights with exactly the slots set below; the sign-up season
$B = '2097-mid1';  // dates, no nights: not checked; open too
$C = '2097-mid2';  // nights from two weeks ago: started
$today = current_time('Y-m-d');
$day = function ($n) use ($today) { return date('Y-m-d', strtotime($today . ' ' . ($n < 0 ? '' : '+') . intval($n) . ' days')); };
// Everything the cleanup reads, set before the try.
$saved = array();
foreach (array(RSL_Seasons::CURRENT_OPTION, RSL_Seasons::SIGNUP_OPTION, RSL_Courts::SETTINGS_OPTION, RSL_Free_Agents::SETTINGS_OPTION, RSL_Portal::PAGE_OPTION) as $opt) {
    $saved[$opt] = get_option($opt, '__unset__');
}
$users = array();
$venue_ids = array();
$made_portal = 0;
$cands = null;
$admin = 0;
$mk = function ($tag, $role = 'subscriber') use ($suffix, &$users) {
    $u = wp_insert_user(array('user_login' => "rslcap-$tag-$suffix", 'user_email' => "rslcap-$tag-$suffix@example.test", 'user_pass' => wp_generate_password(),
        'display_name' => "RSLCap $tag $suffix", 'role' => $role));
    if (is_wp_error($u)) {
        throw new Exception("Couldn't make the test account $tag: " . $u->get_error_message());
    }
    $users[] = $u;
    rsl_dev_profile($u);
    return $u;
};

try {

// --- Setup -------------------------------------------------------------------------------------------------
$fa_rules = RSL_Free_Agents::settings();
$set_rules = function ($o) use (&$fa_rules) {
    $r = RSL_Free_Agents::save_settings(array_merge($fa_rules, $o));
    if (!is_wp_error($r)) { $fa_rules = $r; }
    return $r;
};
$r = $set_rules(array('sit_out_nights' => 0, 'capacity_override' => array('ranked' => 0, 'unranked' => 0)));
$check('setup: allowance 0, no override', !is_wp_error($r) && RSL_Capacity::allowance() === 0, is_wp_error($r) ? $r->get_error_message() : '');
$vr = RSL_Courts::create_venue("Cap Ranked $suffix", 'ranked');
$vu = RSL_Courts::create_venue("Cap Unranked $suffix", 'unranked');
$venue_ids = array(intval($vr->id), intval($vu->id));
$cr = RSL_Courts::create_court($vr->id, 'Court R', '19:00', '20:00');
$cu = RSL_Courts::create_court($vu->id, 'Court U', '19:00', '20:00');
$minutes = array('ranked' => 60, 'unranked' => 60);
RSL_Courts::save_settings($A, array('start' => $day(14), 'end' => $day(34), 'weekday' => 0, 'minutes' => $minutes));
RSL_Courts::save_settings($B, array('start' => $day(70), 'end' => $day(130), 'weekday' => 0, 'minutes' => $minutes));
RSL_Courts::save_settings($C, array('start' => $day(-14), 'end' => $day(13), 'weekday' => 0, 'minutes' => $minutes));
RSL_Courts::generate_nights($A);
RSL_Courts::generate_nights($C);
$nights = RSL_Courts::nights($A);
// On this season's nights only, every other court is off, so each division has exactly the slots set here.
foreach ($nights as $n) {
    foreach (RSL_Courts::venues() as $v) {
        if (in_array(intval($v->id), $venue_ids, true)) {
            continue;
        }
        foreach (RSL_Courts::courts($v->id) as $c) {
            RSL_Courts::set_court_night($n->id, $c->id, false);
        }
    }
}
RSL_Courts::set_court_night($nights[0]->id, $cr->id, true, '19:00', '21:00'); // Ranked: 2 slots on night 1
$counts = RSL_Courts::season_slot_counts($A);
$same = count($counts) === count($nights);
foreach ($counts as $row) {
    $by = array('ranked' => 0, 'unranked' => 0);
    foreach (RSL_Courts::slots_for_night($row['night']->id) as $slot) {
        $by[$slot['division']]++;
    }
    $same = $same && $by === $row['slots'];
}
$check('season_slot_counts() counts each night as slots_for_night() does (a per-night exception included)', $same, json_encode($counts));
$check('setup: three nights, Ranked 2, 1, 1 slots and Unranked 1, 1, 1', count($nights) === 3
    && array_map(function ($r) { return $r['slots']['ranked']; }, $counts) === array(2, 1, 1)
    && array_map(function ($r) { return $r['slots']['unranked']; }, $counts) === array(1, 1, 1), json_encode($counts));
$cap = RSL_Capacity::season($A);
$check('season(): 2 places a division at allowance 0, checked, not started, every night still to come', $cap['checked'] && !$cap['started']
    && $cap['divisions']['ranked']['places'] === 2 && $cap['divisions']['unranked']['places'] === 2
    && $cap['divisions']['ranked']['slots_left'] === array(2, 1, 1) && !$cap['divisions']['ranked']['full'], json_encode($cap['divisions']['ranked']));
$ctx = RSL_Placement::context($A);
$check('placement\'s capacity is the places (not 2 × the first night\'s slots, which would give Ranked 4)', $ctx[2]['capacity']['ranked'] === 2 && $ctx[2]['capacity']['unranked'] === 2
    && $ctx[2]['allowance']['ranked'] === 0, json_encode(array($ctx[2]['capacity'], $ctx[2]['allowance'])));

// The league page: A signs up now, B is open too.
update_option(RSL_Seasons::CURRENT_OPTION, $A);
RSL_Seasons::set_signup($A);
$cands = function ($keys) use ($A, $B) { return array($A, $B); };
add_filter('rsl_open_for_signup_candidates', $cands);
$had = intval(get_option(RSL_Portal::PAGE_OPTION));
$portal_id = intval(RSL_Portal::ensure_page());
$made_portal = $portal_id !== $had ? $portal_id : 0;
$portal = new RSL_Portal();
$view = function ($as) use ($portal) { wp_set_current_user($as); $_GET = array(); return $portal->render(); };
$front = function ($as, $fields) {
    wp_set_current_user($as);
    delete_transient(RSL_Portal::FLASH_PREFIX . $as);
    $to = RSL_Portal::process(array_merge(array('rsl_nonce' => wp_create_nonce('rsl_front')), $fields));
    $f = get_transient(RSL_Portal::FLASH_PREFIX . $as);
    delete_transient(RSL_Portal::FLASH_PREFIX . $as);
    return array((string) $to, is_array($f) ? (string) $f['message'] : '');
};
$admin = $mk('admin', 'administrator');
$adm = function ($fields) use ($A, $admin) {
    wp_set_current_user($admin);
    delete_transient(RSL_Admin::NOTICE_PREFIX . $admin);
    RSL_Admin::process(array_merge(array('rsl_nonce' => wp_create_nonce('rsl_team_action'), 'season' => $A), $fields));
    $n = get_transient(RSL_Admin::NOTICE_PREFIX . $admin);
    delete_transient(RSL_Admin::NOTICE_PREFIX . $admin);
    return is_array($n) ? $n : array('type' => '', 'message' => '');
};
$admin_page = function () use ($A, $admin) {
    wp_set_current_user($admin);
    $_GET = array('page' => RSL_Admin::PAGE, 'season' => $A);
    $page = new RSL_Admin();
    ob_start();
    $page->render();
    $html = ob_get_clean();
    delete_transient(RSL_Admin::NOTICE_PREFIX . $admin);
    $_GET = array();
    return $html;
};
$apply = function ($as, $name, $division, $season) use ($front) {
    return $front($as, array('do' => 'apply', 'team_name' => $name, 'team_level' => 'social', 'team_division' => $division, 'team_season' => $season));
};
$c = array();
foreach (array(1, 2, 4, 5, 6) as $i) {
    $c[$i] = $mk("c$i");
    RSL_Players::register($c[$i], array('level' => 'social', 'years' => '1to3'));
}
$c[3] = $mk('c3'); // registers with Start a team, below
$full_r = 'Ranked is full for 2097 Pre-season. An Unranked team can still get a place; a new Ranked team goes on the waiting list.';
$wait = RSL_Capacity::waiting_words(array('label' => 'Ranked', 'season' => $A));

// --- Applying into a full division ---------------------------------------------------------------------------
$n = $adm(array('do' => 'create_team', 'name' => "T1 $suffix", 'division' => 'ranked', 'colour' => '#8c2361'));
$check('Add a team within the places: the usual notice', $n['message'] === "Team \"T1 $suffix\" created.", $n['message']);
$v = $view($c[1]);
$check('1 of 2 places taken, nothing waiting: the form says nothing about full divisions', strpos($v, 'name="team_division"') !== false && strpos($v, 'is full for') === false);
list($to, $msg) = $apply($c[1], "C1 Team $suffix", 'ranked', $A);
$app1 = RSL_Teams::pending_application_for_user($c[1], $A);
$check('an application within the places: the usual flash', $app1 && empty($app1->waiting_list) && $msg === "Application sent for C1 Team $suffix. We'll email you when it's approved.", $msg);
$v = $view($c[2]);
$check('Ranked full (1 team, 1 application, 2 places): the form says so beside the division and offers Unranked', strpos($v, esc_html($full_r)) !== false
    && strpos($v, 'is full for 2097 Mid-season 1') === false && strpos($v, 'Unranked are both full') === false, $v);
list($to, $msg) = $apply($c[2], "C2 Team $suffix", 'ranked', $A);
$app2 = RSL_Teams::pending_application_for_user($c[2], $A);
$check('applying into a full division still works: pending, on the waiting list, and the flash says what happens next', $app2 && $app2->status === 'pending'
    && RSL_Capacity::on_waiting_list($app2) && $msg === "Application sent for C2 Team $suffix. " . $wait, $msg);
$v = $view($c[2]);
$check('...the captain\'s card says it\'s on the waiting list, and nothing about the other application', strpos($v, esc_html($wait)) !== false
    && strpos($v, "C1 Team $suffix") === false && strpos($v, "T1 $suffix") === false);
$v = $view($c[1]);
$check('...the ordinary application\'s card keeps its words', strpos($v, "We'll email you when it's approved — then you can invite your players.") !== false && strpos($v, 'waiting list. If a place') === false);
$v = $view($c[3]);
$check('the registration form\'s Start a team panel says Ranked is full too', strpos($v, 'rsl-fa-p-start') !== false && strpos($v, esc_html($full_r)) !== false);
list($to, $msg) = $front($c[3], array('do' => 'register', 'level' => 'social', 'years' => '1to3', 'route' => 'start',
    'team_name' => "C3 Team $suffix", 'team_level' => 'solid', 'team_division' => 'ranked', 'team_season' => $A));
$app3 = RSL_Teams::pending_application_for_user($c[3], $A);
$check('Start a team at registration into a full division: on the waiting list, and the flash says so', $app3 && RSL_Capacity::on_waiting_list($app3)
    && $msg === "You're registered and your application for C3 Team $suffix is in. " . $wait, $msg);
list($to, $msg) = $apply($c[4], "C4 Team $suffix", 'ranked', $A);
$app4 = RSL_Teams::pending_application_for_user($c[4], $A);
$q = RSL_Capacity::season($A)['divisions']['ranked'];
$check('the queue: oldest first, the places left go to the oldest', $app4 && $q['order'] === array(intval($app1->id), intval($app2->id), intval($app3->id), intval($app4->id))
    && $q['waiting'] === array(intval($app2->id), intval($app3->id), intval($app4->id)) && $q['left'] === 1 && $q['full'], json_encode($q));

// --- The admin screen ---------------------------------------------------------------------------------------
$html = $admin_page();
$check('admin: the places line for the season and division with applications', strpos($html, esc_html('Ranked, 2097 Pre-season: 1 of 2 places taken, 4 applications waiting (3 on the waiting list).')) !== false
    && strpos($html, 'Unranked, 2097 Pre-season') === false, substr($html, 0, 3000));
$check('admin: applications on the waiting list are labelled (3), and nobody is over the cap yet', substr_count($html, 'On the waiting list') === 3
    && strpos($html, 'Approve over the cap') === false && strpos($html, 'Approving makes') === false);

// --- Withdrawing and declining move the queue -----------------------------------------------------------------
list($to, $msg) = $front($c[4], array('do' => 'withdraw', 'team_id' => $app4->id));
$q = RSL_Capacity::season($A)['divisions']['ranked'];
$check('a withdrawal leaves the queue', $q['waiting'] === array(intval($app2->id), intval($app3->id)), json_encode($q['waiting']));
$n = $adm(array('do' => 'decline', 'team_id' => $app1->id, 'note' => 'Test'));
$q = RSL_Capacity::season($A)['divisions']['ranked'];
$check('a decline moves the next application up: C2 ordinary now, C3 still waiting', $q['waiting'] === array(intval($app3->id))
    && !RSL_Capacity::on_waiting_list(RSL_Teams::get($app2->id)) && RSL_Capacity::on_waiting_list(RSL_Teams::get($app3->id)), json_encode($q));
$v = $view($c[2]);
$check('...and C2\'s card has the usual words again', strpos($v, 'waiting list. If a place') === false && strpos($v, "We'll email you when it's approved") !== false);

// --- Approving over the cap -------------------------------------------------------------------------------------
$n = $adm(array('do' => 'approve', 'team_id' => $app2->id));
$check('approving the last place: no numbers in the notice', RSL_Teams::get($app2->id)->status === 'active' && strpos($n['message'], 'now has') === false
    && strpos($n['message'], "C2 Team $suffix approved") === 0, $n['message']);
$html = $admin_page();
$over3 = 'Approving makes 3 teams for 2 places: about 0.7 nights each sat out this season, so some sit out 1.';
$check('admin: an application whose approval goes over the places says what it costs, and its button says so', strpos($html, esc_html($over3)) !== false
    && strpos($html, 'Approve over the cap') !== false && substr_count($html, 'On the waiting list') === 1
    && strpos($html, esc_html('Ranked, 2097 Pre-season: 2 of 2 places taken, 1 application waiting (1 on the waiting list).')) !== false, substr($html, 0, 3000));
$n = $adm(array('do' => 'approve', 'team_id' => $app3->id));
$check('approving over the cap takes no extra step, and the notice repeats the numbers', RSL_Teams::get($app3->id)->status === 'active'
    && strpos($n['message'], "C3 Team $suffix approved") === 0
    && substr($n['message'], -strlen(' Ranked, 2097 Pre-season now has 3 teams for 2 places: about 0.7 nights each sat out this season, so some sit out 1.'))
        === ' Ranked, 2097 Pre-season now has 3 teams for 2 places: about 0.7 nights each sat out this season, so some sit out 1.', $n['message']);

// --- Other ways onto a division ------------------------------------------------------------------------------------
$n = $adm(array('do' => 'create_team', 'name' => "Extra $suffix", 'division' => 'ranked'));
$check('Add a team over the cap: allowed, and the notice gives the numbers', $n['message'] === "Team \"Extra $suffix\" created. Ranked, 2097 Pre-season now has 4 teams for 2 places: about 1 night each sat out this season.", $n['message']);
$n = $adm(array('do' => 'create_team', 'name' => "U1 $suffix", 'division' => 'unranked'));
$u1 = RSL_Teams::get_by_slug($A, sanitize_title("U1 $suffix"));
$check('Add a team in a division with room: no numbers', $u1 && $n['message'] === "Team \"U1 $suffix\" created.", $n['message']);
$n = $adm(array('do' => 'update_team', 'team_id' => $u1->id, 'name' => "U1 $suffix", 'division' => 'ranked', 'colour' => '#1d3d6e'));
$check('a division change into a division over its places: the notice gives the numbers', RSL_Teams::get($u1->id)->division === 'ranked'
    && $n['message'] === 'Team saved. Ranked, 2097 Pre-season now has 5 teams for 2 places: about 1.4 nights each sat out this season, so some sit out 2.', $n['message']);
$n = $adm(array('do' => 'update_team', 'team_id' => $u1->id, 'name' => "U1 $suffix", 'division' => 'ranked', 'colour' => '#2e8540'));
$check('saving without a division change: the usual notice', $n['message'] === 'Team saved.', $n['message']);
list($to, $msg) = $apply($c[5], "C5 Team $suffix", 'unranked', $A);
$app5 = RSL_Teams::pending_application_for_user($c[5], $A);
$check('an Unranked application with room is ordinary', $app5 && !RSL_Capacity::on_waiting_list($app5) && $msg === "Application sent for C5 Team $suffix. We'll email you when it's approved.", $msg);
$n = $adm(array('do' => 'update_team', 'team_id' => $app5->id, 'name' => "C5 Team $suffix", 'division' => 'ranked', 'colour' => '#1d3d6e'));
$check('an application moved into a full division is on the waiting list there, and the notice says so', RSL_Capacity::on_waiting_list(RSL_Teams::get($app5->id))
    && $n['message'] === 'Team saved. Its application is on the waiting list in Ranked.', $n['message']);
$adm(array('do' => 'create_team', 'name' => "U2 $suffix", 'division' => 'unranked'));
$adm(array('do' => 'create_team', 'name' => "U3 $suffix", 'division' => 'unranked'));
$v = $view($c[6]);
$check('both divisions full: the form says a new team goes on the waiting list', strpos($v, esc_html('Ranked and Unranked are both full for 2097 Pre-season. A new team goes on the waiting list.')) !== false, $v);

// --- Nothing public says a division is full -----------------------------------------------------------------------
wp_set_current_user(0);
$preview = RSL_Public::season_preview($A);
$check('the season preview counts teams only: nothing about places, full divisions or applications', $preview !== ''
    && stripos($preview, 'full') === false && stripos($preview, 'waiting') === false && stripos($preview, 'places') === false, $preview);
$v = $view(0);
$check('a logged-out visitor sees no division words', strpos($v, 'is full for') === false && strpos($v, 'team_division') === false);

// --- Placement --------------------------------------------------------------------------------------------------
$warn = implode(' | ', RSL_Placement::blockers($A)['warnings']);
$check('placement\'s warning uses the places and names the allowance', strpos($warn, 'Ranked would have 5 teams for 2 places: about 1.4 nights each sat out this season, so some sit out 2. 2 is the most it takes with no team sitting out more than 0 nights a season.') !== false
    && strpos($warn, 'first night has game slots') === false, $warn);

// --- A season with no nights, and one that has started --------------------------------------------------------------
$capb = RSL_Capacity::season($B);
$check('a season with no nights: not checked, no places, never full', !$capb['checked'] && $capb['divisions']['ranked']['places'] === null
    && $capb['divisions']['unranked']['places'] === null && !$capb['divisions']['ranked']['full'] && RSL_Placement::context($B)[2]['capacity']['ranked'] === null);
list($to, $msg) = $apply($c[6], "C6 Team $suffix", 'ranked', $B);
$app6 = RSL_Teams::pending_application_for_user($c[6], $B);
$check('...an application there is never on the waiting list', $app6 && !RSL_Capacity::on_waiting_list($app6) && $msg === "Application sent for C6 Team $suffix (2097 Mid-season 1). We'll email you when it's approved.", $msg);
$html = $admin_page();
$check('...the admin line keeps today\'s words', strpos($html, esc_html('Ranked, 2097 Mid-season 1: 0 teams, 1 application waiting. This season has no game nights yet, so court space isn\'t checked.')) !== false);
$warn = RSL_Placement::blockers($B)['warnings'];
$check('...and placement says court space isn\'t checked', in_array('This season has no game nights yet, so court space isn\'t checked.', $warn, true), implode(' | ', $warn));
$capc = RSL_Capacity::season($C);
$left = 0;
foreach (RSL_Courts::nights($C) as $nc) {
    $left += $nc->type === 'regular' && $nc->night_date > $today ? 1 : 0;
}
$w = RSL_Capacity::over_words($capc['divisions']['ranked'], 1);
$check('a season that has started counts the nights left only, and says so', $capc['started'] && count($capc['divisions']['ranked']['slots_left']) === $left
    && count($capc['divisions']['ranked']['slots']) === count(RSL_Courts::nights($C)) && strpos($w, ' left') !== false, $w . ' / ' . $left);

// --- The override and the allowance setting -------------------------------------------------------------------------
$set_rules(array('capacity_override' => array('ranked' => 7, 'unranked' => 0)));
$capa = RSL_Capacity::season($A);
$ctx = RSL_Placement::context($A);
$check('the override wins (Ranked 7, set by hand), and placement reads it', $capa['divisions']['ranked']['places'] === 7 && $capa['divisions']['ranked']['set_by_hand']
    && $capa['divisions']['unranked']['places'] === 2 && $ctx[2]['capacity']['ranked'] === 7 && $ctx[2]['allowance']['ranked'] === null && $ctx[2]['allowance']['unranked'] === 0);
$check('...and with 5 teams and 1 application for 7 places, Ranked isn\'t full', !$capa['divisions']['ranked']['full'] && $capa['divisions']['ranked']['waiting'] === array());
$set_rules(array('capacity_override' => array('ranked' => 0, 'unranked' => 0)));
wp_set_current_user($admin);
$fa_post = function ($fields) use ($A, $admin) {
    delete_transient(RSL_Admin::NOTICE_PREFIX . $admin);
    RSL_Admin_Free_Agents::process(array_merge(array('rsl_nonce' => wp_create_nonce(RSL_Admin_Free_Agents::ACTION), 'season' => $A, 'tab' => 'settings', 'do' => 'save_settings'), $fields));
    $n = get_transient(RSL_Admin::NOTICE_PREFIX . $admin);
    delete_transient(RSL_Admin::NOTICE_PREFIX . $admin);
    return is_array($n) ? $n : array('type' => '', 'message' => '');
};
$n = $fa_post(array_merge($fa_rules, array('sit_out_nights' => '12')));
$check('the allowance setting clamps at ' . RSL_Capacity::MAX_ALLOWANCE . ' and the notice says so', RSL_Free_Agents::settings()['sit_out_nights'] === 10
    && strpos($n['message'], 'Most nights a team sits out in a season 10 (not 12)') !== false, $n['message']);
$check('...at 10 (above the 3 nights) the sum never binds: 50 places', RSL_Capacity::season($A)['divisions']['ranked']['places'] === 50);
$n = $fa_post(array_merge(RSL_Free_Agents::settings(), array('sit_out_nights' => '1')));
$capa = RSL_Capacity::season($A);
$check('the allowance saves (1) and moves the places: Ranked 4, Unranked 3', $n['type'] === 'success' && RSL_Free_Agents::settings()['sit_out_nights'] === 1
    && $capa['divisions']['ranked']['places'] === 4 && $capa['divisions']['unranked']['places'] === 3, $n['message'] . ' ' . json_encode(array($capa['divisions']['ranked']['places'], $capa['divisions']['unranked']['places'])));
$n = $fa_post(array_merge(RSL_Free_Agents::settings(), array('sit_out_nights' => '-3')));
$check('...and never goes below 0', RSL_Free_Agents::settings()['sit_out_nights'] === 0, $n['message']);
$tab = new ReflectionMethod('RSL_Admin_Free_Agents', 'settings_tab');
$tab->setAccessible(true);
$tab_html = $tab->invoke(null, $A);
$check('the Settings tab has both rows under Places in each division, and no Other section', strpos($tab_html, '<h3>Places in each division</h3>') !== false
    && strpos($tab_html, 'name="sit_out_nights"') !== false && strpos($tab_html, 'name="capacity_override[ranked]"') !== false && strpos($tab_html, '<h3>Other</h3>') === false
    && strpos($tab_html, 'Start a team') !== false);

} catch (Throwable $t) {
    $fails++;
    echo 'FAIL: threw ' . get_class($t) . ': ' . $t->getMessage() . ' (line ' . $t->getLine() . ")\n";
} finally {
// --- Cleanup (runs after a failure too) -------------------------------------------------------------------------------
wp_set_current_user(0);
$_GET = array();
if ($cands) {
    remove_filter('rsl_open_for_signup_candidates', $cands);
}
foreach (array($A, $B, $C) as $s_key) {
    foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM " . RSL_Database::table('teams') . " WHERE season = %s", $s_key)) as $tid) {
        $wpdb->delete(RSL_Database::table('team_members'), array('team_id' => $tid));
        $wpdb->delete(RSL_Database::table('invites'), array('team_id' => $tid));
    }
    $wpdb->delete(RSL_Database::table('teams'), array('season' => $s_key));
    foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM " . RSL_Database::table('nights') . " WHERE season = %s", $s_key)) as $nid) {
        $wpdb->delete(RSL_Database::table('night_courts'), array('night_id' => $nid));
        $wpdb->delete(RSL_Database::table('night_venues'), array('night_id' => $nid));
    }
    $wpdb->delete(RSL_Database::table('nights'), array('season' => $s_key));
    $wpdb->delete(RSL_Database::table('free_agents'), array('season' => $s_key));
}
foreach ($venue_ids as $vid) {
    foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM " . RSL_Database::table('courts') . " WHERE venue_id = %d", $vid)) as $cid) {
        $wpdb->delete(RSL_Database::table('night_courts'), array('court_id' => $cid));
    }
    $wpdb->delete(RSL_Database::table('night_venues'), array('venue_id' => $vid));
    $wpdb->delete(RSL_Database::table('courts'), array('venue_id' => $vid));
    $wpdb->delete(RSL_Database::table('venues'), array('id' => $vid));
}
foreach ($users as $u) {
    if (is_int($u) && $u > 0) {
        $wpdb->delete(RSL_Database::table('team_members'), array('user_id' => $u));
        $wpdb->delete(RSL_Database::table('players'), array('user_id' => $u));
        $wpdb->delete(RSL_Database::table('free_agents'), array('user_id' => $u));
        delete_transient(RSL_Portal::FLASH_PREFIX . $u);
        delete_transient(RSL_Portal::APPLY_DRAFT_PREFIX . $u);
        delete_transient(RSL_Admin::NOTICE_PREFIX . $u);
    }
}
if ($made_portal) {
    wp_delete_post($made_portal, true);
}
foreach ($saved as $opt => $was) {
    if ($was === '__unset__') { delete_option($opt); } else { update_option($opt, $was); }
}
if (class_exists('RSL_Portal')) {
    RSL_Portal::flush_menu_state();
}
$wpdb->query($wpdb->prepare("DELETE FROM " . RSL_Database::table('outbox') . " WHERE to_email LIKE %s", '%-' . $suffix . '@example.test'));
rsl_dev_delete_users($users);
rsl_dev_release_mail($hold);
}
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
