<?php
/**
 * The email queue: dedupe, sending in batches, failures and retries,
 * pruning old sent mail; the club look (club_email(), the drained and
 * direct sends with the Club Manager's template, a throw as a failure,
 * the plain path).
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
// Set before the try, so the cleanup can always run (it puts back everything below, after a failure too).
$set_aside = '2999-01-01 00:00:00';
$others = array();
$saved_settings = get_option(RSL_Payments::SETTINGS_OPTION, '__unset__');
$contact = "rslmail-contact-$suffix@example.test";
$sent_to = array();
$ok = function ($return, $atts) use (&$sent_to) { $sent_to[] = $atts['subject']; return true; };
$no = function ($return, $atts) { return false; };
$captured = array();
$capture = function ($return, $atts) use (&$captured) { $captured[] = $atts; return true; };
$throw = null;
try {
    // Other unsent mail on this copy is set aside so drain() and retry_failed() only see ours:
    // stamped "sent" far in the future (prune() only deletes old sent mail), then put back as it was.
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

    add_filter('pre_wp_mail', $ok, 10, 2);
    list($sent, $failed) = RSL_Mail::drain();
    remove_filter('pre_wp_mail', $ok, 10);
    $check('a run sends one batch (25)', $sent === RSL_Mail::BATCH && $failed === 0 && count($sent_to) === RSL_Mail::BATCH);
    $check('sent rows are stamped', count(array_filter($mine(), function ($r) { return $r->sent_at !== null; })) === RSL_Mail::BATCH);
    $check('another run is scheduled while mail waits', (bool) wp_next_scheduled(RSL_Mail::SEND_HOOK));

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

    // --- The club look (0.16.0) ---------------------------------------------------------------
    $by_subject = function ($subject) use (&$captured) {
        foreach ($captured as $atts) {
            if ($atts['subject'] === $subject) {
                return $atts;
            }
        }
        return null;
    };
    $header_list = function ($atts) {
        $h = $atts ? $atts['headers'] : array();
        return array_values(array_filter(array_map('trim', is_array($h) ? $h : preg_split("/\r\n|\n/", (string) $h)), 'strlen'));
    };
    $row_of = function ($id) use ($wpdb, $table) {
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
    };
    $body = "Hi Alex,\n\nTom & Jerry <tj@example.test> said <b>hi</b>.\n\nYour league page:\nhttps://example.test/league/\n\nAlso: https://example.test/other/\n\n— Reds Social League\n";

    // 1. club_email() on its own (pure: runs with or without the Club Manager).
    $e = RSL_Mail::club_email('[Social League] Hello', $body);
    $check('club_email: the program, and the subject without its prefix as the heading', $e['program'] === 'Reds Social League' && $e['heading'] === 'Hello', $e['heading']);
    $check('club_email: the first bare address is the button, named by the short Label: line above it',
        $e['button'] === array('url' => 'https://example.test/league/', 'label' => 'Your league page'), json_encode($e['button']));
    $check('club_email: the button line stands where the label and the address were', preg_match('/^\{button\}$/m', $e['body']) === 1
        && strpos($e['body'], 'Your league page:') === false && strpos($e['body'], 'https://example.test/league/') === false, $e['body']);
    $check('club_email: other addresses stay in the text; no vars or blocks', strpos($e['body'], 'Also: https://example.test/other/') !== false
        && $e['vars'] === array() && $e['blocks'] === array());
    $check('club_email: the preheader skips the greeting', strpos($e['preheader'], 'Tom & Jerry') === 0, $e['preheader']);

    // 2. Long label lines, invite links, no address at all.
    $long = "Hi Alex,\n\nYou need a current rego before your first game, unless you're a full or life club member:\nhttps://example.test/pay/\n";
    $e2 = RSL_Mail::club_email('Placed', $long, array('labels' => array('https://example.test/pay/' => 'Pay your rego')));
    $check('club_email: a long line ending in a colon stays as text; the label comes from the known addresses',
        $e2['button'] === array('url' => 'https://example.test/pay/', 'label' => 'Pay your rego') && strpos($e2['body'], "club member:\n{button}") !== false, json_encode($e2));
    $e3 = RSL_Mail::club_email('Placed', $long);
    $check('club_email: an unknown address is labelled Open', $e3['button'] && $e3['button']['label'] === 'Open');
    $e4 = RSL_Mail::club_email('Approved', "Invite your players with this link:\nhttps://example.test/league/?join=ABCD2345\n\nOr from your league page:\nhttps://example.test/league/\n");
    $check('club_email: an invite link (join=) is never the button, the next bare address is', $e4['button'] && $e4['button']['url'] === 'https://example.test/league/'
        && strpos($e4['body'], "Invite your players with this link:\nhttps://example.test/league/?join=ABCD2345") !== false, json_encode($e4));
    $e5 = RSL_Mail::club_email('Plain', "Hi Alex,\n\nNo links here.\n");
    $check('club_email: no bare address, no button', $e5['button'] === null && strpos($e5['body'], '{button}') === false && $e5['preheader'] === 'No links here.');

    $club = RSL_Mail::club_look_available();
    if (!$club) {
        echo "SKIP: club look checks (no Club Manager email class)\n";
    } else {
        // 3. What render() makes of it.
        $r = TeamOversight_Email::render(RSL_Mail::club_email('[Social League] Hello', $body));
        $check('club look: what people typed is escaped, never HTML', strpos($r['html'], 'Tom &amp; Jerry &lt;tj@example.test&gt; said &lt;b&gt;hi&lt;/b&gt;') !== false && strpos($r['html'], '<b>hi') === false);
        $check('club look: one button to the league page', substr_count($r['html'], 'href="https://example.test/league/"') === 1 && strpos($r['html'], 'Your league page</a>') !== false);
        $check('club look: the program above the heading', strpos($r['html'], '>Reds Social League</p>') !== false);
        $check('club look: the text version has the button as words and its address', strpos($r['text'], 'Your league page: https://example.test/league/') !== false, $r['text']);

        // 4. A drained row.
        RSL_Payments::save_settings(array('contact_emails' => $contact));
        $captured = array();
        add_filter('pre_wp_mail', $capture, 1000, 2);
        $rid = RSL_Mail::queue($to, 'Club look ' . $suffix, $body, "test:$suffix:club");
        RSL_Mail::drain();
        remove_filter('pre_wp_mail', $capture, 1000);
        $got = $by_subject('Club look ' . $suffix);
        $hdrs = $header_list($got);
        $from_cm = array();
        if (class_exists('TeamOversight_Payments') && method_exists('TeamOversight_Payments', 'get_email_headers')) {
            $from_cm = array_values(array_filter(array_map('trim', (array) TeamOversight_Payments::get_email_headers()), function ($h) { return stripos($h, 'From:') === 0; }));
        }
        $from_got = array_values(array_filter($hdrs, function ($h) { return stripos($h, 'From:') === 0; }));
        $check('drained in the club look: HTML, marked, Reply-To the league contact', $got && in_array('Content-Type: text/html; charset=UTF-8', $hdrs, true)
            && in_array(TeamOversight_Email::MARKER_HEADER, $hdrs, true) && in_array('Reply-To: ' . $contact, $hdrs, true), implode(' | ', $hdrs));
        $check('...from the Club Manager\'s sender exactly (no From of the league\'s own)', $from_got === $from_cm, implode(' | ', $from_got) . ' vs ' . implode(' | ', $from_cm));
        $check('...the subject unchanged, the words and the button in the message, the player footer line', $got && $got['subject'] === 'Club look ' . $suffix
            && strpos($got['message'], 'Tom &amp; Jerry') !== false && strpos($got['message'], 'Your league page</a>') !== false
            && strpos($got['message'], 'registered for the Reds Social League') !== false);
        $row = $row_of($rid);
        $check('...the row is stamped sent and still holds the plain text queued', $row && $row->sent_at !== null && $row->body === $body);

        // 5. Mail to the league contact.
        $captured = array();
        add_filter('pre_wp_mail', $capture, 1000, 2);
        RSL_Mail::queue($contact, '[Social League] For the contact ' . $suffix, $body, "test:$suffix:contact");
        RSL_Mail::drain();
        remove_filter('pre_wp_mail', $capture, 1000);
        $got = $by_subject('[Social League] For the contact ' . $suffix);
        $check('mail to the league contact: no Reply-To, the contact footer line, the heading without the prefix', $got
            && !array_filter($header_list($got), function ($h) { return stripos($h, 'Reply-To:') === 0; })
            && strpos($got['message'], 'as a Reds Social League contact.') !== false && strpos($got['message'], '>For the contact ' . $suffix . '</h1>') !== false);

        // 7. send_now(), the path of the emails sent straight away.
        $captured = array();
        add_filter('pre_wp_mail', $capture, 1000, 2);
        $now_ok = RSL_Mail::send_now($to, 'Now ' . $suffix, "Hi Alex,\n\nStraight away.\n");
        $two = array("rslmail-a-$suffix@example.test", "rslmail-b-$suffix@example.test");
        $two_ok = RSL_Mail::send_now($two, 'Two ' . $suffix, "Two of you.\n");
        remove_filter('pre_wp_mail', $capture, 1000);
        $now = $by_subject('Now ' . $suffix);
        $check('send_now: goes out in the club look too', $now_ok && $now && in_array(TeamOversight_Email::MARKER_HEADER, $header_list($now), true) && RSL_Mail::last_error() === '');
        $both = $by_subject('Two ' . $suffix);
        $check('send_now: an array of addresses reaches wp_mail as that array', $two_ok && $both && $both['to'] === $two);
    }

    // 6. A throw is a failed attempt, not a crash (either path).
    $throw = function ($return, $atts) use ($suffix) {
        if ($atts['subject'] === 'Throw ' . $suffix) {
            throw new Exception('test throw');
        }
        return $return;
    };
    $captured = array();
    add_filter('pre_wp_mail', $throw, 5, 2);
    add_filter('pre_wp_mail', $capture, 1000, 2);
    $t1 = RSL_Mail::queue($to, 'Throw ' . $suffix, 'Body', "test:$suffix:throw");
    $t2 = RSL_Mail::queue($to, 'After the throw ' . $suffix, 'Body', "test:$suffix:after");
    $res = RSL_Mail::drain();
    remove_filter('pre_wp_mail', $capture, 1000);
    remove_filter('pre_wp_mail', $throw, 5);
    $r1 = $row_of($t1);
    $r2 = $row_of($t2);
    $check('a send that throws is a failed attempt; the next row still goes', $res === array(1, 1) && $r1 && $r1->sent_at === null
        && strpos((string) $r1->last_error, 'Mail failed:') === 0 && $r2 && $r2->sent_at !== null, json_encode(array($res, $r1 ? $r1->last_error : null)));
    $wpdb->delete($table, array('id' => $t1));

    // 8. The plain path (the Club Manager can't be removed here, so the filter switches the look off).
    add_filter('rsl_mail_club_look', '__return_false');
    $plain_available = RSL_Mail::club_look_available();
    $captured = array();
    add_filter('pre_wp_mail', $capture, 1000, 2);
    RSL_Mail::queue($to, 'Plain ' . $suffix, $body, "test:$suffix:plain");
    RSL_Mail::drain();
    remove_filter('pre_wp_mail', $capture, 1000);
    remove_filter('rsl_mail_club_look', '__return_false');
    $got = $by_subject('Plain ' . $suffix);
    $plain_hdrs = implode("\n", $header_list($got));
    $check('plain path: the stored body byte for byte, no club headers', !$plain_available && $got && $got['message'] === $body
        && stripos($plain_hdrs, 'Content-Type') === false && stripos($plain_hdrs, 'X-MURVC-Email') === false && stripos($plain_hdrs, 'Reply-To') === false, $plain_hdrs);
} catch (Throwable $t) {
    $check('the test ran to the end', false, get_class($t) . ': ' . $t->getMessage() . ' (line ' . $t->getLine() . ')');
} finally {
    // --- Cleanup (runs after a failure too) -------------------------------------------------------
    remove_filter('pre_wp_mail', $ok, 10);
    remove_filter('pre_wp_mail', $no, 10);
    if ($throw) {
        remove_filter('pre_wp_mail', $throw, 5);
    }
    remove_filter('pre_wp_mail', $capture, 1000);
    remove_filter('rsl_mail_club_look', '__return_false');
    if ($saved_settings === '__unset__') {
        delete_option(RSL_Payments::SETTINGS_OPTION);
    } else {
        update_option(RSL_Payments::SETTINGS_OPTION, $saved_settings);
    }
    $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE to_email IN (%s, %s)", $to, $contact));
    if ($others) {
        $wpdb->query($wpdb->prepare("UPDATE $table SET sent_at = NULL WHERE sent_at = %s AND id IN (" . implode(',', array_map('intval', $others)) . ")", $set_aside));
    }
}
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
