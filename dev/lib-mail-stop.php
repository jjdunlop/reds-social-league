<?php
/**
 * DEV ONLY (0.17.0): a test's mail never leaves. Every test that makes accounts:
 *
 *   require_once dirname(__DIR__) . '/lib-mail-stop.php';
 *   $suffix = time();
 *   $hold = rsl_dev_hold_mail($suffix);            // before $check, and before the first account
 *   $check = function (...) use (&$fails, $hold) { rsl_dev_park_mail($hold); ... };
 *   $users = array(); ...                          // everything the cleanup reads, before the try
 *   try { ... } catch (Throwable $thrown) { $fails++; echo 'FAIL: threw ...'; } finally {
 *       ...                                        // the cleanup's own row deletes
 *       rsl_dev_delete_users($users);              // Ultimate Member's account deleted email off first
 *       rsl_dev_release_mail($hold);               // last of all
 *   }
 *
 * The fake-data scripts (fake-season.php, fake-signup.php) hold the mail for their whole run too.
 * Nobody should open the admin Free Agents screen while a test runs: it sends up to 10 waiting
 * emails on every visit, and a row queued since the test's last check isn't parked yet.
 *
 * While held: wp_mail() sends nothing in this process (pre_wp_mail answered false at 999, as
 * test-merge.php does; a test's own capture filters at a lower priority still see each email);
 * the league's send run and its hourly job (which drains the outbox too, and on a quiet copy is
 * often overdue, so any page hit would run it) are put off a day; and every unsent outbox row
 * queued since the hold is parked (attempts at the maximum, last_error RSL_DEV_MAIL_HELD) at each
 * check, so no other request sends it. Release deletes every unsent row queued since the hold and
 * the test addresses' rows, puts the send run and the hourly job back, and only then takes the
 * stop off. A test that dies before its release (a fatal error, an uncaught exception, an exit)
 * is released by a shutdown function, so parked rows never wait for a "Retry failed".
 * Tests run on a local copy or a staging copy where nothing else queues league mail meanwhile.
 *
 * The team avatar job (0.23.0, RSL_Avatars::CRON_HOOK) is put off a day too, so another request
 * never fetches images from DiceBear for a test's teams (every new team is queued); release takes
 * the teams that are gone off its queue and puts the job back while any remain or a season is
 * flagged for a stage check (a test that records a result or publishes a night flags its season).
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('RSL_DEV_MAIL_HELD')) {
    define('RSL_DEV_MAIL_HELD', 'Held by a dev test');
}

/** Stop this process's mail, put the send run and the hourly job off a day; returns the hold. */
function rsl_dev_hold_mail($suffix) {
    global $wpdb;
    add_filter('pre_wp_mail', '__return_false', 999);
    $hold = array(
        'token' => uniqid('rsl', true),
        'suffix' => (string) $suffix,
        'since' => (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM " . RSL_Database::table('outbox')),
        'send_was' => (bool) wp_next_scheduled(RSL_Mail::SEND_HOOK),
    );
    wp_clear_scheduled_hook(RSL_Mail::SEND_HOOK);
    wp_schedule_single_event(time() + DAY_IN_SECONDS, RSL_Mail::SEND_HOOK);
    wp_clear_scheduled_hook('rsl_hourly');
    wp_schedule_event(time() + DAY_IN_SECONDS, 'hourly', 'rsl_hourly');
    if (class_exists('RSL_Avatars')) {
        wp_clear_scheduled_hook(RSL_Avatars::CRON_HOOK);
        wp_schedule_single_event(time() + DAY_IN_SECONDS, RSL_Avatars::CRON_HOOK);
    }
    $GLOBALS['rsl_dev_mail_holds'][$hold['token']] = $hold;
    register_shutdown_function('rsl_dev_mail_shutdown', $hold['token']);
    return $hold;
}

/** Park every unsent row queued since the hold (no other request sends it); returns how many. */
function rsl_dev_park_mail($hold) {
    global $wpdb;
    if (!is_array($hold) || !isset($hold['since'])) {
        return 0; // no hold (say a $check made before it): never park every unsent row
    }
    return (int) $wpdb->query($wpdb->prepare(
        "UPDATE " . RSL_Database::table('outbox') . " SET attempts = %d, last_error = %s WHERE id > %d AND sent_at IS NULL AND attempts < %d",
        RSL_Mail::MAX_ATTEMPTS, RSL_DEV_MAIL_HELD, $hold['since'], RSL_Mail::MAX_ATTEMPTS
    ));
}

/** Delete the test's accounts (real ids only), with Ultimate Member's account deleted email off. */
function rsl_dev_delete_users($users) {
    require_once ABSPATH . 'wp-admin/includes/user.php';
    // Ultimate Member emails an account deleted notice from delete_user: not for throwaway accounts.
    if (function_exists('UM')) {
        UM()->user()->send_mail_on_delete = false;
    }
    foreach ((array) $users as $u) {
        if (!is_int($u) || $u <= 0) {
            continue; // never a WP_Error: as a user_id it would match the rows with user_id 0
        }
        delete_transient(RSL_Portal::FLASH_PREFIX . $u);
        delete_transient(RSL_Portal::APPLY_DRAFT_PREFIX . $u);
        delete_transient(RSL_Admin::NOTICE_PREFIX . $u);
        delete_transient('rsl_mail_failed_' . $u); // approve() / decline() under the stop: the email "failed"
        wp_delete_user($u);
    }
}

/** Delete the mail queued since the hold, put the send run and the hourly job back, then lift the stop. */
function rsl_dev_release_mail($hold) {
    global $wpdb;
    if (!is_array($hold) || !isset($hold['since'], $hold['token'])) {
        return;
    }
    $table = RSL_Database::table('outbox');
    rsl_dev_park_mail($hold); // anything queued since the last check
    // Every unsent row since the hold: parked, or tried in this process with the stop on.
    $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE id > %d AND sent_at IS NULL", $hold['since']));
    $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE to_email LIKE %s", '%-' . $wpdb->esc_like($hold['suffix']) . '@example.test'));
    wp_clear_scheduled_hook(RSL_Mail::SEND_HOOK);
    if ($hold['send_was'] || RSL_Mail::counts()['queued'] > 0) {
        RSL_Mail::schedule_send();
    }
    wp_clear_scheduled_hook('rsl_hourly');
    wp_schedule_event(time() + 300, 'hourly', 'rsl_hourly'); // as rsl_schedule_cron() does
    if (class_exists('RSL_Avatars')) {
        wp_clear_scheduled_hook(RSL_Avatars::CRON_HOOK);
        RSL_Avatars::forget_missing(); // the test's teams, deleted by its cleanup
        RSL_Avatars::reschedule();
    }
    unset($GLOBALS['rsl_dev_mail_holds'][$hold['token']]);
    remove_filter('pre_wp_mail', '__return_false', 999);
}

/** Shutdown: a hold its test never released (it died first) is released now. */
function rsl_dev_mail_shutdown($token) {
    if (!empty($GLOBALS['rsl_dev_mail_holds'][$token])) {
        rsl_dev_release_mail($GLOBALS['rsl_dev_mail_holds'][$token]);
    }
}
