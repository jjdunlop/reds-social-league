<?php
/**
 * Public fixtures, results and ladder pages, and the fixtures section of a
 * team's page. The plugin creates "Fixtures & Results" ([rsl_fixtures]) and
 * "League Ladder" ([rsl_ladder]).
 *
 * Privacy: teams only — never people. Only published nights are shown.
 * Until the first night's fixtures are published each shows the season preview
 * (RSL_Public::season_preview(), 0.18.0).
 */

if (!defined('ABSPATH')) {
    exit;
}

class RSL_Public_Fixtures {

    const FIXTURES_PAGE_OPTION = 'rsl_fixtures_page_id';
    const LADDER_PAGE_OPTION = 'rsl_ladder_page_id';
    // Coming games show hype (Rematch, Even match, Top-of-table clash…), not
    // odds: in a social league nobody should be publicly labelled the likely
    // loser before a game. True shows each team's set-win chance instead.
    const SHOW_SET_CHANCES = false;

    public function __construct() {
        add_shortcode('rsl_fixtures', array($this, 'render_fixtures'));
        add_shortcode('rsl_ladder', array($this, 'render_ladder'));
        add_action('wp_ajax_rsl_live_night', array(__CLASS__, 'ajax_live_night'));
        add_action('wp_ajax_nopriv_rsl_live_night', array(__CLASS__, 'ajax_live_night'));
    }

    public static function ensure_pages() {
        RSL_Public::ensure_page(self::FIXTURES_PAGE_OPTION, 'Fixtures & Results', 'league-fixtures', '[rsl_fixtures]');
        RSL_Public::ensure_page(self::LADDER_PAGE_OPTION, 'League Ladder', 'league-ladder', '[rsl_ladder]');
    }

    public static function fixtures_url($night = null) {
        $page_id = intval(get_option(self::FIXTURES_PAGE_OPTION));
        if (!$page_id) {
            return '';
        }
        $url = get_permalink($page_id);
        return $night ? add_query_arg('week', $night->night_date, $url) : $url;
    }

    public static function ladder_url() {
        $page_id = intval(get_option(self::LADDER_PAGE_OPTION));
        return $page_id ? get_permalink($page_id) : '';
    }

    // ------------------------------------------------------------------
    // [rsl_fixtures]
    // ------------------------------------------------------------------

    public function render_fixtures($atts = array()) {
        $season = RSL_Seasons::current();
        $nights = RSL_Courts::nights($season);
        if (empty($nights)) {
            return '<div class="rsl">' . RSL_Public::season_preview($season, null, $nights) . '<p class="rsl-empty">The ' . esc_html(RSL_Seasons::label($season)) . ' fixture isn\'t out yet.</p></div>' . RSL_Public::styles() . self::styles();
        }
        $labels = RSL_Fixtures::round_labels($season);
        $night = self::chosen_night($nights, isset($_GET['week']) ? sanitize_text_field(wp_unslash($_GET['week'])) : '');
        $mine = self::viewer_team_id($season);
        $today = current_time('Y-m-d');

        ob_start();
        ?>
        <div class="rsl">
            <h2 class="rsl-title"><?php echo esc_html(RSL_Seasons::label($season)); ?></h2>
            <?php echo RSL_Public::season_preview($season, null, $nights); ?>
            <nav class="rsl-weeks" aria-label="Weeks">
                <?php foreach ($nights as $n): ?>
                    <?php
                    $classes = array('rsl-week');
                    if (intval($n->id) === intval($night->id)) { $classes[] = 'is-current'; }
                    if ($n->type === 'bye') { $classes[] = 'is-bye'; }
                    if ($n->type !== 'bye' && !RSL_Fixtures::is_published($n)) { $classes[] = 'is-later'; }
                    ?>
                    <a class="<?php echo esc_attr(implode(' ', $classes)); ?>" href="<?php echo esc_url(self::fixtures_url($n)); ?>"<?php echo intval($n->id) === intval($night->id) ? ' aria-current="page"' : ''; ?>>
                        <strong><?php echo esc_html(self::short_label($labels[$n->id])); ?></strong>
                        <span><?php echo esc_html(date_i18n('j M', strtotime($n->night_date))); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
            <script>(function () { var w = document.querySelector('.rsl-weeks'), c = w && w.querySelector('.is-current'); if (c) { w.scrollLeft = c.offsetLeft - w.offsetLeft - (w.clientWidth - c.offsetWidth) / 2; } })();</script>

            <h3 class="rsl-night-title"><?php echo esc_html($labels[$night->id]); ?> <span>&middot; <?php echo esc_html(date_i18n('l j F', strtotime($night->night_date))); ?></span></h3>
            <?php if ($night->type === 'bye'): ?>
                <p class="rsl-empty">No games this week<?php echo $night->note !== '' ? ' — ' . esc_html($night->note) : ''; ?>.</p>
            <?php elseif (!RSL_Fixtures::is_published($night)): ?>
                <p class="rsl-empty"><?php echo $night->night_date >= $today
                    ? 'Fixtures for this week come out on ' . esc_html(date_i18n('l j F', strtotime($night->night_date . ' -6 days'))) . '.'
                    : 'No fixtures were published for this night.'; ?></p>
            <?php else: ?>
                <?php $games = RSL_Fixtures::games_for_night($night->id); ?>
                <?php echo self::night_games($games, $mine, $today, RSL_Stats::season($season)); ?>
                <?php $window = $night->night_date === $today ? self::live_window($night, $games) : null; ?>
                <?php if ($window): ?>
                    <script>
                    (function () {
                        // Game night: keep scores up to date without reloading — only while
                        // games are on, never once every game has a result, and less often
                        // while nothing changes. Random delays spread everyone's requests out.
                        // The page itself is the first look, so the first poll waits the usual
                        // interval (most visits are a quick check of the score).
                        var url = <?php echo wp_json_encode(add_query_arg(array('action' => 'rsl_live_night', 'night' => intval($night->id)), admin_url('admin-ajax.php'))); ?>;
                        var from = <?php echo intval($window[0]); ?> * 1000, to = <?php echo intval($window[1]); ?> * 1000;
                        var last = {}, quiet = 0, timer = null, stopped = false, lastPoll = Date.now();
                        function later(ms) {
                            clearTimeout(timer);
                            if (!stopped) { timer = setTimeout(poll, ms + Math.random() * 3000); }
                        }
                        function poll() {
                            var now = Date.now();
                            if (now > to) { stopped = true; return; }
                            if (now < from) { later(from - now); return; }
                            if (document.hidden) { return; } // picked up again when the page is shown
                            lastPoll = now;
                            fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
                                if (!r.success) { stopped = true; return; }
                                var changed = false;
                                Object.keys(r.data.games).forEach(function (id) {
                                    if (last[id] === r.data.games[id]) { return; }
                                    last[id] = r.data.games[id];
                                    changed = true;
                                    // Results already in when the page loaded keep their Upset!/Close one labels.
                                    var el = document.querySelector('.rsl-score[data-game="' + id + '"]:not([data-final])');
                                    if (el) { el.innerHTML = r.data.games[id]; }
                                });
                                if (r.data.all_done) { stopped = true; return; }
                                quiet = changed ? 0 : quiet + 1;
                                later(quiet >= 3 ? 60000 : 20000);
                            }).catch(function () { later(60000); });
                        }
                        // Back on the page: catch up now only if the last look is over 20s old
                        // (otherwise the poll already waiting is soon enough).
                        document.addEventListener('visibilitychange', function () { if (!document.hidden && Date.now() - lastPoll > 20000) { later(0); } });
                        later(20000);
                    })();
                    </script>
                <?php endif; ?>
            <?php endif; ?>

            <p class="rsl-links"><a href="<?php echo esc_url(self::ladder_url()); ?>">League ladder &rarr;</a> &nbsp; <a href="<?php echo esc_url(get_permalink(intval(get_option(RSL_Public::PAGE_OPTION)))); ?>">All teams &rarr;</a></p>
        </div>
        <?php
        return ob_get_clean() . RSL_Public::styles() . self::styles();
    }

    /**
     * The week to show: the one asked for; else the next week if its
     * fixtures are out (or it's a bye); else the latest week gone; else the first.
     */
    private static function chosen_night($nights, $week) {
        foreach ($nights as $n) {
            if ($n->night_date === $week) {
                return $n;
            }
        }
        $today = current_time('Y-m-d');
        $past = null;
        foreach ($nights as $n) {
            if ($n->night_date >= $today) {
                if ($n->type === 'bye' || RSL_Fixtures::is_published($n) || !$past) {
                    return $n;
                }
                return $past;
            }
            $past = $n;
        }
        return $past ?: $nights[0];
    }

    /**
     * When the Fixtures page polls for live scores on game night, as real
     * Unix times: array(from, to) — 15 minutes before the first game until
     * 30 minutes after the last ends. Null when there's nothing to wait for
     * (no games, or every game has a result).
     */
    public static function live_window($night, $games) {
        $first = $last = null;
        $open = false;
        foreach ($games as $g) {
            $open = $open || $g->status === 'scheduled';
            $first = $first === null || RSL_Courts::hm($g->start_time) < $first ? RSL_Courts::hm($g->start_time) : $first;
            $last = $last === null || RSL_Courts::hm($g->end_time) > $last ? RSL_Courts::hm($g->end_time) : $last;
        }
        if (!$open) {
            return null;
        }
        $at = function ($hm) use ($night) {
            $time = new DateTime($night->night_date . ' ' . $hm, wp_timezone());
            return $time->getTimestamp();
        };
        return array($at($first) - 15 * MINUTE_IN_SECONDS, $at($last) + 30 * MINUTE_IN_SECONDS);
    }

    /** One night's games, venue by venue, grouped by start time. */
    private static function night_games($games, $mine, $today, $stats) {
        if (empty($games)) {
            return '<p class="rsl-empty">No games this week.</p>';
        }
        $by_venue = array();
        foreach ($games as $g) {
            $by_venue[$g->venue_id]['name'] = $g->venue_name;
            $by_venue[$g->venue_id]['division'] = $g->division;
            $by_venue[$g->venue_id]['times'][RSL_Courts::hm($g->start_time)][] = $g;
        }
        ob_start();
        foreach ($by_venue as $v) {
            ?>
            <section class="rsl-venue">
                <h4 class="rsl-division"><?php echo esc_html(RSL_Teams::DIVISIONS[$v['division']]); ?> <span>&middot; <?php echo esc_html($v['name']); ?></span></h4>
                <?php foreach ($v['times'] as $time => $at): ?>
                    <div class="rsl-time"><?php echo esc_html(RSL_Courts::pretty_time($time)); ?></div>
                    <?php foreach ($at as $g): ?>
                        <?php echo self::game_row($g, $mine, $today, $stats); ?>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </section>
            <?php
        }
        return ob_get_clean();
    }

    private static function game_row($g, $mine, $today, $stats) {
        $h = intval($g->home_team_id);
        $a = intval($g->away_team_id);
        $is_mine = $mine && in_array($mine, array($h, $a), true);
        $home = (object) array('name' => $g->home_name, 'slug' => $g->home_slug, 'colour' => $g->home_colour);
        $away = (object) array('name' => $g->away_name, 'slug' => $g->away_slug, 'colour' => $g->away_colour);
        $home_won = $away_won = false;
        if ($g->status === 'played') {
            $home_won = intval($g->home_sets) > intval($g->away_sets);
            $away_won = intval($g->away_sets) > intval($g->home_sets);
        } elseif ($g->status === 'forfeit') {
            $home_won = intval($g->forfeit_team_id) === $a;
            $away_won = !$home_won;
        }
        $info = isset($stats['games'][intval($g->id)]) ? $stats['games'][intval($g->id)] : null;
        $ranked = $g->division === 'ranked';
        // The match-up: set chances going in (played) or from today's ratings (coming up).
        $p = null;
        $coming = $g->status === 'scheduled' && $g->night_date >= $today;
        if (self::SHOW_SET_CHANCES && $ranked && $coming) {
            $p = RSL_Stats::chance_now($g->season, $h, $a);
        }
        $hype = $coming && !self::SHOW_SET_CHANCES ? RSL_Stats::hype($g) : array();
        $upset = $info && $info['upset_team'] ? intval($info['upset_team']) : 0;
        $close = $info && $info['close'] && !$upset && $g->status === 'played' && intval($g->home_sets) !== intval($g->away_sets);
        ob_start();
        ?>
        <div class="rsl-game<?php echo $is_mine ? ' is-mine' : ''; ?><?php echo $upset ? ' is-upset' : ''; ?>">
            <div class="rsl-game-court"><?php echo esc_html($g->court_name); ?><?php if ($is_mine): ?> <span class="rsl-chip">Your game</span><?php endif; ?></div>
            <div class="rsl-game-body">
                <div class="rsl-game-match">
                    <a class="rsl-side rsl-side-home<?php echo $home_won ? ' is-winner' : ''; ?>" href="<?php echo esc_url(RSL_Public::team_url($home)); ?>">
                        <span class="rsl-side-text"><span class="rsl-side-name"><?php echo esc_html($home->name); ?></span><?php echo $ranked ? self::side_meta($h, $stats, $info) : ''; ?></span><?php echo RSL_Public::badge($home, 'small'); ?>
                    </a>
                    <div class="rsl-score" data-game="<?php echo intval($g->id); ?>"<?php echo $g->status !== 'scheduled' ? ' data-final' : ''; ?>><?php echo self::score_html($g, $today, $upset, $close); ?></div>
                    <a class="rsl-side rsl-side-away<?php echo $away_won ? ' is-winner' : ''; ?>" href="<?php echo esc_url(RSL_Public::team_url($away)); ?>">
                        <?php echo RSL_Public::badge($away, 'small'); ?><span class="rsl-side-text"><span class="rsl-side-name"><?php echo esc_html($away->name); ?></span><?php echo $ranked ? self::side_meta($a, $stats, $info) : ''; ?></span>
                    </a>
                </div>
                <?php if ($p !== null): ?>
                    <?php $hp = (int) round(100 * $p); ?>
                    <div class="rsl-odds" title="Each team's chance of winning any one set, from their ratings">
                        <span class="rsl-odds-num"><?php echo $hp; ?>%</span>
                        <span class="rsl-odds-bar"><span style="width:<?php echo $hp; ?>%; background:<?php echo esc_attr($g->home_colour); ?>"></span><span style="width:<?php echo 100 - $hp; ?>%; background:<?php echo esc_attr($g->away_colour); ?>"></span></span>
                        <span class="rsl-odds-num"><?php echo 100 - $hp; ?>%</span>
                        <span class="rsl-odds-label"><?php
                            $fav = $hp > 50 ? $g->home_name : $g->away_name;
                            $dog = $hp > 50 ? $g->away_name : $g->home_name;
                            echo esc_html(abs($hp - 50) <= 5 ? 'Even match' : (abs($hp - 50) <= 15 ? 'Close — slight edge to ' . $fav : 'Upset alert — ' . $dog . ' are the underdogs'));
                        ?></span>
                    </div>
                <?php endif; ?>
                <?php if ($hype): ?>
                    <div class="rsl-hype"><?php foreach ($hype as $tag): ?><span><?php echo esc_html($tag); ?></span><?php endforeach; ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /** The middle of a game card: result, LIVE score in progress, or "vs". */
    private static function score_html($g, $today, $upset = false, $close = false) {
        $h = intval($g->home_team_id);
        $out = $upset ? '<span class="rsl-upset">Upset!</span>' : ($close ? '<span class="rsl-close">Close one</span>' : '');
        $live = RSL_Fixtures::live($g);
        if ($g->status === 'played') {
            return $out . '<strong>' . intval($g->home_sets) . '&ndash;' . intval($g->away_sets) . '</strong><small>' . esc_html(RSL_Fixtures::format_sets($g->sets, ' · ')) . '</small>';
        }
        if ($g->status === 'forfeit') {
            return $out . '<strong>Forfeit</strong><small>' . esc_html(intval($g->forfeit_team_id) === $h ? $g->home_name : $g->away_name) . ' forfeited</small>';
        }
        if ($live) {
            $won = array(0, 0);
            foreach ($live['sets'] as $s) {
                $won[$s[0] > $s[1] ? 0 : 1]++;
            }
            $sets = array_map(function ($s) { return $s[0] . '-' . $s[1]; }, $live['sets']);
            $now = ($live['current'][0] || $live['current'][1]) ? 'Set ' . (count($live['sets']) + 1) . ': ' . $live['current'][0] . '–' . $live['current'][1] : '';
            return '<span class="rsl-live">Live</span><strong>' . $won[0] . '&ndash;' . $won[1] . '</strong><small>' . esc_html(implode(' · ', array_filter(array_merge($sets, array($now))))) . '</small>';
        }
        if ($g->night_date < $today) {
            return '<strong class="rsl-vs">vs</strong><small>Result to come</small>';
        }
        return '<strong class="rsl-vs">vs</strong>';
    }

    /** Live scores for a night (public, read-only). Polled by the Fixtures page on game night. */
    public static function ajax_live_night() {
        $night = RSL_Courts::get_night(isset($_GET['night']) ? intval($_GET['night']) : 0);
        if (!$night || !RSL_Fixtures::is_published($night)) {
            wp_send_json_error(array(), 404);
        }
        wp_send_json_success(self::live_scores($night));
    }

    /** array(games => game_id => score HTML, all_done => every game has a result, so polling can stop). */
    public static function live_scores($night) {
        $today = current_time('Y-m-d');
        $out = array('games' => array(), 'all_done' => true);
        foreach (RSL_Fixtures::games_for_night($night->id) as $g) {
            $out['games'][intval($g->id)] = self::score_html($g, $today);
            $out['all_done'] = $out['all_done'] && $g->status !== 'scheduled';
        }
        return $out;
    }

    /** Under a Ranked team's name: tier, ladder place, and (for a result) its rating change that night. */
    private static function side_meta($team_id, $stats, $info) {
        $now = isset($stats['teams'][$team_id]['now']) ? $stats['teams'][$team_id]['now'] : null;
        if (!$now) {
            return '';
        }
        $html = '<span class="rsl-side-meta">' . self::tier_chip($now['tier']);
        if ($now['position']) {
            $html .= '<span class="rsl-pos">#' . intval($now['position']) . '</span>';
        }
        if ($info && isset($info['delta'][$team_id]) && $info['delta'][$team_id] !== null) {
            $d = intval($info['delta'][$team_id]);
            $html .= '<span class="rsl-delta ' . ($d >= 0 ? 'is-up' : 'is-down') . '" title="Rating change that night">' . ($d >= 0 ? '+' : '&minus;') . abs($d) . '</span>';
        }
        return $html . '</span>';
    }

    public static function tier_chip($tier, $size = '') {
        return '<span class="rsl-tier rsl-tier-' . esc_attr($tier['key']) . ($size ? ' rsl-tier-' . esc_attr($size) : '') . '" style="--tier:' . esc_attr($tier['colour']) . '">'
            . esc_html($tier['label']) . '</span>';
    }

    /** The tier rule in a sentence (How the ladder works), from RSL_Stats::DEMOTE_ROUNDS and DEMOTE_BUFFER. Plain text. */
    public static function tier_rule_words() {
        $n = intval(RSL_Stats::DEMOTE_ROUNDS);
        $when = $n === 1 ? 'a round' : strtolower(RSL_Public::count_word($n)) . ' rounds in a row';
        return 'They follow the rating with a small buffer so a single result doesn\'t flip a team between tiers: '
            . 'a team moves up as soon as its rating crosses into a tier, and down one tier after ' . $when
            . ' more than ' . intval(RSL_Stats::DEMOTE_BUFFER) . ' points below where its tier starts.';
    }

    /** ✓ when a team has played enough rounds for finals; "2 to go" while it still can; blank once it can't. */
    private static function finals_mark($f) {
        if (!$f) {
            return '';
        }
        if ($f['eligible']) {
            return '<span class="rsl-finals is-in" title="Has played enough rounds for finals">&#10003;</span>';
        }
        return $f['possible'] ? '<span class="rsl-finals" title="Rounds still needed to be eligible for finals">' . intval($f['need']) . ' to go</span>' : '';
    }

    private static function level_chip($points) {
        $l = RSL_Stats::level($points);
        return '<span class="rsl-level" title="' . esc_attr(intval($points) . ' Season Points') . '">' . intval($l['level']) . '</span>';
    }

    private static function progress_bar($fraction, $label, $colour = '') {
        $pct = (int) round(100 * max(0, min(1, $fraction)));
        return '<span class="rsl-progress"><span class="rsl-progress-bar"><span style="width:' . $pct . '%' . ($colour ? '; background:' . esc_attr($colour) : '') . '"></span></span>'
            . '<small>' . esc_html($label) . '</small></span>';
    }

    /** Season leaders box for the ladder page. */
    private static function leaders($stats) {
        $l = $stats['leaders'];
        $items = array();
        if ($l['improved']) {
            $items[] = array('Most improved', $l['improved']['team'], '+' . intval($l['improved']['value']) . ' rating');
        }
        if ($l['giant_killer']) {
            $n = intval($l['giant_killer']['value']);
            $items[] = array('Giant killers', $l['giant_killer']['team'], $n . ' upset' . ($n === 1 ? '' : 's'));
        }
        foreach (RSL_Teams::DIVISIONS as $d => $label) {
            if (isset($l['points'][$d])) {
                $items[] = array('Season Points · ' . $label, $l['points'][$d]['team'], intval($l['points'][$d]['value']) . ' pts');
            }
        }
        if (!$items) {
            return '';
        }
        $html = '<div class="rsl-leaders">';
        foreach ($items as $it) {
            $html .= '<div class="rsl-leader"><small>' . esc_html($it[0]) . '</small>' . self::team_cell($it[1]) . '<span>' . esc_html($it[2]) . '</span></div>';
        }
        return $html . '</div>';
    }

    private static function form_pills($form) {
        if (!$form) {
            return '<span class="rsl-empty">&ndash;</span>';
        }
        $html = '<span class="rsl-form" aria-label="Last ' . count($form) . ' results, oldest first">';
        foreach ($form as $f) {
            $html .= '<span class="rsl-form-' . strtolower($f) . '">' . esc_html($f) . '</span>';
        }
        return $html . '</span>';
    }

    // ------------------------------------------------------------------
    // [rsl_ladder]
    // ------------------------------------------------------------------

    public function render_ladder($atts = array()) {
        $season = RSL_Seasons::current();
        $mine = self::viewer_team_id($season);
        // Stats first: on a cache miss it reads the season afresh, and the
        // label and ladders below then share that same snapshot.
        $stats = RSL_Stats::season($season);
        $after = self::latest_result_label($season);
        ob_start();
        ?>
        <div class="rsl">
            <h2 class="rsl-title"><?php echo esc_html(RSL_Seasons::label($season)); ?> ladder</h2>
            <?php if ($after): ?><p class="rsl-meta">After <?php echo esc_html($after); ?></p><?php endif; ?>
            <?php echo self::leaders($stats); ?>
            <?php foreach (RSL_Teams::DIVISIONS as $division => $label): ?>
                <?php $rows = RSL_Ratings::ladder($season, $division); ?>
                <?php if (empty($rows)) { continue; } ?>
                <h3 class="rsl-division"><?php echo esc_html($label); ?></h3>
                <?php echo $division === 'ranked' ? self::ranked_table($rows, $mine, $stats) : self::unranked_table($rows, $mine, $stats); ?>
            <?php endforeach; ?>
            <?php echo RSL_Public::season_preview($season); ?>
            <?php $pts = RSL_Stats::POINTS; ?>
            <details class="rsl-how">
                <summary>How the ladder works</summary>
                <ul>
                    <li><strong>Ranked</strong> is ordered by rating, from the points in every set plus a bonus for winning sets — beating a strong team counts for more.</li>
                    <li><strong>Tiers:</strong> <?php echo esc_html(implode(' · ', array_map(function ($t) { return $t[1] . ($t[2] > 0 ? ' ' . $t[2] . '+' : ''); }, array_reverse(RSL_Stats::TIERS)))); ?>. <?php echo esc_html(self::tier_rule_words()); ?></li>
                    <li><strong>In placements:</strong> a team's first <?php echo intval(RSL_Ratings::PLACEMENT_GAMES); ?> games.</li>
                    <li><strong>Finals:</strong> <?php echo intval(RSL_Stats::finals_min_rounds()); ?> regular-season rounds played.</li>
                    <li><strong>Level:</strong> Season Points — <?php echo esc_html('+' . $pts['played'] . ' a night, +' . $pts['win'] . ' a win, +' . $pts['close'] . ' a close game, +' . $pts['upset'] . ' an upset, +' . $pts['achievement'] . ' an achievement'); ?>.</li>
                </ul>
            </details>
            <p class="rsl-links"><a href="<?php echo esc_url(self::fixtures_url()); ?>">Fixtures &amp; results &rarr;</a></p>
        </div>
        <?php
        return ob_get_clean() . RSL_Public::styles() . self::styles();
    }

    private static function ranked_table($rows, $mine, $stats) {
        $draws = array_sum(array_map(function ($r) { return $r['drawn']; }, $rows)) > 0;
        ob_start();
        ?>
        <div class="rsl-table-wrap"><table class="rsl-ladder">
            <thead><tr><th class="num">#</th><th>Team</th><th>Tier</th><th class="num">Rating</th><th class="num">P</th><th class="num">W</th><?php if ($draws): ?><th class="num">D</th><?php endif; ?><th class="num">L</th><th class="num">Sets</th><th>Form</th><th class="num" title="<?php echo esc_attr('Finals: ' . RSL_Stats::finals_min_rounds() . ' regular-season rounds needed'); ?>">Finals</th><th class="num" title="Places moved since last week">&plusmn;</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <?php $t = intval($r['team']->id); $s = isset($stats['teams'][$t]) ? $stats['teams'][$t] : null; ?>
                <tr<?php echo $mine === $t ? ' class="is-mine"' : ''; ?>>
                    <td class="num rsl-place"><?php echo $r['position'] ? intval($r['position']) : '&ndash;'; ?></td>
                    <td><?php echo self::team_cell($r['team']); ?></td>
                    <td><?php echo $r['new'] ? '<span class="rsl-chip">New</span>' : self::tier_chip($s && $s['now'] ? $s['now']['tier'] : RSL_Stats::tier(null, true)); ?></td>
                    <td class="num rsl-rating"><?php echo $r['new'] || $r['placement'] ? '&ndash;' : intval($r['rating']); ?></td>
                    <td class="num"><?php echo intval($r['played']); ?></td>
                    <td class="num"><?php echo intval($r['won']); ?></td>
                    <?php if ($draws): ?><td class="num"><?php echo intval($r['drawn']); ?></td><?php endif; ?>
                    <td class="num"><?php echo intval($r['lost']); ?></td>
                    <td class="num"><?php echo intval($r['sets_for']); ?>&ndash;<?php echo intval($r['sets_against']); ?></td>
                    <td><?php echo self::form_pills($s ? $s['form'] : array()); ?></td>
                    <td class="num"><?php echo self::finals_mark($s ? $s['finals'] : null); ?></td>
                    <td class="num"><?php echo self::move($r['move']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php
        return ob_get_clean();
    }

    private static function unranked_table($rows, $mine, $stats) {
        $draws = array_sum(array_map(function ($r) { return $r['drawn']; }, $rows)) > 0;
        ob_start();
        ?>
        <div class="rsl-table-wrap"><table class="rsl-ladder">
            <thead><tr><th class="num">#</th><th>Team</th><th class="num">P</th><th class="num">W</th><?php if ($draws): ?><th class="num">D</th><?php endif; ?><th class="num">L</th><th class="num">Sets</th><th>Form</th><th class="num" title="Team level from Season Points">Level</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <?php $t = intval($r['team']->id); $s = isset($stats['teams'][$t]) ? $stats['teams'][$t] : null; ?>
                <tr<?php echo $mine === $t ? ' class="is-mine"' : ''; ?>>
                    <td class="num rsl-place"><?php echo intval($r['position']); ?></td>
                    <td><?php echo self::team_cell($r['team']); ?></td>
                    <td class="num"><?php echo intval($r['played']); ?></td>
                    <td class="num"><?php echo intval($r['won']); ?></td>
                    <?php if ($draws): ?><td class="num"><?php echo intval($r['drawn']); ?></td><?php endif; ?>
                    <td class="num"><?php echo intval($r['lost']); ?></td>
                    <td class="num"><?php echo intval($r['sets_for']); ?>&ndash;<?php echo intval($r['sets_against']); ?></td>
                    <td><?php echo self::form_pills($s ? $s['form'] : array()); ?></td>
                    <td class="num"><?php echo self::level_chip($s ? $s['points'] : 0); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php
        return ob_get_clean();
    }

    private static function team_cell($team) {
        return '<a class="rsl-team-cell" href="' . esc_url(RSL_Public::team_url($team)) . '">' . RSL_Public::badge($team, 'small') . '<span>' . esc_html($team->name) . '</span></a>';
    }

    private static function move($move) {
        if ($move === null || $move === 0) {
            return '<span class="rsl-move">&ndash;</span>';
        }
        return $move > 0
            ? '<span class="rsl-move is-up" title="Up ' . intval($move) . '">&#9650;' . intval($move) . '</span>'
            : '<span class="rsl-move is-down" title="Down ' . intval(-$move) . '">&#9660;' . intval(-$move) . '</span>';
    }

    /** "Round 4 (18 Oct)" — the latest published night with a result in. */
    private static function latest_result_label($season) {
        $latest = null;
        foreach (RSL_Ratings::season_data($season)['games'] as $g) {
            if (!empty($g->published_at) && ($g->status === 'played' || $g->status === 'forfeit') && ($latest === null || $g->night_date > $latest->night_date)) {
                $latest = $g;
            }
        }
        if (!$latest) {
            return '';
        }
        $labels = RSL_Fixtures::round_labels($season);
        return $labels[$latest->night_id] . ' (' . date_i18n('j M', strtotime($latest->night_date)) . ')';
    }

    // ------------------------------------------------------------------
    // A team's page
    // ------------------------------------------------------------------

    /** The fixtures-and-results section of a team's public page. */
    public static function team_section($team) {
        $games = RSL_Fixtures::games_for_team($team->id);
        $labels = RSL_Fixtures::round_labels($team->season);
        $today = current_time('Y-m-d');
        $stats = RSL_Stats::season($team->season); // first, so the standing below reads the same snapshot
        $standing = '';
        foreach (RSL_Ratings::ladder($team->season, $team->division) as $r) {
            if (intval($r['team']->id) !== intval($team->id)) {
                continue;
            }
            $record = intval($r['won']) . ' won · ' . intval($r['lost']) . ' lost' . ($r['drawn'] ? ' · ' . intval($r['drawn']) . ' drawn' : '') . ' · sets ' . intval($r['sets_for']) . '–' . intval($r['sets_against']);
            if ($team->division === 'ranked') {
                $standing = $r['new']
                    ? 'New team — rated after its first games.'
                    : ($r['placement']
                        ? 'In placements — takes its place on the ladder once its rating settles · ' . $record
                        : 'Rating ' . intval($r['rating']) . ' · ' . self::ordinal($r['position']) . ' on the ladder · ' . $record);
            } elseif ($r['played']) {
                $standing = self::ordinal($r['position']) . ' on the ladder · ' . $record;
            }
        }
        ob_start();
        ?>
        <?php if ($standing): ?><p class="rsl-standing"><?php echo esc_html($standing); ?> &middot; <a href="<?php echo esc_url(self::ladder_url()); ?>">Ladder</a></p><?php endif; ?>
        <h3 class="rsl-division">Season schedule</h3>
        <?php echo self::season_schedule($team, $games, $labels, $today, $stats); ?>
        <?php
        return ob_get_clean() . self::styles();
    }

    /** The game-style card under a team's name: tier, form, streak, journey, achievements. */
    public static function team_profile($team) {
        $stats = RSL_Stats::season($team->season);
        $s = isset($stats['teams'][intval($team->id)]) ? $stats['teams'][intval($team->id)] : null;
        ob_start();
        ?>
        <?php if ($s): ?>
            <?php $lvl = RSL_Stats::level($s['points']); ?>
            <div class="rsl-profile">
                <?php if ($team->division === 'ranked' && $s['now']): ?>
                    <?php $now = $s['now']; ?>
                    <div class="rsl-profile-tier"><?php echo self::tier_chip($now['tier'], 'large'); ?>
                        <?php if ($now['tier']['key'] === 'placement'): ?>
                            <small>Placements: tier and ladder place revealed after <?php echo intval(RSL_Ratings::PLACEMENT_GAMES); ?> games</small>
                        <?php elseif ($now['at_risk']): ?>
                            <small class="rsl-at-risk">At risk — another week well below <?php echo esc_html($now['tier']['label']); ?> drops a tier</small>
                        <?php elseif ($now['next']): ?>
                            <?php echo self::progress_bar($now['progress'], (int) round(100 * $now['progress']) . '% of the way to ' . $now['next']['label'], $now['next']['colour']); ?>
                        <?php else: ?>
                            <small>Top tier</small>
                        <?php endif; ?>
                        <?php if ($s['peak'] && $s['peak']['key'] !== $now['tier']['key']): ?><small>Peak this season: <?php echo esc_html($s['peak']['label']); ?></small><?php endif; ?>
                    </div>
                <?php endif; ?>
                <div class="rsl-profile-level">
                    <span class="rsl-level rsl-level-large">Lvl <?php echo intval($lvl['level']); ?></span>
                    <?php echo self::progress_bar($lvl['into'] / $lvl['need'], intval($s['points']) . ' Season Points · ' . ($lvl['need'] - $lvl['into']) . ' to Level ' . ($lvl['level'] + 1)); ?>
                </div>
                <dl class="rsl-profile-facts">
                    <?php if ($s['form']): ?><div><dt>Form</dt><dd><?php echo self::form_pills($s['form']); ?></dd></div><?php endif; ?>
                    <?php if ($s['streak'] && intval(substr($s['streak'], 1)) >= 2): ?>
                        <div><dt>Streak</dt><dd><span class="rsl-streak is-<?php echo esc_attr(strtolower($s['streak'][0])); ?>"><?php echo esc_html(intval(substr($s['streak'], 1)) . ' ' . array('W' => 'wins', 'L' => 'losses', 'D' => 'draws')[$s['streak'][0]] . ' in a row'); ?></span></dd></div>
                    <?php endif; ?>
                    <?php $journey = self::journey($s['history'], $team->season); ?>
                    <?php if ($journey): ?><div><dt>Journey</dt><dd class="rsl-journey"><?php echo $journey; ?></dd></div><?php endif; ?>
                </dl>
                <?php if ($s['achievements']): ?>
                    <div class="rsl-achievements">
                        <?php foreach ($s['achievements'] as $key => $ach): ?>
                            <span class="rsl-ach rsl-ach-<?php echo esc_attr($key); ?>" title="<?php echo esc_attr($ach['why']); ?>"><strong><?php echo esc_html($ach['label']); ?></strong><small><?php echo esc_html($ach['why']); ?></small></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?php
        return ob_get_clean() . RSL_Public::styles() . self::styles();
    }

    /**
     * Every night of the team's season: its games (and results) once that
     * week is published, "TBA" before, byes and finals. The captain can ask
     * for a week off or two games before a week's fixtures come out, and
     * say "can't make it" after.
     */
    private static function season_schedule($team, $games, $labels, $today, $stats) {
        $nights = RSL_Courts::nights($team->season);
        if (!$nights) {
            return '<p class="rsl-empty">The season calendar isn\'t out yet.</p>';
        }
        $by_night = array();
        foreach ($games as $g) {
            $by_night[intval($g->night_id)][] = $g;
        }
        $user_id = get_current_user_id();
        $captain = $user_id && (RSL_Teams::is_captain($team->id, $user_id) || current_user_can('manage_options'));
        // Requests are the team's business: only its members (and admins) see them.
        $insider = $captain || ($user_id && RSL_Teams::is_member($team->id, $user_id));
        $requests = $insider ? RSL_Requests::for_team($team->id) : array();
        $back = RSL_Public::team_url($team);
        $button = function ($do, $label, $night, $extra = array(), $confirm = '') use ($team, $back) {
            return '<form method="post" class="rsl-req-form"' . ($confirm ? ' onsubmit="return confirm(\'' . esc_js($confirm) . '\');"' : '') . '>'
                . RSL_Portal::form_fields($do, array_merge(array('team_id' => $team->id, 'night_id' => $night->id, 'back' => $back), $extra))
                . '<button type="submit" class="rsl-req-btn">' . esc_html($label) . '</button></form>';
        };
        ob_start();
        ?>
        <?php if ($captain): ?><p class="rsl-hint">Captain: ask for a week off or two games before that week's fixtures come out (Mondays).</p><?php endif; ?>
        <div class="rsl-table-wrap"><table class="rsl-team-games rsl-schedule">
            <tbody>
            <?php foreach ($nights as $n): ?>
                <?php
                $req = isset($requests[intval($n->id)]) ? $requests[intval($n->id)] : null;
                $published = RSL_Fixtures::is_published($n);
                $upcoming = $n->night_date >= $today;
                $mine = isset($by_night[intval($n->id)]) ? $by_night[intval($n->id)] : array();
                $label = '<a href="' . esc_url(self::fixtures_url($n)) . '">' . esc_html(self::short_label($labels[$n->id])) . '</a><br><small>' . esc_html(date_i18n('D j M', strtotime($n->night_date))) . '</small>';
                ?>
                <?php if ($n->type === 'bye'): ?>
                    <tr class="rsl-sched-quiet"><td><?php echo $label; ?></td><td colspan="3">No games this week<?php echo $n->note !== '' ? ' — ' . esc_html($n->note) : ''; ?></td></tr>
                <?php elseif ($published && $mine): ?>
                    <?php foreach ($mine as $i => $g): ?>
                        <?php
                        $is_home = intval($g->home_team_id) === intval($team->id);
                        $opp = (object) ($is_home
                            ? array('name' => $g->away_name, 'slug' => $g->away_slug, 'colour' => $g->away_colour)
                            : array('name' => $g->home_name, 'slug' => $g->home_slug, 'colour' => $g->home_colour));
                        $r = RSL_Fixtures::result_for($g, $team->id);
                        ?>
                        <tr<?php echo $upcoming && $r['outcome'] === 'pending' ? ' class="is-next"' : ''; ?>>
                            <?php if ($i === 0): ?><td rowspan="<?php echo count($mine); ?>"><?php echo $label; ?></td><?php endif; ?>
                            <td><?php echo esc_html(RSL_Courts::pretty_time(RSL_Courts::hm($g->start_time))); ?><br><small><?php echo esc_html($g->court_name . ', ' . $g->venue_name); ?></small></td>
                            <td><a class="rsl-team-cell" href="<?php echo esc_url(RSL_Public::team_url($opp)); ?>"><?php echo RSL_Public::badge($opp, 'small'); ?><span><?php echo esc_html($opp->name); ?></span></a></td>
                            <td class="rsl-result"><?php echo self::result_label($r, $g, $today); ?><?php
                                $gi = isset($stats['games'][intval($g->id)]) ? $stats['games'][intval($g->id)] : null;
                                if ($gi && intval($gi['upset_team']) === intval($team->id)) {
                                    echo ' <span class="rsl-upset">Upset win!</span>';
                                }
                                if ($gi && isset($gi['delta'][intval($team->id)]) && $gi['delta'][intval($team->id)] !== null) {
                                    $d = intval($gi['delta'][intval($team->id)]);
                                    echo ' <span class="rsl-delta ' . ($d >= 0 ? 'is-up' : 'is-down') . '" title="Rating change that night">' . ($d >= 0 ? '+' : '&minus;') . abs($d) . '</span>';
                                }
                                if ($i === count($mine) - 1 && $upcoming) {
                                    if ($req && $req->type === 'cant_make') {
                                        echo '<br><span class="rsl-req-chip is-warn">' . ($req->status === 'handled' ? 'Sorted by the league' : 'You\'ve told the league you can\'t make it') . '</span>';
                                        // Captains can take it back only until the night starts or a result is in
                                        // (RSL_Requests::cancel enforces it; admins can always withdraw it).
                                        $start = RSL_Officials::night_start($n);
                                        $too_late = ($start && current_time('timestamp') >= $start)
                                            || array_filter($mine, function ($x) { return $x->status !== 'scheduled'; });
                                        if ($captain && $req->status === 'active' && (!$too_late || current_user_can('manage_options'))) {
                                            echo $button('cancel_request', 'We can make it after all', $n, array('request_id' => $req->id));
                                        }
                                    } elseif ($captain && $r['outcome'] === 'pending') {
                                        // Free before the cut-off; after it the team pays both teams' fees — say so before it's sent.
                                        $until = RSL_Payments::free_cancel_until($n);
                                        $when = $until !== null ? date_i18n('D j M, g:ia', $until) : '';
                                        // As RSL_Payments::sync_night() will charge it: a late fee (both teams' fees) on each
                                        // game not yet played, in place of the usual fee. A game already played is charged as
                                        // normal, and a game the other team has already called off costs nothing (only the
                                        // first team out of a game pays; this team has no "can't make it" yet).
                                        $left = 0;
                                        if ($until !== null && current_time('timestamp') > $until) {
                                            $called_off = RSL_Requests::for_night($n->id, 'cant_make', array('active', 'handled'));
                                            foreach ($mine as $x) {
                                                $other = intval($x->home_team_id) === intval($team->id) ? intval($x->away_team_id) : intval($x->home_team_id);
                                                if ($x->status !== 'played' && !isset($called_off[$other])) {
                                                    $left++;
                                                }
                                            }
                                        }
                                        if ($left > 0) {
                                            $fee = RSL_Payments::settings()['game_fee'];
                                            echo $button('cant_make', 'Can\'t make it', $n, array(), 'It\'s past the free cut-off (' . $when . '), so your team will be charged both teams\' game fees for '
                                                . ($left === 1 ? 'the game' : 'the ' . $left . ' games') . ' still to play: '
                                                . RSL_Payments::money(2 * $fee * $left) . ' instead of ' . RSL_Payments::money($fee * $left) . '. Tell the league your team can\'t make it?');
                                            echo '<br><small>Past the free cut-off — costs both teams\' fees</small>';
                                        } else {
                                            echo $button('cant_make', 'Can\'t make it', $n, array(), 'Tell the league your team can\'t make it this week? They\'ll sort it out with the other team.');
                                            if ($until !== null && current_time('timestamp') <= $until) {
                                                echo '<br><small>Free until ' . esc_html($when) . '</small>';
                                            }
                                        }
                                    }
                                }
                            ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php elseif ($published): ?>
                    <tr class="rsl-sched-quiet"><td><?php echo $label; ?></td><td colspan="3"><?php echo $req && $req->type === 'off' ? 'Week off (requested)' : ($req && $req->type === 'unpaid' ? 'Not fixtured — the team balance was owing' : 'No game this week'); ?></td></tr>
                <?php else: ?>
                    <tr class="rsl-sched-tba">
                        <td><?php echo $label; ?></td>
                        <td colspan="2">
                            <?php if ($n->type === 'finals'): ?>
                                <strong>Finals</strong> &middot; <span class="rsl-empty">TBA</span>
                            <?php else: ?>
                                <span class="rsl-tba">TBA</span>
                                <?php if ($upcoming): ?><small>fixtures out <?php echo esc_html(date_i18n('D j M', strtotime($n->night_date . ' -6 days'))); ?></small><?php endif; ?>
                            <?php endif; ?>
                            <?php if ($req && $req->type === 'off'): ?><span class="rsl-req-chip">Week off requested</span><?php endif; ?>
                            <?php if ($req && $req->type === 'double'): ?><span class="rsl-req-chip is-good">Two games requested</span><?php endif; ?>
                        </td>
                        <td class="rsl-result">
                            <?php if ($captain && $upcoming && $n->type === 'regular'): ?>
                                <?php if ($req && in_array($req->type, array('off', 'double'), true)): ?>
                                    <?php echo $button('cancel_request', 'Withdraw request', $n, array('request_id' => $req->id)); ?>
                                <?php else: ?>
                                    <?php echo $button('request_off', 'Week off', $n); ?>
                                    <?php echo $button('request_double', 'Two games', $n); ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php
        return ob_get_clean();
    }

    /** "In placements → Silver (R2) → Gold (R4)": each tier change after a round. */
    private static function journey($history, $season) {
        if (!$history) {
            return '';
        }
        $labels = array();
        foreach (RSL_Courts::nights($season) as $n) {
            $labels[$n->night_date] = $n->id;
        }
        $round_labels = RSL_Fixtures::round_labels($season);
        $steps = array();
        $last = null;
        foreach ($history as $date => $h) {
            if ($h['tier']['key'] === $last) {
                continue;
            }
            $round = isset($labels[$date]) ? self::short_label($round_labels[$labels[$date]]) : '';
            $steps[] = self::tier_chip($h['tier'], 'small') . ($last !== null && $round ? ' <small>' . esc_html($round) . '</small>' : '');
            $last = $h['tier']['key'];
        }
        return count($steps) > 1 ? implode(' <span class="rsl-arrow">&rarr;</span> ', $steps) : '';
    }

    private static function result_label($r, $g, $today) {
        switch ($r['outcome']) {
            case 'win':
            case 'loss':
            case 'draw':
                $letter = array('win' => 'W', 'loss' => 'L', 'draw' => 'D')[$r['outcome']];
                return '<span class="rsl-res rsl-res-' . $r['outcome'] . '">' . $letter . ' ' . intval($r['for']) . '&ndash;' . intval($r['against']) . '</span><br><small>' . esc_html(RSL_Fixtures::format_sets($r['sets'], ' · ')) . '</small>';
            case 'forfeit_win':
                return '<span class="rsl-res rsl-res-win">W</span><br><small>Forfeit</small>';
            case 'forfeit_loss':
                return '<span class="rsl-res rsl-res-loss">L</span><br><small>Forfeited</small>';
        }
        return $g->night_date < $today ? '<small>Result to come</small>' : '<small>Upcoming</small>';
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** The signed-in viewer's team this season (to highlight it), or 0. */
    private static function viewer_team_id($season) {
        $user_id = get_current_user_id();
        $team = $user_id ? RSL_Teams::current_team_for_user($user_id, $season) : null;
        return $team ? intval($team->id) : 0;
    }

    /** "Round 3" -> "R3", "Finals 1" -> "F1", "Finals" -> "F", "Bye" stays. */
    private static function short_label($label) {
        if (preg_match('/^Round (\d+)$/', $label, $m)) {
            return 'R' . $m[1];
        }
        if (preg_match('/^Finals ?(\d*)$/', $label, $m)) {
            return 'F' . $m[1];
        }
        return $label;
    }

    private static function ordinal($n) {
        $n = intval($n);
        $suffix = in_array($n % 100, array(11, 12, 13), true) ? 'th' : array('th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th')[$n % 10];
        return $n . $suffix;
    }

    /** Shared front-end styles for the league pages (weeks strip, chips, buttons…). */
    public static function styles() {
        static $done = false;
        if ($done) {
            return '';
        }
        $done = true;
        return '<style>
            .rsl a.rsl-week, .rsl a.rsl-side, .rsl a.rsl-team-cell { text-decoration: none !important; color: inherit; box-shadow: none; }
            .rsl a.rsl-side:hover .rsl-side-name, .rsl a.rsl-team-cell:hover span { text-decoration: underline; }
            .rsl a.rsl-week.is-current { color: #fff; }
            .rsl-weeks { display: flex; gap: 6px; overflow-x: auto; padding: 4px 0 10px; margin: 8px 0 4px; }
            .rsl-week { flex: 0 0 auto; display: flex; flex-direction: column; align-items: center; min-width: 54px; padding: 6px 8px; border: 1px solid var(--rsl-border); border-radius: 8px; text-decoration: none; color: inherit; line-height: 1.2; }
            .rsl-week strong { font-size: 14px; }
            .rsl-week span { font-size: 12px; color: var(--rsl-muted); }
            .rsl-week:hover { border-color: var(--rsl-navy); }
            .rsl-week.is-current { background: var(--rsl-navy); border-color: var(--rsl-navy); color: #fff; }
            .rsl-week.is-current span { color: #dbe4f0; }
            .rsl-week.is-later { border-style: dashed; }
            .rsl-week.is-bye strong { color: var(--rsl-muted); font-weight: 600; }
            .rsl-night-title { margin: 14px 0 4px; }
            .rsl-night-title span { color: var(--rsl-muted); font-weight: 400; }
            .rsl-venue { margin-bottom: 18px; }
            .rsl-time { margin: 14px 0 6px; font-size: 13px; font-weight: 700; color: var(--rsl-muted); text-transform: uppercase; letter-spacing: .4px; }
            .rsl-game { display: flex; align-items: center; gap: 12px; padding: 10px 12px; margin-bottom: 6px; border: 1px solid var(--rsl-border); border-radius: 8px; }
            .rsl-game.is-mine { border-color: var(--rsl-navy); box-shadow: inset 4px 0 0 var(--rsl-navy); }
            .rsl-game-court { flex: 0 0 96px; font-size: 13px; color: var(--rsl-muted); }
            .rsl-game-match { flex: 1 1 auto; display: grid; grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr); align-items: center; gap: 10px; }
            .rsl-side { display: flex; align-items: center; gap: 8px; text-decoration: none; color: inherit; min-width: 0; }
            .rsl-side-home { justify-content: flex-end; text-align: right; }
            .rsl-side-name { overflow: hidden; text-overflow: ellipsis; }
            .rsl-side.is-winner .rsl-side-name { font-weight: 700; }
            .rsl-score { display: flex; flex-direction: column; align-items: center; min-width: 72px; text-align: center; }
            .rsl-score strong { font-size: 17px; }
            .rsl-score small, .rsl-team-games small { color: var(--rsl-muted); font-size: 12px; }
            .rsl-vs { color: var(--rsl-muted); font-weight: 600; font-size: 14px !important; }
            .rsl-badge-small { width: 28px; height: 28px; font-size: 11px; }
            .rsl-links { margin-top: 20px; }
            .rsl-table-wrap { overflow-x: auto; }
            .rsl-ladder, .rsl-team-games { width: 100%; border-collapse: collapse; }
            .rsl-ladder th { text-align: left; font-size: 12px; color: var(--rsl-muted); font-weight: 600; padding: 6px 8px; border-bottom: 2px solid var(--rsl-border); }
            .rsl-ladder td, .rsl-team-games td { padding: 8px; border-bottom: 1px solid var(--rsl-border); vertical-align: middle; }
            .rsl-ladder .num { text-align: center; white-space: nowrap; }
            .rsl-ladder tr.is-mine td { background: #eef2f8; }
            .rsl-team-cell { display: inline-flex; align-items: center; gap: 8px; text-decoration: none; color: inherit; font-weight: 600; }
            .rsl-rating { font-weight: 700; }
            .rsl-prov { font-size: 11px; color: var(--rsl-muted); text-decoration: none; border: 1px solid var(--rsl-border); border-radius: 4px; padding: 0 3px; font-weight: 600; }
            .rsl-move { font-size: 12px; color: var(--rsl-muted); }
            .rsl-move.is-up { color: #2e7d32; }
            .rsl-move.is-down { color: #b32d2e; }
            .rsl-note { font-size: 13px; color: var(--rsl-muted); margin-top: 8px; }
            .rsl-how { margin-top: 18px; font-size: 13px; color: var(--rsl-muted); }
            .rsl-how summary { cursor: pointer; font-weight: 700; color: var(--rsl-navy); }
            .rsl-how ul { margin: 8px 0 0 18px; padding: 0; }
            .rsl-how li { margin: 3px 0; }
            .rsl-standing { margin: 12px 0 0; font-weight: 600; }
            .rsl-team-games tr.is-next td { background: #eef2f8; }
            .rsl-result { text-align: right; white-space: nowrap; }
            .rsl-res { font-weight: 700; }
            .rsl-res-win { color: #2e7d32; }
            .rsl-res-loss { color: #b32d2e; }
            .rsl-res-draw { color: var(--rsl-muted); }
            .rsl-live { display: inline-flex; align-items: center; gap: 4px; margin-bottom: 2px; padding: 0 6px; border-radius: 4px; font-size: 10px; font-weight: 800; letter-spacing: .5px; text-transform: uppercase; background: #e0262a; color: #fff; }
            .rsl-live::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: #fff; animation: rsl-pulse 1.4s infinite; }
            @keyframes rsl-pulse { 50% { opacity: .3; } }
            .rsl-hype { display: flex; flex-wrap: wrap; justify-content: center; gap: 6px; margin-top: 8px; }
            .rsl-hype span { padding: 1px 8px; border-radius: 10px; font-size: 11px; font-weight: 700; background: #eef2f8; color: var(--rsl-navy); }
            .rsl-close { display: inline-block; margin-bottom: 2px; padding: 0 6px; border-radius: 4px; font-size: 10px; font-weight: 800; letter-spacing: .5px; text-transform: uppercase; background: #e7f0fb; color: #1d4f8a; }
            .rsl-flash { padding: 10px 14px; border-radius: 6px; margin: 12px 0; }
            .rsl-flash-success { background: #edfaef; color: #00602a; }
            .rsl-flash-error { background: #fcf0f1; color: #8a1f1f; }
            .rsl-hint { font-size: 13px; color: var(--rsl-muted); }
            .rsl-schedule td { vertical-align: top; }
            .rsl-sched-quiet td { color: var(--rsl-muted); }
            .rsl-sched-tba td { background: #fafbfc; }
            .rsl-tba { display: inline-block; margin-right: 6px; padding: 0 6px; border: 1px dashed var(--rsl-border); border-radius: 4px; font-size: 11px; font-weight: 800; letter-spacing: .5px; color: var(--rsl-muted); }
            .rsl-sched-tba small { color: var(--rsl-muted); font-size: 12px; }
            .rsl-req-chip { display: inline-block; margin: 2px 0 0 6px; padding: 1px 8px; border-radius: 10px; font-size: 11px; font-weight: 700; background: #eef2f8; color: var(--rsl-navy); }
            .rsl-req-chip.is-good { background: #edfaef; color: #00602a; }
            .rsl-req-chip.is-warn { background: #fcf0e8; color: #8a4600; margin-left: 0; }
            .rsl-req-form { display: inline-block; margin: 2px 0 0 4px; }
            .rsl .rsl-req-btn { padding: 3px 10px; border: 1px solid var(--rsl-navy); border-radius: 14px; background: #fff; color: var(--rsl-navy); font-size: 12px; font-weight: 700; cursor: pointer; line-height: 1.4; }
            .rsl .rsl-req-btn:hover { background: var(--rsl-navy); color: #fff; }
            .rsl-finals { font-size: 11px; color: var(--rsl-muted); white-space: nowrap; }
            .rsl-finals.is-in { color: #2e7d32; font-weight: 800; font-size: 14px; }
            .rsl-level { display: inline-flex; align-items: center; justify-content: center; min-width: 22px; height: 22px; padding: 0 5px; border-radius: 11px; background: var(--rsl-navy); color: #fff; font-size: 11px; font-weight: 800; }
            .rsl-level-large { height: 30px; padding: 0 12px; border-radius: 15px; font-size: 14px; }
            .rsl-progress { display: inline-flex; flex-direction: column; gap: 3px; min-width: 180px; }
            .rsl-progress-bar { display: block; height: 8px; border-radius: 4px; background: var(--rsl-border); overflow: hidden; }
            .rsl-progress-bar span { display: block; height: 100%; background: var(--rsl-navy); }
            .rsl-progress small { color: var(--rsl-muted); font-size: 11px; }
            .rsl-profile-level { display: flex; flex-direction: column; align-items: flex-start; gap: 4px; }
            .rsl-at-risk { color: #b32d2e !important; font-weight: 700; }
            .rsl-leaders { display: flex; flex-wrap: wrap; gap: 8px; margin: 12px 0 4px; }
            .rsl-leader { display: flex; flex-direction: column; gap: 4px; flex: 1 1 180px; padding: 10px 12px; border: 1px solid var(--rsl-border); border-radius: 10px; background: linear-gradient(180deg, #f7f9fc, #fff); }
            .rsl-leader small { font-size: 11px; text-transform: uppercase; letter-spacing: .4px; color: var(--rsl-muted); font-weight: 700; }
            .rsl-leader > span { font-size: 12px; font-weight: 800; color: var(--rsl-navy); }
            .rsl-game-body { flex: 1 1 auto; min-width: 0; }
            .rsl-side-text { display: flex; flex-direction: column; min-width: 0; }
            .rsl-side-home .rsl-side-text { align-items: flex-end; }
            .rsl-side-meta { display: inline-flex; align-items: center; gap: 5px; margin-top: 2px; font-size: 11px; font-weight: 400; }
            .rsl-pos { color: var(--rsl-muted); font-weight: 700; }
            .rsl-tier { display: inline-block; padding: 1px 7px; border-radius: 4px; font-size: 10px; font-weight: 800; letter-spacing: .5px; text-transform: uppercase; color: #fff; background: var(--tier); line-height: 1.6; white-space: nowrap; }
            .rsl-tier-placement { background: transparent; color: var(--tier); border: 1px dashed var(--tier); }
            .rsl-tier-small { font-size: 9px; padding: 0 5px; }
            .rsl-tier-large { font-size: 14px; padding: 4px 12px; border-radius: 6px; box-shadow: 0 2px 0 rgba(0,0,0,.15); }
            .rsl-tier-diamond { background: linear-gradient(135deg, #6d7cf0, #4f5fd6 55%, #8a5ce0); }
            .rsl-tier-platinum { background: linear-gradient(135deg, #2bb3ac, #16928c); }
            .rsl-tier-gold { background: linear-gradient(135deg, #d9a90f, #b98a00); }
            .rsl-delta { font-size: 11px; font-weight: 800; font-variant-numeric: tabular-nums; }
            .rsl-delta.is-up { color: #2e7d32; }
            .rsl-delta.is-down { color: #b32d2e; }
            .rsl-upset { display: inline-block; margin-bottom: 2px; padding: 0 6px; border-radius: 4px; font-size: 10px; font-weight: 800; letter-spacing: .5px; text-transform: uppercase; background: #ffd400; color: #3a2e00; transform: rotate(-3deg); }
            .rsl-game.is-upset { border-color: #e6c200; }
            .rsl-odds { display: grid; grid-template-columns: auto 1fr auto; align-items: center; gap: 8px; margin-top: 8px; font-size: 12px; }
            .rsl-odds-num { font-weight: 800; font-variant-numeric: tabular-nums; color: var(--rsl-muted); }
            .rsl-odds-bar { display: flex; height: 6px; border-radius: 3px; overflow: hidden; background: var(--rsl-border); }
            .rsl-odds-bar span { display: block; height: 100%; opacity: .85; }
            .rsl-odds-label { grid-column: 1 / -1; text-align: center; color: var(--rsl-muted); font-size: 11px; }
            .rsl-form { display: inline-flex; gap: 3px; }
            .rsl-form span { display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px; border-radius: 4px; font-size: 10px; font-weight: 800; color: #fff; }
            .rsl-form-w { background: #2e7d32; }
            .rsl-form-l { background: #b32d2e; }
            .rsl-form-d { background: #8a8f98; }
            .rsl-place { font-weight: 800; }
            .rsl-profile { display: flex; flex-wrap: wrap; align-items: flex-start; gap: 14px 24px; margin: 16px 0 4px; padding: 14px 16px; border: 1px solid var(--rsl-border); border-radius: 10px; background: linear-gradient(180deg, #f7f9fc, #fff); }
            .rsl-profile-tier { display: flex; flex-direction: column; align-items: flex-start; gap: 4px; }
            .rsl-profile-tier small { color: var(--rsl-muted); font-size: 12px; }
            .rsl-profile-facts { display: flex; flex-wrap: wrap; gap: 10px 24px; margin: 0; }
            .rsl-profile-facts dt { font-size: 11px; text-transform: uppercase; letter-spacing: .4px; color: var(--rsl-muted); font-weight: 700; }
            .rsl-profile-facts dd { margin: 2px 0 0; }
            .rsl-streak { font-weight: 700; }
            .rsl-streak.is-w { color: #2e7d32; }
            .rsl-streak.is-l { color: #b32d2e; }
            .rsl-journey { display: flex; flex-wrap: wrap; align-items: center; gap: 4px; }
            .rsl-journey small { color: var(--rsl-muted); font-size: 11px; }
            .rsl-arrow { color: var(--rsl-muted); }
            .rsl-achievements { display: flex; flex-wrap: wrap; gap: 8px; flex-basis: 100%; }
            .rsl-ach { display: inline-flex; flex-direction: column; padding: 6px 10px; border-radius: 8px; background: #fff7d6; border: 1px solid #f0d77a; line-height: 1.25; }
            .rsl-ach strong { font-size: 13px; }
            .rsl-ach small { font-size: 11px; color: #6b5a1a; }
            @media (max-width: 600px) {
                .rsl-game { flex-direction: column; align-items: stretch; gap: 6px; }
                .rsl-game-court { flex: none; }
                .rsl-score { min-width: 56px; }
                .rsl-game .rsl-badge-small { display: none; }
            }
        </style>';
    }
}
