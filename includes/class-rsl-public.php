<?php
/**
 * Public pages. The plugin creates a "League Teams" page holding
 * [rsl_teams]; one team's page lives under it at /league-teams/{slug}/
 * (a rewrite onto the same page — no WordPress page per team).
 *
 * Privacy: public pages show TEAMS, never people — name, division, badge,
 * size. A team's roster is shown only to its own members and admins.
 *
 * The season preview (0.18.0): until a season's first fixtures are published, the League Teams
 * list, League Ladder and Fixtures & Results pages show one card (season_preview()) saying when
 * it starts, how many teams are in, the tiers and the free agent dates. Teams counted, never named.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RSL_Public {

    const PAGE_OPTION = 'rsl_teams_page_id';
    const QUERY_VAR = 'rsl_team';

    // The How the league works page (0.18.0): the season preview's second button. Set on
    // Social League → Teams (League pages). A page id, never an account.
    const ABOUT_OPTION = 'rsl_about_page_id';

    /** The season preview's countdown script is on this page already (one per page, however many cards). */
    private static $preview_script_done = false;

    public function __construct() {
        add_shortcode('rsl_teams', array($this, 'render_teams'));
        add_action('init', array(__CLASS__, 'add_rewrite_rules'));
        add_filter('query_vars', function ($vars) {
            $vars[] = RSL_Public::QUERY_VAR;
            return $vars;
        });
    }

    /** Create the public league pages if they're missing (activation / upgrade). */
    public static function ensure_pages() {
        RSL_Public_Fixtures::ensure_pages();
        RSL_Ref_Page::ensure_page();
        return self::ensure_page(self::PAGE_OPTION, 'League Teams', 'league-teams', '[rsl_teams]');
    }

    /** A published page holding $shortcode, remembered in $option. Returns its id. */
    public static function ensure_page($option, $title, $slug, $shortcode) {
        $page_id = intval(get_option($option));
        // A trashed or deleted page counts as missing (otherwise links point nowhere).
        if ($page_id && in_array(get_post_status($page_id), array('publish', 'private', 'draft', 'pending', 'future'), true)) {
            return $page_id;
        }
        $page_id = wp_insert_post(array(
            'post_title' => $title,
            'post_name' => $slug,
            'post_content' => $shortcode,
            'post_status' => 'publish',
            'post_type' => 'page',
        ));
        if ($page_id && !is_wp_error($page_id)) {
            update_option($option, $page_id, false);
        }
        return $page_id;
    }

    public static function add_rewrite_rules() {
        $page_id = intval(get_option(self::PAGE_OPTION));
        $page = $page_id ? get_post($page_id) : null;
        if (!$page) {
            return;
        }
        $path = get_page_uri($page);
        add_rewrite_rule('^' . preg_quote($path, '#') . '/([^/]+)/?$', 'index.php?page_id=' . $page_id . '&' . self::QUERY_VAR . '=$matches[1]', 'top');
        // The page was moved or renamed since the rules were saved: refresh them once.
        if (get_option('rsl_teams_rule_path') !== $path) {
            update_option('rsl_teams_rule_path', $path, true);
            flush_rewrite_rules(false);
        }
    }

    /** Public link to a team's page (with its season when it isn't the current one). */
    public static function team_url($team) {
        $page_id = intval(get_option(self::PAGE_OPTION));
        if (!$page_id) {
            return '';
        }
        $base = get_permalink($page_id);
        $url = get_option('permalink_structure')
            ? trailingslashit($base) . rawurlencode($team->slug) . '/'
            : add_query_arg(self::QUERY_VAR, $team->slug, $base);
        return !empty($team->season) && $team->season !== RSL_Seasons::current() ? add_query_arg('season', $team->season, $url) : $url;
    }

    // ------------------------------------------------------------------
    // [rsl_teams]
    // ------------------------------------------------------------------

    public function render_teams($atts = array()) {
        $asked = isset($_GET['season']) ? sanitize_key(wp_unslash($_GET['season'])) : '';
        $season = RSL_Seasons::is_valid($asked) ? $asked : RSL_Seasons::current();
        $slug = get_query_var(self::QUERY_VAR);
        if ($slug === '' && isset($_GET[self::QUERY_VAR])) {
            $slug = sanitize_title(wp_unslash($_GET[self::QUERY_VAR]));
        }
        $html = $slug !== '' ? $this->render_team($season, sanitize_title($slug)) : $this->render_team_list($season);
        return '<div class="rsl">' . $html . '</div>' . self::styles();
    }

    private function render_team_list($season) {
        $teams = RSL_Teams::list_for_season($season);
        // The season preview (0.18.0): the current season only, before its first published night.
        $preview = $season === RSL_Seasons::current() ? self::season_preview($season, $teams) : '';
        ob_start();
        ?>
        <h2 class="rsl-title"><?php echo esc_html(RSL_Seasons::label($season)); ?> teams</h2>
        <p class="rsl-links"><a href="<?php echo esc_url(RSL_Public_Fixtures::fixtures_url()); ?>">Fixtures &amp; results &rarr;</a> &nbsp; <a href="<?php echo esc_url(RSL_Public_Fixtures::ladder_url()); ?>">Ladder &rarr;</a></p>
        <?php echo $preview; ?>
        <?php if (empty($teams) && $preview === ''): ?>
            <p class="rsl-empty">No teams yet this season.</p>
        <?php endif; ?>
        <?php foreach (RSL_Teams::DIVISIONS as $division => $label): ?>
            <?php $in = array_filter($teams, function ($t) use ($division) { return $t->division === $division; }); ?>
            <?php if (empty($in)) { continue; } ?>
            <h3 class="rsl-division"><?php echo esc_html($label); ?> <span>(<?php echo count($in); ?>)</span></h3>
            <ul class="rsl-team-list">
                <?php foreach ($in as $team): ?>
                    <li>
                        <a href="<?php echo esc_url(self::team_url($team)); ?>">
                            <?php echo self::badge($team); ?>
                            <span class="rsl-team-name"><?php echo esc_html($team->name); ?></span>
                            <span class="rsl-team-size"><?php echo intval($team->member_count); ?> player<?php echo intval($team->member_count) === 1 ? '' : 's'; ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>
        <?php
        return ob_get_clean();
    }

    private function render_team($season, $slug) {
        $team = RSL_Teams::get_by_slug($season, $slug);
        $back = get_permalink(intval(get_option(self::PAGE_OPTION)));
        if (!$team || $team->status !== 'active') {
            return '<p class="rsl-empty">That team isn\'t playing this season.</p><p><a href="' . esc_url($back) . '">&larr; All teams</a></p>';
        }
        $members = RSL_Teams::members($team->id);
        $user_id = get_current_user_id();
        ob_start();
        ?>
        <?php echo RSL_Portal::flash_html(); ?>
        <p class="rsl-back"><a href="<?php echo esc_url($back); ?>">&larr; All teams</a></p>
        <div class="rsl-team-head">
            <?php echo self::badge($team, 'large'); ?>
            <div>
                <h2 class="rsl-title"><?php echo esc_html($team->name); ?></h2>
                <p class="rsl-meta"><?php echo esc_html(RSL_Teams::DIVISIONS[$team->division]); ?> &middot; <?php echo esc_html(RSL_Seasons::label($season)); ?> &middot; <?php echo count($members); ?> player<?php echo count($members) === 1 ? '' : 's'; ?></p>
                <?php // 0.23.0: New avatar (the robot chooser and Use this one), for the captain and league admins only. ?>
                <?php echo RSL_Portal::avatar_button($team, $user_id); ?>
            </div>
        </div>
        <?php echo RSL_Public_Fixtures::team_profile($team); ?>
        <?php echo RSL_Payments::team_card($team); ?>
        <?php // 0.20.0: the team's home for the people on it: the roster, and the captain's tools around the schedule. ?>
        <?php echo RSL_Portal::team_tools($team, $user_id); ?>
        <?php echo RSL_Public_Fixtures::team_section($team); ?>
        <?php echo RSL_Portal::team_free_agents($team, $user_id); ?>
        <?php echo RSL_Portal::team_prefs($team, $user_id); ?>
        <?php
        return ob_get_clean();
    }

    /**
     * The team's badge (0.23.0): its avatar (RSL_Avatars::picture()) once the team's two image
     * files are on this site; until then, and for the partial objects some callers build (a name
     * and a colour), its initials on its colour, as before. Never fetches: a team (an object with
     * an id) whose files are missing goes on the avatar queue (RSL_Avatars::missing()).
     */
    public static function badge($team, $size = '') {
        $urls = RSL_Avatars::urls($team);
        if ($urls) {
            return RSL_Avatars::picture($urls, $size);
        }
        RSL_Avatars::missing($team);
        return '<span class="rsl-badge' . ($size ? ' rsl-badge-' . esc_attr($size) : '') . '" style="background:' . esc_attr($team->colour) . '" aria-hidden="true">'
            . esc_html(RSL_Teams::initials($team->name)) . '</span>';
    }

    // ------------------------------------------------------------------
    // The season preview (0.18.0)
    // ------------------------------------------------------------------

    /**
     * Whether the season preview shows for $season, and its first night: array('show' => bool,
     * 'first' => Y-m-d, or '' with no dates). $nights: the season's nights (RSL_Courts::nights(),
     * date order) when the caller already has them (Fixtures & Results), else read here (one
     * query). The first night is the season's first night that isn't a bye; with no nights yet
     * (or only byes), the first night in its settings (RSL_Seasons::span(), when dated); else
     * none. Shown while no night of the season is published and the first night is today or
     * later (the site's date, current_time()); with no dates, while the season hasn't finished
     * (its quarter's last day is today or later). A bad key never shows.
     */
    public static function preview_state($season, $nights = null) {
        $hidden = array('show' => false, 'first' => '');
        if (!RSL_Seasons::is_valid($season)) {
            return $hidden;
        }
        $first = '';
        foreach (is_array($nights) ? $nights : RSL_Courts::nights($season) as $n) {
            if (RSL_Fixtures::is_published($n)) {
                return $hidden;
            }
            if ($first === '' && $n->type !== 'bye') {
                $first = (string) $n->night_date;
            }
        }
        $span = RSL_Seasons::span($season);
        if ($first === '' && $span && !empty($span['dated'])) {
            $first = $span['start'];
        }
        $today = current_time('Y-m-d');
        if ($first !== '') {
            return array('show' => $first >= $today, 'first' => $first);
        }
        return array('show' => $span && $span['end'] >= $today, 'first' => '');
    }

    /**
     * The season preview card (0.18.0) for the League Teams list, the League Ladder and Fixtures &
     * Results: '' unless preview_state() shows it. The same HTML for every viewer (these pages may
     * be served from a cache): active teams counted by division, never a team name, a person or a
     * pending application. $teams: the season's active teams when the caller has them (the Teams
     * page), else read here. $nights: passed on to preview_state() (Fixtures & Results has them).
     * A dated card carries its first night (data-rsl-first-night) and the site's time zone
     * (data-rsl-tz: wp_timezone_string(), a zone name or an offset like +10:00, the same for
     * every viewer) and, the first time on a page, the countdown script.
     */
    public static function season_preview($season, $teams = null, $nights = null) {
        $state = self::preview_state($season, $nights);
        if (!$state['show']) {
            return '';
        }
        $first = $state['first'];
        $days = $first !== '' ? self::days_until($first, current_time('Y-m-d')) : null;
        $label = RSL_Seasons::label($season);
        $counts = array();
        foreach (is_array($teams) ? $teams : RSL_Teams::list_for_season($season) as $t) {
            if (isset($t->status, $t->division) && $t->status === 'active') {
                $counts[$t->division] = (isset($counts[$t->division]) ? $counts[$t->division] : 0) + 1;
            }
        }
        $games = intval(RSL_Ratings::PLACEMENT_GAMES);
        $fa = self::preview_fa_words($season);
        $portal_id = intval(get_option(RSL_Portal::PAGE_OPTION));
        $register = $portal_id && get_post_status($portal_id) === 'publish' ? (string) get_permalink($portal_id) : '';
        $about = self::about_url();
        ob_start();
        ?>
        <section class="rsl-preview"<?php echo $first !== '' ? ' data-rsl-first-night="' . esc_attr($first) . '" data-rsl-tz="' . esc_attr(wp_timezone_string()) . '"' : ''; ?>>
            <h3 class="rsl-preview-title"><?php echo esc_html($first !== '' ? $label . ' starts ' . date_i18n('l j F', strtotime($first)) : $label . ' dates coming soon'); ?></h3>
            <?php if ($days !== null && $days >= 0): ?>
                <p class="rsl-preview-countdown"><?php echo esc_html(self::countdown_words($days)); ?></p>
            <?php endif; ?>
            <p class="rsl-preview-teams"><?php echo esc_html(self::teams_in_words($counts)); ?></p>
            <div class="rsl-preview-divs">
                <div class="rsl-preview-div">
                    <p class="rsl-preview-div-name"><?php echo esc_html(RSL_Teams::DIVISIONS['ranked']); ?></p>
                    <ol class="rsl-preview-tiers" aria-label="Ranked tiers, lowest first">
                        <?php foreach (array_reverse(RSL_Stats::TIERS) as $t): ?>
                            <li><span class="rsl-preview-swatch" style="background:<?php echo esc_attr(sanitize_hex_color($t[3]) ?: '#6b7280'); ?>" aria-hidden="true"></span><?php echo esc_html($t[1]); ?></li>
                        <?php endforeach; ?>
                    </ol>
                    <p class="rsl-preview-note"><?php echo esc_html(self::count_word($games) . ' placement game' . ($games === 1 ? '' : 's') . ', then climb.'); ?></p>
                </div>
                <div class="rsl-preview-div">
                    <p class="rsl-preview-div-name"><?php echo esc_html(RSL_Teams::DIVISIONS['unranked']); ?></p>
                    <p class="rsl-preview-note">Just here for a hit? Unranked is a simple ladder of wins and sets.</p>
                </div>
            </div>
            <?php if ($fa !== ''): ?>
                <p class="rsl-preview-fa"><?php echo esc_html($fa); ?></p>
            <?php endif; ?>
            <?php if ($register !== '' || $about !== ''): ?>
                <p class="rsl-preview-buttons">
                    <?php if ($register !== ''): ?><a class="rsl-preview-btn" href="<?php echo esc_url($register); ?>"><?php echo esc_html('Register for ' . substr($season, 0, 4)); ?></a><?php endif; ?>
                    <?php if ($about !== ''): ?><a class="rsl-preview-btn is-secondary" href="<?php echo esc_url($about); ?>">How the league works</a><?php endif; ?>
                </p>
            <?php endif; ?>
        </section>
        <?php
        return ob_get_clean() . ($first !== '' ? self::preview_script() : '');
    }

    /**
     * The free agent line (0.18.0), or '': only for a season open for sign-up (players can join
     * its list), while its window hasn't closed (RSL_Free_Agents::phase() before or open). Dates
     * from RSL_Free_Agents::dates(); with none the list is open with no deadline. Plain text.
     */
    public static function preview_fa_words($season) {
        if (!class_exists('RSL_Free_Agents') || !isset(RSL_Seasons::open_for_signup()[$season])) {
            return '';
        }
        $phase = RSL_Free_Agents::phase($season);
        $d = RSL_Free_Agents::dates($season);
        $day = function ($ymd) {
            return date_i18n('j F', strtotime($ymd));
        };
        if (!$d) {
            return $phase === 'open' ? 'No team? Join the free agent list and we\'ll find you a team.' : '';
        }
        if ($phase === 'before') {
            return 'No team? The free agent list opens ' . $day($d['opens']) . ' and everyone hears their team by ' . $day($d['confirm_by']) . '.';
        }
        if ($phase === 'open') {
            return 'No team? The free agent list is open until ' . $day($d['deadline']) . ' and everyone hears their team by ' . $day($d['confirm_by']) . '.';
        }
        return '';
    }

    /**
     * "4 Ranked teams and 2 Unranked teams are in so far." from division => count (divisions in
     * RSL_Teams::DIVISIONS order; anything else ignored); "Be the first team in." with none. Pure.
     */
    public static function teams_in_words($counts) {
        $counts = is_array($counts) ? $counts : array();
        $parts = array();
        $total = 0;
        foreach (RSL_Teams::DIVISIONS as $division => $label) {
            $n = isset($counts[$division]) ? max(0, intval($counts[$division])) : 0;
            if ($n > 0) {
                $parts[] = $n . ' ' . $label . ' team' . ($n === 1 ? '' : 's');
                $total += $n;
            }
        }
        if (!$parts) {
            return 'Be the first team in.';
        }
        return implode(' and ', $parts) . ($total === 1 ? ' is' : ' are') . ' in so far.';
    }

    /** "53 days to go", "1 day to go", "The first night is tonight"; '' for a day gone. Pure. */
    public static function countdown_words($days) {
        $days = intval($days);
        if ($days < 0) {
            return '';
        }
        if ($days === 0) {
            return 'The first night is tonight';
        }
        return $days . ($days === 1 ? ' day' : ' days') . ' to go';
    }

    /** Whole days from $today to $ymd (both Y-m-d; negative when gone), or null for a bad date. Pure. */
    public static function days_until($ymd, $today) {
        $utc = new DateTimeZone('UTC');
        $to = is_string($ymd) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd) ? DateTime::createFromFormat('!Y-m-d', $ymd, $utc) : false;
        $from = is_string($today) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) ? DateTime::createFromFormat('!Y-m-d', $today, $utc) : false;
        if (!$to || !$from) {
            return null;
        }
        return (int) $from->diff($to)->format('%r%a');
    }

    /** 1 to 10 as a capitalised word ("Three"); anything else as digits. Pure. */
    public static function count_word($n) {
        $words = array(1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine', 10 => 'Ten');
        $n = intval($n);
        return isset($words[$n]) ? $words[$n] : (string) $n;
    }

    /** The How the league works page's link (ABOUT_OPTION), or '' while none is set or it isn't a published page. */
    public static function about_url() {
        $id = intval(get_option(self::ABOUT_OPTION));
        if (!$id || get_post_type($id) !== 'page' || get_post_status($id) !== 'publish') {
            return '';
        }
        return (string) get_permalink($id);
    }

    /**
     * The countdown's script, the first time a dated card is printed on a page; '' after. It
     * recounts every dated card's countdown from data-rsl-first-night against today's date in
     * the site's time zone (data-rsl-tz), the day the server counts from, so a page served from
     * a cache shows today's count and a visitor in another zone sees the same count; and it hides
     * a card whose first night has passed. An offset zone (+10:00) is worked out directly, a
     * named one with Intl.DateTimeFormat; the browser's own date is the fallback when Intl is
     * missing or refuses the zone. Text only (textContent); runs once the page has loaded, so a
     * card printed after the script (two shortcodes on one page) is counted too.
     */
    private static function preview_script() {
        if (self::$preview_script_done) {
            return '';
        }
        self::$preview_script_done = true;
        return <<<'JS'
<script>
(function () {
    // Today in the site's time zone (a zone name, or an offset like +10:00), as UTC midnight.
    function today(tz) {
        var now = new Date();
        var m = /^([+-])(\d{2}):(\d{2})$/.exec(tz);
        if (m) {
            var at = new Date(now.getTime() + (m[1] === '-' ? -1 : 1) * (+m[2] * 60 + +m[3]) * 60000);
            return Date.UTC(at.getUTCFullYear(), at.getUTCMonth(), at.getUTCDate());
        }
        try {
            var parts = new Intl.DateTimeFormat('en-US', { timeZone: tz, year: 'numeric', month: 'numeric', day: 'numeric' }).formatToParts(now);
            var v = {};
            for (var j = 0; j < parts.length; j++) { v[parts[j].type] = +parts[j].value; }
            if (v.year && v.month && v.day) { return Date.UTC(v.year, v.month - 1, v.day); }
        } catch (e) {}
        return Date.UTC(now.getFullYear(), now.getMonth(), now.getDate());
    }
    function run() {
        var cards = document.querySelectorAll('.rsl-preview[data-rsl-first-night]');
        for (var i = 0; i < cards.length; i++) {
            var p = (cards[i].getAttribute('data-rsl-first-night') || '').split('-');
            var t = today(cards[i].getAttribute('data-rsl-tz') || '');
            var days = p.length === 3 ? Math.round((Date.UTC(+p[0], +p[1] - 1, +p[2]) - t) / 86400000) : NaN;
            if (isNaN(days)) { continue; }
            if (days < 0) { cards[i].hidden = true; continue; }
            var out = cards[i].querySelector('.rsl-preview-countdown');
            if (out) { out.textContent = days === 0 ? 'The first night is tonight' : days + (days === 1 ? ' day' : ' days') + ' to go'; }
        }
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', run); } else { run(); }
})();
</script>
JS;
    }

    public static function styles() {
        static $done = false;
        if ($done) {
            return '';
        }
        $done = true;
        return '<style>
            .rsl { --rsl-navy: #1d3d6e; --rsl-muted: #6b7280; --rsl-border: #dcdcde; }
            .rsl-division { margin: 22px 0 8px; color: var(--rsl-navy); }
            .rsl-division span, .rsl-team-size, .rsl-meta, .rsl-empty { color: var(--rsl-muted); font-weight: 400; }
            .rsl-team-list, .rsl-roster { list-style: none; margin: 0; padding: 0; }
            .rsl-team-list li a { display: flex; align-items: center; gap: 12px; padding: 10px 12px; margin-bottom: 8px; border: 1px solid var(--rsl-border); border-radius: 8px; text-decoration: none; color: inherit; }
            .rsl-team-list li a:hover { border-color: var(--rsl-navy); }
            .rsl-team-name { font-weight: 600; flex: 1 1 auto; }
            .rsl-team-size { font-size: 13px; }
            .rsl-badge { display: inline-flex; align-items: center; justify-content: center; flex: 0 0 auto; width: 40px; height: 40px; border-radius: 50%; color: #fff; font-weight: 700; font-size: 15px; letter-spacing: 0.5px; }
            .rsl-badge-large { width: 72px; height: 72px; font-size: 26px; }
            ' . RSL_Avatars::css() . '
            .rsl-avatar-new { margin: 6px 0 0; }
            .rsl .rsl-avatar-new > summary { display: inline-block; padding: 3px 10px; border: 1px solid var(--rsl-navy); border-radius: 4px; background: #fff; color: var(--rsl-navy); font-size: 13px; font-weight: 600; line-height: 1.5; list-style: none; cursor: pointer; }
            .rsl .rsl-avatar-new > summary::-webkit-details-marker { display: none; }
            .rsl .rsl-avatar-new > summary:hover { background: #eef2f8; }
            .rsl .rsl-avatar-new button.rsl-avatar-use { padding: 6px 14px; border: 1px solid var(--rsl-navy); border-radius: 4px; background: var(--rsl-navy); color: #fff; font-size: 14px; font-weight: 600; line-height: 1.5; text-transform: none; cursor: pointer; }
            .rsl-team-head { display: flex; align-items: center; gap: 16px; }
            .rsl-team-head .rsl-title { margin: 0; }
            .rsl-meta { margin: 4px 0 0; }
            .rsl-roster li { padding: 8px 0; border-bottom: 1px solid var(--rsl-border); }
            .rsl-chip { display: inline-block; margin-left: 6px; padding: 1px 8px; border-radius: 10px; font-size: 12px; background: #eef2f8; color: var(--rsl-navy); font-weight: 600; }
            .rsl-preview { display: block; margin: 16px 0 20px; padding: 18px 20px; border: 1px solid var(--rsl-border); border-radius: 12px; background: linear-gradient(180deg, #f3f6fb, #fff); }
            .rsl-preview[hidden] { display: none; }
            .rsl-preview-title { margin: 0 0 4px; color: var(--rsl-navy); font-size: 1.25em; line-height: 1.3; }
            .rsl-preview-countdown { margin: 0 0 10px; font-size: 1.6em; font-weight: 800; color: var(--rsl-navy); font-variant-numeric: tabular-nums; line-height: 1.2; }
            .rsl-preview-teams { margin: 0 0 12px; font-weight: 600; }
            .rsl-preview-divs { display: flex; flex-wrap: wrap; gap: 12px; margin: 0 0 12px; }
            .rsl-preview-div { flex: 1 1 240px; min-width: 0; padding: 10px 12px; border: 1px solid var(--rsl-border); border-radius: 8px; background: #fff; }
            .rsl-preview-div-name { margin: 0 0 6px; font-size: 12px; font-weight: 800; letter-spacing: .5px; text-transform: uppercase; color: var(--rsl-muted); }
            .rsl-preview-tiers { display: flex; flex-wrap: wrap; gap: 6px 12px; list-style: none; margin: 0 0 6px; padding: 0; }
            .rsl-preview-tiers li { display: inline-flex; align-items: center; gap: 5px; margin: 0; font-size: 13px; font-weight: 700; }
            .rsl-preview-swatch { display: inline-block; width: 12px; height: 12px; border-radius: 3px; flex: 0 0 auto; }
            .rsl-preview-note { margin: 0; font-size: 14px; }
            .rsl-preview-fa { margin: 0 0 12px; font-size: 14px; }
            .rsl-preview-buttons { display: flex; flex-wrap: wrap; gap: 8px; margin: 0; }
            .rsl a.rsl-preview-btn { display: inline-block; padding: 8px 14px; border-radius: 4px; border: 1px solid var(--rsl-navy); background: var(--rsl-navy); color: #fff !important; font-weight: 600; font-size: 14px; text-decoration: none !important; box-shadow: none; }
            .rsl a.rsl-preview-btn.is-secondary { background: #fff; color: var(--rsl-navy) !important; }
            @media (max-width: 600px) {
                .rsl-preview { padding: 14px; }
                .rsl-preview-countdown { font-size: 1.35em; }
            }
        </style>';
    }
}
