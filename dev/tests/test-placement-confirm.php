<?php
/**
 * Placement on the database, end to end: suggest → draft → move (a stale
 * draft refused) → re-run keeping the move → a placeholder name blocks
 * confirming everything (but not one ready team) → name the new team →
 * confirm: a new team made (formed_by placement, with its captain and a
 * level), people placed with a "Not for me" window, the leftover marked
 * not placed, emails queued (contact details only between a player and
 * their own captain). Confirming again adds nothing and queues nothing;
 * someone who joined a team in between is skipped. Then late changes: a
 * friend who joins a held-back player's group is held back with them (not
 * left split), a move onto a draft team since made into a team means that
 * team (no second one), and someone who steps back ("Not for me") and is
 * placed on another team is emailed about it.
 *
 *   wp eval-file dev/tests/test-placement-confirm.php
 *
 * Uses a far-future season and throwaway @example.test users; cleans up
 * (teams, list rows, requests, the season's draft, queued emails, users).
 */

if (!defined('WP_CLI') || !WP_CLI || (wp_get_environment_type() === 'production' && !getenv('RSL_DEV'))) {
    exit("Local copy only: run with wp eval-file, with RSL_DEV=1 set (docker exec -e RSL_DEV=1 ...).\n");
}
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once dirname(__DIR__) . '/lib-profile.php';
global $wpdb;

$season = '2086-pre';
$suffix = time();
$fails = 0;
$check = function ($label, $ok, $detail = '') use (&$fails) {
    if (!$ok) { $fails++; }
    echo ($ok ? "OK: $label\n" : "FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n");
};
$saved_fa = get_option(RSL_Free_Agents::SETTINGS_OPTION, '__unset__');
delete_option(RSL_Free_Agents::SETTINGS_OPTION); // the defaults: teams of 7–10, filled to 8, new teams of 8
$forget_draft = function () use ($season) {
    $all = get_option(RSL_Free_Agents::BUILDING_OPTION, array());
    if (is_array($all) && isset($all[$season])) {
        unset($all[$season]);
        update_option(RSL_Free_Agents::BUILDING_OPTION, $all, false);
    }
};
$forget_draft();
$users = array();
$mk = function ($tag, $level = '', $years = '1to3', $positions = array('outside')) use ($suffix, &$users) {
    $u = wp_insert_user(array('user_login' => "rslpc-$tag-$suffix", 'user_email' => "rslpc-$tag-$suffix@example.test", 'user_pass' => wp_generate_password(),
        'display_name' => "Pc$tag Tester", 'first_name' => "Pc$tag", 'role' => 'subscriber'));
    $users[] = $u;
    rsl_dev_profile($u);
    if ($level !== '') {
        RSL_Players::register($u, array('level' => $level, 'years' => $years, 'positions' => $positions));
    }
    return $u;
};
$email = function ($uid) {
    $u = get_userdata($uid);
    return $u ? $u->user_email : '';
};
$outbox = function () use ($wpdb, $suffix) {
    return $wpdb->get_results($wpdb->prepare("SELECT * FROM " . RSL_Database::table('outbox') . " WHERE to_email LIKE %s ORDER BY id", '%-' . $suffix . '@example.test'));
};
$mail_to = function ($uid, $prefix) use ($outbox, $email) {
    foreach ($outbox() as $m) {
        if ($m->to_email === $email($uid) && strpos($m->dedupe, $prefix) === 0) {
            return $m;
        }
    }
    return null;
};
$members = function ($team_id) use ($wpdb) {
    return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . RSL_Database::table('team_members') . " WHERE team_id = %d AND left_at IS NULL", $team_id));
};
$season_members = function () use ($wpdb, $season) {
    return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . RSL_Database::table('team_members') . " m JOIN " . RSL_Database::table('teams') . " t ON t.id = m.team_id WHERE t.season = %s", $season));
};
$version = function () use ($season) {
    return intval(RSL_Free_Agents::building($season)['draft_version']);
};
$row = function ($uid) use ($season) {
    return RSL_Free_Agents::get($uid, $season);
};
$dest_of = function ($uid) use ($row) {
    $r = $row($uid);
    return $r ? (string) $r->proposed_dest : '';
};

// --- The season: a short team, a team with one open spot, 13 free agents ---------------------------
// Teams are "social" (band 2); the free agents play social comp for 1–3 years (band 2.5).
$team_a = RSL_Teams::create($season, array('name' => "PC Short $suffix", 'division' => 'unranked', 'level' => 'social', 'status' => 'active'));
$cap_a = $mk('capa');
RSL_Teams::add_member($team_a->id, $cap_a, 'captain');
for ($i = 1; $i <= 4; $i++) {
    RSL_Teams::add_member($team_a->id, $mk("a$i"));
}
$team_b = RSL_Teams::create($season, array('name' => "PC Spots $suffix", 'division' => 'unranked', 'level' => 'social', 'status' => 'active'));
$cap_b = $mk('capb');
RSL_Teams::add_member($team_b->id, $cap_b, 'captain');
for ($i = 1; $i <= 6; $i++) {
    RSL_Teams::add_member($team_b->id, $mk("b$i"));
}
RSL_Free_Agents::set_spots($team_b->id, $cap_b, array('spots' => 1));
$check('setup: a team of 5 and a team of 7 with an open spot', $members($team_a->id) === 5 && $members($team_b->id) === 7 && intval(RSL_Teams::get($team_b->id)->spots_open) === 1);
$f = array();
for ($i = 1; $i <= 12; $i++) {
    // All the same position, so among equals the earliest sign-ups go first (a setter would jump the queue for a team without one).
    $f[$i] = $mk("f$i", 'social', '1to3', array('outside'));
    RSL_Free_Agents::join($f[$i], $season, array('division_pref' => 'unranked', 'captain_ok' => $i === 5 ? 1 : 0, 'browsable' => 1), true);
}
$code = RSL_Free_Agents::group_create($f[10], $season);
RSL_Free_Agents::group_join($f[11], $season, $code);
$rk = $mk('rk', 'state', 'gt3');
RSL_Free_Agents::join($rk, $season, array('division_pref' => 'ranked', 'browsable' => 1), true);
$check('setup: 13 on the list (one friend group), nothing emailed yet', count(RSL_Free_Agents::pool($season)) === 13 && $row($f[11])->group_code === $code && !$outbox());

// --- Suggest and the draft --------------------------------------------------------------------------------
$summary = RSL_Placement::suggest($season);
$v1 = $version();
$check('suggest makes a proposal and a new draft version', is_array($summary) && $summary && $v1 >= 1, is_wp_error($summary) ? $summary->get_error_message() : '');
$check('the short team is proposed two players first', $dest_of($f[1]) === 't:' . $team_a->id && $dest_of($f[2]) === 't:' . $team_a->id, $dest_of($f[1]) . ' ' . $dest_of($f[2]));
$check('the open spot gets one', $dest_of($f[3]) === 't:' . $team_b->id, $dest_of($f[3]));
$rest = true;
for ($i = 4; $i <= 12; $i++) {
    $rest = $rest && $dest_of($f[$i]) === 'n:U1';
}
$check('the other nine make a new team, the friend group together', $rest);
$check('the Ranked-only player is left over, with a reason in plain words', $dest_of($rk) === 'none' && strpos($row($rk)->proposed_reason, 'Ranked player') !== false, $row($rk)->proposed_reason);
$draft = RSL_Placement::draft($season);
$new = isset($draft['new'][0]) ? $draft['new'][0] : null;
$check('draft() groups it for the admin screen', $draft['version'] === $v1 && count($draft['existing']) === 2 && count($draft['new']) === 1 && $new['ref'] === 'U1'
    && count($new['rows']) === 9 && count($draft['none']) === 1 && !$draft['unproposed']);
$check('the new team\'s draft: placeholder name, the volunteer as captain', $new && RSL_Placement::is_placeholder($new['draft']['name']) && intval($new['draft']['captain_user_id']) === $f[5]);
$check('the placeholder name is a blocker', (bool) array_filter(RSL_Placement::blockers($season)['blockers'], function ($m) { return stripos($m, 'placeholder') !== false; }));

// --- Moving people ---------------------------------------------------------------------------------------------
$dests = RSL_Placement::destinations($season, $f[6]);
$check('"Move to" lists teams with room, the new team, another new team, not placed', isset($dests['t:' . $team_a->id], $dests['t:' . $team_b->id], $dests['n:U1'], $dests['n:U2'], $dests['none']), json_encode(array_keys($dests)));
$check('and nothing Ranked for an Unranked-only player', !preg_grep('/^n:R/', array_keys($dests)));
$check('a Ranked-only player isn\'t offered Unranked teams', !array_intersect(array('t:' . $team_a->id, 't:' . $team_b->id, 'n:U1'), array_keys(RSL_Placement::destinations($season, $rk))));
// The placement screen asks for everyone at once: same answers as one at a time, in a handful of queries.
$q = $wpdb->num_queries;
$all_dests = RSL_Placement::destinations_all($season);
$all_queries = $wpdb->num_queries - $q;
$same = count($all_dests) === count(RSL_Free_Agents::pool($season));
foreach ($all_dests as $uid => $d) {
    $same = $same && $d === RSL_Placement::destinations($season, $uid);
}
$check('destinations_all() gives everyone the same "Move to" options as destinations(), in a few queries', $same && $all_queries <= 8, $all_queries . ' queries');
$stale = RSL_Placement::move($season, $f[6], 't:' . $team_b->id, $v1 - 1);
$check('a move from a stale draft is refused', is_wp_error($stale) && $stale->get_error_code() === 'rsl_place_stale');
$check('a move somewhere not offered is refused', is_wp_error(RSL_Placement::move($season, $f[6], 't:999999999', $v1)) && $version() === $v1);
$check('the admin moves someone', RSL_Placement::move($season, $f[6], 't:' . $team_b->id, $v1) === true && $dest_of($f[6]) === 't:' . $team_b->id && intval($row($f[6])->proposed_locked) === 1);
$v2 = $version();
$check('which bumps the version, so the old tab is stale now', $v2 === $v1 + 1 && is_wp_error(RSL_Placement::move($season, $f[6], 'n:U1', $v1)));
$check('a friend group can\'t be moved where only one of them fits', is_wp_error(RSL_Placement::move($season, $f[10], 't:' . $team_b->id, $v2)) && $dest_of($f[10]) === 'n:U1');
RSL_Placement::suggest($season);
$v3 = $version();
$check('re-running keeps the admin\'s move (and the open spot, now filled by it, takes nobody else)', $v3 === $v2 + 1 && $dest_of($f[6]) === 't:' . $team_b->id && intval($row($f[6])->proposed_locked) === 1 && $dest_of($f[3]) === 'n:U1', $dest_of($f[3]));

// --- Confirming one ready team; the unnamed new team blocks the rest ---------------------------------------------
$blocked = RSL_Placement::confirm($season);
$check('confirming everything is refused while the new team has a placeholder name', is_wp_error($blocked) && $blocked->get_error_code() === 'rsl_place_blocked' && stripos($blocked->get_error_message(), 'placeholder') !== false);
$check('and nothing happened', $season_members() === 12 && RSL_Free_Agents::is_open($row($f[1])) && !$outbox());
$one = RSL_Placement::confirm($season, 't:' . $team_a->id);
$check('one ready team can be confirmed on its own', is_array($one) && $one['placed'] === 2 && $one['new_teams'] === 0 && $one['skipped'] === 0 && $one['emails'] === 3, json_encode($one));
$r1 = $row($f[1]);
$check('they\'re on the team, marked placed by placement with a "Not for me" window', RSL_Teams::is_member($team_a->id, $f[1]) && RSL_Teams::is_member($team_a->id, $f[2]) && $members($team_a->id) === 7
    && $r1->status === 'placed' && intval($r1->team_id) === intval($team_a->id) && $r1->placed_via === 'placement' && $r1->optout_until > current_time('mysql') && $r1->proposed_dest === '');
$check('the rest of the season isn\'t confirmed yet', RSL_Free_Agents::building($season)['confirmed_at'] === '' && RSL_Free_Agents::is_open($row($f[3])));

// --- The admin screen: picking a captain before the team has a name -------------------------------------------------
// The form posts what it was drawn with; only what changed is saved, so the placeholder
// name doesn't block a captain change and the untouched level keeps following the players.
$admin = wp_insert_user(array('user_login' => "rslpc-admin-$suffix", 'user_email' => "rslpc-admin-$suffix@example.test", 'user_pass' => wp_generate_password(), 'role' => 'administrator'));
$users[] = $admin;
wp_set_current_user($admin);
$fa_post = function ($do, $extra) use ($season, $version) {
    return RSL_Admin_Free_Agents::process(array_merge(array('rsl_nonce' => wp_create_nonce(RSL_Admin_Free_Agents::ACTION), 'season' => $season, 'tab' => 'placement', 'do' => $do, 'version' => $version()), $extra));
};
$fa_notice = function () use ($admin) {
    $n = get_transient(RSL_Admin::NOTICE_PREFIX . $admin);
    delete_transient(RSL_Admin::NOTICE_PREFIX . $admin);
    return is_array($n) ? $n : array('type' => '', 'message' => '');
};
$u1 = RSL_Free_Agents::building($season)['new_teams']['U1'];
$form = array('ref' => 'U1', 'name' => $u1['name'], 'name_was' => $u1['name'], 'est_level' => $u1['est_level'], 'level_was' => $u1['est_level']);
$fa_post('update_new', $form + array('captain_user_id' => $f[7], 'captain_was' => $u1['captain_user_id']));
$n = $fa_notice();
$u1b = RSL_Free_Agents::building($season)['new_teams']['U1'];
$check('admin: a captain can be picked while the name is still the placeholder', $n['type'] === 'success' && intval($u1b['captain_user_id']) === $f[7]
    && empty($u1b['name_set']) && empty($u1b['level_set']) && RSL_Placement::is_placeholder($u1b['name']), $n['message'] . ' ' . json_encode($u1b));
$fa_post('update_new', $form + array('captain_user_id' => $f[5], 'captain_was' => $f[7]));
$n = $fa_notice();
$check('...and changed back (the volunteer), still with the level following the players', $n['type'] === 'success' && intval(RSL_Free_Agents::building($season)['new_teams']['U1']['captain_user_id']) === $f[5]
    && empty(RSL_Free_Agents::building($season)['new_teams']['U1']['level_set']), $n['message']);
$fa_post('update_new', $form + array('captain_user_id' => $f[5], 'captain_was' => $f[5]));
$n = $fa_notice();
$v4 = $version();
$check('saving an untouched form changes nothing (and doesn\'t make other tabs stale)', $n['type'] === 'success' && strpos($n['message'], 'Nothing to save') !== false && $v4 === $v3 + 2, $n['message']);
wp_set_current_user(0);

// --- Naming the new team ---------------------------------------------------------------------------------------------
$check('naming with a stale version is refused', is_wp_error($e = RSL_Placement::update_new($season, 'U1', array('name' => "PC Newbies $suffix"), $v3)) && $e->get_error_code() === 'rsl_place_stale');
$check('a placeholder isn\'t a name', is_wp_error($e = RSL_Placement::update_new($season, 'U1', array('name' => 'Unranked New 1'), $v4)) && $e->get_error_code() === 'rsl_name');
$check('the captain has to be in the team', is_wp_error($e = RSL_Placement::update_new($season, 'U1', array('captain_user_id' => $cap_a), $v4)) && $e->get_error_code() === 'rsl_place_captain');
$check('a real name, and a level', RSL_Placement::update_new($season, 'U1', array('name' => "PC Newbies $suffix", 'est_level' => 'social'), $v4) === true && $version() === $v4 + 1);
$left = RSL_Placement::blockers($season);
$check('nothing blocks confirming now', $left['blockers'] === array(), implode(' | ', $left['blockers']));

// --- Someone joins a team another way in between ---------------------------------------------------------------------
$check('a proposed player joins a team by code before confirming', RSL_Teams::add_member($team_a->id, $f[4], 'player', false, array('how' => 'code')) === true && $row($f[4])->status === 'placed');

// --- Confirm everything -------------------------------------------------------------------------------------------------
$all = RSL_Placement::confirm($season, null, 0);
$check('confirm: 9 placed (8 into a new team), 1 new team, 1 not placed, 1 skipped, 11 emails', is_array($all) && $all['placed'] === 9 && $all['new_teams'] === 1 && $all['unplaced'] === 1 && $all['skipped'] === 1 && $all['emails'] === 11 && !isset($all['errors']), json_encode($all));
$nt = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . RSL_Database::table('teams') . " WHERE season = %s AND name = %s", $season, "PC Newbies $suffix"));
$check('the new team is made by placement, active, Unranked, with the level set', $nt && $nt->formed_by === 'placement' && $nt->status === 'active' && $nt->division === 'unranked' && $nt->est_level === 'social');
$captain = $nt ? RSL_Teams::captain($nt->id) : null;
$check('with the volunteer as captain and its 8 players (the friend group together)', $nt && $captain && intval($captain->user_id) === $f[5] && $members($nt->id) === 8
    && RSL_Teams::is_member($nt->id, $f[10]) && RSL_Teams::is_member($nt->id, $f[11]) && RSL_Teams::is_member($nt->id, $f[3]));
$check('the draft remembers the team it became', $nt && intval(RSL_Free_Agents::building($season)['new_teams']['U1']['team_id']) === intval($nt->id));
$r7 = $row($f[7]);
$check('new-team players are marked placed with a "Not for me" window', $nt && $r7->status === 'placed' && intval($r7->team_id) === intval($nt->id) && $r7->placed_via === 'placement' && $r7->optout_until > current_time('mysql'));
$check('the moved player is on the team the admin chose', RSL_Teams::is_member($team_b->id, $f[6]) && $row($f[6])->status === 'placed' && $members($team_b->id) === 8);
$rr = $row($rk);
$check('the leftover is marked not placed, with the reason', $rr->status === 'unplaced' && strpos($rr->unplaced_reason, 'Ranked player') !== false && !RSL_Teams::current_team_for_user($rk, $season));
$r4 = $row($f[4]);
$check('the player who joined in between stays where they joined, untouched', RSL_Teams::is_member($team_a->id, $f[4]) && (!$nt || !RSL_Teams::is_member($nt->id, $f[4])) && $r4->placed_via === 'code' && $r4->optout_until === null && !$mail_to($f[4], 'placed:'));
$check('the season\'s placements are confirmed', RSL_Free_Agents::building($season)['confirmed_at'] !== '' && RSL_Free_Agents::phase($season) === 'season');

// --- The emails ----------------------------------------------------------------------------------------------------------
$m7 = $mail_to($f[7], 'placed:' . $season . ':' . $f[7] . ':' . ($nt ? $nt->id : 0));
$check('a placed player gets their captain\'s name and email — and nobody else\'s email', $m7 && strpos($m7->body, $email($f[5])) !== false && strpos($m7->body, $email($f[8])) === false && strpos($m7->body, $email($f[7])) === false);
$mc = $mail_to($f[5], 'newcap:' . ($nt ? $nt->id : 0));
$check('the new team\'s captain gets the roster with emails', $mc && strpos($mc->body, $email($f[7])) !== false && strpos($mc->body, $email($f[11])) !== false);
$mb = $mail_to($cap_b, 'placed-team:' . $season . ':' . $team_b->id);
$check('a receiving captain gets their new player\'s details only', $mb && strpos($mb->body, $email($f[6])) !== false && strpos($mb->body, $email($f[7])) === false);
$ma = $mail_to($cap_a, 'placed-team:' . $season . ':' . $team_a->id);
$check('the first team\'s captain was told too (and not about the player who joined by code)', $ma && strpos($ma->body, $email($f[1])) !== false && strpos($ma->body, $email($f[4])) === false);
$check('the leftover is told, not charged', ($mu = $mail_to($rk, 'unplaced:' . $season . ':' . $rk)) && stripos($mu->body, 'charged') !== false);
$check('14 emails in all, one each', count($outbox()) === 14 && count(array_unique(array_map(function ($m) { return $m->dedupe; }, $outbox()))) === 14, (string) count($outbox()));

// --- Confirming again changes nothing --------------------------------------------------------------------------------------
$before_members = $season_members();
$before_mail = count($outbox());
$again = RSL_Placement::confirm($season);
$check('confirming twice adds nobody, makes no team, tells nobody again', is_array($again) && $again['placed'] === 0 && $again['new_teams'] === 0 && $again['unplaced'] === 0 && $again['emails'] === 0, json_encode($again));
$check('no new members, teams or queued emails', $season_members() === $before_members && count($outbox()) === $before_mail
    && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . RSL_Database::table('teams') . " WHERE season = %s AND formed_by = 'placement'", $season)) === 1);
$check('the leftover is still first in line (not placed, still waiting)', $row($rk)->status === 'unplaced' && RSL_Free_Agents::is_open($row($rk)));
$check('a block that isn\'t there is refused', is_wp_error(RSL_Placement::confirm($season, 'x:1')));
wp_set_current_user($admin);
$fa_post('confirm', array('only' => 'none'));
$n = $fa_notice();
wp_set_current_user(0);
$check('admin: "Tell these players only" confirms the not-placed block (already told, so nobody again)', $n['type'] === 'success' && strpos($n['message'], 'isn\'t there any more') === false
    && count($outbox()) === $before_mail, $n['message']);

// --- Late changes: a friend joins someone the admin held back; a move onto a draft team that's since been made ----------
$rk2 = $mk('rk2', 'state', 'gt3');
RSL_Free_Agents::join($rk2, $season, array('division_pref' => 'ranked', 'browsable' => 1), true);
$check('the admin holds the leftover back', RSL_Placement::move($season, $rk, 'none', $version()) === true && intval($row($rk)->proposed_locked) === 1);
$check('then a friend joins their group late (any phase)', RSL_Free_Agents::group_join($rk2, $season, RSL_Free_Agents::group_create($rk, $season)) === true && $dest_of($rk2) === '');
$split = RSL_Placement::confirm($season, 'none');
$check('until a re-run, the split group holds up its own block…', is_wp_error($split) && strpos($split->get_error_message(), 'split up') !== false && preg_grep('/split up/', RSL_Placement::blockers($season)['blockers']));
$check('…but not the other teams', is_array($other = RSL_Placement::confirm($season, 't:' . $team_b->id)) && $other['placed'] === 0 && $other['emails'] === 0, is_wp_error($other) ? $other->get_error_message() : json_encode($other));
$late = $mk('late', 'social', '1to3');
RSL_Free_Agents::join($late, $season, array('division_pref' => 'unranked', 'browsable' => 1), true);
// As an admin's move onto new team U1 left over when U1 was made (rows like this are what an earlier version stranded).
$wpdb->update(RSL_Database::table('free_agents'), array('proposed_dest' => 'n:U1', 'proposed_locked' => 1), array('season' => $season, 'user_id' => $late));
RSL_Placement::suggest($season);
$check('a re-run holds the late friend back with them, so the group isn\'t split', $dest_of($rk2) === 'none' && intval($row($rk2)->proposed_locked) === 1
    && !preg_grep('/split up/', RSL_Placement::blockers($season)['blockers']), $dest_of($rk2));
$check('a move onto a new team that\'s been made means the real team (the draft keeps the team it became)', $nt && $dest_of($late) === 't:' . $nt->id
    && intval(RSL_Free_Agents::building($season)['new_teams']['U1']['team_id']) === intval($nt->id), $dest_of($late));
$joined = $nt ? RSL_Placement::confirm($season, 't:' . $nt->id) : null;
$check('confirming it adds them to the team made earlier — no second team', is_array($joined) && $joined['placed'] === 1 && $joined['new_teams'] === 0 && $joined['emails'] === 2
    && RSL_Teams::is_member($nt->id, $late) && $members($nt->id) === 9
    && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . RSL_Database::table('teams') . " WHERE season = %s AND formed_by = 'placement'", $season)) === 1, json_encode($joined));
$check('and its captain is told about them (a new email, though the draft version may not have changed)', $nt && ($ml = $mail_to($f[5], 'placed-team:' . $season . ':' . $nt->id . ':')) && strpos($ml->body, $email($late)) !== false);

// --- Stepping back, then placed on another team: told about that one too ---------------------------------------------------
$check('a placed player says "Not for me"', RSL_Free_Agents::not_for_me($f[7], $season) === true && $row($f[7])->status === 'looking');
$check('the admin moves them to another team', RSL_Placement::move($season, $f[7], 't:' . $team_b->id, $version()) === true);
$again7 = RSL_Placement::confirm($season, 't:' . $team_b->id);
$m7b = $mail_to($f[7], 'placed:' . $season . ':' . $f[7] . ':' . $team_b->id);
$check('confirming it emails them their new team and captain (not held back by the first placement email)', is_array($again7) && $again7['placed'] === 1 && RSL_Teams::is_member($team_b->id, $f[7])
    && $m7b && strpos($m7b->body, $email($cap_b)) !== false, json_encode($again7));

// --- Start over: the admin's holds are forgotten ------------------------------------------------------------------------------
wp_set_current_user($admin);
$fa_post('suggest', array('fresh' => 1));
$n = $fa_notice();
wp_set_current_user(0);
$check('admin: "Start over" forgets the admin\'s holds (nobody stays fixed by an admin)', $n['type'] === 'success' && strpos($n['message'], 'Started over') !== false
    && intval($row($rk)->proposed_locked) === 0 && intval($row($rk2)->proposed_locked) === 0, $n['message']);

// --- Cleanup ----------------------------------------------------------------------------------------------------------------
foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM " . RSL_Database::table('teams') . " WHERE season = %s", $season)) as $tid) {
    $wpdb->delete(RSL_Database::table('team_members'), array('team_id' => $tid));
    $wpdb->delete(RSL_Database::table('invites'), array('team_id' => $tid));
}
$wpdb->delete(RSL_Database::table('teams'), array('season' => $season));
$wpdb->delete(RSL_Database::table('free_agents'), array('season' => $season));
$wpdb->delete(RSL_Database::table('join_requests'), array('season' => $season));
$forget_draft();
foreach ($users as $u) {
    $wpdb->delete(RSL_Database::table('team_members'), array('user_id' => $u));
    $wpdb->delete(RSL_Database::table('players'), array('user_id' => $u));
    $wpdb->delete(RSL_Database::table('free_agents'), array('user_id' => $u));
    $wpdb->delete(RSL_Database::table('invites'), array('user_id' => $u));
    wp_delete_user($u);
}
$wpdb->query($wpdb->prepare("DELETE FROM " . RSL_Database::table('outbox') . " WHERE to_email LIKE %s OR dedupe LIKE %s", '%-' . $suffix . '@example.test', 'optout:' . $season . ':%'));
if ($saved_fa === '__unset__') {
    delete_option(RSL_Free_Agents::SETTINGS_OPTION);
} else {
    update_option(RSL_Free_Agents::SETTINGS_OPTION, $saved_fa);
}
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
