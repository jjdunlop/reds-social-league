<?php
/**
 * Admin: Social League → Fixtures. One game night at a time:
 *  - Make fixtures automatically (RSL_Pairing), or add/remove games by hand.
 *  - See why each game is there: each team's chance of winning a set, earlier meetings,
 *    double-headers, time preferences met or missed, who isn't playing.
 *  - Publish the night (fixtures go public), then enter results / forfeits.
 * Every change posts to admin-post.php (nonce + manage_options) and
 * redirects back with a notice.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RSL_Admin_Fixtures {

    const PAGE = 'rsl-fixtures';

    public function __construct() {
        add_action('admin_menu', array($this, 'menu'), 25);
        add_action('admin_post_rsl_fixtures_action', array($this, 'handle'));
    }

    public function menu() {
        add_submenu_page(RSL_Admin::PAGE, 'Fixtures', 'Fixtures', 'manage_options', self::PAGE, array($this, 'render'));
    }

    public function handle() {
        wp_safe_redirect(self::process($_POST));
        exit;
    }

    private static function url($args = array()) {
        return add_query_arg($args, admin_url('admin.php?page=' . self::PAGE));
    }

    /** One action from posted fields; returns where to go back to. Testable. */
    public static function process($post) {
        $season = isset($post['season']) && RSL_Seasons::is_valid($post['season']) ? $post['season'] : RSL_Seasons::current();
        $night_id = isset($post['night_id']) ? intval($post['night_id']) : 0;
        $back = self::url(array('season' => $season, 'night' => $night_id));
        if (!current_user_can('manage_options') || !isset($post['rsl_nonce']) || !wp_verify_nonce($post['rsl_nonce'], 'rsl_fixtures_action')) {
            self::notice('error', 'Security check failed — please try again.');
            return $back;
        }
        $night = RSL_Courts::get_night($night_id);
        if (!$night) {
            self::notice('error', 'That night no longer exists.');
            return self::url(array('season' => $season));
        }
        $do = isset($post['do']) ? sanitize_key($post['do']) : '';
        $game_id = isset($post['game_id']) ? intval($post['game_id']) : 0;
        $game = $game_id ? RSL_Fixtures::get($game_id) : null;
        if ($game_id && (!$game || intval($game->night_id) !== intval($night->id))) {
            self::notice('error', 'That game no longer exists.');
            return $back;
        }
        $locked = RSL_Fixtures::is_published($night);

        switch ($do) {
            case 'generate':
                $r = RSL_Pairing::generate($night->id);
                if (is_wp_error($r)) {
                    self::notice('error', $r->get_error_message());
                } else {
                    self::notice('success', 'Made ' . $r['created'] . ' game' . ($r['created'] === 1 ? '' : 's') . ' automatically. Check them, then publish.'
                        . ($r['notes'] ? ' Note: ' . implode(' ', $r['notes']) : ''));
                }
                return $back;
            case 'clear':
                if ($locked) {
                    self::notice('error', 'Unpublish the night before clearing it.');
                    return $back;
                }
                $n = 0;
                foreach (RSL_Fixtures::games_for_night($night->id) as $g) {
                    if ($g->status === 'scheduled' && RSL_Fixtures::delete_game($g->id)) {
                        $n++;
                    }
                }
                self::notice('success', 'Removed ' . $n . ' game' . ($n === 1 ? '' : 's') . '.');
                return $back;
            case 'publish':
                if (!RSL_Fixtures::games_for_night($night->id)) {
                    self::notice('error', 'There are no games to publish yet.');
                    return $back;
                }
                RSL_Fixtures::publish_night($night->id, true);
                self::notice('success', 'Published — the fixtures are on the public Fixtures & Results page.');
                return $back;
            case 'unpublish':
                RSL_Fixtures::publish_night($night->id, false);
                self::notice('success', 'Unpublished — the fixtures are hidden again.');
                return $back;
            case 'add_game':
                if ($locked) {
                    self::notice('error', 'Unpublish the night before changing its games.');
                    return $back;
                }
                list($court_id, $start) = array_pad(explode('|', isset($post['slot']) ? sanitize_text_field(wp_unslash($post['slot'])) : ''), 2, '');
                $r = RSL_Fixtures::add_game($night->id, array('home' => intval($post['home']), 'away' => intval($post['away']), 'court_id' => intval($court_id), 'start' => $start));
                self::result($r, 'Game added.');
                return $back;
            case 'delete_game':
                if ($locked || !$game || $game->status !== 'scheduled') {
                    self::notice('error', $locked ? 'Unpublish the night before changing its games.' : 'Clear the result before removing the game.');
                    return $back;
                }
                RSL_Fixtures::delete_game($game->id);
                self::notice('success', 'Game removed.');
                return $back;
            case 'save_result':
                if (!$game) {
                    break;
                }
                $forfeit = isset($post['forfeit']) ? intval($post['forfeit']) : 0;
                $r = $forfeit
                    ? RSL_Fixtures::record_forfeit($game->id, $forfeit, get_current_user_id())
                    : RSL_Fixtures::record_result($game->id, isset($post['sets']) ? sanitize_text_field(wp_unslash($post['sets'])) : '', get_current_user_id());
                self::result($r, 'Result saved: ' . ($forfeit ? 'forfeit' : $game->home_name . ' v ' . $game->away_name) . '.');
                return $back;
            case 'clear_result':
                if ($game) {
                    RSL_Fixtures::clear_result($game->id);
                    self::notice('success', 'Result cleared.');
                }
                return $back;
            case 'assign_ref':
                $r = RSL_Officials::claim_ref($game_id, isset($post['user_id']) ? intval($post['user_id']) : 0, get_current_user_id());
                self::result($r, 'Referee assigned.');
                return $back;
            case 'assign_supervisor':
                $r = RSL_Officials::claim_supervisor($night->id, isset($post['venue_id']) ? intval($post['venue_id']) : 0, isset($post['user_id']) ? intval($post['user_id']) : 0, get_current_user_id());
                self::result($r, 'Court supervisor assigned.');
                return $back;
            case 'unassign_job':
                $r = RSL_Officials::release(isset($post['job_id']) ? intval($post['job_id']) : 0, 0, true);
                self::result($r, 'Removed from the job (and from the pay report).');
                return $back;
            case 'handle_request':
                $req = RSL_Requests::get(isset($post['request_id']) ? intval($post['request_id']) : 0);
                if ($req && intval($req->night_id) === intval($night->id)) {
                    RSL_Requests::mark_handled($req->id, get_current_user_id());
                    self::notice('success', 'Marked as handled — the team page now says it\'s sorted.');
                }
                return $back;
        }
        self::notice('error', 'Unknown action.');
        return $back;
    }

    private static function result($r, $ok) {
        self::notice(is_wp_error($r) ? 'error' : 'success', is_wp_error($r) ? $r->get_error_message() : $ok);
    }

    private static function notice($type, $message) {
        set_transient(RSL_Admin::NOTICE_PREFIX . get_current_user_id(), array('type' => $type, 'message' => $message), 60);
    }

    private static function take_notice() {
        $key = RSL_Admin::NOTICE_PREFIX . get_current_user_id();
        $n = get_transient($key);
        delete_transient($key);
        return is_array($n) ? '<div class="notice notice-' . ($n['type'] === 'error' ? 'error' : 'success') . ' is-dismissible"><p>' . esc_html($n['message']) . '</p></div>' : '';
    }

    private static function fields($do, $season, $night_id, $extra = array()) {
        $html = wp_nonce_field('rsl_fixtures_action', 'rsl_nonce', true, false)
            . '<input type="hidden" name="action" value="rsl_fixtures_action"><input type="hidden" name="do" value="' . esc_attr($do) . '">'
            . '<input type="hidden" name="season" value="' . esc_attr($season) . '"><input type="hidden" name="night_id" value="' . intval($night_id) . '">';
        foreach ($extra as $k => $v) {
            $html .= '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
        }
        return $html;
    }

    // ------------------------------------------------------------------
    // Screen
    // ------------------------------------------------------------------

    public function render() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $season = isset($_GET['season']) && RSL_Seasons::is_valid($_GET['season']) ? $_GET['season'] : RSL_Seasons::current();
        $nights = RSL_Courts::nights($season);
        echo '<div class="wrap rsl-fx"><h1>Fixtures <span class="rsl-fx-season">' . esc_html(RSL_Seasons::label($season)) . '</span></h1>' . self::take_notice();
        if (!$nights) {
            echo '<p>No game nights yet — set the season up in <a href="' . esc_url(admin_url('admin.php?page=' . RSL_Admin_Courts::PAGE . '&season=' . $season)) . '">Courts &amp; Nights</a> first.</p></div>';
            return;
        }
        $night = null;
        if (isset($_GET['night'])) {
            $night = RSL_Courts::get_night(intval($_GET['night']));
        }
        if (!$night || $night->season !== $season) {
            $night = self::default_night($nights);
        }
        $labels = RSL_Fixtures::round_labels($season);
        $counts = array();
        foreach (RSL_Fixtures::games_for_season($season) as $g) {
            $counts[intval($g->night_id)] = (isset($counts[intval($g->night_id)]) ? $counts[intval($g->night_id)] : 0) + 1;
        }

        echo '<nav class="rsl-fx-nights">';
        foreach ($nights as $n) {
            $state = $n->type === 'bye' ? 'bye' : (RSL_Fixtures::is_published($n) ? 'published' : (!empty($counts[intval($n->id)]) ? 'draft' : 'empty'));
            $state_label = array('bye' => 'Bye', 'published' => '✓ Published', 'draft' => 'Draft', 'empty' => '—')[$state];
            echo '<a class="rsl-fx-night is-' . $state . (intval($n->id) === intval($night->id) ? ' is-current' : '') . '" href="' . esc_url(self::url(array('season' => $season, 'night' => $n->id))) . '">'
                . '<strong>' . esc_html($labels[$n->id]) . '</strong><span>' . esc_html(date_i18n('j M', strtotime($n->night_date))) . '</span><em>' . esc_html($state_label) . '</em></a>';
        }
        echo '</nav>';
        $this->render_night($season, $night, $labels);
        echo self::styles() . '</div>';
    }

    /** The next night still to publish; else the latest. */
    private static function default_night($nights) {
        $today = current_time('Y-m-d');
        foreach ($nights as $n) {
            if ($n->night_date >= $today && $n->type !== 'bye' && !RSL_Fixtures::is_published($n)) {
                return $n;
            }
        }
        return end($nights);
    }

    private function render_night($season, $night, $labels) {
        $post = admin_url('admin-post.php');
        $games = RSL_Fixtures::games_for_night($night->id);
        $published = RSL_Fixtures::is_published($night);
        $has_results = (bool) array_filter($games, function ($g) { return $g->status !== 'scheduled'; });
        $why = $games ? RSL_Pairing::explain_night($night->id) : array();
        $teams = array();
        foreach (RSL_Teams::list_for_season($season) as $t) {
            $teams[intval($t->id)] = $t;
        }
        $jobs = $published ? RSL_Officials::jobs_for_night($night->id) : array('ref' => array(), 'supervisor' => array());
        $people = RSL_Officials::people();
        $refs = array_filter($people, function ($p) { return $p->ref === 'approved'; });
        $sups = array_filter($people, function ($p) { return (int) $p->supervisor === 1; });
        // A job holder (with remove) or a quick "assign" picker.
        $job_cell = function ($job, $choices, $do, $extra) use ($season, $night, $post) {
            if ($job) {
                return esc_html($job->display_name) . ' <form method="post" action="' . esc_url($post) . '" class="rsl-fx-inline-form">'
                    . self::fields('unassign_job', $season, $night->id, array('job_id' => $job->id)) . '<button class="button-link button-link-delete" title="Remove (e.g. didn\'t turn up)">&times;</button></form>';
            }
            if (!$choices) {
                return '<span class="description">—</span>';
            }
            $html = '<form method="post" action="' . esc_url($post) . '" class="rsl-fx-assign">' . self::fields($do, $season, $night->id, $extra) . '<select name="user_id"><option value="">Needs one…</option>';
            foreach ($choices as $p) {
                $html .= '<option value="' . intval($p->user_id) . '">' . esc_html($p->display_name) . '</option>';
            }
            return $html . '</select><button class="button button-small">Assign</button></form>';
        };
        ?>
        <h2><?php echo esc_html($labels[$night->id]); ?> &middot; <?php echo esc_html(date_i18n('l j F Y', strtotime($night->night_date))); ?>
            <?php if ($published): ?><span class="rsl-fx-pill is-published">Published <?php echo esc_html(date_i18n('j M', strtotime($night->published_at))); ?></span>
            <?php elseif ($games): ?><span class="rsl-fx-pill is-draft">Draft — not public yet</span><?php endif; ?>
        </h2>

        <?php if ($night->type === 'bye'): ?>
            <p>No games — this night is a bye<?php echo $night->note !== '' ? ' (' . esc_html($night->note) . ')' : ''; ?>. Change that in Courts &amp; Nights.</p>
            <?php return; ?>
        <?php endif; ?>

        <?php
        $off = RSL_Requests::for_night($night->id, 'off', array('active', 'handled'));
        $double = RSL_Requests::for_night($night->id, 'double');
        $cant = RSL_Requests::for_night($night->id, 'cant_make', array('active', 'handled'));
        ?>
        <?php if ($cant): ?>
            <div class="notice notice-warning inline rsl-fx-cant"><p><strong>Can't make it:</strong></p><ul>
                <?php foreach ($cant as $r): ?>
                    <li><?php echo esc_html($r->team_name); ?> told us <?php echo esc_html(human_time_diff(strtotime($r->requested_at), current_time('timestamp'))); ?> ago
                        <strong><?php echo $r->late ? '(after the cut-off: they pay both teams\' fees, ' . esc_html(RSL_Payments::money(2 * RSL_Payments::settings()['game_fee'])) . ')' : '(before the cut-off: no fee for either team)'; ?></strong> —
                        <?php if ($r->status === 'handled'): ?><em>handled</em>
                        <?php else: ?>sort it out with their opponent (swap in a standby team, or give the opponent the night off), then
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="rsl-fx-inline-form"><?php echo self::fields('handle_request', $season, $night->id, array('request_id' => $r->id)); ?><button class="button button-small">Mark handled</button></form>
                        <?php endif; ?></li>
                <?php endforeach; ?>
            </ul></div>
        <?php endif; ?>
        <?php if ($off || $double): ?>
            <p class="rsl-fx-requests">
                <?php if ($off): ?><strong>Week off:</strong> <?php echo esc_html(implode(', ', array_map(function ($r) { return $r->team_name; }, $off))); ?> (left out of the fixtures). <?php endif; ?>
                <?php if ($double): ?><strong>Asked for two games:</strong> <?php echo esc_html(implode(', ', array_map(function ($r) { return $r->team_name; }, $double))); ?> (first in line for spare slots).<?php endif; ?>
            </p>
        <?php endif; ?>

        <div class="rsl-fx-actions">
            <?php if (!$published && !$has_results): ?>
                <form method="post" action="<?php echo esc_url($post); ?>"<?php echo $games ? ' onsubmit="return confirm(\'Replace this night\\\'s games with new automatic fixtures?\');"' : ''; ?>>
                    <?php echo self::fields('generate', $season, $night->id); ?>
                    <button class="button button-primary"><?php echo $games ? 'Remake automatically' : 'Make fixtures automatically'; ?></button>
                </form>
            <?php endif; ?>
            <?php if ($games && !$published): ?>
                <form method="post" action="<?php echo esc_url($post); ?>"><?php echo self::fields('publish', $season, $night->id); ?><button class="button button-primary">Publish fixtures</button></form>
            <?php elseif ($published): ?>
                <form method="post" action="<?php echo esc_url($post); ?>"><?php echo self::fields('unpublish', $season, $night->id); ?><button class="button">Unpublish</button></form>
            <?php endif; ?>
            <?php if ($games && !$published && !$has_results): ?>
                <form method="post" action="<?php echo esc_url($post); ?>" onsubmit="return confirm('Remove all of this night\'s games?');"><?php echo self::fields('clear', $season, $night->id); ?><button class="button button-link-delete">Clear all</button></form>
            <?php endif; ?>
        </div>

        <?php if (!$games): ?>
            <p class="description">No games yet. <strong>Make fixtures automatically</strong> pairs every team (Ranked by rating, Unranked by who's played whom least), puts the games on the night's courts with preferences in mind, then waits for you to check and publish.</p>
        <?php else: ?>
            <table class="widefat striped rsl-fx-games">
                <thead><tr><th>Time</th><th>Court</th><th>Home</th><th>Away</th><th title="Each team's chance of winning any one set, from the ratings">Set chance</th><th>Why</th><?php if ($published): ?><th>Ref</th><?php endif; ?><th>Result</th></tr></thead>
                <tbody>
                <?php $division = null; ?>
                <?php foreach ($games as $g): ?>
                    <?php if ($g->division !== $division): $division = $g->division; ?>
                        <tr class="rsl-fx-division"><td colspan="<?php echo $published ? 8 : 7; ?>"><?php echo esc_html(RSL_Teams::DIVISIONS[$division] . ' · ' . $g->venue_name); ?>
                            <?php if ($published): ?><span class="rsl-fx-sup">Court supervisor: <?php echo $job_cell(isset($jobs['supervisor'][intval($g->venue_id)]) ? $jobs['supervisor'][intval($g->venue_id)] : null, $sups, 'assign_supervisor', array('venue_id' => $g->venue_id)); ?></span><?php endif; ?>
                        </td></tr>
                    <?php endif; ?>
                    <?php $w = isset($why[intval($g->id)]) ? $why[intval($g->id)] : null; ?>
                    <tr>
                        <td><?php echo esc_html(RSL_Courts::pretty_time(RSL_Courts::hm($g->start_time))); ?></td>
                        <td><?php echo esc_html($g->court_name); ?></td>
                        <td><?php echo esc_html($g->home_name); ?></td>
                        <td><?php echo esc_html($g->away_name); ?></td>
                        <td><?php echo $w && $w['p'] !== null ? self::chance_bar($w['p']) : '<span class="description">—</span>'; ?></td>
                        <td class="rsl-fx-why"><?php echo $w ? self::why_chips($w, $g, $teams) : ''; ?></td>
                        <?php if ($published): ?><td class="rsl-fx-ref"><?php echo $job_cell(isset($jobs['ref'][intval($g->id)]) ? $jobs['ref'][intval($g->id)] : null, $refs, 'assign_ref', array('game_id' => $g->id)); ?></td><?php endif; ?>
                        <td class="rsl-fx-result">
                            <?php if (!$published && $g->status === 'scheduled'): ?>
                                <span class="description">Scores once published</span>
                                <form method="post" action="<?php echo esc_url($post); ?>" class="rsl-fx-inline"><?php echo self::fields('delete_game', $season, $night->id, array('game_id' => $g->id)); ?><button class="button-link button-link-delete">Remove game</button></form>
                            <?php elseif ($g->status === 'scheduled' && $night->night_date > current_time('Y-m-d')): ?>
                                <span class="description">Scores on the night</span>
                            <?php else: ?>
                            <form method="post" action="<?php echo esc_url($post); ?>">
                                <?php echo self::fields('save_result', $season, $night->id, array('game_id' => $g->id)); ?>
                                <input type="text" name="sets" value="<?php echo esc_attr(RSL_Fixtures::format_sets($g->sets)); ?>" placeholder="e.g. 25-20, 23-25, 15-12" aria-label="Set scores, home first">
                                <select name="forfeit" aria-label="Forfeit">
                                    <option value="0">Played</option>
                                    <option value="<?php echo intval($g->home_team_id); ?>" <?php selected($g->status === 'forfeit' && intval($g->forfeit_team_id) === intval($g->home_team_id)); ?>>Home forfeited</option>
                                    <option value="<?php echo intval($g->away_team_id); ?>" <?php selected($g->status === 'forfeit' && intval($g->forfeit_team_id) === intval($g->away_team_id)); ?>>Away forfeited</option>
                                </select>
                                <button class="button button-small">Save</button>
                            </form>
                            <?php if ($g->status !== 'scheduled'): ?>
                                <form method="post" action="<?php echo esc_url($post); ?>" class="rsl-fx-inline"><?php echo self::fields('clear_result', $season, $night->id, array('game_id' => $g->id)); ?><button class="button-link">Clear result</button></form>
                            <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (!empty($why['_resting'])): ?>
                <?php $unpaid = RSL_Requests::for_night($night->id, 'unpaid', array('handled')); ?>
                <p><strong>Not playing this night:</strong> <?php echo esc_html(implode(', ', array_map(function ($id) use ($teams, $off, $unpaid) {
                    return $teams[$id]->name . (isset($off[$id]) ? ' (week off)' : (isset($unpaid[$id]) ? ' (owes money — not fixtured)' : ' (bye — odd numbers or no court)'));
                }, $why['_resting']))); ?></p>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (!$published): ?>
            <?php echo $this->add_game_form($season, $night, $games, $teams); ?>
        <?php endif; ?>

        <details class="rsl-fx-help">
            <summary>How automatic fixtures are made</summary>
            <p>Every possible pairing gets a cost and the engine picks the set of games with the lowest total for the night, so it never gets stuck with a bad last pair. The unit is "a 2-to-1 matchup": a game where one team has a 67% chance of winning each set costs 1.</p>
            <ul>
                <li><strong>Close games (Ranked):</strong> from the two ratings, each team's chance of winning a set — shown in the <em>Set chance</em> column. It doesn't depend on how many sets fit in the hour. 50/50 costs nothing, 67/33 costs 1, 75/25 costs 2.25, 80/20 costs 3.2, 90/10 costs 5.8 — slightly uneven is cheap, lopsided is expensive. New teams' ratings are uncertain, so their chances are pulled towards 50/50.</li>
                <li><strong>Variety:</strong> a second meeting costs the same as a 67/33 game, so a rematch happens only when it's clearly the closest option. A third meeting costs far more than any lopsided game, so teams meet at most twice a season unless there's no alternative. Last week's opponent is never repeated; two weeks ago costs a little extra.</li>
                <li><strong>Unranked:</strong> no ratings, so it's purely who's played whom least and longest ago.</li>
                <li><strong>Odd numbers:</strong> the spare team gets a game against a team happy to double-header, or sits out (sharing that around).</li>
                <li><strong>Slots:</strong> earlier/later preferences are honoured where possible (teams that missed out get priority next time), the last slot is shared around, and spare slots become double-headers for teams that opted in — back-to-back or with a break, as they asked.</li>
            </ul>
            <p><strong>Ratings</strong> come from the points in every set (each rally is a small contest between the teams) plus a small bonus for winning sets — so a timed game that ends 1–1, a third set to 15, or a set cut off by the hour all count fairly. Forfeits never count.</p>
            <p class="description">Simulated over many 10-round seasons of timed games, this gives games about as close as pairing by ladder position, with no team meeting another more than twice.</p>
        </details>
        <?php
    }

    /** "62% · 38%" with a small bar: home's chance of winning a set, then away's. */
    private static function chance_bar($p) {
        $home = (int) round(100 * $p);
        return '<span class="rsl-fx-chance" title="Chance of winning any one set">'
            . '<span class="rsl-fx-bar"><span style="width:' . $home . '%"></span></span>'
            . $home . '% · ' . (100 - $home) . '%</span>';
    }

    private static function why_chips($w, $g, $teams) {
        $chips = array();
        if ($w['count'] === 0) {
            $chips[] = '<span class="rsl-fx-chip">First meeting</span>';
        } else {
            $chips[] = '<span class="rsl-fx-chip is-warn">' . esc_html(($w['count'] + 1) . self::nth($w['count'] + 1) . ' meeting · last ' . ($w['last'] === 1 ? 'last week' : $w['last'] . ' rounds ago')) . '</span>';
        }
        foreach ($w['double'] as $t => $double) {
            if ($double) {
                $chips[] = '<span class="rsl-fx-chip">' . esc_html($teams[$t]->name) . ' plays twice</span>';
            }
        }
        foreach ($w['time_ok'] as $t => $ok) {
            if ($ok === null) {
                continue;
            }
            $pref = $teams[$t]->pref_time === 'early' ? 'earlier' : 'later';
            $chips[] = '<span class="rsl-fx-chip ' . ($ok ? 'is-good' : 'is-warn') . '">' . esc_html($teams[$t]->name . ' wanted ' . $pref . ($ok ? ' ✓' : ' ✗')) . '</span>';
        }
        return implode(' ', $chips);
    }

    private static function nth($n) {
        return in_array($n % 100, array(11, 12, 13), true) ? 'th' : array('th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th')[$n % 10];
    }

    private function add_game_form($season, $night, $games, $teams) {
        $used = array();
        foreach ($games as $g) {
            $used[intval($g->court_id) . '|' . RSL_Courts::hm($g->start_time)] = true;
        }
        $courts = array();
        foreach (RSL_Courts::venues() as $v) {
            foreach (RSL_Courts::courts($v->id) as $c) {
                $courts[intval($c->id)] = $c->name . ', ' . $v->name;
            }
        }
        $free = array_filter(RSL_Courts::slots_for_night($night->id), function ($s) use ($used) { return !isset($used[$s['court_id'] . '|' . $s['start']]); });
        if (!$free) {
            return '<p class="description">Every slot this night is taken.</p>';
        }
        ob_start();
        ?>
        <h3>Add a game by hand</h3>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="rsl-fx-add">
            <?php echo self::fields('add_game', $season, $night->id); ?>
            <select name="slot" required aria-label="Slot">
                <option value="">Slot…</option>
                <?php foreach ($free as $s): ?>
                    <option value="<?php echo esc_attr($s['court_id'] . '|' . $s['start']); ?>"><?php echo esc_html(RSL_Courts::pretty_time($s['start']) . ' · ' . (isset($courts[$s['court_id']]) ? $courts[$s['court_id']] : '') . ' (' . RSL_Teams::DIVISIONS[$s['division']] . ')'); ?></option>
                <?php endforeach; ?>
            </select>
            <?php foreach (array('home' => 'Home', 'away' => 'Away') as $field => $label): ?>
                <select name="<?php echo esc_attr($field); ?>" required aria-label="<?php echo esc_attr($label); ?>">
                    <option value=""><?php echo esc_html($label); ?>…</option>
                    <?php foreach (RSL_Teams::DIVISIONS as $division => $dlabel): ?>
                        <optgroup label="<?php echo esc_attr($dlabel); ?>">
                            <?php foreach ($teams as $t): ?>
                                <?php if ($t->division === $division): ?><option value="<?php echo intval($t->id); ?>"><?php echo esc_html($t->name); ?></option><?php endif; ?>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            <?php endforeach; ?>
            <button class="button">Add game</button>
        </form>
        <?php
        return ob_get_clean();
    }

    private static function styles() {
        return '<style>
            .rsl-fx-season { font-size: 14px; font-weight: 400; color: #646970; margin-left: 6px; }
            .rsl-fx-nights { display: flex; gap: 6px; flex-wrap: wrap; margin: 12px 0 4px; }
            .rsl-fx-night { display: flex; flex-direction: column; align-items: center; min-width: 78px; padding: 6px 8px; border: 1px solid #c3c4c7; border-radius: 6px; background: #fff; text-decoration: none; color: #1d2327; line-height: 1.3; }
            .rsl-fx-night span { font-size: 12px; color: #646970; }
            .rsl-fx-night em { font-style: normal; font-size: 11px; color: #646970; }
            .rsl-fx-night.is-published em { color: #00a32a; font-weight: 600; }
            .rsl-fx-night.is-draft em { color: #996800; font-weight: 600; }
            .rsl-fx-night.is-bye { background: #f6f7f7; }
            .rsl-fx-night.is-current { border-color: #2271b1; box-shadow: 0 0 0 1px #2271b1; }
            .rsl-fx-pill { display: inline-block; margin-left: 8px; padding: 2px 10px; border-radius: 10px; font-size: 12px; font-weight: 600; vertical-align: middle; }
            .rsl-fx-pill.is-published { background: #edfaef; color: #00762a; }
            .rsl-fx-pill.is-draft { background: #fcf9e8; color: #8a6100; }
            .rsl-fx-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin: 10px 0 14px; }
            .rsl-fx-actions form { margin: 0; }
            .rsl-fx-games td { vertical-align: middle; }
            .rsl-fx-division td { background: #f0f0f1 !important; font-weight: 600; }
            .rsl-fx-chance { display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; font-variant-numeric: tabular-nums; }
            .rsl-fx-bar { display: inline-block; width: 46px; height: 6px; border-radius: 3px; background: #dcdcde; overflow: hidden; }
            .rsl-fx-bar span { display: block; height: 100%; background: #2271b1; }
            .rsl-fx-chip { display: inline-block; margin: 1px 0; padding: 1px 7px; border-radius: 9px; font-size: 11px; background: #f0f0f1; color: #3c434a; white-space: nowrap; }
            .rsl-fx-chip.is-good { background: #edfaef; color: #00762a; }
            .rsl-fx-chip.is-warn { background: #fcf0e8; color: #8a4600; }
            .rsl-fx-result form { display: flex; gap: 4px; align-items: center; margin: 0; }
            .rsl-fx-result input[type=text] { width: 150px; }
            .rsl-fx-result .rsl-fx-inline { margin-top: 2px; }
            .rsl-fx-sup { float: right; font-weight: 400; }
            .rsl-fx-assign { display: inline-flex; gap: 4px; align-items: center; margin: 0; }
            .rsl-fx-assign select { max-width: 150px; }
            .rsl-fx-ref { white-space: nowrap; }
            .rsl-fx-cant ul { margin: 0 0 8px 18px; list-style: disc; }
            .rsl-fx-inline-form { display: inline; }
            .rsl-fx-requests { background: #fff; border-left: 4px solid #72aee6; padding: 8px 12px; max-width: 900px; }
            .rsl-fx-add { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
            .rsl-fx-help { margin-top: 24px; max-width: 900px; }
            .rsl-fx-help summary { cursor: pointer; font-weight: 600; }
        </style>';
    }
}
