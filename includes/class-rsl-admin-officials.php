<?php
/**
 * Admin: Social League → Refs & Supervisors.
 *  - Approve or decline players who offered to referee; add or remove
 *    refs and court supervisors by email.
 *  - Pay settings: rate per game refereed, per night supervised; how long
 *    before a night refs can still drop a job; most games per ref per night.
 *  - Pay report for a date range (nights that have happened), with a CSV
 *    for the treasurer.
 * Every change posts to admin-post.php (nonce + manage_options).
 */

if (!defined('ABSPATH')) {
    exit;
}

class RSL_Admin_Officials {

    const PAGE = 'rsl-officials';

    public function __construct() {
        add_action('admin_menu', array($this, 'menu'), 26);
        add_action('admin_post_rsl_officials_action', array($this, 'handle'));
        add_action('admin_post_rsl_officials_csv', array($this, 'csv'));
    }

    public function menu() {
        add_submenu_page(RSL_Admin::PAGE, 'Refs & Supervisors', 'Refs & Supervisors', 'manage_options', self::PAGE, array($this, 'render'));
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
        if (!current_user_can('manage_options') || !isset($post['rsl_nonce']) || !wp_verify_nonce($post['rsl_nonce'], 'rsl_officials_action')) {
            self::notice('error', 'Security check failed — please try again.');
            return self::url();
        }
        $me = get_current_user_id();
        $do = isset($post['do']) ? sanitize_key($post['do']) : '';
        $user_id = isset($post['user_id']) ? intval($post['user_id']) : 0;
        if (isset($post['email']) && trim($post['email']) !== '') {
            $u = get_user_by('email', sanitize_email(wp_unslash($post['email'])));
            if (!$u) {
                self::notice('error', 'No club account has that email.');
                return self::url();
            }
            $user_id = $u->ID;
        }
        switch ($do) {
            case 'approve':
            case 'decline':
            case 'remove_ref':
            case 'add_ref':
                $status = array('approve' => 'approved', 'decline' => 'declined', 'remove_ref' => '', 'add_ref' => 'approved')[$do];
                $r = RSL_Officials::set_ref($user_id, $status, $me);
                $name = get_userdata($user_id) ? get_userdata($user_id)->display_name : '';
                self::result($r, array('approve' => "$name is now a referee.", 'decline' => "$name's offer to referee was declined.", 'remove_ref' => "$name is no longer a referee.", 'add_ref' => "$name is now a referee.")[$do] . self::released($r));
                return self::url();
            case 'add_supervisor':
            case 'remove_supervisor':
                $r = RSL_Officials::set_supervisor($user_id, $do === 'add_supervisor', $me);
                $name = get_userdata($user_id) ? get_userdata($user_id)->display_name : '';
                self::result($r, ($do === 'add_supervisor' ? "$name can now take court supervisor jobs." : "$name is no longer a court supervisor.") . self::released($r));
                return self::url();
            case 'save_settings':
                RSL_Officials::save_settings(array(
                    'ref_rate' => isset($post['ref_rate']) ? $post['ref_rate'] : 25,
                    'supervisor_rate' => isset($post['supervisor_rate']) ? $post['supervisor_rate'] : 30,
                    'release_hours' => isset($post['release_hours']) ? $post['release_hours'] : 24,
                    'max_games' => isset($post['max_games']) ? $post['max_games'] : 5,
                ));
                self::notice('success', 'Saved. New rates apply to jobs taken from now on.');
                return self::url();
        }
        self::notice('error', 'Unknown action.');
        return self::url();
    }

    private static function result($r, $ok) {
        self::notice(is_wp_error($r) ? 'error' : 'success', is_wp_error($r) ? $r->get_error_message() : $ok);
    }

    /** " 2 jobs still to come were released…" after removing someone who had jobs booked, else ''. */
    private static function released($r) {
        $n = !is_wp_error($r) && isset($r->released) ? intval($r->released) : 0;
        return $n ? ' ' . $n . ' job' . ($n === 1 ? '' : 's') . ' still to come ' . ($n === 1 ? 'was' : 'were') . ' released — open again on the Ref Jobs page.' : '';
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

    private static function fields($do, $extra = array()) {
        $html = wp_nonce_field('rsl_officials_action', 'rsl_nonce', true, false) . '<input type="hidden" name="action" value="rsl_officials_action"><input type="hidden" name="do" value="' . esc_attr($do) . '">';
        foreach ($extra as $k => $v) {
            $html .= '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
        }
        return $html;
    }

    /** Default report range: the current season's first night to today. */
    private static function range() {
        $nights = RSL_Courts::nights(RSL_Seasons::current());
        $from = isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from']) ? $_GET['from'] : ($nights ? $nights[0]->night_date : date('Y-m-01'));
        $to = isset($_GET['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']) ? $_GET['to'] : current_time('Y-m-d');
        return array($from, $to);
    }

    public function csv() {
        if (!current_user_can('manage_options') || !isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'rsl_officials_csv')) {
            wp_die('Security check failed.');
        }
        list($from, $to) = self::range();
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="social-league-officials-pay-' . $from . '-to-' . $to . '.csv"');
        $out = fopen('php://output', 'w');
        foreach (self::csv_rows($from, $to) as $row) {
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }

    /**
     * The pay report as CSV rows, header first. Names and emails are typed
     * by people, so a cell starting with = + - @ (or a tab / return) gets a
     * leading ' — a spreadsheet then shows it as text instead of running it
     * as a formula. Testable.
     */
    public static function csv_rows($from, $to) {
        $safe = function ($v) {
            return is_string($v) && $v !== '' && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
        };
        $rows = array(array('Name', 'Email', 'Games refereed', 'Referee pay', 'Nights supervised', 'Supervisor pay', 'Total'));
        foreach (RSL_Officials::pay_report($from, $to) as $row) {
            $rows[] = array_map($safe, array($row['name'], $row['email'], $row['refs'], number_format($row['ref_pay'], 2, '.', ''), $row['supervised'], number_format($row['supervisor_pay'], 2, '.', ''), number_format($row['total'], 2, '.', '')));
        }
        return $rows;
    }

    // ------------------------------------------------------------------
    // Screen
    // ------------------------------------------------------------------

    public function render() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $post = admin_url('admin-post.php');
        $s = RSL_Officials::settings();
        $people = RSL_Officials::people();
        $applied = array_filter($people, function ($p) { return $p->ref === 'applied'; });
        $refs = array_filter($people, function ($p) { return $p->ref === 'approved'; });
        $sups = array_filter($people, function ($p) { return (int) $p->supervisor === 1; });
        $season = RSL_Seasons::current();
        list($from, $to) = self::range();
        $report = RSL_Officials::pay_report($from, $to);
        ?>
        <div class="wrap rsl-off">
            <h1>Refs &amp; Supervisors</h1>
            <?php echo self::take_notice(); ?>
            <p class="description">Refs and supervisors take jobs on the <a href="<?php echo esc_url(RSL_Ref_Page::url()); ?>">Ref Jobs page</a> once each week's fixtures are published.</p>

            <h2>Offered to referee <span class="count">(<?php echo count($applied); ?>)</span></h2>
            <?php if (!$applied): ?>
                <p class="description">Nobody waiting. Players offer when they register (or in their details).</p>
            <?php else: ?>
                <table class="widefat striped rsl-off-table"><thead><tr><th>Name</th><th>Email</th><th>Team</th><th></th></tr></thead><tbody>
                <?php foreach ($applied as $p): ?>
                    <?php $team = RSL_Teams::current_team_for_user($p->user_id, $season); ?>
                    <tr><td><?php echo esc_html($p->display_name); ?></td><td><?php echo esc_html($p->user_email); ?></td><td><?php echo esc_html($team ? $team->name : '—'); ?></td>
                        <td class="rsl-off-acts">
                            <form method="post" action="<?php echo esc_url($post); ?>"><?php echo self::fields('approve', array('user_id' => $p->user_id)); ?><button class="button button-primary button-small">Approve</button></form>
                            <form method="post" action="<?php echo esc_url($post); ?>"><?php echo self::fields('decline', array('user_id' => $p->user_id)); ?><button class="button button-small">Decline</button></form>
                        </td></tr>
                <?php endforeach; ?>
                </tbody></table>
            <?php endif; ?>

            <div class="rsl-off-cols">
                <div>
                    <h2>Referees <span class="count">(<?php echo count($refs); ?>)</span></h2>
                    <table class="widefat striped rsl-off-table"><thead><tr><th>Name</th><th>Jobs this season</th><th></th></tr></thead><tbody>
                    <?php foreach ($refs as $p): ?>
                        <?php $e = RSL_Officials::earnings($p->user_id, $season); ?>
                        <tr><td><?php echo esc_html($p->display_name); ?><br><small><?php echo esc_html($p->user_email); ?></small></td>
                            <td><?php echo intval($e['done']); ?> done · <?php echo intval($e['booked']); ?> booked</td>
                            <td><form method="post" action="<?php echo esc_url($post); ?>" onsubmit="return confirm('Remove <?php echo esc_js($p->display_name); ?> as a referee? Their jobs still to come are released; jobs already done stay on the pay report.');"><?php echo self::fields('remove_ref', array('user_id' => $p->user_id)); ?><button class="button-link button-link-delete">Remove</button></form></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$refs): ?><tr><td colspan="3" class="description">No referees yet.</td></tr><?php endif; ?>
                    </tbody></table>
                    <form method="post" action="<?php echo esc_url($post); ?>" class="rsl-off-add"><?php echo self::fields('add_ref'); ?><input type="email" name="email" placeholder="Their club account email" required> <button class="button">Add a referee</button></form>
                </div>
                <div>
                    <h2>Court supervisors <span class="count">(<?php echo count($sups); ?>)</span></h2>
                    <table class="widefat striped rsl-off-table"><thead><tr><th>Name</th><th></th></tr></thead><tbody>
                    <?php foreach ($sups as $p): ?>
                        <tr><td><?php echo esc_html($p->display_name); ?><br><small><?php echo esc_html($p->user_email); ?></small></td>
                            <td><form method="post" action="<?php echo esc_url($post); ?>" onsubmit="return confirm('Remove <?php echo esc_js($p->display_name); ?> as a court supervisor? Their nights still to come are released; nights already done stay on the pay report.');"><?php echo self::fields('remove_supervisor', array('user_id' => $p->user_id)); ?><button class="button-link button-link-delete">Remove</button></form></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$sups): ?><tr><td colspan="2" class="description">No court supervisors yet.</td></tr><?php endif; ?>
                    </tbody></table>
                    <form method="post" action="<?php echo esc_url($post); ?>" class="rsl-off-add"><?php echo self::fields('add_supervisor'); ?><input type="email" name="email" placeholder="Their club account email" required> <button class="button">Add a supervisor</button></form>
                    <p class="description">One supervisor per venue per night: questions, first aid, keeping the night running.</p>
                </div>
            </div>

            <h2>Pay and rules</h2>
            <form method="post" action="<?php echo esc_url($post); ?>" class="rsl-off-settings">
                <?php echo self::fields('save_settings'); ?>
                <label>Per game refereed $<input type="number" name="ref_rate" min="0" step="0.5" value="<?php echo esc_attr($s['ref_rate']); ?>"></label>
                <label>Per night supervised $<input type="number" name="supervisor_rate" min="0" step="0.5" value="<?php echo esc_attr($s['supervisor_rate']); ?>"></label>
                <label>Refs can drop a job until <input type="number" name="release_hours" min="0" max="168" value="<?php echo esc_attr($s['release_hours']); ?>"> hours before the night</label>
                <label>Most games one ref takes a night <input type="number" name="max_games" min="1" max="12" value="<?php echo esc_attr($s['max_games']); ?>"></label>
                <button class="button button-primary">Save</button>
            </form>

            <h2>Pay report</h2>
            <form method="get" class="rsl-off-range">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE); ?>">
                <label>From <input type="date" name="from" value="<?php echo esc_attr($from); ?>"></label>
                <label>to <input type="date" name="to" value="<?php echo esc_attr($to); ?>"></label>
                <button class="button">Show</button>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(add_query_arg(array('action' => 'rsl_officials_csv', 'from' => $from, 'to' => $to), admin_url('admin-post.php')), 'rsl_officials_csv')); ?>">Download CSV</a>
            </form>
            <p class="description">Jobs on nights that have happened. If someone didn't turn up, remove their job on that night in Fixtures.</p>
            <table class="widefat striped rsl-off-table"><thead><tr><th>Name</th><th class="num">Games refereed</th><th class="num">Nights supervised</th><th class="num">Total</th></tr></thead><tbody>
            <?php $grand = 0; foreach ($report as $row): $grand += $row['total']; ?>
                <tr><td><?php echo esc_html($row['name']); ?></td>
                    <td class="num"><?php echo intval($row['refs']); ?> · <?php echo esc_html(RSL_Officials::money($row['ref_pay'])); ?></td>
                    <td class="num"><?php echo intval($row['supervised']); ?> · <?php echo esc_html(RSL_Officials::money($row['supervisor_pay'])); ?></td>
                    <td class="num"><strong><?php echo esc_html(RSL_Officials::money($row['total'])); ?></strong></td></tr>
            <?php endforeach; ?>
            <?php if (!$report): ?><tr><td colspan="4" class="description">No jobs in that range.</td></tr><?php else: ?>
                <tr class="rsl-off-total"><td>Total</td><td></td><td></td><td class="num"><strong><?php echo esc_html(RSL_Officials::money($grand)); ?></strong></td></tr>
            <?php endif; ?>
            </tbody></table>
        </div>
        <style>
            .rsl-off .count { color: #646970; font-weight: 400; font-size: 14px; }
            .rsl-off-table { max-width: 900px; }
            .rsl-off-table .num { text-align: right; }
            .rsl-off-acts form { display: inline; margin-right: 4px; }
            .rsl-off-cols { display: flex; flex-wrap: wrap; gap: 24px; }
            .rsl-off-cols > div { flex: 1 1 380px; max-width: 560px; }
            .rsl-off-add { margin-top: 8px; }
            .rsl-off-add input { width: 260px; }
            .rsl-off-settings, .rsl-off-range { display: flex; flex-wrap: wrap; gap: 14px; align-items: center; background: #fff; border: 1px solid #c3c4c7; padding: 12px 14px; max-width: 900px; }
            .rsl-off-settings input[type=number] { width: 70px; margin-left: 4px; }
            .rsl-off-total td { font-weight: 700; }
        </style>
        <?php
    }
}
