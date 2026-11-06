<?php
/**
 * Team avatars (0.23.0): new_seed() (random, its format), the API address, RSL_Teams::create()
 * setting a seed and queueing the team (admin Add a team and the captain application too), the
 * schema 13 backfill, ensure() writing two files named by the team id and the seed's hash, the
 * check before saving (clean()) refusing each bad case with nothing saved, a failed fetch saving
 * nothing, RSL_Public::badge() with files (the picture, the reduced-motion source, the sizes, no
 * external address) and without (today's initials badge exactly, partial objects too), the
 * fixtures' sides carrying the seed, a public page with missing files making no request and
 * queueing, the job's batch, its reschedule, a failure's wait and teams with nothing to draw,
 * regenerate() by the captain (through the league page's process(), the flash, the button), by
 * a league admin (and the Teams screen's action and button), refused for a plain member and a
 * stranger, refused on a failed fetch keeping the old seed and files, the daily cap, old files
 * tidied, the schema upgrade still to finish, roll_over() keeping the seed (and the files), and
 * the captain's form without a colour field.
 *
 *   wp eval-file dev/tests/test-avatars.php
 *
 * DiceBear is never called: the HTTP API is answered by a pre_http_request filter (a small
 * voxel-like SVG, a bad one, a 500, the wrong type or a timeout) that counts every request.
 * Far-future throwaway seasons (2083 Post-season running now, 2084 Pre-season after it, dates from
 * today), @example.test users; the queue, the job, the files and options are put back; cleans up.
 */

if (!defined('WP_CLI') || !WP_CLI || (wp_get_environment_type() === 'production' && !getenv('RSL_DEV'))) {
    exit("Local copy only: run with wp eval-file, with RSL_DEV=1 set (docker exec -e RSL_DEV=1 ...).\n");
}
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once dirname(__DIR__) . '/lib-profile.php';
require_once dirname(__DIR__) . '/lib-mail-stop.php';
global $wpdb;

$A = '2083-post'; // running now (today-14 to today+30): the team entered again comes from here
$S = '2084-pre';  // today+40 to today+100, open for sign-up: most of the test's teams
$suffix = time();
// Holds the mail, and puts the avatar job off a day (lib-mail-stop.php), so no other request fetches for these teams.
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
$users = array();
$team_ids = array();
$queue_before = RSL_Avatars::queued();
$courts_before = get_option(RSL_Courts::SETTINGS_OPTION, '__unset__');
$up = wp_upload_dir(null, false);
$dir = untrailingslashit($up['basedir']) . '/' . RSL_Avatars::DIR;
$dir_was = is_dir($dir);
$stub = null;
$cands = null;
$behind = null;
// The stub's mode and its log: every request (url), and the ones to DiceBear.
$http = array('mode' => 'good', 'all' => array(), 'dicebear' => array());
$mk = function ($tag, $role = 'subscriber', $register = false) use ($suffix, &$users) {
    $u = wp_insert_user(array('user_login' => "rslav-$tag-$suffix", 'user_email' => "rslav-$tag-$suffix@example.test", 'user_pass' => wp_generate_password(),
        'display_name' => "RSLAV $tag $suffix", 'role' => $role));
    if (is_wp_error($u)) {
        throw new Exception("Couldn't make the test account $tag: " . $u->get_error_message());
    }
    $users[] = $u;
    rsl_dev_profile($u);
    if ($register) {
        $r = RSL_Players::register($u, array('level' => 'social', 'years' => '1to3'));
        if (is_wp_error($r)) {
            throw new Exception("Couldn't register the test account $tag: " . $r->get_error_message());
        }
    }
    return $u;
};
$mkteam = function ($season, $name, $args = array()) use (&$team_ids) {
    $t = RSL_Teams::create($season, array_merge(array('name' => $name, 'division' => 'ranked', 'status' => 'active'), $args));
    if (!$t || is_wp_error($t)) {
        throw new Exception("Couldn't make the test team $name" . (is_wp_error($t) ? ': ' . $t->get_error_message() : '.'));
    }
    $team_ids[] = intval($t->id);
    return $t;
};
$calls = function () use (&$http) { return count($http['dicebear']); };
$reset = function () use (&$http) { $http['all'] = array(); $http['dicebear'] = array(); };
$files_of = function ($team_id) use ($dir) {
    $f = glob($dir . '/team-' . intval($team_id) . '-*');
    $f = is_array($f) ? array_map('basename', $f) : array();
    sort($f);
    return $f;
};
$hold_job = function () {
    wp_clear_scheduled_hook(RSL_Avatars::CRON_HOOK);
    wp_schedule_single_event(time() + DAY_IN_SECONDS, RSL_Avatars::CRON_HOOK);
};
$set_queue = function ($ids) {
    $q = array();
    foreach ($ids as $id) {
        $q[intval($id)] = array('tries' => 0, 'next' => 0);
    }
    update_option(RSL_Avatars::QUEUE_OPTION, $q, false);
};
$in_queue = function ($team_id) { $q = RSL_Avatars::queued(); return isset($q[intval($team_id)]); };
$flash = function () {
    $f = get_transient(RSL_Portal::FLASH_PREFIX . get_current_user_id());
    delete_transient(RSL_Portal::FLASH_PREFIX . get_current_user_id());
    return is_array($f) ? $f : array('type' => '', 'message' => '');
};
$notice = function () {
    $n = get_transient(RSL_Admin::NOTICE_PREFIX . get_current_user_id());
    delete_transient(RSL_Admin::NOTICE_PREFIX . get_current_user_id());
    return is_array($n) ? $n : array('type' => '', 'message' => '');
};
// A small voxel-like SVG, as DiceBear's: a clip path by url(#...), a style that animates only
// with no-preference for reduced motion, use by href="#...". The seed and which image are in a comment.
$svg_for = function ($seed, $animated) {
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128" fill="none" aria-hidden="true"><!-- test ' . $seed . ' ' . ($animated ? 'animated' : 'still') . ' -->'
        . '<metadata><dc:title>Voxel Bot</dc:title></metadata><defs><clipPath id="c"><rect width="128" height="128" rx="25.6" ry="25.6"/></clipPath></defs>'
        . '<g clip-path="url(#c)"><rect width="128" height="128" fill="#b6e3f4"/><g id="a">'
        . ($animated ? '<style>@media (prefers-reduced-motion: no-preference) { @keyframes b { 0%, 100% { opacity: 0; } 50% { opacity: 1; } } }</style>' : '')
        . '<path d="M54.5 71.6h15v10h-15Z" fill="#000000" fill-opacity=".2"/></g><use href="#a"/></g></svg>';
};

try {

// --- Setup ---------------------------------------------------------------------------------------------
$stub = function ($pre, $args, $url) use (&$http, $svg_for) {
    $http['all'][] = (string) $url;
    if (strpos((string) $url, 'api.dicebear.com') === false) {
        return $pre;
    }
    $http['dicebear'][] = (string) $url;
    $q = array();
    parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $q);
    $seed = isset($q['seed']) ? (string) $q['seed'] : '';
    $animated = isset($q['tags']) && $q['tags'] === 'animation';
    $svg = $svg_for($seed, $animated);
    $headers = array('content-type' => 'image/svg+xml');
    $code = 200;
    switch ($http['mode']) {
        case 'error':
            return new WP_Error('http_request_failed', 'cURL error 28: Operation timed out');
        case '500':
            $code = 500;
            break;
        case 'type':
            $headers = array('content-type' => 'text/html; charset=utf-8');
            break;
        case 'script':
            $svg = str_replace('<use href="#a"/>', '<use href="#a"/><script>alert(1)</script>', $svg);
            break;
        case 'href':
            $svg = str_replace('<use href="#a"/>', '<use href="https://elsewhere.example.test/a.svg#a"/>', $svg);
            break;
        case 'big':
            $svg = str_replace('</svg>', '<!-- ' . str_repeat('x', RSL_Avatars::MAX_BYTES) . ' --></svg>', $svg);
            break;
        case 'still-bad':
            if (!$animated) {
                $svg = str_replace('<svg ', '<svg onload="alert(1)" ', $svg);
            }
            break;
    }
    return array('headers' => $headers, 'body' => $svg, 'response' => array('code' => $code, 'message' => $code === 200 ? 'OK' : 'Internal Server Error'),
        'cookies' => array(), 'filename' => null);
};
add_filter('pre_http_request', $stub, 10, 3);
$check('setup: schema 13 with teams.avatar_seed', RSL_Database::schema_current() && RSL_Database::SCHEMA_VERSION === '13'
    && (bool) $wpdb->get_var("SHOW COLUMNS FROM " . RSL_Database::table('teams') . " LIKE 'avatar_seed'"));
$check('setup: the job is hooked, and put off a day by the mail stop', has_action(RSL_Avatars::CRON_HOOK, array('RSL_Avatars', 'work')) !== false
    && wp_next_scheduled(RSL_Avatars::CRON_HOOK) > time() + DAY_IN_SECONDS - 120);
RSL_Courts::save_settings($A, array('start' => $day(-14), 'end' => $day(30), 'weekday' => 0));
RSL_Courts::save_settings($S, array('start' => $day(40), 'end' => $day(100), 'weekday' => 0));
$cands = function ($keys) use ($A, $S) { return array($A, $S); };
add_filter('rsl_open_for_signup_candidates', $cands);
$check('setup: 2084 Pre-season is open for sign-up and comes after 2083 Post-season, which has started',
    isset(RSL_Seasons::open_for_signup()[$S]) && RSL_Seasons::next($A) === $S && RSL_Seasons::has_started($A));

// --- new_seed() and the address ---------------------------------------------------------------------------
$seeds = array();
for ($i = 0; $i < 200; $i++) {
    $seeds[] = RSL_Avatars::new_seed();
}
$check('new_seed(): 16 lower-case hex characters, every one valid', count(array_filter($seeds, function ($s) { return preg_match('/^[a-f0-9]{16}$/', $s) === 1 && RSL_Avatars::valid_seed($s); })) === 200);
$check('...and random: 200 in a row, all different', count(array_unique($seeds)) === 200);
$check('valid_seed() refuses a name, an empty seed, capitals, the wrong length and a non-string',
    !RSL_Avatars::valid_seed('Block Party') && !RSL_Avatars::valid_seed('') && !RSL_Avatars::valid_seed('ABCDEF0123456789')
    && !RSL_Avatars::valid_seed('abcdef012345678') && !RSL_Avatars::valid_seed('abcdef01234567890') && !RSL_Avatars::valid_seed(1234567890123456));
$u_anim = RSL_Avatars::api_url('0123456789abcdef', true);
$u_still = RSL_Avatars::api_url('0123456789abcdef', false);
$bg = explode(',', (string) substr($u_anim, strpos($u_anim, 'backgroundColor=') + strlen('backgroundColor=')));
$check('the address: Voxel Bot on 10.x, the seed, flipped, rounded corners, 6 to 8 soft backgrounds; the animation tag on the animated one only',
    strpos($u_anim, 'https://api.dicebear.com/10.x/voxel-bot/svg?seed=0123456789abcdef&') === 0 && strpos($u_anim, '&flip=horizontal') !== false
    && strpos($u_anim, '&tags=animation') !== false && strpos($u_still, 'tags=') === false && strpos($u_anim, '&borderRadius=20') !== false
    && strpos($u_still, '&borderRadius=20') !== false && count($bg) >= 6 && count($bg) <= 8
    && count(array_filter($bg, function ($c) { return preg_match('/^[0-9a-f]{6}$/', $c) === 1; })) === count($bg)
    && str_replace('&tags=animation', '', $u_anim) === $u_still, $u_anim . ' / ' . $u_still);

// --- create() sets a seed and queues the team ---------------------------------------------------------------
$t1 = $mkteam($S, "Tee One $suffix");
$check('create(): a random seed (never the name) and the team on the queue', RSL_Avatars::valid_seed($t1->avatar_seed) && $in_queue($t1->id),
    wp_json_encode(array($t1->avatar_seed, RSL_Avatars::queued())));
$check('...and the job is due (the mail stop keeps it a day off while the test runs)', (bool) wp_next_scheduled(RSL_Avatars::CRON_HOOK));
$check('...no request made', $calls() === 0);
$kept = RSL_Avatars::new_seed();
$tk = $mkteam($S, "Tee Kept $suffix", array('avatar_seed' => $kept));
$tbad = $mkteam($S, "Tee Bad Seed $suffix", array('avatar_seed' => 'Tee Bad Seed'));
$check('...a seed passed in (roll_over()) is kept; anything that isn\'t a seed gets a new one', $tk->avatar_seed === $kept
    && RSL_Avatars::valid_seed($tbad->avatar_seed) && $tbad->avatar_seed !== 'Tee Bad Seed');
$admin = $mk('admin', 'administrator');
wp_set_current_user($admin);
RSL_Admin::process(array('rsl_nonce' => wp_create_nonce('rsl_team_action'), 'season' => $S, 'do' => 'create_team', 'name' => "Tee Admin $suffix", 'division' => 'unranked', 'colour' => '#8c2361'));
$notice();
$tadm = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . RSL_Database::table('teams') . " WHERE season = %s AND name = %s", $S, "Tee Admin $suffix"));
if ($tadm) {
    $team_ids[] = intval($tadm->id);
}
$check('admin Add a team: seeded and queued, with the colour the admin chose', $tadm && RSL_Avatars::valid_seed($tadm->avatar_seed) && $in_queue($tadm->id) && $tadm->colour === '#8c2361');
wp_set_current_user(0);

// --- The captain's form has no colour field ------------------------------------------------------------------
$fields = RSL_Portal::team_fields(array(), array($S => RSL_Seasons::label_with_dates($S, RSL_Seasons::span($S))), true);
$check('the team application\'s fields: name, division and level, no colour', strpos($fields, 'name="team_name"') !== false && strpos($fields, 'name="team_level"') !== false
    && strpos($fields, 'team_colour') === false && strpos($fields, 'type="color"') === false && stripos($fields, 'colour') === false);
$applicant = $mk('applicant', 'subscriber', true);
$app = RSL_Portal::apply_from_post($applicant, array('team_name' => "Tee Apply $suffix", 'team_division' => 'ranked', 'team_level' => 'social', 'team_colour' => '#123456', 'team_season' => $S));
if ($app && !is_wp_error($app)) {
    $team_ids[] = intval($app->id);
}
$check('...a colour posted (a page opened before 0.23.0) is ignored: the default navy, a seed, queued', $app && !is_wp_error($app) && $app->colour === '#1d3d6e'
    && RSL_Avatars::valid_seed($app->avatar_seed) && $in_queue($app->id), is_wp_error($app) ? $app->get_error_message() : wp_json_encode($app));

// --- The backfill ------------------------------------------------------------------------------------------------
$tb = $mkteam($S, "Tee Backfill $suffix");
$tdec = $mkteam($S, "Tee Declined $suffix", array('status' => 'pending'));
$wpdb->update(RSL_Database::table('teams'), array('avatar_seed' => ''), array('id' => $tb->id));
$wpdb->update(RSL_Database::table('teams'), array('avatar_seed' => '', 'status' => 'declined'), array('id' => $tdec->id));
RSL_Avatars::dequeue($tb->id);
RSL_Avatars::dequeue($tdec->id);
$n = RSL_Avatars::backfill();
$tb2 = RSL_Teams::get($tb->id);
$tdec2 = RSL_Teams::get($tdec->id);
$check('backfill(): a team with no seed gets one and goes on the queue', $n >= 2 && RSL_Avatars::valid_seed($tb2->avatar_seed) && $in_queue($tb->id), "seeded $n");
$check('...a declined application gets a seed but isn\'t queued (it is never drawn)', RSL_Avatars::valid_seed($tdec2->avatar_seed) && !$in_queue($tdec->id));
$check('...a team that had a seed keeps it', RSL_Teams::get($t1->id)->avatar_seed === $t1->avatar_seed);
RSL_Avatars::backfill();
$check('...run again, nothing of ours changes (safe to repeat)', RSL_Teams::get($tb->id)->avatar_seed === $tb2->avatar_seed && RSL_Teams::get($tdec->id)->avatar_seed === $tdec2->avatar_seed);
$check('...and the upgrade runs it', strpos((string) file_get_contents(RSL_PLUGIN_DIR . 'includes/class-rsl-database.php'), 'RSL_Avatars::backfill()') !== false);

// --- ensure() ------------------------------------------------------------------------------------------------------
$reset();
$http['mode'] = 'good';
$hash = substr(md5($t1->avatar_seed), 0, 12);
$ok = RSL_Avatars::ensure($t1);
$p1 = RSL_Avatars::paths($t1);
$check('ensure(): true, two requests to the API for the seed (animated, then still)', $ok && $calls() === 2
    && strpos($http['dicebear'][0], 'seed=' . $t1->avatar_seed . '&') !== false && strpos($http['dicebear'][0], 'tags=animation') !== false
    && strpos($http['dicebear'][1], 'seed=' . $t1->avatar_seed . '&') !== false && strpos($http['dicebear'][1], 'tags=') === false, wp_json_encode($http['dicebear']));
$check('...two files, named by the team id and the seed\'s hash, in uploads/rsl-team-avatars', $files_of($t1->id) === array('team-' . $t1->id . '-' . $hash . '-still.svg', 'team-' . $t1->id . '-' . $hash . '.svg')
    && $p1['animated'] === $dir . '/team-' . $t1->id . '-' . $hash . '.svg', wp_json_encode($files_of($t1->id)) . ' in ' . $dir);
$check('...holding what the API sent (it passed the check unchanged)', (string) @file_get_contents($p1['animated']) === $svg_for($t1->avatar_seed, true)
    && (string) @file_get_contents($p1['still']) === $svg_for($t1->avatar_seed, false));
$check('...the folder has an index.php', file_exists($dir . '/index.php'));
$reset();
$check('...again: true with no request (the files are there)', RSL_Avatars::ensure($t1) && $calls() === 0);
$check('...a team with no seed, or no id: false, no request', !RSL_Avatars::ensure((object) array('id' => $t1->id, 'avatar_seed' => ''))
    && !RSL_Avatars::ensure((object) array('name' => 'x', 'colour' => '#000000')) && $calls() === 0);

// --- The check before saving, and a failed fetch saves nothing ------------------------------------------------------
$good = $svg_for('0123456789abcdef', true);
$check('clean(): the voxel-like SVG passes unchanged (trimmed)', RSL_Avatars::clean($good) === $good && RSL_Avatars::clean("\n " . $good . " \n") === $good);
$bad = array(
    'a script' => str_replace('</svg>', '<script>alert(1)</script></svg>', $good),
    'a script with a prefix' => str_replace('</svg>', '<h:script xmlns:h="http://www.w3.org/1999/xhtml">alert(1)</h:script></svg>', $good),
    'a foreignObject' => str_replace('</svg>', '<foreignObject><div>x</div></foreignObject></svg>', $good),
    'an onload attribute' => str_replace('<svg ', '<svg onload="alert(1)" ', $good),
    'an onclick attribute' => str_replace('<use href', '<use onclick="x()" href', $good),
    'javascript:' => str_replace('</svg>', '<a href="javascript:alert(1)"><rect/></a></svg>', $good),
    'an href elsewhere' => str_replace('<use href="#a"/>', '<use href="https://elsewhere.example.test/a.svg#a"/>', $good),
    'an xlink:href elsewhere' => str_replace('<use href="#a"/>', '<use xlink:href="//elsewhere.example.test/a"/>', $good),
    'a data: href' => str_replace('<use href="#a"/>', '<image href="data:image/png;base64,AAAA"/>', $good),
    'a DOCTYPE' => '<!DOCTYPE svg>' . $good,
    'an ENTITY' => str_replace('<defs>', '<!ENTITY x "y"><defs>', $good),
    'a numeric character reference' => str_replace('url(#c)', 'url&#40;https://x&#41;', $good),
    'a url() elsewhere' => str_replace('url(#c)', 'url(https://elsewhere.example.test/x)', $good),
    '@import' => str_replace('@media', '@import "x.css"; @media', $good),
    'an animation setting an href' => str_replace('</svg>', '<set attributeName="href" to="#a"/></svg>', $good),
    'not starting with <svg' => '<html>' . $good . '</html>',
    'cut off' => substr($good, 0, -6),
    'over 64 KB' => str_replace('</svg>', '<!-- ' . str_repeat('x', RSL_Avatars::MAX_BYTES) . ' --></svg>', $good),
    'empty' => '',
);
$let_through = array();
foreach ($bad as $label => $svg) {
    if (RSL_Avatars::clean($svg) !== null) {
        $let_through[] = $label;
    }
}
$check('clean() refuses each: ' . implode(', ', array_keys($bad)), !$let_through && RSL_Avatars::clean(null) === null, 'let through: ' . implode(', ', $let_through));
$t2 = $mkteam($S, "Tee Two $suffix");
foreach (array('script' => 'a script in the image', 'href' => 'a link elsewhere in the image', 'still-bad' => 'a good animated image but a bad still one',
    'big' => 'an image over 64 KB', '500' => 'a 500', 'type' => 'the wrong content type', 'error' => 'a timeout') as $mode => $words) {
    $http['mode'] = $mode;
    $reset();
    $ok = RSL_Avatars::ensure($t2);
    $check("ensure() with $words: false, nothing saved", !$ok && $files_of($t2->id) === array() && RSL_Avatars::urls($t2) === null && $calls() >= 1,
        wp_json_encode(array($ok, $files_of($t2->id), $calls())));
}
$http['mode'] = 'good';

// --- The badge --------------------------------------------------------------------------------------------------------
$urls = RSL_Avatars::urls($t1);
$b = RSL_Public::badge($t1);
$base = untrailingslashit($up['baseurl']) . '/' . RSL_Avatars::DIR . '/team-' . $t1->id . '-' . $hash;
$check('urls(): this site\'s files, animated and still', $urls === array('animated' => $base . '.svg', 'still' => $base . '-still.svg'), wp_json_encode($urls));
$check('badge() with files: a picture, the still image for reduced motion, the animated one in the img',
    strpos($b, '<picture class="rsl-avatar-pic" aria-hidden="true">') === 0
    && strpos($b, '<source srcset="' . esc_url($base . '-still.svg') . '" media="(prefers-reduced-motion: reduce)">') !== false
    && strpos($b, '<img src="' . esc_url($base . '.svg') . '" alt="" width="44" height="44" loading="lazy" decoding="async" class="rsl-badge rsl-avatar">') !== false
    && substr($b, -10) === '</picture>', $b);
$bl = RSL_Public::badge($t1, 'large');
$bs = RSL_Public::badge($t1, 'small');
$check('...large is 72 (the team page), small 28 (fixtures and the ladder), each with its size class', strpos($bl, 'class="rsl-avatar-pic rsl-avatar-pic-large"') !== false
    && strpos($bl, 'width="72" height="72"') !== false && strpos($bl, 'class="rsl-badge rsl-avatar rsl-badge-large"') !== false
    && strpos($bs, 'width="28" height="28"') !== false && strpos($bs, 'class="rsl-badge rsl-avatar rsl-badge-small"') !== false
    && strpos($bs, 'rsl-avatar-pic-small') !== false, $bl . ' / ' . $bs);
$check('...no address but this site\'s: nothing that names DiceBear', stripos($b . $bl . $bs, 'dicebear') === false && strpos($b, 'http') === strpos($b, esc_url($base)));
$src = '';
foreach (array('class-rsl-public.php', 'class-rsl-portal.php', 'class-rsl-admin.php') as $file) {
    $src .= strpos((string) file_get_contents(RSL_PLUGIN_DIR . 'includes/' . $file), 'RSL_Avatars::css()') !== false ? 'y' : 'n';
}
$check('...every stylesheet with a badge rounds its corners (as the image does) before it loads', $src === 'yyy'
    && strpos(RSL_Avatars::css(), 'img.rsl-badge.rsl-avatar') !== false && strpos(RSL_Avatars::css(), 'border-radius: 20%') !== false, $src);
RSL_Avatars::dequeue($t2->id);
$want2 = '<span class="rsl-badge" style="background:#1d3d6e" aria-hidden="true">TT</span>';
$check('badge() without files: today\'s initials badge exactly', RSL_Public::badge($t2) === $want2, RSL_Public::badge($t2));
$check('...small too', RSL_Public::badge($t2, 'small') === '<span class="rsl-badge rsl-badge-small" style="background:#1d3d6e" aria-hidden="true">TT</span>');
$check('...and the team goes on the queue', $in_queue($t2->id));
$q_before = RSL_Avatars::queued();
$partial = RSL_Public::badge((object) array('colour' => '#8c2361', 'name' => "Tee One $suffix"));
$check('a partial object (a colour and a name, as the free agent requests build): the initials, nothing queued',
    $partial === '<span class="rsl-badge" style="background:#8c2361" aria-hidden="true">TO</span>' && RSL_Avatars::queued() === $q_before, $partial);
$side = new ReflectionMethod('RSL_Public_Fixtures', 'side_team');
$side->setAccessible(true);
$g = (object) array('home_team_id' => $t1->id, 'home_name' => $t1->name, 'home_slug' => $t1->slug, 'home_colour' => $t1->colour, 'home_avatar_seed' => $t1->avatar_seed,
    'away_team_id' => $t2->id, 'away_name' => $t2->name, 'away_slug' => $t2->slug, 'away_colour' => $t2->colour, 'away_avatar_seed' => $t2->avatar_seed);
$home = $side->invoke(null, $g, 'home');
$away = $side->invoke(null, $g, 'away');
$check('fixtures: each side of a game carries its team id and seed, so its badge is the avatar', intval($home->id) === intval($t1->id) && $home->avatar_seed === $t1->avatar_seed
    && strpos(RSL_Public::badge($home, 'small'), '<picture') === 0 && strpos(RSL_Public::badge($away, 'small'), '<span class="rsl-badge rsl-badge-small"') === 0);
$sql = new ReflectionMethod('RSL_Fixtures', 'select_sql');
$sql->setAccessible(true);
$check('...the games\' query reads both teams\' seeds', strpos($sql->invoke(null), 'h.avatar_seed AS home_avatar_seed, a.avatar_seed AS away_avatar_seed') !== false);

// --- A public page with missing files makes no request ---------------------------------------------------------------
$t3 = $mkteam($S, "Tee Three $suffix");
RSL_Avatars::dequeue($t3->id);
$public = new RSL_Public();
$render = function ($as, $slug = '') use ($public, $S) {
    wp_set_current_user($as);
    $_GET['season'] = $S;
    set_query_var(RSL_Public::QUERY_VAR, $slug);
    return $public->render_teams();
};
$reset();
$list = $render(0);
$page3 = $render(0, $t3->slug);
$check('the League Teams list and a team page with missing files: no request to DiceBear', $calls() === 0, wp_json_encode($http['all']));
$check('...the team is drawn as its initials and goes on the queue', strpos($list, '<span class="rsl-badge" style="background:#1d3d6e" aria-hidden="true">TT</span>') !== false
    && strpos($page3, '<span class="rsl-badge rsl-badge-large" style="background:#1d3d6e" aria-hidden="true">TT</span>') !== false && $in_queue($t3->id));
$check('...a team with files is its avatar, and no page names DiceBear', strpos($list, esc_url($base . '.svg')) !== false && stripos($list . $page3, 'dicebear') === false);
$check('...nobody logged out gets the New avatar button', strpos($page3, 'class="rsl-avatar-new"') === false && strpos($list, 'class="rsl-avatar-new"') === false);

// --- The job -------------------------------------------------------------------------------------------------------------
$w = array();
for ($i = 1; $i <= 12; $i++) {
    $w[$i] = $mkteam($S, "Tee Worker $i $suffix");
}
$wpdb->update(RSL_Database::table('teams'), array('avatar_seed' => ''), array('id' => $w[12]->id)); // queued with no seed
$set_queue(array_map(function ($t) { return $t->id; }, $w));
$reset();
wp_clear_scheduled_hook(RSL_Avatars::CRON_HOOK);
RSL_Avatars::work();
$when = wp_next_scheduled(RSL_Avatars::CRON_HOOK);
$hold_job();
$have = array();
foreach ($w as $i => $t) {
    $have[$i] = RSL_Avatars::urls(RSL_Teams::get($t->id)) !== null;
}
$check('work(): a batch of ' . RSL_Avatars::BATCH . ' teams, oldest first (20 requests)', RSL_Avatars::BATCH === 10 && $calls() === 20
    && count(array_filter(array_slice($have, 0, 10, true))) === 10 && !$have[11] && !$have[12], wp_json_encode(array($calls(), $have)));
$check('...the other two stay queued and the job is rescheduled (in ' . RSL_Avatars::GAP . ' seconds, not a day)', $in_queue($w[11]->id) && $in_queue($w[12]->id)
    && !$in_queue($w[1]->id) && !$in_queue($w[10]->id) && $when && $when <= time() + RSL_Avatars::GAP + 5 && $when >= time() + RSL_Avatars::GAP - 10, wp_json_encode(array($when, time())));
$reset();
wp_clear_scheduled_hook(RSL_Avatars::CRON_HOOK);
RSL_Avatars::work();
$when = wp_next_scheduled(RSL_Avatars::CRON_HOOK);
$w12 = RSL_Teams::get($w[12]->id);
$check('...the next run does the rest: a team queued with no seed gets one first, then its files', $calls() === 4 && RSL_Avatars::valid_seed($w12->avatar_seed)
    && RSL_Avatars::urls($w12) !== null && RSL_Avatars::urls(RSL_Teams::get($w[11]->id)) !== null && !$in_queue($w[11]->id) && !$in_queue($w[12]->id));
$check('...and with the queue empty, no run is scheduled', RSL_Avatars::queued() ? true : $when === false, wp_json_encode(array($when, RSL_Avatars::queued())));
$hold_job();
$f = array();
for ($i = 1; $i <= 3; $i++) {
    $f[$i] = $mkteam($S, "Tee Fail $i $suffix");
}
$set_queue(array($f[1]->id, $f[2]->id, $f[3]->id));
$http['mode'] = '500';
$reset();
wp_clear_scheduled_hook(RSL_Avatars::CRON_HOOK);
RSL_Avatars::work();
$when = wp_next_scheduled(RSL_Avatars::CRON_HOOK);
$q = RSL_Avatars::queued();
$check('a failed fetch: the run stops there (one request), the team waits 10 minutes, the rest untouched', $calls() === 1 && isset($q[$f[1]->id]) && intval($q[$f[1]->id]['tries']) === 1
    && intval($q[$f[1]->id]['next']) >= time() + 590 && isset($q[$f[2]->id], $q[$f[3]->id]) && intval($q[$f[2]->id]['tries']) === 0 && $files_of($f[1]->id) === array(), wp_json_encode($q));
$check('...rescheduled for the others', $when && $when <= time() + RSL_Avatars::GAP + 5);
$http['mode'] = 'good';
$reset();
wp_clear_scheduled_hook(RSL_Avatars::CRON_HOOK);
RSL_Avatars::work();
$when = wp_next_scheduled(RSL_Avatars::CRON_HOOK);
$check('...the next run leaves the waiting team alone and does the others', $calls() === 4 && $in_queue($f[1]->id) && !$in_queue($f[2]->id) && !$in_queue($f[3]->id)
    && RSL_Avatars::urls(RSL_Teams::get($f[3]->id)) !== null && $files_of($f[1]->id) === array());
$check('...and the job comes back when the waiting team may be tried', $when && $when >= time() + 500, wp_json_encode(array($when, time())));
$hold_job();
$tgone = $mkteam($S, "Tee Withdrawn $suffix", array('status' => 'pending'));
$wpdb->update(RSL_Database::table('teams'), array('status' => 'withdrawn'), array('id' => $tgone->id));
$set_queue(array($tgone->id, 2147483000));
$reset();
wp_clear_scheduled_hook(RSL_Avatars::CRON_HOOK);
RSL_Avatars::work();
$hold_job();
$check('a withdrawn application and a team that no longer exists come off the queue, no request', $calls() === 0 && !$in_queue($tgone->id) && !$in_queue(2147483000));
$set_queue(array($f[1]->id));
RSL_Avatars::forget_missing();
$check('forget_missing() keeps a team that exists', $in_queue($f[1]->id));
$set_queue(array());

// --- regenerate() ------------------------------------------------------------------------------------------------------------
$cap = $mk('cap');
$member = $mk('member');
$stranger = $mk('stranger');
RSL_Access::ensure_role();
$la = $mk('la', RSL_Access::ROLE);
RSL_Teams::add_member($t1->id, $cap, 'captain', false, array('admin' => true));
RSL_Teams::add_member($t1->id, $member, 'player', false, array('admin' => true));
$check('setup: the captain, a member, a stranger and a league admin', RSL_Teams::is_captain($t1->id, $cap) && RSL_Teams::is_member($t1->id, $member)
    && !RSL_Teams::is_captain($t1->id, $member) && RSL_Access::can($la) && !RSL_Access::can($member) && !RSL_Access::can($cap));
$url1 = RSL_Public::team_url($t1);
$page_cap = $render($cap, $t1->slug);
$check('the team page: New avatar by the header for the captain, posting back to the team page', strpos($page_cap, '<form method="post" class="rsl-avatar-new">') !== false
    && strpos($page_cap, 'name="do" value="new_avatar"') !== false && strpos($page_cap, 'name="team_id" value="' . $t1->id . '"') !== false
    && strpos($page_cap, 'name="back" value="' . esc_attr($url1) . '"') !== false && strpos($page_cap, '>New avatar</button>') !== false
    && strpos($page_cap, 'class="rsl-avatar-new"') < strpos($page_cap, 'class="rsl-portal rsl-team-tools"'));
$check('...for a league admin too; not for a plain member', strpos($render($la, $t1->slug), 'class="rsl-avatar-new"') !== false
    && strpos($render($member, $t1->slug), 'class="rsl-avatar-new"') === false);
$check('avatar_button(): \'\' for a stranger and for nobody', RSL_Portal::avatar_button($t1, $stranger) === '' && RSL_Portal::avatar_button($t1, 0) === '');
// A file of this team's replaced long ago: tidied by the next new avatar. The files just replaced stay.
$stale = $dir . '/team-' . $t1->id . '-aaaaaaaaaaaa.svg';
file_put_contents($stale, $good);
touch($stale, time() - RSL_Avatars::KEEP_OLD - 3600);
$old1 = RSL_Avatars::paths(RSL_Teams::get($t1->id));
touch($old1['animated'], time() - RSL_Avatars::KEEP_OLD - 3600); // the old avatar, fetched long ago
$reset();
wp_set_current_user($cap);
$to = RSL_Portal::process(array('rsl_nonce' => wp_create_nonce('rsl_front'), 'rsl_front' => '1', 'do' => 'new_avatar', 'team_id' => $t1->id, 'back' => $url1));
$fl = $flash();
$t1b = RSL_Teams::get($t1->id);
$new1 = RSL_Avatars::paths($t1b);
clearstatcache();
$check('the captain\'s New avatar: back to the team page with the flash', $to === $url1 && $fl['type'] === 'success' && $fl['message'] === 'New avatar saved. Not keen? Try again.', wp_json_encode(array($to, $fl)));
$check('...a new seed, its two files fetched (two requests), the badge is the new one', RSL_Avatars::valid_seed($t1b->avatar_seed) && $t1b->avatar_seed !== $t1->avatar_seed
    && $calls() === 2 && file_exists($new1['animated']) && file_exists($new1['still']) && strpos(RSL_Public::badge($t1b), basename($new1['animated'])) !== false);
$check('...the files just replaced stay (a cached page may show them) with a fresh time; a file replaced long ago is gone',
    file_exists($old1['animated']) && file_exists($old1['still']) && filemtime($old1['animated']) >= time() - 60 && !file_exists($stale));
$check('...it counted one try today', intval(get_transient(RSL_Avatars::tries_key($t1->id))) === 1);
$reset();
$r = RSL_Avatars::regenerate($t1->id, $la);
$t1c = RSL_Teams::get($t1->id);
$check('a league admin: regenerate() gives a new seed too', !is_wp_error($r) && $t1c->avatar_seed !== $t1b->avatar_seed && RSL_Avatars::urls($t1c) !== null && $calls() === 2);
wp_set_current_user($la);
$reset();
$to = RSL_Admin::process(array('rsl_nonce' => wp_create_nonce('rsl_team_action'), 'season' => $S, 'do' => 'new_avatar', 'team_id' => $t1->id));
$nt = $notice();
$t1d = RSL_Teams::get($t1->id);
$check('...the Teams screen\'s New avatar: the notice, back to the team, a new seed', $nt['type'] === 'success' && $nt['message'] === 'New avatar saved. Not keen? Try again.'
    && strpos($to, 'team=' . $t1->id) !== false && $t1d->avatar_seed !== $t1c->avatar_seed && $calls() === 2, wp_json_encode(array($to, $nt)));
$edit = new ReflectionMethod('RSL_Admin', 'render_team');
$edit->setAccessible(true);
ob_start();
$edit->invoke(new RSL_Admin(), $t1d);
$screen = (string) ob_get_clean();
$check('...the team\'s edit screen has the New avatar button, the badge is the avatar, and the colour field stays', strpos($screen, 'name="do" value="new_avatar"') !== false
    && strpos($screen, '>New avatar</button>') !== false && strpos($screen, '<picture class="rsl-avatar-pic"') !== false && strpos($screen, 'name="colour"') !== false);
$seed_now = $t1d->avatar_seed;
$files_now = $files_of($t1->id);
foreach (array($member => 'a plain member', $stranger => 'a stranger') as $who => $words) {
    $reset();
    $r = RSL_Avatars::regenerate($t1->id, $who);
    $check("regenerate() by $words: refused, nothing asked, the avatar kept", is_wp_error($r) && $r->get_error_message() === 'Only the captain can change the team\'s avatar.'
        && $calls() === 0 && RSL_Teams::get($t1->id)->avatar_seed === $seed_now && $files_of($t1->id) === $files_now);
}
wp_set_current_user($member);
$to = RSL_Portal::process(array('rsl_nonce' => wp_create_nonce('rsl_front'), 'rsl_front' => '1', 'do' => 'new_avatar', 'team_id' => $t1->id, 'back' => $url1));
$fl = $flash();
$check('...the member through the league page: refused, back to the team page', $to === $url1 && $fl['type'] === 'error' && $fl['message'] === 'Only the team captain can do that.'
    && RSL_Teams::get($t1->id)->avatar_seed === $seed_now, wp_json_encode(array($to, $fl)));
wp_set_current_user($cap);
$http['mode'] = '500';
$reset();
$to = RSL_Portal::process(array('rsl_nonce' => wp_create_nonce('rsl_front'), 'rsl_front' => '1', 'do' => 'new_avatar', 'team_id' => $t1->id, 'back' => $url1));
$fl = $flash();
$check('a failed fetch: refused with the sentence, the seed and files as they were', $to === $url1 && $fl['type'] === 'error'
    && $fl['message'] === 'A new avatar couldn\'t be fetched just now, so the old one stays. Try again in a few minutes.'
    && RSL_Teams::get($t1->id)->avatar_seed === $seed_now && $files_of($t1->id) === $files_now && RSL_Avatars::urls(RSL_Teams::get($t1->id)) !== null, wp_json_encode($fl));
$http['mode'] = 'still-bad';
$r = RSL_Avatars::regenerate($t1->id, $cap);
$check('...a still image the check refuses: the same, nothing saved', is_wp_error($r) && $r->get_error_code() === 'rsl_avatar_fetch'
    && RSL_Teams::get($t1->id)->avatar_seed === $seed_now && $files_of($t1->id) === $files_now);
$http['mode'] = 'good';
$check('...every try that asked counts (5 so far today)', intval(get_transient(RSL_Avatars::tries_key($t1->id))) === 5);
set_transient(RSL_Avatars::tries_key($t1->id), RSL_Avatars::DAILY_LIMIT - 1, DAY_IN_SECONDS);
$reset();
$r = RSL_Avatars::regenerate($t1->id, $cap);
$check('the daily cap: the 20th of the day works', !is_wp_error($r) && $calls() === 2 && intval(get_transient(RSL_Avatars::tries_key($t1->id))) === RSL_Avatars::DAILY_LIMIT);
$seed_now = RSL_Teams::get($t1->id)->avatar_seed;
$reset();
$r = RSL_Avatars::regenerate($t1->id, $cap);
$r2 = RSL_Avatars::regenerate($t1->id, $la);
$check('...the 21st is refused, for the captain and a league admin alike, nothing asked, the avatar kept', is_wp_error($r) && $r->get_error_message() === 'You\'ve tried 20 avatars today; try again tomorrow.'
    && is_wp_error($r2) && $r2->get_error_code() === 'rsl_avatar_limit' && $calls() === 0 && RSL_Teams::get($t1->id)->avatar_seed === $seed_now);
$check('...counted per team: another team is free', !is_wp_error(RSL_Avatars::regenerate($t2->id, $la)));
$check('regenerate(): a team that no longer exists', RSL_Avatars::regenerate(2147483000, $la)->get_error_message() === 'That team no longer exists.');
wp_set_current_user(0);

// --- The schema upgrade still to finish ------------------------------------------------------------------------------------------
$behind = function () { return '12'; };
add_filter('pre_option_' . RSL_Database::SCHEMA_OPTION, $behind);
$tz = $mkteam($S, "Tee Upgrade $suffix");
$r = RSL_Avatars::regenerate($t1->id, $la);
$reset();
$set_queue(array($tz->id));
wp_clear_scheduled_hook(RSL_Avatars::CRON_HOOK);
RSL_Avatars::work();
$when = wp_next_scheduled(RSL_Avatars::CRON_HOOK);
$hold_job();
$check('while the upgrade is to finish: create() writes no seed (the column may not be there) and queues', !RSL_Database::schema_current() && $tz->avatar_seed === '' && $in_queue($tz->id));
$check('...regenerate() refuses', is_wp_error($r) && $r->get_error_message() === 'The league is being updated right now — please try again in a minute.');
$check('...the job waits five minutes, asking nothing', $calls() === 0 && $in_queue($tz->id) && $when && $when >= time() + 290);
$check('...the games\' query reads no seed column', strpos($sql->invoke(null), "'' AS home_avatar_seed, '' AS away_avatar_seed") !== false);
remove_filter('pre_option_' . RSL_Database::SCHEMA_OPTION, $behind);
$behind = null;
$set_queue(array());

// --- roll_over() keeps the seed ---------------------------------------------------------------------------------------------------
$rc = $mk('rollcap', 'subscriber', true);
$rc2 = $mk('rollcap2', 'subscriber', true);
$ro = $mkteam($A, "Tee Rolling $suffix", array('level' => 'strong'));
$ro2 = $mkteam($A, "Tee Rolling Two $suffix", array('level' => 'social'));
RSL_Teams::add_member($ro->id, $rc, 'captain', false, array('admin' => true));
RSL_Teams::add_member($ro2->id, $rc2, 'captain', false, array('admin' => true));
RSL_Avatars::ensure($ro);
$reset();
$rolled = RSL_Teams::roll_over($ro->id, $rc);
if ($rolled && !is_wp_error($rolled)) {
    $team_ids[] = intval($rolled->id);
}
$check('roll_over(): the team entered again has the old team\'s seed', $rolled && !is_wp_error($rolled) && $rolled->season === $S && $rolled->avatar_seed === $ro->avatar_seed,
    is_wp_error($rolled) ? $rolled->get_error_message() : wp_json_encode($rolled));
$pr = $rolled && !is_wp_error($rolled) ? RSL_Avatars::paths($rolled) : null;
$po = RSL_Avatars::paths($ro);
$check('...and the same images, copied to its own names with no request, so nothing is queued', $pr && RSL_Avatars::urls($rolled) !== null && $calls() === 0
    && basename($pr['animated']) === 'team-' . $rolled->id . '-' . substr(md5($ro->avatar_seed), 0, 12) . '.svg'
    && file_get_contents($pr['animated']) === file_get_contents($po['animated']) && file_get_contents($pr['still']) === file_get_contents($po['still']) && !$in_queue($rolled->id));
$rolled2 = RSL_Teams::roll_over($ro2->id, $rc2);
if ($rolled2 && !is_wp_error($rolled2)) {
    $team_ids[] = intval($rolled2->id);
}
$check('...an old team with no files yet: the seed is kept and the new team is queued for them', $rolled2 && !is_wp_error($rolled2) && $rolled2->avatar_seed === $ro2->avatar_seed
    && RSL_Avatars::urls($rolled2) === null && $in_queue($rolled2->id) && $calls() === 0, is_wp_error($rolled2) ? $rolled2->get_error_message() : '');
$check('...the old teams keep theirs', RSL_Teams::get($ro->id)->avatar_seed === $ro->avatar_seed && RSL_Avatars::urls(RSL_Teams::get($ro->id)) !== null);

} catch (Throwable $thrown) {
    $fails++;
    echo 'FAIL: threw ' . get_class($thrown) . ': ' . $thrown->getMessage() . ' (line ' . $thrown->getLine() . ")\n";
} finally {
// --- Cleanup (runs after a failure too) -------------------------------------------------------------------------------------------
wp_set_current_user(0);
set_query_var(RSL_Public::QUERY_VAR, '');
$_GET = array();
if ($stub) {
    remove_filter('pre_http_request', $stub, 10);
}
if ($cands) {
    remove_filter('rsl_open_for_signup_candidates', $cands);
}
if ($behind) {
    remove_filter('pre_option_' . RSL_Database::SCHEMA_OPTION, $behind);
}
foreach (array($A, $S) as $s_key) {
    foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM " . RSL_Database::table('teams') . " WHERE season = %s", $s_key)) as $tid) {
        $team_ids[] = intval($tid);
    }
}
$team_ids = array_values(array_unique(array_filter(array_map('intval', $team_ids))));
foreach ($team_ids as $tid) {
    foreach (array('ledger', 'requests', 'invites', 'join_requests', 'team_members') as $tbl) {
        $wpdb->delete(RSL_Database::table($tbl), array('team_id' => $tid));
    }
    $wpdb->delete(RSL_Database::table('teams'), array('id' => $tid));
    // The test's files (and any temporary one a failure left), and its tries today.
    foreach ((array) glob($dir . '/team-' . $tid . '-*') as $file) {
        if (is_string($file) && $file !== '') {
            @unlink($file);
        }
    }
    delete_transient(RSL_Avatars::tries_key($tid));
}
// The queue as it was, with anything another request added meanwhile (never the test's teams).
$now_q = RSL_Avatars::queued();
$restored = $queue_before;
foreach ($now_q as $tid => $item) {
    if (!in_array(intval($tid), $team_ids, true) && !isset($restored[$tid])) {
        $restored[$tid] = $item;
    }
}
foreach ($team_ids as $tid) {
    unset($restored[$tid]);
}
if ($restored) {
    update_option(RSL_Avatars::QUEUE_OPTION, $restored, false);
} else {
    delete_option(RSL_Avatars::QUEUE_OPTION);
}
// The folder, when this test made it and left nothing else in it.
if (!$dir_was && is_dir($dir)) {
    $left_files = array_diff((array) scandir($dir), array('.', '..', 'index.php'));
    if (!$left_files) {
        @unlink($dir . '/index.php');
        @rmdir($dir);
    }
}
if ($courts_before === '__unset__') {
    delete_option(RSL_Courts::SETTINGS_OPTION);
} else {
    update_option(RSL_Courts::SETTINGS_OPTION, $courts_before, false);
}
foreach (array($A, $S) as $s_key) {
    delete_transient(RSL_Stats::CACHE_PREFIX . $s_key);
}
foreach ($users as $u) {
    if (is_int($u) && $u > 0) {
        $wpdb->delete(RSL_Database::table('team_members'), array('user_id' => $u));
        $wpdb->delete(RSL_Database::table('players'), array('user_id' => $u));
        $wpdb->delete(RSL_Database::table('free_agents'), array('user_id' => $u));
        $wpdb->delete(RSL_Database::table('officials'), array('user_id' => $u));
    }
}
rsl_dev_delete_users($users);
// Last: the mail stop comes off, the avatar job goes back (only while anything is queued).
rsl_dev_release_mail($hold);
}

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
