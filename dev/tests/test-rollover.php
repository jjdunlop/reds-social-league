<?php
/**
 * Entering a team again for the next season (0.21.0): RSL_Seasons::has_started(),
 * RSL_Teams::next_season_for(), roll_over() (every refusal but the lock's, with the words of
 * apply()'s naming the season; an admin sending it, what it copies, a full division's waiting
 * list and the card's line about it, the same form sent twice), rolled_into() (and nothing
 * while the schema upgrade is still to finish), roll_state(), a declined entry said above the
 * box, approve() inviting last season's players (the captain, a player on another team there,
 * one who left and one already invited left out; no second invite when approval runs again;
 * one email each, parked; the captain's sentence and link label; the Teams screen's note and
 * notice; placement holding their places), the team page's invite labels, accepting and
 * declining, and the league page's card for the captain and the player in each state: hidden
 * before the team's season starts (or with no dates) and once they have something in the next
 * season, the Not carrying on? box (closed, its ids once, opened by a failed application, which
 * comes back to its own card), and with two seasons on the page.
 *
 *   wp eval-file dev/tests/test-rollover.php
 *
 * Far-future throwaway seasons (2098 Post-season running now, 2099 Pre-season after it, dates
 * from today) and @example.test users; mail held and parked, never sent; cleans up.
 */

if (!defined('WP_CLI') || !WP_CLI || (wp_get_environment_type() === 'production' && !getenv('RSL_DEV'))) {
    exit("Local copy only: run with wp eval-file, with RSL_DEV=1 set (docker exec -e RSL_DEV=1 ...).\n");
}
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once dirname(__DIR__) . '/lib-profile.php';
require_once dirname(__DIR__) . '/lib-mail-stop.php';
global $wpdb;

$A = '2098-post';       // the team's season: running now (today-14 to today+30)
$S = '2099-pre';        // the season after it: today+40 to today+100, open for sign-up
$NODATES = '2099-mid2'; // never given dates
$suffix = time();
$hold = rsl_dev_hold_mail($suffix);
$fails = 0;
$check = function ($label, $ok, $detail = '') use (&$fails, $hold) {
    rsl_dev_park_mail($hold);
    if (!$ok) { $fails++; }
    echo ($ok ? "OK: $label\n" : "FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n");
};
$today = current_time('Y-m-d');
$day = function ($n) use ($today) { return date('Y-m-d', strtotime($today . ' ' . ($n < 0 ? '' : '+') . intval($n) . ' days')); };
// Everything the cleanup reads, set before the try.
$saved = array();
foreach (array(RSL_Seasons::CURRENT_OPTION, RSL_Seasons::SIGNUP_OPTION, RSL_Courts::SETTINGS_OPTION, RSL_Portal::PAGE_OPTION) as $opt) {
    $saved[$opt] = get_option($opt, '__unset__');
}
$users = array();
$made_portal = 0;
$cands = null;
$fa_filter = null;
$fa_now = array();
$schema_behind = null;
$no_look = null;
$capture = null;
$admin = 0;
$mk = function ($tag, $role = 'subscriber') use ($suffix, &$users) {
    $u = wp_insert_user(array('user_login' => "rslr-$tag-$suffix", 'user_email' => "rslr-$tag-$suffix@example.test", 'user_pass' => wp_generate_password(),
        'display_name' => "RSLR $tag $suffix", 'role' => $role));
    if (is_wp_error($u)) {
        throw new Exception("Couldn't make the test account $tag: " . $u->get_error_message());
    }
    $users[] = $u;
    rsl_dev_profile($u);
    $r = RSL_Players::register($u, array('level' => 'social', 'years' => '1to3'));
    if (is_wp_error($r)) {
        throw new Exception("Couldn't register the test account $tag: " . $r->get_error_message());
    }
    return $u;
};

try {

// --- Setup -------------------------------------------------------------------------------------------------
$check('setup: the schema has teams.rolled_from (schema 12)', RSL_Database::schema_current() && RSL_Database::SCHEMA_VERSION === '12'
    && (bool) $wpdb->get_var("SHOW COLUMNS FROM " . RSL_Database::table('teams') . " LIKE 'rolled_from'"));
$set_dates = function ($season, $start, $end) {
    return RSL_Courts::save_settings($season, array('start' => $start, 'end' => $end, 'weekday' => 0));
};
$clear_dates = function ($season) {
    $all = get_option(RSL_Courts::SETTINGS_OPTION, array());
    $all = is_array($all) ? $all : array();
    unset($all[$season]);
    update_option(RSL_Courts::SETTINGS_OPTION, $all, false);
};
$set_dates($A, $day(-14), $day(30));
$set_dates($S, $day(40), $day(100));
$clear_dates($NODATES);
update_option(RSL_Seasons::CURRENT_OPTION, $A);
RSL_Seasons::set_signup($A);
$cands = function ($keys) use ($A, $S) { return array($A, $S); };
add_filter('rsl_open_for_signup_candidates', $cands);
// The free agent settings as the site has them, but no division's places set by hand (so a season with
// no game nights isn't checked, and nothing waits) until the waiting list part sets Ranked to 1.
$fa_base = array_merge(RSL_Free_Agents::settings(), array('capacity_override' => array('ranked' => 0, 'unranked' => 0)));
$fa_now = $fa_base;
$fa_filter = function () use (&$fa_now) { return $fa_now; };
add_filter('pre_option_' . RSL_Free_Agents::SETTINGS_OPTION, $fa_filter);
$had = intval(get_option(RSL_Portal::PAGE_OPTION));
$portal_id = intval(RSL_Portal::ensure_page()); // the existing page when there is one
$made_portal = $portal_id !== $had ? $portal_id : 0;
$open = RSL_Seasons::open_for_signup();
$check('setup: 2099 Pre-season comes after 2098 Post-season and both are open for sign-up', RSL_Seasons::next($A) === $S && isset($open[$S]) && isset($open[$A]), wp_json_encode($open));
$check('has_started(): a season with dates whose first night has come; not one still to start, one with no dates or a bad key',
    RSL_Seasons::has_started($A) && !RSL_Seasons::has_started($S) && !RSL_Seasons::has_started($NODATES) && !RSL_Seasons::has_started('2099-xmas'));

$cap = $mk('cap'); $p1 = $mk('p1'); $p2 = $mk('p2'); $p4 = $mk('p4'); $pe = $mk('pe'); $pl = $mk('pl');
$cap2 = $mk('cap2'); $p3 = $mk('p3'); $oc = $mk('oc'); $stranger = $mk('str'); $admin = $mk('admin', 'administrator');
$name = "Middles $suffix";
$old = RSL_Teams::create($A, array('name' => $name, 'division' => 'ranked', 'colour' => '#8c2361', 'level' => 'strong', 'status' => 'active'));
if (!$old || is_wp_error($old)) {
    throw new Exception('setup: the team could not be made');
}
RSL_Teams::add_member($old->id, $cap, 'captain', false, array('admin' => true));
foreach (array($p1, $p2, $p4, $pe, $pl) as $u) {
    RSL_Teams::add_member($old->id, $u, 'player', false, array('admin' => true));
}
RSL_Teams::remove_member($old->id, $pl); // left the team: never invited
RSL_Teams::set_preferences($old->id, $cap, array('time' => 'late', 'double' => 1, 'shape' => 'break', 'standby' => 1));
$old = RSL_Teams::get($old->id);
$name2 = "Setters $suffix";
$old2 = RSL_Teams::create($A, array('name' => $name2, 'division' => 'unranked', 'status' => 'active')); // no level
RSL_Teams::add_member($old2->id, $cap2, 'captain', false, array('admin' => true));
RSL_Teams::add_member($old2->id, $p3, 'player', false, array('admin' => true));
$other = RSL_Teams::create($S, array('name' => "Other $suffix", 'division' => 'unranked', 'status' => 'active'));
RSL_Teams::add_member($other->id, $oc, 'captain', false, array('admin' => true));
$check('setup: the teams', $old->est_level === 'strong' && $old->pref_time === 'late' && RSL_Teams::is_captain($old->id, $cap) && !RSL_Teams::is_member($old->id, $pl)
    && $old2 && !is_wp_error($old2) && $old2->est_level === '' && $other && !is_wp_error($other) && RSL_Teams::is_captain($other->id, $oc));

// --- next_season_for() -------------------------------------------------------------------------------------------
$check('next_season_for(): the season after the team\'s, while it is open for sign-up', RSL_Teams::next_season_for($old) === $S);
$check('...none when the season after isn\'t open (2099 Mid-season 1 isn\'t a candidate), nor for no team or a bad season', RSL_Teams::next_season_for($other) === ''
    && RSL_Teams::next_season_for(null) === '' && RSL_Teams::next_season_for((object) array('season' => 'nope')) === '');
$clear_dates($S);
$check('...none while the season after has no dates (a later season with no dates isn\'t open)', RSL_Teams::next_season_for($old) === '');
$set_dates($S, $day(40), $day(100));

// --- The league page before the team is entered again -----------------------------------------------------------
$portal = new RSL_Portal();
$view = function ($as) use ($portal) {
    wp_set_current_user($as);
    $_GET = array();
    RSL_Portal::flush_menu_state();
    return $portal->render();
};
$front = function ($as, $fields) {
    wp_set_current_user($as);
    delete_transient(RSL_Portal::FLASH_PREFIX . $as);
    $to = RSL_Portal::process(array_merge(array('rsl_nonce' => wp_create_nonce('rsl_front')), $fields));
    $f = get_transient(RSL_Portal::FLASH_PREFIX . $as);
    delete_transient(RSL_Portal::FLASH_PREFIX . $as);
    return array((string) $to, is_array($f) ? (string) $f['message'] : '');
};
$card_id = function ($team) { return 'id="rsl-roll-' . intval($team->id) . '"'; };
$roll_form = function ($html) {
    $a = strpos($html, 'value="roll_over"');
    if ($a === false) {
        return '';
    }
    $b = strpos($html, '</form>', $a);
    return (string) substr($html, $a, $b === false ? strlen($html) - $a : $b - $a);
};
$label_s = RSL_Seasons::label_with_dates($S, RSL_Seasons::span($S));
$head = '<h3 class="rsl-roll-head">' . esc_html($label_s) . '</h3>';
$box = '<summary>Not carrying on? Find another team for 2099 Pre-season</summary>';
$strong = RSL_Teams::levels()['strong'];

$v = $view($cap);
$form = $roll_form($v);
$check('captain: a card under the team, headed with the next season and its dates, with Enter the team again', strpos($v, $card_id($old)) !== false
    && strpos($v, $card_id($old)) > strpos($v, 'Open team page') && strpos($v, $head) !== false
    && strpos($v, 'Keep ' . $name . ' going: enter it again as it is now.') !== false
    && strpos($v, 'Once the league approves it, your players get an invite to accept, so anyone not carrying on can say no.') !== false
    && strpos($v, $name . ' goes on the waiting list.') === false
    && $form !== '' && strpos($form, 'name="team_id" value="' . $old->id . '"') !== false && strpos($v, '>Enter the team again</button>') !== false, $form);
$check('...the team has a level, so the form asks none, and the level is never shown or filled in', strpos($form, 'team_level') === false
    && strpos($v, 'value="strong" selected') === false && strpos($v, 'est_level') === false
    && substr_count($v, esc_html($strong)) === substr_count($v, 'name="team_level"'), $form);
$check('...the Not carrying on? box: closed, holding that season\'s ways onto a team (its own ids), the application card once', strpos($v, '<details class="rsl-roll-other">') !== false
    && strpos($v, '<details class="rsl-roll-other" open>') === false && strpos($v, $box) !== false && strpos($v, $box) > strpos($v, $card_id($old))
    && substr_count($v, 'id="rsl-apply"') === 1 && strpos($v, 'id="rsl-apply"') > strpos($v, $box) && strpos($v, 'Got an invite code?') > strpos($v, $box)
    && strpos($v, 'id="rsl-fa-find-' . $S . '"') !== false && strpos($v, 'name="team_season" value="' . $S . '"') !== false);
$rs = RSL_Teams::roll_state($old, $cap);
$check('roll_state(): the captain', is_array($rs) && $rs['state'] === 'captain' && $rs['season'] === $S && $rs['rolled'] === null && $rs['captain'] === true, wp_json_encode($rs));

$v = $view($cap2);
$form = $roll_form($v);
$check('captain of a team with no level: the form asks roughly what level, required', strpos($form, '<select name="team_level" required>') !== false
    && strpos($form, 'value="social"') !== false, $form);

$v = $view($p1);
$check('player: the card says to ask the captain, with no form, and the box', strpos($v, $card_id($old)) !== false && strpos($v, $head) !== false
    && strpos($v, '<strong>Want to keep playing with ' . $name . '?</strong> Ask your captain to enter the team again. You\'ll get an invite to accept once the league approves it.') !== false
    && $roll_form($v) === '' && strpos($v, $box) !== false && substr_count($v, 'id="rsl-apply"') === 1);
$rp = RSL_Teams::roll_state($old, $p1);
$check('roll_state(): a player; nothing for someone who left the team or was never on it', is_array($rp) && $rp['state'] === 'player' && $rp['captain'] === false
    && RSL_Teams::roll_state($old, $pl) === null && RSL_Teams::roll_state($old, $stranger) === null);
$check('...and no card for the player who left', strpos($view($pl), $card_id($old)) === false);

list($to, $msg) = $front($p1, array('do' => 'apply', 'team_season' => $S, 'team_name' => '', 'team_level' => 'social'));
$v = $view($p1);
$check('a failed application from the box: the box and the application card open again', $msg === 'A team needs a name.'
    && strpos($v, '<details class="rsl-roll-other" open>') !== false && strpos($v, 'id="rsl-apply" open') !== false, $msg);
$check('...once', strpos($view($p1), '<details class="rsl-roll-other" open>') === false);
$check('...it comes back to #rsl-apply, the card it came from', $to === RSL_Portal::url() . '#rsl-apply', $to);
list($to, $msg) = $front($p1, array('do' => 'apply', 'team_season' => $S, 'team_name' => '', 'team_level' => 'social', 'apply_id' => 'rsl-apply-' . $S));
list($to2, $msg2) = $front($p1, array('do' => 'apply', 'team_season' => $S, 'team_name' => '', 'team_level' => 'social', 'apply_id' => 'rsl-apply-nope'));
$view($p1); // takes the draft
$check('...or to rsl-apply-<season> when the card in a box had that id; anything else is #rsl-apply', $to === RSL_Portal::url() . '#rsl-apply-' . $S
    && $to2 === RSL_Portal::url() . '#rsl-apply', $to . ' / ' . $to2);

$fa = RSL_Free_Agents::join($p3, $S, array('division_pref' => 'either', 'browsable' => 1), true);
$check('no card for a player on the next season\'s free agent list', !is_wp_error($fa) && RSL_Teams::roll_state($old2, $p3) === null
    && strpos($view($p3), $card_id($old2)) === false, is_wp_error($fa) ? $fa->get_error_message() : '');

$set_dates($A, $day(3), $day(30));
$check('no card before the team\'s season has started (its first night still to come)', strpos($view($cap), $card_id($old)) === false
    && RSL_Teams::roll_state($old, $cap) === null && RSL_Teams::next_season_for($old) === $S);
$clear_dates($A);
$check('...nor while the team\'s season has no dates', strpos($view($p1), $card_id($old)) === false && !RSL_Seasons::has_started($A) && RSL_Teams::next_season_for($old) === $S);
$set_dates($A, $day(-14), $day(30));

// Two seasons on the page: still playing 2098 Post-season, signing up 2099 Pre-season.
RSL_Seasons::set_signup($S);
$v = $view($cap);
$at = strpos($v, $card_id($old));
$next_head = strpos($v, '">Next season <span');
$check('two seasons on the page: the card heads the Next season part, and that season\'s ways onto a team sit in its box, once each',
    $at !== false && $next_head !== false && $at > $next_head && strpos($v, $box) > $at && strpos($v, '>Enter the team again</button>') !== false
    && substr_count($v, 'id="rsl-apply"') === 1 && substr_count($v, 'Got an invite code?') === 1
    && substr_count($v, 'id="rsl-fa-find"') === 1 && strpos($v, 'id="rsl-fa-find"') > strpos($v, $box) && strpos($v, 'value="strong" selected') === false,
    'card ' . var_export($at, true) . ', heading ' . var_export($next_head, true));
RSL_Seasons::set_signup($A);

// --- roll_over(): every refusal -----------------------------------------------------------------------------------
$code = function ($r) { return is_wp_error($r) ? $r->get_error_code() : (is_object($r) && isset($r->id) ? 'made ' . $r->id : wp_json_encode($r)); };
$r = RSL_Teams::roll_over($old->id, $stranger);
$check('refused: someone not on the team', $code($r) === 'rsl_not_captain' && $r->get_error_message() === 'Only the captain can enter the team again.', $code($r));
$check('refused: a player who isn\'t the captain', $code(RSL_Teams::roll_over($old->id, $p1)) === 'rsl_not_captain');
$check('refused: a team that doesn\'t exist', $code(RSL_Teams::roll_over(0, $cap)) === 'rsl_team');
$pend = RSL_Teams::create($A, array('name' => "Pending $suffix", 'status' => 'pending'));
RSL_Teams::add_member($pend->id, $stranger, 'captain', true);
$check('refused: a team that isn\'t active (an application)', $code(RSL_Teams::roll_over($pend->id, $stranger)) === 'rsl_team');
$clear_dates($S);
$r = RSL_Teams::roll_over($old->id, $cap);
$check('refused: the season after isn\'t open for sign-up', $code($r) === 'rsl_roll_closed' && $r->get_error_message() === '2099 Pre-season isn\'t open for sign-up yet.', $code($r));
$set_dates($S, $day(40), $day(100));
$set_dates($A, $day(3), $day(30));
$r = RSL_Teams::roll_over($old->id, $cap);
$check('refused: the team\'s season hasn\'t started', $code($r) === 'rsl_roll_early' && $r->get_error_message() === $name . ' can be entered again once 2098 Post-season has started.', $code($r));
$clear_dates($A);
$check('...nor while it has no dates', $code(RSL_Teams::roll_over($old->id, $cap)) === 'rsl_roll_early');
$set_dates($A, $day(-14), $day(30));
$schema_behind = function () { return '11'; };
add_filter('pre_option_' . RSL_Database::SCHEMA_OPTION, $schema_behind);
$r = RSL_Teams::roll_over($old->id, $cap);
$none = RSL_Teams::rolled_into($old->id, $S);
remove_filter('pre_option_' . RSL_Database::SCHEMA_OPTION, $schema_behind);
$schema_behind = null;
$check('refused while the schema upgrade is still to finish (and rolled_into() reads nothing then)', $code($r) === 'rsl_busy' && $none === null, $code($r));
$check('refused by apply(): a team with no level needs one picked', $code(RSL_Teams::roll_over($old2->id, $cap2, '')) === 'rsl_level');
$check('...a word that isn\'t one of the levels is no level', $code(RSL_Teams::roll_over($old2->id, $cap2, 'elite')) === 'rsl_level');
RSL_Teams::add_member($other->id, $cap2, 'player', false, array('admin' => true));
$r = RSL_Teams::roll_over($old2->id, $cap2, 'social');
$check('refused by apply(): the captain is on a team in the season after already, in words naming that season', $code($r) === 'rsl_elsewhere'
    && $r->get_error_message() === "You're already on Other $suffix for 2099 Pre-season.", is_wp_error($r) ? $r->get_error_message() : $code($r));
$r = RSL_Teams::roll_over($old2->id, $admin, 'social');
$check('...an admin sending it hears it of the captain', $code($r) === 'rsl_elsewhere' && $r->get_error_message() === "The captain is already on Other $suffix for 2099 Pre-season.",
    is_wp_error($r) ? $r->get_error_message() : $code($r));
RSL_Teams::remove_member($other->id, $cap2);
$side = RSL_Teams::apply($S, $cap2, array('name' => "Side $suffix", 'level' => 'social'));
$check('refused by apply(): the captain has an application waiting there', $side && !is_wp_error($side) && $code(RSL_Teams::roll_over($old2->id, $cap2, 'social')) === 'rsl_pending');
RSL_Teams::withdraw($side->id, $cap2);
foreach (array('w1', 'w2') as $w) {
    $wpdb->insert(RSL_Database::table('teams'), array('season' => $S, 'name' => "Withdrawn $w $suffix", 'slug' => "withdrawn-$w-$suffix", 'status' => 'withdrawn',
        'applied_by' => $cap2, 'created_at' => current_time('mysql')));
}
$r = RSL_Teams::roll_over($old2->id, $cap2, 'social');
$check('refused by apply(): three applications withdrawn there, in words naming that season', $code($r) === 'rsl_withdrawn'
    && $r->get_error_message() === "You've withdrawn 3 applications for 2099 Pre-season — talk to the league to enter the team again.", is_wp_error($r) ? $r->get_error_message() : $code($r));
foreach (array('w1', 'w2') as $w) {
    $wpdb->delete(RSL_Database::table('teams'), array('season' => $S, 'slug' => "withdrawn-$w-$suffix"));
}
$dup = RSL_Teams::create($S, array('name' => $name2, 'division' => 'unranked', 'status' => 'active'));
$r = RSL_Teams::roll_over($old2->id, $cap2, 'social');
$check('refused by apply(): a team with that name in the season after already, in words naming that season (not this season)', $dup && !is_wp_error($dup)
    && $code($r) === 'rsl_duplicate' && $r->get_error_message() === 'There\'s already a team called "' . $name2 . '" in 2099 Pre-season — ask the league.',
    is_wp_error($r) ? $r->get_error_message() : $code($r));
if ($dup && !is_wp_error($dup)) {
    $wpdb->delete(RSL_Database::table('teams'), array('id' => $dup->id));
}
// A team whose captain has left: an admin's entry is refused rather than sent with nobody as the applicant.
$orphan = RSL_Teams::create($A, array('name' => "Orphan $suffix", 'division' => 'unranked', 'status' => 'active'));
$r = $orphan && !is_wp_error($orphan) ? RSL_Teams::roll_over($orphan->id, $admin, 'social') : null;
$check('refused: a team with no captain', $code($r) === 'rsl_no_captain' && $r->get_error_message() === "Orphan $suffix has no captain to enter it again — ask the league."
    && RSL_Teams::rolled_into($orphan->id, $S) === null, $code($r));
$check('...and no refusal left an application behind', RSL_Teams::rolled_into($old->id, $S) === null && RSL_Teams::rolled_into($old2->id, $S) === null
    && !RSL_Teams::pending_application_for_user($cap, $S) && !RSL_Teams::pending_application_for_user($cap2, $S));

// --- An admin, then the captain from the league page ---------------------------------------------------------------
$app2 = RSL_Teams::roll_over($old2->id, $admin, 'social');
$check('an admin may enter a team again: the captain is the applicant, with the level given and the team\'s preferences', is_object($app2) && !is_wp_error($app2)
    && intval($app2->applied_by) === $cap2 && RSL_Teams::is_captain($app2->id, $cap2) && !RSL_Teams::is_member($app2->id, $admin)
    && $app2->season === $S && $app2->status === 'pending' && $app2->est_level === 'social' && intval($app2->rolled_from) === intval($old2->id)
    && $app2->division === 'unranked' && $app2->pref_time === 'any' && (int) $app2->pref_double === 0 && $app2->waiting_list === false, $code($app2));
if (is_object($app2) && !is_wp_error($app2)) {
    RSL_Teams::withdraw($app2->id, $cap2);
}
$check('rolled_into(): a withdrawn one doesn\'t count', RSL_Teams::rolled_into($old2->id, $S) === null);
list($to, $msg) = $front($cap2, array('do' => 'roll_over', 'team_id' => $old2->id, 'team_level' => 'solid'));
$app2 = RSL_Teams::rolled_into($old2->id, $S);
$check('the captain enters it from the league page: the flash says it\'s in, back to the league page', $app2 && $app2->est_level === 'solid' && intval($app2->applied_by) === $cap2
    && $to === RSL_Portal::url() && $msg === $name2 . ' is entered again for 2099 Pre-season. We\'ll email you once the league approves it, and your players get their invites then.', $msg . ' / ' . $to);
list($to, $msg) = $front($p1, array('do' => 'roll_over', 'team_id' => $old->id));
$check('a player can\'t enter it from the page', $msg === 'Only the team captain can do that.' && RSL_Teams::rolled_into($old->id, $S) === null, $msg);

// A full division (Most teams per division 1 for Ranked, one Ranked team there already) never refuses it.
$full = RSL_Teams::create($S, array('name' => "Full $suffix", 'division' => 'ranked', 'status' => 'active'));
$fa_now = array_merge($fa_base, array('capacity_override' => array('ranked' => 1, 'unranked' => 0)));
$v = $view($cap);
$full_at = strpos($v, 'Ranked is full for 2099 Pre-season, so ' . $name . ' goes on the waiting list.');
$check('a full division: the captain\'s card says so above the button, before it\'s sent', $full_at !== false && $full_at > strpos($v, $card_id($old))
    && $full_at < strpos($v, '>Enter the team again</button>'));
list($to, $msg) = $front($cap, array('do' => 'roll_over', 'team_id' => $old->id));
$new = RSL_Teams::rolled_into($old->id, $S);
$waiting = $new ? RSL_Capacity::waiting_note($new, false) : '';
$check('the captain enters it again: an application for the season after, with them as captain', $new && $new->status === 'pending' && $new->season === $S
    && intval($new->applied_by) === $cap && RSL_Teams::is_captain($new->id, $cap) && $to === RSL_Portal::url(), $msg);
$check('...with the name, division, colour and level, and rolled_from', $new && $new->name === $name && $new->division === 'ranked' && $new->colour === '#8c2361'
    && $new->est_level === 'strong' && intval($new->rolled_from) === intval($old->id));
$check('...and the team preferences', $new && $new->pref_time === 'late' && (int) $new->pref_double === 1 && $new->pref_double_shape === 'break' && (int) $new->pref_standby === 1);
$check('...a full division puts it on the waiting list, and the flash says so', $new && RSL_Capacity::on_waiting_list($new) && $waiting !== ''
    && strpos($waiting, 'waiting list') !== false && $msg === $name . ' is entered again for 2099 Pre-season. ' . $waiting, $msg);
list($to2, $msg2) = $front($cap, array('do' => 'roll_over', 'team_id' => $old->id));
$count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . RSL_Database::table('teams') . " WHERE rolled_from = %d", $old->id));
$check('the same form sent again: its success, and still one application', $msg2 === $msg && $count === 1, $msg2);
$fa_now = $fa_base;
$r = RSL_Teams::roll_over($old->id, $cap);
$check('refused: already entered again for that season', $code($r) === 'rsl_rolled' && $r->get_error_message() === $name . ' is already entered again for 2099 Pre-season.', $code($r));
$check('rolled_into(): the application for that season, none for another season or from the new team', $new && intval(RSL_Teams::rolled_into($old->id, $S)->id) === intval($new->id)
    && RSL_Teams::rolled_into($old->id, $A) === null && RSL_Teams::rolled_into($new->id, $S) === null);
// The schema guard again, now that there is a team entered again for it to find.
$schema_behind = function () { return '11'; };
add_filter('pre_option_' . RSL_Database::SCHEMA_OPTION, $schema_behind);
$none = RSL_Teams::rolled_into($old->id, $S);
$rp_behind = RSL_Teams::roll_state($old, $p1);
remove_filter('pre_option_' . RSL_Database::SCHEMA_OPTION, $schema_behind);
$schema_behind = null;
$check('...and nothing while the schema upgrade is still to finish, though one is there (the player\'s card then says to ask the captain)', $new && $none === null
    && is_array($rp_behind) && $rp_behind['state'] === 'player', wp_json_encode($rp_behind ? $rp_behind['state'] : null));

// --- The league page while it waits for approval ------------------------------------------------------------------
$v = $view($p1);
$rp = RSL_Teams::roll_state($old, $p1);
$check('player, while it waits for approval: the captain has entered it, the invite comes once approved', strpos($v, $card_id($old)) !== false
    && strpos($v, 'Your captain has entered ' . $name . ' again for 2099 Pre-season. You\'ll get an invite to accept once the league approves it.') !== false
    && strpos($v, $box) !== false && $roll_form($v) === '' && is_array($rp) && $rp['state'] === 'pending');
$v = $view($cap);
$check('captain, once entered again: no card; the application shows under Later season, never the level', strpos($v, $card_id($old)) === false
    && RSL_Teams::roll_state($old, $cap) === null && strpos($v, 'Your application for ' . $name . ' is waiting for approval.') !== false && strpos($v, esc_html($strong)) === false);
$check('...and it says the players get their invites once it\'s approved, not to invite them', strpos($v, 'We\'ll email you when it\'s approved, and your players get their invites then.') !== false
    && strpos($v, 'then you can invite your players') === false);

$admin_page = function () use ($A, $admin) {
    wp_set_current_user($admin);
    $_GET = array('page' => RSL_Admin::PAGE, 'season' => $A);
    ob_start();
    (new RSL_Admin())->render();
    $html = ob_get_clean();
    delete_transient(RSL_Admin::NOTICE_PREFIX . $admin);
    $_GET = array();
    return $html;
};
$html = $admin_page();
$check('the Teams screen: an application entered again says from which team', strpos($html, esc_html('Entered again from ' . $name . ' (2098 Post-season)')) !== false
    && strpos($html, esc_html('Entered again from ' . $name2 . ' (2098 Post-season)')) !== false);

// A declined entry: the card says so above its box (never only inside it), and offers the form again.
$dec = $app2 ? RSL_Teams::decline($app2->id, 'Unranked is full') : null;
$v = $view($cap2);
$said = strpos($v, 'The league didn\'t approve ' . $name2 . ' for 2099 Pre-season: Unranked is full</div>');
$rs2 = RSL_Teams::roll_state($old2, $cap2);
$check('declined: the captain\'s card says the league didn\'t approve it, above the box, with the form again', $dec && !is_wp_error($dec) && $said !== false
    && $said > strpos($v, $card_id($old2)) && $said < strpos($v, '<details class="rsl-roll-other">') && strpos($v, 'wasn\'t approved') === false
    && $roll_form($v) !== '' && is_array($rs2) && $rs2['state'] === 'captain', is_wp_error($dec) ? $dec->get_error_message() : '');
$check('...a second decline (or a withdraw) finds nothing pending to change', is_wp_error(RSL_Teams::decline($app2->id, 'again'))
    && is_wp_error(RSL_Teams::withdraw($app2->id, $cap2)) && RSL_Teams::get($app2->id)->decision_note === 'Unranked is full');

// --- Approval invites last season's players ---------------------------------------------------------------------
// Already invited (an email invite waiting), and on another team in the season after: both left out.
$wpdb->insert(RSL_Database::table('invites'), array('team_id' => $new->id, 'user_id' => $p2, 'email' => "rslr-p2-$suffix@example.test", 'invited_by' => $cap,
    'source' => 'email', 'created_at' => current_time('mysql')));
RSL_Teams::add_member($other->id, $pe, 'player', false, array('admin' => true));
$check('no card for a player already on a team in the season after', strpos($view($pe), $card_id($old)) === false && RSL_Teams::roll_state($old, $pe) === null);
$adm = function ($fields) use ($S, $admin) {
    wp_set_current_user($admin);
    delete_transient(RSL_Admin::NOTICE_PREFIX . $admin);
    RSL_Admin::process(array_merge(array('rsl_nonce' => wp_create_nonce('rsl_team_action'), 'season' => $S), $fields));
    $n = get_transient(RSL_Admin::NOTICE_PREFIX . $admin);
    delete_transient(RSL_Admin::NOTICE_PREFIX . $admin);
    return is_array($n) ? $n : array('type' => '', 'message' => '');
};
// The captain's approval email as plain text, "sent" (answered true after the hold's false, so nothing leaves).
$no_look = '__return_false';
add_filter('rsl_mail_club_look', $no_look);
$captured = array();
$capture = function ($return, $atts) use (&$captured) { $captured[] = $atts; return true; };
add_filter('pre_wp_mail', $capture, 1000, 2);
$n = $adm(array('do' => 'approve', 'team_id' => $new->id));
remove_filter('pre_wp_mail', $capture, 1000);
remove_filter('rsl_mail_club_look', $no_look);
$capture = null;
$no_look = null;
$approved = RSL_Teams::get($new->id);
$rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . RSL_Database::table('invites') . " WHERE team_id = %d AND source = 'rollover' ORDER BY user_id", $new->id));
$ids = array_map(function ($r) { return (int) $r->user_id; }, $rows);
$want = array($p1, $p4);
sort($want);
$check('approving invites last season\'s players: not the captain, one on another team there, one who left, or one invited already', $approved->status === 'active'
    && $ids === $want, wp_json_encode($ids));
$shape = (bool) $rows;
foreach ($rows as $r) {
    $u = get_userdata($r->user_id);
    $shape = $shape && $r->status === 'pending' && intval($r->invited_by) === $cap && $r->expires_at === null && $u && $r->email === $u->user_email;
}
$check('...each invite from the captain, to the account\'s email, with no expiry', $shape, wp_json_encode($rows));
$check('...the player with an invite waiting keeps that one only', (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . RSL_Database::table('invites') . " WHERE team_id = %d AND user_id = %d", $new->id, $p2)) === 1);
$mail_rows = array();
foreach ($rows as $r) {
    $mail_rows[] = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . RSL_Database::table('outbox') . " WHERE dedupe = %s", 'rollinv:' . $r->id));
}
$mail_ok = count($mail_rows) === 2;
foreach ($mail_rows as $i => $m) {
    $u = get_userdata($rows[$i]->user_id);
    $mail_ok = $mail_ok && $m && $u && $m->to_email === $u->user_email && $m->sent_at === null && $m->subject === $name . ' is playing 2099 Pre-season'
        && strpos($m->body, 'Your captain has entered ' . $name . ' again for ' . $label_s . '.') !== false
        && strpos($m->body, "If you're not playing, decline it so your captain can find someone else.") !== false
        && strpos($m->body, "Accept or decline on your league page:\n" . RSL_Portal::url() . "\n") !== false;
}
$check('...one email each, queued: the team is playing that season, its dates, and the league page to answer on', $mail_ok, wp_json_encode($mail_rows));
$parked = (bool) $mail_rows;
foreach ($mail_rows as $m) {
    $now_row = $m ? $wpdb->get_row($wpdb->prepare("SELECT attempts, last_error, sent_at FROM " . RSL_Database::table('outbox') . " WHERE id = %d", $m->id)) : null;
    $parked = $parked && $now_row && (int) $now_row->attempts === RSL_Mail::MAX_ATTEMPTS && $now_row->last_error === RSL_DEV_MAIL_HELD && $now_row->sent_at === null;
}
$check('...parked by the test, so nothing sends them', $parked);
$cap_mail = null;
foreach ($captured as $atts) {
    if (is_array($atts) && isset($atts['subject']) && $atts['subject'] === 'Your team ' . $name . ' is approved') {
        $cap_mail = $atts;
    }
}
$check('the captain\'s approval email says how many were invited', $cap_mail && strpos((string) $cap_mail['message'], $name . ' is approved for the 2099 Pre-season (Summer) Reds Social League. 2 players from last season\'s team have been invited and join once they accept.') !== false,
    $cap_mail ? (string) $cap_mail['message'] : 'no email');
$check('...and its link is for anyone else', $cap_mail && strpos((string) $cap_mail['message'], "Invite anyone else with this link:\n") !== false
    && strpos((string) $cap_mail['message'], 'Invite your players with this link') === false);
$check('...one player is worded as one', RSL_Teams::invited_words(1) === '1 player from last season\'s team has been invited and joins once they accept.');
$check('the approve notice says how many players were invited', $n['type'] === 'success'
    && strpos($n['message'], $name . ' approved — the captain has been emailed their invite link. 2 players from last season\'s team were invited.') === 0, $n['message']);
$again = RSL_Teams::approve($new->id);
$more = RSL_Teams::invite_previous_players(RSL_Teams::get($new->id));
$invites_now = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . RSL_Database::table('invites') . " WHERE team_id = %d AND source = 'rollover'", $new->id));
$mails_now = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . RSL_Database::table('outbox') . " WHERE dedupe LIKE %s AND to_email LIKE %s", 'rollinv:%', '%-' . $suffix . '@example.test'));
$check('approving again is refused, and inviting again finds nobody new: no second invite or email', is_wp_error($again) && $more === 0 && $invites_now === 2 && $mails_now === 2,
    wp_json_encode(array($more, $invites_now, $mails_now)));
$labels = array();
foreach (RSL_Teams::pending_invites_for_team($new->id) as $i) {
    $labels[] = $i->label;
}
sort($labels);
$want_labels = array("RSLR p1 $suffix (last season)", "RSLR p4 $suffix (last season)", "rslr-p2-$suffix@example.test");
sort($want_labels);
$check('the captain sees them waiting by name with (last season); an email invite by its email', $labels === $want_labels
    && strpos(RSL_Portal::team_tools($approved, $cap), esc_html("RSLR p1 $suffix (last season)")) !== false, wp_json_encode($labels));

// --- Placement holds their places until they answer ----------------------------------------------------------------
$ctx = RSL_Placement::context($S);
$pn = null;
foreach ($ctx[1] as $pt) {
    if ((int) $pt['id'] === (int) $new->id) {
        $pn = $pt['n'];
    }
}
$check('placement counts the invites still waiting as places taken: the captain and the two invited (not the email invite)', $pn === 3, var_export($pn, true));
$min = intval(RSL_Free_Agents::settings()['min_roster']);
$pd = RSL_Placement::draft($S);
$check('...and the placement screen says how many from last season\'s team haven\'t answered', $min <= 1
    || in_array($name . ' will have 1 player (the minimum is ' . $min . '); 2 players from last season\'s team still have invites to answer.', $pd['warnings'], true),
    wp_json_encode($pd['warnings']));

// --- Accept and decline -------------------------------------------------------------------------------------------
$inv = array();
foreach ($rows as $r) {
    $inv[(int) $r->user_id] = (int) $r->id;
}
$v = $view($p1);
$check('player, once approved: the invite card with Accept and Decline instead of the card', strpos($v, $card_id($old)) === false
    && strpos($v, $name . ' would like you to join.') !== false && strpos($v, 'name="invite_id" value="' . $inv[$p1] . '"') !== false && RSL_Teams::roll_state($old, $p1) === null);
list($to, $msg) = $front($p1, array('do' => 'accept_invite', 'invite_id' => $inv[$p1]));
$check('accepting puts them on the team for the season after', $msg === 'You\'ve joined the team.' && RSL_Teams::is_member($new->id, $p1)
    && $wpdb->get_var($wpdb->prepare("SELECT status FROM " . RSL_Database::table('invites') . " WHERE id = %d", $inv[$p1])) === 'accepted'
    && strpos($view($p1), $card_id($old)) === false, $msg);
list($to, $msg) = $front($p4, array('do' => 'decline_invite', 'invite_id' => $inv[$p4]));
$check('declining leaves them off it', $msg === 'Invite declined.' && !RSL_Teams::is_member($new->id, $p4)
    && $wpdb->get_var($wpdb->prepare("SELECT status FROM " . RSL_Database::table('invites') . " WHERE id = %d", $inv[$p4])) === 'declined', $msg);
$v = $view($p4);
$rp = RSL_Teams::roll_state($old, $p4);
$check('...the card then says the team is playing that season and to ask the captain for an invite, with the box', strpos($v, $card_id($old)) !== false
    && strpos($v, $name . ' is playing 2099 Pre-season. To keep playing with them, ask your captain to invite you.') !== false && strpos($v, $box) !== false
    && is_array($rp) && $rp['state'] === 'rolled');
$check('the captain, on the new team: no card', RSL_Teams::roll_state($old, $cap) === null && strpos($view($cap), $card_id($old)) === false);

} catch (Throwable $thrown) {
    $fails++;
    echo 'FAIL: threw ' . get_class($thrown) . ': ' . $thrown->getMessage() . ' (line ' . $thrown->getLine() . ")\n";
} finally {
// --- Cleanup (runs after a failure too) --------------------------------------------------------------------------
wp_set_current_user(0);
$_GET = array();
if ($cands) {
    remove_filter('rsl_open_for_signup_candidates', $cands);
}
if ($fa_filter) {
    remove_filter('pre_option_' . RSL_Free_Agents::SETTINGS_OPTION, $fa_filter);
}
if ($schema_behind) {
    remove_filter('pre_option_' . RSL_Database::SCHEMA_OPTION, $schema_behind);
}
if ($capture) {
    remove_filter('pre_wp_mail', $capture, 1000);
}
if ($no_look) {
    remove_filter('rsl_mail_club_look', $no_look);
}
foreach (array($A, $S) as $s_key) {
    foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM " . RSL_Database::table('teams') . " WHERE season = %s", $s_key)) as $tid) {
        $wpdb->delete(RSL_Database::table('team_members'), array('team_id' => $tid));
        $wpdb->delete(RSL_Database::table('invites'), array('team_id' => $tid));
        $wpdb->delete(RSL_Database::table('join_requests'), array('team_id' => $tid));
    }
    $wpdb->delete(RSL_Database::table('teams'), array('season' => $s_key));
    $wpdb->delete(RSL_Database::table('free_agents'), array('season' => $s_key));
}
foreach ($users as $u) {
    if (is_int($u) && $u > 0) {
        $wpdb->delete(RSL_Database::table('team_members'), array('user_id' => $u));
        $wpdb->delete(RSL_Database::table('players'), array('user_id' => $u));
        $wpdb->delete(RSL_Database::table('free_agents'), array('user_id' => $u));
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
rsl_dev_delete_users($users); // the flashes, drafts and notices too
rsl_dev_release_mail($hold);
}
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
