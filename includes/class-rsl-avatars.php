<?php
/**
 * Team avatars (0.23.0, club decisions 7 Nov 2026): every team's badge is a DiceBear Voxel Bot
 * (style CC0) drawn from a random seed, never from the team's name, so DiceBear never learns a
 * name and a renamed team keeps its look. teams.avatar_seed (schema 13; '' = none yet) is the
 * seed: a random string, never an account id, so RSL_Merge lists nothing for it.
 *
 * The robot builds up with the team's Season Points level (RSL_Stats::level(), 30 points a
 * level since 0.23.2, so fully built at 210). Season Points start again every season, so every team's robot starts at level 1.
 * Stage = min(8, level): stage 1 is the body and eyes on one background colour, 2 adds the
 * mouth, 3 the chest, 4 the top; from 5 the background is a 45 degree gradient of 2 colours, then
 * 3, then 4, and from 8 all 5 (fully built). Every part, colour and the animation speed is
 * pinned per team from its seed by our own hash (picks()), never left to DiceBear's pick, so a
 * stage only ever adds. teams.avatar_stage (schema 14) is the stage the team's files are for:
 * the badge draws that and never works out stats.
 *
 * Each seed and stage's two images (animated, and still for anyone who asked their browser for
 * less motion) are fetched ONCE from DiceBear's hosted API by the server and kept on this site
 * under uploads/rsl-team-avatars/, so a visitor's browser never contacts DiceBear. A file's name
 * is the team id, a short hash of the seed and the stage, so a new seed or stage is a new URL and
 * no browser shows a stale image. What comes back is checked before it is saved (clean()); a
 * failed fetch saves nothing.
 *
 * Nothing is fetched while a page is drawn (a ladder of 30 teams must not make 60 requests in one
 * view): a badge whose files are missing is drawn as the initials and its team goes on the queue
 * (QUEUE_OPTION), which one WP-cron event (CRON_HOOK, work()) works through, up to BATCH teams a
 * run, rescheduling itself while any remain. Stages are kept current by the same job: results
 * saved and nights published (the rsl_results_changed action, from RSL_Fixtures) and the hourly
 * job flag a season (STAGE_OPTION); the next run works out that season's levels once through
 * RSL_Stats and queues every team whose stage changed, and the team's new stage is fetched, then
 * written. Files a team no longer uses (a new seed, a new stage, a team that won't play) are
 * deleted KEEP_OLD later (PURGE_OPTION, purge()), for pages a cache still serves; the files are
 * only a copy made from the seed, so a missing one is simply fetched again.
 *
 * The one place a browser loads DiceBear directly (club decision, 7 Nov 2026): the robot chooser
 * (chooser()) a captain sees when applying, and New avatar, show previews straight from
 * api.dicebear.com, only to the captain or league admin using it and only in that box. The
 * address carries the seed and the style's options, never the team's name, and no referrer.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RSL_Avatars {

    /** DiceBear's hosted API for the style (the version is part of the path). */
    const API = 'https://api.dicebear.com/10.x/voxel-bot/svg';

    /** The last stage: fully built, from level 8 on. */
    const STAGES = 8;

    /** Each part and the stage it comes in; the body is always there. */
    const PARTS = array('eyes' => 1, 'mouth' => 2, 'chest' => 3, 'top' => 4);

    /** From this stage the background is a gradient: 2 colours here, one more a stage, BACKGROUND_STOPS at STAGES. */
    const GRADIENT_FROM = 5;

    /** Colours in a team's background (all of them once fully built). */
    const BACKGROUND_STOPS = 5;

    /** The gradient's angle in degrees. */
    const ANGLE = 45;

    // The style's own variants and default palettes, copied from DiceBear's definition of the
    // style (api.dicebear.com/10.x/voxel-bot/definition.json, @dicebear/styles 10.6.0).
    // Colours are hex without the #. Our own hash picks one of each per seed (picks()).
    const EYES = array('round', 'square', 'visor', 'cyclops', 'happy', 'sleepy', 'plus');
    const MOUTHS = array('grill', 'speaker', 'line', 'smile', 'zigzag');
    const CHESTS = array('buttons', 'vents', 'screen', 'heart', 'dial', 'slot');
    const TOPS = array('antenna', 'twin', 'dish', 'fin', 'lightbar', 'studs', 'siren');
    /** Animation speeds (the style's animation variants but none): each team has its own at every stage. */
    const SPEEDS = array('slowest', 'slow', 'medium', 'fast', 'fastest');
    const BODY_COLOURS = array('e9ecef', 'aeb8c2', '74c0fc', '63e6be', 'ffd43b', 'ff9d9d', 'b6a6f5', '69db7c');
    const ACCENT_COLOURS = array('343a40', '495057', '2f3b4c', '5f6b7a', '7a4a2d', '243b53');
    const SCREEN_COLOURS = array('161a21', '101c17', '1a1430', '211016');
    /** The eyes glow in one of these, never the body colour (the style's own rule). */
    const GLOW_COLOURS = array('3bc9db', '69db7c', 'ffd43b', 'ff6b6b', 'e599f7', '74c0fc', 'fff3bf');

    /** Soft backgrounds, hex without #: each team has 5 of them in an order its seed sets (the first five are the style's own). */
    const BACKGROUNDS = array('b6e3f4', 'c0aede', 'd1d4f9', 'ffd5dc', 'ffdfbf', 'c7ecd9', 'f9e8a7', 'd9efb5', 'f0c8ea', 'e8dfd0');

    /** The folder under the uploads folder. */
    const DIR = 'rsl-team-avatars';

    /** A file the league saves there: team-<id>-<12 hex of the seed's MD5>-s<stage>.svg and ...-still.svg. */
    const FILE_PATTERN = '/^team-(\d+)-[a-f0-9]{12}(?:-s[1-8])?(?:-still)?\.svg\z/';

    /**
     * The only elements an image may hold (clean()): every element in DiceBear's real Voxel Bot
     * output, at every stage, animated and still. Anything else is refused whole.
     */
    const SVG_ELEMENTS = array('svg', 'metadata', 'rdf:RDF', 'rdf:Description', 'dc:title', 'dc:creator', 'dc:source', 'dc:rights', 'dcterms:license',
        'defs', 'linearGradient', 'radialGradient', 'stop', 'clipPath', 'g', 'path', 'rect', 'use', 'style');

    /** The only namespace declarations an image may make (clean()): prefix ('' the default) => its exact address, as DiceBear's output has them. */
    const SVG_NAMESPACES = array(
        '' => 'http://www.w3.org/2000/svg',
        'rdf' => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
        'xsi' => 'http://www.w3.org/2001/XMLSchema-instance',
        'dc' => 'http://purl.org/dc/elements/1.1/',
        'dcterms' => 'http://purl.org/dc/terms/',
    );

    /** team id => array('tries' => failed fetches so far, 'next' => unix time it may be tried again). */
    const QUEUE_OPTION = 'rsl_avatar_queue';

    /** season => 1: seasons whose teams' stages the next run checks (sweep()). */
    const STAGE_OPTION = 'rsl_avatar_stage_check';

    /** file name => unix time it may be deleted (purge()). */
    const PURGE_OPTION = 'rsl_avatar_purge';

    /** The one WP-cron event that fetches queued teams' images. */
    const CRON_HOOK = 'rsl_avatar_fetch';

    /** The action RSL_Fixtures fires when a season's results or published nights change. */
    const RESULTS_HOOK = 'rsl_results_changed';

    /** Seconds after results change before the job checks stages (one run for a night's results). */
    const STAGE_DELAY = 300;

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

    /** A file a team no longer uses stays this long (seconds), for pages a cache still serves. */
    const KEEP_OLD = 172800;

    /** The img's width and height for each badge size (the stylesheets set the drawn size, as for the initials). */
    const SIZES = array('' => 44, 'large' => 72, 'medium' => 36, 'small' => 28);

    /** Robots the chooser offers (Try another goes round them, saying which one it shows). */
    const CHOICES = 12;

    /** What the captain (or league admin) reads after a new robot is saved. */
    const SAVED_WORDS = 'New robot saved.';

    /** The chooser's line on the application. */
    const START_WORDS = 'It starts at level 1 and builds up as your team earns Season Points.';

    /** The chooser's line under New avatar. */
    const KEEPS_LEVEL_WORDS = 'The new robot keeps the team\'s level.';

    /**
     * The chooser's words after "Robot 12 of 12." on a form with typed fields (the captain's
     * application): never to reload, which can lose what was typed.
     */
    const LAST_WORDS = 'Try another goes back to the first.';

    /** The same under New avatar (the team page, the Teams screen), where there is nothing typed to lose. */
    const MORE_WORDS = 'Reload the page for more.';

    /** Another site's run on the same database server (GET_LOCK names are server-wide) never holds this site's lock: lock_name(). */
    const LOCK_PREFIX = 'rsl_avatar_';

    /** A test's mail stop (dev/lib-mail-stop.php) sets this to a unix time: until then schedule() never moves a run it finds earlier. */
    const HOLD_OPTION = 'rsl_avatar_hold_until';

    /** The uploads folder's path and URL, read once a request. */
    private static $dir = null;

    /** seed => picks(), this request. */
    private static $picks = array();

    /** season => team id => stage, for one run of the job (season_stages()). */
    private static $stages = array();

    /** The chooser's script is printed once a page. */
    private static $script_done = false;

    public static function register_hooks() {
        add_action(self::CRON_HOOK, array(__CLASS__, 'work'));
        add_action(self::RESULTS_HOOK, array(__CLASS__, 'stage_check'));
        add_action('rsl_hourly', array(__CLASS__, 'hourly'));
    }

    // ------------------------------------------------------------------
    // Seeds, picks and the address
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
        return is_string($seed) && preg_match('/^[a-f0-9]{16}\z/', $seed) === 1; // \z: a trailing newline is no seed
    }

    /** A seed from a form's avatar_seed field, unslashed and trimmed ('' when there is none); valid_seed() judges it. */
    public static function posted_seed($post) {
        return is_array($post) && isset($post['avatar_seed']) && is_scalar($post['avatar_seed']) ? trim((string) wp_unslash($post['avatar_seed'])) : '';
    }

    /** A number from 0 to $n - 1 for a seed and a part (pure): our own hash, so DiceBear's pick never decides. */
    public static function pick($seed, $what, $n) {
        return intval(hexdec(substr(md5('rsl-avatar|' . $what . '|' . (string) $seed), 0, 7))) % max(1, intval($n));
    }

    /**
     * Everything about a seed's robot (pure, the same for a seed forever): array(eyes, mouth,
     * chest, top, speed (the animation variant), body, accent, screen, glow (never the body
     * colour), backgrounds (BACKGROUND_STOPS of BACKGROUNDS, in the seed's order)).
     */
    public static function picks($seed) {
        $seed = (string) $seed;
        if (isset(self::$picks[$seed])) {
            return self::$picks[$seed];
        }
        $body = self::BODY_COLOURS[self::pick($seed, 'body', count(self::BODY_COLOURS))];
        $glows = array_values(array_diff(self::GLOW_COLOURS, array($body)));
        $backgrounds = self::BACKGROUNDS;
        usort($backgrounds, function ($a, $b) use ($seed) {
            return strcmp(md5('rsl-avatar|background|' . $seed . '|' . $a), md5('rsl-avatar|background|' . $seed . '|' . $b));
        });
        return self::$picks[$seed] = array(
            'eyes' => self::EYES[self::pick($seed, 'eyes', count(self::EYES))],
            'mouth' => self::MOUTHS[self::pick($seed, 'mouth', count(self::MOUTHS))],
            'chest' => self::CHESTS[self::pick($seed, 'chest', count(self::CHESTS))],
            'top' => self::TOPS[self::pick($seed, 'top', count(self::TOPS))],
            'speed' => self::SPEEDS[self::pick($seed, 'speed', count(self::SPEEDS))],
            'body' => $body,
            'accent' => self::ACCENT_COLOURS[self::pick($seed, 'accent', count(self::ACCENT_COLOURS))],
            'screen' => self::SCREEN_COLOURS[self::pick($seed, 'screen', count(self::SCREEN_COLOURS))],
            'glow' => $glows[self::pick($seed, 'glow', count($glows))],
            'backgrounds' => array_slice($backgrounds, 0, self::BACKGROUND_STOPS),
        );
    }

    /** A level (RSL_Stats::level()) as a stage: 1 to STAGES. */
    public static function stage_for_level($level) {
        return max(1, min(self::STAGES, intval($level)));
    }

    /** Season Points as a stage. */
    public static function stage_for_points($points) {
        $level = RSL_Stats::level(intval($points));
        return self::stage_for_level($level['level']);
    }

    /** The stage a team's files are for (its stored avatar_stage; 1 when it has none). */
    public static function stage_of($team) {
        return is_object($team) && isset($team->avatar_stage) ? self::stage_for_level($team->avatar_stage) : 1;
    }

    /** The background colours at a stage: the first one to stage 4, then 2, 3, 4 and all 5. */
    public static function backgrounds_at($seed, $stage) {
        $stage = self::stage_for_level($stage);
        $all = self::picks($seed)['backgrounds'];
        return $stage < self::GRADIENT_FROM ? array($all[0]) : array_slice($all, 0, min(self::BACKGROUND_STOPS, $stage - self::GRADIENT_FROM + 2));
    }

    /**
     * The API's options for a seed at a stage (pure, in a fixed order): the seed, flipped, the
     * corners (borderRadius=20; 10.x ignores radius), each part pinned with its probability 100
     * from its stage on and 0 before, the four colours, the background (solid, or linear with its
     * stops), the colours in our order and 45 degrees; the animated image adds tags=animation and
     * the team's speed. The still image is the same stage without the animation.
     */
    public static function query($seed, $stage, $animated) {
        $stage = self::stage_for_level($stage);
        $p = self::picks($seed);
        $q = array('seed' => (string) $seed, 'flip' => 'horizontal', 'borderRadius' => '20');
        foreach (self::PARTS as $part => $from) {
            $q[$part . 'Variant'] = $p[$part];
            $q[$part . 'Probability'] = $stage >= $from ? '100' : '0';
        }
        $q['bodyColor'] = $p['body'];
        $q['accentColor'] = $p['accent'];
        $q['screenColor'] = $p['screen'];
        $q['glowColor'] = $p['glow'];
        $bg = self::backgrounds_at($seed, $stage);
        $q['backgroundColor'] = implode(',', $bg);
        $q['backgroundColorFill'] = count($bg) > 1 ? 'linear' : 'solid';
        if (count($bg) > 1) {
            $q['backgroundColorFillStops'] = (string) count($bg);
        }
        $q['backgroundColorOrder'] = 'fixed';
        $q['backgroundColorAngle'] = (string) self::ANGLE;
        if ($animated) {
            $q['tags'] = 'animation';
            $q['animationVariant'] = $p['speed'];
        }
        return $q;
    }

    /** The API address for a seed at a stage: the animated image, or the still one ($animated false). */
    public static function api_url($seed, $stage, $animated) {
        $parts = array();
        foreach (self::query($seed, $stage, $animated) as $k => $v) {
            $parts[] = $k . '=' . implode(',', array_map('rawurlencode', explode(',', $v)));
        }
        return self::API . '?' . implode('&', $parts);
    }

    // ------------------------------------------------------------------
    // Files
    // ------------------------------------------------------------------

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
     * A team's two files at its stored stage: array('seed', 'stage', 'animated', 'still',
     * 'animated_url', 'still_url'), or null when the object has no team id or no seed (a partial
     * object some callers build, or a team before it has a seed). Names:
     * team-<id>-<12 hex of the seed's MD5>-s<stage>.svg and ...-still.svg.
     */
    public static function paths($team) {
        if (!is_object($team) || empty($team->id) || intval($team->id) <= 0 || !isset($team->avatar_seed) || !self::valid_seed($team->avatar_seed)) {
            return null;
        }
        $dir = self::dir();
        if (!$dir) {
            return null;
        }
        $stage = self::stage_of($team);
        $base = 'team-' . intval($team->id) . '-' . substr(md5($team->avatar_seed), 0, 12) . '-s' . $stage;
        return array(
            'seed' => $team->avatar_seed,
            'stage' => $stage,
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
     * The team's two files at its stage are there: fetched and saved now when either is missing.
     * True when both are there; false (nothing saved) when the team has no seed, uploads can't be
     * written, or either fetch fails or is refused (fetch()).
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
        $animated = self::fetch(self::api_url($p['seed'], $p['stage'], true));
        if ($animated === null) {
            return false;
        }
        $still = self::fetch(self::api_url($p['seed'], $p['stage'], false));
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

    /**
     * One image from the API, checked (clean()); null for anything but a 200 image/svg+xml that
     * passes. wp_safe_remote_get() with no redirects: an answer can't send the server anywhere
     * else (a redirect is not a 200, so it saves nothing).
     */
    private static function fetch($url) {
        $r = wp_safe_remote_get($url, array(
            'timeout' => self::TIMEOUT,
            'redirection' => 0,
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
     * nothing. It must start with <svg and end with </svg>, and be at most MAX_BYTES. An
     * allowlist first, from DiceBear's real output: every element must be one of SVG_ELEMENTS
     * (so a script or foreignObject under any prefix, an image, a link or an animation is
     * refused) and every namespace declaration one of SVG_NAMESPACES with its exact address; no
     * backslash (a CSS escape could spell url() or @import), no processing instruction and no
     * <! but a comment (DOCTYPE, ENTITY, CDATA). Then, refused whole as well: anything in the
     * XHTML namespace, a numeric character reference (it could spell any of these), an on* event
     * attribute, a javascript: or vbscript: address, an href or xlink:href to anything but a
     * #fragment in the file, a CSS url() to anything but a #fragment, @import, image-set() or
     * expression(), and an animation that sets an href.
     */
    public static function clean($svg) {
        if (!is_string($svg) || $svg === '' || strlen($svg) > self::MAX_BYTES) {
            return null;
        }
        $s = trim($svg);
        if (!preg_match('/^<svg[\s>]/', $s) || !preg_match('/<\/svg>\z/', $s)) {
            return null;
        }
        if (strpos($s, '\\') !== false || strpos($s, '<?') !== false || preg_match('/<!(?!--)/', $s)) {
            return null;
        }
        // Every tag, opening or closing, but a comment: its name as written (a space after < is no name, so refused).
        preg_match_all('/<(?!!--)\/?([^\s\/>]*)/', $s, $tags);
        foreach ($tags[1] as $name) {
            if (!in_array($name, self::SVG_ELEMENTS, true)) {
                return null;
            }
        }
        // Every xmlns must be a plain declaration (counted both ways), each of a prefix and address on the list.
        $declared = preg_match_all('/\sxmlns(?::([^\s=\/>]+))?\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/', $s, $ns, PREG_SET_ORDER);
        if ($declared !== substr_count($s, 'xmlns')) {
            return null;
        }
        foreach ($ns as $n) {
            $prefix = isset($n[1]) ? $n[1] : '';
            $uri = isset($n[3]) && $n[3] !== '' ? $n[3] : (isset($n[2]) ? $n[2] : '');
            if (!array_key_exists($prefix, self::SVG_NAMESPACES) || self::SVG_NAMESPACES[$prefix] !== $uri) {
                return null;
            }
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
                if (!preg_match('/^#[A-Za-z0-9_.:-]+\z/', $to)) {
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

    /** The avatar and chooser rules every stylesheet with a badge carries (no apostrophes: they sit in single-quoted strings). */
    public static function css() {
        return '
            .rsl-avatar-pic { display: inline-flex; flex: 0 0 auto; line-height: 0; }
            img.rsl-badge.rsl-avatar { display: block; max-width: none; padding: 0; border-radius: 20%; background: #eef2f8; object-fit: cover; }
            .rsl-robot-box { box-sizing: border-box; max-width: 100%; width: 300px; margin: 10px 0; padding: 10px 12px; border: 1px solid #d6dce6; border-radius: 8px; background: #fff; }
            .rsl-robot-title { margin: 0; font-weight: 600; }
            .rsl-robot-pair { display: flex; flex-wrap: wrap; gap: 12px; margin: 8px 0; }
            .rsl-robot-pair figure { margin: 0; width: 96px; text-align: center; }
            .rsl-robot-pair img { display: block; width: 96px; height: 96px; max-width: none; border-radius: 20%; background: #eef2f8; }
            .rsl-robot-pair figcaption { margin-top: 4px; font-size: 13px; line-height: 1.3; }
            .rsl-robot-hint { margin: 0 0 8px; }
            .rsl-robot-box .rsl-robot-next[hidden] { display: none; }
            .rsl .rsl-robot-next { padding: 4px 12px; border: 1px solid #1d3d6e; border-radius: 4px; background: #fff; color: #1d3d6e; font-size: 14px; font-weight: 600; line-height: 1.5; text-transform: none; cursor: pointer; }
            .rsl-robot-note { margin: 6px 0 0; font-size: 13px; }
        ';
    }

    // ------------------------------------------------------------------
    // The chooser
    // ------------------------------------------------------------------

    /** CHOICES different new seeds; $first (a valid seed, as from an application draft) first. */
    public static function candidates($first = '') {
        $seeds = self::valid_seed($first) ? array($first) : array();
        while (count($seeds) < self::CHOICES) {
            $s = self::new_seed();
            if (!in_array($s, $seeds, true)) {
                $seeds[] = $s;
            }
        }
        return $seeds;
    }

    /**
     * The robot chooser (0.23.0): a box with a robot at level 1 and fully built side by side, Try
     * another, and a hidden avatar_seed field for the form around it. The server makes CHOICES
     * candidates, each with its two preview addresses (api_url(), so the pinning stays in one
     * place), in data-rsl-robots; the script only moves to the next (attributes and textContent,
     * never markup), says where it is in a live note ("Robot 3 of 12.", so a screen reader hears
     * each one) and after the last adds the last words ($args['last']), then starts again. The
     * hidden field never autocompletes and the script sets it to the first robot when it starts,
     * so a browser that restores fields on reload never sends a robot that isn't on screen.
     * Without JavaScript the first shows and is the one sent. The previews load straight from
     * api.dicebear.com in the browser of the captain (or league admin) using it, with no referrer;
     * the addresses hold the seed and the style's options only. Only logged-in captains and league
     * admins reach it, so a page cache never shows it to anyone else. $args: title, hint (a line
     * under the robots, '' for none), first (a seed to show first), button_class (wp-admin's
     * button class on the admin screen), last (LAST_WORDS on the application, which never says to
     * reload; MORE_WORDS under New avatar).
     */
    public static function chooser($args = array()) {
        $args = array_merge(array('title' => 'Your team\'s robot', 'hint' => '', 'first' => '', 'button_class' => '', 'last' => self::LAST_WORDS), is_array($args) ? $args : array());
        $list = array();
        foreach (self::candidates((string) $args['first']) as $seed) {
            $list[] = array($seed, self::api_url($seed, 1, true), self::api_url($seed, self::STAGES, true));
        }
        $img = function ($url, $stage, $alt) {
            return '<img src="' . esc_url($url) . '" data-rsl-robot="' . intval($stage) . '" alt="' . esc_attr($alt) . '" width="96" height="96" loading="lazy" decoding="async" referrerpolicy="no-referrer">';
        };
        $html = '<div class="rsl-robot-box" data-rsl-robots="' . esc_attr(wp_json_encode($list)) . '">'
            . '<p class="rsl-robot-title">' . esc_html($args['title']) . '</p>'
            . '<div class="rsl-robot-pair">'
            . '<figure>' . $img($list[0][1], 1, 'Robot at level 1') . '<figcaption>Level 1</figcaption></figure>'
            . '<figure>' . $img($list[0][2], self::STAGES, 'Robot fully built') . '<figcaption>Fully built</figcaption></figure>'
            . '</div>'
            . ((string) $args['hint'] !== '' ? '<p class="rsl-hint rsl-robot-hint">' . esc_html($args['hint']) . '</p>' : '')
            . '<button type="button" class="rsl-robot-next' . ((string) $args['button_class'] !== '' ? ' ' . esc_attr($args['button_class']) : '') . '" hidden>Try another</button>'
            . '<p class="rsl-robot-note" aria-live="polite" data-rsl-last="' . esc_attr((string) $args['last']) . '"></p>'
            . '<input type="hidden" name="avatar_seed" value="' . esc_attr($list[0][0]) . '" autocomplete="off">'
            . '</div>';
        if (!self::$script_done) {
            self::$script_done = true;
            $html .= self::script();
        }
        return $html;
    }

    /**
     * The chooser's script (printed once a page by chooser()): sets attributes and textContent
     * only. It puts the hidden field back to the first robot as it starts (the one on screen),
     * and each Try another says "Robot N of M." in the live note, with the last words on the last.
     */
    public static function script() {
        return <<<'JS'
<script>
(function () {
    var API = 'https://api.dicebear.com/';
    function start(box) {
        if (box.getAttribute('data-rsl-ready') === '1') { return; }
        var list;
        try { list = JSON.parse(box.getAttribute('data-rsl-robots') || '[]'); } catch (e) { return; }
        var input = box.querySelector('input[name="avatar_seed"]');
        var next = box.querySelector('.rsl-robot-next');
        var note = box.querySelector('.rsl-robot-note');
        var one = box.querySelector('img[data-rsl-robot="1"]');
        var full = box.querySelector('img[data-rsl-robot="8"]');
        if (!list || !list.length || !list[0] || !input || !next || !one || !full) { return; }
        box.setAttribute('data-rsl-ready', '1');
        var last = note ? (note.getAttribute('data-rsl-last') || '') : '';
        var i = 0;
        input.value = String(list[0][0]);
        next.hidden = false;
        next.addEventListener('click', function () {
            i = (i + 1) % list.length;
            var c = list[i];
            if (!c || String(c[1]).indexOf(API) !== 0 || String(c[2]).indexOf(API) !== 0) { return; }
            input.value = c[0];
            one.setAttribute('src', c[1]);
            full.setAttribute('src', c[2]);
            if (note) { note.textContent = 'Robot ' + (i + 1) + ' of ' + list.length + '.' + (i === list.length - 1 && last ? ' ' + last : ''); }
        });
    }
    function all() {
        var boxes = document.querySelectorAll('.rsl-robot-box');
        for (var k = 0; k < boxes.length; k++) { start(boxes[k]); }
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', all); } else { all(); }
})();
</script>
JS;
    }

    // ------------------------------------------------------------------
    // The queue and the job
    // ------------------------------------------------------------------

    /**
     * A list option (the queue, the deletion list) as stored now, never this request's copy of it,
     * so the job (a long run) or a write path never saves back a list another request changed
     * meanwhile (a team queued, files listed to go). One query.
     */
    private static function stored($option) {
        wp_cache_delete($option, 'options');
        $not = wp_cache_get('notoptions', 'options');
        if (is_array($not) && isset($not[$option])) {
            unset($not[$option]);
            wp_cache_set('notoptions', $not, 'options');
        }
        $v = get_option($option, array());
        return is_array($v) ? $v : array();
    }

    /**
     * The queue: team id => array('tries', 'next'), oldest first. $fresh: as stored now
     * (stored()), for anything that writes it back; a page view's look reads this request's copy.
     */
    public static function queued($fresh = false) {
        if ($fresh) {
            return self::stored(self::QUEUE_OPTION);
        }
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

    /**
     * Several teams at once, in the order given (one write; nothing read again when they are all
     * there already, as on a page view drawing the same missing badge twice). A team added brings
     * the run to now (schedule()); with none added it only makes sure one is scheduled, so badges
     * drawn while queued teams wait out a failed fetch never bring the run back early.
     * $schedule false: the run doing it reschedules itself.
     */
    public static function queue_many($ids, $schedule = true) {
        $seen = self::queued();
        $new = array();
        foreach ((array) $ids as $id) {
            $id = intval($id);
            if ($id > 0 && !isset($seen[$id])) {
                $new[] = $id;
            }
        }
        $q = $seen;
        $added = false;
        if ($new) {
            $q = self::queued(true);
            foreach ($new as $id) {
                if (!isset($q[$id])) {
                    $q[$id] = array('tries' => 0, 'next' => 0);
                    $added = true;
                }
            }
            if ($added) {
                self::save_queue($q);
            }
        }
        if ($q && $schedule) {
            self::schedule(0, $added);
        }
        return $added;
    }

    /** Take a team off the queue. */
    public static function dequeue($team_id) {
        $q = self::queued(true);
        if (isset($q[intval($team_id)])) {
            unset($q[intval($team_id)]);
            self::save_queue($q);
        }
    }

    /**
     * The job runs in $delay seconds. A run already due by then stands (so a burst of changes
     * makes one run); a later one, such as a run put off while a team waits out a failed fetch,
     * is brought forward, so results and new teams never wait behind that team ($sooner false:
     * only when none is scheduled). While a test holds the job (HOLD_OPTION, dev/lib-mail-stop.php)
     * a scheduled run is never moved.
     */
    public static function schedule($delay, $sooner = true) {
        $at = time() + max(0, intval($delay));
        $ts = wp_next_scheduled(self::CRON_HOOK);
        if ($ts !== false) {
            if (!$sooner || intval($ts) <= $at || intval(get_option(self::HOLD_OPTION, 0)) > time()) {
                return;
            }
            wp_unschedule_event($ts, self::CRON_HOOK);
        }
        wp_schedule_single_event($at, self::CRON_HOOK);
    }

    /** A database lock's name for this site (GET_LOCK names are server-wide, and another site on the server has its own job). */
    private static function lock_name($what) {
        global $wpdb;
        return self::LOCK_PREFIX . $what . '_' . substr(md5((defined('DB_NAME') ? DB_NAME : '') . '|' . $wpdb->prefix), 0, 16);
    }

    /** After a run: the job again while teams remain (GAP seconds on, or when the first waiting one may be tried) or a season is flagged. */
    public static function reschedule() {
        $q = self::queued(true);
        $flags = self::stored(self::STAGE_OPTION);
        if (!$q) {
            if (is_array($flags) && $flags) {
                self::schedule(self::GAP);
            }
            return;
        }
        $next = PHP_INT_MAX;
        foreach ($q as $item) {
            $next = min($next, isset($item['next']) ? intval($item['next']) : 0);
        }
        self::schedule(is_array($flags) && $flags ? self::GAP : max(self::GAP, $next - time()));
    }

    /** Teams on the queue that no longer exist come off it (the tests' cleanup). */
    public static function forget_missing() {
        global $wpdb;
        $q = self::queued(true);
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
     * A season's results or published nights changed (RESULTS_HOOK, from RSL_Fixtures), or the
     * hourly job: the season is flagged and the job runs STAGE_DELAY seconds on (or sooner when a
     * run is already due), once for any number of changes. Nothing is worked out here.
     */
    public static function stage_check($season) {
        if (!is_string($season) || !RSL_Seasons::is_valid($season)) {
            return;
        }
        $flags = self::stored(self::STAGE_OPTION);
        if (!isset($flags[$season])) {
            $flags[$season] = 1;
            update_option(self::STAGE_OPTION, $flags, false);
        }
        self::schedule(self::STAGE_DELAY);
    }

    /** The hourly job (rsl_hourly): files due for deletion go, and the current season's stages are checked. */
    public static function hourly() {
        self::purge();
        self::stage_check(RSL_Seasons::current());
    }

    /**
     * The job (CRON_HOOK). First the flagged seasons (sweep()): each active team whose stage is
     * out of date goes on the queue. Then up to BATCH queued teams that may be tried now, oldest
     * first: a team with no seed gets one first; a team whose stage changed gets the new stage's
     * images, then the stage written (move_stage()); else its missing files (ensure()). Done (or
     * a team that's gone, declined or withdrawn, whose files go KEEP_OLD later): off the queue. A
     * failed fetch: the team waits (10 minutes, doubling, at most a day) and the run stops, since
     * the service is likely down or slow. No new team after TIME_BUDGET seconds. One run at a time
     * (a database lock). Files due go (purge()); it reschedules itself while any remain.
     */
    public static function work() {
        global $wpdb;
        if (!RSL_Database::schema_current()) {
            self::schedule(300);
            return;
        }
        $lock = self::lock_name('work');
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== '1') {
            self::schedule(self::GAP); // another run holds it; this one comes back rather than leave the queue to the next page view
            return;
        }
        $started = microtime(true);
        self::$stages = array();
        RSL_Stats::flush(); // this run reads the levels afresh (the copy saved between views is keyed by the data)
        try {
            self::sweep();
            $done = 0;
            foreach (self::queued(true) as $team_id => $item) {
                if ($done >= self::BATCH || microtime(true) - $started > self::TIME_BUDGET) {
                    break;
                }
                if (is_array($item) && isset($item['next']) && intval($item['next']) > time()) {
                    continue; // waiting out a failed fetch (only this run changes a wait)
                }
                $done++;
                $ok = self::work_one(intval($team_id));
                $q = self::queued(true); // as stored now: a page may have queued a team meanwhile
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
            self::$stages = array(); // the levels are this run's only
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
        self::purge();
        self::reschedule();
    }

    /**
     * The flagged seasons (STAGE_OPTION, taken: a change during the run flags its season again):
     * each one's levels worked out once (season_stages()) and every active team whose stored stage
     * isn't its stage now put on the queue. Returns how many.
     */
    private static function sweep() {
        global $wpdb;
        $flags = self::stored(self::STAGE_OPTION);
        if (!$flags) {
            return 0;
        }
        delete_option(self::STAGE_OPTION);
        $ids = array();
        foreach (array_keys($flags) as $season) {
            if (!RSL_Seasons::is_valid((string) $season)) {
                continue;
            }
            $rows = $wpdb->get_results($wpdb->prepare("SELECT id, avatar_stage FROM " . RSL_Database::table('teams') . " WHERE season = %s AND status = 'active' ORDER BY id", $season));
            if (!$rows) {
                continue; // no team to draw: no levels worked out
            }
            $want = self::season_stages((string) $season);
            foreach ($rows as $r) {
                $stage = isset($want[intval($r->id)]) ? $want[intval($r->id)] : 1;
                if ($stage !== intval($r->avatar_stage)) {
                    $ids[] = intval($r->id);
                }
            }
        }
        if ($ids) {
            self::queue_many($ids, false);
        }
        return count($ids);
    }

    /** team id => stage for a season's active teams, from RSL_Stats (once a season a run). */
    private static function season_stages($season) {
        if (!isset(self::$stages[$season])) {
            $out = array();
            $stats = RSL_Stats::season($season);
            foreach (isset($stats['teams']) && is_array($stats['teams']) ? $stats['teams'] : array() as $id => $row) {
                $out[intval($id)] = self::stage_for_points(is_array($row) && isset($row['points']) ? $row['points'] : 0);
            }
            self::$stages[$season] = $out;
        }
        return self::$stages[$season];
    }

    /** The stage a team's robot should be at now: from its Season Points while it's active (RSL_Stats), else 1. Never on a page view. */
    public static function wanted_stage($team) {
        if (!is_object($team) || empty($team->id) || !isset($team->status) || $team->status !== 'active' || empty($team->season)) {
            return 1;
        }
        $map = self::season_stages((string) $team->season);
        return isset($map[intval($team->id)]) ? $map[intval($team->id)] : 1;
    }

    /** One queued team: true when it's done or there's nothing to draw, false when the fetch failed. */
    private static function work_one($team_id) {
        global $wpdb;
        $team = RSL_Teams::get($team_id);
        if (!$team || in_array($team->status, array('declined', 'withdrawn'), true)) {
            self::retire($team_id);
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
        $want = self::wanted_stage($team);
        if (!isset($team->avatar_stage) || intval($team->avatar_stage) !== $want) {
            return self::move_stage($team, $want);
        }
        return self::ensure($team);
    }

    /**
     * A team's robot to another stage: the new stage's two images fetched first, then the stage
     * written (only over the seed and stage it was fetched for, so a New avatar meanwhile wins),
     * then the old stage's files listed to go KEEP_OLD later. False when the fetch failed or the
     * write failed (the job tries again); the stage as it was.
     */
    private static function move_stage($team, $stage) {
        global $wpdb;
        $next = clone $team;
        $next->avatar_stage = self::stage_for_level($stage);
        if (!self::ensure($next)) {
            return false;
        }
        $was = isset($team->avatar_stage) ? intval($team->avatar_stage) : 1;
        $n = $wpdb->query($wpdb->prepare("UPDATE " . RSL_Database::table('teams') . " SET avatar_stage = %d WHERE id = %d AND avatar_seed = %s AND avatar_stage = %d",
            $next->avatar_stage, $team->id, (string) $team->avatar_seed, $was));
        if ($n === false) {
            return false;
        }
        if (!$n) {
            self::purge_later(self::pair(self::paths($next))); // changed meanwhile: kept if they are in use (purge())
            return true;
        }
        self::purge_later(self::pair(self::paths($team)));
        return true;
    }

    /**
     * The schema 13 upgrade's step (safe to repeat): every team with no seed gets one, and those
     * active or waiting for approval go on the queue, newest first (a declined or withdrawn
     * application is never drawn). Returns how many got a seed. (Schema 14's avatar_stage starts at
     * 1; the queue's run and the hourly check put each team at its stage.)
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
     * A team entered again (RSL_Teams::roll_over()) has the old team's seed and starts at stage
     * 1 (Season Points start again): the old team's files are copied to the new team's names when
     * they are for the same stage, so it needs no fetch. False when the seeds or stages differ or
     * the old files aren't there (the queue fetches them then).
     */
    public static function copy_from($from, $to) {
        $a = self::paths($from);
        $b = self::paths($to);
        if (!$a || !$b || $a['seed'] !== $b['seed'] || $a['stage'] !== $b['stage'] || !file_exists($a['animated']) || !file_exists($a['still'])) {
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
    // Files a team no longer uses
    // ------------------------------------------------------------------

    /** The two files of paths(), or none. */
    private static function pair($p) {
        return is_array($p) ? array($p['animated'], $p['still']) : array();
    }

    /** The deletion list: file name => unix time it may go, as stored now (stored(): a run never writes back a list a web request added to meanwhile). */
    public static function purge_list() {
        return self::stored(self::PURGE_OPTION);
    }

    /**
     * Files to delete KEEP_OLD from now (a file already listed keeps the later time). Only the
     * league's own file names in the avatars folder, and only files that are there. True when any
     * were listed.
     */
    public static function purge_later($files) {
        $dir = self::dir();
        if (!$dir) {
            return false;
        }
        $list = self::purge_list();
        $due = time() + self::KEEP_OLD;
        $added = false;
        foreach ((array) $files as $f) {
            $name = basename((string) $f);
            if (!preg_match(self::FILE_PATTERN, $name) || !file_exists($dir['path'] . '/' . $name)) {
                continue;
            }
            $list[$name] = max($due, isset($list[$name]) ? intval($list[$name]) : 0);
            $added = true;
        }
        if ($added) {
            update_option(self::PURGE_OPTION, $list, false);
        }
        return $added;
    }

    /** A team that won't be drawn (declined, withdrawn, gone): off the queue, and all its files go KEEP_OLD later. */
    public static function retire($team_id) {
        $dir = self::dir();
        $files = $dir ? glob($dir['path'] . '/team-' . intval($team_id) . '-*.svg') : array();
        self::dequeue($team_id);
        return self::purge_later(is_array($files) ? $files : array());
    }

    /**
     * Deletes the listed files whose time has come (the hourly job and every run). A file the team
     * uses again by then (its stage went back, or a New avatar chose its old robot) is kept and
     * comes off the list. Returns how many were deleted.
     */
    public static function purge() {
        $list = self::purge_list();
        if (!$list) {
            return 0;
        }
        $dir = self::dir();
        $now = time();
        $in_use = array();
        $gone = 0;
        foreach ($list as $name => $due) {
            if (intval($due) > $now) {
                continue;
            }
            unset($list[$name]);
            if (!$dir || !preg_match(self::FILE_PATTERN, (string) $name, $m)) {
                continue;
            }
            $id = intval($m[1]);
            if (!isset($in_use[$id])) {
                $in_use[$id] = self::in_use($id);
            }
            if (!in_array((string) $name, $in_use[$id], true) && @unlink($dir['path'] . '/' . $name)) {
                $gone++;
            }
        }
        if ($list) {
            update_option(self::PURGE_OPTION, $list, false);
        } else {
            delete_option(self::PURGE_OPTION);
        }
        return $gone;
    }

    /** The file names a team draws now (active or pending), else none. */
    private static function in_use($team_id) {
        $team = RSL_Teams::get($team_id);
        if (!$team || !in_array($team->status, array('active', 'pending'), true)) {
            return array();
        }
        return array_map('basename', self::pair(self::paths($team)));
    }

    // ------------------------------------------------------------------
    // A new avatar
    // ------------------------------------------------------------------

    /** The transient counting a team's tries today (the site's date). */
    public static function tries_key($team_id) {
        return self::TRIES_PREFIX . intval($team_id) . '_' . current_time('Ymd');
    }

    /**
     * Use this one (New avatar), by the team's captain or a league admin (RSL_Access::can()): the
     * robot they chose ($seed, from the chooser). The seed's format is checked, the images for the
     * team's CURRENT stage are fetched and saved, and only then the seed is written; the stage is
     * never changed here. The old files go KEEP_OLD later. Refused, the old robot kept, with a
     * sentence: not the captain, a declined or withdrawn application, the schema upgrade still
     * to finish, a seed that isn't one,
     * DAILY_LIMIT tries today (every try that asks DiceBear counts, a failed one too), a failed
     * fetch, or a failed save. The robot it already has (a form sent twice) is its success, with
     * nothing asked. Returns the team or WP_Error.
     */
    public static function regenerate($team_id, $user_id, $seed = '') {
        global $wpdb;
        $user_id = intval($user_id);
        $team = RSL_Teams::get(intval($team_id));
        if (!$team) {
            return new WP_Error('rsl_team', 'That team no longer exists.');
        }
        if (!RSL_Teams::is_captain($team->id, $user_id) && !RSL_Access::can($user_id)) {
            return new WP_Error('rsl_not_captain', 'Only the captain can change the team\'s robot.');
        }
        if (!in_array($team->status, array('active', 'pending'), true)) {
            // A declined or withdrawn application is never drawn (its files go, retire()).
            return new WP_Error('rsl_team', 'That application was declined or withdrawn, so its robot stays as it is.');
        }
        if (!RSL_Database::schema_current()) {
            return new WP_Error('rsl_busy', 'The league is being updated right now — please try again in a minute.');
        }
        if (!self::valid_seed($seed)) {
            return new WP_Error('rsl_avatar_seed', 'That robot can\'t be used. Please pick another.');
        }
        if ($seed === (string) $team->avatar_seed) {
            return $team;
        }
        $key = self::tries_key($team->id);
        $tries = intval(get_transient($key));
        if ($tries >= self::DAILY_LIMIT) {
            return new WP_Error('rsl_avatar_limit', 'You\'ve tried ' . self::DAILY_LIMIT . ' robots today; try again tomorrow.');
        }
        set_transient($key, $tries + 1, DAY_IN_SECONDS);
        $old = self::paths($team);
        $next = clone $team;
        $next->avatar_seed = $seed; // the same stage: a new robot never changes the level
        if (!self::ensure($next)) {
            return new WP_Error('rsl_avatar_fetch', 'The new robot couldn\'t be fetched just now, so the old one stays. Try again in a few minutes.');
        }
        $made = self::paths($next);
        $n = $wpdb->query($wpdb->prepare("UPDATE " . RSL_Database::table('teams') . " SET avatar_seed = %s WHERE id = %d AND avatar_seed = %s AND avatar_stage = %d",
            $seed, $team->id, (string) $team->avatar_seed, isset($team->avatar_stage) ? intval($team->avatar_stage) : 1));
        if (!$n) {
            // A database error, or the job moved its stage meanwhile: the new files are listed to
            // go (kept if they turn out to be in use).
            self::purge_later(self::pair($made));
            return new WP_Error('rsl_save', 'The new robot couldn\'t be saved, so the old one stays. Please try again.');
        }
        self::dequeue($team->id);
        self::purge_later(self::pair($old));
        return RSL_Teams::get($team->id);
    }
}
