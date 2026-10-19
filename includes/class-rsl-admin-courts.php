<?php
/**
 * Admin: Social League → Courts & Nights.
 *  - Venues & courts: each court's usual game-night hours; each venue's
 *    home division.
 *  - Season calendar: the season's dates and game lengths, then a grid of
 *    game nights × venues; click a night to see it as a timeline of courts
 *    and slots, change it for that night only, or copy it forward.
 * Every change posts to admin-post.php (nonce + manage_options) and
 * redirects back with a notice.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RSL_Admin_Courts {

    const PAGE = 'rsl-courts';

    public function __construct() {
        add_action('admin_menu', array($this, 'menu'), 20);
        add_action('admin_post_rsl_courts_action', array($this, 'handle'));
    }

    public function menu() {
        add_submenu_page(RSL_Admin::PAGE, 'Courts & Nights', 'Courts & Nights', 'manage_options', self::PAGE, array($this, 'render'));
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
        if (!current_user_can('manage_options') || !isset($post['rsl_nonce']) || !wp_verify_nonce($post['rsl_nonce'], 'rsl_courts_action')) {
            self::notice('error', 'Security check failed — please try again.');
            return self::url();
        }
        $do = isset($post['do']) ? sanitize_key($post['do']) : '';
        $text = function ($key) use ($post) { return isset($post[$key]) ? sanitize_text_field(wp_unslash($post[$key])) : ''; };
        $venues_url = self::url(array('tab' => 'venues'));
        $season_url = self::url(array('season' => $season));
        $night_id = isset($post['night_id']) ? intval($post['night_id']) : 0;
        $night_url = self::url(array('season' => $season, 'night' => $night_id));

        switch ($do) {
            case 'create_venue':
                $r = RSL_Courts::create_venue($text('name'), sanitize_key($text('division')));
                self::result($r, 'Venue added — now add its courts.');
                return $venues_url;
            case 'save_venue':
                RSL_Courts::update_venue(intval($post['venue_id']), array('name' => $text('name'), 'division' => sanitize_key($text('division')), 'active' => !empty($post['active'])));
                self::notice('success', 'Venue saved.');
                return $venues_url;
            case 'create_court':
                $r = RSL_Courts::create_court(intval($post['venue_id']), $text('name'), $text('start'), $text('end'));
                self::result($r, 'Court added.');
                return $venues_url;
            case 'save_court':
                $r = RSL_Courts::update_court(intval($post['court_id']), array('name' => $text('name'), 'start' => $text('start'), 'end' => $text('end'), 'active' => !empty($post['active'])));
                self::result($r, 'Court saved.');
                return $venues_url;
            case 'save_settings':
                $r = RSL_Courts::save_settings($season, array(
                    'start' => $text('start'), 'end' => $text('end'), 'weekday' => intval($post['weekday']),
                    'minutes' => isset($post['minutes']) ? array_map('intval', (array) $post['minutes']) : array(),
                ));
                if (is_wp_error($r)) {
                    self::notice('error', $r->get_error_message());
                    return $season_url;
                }
                $g = RSL_Courts::generate_nights($season);
                // Nights outside the new dates that still have fixtures, results, jobs,
                // can't-make-its or charges are kept, never deleted — say which, and why.
                $kept = array();
                foreach ($g['kept'] as $date => $why) {
                    $kept[] = date('D j M', strtotime($date)) . ' (' . implode(', ', $why) . ')';
                }
                // Week-off / two-games requests went with their removed nights: name the teams to tell.
                $dropped = array();
                foreach ($g['dropped'] as $d) {
                    $team = RSL_Teams::get($d['team_id']);
                    $dropped[] = ($team ? $team->name : 'A team') . ' (' . strtolower(RSL_Requests::TYPES[$d['type']]) . ', ' . date('D j M', strtotime($d['night_date'])) . ')';
                }
                self::notice($kept || $dropped ? 'warning' : 'success', 'Season saved: ' . count(RSL_Courts::nights($season)) . ' game nights'
                    . ($g['added'] ? ', ' . $g['added'] . ' added' : '') . ($g['removed'] ? ', ' . $g['removed'] . ' removed (outside the new dates)' : '') . '.'
                    . ($dropped ? ' Captains\' requests on removed nights went with them: ' . implode('; ', $dropped) . '. Let those teams know they can ask again for the new nights.' : '')
                    . ($kept ? ' Kept ' . count($kept) . ' night' . (count($kept) === 1 ? '' : 's') . ' outside the new dates that still '
                        . (count($kept) === 1 ? 'has' : 'have') . ' fixtures or records: ' . implode('; ', $kept)
                        . '. To remove one, unpublish and clear it on the Fixtures page (withdraw any "can\'t make it" there too), then save the dates again.' : ''));
                return $season_url;
            case 'set_type':
                $r = RSL_Courts::set_night_type($night_id, sanitize_key($text('type')), $text('note'));
                self::result($r, 'Night updated.');
                return isset($post['back']) && $post['back'] === 'night' ? $night_url : $season_url;
            case 'save_night':
                $errors = array();
                foreach ((array) (isset($post['courts']) ? $post['courts'] : array()) as $court_id => $c) {
                    $r = RSL_Courts::set_court_night($night_id, intval($court_id), !empty($c['available']),
                        isset($c['start']) ? sanitize_text_field($c['start']) : null, isset($c['end']) ? sanitize_text_field($c['end']) : null);
                    if (is_wp_error($r)) {
                        $court = RSL_Courts::get_court(intval($court_id));
                        $errors[] = ($court ? $court->name : 'A court') . ': ' . $r->get_error_message();
                    }
                }
                foreach ((array) (isset($post['venue_division']) ? $post['venue_division'] : array()) as $venue_id => $division) {
                    RSL_Courts::set_venue_night_division($night_id, intval($venue_id), sanitize_key($division));
                }
                self::notice($errors ? 'error' : 'success', $errors ? 'Saved, except — ' . implode('; ', $errors) : 'Night saved.');
                return $night_url;
            case 'copy_forward':
                $n = RSL_Courts::copy_night_forward($night_id);
                self::notice('success', $n ? 'Copied to ' . $n . ' later night' . ($n === 1 ? '' : 's') . '.' : 'There are no later nights to copy to.');
                return $night_url;
        }
        self::notice('error', 'Unknown action.');
        return self::url();
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
        return is_array($n) ? '<div class="notice notice-' . (in_array($n['type'], array('error', 'warning'), true) ? $n['type'] : 'success') . ' is-dismissible"><p>' . esc_html($n['message']) . '</p></div>' : '';
    }

    private static function fields($do, $extra = array()) {
        $html = wp_nonce_field('rsl_courts_action', 'rsl_nonce', true, false)
            . '<input type="hidden" name="action" value="rsl_courts_action"><input type="hidden" name="do" value="' . esc_attr($do) . '">';
        foreach ($extra as $k => $v) {
            $html .= '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
        }
        return $html;
    }

    // ------------------------------------------------------------------
    // Screens
    // ------------------------------------------------------------------

    public function render() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $tab = isset($_GET['tab']) && $_GET['tab'] === 'venues' ? 'venues' : 'calendar';
        $season = isset($_GET['season']) && RSL_Seasons::is_valid($_GET['season']) ? $_GET['season'] : RSL_Seasons::current();
        $night = isset($_GET['night']) ? RSL_Courts::get_night(intval($_GET['night'])) : null;
        echo '<div class="wrap rsl-courts"><h1>Courts &amp; Nights</h1>' . self::take_notice();
        echo '<nav class="nav-tab-wrapper"><a class="nav-tab' . ($tab === 'calendar' ? ' nav-tab-active' : '') . '" href="' . esc_url(self::url(array('season' => $season))) . '">Season calendar</a>'
            . '<a class="nav-tab' . ($tab === 'venues' ? ' nav-tab-active' : '') . '" href="' . esc_url(self::url(array('tab' => 'venues'))) . '">Venues &amp; courts</a></nav>';
        if ($tab === 'venues') {
            $this->render_venues();
        } elseif ($night) {
            $this->render_night($night);
        } else {
            $this->render_calendar($season);
        }
        echo self::styles() . '</div>';
    }

    private function render_venues() {
        $post = admin_url('admin-post.php');
        ?>
        <p class="description">Each court's usual game-night hours. Every game night starts from these; change a single night on the Season calendar.</p>
        <?php foreach (RSL_Courts::venues(false) as $venue): ?>
            <div class="rsl-box">
                <form method="post" action="<?php echo esc_url($post); ?>" class="rsl-inline-row">
                    <?php echo self::fields('save_venue', array('venue_id' => $venue->id)); ?>
                    <input type="text" name="name" value="<?php echo esc_attr($venue->name); ?>" class="regular-text" required>
                    <label>Usually hosts <select name="division"><?php foreach (RSL_Teams::DIVISIONS as $k => $l): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($venue->division, $k); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?></select></label>
                    <label><input type="checkbox" name="active" value="1" <?php checked($venue->active, 1); ?>> In use</label>
                    <button type="submit" class="button">Save venue</button>
                </form>
                <table class="widefat striped rsl-court-table">
                    <thead><tr><th>Court</th><th>Usual start</th><th>Usual finish</th><th>In use</th><th></th></tr></thead>
                    <tbody>
                        <?php // A form can't wrap a table row, so each row's inputs join their form by id (form="..."). ?>
                        <?php foreach (RSL_Courts::courts($venue->id, false) as $court): ?>
                            <?php $f = 'rsl-court-' . intval($court->id); ?>
                            <tr>
                                <td><form id="<?php echo $f; ?>" method="post" action="<?php echo esc_url($post); ?>"><?php echo self::fields('save_court', array('court_id' => $court->id)); ?></form>
                                    <input form="<?php echo $f; ?>" type="text" name="name" value="<?php echo esc_attr($court->name); ?>" required></td>
                                <td><input form="<?php echo $f; ?>" type="time" name="start" value="<?php echo esc_attr(RSL_Courts::hm($court->default_start)); ?>" step="300" required></td>
                                <td><input form="<?php echo $f; ?>" type="time" name="end" value="<?php echo esc_attr(RSL_Courts::hm($court->default_end)); ?>" step="300" required></td>
                                <td><input form="<?php echo $f; ?>" type="checkbox" name="active" value="1" <?php checked($court->active, 1); ?>></td>
                                <td><button form="<?php echo $f; ?>" type="submit" class="button button-small">Save</button></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php $f = 'rsl-new-court-' . intval($venue->id); ?>
                        <tr>
                            <td><form id="<?php echo $f; ?>" method="post" action="<?php echo esc_url($post); ?>"><?php echo self::fields('create_court', array('venue_id' => $venue->id)); ?></form>
                                <input form="<?php echo $f; ?>" type="text" name="name" placeholder="e.g. Court <?php echo count(RSL_Courts::courts($venue->id, false)) + 1; ?>" required></td>
                            <td><input form="<?php echo $f; ?>" type="time" name="start" value="19:00" step="300" required></td>
                            <td><input form="<?php echo $f; ?>" type="time" name="end" value="23:00" step="300" required></td>
                            <td></td>
                            <td><button form="<?php echo $f; ?>" type="submit" class="button button-small button-primary">Add court</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
        <h2>Add a venue</h2>
        <form method="post" action="<?php echo esc_url($post); ?>" class="rsl-inline-row">
            <?php echo self::fields('create_venue'); ?>
            <input type="text" name="name" placeholder="Venue name" class="regular-text" required>
            <label>Usually hosts <select name="division"><?php foreach (RSL_Teams::DIVISIONS as $k => $l): ?><option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($l); ?></option><?php endforeach; ?></select></label>
            <button type="submit" class="button button-primary">Add venue</button>
        </form>
        <?php
    }

    private function render_calendar($season) {
        $post = admin_url('admin-post.php');
        $s = RSL_Courts::settings($season);
        $nights = RSL_Courts::nights($season);
        $venues = RSL_Courts::venues();
        $weekdays = array('Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday');
        ?>
        <form method="get" class="rsl-inline-row" style="margin-top: 12px;">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE); ?>">
            <label><strong>Season</strong> <select name="season" onchange="this.form.submit()">
                <?php foreach (RSL_Seasons::choices() as $k => $l): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($season, $k); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?>
            </select></label>
        </form>

        <div class="rsl-box">
            <h2 style="margin-top: 0;">Season dates and game length</h2>
            <form method="post" action="<?php echo esc_url($post); ?>" class="rsl-inline-row"<?php echo $nights ? ' onsubmit="return confirm(\'Save these dates? Empty nights outside the new dates are removed. Nights that already have fixtures, results or charges are kept until you clear them.\');"' : ''; ?>>
                <?php echo self::fields('save_settings', array('season' => $season)); ?>
                <label>First game night <input type="date" name="start" value="<?php echo esc_attr($s['start']); ?>" required></label>
                <label>Last game night <input type="date" name="end" value="<?php echo esc_attr($s['end']); ?>" required></label>
                <label>Plays on <select name="weekday"><?php foreach ($weekdays as $i => $d): ?><option value="<?php echo $i; ?>" <?php selected($s['weekday'], $i); ?>><?php echo esc_html($d); ?>s</option><?php endforeach; ?></select></label>
                <?php foreach (RSL_Teams::DIVISIONS as $k => $l): ?>
                    <label><?php echo esc_html($l); ?> games <input type="number" name="minutes[<?php echo esc_attr($k); ?>]" value="<?php echo intval($s['minutes'][$k]); ?>" min="15" max="180" step="5" style="width: 70px;"> min</label>
                <?php endforeach; ?>
                <button type="submit" class="button button-primary"><?php echo $nights ? 'Save' : 'Save and create game nights'; ?></button>
            </form>
            <p class="description">Saving lists every game night between the dates. Nights you've already set up are kept; empty nights outside new dates are removed. A night outside them that already has fixtures, results, ref jobs, "can't make it" requests or charges is kept (marked below) until you clear it on the Fixtures page. Week-off and two-games requests on a removed night go with it.</p>
        </div>

        <?php if (empty($venues)): ?>
            <p>Add your venues and courts first, on the <a href="<?php echo esc_url(self::url(array('tab' => 'venues'))); ?>">Venues &amp; courts</a> tab.</p>
        <?php elseif ($nights): ?>
            <table class="widefat rsl-calendar">
                <thead><tr><th>Night</th><th>Type</th><?php foreach ($venues as $v): ?><th><?php echo esc_html($v->name); ?></th><?php endforeach; ?><th>Games</th></tr></thead>
                <tbody>
                    <?php foreach ($nights as $night): ?>
                        <?php
                        $plan = RSL_Courts::night_plan($night->id);
                        $totals = array();
                        // Kept when the dates changed because it still had fixtures or records.
                        $outside = $s['start'] !== '' && ($night->night_date < $s['start'] || $night->night_date > $s['end'] || intval(date('w', strtotime($night->night_date))) !== $s['weekday']);
                        // The type saves as soon as it's picked, so a published night asks first.
                        $on_change = RSL_Fixtures::is_published($night)
                            ? "if (confirm('This night\\'s fixtures are published. Change its type anyway?')) { this.form.submit(); } else { this.value = '" . esc_js($night->type) . "'; }"
                            : 'this.form.submit()';
                        ?>
                        <tr class="rsl-night-<?php echo esc_attr($night->type); ?>">
                            <td><a href="<?php echo esc_url(self::url(array('season' => $season, 'night' => $night->id))); ?>"><strong><?php echo esc_html(date('D j M', strtotime($night->night_date))); ?></strong></a>
                                <?php if ($night->note !== ''): ?><br><span class="description"><?php echo esc_html($night->note); ?></span><?php endif; ?>
                                <?php if ($outside): ?><br><span class="rsl-changed-mark" title="Kept because it still has fixtures or records. Clear it on the Fixtures page, then save the dates again to remove it.">outside the season dates</span><?php endif; ?></td>
                            <td><form method="post" action="<?php echo esc_url($post); ?>"><?php echo self::fields('set_type', array('season' => $season, 'night_id' => $night->id, 'note' => $night->note)); ?>
                                <select name="type" onchange="<?php echo esc_attr($on_change); ?>"><?php foreach (RSL_Courts::NIGHT_TYPES as $k => $l): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($night->type, $k); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?></select></form></td>
                            <?php foreach ($plan['venues'] as $v): ?>
                                <?php
                                $open = array_filter($v['courts'], function ($c) { return $c['available']; });
                                $games = array_sum(array_map(function ($c) { return count($c['slots']); }, $v['courts']));
                                $totals[$v['division']] = (isset($totals[$v['division']]) ? $totals[$v['division']] : 0) + $games;
                                $changed = $v['division_changed'] || array_filter($v['courts'], function ($c) { return $c['changed']; });
                                $starts = array_map(function ($c) { return $c['start']; }, $open);
                                $ends = array_map(function ($c) { return $c['end']; }, $open);
                                ?>
                                <td class="rsl-cell <?php echo $changed ? 'rsl-changed' : ''; ?>">
                                    <a href="<?php echo esc_url(self::url(array('season' => $season, 'night' => $night->id))); ?>">
                                        <?php if ($night->type === 'bye'): ?>
                                            <span class="description">Bye</span>
                                        <?php elseif (!$open): ?>
                                            <span class="description">No courts</span>
                                        <?php else: ?>
                                            <strong><?php echo count($open); ?> court<?php echo count($open) === 1 ? '' : 's'; ?></strong> &middot; <?php echo esc_html(RSL_Courts::pretty_time(min($starts)) . '–' . RSL_Courts::pretty_time(max($ends))); ?>
                                            <br><?php echo intval($games); ?> games &middot; <span class="rsl-div rsl-div-<?php echo esc_attr($v['division']); ?>"><?php echo esc_html(RSL_Teams::DIVISIONS[$v['division']]); ?></span>
                                        <?php endif; ?>
                                        <?php if ($changed): ?><span class="rsl-changed-mark" title="Different from the usual this night">changed</span><?php endif; ?>
                                    </a>
                                </td>
                            <?php endforeach; ?>
                            <td><?php foreach ($totals as $div => $n): ?><?php if ($n): ?><span class="rsl-div rsl-div-<?php echo esc_attr($div); ?>"><?php echo esc_html(RSL_Teams::DIVISIONS[$div]); ?> <?php echo intval($n); ?></span> <?php endif; ?><?php endforeach; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="description">Click a night to see its courts as a timeline and change that night only.</p>
        <?php endif; ?>
        <?php
    }

    private function render_night($night) {
        $post = admin_url('admin-post.php');
        $plan = RSL_Courts::night_plan($night->id);
        $back = self::url(array('season' => $night->season));
        // Timeline spans the earliest start to the latest finish across all courts.
        $lo = 24 * 60; $hi = 0;
        foreach ($plan['venues'] as $v) {
            foreach ($v['courts'] as $c) {
                $lo = min($lo, self::mins($c['start']), self::mins(RSL_Courts::hm($c['court']->default_start)));
                $hi = max($hi, self::mins($c['end']), self::mins(RSL_Courts::hm($c['court']->default_end)));
            }
        }
        $lo = intdiv($lo, 60) * 60;
        $hi = (int) ceil($hi / 60) * 60;
        $span = max(60, $hi - $lo);
        $pct = function ($hm) use ($lo, $span) { return round((self::mins($hm) - $lo) / $span * 100, 3); };
        ?>
        <p><a href="<?php echo esc_url($back); ?>">&larr; <?php echo esc_html(RSL_Seasons::label($night->season)); ?> calendar</a></p>
        <h2><?php echo esc_html(date('l j F Y', strtotime($night->night_date))); ?></h2>
        <form method="post" action="<?php echo esc_url($post); ?>" class="rsl-inline-row">
            <?php echo self::fields('set_type', array('season' => $night->season, 'night_id' => $night->id, 'back' => 'night')); ?>
            <label>This night is <select name="type"><?php foreach (RSL_Courts::NIGHT_TYPES as $k => $l): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($night->type, $k); ?>><?php echo esc_html($l); ?></option><?php endforeach; ?></select></label>
            <input type="text" name="note" value="<?php echo esc_attr($night->note); ?>" placeholder="Note (e.g. King's Birthday)" style="width: 240px;">
            <button type="submit" class="button">Save</button>
        </form>

        <?php if ($night->type === 'bye'): ?>
            <div class="notice notice-info inline"><p>This night is a <strong>bye</strong> — no games. Change the type above to set up its courts.</p></div>
        <?php else: ?>
            <form method="post" action="<?php echo esc_url($post); ?>">
                <?php echo self::fields('save_night', array('season' => $night->season, 'night_id' => $night->id)); ?>
                <?php foreach ($plan['venues'] as $v): ?>
                    <div class="rsl-box">
                        <h3 style="margin-top: 0;"><?php echo esc_html($v['venue']->name); ?>
                            <label style="font-weight: 400; margin-left: 10px;">hosts
                                <select name="venue_division[<?php echo intval($v['venue']->id); ?>]"><?php foreach (RSL_Teams::DIVISIONS as $k => $l): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($v['division'], $k); ?>><?php echo esc_html($l . ($k === $v['venue']->division ? ' (usual)' : '')); ?></option><?php endforeach; ?></select>
                            </label>
                            <span class="description" style="font-weight: 400;"> &middot; <?php echo intval($v['minutes']); ?>-minute games &middot; <?php echo intval(array_sum(array_map(function ($c) { return count($c['slots']); }, $v['courts']))); ?> games</span>
                        </h3>
                        <div class="rsl-axis" style="margin-left: 340px;">
                            <?php for ($t = $lo; $t <= $hi; $t += 60): ?>
                                <span style="left: <?php echo round(($t - $lo) / $span * 100, 3); ?>%;"><?php echo esc_html(RSL_Courts::pretty_time(sprintf('%02d:00', intdiv($t, 60)))); ?></span>
                            <?php endfor; ?>
                        </div>
                        <?php foreach ($v['courts'] as $c): ?>
                            <?php $id = intval($c['court']->id); ?>
                            <div class="rsl-court-row <?php echo $c['available'] ? '' : 'rsl-off'; ?>">
                                <div class="rsl-court-controls">
                                    <label><input type="checkbox" name="courts[<?php echo $id; ?>][available]" value="1" <?php checked($c['available']); ?>> <strong><?php echo esc_html($c['court']->name); ?></strong></label>
                                    <input type="time" name="courts[<?php echo $id; ?>][start]" value="<?php echo esc_attr($c['start']); ?>" step="300">
                                    <span>–</span>
                                    <input type="time" name="courts[<?php echo $id; ?>][end]" value="<?php echo esc_attr($c['end']); ?>" step="300">
                                    <?php if ($c['changed']): ?><span class="rsl-changed-mark" title="Usual: <?php echo esc_attr(RSL_Courts::pretty_time(RSL_Courts::hm($c['court']->default_start)) . '–' . RSL_Courts::pretty_time(RSL_Courts::hm($c['court']->default_end))); ?>">changed</span><?php endif; ?>
                                </div>
                                <div class="rsl-track">
                                    <?php foreach ($c['slots'] as $slot): ?>
                                        <span class="rsl-slot rsl-slot-<?php echo esc_attr($v['division']); ?>" style="left: <?php echo $pct($slot[0]); ?>%; width: <?php echo $pct($slot[1]) - $pct($slot[0]); ?>%;" title="<?php echo esc_attr(RSL_Courts::pretty_time($slot[0]) . '–' . RSL_Courts::pretty_time($slot[1])); ?>"><?php echo esc_html(RSL_Courts::pretty_time($slot[0])); ?></span>
                                    <?php endforeach; ?>
                                    <?php if (!$c['available']): ?><span class="rsl-track-off">Not in use this night</span><?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                <p><button type="submit" class="button button-primary">Save this night</button></p>
            </form>
            <form method="post" action="<?php echo esc_url($post); ?>" onsubmit="return confirm('Copy this night\'s courts, hours and divisions to every later night this season? Their own changes are replaced (byes and finals stay as they are).');">
                <?php echo self::fields('copy_forward', array('season' => $night->season, 'night_id' => $night->id)); ?>
                <button type="submit" class="button">Copy this setup to all later nights</button>
            </form>
        <?php endif; ?>
        <?php
    }

    private static function mins($hm) {
        list($h, $m) = array_map('intval', explode(':', $hm));
        return $h * 60 + $m;
    }

    private static function styles() {
        return '<style>
            .rsl-courts .rsl-box { background: #fff; border: 1px solid #c3c4c7; padding: 14px 16px; margin: 14px 0; max-width: 1100px; }
            .rsl-courts .rsl-inline-row { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin: 8px 0; }
            .rsl-court-table { margin-top: 10px; max-width: 760px; }
            .rsl-court-table input[type=text] { width: 140px; }
            .rsl-calendar { max-width: 1100px; margin-top: 12px; }
            .rsl-calendar td { vertical-align: top; }
            .rsl-calendar .rsl-cell a { display: block; text-decoration: none; color: inherit; }
            .rsl-calendar .rsl-cell:hover { background: #f0f6fc; }
            .rsl-night-bye td { background: #f6f7f7; color: #8a8f95; }
            .rsl-night-finals td:first-child { box-shadow: inset 4px 0 0 #b32d2e; }
            .rsl-changed { box-shadow: inset 3px 0 0 #d97706; }
            .rsl-changed-mark { display: inline-block; margin-left: 6px; padding: 0 6px; border-radius: 8px; background: #fff4e0; color: #8a4b00; font-size: 11px; }
            .rsl-div { display: inline-block; padding: 0 6px; border-radius: 8px; font-size: 11px; font-weight: 600; }
            .rsl-div-ranked { background: #e7eef8; color: #1d3d6e; }
            .rsl-div-unranked { background: #e8f5ea; color: #1e6b2a; }
            .rsl-axis { position: relative; height: 18px; border-bottom: 1px solid #dcdcde; margin-bottom: 4px; font-size: 11px; color: #646970; }
            .rsl-axis span { position: absolute; transform: translateX(-50%); }
            .rsl-court-row { display: flex; align-items: center; gap: 0; margin: 6px 0; }
            .rsl-court-controls { flex: 0 0 340px; display: flex; align-items: center; gap: 6px; }
            .rsl-court-controls label { white-space: nowrap; min-width: 90px; }
            .rsl-court-controls input[type=time] { width: 112px; }
            .rsl-track { position: relative; flex: 1 1 auto; height: 30px; background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px; }
            .rsl-slot { position: absolute; top: 3px; bottom: 3px; border-radius: 3px; font-size: 11px; line-height: 22px; padding-left: 4px; overflow: hidden; white-space: nowrap; box-sizing: border-box; border: 1px solid; }
            .rsl-slot-ranked { background: #e7eef8; border-color: #8aa4c8; color: #1d3d6e; }
            .rsl-slot-unranked { background: #e8f5ea; border-color: #8cc49a; color: #1e6b2a; }
            .rsl-off .rsl-court-controls strong { color: #8a8f95; text-decoration: line-through; }
            .rsl-track-off { position: absolute; left: 8px; top: 6px; font-size: 12px; color: #8a8f95; }
        </style>';
    }
}
