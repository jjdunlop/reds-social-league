<?php
/**
 * Public fixtures, results and ladder pages, and the fixtures section of a
 * team's page. The plugin creates "Fixtures & Results" ([rsl_fixtures]) and
 * "League Ladder" ([rsl_ladder]).
 *
 * Privacy: teams only — never people. Only published nights are shown.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RSL_Public_Fixtures {

    const FIXTURES_PAGE_OPTION = 'rsl_fixtures_page_id';
    const LADDER_PAGE_OPTION = 'rsl_ladder_page_id';

    public function __construct() {
        add_shortcode('rsl_fixtures', array($this, 'render_fixtures'));
        add_shortcode('rsl_ladder', array($this, 'render_ladder'));
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
            return '<div class="rsl"><p class="rsl-empty">The ' . esc_html(RSL_Seasons::label($season)) . ' fixture isn\'t out yet.</p></div>' . RSL_Public::styles() . self::styles();
        }
        $labels = RSL_Fixtures::round_labels($season);
        $night = self::chosen_night($nights, isset($_GET['week']) ? sanitize_text_field(wp_unslash($_GET['week'])) : '');
        $mine = self::viewer_team_id($season);
        $today = current_time('Y-m-d');

        ob_start();
        ?>
        <div class="rsl">
            <h2 class="rsl-title"><?php echo esc_html(RSL_Seasons::label($season)); ?></h2>
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
                <?php echo self::night_games($night, $mine, $today); ?>
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

    /** One night's games, venue by venue, grouped by start time. */
    private static function night_games($night, $mine, $today) {
        $games = RSL_Fixtures::games_for_night($night->id);
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
                        <?php echo self::game_row($g, $mine, $today); ?>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </section>
            <?php
        }
        return ob_get_clean();
    }

    private static function game_row($g, $mine, $today) {
        $is_mine = $mine && in_array($mine, array(intval($g->home_team_id), intval($g->away_team_id)), true);
        $home = (object) array('name' => $g->home_name, 'slug' => $g->home_slug, 'colour' => $g->home_colour);
        $away = (object) array('name' => $g->away_name, 'slug' => $g->away_slug, 'colour' => $g->away_colour);
        $home_won = $away_won = false;
        if ($g->status === 'played') {
            $home_won = intval($g->home_sets) > intval($g->away_sets);
            $away_won = intval($g->away_sets) > intval($g->home_sets);
        } elseif ($g->status === 'forfeit') {
            $home_won = intval($g->forfeit_team_id) === intval($g->away_team_id);
            $away_won = !$home_won;
        }
        ob_start();
        ?>
        <div class="rsl-game<?php echo $is_mine ? ' is-mine' : ''; ?>">
            <div class="rsl-game-court"><?php echo esc_html($g->court_name); ?><?php if ($is_mine): ?> <span class="rsl-chip">Your game</span><?php endif; ?></div>
            <div class="rsl-game-match">
                <a class="rsl-side rsl-side-home<?php echo $home_won ? ' is-winner' : ''; ?>" href="<?php echo esc_url(RSL_Public::team_url($home)); ?>">
                    <span class="rsl-side-name"><?php echo esc_html($home->name); ?></span><?php echo RSL_Public::badge($home, 'small'); ?>
                </a>
                <div class="rsl-score">
                    <?php if ($g->status === 'played'): ?>
                        <strong><?php echo intval($g->home_sets); ?>&ndash;<?php echo intval($g->away_sets); ?></strong>
                        <small><?php echo esc_html(RSL_Fixtures::format_sets($g->sets, ' · ')); ?></small>
                    <?php elseif ($g->status === 'forfeit'): ?>
                        <strong>Forfeit</strong>
                        <small><?php echo esc_html(intval($g->forfeit_team_id) === intval($g->home_team_id) ? $g->home_name : $g->away_name); ?> forfeited</small>
                    <?php elseif ($g->night_date < $today): ?>
                        <strong class="rsl-vs">vs</strong><small>Result to come</small>
                    <?php else: ?>
                        <strong class="rsl-vs">vs</strong>
                    <?php endif; ?>
                </div>
                <a class="rsl-side rsl-side-away<?php echo $away_won ? ' is-winner' : ''; ?>" href="<?php echo esc_url(RSL_Public::team_url($away)); ?>">
                    <?php echo RSL_Public::badge($away, 'small'); ?><span class="rsl-side-name"><?php echo esc_html($away->name); ?></span>
                </a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ------------------------------------------------------------------
    // [rsl_ladder]
    // ------------------------------------------------------------------

    public function render_ladder($atts = array()) {
        $season = RSL_Seasons::current();
        $mine = self::viewer_team_id($season);
        $after = self::latest_result_label($season);
        ob_start();
        ?>
        <div class="rsl">
            <h2 class="rsl-title"><?php echo esc_html(RSL_Seasons::label($season)); ?> ladder</h2>
            <?php if ($after): ?><p class="rsl-meta">After <?php echo esc_html($after); ?></p><?php endif; ?>
            <?php foreach (RSL_Teams::DIVISIONS as $division => $label): ?>
                <?php $rows = RSL_Ratings::ladder($season, $division); ?>
                <?php if (empty($rows)) { continue; } ?>
                <h3 class="rsl-division"><?php echo esc_html($label); ?></h3>
                <?php echo $division === 'ranked' ? self::ranked_table($rows, $mine) : self::unranked_table($rows, $mine); ?>
                <?php if ($division === 'ranked'): ?>
                    <p class="rsl-note">Ranked teams are ordered by rating, worked out from every set played this season — so a win over a strong team counts for more than a win over a weaker one, and close losses count too. <strong>prov.</strong> = provisional: a new team's rating settles after its first few games. Forfeits count as a loss on the record but don't change ratings.</p>
                <?php endif; ?>
            <?php endforeach; ?>
            <p class="rsl-links"><a href="<?php echo esc_url(self::fixtures_url()); ?>">Fixtures &amp; results &rarr;</a></p>
        </div>
        <?php
        return ob_get_clean() . RSL_Public::styles() . self::styles();
    }

    private static function ranked_table($rows, $mine) {
        $draws = array_sum(array_map(function ($r) { return $r['drawn']; }, $rows)) > 0;
        ob_start();
        ?>
        <div class="rsl-table-wrap"><table class="rsl-ladder">
            <thead><tr><th class="num">#</th><th>Team</th><th class="num">Rating</th><th class="num">P</th><th class="num">W</th><?php if ($draws): ?><th class="num">D</th><?php endif; ?><th class="num">L</th><th class="num">Sets</th><th class="num" title="Places moved since last week">&plusmn;</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr<?php echo $mine === intval($r['team']->id) ? ' class="is-mine"' : ''; ?>>
                    <td class="num"><?php echo $r['position'] ? intval($r['position']) : '&ndash;'; ?></td>
                    <td><?php echo self::team_cell($r['team']); ?></td>
                    <td class="num rsl-rating"><?php if ($r['new']): ?><span class="rsl-chip">New</span><?php else: ?><?php echo intval($r['rating']); ?><?php if ($r['provisional']): ?> <abbr class="rsl-prov" title="Provisional — settles after a few games">prov.</abbr><?php endif; ?><?php endif; ?></td>
                    <td class="num"><?php echo intval($r['played']); ?></td>
                    <td class="num"><?php echo intval($r['won']); ?></td>
                    <?php if ($draws): ?><td class="num"><?php echo intval($r['drawn']); ?></td><?php endif; ?>
                    <td class="num"><?php echo intval($r['lost']); ?></td>
                    <td class="num"><?php echo intval($r['sets_for']); ?>&ndash;<?php echo intval($r['sets_against']); ?></td>
                    <td class="num"><?php echo self::move($r['move']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php
        return ob_get_clean();
    }

    private static function unranked_table($rows, $mine) {
        $draws = array_sum(array_map(function ($r) { return $r['drawn']; }, $rows)) > 0;
        ob_start();
        ?>
        <div class="rsl-table-wrap"><table class="rsl-ladder">
            <thead><tr><th class="num">#</th><th>Team</th><th class="num">P</th><th class="num">W</th><?php if ($draws): ?><th class="num">D</th><?php endif; ?><th class="num">L</th><th class="num">Sets</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr<?php echo $mine === intval($r['team']->id) ? ' class="is-mine"' : ''; ?>>
                    <td class="num"><?php echo intval($r['position']); ?></td>
                    <td><?php echo self::team_cell($r['team']); ?></td>
                    <td class="num"><?php echo intval($r['played']); ?></td>
                    <td class="num"><?php echo intval($r['won']); ?></td>
                    <?php if ($draws): ?><td class="num"><?php echo intval($r['drawn']); ?></td><?php endif; ?>
                    <td class="num"><?php echo intval($r['lost']); ?></td>
                    <td class="num"><?php echo intval($r['sets_for']); ?>&ndash;<?php echo intval($r['sets_against']); ?></td>
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

    /** "Round 4 (18 Oct)" — the latest night with a result in. */
    private static function latest_result_label($season) {
        $latest = null;
        foreach (RSL_Fixtures::games_for_season($season) as $g) {
            if (($g->status === 'played' || $g->status === 'forfeit') && ($latest === null || $g->night_date > $latest->night_date)) {
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
        $standing = '';
        foreach (RSL_Ratings::ladder($team->season, $team->division) as $r) {
            if (intval($r['team']->id) !== intval($team->id)) {
                continue;
            }
            $record = intval($r['won']) . ' won · ' . intval($r['lost']) . ' lost' . ($r['drawn'] ? ' · ' . intval($r['drawn']) . ' drawn' : '') . ' · sets ' . intval($r['sets_for']) . '–' . intval($r['sets_against']);
            if ($team->division === 'ranked') {
                $standing = $r['new']
                    ? 'New team — rated after its first game.'
                    : 'Rating ' . intval($r['rating']) . ($r['provisional'] ? ' (provisional)' : '') . ' · ' . self::ordinal($r['position']) . ' on the ladder · ' . $record;
            } elseif ($r['played']) {
                $standing = self::ordinal($r['position']) . ' on the ladder · ' . $record;
            }
        }
        ob_start();
        ?>
        <?php if ($standing): ?><p class="rsl-standing"><?php echo esc_html($standing); ?> &middot; <a href="<?php echo esc_url(self::ladder_url()); ?>">Ladder</a></p><?php endif; ?>
        <h3 class="rsl-division">Fixtures &amp; results</h3>
        <?php if (empty($games)): ?>
            <p class="rsl-empty">No fixtures out yet — they're released each Monday for the coming Sunday.</p>
        <?php else: ?>
            <div class="rsl-table-wrap"><table class="rsl-team-games">
                <tbody>
                <?php foreach ($games as $g): ?>
                    <?php
                    $is_home = intval($g->home_team_id) === intval($team->id);
                    $opp = (object) ($is_home
                        ? array('name' => $g->away_name, 'slug' => $g->away_slug, 'colour' => $g->away_colour)
                        : array('name' => $g->home_name, 'slug' => $g->home_slug, 'colour' => $g->home_colour));
                    $r = RSL_Fixtures::result_for($g, $team->id);
                    ?>
                    <tr<?php echo $g->night_date >= $today && $r['outcome'] === 'pending' ? ' class="is-next"' : ''; ?>>
                        <td><a href="<?php echo esc_url(self::fixtures_url((object) array('night_date' => $g->night_date))); ?>"><?php echo esc_html(self::short_label($labels[$g->night_id])); ?></a><br><small><?php echo esc_html(date_i18n('j M', strtotime($g->night_date))); ?></small></td>
                        <td><?php echo esc_html(RSL_Courts::pretty_time(RSL_Courts::hm($g->start_time))); ?><br><small><?php echo esc_html($g->court_name . ', ' . $g->venue_name); ?></small></td>
                        <td><a class="rsl-team-cell" href="<?php echo esc_url(RSL_Public::team_url($opp)); ?>"><?php echo RSL_Public::badge($opp, 'small'); ?><span><?php echo esc_html($opp->name); ?></span></a></td>
                        <td class="rsl-result"><?php echo self::result_label($r, $g, $today); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
        <?php
        return ob_get_clean() . self::styles();
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

    private static function styles() {
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
            .rsl-standing { margin: 12px 0 0; font-weight: 600; }
            .rsl-team-games tr.is-next td { background: #eef2f8; }
            .rsl-result { text-align: right; white-space: nowrap; }
            .rsl-res { font-weight: 700; }
            .rsl-res-win { color: #2e7d32; }
            .rsl-res-loss { color: #b32d2e; }
            .rsl-res-draw { color: var(--rsl-muted); }
            @media (max-width: 600px) {
                .rsl-game { flex-direction: column; align-items: stretch; gap: 6px; }
                .rsl-game-court { flex: none; }
                .rsl-score { min-width: 56px; }
                .rsl-game .rsl-badge-small { display: none; }
            }
        </style>';
    }
}
