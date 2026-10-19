<?php
/**
 * Fixtures, results, ratings, ladders and the public fixtures pages.
 *
 *   wp eval-file dev/tests/test-fixtures.php
 */

if (!defined('WP_CLI') || !WP_CLI) {
    exit("Run with wp eval-file.\n");
}
require_once ABSPATH . 'wp-admin/includes/user.php';
global $wpdb;

$season = '2093-pre';
$suffix = time();
$fails = 0;
$check = function ($label, $ok, $detail = '') use (&$fails) {
    if (!$ok) { $fails++; }
    echo ($ok ? "OK: $label\n" : "FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n");
};
$saved_current = get_option(RSL_Seasons::CURRENT_OPTION, '__unset__');
update_option(RSL_Seasons::CURRENT_OPTION, $season);

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
$check('the dominant team rates highest, the beaten team lowest', $r[$A->id]['rating'] > $r[$B->id]['rating'] && $r[$B->id]['rating'] > $r[$D->id]['rating'] && $r[$C->id]['rating'] > $r[$D->id]['rating']);
$check('a team with no games sits at its seed, provisional', abs($r[$E->id]['rating'] - RSL_Teams::level_seed_rating('strong')) < 0.01 && $r[$E->id]['provisional'] && $r[$E->id]['games'] === 0);
$check('unranked teams aren\'t rated', !isset($r[$U1->id]));
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
$short3 = RSL_Fixtures::parse_sets('25-20, 18-25, 15-11');
$check('a third set to 15 is a normal result', !is_wp_error($short3) && count($short3) === 3);
$check('a set ended by time (score as it stands) is accepted', !is_wp_error(RSL_Fixtures::parse_sets('25-21, 22-25, 9-7')));

// More sets played = more certain.
$check('uncertainty shrinks with sets played', $after[$A->id]['sd'] < $after[$E->id]['sd'] && $after[$E->id]['sd'] > RSL_Ratings::PROVISIONAL_SD);
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
$ul = RSL_Ratings::ladder($season, 'unranked');
$check('unranked ladder: by wins, no ratings', $ul[0]['team']->name === $U2->name && $ul[0]['won'] === 1 && $ul[0]['rating'] === null);

// --- Round labels ----------------------------------------------------------------------------
$labels = RSL_Fixtures::round_labels($season);
$check('round labels', $labels[$nights[0]->id] === 'Round 1' && $labels[$nights[3]->id] === 'Round 4' && $labels[$nights[4]->id] === 'Bye' && $labels[$nights[5]->id] === 'Finals');

// --- Publishing and public pages ------------------------------------------------------------
$check('unpublished games are hidden from a team\'s list', count(RSL_Fixtures::games_for_team($A->id)) === 0 && count(RSL_Fixtures::games_for_team($A->id, false)) === 3);
foreach (array(0, 1, 2) as $i) { RSL_Fixtures::publish_night($nights[$i]->id); }
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
$check('ladder page lists rated teams with ratings, and the new team as New', strpos($html, $A->name) !== false && strpos($html, '>New<') !== false
    && strpos($html, (string) round(RSL_Ratings::compute($season)[$A->id]['rating'])) !== false);
$check('ladder never shows the hidden seed of an unplayed team', strpos($html, '>' . RSL_Teams::level_seed_rating('strong') . '<') === false);
$section = RSL_Public_Fixtures::team_section(RSL_Teams::get($A->id));
$check('team page lists results and standing', strpos($section, 'W 2&ndash;1') !== false && strpos($section, '1st on the ladder') !== false && strpos($section, $B->name) !== false);

// --- Game-style touches: tiers, upsets, achievements, match-ups ------------------------------
$check('tiers by rating', RSL_Stats::tier(1850)['key'] === 'diamond' && RSL_Stats::tier(1700)['key'] === 'platinum' && RSL_Stats::tier(1550)['key'] === 'gold'
    && RSL_Stats::tier(1400)['key'] === 'silver' && RSL_Stats::tier(1100)['key'] === 'bronze');
$check('a provisional team is in placements (no tier yet)', RSL_Stats::tier(1700, true)['key'] === 'placement' && RSL_Stats::tier(null)['key'] === 'placement');
$upset = RSL_Fixtures::add_game($n3->id, array('home' => $D->id, 'away' => $A->id, 'court_id' => $cxid, 'start' => '20:00'));
RSL_Fixtures::record_result($upset->id, '25-20, 25-22');
RSL_Ratings::flush();
RSL_Stats::flush();
$stats = RSL_Stats::season($season);
$check('the underdog winning is an upset', intval($stats['games'][intval($upset->id)]['upset_team']) === intval($D->id) && $stats['games'][intval($upset->id)]['p_home'] < RSL_Stats::UPSET_CHANCE);
$check('...and earns Giant killer', isset($stats['teams'][intval($D->id)]['achievements']['giant_killer']));
$check('form is the latest results, newest last', end($stats['teams'][intval($D->id)]['form']) === 'W' && $stats['teams'][intval($D->id)]['streak'] === 'W1');
$check('a normal win isn\'t an upset', $stats['games'][intval($g3->id)]['upset_team'] === null);
$d = $stats['games'][intval($upset->id)]['delta'];
$check('rating changes are whole numbers, or hidden during placements', ($d[intval($D->id)] === null || is_int($d[intval($D->id)])) && ($d[intval($A->id)] === null || is_int($d[intval($A->id)])));
$_GET = array('week' => $n3->night_date);
$html = $pages->render_fixtures();
$check('fixtures page flags the upset and shows tiers', strpos($html, 'Upset!') !== false && strpos($html, 'rsl-tier') !== false);
RSL_Fixtures::publish_night($nights[3]->id);
$_GET = array('week' => $nights[3]->night_date);
$html = $pages->render_fixtures();
$check('a coming game shows hype, not odds', strpos($html, 'rsl-hype') !== false && strpos($html, 'rsl-odds') === false
    && (strpos($html, 'Rematch') !== false || strpos($html, 'First meeting') !== false));
$_GET = array();
$html = $pages->render_ladder();
$check('ladder shows tiers, form, levels and season leaders', strpos($html, '<th>Tier</th>') !== false && strpos($html, 'rsl-form-w') !== false
    && strpos($html, 'rsl-level') !== false && strpos($html, 'Giant killers') !== false);

// Earned tiers: honest rating underneath, fair badge on top.
$tk = function ($k) { return RSL_Stats::tier_by_key($k); };
$s1 = RSL_Stats::step_tier($tk('silver'), 0, $tk('silver'), 1510);
$check('promotion is instant', $s1['earned']['key'] === 'gold' && $s1['promoted']);
$s1 = RSL_Stats::step_tier($tk('gold'), 0, $tk('gold'), 1470);
$check('a little below the floor holds the tier', $s1['earned']['key'] === 'gold' && $s1['below'] === 0);
$s1 = RSL_Stats::step_tier($tk('gold'), 0, $tk('gold'), 1400);
$check('well below once: still the tier, but at risk', $s1['earned']['key'] === 'gold' && $s1['below'] === 1);
$s2 = RSL_Stats::step_tier($s1['earned'], $s1['below'], $tk('gold'), 1400);
$check('well below twice in a row: down one tier', $s2['earned']['key'] === 'silver' && $s2['below'] === 0);
$s2 = RSL_Stats::step_tier($tk('gold'), 1, $tk('gold'), 1490);
$check('bouncing back clears the risk', $s2['earned']['key'] === 'gold' && $s2['below'] === 0);
$s1 = RSL_Stats::step_tier($tk('platinum'), 1, $tk('platinum'), 1100);
$check('a collapse still drops only one tier at a time', $s1['earned']['key'] === 'gold');
$s1 = RSL_Stats::step_tier($tk('silver'), 1, $tk('silver'), 1100);
$check('Silver, once reached, is kept all season (and never shows at risk)', $s1['earned']['key'] === 'silver' && $s1['below'] === 0);
$s1 = RSL_Stats::step_tier(null, 0, null, 1700);
$check('placements end in the tier the rating has earned', $s1['earned']['key'] === 'platinum' && !$s1['promoted']);

// Season Points: only ever up.
$check('levels every ' . RSL_Stats::LEVEL_POINTS . ' points', RSL_Stats::level(0)['level'] === 1 && RSL_Stats::level(RSL_Stats::LEVEL_POINTS * 2 + 5)['level'] === 3
    && RSL_Stats::level(RSL_Stats::LEVEL_POINTS * 2 + 5)['into'] === 5);
$dp = $stats['teams'][intval($D->id)]['points'];
$check('Season Points reward turning up, even when losing', $dp >= RSL_Stats::POINTS['played'] * 2 && $stats['teams'][intval($B->id)]['points'] > 0);
$check('a three-set game is a close one', $stats['games'][intval($g1->id)]['close'] === true);
$profile = RSL_Public_Fixtures::team_profile(RSL_Teams::get($D->id));
$check('team profile shows the level', strpos($profile, 'Season Points') !== false && strpos($profile, 'Lvl ') !== false);
$check('team profile shows achievements and form', strpos($profile, 'Giant killer') !== false && strpos($profile, 'rsl-form') !== false);
$check('profiles never show people', strpos($profile, "Hidden Person $suffix") === false && strpos(RSL_Public_Fixtures::team_profile(RSL_Teams::get($A->id)), "Hidden Person $suffix") === false);

// A night removed from the season takes its games with it.
RSL_Courts::save_settings($season, array('start' => $nights[0]->night_date, 'end' => $nights[2]->night_date, 'weekday' => 0, 'minutes' => array('ranked' => 60, 'unranked' => 60)));
RSL_Courts::generate_nights($season);
$check('games on a removed night are removed', RSL_Fixtures::get($next->id) === null);

// --- Cleanup ------------------------------------------------------------------------------------
$_GET = array();
$wpdb->delete(RSL_Database::table('games'), array('season' => $season));
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
foreach (array($A, $B, $C, $D, $E, $U1, $U2) as $t) {
    $wpdb->delete(RSL_Database::table('team_members'), array('team_id' => $t->id));
    $wpdb->delete(RSL_Database::table('teams'), array('id' => $t->id));
}
wp_delete_user($person);
if ($saved_current === '__unset__') { delete_option(RSL_Seasons::CURRENT_OPTION); } else { update_option(RSL_Seasons::CURRENT_OPTION, $saved_current); }
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
