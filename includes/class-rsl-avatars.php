<?php
/**
 * Team avatars (0.23.0, club decision 7 Nov 2026): every team's badge is a DiceBear Voxel Bot
 * (style CC0) drawn from a random seed, never from the team's name, so DiceBear never learns a
 * name and a renamed team keeps its look. teams.avatar_seed (schema 13; '' = none yet) is the
 * seed: a random string, never an account id, so RSL_Merge lists nothing for it.
 *
 * Each seed's two images (animated, and still for anyone who asked their browser for less
 * motion) are fetched ONCE from DiceBear's hosted API by the server and kept on this site under
 * uploads/rsl-team-avatars/, so a visitor's browser never contacts DiceBear. A file's name is the
 * team id and a short hash of the seed, so a new seed is a new URL and no browser shows a stale
 * image. What comes back is checked before it is saved (clean()); a failed fetch saves nothing.
 *
 * Nothing is fetched while a page is drawn (a ladder of 30 teams must not make 60 requests in
 * one view): a badge whose files are missing is drawn as today's initials and its team goes on
 * the queue (QUEUE_OPTION), which one WP-cron event (CRON_HOOK, work()) works through, up to
 * BATCH teams a run, rescheduling itself while any remain. Team creation (RSL_Teams::create())
 * sets a seed and queues the team; the schema 13 upgrade gives every team a seed (backfill()).
 * A captain (or a league admin) can ask for another (regenerate()), DAILY_LIMIT a team a day.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RSL_Avatars {

    /** DiceBear's hosted API for the style (the version is part of the path). */
    const API = 'https://api.dicebear.com/10.x/voxel-bot/svg';

    /** Soft backgrounds, hex without #: DiceBear picks one per seed. */
    const BACKGROUNDS = array('b6e3f4', 'c0aede', 'd1d4f9', 'ffd5dc', 'ffdfbf', 'c7ecd9', 'f9e8a7', 'fbd3d0');

    /** The folder under the uploads folder. */
    const DIR = 'rsl-team-avatars';

    /** team id => array('tries' => failed fetches so far, 'next' => unix time it may be tried again). */
    const QUEUE_OPTION = 'rsl_avatar_queue';

    /** The one WP-cron event that fetches queued teams' images. */
    const CRON_HOOK = 'rsl_avatar_fetch';

    /** Teams fetched in one run of the job. */
    const BATCH = 10;

    /** A run starts no new team after this many seconds (a fetch can take TIMEOUT twice). */
    const TIME_BUDGET = 10;

    /** Seconds between runs while teams that may be tried now remain. */
    const GAP = 30;

    /** Seconds a fetch may take. */
    const TIMEOUT = 8;

    /** The largest image saved, in bytes (a Voxel Bot is about 7 KB). */
    const MAX_BYTES = 65536;

    /** New avatars a team may ask for in a day (the site's date), captain and league admins together. */
    const DAILY_LIMIT = 20;

    /** The count of a team's tries today: this prefix, the team id and the date. */
    const TRIES_PREFIX = 'rsl_avatar_tries_';

    /** A replaced avatar's files stay this long (seconds), for pages a cache still serves. */
    const KEEP_OLD = 172800;

    /** The img's width and height for each badge size (the stylesheets set the drawn size, as for the initials). */
    const SIZES = array('' => 44, 'large' => 72, 'small' => 28);

    /** What the captain (or league admin) reads after a new avatar is saved. */
    const SAVED_WORDS = 'New avatar saved. Not keen? Try again.';

    /** The uploads folder's path and URL, read once a request. */
    private static $dir = null;

    public static function register_hooks() {
        add_action(self::CRON_HOOK, array(__CLASS__, 'work'));
    }

    // ------------------------------------------------------------------
    // Seeds and files
    // ------------------------------------------------------------------

    /** A new random seed: 16 hex characters. Never a team's name. */
    public static function new_seed() {
        try {
            return bin2hex(random_bytes(8));
        } catch (Exception $e) {
            return substr(md5(wp_generate_password(32, true, true)), 0, 16);
        }
    }

    /** True for a seed new_seed() could have made. */
    public static function valid_seed($seed) {
        return is_string($seed) && preg_match('/^[a-f0-9]{16}$/', $seed) === 1;
    }

    /** The API address for a seed: the animated image, or the still one ($animated false). */
    public static function api_url($seed, $animated) {
        return self::API . '?seed=' . rawurlencode((string) $seed) . '&flip=horizontal' . ($animated ? '&tags=animation' : '')
            . '&borderRadius=20&backgroundColor=' . implode(',', self::BACKGROUNDS);
    }

    /** The uploads folder for avatars: array('path', 'url'), or null when uploads can't be found. */
    private static function dir() {
        if (self::$dir === null) {
            $up = wp_upload_dir(null, false);
            self::$dir = is_array($up) && !empty($up['basedir']) && !empty($up['baseurl'])
                ? array('path' => untrailingslashit($up['basedir']) . '/' . self::DIR, 'url' => untrailingslashit($up['baseurl']) . '/' . self::DIR)
                : false;
        }
        return self::$dir ?: null;
    }

    /**
     * A team's two files: array('seed', 'animated', 'still', 'animated_url', 'still_url'), or null
     * when the object has no team id or no seed (a partial object some callers build, or a team
     * before it has a seed). Names: team-<id>-<12 hex of the seed's MD5>.svg and ...-still.svg.
     */
    public static function paths($team) {
        if (!is_object($team) || empty($team->id) || intval($team->id) <= 0 || !isset($team->avatar_seed) || !self::valid_seed($team->avatar_seed)) {
            return null;
        }
        $dir = self::dir();
        if (!$dir) {
            return null;
        }
        $base = 'team-' . intval($team->id) . '-' . substr(md5($team->avatar_seed), 0, 12);
        return array(
            'seed' => $team->avatar_seed,
            'animated' => $dir['path'] . '/' . $base . '.svg',
            'still' => $dir['path'] . '/' . $base . '-still.svg',
            'animated_url' => $dir['url'] . '/' . $base . '.svg',
            'still_url' => $dir['url'] . '/' . $base . '-still.svg',
        );
    }

    /** The team's avatar: array('animated' => URL, 'still' => URL), or null while either file isn't there. Never fetches. */
    public static function urls($team) {
        $p = self::paths($team);
        if (!$p || !file_exists($p['animated']) || !file_exists($p['still'])) {
            return null;
        }
        return array('animated' => $p['animated_url'], 'still' => $p['still_url']);
    }

    /**
     * The team's two files are there: fetched and saved now when either is missing. True when
     * both are there; false (nothing saved) when the team has no seed, uploads can't be written,
     * or either fetch fails or is refused (fetch()).
     */
    public static function ensure($team) {
        $p = self::paths($team);
        if (!$p) {
            return false;
        }
        if (file_exists($p['animated']) && file_exists($p['still'])) {
            return true;
        }
        if (!self::make_dir()) {
            return false;
        }
        $animated = self::fetch(self::api_url($p['seed'], true));
        if ($animated === null) {
            return false;
        }
        $still = self::fetch(self::api_url($p['seed'], false));
        if ($still === null) {
            return false;
        }
        if (!self::write($p['animated'], $animated)) {
            return false;
        }
        if (!self::write($p['still'], $still)) {
            @unlink($p['animated']);
            return false;
        }
        return true;
    }

    /** One image from the API, checked (clean()); null for anything but a 200 image/svg+xml that passes. */
    private static function fetch($url) {
        $r = wp_remote_get($url, array(
            'timeout' => self::TIMEOUT,
            'redirection' => 2,
            'limit_response_size' => self::MAX_BYTES + 1,
            'headers' => array('Accept' => 'image/svg+xml'),
        ));
        if (is_wp_error($r) || intval(wp_remote_retrieve_response_code($r)) !== 200) {
            return null;
        }
        $type = wp_remote_retrieve_header($r, 'content-type');
        $type = strtolower(trim((string) (is_array($type) ? reset($type) : $type)));
        if (strpos($type, 'image/svg+xml') !== 0) {
            return null;
        }
        return self::clean((string) wp_remote_retrieve_body($r));
    }

    /**
     * The check before an image is saved (pure). Returns the SVG (trimmed) or null to save
     * nothing. It must start with <svg and end with </svg>, and be at most MAX_BYTES. Refused
     * whole: a script or a foreignObject (with or without a namespace prefix), anything in the
     * XHTML namespace, a DOCTYPE or ENTITY, a numeric character reference (it could spell any of
     * these), an on* event attribute, a javascript: or vbscript: address, an href or xlink:href
     * to anything but a #fragment in the file, a CSS url() to anything but a #fragment, @import,
     * image-set() or expression(), and an animation that sets an href.
     */
    public static function clean($svg) {
        if (!is_string($svg) || $svg === '' || strlen($svg) > self::MAX_BYTES) {
            return null;
        }
        $s = trim($svg);
        if (!preg_match('/^<svg[\s>]/i', $s) || !preg_match('/<\/svg>$/i', $s)) {
            return null;
        }
        $never = '/<\s*(?:[\w.-]+:)?(?:script|foreignObject)\b|1999\/xhtml|<!\s*(?:DOCTYPE|ENTITY)|&#|(?:java|vb)script\s*:|@import|image-set\s*\(|expression\s*\(/i';
        if (preg_match($never, $s)) {
            return null;
        }
        if (preg_match('/[\s\/"\']on[a-z]+\s*=/i', $s)) {
            return null;
        }
        if (preg_match_all('/\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]*))/i', $s, $links, PREG_SET_ORDER)) {
            foreach ($links as $l) {
                $to = isset($l[3]) && $l[3] !== '' ? $l[3] : (isset($l[2]) && $l[2] !== '' ? $l[2] : (isset($l[1]) ? $l[1] : ''));
                if (!preg_match('/^#[A-Za-z0-9_.:-]+$/', $to)) {
                    return null;
                }
            }
        }
        if (preg_match('/url\s*\(\s*(?![\'"]?\s*#)/i', $s) || preg_match('/attributeName\s*=\s*["\']?\s*(?:xlink:)?href/i', $s)) {
            return null;
        }
        return $s;
    }

    /** The folder exists with an index.php in it; its path and URL, or null. */
    private static function make_dir() {
        $dir = self::dir();
        if (!$dir) {
            return null;
        }
        if (!is_dir($dir['path']) && !wp_mkdir_p($dir['path'])) {
            return null;
        }
        if (!file_exists($dir['path'] . '/index.php')) {
            @file_put_contents($dir['path'] . '/index.php', "<?php\n// Silence is golden.\n");
        }
        return is_writable($dir['path']) ? $dir : null;
    }

    /** Write a file whole: to a temporary name, then renamed, so a half-written image is never served. */
    private static function write($path, $contents) {
        $tmp = $path . '.' . wp_generate_password(8, false, false) . '.tmp';
        if (@file_put_contents($tmp, $contents, LOCK_EX) !== strlen($contents) || !@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        @chmod($path, defined('FS_CHMOD_FILE') ? FS_CHMOD_FILE : 0644);
        return true;
    }

    // ------------------------------------------------------------------
    // The badge
    // ------------------------------------------------------------------

    /**
     * The avatar badge from urls(): the still image for a browser that asked for less motion, the
     * animated one for everyone else. aria-hidden as the initials badge is (the team name is always
     * beside it), alt "". $size: '' (44), 'large' (72, the team page) or 'small' (28, fixtures and
     * the ladder); the stylesheets set the drawn size, as they do for the initials.
     */
    public static function picture($urls, $size = '') {
        $size = is_string($size) && isset(self::SIZES[$size]) ? $size : '';
        $px = self::SIZES[$size];
        return '<picture class="rsl-avatar-pic' . ($size !== '' ? ' rsl-avatar-pic-' . esc_attr($size) : '') . '" aria-hidden="true">'
            . '<source srcset="' . esc_url($urls['still']) . '" media="(prefers-reduced-motion: reduce)">'
            . '<img src="' . esc_url($urls['animated']) . '" alt="" width="' . $px . '" height="' . $px . '" loading="lazy" decoding="async"'
            . ' class="rsl-badge rsl-avatar' . ($size !== '' ? ' rsl-badge-' . esc_attr($size) : '') . '">'
            . '</picture>';
    }

    /** A badge drew the initials for a team whose files aren't there: the team goes on the queue (no fetch now). */
    public static function missing($team) {
        if (is_object($team) && !empty($team->id)) {
            self::queue(intval($team->id));
        }
    }

    /** The avatar rules every stylesheet with a badge carries (no apostrophes: they sit in single-quoted strings). */
    public static function css() {
        return '
            .rsl-avatar-pic { display: inline-flex; flex: 0 0 auto; line-height: 0; }
            img.rsl-badge.rsl-avatar { display: block; max-width: none; padding: 0; border-radius: 20%; background: #eef2f8; object-fit: cover; }
        ';
    }

    // ------------------------------------------------------------------
    // The queue and the job
    // ------------------------------------------------------------------

    /** The queue: team id => array('tries', 'next'), oldest first. */
    public static function queued() {
        $q = get_option(self::QUEUE_OPTION, array());
        return is_array($q) ? $q : array();
    }

    private static function save_queue($q) {
        if ($q) {
            update_option(self::QUEUE_OPTION, $q, false);
        } else {
            delete_option(self::QUEUE_OPTION);
        }
    }

    /** Put a team on the queue (once: a team already there keeps its place and its wait) and make sure the job will run. */
    public static function queue($team_id) {
        return self::queue_many(array($team_id));
    }

    /** Several teams at once, in the order given (one write). */
    public static function queue_many($ids) {
        $q = self::queued();
        $added = false;
        foreach ((array) $ids as $id) {
            $id = intval($id);
            if ($id > 0 && !isset($q[$id])) {
                $q[$id] = array('tries' => 0, 'next' => 0);
                $added = true;
            }
        }
        if ($added) {
            self::save_queue($q);
        }
        if ($q) {
            self::schedule(0);
        }
        return $added;
    }

    /** Take a team off the queue. */
    public static function dequeue($team_id) {
        $q = self::queued();
        if (isset($q[intval($team_id)])) {
            unset($q[intval($team_id)]);
            self::save_queue($q);
        }
    }

    /** The job runs in $delay seconds, unless it is due already. */
    public static function schedule($delay) {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + max(0, intval($delay)), self::CRON_HOOK);
        }
    }

    /** After a run: the job again while teams remain, GAP seconds on or when the first waiting one may be tried. */
    public static function reschedule() {
        $q = self::queued();
        if (!$q) {
            return;
        }
        $next = PHP_INT_MAX;
        foreach ($q as $item) {
            $next = min($next, isset($item['next']) ? intval($item['next']) : 0);
        }
        self::schedule(max(self::GAP, $next - time()));
    }

    /** Teams on the queue that no longer exist come off it (the tests' cleanup). */
    public static function forget_missing() {
        global $wpdb;
        $q = self::queued();
        if (!$q) {
            return;
        }
        $ids = array_map('intval', array_keys($q));
        $found = array_map('intval', $wpdb->get_col("SELECT id FROM " . RSL_Database::table('teams') . " WHERE id IN (" . implode(',', $ids) . ")"));
        foreach ($ids as $id) {
            if (!in_array($id, $found, true)) {
                unset($q[$id]);
            }
        }
        self::save_queue($q);
    }

    /**
     * The job (CRON_HOOK): up to BATCH queued teams that may be tried now, oldest first. A team
     * with no seed gets one first; then ensure(). Done (or a team that's gone, declined or
     * withdrawn): off the queue. A failed fetch: the team waits (10 minutes, doubling, at most a
     * day) and the run stops, since the service is likely down or slow. No new team after
     * TIME_BUDGET seconds. One run at a time (a database lock). Reschedules itself while any remain.
     */
    public static function work() {
        global $wpdb;
        if (!RSL_Database::schema_current()) {
            self::schedule(300);
            return;
        }
        if ((string) $wpdb->get_var("SELECT GET_LOCK('rsl_avatar_work', 0)") !== '1') {
            return; // another run holds it, and reschedules when it ends
        }
        $started = microtime(true);
        try {
            $done = 0;
            foreach (array_keys(self::queued()) as $team_id) {
                if ($done >= self::BATCH || microtime(true) - $started > self::TIME_BUDGET) {
                    break;
                }
                $q = self::queued();
                if (!isset($q[$team_id]) || (isset($q[$team_id]['next']) && intval($q[$team_id]['next']) > time())) {
                    continue;
                }
                $done++;
                $ok = self::work_one(intval($team_id));
                $q = self::queued(); // afresh: a page may have queued a team meanwhile
                if ($ok) {
                    unset($q[$team_id]);
                } elseif (isset($q[$team_id])) {
                    $tries = (isset($q[$team_id]['tries']) ? intval($q[$team_id]['tries']) : 0) + 1;
                    $q[$team_id] = array('tries' => $tries, 'next' => time() + (int) min(DAY_IN_SECONDS, 600 * pow(2, min($tries - 1, 10))));
                }
                self::save_queue($q);
                if (!$ok) {
                    break;
                }
            }
        } finally {
            $wpdb->query("SELECT RELEASE_LOCK('rsl_avatar_work')");
        }
        self::reschedule();
    }

    /** One queued team: true when it's done or there's nothing to draw, false when the fetch failed. */
    private static function work_one($team_id) {
        global $wpdb;
        $team = RSL_Teams::get($team_id);
        if (!$team || in_array($team->status, array('declined', 'withdrawn'), true)) {
            return true;
        }
        if (!isset($team->avatar_seed) || !self::valid_seed($team->avatar_seed)) {
            // Only a team still without one: never over a seed set meanwhile.
            $wpdb->query($wpdb->prepare("UPDATE " . RSL_Database::table('teams') . " SET avatar_seed = %s WHERE id = %d AND avatar_seed = ''", self::new_seed(), $team->id));
            $team = RSL_Teams::get($team_id);
            if (!$team) {
                return true;
            }
        }
        return self::ensure($team);
    }

    /**
     * The schema 13 upgrade's step (safe to repeat): every team with no seed gets one, and those
     * active or waiting for approval go on the queue, newest first (a declined or withdrawn
     * application is never drawn). Returns how many got a seed.
     */
    public static function backfill() {
        global $wpdb;
        if (!RSL_Database::schema_current()) {
            return 0;
        }
        $table = RSL_Database::table('teams');
        $rows = $wpdb->get_results("SELECT id, status FROM $table WHERE avatar_seed = '' ORDER BY id DESC");
        $queue = array();
        $n = 0;
        foreach ((array) $rows as $r) {
            if ($wpdb->query($wpdb->prepare("UPDATE $table SET avatar_seed = %s WHERE id = %d AND avatar_seed = ''", self::new_seed(), $r->id))) {
                $n++;
                if (in_array($r->status, array('active', 'pending'), true)) {
                    $queue[] = intval($r->id);
                }
            }
        }
        if ($queue) {
            self::queue_many($queue);
        }
        return $n;
    }

    /**
     * A team entered again (RSL_Teams::roll_over()) has the old team's seed: the old team's files
     * are copied to the new team's names, so it keeps the same images without a fetch. False when
     * the seeds differ or the old files aren't there (the queue fetches them then).
     */
    public static function copy_from($from, $to) {
        $a = self::paths($from);
        $b = self::paths($to);
        if (!$a || !$b || $a['seed'] !== $b['seed'] || !file_exists($a['animated']) || !file_exists($a['still'])) {
            return false;
        }
        if (!file_exists($b['animated']) || !file_exists($b['still'])) {
            if (!self::make_dir()) {
                return false;
            }
            $animated = @file_get_contents($a['animated']);
            $still = @file_get_contents($a['still']);
            if (!is_string($animated) || !is_string($still) || !self::write($b['animated'], $animated)) {
                return false;
            }
            if (!self::write($b['still'], $still)) {
                @unlink($b['animated']);
                return false;
            }
        }
        self::dequeue($to->id);
        return true;
    }

    // ------------------------------------------------------------------
    // A new avatar
    // ------------------------------------------------------------------

    /** The transient counting a team's tries today (the site's date). */
    public static function tries_key($team_id) {
        return self::TRIES_PREFIX . intval($team_id) . '_' . current_time('Ymd');
    }

    /**
     * Another avatar for the team, asked for by its captain or a league admin (RSL_Access::can()).
     * A new seed, both images fetched and saved, and only then the seed written; the old files
     * stay KEEP_OLD for pages a cache still serves, and older replaced ones go. Refused, the
     * old avatar kept, with a sentence: not the captain, the schema upgrade still to finish,
     * DAILY_LIMIT tries today (every try that asks DiceBear counts, a failed one too), a failed
     * fetch, or a failed save. Returns the team or WP_Error.
     */
    public static function regenerate($team_id, $user_id) {
        global $wpdb;
        $user_id = intval($user_id);
        $team = RSL_Teams::get(intval($team_id));
        if (!$team) {
            return new WP_Error('rsl_team', 'That team no longer exists.');
        }
        if (!RSL_Teams::is_captain($team->id, $user_id) && !RSL_Access::can($user_id)) {
            return new WP_Error('rsl_not_captain', 'Only the captain can change the team\'s avatar.');
        }
        if (!RSL_Database::schema_current()) {
            return new WP_Error('rsl_busy', 'The league is being updated right now — please try again in a minute.');
        }
        $key = self::tries_key($team->id);
        $tries = intval(get_transient($key));
        if ($tries >= self::DAILY_LIMIT) {
            return new WP_Error('rsl_avatar_limit', 'You\'ve tried ' . self::DAILY_LIMIT . ' avatars today; try again tomorrow.');
        }
        set_transient($key, $tries + 1, DAY_IN_SECONDS);
        $old = self::paths($team);
        $next = clone $team;
        $next->avatar_seed = self::new_seed();
        if (!self::ensure($next)) {
            return new WP_Error('rsl_avatar_fetch', 'A new avatar couldn\'t be fetched just now, so the old one stays. Try again in a few minutes.');
        }
        $made = self::paths($next);
        if ($wpdb->update(RSL_Database::table('teams'), array('avatar_seed' => $next->avatar_seed), array('id' => $team->id)) === false) {
            @unlink($made['animated']);
            @unlink($made['still']);
            return new WP_Error('rsl_save', 'The new avatar couldn\'t be saved, so the old one stays. Please try again.');
        }
        self::dequeue($team->id);
        self::tidy($team->id, $made, $old);
        return RSL_Teams::get($team->id);
    }

    /**
     * After a new avatar: the files just replaced ($old) are touched, so they stay KEEP_OLD from
     * now; this team's other files that aren't the new ones ($made) and are older than that go.
     */
    private static function tidy($team_id, $made, $old) {
        $keep = array(basename($made['animated']), basename($made['still']));
        if ($old) {
            foreach (array('animated', 'still') as $k) {
                if (file_exists($old[$k])) {
                    @touch($old[$k]);
                    $keep[] = basename($old[$k]);
                }
            }
        }
        $dir = self::dir();
        $files = $dir ? glob($dir['path'] . '/team-' . intval($team_id) . '-*.svg') : array();
        foreach (is_array($files) ? $files : array() as $f) {
            if (!in_array(basename($f), $keep, true) && @filemtime($f) < time() - self::KEEP_OLD) {
                @unlink($f);
            }
        }
    }
}
