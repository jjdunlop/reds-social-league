<?php
/**
 * The email queue: dedupe, sending in batches, failures and retries,
 * pruning old sent mail.
 *
 *   wp eval-file dev/tests/test-mail.php
 *
 * Nothing is really sent (pre_wp_mail short-circuits wp_mail). Cleans up.
 */

if (!defined('WP_CLI') || !WP_CLI || (wp_get_environment_type() === 'production' && !getenv('RSL_DEV'))) {
    exit("Local copy only: run with wp eval-file, with RSL_DEV=1 set (docker exec -e RSL_DEV=1 ...).\n");
}
global $wpdb;

$suffix = time();
$fails = 0;
$check = function ($label, $ok, $detail = '') use (&$fails) {
    if (!$ok) { $fails++; }
    echo ($ok ? "OK: $label\n" : "FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n");
};
$table = RSL_Database::table('outbox');
$to = "rslmail-$suffix@example.test";
$mine = function () use ($wpdb, $table, $to) {
    return $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE to_email = %s ORDER BY id", $to));
};
// Other unsent mail on this copy is set aside so drain() and retry_failed() only see ours:
// stamped "sent" far in the future (prune() only deletes old sent mail), then put back as it was.
$set_aside = '2999-01-01 00:00:00';
$others = $wpdb->get_col($wpdb->prepare("SELECT id FROM $table WHERE sent_at IS NULL AND to_email <> %s", $to));
if ($others) {
    $wpdb->query($wpdb->prepare("UPDATE $table SET sent_at = %s WHERE id IN (" . implode(',', array_map('intval', $others)) . ")", $set_aside));
}

$id = RSL_Mail::queue($to, 'Hello', 'Body one', "test:$suffix:a");
$check('queue an email', is_int($id) && $id > 0);
$check('the same dedupe key queues nothing', RSL_Mail::queue($to, 'Hello again', 'Body two', "test:$suffix:a") === 0 && count($mine()) === 1);
$check('a bad address is refused', is_wp_error(RSL_Mail::queue('not-an-email', 'x', 'y', "test:$suffix:bad")));
$check('a long dedupe key still fits', is_int(RSL_Mail::queue($to, 'Long', 'Body', "test:$suffix:" . str_repeat('k', 200))));
$check('a send run is scheduled', (bool) wp_next_scheduled(RSL_Mail::SEND_HOOK));
for ($i = 0; $i < 30; $i++) {
    RSL_Mail::queue($to, "Batch $i", 'Body', "test:$suffix:batch:$i");
}

$sent_to = array();
$ok = function ($return, $atts) use (&$sent_to) { $sent_to[] = $atts['subject']; return true; };
add_filter('pre_wp_mail', $ok, 10, 2);
list($sent, $failed) = RSL_Mail::drain();
remove_filter('pre_wp_mail', $ok, 10);
$check('a run sends one batch (25)', $sent === RSL_Mail::BATCH && $failed === 0 && count($sent_to) === RSL_Mail::BATCH);
$check('sent rows are stamped', count(array_filter($mine(), function ($r) { return $r->sent_at !== null; })) === RSL_Mail::BATCH);
$check('another run is scheduled while mail waits', (bool) wp_next_scheduled(RSL_Mail::SEND_HOOK));

$no = function ($return, $atts) { return false; };
add_filter('pre_wp_mail', $no, 10, 2);
for ($i = 0; $i < RSL_Mail::MAX_ATTEMPTS; $i++) {
    RSL_Mail::drain();
}
remove_filter('pre_wp_mail', $no, 10);
$failed_rows = array_filter($mine(), function ($r) { return $r->sent_at === null; });
$check('failures are retried, then given up', $failed_rows && !array_filter($failed_rows, function ($r) { return intval($r->attempts) < RSL_Mail::MAX_ATTEMPTS || $r->last_error === ''; }));
$check('counts show them as failed', RSL_Mail::counts()['failed'] >= count($failed_rows));
$check('the failed list shows them', count(RSL_Mail::failed(50)) >= count($failed_rows));
$check('retry puts them back in the queue', RSL_Mail::retry_failed() >= count($failed_rows));
add_filter('pre_wp_mail', $ok, 10, 2);
RSL_Mail::drain(100);
remove_filter('pre_wp_mail', $ok, 10);
$check('and they go', !array_filter($mine(), function ($r) { return $r->sent_at === null; }));

$old = $mine()[0];
$wpdb->update($table, array('sent_at' => date('Y-m-d H:i:s', current_time('timestamp') - 40 * DAY_IN_SECONDS)), array('id' => $old->id));
RSL_Mail::prune();
$check('sent mail older than 30 days is deleted', !$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE id = %d", $old->id)) && count($mine()) > 1);

// --- Cleanup ------------------------------------------------------------------------------
$wpdb->delete($table, array('to_email' => $to));
if ($others) {
    $wpdb->query($wpdb->prepare("UPDATE $table SET sent_at = NULL WHERE sent_at = %s AND id IN (" . implode(',', array_map('intval', $others)) . ")", $set_aside));
}
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
