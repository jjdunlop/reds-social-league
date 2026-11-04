<?php
/**
 * Fixtures, results, ratings, ladders and the public fixtures pages.
 *
 *   wp eval-file dev/tests/test-fixtures.php
 */

if (!defined('WP_CLI') || !WP_CLI || (wp_get_environment_type() === 'production' && !getenv('RSL_DEV'))) {
    exit("Local copy only: run with wp eval-file, with RSL_DEV=1 set (docker exec -e RSL_DEV=1 ...).\n");
}
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once dirname(__DIR__) . '/lib-mail-stop.php';
global $wpdb;

$season = '2093-pre';
$suffix = time();
$hold = rsl_dev_hold_mail($suffix);
$fails = 0;
$check = function ($label, $ok, $detail = '') use (&$fails, $hold) {
    rsl_dev_park_mail($hold);
    if (!$ok) { $fails++; }
    echo ($ok ? "OK: $label\n" : "FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n");
};
$saved_current = get_option(RSL_Seasons::CURRENT_OPTION, '__unset__');
$person = 0; // set before the try, so the cleanup can always run
update_option(RSL_Seasons::CURRENT_OPTION, $season);

try {

// --- Setup: a venue with two courts, six nights (three gone, three to come) ---------
$hall = RSL_Courts::create_venue("Test Hall $suffix", 'ranked');
$cx = RSL_Courts::create_court($hall->id, 'Court X', '19:00', '21:00');
$cy = RSL_Courts::create_court($hall->id, 'Court Y', '19:00', '21:00');
$today = current_time('Y-m-d');
$sunday = date('w', strtotime($today)) === '0' ? $today : date('Y-m-d', strtotime('last sunday', strtotime($today)));
RSL_Courts::save_settings($season, array('start' => date('Y-m-d', strtotime($sunday . ' -2 weeks')), 'end' => date('Y-m-d', strtotime($sunday . ' +3 weeks')),
    'weekday' => 0, 'minutes' => array('ranked' => 60, 'unranked' => 60)));
RSL_Courts::generate_nights($season);
$nights = RSL_Courts::nights($season);
RSL_Courts::set_night_type($nights[4]->id, 'bye', 'Holiday');
RSL_Courts::set_night_type($nights[5]->id, 'finals');
$check('six nights set up', count($nights) === 6);

$team = function ($name, $division, $level) use ($season, $suffix) {
    return RSL_Teams::create($season, array('name' => "$name $suffix", 'division' => $division, 'level' => $level));
};
$A = $team('Aces', 'ranked', 'advanced');
$B = $team('Blockers', 'ranked', 'solid');
$C = $team('Cobras', 'ranked', 'solid');
$D = $team('Diggers', 'ranked', 'new');
$E = $team('Eagles', 'ranked', 'strong'); // never plays
$U1 = $team('Uni One', 'unranked', 'new');
$U2 = $team('Uni Two', 'unranked', 'social');
$person = wp_insert_user(array('user_login' => "rslf-p-$suffix", 'user_email' => "rslf-p-$suffix@example.test", 'user_pass' => wp_generate_password(), 'display_name' => "Hidden Person $suffix"));
RSL_Teams::add_member($A->id, $person, 'captain');

$n1 = $nights[0];
$cxid = intval($cx->id);
$cyid = intval($cy->id);

// --- Adding games -----------------------------------------------------------------------
$e = RSL_Fixtures::add_game($nights[4]->id, array('home' => $A->id, 'away' => $B->id, 'court_id' => $cxid, 'start' => '19:00'));
$check('no games on a bye', is_wp_error($e) && $e->get_error_code() === 'rsl_night');
$e = RSL_Fixtures::add_game($n1->id, array('home' => $A->id, 'away' => $A->id, 'court_id' => $cxid, 'start' => '19:00'));
$check('a team can\'t play itself', is_wp_error($e));
$e = RSL_Fixtures::add_game($n1->id, array('home' => $A->id, 'away' => $U1->id, 'court_id' => $cxid, 'start' => '19:00'));
$check('divisions can\'t mix', is_wp_error($e) && $e->get_error_code() === 'rsl_division');
$e = RSL_Fixtures::add_game($n1->id, array('home' => $A->id, 'away' => $B->id, 'court_id' => $cxid, 'start' => '21:00'));
$check('only real slots (court closes at 9)', is_wp_error($e) && $e->get_error_code() === 'rsl_slot');
$e = RSL_Fixtures::add_game($n1->id, array('home' => $U1->id, 'away' => $U2->id, 'court_id' => $cxid, 'start' => '19:00'));
$check('a court only hosts its division that night', is_wp_error($e) && $e->get_error_code() === 'rsl_slot');
$g1 = RSL_Fixtures::add_game($n1->id, array('home' => $A->id, 'away' => $B->id, 'court_id' => $cxid, 'start' => '19:00'));
$check('a game goes in a slot', !is_wp_error($g1) && $g1->home_name === $A->name && RSL_Courts::hm($g1->end_time) === '20:00' && $g1->division === 'ranked');
$e = RSL_Fixtures::add_game($n1->id, array('home' => $C->id, 'away' => $D->id, 'court_id' => $cxid, 'start' => '19:00'));
$check('one game per court at a time', is_wp_error($e) && $e->get_error_code() === 'rsl_clash');
$e = RSL_Fixtures::add_game($n1->id, array('home' => $C->id, 'away' => $A->id, 'court_id' => $cyid, 'start' => '19:00'));
$check('a team can\'t be in two places at once', is_wp_error($e) && strpos($e->get_error_message(), $A->name) !== false);
$g2 = RSL_Fixtures::add_game($n1->id, array('home' => $C->id, 'away' => $D->id, 'court_id' => $cyid, 'start' => '19:00'));
$g3 = RSL_Fixtures::add_game($n1->id, array('home' => $A->id, 'away' => $C->id, 'court_id' => $cxid, 'start' => '20:00'));
$check('a double-header later in the night is fine', !is_wp_error($g2) && !is_wp_error($g3));

// --- Set scores -------------------------------------------------------------------------
$check('sets parse', RSL_Fixtures::parse_sets('25-20, 23-25; 15–12') === array(array(25, 20), array(23, 25), array(15, 12)));
$check('sets from an array', RSL_Fixtures::parse_sets(array(array('25', '9'))) === array(array(25, 9)));
$check('a tied set is refused', is_wp_error(RSL_Fixtures::parse_sets('25-25')));
$check('nonsense is refused', is_wp_error(RSL_Fixtures::parse_sets('we won')) && is_wp_error(RSL_Fixtures::parse_sets('')));
$check('at most five sets', is_wp_error(RSL_Fixtures::parse_sets('25-1,25-1,25-1,25-1,25-1,25-1')));
$g1 = RSL_Fixtures::record_result($g1->id, '25-20, 23-25, 25-15');
$check('result recorded', $g1->status === 'played' && intval($g1->home_sets) === 2 && intval($g1->away_sets) === 1 && $g1->sets === '25-20,23-25,25-15');
$rb = RSL_Fixtures::result_for($g1, $B->id);
$check('result from the away side is flipped', $rb['outcome'] === 'loss' && $rb['for'] === 1 && $rb['sets'][0] === array(20, 25));
$check('bad result refused, game unchanged', is_wp_error(RSL_Fixtures::record_result($g1->id, '25-25')) && RSL_Fixtures::get($g1->id)->status === 'played');
$check('forfeit only by a team in the game', is_wp_error(RSL_Fixtures::record_forfeit($g2->id, $A->id)));

// --- Only published nights count ---------------------------------------------------------------
$check('unpublished games are hidden from a team\'s list', count(RSL_Fixtures::games_for_team($A->id)) === 0 && count(RSL_Fixtures::games_for_team($A->id, false)) === 2);
RSL_Ratings::flush();
$check('ratings and ladders ignore unpublished nights (a result is in, but not counted)', RSL_Ratings::compute($season)[$A->id]['games'] === 0
    && array_sum(array_column(RSL_Ratings::ladder($season, 'ranked'), 'played')) === 0);
foreach (array(0, 1, 2) as $i) { RSL_Fixtures::publish_night($nights[$i]->id); }

// --- Ratings ---------------------------------------------------------------------------------
// Aces beat everyone heavily; Diggers lose everything.
RSL_Fixtures::record_result($g2->id, '25-22, 25-23'); // Cobras beat Diggers
RSL_Fixtures::record_result($g3->id, '25-10, 25-12'); // Aces beat Cobras
$n2 = $nights[1];
$h1 = RSL_Fixtures::add_game($n2->id, array('home' => $A->id, 'away' => $D->id, 'court_id' => $cxid, 'start' => '19:00'));
$h2 = RSL_Fixtures::add_game($n2->id, array('home' => $B->id, 'away' => $C->id, 'court_id' => $cyid, 'start' => '19:00'));
RSL_Fixtures::record_result($h1->id, '25-5, 25-6, 25-4');
RSL_Fixtures::record_result($h2->id, '25-23, 22-25, 25-21');
RSL_Ratings::flush();
$r = RSL_Ratings::compute($season);
$rf = RSL_Ratings::compute($season, null, 'fixtures');
$check('the dominant team rates highest, the beaten team lowest (ladder and fixtures ratings alike)',
    $r[$A->id]['rating'] > $r[$B->id]['rating'] && $r[$B->id]['rating'] > $r[$D->id]['rating'] && $r[$C->id]['rating'] > $r[$D->id]['rating']
    && $rf[$A->id]['rating'] > $rf[$B->id]['rating'] && $rf[$B->id]['rating'] > $rf[$D->id]['rating'] && $rf[$C->id]['rating'] > $rf[$D->id]['rating']);
// 0.18.1: two ratings. The ladder (the default) starts every team on one number per season, frozen
// when the season's first Ranked night was published: the mean of the level seeds of the teams with a
// game that night, rounded to 10. Night one had Aces, Blockers, Cobras and Diggers: 1500. Eagles (no
// game that night) don't count; with them the mean would be 1530.
$starts = get_option(RSL_Ratings::STARTS_OPTION, array());
$frozen = is_array($starts) && isset($starts[$season]) ? $starts[$season] : null;
$check('the ladder\'s start is frozen when night one is published: the mean seed of that night\'s teams, rounded to 10 (1500), kept in its option',
    $frozen && $frozen['start'] === 1500 && $frozen['night'] === $n1->night_date && $frozen['teams'] === 4 && RSL_Ratings::start_for($season) === 1500
    && RSL_Ratings::common_start(RSL_Ratings::seeds(RSL_Teams::list_for_season($season))) === 1530, wp_json_encode($frozen));
$check('the start formula: the mean of the level seeds, rounded to 10; 1500 with none', RSL_Ratings::common_start(array()) === 1500
    && RSL_Ratings::common_start(array(1 => 1200, 2 => 1350)) === 1280 && RSL_Ratings::frozen_start(array(1 => 1200, 2 => 1350, 3 => 1800), array(1, 2)) === 1280);
$check('a team with no games: the fixtures rating sits at its seed, the ladder rating at the frozen start; provisional in both',
    abs($rf[$E->id]['rating'] - RSL_Teams::level_seed_rating('strong')) < 0.01 && abs($r[$E->id]['rating'] - 1500) < 0.01
    && $r[$E->id]['provisional'] && $rf[$E->id]['provisional'] && $r[$E->id]['games'] === 0,
    round($rf[$E->id]['rating'], 2) . ' / ' . round($r[$E->id]['rating'], 2));
$check('the default kind is the ladder, and anything but fixtures is read as the ladder', RSL_Ratings::compute($season, null, 'ladder') === $r
    && RSL_Ratings::compute($season, null, 'nonsense') === $r && RSL_Ratings::kind('') === 'ladder' && RSL_Ratings::kind('fixtures') === 'fixtures');
// The ladder's prior is wider (it knows nothing about a team), so the same results move a team further from
// its start than the fixtures rating moves it from its level seed.
$check('results move a ladder rating further from the start than the fixtures rating from its seed (the ladder\'s wider prior)',
    $r[$A->id]['rating'] - 1500 > $rf[$A->id]['rating'] - RSL_Teams::level_seed_rating('advanced') + 100
    && 1500 - $r[$D->id]['rating'] > RSL_Teams::level_seed_rating('new') - $rf[$D->id]['rating'] + 100 && $r[$A->id]['sd'] > $rf[$A->id]['sd'],
    round($r[$A->id]['rating']) . ' vs ' . round($rf[$A->id]['rating']));
$check('Aces, three games, ladder sd between 95 and 120: settled on the ladder (its line is 120, not the fixtures rating\'s 95)',
    !$r[$A->id]['provisional'] && $r[$A->id]['games'] === 3 && $r[$A->id]['sd'] > RSL_Ratings::PROVISIONAL_SD
    && $r[$A->id]['sd'] <= RSL_Ratings::LADDER_PROVISIONAL_SD, round($r[$A->id]['sd'], 1));
$check('unranked teams aren\'t rated', !isset($r[$U1->id]) && !isset($rf[$U1->id]));
$check('games are counted per team', $r[$D->id]['games'] === 2 && $r[$B->id]['games'] === 2 && $r[$A->id]['games'] === 3);

// Forfeits never move ratings.
$n3 = $nights[2];
$f = RSL_Fixtures::add_game($n3->id, array('home' => $D->id, 'away' => $B->id, 'court_id' => $cxid, 'start' => '19:00'));
$before = RSL_Ratings::compute($season);
RSL_Fixtures::record_forfeit($f->id, $D->id);
RSL_Ratings::flush();
$after = RSL_Ratings::compute($season);
$check('a forfeit doesn\'t change any rating', abs($before[$D->id]['rating'] - $after[$D->id]['rating']) < 1e-6 && abs($before[$B->id]['rating'] - $after[$B->id]['rating']) < 1e-6);
$fr = RSL_Fixtures::result_for(RSL_Fixtures::get($f->id), $B->id);
$check('a forfeit is a win for the other team', $fr['outcome'] === 'forfeit_win');

// Timed games: points count, so draws and short sets are fair.
$even = array(1 => 1500, 2 => 1500);
$draw = RSL_Ratings::fit($even, array(array(1, 2, array(array(25, 10), array(23, 25)), 0)));
$check('a 1–1 draw: the team that won far more points rates higher', $draw[1]['rating'] > $draw[2]['rating'] + 5);
$cut = RSL_Ratings::fit($even, array(array(1, 2, array(array(8, 5)), 0)));
$full = RSL_Ratings::fit($even, array(array(1, 2, array(array(25, 15)), 0)));
$check('a set cut off by the hour counts, but for less than a full set', $cut[1]['rating'] > 1500 && $cut[1]['rating'] - 1500 < $full[1]['rating'] - 1500);

// The set bonus: a finished set counts whole, a set the clock cut short as far as the leader got.
$share = function ($set, $index) { return RSL_Ratings::set_share($set, $index); };
$check('set bonus: 25-10 and 25-23 (first or second set) and a third set 15-8 each count as a whole set',
    $share(array(25, 10), 0) === 1.0 && $share(array(23, 25), 1) === 1.0 && $share(array(15, 8), 2) === 1.0
    && $share(array(8, 15), 2) === 1.0 && $share(array(25, 21), 2) === 1.0 && $share(array(27, 25), 0) === 1.0,
    implode(' ', array($share(array(25, 10), 0), $share(array(23, 25), 1), $share(array(15, 8), 2))));
$check('set bonus: a first set cut short at 14-11 counts 14/25; a third set cut short at 10-8 counts 10/15',
    abs($share(array(14, 11), 0) - 14 / 25) < 1e-9 && abs($share(array(11, 14), 1) - 14 / 25) < 1e-9 && abs($share(array(10, 8), 2) - 10 / 15) < 1e-9,
    $share(array(14, 11), 0) . ' ' . $share(array(10, 8), 2));
$check('set bonus: a set finishes at its target two clear (25-24 and a third set 15-14 at time were cut short; 15-13 finishes a third set, not a first, where it counts 15/25; 25-24 still counts whole, its leader reached 25), a tie counts nothing',
    !RSL_Ratings::set_finished(array(25, 24), 0) && $share(array(25, 24), 0) === 1.0 && !RSL_Ratings::set_finished(array(15, 14), 2)
    && RSL_Ratings::set_finished(array(15, 13), 2) && !RSL_Ratings::set_finished(array(15, 13), 1) && abs($share(array(15, 13), 0) - 15 / 25) < 1e-9
    && $share(array(12, 12), 0) === 0.0);
// The same through fit(), sets only (points evidence off), so the set bonus is all that moves a rating.
$sets_only = array('set_weight' => 1, 'point_weight' => 0);
$rating = function ($sets, $opts) use ($even) { return RSL_Ratings::fit($even, array(array(1, 2, $sets, 0)), $opts)[1]['rating']; };
$whole = $rating(array(array(25, 0)), $sets_only);
$check('fit(): 25-10, 25-23 and a third set 15-8 move a rating exactly as far as any whole set (the first two sets tied, so only the third counts)',
    $whole > 1500 && abs($rating(array(array(25, 10)), $sets_only) - $whole) < 1e-6 && abs($rating(array(array(25, 23)), $sets_only) - $whole) < 1e-6
    && abs($rating(array(array(20, 20), array(20, 20), array(15, 8)), $sets_only) - $whole) < 1e-6,
    $whole . ' ' . $rating(array(array(25, 10)), $sets_only) . ' ' . $rating(array(array(20, 20), array(20, 20), array(15, 8)), $sets_only));
$check('fit(): a first set cut short at 14-11 is 14/25 of a whole set; a third set cut short at 10-8 is 10/15 of one',
    abs($rating(array(array(14, 11)), $sets_only) - $rating(array(array(25, 0)), array('set_weight' => 14 / 25, 'point_weight' => 0))) < 1e-6
    && abs($rating(array(array(20, 20), array(20, 20), array(10, 8)), $sets_only) - $rating(array(array(25, 0)), array('set_weight' => 10 / 15, 'point_weight' => 0))) < 1e-6);
$check('fit(): a 1–1 draw of two finished sets is level on the set bonus (25-10 counts no less than 23-25)',
    abs($rating(array(array(25, 10), array(23, 25)), $sets_only) - 1500) < 1e-6);
$check('fit(): a 1–1 draw still moves ratings by points, with the live weights', $draw[1]['rating'] > 1505 && $draw[2]['rating'] < 1495
    && $rating(array(array(25, 10), array(23, 25)), array('set_weight' => 0, 'point_weight' => RSL_Ratings::POINT_WEIGHT)) > 1505);
// The ladder's own prior and provisional line (0.18.1): fit() takes them as options, and its defaults are the constants.
$even4 = array(1 => 1500, 2 => 1500, 3 => 1500, 4 => 1500);
$three = function ($sets) { return array(array(1, 2, $sets, 0), array(1, 3, $sets, 0), array(1, 4, $sets, 0)); };
$h3 = $three(array(array(25, 20), array(25, 21)));
$f200 = RSL_Ratings::fit($even4, $h3);
$fdef = RSL_Ratings::fit($even4, $h3, array('prior_sd' => RSL_Ratings::PRIOR_SD, 'provisional_sd' => RSL_Ratings::PROVISIONAL_SD));
$f350 = RSL_Ratings::fit($even4, $h3, RSL_Ratings::fit_opts('ladder'));
$check('fit(): prior_sd and provisional_sd default to PRIOR_SD and PROVISIONAL_SD; fit_opts() gives the ladder 350 and 120 and the fixtures rating the defaults',
    $fdef === $f200 && RSL_Ratings::fit_opts('ladder') === array('prior_sd' => 350, 'provisional_sd' => 120) && RSL_Ratings::fit_opts('fixtures') === array()
    && RSL_Ratings::fit_opts('nonsense') === RSL_Ratings::fit_opts('ladder') && RSL_Ratings::PRIOR_SD === 200);
$check('fit(): prior sd 350 spreads the same results wider than 200, with a wider uncertainty',
    $f350[1]['rating'] - $f350[2]['rating'] > $f200[1]['rating'] - $f200[2]['rating'] + 20 && $f350[1]['sd'] > $f200[1]['sd'] + 10,
    round($f350[1]['rating'] - $f350[2]['rating']) . ' vs ' . round($f200[1]['rating'] - $f200[2]['rating']));
$one_set = RSL_Ratings::fit($even4, $three(array(array(25, 20))), RSL_Ratings::fit_opts('ladder'));
$at95 = RSL_Ratings::fit($even4, $h3, array('prior_sd' => RSL_Ratings::LADDER_PRIOR_SD, 'provisional_sd' => RSL_Ratings::PROVISIONAL_SD));
$check('ladder: a team on 3 games with sd about 100 is settled (the fixtures line of 95 would hold it back); one on 3 one-set games, sd about 130, is still provisional',
    $f350[1]['games'] === 3 && $f350[1]['sd'] > 95 && $f350[1]['sd'] < 110 && !$f350[1]['provisional'] && $at95[1]['provisional']
    && $one_set[1]['games'] === 3 && $one_set[1]['sd'] > 120 && $one_set[1]['sd'] < 140 && $one_set[1]['provisional'],
    round($f350[1]['sd'], 1) . ' / ' . round($one_set[1]['sd'], 1));
$short3 = RSL_Fixtures::parse_sets('25-20, 18-25, 15-11');
$check('a third set to 15 is a normal result', !is_wp_error($short3) && count($short3) === 3);
$check('a set ended by time (score as it stands) is accepted', !is_wp_error(RSL_Fixtures::parse_sets('25-21, 22-25, 9-7')));

// More sets played = more certain.
$check('uncertainty shrinks with sets played', $after[$A->id]['sd'] < $after[$E->id]['sd'] && $after[$E->id]['sd'] > RSL_Ratings::LADDER_PROVISIONAL_SD);
$sd_before = $after[$B->id]['sd'];
RSL_Fixtures::record_result($h2->id, '25-23, 22-25, 25-21, 23-25, 25-20');
RSL_Ratings::flush();
$check('a longer match firms up both teams', RSL_Ratings::compute($season)[$B->id]['sd'] < $sd_before);

// --- Ladders -------------------------------------------------------------------------------
$ladder = RSL_Ratings::ladder($season, 'ranked');
$names = array_map(function ($row) { return $row['team']->name; }, $ladder);
$check('ranked ladder: rated teams by rating, unplayed team last with no rating', $names[0] === $A->name && end($names) === $E->name
    && end($ladder)['rating'] === null && end($ladder)['position'] === null && end($ladder)['new']);
$drow = null;
foreach ($ladder as $row) { if (intval($row['team']->id) === intval($D->id)) { $drow = $row; } }
$check('record counts the forfeit as a loss', $drow['played'] === 3 && $drow['lost'] === 3 && $drow['forfeits'] === 1);
$check('movement since the previous round is worked out', array_key_exists('move', $ladder[0]) && $ladder[0]['move'] !== null);

// Unranked: by wins.
$u = RSL_Fixtures::add_game($n1->id, array('home' => $U1->id, 'away' => $U2->id, 'court_id' => intval($cx->id), 'start' => '20:00'));
$check('a ranked-only court refuses unranked games', is_wp_error($u));
$uni = RSL_Courts::create_venue("Test Uni $suffix", 'unranked');
$cu = RSL_Courts::create_court($uni->id, 'Court U', '18:00', '19:00');
$u = RSL_Fixtures::add_game($n1->id, array('home' => $U1->id, 'away' => $U2->id, 'court_id' => intval($cu->id), 'start' => '18:00'));
RSL_Fixtures::record_result($u->id, '20-25, 18-25');
RSL_Ratings::flush();
$ul = RSL_Ratings::ladder($season, 'unranked');
$check('unranked ladder: by wins, no ratings', $ul[0]['team']->name === $U2->name && $ul[0]['won'] === 1 && $ul[0]['rating'] === null);

// --- Round labels ----------------------------------------------------------------------------
$labels = RSL_Fixtures::round_labels($season);
$check('round labels', $labels[$nights[0]->id] === 'Round 1' && $labels[$nights[3]->id] === 'Round 4' && $labels[$nights[4]->id] === 'Bye' && $labels[$nights[5]->id] === 'Finals');

// --- Public pages -----------------------------------------------------------------------------
$check('publishing shows them', count(RSL_Fixtures::games_for_team($A->id)) === 3);
$next = RSL_Fixtures::add_game($nights[3]->id, array('home' => $A->id, 'away' => $B->id, 'court_id' => $cxid, 'start' => '19:00'));

RSL_Public::ensure_pages();
$pages = new RSL_Public_Fixtures();
wp_set_current_user(0);
$_GET = array('week' => $n1->night_date);
$html = $pages->render_fixtures();
$check('fixtures page shows a played week with scores', strpos($html, $A->name) !== false && strpos($html, '2&ndash;1') !== false && strpos($html, '25-20 · 23-25 · 25-15') !== false);
$check('fixtures page never shows people', strpos($html, "Hidden Person $suffix") === false);
$_GET = array('week' => $nights[3]->night_date);
$html = $pages->render_fixtures();
$check('an unpublished week says when fixtures come out', strpos($html, 'come out on') !== false && strpos($html, $B->name) === false);
$_GET = array('week' => $nights[4]->night_date);
$check('a bye week says so', strpos($pages->render_fixtures(), 'No games this week — Holiday') !== false);
$_GET = array();
$html = $pages->render_ladder();
$check('ladder page lists rated teams with their ladder ratings, and the new team as New', strpos($html, $A->name) !== false && strpos($html, '>New<') !== false
    && strpos($html, '>' . round(RSL_Ratings::compute($season, null, 'ladder')[$A->id]['rating']) . '<') !== false);
$check('ladder never shows the hidden seed of an unplayed team', strpos($html, '>' . RSL_Teams::level_seed_rating('strong') . '<') === false);
$check('ladder page never shows the seeded fixtures rating', strpos($html, '>' . round(RSL_Ratings::compute($season, null, 'fixtures')[$A->id]['rating']) . '<') === false);
$section = RSL_Public_Fixtures::team_section(RSL_Teams::get($A->id));
$check('team page lists results and standing', strpos($section, 'W 2&ndash;1') !== false && strpos($section, '1st on the ladder') !== false && strpos($section, $B->name) !== false);

// --- Game-style touches: tiers, upsets, achievements, match-ups ------------------------------
$check('tiers by rating', RSL_Stats::tier(1850)['key'] === 'diamond' && RSL_Stats::tier(1700)['key'] === 'platinum' && RSL_Stats::tier(1550)['key'] === 'gold'
    && RSL_Stats::tier(1400)['key'] === 'silver' && RSL_Stats::tier(1100)['key'] === 'bronze');
$check('a provisional team is in placements (no tier yet)', RSL_Stats::tier(1700, true)['key'] === 'placement' && RSL_Stats::tier(null)['key'] === 'placement');
// Round 3: Cobras (settled after three games) beat Aces — an upset. Diggers, still in
// placements after two games, beat Aces too: no set chance, no upset, no rating change
// (a rating from two games is still mostly its starting value).
$upset = RSL_Fixtures::add_game($n3->id, array('home' => $C->id, 'away' => $A->id, 'court_id' => $cyid, 'start' => '19:00'));
RSL_Fixtures::record_result($upset->id, '25-20, 25-22');
$placed = RSL_Fixtures::add_game($n3->id, array('home' => $D->id, 'away' => $A->id, 'court_id' => $cxid, 'start' => '20:00'));
RSL_Fixtures::record_result($placed->id, '25-20, 25-22');
// Eagles (never fixtured) applied before round 1 but were approved the day after it, and
// said they couldn't make round 2 (their game since removed): only round 3 is a bye for them.
$wpdb->update(RSL_Database::table('teams'), array('created_at' => date('Y-m-d H:i:s', strtotime($n1->night_date . ' -7 days')),
    'decided_at' => date('Y-m-d 10:00:00', strtotime($n1->night_date . ' +1 day'))), array('id' => $E->id));
$wpdb->insert(RSL_Database::table('requests'), array('team_id' => $E->id, 'night_id' => $n2->id, 'type' => 'cant_make', 'status' => 'handled',
    'requested_by' => $person, 'requested_at' => current_time('mysql')));
RSL_Ratings::flush();
RSL_Stats::flush();
$stats = RSL_Stats::season($season);
$ug = $stats['games'][intval($upset->id)];
$check('the underdog winning between settled teams is an upset', intval($ug['upset_team']) === intval($C->id) && $ug['p_home'] !== null && $ug['p_home'] < RSL_Stats::UPSET_CHANCE);
$gk = isset($stats['teams'][intval($C->id)]['achievements']['giant_killer']) ? $stats['teams'][intval($C->id)]['achievements']['giant_killer'] : null;
$check('...and earns Giant killer, with no percentage (no odds shown)', $gk && strpos($gk['why'], '%') === false);
$pg = $stats['games'][intval($placed->id)];
$check('a team in placements beating the favourite: no set chance, no upset, no Giant killer, no rating change',
    $pg['p_home'] === null && $pg['upset_team'] === null && !isset($stats['teams'][intval($D->id)]['achievements']['giant_killer']) && $pg['delta'][intval($D->id)] === null);
$check('no set chance (so never Even match) while a team is in placements', RSL_Stats::chance_now($season, $E->id, $A->id) === null && RSL_Stats::chance_now($season, $C->id, $A->id) !== null);
$se = $stats['teams'][intval($E->id)];
$check('byes count only from approval, and not on a night the team said it couldn\'t make', $se['rounds'] === 1 && $se['points'] === RSL_Stats::POINTS['played']);
$check('form is the latest results, newest last', end($stats['teams'][intval($D->id)]['form']) === 'W' && $stats['teams'][intval($D->id)]['streak'] === 'W1');
$check('a normal win isn\'t an upset', $stats['games'][intval($g3->id)]['upset_team'] === null);
$d = $ug['delta'];
$check('rating changes are whole numbers, or hidden during placements', ($d[intval($C->id)] === null || is_int($d[intval($C->id)])) && ($d[intval($A->id)] === null || is_int($d[intval($A->id)])));
// Saved between page views: the next view loads it (one fingerprint query) instead of rebuilding.
RSL_Ratings::flush();
RSL_Stats::flush();
$q = $wpdb->num_queries;
$again = RSL_Stats::season($season);
$check('the season story is saved between page views', $again == $stats && $wpdb->num_queries - $q <= 3, ($wpdb->num_queries - $q) . ' queries');
// Two results swapped (entered against the wrong courts, then fixed) make a new key, though
// same-length values like these cancel out in a CRC32; and the rebuild never reuses a
// snapshot this request read before the fingerprint (as the ladder page once did).
$key_of = function () use ($season) {
    $t = get_transient(RSL_Stats::CACHE_PREFIX . $season);
    return is_array($t) && isset($t['key']) ? $t['key'] : null;
};
$swap = function ($x, $y) use ($wpdb) {
    $gx = RSL_Fixtures::get($x->id);
    $gy = RSL_Fixtures::get($y->id);
    foreach (array(array($gx, $gy), array($gy, $gx)) as $p) {
        $wpdb->update(RSL_Database::table('games'), array('sets' => $p[1]->sets, 'home_sets' => $p[1]->home_sets, 'away_sets' => $p[1]->away_sets), array('id' => $p[0]->id));
    }
};
$key_before = $key_of();
RSL_Ratings::season_data($season); // an older snapshot already in this request
$swap($g2, $g3); // 25-22,25-23 (close) <-> 25-10,25-12: both 2–0, same length
RSL_Stats::flush();
$swapped = RSL_Stats::season($season);
$check('swapping two results makes a new key and rebuilds from fresh data', $key_before !== null && $key_of() !== $key_before
    && $swapped['games'][intval($g3->id)]['close'] === true && $swapped['games'][intval($g2->id)]['close'] === false);
$swap($g2, $g3); // back as they were
RSL_Stats::flush();
$check('...and swapping back rebuilds the original story under the original key', RSL_Stats::season($season) == $stats && $key_of() === $key_before);
$_GET = array('week' => $n3->night_date);
$html = $pages->render_fixtures();
$check('fixtures page flags the upset and shows tiers', strpos($html, 'Upset!') !== false && strpos($html, 'rsl-tier') !== false);
RSL_Fixtures::publish_night($nights[3]->id);
$_GET = array('week' => $nights[3]->night_date);
$html = $pages->render_fixtures();
$check('a coming game shows hype, not odds', strpos($html, 'rsl-hype') !== false && strpos($html, 'rsl-odds') === false
    && (strpos($html, 'Rematch') !== false || strpos($html, 'First meeting') !== false));
$next_game = RSL_Fixtures::get($next->id);
$q = $wpdb->num_queries;
$tags = RSL_Stats::hype($next_game);
$check('hype runs no queries per game, and remembers the last meeting', $wpdb->num_queries === $q && in_array('Rematch — ' . $A->name . ' won 2–1 last time', $tags, true),
    implode(' / ', $tags));

// Game night: live scores are polled only while games are on, and stop once every result is in.
$w = RSL_Public_Fixtures::live_window($nights[3], RSL_Fixtures::games_for_night($nights[3]->id));
$starts = new DateTime($nights[3]->night_date . ' 18:45', wp_timezone());
$check('live scores are polled from 15 minutes before the first game until 30 after the last ends', $w && $w[0] === $starts->getTimestamp() && $w[1] - $w[0] === 105 * MINUTE_IN_SECONDS);
$check('...and not at all on a night with every result in', RSL_Public_Fixtures::live_window($n3, RSL_Fixtures::games_for_night($n3->id)) === null);
$live = RSL_Public_Fixtures::live_scores($nights[3]);
$check('the live feed says when every game has a result (so polling stops)', $live['all_done'] === false && isset($live['games'][intval($next->id)])
    && RSL_Public_Fixtures::live_scores($n3)['all_done'] === true);

// "Can't make it" on the team page: free before the cut-off, and after it the captain is told what it costs.
$cut_off = function ($hours) { return function () use ($hours) { return array('game_fee' => 75, 'free_cancel_hours' => $hours, 'max_owing' => 75); }; };
wp_set_current_user($person);
$early = $cut_off(0);
add_filter('pre_option_' . RSL_Payments::SETTINGS_OPTION, $early);
$section = RSL_Public_Fixtures::team_section(RSL_Teams::get($A->id));
remove_filter('pre_option_' . RSL_Payments::SETTINGS_OPTION, $early);
$check('before the cut-off: "Free until" beside Can\'t make it', strpos($section, 'value="cant_make"') !== false && strpos($section, 'Free until ') !== false
    && strpos($section, 'still to play') === false);
$late = $cut_off(24 * 14);
add_filter('pre_option_' . RSL_Payments::SETTINGS_OPTION, $late);
$section = RSL_Public_Fixtures::team_section(RSL_Teams::get($A->id));
$check('after it: the confirm names the charge (both teams\' fees)', strpos($section, 'value="cant_make"') !== false && strpos($section, 'for the game still to play: $150 instead of $75') !== false
    && strpos($section, 'Free until ') === false);
// Game night of a double-header with the first game in: sync_night() charges the played game
// as normal and the late fee only on the other, so that's all the confirm counts.
$second = RSL_Fixtures::add_game($nights[3]->id, array('home' => $C->id, 'away' => $A->id, 'court_id' => $cxid, 'start' => '20:00'));
$was = $wpdb->get_row($wpdb->prepare("SELECT status, sets, home_sets, away_sets FROM " . RSL_Database::table('games') . " WHERE id = %d", $next->id), ARRAY_A);
$wpdb->update(RSL_Database::table('games'), array('status' => 'played', 'sets' => '25-20,25-22', 'home_sets' => 2, 'away_sets' => 0), array('id' => $next->id));
$section = RSL_Public_Fixtures::team_section(RSL_Teams::get($A->id));
$check('double-header, first game played: the confirm counts only the game still to play', !is_wp_error($second)
    && strpos($section, 'for the game still to play: $150 instead of $75') !== false);
// The other team had already called that game off: only the first team out of a game pays
// (sync_night), so pulling out of it now costs nothing and the confirm names no charge.
$wpdb->insert(RSL_Database::table('requests'), array('team_id' => $C->id, 'night_id' => $nights[3]->id, 'type' => 'cant_make', 'status' => 'active',
    'requested_by' => $person, 'requested_at' => current_time('mysql')));
$their_req = $wpdb->insert_id;
$section = RSL_Public_Fixtures::team_section(RSL_Teams::get($A->id));
$check('...and with that game already called off by the other team, no charge is named (and no stale "Free until")', strpos($section, 'value="cant_make"') !== false
    && strpos($section, 'still to play') === false && strpos($section, 'sort it out with the other team') !== false && strpos($section, 'Free until ') === false);
remove_filter('pre_option_' . RSL_Payments::SETTINGS_OPTION, $late);
$wpdb->delete(RSL_Database::table('requests'), array('id' => $their_req));
$wpdb->update(RSL_Database::table('games'), $was, array('id' => $next->id));
if (!is_wp_error($second)) { RSL_Fixtures::delete_game($second->id); }
RSL_Ratings::flush();
RSL_Stats::flush();
wp_set_current_user(0);

$_GET = array();
$html = $pages->render_ladder();
$check('ladder shows tiers, form, levels and season leaders', strpos($html, '<th>Tier</th>') !== false && strpos($html, 'rsl-form-w') !== false
    && strpos($html, 'rsl-level') !== false && strpos($html, 'Giant killers') !== false);
$check('How the ladder works gives the reason for the tier buffer, and nothing about a protected tier (0.18.1)',
    strpos($html, esc_html(RSL_Public_Fixtures::tier_rule_words())) !== false
    && strpos(RSL_Public_Fixtures::tier_rule_words(), 'so a single result doesn\'t flip a team between tiers') !== false
    && strpos(RSL_Public_Fixtures::tier_rule_words(), 'two rounds in a row more than 50 points below where its tier starts') !== false
    && strpos($html, 'Up straight away') === false && stripos($html, 'keep at least') === false);

// Earned tiers: honest rating underneath, fair badge on top.
$tk = function ($k) { return RSL_Stats::tier_by_key($k); };
$s1 = RSL_Stats::step_tier($tk('silver'), 0, 1510);
$check('promotion is instant', $s1['earned']['key'] === 'gold' && $s1['promoted']);
$s1 = RSL_Stats::step_tier($tk('gold'), 0, 1470);
$check('a little below the floor holds the tier', $s1['earned']['key'] === 'gold' && $s1['below'] === 0);
$s1 = RSL_Stats::step_tier($tk('gold'), 0, 1400);
$check('well below once: still the tier, but at risk', $s1['earned']['key'] === 'gold' && $s1['below'] === 1);
$s2 = RSL_Stats::step_tier($s1['earned'], $s1['below'], 1400);
$check('well below twice in a row: down one tier', $s2['earned']['key'] === 'silver' && $s2['below'] === 0);
$s2 = RSL_Stats::step_tier($tk('gold'), 1, 1490);
$check('bouncing back clears the risk', $s2['earned']['key'] === 'gold' && $s2['below'] === 0);
$s1 = RSL_Stats::step_tier($tk('platinum'), 1, 1100);
$check('a collapse still drops only one tier at a time', $s1['earned']['key'] === 'gold');
$s1 = RSL_Stats::step_tier($tk('silver'), 0, 1100);
$check('Silver is not protected (0.18.1): well below once shows at risk there too', $s1['earned']['key'] === 'silver' && $s1['below'] === 1);
$s2 = RSL_Stats::step_tier($s1['earned'], $s1['below'], 1100);
$check('Silver well below twice in a row: down to Bronze', $s2['earned']['key'] === 'bronze' && $s2['below'] === 0 && !$s2['promoted']);
$s1 = RSL_Stats::step_tier($tk('bronze'), 1, 900);
$check('Bronze is the bottom tier: never at risk', $s1['earned']['key'] === 'bronze' && $s1['below'] === 0);
$s1 = RSL_Stats::step_tier(null, 0, 1700);
$check('placements end in the tier the rating has earned', $s1['earned']['key'] === 'platinum' && !$s1['promoted']);

// Season Points: only ever up.
$check('levels every ' . RSL_Stats::LEVEL_POINTS . ' points', RSL_Stats::level(0)['level'] === 1 && RSL_Stats::level(RSL_Stats::LEVEL_POINTS * 2 + 5)['level'] === 3
    && RSL_Stats::level(RSL_Stats::LEVEL_POINTS * 2 + 5)['into'] === 5);
$dp = $stats['teams'][intval($D->id)]['points'];
$check('Season Points reward turning up, even when losing', $dp >= RSL_Stats::POINTS['played'] * 2 && $stats['teams'][intval($B->id)]['points'] > 0);
$check('a three-set game is a close one', $stats['games'][intval($g1->id)]['close'] === true);
// Fair however many games: turn-up points and finals rounds are per night.
$sa = $stats['teams'][intval($A->id)];
$check('a double-header night counts as one round (Aces: 5 games over 3 nights)', $sa['rounds'] === 3);
$check('finals: needs ' . RSL_Stats::finals_min_rounds() . ' rounds — shows how many to go, and when it can\'t get there',
    $sa['finals']['need'] === RSL_Stats::finals_min_rounds() - 3 && !$sa['finals']['eligible'] && $sa['finals']['possible'] === false);
$check('a forfeit loss isn\'t a round played', $stats['teams'][intval($D->id)]['rounds'] === 3); // D forfeited one game but played the other that night
$ladder = RSL_Ratings::ladder($season, 'ranked');
$order_ok = true;
$seen_unnumbered = false;
foreach ($ladder as $row) {
    if ($row['placement'] || $row['new']) {
        $order_ok = $order_ok && $row['position'] === null;
        $seen_unnumbered = true;
    } elseif ($seen_unnumbered) {
        $order_ok = false; // a numbered team listed after an unnumbered one
    }
}
$check('teams in placements aren\'t numbered and sit under the settled teams', $order_ok);
$html = $pages->render_ladder();
$check('Ranked ladder has Finals, not Lvl; Unranked keeps Level', strpos($html, '>Finals</th>') !== false && strpos($html, '>Lvl</th>') === false && strpos($html, '>Level</th>') !== false);

$profile = RSL_Public_Fixtures::team_profile(RSL_Teams::get($C->id));
$check('team profile shows the level', strpos($profile, 'Season Points') !== false && strpos($profile, 'Lvl ') !== false);
$check('team profile shows achievements and form', strpos($profile, 'Giant killer') !== false && strpos($profile, 'rsl-form') !== false);
$check('profiles never show people', strpos($profile, "Hidden Person $suffix") === false && strpos(RSL_Public_Fixtures::team_profile(RSL_Teams::get($A->id)), "Hidden Person $suffix") === false);

// --- Two ratings (0.18.1): the captain's level shapes the fixtures, never the ladder ---------------
// Twins: a new Ranked team at the lowest level and one at the highest draw 1–1 with each other,
// 25-20 each way, on the same night, so their results are identical.
$lo = $team('Twin Low', 'ranked', 'new');
$hi = $team('Twin High', 'ranked', 'advanced');
$twin = RSL_Fixtures::add_game($n2->id, array('home' => $lo->id, 'away' => $hi->id, 'court_id' => $cxid, 'start' => '20:00'));
$twin_ok = !is_wp_error($twin) && !is_wp_error(RSL_Fixtures::record_result($twin->id, '25-20, 20-25'));
RSL_Ratings::flush();
$lad = RSL_Ratings::compute($season, null, 'ladder');
$fix = RSL_Ratings::compute($season, null, 'fixtures');
$twins_in = $twin_ok && isset($lad[intval($lo->id)], $lad[intval($hi->id)], $fix[intval($lo->id)], $fix[intval($hi->id)]);
$check('identical results, different captain levels: identical ladder ratings (and uncertainty), different fixtures ratings',
    $twins_in && abs($lad[$lo->id]['rating'] - $lad[$hi->id]['rating']) < 1e-3 && abs($lad[$lo->id]['sd'] - $lad[$hi->id]['sd']) < 1e-3
    && $lad[$lo->id]['games'] === 1 && $lad[$hi->id]['games'] === 1 && $fix[$hi->id]['rating'] - $fix[$lo->id]['rating'] > 150,
    $twins_in ? sprintf('ladder %.3f / %.3f, fixtures %.1f / %.1f', $lad[$lo->id]['rating'], $lad[$hi->id]['rating'], $fix[$lo->id]['rating'], $fix[$hi->id]['rating']) : 'game not added');
// The twins joined after night one: their levels don't move the frozen start (the season's mean seed now
// rounds to 1520, but the ladder still starts every team on 1500).
$starts = get_option(RSL_Ratings::STARTS_OPTION, array());
$check('late joiners\' levels don\'t move the start: it stays as frozen on night one', RSL_Ratings::start_for($season) === 1500
    && isset($starts[$season]) && $starts[$season]['start'] === 1500 && $starts[$season]['teams'] === 4
    && RSL_Ratings::common_start(RSL_Ratings::seeds(RSL_Teams::list_for_season($season))) === 1520
    && abs($lad[intval($E->id)]['rating'] - 1500) < 1e-9);

// After night one, no level reaches the ladder at all, for any team at any game count: edit levels —
// teams on 5, 4, 3, 1 and 0 games, the twins under 5 — and every ladder rating (and uncertainty) stays
// exactly as it was, and so does everything public: the ladder, tiers and their buffer, placements, first
// ratings, rating changes, upsets, close games, leaders. Only the fixtures ratings follow the levels.
$norm = function ($v) use (&$norm) {
    if (is_array($v)) {
        return array_map($norm, $v);
    }
    return is_float($v) ? round($v, 6) : $v;
};
$public = function () use ($season, $norm) {
    RSL_Ratings::flush();
    RSL_Stats::flush();
    $st = RSL_Stats::season($season);
    $out = array('ladder' => array(), 'teams' => array(), 'games' => $st['games'], 'leaders' => array());
    foreach (RSL_Ratings::ladder($season, 'ranked') as $row) {
        $out['ladder'][] = array(intval($row['team']->id), $row['position'], $row['rating'], $row['provisional'], $row['placement'], $row['new'], $row['move']);
    }
    foreach ($st['teams'] as $id => $t) {
        if ($t['team']->division === 'ranked') {
            $out['teams'][$id] = array($t['now'], $t['history'], $t['peak'], $t['achievements'], $t['upsets'], $t['points'], $t['first_rating']);
        }
    }
    foreach (array('improved', 'giant_killer') as $k) {
        $out['leaders'][$k] = $st['leaders'][$k] ? array(intval($st['leaders'][$k]['team']->id), $st['leaders'][$k]['value']) : null;
    }
    return $norm($out);
};
$levels_were = array();
foreach (array($A, $B, $C, $D, $E, $lo, $hi) as $t) {
    $levels_were[intval($t->id)] = RSL_Teams::get($t->id)->est_level;
}
$seen = $public();
RSL_Ratings::flush();
$lad_was = RSL_Ratings::compute($season, null, 'ladder');
$fix_was = RSL_Ratings::compute($season, null, 'fixtures');
$games_by_team = array_map(function ($x) { return $x['games']; }, $lad_was);
$edits = array(intval($A->id) => 'new', intval($B->id) => 'advanced', intval($D->id) => 'advanced', intval($E->id) => 'new',
    intval($lo->id) => 'advanced', intval($hi->id) => 'new');
foreach ($edits as $id => $level) {
    RSL_Teams::set_level($id, $level);
}
$edited_view = $public();
RSL_Ratings::flush();
$lad_now = RSL_Ratings::compute($season, null, 'ladder');
$fix_now = RSL_Ratings::compute($season, null, 'fixtures');
$identical = count($lad_now) === count($lad_was);
foreach ($lad_was as $id => $x) {
    $identical = $identical && isset($lad_now[$id]) && $lad_now[$id] === $x;
}
$a_tier = isset($seen['teams'][intval($A->id)][0]['tier']['key']) ? $seen['teams'][intval($A->id)][0]['tier']['key'] : 'none';
$check('levels edited after night one leave every ladder rating exactly as it was, teams under 5 games too (games: ' . implode(', ', $games_by_team) . ')',
    $identical && min($games_by_team) === 0 && in_array(1, $games_by_team, true) && max($games_by_team) >= 5 && RSL_Ratings::start_for($season) === 1500);
$check('...and nothing public moves: ladder, places, tiers and their buffer, placements, first ratings, changes, upsets and leaders',
    $edited_view == $seen && $a_tier !== 'placement' && $a_tier !== 'none', 'Aces tier ' . $a_tier);
$check('...while the fixtures ratings follow the levels', $fix_now[$A->id]['rating'] < $fix_was[$A->id]['rating'] - 20 && $fix_now[$D->id]['rating'] > $fix_was[$D->id]['rating'] + 20,
    round($fix_was[$A->id]['rating']) . ' -> ' . round($fix_now[$A->id]['rating']));
foreach ($levels_were as $id => $level) {
    RSL_Teams::set_level($id, $level);
}

// The start is frozen only while a Ranked night is published: unpublish them all and it's forgotten;
// publish night one again and it freezes from that night's teams' levels as they are then (Blockers
// edited to advanced meanwhile: the four average 1575, rounded to 1580).
foreach ($nights as $n) {
    RSL_Fixtures::publish_night($n->id, false);
}
$starts = get_option(RSL_Ratings::STARTS_OPTION, array());
$cleared = !is_array($starts) || !isset($starts[$season]);
RSL_Teams::set_level($B->id, 'advanced');
RSL_Ratings::flush();
$unfrozen = RSL_Ratings::start_for($season); // nothing published: the current mean seed, not stored
$starts = get_option(RSL_Ratings::STARTS_OPTION, array());
$still_cleared = !is_array($starts) || !isset($starts[$season]);
RSL_Fixtures::publish_night($n1->id);
$starts = get_option(RSL_Ratings::STARTS_OPTION, array());
$refrozen = is_array($starts) && isset($starts[$season]) ? $starts[$season] : null;
$lad_re = RSL_Ratings::compute($season, null, 'ladder');
$check('unpublishing every Ranked night forgets the start (with none published it is the current mean seed, not stored); publishing night one freezes it again from that night\'s teams',
    $cleared && $still_cleared && $unfrozen === RSL_Ratings::common_start(RSL_Ratings::seeds(RSL_Teams::list_for_season($season)))
    && $refrozen && $refrozen['start'] === 1580 && $refrozen['night'] === $n1->night_date && RSL_Ratings::start_for($season) === 1580
    && abs($lad_re[intval($E->id)]['rating'] - 1580) < 1e-9, wp_json_encode($refrozen) . ' / ' . $unfrozen);
RSL_Teams::set_level($B->id, $levels_were[intval($B->id)]);
RSL_Fixtures::publish_night($n1->id, false);
foreach (array(0, 1, 2, 3) as $i) {
    RSL_Fixtures::publish_night($nights[$i]->id);
}
$starts = get_option(RSL_Ratings::STARTS_OPTION, array());
$check('...and back as it was (night one\'s teams at their own levels: 1500)', isset($starts[$season]) && $starts[$season]['start'] === 1500 && RSL_Ratings::start_for($season) === 1500);
RSL_Ratings::flush();
RSL_Stats::flush();

// A night outside new season dates is kept while it has games (it's only removed once cleared).
RSL_Courts::save_settings($season, array('start' => $nights[0]->night_date, 'end' => $nights[2]->night_date, 'weekday' => 0, 'minutes' => array('ranked' => 60, 'unranked' => 60)));
$g = RSL_Courts::generate_nights($season);
$check('a night with games outside the new dates is kept, with its games', RSL_Fixtures::get($next->id) !== null && isset($g['kept'][$nights[3]->night_date]));

} catch (Throwable $thrown) {
    $fails++;
    echo 'FAIL: threw ' . get_class($thrown) . ': ' . $thrown->getMessage() . ' (line ' . $thrown->getLine() . ")\n";
} finally {
// --- Cleanup (runs after a failure too) -----------------------------------------------------------
wp_set_current_user(0);
$_GET = array();
$wpdb->delete(RSL_Database::table('games'), array('season' => $season));
$wpdb->delete(RSL_Database::table('ledger'), array('season' => $season));
foreach (RSL_Courts::nights($season) as $n) {
    $wpdb->delete(RSL_Database::table('night_courts'), array('night_id' => $n->id));
    $wpdb->delete(RSL_Database::table('night_venues'), array('night_id' => $n->id));
}
$wpdb->delete(RSL_Database::table('nights'), array('season' => $season));
$settings = get_option(RSL_Courts::SETTINGS_OPTION, array());
if (is_array($settings) && isset($settings[$season])) {
    unset($settings[$season]);
    update_option(RSL_Courts::SETTINGS_OPTION, $settings, false);
}
// Every venue the test made (each name ends with the suffix), with its courts.
foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM " . RSL_Database::table('venues') . " WHERE name LIKE %s", '% ' . $wpdb->esc_like((string) $suffix))) as $vid) {
    $wpdb->delete(RSL_Database::table('courts'), array('venue_id' => $vid));
    $wpdb->delete(RSL_Database::table('venues'), array('id' => $vid));
}
delete_transient(RSL_Stats::CACHE_PREFIX . $season);
RSL_Ratings::forget_start($season);
// Every team in the test's season (however far the test got).
foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM " . RSL_Database::table('teams') . " WHERE season = %s", $season)) as $tid) {
    $wpdb->delete(RSL_Database::table('requests'), array('team_id' => $tid));
    $wpdb->delete(RSL_Database::table('team_members'), array('team_id' => $tid));
    $wpdb->delete(RSL_Database::table('teams'), array('id' => $tid));
}
rsl_dev_delete_users(array($person));
if ($saved_current === '__unset__') { delete_option(RSL_Seasons::CURRENT_OPTION); } else { update_option(RSL_Seasons::CURRENT_OPTION, $saved_current); }
rsl_dev_release_mail($hold);
}
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
