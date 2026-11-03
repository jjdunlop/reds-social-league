<?php
/**
 * Account merges (0.14.0): the Club Manager's Duplicate accounts tool
 * refuses a merge while an account being merged away is in use in the
 * league, and a merge never changes a league row.
 *
 *  e) Schema guard: every user_id / *_by column in class-rsl-database.php
 *     is in RSL_Merge::columns() (and every listed column exists), so a
 *     new table or column can't be forgotten.
 *  a) RSL_Merge::uses() is empty for a fresh account and names every kind
 *     of row: one row in each column of RSL_Merge::columns() in turn, a
 *     top-up order's member, the three account spots in the placement
 *     draft option.
 *  b) Merging away an account in use: the preview says why (the exact
 *     sentence), the merge is refused, nothing changes. With the preview's
 *     check taken off, the league's handler inside the merge refuses it
 *     and the merge rolls back. Both accounts in use: can't be merged.
 *  c) Keeping the account in use and merging a clean one into it: no
 *     league blocker, the merge goes ahead, the kept account's league rows
 *     are exactly as before, and Undo brings the other account back.
 *  d) Two accounts with no league rows merge and undo as usual.
 *
 *   wp eval-file dev/tests/test-merge.php --user=<admin id>
 *
 * b–d need the Club Manager (its account merge tool); without it they
 * print SKIP. Throwaway @example.test accounts and the far-past season
 * 1991-pre; everything made is deleted at the end, even after a failure,
 * and the placement draft option is put back exactly. No email is sent.
 */

if (!defined('WP_CLI') || !WP_CLI || (wp_get_environment_type() === 'production' && !getenv('RSL_DEV'))) {
    exit("Local copy only: run with wp eval-file, with RSL_DEV=1 set (docker exec -e RSL_DEV=1 ...).\n");
}
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once dirname(__DIR__) . '/lib-profile.php';
global $wpdb;
add_filter('pre_wp_mail', '__return_false', 999); // nothing is emailed, merges included

$season = '1991-pre';
$label = RSL_Seasons::label($season);
$tag = 'rslmg' . wp_rand(10000, 99999);
$fails = 0;
$check = function ($what, $ok, $detail = '') use (&$fails) {
    if (!$ok) { $fails++; }
    echo ($ok ? "OK: $what\n" : "FAIL: $what" . ($detail !== '' ? " — $detail" : '') . "\n");
};
$cm = class_exists('TeamOversight_Account_Merge');
$woo = function_exists('wc_create_order') && class_exists('WC_Order_Item_Fee');
$opt = RSL_Free_Agents::BUILDING_OPTION;
$option_row = function () use ($wpdb, $opt) {
    return $wpdb->get_row($wpdb->prepare("SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $opt), ARRAY_A);
};
$orig_option = $option_row();
$prefix_len = strlen(RSL_Database::table(''));
$users = array();
$teams = array();
$orders = array();
$merges = array();
$blocker_cb = array('RSL_Merge', 'blockers');

$mk = function ($name) use ($tag, &$users) {
    $id = wp_insert_user(array(
        'user_login' => "$tag-$name", 'user_email' => "$tag-$name@example.test", 'user_pass' => wp_generate_password(24),
        'role' => 'customer', 'first_name' => 'Merge', 'last_name' => 'Tester' . ucfirst($name), 'display_name' => 'Merge Tester' . ucfirst($name),
    ));
    if (is_wp_error($id)) {
        throw new Exception('could not make a test account: ' . $id->get_error_message());
    }
    $users[] = (int) $id;
    // The same profile on every account: no birth date or first name questions in the merge preview.
    rsl_dev_profile($id);
    update_user_meta($id, 'account_status', 'approved');
    return (int) $id;
};
$mk_team = function ($name, $args = array()) use ($season, $tag, &$teams) {
    $team = RSL_Teams::create($season, array_merge(array('name' => "Merge test $tag $name", 'status' => 'pending'), $args));
    if (is_wp_error($team) || !$team) {
        throw new Exception('could not make a test team: ' . (is_wp_error($team) ? $team->get_error_message() : 'no row'));
    }
    $teams[] = (int) $team->id;
    return $team;
};
// Every league row naming these accounts, as text: equal means byte for byte unchanged.
$rows_of = function ($ids) use ($wpdb, $opt) {
    $ids = array_map('intval', (array) $ids);
    $in = implode(',', array_fill(0, count($ids), '%d'));
    $out = array();
    foreach (RSL_Merge::columns() as $col) {
        $out[$col['table'] . '.' . $col['column']] = $wpdb->get_results($wpdb->prepare("SELECT * FROM `{$col['table']}` WHERE `{$col['column']}` IN ($in) ORDER BY 1", $ids), ARRAY_A);
    }
    $meta = $wpdb->prefix . 'woocommerce_order_itemmeta';
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($meta)))) {
        $sin = implode(',', array_fill(0, count($ids), '%s'));
        $out['topup'] = $wpdb->get_results($wpdb->prepare("SELECT * FROM `$meta` WHERE meta_key = %s AND meta_value IN ($sin) ORDER BY meta_id", array_merge(array(RSL_Merge::TOPUP_META), array_map('strval', $ids))), ARRAY_A);
    }
    $out['building'] = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $opt));
    return wp_json_encode($out);
};
// The account itself: its user row and profile (sign-in sessions aside).
$account = function ($id) use ($wpdb) {
    return wp_json_encode(array(
        $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->users} WHERE ID = %d", $id), ARRAY_A),
        $wpdb->get_results($wpdb->prepare("SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key <> 'session_tokens' ORDER BY umeta_id", $id), ARRAY_A),
    ));
};
$forget_draft = function () use ($season, $opt) {
    $all = get_option($opt, array());
    if (is_array($all) && isset($all[$season])) {
        unset($all[$season]);
        update_option($opt, $all, false);
    }
};
$league_blockers = function ($plan) {
    $out = array();
    foreach (is_wp_error($plan) ? array() : $plan['blockers'] as $b) {
        if (stripos($b, 'league') !== false || stripos($b, 'nothing says how to merge') !== false) {
            $out[] = $b;
        }
    }
    return $out;
};
$gone = function ($id) {
    clean_user_cache($id);
    return !get_userdata($id);
};

try {
    // ---------- e) schema guard ----------
    $src = (string) file_get_contents(RSL_PLUGIN_DIR . 'includes/class-rsl-database.php');
    preg_match_all('/CREATE TABLE " \. self::table\(\'([a-z0-9_]+)\'\) \. " \((.*?)\) \$charset;/s', $src, $tables, PREG_SET_ORDER);
    $check('schema guard: every CREATE TABLE in class-rsl-database.php read', count($tables) > 0 && count($tables) === substr_count($src, 'dbDelta("CREATE TABLE'), count($tables) . ' read');
    // Columns that look like accounts but aren't.
    $not_accounts = array('teams.formed_by' => 'how the team was made (e.g. placement), a word');
    $listed = array();
    foreach (RSL_Merge::columns() as $col) {
        $listed[substr($col['table'], $prefix_len) . '.' . $col['column']] = true;
    }
    $schema = array();
    $missing = array();
    foreach ($tables as $t) {
        preg_match_all('/^\s*([a-z0-9_]+)\s+(?:bigint|int|tinyint|smallint|mediumint|varchar|char|text|longtext|date|datetime|time|decimal)\b/m', $t[2], $cols);
        foreach ($cols[1] as $name) {
            $key = $t[1] . '.' . $name;
            $schema[$key] = true;
            if ((strpos($name, 'user_id') !== false || substr($name, -3) === '_by') && !isset($not_accounts[$key]) && !isset($listed[$key])) {
                $missing[] = $key;
            }
        }
    }
    $check('schema guard: every user_id / *_by column is in RSL_Merge::columns() (' . count($listed) . ' listed)', !$missing, 'missing: ' . implode(', ', $missing));
    $check('schema guard: every column in RSL_Merge::columns() exists', !array_diff_key($listed, $schema), implode(', ', array_keys(array_diff_key($listed, $schema))));
    $check('schema guard: the columns that only look like accounts still exist', !array_diff_key($not_accounts, $schema));

    // ---------- a) uses() names every kind of row ----------
    $a = $mk('a');
    $a2 = $mk('a2'); // stands in where a row needs some other account
    $team_a = $mk_team('A');
    $check('uses(): nothing for no account', RSL_Merge::uses(0) === array());
    $check('uses(): nothing for a fresh account', RSL_Merge::uses($a) === array(), wp_json_encode(RSL_Merge::uses($a)));
    $now = current_time('mysql');
    $n = 0;
    $base = array(
        'teams' => array('season' => $season, 'status' => 'withdrawn'),
        'players' => array(),
        'regos' => array('user_id' => 0, 'start_date' => '1991-01-01', 'end_date' => '1991-12-31', 'source' => 'manual', 'note' => $tag),
        'invites' => array('team_id' => $team_a->id, 'user_id' => 0, 'email' => "$tag-invite@example.test", 'invited_by' => 0, 'status' => 'cancelled', 'source' => 'email'),
        'free_agents' => array('season' => $season, 'user_id' => 0, 'status' => 'withdrawn'),
        'join_requests' => array('season' => $season, 'team_id' => $team_a->id, 'user_id' => 0, 'status' => 'withdrawn', 'expires_at' => '1991-01-01 00:00:00'),
        // Marked sent, so the mail queue never picks it up.
        'outbox' => array('user_id' => 0, 'to_email' => "$tag-mail@example.test", 'subject' => 'Merge test', 'body' => 'Merge test', 'sent_at' => $now, 'attempts' => 1),
        'team_members' => array('team_id' => $team_a->id, 'user_id' => 0, 'role' => 'player', 'joined_at' => '1991-01-01', 'left_at' => '1991-01-02'),
        'games' => array('season' => $season, 'night_id' => 0, 'venue_id' => 0, 'court_id' => 0, 'start_time' => '19:00:00', 'end_time' => '20:00:00',
            'home_team_id' => $team_a->id, 'away_team_id' => $team_a->id, 'status' => 'scheduled'),
        'requests' => array('team_id' => $team_a->id, 'night_id' => 0, 'type' => 'off', 'status' => 'cancelled', 'requested_by' => 0),
        'officials' => array('user_id' => $a2, 'ref' => '', 'supervisor' => 0),
        'jobs' => array('night_id' => 0, 'type' => 'mergetest', 'game_id' => 0, 'venue_id' => 0, 'user_id' => 0, 'rate' => 0),
        'ledger' => array('team_id' => $team_a->id, 'season' => $season, 'type' => 'adjust', 'amount' => 0, 'note' => $tag),
    );
    foreach (RSL_Merge::columns() as $col) {
        $short = substr($col['table'], $prefix_len);
        $name = $short . '.' . $col['column'];
        if (!isset($base[$short])) {
            $check("uses(): $name has a test row", false, 'add the table to $base in this test');
            continue;
        }
        $n++;
        $row = $base[$short];
        if ($short === 'teams') {
            $row['name'] = "Merge test $tag row $n";
            $row['slug'] = "merge-test-$tag-row-$n";
        }
        if ($short === 'outbox') {
            $row['dedupe'] = "mergetest:$tag:$n";
        }
        $row[$col['column']] = $a;
        $ok = $wpdb->insert($col['table'], $row);
        $row_id = (int) $wpdb->insert_id;
        if (!$ok) {
            $check("uses(): a row in $name", false, $wpdb->last_error);
            continue;
        }
        $want = $col['what'] . ($col['season'] !== '' ? " in $label" : '');
        $got = RSL_Merge::uses($a);
        $check("uses(): a row in $name reads \"$want\"", $got === array($want), wp_json_encode($got));
        if (in_array($short, array('players', 'officials'), true)) {
            $wpdb->delete($col['table'], array('user_id' => $row['user_id']));
        } else {
            $wpdb->delete($col['table'], array('id' => $row_id));
        }
        $check("uses(): $name row gone, nothing left", RSL_Merge::uses($a) === array(), wp_json_encode(RSL_Merge::uses($a)));
    }
    $check('uses(): every listed column was tried', $n === count(RSL_Merge::columns()) && $n === count($listed), "$n tried");

    if ($woo) {
        $order = wc_create_order();
        if (is_wp_error($order)) {
            throw new Exception('could not make a test order: ' . $order->get_error_message());
        }
        $item = new WC_Order_Item_Fee();
        $item->set_name('Merge test ' . $tag);
        $item->set_total(0);
        $item->add_meta_data(RSL_Merge::TOPUP_META, $a, true);
        $order->add_item($item);
        $order->save();
        $orders[] = $order->get_id();
        $got = RSL_Merge::uses($a);
        $check('uses(): a top-up order bought for the account reads "paid for a team top-up"', $got === array('paid for a team top-up'), wp_json_encode($got));
        $order->delete(true);
        $check('uses(): order gone, nothing left', RSL_Merge::uses($a) === array(), wp_json_encode(RSL_Merge::uses($a)));
    } else {
        echo "SKIP: top-up order spot (WooCommerce is not active)\n";
    }

    $spots = array(
        'a placement draft destination' => array(array('proposed_map' => array($a => 'none')), "in the placement draft for $label"),
        'a draft team\'s captain' => array(array('new_teams' => array('n1' => array('name' => 'Merge test', 'captain_user_id' => $a))), "in the placement draft for $label"),
        'who confirmed placement' => array(array('confirmed_by' => $a), "confirmed placement for $label"),
    );
    foreach ($spots as $what => $spot) {
        RSL_Free_Agents::save_building($season, $spot[0]);
        $got = RSL_Merge::uses($a);
        $check("uses(): $what reads \"{$spot[1]}\"", $got === array($spot[1]), wp_json_encode($got));
        $forget_draft();
        $check("uses(): $what gone, nothing left", RSL_Merge::uses($a) === array(), wp_json_encode(RSL_Merge::uses($a)));
    }

    if (!$cm) {
        echo "SKIP: b) c) d) merges (the Club Manager's account merge tool is not installed)\n";
    } else {
        // ---------- b) merging away an account in use ----------
        $k1 = $mk('k1');
        $m1 = $mk('m1');
        $team_b = $mk_team('B');
        $r = RSL_Players::register($m1, array('level' => 'social', 'years' => '1to3', 'positions' => array('outside')));
        $r2 = RSL_Teams::add_member($team_b->id, $m1, 'player', true, array('admin' => true));
        $r3 = RSL_Rego::grant($m1, array('source' => 'manual', 'start' => '1991-01-01', 'note' => $tag));
        RSL_Officials::apply_ref($m1);
        $check('b: the account to merge away is a player on a team, with a rego, on the referees list', $r === true && $r2 === true && $r3 > 0 && RSL_Officials::get($m1)->ref === 'applied');
        $expected = "#$m1 is in use in the Social League: registered as a player; on a team in $label; has a social rego; "
            . "on the referees and court supervisors list; changed an entry on the referees and court supervisors list. "
            . "League records never move from one account to another, so keep #$m1 instead and merge #$k1 into it.";
        $rows_before = $rows_of(array($k1, $m1));
        $k_before = $account($k1);
        $m_before = $account($m1);

        $plan = TeamOversight_Account_Merge::plan($k1, array($m1));
        $check('b: preview shows the league\'s sentence, word for word', !is_wp_error($plan) && in_array($expected, $plan['blockers'], true),
            is_wp_error($plan) ? $plan->get_error_message() : wp_json_encode($plan['blockers']));
        $check('b: and not the old "nothing says how to merge" one', !is_wp_error($plan) && !preg_grep('/nothing says how to merge/', $plan['blockers']));
        $res = is_wp_error($plan) ? $plan : TeamOversight_Account_Merge::apply($k1, array($m1), array_keys($plan['confirm']), $plan['hash']);
        if (!is_wp_error($res)) {
            $merges[] = $res['id'];
        }
        $check('b: the merge is refused, saying why', is_wp_error($res) && $res->get_error_code() === 'murvc_merge_blocked' && strpos($res->get_error_message(), $expected) !== false,
            is_wp_error($res) ? $res->get_error_message() : 'it merged');
        wp_cache_flush();
        $check('b: both accounts are still there, exactly as they were', !$gone($m1) && $account($k1) === $k_before && $account($m1) === $m_before);
        $check('b: every league row is untouched', $rows_of(array($k1, $m1)) === $rows_before);

        $thrown = '';
        try {
            RSL_Merge::apply($k1, array($m1));
        } catch (Exception $ex) {
            $thrown = $ex->getMessage();
        }
        $check('b: the handler on its own throws the same sentence', $thrown === $expected, $thrown);
        $check('b: blockers() always returns an array (given nothing, or another plugin\'s sentence)',
            RSL_Merge::blockers(null, $k1, array($m1)) === array($expected)
            && RSL_Merge::blockers('Another reason.', $k1, array($m1)) === array('Another reason.', $expected)
            && RSL_Merge::blockers(array(), $k1, array()) === array());

        // The safety net: with the preview's check off, the handler inside the merge refuses it.
        remove_filter('murvc_account_merge_blockers', $blocker_cb, 10);
        try {
            $plan2 = TeamOversight_Account_Merge::plan($k1, array($m1));
            $check('b: (preview check off) no league blocker in the preview', !is_wp_error($plan2) && !$league_blockers($plan2), is_wp_error($plan2) ? $plan2->get_error_message() : wp_json_encode($plan2['blockers']));
            $res2 = is_wp_error($plan2) ? $plan2 : TeamOversight_Account_Merge::apply($k1, array($m1), array_keys($plan2['confirm']), $plan2['hash']);
        } finally {
            add_filter('murvc_account_merge_blockers', $blocker_cb, 10, 3);
        }
        if (!is_wp_error($res2)) {
            $merges[] = $res2['id'];
            TeamOversight_Account_Merge::undo($res2['id']);
        }
        $check('b: (preview check off) the league\'s handler stops the merge: Nothing was changed: <the sentence>',
            is_wp_error($res2) && $res2->get_error_code() === 'murvc_merge_failed' && $res2->get_error_message() === 'Nothing was changed: ' . $expected,
            is_wp_error($res2) ? $res2->get_error_message() : 'it merged');
        wp_cache_flush();
        $check('b: (preview check off) rolled back: both accounts exactly as they were', !$gone($m1) && $account($k1) === $k_before && $account($m1) === $m_before);
        $check('b: (preview check off) every league row is untouched', $rows_of(array($k1, $m1)) === $rows_before);

        // Both in use.
        RSL_Players::register($k1, array('level' => 'vvl', 'years' => 'gt3', 'positions' => array('setter')));
        $both = "Both #$k1 and #$m1 are in use in the Social League, and league records never move from one account to another, so these accounts can't be merged."
            . " #$k1: registered as a player. #$m1: registered as a player; on a team in $label; has a social rego; on the referees and court supervisors list;"
            . " changed an entry on the referees and court supervisors list.";
        $plan3 = TeamOversight_Account_Merge::plan($k1, array($m1));
        $check('b: both accounts in use: the preview says they can\'t be merged, word for word', !is_wp_error($plan3) && in_array($both, $plan3['blockers'], true) && count($league_blockers($plan3)) === 1,
            is_wp_error($plan3) ? $plan3->get_error_message() : wp_json_encode($plan3['blockers']));
        $res3 = is_wp_error($plan3) ? $plan3 : TeamOversight_Account_Merge::apply($k1, array($m1), array_keys($plan3['confirm']), $plan3['hash']);
        if (!is_wp_error($res3)) {
            $merges[] = $res3['id'];
        }
        $check('b: both in use: refused', is_wp_error($res3) && !$gone($m1));

        // ---------- c) keeping the account in use ----------
        $k2 = $mk('k2');
        $m2 = $mk('m2');
        RSL_Players::register($k2, array('level' => 'social', 'years' => 'gt3', 'positions' => array('middle')));
        $team_c = $mk_team('C', array('applied_by' => $k2));
        RSL_Teams::add_member($team_c->id, $k2, 'captain', true, array('admin' => true));
        RSL_Rego::grant($k2, array('source' => 'manual', 'start' => '1991-01-01', 'note' => $tag));
        RSL_Officials::apply_ref($k2);
        RSL_Free_Agents::save_building($season, array('proposed_map' => array($k2 => 't:' . $team_c->id)));
        $check('c: the account to keep is in use, the other isn\'t', count(RSL_Merge::uses($k2)) >= 5 && RSL_Merge::uses($m2) === array(), wp_json_encode(RSL_Merge::uses($k2)));
        $check('c: refusal() has nothing to say', RSL_Merge::refusal($k2, array($m2)) === '');
        $k_rows = $rows_of(array($k2));
        $plan = TeamOversight_Account_Merge::plan($k2, array($m2));
        $check('c: no league blocker (and not the old "nothing says how to merge")', !is_wp_error($plan) && !$league_blockers($plan), is_wp_error($plan) ? $plan->get_error_message() : wp_json_encode($plan['blockers']));
        $check('c: no blockers at all', !is_wp_error($plan) && !$plan['blockers'], is_wp_error($plan) ? '' : wp_json_encode($plan['blockers']));
        $res = is_wp_error($plan) ? $plan : TeamOversight_Account_Merge::apply($k2, array($m2), array_keys($plan['confirm']), $plan['hash']);
        $check('c: the merge goes ahead', !is_wp_error($res), is_wp_error($res) ? $res->get_error_message() : '');
        if (!is_wp_error($res)) {
            $merges[] = $res['id'];
            wp_cache_flush();
            $check('c: the merged account is gone', $gone($m2));
            $check('c: the kept account\'s league rows are byte for byte as before', $rows_of(array($k2)) === $k_rows);
            $u = TeamOversight_Account_Merge::undo($res['id']);
            $check('c: undo runs, leaving nothing behind', !is_wp_error($u) && empty($u['skipped']), is_wp_error($u) ? $u->get_error_message() : wp_json_encode($u['skipped']));
            wp_cache_flush();
            $check('c: undo brings the merged account back', !$gone($m2));
            $check('c: the kept account\'s league rows are still as before', $rows_of(array($k2)) === $k_rows);
            $check('c: the merged account still has no league rows', RSL_Merge::uses($m2) === array(), wp_json_encode(RSL_Merge::uses($m2)));
        }

        // ---------- d) two accounts with no league rows ----------
        $p = $mk('p');
        $q = $mk('q');
        $plan = TeamOversight_Account_Merge::plan($p, array($q));
        $check('d: no league rows: no blockers', !is_wp_error($plan) && !$plan['blockers'], is_wp_error($plan) ? $plan->get_error_message() : wp_json_encode($plan['blockers']));
        $res = is_wp_error($plan) ? $plan : TeamOversight_Account_Merge::apply($p, array($q), array_keys($plan['confirm']), $plan['hash']);
        $check('d: the merge goes ahead', !is_wp_error($res), is_wp_error($res) ? $res->get_error_message() : '');
        if (!is_wp_error($res)) {
            $merges[] = $res['id'];
            wp_cache_flush();
            $check('d: the merged account is gone', $gone($q));
            $u = TeamOversight_Account_Merge::undo($res['id']);
            $check('d: undo runs, leaving nothing behind', !is_wp_error($u) && empty($u['skipped']), is_wp_error($u) ? $u->get_error_message() : wp_json_encode($u['skipped']));
            wp_cache_flush();
            $check('d: undo brings it back', !$gone($q));
        }
    }
} catch (Throwable $t) {
    $check('the test ran to the end', false, get_class($t) . ': ' . $t->getMessage() . ' at line ' . $t->getLine());
} finally {
    // ---------- clean up ----------
    if (function_exists('UM')) {
        UM()->user()->send_mail_on_delete = false;
    }
    foreach ($orders as $o) {
        $order = wc_get_order($o);
        if ($order) {
            $order->delete(true);
        }
    }
    if ($users) {
        $in = implode(',', array_fill(0, count($users), '%d'));
        foreach (RSL_Merge::columns() as $col) {
            $wpdb->query($wpdb->prepare("DELETE FROM `{$col['table']}` WHERE `{$col['column']}` IN ($in)", $users));
        }
    }
    foreach ($teams as $tid) {
        foreach (array('team_members', 'invites', 'join_requests', 'requests', 'ledger') as $t) {
            $wpdb->delete(RSL_Database::table($t), array('team_id' => $tid));
        }
        $wpdb->delete(RSL_Database::table('games'), array('home_team_id' => $tid));
        $wpdb->delete(RSL_Database::table('teams'), array('id' => $tid));
    }
    $wpdb->query($wpdb->prepare("DELETE FROM " . RSL_Database::table('outbox') . " WHERE to_email LIKE %s OR dedupe LIKE %s", $wpdb->esc_like($tag) . '%', 'mergetest:' . $wpdb->esc_like($tag) . '%'));
    if ($cm) {
        foreach ($merges as $mid) {
            $wpdb->delete(TeamOversight_Account_Merge::table(), array('id' => $mid));
        }
    }
    if ($users && class_exists('TeamOversight_Log')) {
        $wpdb->query($wpdb->prepare('DELETE FROM ' . TeamOversight_Log::table() . " WHERE subject_user_id IN ($in)", $users));
    }
    foreach ($users as $uid) {
        if (get_userdata($uid)) {
            wp_delete_user($uid);
        }
        if (class_exists('TeamOversight_Duplicates')) {
            TeamOversight_Duplicates::forget_decisions_for($uid);
        }
    }
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'wc_customer_lookup')))) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}wc_customer_lookup WHERE email LIKE %s", $wpdb->esc_like($tag) . '%'));
    }
    if (class_exists('TeamOversight_Duplicates')) {
        TeamOversight_Duplicates::forget_scan();
        wp_clear_scheduled_hook(TeamOversight_Duplicates::RESCAN_HOOK);
    }
    // The placement draft option, exactly as it was (value and autoload).
    if ($orig_option === null) {
        delete_option($opt);
    } elseif ($option_row() !== $orig_option) {
        $wpdb->update($wpdb->options, $orig_option, array('option_name' => $opt));
    }
    wp_cache_delete($opt, 'options');
    wp_cache_delete('alloptions', 'options');
    wp_cache_delete('notoptions', 'options');
    $check('clean up: the placement draft option is exactly as it was', $option_row() === $orig_option);
    $left = 0;
    if ($users) {
        foreach (RSL_Merge::columns() as $col) {
            $left += (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$col['table']}` WHERE `{$col['column']}` IN ($in)", $users));
        }
    }
    $check('clean up: no league row names a test account, no test account left', $left === 0 && !array_filter(array_map('get_userdata', $users)));
    echo "cleaned up\n";
}
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
