<?php
/**
 * Admin: Social League → Teams. Every change posts to admin-post.php
 * (nonce + manage_options), then redirects back with a one-off notice —
 * Post/Redirect/Get, so a refresh never repeats an action.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RSL_Admin {

    const PAGE = 'rsl-teams';
    const NOTICE_PREFIX = 'rsl_notice_';

    public function __construct() {
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_post_rsl_team_action', array($this, 'handle'));
    }

    const REG_PAGE = 'rsl-registration';

    public function menu() {
        $pending = count(RSL_Teams::pending_applications());
        $bubble = $pending ? ' <span class="awaiting-mod">' . intval($pending) . '</span>' : '';
        add_menu_page('Social League', 'Social League' . $bubble, 'manage_options', self::PAGE, array($this, 'render'), 'dashicons-groups', 33);
        add_submenu_page(self::PAGE, 'Teams', 'Teams' . $bubble, 'manage_options', self::PAGE, array($this, 'render'));
        add_submenu_page(self::PAGE, 'Registration', 'Registration', 'manage_options', self::REG_PAGE, array($this, 'render_registration'));
    }

    // ------------------------------------------------------------------
    // Actions
    // ------------------------------------------------------------------

    /** admin-post.php?action=rsl_team_action — process, then redirect back. */
    public function handle() {
        $redirect = self::process($_POST);
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Do one action from posted fields; returns the URL to go back to.
     * Separate from handle() (which exits) so it can be tested.
     */
    public static function process($post) {
        $season = isset($post['season']) ? sanitize_text_field(wp_unslash($post['season'])) : RSL_Seasons::current();
        $back = admin_url('admin.php?page=' . self::PAGE . '&season=' . rawurlencode($season));

        if (!current_user_can('manage_options') || !isset($post['rsl_nonce']) || !wp_verify_nonce($post['rsl_nonce'], 'rsl_team_action')) {
            self::notice('error', 'Security check failed — please try again.');
            return $back;
        }

        $do = isset($post['do']) ? sanitize_key($post['do']) : '';
        $team_id = isset($post['team_id']) ? intval($post['team_id']) : 0;
        $team_back = $team_id ? add_query_arg('team', $team_id, $back) : $back;

        switch ($do) {
            case 'set_season':
                if (RSL_Seasons::set_current($season)) {
                    self::notice('success', RSL_Seasons::label($season) . ' is now the current season on the public pages.');
                }
                return $back;

            case 'create_team':
                $posted = isset($post['division']) ? sanitize_key($post['division']) : 'ranked';
                $before = self::division_info($season, $posted);
                $team = RSL_Teams::create($season, array(
                    'name' => isset($post['name']) ? wp_unslash($post['name']) : '',
                    'division' => isset($post['division']) ? sanitize_key($post['division']) : 'ranked',
                    'colour' => isset($post['colour']) ? wp_unslash($post['colour']) : '',
                    'level' => isset($post['level']) ? sanitize_key($post['level']) : '',
                ));
                if (is_wp_error($team)) {
                    self::notice('error', $team->get_error_message());
                    return $back;
                }
                // Over the division's places is allowed (0.19.0); the notice says what it costs.
                self::notice('success', self::places_words('Team "' . $team->name . '" created.', $team, $before));
                return add_query_arg('team', $team->id, $back);

            case 'update_team':
                $was = RSL_Teams::get($team_id);
                $to = isset($post['division']) ? sanitize_key($post['division']) : '';
                $before = $was && $to !== $was->division ? self::division_info($was->season, $to) : null;
                $team = RSL_Teams::update($team_id, array(
                    'name' => isset($post['name']) ? wp_unslash($post['name']) : '',
                    'division' => isset($post['division']) ? sanitize_key($post['division']) : '',
                    'colour' => isset($post['colour']) ? wp_unslash($post['colour']) : '',
                ));
                if (!is_wp_error($team) && !empty($post['level'])) {
                    RSL_Teams::set_level($team_id, sanitize_key($post['level']));
                }
                if (is_wp_error($team)) {
                    self::notice('error', $team->get_error_message());
                    return $team_back;
                }
                $saved = 'Team saved.';
                if ($was && $was->division !== $team->division) {
                    // Moved into a division (0.19.0): over its places is allowed, and the notice says what it costs;
                    // an application moved into a full one is on the waiting list there, and one that takes a place
                    // another application was in line for says so.
                    $saved = self::places_words($saved, $team, $before, $team->status === 'pending');
                }
                self::notice('success', $saved);
                return $team_back;

            case 'add_member':
                $who = isset($post['who']) ? trim(sanitize_text_field(wp_unslash($post['who']))) : '';
                $user = is_email($who) ? get_user_by('email', $who) : get_user_by('login', $who);
                if (!$user) {
                    self::notice('error', 'No account found for "' . $who . '". Use their account email or username.');
                    return $team_back;
                }
                $role = isset($post['role']) ? sanitize_key($post['role']) : 'player';
                // Admins can go past the roster maximum.
                $result = RSL_Teams::add_member($team_id, $user->ID, $role, false, array('admin' => true, 'how' => 'admin'));
                self::notice(is_wp_error($result) ? 'error' : 'success', is_wp_error($result) ? $result->get_error_message() : $user->display_name . ' added to the team.');
                return $team_back;

            case 'approve':
            case 'decline':
                $mail_flag = 'rsl_mail_failed_' . get_current_user_id();
                delete_transient($mail_flag);
                // Approving one ahead of older applications can put one of them on the waiting list (0.19.0).
                $app = $do === 'approve' ? RSL_Teams::get($team_id) : null;
                $before = $app && $app->status === 'pending' ? self::division_info($app->season, $app->division) : null;
                $result = $do === 'approve' ? RSL_Teams::approve($team_id) : RSL_Teams::decline($team_id, isset($post['note']) ? wp_unslash($post['note']) : '');
                $unsent = get_transient($mail_flag);
                delete_transient($mail_flag);
                if (is_wp_error($result)) {
                    self::notice('error', $result->get_error_message());
                } elseif ($unsent !== false) {
                    $words = $result->name . ($do === 'approve' ? ' approved' : ' declined') . ', but the email to the captain couldn\'t be sent — ' . ($do === 'approve' ? 'send them the invite link yourself.' : 'let them know yourself.');
                    self::notice('error', $do === 'approve' ? self::places_words($words, $result, $before, true) : $words);
                } else {
                    $words = $result->name . ($do === 'approve' ? ' approved — the captain has been emailed their invite link.' : ' declined — the captain has been emailed.');
                    // Approving over the places takes no extra step (0.19.0); the notice repeats the numbers.
                    self::notice('success', $do === 'approve' ? self::places_words($words, $result, $before, true) : $words);
                }
                return $back;

            case 'save_rego_product':
                $year_id = isset($post['product_id']) ? intval($post['product_id']) : 0;
                // Only when posted: a page opened before 0.15.0 doesn't clear it.
                $season_posted = isset($post['season_product_id']);
                $season_id = $season_posted ? intval($post['season_product_id']) : RSL_Rego::season_product_id();
                $topup_id = RSL_Payments::product_id();
                if ($year_id && $year_id === $season_id) {
                    self::notice('error', 'The season and yearly regos need different products.');
                } elseif ($topup_id && ($year_id === $topup_id || $season_id === $topup_id)) {
                    // Its past orders would be read as regos by the scan.
                    self::notice('error', 'The team top-up product can\'t be a rego product.');
                } else {
                    update_option(RSL_Rego::PRODUCT_OPTION, $year_id, true);
                    if ($season_posted) {
                        update_option(RSL_Rego::SEASON_PRODUCT_OPTION, $season_id, true);
                    }
                    self::notice('success', 'Rego products saved.');
                }
                return admin_url('admin.php?page=' . self::REG_PAGE);

            case 'scan_regos':
                $added = RSL_Rego::scan_orders();
                self::notice('success', $added ? $added . ' rego(s) picked up from past orders.' : 'No new rego purchases found in the last 13 months.');
                return admin_url('admin.php?page=' . self::REG_PAGE);

            case 'grant_rego':
                $who = isset($post['who']) ? trim(sanitize_text_field(wp_unslash($post['who']))) : '';
                $user = is_email($who) ? get_user_by('email', $who) : get_user_by('login', $who);
                // Normalised as grant() reads it: a Y-m-d, else today (never a string compared with dates).
                $start = isset($post['start']) ? sanitize_text_field(wp_unslash($post['start'])) : '';
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
                    $start = current_time('Y-m-d');
                }
                // 'kind', not 'season': fields() already posts the screen's season.
                $kind = isset($post['kind']) ? sanitize_key(wp_unslash($post['kind'])) : '';
                $note = isset($post['note']) ? sanitize_text_field(wp_unslash($post['note'])) : '';
                $back_reg = admin_url('admin.php?page=' . self::REG_PAGE);
                if (!$user) {
                    self::notice('error', 'No account found for "' . $who . '".');
                    return $back_reg;
                }
                if ($kind === '' || $kind === 'year') {
                    $id = RSL_Rego::grant($user->ID, array('start' => $start, 'source' => 'manual', 'note' => $note));
                    $g = $id ? RSL_Rego::get($id) : null;
                    if (!$g) {
                        self::notice('error', 'That 12-month rego couldn\'t be added — please try again.');
                    } else {
                        self::notice('success', '12-month rego added for ' . $user->display_name
                            . ' (' . date('j M Y', strtotime($g->start_date)) . ' – ' . date('j M Y', strtotime($g->end_date)) . ').');
                    }
                    return $back_reg;
                }
                // Any season from this year to two years ahead (finished ones too, to record a payment after the
                // fact), as well as the seasons open for sign-up. No "already covered" refusal:
                // the league may be recording something it knows about.
                $allowed = RSL_Seasons::open_for_signup() + RSL_Seasons::choices();
                if (!RSL_Seasons::is_valid($kind) || !isset($allowed[$kind])) {
                    self::notice('error', 'Pick 12 months or a season.');
                    return $back_reg;
                }
                $span = RSL_Seasons::span($kind);
                if (max($start, $span['start']) > $span['end']) {
                    self::notice('error', RSL_Seasons::short_label($kind) . ' ended on ' . date('j M Y', strtotime($span['end'])) . ', before the start date. Give an earlier start date or pick another season.');
                    return $back_reg;
                }
                $id = RSL_Rego::grant($user->ID, array('start' => $start, 'source' => 'manual', 'season' => $kind, 'note' => $note));
                $g = $id ? RSL_Rego::get($id) : null;
                if (!$g) {
                    self::notice('error', 'That season rego couldn\'t be added — please try again.');
                } else {
                    self::notice('success', 'Season rego for ' . RSL_Seasons::short_label($kind) . ' added for ' . $user->display_name
                        . ' (' . date('j M Y', strtotime($g->start_date)) . ' – ' . date('j M Y', strtotime($g->end_date)) . ').');
                }
                return $back_reg;

            case 'remove_rego':
                $result = RSL_Rego::revoke(isset($post['grant_id']) ? intval($post['grant_id']) : 0, 'Removed by the league');
                self::notice(is_wp_error($result) ? 'error' : 'success', is_wp_error($result) ? $result->get_error_message() : 'Rego removed — it no longer covers them from today.');
                return admin_url('admin.php?page=' . self::REG_PAGE);

            case 'save_about_page':
                // The season preview's How the league works button (0.18.0): a published page, or none.
                $page_id = isset($post['about_page_id']) ? intval($post['about_page_id']) : 0;
                if ($page_id && (get_post_type($page_id) !== 'page' || get_post_status($page_id) !== 'publish')) {
                    self::notice('error', 'Pick a published page for How the league works.');
                    return $back . '#rsl-league-pages';
                }
                update_option(RSL_Public::ABOUT_OPTION, $page_id, true);
                self::notice('success', $page_id
                    ? 'The season preview\'s How the league works button now goes to ' . get_post_field('post_title', $page_id) . '.'
                    : 'The season preview has no How the league works button now.');
                return $back . '#rsl-league-pages';

            case 'remove_member':
            case 'make_captain':
                $user_id = isset($post['user_id']) ? intval($post['user_id']) : 0;
                $user = get_userdata($user_id);
                $result = $do === 'remove_member' ? RSL_Teams::remove_member($team_id, $user_id) : RSL_Teams::set_captain($team_id, $user_id);
                $done = $do === 'remove_member' ? ' removed from the team.' : ' is now captain.';
                self::notice(is_wp_error($result) ? 'error' : 'success', is_wp_error($result) ? $result->get_error_message() : ($user ? $user->display_name : 'Player') . $done);
                return $team_back;
        }

        self::notice('error', 'Unknown action.');
        return $back;
    }

    private static function notice($type, $message) {
        set_transient(self::NOTICE_PREFIX . get_current_user_id(), array('type' => $type, 'message' => $message), 60);
    }

    /** One division's places now (RSL_Capacity::season()), or null for a division that isn't one. */
    private static function division_info($season, $division) {
        if (!isset(RSL_Teams::DIVISIONS[$division])) {
            return null;
        }
        $all = RSL_Capacity::season($season);
        return $all['divisions'][$division];
    }

    /**
     * A notice's words after a change made $team active in its division or moved it there
     * (0.19.0), from one read of the places: the sit-out numbers when an active team's division
     * is now over (RSL_Capacity::over_line(): "… Ranked, 2027 Mid-season 2 now has 31 teams for
     * 30 places: about 2.3 nights each sat out this season, so some sit out 3."); for an
     * application moved there, whether it's on the waiting list; and, against $before (the
     * division read before the change), how many applications it put on the waiting list
     * (RSL_Capacity::bumped_words(); $other: the change was about an application itself).
     * Never names another team.
     */
    private static function places_words($words, $team, $before = null, $other = false) {
        if (!$team || !is_object($team)) {
            return $words;
        }
        $info = self::division_info($team->season, $team->division);
        if (!$info) {
            return $words;
        }
        if ($team->status === 'active') {
            $over = RSL_Capacity::over_line($info);
            $words .= $over !== '' ? ' ' . $over : '';
        } elseif ($team->status === 'pending' && $before && in_array(intval($team->id), $info['waiting'], true)) {
            $words .= ' Its application is on the waiting list in ' . $info['label'] . '.';
        }
        $moved = $before ? count(RSL_Capacity::bumped($before, $info, $team->id)) : 0;
        return $moved ? $words . ' ' . RSL_Capacity::bumped_words($info, $moved, $other) : $words;
    }

    private static function take_notice() {
        $key = self::NOTICE_PREFIX . get_current_user_id();
        $notice = get_transient($key);
        delete_transient($key);
        if (!is_array($notice)) {
            return '';
        }
        return '<div class="notice notice-' . esc_attr($notice['type'] === 'error' ? 'error' : 'success') . ' is-dismissible"><p>' . esc_html($notice['message']) . '</p></div>';
    }

    // ------------------------------------------------------------------
    // Screens
    // ------------------------------------------------------------------

    public function render() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $season = isset($_GET['season']) && RSL_Seasons::is_valid($_GET['season']) ? $_GET['season'] : RSL_Seasons::current();
        $team = isset($_GET['team']) ? RSL_Teams::get(intval($_GET['team'])) : null;

        echo '<div class="wrap rsl-admin">';
        echo $team ? '<h1>' . esc_html($team->name) . '</h1>' : '<h1>Social League — Teams</h1>';
        echo self::take_notice();
        if ($team) {
            $this->render_team($team);
        } else {
            $this->render_list($season);
        }
        echo '</div>';
    }

    private static function fields($do, $season, $team_id = 0) {
        $html = wp_nonce_field('rsl_team_action', 'rsl_nonce', true, false)
            . '<input type="hidden" name="action" value="rsl_team_action">'
            . '<input type="hidden" name="do" value="' . esc_attr($do) . '">'
            . '<input type="hidden" name="season" value="' . esc_attr($season) . '">';
        if ($team_id) {
            $html .= '<input type="hidden" name="team_id" value="' . intval($team_id) . '">';
        }
        return $html;
    }

    private function render_list($season) {
        $teams = RSL_Teams::list_for_season($season);
        $current = RSL_Seasons::current();
        $post_url = admin_url('admin-post.php');
        ?>
        <form method="get" style="margin: 12px 0; display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE); ?>">
            <label for="rsl-season"><strong>Season</strong></label>
            <select id="rsl-season" name="season" onchange="this.form.submit()">
                <?php foreach (RSL_Seasons::choices() as $key => $label): ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($season, $key); ?>><?php echo esc_html($label . ($key === $current ? ' — current' : '')); ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php if ($season !== $current): ?>
            <form method="post" action="<?php echo esc_url($post_url); ?>" style="margin-bottom: 12px;">
                <?php echo self::fields('set_season', $season); ?>
                <button type="submit" class="button">Make <?php echo esc_html(RSL_Seasons::label($season)); ?> the current season</button>
                <span class="description">The public team pages show the current season (<?php echo esc_html(RSL_Seasons::label($current)); ?>).</span>
            </form>
        <?php endif; ?>

        <?php $applications = RSL_Teams::pending_applications(); ?>
        <?php if ($applications): ?>
            <?php
            // Places in each season and division with applications (RSL_Capacity, 0.19.0): one read for them all.
            $lines = array();
            foreach ($applications as $app) {
                $lines[$app->season][$app->division] = true;
            }
            $places = RSL_Capacity::seasons(array_keys($lines));
            uksort($lines, function ($a, $b) { return RSL_Seasons::ordinal($a) - RSL_Seasons::ordinal($b); });
            // The words under the lines describe only what they show: places worked out from the nights, set by hand, or none checked.
            $worked = false;
            $by_hand = false;
            foreach ($lines as $s_key => $divs_with) {
                foreach (array_keys($divs_with) as $d) {
                    $i = isset($places[$s_key]['divisions'][$d]) ? $places[$s_key]['divisions'][$d] : null;
                    if ($i && $i['places'] !== null) {
                        $by_hand = $by_hand || !empty($i['set_by_hand']);
                        $worked = $worked || empty($i['set_by_hand']);
                    }
                }
            }
            $allowance = RSL_Capacity::allowance();
            $about = '';
            if ($worked) {
                $about .= 'A division\'s places are the most teams its game slots fit with no team sitting out more than ' . $allowance . ' night' . ($allowance === 1 ? '' : 's') . ' in the season (set on Free Agents → Settings). ';
            }
            if ($by_hand) {
                $about .= $worked ? 'A line marked set by hand takes its places from Most teams per division instead. ' : 'Places marked set by hand come from Most teams per division (Free Agents → Settings). ';
            }
            if ($worked || $by_hand) {
                $about .= 'Applications past the places left are on the waiting list, oldest first. You can still approve one over the places: the row says how many nights teams would sit out.';
            }
            ?>
            <h2>Team applications (<?php echo count($applications); ?>)</h2>
            <ul class="rsl-places">
                <?php foreach ($lines as $s_key => $divs_with): ?>
                    <?php foreach (array_keys(RSL_Teams::DIVISIONS) as $d): ?>
                        <?php if (isset($divs_with[$d])): ?><li><?php echo esc_html(RSL_Capacity::places_line($places[$s_key]['divisions'][$d])); ?></li><?php endif; ?>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </ul>
            <?php if ($about !== ''): ?><p class="description"><?php echo esc_html($about); ?></p><?php endif; ?>
            <table class="widefat striped" style="max-width: 1100px;">
                <thead><tr><th style="width: 52px;"></th><th>Team</th><th>Season</th><th>Division</th><th>Level (their guess)</th><th>Captain</th><th>Applied</th><th>Decision</th></tr></thead>
                <tbody>
                    <?php foreach ($applications as $app): ?>
                        <?php
                        $info = isset($places[$app->season]['divisions'][$app->division]) ? $places[$app->season]['divisions'][$app->division] : null;
                        $waiting = $info && in_array(intval($app->id), $info['waiting'], true);
                        // Approving puts the division over its places once its active teams fill them.
                        $over = $info && $info['places'] !== null && $info['active'] >= $info['places'];
                        ?>
                        <tr>
                            <td><?php echo RSL_Public::badge($app); ?></td>
                            <td><strong><?php echo esc_html($app->name); ?></strong><?php if ($waiting): ?><br><span class="description">On the waiting list</span><?php endif; ?></td>
                            <td><?php echo esc_html(RSL_Seasons::label($app->season)); ?></td>
                            <td><?php echo esc_html(RSL_Teams::DIVISIONS[$app->division]); ?></td>
                            <td><?php echo esc_html(isset(RSL_Teams::levels()[$app->est_level]) ? RSL_Teams::levels()[$app->est_level] : '—'); ?></td>
                            <td><?php echo esc_html($app->captain_name); ?><br><span class="description"><?php echo esc_html($app->captain_email); ?></span></td>
                            <td><?php echo esc_html(date('j M Y', strtotime($app->applied_at))); ?></td>
                            <td>
                                <?php if ($over): ?>
                                    <p class="description" style="margin-top: 0;"><?php echo esc_html('Approving makes ' . RSL_Capacity::over_words($info, $info['active'] + 1) . '.'); ?></p>
                                <?php endif; ?>
                                <form method="post" action="<?php echo esc_url($post_url); ?>" style="display: inline;">
                                    <?php echo self::fields('approve', $app->season, $app->id); ?>
                                    <button type="submit" class="button button-primary"><?php echo $over ? 'Approve over the cap' : 'Approve'; ?></button>
                                </form>
                                <form method="post" action="<?php echo esc_url($post_url); ?>" style="display: inline-flex; gap: 4px; margin-left: 6px;">
                                    <?php echo self::fields('decline', $app->season, $app->id); ?>
                                    <input type="text" name="note" placeholder="Reason (emailed to the captain)" style="width: 220px;">
                                    <button type="submit" class="button">Decline</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h2>Add a team</h2>
        <form method="post" action="<?php echo esc_url($post_url); ?>" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <?php echo self::fields('create_team', $season); ?>
            <input type="text" name="name" placeholder="Team name" required maxlength="100" class="regular-text">
            <select name="division">
                <?php foreach (RSL_Teams::DIVISIONS as $key => $label): ?>
                    <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="level">
                <option value="">Level (estimate)…</option>
                <?php foreach (RSL_Teams::levels() as $key => $label): ?><option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option><?php endforeach; ?>
            </select>
            <label>Colour <input type="color" name="colour" value="#1d3d6e"></label>
            <button type="submit" class="button button-primary">Add team</button>
        </form>

        <h2><?php echo esc_html(RSL_Seasons::label($season)); ?> (<?php echo count($teams); ?> teams)</h2>
        <?php if (empty($teams)): ?>
            <p class="description">No teams in this season yet.</p>
        <?php else: ?>
            <table class="widefat striped" style="max-width: 900px;">
                <thead><tr><th style="width: 52px;"></th><th>Team</th><th>Division</th><th>Level (estimate)</th><th>Players</th><th>Captain</th></tr></thead>
                <tbody>
                    <?php foreach ($teams as $team): ?>
                        <?php $captain = RSL_Teams::captain($team->id); ?>
                        <tr>
                            <td><?php echo RSL_Public::badge($team); ?></td>
                            <td><a href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE . '&season=' . rawurlencode($season) . '&team=' . intval($team->id))); ?>"><strong><?php echo esc_html($team->name); ?></strong></a>
                                <div class="row-actions"><a href="<?php echo esc_url(RSL_Public::team_url($team)); ?>" target="_blank">View public page</a></div></td>
                            <td><?php echo esc_html(RSL_Teams::DIVISIONS[$team->division]); ?></td>
                            <td><?php echo isset(RSL_Teams::levels()[$team->est_level]) ? esc_html(strtok(RSL_Teams::levels()[$team->est_level], '—')) : '<span class="description">Not set</span>'; ?></td>
                            <td><?php echo intval($team->member_count); ?></td>
                            <td><?php echo $captain ? esc_html($captain->display_name) : '<span class="description">None yet</span>'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h2 id="rsl-league-pages">League pages</h2>
        <?php
        $made = array();
        $own = array(
            RSL_Portal::PAGE_OPTION => 'Social League',
            RSL_Public::PAGE_OPTION => 'League Teams',
            RSL_Public_Fixtures::FIXTURES_PAGE_OPTION => 'Fixtures & Results',
            RSL_Public_Fixtures::LADDER_PAGE_OPTION => 'League Ladder',
            RSL_Ref_Page::PAGE_OPTION => 'Ref Jobs',
        );
        foreach ($own as $opt => $name) {
            $pid = intval(get_option($opt));
            if ($pid && get_post_status($pid) === 'publish') {
                $title = (string) get_post_field('post_title', $pid);
                $made[] = '<a href="' . esc_url(get_permalink($pid)) . '" target="_blank">' . esc_html($title !== '' ? $title : $name) . '</a>';
            }
        }
        // A page chosen before that's no longer a published page isn't in the picker (it lists
        // published pages only), so the picker shows None: say so, or a save would clear it unseen.
        $about_id = intval(get_option(RSL_Public::ABOUT_OPTION));
        $about_lost = $about_id && RSL_Public::about_url() === '';
        $about_title = $about_lost ? (string) get_post_field('post_title', $about_id) : '';
        ?>
        <p class="description">The pages the league made for itself: <?php echo $made ? implode(', ', $made) . '.' : 'none yet.'; ?></p>
        <form method="post" action="<?php echo esc_url($post_url); ?>" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <?php echo self::fields('save_about_page', $season); ?>
            <label for="rsl-about-page"><strong>How the league works page</strong></label>
            <?php echo wp_dropdown_pages(array('name' => 'about_page_id', 'id' => 'rsl-about-page', 'selected' => $about_id,
                'show_option_none' => '— None (no button) —', 'option_none_value' => '0', 'echo' => 0)); ?>
            <button type="submit" class="button">Save</button>
        </form>
        <?php if ($about_lost): ?>
            <p class="description">The page chosen before<?php echo $about_title !== '' ? ' (' . esc_html($about_title) . ')' : ''; ?> isn't published, so the button is hidden.</p>
        <?php endif; ?>
        <p class="description">Until the current season's first fixtures are published, the League Teams, League Ladder and Fixtures &amp; Results pages show a season preview: when the season starts, teams in so far, the tiers and the free agent dates, with a Register button (to the Social League page) and a How the league works button to the page chosen here. With none chosen, that button isn't shown.</p>
        <?php echo self::badge_styles(); ?>
        <?php
    }

    private function render_team($team) {
        $members = RSL_Teams::members($team->id);
        $post_url = admin_url('admin-post.php');
        $list = admin_url('admin.php?page=' . self::PAGE . '&season=' . rawurlencode($team->season));
        ?>
        <p><a href="<?php echo esc_url($list); ?>">&larr; All <?php echo esc_html(RSL_Seasons::label($team->season)); ?> teams</a>
            &middot; <a href="<?php echo esc_url(RSL_Public::team_url($team)); ?>" target="_blank">View public page</a></p>
        <?php if ($team->status !== 'active'): ?>
            <div class="notice notice-warning inline"><p>This team is <strong><?php echo esc_html($team->status); ?></strong> — it isn't public and can't take players.</p></div>
        <?php else: ?>
            <p>Invite link: <code><?php echo esc_html(RSL_Portal::invite_url($team)); ?></code></p>
        <?php endif; ?>

        <h2>Details</h2>
        <form method="post" action="<?php echo esc_url($post_url); ?>" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <?php echo self::fields('update_team', $team->season, $team->id); ?>
            <?php echo RSL_Public::badge($team); ?>
            <input type="text" name="name" value="<?php echo esc_attr($team->name); ?>" required maxlength="100" class="regular-text">
            <select name="division">
                <?php foreach (RSL_Teams::DIVISIONS as $key => $label): ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($team->division, $key); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
            <label>Colour <input type="color" name="colour" value="<?php echo esc_attr($team->colour); ?>"></label>
            <label>Level (estimate)
                <select name="level">
                    <option value="">Not set</option>
                    <?php foreach (RSL_Teams::levels() as $key => $label): ?><option value="<?php echo esc_attr($key); ?>" <?php selected($team->est_level, $key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="button">Save</button>
        </form>
        <p class="description">The level is the captain's starting guess (or yours) — admin-only, never public. It seeds a Ranked team's first-week matchmaking (<?php echo intval(RSL_Teams::level_seed_rating($team->est_level ?: 'solid')); ?> on the rating scale) until results take over. The public ladder never uses it: there every team starts on the same rating and only results move it, so changing the level never moves a team on the ladder.</p>
        <p><strong>Fixturing preferences:</strong> <?php echo esc_html(RSL_Teams::preferences_summary($team)); ?> <span class="description">(set by the captain on the league page)</span></p>

        <h2>Roster (<?php echo count($members); ?>)</h2>
        <?php if (empty($members)): ?>
            <p class="description">No players yet.</p>
        <?php else: ?>
            <table class="widefat striped" style="max-width: 900px;">
                <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Cleared to play</th><th>Joined</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($members as $m): ?>
                        <?php $clear = RSL_Rego::clearance_for($m->user_id, $team->season); // for the team's season: a rego paid ahead counts ?>
                        <tr>
                            <td><?php echo esc_html($m->display_name); ?></td>
                            <td><?php echo esc_html($m->user_email); ?></td>
                            <td><?php echo esc_html(RSL_Teams::ROLES[$m->role]); ?></td>
                            <?php $unknown = !$clear['cleared'] && !empty($clear['unknown']); // memberships can't be checked right now ?>
                            <td style="color: <?php echo $clear['cleared'] ? '#1e6b2a' : ($unknown ? '#646970' : '#b32d2e'); ?>;"><?php echo ($clear['cleared'] ? '&#10003; ' : ($unknown ? '? ' : '&#10007; ')) . esc_html(RSL_Rego::describe($clear)); ?></td>
                            <td><?php echo esc_html(date('j M Y', strtotime($m->joined_at))); ?></td>
                            <td style="white-space: nowrap;">
                                <?php if ($m->role !== 'captain'): ?>
                                    <form method="post" action="<?php echo esc_url($post_url); ?>" style="display: inline;">
                                        <?php echo self::fields('make_captain', $team->season, $team->id); ?>
                                        <input type="hidden" name="user_id" value="<?php echo intval($m->user_id); ?>">
                                        <button type="submit" class="button button-small">Make captain</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" action="<?php echo esc_url($post_url); ?>" style="display: inline;" onsubmit="return confirm('Remove <?php echo esc_js($m->display_name); ?> from the team?');">
                                    <?php echo self::fields('remove_member', $team->season, $team->id); ?>
                                    <input type="hidden" name="user_id" value="<?php echo intval($m->user_id); ?>">
                                    <button type="submit" class="button button-small button-link-delete">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h3>Add a player</h3>
        <form method="post" action="<?php echo esc_url($post_url); ?>" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <?php echo self::fields('add_member', $team->season, $team->id); ?>
            <input type="text" name="who" placeholder="Account email or username" required class="regular-text">
            <select name="role">
                <?php foreach (RSL_Teams::ROLES as $key => $label): ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($key, 'player'); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="button button-primary">Add</button>
        </form>
        <p class="description">One team per person per season. Players register themselves in a later phase; for now admins build rosters here.</p>
        <?php echo self::badge_styles(); ?>
        <?php
    }

    /** Social League → Registration: the rego products, past-order scan, manual regos. */
    public function render_registration() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $post_url = admin_url('admin-post.php');
        $season = RSL_Seasons::current();
        $product_id = RSL_Rego::product_id();
        $season_product_id = RSL_Rego::season_product_id();
        $today = current_time('Y-m-d');
        $open = RSL_Seasons::open_for_signup();
        $products = function_exists('wc_get_products') ? wc_get_products(array('status' => 'publish', 'limit' => 300, 'orderby' => 'title', 'order' => 'ASC')) : array();
        echo '<div class="wrap"><h1>Social League — Registration</h1>' . self::take_notice();
        ?>
        <p><strong><?php echo intval(RSL_Players::count()); ?></strong> players registered &middot; <strong><?php echo intval(RSL_Rego::count_current()); ?></strong> with a current social rego. Full and life club members are covered without a rego.</p>
        <?php if (!RSL_Rego::membership_source_available()): ?>
            <div class="notice notice-warning inline"><p><strong>Club memberships can't be checked</strong> — the MURVC Club Manager (or its membership check) isn't active. Players without a rego are shown as "can't check" rather than asked to pay, and nobody counts as covered by membership until it's back.</p></div>
        <?php endif; ?>

        <h2>Rego products</h2>
        <p class="description">The WooCommerce products players buy. A season rego (<?php echo esc_html(RSL_Rego::price_label('season')); ?>) covers one season, which the player chooses on the league page from the seasons open for sign-up: from its first game night (or the payment date, if later) to its last. A season with no game nights set yet covers its quarter (January to March, and so on). A player already covered for every game night of a season can't buy it. A yearly rego (<?php echo esc_html(RSL_Rego::price_label()); ?>) gives 12 months from the payment date; renewing early starts when the current rego ends. Choose either or both: the league page offers whichever are set, season first. Use simple products with no extra required options. One rego goes in the cart at a time; a refunded or cancelled order ends its rego.</p>
        <form method="post" action="<?php echo esc_url($post_url); ?>" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <?php echo self::fields('save_rego_product', $season); ?>
            <label>Yearly rego product
            <select name="product_id" style="max-width: 420px;">
                <option value="0">— Not set —</option>
                <?php foreach ($products as $product): ?>
                    <option value="<?php echo intval($product->get_id()); ?>" <?php selected($product_id, $product->get_id()); ?>><?php echo esc_html($product->get_name() . ' (#' . $product->get_id() . ')'); ?></option>
                <?php endforeach; ?>
            </select></label>
            <label>Season rego product
            <select name="season_product_id" style="max-width: 420px;">
                <option value="0">— Not set —</option>
                <?php foreach ($products as $product): ?>
                    <option value="<?php echo intval($product->get_id()); ?>" <?php selected($season_product_id, $product->get_id()); ?>><?php echo esc_html($product->get_name() . ' (#' . $product->get_id() . ')'); ?></option>
                <?php endforeach; ?>
            </select></label>
            <button type="submit" class="button button-primary">Save</button>
        </form>
        <?php if (!$product_id && !$season_product_id): ?>
            <p class="description">Neither is set: players can't pay online.</p>
        <?php endif; ?>
        <?php if ($product_id || $season_product_id): ?>
            <form method="post" action="<?php echo esc_url($post_url); ?>" style="margin-top: 10px;">
                <?php echo self::fields('scan_regos', $season); ?>
                <button type="submit" class="button">Pick up rego purchases from the last 13 months</button>
                <span class="description">Safe to run again — each order is only counted once.</span>
            </form>
        <?php endif; ?>

        <h2>Add a rego by hand</h2>
        <p class="description">For a rego paid another way (cash, bank transfer): 12 months from the start date, or one season: from its first game night (or the start date, if later) to its last.</p>
        <form method="post" action="<?php echo esc_url($post_url); ?>" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <?php echo self::fields('grant_rego', $season); ?>
            <input type="text" name="who" placeholder="Account email or username" required class="regular-text">
            <select name="kind" style="max-width: 420px;">
                <option value="year">12 months from the start date</option>
                <?php if ($open): ?>
                    <optgroup label="Seasons open for sign-up">
                        <?php foreach ($open as $key => $label): ?>
                            <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endif; ?>
                <?php $others = array_diff_key(RSL_Seasons::choices(), $open); ?>
                <?php if ($others): ?>
                    <optgroup label="Other seasons">
                        <?php foreach (array_keys($others) as $key): ?>
                            <?php $span = RSL_Seasons::span($key); ?>
                            <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html(RSL_Seasons::label_with_dates($key, $span) . ($span && $span['end'] < $today ? ', finished' : '')); ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endif; ?>
            </select>
            <label>Starts <input type="date" name="start" value="<?php echo esc_attr(current_time('Y-m-d')); ?>" required></label>
            <input type="text" name="note" placeholder="Note (e.g. paid cash)" style="width: 220px;">
            <button type="submit" class="button">Add rego</button>
        </form>

        <h2>Recent regos</h2>
        <?php $recent = RSL_Rego::recent(); ?>
        <?php if (empty($recent)): ?>
            <p class="description">None yet.</p>
        <?php else: ?>
            <table class="widefat striped" style="max-width: 1000px;">
                <thead><tr><th>Player</th><th>Valid</th><th>How</th><th>Note</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($recent as $r): ?>
                        <tr>
                            <td><?php echo esc_html($r->display_name ?: 'Deleted account'); ?><br><span class="description"><?php echo esc_html($r->user_email); ?></span></td>
                            <td><?php echo RSL_Rego::is_removed($r) ? '<em>Removed</em>' : esc_html(date('j M Y', strtotime($r->start_date)) . ' – ' . date('j M Y', strtotime($r->end_date))); ?>
                                <br><span class="description"><?php echo isset($r->season) && RSL_Seasons::is_valid($r->season) ? 'Season: ' . esc_html(RSL_Seasons::short_label($r->season)) : '12 months'; ?></span></td>
                            <td><?php echo $r->source === 'purchase' ? 'Order #' . intval($r->order_id) : 'Added by hand'; ?></td>
                            <td><?php echo esc_html($r->note); ?></td>
                            <td>
                                <?php if (RSL_Rego::row_counts($r)): // a season rego kept counting by its season's moved dates can still be removed ?>
                                    <form method="post" action="<?php echo esc_url($post_url); ?>" style="display: inline;" onsubmit="return confirm('Remove this rego? It stops covering <?php echo esc_js($r->display_name); ?> from today.');">
                                        <?php echo self::fields('remove_rego', $season); ?>
                                        <input type="hidden" name="grant_id" value="<?php echo intval($r->id); ?>">
                                        <button type="submit" class="button button-small button-link-delete">Remove</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
        echo '</div>';
    }

    private static function badge_styles() {
        return '<style>.rsl-badge{display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:50%;color:#fff;font-weight:700;font-size:13px;}</style>';
    }
}
