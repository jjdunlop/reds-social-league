<?php
/**
 * Registration, social rego and team-building paths:
 *  - registering (free) vs being cleared to play (full/life member, or a
 *    12-month rego bought through WooCommerce or added by an admin);
 *  - captain applications: pending until an admin approves or declines;
 *  - joining with the invite code (instant) or by email invite (must accept);
 *  - privacy: captains see ✓/✗ only, never why; invitees aren't shown.
 *
 *   wp eval-file dev/tests/test-registration.php
 */

if (!defined('WP_CLI') || !WP_CLI) {
    exit("Run with wp eval-file.\n");
}
require_once ABSPATH . 'wp-admin/includes/user.php';
global $wpdb;

$season = '2091-pre';
$suffix = time();
$fails = 0;
$check = function ($label, $ok, $detail = '') use (&$fails) {
    if (!$ok) { $fails++; }
    echo ($ok ? "OK: $label\n" : "FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n");
};
$saved = array();
foreach (array(RSL_Seasons::CURRENT_OPTION, RSL_Rego::PRODUCT_OPTION) as $opt) { $saved[$opt] = get_option($opt, '__unset__'); }
// Regos made during this test are removed at the end — including any the
// past-order scan picks up for other (real, local) accounts.
$rego_id_before = (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM " . RSL_Database::table('regos'));
update_option(RSL_Seasons::CURRENT_OPTION, $season);
$sent = array();
$mail = function ($null, $atts) use (&$sent) { $sent[] = $atts; return true; };
add_filter('pre_wp_mail', $mail, 10, 2);

$users = array();
$mk = function ($tag, $role = 'subscriber') use ($suffix, &$users) {
    $u = wp_insert_user(array('user_login' => "rslr-$tag-$suffix", 'user_email' => "rslr-$tag-$suffix@example.test", 'user_pass' => wp_generate_password(),
        'display_name' => "RSLR $tag $suffix", 'role' => $role));
    $users[] = $u;
    return $u;
};
$reg = function ($u) { return RSL_Players::register($u, array('level' => 'social', 'years' => '1to3', 'positions' => array('setter', 'bogus'))); };
$today = current_time('Y-m-d');

// --- Registering ------------------------------------------------------------------
$cap = $mk('cap'); $p1 = $mk('p1'); $p2 = $mk('p2'); $p3 = $mk('p3'); $out = $mk('out'); $admin = $mk('admin', 'administrator');
$check('registration needs a level', is_wp_error(RSL_Players::register($cap, array('years' => 'lt1'))));
$check('registration needs years', is_wp_error(RSL_Players::register($cap, array('level' => 'vvl'))));
$check('register', $reg($cap) === true && RSL_Players::is_registered($cap));
$check('unknown positions dropped', RSL_Players::get($cap)->positions === 'setter');
$check('re-registering updates, never duplicates', RSL_Players::register($cap, array('level' => 'vvl', 'years' => 'gt3')) === true
    && RSL_Players::get($cap)->level === 'vvl' && (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . RSL_Database::table('players') . " WHERE user_id = %d", $cap)) === 1);
foreach (array($p1, $p2, $p3) as $u) { $reg($u); }

// --- Cleared to play -----------------------------------------------------------------
$check('not cleared with no rego or membership', RSL_Rego::clearance($p1)['cleared'] === false);
$mem = $wpdb->prefix . 'team_memberships';
$have_cm = class_exists('TeamOversight_Memberships') && method_exists('TeamOversight_Memberships', 'covered_until');
$check('Club Manager provides covered_until', $have_cm);
if ($have_cm) {
    $wpdb->insert($mem, array('user_id' => $p1, 'tier' => 'full-member', 'start_date' => date('Y-m-d', strtotime('-30 days')), 'end_date' => date('Y-m-d', strtotime('+60 days')), 'source' => 'manual'));
    $wpdb->insert($mem, array('user_id' => $p1, 'tier' => 'full-member', 'start_date' => date('Y-m-d', strtotime('+61 days')), 'end_date' => date('Y-m-d', strtotime('+200 days')), 'source' => 'manual'));
    $c = RSL_Rego::clearance($p1);
    $check('full member: cleared by membership, no rego', $c['cleared'] && $c['by'] === 'member');
    $check('back-to-back membership grants count as one run', $c['until'] === date('Y-m-d', strtotime('+200 days')), (string) $c['until']);
    $wpdb->insert($mem, array('user_id' => $p2, 'tier' => 'associate-member', 'start_date' => date('Y-m-d', strtotime('-30 days')), 'end_date' => date('Y-m-d', strtotime('+60 days')), 'source' => 'manual'));
    $check('associate member: still needs the rego', RSL_Rego::clearance($p2)['cleared'] === false);
    $wpdb->insert($mem, array('user_id' => $p3, 'tier' => 'life-member', 'start_date' => '2000-01-01', 'end_date' => '2099-12-31', 'source' => 'manual'));
    $check('life member: cleared', RSL_Rego::clearance($p3)['by'] === 'member' && RSL_Rego::describe(RSL_Rego::clearance($p3)) === 'Full/life member (no expiry)');
    $check('lapsed membership: not cleared after it ends', RSL_Rego::clearance($p1, date('Y-m-d', strtotime('+201 days')))['cleared'] === false);
}
$g = RSL_Rego::grant($p2, array('start' => $today, 'source' => 'manual', 'note' => 'cash'));
$c = RSL_Rego::clearance($p2);
$check('manual rego: cleared for 12 months', $g > 0 && $c['by'] === 'rego' && $c['until'] === date('Y-m-d', strtotime($today . ' +1 year -1 day')), (string) $c['until']);
RSL_Rego::grant($p2, array('start' => $today, 'source' => 'purchase'));
$check('renewing early starts when the current rego ends', RSL_Rego::rego_valid_until($p2) === date('Y-m-d', strtotime($today . ' +2 years -1 day')));
$check('rego covers future dates within its run, not after', RSL_Rego::clearance($p2, date('Y-m-d', strtotime($today . ' +18 months')))['cleared']
    && !RSL_Rego::clearance($p2, date('Y-m-d', strtotime($today . ' +25 months')))['cleared']);

// WooCommerce purchase -> rego, once.
$product_id = 0;
foreach (wc_get_products(array('type' => 'simple', 'status' => 'publish', 'limit' => 50)) as $prod) {
    if ($prod->is_purchasable()) { $product_id = $prod->get_id(); break; }
}
update_option(RSL_Rego::PRODUCT_OPTION, $product_id);
$order = wc_create_order(array('customer_id' => $cap));
$order->add_product(wc_get_product($product_id), 1);
$order->calculate_totals();
$order->set_status('processing');
$order->save();
$check('paid order: 12-month rego for the buyer', RSL_Rego::clearance($cap)['by'] === 'rego' && RSL_Rego::rego_valid_until($cap) === date('Y-m-d', strtotime($today . ' +1 year -1 day')), (string) RSL_Rego::rego_valid_until($cap));
$order->set_status('completed');
$order->save();
$check('processing then completed grants once', (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . RSL_Database::table('regos') . " WHERE user_id = %d", $cap)) === 1);
RSL_Rego::scan_orders();
$check('past-order scan doesn\'t grant the same order twice', (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . RSL_Database::table('regos') . " WHERE user_id = %d", $cap)) === 1);
$check('running the scan again adds nothing', RSL_Rego::scan_orders() === 0);
$check('pay link goes to checkout with the rego', strpos(RSL_Rego::pay_url(), 'add-to-cart=' . $product_id) !== false);

// --- Captain applications ---------------------------------------------------------------
$check('unregistered people can\'t apply', is_wp_error(RSL_Teams::apply($season, $out, array('name' => "X $suffix", 'level' => 'social'))));
$check('applying needs a level', ($e = RSL_Teams::apply($season, $cap, array('name' => "No Level $suffix"))) && is_wp_error($e) && $e->get_error_code() === 'rsl_level');
$app = RSL_Teams::apply($season, $cap, array('name' => "Spikes $suffix", 'division' => 'ranked', 'colour' => '#8c2361', 'level' => 'strong'));
$check('captain applies: team pending, captain on it', !is_wp_error($app) && $app->status === 'pending' && RSL_Teams::is_captain($app->id, $cap));
$check('pending team isn\'t public', !in_array("Spikes $suffix", wp_list_pluck(RSL_Teams::list_for_season($season), 'name'), true));
$check('no invites before approval', is_wp_error(RSL_Teams::invite_by_email($app->id, $cap, "rslr-p1-$suffix@example.test")));
$check('nobody can join a pending team with its code', RSL_Teams::get_by_code($app->invite_code) === null);
$check('a pending captain can\'t join another team', ($e = RSL_Teams::why_cant_join($cap, $season)) && $e->get_error_code() === 'rsl_pending');
$check('the level is stored with the application', $app->est_level === 'strong');
$check('one application at a time', is_wp_error(RSL_Teams::apply($season, $cap, array('name' => "Second $suffix", 'level' => 'social'))));
$check('withdraw frees the captain', RSL_Teams::withdraw($app->id, $cap) === true && RSL_Teams::why_cant_join($cap, $season) === null && !RSL_Teams::is_member($app->id, $cap));
$app = RSL_Teams::apply($season, $cap, array('name' => "Spikes Again $suffix", 'division' => 'ranked', 'level' => 'solid'));
wp_set_current_user($admin);
$sent = array();
RSL_Admin::process(array('rsl_nonce' => wp_create_nonce('rsl_team_action'), 'season' => $season, 'do' => 'approve', 'team_id' => $app->id));
$team = RSL_Teams::get($app->id);
$check('admin approves: team active', $team->status === 'active');
$check('captain emailed the invite link', count($sent) === 1 && $sent[0]['to'] === "rslr-cap-$suffix@example.test" && strpos($sent[0]['message'], 'join=' . $team->invite_code) !== false);
$declined_by = $mk('dec'); $reg($declined_by);
$app2 = RSL_Teams::apply($season, $declined_by, array('name' => "Nope $suffix", 'level' => 'new'));
$sent = array();
RSL_Admin::process(array('rsl_nonce' => wp_create_nonce('rsl_team_action'), 'season' => $season, 'do' => 'decline', 'team_id' => $app2->id, 'note' => wp_slash("Name's already taken")));
$check('admin declines with a reason, captain emailed it', RSL_Teams::get($app2->id)->status === 'declined' && strpos($sent[0]['message'], "Name's already taken") !== false);
$check('declined captain is free and sees why', RSL_Teams::why_cant_join($declined_by, $season) === null && RSL_Teams::last_decided_application($declined_by, $season)->decision_note === "Name's already taken");

// --- Joining with the code ---------------------------------------------------------------
$check('unregistered can\'t join with a code', is_wp_error(RSL_Teams::join_by_code($out, $team->invite_code)));
$check('bad code refused', is_wp_error(RSL_Teams::join_by_code($p3, 'NOPE1234')));
$joined = RSL_Teams::join_by_code($p3, strtolower($team->invite_code));
$check('join with the code (any case): straight onto the team', !is_wp_error($joined) && RSL_Teams::is_member($team->id, $p3));
$old = $team->invite_code;
RSL_Teams::regenerate_code($team->id);
$check('a new code stops the old link', is_wp_error(RSL_Teams::join_by_code($p1, $old)));

// --- Invites by email -----------------------------------------------------------------------
$check('invite an unregistered email: told to send the link', ($e = RSL_Teams::invite_by_email($team->id, $cap, "rslr-out-$suffix@example.test")) && is_wp_error($e) && strpos($e->get_error_message(), 'invite link') !== false);
$check('only the captain can invite', is_wp_error(RSL_Teams::invite_by_email($team->id, $p3, "rslr-p1-$suffix@example.test")));
$sent = array();
$inv = RSL_Teams::invite_by_email($team->id, $cap, "rslr-p1-$suffix@example.test");
$check('invite a registered player: pending, not on the team yet', is_int($inv) && !RSL_Teams::is_member($team->id, $p1));
$check('the player is emailed', count($sent) === 1 && $sent[0]['to'] === "rslr-p1-$suffix@example.test");
$check('no double invites', is_wp_error(RSL_Teams::invite_by_email($team->id, $cap, "rslr-p1-$suffix@example.test")));
$team_invites = RSL_Teams::pending_invites_for_team($team->id);
$check('captain sees only the email they typed', count($team_invites) === 1 && $team_invites[0]->email === "rslr-p1-$suffix@example.test" && !isset($team_invites[0]->display_name));
$check('the player sees the invite', count(RSL_Teams::pending_invites_for_user($p1)) === 1);
$check('someone else can\'t accept it', is_wp_error(RSL_Teams::respond_invite($inv, $p2, true)));
$check('accepting puts them on the team', RSL_Teams::respond_invite($inv, $p1, true) === true && RSL_Teams::is_member($team->id, $p1));
$reg($p2);
$inv2 = RSL_Teams::invite_by_email($team->id, $cap, "rslr-p2-$suffix@example.test");
$check('declining leaves them off', RSL_Teams::respond_invite($inv2, $p2, false) === true && !RSL_Teams::is_member($team->id, $p2));
$inv3 = RSL_Teams::invite_by_email($team->id, $cap, "rslr-p2-$suffix@example.test");
$check('captain can cancel an invite', RSL_Teams::cancel_invite($inv3, $cap) === true && count(RSL_Teams::pending_invites_for_user($p2)) === 0);

// --- The league page ----------------------------------------------------------------------------
$portal = new RSL_Portal();
RSL_Portal::ensure_page();
$page = function ($as, $join = '') use ($portal) {
    wp_set_current_user($as);
    $_GET = $join !== '' ? array('join' => $join) : array();
    return $portal->render();
};
$code = RSL_Teams::get($team->id)->invite_code;
$html = $page(0, $code);
$check('logged out with an invite: team named, log in offered', strpos($html, "invited to join Spikes Again $suffix") !== false && strpos($html, 'Log in') !== false);
$new = $mk('new');
$html = $page($new, $code);
$check('logged in, not registered: registration form that joins the team', strpos($html, 'name="do" value="register"') !== false && strpos($html, 'Register and join') !== false && strpos($html, 'value="' . $code . '"') !== false);
wp_set_current_user($new);
RSL_Portal::process(array('rsl_nonce' => wp_create_nonce('rsl_front'), 'do' => 'register', 'level' => 'never', 'years' => 'lt1', 'join_code' => $code));
$check('registering through the invite joins the team', RSL_Players::is_registered($new) && RSL_Teams::is_member($team->id, $new));
$html = $page($new);
$check('not cleared: told to pay, with the pay link', strpos($html, 'You need a social rego before your first game') !== false && strpos($html, 'add-to-cart=' . $product_id) !== false);
$cap_view = $page($cap);
$roster = substr($cap_view, strpos($cap_view, '<h4>Players'), strpos($cap_view, '<h4>Add players') - strpos($cap_view, '<h4>Players'));
$check('captain: teammates show cleared / needs rego', strpos($roster, '&#10003; Cleared') !== false && strpos($roster, '&#10007; Needs rego') !== false);
$check('captain never sees why (membership vs rego)', stripos($roster, 'Full/life') === false && stripos($roster, 'membership') === false && strpos($roster, 'Rego paid') === false && strpos($roster, 'until') === false);
$check('captain tools: invite link and email invite', strpos($cap_view, 'join=' . $code) !== false && strpos($cap_view, 'name="do" value="invite"') !== false);
$p3_view = $page($p3);
$check('a player (not captain) sees teammates but no statuses or tools', strpos($p3_view, "RSLR p1 $suffix") !== false && strpos($p3_view, 'Needs rego</span>') === false && strpos($p3_view, 'value="invite"') === false);
wp_set_current_user($p3);
RSL_Portal::process(array('rsl_nonce' => wp_create_nonce('rsl_front'), 'do' => 'remove_member', 'team_id' => $team->id, 'user_id' => $p1));
$check('non-captain can\'t remove players (server-side)', RSL_Teams::is_member($team->id, $p1));
wp_set_current_user($cap);
RSL_Portal::process(array('rsl_nonce' => wp_create_nonce('rsl_front'), 'do' => 'remove_member', 'team_id' => $team->id, 'user_id' => $cap));
$check('captain can\'t remove themselves', RSL_Teams::is_member($team->id, $cap));
RSL_Portal::process(array('rsl_nonce' => wp_create_nonce('rsl_front'), 'do' => 'remove_member', 'team_id' => $team->id, 'user_id' => $p1));
$check('captain removes a player', !RSL_Teams::is_member($team->id, $p1));
RSL_Portal::process(array('rsl_nonce' => 'bogus', 'do' => 'new_code', 'team_id' => $team->id));
$check('bad nonce refused', RSL_Teams::get($team->id)->invite_code === $code);
$free = $mk('free'); $reg($free);
wp_set_current_user($free);
RSL_Portal::process(array('rsl_nonce' => wp_create_nonce('rsl_front'), 'do' => 'looking', 'looking' => '1'));
$check('free agent list', (int) RSL_Players::get($free)->looking === 1);

// --- Admin ---------------------------------------------------------------------------------------
wp_set_current_user($admin);
RSL_Teams::apply($season, $declined_by, array('name' => "Pending One $suffix", 'level' => 'social'));
$_GET = array('page' => 'rsl-teams', 'season' => $season);
ob_start(); (new RSL_Admin())->render(); $admin_html = ob_get_clean();
$check('admin sees pending applications to decide', strpos($admin_html, 'Team applications') !== false && strpos($admin_html, "Pending One $suffix") !== false && strpos($admin_html, 'value="approve"') !== false);
$_GET = array('page' => 'rsl-teams', 'season' => $season, 'team' => $team->id);
ob_start(); (new RSL_Admin())->render(); $team_admin = ob_get_clean();
$check('admin roster says why someone is cleared', strpos($team_admin, 'Rego paid') !== false || strpos($team_admin, 'Full/life member') !== false);
RSL_Admin::process(array('rsl_nonce' => wp_create_nonce('rsl_team_action'), 'season' => $season, 'do' => 'grant_rego', 'who' => "rslr-free-$suffix@example.test", 'start' => $today, 'note' => 'cash'));
$check('admin adds a rego by hand', RSL_Rego::clearance($free)['by'] === 'rego');
ob_start(); (new RSL_Admin())->render_registration(); $reg_page = ob_get_clean();
$check('registration page renders with the product chosen', strpos($reg_page, 'Rego product') !== false && strpos($reg_page, 'value="' . $product_id . '"  selected=\'selected\'') !== false, substr($reg_page, strpos($reg_page, 'value="' . $product_id . '"'), 60));

// --- Cleanup ---------------------------------------------------------------------------------------
wp_set_current_user(0);
$_GET = array();
remove_filter('pre_wp_mail', $mail, 10);
foreach (array($order->get_id()) as $oid) { $o = wc_get_order($oid); if ($o) { $o->delete(true); } }
$team_ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM " . RSL_Database::table('teams') . " WHERE season = %s", $season));
foreach ($team_ids as $tid) {
    $wpdb->delete(RSL_Database::table('team_members'), array('team_id' => $tid));
    $wpdb->delete(RSL_Database::table('invites'), array('team_id' => $tid));
}
$wpdb->delete(RSL_Database::table('teams'), array('season' => $season));
$wpdb->query($wpdb->prepare("DELETE FROM " . RSL_Database::table('regos') . " WHERE id > %d", $rego_id_before));
foreach ($users as $u) {
    foreach (array('players', 'regos', 'team_members', 'invites') as $t) { $wpdb->delete(RSL_Database::table($t), array('user_id' => $u)); }
    $wpdb->delete($mem, array('user_id' => $u));
    delete_transient(RSL_Portal::FLASH_PREFIX . $u);
    delete_transient(RSL_Admin::NOTICE_PREFIX . $u);
    wp_delete_user($u);
}
foreach ($saved as $opt => $value) { if ($value === '__unset__') { delete_option($opt); } else { update_option($opt, $value); } }
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
