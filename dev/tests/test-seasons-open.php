<?php
/**
 * The seasons open for sign-up (0.15.0): RSL_Seasons::open_for_signup()
 * and its pure core open_from(), the date labels (label_with_dates(),
 * dates_words(), day_words()), quarters, calendar order (ordinal()),
 * span() and the rsl_open_for_signup_candidates filter; and (0.17.0) the
 * choices() span, signup_choices(), pick_open() (the one check of a season a
 * player picks) and default_pick(). Pure checks first,
 * with made-up keys and a made-up today; then the wrapper with far-future
 * (and far-past) seasons only. The site's real seasons are only read.
 *
 *   wp eval-file dev/tests/test-seasons-open.php
 */

if (!defined('WP_CLI') || !WP_CLI || (wp_get_environment_type() === 'production' && !getenv('RSL_DEV'))) {
    exit("Local copy only: run with wp eval-file, with RSL_DEV=1 set (docker exec -e RSL_DEV=1 ...).\n");
}

$fails = 0;
$check = function ($label, $ok, $detail = '') use (&$fails) {
    if (!$ok) { $fails++; }
    echo ($ok ? "OK: $label\n" : "FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n");
};
$today = current_time('Y-m-d');
$day = function ($n) use ($today) { return date('Y-m-d', strtotime($today . ' ' . ($n < 0 ? '' : '+') . intval($n) . ' days')); };
$saved = array();
foreach (array(RSL_Seasons::SIGNUP_OPTION, RSL_Courts::SETTINGS_OPTION) as $opt) { $saved[$opt] = get_option($opt, '__unset__'); }
$cands = null;
$swap = null;

try {
    // --- Pure helpers ------------------------------------------------------------------------
    $check('short_label drops the time of year; a bad key comes back as given',
        RSL_Seasons::short_label('2027-pre') === '2027 Pre-season' && RSL_Seasons::short_label('2027-mid1') === '2027 Mid-season 1' && RSL_Seasons::short_label('bogus') === 'bogus');
    $quarters = array(RSL_Seasons::quarter('2093-pre'), RSL_Seasons::quarter('2093-mid1'), RSL_Seasons::quarter('2093-mid2'), RSL_Seasons::quarter('2093-post'));
    $check('quarters: Jan–Mar, Apr–Jun, Jul–Sep, Oct–Dec; a bad key has none', $quarters === array(
        array('2093-01-01', '2093-03-31'), array('2093-04-01', '2093-06-30'), array('2093-07-01', '2093-09-30'), array('2093-10-01', '2093-12-31'),
    ) && RSL_Seasons::quarter('bogus') === null, wp_json_encode($quarters));
    $check('calendar order by ordinal(), not by the keys as strings', RSL_Seasons::ordinal('2093-pre') < RSL_Seasons::ordinal('2093-mid1')
        && RSL_Seasons::ordinal('2093-mid1') < RSL_Seasons::ordinal('2093-post') && RSL_Seasons::ordinal('2093-post') < RSL_Seasons::ordinal('2094-pre')
        && RSL_Seasons::ordinal('bogus') === -1 && strcmp('2093-mid1', '2093-pre') < 0);
    $check('dates in words: no year unless it isn\'t the season\'s; one night is one date',
        RSL_Seasons::dates_words('2093-01-24', '2093-03-21', 2093) === '24 Jan to 21 Mar'
        && RSL_Seasons::dates_words('2092-12-13', '2093-03-21', 2093) === '13 Dec 2092 to 21 Mar'
        && RSL_Seasons::dates_words('2093-10-03', '2094-01-09', 2093) === '3 Oct to 9 Jan 2094'
        && RSL_Seasons::dates_words('2093-01-24', '2093-01-24', 2093) === '24 Jan');
    $dated = function ($start, $end) { return array('start' => $start, 'end' => $end, 'dated' => true); };
    $check('label with dates, or "dates to come" with what it covers',
        RSL_Seasons::label_with_dates('2093-pre', $dated('2093-01-24', '2093-03-21')) === '2093 Pre-season (24 Jan to 21 Mar)'
        && RSL_Seasons::label_with_dates('2093-pre', RSL_Seasons::quarter_span('2093-pre')) === '2093 Pre-season (dates to come; covers 1 Jan to 31 Mar)');

    // --- open_from(): the rules ------------------------------------------------------------------
    $spans = array(
        '2093-pre' => $dated('2093-01-24', '2093-03-21'),
        '2093-mid1' => $dated('2093-04-04', '2093-06-06'),
        '2093-mid2' => RSL_Seasons::quarter_span('2093-mid2'),
        '2093-post' => $dated('2093-10-03', '2093-11-28'),
        '2094-pre' => $dated('2094-01-23', '2094-03-20'),
        '1990-pre' => $dated('1990-01-21', '1990-03-18'),
    );
    $keys = array('2094-pre', '2093-post', '2093-mid2', '2093-mid1', '1990-pre', 'bogus');
    $open = RSL_Seasons::open_from('2093-pre', $keys, $spans, '2093-02-01');
    $labels_ok = true;
    foreach ($open as $k => $label) { $labels_ok = $labels_ok && $label === RSL_Seasons::label_with_dates($k, $spans[$k]); }
    $check('the sign-up season plus later seasons with dates, in calendar order (no dates, earlier, bad: left out)',
        array_keys($open) === array('2093-pre', '2093-mid1', '2093-post', '2094-pre') && $labels_ok && $open['2094-pre'] === '2094 Pre-season (23 Jan to 20 Mar)', wp_json_encode($open));
    $undated = $spans;
    $undated['2093-pre'] = RSL_Seasons::quarter_span('2093-pre');
    $open = RSL_Seasons::open_from('2093-pre', $keys, $undated, '2093-02-01');
    reset($open);
    $check('a sign-up season with no dates is still offered first, saying what it covers', key($open) === '2093-pre'
        && $open['2093-pre'] === '2093 Pre-season (dates to come; covers 1 Jan to 31 Mar)', wp_json_encode($open));
    $after = RSL_Seasons::open_from('2093-pre', $keys, $spans, '2093-03-22');
    $last = RSL_Seasons::open_from('2093-pre', $keys, $spans, '2093-03-21');
    reset($after);
    $check('a finished sign-up season is left out (the list starts at the next); on its last night it\'s still in',
        !isset($after['2093-pre']) && key($after) === '2093-mid1' && isset($last['2093-pre']), wp_json_encode($after));
    $over = $spans;
    $over['2093-mid1'] = $dated('2093-01-02', '2093-01-30');
    $check('a later season whose dates are already over is left out', !isset(RSL_Seasons::open_from('2093-pre', $keys, $over, '2093-02-01')['2093-mid1']));
    $check('an invalid sign-up season, or nothing qualifying, gives nothing', RSL_Seasons::open_from('bogus', $keys, $spans, '2093-02-01') === array()
        && RSL_Seasons::open_from('2093-pre', array(), array('2093-pre' => $dated('2093-01-01', '2093-01-08')), '2093-02-01') === array());
    $check('a sign-up season with no dates is finished once its quarter has ended',
        RSL_Seasons::open_from('2093-pre', array(), array('2093-pre' => RSL_Seasons::quarter_span('2093-pre')), '2093-04-01') === array()
        && RSL_Seasons::open_from('2093-pre', array(), array('2093-pre' => RSL_Seasons::quarter_span('2093-pre')), '2093-03-31') === array('2093-pre' => '2093 Pre-season (dates to come; covers 1 Jan to 31 Mar)'));
    $check('a sign-up season missing from the spans gets its quarter; a later one missing has no dates (left out)',
        RSL_Seasons::open_from('2093-pre', array(), array(), '2093-02-01') === array('2093-pre' => '2093 Pre-season (dates to come; covers 1 Jan to 31 Mar)')
        && RSL_Seasons::open_from('2093-pre', array('2093-mid1'), array(), '2093-02-01') === array('2093-pre' => '2093 Pre-season (dates to come; covers 1 Jan to 31 Mar)')
        && RSL_Seasons::quarter_span('2093-mid1') === array('start' => '2093-04-01', 'end' => '2093-06-30', 'dated' => false)
        && RSL_Seasons::quarter_span('bogus') === null);

    // --- choices(): the admin pickers and open_for_signup()'s candidates (0.17.0) -----------------
    $ch = RSL_Seasons::choices();
    $y = (int) current_time('Y');
    $keys = array_keys($ch);
    $ordered = true;
    for ($i = 1; $i < count($keys); $i++) { $ordered = $ordered && RSL_Seasons::ordinal($keys[$i]) === RSL_Seasons::ordinal($keys[$i - 1]) + 1; }
    $check('choices(): every season this year and the next two, in calendar order', count($ch) === 12 && $keys[0] === $y . '-pre'
        && $keys[11] === ($y + 2) . '-post' && $ordered && $ch[$y . '-mid1'] === RSL_Seasons::label($y . '-mid1'), wp_json_encode($keys));

    // --- span() and the wrapper ------------------------------------------------------------------
    RSL_Courts::save_settings('2093-mid1', array('start' => $day(70), 'end' => $day(130)));
    $s = RSL_Seasons::span('2093-mid1');
    $check('span(): the season\'s first and last game night once set', $s === array('start' => $day(70), 'end' => $day(130), 'dated' => true), wp_json_encode($s));
    $check('span(): its quarter with no dates; nothing for a bad key', RSL_Seasons::span('2093-mid2') === RSL_Seasons::quarter_span('2093-mid2') && RSL_Seasons::span('bogus') === null);

    RSL_Courts::save_settings('2093-pre', array('start' => $day(10), 'end' => $day(60)));
    RSL_Courts::save_settings('1990-pre', array('start' => '1990-01-21', 'end' => '1990-03-18'));
    RSL_Seasons::set_signup('2093-pre');
    $cands = function ($keys) { return array('2093-mid1', '2093-mid2', '1990-pre'); };
    add_filter('rsl_open_for_signup_candidates', $cands);
    $open = RSL_Seasons::open_for_signup();
    $labels_ok = true;
    foreach ($open as $k => $label) { $labels_ok = $labels_ok && $label === RSL_Seasons::label_with_dates($k, RSL_Seasons::span($k)); }
    $check('open_for_signup(): the sign-up season, then later candidates with dates (the filter adds them)', array_keys($open) === array('2093-pre', '2093-mid1') && $labels_ok, wp_json_encode($open));

    // The one check of a season a player picks (0.17.0).
    $check('signup_choices(): the open list while something is open', RSL_Seasons::signup_choices() === $open, wp_json_encode(RSL_Seasons::signup_choices()));
    $check('pick_open(): nothing picked is the sign-up season; an open season is itself', RSL_Seasons::pick_open('') === '2093-pre' && RSL_Seasons::pick_open('2093-mid1') === '2093-mid1');
    $closed = RSL_Seasons::pick_open('1990-pre');
    $check('pick_open(): a season that isn\'t open is refused, naming it', is_wp_error($closed) && $closed->get_error_code() === 'rsl_season_closed'
        && $closed->get_error_message() === '1990 Pre-season isn\'t open for sign-up — pick one of the seasons listed.', is_wp_error($closed) ? $closed->get_error_message() : wp_json_encode($closed));
    $check('pick_open(): a later season with no dates isn\'t offered, so it\'s refused', is_wp_error(RSL_Seasons::pick_open('2093-mid2')));
    $bogus = RSL_Seasons::pick_open('bogus');
    $check('pick_open(): a key that isn\'t a season is refused without naming it', is_wp_error($bogus) && $bogus->get_error_message() === 'Pick one of the seasons listed.');
    $check('default_pick(): the sign-up season when offered, else the first offered, else nothing', RSL_Seasons::default_pick($open) === '2093-pre'
        && RSL_Seasons::default_pick(array('2093-mid1' => 'x', '2093-mid2' => 'y')) === '2093-mid1' && RSL_Seasons::default_pick(array()) === '');
    remove_filter('rsl_open_for_signup_candidates', $cands);
    $cands = null;

    RSL_Seasons::set_signup('1990-pre');
    // A stale pin (the sign-up season finished, a later one open): the season offered, not the finished one.
    $swap = function ($keys) { return array('2093-mid1'); };
    add_filter('rsl_open_for_signup_candidates', $swap);
    $check('a stale sign-up pin: nothing picked is the later season on offer, and the pickers start on it', RSL_Seasons::pick_open('') === '2093-mid1'
        && RSL_Seasons::default_pick(RSL_Seasons::signup_choices()) === '2093-mid1', wp_json_encode(RSL_Seasons::signup_choices()));
    remove_filter('rsl_open_for_signup_candidates', $swap);
    $swap = function ($keys) { return array(); };
    add_filter('rsl_open_for_signup_candidates', $swap);
    $check('nothing open at all: the sign-up season alone, as before (the page never dead-ends)',
        RSL_Seasons::signup_choices() === array('1990-pre' => RSL_Seasons::label_with_dates('1990-pre', RSL_Seasons::span('1990-pre'))) && RSL_Seasons::pick_open('') === '1990-pre',
        wp_json_encode(RSL_Seasons::signup_choices()));
    remove_filter('rsl_open_for_signup_candidates', $swap);
    $swap = null;
    $open = RSL_Seasons::open_for_signup();
    $generic = !isset($open['1990-pre']);
    foreach (array_keys($open) as $k) {
        $sp = RSL_Seasons::span($k);
        $generic = $generic && RSL_Seasons::ordinal($k) > RSL_Seasons::ordinal('1990-pre') && $sp['dated'] && $sp['end'] >= $today;
    }
    $check('a finished pinned sign-up season isn\'t offered; everything listed is later, dated and not finished', $generic, wp_json_encode($open));

    RSL_Seasons::set_signup('');
    $signup = RSL_Seasons::signup();
    $sp = RSL_Seasons::span($signup);
    $open = RSL_Seasons::open_for_signup();
    reset($open);
    $check('unpinned: the sign-up season comes first while it hasn\'t finished', $sp['end'] < $today || key($open) === $signup, $signup . ' / ' . wp_json_encode($open));
} catch (Throwable $t) {
    $fails++;
    echo 'FAIL: threw ' . get_class($t) . ': ' . $t->getMessage() . "\n";
} finally {
    if ($cands) {
        remove_filter('rsl_open_for_signup_candidates', $cands);
    }
    if ($swap) {
        remove_filter('rsl_open_for_signup_candidates', $swap);
    }
    foreach ($saved as $opt => $value) {
        if ($value === '__unset__') { delete_option($opt); } else { update_option($opt, $value); }
    }
}
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
