<?php
/**
 * Places in each division (0.19.0, RSL_Capacity): the rule, the sit-out
 * numbers, the waiting list, and every place that reads them.
 *
 * Pure (no database): places_detail() against brute force on 3,000 random
 * per-night slot lists at allowances 0 to 4 (nights with 0 slots included, the
 * ceiling of 50 or twice the fewest slots, clamped), the 2027 table,
 * sit_out_numbers() / sit_out_words() / over_words() / over_line(), the
 * waiting-list queue (ties on applied_at go by id), bumped() /
 * would_bump() / bumped_words(), places_line(), full_words() (a division with
 * no game slots too), waiting_words() (with and without the season).
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
 * clamps, moves the places); the ceiling (clamped, no allowance named); a
 * division with no slot (its words); what RSL_Teams::apply() returns and a
 * second submit; a later season named once in the flashes; the success
 * notice after approving over the cap; the Teams screen's counts scoped to
 * this test's rows. Then a fourth season for placement: an older application
 * moved into a division bumping a newer one, the warning that new teams take
 * places applications are in line for, Add a team bumping one, and a
 * placement confirm over the places (the notice's numbers and the bump).
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
// Every n from 0 to 1000 tried: with at most 30 slots a night and an allowance under the nights, no
// answer is above 300, so 1000 fits only when the sum never binds. The ceiling is 50, or twice the
// fewest slots on a night when that's more; clamped when the ceiling, not the allowance, stopped it.
$brute = function ($slots, $a) {
    if (!$slots) { return array('places' => null, 'clamped' => false); }
    $on = array_values(array_filter($slots, function ($s) { return $s > 0; }));
    if (!$on) { return array('places' => 0, 'clamped' => false); }
    $best = 0;
    for ($n = 0; $n <= 1000; $n++) {
        $sum = 0;
        foreach ($on as $s) { $sum += max(0, $n - 2 * $s); }
        if ($sum <= $a * $n) { $best = $n; }
    }
    $top = max(RSL_Capacity::MAX_PLACES, 2 * min($on));
    return array('places' => min($top, $best), 'clamped' => $best > $top);
};
mt_srand(19019);
$bad = array();
$runs = 0;
$seasons = 3000;
for ($i = 0; $i < $seasons; $i++) {
    $len = mt_rand(0, 12);
    $slots = array();
    for ($k = 0; $k < $len; $k++) {
        // Mostly up to 14 slots a night; one season in ten has big nights (up to 30), so the ceiling goes above 50.
        $slots[] = mt_rand(0, 4) === 0 ? 0 : mt_rand(1, $i % 10 === 0 ? 30 : 14);
    }
    for ($a = 0; $a <= 4; $a++) {
        $runs++;
        $want = $brute($slots, $a);
        $got = RSL_Capacity::places_detail($slots, $a);
        if ($want !== $got || RSL_Capacity::places($slots, $a) !== $want['places']) {
            $bad[] = json_encode($slots) . " a=$a want " . json_encode($want) . ' got ' . json_encode($got);
        }
    }
}
$check("places_detail() matches brute force on $seasons random seasons at allowances 0 to 4 ($runs checks: nights with 0 slots, the ceiling, clamped)", !$bad, implode(' | ', array_slice($bad, 0, 5)));
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
$x = RSL_Capacity::places_detail(array(12, 12, 12), 2);
$check('...and nothing goes above 50 (12 slots on 3 nights at allowance 2 would be 72): clamped, so no word blames the allowance', $x === array('places' => 50, 'clamped' => true), json_encode($x));
$check('...51 teams there still sit out at most 2 nights (why it says clamped)', RSL_Capacity::sit_out_numbers(51, array(12, 12, 12))['most'] <= 2);
$check('the 2027 seasons at allowance 2 aren\'t clamped (32 over 8 nights)', RSL_Capacity::places_detail(array_fill(0, 8, 12), 2) === array('places' => 32, 'clamped' => false));
$check('allowance 0 is twice the fewest slots even above 50 (30 slots: 60, not clamped)', RSL_Capacity::places_detail(array(30, 40), 0) === array('places' => 60, 'clamped' => false));
$check('...and a bigger allowance never gives fewer (30 slots on 1 night at allowance 1: 60, clamped)', RSL_Capacity::places_detail(array(30), 1) === array('places' => 60, 'clamped' => true)
    && RSL_Capacity::places_detail(array(30, 30, 30), 2) === array('places' => 60, 'clamped' => true));
$check('ceiling(): 50, twice the fewest slots when that\'s more, 0 with no slot', RSL_Capacity::ceiling(array(12, 0, 12)) === 50 && RSL_Capacity::ceiling(array(30, 0, 40)) === 60
    && RSL_Capacity::ceiling(array(0, 0)) === 0 && RSL_Capacity::ceiling(array()) === 0);
$check('allowance 0 is never clamped', RSL_Capacity::places_detail(array(3, 3), 0) === array('places' => 6, 'clamped' => false));
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
// An admin change that takes a place an ordinary application was in line for.
$at = function ($active, $list) use ($apps) {
    $q = RSL_Capacity::queue(30, $active, $list);
    return array('label' => 'Ranked', 'season' => '2027-pre', 'places' => 30, 'active' => $active, 'order' => $q['order'], 'waiting' => $q['waiting']);
};
$check('bumped(): a new active team puts the newest ordinary application on the waiting list', RSL_Capacity::bumped($at(28, $apps), $at(29, $apps)) === array(41));
$older = array_merge($apps, array(array('id' => 3, 'applied_at' => '2026-11-01 08:00:00')));
$check('...an older application moved in bumps one too, and the moved one isn\'t counted', RSL_Capacity::bumped($at(28, $apps), $at(28, $older), 3) === array(41));
$check('...approving an ordinary application bumps nobody', RSL_Capacity::bumped($at(28, $apps), $at(29, array_slice($apps, 1)), 41) === array());
$check('...nor does a change with nobody ordinary before', RSL_Capacity::bumped($at(30, $apps), $at(31, $apps)) === array());
$check('would_bump(): 2 ordinary applications, 30 places, 30 teams after: 2; 29 after: 1; not checked: 0', RSL_Capacity::would_bump($at(28, $apps), 30) === 2
    && RSL_Capacity::would_bump($at(28, $apps), 29) === 1 && RSL_Capacity::would_bump(array_merge($at(28, $apps), array('places' => null)), 30) === 0);
$check('bumped_words(): one, several, other', RSL_Capacity::bumped_words($at(28, $apps), 1) === '1 application in Ranked, 2027 Pre-season is now on the waiting list.'
    && RSL_Capacity::bumped_words($at(28, $apps), 2) === '2 applications in Ranked, 2027 Pre-season are now on the waiting list.'
    && RSL_Capacity::bumped_words($at(28, $apps), 1, true) === '1 other application in Ranked, 2027 Pre-season is now on the waiting list.');
$ol = array_merge($info, array('season' => '2027-pre', 'active' => 31));
$check('over_line(): over the places gives the numbers; within them, nothing', RSL_Capacity::over_line($ol) === 'Ranked, 2027 Pre-season now has 31 teams for 30 places: about 2.3 nights each sat out this season, so some sit out 3.'
    && RSL_Capacity::over_line(array_merge($ol, array('active' => 30))) === '' && RSL_Capacity::over_line(array_merge($ol, array('places' => null))) === '', RSL_Capacity::over_line($ol));

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
$check('places line: the ceiling says so', RSL_Capacity::places_line($di(array('places' => 50, 'active' => 50, 'clamped' => true)))
    === 'Ranked, 2027 Pre-season: 50 of 50 places taken (the most a division takes), 4 applications waiting (2 on the waiting list).');
$check('places line: no game slots says so', RSL_Capacity::places_line($di(array('label' => 'Unranked', 'season' => '2027-post', 'places' => 0, 'active' => 0, 'pending' => 1, 'waiting' => array(5), 'no_slots' => true)))
    === 'Unranked, 2027 Post-season: 0 of 0 places taken (no game slots this season), 1 application waiting (1 on the waiting list).');
$si = function ($r, $u, $rn = false, $un = false) {
    return array('season' => '2027-pre', 'divisions' => array('ranked' => array('label' => 'Ranked', 'full' => $r || $rn, 'no_slots' => $rn),
        'unranked' => array('label' => 'Unranked', 'full' => $u || $un, 'no_slots' => $un)));
};
$check('full words: Ranked full', RSL_Capacity::full_words($si(true, false)) === 'Ranked is full for 2027 Pre-season. An Unranked team can still get a place; a new Ranked team goes on the waiting list.');
$check('full words: Unranked full', RSL_Capacity::full_words($si(false, true)) === 'Unranked is full for 2027 Pre-season. A Ranked team can still get a place; a new Unranked team goes on the waiting list.');
$check('full words: both full', RSL_Capacity::full_words($si(true, true)) === 'Ranked and Unranked are both full for 2027 Pre-season. A new team goes on the waiting list.');
$check('full words: neither full, nothing', RSL_Capacity::full_words($si(false, false)) === '');
$w = RSL_Capacity::full_words($si(false, false, false, true));
$check('full words: a division with no game slots says that, not full', $w === 'Unranked has no game slots for 2027 Pre-season. A Ranked team can still get a place; a new Unranked team goes on the waiting list.', $w);
$w = RSL_Capacity::full_words($si(true, false, false, true));
$check('full words: one full, the other with no game slots', $w === 'Ranked is full and Unranked has no game slots for 2027 Pre-season. A new team goes on the waiting list.', $w);
$w = RSL_Capacity::full_words($si(false, false, true, true));
$check('full words: neither has game slots', $w === 'Ranked and Unranked have no game slots for 2027 Pre-season. A new team goes on the waiting list.', $w);
$check('the captain\'s waiting words', RSL_Capacity::waiting_words($di()) === 'Ranked is full for 2027 Pre-season, so your application is on the waiting list. If a place opens and the league approves it, you\'ll get the approval email with your team\'s invite link.');
$check('...without the season, for a message that has just named it', RSL_Capacity::waiting_words($di(), false) === 'Ranked is full, so your application is on the waiting list. If a place opens and the league approves it, you\'ll get the approval email with your team\'s invite link.');
$w = RSL_Capacity::waiting_words($di(array('label' => 'Unranked', 'no_slots' => true)));
$check('...a division with no game slots gives the real reason', $w === 'Unranked has no game slots for 2027 Pre-season, so your application is on the waiting list. If the league adds Unranked courts and then approves your application, you\'ll get the approval email with your team\'s invite link.', $w);
$check('...and without the season', strpos(RSL_Capacity::waiting_words($di(array('label' => 'Unranked', 'no_slots' => true)), false), 'Unranked has no game slots that season, so') === 0);

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
$D = '2097-post';  // placement: nights with 1 slot a division (this test's venues only), 2 places each at allowance 0
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
$sent_ok = null;
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
// The Team applications table's rows that are this test's own (the screen lists every pending
// application on the site, so counts over the whole page would include real ones).
$app_rows = function ($html) use ($suffix) {
    $from = strpos($html, '<h2>Team applications');
    if ($from === false) {
        return '';
    }
    $to = strpos($html, '</table>', $from);
    $out = '';
    foreach (explode('<tr', substr($html, $from, $to === false ? null : $to - $from)) as $row) {
        $out .= strpos($row, (string) $suffix) !== false ? '<tr' . $row : '';
    }
    return $out;
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
$check('an application within the places: the usual flash', $app1 && !RSL_Capacity::on_waiting_list($app1) && $msg === "Application sent for C1 Team $suffix. We'll email you when it's approved.", $msg);
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
$r4 = RSL_Teams::apply($A, $c[4], array('name' => "C4 Team $suffix", 'division' => 'ranked', 'level' => 'social'));
$check('RSL_Teams::apply() into a full division returns the application with waiting_list true', is_object($r4) && $r4->status === 'pending' && $r4->waiting_list === true,
    is_wp_error($r4) ? $r4->get_error_message() : json_encode($r4));
list($to, $msg) = $apply($c[4], "C4 Team $suffix", 'ranked', $A);
$check('...the same application sent again from the form is its success, with the waiting-list words', $msg === "Application sent for C4 Team $suffix. " . $wait, $msg);
$app4 = RSL_Teams::pending_application_for_user($c[4], $A);
$q = RSL_Capacity::season($A)['divisions']['ranked'];
$check('the queue: oldest first, the places left go to the oldest', $app4 && $q['order'] === array(intval($app1->id), intval($app2->id), intval($app3->id), intval($app4->id))
    && $q['waiting'] === array(intval($app2->id), intval($app3->id), intval($app4->id)) && $q['left'] === 1 && $q['full'], json_encode($q));

// --- The admin screen ---------------------------------------------------------------------------------------
$html = $admin_page();
$check('admin: the places line for the season and division with applications', strpos($html, esc_html('Ranked, 2097 Pre-season: 1 of 2 places taken, 4 applications waiting (3 on the waiting list).')) !== false
    && strpos($html, 'Unranked, 2097 Pre-season') === false, substr($html, 0, 3000));
$rows = $app_rows($html);
$check('admin: applications on the waiting list are labelled (3), and nobody is over the cap yet', substr_count($rows, 'On the waiting list') === 3
    && substr_count($rows, '<tr') === 4 && strpos($rows, 'Approve over the cap') === false && strpos($rows, 'Approving makes') === false, $rows);
$check('admin: the words under the places lines name the allowance (0 here), with nothing set by hand', strpos($html, esc_html('no team sitting out more than 0 nights in the season')) !== false
    && strpos($html, 'set by hand takes its places') === false);

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
$rows = $app_rows($html);
$check('admin: an application whose approval goes over the places says what it costs, and its button says so', strpos($rows, esc_html($over3)) !== false
    && substr_count($rows, 'Approve over the cap') === 1 && substr_count($rows, 'On the waiting list') === 1
    && strpos($html, esc_html('Ranked, 2097 Pre-season: 2 of 2 places taken, 1 application waiting (1 on the waiting list).')) !== false, $rows);
// The email "sent" (wp_mail answered true after the hold's false, so nothing leaves), so the success branch runs.
$sent_ok = function () { return true; };
add_filter('pre_wp_mail', $sent_ok, 1000);
$n = $adm(array('do' => 'approve', 'team_id' => $app3->id));
remove_filter('pre_wp_mail', $sent_ok, 1000);
$check('approving over the cap takes no extra step, and the success notice repeats the numbers', RSL_Teams::get($app3->id)->status === 'active' && $n['type'] === 'success'
    && $n['message'] === "C3 Team $suffix approved — the captain has been emailed their invite link. Ranked, 2097 Pre-season now has 3 teams for 2 places: about 0.7 nights each sat out this season, so some sit out 1.", $n['message']);

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
$r5 = RSL_Teams::apply($A, $c[5], array('name' => "C5 Team $suffix", 'division' => 'unranked', 'level' => 'social'));
$check('RSL_Teams::apply() with room returns the application with waiting_list false', is_object($r5) && $r5->waiting_list === false, is_wp_error($r5) ? $r5->get_error_message() : '');
list($to, $msg) = $apply($c[5], "C5 Team $suffix", 'unranked', $A);
$app5 = RSL_Teams::pending_application_for_user($c[5], $A);
$check('an Unranked application with room is ordinary (sent again from the form: the usual flash)', $app5 && !RSL_Capacity::on_waiting_list($app5) && $msg === "Application sent for C5 Team $suffix. We'll email you when it's approved.", $msg);
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
$words = preg_replace('#<script\b.*?</script>#s', '', $preview); // the countdown script's getUTCFullYear isn't a word
$check('the season preview counts teams only: nothing about places, full divisions or applications', $preview !== ''
    && !preg_match('/\b(full|waiting|places?|applications?)\b/i', $words), $words);
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
// A season other than the sign-up season names itself once: the aside, not the waiting-list words again.
// Most teams per division at 1 for a moment gives B one Ranked place, which C6's application has.
$set_rules(array('capacity_override' => array('ranked' => 1, 'unranked' => 0)));
$c[7] = $mk('c7');
RSL_Players::register($c[7], array('level' => 'social', 'years' => '1to3'));
list($to, $msg) = $apply($c[7], "C7 Team $suffix", 'ranked', $B);
$check('a waiting-list flash for a later season names it once', $msg === "Application sent for C7 Team $suffix (2097 Mid-season 1). Ranked is full, so your application is on the waiting list. "
    . "If a place opens and the league approves it, you'll get the approval email with your team's invite link.", $msg);
$c[8] = $mk('c8');
list($to, $msg) = $front($c[8], array('do' => 'register', 'level' => 'social', 'years' => '1to3', 'route' => 'start',
    'team_name' => "C8 Team $suffix", 'team_level' => 'social', 'team_division' => 'ranked', 'team_season' => $B));
$check('...and so does the registration flash', $msg === "You're registered and your application for C8 Team $suffix (2097 Mid-season 1) is in. Ranked is full, so your application is on the waiting list. "
    . "If a place opens and the league approves it, you'll get the approval email with your team's invite link.", $msg);
$v = $view($c[7]);
$check('...the card names the season (it has no aside)', strpos($v, esc_html('Ranked is full for 2097 Mid-season 1, so your application is on the waiting list.')) !== false);
$set_rules(array('capacity_override' => array('ranked' => 0, 'unranked' => 0)));
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
$capa = RSL_Capacity::season($A);
$ctx = RSL_Placement::context($A);
$check('...at 10 (above the 3 nights) the sum never binds: 50 places, clamped, and placement doesn\'t name the allowance', $capa['divisions']['ranked']['places'] === 50
    && $capa['divisions']['ranked']['clamped'] && $ctx[2]['capacity']['ranked'] === 50 && $ctx[2]['allowance']['ranked'] === null, json_encode($ctx[2]['allowance']));
$check('...and the places line says it\'s the most a division takes', strpos(RSL_Capacity::places_line($capa['divisions']['ranked']), '(the most a division takes)') !== false, RSL_Capacity::places_line($capa['divisions']['ranked']));
$n = $fa_post(array_merge(RSL_Free_Agents::settings(), array('sit_out_nights' => '1')));
$capa = RSL_Capacity::season($A);
$check('the allowance saves (1) and moves the places: Ranked 4, Unranked 3', $n['type'] === 'success' && RSL_Free_Agents::settings()['sit_out_nights'] === 1
    && $capa['divisions']['ranked']['places'] === 4 && $capa['divisions']['unranked']['places'] === 3, $n['message'] . ' ' . json_encode(array($capa['divisions']['ranked']['places'], $capa['divisions']['unranked']['places'])));
$n = $fa_post(array_merge(RSL_Free_Agents::settings(), array('sit_out_nights' => '-3')));
$check('...and never goes below 0', RSL_Free_Agents::settings()['sit_out_nights'] === 0, $n['message']);
// A division with no slot on any night: 0 places, and placement doesn't blame the allowance.
foreach ($nights as $nn) {
    RSL_Courts::set_court_night($nn->id, $cu->id, false);
}
$capa = RSL_Capacity::season($A);
$ctx = RSL_Placement::context($A);
$warn = implode(' | ', RSL_Placement::blockers($A)['warnings']);
$check('no Unranked slot on any night: 0 places, no allowance named, and the warning says why', $capa['divisions']['unranked']['places'] === 0
    && $capa['divisions']['unranked']['no_slots'] && !$capa['divisions']['ranked']['no_slots']
    && $ctx[2]['capacity']['unranked'] === 0 && $ctx[2]['allowance']['unranked'] === null && $ctx[2]['allowance']['ranked'] === 0
    && $ctx[2]['no_slots'] === array('unranked' => true, 'ranked' => false)
    && strpos($warn, 'Unranked would have 2 teams for 0 places: Unranked has no game slots this season.') !== false
    && strpos($warn, '0 is the most it takes') === false, $warn);
$check('...the places line gives the reason', strpos(RSL_Capacity::places_line($capa['divisions']['unranked']), '(no game slots this season)') !== false);
$p = $mk('p');
RSL_Players::register($p, array('level' => 'social', 'years' => '1to3'));
$v = $view($p);
$check('...and Start a team says Unranked has no game slots, not that it\'s full', strpos($v, esc_html('Ranked is full and Unranked has no game slots for 2097 Pre-season. A new team goes on the waiting list.')) !== false
    && strpos($v, 'Unranked is full') === false, $v);
$tab = new ReflectionMethod('RSL_Admin_Free_Agents', 'settings_tab');
$tab->setAccessible(true);
$tab_html = $tab->invoke(null, $A);
$check('the Settings tab has both rows under Places in each division, and no Other section', strpos($tab_html, '<h3>Places in each division</h3>') !== false
    && strpos($tab_html, 'name="sit_out_nights"') !== false && strpos($tab_html, 'name="capacity_override[ranked]"') !== false && strpos($tab_html, '<h3>Other</h3>') === false
    && strpos($tab_html, 'Start a team') !== false);

// --- Placement confirm, and changes that take a place an application was in line for -------------------------------------
// D: one slot a night in each division (this test's two courts; every other court off on its nights), allowance 0, so
// 2 places each. Teams of 4 to 6 here, so a handful of free agents makes a new team.
$r = $set_rules(array('min_roster' => 4, 'target_roster' => 4, 'new_team_target' => 4, 'max_roster' => 6));
$check('placement setup: teams of 4 to 6', !is_wp_error($r) && RSL_Free_Agents::settings()['min_roster'] === 4, is_wp_error($r) ? $r->get_error_message() : '');
RSL_Courts::save_settings($D, array('start' => $day(150), 'end' => $day(170), 'weekday' => 0, 'minutes' => $minutes));
RSL_Courts::generate_nights($D);
foreach (RSL_Courts::nights($D) as $n) {
    foreach (RSL_Courts::venues() as $vv) {
        if (!in_array(intval($vv->id), $venue_ids, true)) {
            foreach (RSL_Courts::courts($vv->id) as $cc) {
                RSL_Courts::set_court_night($n->id, $cc->id, false);
            }
        }
    }
}
$tu = RSL_Teams::create($D, array('name' => "DU $suffix", 'division' => 'unranked', 'level' => 'social', 'status' => 'active'));
$tr = RSL_Teams::create($D, array('name' => "DR $suffix", 'division' => 'ranked', 'level' => 'social', 'status' => 'active'));
foreach (array(1, 2, 3, 4) as $i) {
    RSL_Teams::add_member($tu->id, $c[$i], $i === 1 ? 'captain' : 'player');
    RSL_Teams::add_member($tr->id, $c[$i + 4], $i === 1 ? 'captain' : 'player');
}
$q = $mk('q');
RSL_Players::register($q, array('level' => 'social', 'years' => '1to3'));
$appq = RSL_Teams::apply($D, $q, array('name' => "Q Team $suffix", 'division' => 'ranked', 'level' => 'social'));
$appp = RSL_Teams::apply($D, $p, array('name' => "P Team $suffix", 'division' => 'unranked', 'level' => 'social'));
$capd = RSL_Capacity::season($D);
$check('placement setup: 2 places a division, one team each with 4 players, an ordinary application each', is_object($appq) && is_object($appp)
    && $capd['divisions']['ranked']['places'] === 2 && $capd['divisions']['unranked']['places'] === 2
    && $capd['divisions']['ranked']['active'] === 1 && $capd['divisions']['unranked']['active'] === 1
    && !$capd['divisions']['ranked']['waiting'] && !$capd['divisions']['unranked']['waiting'], json_encode(array($capd['divisions']['ranked'], $capd['divisions']['unranked'])));
// An older application moved into a division puts a newer one there on the waiting list.
$n = $adm(array('do' => 'update_team', 'season' => $D, 'team_id' => $appq->id, 'name' => "Q Team $suffix", 'division' => 'unranked', 'colour' => '#1d3d6e'));
$check('an older application moved into a division with one place left: the newer one there goes on the waiting list, and the notice says so (no team named)',
    $n['message'] === 'Team saved. 1 other application in Unranked, 2097 Post-season is now on the waiting list.' && RSL_Capacity::on_waiting_list(RSL_Teams::get($appp->id))
    && !RSL_Capacity::on_waiting_list(RSL_Teams::get($appq->id)), $n['message']);
$n = $adm(array('do' => 'update_team', 'season' => $D, 'team_id' => $appq->id, 'name' => "Q Team $suffix", 'division' => 'ranked', 'colour' => '#1d3d6e'));
$check('...moved back, where it has room: the usual notice, and the other is ordinary again', $n['message'] === 'Team saved.' && !RSL_Capacity::on_waiting_list(RSL_Teams::get($appp->id)), $n['message']);
$fa = array();
foreach (array('unranked', 'ranked') as $d) {
    for ($i = 1; $i <= 4; $i++) {
        $u = $mk("fa$d$i");
        RSL_Players::register($u, $d === 'ranked' ? array('level' => 'state', 'years' => 'gt3', 'positions' => array('outside')) : array('level' => 'social', 'years' => '1to3', 'positions' => array('outside')));
        RSL_Free_Agents::join($u, $D, array('division_pref' => $d, 'captain_ok' => $i === 1 ? 1 : 0, 'browsable' => 1), true);
        $fa[] = $u;
    }
}
$sum = RSL_Placement::suggest($D);
$drafts = RSL_Free_Agents::building($D)['new_teams'];
$check('placement proposes one new team in each division (1 place left in each)', !is_wp_error($sum) && isset($drafts['U1'], $drafts['R1']) && !isset($drafts['U2']) && !isset($drafts['R2']),
    is_wp_error($sum) ? $sum->get_error_message() : json_encode(array_keys((array) $drafts)));
$warn = RSL_Placement::blockers($D)['warnings'];
$bump_u = 'Confirming fills a place that 1 Unranked application waiting for approval is next in line for, so it goes on the waiting list.';
$bump_r = 'Confirming fills a place that 1 Ranked application waiting for approval is next in line for, so it goes on the waiting list.';
$check('placement warns that the new teams take places applications are in line for (no division over its places)', in_array($bump_u, $warn, true) && in_array($bump_r, $warn, true)
    && !preg_grep('/would have \d+ teams for/', $warn), implode(' | ', $warn));
// Add a team takes Ranked's last place, so Q's application goes on the waiting list.
$n = $adm(array('do' => 'create_team', 'season' => $D, 'name' => "DX $suffix", 'division' => 'ranked'));
$check('Add a team taking the place an application was in line for: the notice says it\'s now on the waiting list', $n['message'] === "Team \"DX $suffix\" created. 1 application in Ranked, 2097 Post-season is now on the waiting list."
    && RSL_Capacity::on_waiting_list(RSL_Teams::get($appq->id)), $n['message']);
$warn = RSL_Placement::blockers($D)['warnings'];
$check('...now placement\'s new Ranked team goes over: the numbers and the allowance, and no Ranked bump (nobody ordinary left there)',
    (bool) preg_grep('/^Ranked would have 3 teams for 2 places: about 1 night each sat out (this season|in the \d+ nights? left)\. 2 is the most it takes with no team sitting out more than 0 nights a season\.$/', $warn)
    && in_array($bump_u, $warn, true) && !in_array($bump_r, $warn, true), implode(' | ', $warn));
$ver = intval(RSL_Free_Agents::building($D)['draft_version']);
$named = RSL_Placement::update_new($D, 'U1', array('name' => "DU New $suffix"), $ver);
$ver = intval(RSL_Free_Agents::building($D)['draft_version']);
$named = $named === true && RSL_Placement::update_new($D, 'R1', array('name' => "DR New $suffix"), $ver) === true;
$check('the new teams are named', $named && !RSL_Placement::blockers($D)['blockers'], implode(' | ', RSL_Placement::blockers($D)['blockers']));
wp_set_current_user($admin);
delete_transient(RSL_Admin::NOTICE_PREFIX . $admin);
RSL_Admin_Free_Agents::process(array('rsl_nonce' => wp_create_nonce(RSL_Admin_Free_Agents::ACTION), 'season' => $D, 'tab' => 'placement', 'do' => 'confirm',
    'version' => intval(RSL_Free_Agents::building($D)['draft_version'])));
$n = get_transient(RSL_Admin::NOTICE_PREFIX . $admin);
delete_transient(RSL_Admin::NOTICE_PREFIX . $admin);
$n = is_array($n) ? $n : array('type' => '', 'message' => '');
$made = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . RSL_Database::table('teams') . " WHERE season = %s AND formed_by = 'placement' AND status = 'active'", $D)));
$check('placement confirm over the places is allowed: two new teams, and the notice gives Ranked\'s numbers', $n['type'] === 'success' && $made === 2
    && (bool) preg_match('/ Ranked, 2097 Post-season now has 3 teams for 2 places: about 1 night each sat out (this season|in the \d+ nights? left)\./', $n['message']), $n['message']);
$check('...and says the Unranked application waiting for approval is now on the waiting list', strpos($n['message'], ' 1 application in Unranked, 2097 Post-season is now on the waiting list.') !== false
    && RSL_Capacity::on_waiting_list(RSL_Teams::get($appp->id)) && strpos($n['message'], 'P Team') === false && strpos($n['message'], 'Q Team') === false, $n['message']);

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
if ($sent_ok) {
    remove_filter('pre_wp_mail', $sent_ok, 1000);
}
$all_drafts = get_option(RSL_Free_Agents::BUILDING_OPTION, array());
if (is_array($all_drafts) && isset($all_drafts[$D])) {
    unset($all_drafts[$D]);
    update_option(RSL_Free_Agents::BUILDING_OPTION, $all_drafts, false);
}
$wpdb->delete(RSL_Database::table('join_requests'), array('season' => $D));
foreach (array($A, $B, $C, $D) as $s_key) {
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
$wpdb->query($wpdb->prepare("DELETE FROM " . RSL_Database::table('outbox') . " WHERE to_email LIKE %s OR dedupe LIKE %s", '%-' . $suffix . '@example.test', 'optout:' . $D . ':%'));
rsl_dev_delete_users($users);
rsl_dev_release_mail($hold);
}
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
