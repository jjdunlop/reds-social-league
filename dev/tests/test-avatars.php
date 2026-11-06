<?php
/**
 * Team avatars (0.23.0): new_seed() (random, its format), the pinned picks (deterministic per
 * seed, different across seeds, from the style's own lists, the glow never the body colour, the
 * example seed's picks pinned forever), each stage's address (exactly the right parts and
 * probabilities, the colours, solid or linear with its stops, order fixed, angle 45, animation and
 * speed on the animated one only, stage 8 the cap, each stage only adding), RSL_Teams::create()
 * setting a seed and stage 1 and queueing the team (admin Add a team and the captain application
 * too), the captain's application keeping the robot chosen (the card and Start a team at
 * registration), the schema 13 backfill, ensure() writing two files named by the team id, the
 * seed's hash and the stage, the check before saving (clean()) refusing each bad case with nothing
 * saved (elements and namespaces off its lists, a script under a prefix that isn't ASCII, CSS
 * escapes, processing instructions, CDATA), a failed fetch saving nothing, RSL_Public::badge() with files (the picture, the
 * reduced-motion source, the sizes, no external address, the stored stage) and without (today's
 * initials badge exactly, partial objects too), the fixtures' sides carrying the seed and stage, a
 * public page with missing files making no request and queueing, the chooser (two images, Level 1
 * and Fully built, 12 candidates with valid seeds, the hidden field, no innerHTML in the script,
 * nothing but api.dicebear.com in the previews, the team name in none, the script once a page), the
 * job's batch, its reschedule, a failure's wait and teams with nothing to draw (a gone team's files
 * listed to go), the results hook (record, forfeit, clear, publish, unpublish, delete), a run put
 * off by a failed fetch brought forward by results and new teams (never while a test holds it), the
 * stats fingerprint leaving the stage out, the stage
 * job (1 to 3 and 8 after Season Points change: fetched, written, the old files listed; levels
 * worked out once a run; a ladder render asking nothing; back to 1 with no fetch), Use this one by
 * the captain (the league page's process(), the flash, the closed details under the header), by a
 * league admin (regenerate(), the team page's process() and the Teams screen's details), refused for a plain member and a stranger and a bad seed, a failed
 * fetch keeping the old robot, the daily cap, the stage kept, the purge list (New avatar, a stage
 * change, a declined and a withdrawn team; purge() keeping a file in use), the schema upgrade still
 * to finish, roll_over() keeping the seed (the files copied at stage 1, fetched again otherwise),
 * and the captain's form without a colour field.
 *
 *   wp eval-file dev/tests/test-avatars.php
 *
 * DiceBear is never called: the HTTP API is answered by a pre_http_request filter (a small
 * voxel-like SVG naming the seed and stage asked for, a bad one, a 500, the wrong type or a
 * timeout) that counts every request. Season Points are planted in RSL_Stats' saved copy under
 * the season's own fingerprint. Far-future throwaway seasons (2083 Post-season running now, 2084
 * Pre-season after it, dates from today), @example.test users; the queue, the stage flags, the
 * deletion list, the job, the files and options are put back; cleans up.
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
$flags_before = get_option(RSL_Avatars::STAGE_OPTION, '__unset__');
$purge_before = RSL_Avatars::purge_list();
$courts_before = get_option(RSL_Courts::SETTINGS_OPTION, '__unset__');
$up = wp_upload_dir(null, false);
$dir = untrailingslashit($up['basedir']) . '/' . RSL_Avatars::DIR;
$dir_was = is_dir($dir);
$stub = null;
$cands = null;
$behind = null;
$current = null;
$counter = null;
$capture = null;
$script_flag = new ReflectionProperty('RSL_Avatars', 'script_done');
$script_flag->setAccessible(true);
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
$names = function ($team_id, $seed, $stage) {
    $base = 'team-' . intval($team_id) . '-' . substr(md5($seed), 0, 12) . '-s' . intval($stage);
    return array($base . '-still.svg', $base . '.svg');
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
$flagged = function ($season) { $f = get_option(RSL_Avatars::STAGE_OPTION, array()); return is_array($f) && isset($f[$season]); };
$listed = function ($name) { $l = RSL_Avatars::purge_list(); return isset($l[$name]) ? intval($l[$name]) : 0; };
$set_stage = function ($team_id, $stage) use ($wpdb) {
    $wpdb->update(RSL_Database::table('teams'), array('avatar_stage' => intval($stage)), array('id' => intval($team_id)));
    return RSL_Teams::get($team_id);
};
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
$query_of = function ($url) {
    $q = array();
    parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $q);
    return $q;
};
// The stage an address asks for: 1, plus each part from the mouth on, plus each background colour past the first.
$stage_of_url = function ($url) use ($query_of) {
    $q = $query_of($url);
    $n = 1;
    foreach (array('mouth', 'chest', 'top') as $part) {
        $n += isset($q[$part . 'Probability']) && $q[$part . 'Probability'] === '100' ? 1 : 0;
    }
    $bg = isset($q['backgroundColor']) ? count(explode(',', $q['backgroundColor'])) : 1;
    return $n + ($bg > 1 ? $bg - 1 : 0);
};
// A small voxel-like SVG, as DiceBear's: a clip path by url(#...), a style that animates only
// with no-preference for reduced motion, use by href="#...". The seed, stage and which image are in a comment.
$svg_for = function ($seed, $animated, $stage = 1) {
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 128 128" fill="none" aria-hidden="true"><!-- test ' . $seed . ' s' . intval($stage) . ' ' . ($animated ? 'animated' : 'still') . ' -->'
        . '<metadata><dc:title>Voxel Bot</dc:title></metadata><defs><clipPath id="c"><rect width="128" height="128" rx="25.6" ry="25.6"/></clipPath></defs>'
        . '<g clip-path="url(#c)"><rect width="128" height="128" fill="#b6e3f4"/><g id="a">'
        . ($animated ? '<style>@media (prefers-reduced-motion: no-preference) { @keyframes b { 0%, 100% { opacity: 0; } 50% { opacity: 1; } } }</style>' : '')
        . '<path d="M54.5 71.6h15v10h-15Z" fill="#000000" fill-opacity=".2"/></g><use href="#a"/></g></svg>';
};
// Season Points planted in RSL_Stats' saved copy of the season, under its own fingerprint (the real build, points changed).
$fp = new ReflectionMethod('RSL_Stats', 'fingerprint');
$fp->setAccessible(true);
$plant = function ($season, $points) use ($fp) {
    RSL_Stats::flush();
    RSL_Ratings::flush();
    delete_transient(RSL_Stats::CACHE_PREFIX . $season);
    $real = RSL_Stats::season($season);
    $found = 0;
    foreach ($points as $id => $p) {
        if (isset($real['teams'][$id])) {
            $real['teams'][$id]['points'] = $p;
            $found++;
        }
    }
    set_transient(RSL_Stats::CACHE_PREFIX . $season, array('key' => $fp->invoke(null, $season), 'data' => $real), HOUR_IN_SECONDS);
    RSL_Stats::flush();
    RSL_Ratings::flush();
    return $found === count($points);
};

try {

// --- Setup ---------------------------------------------------------------------------------------------
$stub = function ($pre, $args, $url) use (&$http, $svg_for, $query_of, $stage_of_url) {
    $http['all'][] = (string) $url;
    if (strpos((string) $url, 'api.dicebear.com') === false) {
        return $pre;
    }
    $http['dicebear'][] = (string) $url;
    $q = $query_of($url);
    $seed = isset($q['seed']) ? (string) $q['seed'] : '';
    $animated = isset($q['tags']) && $q['tags'] === 'animation';
    $svg = $svg_for($seed, $animated, $stage_of_url($url));
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
        case 'script-prefix': // a script under a prefix with a letter that isn't ASCII (an e with an acute accent), declared as the SVG namespace
            $svg = str_replace('<use href="#a"/>', "<use href=\"#a\"/><\xC3\xA9:script xmlns:\xC3\xA9=\"http://www.w3.org/2000/svg\">alert(1)</\xC3\xA9:script>", $svg);
            break;
        case 'escape': // url() spelled with a CSS escape
            $svg = str_replace('@media', 'svg { background: \\75 rl(https://elsewhere.example.test/x); } @media', $svg);
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
$check('setup: schema 14 with teams.avatar_seed and teams.avatar_stage', RSL_Database::schema_current() && RSL_Database::SCHEMA_VERSION === '14'
    && (bool) $wpdb->get_var("SHOW COLUMNS FROM " . RSL_Database::table('teams') . " LIKE 'avatar_seed'")
    && (bool) $wpdb->get_var("SHOW COLUMNS FROM " . RSL_Database::table('teams') . " LIKE 'avatar_stage'"));
$check('setup: the job, the results hook and the hourly check are hooked, and the job is put off a day by the mail stop',
    has_action(RSL_Avatars::CRON_HOOK, array('RSL_Avatars', 'work')) !== false && has_action(RSL_Avatars::RESULTS_HOOK, array('RSL_Avatars', 'stage_check')) !== false
    && has_action('rsl_hourly', array('RSL_Avatars', 'hourly')) !== false && wp_next_scheduled(RSL_Avatars::CRON_HOOK) > time() + DAY_IN_SECONDS - 120);
RSL_Courts::save_settings($A, array('start' => $day(-14), 'end' => $day(30), 'weekday' => 0));
RSL_Courts::save_settings($S, array('start' => $day(40), 'end' => $day(100), 'weekday' => 0));
$cands = function ($keys) use ($A, $S) { return array($A, $S); };
add_filter('rsl_open_for_signup_candidates', $cands);
$check('setup: 2084 Pre-season is open for sign-up and comes after 2083 Post-season, which has started',
    isset(RSL_Seasons::open_for_signup()[$S]) && RSL_Seasons::next($A) === $S && RSL_Seasons::has_started($A));
delete_option(RSL_Avatars::STAGE_OPTION);

// --- new_seed() ---------------------------------------------------------------------------------------------
$seeds = array();
for ($i = 0; $i < 200; $i++) {
    $seeds[] = RSL_Avatars::new_seed();
}
$check('new_seed(): 16 lower-case hex characters, every one valid', count(array_filter($seeds, function ($s) { return preg_match('/^[a-f0-9]{16}$/', $s) === 1 && RSL_Avatars::valid_seed($s); })) === 200);
$check('...and random: 200 in a row, all different', count(array_unique($seeds)) === 200);
$check('valid_seed() refuses a name, an empty seed, capitals, the wrong length, a trailing newline and a non-string',
    !RSL_Avatars::valid_seed('Block Party') && !RSL_Avatars::valid_seed('') && !RSL_Avatars::valid_seed('ABCDEF0123456789')
    && !RSL_Avatars::valid_seed('abcdef012345678') && !RSL_Avatars::valid_seed('abcdef01234567890') && !RSL_Avatars::valid_seed("abcdef0123456789\n")
    && !RSL_Avatars::valid_seed(1234567890123456) && RSL_Avatars::valid_seed('abcdef0123456789'));

// --- The pinned picks ---------------------------------------------------------------------------------------
$X = '3f9c2a7be01d4c58'; // the example seed
$px = RSL_Avatars::picks($X);
$check('picks(): the example seed\'s robot, pinned forever (a change here reshuffles every team\'s robot)', $px === array(
    'eyes' => 'square', 'mouth' => 'speaker', 'chest' => 'heart', 'top' => 'fin', 'speed' => 'slowest',
    'body' => 'b6a6f5', 'accent' => '495057', 'screen' => '1a1430', 'glow' => '3bc9db',
    'backgrounds' => array('f0c8ea', 'ffdfbf', 'f9e8a7', 'd1d4f9', 'd9efb5')), wp_json_encode($px));
$check('...from our own hash: each pick is pick() of the seed, the same every time', $px['eyes'] === RSL_Avatars::EYES[RSL_Avatars::pick($X, 'eyes', 7)]
    && $px['mouth'] === RSL_Avatars::MOUTHS[RSL_Avatars::pick($X, 'mouth', 5)] && $px['speed'] === RSL_Avatars::SPEEDS[RSL_Avatars::pick($X, 'speed', 5)]
    && RSL_Avatars::pick($X, 'eyes', 7) === RSL_Avatars::pick($X, 'eyes', 7) && RSL_Avatars::picks($X) === $px);
$check('...the style\'s own lists: 7 eyes, 5 mouths, 6 chests, 7 tops, 5 speeds (never none), 8 body, 6 accent, 4 screen and 7 glow colours, 10 backgrounds',
    count(RSL_Avatars::EYES) === 7 && count(RSL_Avatars::MOUTHS) === 5 && count(RSL_Avatars::CHESTS) === 6 && count(RSL_Avatars::TOPS) === 7
    && RSL_Avatars::SPEEDS === array('slowest', 'slow', 'medium', 'fast', 'fastest') && count(RSL_Avatars::BODY_COLOURS) === 8
    && count(RSL_Avatars::ACCENT_COLOURS) === 6 && count(RSL_Avatars::SCREEN_COLOURS) === 4 && count(RSL_Avatars::GLOW_COLOURS) === 7
    && count(array_unique(RSL_Avatars::BACKGROUNDS)) === 10);
$seen = array();
$tuples = array();
$bad_pick = array();
foreach ($seeds as $s) {
    $p = RSL_Avatars::picks($s);
    foreach (array('eyes' => RSL_Avatars::EYES, 'mouth' => RSL_Avatars::MOUTHS, 'chest' => RSL_Avatars::CHESTS, 'top' => RSL_Avatars::TOPS, 'speed' => RSL_Avatars::SPEEDS,
        'body' => RSL_Avatars::BODY_COLOURS, 'accent' => RSL_Avatars::ACCENT_COLOURS, 'screen' => RSL_Avatars::SCREEN_COLOURS, 'glow' => RSL_Avatars::GLOW_COLOURS) as $k => $list) {
        if (!in_array($p[$k], $list, true)) {
            $bad_pick[] = "$s $k";
        }
        $seen[$k][$p[$k]] = true;
    }
    if ($p['glow'] === $p['body'] || count(array_unique($p['backgrounds'])) !== 5 || array_diff($p['backgrounds'], RSL_Avatars::BACKGROUNDS)) {
        $bad_pick[] = "$s colours";
    }
    $tuples[wp_json_encode($p)] = true;
}
$check('...over 200 seeds: every pick from its list, the glow never the body colour, 5 different backgrounds', !$bad_pick, implode(', ', $bad_pick));
$check('...and they differ across seeds: every variant and speed turns up, and nearly every robot is different',
    count($seen['eyes']) === 7 && count($seen['mouth']) === 5 && count($seen['chest']) === 6 && count($seen['top']) === 7 && count($seen['speed']) === 5
    && count($tuples) >= 195, count($tuples) . ' different');

// --- Stages and the address ------------------------------------------------------------------------------------
$check('stage_for_points(): 40 points a level, stage = level, at most 8', RSL_Avatars::stage_for_points(0) === 1 && RSL_Avatars::stage_for_points(39) === 1
    && RSL_Avatars::stage_for_points(40) === 2 && RSL_Avatars::stage_for_points(85) === 3 && RSL_Avatars::stage_for_points(279) === 7
    && RSL_Avatars::stage_for_points(280) === 8 && RSL_Avatars::stage_for_points(5000) === 8 && RSL_Avatars::stage_for_level(0) === 1 && RSL_Avatars::stage_for_level(12) === 8);
$want_url1 = 'https://api.dicebear.com/10.x/voxel-bot/svg?seed=3f9c2a7be01d4c58&flip=horizontal&borderRadius=20&eyesVariant=square&eyesProbability=100'
    . '&mouthVariant=speaker&mouthProbability=0&chestVariant=heart&chestProbability=0&topVariant=fin&topProbability=0'
    . '&bodyColor=b6a6f5&accentColor=495057&screenColor=1a1430&glowColor=3bc9db&backgroundColor=f0c8ea&backgroundColorFill=solid'
    . '&backgroundColorOrder=fixed&backgroundColorAngle=45&tags=animation&animationVariant=slowest';
$check('the address at stage 1 (animated), exactly', RSL_Avatars::api_url($X, 1, true) === $want_url1, RSL_Avatars::api_url($X, 1, true));
$keys = array('seed', 'flip', 'borderRadius', 'eyesVariant', 'eyesProbability', 'mouthVariant', 'mouthProbability', 'chestVariant', 'chestProbability',
    'topVariant', 'topProbability', 'bodyColor', 'accentColor', 'screenColor', 'glowColor', 'backgroundColor', 'backgroundColorFill');
$stage_bad = array();
for ($st = 1; $st <= 8; $st++) {
    foreach (array(true, false) as $anim) {
        $u = RSL_Avatars::api_url($X, $st, $anim);
        $q = $query_of($u);
        $bg = $st < 5 ? array('f0c8ea') : array_slice(array('f0c8ea', 'ffdfbf', 'f9e8a7', 'd1d4f9', 'd9efb5'), 0, $st - 3);
        $want_keys = array_merge($keys, $st >= 5 ? array('backgroundColorFillStops') : array(), array('backgroundColorOrder', 'backgroundColorAngle'), $anim ? array('tags', 'animationVariant') : array());
        $ok = strpos($u, RSL_Avatars::API . '?') === 0 && array_keys($q) === $want_keys
            && $q['seed'] === $X && $q['flip'] === 'horizontal' && $q['borderRadius'] === '20'
            && $q['eyesVariant'] === 'square' && $q['eyesProbability'] === '100'
            && $q['mouthVariant'] === 'speaker' && $q['mouthProbability'] === ($st >= 2 ? '100' : '0')
            && $q['chestVariant'] === 'heart' && $q['chestProbability'] === ($st >= 3 ? '100' : '0')
            && $q['topVariant'] === 'fin' && $q['topProbability'] === ($st >= 4 ? '100' : '0')
            && $q['bodyColor'] === 'b6a6f5' && $q['accentColor'] === '495057' && $q['screenColor'] === '1a1430' && $q['glowColor'] === '3bc9db'
            && $q['backgroundColor'] === implode(',', $bg) && $q['backgroundColorFill'] === ($st >= 5 ? 'linear' : 'solid')
            && (!isset($q['backgroundColorFillStops']) || $q['backgroundColorFillStops'] === (string) count($bg))
            && $q['backgroundColorOrder'] === 'fixed' && $q['backgroundColorAngle'] === '45'
            && (!$anim || ($q['tags'] === 'animation' && $q['animationVariant'] === 'slowest'))
            && $stage_of_url($u) === $st;
        if (!$ok) {
            $stage_bad[] = "stage $st " . ($anim ? 'animated' : 'still') . ': ' . $u;
        }
    }
    if (RSL_Avatars::api_url($X, $st, false) . '&tags=animation&animationVariant=slowest' !== RSL_Avatars::api_url($X, $st, true)) {
        $stage_bad[] = "stage $st: the still image isn't the animated one without the animation";
    }
}
$check('each stage\'s address: body and eyes (glow on) from 1, the mouth from 2, the chest from 3, the top from 4, one solid background to 4, then linear at 45 with 2, 3, 4 and 5 stops, order fixed; the still one without the animation',
    !$stage_bad, implode(' | ', $stage_bad));
$added = array();
for ($st = 2; $st <= 8; $st++) {
    $a = RSL_Avatars::query($X, $st - 1, true);
    $b = RSL_Avatars::query($X, $st, true);
    $diff = array();
    foreach ($b as $k => $v) {
        if (!isset($a[$k]) || $a[$k] !== $v) {
            $diff[] = $k;
        }
    }
    $added[$st] = implode(',', $diff);
}
$check('...each stage only adds: a part\'s probability, then the gradient and a colour a stage', $added === array(2 => 'mouthProbability', 3 => 'chestProbability', 4 => 'topProbability',
    5 => 'backgroundColor,backgroundColorFill,backgroundColorFillStops', 6 => 'backgroundColor,backgroundColorFillStops', 7 => 'backgroundColor,backgroundColorFillStops',
    8 => 'backgroundColor,backgroundColorFillStops'), wp_json_encode($added));
$check('...stage 8 is the cap (level 9 and up are fully built), and below 1 is 1', RSL_Avatars::api_url($X, 9, true) === RSL_Avatars::api_url($X, 8, true)
    && RSL_Avatars::api_url($X, 40, false) === RSL_Avatars::api_url($X, 8, false) && RSL_Avatars::api_url($X, 0, true) === $want_url1);
$check('...every team at its own speed: the speed is the same at every stage, and differs across seeds', $query_of(RSL_Avatars::api_url($X, 3, true))['animationVariant'] === 'slowest'
    && $query_of(RSL_Avatars::api_url($X, 8, true))['animationVariant'] === 'slowest' && count($seen['speed']) === 5);

// --- create() sets a seed and stage 1, and queues the team ---------------------------------------------------
$t1 = $mkteam($S, "Tee One $suffix");
$check('create(): a random seed (never the name), stage 1, and the team on the queue', RSL_Avatars::valid_seed($t1->avatar_seed) && intval($t1->avatar_stage) === 1
    && RSL_Avatars::stage_of($t1) === 1 && $in_queue($t1->id), wp_json_encode(array($t1->avatar_seed, $t1->avatar_stage, RSL_Avatars::queued())));
$check('...and the job is due (the mail stop keeps it a day off while the test runs)', (bool) wp_next_scheduled(RSL_Avatars::CRON_HOOK));
$check('...no request made', $calls() === 0);
$kept = RSL_Avatars::new_seed();
$tk = $mkteam($S, "Tee Kept $suffix", array('avatar_seed' => $kept));
$tbad = $mkteam($S, "Tee Bad Seed $suffix", array('avatar_seed' => 'Tee Bad Seed'));
$check('...a seed passed in (a chosen robot, roll_over()) is kept; anything that isn\'t a seed gets a new one', $tk->avatar_seed === $kept
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

// --- The captain's application: no colour field, the robot chooser, the robot kept -----------------------------
$fields = RSL_Portal::team_fields(array(), array($S => RSL_Seasons::label_with_dates($S, RSL_Seasons::span($S))), true);
$check('the team application\'s fields: name, division and level, no colour', strpos($fields, 'name="team_name"') !== false && strpos($fields, 'name="team_level"') !== false
    && strpos($fields, 'team_colour') === false && strpos($fields, 'type="color"') === false && stripos($fields, 'colour') === false);
$check('...and Your team\'s robot: Level 1 and Fully built, Try another, the hidden avatar_seed field, and the line about Season Points',
    strpos($fields, '<p class="rsl-robot-title">Your team&#039;s robot</p>') !== false && strpos($fields, '<figcaption>Level 1</figcaption>') !== false
    && strpos($fields, '<figcaption>Fully built</figcaption>') !== false && strpos($fields, '>Try another</button>') !== false
    && preg_match('/<input type="hidden" name="avatar_seed" value="[a-f0-9]{16}" autocomplete="off">/', $fields) === 1
    && strpos($fields, 'data-rsl-last="' . RSL_Avatars::LAST_WORDS . '"') !== false && stripos(RSL_Avatars::LAST_WORDS, 'reload') === false
    && strpos($fields, esc_html(RSL_Avatars::START_WORDS)) !== false && strpos($fields, 'It starts at level 1 and builds up as your team earns Season Points.') !== false);
$applicant = $mk('applicant', 'subscriber', true);
$chosen = RSL_Avatars::new_seed();
$app = RSL_Portal::apply_from_post($applicant, array('team_name' => "Tee Apply $suffix", 'team_division' => 'ranked', 'team_level' => 'social', 'team_colour' => '#123456',
    'team_season' => $S, 'avatar_seed' => $chosen));
if ($app && !is_wp_error($app)) {
    $team_ids[] = intval($app->id);
}
$check('the application card: the robot chosen is the team\'s seed, stage 1, queued; a colour posted (a page opened before 0.23.0) is ignored, navy',
    $app && !is_wp_error($app) && $app->avatar_seed === $chosen && intval($app->avatar_stage) === 1 && $in_queue($app->id) && $app->colour === '#1d3d6e',
    is_wp_error($app) ? $app->get_error_message() : wp_json_encode($app));
$starter = $mk('starter', 'subscriber', true);
$chosen2 = RSL_Avatars::new_seed();
$res = RSL_Portal_FA::after_register($starter, array('route' => 'start', 'team_name' => "Tee Start $suffix", 'team_division' => 'unranked', 'team_level' => 'social',
    'team_season' => $S, 'avatar_seed' => $chosen2));
$app2 = RSL_Teams::pending_application_for_user($starter, $S);
if ($app2) {
    $team_ids[] = intval($app2->id);
}
$check('...Start a team at registration keeps the robot chosen too', $app2 && $app2->avatar_seed === $chosen2 && is_array($res) && $res['type'] === 'success', wp_json_encode($res));
$fixer = $mk('fixer', 'subscriber', true);
$draft_seed = RSL_Avatars::new_seed();
$failed = RSL_Portal::apply_from_post($fixer, array('team_name' => '', 'team_division' => 'ranked', 'team_level' => 'social', 'team_season' => $S, 'avatar_seed' => $draft_seed));
$draft = get_transient(RSL_Portal::APPLY_DRAFT_PREFIX . $fixer);
delete_transient(RSL_Portal::APPLY_DRAFT_PREFIX . $fixer);
$again = RSL_Portal::team_fields(is_array($draft) ? $draft : array(), array($S => RSL_Seasons::label($S)), true);
$check('...a failed application keeps the robot in its draft, and the card shows it first', is_wp_error($failed) && is_array($draft) && $draft['seed'] === $draft_seed
    && strpos($again, '<input type="hidden" name="avatar_seed" value="' . $draft_seed . '" autocomplete="off">') !== false, wp_json_encode($draft));
$odd = RSL_Portal::apply_from_post($fixer, array('team_name' => "Tee Odd Seed $suffix", 'team_division' => 'ranked', 'team_level' => 'social', 'team_season' => $S, 'avatar_seed' => 'Tee Odd Seed'));
if ($odd && !is_wp_error($odd)) {
    $team_ids[] = intval($odd->id);
}
$check('...a posted seed that isn\'t one: the application still goes in, with a random robot', $odd && !is_wp_error($odd) && RSL_Avatars::valid_seed($odd->avatar_seed));

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
$check('ensure(): true, two requests for the seed at its stage (animated, then still)', $ok && $calls() === 2
    && $http['dicebear'][0] === RSL_Avatars::api_url($t1->avatar_seed, 1, true) && $http['dicebear'][1] === RSL_Avatars::api_url($t1->avatar_seed, 1, false), wp_json_encode($http['dicebear']));
$check('...two files, named by the team id, the seed\'s hash and the stage, in uploads/rsl-team-avatars', $files_of($t1->id) === $names($t1->id, $t1->avatar_seed, 1)
    && $p1['animated'] === $dir . '/team-' . $t1->id . '-' . $hash . '-s1.svg' && $p1['stage'] === 1, wp_json_encode($files_of($t1->id)) . ' in ' . $dir);
$check('...holding what the API sent (it passed the check unchanged)', (string) @file_get_contents($p1['animated']) === $svg_for($t1->avatar_seed, true, 1)
    && (string) @file_get_contents($p1['still']) === $svg_for($t1->avatar_seed, false, 1));
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
    'a script under a prefix that isn\'t ASCII' => str_replace('</svg>', "<\xC3\xA9:script xmlns:\xC3\xA9=\"http://www.w3.org/2000/svg\">alert(1)</\xC3\xA9:script></svg>", $good),
    'the same, declared on the svg' => str_replace(array('<svg ', '</svg>'), array("<svg xmlns:\xC3\xA9=\"http://www.w3.org/2000/svg\" ", "<\xC3\xA9:script>alert(1)</\xC3\xA9:script></svg>"), $good),
    'a script under a prefix on the list' => str_replace(array('<svg ', '</svg>'), array('<svg xmlns:dc="http://www.w3.org/2000/svg" ', '<dc:script>alert(1)</dc:script></svg>'), $good),
    'a CSS escape spelling url()' => str_replace('@media', 'svg { background: \\75 rl(https://elsewhere.example.test/x); } @media', $good),
    'a CSS escape spelling @import' => str_replace('@media', '@\\69mport "x.css"; @media', $good),
    'an element DiceBear never uses (image)' => str_replace('</svg>', '<image width="1" height="1"/></svg>', $good),
    'an element DiceBear never uses (text)' => str_replace('</svg>', '<text>Hi</text></svg>', $good),
    'a tag with a space after <' => str_replace('</svg>', '< script>alert(1)</script></svg>', $good),
    'a namespace not on the list' => str_replace('<svg ', '<svg xmlns:x="http://elsewhere.example.test/x" ', $good),
    'the default namespace changed inside' => str_replace('<g id="a">', '<g id="a" xmlns="http://elsewhere.example.test/x">', $good),
    'a prefix on the list with another address' => str_replace('<svg ', '<svg xmlns:dc="http://elsewhere.example.test/dc" ', $good),
    'a processing instruction' => str_replace('<defs>', '<?xml-stylesheet href="x.css"?><defs>', $good),
    'a CDATA section' => str_replace('</svg>', '<![CDATA[x]]></svg>', $good),
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
foreach (array('script' => 'a script in the image', 'script-prefix' => 'a script under a prefix that isn\'t ASCII', 'href' => 'a link elsewhere in the image',
    'escape' => 'a CSS escape spelling url()', 'still-bad' => 'a good animated image but a bad still one',
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
$base = untrailingslashit($up['baseurl']) . '/' . RSL_Avatars::DIR . '/team-' . $t1->id . '-' . $hash . '-s1';
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
$check('...every stylesheet with a badge rounds its corners (as the image does) before it loads, and carries the chooser\'s rules', $src === 'yyy'
    && strpos(RSL_Avatars::css(), 'img.rsl-badge.rsl-avatar') !== false && strpos(RSL_Avatars::css(), 'border-radius: 20%') !== false
    && strpos(RSL_Avatars::css(), '.rsl-robot-box .rsl-robot-next[hidden] { display: none; }') !== false && strpos(RSL_Avatars::css(), "'") === false, $src);
// The stored stage: a team (in 2083 Post-season, whose stages this test never checks) at stage 3 draws its stage 3 files.
$tst = $mkteam($A, "Tee Stage $suffix");
RSL_Avatars::dequeue($tst->id);
$reset();
$tst3 = clone $tst;
$tst3->avatar_stage = 3;
$made3 = RSL_Avatars::ensure($tst3);
$tst = $set_stage($tst->id, 3);
$bst = RSL_Public::badge($tst);
$check('badge(): the stored stage\'s files (stage 3 here), fetched for that stage; the badge never works out a level', $made3 && $calls() === 2 && $stage_of_url($http['dicebear'][0]) === 3
    && strpos($bst, $names($tst->id, $tst->avatar_seed, 3)[1]) !== false && strpos($bst, $names($tst->id, $tst->avatar_seed, 3)[0]) !== false
    && strpos($bst, '-s1') === false && $files_of($tst->id) === $names($tst->id, $tst->avatar_seed, 3), $bst);
$tst4 = $set_stage($tst->id, 4);
$reset();
$check('...a stored stage whose files aren\'t there: the initials, queued, nothing asked', strpos(RSL_Public::badge($tst4), '<span class="rsl-badge"') === 0 && $in_queue($tst->id) && $calls() === 0);
RSL_Avatars::dequeue($tst->id);
$tst = $set_stage($tst->id, 3);
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
$g = (object) array('home_team_id' => $t1->id, 'home_name' => $t1->name, 'home_slug' => $t1->slug, 'home_colour' => $t1->colour, 'home_avatar_seed' => $t1->avatar_seed, 'home_avatar_stage' => '1',
    'away_team_id' => $tst->id, 'away_name' => $tst->name, 'away_slug' => $tst->slug, 'away_colour' => $tst->colour, 'away_avatar_seed' => $tst->avatar_seed, 'away_avatar_stage' => '3');
$home = $side->invoke(null, $g, 'home');
$away = $side->invoke(null, $g, 'away');
$check('fixtures: each side of a game carries its team id, seed and stage, so its badge is the avatar at its stage', intval($home->id) === intval($t1->id) && $home->avatar_seed === $t1->avatar_seed
    && $home->avatar_stage === 1 && $away->avatar_stage === 3 && strpos(RSL_Public::badge($home, 'small'), '<picture') === 0
    && strpos(RSL_Public::badge($away, 'small'), $names($tst->id, $tst->avatar_seed, 3)[1]) !== false);
$sql = new ReflectionMethod('RSL_Fixtures', 'select_sql');
$sql->setAccessible(true);
$check('...the games\' query reads both teams\' seeds and stages', strpos($sql->invoke(null), 'h.avatar_seed AS home_avatar_seed, a.avatar_seed AS away_avatar_seed, h.avatar_stage AS home_avatar_stage, a.avatar_stage AS away_avatar_stage') !== false);

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
$check('...nobody logged out gets New avatar or the chooser', strpos($page3, 'class="rsl-avatar-new"') === false && strpos($list, 'class="rsl-avatar-new"') === false
    && strpos($page3 . $list, 'class="rsl-robot-box"') === false && strpos($page3 . $list, 'api.dicebear.com') === false);

// --- The chooser ------------------------------------------------------------------------------------------------------------
$script_flag->setValue(null, false);
$pick_first = RSL_Avatars::new_seed();
$ch = RSL_Avatars::chooser(array('title' => 'Your team\'s robot', 'hint' => RSL_Avatars::START_WORDS, 'first' => $pick_first));
$ch2 = RSL_Avatars::chooser(array());
$data = preg_match('/data-rsl-robots="([^"]*)"/', $ch, $m) ? json_decode(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), true) : null;
$cand_ok = is_array($data) && count($data) === RSL_Avatars::CHOICES && count(array_unique(array_map(function ($c) { return $c[0]; }, $data))) === RSL_Avatars::CHOICES;
foreach (is_array($data) ? $data : array() as $c) {
    $cand_ok = $cand_ok && count($c) === 3 && RSL_Avatars::valid_seed($c[0]) && $c[1] === RSL_Avatars::api_url($c[0], 1, true) && $c[2] === RSL_Avatars::api_url($c[0], 8, true);
}
$check('the chooser: ' . RSL_Avatars::CHOICES . ' candidates, each a valid new seed with its Level 1 and Fully built addresses from api_url() (the pinning in one place), the one passed first',
    $cand_ok && $data[0][0] === $pick_first, is_array($data) ? count($data) . ' candidates' : 'no data');
$check('...two images side by side, 96 px, captioned Level 1 and Fully built, the first candidate\'s, with no referrer',
    strpos($ch, '<div class="rsl-robot-pair"><figure><img src="' . esc_url($data[0][1]) . '" data-rsl-robot="1" alt="Robot at level 1" width="96" height="96" loading="lazy" decoding="async" referrerpolicy="no-referrer"><figcaption>Level 1</figcaption></figure>') !== false
    && strpos($ch, '<figure><img src="' . esc_url($data[0][2]) . '" data-rsl-robot="8" alt="Robot fully built" width="96" height="96" loading="lazy" decoding="async" referrerpolicy="no-referrer"><figcaption>Fully built</figcaption></figure></div>') !== false, $ch);
$check('...Try another (a button that never submits, hidden until the script runs), the live note (always rendered, so a screen reader hears it) with the words for after the last one (never to reload on a form), and the hidden field holding the first candidate (what a browser without JavaScript sends; never autocompleted, so a reload never restores another)',
    strpos($ch, '<button type="button" class="rsl-robot-next" hidden>Try another</button>') !== false
    && strpos($ch, '<p class="rsl-robot-note" aria-live="polite" data-rsl-last="Try another goes back to the first."></p>') !== false
    && strpos(RSL_Avatars::css(), ':empty') === false
    && strpos(RSL_Avatars::chooser(array('last' => RSL_Avatars::MORE_WORDS)), 'data-rsl-last="Reload the page for more."') !== false
    && strpos($ch, '<input type="hidden" name="avatar_seed" value="' . $pick_first . '" autocomplete="off">') !== false
    && strpos($ch, '<p class="rsl-robot-title">Your team&#039;s robot</p>') !== false && strpos($ch, '<p class="rsl-hint rsl-robot-hint">It starts at level 1') !== false);
$all_urls = array();
// Every address in the box (the script, checked below, holds only the prefix it compares with).
preg_match_all('#(?:https?:)?//[^"\'\s<>]+#i', html_entity_decode(preg_replace('#<script>.*?</script>#s', '', $ch), ENT_QUOTES, 'UTF-8'), $found);
$all_urls = array_merge($found[0], is_array($data) ? array_merge(array_column($data, 1), array_column($data, 2)) : array());
$elsewhere = array_filter($all_urls, function ($u) { return strpos($u, 'https://api.dicebear.com/10.x/voxel-bot/svg?') !== 0; });
$check('...nothing but api.dicebear.com in the previews (' . count($all_urls) . ' addresses)', count($all_urls) >= 2 * RSL_Avatars::CHOICES && !$elsewhere, implode(' ', $elsewhere));
$js = RSL_Avatars::script();
$check('...the script: attributes and textContent only, never innerHTML, outerHTML, insertAdjacentHTML or document.write, and it checks each address is DiceBear\'s',
    strpos($js, 'innerHTML') === false && strpos($js, 'outerHTML') === false && strpos($js, 'insertAdjacentHTML') === false && strpos($js, 'document.write') === false
    && strpos($js, 'textContent') !== false && strpos($js, "setAttribute('src'") !== false && strpos($js, "'https://api.dicebear.com/'") !== false && strpos($js, 'input.value = c[0]') !== false);
$check('...it puts the hidden field back to the first robot as it starts (the one on screen), and each Try another says where it is (Robot N of M) in the note',
    strpos($js, 'input.value = String(list[0][0]);') !== false && strpos($js, "note.textContent = 'Robot ' + (i + 1) + ' of ' + list.length + '.'") !== false
    && strpos($js, 'innerHTML') === false);
$check('...printed once a page: in the first chooser, not the second', substr_count($ch, '<script>') === 1 && strpos($ch, $js) !== false && strpos($ch2, '<script') === false
    && strpos($ch2, '<p class="rsl-robot-title">Your team&#039;s robot</p>') !== false && strpos($ch2, 'rsl-robot-hint') === false);

// --- The job -------------------------------------------------------------------------------------------------------------
delete_option(RSL_Avatars::STAGE_OPTION);
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
$check('work(): a batch of ' . RSL_Avatars::BATCH . ' teams, oldest first (20 requests, each at stage 1: no Season Points yet)', RSL_Avatars::BATCH === 10 && $calls() === 20
    && count(array_filter(array_slice($have, 0, 10, true))) === 10 && !$have[11] && !$have[12]
    && count(array_filter($http['dicebear'], function ($u) use ($stage_of_url) { return $stage_of_url($u) === 1; })) === 20, wp_json_encode(array($calls(), $have)));
$check('...the other two stay queued and the job is rescheduled (in ' . RSL_Avatars::GAP . ' seconds, not a day)', $in_queue($w[11]->id) && $in_queue($w[12]->id)
    && !$in_queue($w[1]->id) && !$in_queue($w[10]->id) && $when && $when <= time() + RSL_Avatars::GAP + 5 && $when >= time() + RSL_Avatars::GAP - 10, wp_json_encode(array($when, time())));
$reset();
wp_clear_scheduled_hook(RSL_Avatars::CRON_HOOK);
RSL_Avatars::work();
$when = wp_next_scheduled(RSL_Avatars::CRON_HOOK);
$w12 = RSL_Teams::get($w[12]->id);
$check('...the next run does the rest: a team queued with no seed gets one first, then its files', $calls() === 4 && RSL_Avatars::valid_seed($w12->avatar_seed)
    && RSL_Avatars::urls($w12) !== null && RSL_Avatars::urls(RSL_Teams::get($w[11]->id)) !== null && !$in_queue($w[11]->id) && !$in_queue($w[12]->id));
$check('...and with the queue empty and no season flagged, no run is scheduled', RSL_Avatars::queued() ? true : $when === false, wp_json_encode(array($when, RSL_Avatars::queued())));
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
// A team deleted with its files still there: they go KEEP_OLD later.
$tdel = $mkteam($S, "Tee Deleted $suffix");
RSL_Avatars::ensure($tdel);
$del_files = $files_of($tdel->id);
$wpdb->delete(RSL_Database::table('teams'), array('id' => $tdel->id));
$set_queue(array($tgone->id, 2147483000, $tdel->id));
$reset();
wp_clear_scheduled_hook(RSL_Avatars::CRON_HOOK);
RSL_Avatars::work();
$hold_job();
$check('a withdrawn application and a team that no longer exists come off the queue, no request', $calls() === 0 && !$in_queue($tgone->id) && !$in_queue(2147483000) && !$in_queue($tdel->id));
$check('...a deleted team\'s files stay for now and are listed to go two days on', count($del_files) === 2 && $files_of($tdel->id) === $del_files
    && $listed($del_files[0]) >= time() + RSL_Avatars::KEEP_OLD - 120 && $listed($del_files[1]) >= time() + RSL_Avatars::KEEP_OLD - 120, wp_json_encode(array($del_files, RSL_Avatars::purge_list())));
$set_queue(array($f[1]->id));
RSL_Avatars::forget_missing();
$check('forget_missing() keeps a team that exists', $in_queue($f[1]->id));
$set_queue(array());

// --- The results hook: every write to a season's results or published nights flags it -------------------------------------
$ga = $mkteam($S, "Tee Game Home $suffix", array('division' => 'unranked'));
$gb = $mkteam($S, "Tee Game Away $suffix", array('division' => 'unranked'));
$wpdb->insert(RSL_Database::table('nights'), array('season' => $S, 'night_date' => $day(47), 'type' => 'regular'));
$night_id = intval($wpdb->insert_id);
$wpdb->insert(RSL_Database::table('games'), array('season' => $S, 'night_id' => $night_id, 'division' => 'unranked', 'venue_id' => 0, 'court_id' => 0,
    'start_time' => '19:00:00', 'end_time' => '20:00:00', 'home_team_id' => $ga->id, 'away_team_id' => $gb->id));
$game_id = intval($wpdb->insert_id);
$hooked = array();
$steps = array(
    'a result' => function () use ($game_id) { return RSL_Fixtures::record_result($game_id, '25-20, 25-18'); },
    'a forfeit' => function () use ($game_id, $ga) { return RSL_Fixtures::record_forfeit($game_id, $ga->id); },
    'a result cleared' => function () use ($game_id) { return RSL_Fixtures::clear_result($game_id); },
    'a night published' => function () use ($night_id) { return RSL_Fixtures::publish_night($night_id, true); },
    'a night pulled back' => function () use ($night_id) { return RSL_Fixtures::publish_night($night_id, false); },
    'a game deleted' => function () use ($game_id) { return RSL_Fixtures::delete_game($game_id); },
);
foreach ($steps as $label => $step) {
    delete_option(RSL_Avatars::STAGE_OPTION);
    $r = $step();
    $hooked[$label] = !is_wp_error($r) && $r && $flagged($S) ? 'flagged' : 'not flagged';
}
$check('the results hook: ' . implode(', ', array_keys($steps)) . ' each flag the season', count(array_filter($hooked, function ($v) { return $v === 'flagged'; })) === count($steps), wp_json_encode($hooked));
delete_option(RSL_Avatars::STAGE_OPTION);
wp_clear_scheduled_hook(RSL_Avatars::CRON_HOOK);
$reset();
do_action(RSL_Avatars::RESULTS_HOOK, $S);
do_action(RSL_Avatars::RESULTS_HOOK, $S);
$when = wp_next_scheduled(RSL_Avatars::CRON_HOOK);
$cron = _get_cron_array();
$events = 0;
foreach (is_array($cron) ? $cron : array() as $hooks) {
    $events += isset($hooks[RSL_Avatars::CRON_HOOK]) ? count($hooks[RSL_Avatars::CRON_HOOK]) : 0;
}
$check('...debounced: two changes make one run, ' . RSL_Avatars::STAGE_DELAY . ' seconds on, and nothing is asked now', $flagged($S) && $events === 1 && $when
    && $when >= time() + RSL_Avatars::STAGE_DELAY - 10 && $when <= time() + RSL_Avatars::STAGE_DELAY + 5 && $calls() === 0, wp_json_encode(array($when, time(), $events)));
$hold_job();
// A run put off while a team waits out a failed fetch (3 hours here) is brought forward by results and new teams.
// The test's hold is lifted for this part only, as on the live site; a run moved to now is caught, never scheduled.
delete_option(RSL_Avatars::HOLD_OPTION);
$later = time() + 3 * HOUR_IN_SECONDS;
update_option(RSL_Avatars::QUEUE_OPTION, array(intval($ga->id) => array('tries' => 5, 'next' => $later)), false);
wp_clear_scheduled_hook(RSL_Avatars::CRON_HOOK);
wp_schedule_single_event($later, RSL_Avatars::CRON_HOOK);
RSL_Avatars::queue($ga->id);
$when_waiting = wp_next_scheduled(RSL_Avatars::CRON_HOOK);
RSL_Avatars::stage_check($S);
$when_results = wp_next_scheduled(RSL_Avatars::CRON_HOOK);
$events_results = 0;
foreach ((array) _get_cron_array() as $hooks) {
    $events_results += is_array($hooks) && isset($hooks[RSL_Avatars::CRON_HOOK]) ? count($hooks[RSL_Avatars::CRON_HOOK]) : 0;
}
wp_clear_scheduled_hook(RSL_Avatars::CRON_HOOK);
wp_schedule_single_event($later, RSL_Avatars::CRON_HOOK);
$caught = null;
$capture = function ($pre, $event) use (&$caught) {
    if (is_object($event) && isset($event->hook) && $event->hook === RSL_Avatars::CRON_HOOK) {
        $caught = intval($event->timestamp);
        return true; // caught: nothing is scheduled at now
    }
    return $pre;
};
add_filter('pre_schedule_event', $capture, 10, 2);
RSL_Avatars::queue($gb->id);
remove_filter('pre_schedule_event', $capture, 10);
$capture = null;
update_option(RSL_Avatars::HOLD_OPTION, time() + DAY_IN_SECONDS, false); // the test's hold again
$hold_job();
RSL_Avatars::stage_check($S);
RSL_Avatars::queue($t3->id);
$when_held = wp_next_scheduled(RSL_Avatars::CRON_HOOK);
$check('a run put off while a team waits out a failed fetch: drawing that team\'s badge again leaves it where it is', intval($when_waiting) === $later, wp_json_encode(array($when_waiting, $later)));
$check('...results changing bring it forward to ' . RSL_Avatars::STAGE_DELAY . ' seconds on (one run, not two)', $when_results && $when_results <= time() + RSL_Avatars::STAGE_DELAY + 5
    && $when_results >= time() + RSL_Avatars::STAGE_DELAY - 10 && $events_results === 1, wp_json_encode(array($when_results, time(), $events_results)));
$check('...a team newly queued brings it to now', $caught !== null && $caught <= time() + 5 && $in_queue($gb->id), wp_json_encode(array($caught, time())));
$check('...and while a test holds the job (the mail stop\'s marker) nothing moves it', $when_held && $when_held > time() + DAY_IN_SECONDS - 120 && $in_queue($t3->id),
    wp_json_encode(array($when_held, time())));
$set_queue(array());
delete_option(RSL_Avatars::STAGE_OPTION);
$current = function () use ($S) { return $S; };
add_filter('pre_option_' . RSL_Seasons::CURRENT_OPTION, $current);
delete_option(RSL_Avatars::STAGE_OPTION);
RSL_Avatars::hourly();
$check('...and the hourly job flags the current season (the catch-all)', $flagged($S));
delete_option(RSL_Avatars::STAGE_OPTION);

// --- Stages kept current by the job ----------------------------------------------------------------------------------------------
$ts = $mkteam($S, "Tee Builds $suffix");
$ts2 = $mkteam($S, "Tee Built $suffix");
$ts3 = $mkteam($S, "Tee Stays $suffix");
RSL_Avatars::ensure($ts);
RSL_Avatars::ensure($ts3);
$set_queue(array());
$ts_s1 = $names($ts->id, $ts->avatar_seed, 1);
$check('setup: three active teams at stage 1, two with their stage 1 files', RSL_Avatars::stage_of($ts) === 1 && RSL_Avatars::stage_of($ts2) === 1 && $files_of($ts->id) === $ts_s1
    && $files_of($ts2->id) === array());
$check('setup: their Season Points planted (85, 300, 0) in the season\'s saved stats', $plant($S, array($ts->id => 85, $ts2->id => 300, $ts3->id => 0)));
$ladder = new RSL_Public_Fixtures();
$reset();
$lad = $ladder->render_ladder();
$check('a ladder render with stages out of date: no request, and each badge is its stored stage (the page never works out a level)', $calls() === 0
    && strpos($lad, $ts_s1[1]) !== false && strpos($lad, '-s3') === false && !$in_queue($ts->id) && intval(RSL_Teams::get($ts->id)->avatar_stage) === 1, wp_json_encode($http['all']));
$set_queue(array()); // the teams it queued for missing files (that part is checked above) aren't this part's
$fpq = 0;
$counter = function ($q) use (&$fpq) {
    if (strpos($q, 'BIT_XOR(CAST(CONV(SUBSTRING(MD5(x)') !== false) {
        $fpq++;
    }
    return $q;
};
add_filter('query', $counter);
do_action(RSL_Avatars::RESULTS_HOOK, $S);
$reset();
wp_clear_scheduled_hook(RSL_Avatars::CRON_HOOK);
RSL_Avatars::work();
remove_filter('query', $counter);
$counter = null;
$hold_job();
$ts_b = RSL_Teams::get($ts->id);
$ts2_b = RSL_Teams::get($ts2->id);
$ts_s3 = $names($ts->id, $ts->avatar_seed, 3);
$stages_asked = array_map($stage_of_url, $http['dicebear']);
$check('the job after Season Points change: 85 points is level 3, so stage 3 is fetched (animated, then still), then written', intval($ts_b->avatar_stage) === 3
    && in_array(RSL_Avatars::api_url($ts->avatar_seed, 3, true), $http['dicebear'], true) && in_array(RSL_Avatars::api_url($ts->avatar_seed, 3, false), $http['dicebear'], true)
    && count(array_intersect($ts_s3, $files_of($ts->id))) === 2, wp_json_encode(array($ts_b->avatar_stage, $stages_asked, $files_of($ts->id))));
$check('...300 points is level 8: fully built (the cap), stage 8 fetched and written', intval($ts2_b->avatar_stage) === 8 && $files_of($ts2->id) === $names($ts2->id, $ts2->avatar_seed, 8)
    && in_array(RSL_Avatars::api_url($ts2->avatar_seed, 8, true), $http['dicebear'], true));
$check('...four requests in all: the team still at its level and every other team in the season asked nothing', $calls() === 4 && intval(RSL_Teams::get($ts3->id)->avatar_stage) === 1
    && $files_of($ts3->id) === $names($ts3->id, $ts3->avatar_seed, 1), wp_json_encode($stages_asked));
$check('...the levels were worked out once for the season in the run (one stats fingerprint read)', $fpq === 1, "$fpq reads");
$check('...the old stage\'s files stay for cached pages, listed to go two days on; the flag is taken and nothing is left queued',
    count(array_intersect($ts_s1, $files_of($ts->id))) === 2 && $listed($ts_s1[0]) >= time() + RSL_Avatars::KEEP_OLD - 120 && $listed($ts_s1[1]) >= time() + RSL_Avatars::KEEP_OLD - 120
    && $listed($ts_s3[1]) === 0 && !$flagged($S) && !$in_queue($ts->id) && !$in_queue($ts2->id));
$check('...the badge is now stage 3', strpos(RSL_Public::badge($ts_b), $ts_s3[1]) !== false);
// The leaders box draws teams from the saved stats, built while the team was at stage 1 (the fingerprint leaves the stage out).
$leaders_m = new ReflectionMethod('RSL_Public_Fixtures', 'leaders');
$leaders_m->setAccessible(true);
$old_ts = clone $ts_b;
$old_ts->avatar_stage = 1;
$old_ts2 = clone $ts2_b;
$old_ts2->avatar_stage = 1;
$set_queue(array());
$reset();
$box = $leaders_m->invoke(null, array('leaders' => array('improved' => array('team' => $old_ts2, 'value' => 40), 'giant_killer' => null,
    'points' => array($ts_b->division => array('team' => $old_ts, 'value' => 85)))));
$check('the ladder\'s leaders box draws each team\'s stage as stored now, not the saved stats\' (stage 3 and 8; stage 1\'s files would be stale, and stage 1 for a team that went straight to 8 never existed, which drew the initials and queued it on every view)',
    strpos($box, $ts_s3[1]) !== false && strpos($box, $ts_s1[1]) === false && strpos($box, $names($ts2->id, $ts2->avatar_seed, 8)[1]) !== false
    && strpos($box, '<span class="rsl-badge') === false && !$in_queue($ts->id) && !$in_queue($ts2->id) && $calls() === 0, $box);
$fp_before = $fp->invoke(null, $S);
$set_stage($ts3->id, 2);
$fp_stage = $fp->invoke(null, $S);
$set_stage($ts3->id, 1);
$ts3_seed = RSL_Teams::get($ts3->id)->avatar_seed;
$wpdb->update(RSL_Database::table('teams'), array('avatar_seed' => RSL_Avatars::new_seed()), array('id' => $ts3->id));
$fp_seed = $fp->invoke(null, $S);
$wpdb->update(RSL_Database::table('teams'), array('avatar_seed' => $ts3_seed), array('id' => $ts3->id));
$check('the saved stats\' fingerprint leaves the stage out (the job writes stages after every results night, and each write would throw the saved stats away) but has the seed',
    is_string($fp_before) && $fp_before === $fp_stage && $fp_seed !== $fp_before && $fp->invoke(null, $S) === $fp_before);
$reset();
wp_clear_scheduled_hook(RSL_Avatars::CRON_HOOK);
RSL_Avatars::work();
$hold_job();
$check('...a run with nothing flagged or queued asks nothing', $calls() === 0 && intval(RSL_Teams::get($ts->id)->avatar_stage) === 3);
// A result put right: back to 0 points. The stage 1 files are still there, so nothing is fetched.
$check('setup: the points planted again (0 and 300)', $plant($S, array($ts->id => 0, $ts2->id => 300)));
do_action(RSL_Avatars::RESULTS_HOOK, $S);
$reset();
wp_clear_scheduled_hook(RSL_Avatars::CRON_HOOK);
RSL_Avatars::work();
$hold_job();
$check('...back to level 1: stage 1 written with no fetch (its files are still there), stage 3\'s listed to go', intval(RSL_Teams::get($ts->id)->avatar_stage) === 1 && $calls() === 0
    && $listed($ts_s3[0]) >= time() + RSL_Avatars::KEEP_OLD - 120 && $listed($ts_s3[1]) >= time() + RSL_Avatars::KEEP_OLD - 120);
$due = RSL_Avatars::purge_list();
foreach ($due as $name => $t) {
    if (preg_match('/^team-(\d+)-/', $name, $mm) && intval($mm[1]) === intval($ts->id)) {
        $due[$name] = time() - 1;
    }
}
update_option(RSL_Avatars::PURGE_OPTION, $due, false);
$gone = RSL_Avatars::purge();
$check('purge(): once due, stage 3\'s files go; stage 1\'s, listed earlier but in use again, are kept and come off the list', $files_of($ts->id) === $ts_s1
    && $listed($ts_s1[0]) === 0 && $listed($ts_s3[0]) === 0 && $gone >= 2, wp_json_encode(array($files_of($ts->id), $gone)));
delete_transient(RSL_Stats::CACHE_PREFIX . $S);
RSL_Stats::flush();
remove_filter('pre_option_' . RSL_Seasons::CURRENT_OPTION, $current);
$current = null;
delete_option(RSL_Avatars::STAGE_OPTION);

// --- Use this one (New avatar) ----------------------------------------------------------------------------------------------------
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
$check('the team page: New avatar by the header for the captain, a closed details with Your team\'s robot, the line about the level and Use this one, posting back to the team page',
    strpos($page_cap, '<details class="rsl-avatar-new"><summary>New avatar</summary><form method="post">') !== false
    && strpos($page_cap, 'name="do" value="new_avatar"') !== false && strpos($page_cap, 'name="team_id" value="' . $t1->id . '"') !== false
    && strpos($page_cap, 'name="back" value="' . esc_attr($url1) . '"') !== false && strpos($page_cap, '<p class="rsl-robot-title">Your team&#039;s robot</p>') !== false
    && strpos($page_cap, 'The new robot keeps the team&#039;s level.') !== false && preg_match('/name="avatar_seed" value="[a-f0-9]{16}"/', $page_cap) === 1
    && strpos($page_cap, '<button type="submit" class="rsl-avatar-use">Use this one</button></form></details>') !== false
    && strpos($page_cap, '<details class="rsl-avatar-new" open') === false
    && strpos($page_cap, 'class="rsl-avatar-new"') < strpos($page_cap, 'class="rsl-portal rsl-team-tools"'));
$check('...under the header, full width (inside the header\'s flex row the box made a 360 px phone scroll sideways), and after the last robot it says to reload for more (nothing typed to lose there)',
    preg_match('#<p class="rsl-meta">[^<]*</p>\s*</div>\s*</div>\s*<details class="rsl-avatar-new">#', $page_cap) === 1
    && strpos($page_cap, 'data-rsl-last="Reload the page for more."') !== false
    && strpos((string) file_get_contents(RSL_PLUGIN_DIR . 'includes/class-rsl-public.php'), '.rsl-team-head > div { min-width: 0; }') !== false);
$page_la = $render($la, $t1->slug);
$check('...for a league admin too (The team\'s robot); not for a plain member', strpos($page_la, 'class="rsl-avatar-new"') !== false
    && strpos($page_la, '<p class="rsl-robot-title">The team&#039;s robot</p>') !== false && strpos($render($member, $t1->slug), 'class="rsl-avatar-new"') === false);
$cap_data = preg_match('/data-rsl-robots="([^"]*)"/', $page_cap, $m) ? html_entity_decode($m[1], ENT_QUOTES, 'UTF-8') : '';
$check('...the team\'s name and address go in no preview', $cap_data !== '' && stripos($cap_data, 'Tee One') === false && stripos($cap_data, $t1->slug) === false
    && stripos($cap_data, rawurlencode($t1->name)) === false);
$check('avatar_button(): \'\' for a stranger and for nobody', RSL_Portal::avatar_button($t1, $stranger) === '' && RSL_Portal::avatar_button($t1, 0) === '');
$old1 = RSL_Avatars::paths(RSL_Teams::get($t1->id));
$new_seed = RSL_Avatars::new_seed();
$reset();
wp_set_current_user($cap);
$to = RSL_Portal::process(array('rsl_nonce' => wp_create_nonce('rsl_front'), 'rsl_front' => '1', 'do' => 'new_avatar', 'team_id' => $t1->id, 'back' => $url1, 'avatar_seed' => $new_seed));
$fl = $flash();
$t1b = RSL_Teams::get($t1->id);
$new1 = RSL_Avatars::paths($t1b);
clearstatcache();
$check('the captain\'s Use this one: back to the team page with the flash', $to === $url1 && $fl['type'] === 'success' && $fl['message'] === 'New robot saved.', wp_json_encode(array($to, $fl)));
$check('...the seed chosen, its two files fetched at the team\'s stage (two requests), the badge is the new one', $t1b->avatar_seed === $new_seed && intval($t1b->avatar_stage) === 1
    && $calls() === 2 && $http['dicebear'][0] === RSL_Avatars::api_url($new_seed, 1, true) && file_exists($new1['animated']) && file_exists($new1['still'])
    && strpos(RSL_Public::badge($t1b), basename($new1['animated'])) !== false);
$check('...the files just replaced stay (a cached page may show them), listed to go two days on', file_exists($old1['animated']) && file_exists($old1['still'])
    && $listed(basename($old1['animated'])) >= time() + RSL_Avatars::KEEP_OLD - 120 && $listed(basename($old1['still'])) >= time() + RSL_Avatars::KEEP_OLD - 120
    && $listed(basename($new1['animated'])) === 0);
$check('...it counted one try today', intval(get_transient(RSL_Avatars::tries_key($t1->id))) === 1);
$reset();
$to = RSL_Portal::process(array('rsl_nonce' => wp_create_nonce('rsl_front'), 'rsl_front' => '1', 'do' => 'new_avatar', 'team_id' => $t1->id, 'back' => $url1, 'avatar_seed' => $new_seed));
$fl = $flash();
$check('...the same form sent again: the same success, nothing asked, no try counted', $fl['type'] === 'success' && $calls() === 0
    && intval(get_transient(RSL_Avatars::tries_key($t1->id))) === 1 && RSL_Teams::get($t1->id)->avatar_seed === $new_seed);
foreach (array('Tee Name' => 'a team name', '' => 'no seed (a page opened before the chooser)', 'ABCDEF0123456789' => 'capitals', '0123456789abcdef0' => 'too long') as $posted => $words) {
    $reset();
    $to = RSL_Portal::process(array('rsl_nonce' => wp_create_nonce('rsl_front'), 'rsl_front' => '1', 'do' => 'new_avatar', 'team_id' => $t1->id, 'back' => $url1, 'avatar_seed' => $posted));
    $fl = $flash();
    $check("...a seed that isn't one ($words): refused with the sentence, nothing asked, no try counted, the robot kept", $to === $url1 && $fl['type'] === 'error'
        && $fl['message'] === 'That robot can\'t be used. Please pick another.' && $calls() === 0 && intval(get_transient(RSL_Avatars::tries_key($t1->id))) === 1
        && RSL_Teams::get($t1->id)->avatar_seed === $new_seed, wp_json_encode($fl));
}
$reset();
$la_seed = RSL_Avatars::new_seed();
$r = RSL_Avatars::regenerate($t1->id, $la, $la_seed);
$t1c = RSL_Teams::get($t1->id);
$check('a league admin: regenerate() saves the robot they chose too', !is_wp_error($r) && $t1c->avatar_seed === $la_seed && RSL_Avatars::urls($t1c) !== null && $calls() === 2);
wp_set_current_user($la);
$reset();
$la_page_seed = RSL_Avatars::new_seed();
$to = RSL_Portal::process(array('rsl_nonce' => wp_create_nonce('rsl_front'), 'rsl_front' => '1', 'do' => 'new_avatar', 'team_id' => $t1->id, 'back' => $url1, 'avatar_seed' => $la_page_seed));
$fl = $flash();
$t1la = RSL_Teams::get($t1->id);
$check('...a league admin\'s Use this one on the team page (not its captain): back to the team page with the flash, the seed saved',
    $to === $url1 && $fl['type'] === 'success' && $fl['message'] === 'New robot saved.' && $t1la->avatar_seed === $la_page_seed && $calls() === 2
    && RSL_Avatars::urls($t1la) !== null, wp_json_encode(array($to, $fl, $calls())));
$reset();
$adm_seed = RSL_Avatars::new_seed();
$to = RSL_Admin::process(array('rsl_nonce' => wp_create_nonce('rsl_team_action'), 'season' => $S, 'do' => 'new_avatar', 'team_id' => $t1->id, 'avatar_seed' => $adm_seed));
$nt = $notice();
$t1d = RSL_Teams::get($t1->id);
$check('...the Teams screen\'s Use this one: the notice, back to the team, the seed posted', $nt['type'] === 'success' && $nt['message'] === 'New robot saved.'
    && strpos($to, 'team=' . $t1->id) !== false && $t1d->avatar_seed === $adm_seed && $calls() === 2, wp_json_encode(array($to, $nt)));
$edit = new ReflectionMethod('RSL_Admin', 'render_team');
$edit->setAccessible(true);
ob_start();
$edit->invoke(new RSL_Admin(), $t1d);
$screen = (string) ob_get_clean();
$check('...the team\'s edit screen: a closed details New avatar with The team\'s robot and Use this one; the badge is the avatar, and the colour field stays',
    strpos($screen, '<details class="rsl-avatar-admin">') !== false && strpos($screen, '<summary class="button">New avatar</summary>') !== false
    && strpos($screen, '<p class="rsl-robot-title">The team&#039;s robot</p>') !== false && strpos($screen, 'class="rsl-robot-next button" hidden>Try another</button>') !== false
    && strpos($screen, 'name="do" value="new_avatar"') !== false && strpos($screen, '>Use this one</button>') !== false
    && strpos($screen, '<picture class="rsl-avatar-pic"') !== false && strpos($screen, 'name="colour"') !== false && strpos($screen, '.rsl-robot-box') !== false);
$seed_now = $t1d->avatar_seed;
$files_now = $files_of($t1->id);
foreach (array($member => 'a plain member', $stranger => 'a stranger') as $who => $words) {
    $reset();
    $r = RSL_Avatars::regenerate($t1->id, $who, RSL_Avatars::new_seed());
    $check("regenerate() by $words: refused, nothing asked, the robot kept", is_wp_error($r) && $r->get_error_message() === 'Only the captain can change the team\'s robot.'
        && $calls() === 0 && RSL_Teams::get($t1->id)->avatar_seed === $seed_now && $files_of($t1->id) === $files_now);
}
wp_set_current_user($member);
$to = RSL_Portal::process(array('rsl_nonce' => wp_create_nonce('rsl_front'), 'rsl_front' => '1', 'do' => 'new_avatar', 'team_id' => $t1->id, 'back' => $url1, 'avatar_seed' => RSL_Avatars::new_seed()));
$fl = $flash();
$check('...the member through the league page: refused, back to the team page', $to === $url1 && $fl['type'] === 'error' && $fl['message'] === 'Only the team captain can do that.'
    && RSL_Teams::get($t1->id)->avatar_seed === $seed_now, wp_json_encode(array($to, $fl)));
wp_set_current_user($cap);
$http['mode'] = '500';
$reset();
$to = RSL_Portal::process(array('rsl_nonce' => wp_create_nonce('rsl_front'), 'rsl_front' => '1', 'do' => 'new_avatar', 'team_id' => $t1->id, 'back' => $url1, 'avatar_seed' => RSL_Avatars::new_seed()));
$fl = $flash();
$check('a failed fetch: refused with the sentence, the seed and files as they were', $to === $url1 && $fl['type'] === 'error'
    && $fl['message'] === 'The new robot couldn\'t be fetched just now, so the old one stays. Try again in a few minutes.'
    && RSL_Teams::get($t1->id)->avatar_seed === $seed_now && $files_of($t1->id) === $files_now && RSL_Avatars::urls(RSL_Teams::get($t1->id)) !== null, wp_json_encode($fl));
$http['mode'] = 'still-bad';
$r = RSL_Avatars::regenerate($t1->id, $cap, RSL_Avatars::new_seed());
$check('...a still image the check refuses: the same, nothing saved', is_wp_error($r) && $r->get_error_code() === 'rsl_avatar_fetch'
    && RSL_Teams::get($t1->id)->avatar_seed === $seed_now && $files_of($t1->id) === $files_now);
$http['mode'] = 'good';
$check('...every try that asked counts (6 so far today)', intval(get_transient(RSL_Avatars::tries_key($t1->id))) === 6);
set_transient(RSL_Avatars::tries_key($t1->id), RSL_Avatars::DAILY_LIMIT - 1, DAY_IN_SECONDS);
$reset();
$r = RSL_Avatars::regenerate($t1->id, $cap, RSL_Avatars::new_seed());
$check('the daily cap: the 20th of the day works', !is_wp_error($r) && $calls() === 2 && intval(get_transient(RSL_Avatars::tries_key($t1->id))) === RSL_Avatars::DAILY_LIMIT);
$seed_now = RSL_Teams::get($t1->id)->avatar_seed;
$reset();
$r = RSL_Avatars::regenerate($t1->id, $cap, RSL_Avatars::new_seed());
$r2 = RSL_Avatars::regenerate($t1->id, $la, RSL_Avatars::new_seed());
$check('...the 21st is refused, for the captain and a league admin alike, nothing asked, the robot kept', is_wp_error($r) && $r->get_error_message() === 'You\'ve tried 20 robots today; try again tomorrow.'
    && is_wp_error($r2) && $r2->get_error_code() === 'rsl_avatar_limit' && $calls() === 0 && RSL_Teams::get($t1->id)->avatar_seed === $seed_now);
$check('...counted per team: another team is free', !is_wp_error(RSL_Avatars::regenerate($t2->id, $la, RSL_Avatars::new_seed())));
$check('regenerate(): a team that no longer exists', RSL_Avatars::regenerate(2147483000, $la, RSL_Avatars::new_seed())->get_error_message() === 'That team no longer exists.');
// The stage is kept: a team at stage 3 gets the new robot at stage 3.
$old_st = RSL_Avatars::paths($tst);
$st_seed = RSL_Avatars::new_seed();
$reset();
$r = RSL_Avatars::regenerate($tst->id, $la, $st_seed);
$tst_b = RSL_Teams::get($tst->id);
$check('...a team at stage 3: the new robot is fetched at stage 3 and the stage stays (a new robot never changes the level)', !is_wp_error($r) && intval($tst_b->avatar_stage) === 3
    && $tst_b->avatar_seed === $st_seed && $calls() === 2 && $http['dicebear'][0] === RSL_Avatars::api_url($st_seed, 3, true)
    && count(array_intersect($names($tst->id, $st_seed, 3), $files_of($tst->id))) === 2 && $listed(basename($old_st['animated'])) > time());
wp_set_current_user(0);

// --- Teams that won't play: their files go KEEP_OLD later -------------------------------------------------------------------------
$tdcl = $mkteam($S, "Tee Turned Down $suffix", array('status' => 'pending'));
RSL_Avatars::ensure($tdcl);
$dcl_files = $files_of($tdcl->id);
$dr = RSL_Teams::decline($tdcl->id, 'Test');
$check('a declined application: its files stay for now and are listed to go two days on, and it is off the queue', !is_wp_error($dr) && count($dcl_files) === 2
    && $files_of($tdcl->id) === $dcl_files && $listed($dcl_files[0]) >= time() + RSL_Avatars::KEEP_OLD - 120 && $listed($dcl_files[1]) >= time() + RSL_Avatars::KEEP_OLD - 120
    && !$in_queue($tdcl->id), wp_json_encode(array($dr, $dcl_files)));
$reset();
$r = RSL_Avatars::regenerate($tdcl->id, $la, RSL_Avatars::new_seed());
$check('...its robot can\'t be changed any more (nothing asked), and the Teams screen offers no New avatar for it', is_wp_error($r)
    && $r->get_error_message() === 'That application was declined or withdrawn, so its robot stays as it is.' && $calls() === 0
    && (function () use ($edit, $tdcl) { ob_start(); $edit->invoke(new RSL_Admin(), RSL_Teams::get($tdcl->id)); $h = (string) ob_get_clean(); return strpos($h, '<details class="rsl-avatar-admin">') === false; })());
$wd = $mk('withdrawer', 'subscriber', true);
$twd = RSL_Teams::apply($S, $wd, array('name' => "Tee Pulled Out $suffix", 'division' => 'ranked', 'level' => 'social'));
if ($twd && !is_wp_error($twd)) {
    $team_ids[] = intval($twd->id);
}
RSL_Avatars::ensure($twd);
$wd_files = $files_of($twd->id);
$wr = RSL_Teams::withdraw($twd->id, $wd);
$check('...a withdrawn one too', $wr === true && count($wd_files) === 2 && $listed($wd_files[0]) >= time() + RSL_Avatars::KEEP_OLD - 120 && !$in_queue($twd->id));
$due = RSL_Avatars::purge_list();
foreach ($due as $name => $t) {
    if (preg_match('/^team-(\d+)-/', $name, $mm) && in_array(intval($mm[1]), array(intval($tdcl->id), intval($twd->id), intval($tdel->id), intval($t1->id)), true)) {
        $due[$name] = time() - 1;
    }
}
$t1_files = RSL_Avatars::paths(RSL_Teams::get($t1->id));
$due[basename($t1_files['animated'])] = time() - 1;
update_option(RSL_Avatars::PURGE_OPTION, $due, false);
RSL_Avatars::purge();
$check('purge(): once due, the declined, withdrawn and deleted teams\' files and t1\'s replaced files are gone; t1\'s robot now, listed by mistake, is kept',
    $files_of($tdcl->id) === array() && $files_of($twd->id) === array() && $files_of($tdel->id) === array() && !file_exists($old1['animated']) && !file_exists($new1['animated'])
    && file_exists($t1_files['animated']) && file_exists($t1_files['still']) && $listed(basename($t1_files['animated'])) === 0, wp_json_encode($files_of($t1->id)));

// --- The schema upgrade still to finish ------------------------------------------------------------------------------------------
$behind = function () { return '13'; };
add_filter('pre_option_' . RSL_Database::SCHEMA_OPTION, $behind);
$tz = $mkteam($S, "Tee Upgrade $suffix");
$r = RSL_Avatars::regenerate($t1->id, $la, RSL_Avatars::new_seed());
$reset();
$set_queue(array($tz->id));
wp_clear_scheduled_hook(RSL_Avatars::CRON_HOOK);
RSL_Avatars::work();
$when = wp_next_scheduled(RSL_Avatars::CRON_HOOK);
$hold_job();
$check('while the upgrade is to finish: create() writes no seed (the column may not be there) and queues', !RSL_Database::schema_current() && $tz->avatar_seed === '' && $in_queue($tz->id));
$check('...regenerate() refuses', is_wp_error($r) && $r->get_error_message() === 'The league is being updated right now — please try again in a minute.');
$check('...the job waits five minutes, asking nothing', $calls() === 0 && $in_queue($tz->id) && $when && $when >= time() + 290);
$check('...the games\' query reads no seed or stage column', strpos($sql->invoke(null), "'' AS home_avatar_seed, '' AS away_avatar_seed, 1 AS home_avatar_stage, 1 AS away_avatar_stage") !== false);
remove_filter('pre_option_' . RSL_Database::SCHEMA_OPTION, $behind);
$behind = null;
$set_queue(array());

// --- roll_over() keeps the seed; the robot starts again at stage 1 ---------------------------------------------------------------
$rc = $mk('rollcap', 'subscriber', true);
$rc2 = $mk('rollcap2', 'subscriber', true);
$ro = $mkteam($A, "Tee Rolling $suffix", array('level' => 'strong'));
$ro2 = $mkteam($A, "Tee Rolling Two $suffix", array('level' => 'social'));
RSL_Teams::add_member($ro->id, $rc, 'captain', false, array('admin' => true));
RSL_Teams::add_member($ro2->id, $rc2, 'captain', false, array('admin' => true));
RSL_Avatars::ensure($ro);
$ro2_4 = clone $ro2;
$ro2_4->avatar_stage = 4;
RSL_Avatars::ensure($ro2_4);
$ro2 = $set_stage($ro2->id, 4);
$reset();
$rolled = RSL_Teams::roll_over($ro->id, $rc);
if ($rolled && !is_wp_error($rolled)) {
    $team_ids[] = intval($rolled->id);
}
$check('roll_over(): the team entered again has the old team\'s seed, at stage 1', $rolled && !is_wp_error($rolled) && $rolled->season === $S && $rolled->avatar_seed === $ro->avatar_seed
    && intval($rolled->avatar_stage) === 1, is_wp_error($rolled) ? $rolled->get_error_message() : wp_json_encode($rolled));
$pr = $rolled && !is_wp_error($rolled) ? RSL_Avatars::paths($rolled) : null;
$po = RSL_Avatars::paths($ro);
$check('...an old team at stage 1: the same images, copied to its own names with no request, so nothing is queued', $pr && RSL_Avatars::urls($rolled) !== null && $calls() === 0
    && basename($pr['animated']) === 'team-' . $rolled->id . '-' . substr(md5($ro->avatar_seed), 0, 12) . '-s1.svg'
    && file_get_contents($pr['animated']) === file_get_contents($po['animated']) && file_get_contents($pr['still']) === file_get_contents($po['still']) && !$in_queue($rolled->id));
$rolled2 = RSL_Teams::roll_over($ro2->id, $rc2);
if ($rolled2 && !is_wp_error($rolled2)) {
    $team_ids[] = intval($rolled2->id);
}
$check('...an old team at stage 4: the seed is kept, the new team starts at stage 1 (Season Points start again) and is queued for its files, nothing copied',
    $rolled2 && !is_wp_error($rolled2) && $rolled2->avatar_seed === $ro2->avatar_seed && intval($rolled2->avatar_stage) === 1
    && RSL_Avatars::urls($rolled2) === null && $in_queue($rolled2->id) && $calls() === 0 && $files_of($rolled2->id) === array(), is_wp_error($rolled2) ? $rolled2->get_error_message() : '');
$check('...the old teams keep theirs', RSL_Teams::get($ro->id)->avatar_seed === $ro->avatar_seed && RSL_Avatars::urls(RSL_Teams::get($ro->id)) !== null
    && intval(RSL_Teams::get($ro2->id)->avatar_stage) === 4 && RSL_Avatars::urls(RSL_Teams::get($ro2->id)) !== null);

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
if ($current) {
    remove_filter('pre_option_' . RSL_Seasons::CURRENT_OPTION, $current);
}
if ($counter) {
    remove_filter('query', $counter);
}
if ($capture) {
    remove_filter('pre_schedule_event', $capture, 10);
}
foreach (array($A, $S) as $s_key) {
    foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM " . RSL_Database::table('teams') . " WHERE season = %s", $s_key)) as $tid) {
        $team_ids[] = intval($tid);
    }
    // The results hook's night and game (and anything a failure left).
    foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM " . RSL_Database::table('games') . " WHERE season = %s", $s_key)) as $gid) {
        $wpdb->delete(RSL_Database::table('jobs'), array('game_id' => intval($gid)));
    }
    $wpdb->delete(RSL_Database::table('games'), array('season' => $s_key));
    foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM " . RSL_Database::table('nights') . " WHERE season = %s", $s_key)) as $nid) {
        $wpdb->delete(RSL_Database::table('jobs'), array('night_id' => intval($nid)));
    }
    $wpdb->delete(RSL_Database::table('nights'), array('season' => $s_key));
    RSL_Ratings::forget_start($s_key);
}
if (isset($tdel) && is_object($tdel)) {
    $team_ids[] = intval($tdel->id);
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
// The queue and the deletion list as they were, with anything another request added meanwhile (never the test's teams).
$now_q = RSL_Avatars::queued(true);
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
$now_p = RSL_Avatars::purge_list();
$restored_p = $purge_before;
foreach ($now_p as $name => $at) {
    $owner = preg_match('/^team-(\d+)-/', (string) $name, $mm) ? intval($mm[1]) : 0;
    if (!in_array($owner, $team_ids, true) && !isset($restored_p[$name])) {
        $restored_p[$name] = $at;
    }
}
foreach (array_keys($restored_p) as $name) {
    if (preg_match('/^team-(\d+)-/', (string) $name, $mm) && in_array(intval($mm[1]), $team_ids, true)) {
        unset($restored_p[$name]);
    }
}
if ($restored_p) {
    update_option(RSL_Avatars::PURGE_OPTION, $restored_p, false);
} else {
    delete_option(RSL_Avatars::PURGE_OPTION);
}
// The stage flags as they were, without the test's seasons.
$now_f = get_option(RSL_Avatars::STAGE_OPTION, array());
$restored_f = is_array($flags_before) ? $flags_before : array();
foreach (is_array($now_f) ? $now_f : array() as $s_key => $v) {
    if (!in_array($s_key, array($A, $S), true)) {
        $restored_f[$s_key] = $v;
    }
}
unset($restored_f[$A], $restored_f[$S]);
if ($restored_f) {
    update_option(RSL_Avatars::STAGE_OPTION, $restored_f, false);
} else {
    delete_option(RSL_Avatars::STAGE_OPTION);
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
// Last: the mail stop comes off, the avatar job goes back (only while anything is queued or flagged).
rsl_dev_release_mail($hold);
}

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
