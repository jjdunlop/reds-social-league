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
                self::notice('success', 'Team "' . $team->name . '" created.');
                return add_query_arg('team', $team->id, $back);

            case 'update_team':
                $team = RSL_Teams::update($team_id, array(
                    'name' => isset($post['name']) ? wp_unslash($post['name']) : '',
                    'division' => isset($post['division']) ? sanitize_key($post['division']) : '',
                    'colour' => isset($post['colour']) ? wp_unslash($post['colour']) : '',
                ));
                if (!is_wp_error($team) && !empty($post['level'])) {
                    RSL_Teams::set_level($team_id, sanitize_key($post['level']));
                }
                self::notice(is_wp_error($team) ? 'error' : 'success', is_wp_error($team) ? $team->get_error_message() : 'Team saved.');
                return $team_back;

            case 'add_member':
                $who = isset($post['who']) ? trim(sanitize_text_field(wp_unslash($post['who']))) : '';
                $user = is_email($who) ? get_user_by('email', $who) : get_user_by('login', $who);
                if (!$user) {
                    self::notice('error', 'No account found for "' . $who . '". Use their account email or username.');
                    return $team_back;
                }
                $role = isset($post['role']) ? sanitize_key($post['role']) : 'player';
                $result = RSL_Teams::add_member($team_id, $user->ID, $role);
                self::notice(is_wp_error($result) ? 'error' : 'success', is_wp_error($result) ? $result->get_error_message() : $user->display_name . ' added to the team.');
                return $team_back;

            case 'approve':
                $result = RSL_Teams::approve($team_id);
                self::notice(is_wp_error($result) ? 'error' : 'success', is_wp_error($result) ? $result->get_error_message() : $result->name . ' approved — the captain has been emailed their invite link.');
                return $back;

            case 'decline':
                $result = RSL_Teams::decline($team_id, isset($post['note']) ? wp_unslash($post['note']) : '');
                self::notice(is_wp_error($result) ? 'error' : 'success', is_wp_error($result) ? $result->get_error_message() : $result->name . ' declined — the captain has been emailed.');
                return $back;

            case 'save_rego_product':
                update_option(RSL_Rego::PRODUCT_OPTION, isset($post['product_id']) ? intval($post['product_id']) : 0, false);
                self::notice('success', 'Rego product saved.');
                return admin_url('admin.php?page=' . self::REG_PAGE);

            case 'scan_regos':
                $added = RSL_Rego::scan_orders();
                self::notice('success', $added ? $added . ' rego(s) picked up from past orders.' : 'No new rego purchases found in the last 13 months.');
                return admin_url('admin.php?page=' . self::REG_PAGE);

            case 'grant_rego':
                $who = isset($post['who']) ? trim(sanitize_text_field(wp_unslash($post['who']))) : '';
                $user = is_email($who) ? get_user_by('email', $who) : get_user_by('login', $who);
                $start = isset($post['start']) ? sanitize_text_field(wp_unslash($post['start'])) : '';
                if (!$user) {
                    self::notice('error', 'No account found for "' . $who . '".');
                } else {
                    RSL_Rego::grant($user->ID, array('start' => $start, 'source' => 'manual', 'note' => isset($post['note']) ? sanitize_text_field(wp_unslash($post['note'])) : ''));
                    self::notice('success', '12-month rego added for ' . $user->display_name . '.');
                }
                return admin_url('admin.php?page=' . self::REG_PAGE);

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
            <h2>Team applications (<?php echo count($applications); ?>)</h2>
            <table class="widefat striped" style="max-width: 1100px;">
                <thead><tr><th style="width: 52px;"></th><th>Team</th><th>Season</th><th>Division</th><th>Level (their guess)</th><th>Captain</th><th>Applied</th><th>Decision</th></tr></thead>
                <tbody>
                    <?php foreach ($applications as $app): ?>
                        <tr>
                            <td><?php echo RSL_Public::badge($app); ?></td>
                            <td><strong><?php echo esc_html($app->name); ?></strong></td>
                            <td><?php echo esc_html(RSL_Seasons::label($app->season)); ?></td>
                            <td><?php echo esc_html(RSL_Teams::DIVISIONS[$app->division]); ?></td>
                            <td><?php echo esc_html(isset(RSL_Teams::levels()[$app->est_level]) ? RSL_Teams::levels()[$app->est_level] : '—'); ?></td>
                            <td><?php echo esc_html($app->captain_name); ?><br><span class="description"><?php echo esc_html($app->captain_email); ?></span></td>
                            <td><?php echo esc_html(date('j M Y', strtotime($app->applied_at))); ?></td>
                            <td>
                                <form method="post" action="<?php echo esc_url($post_url); ?>" style="display: inline;">
                                    <?php echo self::fields('approve', $app->season, $app->id); ?>
                                    <button type="submit" class="button button-primary">Approve</button>
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
        <p class="description">The level is the captain's starting guess (or yours) — admin-only, never public. It seeds a Ranked team's first-week matchmaking (<?php echo intval(RSL_Teams::level_seed_rating($team->est_level ?: 'solid')); ?> on the rating scale) until results take over.</p>
        <p><strong>Fixturing preferences:</strong> <?php echo esc_html(RSL_Teams::preferences_summary($team)); ?> <span class="description">(set by the captain on the league page)</span></p>

        <h2>Roster (<?php echo count($members); ?>)</h2>
        <?php if (empty($members)): ?>
            <p class="description">No players yet.</p>
        <?php else: ?>
            <table class="widefat striped" style="max-width: 900px;">
                <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Cleared to play</th><th>Joined</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($members as $m): ?>
                        <?php $clear = RSL_Rego::clearance($m->user_id); ?>
                        <tr>
                            <td><?php echo esc_html($m->display_name); ?></td>
                            <td><?php echo esc_html($m->user_email); ?></td>
                            <td><?php echo esc_html(RSL_Teams::ROLES[$m->role]); ?></td>
                            <td style="color: <?php echo $clear['cleared'] ? '#1e6b2a' : '#b32d2e'; ?>;"><?php echo ($clear['cleared'] ? '&#10003; ' : '&#10007; ') . esc_html(RSL_Rego::describe($clear)); ?></td>
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

    /** Social League → Registration: the rego product, past-order scan, manual regos. */
    public function render_registration() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $post_url = admin_url('admin-post.php');
        $season = RSL_Seasons::current();
        $product_id = RSL_Rego::product_id();
        $products = function_exists('wc_get_products') ? wc_get_products(array('status' => 'publish', 'limit' => 300, 'orderby' => 'title', 'order' => 'ASC')) : array();
        echo '<div class="wrap"><h1>Social League — Registration</h1>' . self::take_notice();
        ?>
        <p><strong><?php echo intval(RSL_Players::count()); ?></strong> players registered &middot; <strong><?php echo intval(RSL_Rego::count_current()); ?></strong> with a current social rego. Full and life club members are covered without a rego.</p>

        <h2>Rego product</h2>
        <p class="description">The WooCommerce product players buy for the <?php echo esc_html(RSL_Rego::PRICE_LABEL); ?> social rego. Each paid order gives the buyer 12 months from the payment date (renewing early starts when the current rego ends). Use a simple product with no extra required options, so the league page can send players straight to checkout.</p>
        <form method="post" action="<?php echo esc_url($post_url); ?>" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <?php echo self::fields('save_rego_product', $season); ?>
            <select name="product_id" style="max-width: 420px;">
                <option value="0">— Not set: players can't pay online —</option>
                <?php foreach ($products as $product): ?>
                    <option value="<?php echo intval($product->get_id()); ?>" <?php selected($product_id, $product->get_id()); ?>><?php echo esc_html($product->get_name() . ' (#' . $product->get_id() . ')'); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="button button-primary">Save</button>
        </form>
        <?php if ($product_id): ?>
            <form method="post" action="<?php echo esc_url($post_url); ?>" style="margin-top: 10px;">
                <?php echo self::fields('scan_regos', $season); ?>
                <button type="submit" class="button">Pick up rego purchases from the last 13 months</button>
                <span class="description">Safe to run again — each order is only counted once.</span>
            </form>
        <?php endif; ?>

        <h2>Add a rego by hand</h2>
        <p class="description">For a rego paid another way (cash, bank transfer). It lasts 12 months from the start date.</p>
        <form method="post" action="<?php echo esc_url($post_url); ?>" style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
            <?php echo self::fields('grant_rego', $season); ?>
            <input type="text" name="who" placeholder="Account email or username" required class="regular-text">
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
                <thead><tr><th>Player</th><th>Valid</th><th>How</th><th>Note</th></tr></thead>
                <tbody>
                    <?php foreach ($recent as $r): ?>
                        <tr>
                            <td><?php echo esc_html($r->display_name ?: 'Deleted account'); ?><br><span class="description"><?php echo esc_html($r->user_email); ?></span></td>
                            <td><?php echo esc_html(date('j M Y', strtotime($r->start_date)) . ' – ' . date('j M Y', strtotime($r->end_date))); ?></td>
                            <td><?php echo $r->source === 'purchase' ? 'Order #' . intval($r->order_id) : 'Added by hand'; ?></td>
                            <td><?php echo esc_html($r->note); ?></td>
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
