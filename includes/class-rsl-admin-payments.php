<?php
/**
 * Admin: Social League → Team Balances.
 *  - Rules: game fee, how long before a night "can't make it" is free,
 *    how much a team may owe and still be fixtured; the WooCommerce
 *    product used for top-ups.
 *  - Every team's balance (most owing first), and one team's full history
 *    with credits / charges (cash paid, refunds, corrections) with a note.
 * Every change posts to admin-post.php (nonce + manage_options).
 */

if (!defined('ABSPATH')) {
    exit;
}

class RSL_Admin_Payments {

    const PAGE = 'rsl-balances';

    public function __construct() {
        add_action('admin_menu', array($this, 'menu'), 27);
        add_action('admin_post_rsl_payments_action', array($this, 'handle'));
    }

    public function menu() {
        add_submenu_page(RSL_Admin::PAGE, 'Team Balances', 'Team Balances', 'manage_options', self::PAGE, array($this, 'render'));
    }

    public function handle() {
        wp_safe_redirect(self::process($_POST));
        exit;
    }

    private static function url($args = array()) {
        return add_query_arg($args, admin_url('admin.php?page=' . self::PAGE));
    }

    /** One action; returns where to go back to. Testable. */
    public static function process($post) {
        $team_id = isset($post['team_id']) ? intval($post['team_id']) : 0;
        $back = $team_id ? self::url(array('team' => $team_id)) : self::url();
        if (!current_user_can('manage_options') || !isset($post['rsl_nonce']) || !wp_verify_nonce($post['rsl_nonce'], 'rsl_payments_action')) {
            self::notice('error', 'Security check failed — please try again.');
            return $back;
        }
        $do = isset($post['do']) ? sanitize_key($post['do']) : '';
        switch ($do) {
            case 'save_settings':
                RSL_Payments::save_settings(array(
                    'game_fee' => isset($post['game_fee']) ? $post['game_fee'] : 75,
                    'free_cancel_hours' => isset($post['free_cancel_hours']) ? $post['free_cancel_hours'] : 48,
                    'max_owing' => isset($post['max_owing']) ? $post['max_owing'] : 75,
                ));
                update_option(RSL_Payments::PRODUCT_OPTION, isset($post['product_id']) ? intval($post['product_id']) : 0, false);
                self::notice('success', 'Saved. A new game fee applies to fixtures published from now on.');
                return $back;
            case 'adjust':
                $r = RSL_Payments::adjust($team_id, isset($post['amount']) ? $post['amount'] : 0, isset($post['note']) ? wp_unslash($post['note']) : '', get_current_user_id());
                self::notice(is_wp_error($r) ? 'error' : 'success', is_wp_error($r) ? $r->get_error_message() : 'Recorded.');
                return $back;
        }
        self::notice('error', 'Unknown action.');
        return $back;
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
        $html = wp_nonce_field('rsl_payments_action', 'rsl_nonce', true, false) . '<input type="hidden" name="action" value="rsl_payments_action"><input type="hidden" name="do" value="' . esc_attr($do) . '">';
        foreach ($extra as $k => $v) {
            $html .= '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
        }
        return $html;
    }

    public function render() {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<div class="wrap rsl-pay"><h1>Team Balances</h1>' . self::take_notice();
        $team = isset($_GET['team']) ? RSL_Teams::get(intval($_GET['team'])) : null;
        if ($team) {
            $this->render_team($team);
        } else {
            $this->render_overview();
        }
        echo '<style>
            .rsl-pay-table { max-width: 1000px; }
            .rsl-pay-table .num { text-align: right; white-space: nowrap; }
            .rsl-pay .is-owing { color: #b32d2e; font-weight: 600; }
            .rsl-pay .is-ok { color: #00762a; }
            .rsl-pay-pill { display: inline-block; padding: 1px 8px; border-radius: 9px; font-size: 11px; font-weight: 600; }
            .rsl-pay-pill.blocked { background: #fcf0f1; color: #8a1f1f; }
            .rsl-pay-pill.owing { background: #fcf9e8; color: #8a6100; }
            .rsl-pay-pill.ok { background: #edfaef; color: #00762a; }
            .rsl-pay-settings, .rsl-pay-adjust { display: flex; flex-wrap: wrap; gap: 14px; align-items: center; background: #fff; border: 1px solid #c3c4c7; padding: 12px 14px; max-width: 1000px; }
            .rsl-pay-settings input[type=number] { width: 80px; margin-left: 4px; }
            .rsl-pay-adjust input[name=note] { width: 320px; }
        </style></div>';
    }

    private function render_overview() {
        $post = admin_url('admin-post.php');
        $season = RSL_Seasons::current();
        $s = RSL_Payments::settings();
        $rows = RSL_Payments::season_balances($season);
        $products = function_exists('wc_get_products') ? wc_get_products(array('status' => array('publish', 'private'), 'limit' => 300, 'orderby' => 'title', 'order' => 'ASC')) : array();
        $owed = array_sum(array_map(function ($r) { return max(0, -$r->balance); }, $rows));
        $credit = array_sum(array_map(function ($r) { return max(0, $r->balance); }, $rows));
        ?>
        <p class="description">Game fees are charged to a team when that week's fixtures are published. Teams top up any amount on their team page. Across <?php echo esc_html(RSL_Seasons::label($season)); ?>: <strong class="is-owing"><?php echo esc_html(RSL_Payments::money($owed)); ?> owed</strong> · <strong class="is-ok"><?php echo esc_html(RSL_Payments::money($credit)); ?> in credit</strong>.</p>
        <table class="widefat striped rsl-pay-table">
            <thead><tr><th>Team</th><th>Division</th><th>Captain</th><th class="num">Balance</th><th>Fixtures</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <?php $cap = RSL_Teams::captain($r->team->id); $blocked = RSL_Payments::blocked($r->team->id); ?>
                <tr>
                    <td><strong><?php echo esc_html($r->team->name); ?></strong></td>
                    <td><?php echo esc_html(RSL_Teams::DIVISIONS[$r->team->division]); ?></td>
                    <td><?php echo esc_html($cap ? $cap->display_name : '—'); ?></td>
                    <td class="num <?php echo $r->balance < 0 ? 'is-owing' : 'is-ok'; ?>"><?php echo $r->balance < 0 ? 'owes ' . esc_html(RSL_Payments::money(-$r->balance)) : esc_html(RSL_Payments::money($r->balance)); ?></td>
                    <td><?php echo $blocked ? '<span class="rsl-pay-pill blocked">Not fixtured</span>' : ($r->balance < 0 ? '<span class="rsl-pay-pill owing">Owing</span>' : '<span class="rsl-pay-pill ok">OK</span>'); ?></td>
                    <td><a href="<?php echo esc_url(self::url(array('team' => $r->team->id))); ?>">History &amp; adjust</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="6" class="description">No teams this season yet.</td></tr><?php endif; ?>
            </tbody>
        </table>

        <h2>Rules</h2>
        <form method="post" action="<?php echo esc_url($post); ?>" class="rsl-pay-settings">
            <?php echo self::fields('save_settings'); ?>
            <label>Game fee $<input type="number" name="game_fee" min="0" step="0.5" value="<?php echo esc_attr($s['game_fee']); ?>"> incl. GST</label>
            <label>"Can't make it" is free until <input type="number" name="free_cancel_hours" min="0" max="336" value="<?php echo esc_attr($s['free_cancel_hours']); ?>"> hours before the night (after that the team pays both fees)</label>
            <label>Not fixtured when owing more than $<input type="number" name="max_owing" min="0" step="1" value="<?php echo esc_attr($s['max_owing']); ?>"></label>
            <label>Top-up product
                <select name="product_id">
                    <option value="0">— Not set: teams can't pay online —</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?php echo intval($p->get_id()); ?>" <?php selected(RSL_Payments::product_id(), $p->get_id()); ?>><?php echo esc_html($p->get_name() . ' (#' . $p->get_id() . ')'); ?></option>
                    <?php endforeach; ?>
                </select></label>
            <button class="button button-primary">Save</button>
        </form>
        <p class="description">The top-up product is a placeholder: a simple, virtual product (any price, e.g. $1), <strong>Published</strong> with Catalog visibility set to <strong>Hidden</strong> — a Private product can't be bought by players. The amount a player chooses replaces its price at checkout — GST included, so a $75 top-up costs exactly $75 — and the payment is credited to their team.</p>
        <?php
    }

    private function render_team($team) {
        $post = admin_url('admin-post.php');
        $balance = RSL_Payments::balance($team->id);
        $labels = RSL_Fixtures::round_labels($team->season);
        $paid = RSL_Payments::contributions($team->id);
        ?>
        <p><a href="<?php echo esc_url(self::url()); ?>">&larr; All teams</a></p>
        <h2><?php echo esc_html($team->name); ?> — <span class="<?php echo $balance < 0 ? 'is-owing' : 'is-ok'; ?>"><?php echo $balance < 0 ? 'owes ' . esc_html(RSL_Payments::money(-$balance)) : esc_html(RSL_Payments::money($balance)) . ' credit'; ?></span>
            <?php if (RSL_Payments::blocked($team->id)): ?><span class="rsl-pay-pill blocked">Not fixtured until paid</span><?php endif; ?></h2>
        <?php if ($paid): ?><p><strong>Paid online by:</strong> <?php echo esc_html(implode(' · ', array_map(function ($p) { return ($p->name ?: 'Unknown') . ' ' . RSL_Payments::money($p->total) . ' (' . intval($p->payments) . ')'; }, $paid))); ?></p><?php endif; ?>

        <form method="post" action="<?php echo esc_url($post); ?>" class="rsl-pay-adjust">
            <?php echo self::fields('adjust', array('team_id' => $team->id)); ?>
            <label>Amount $<input type="number" name="amount" step="0.01" required style="width: 100px;"></label>
            <label>Note <input type="text" name="note" required maxlength="200" placeholder="e.g. Cash paid at the venue / Refund for cancelled night"></label>
            <button class="button button-primary">Record</button>
            <span class="description">Positive = money in or a credit · negative = a charge</span>
        </form>

        <h3>History</h3>
        <table class="widefat striped rsl-pay-table">
            <thead><tr><th>Date</th><th>What</th><th class="num">Amount</th></tr></thead>
            <tbody>
            <?php foreach (RSL_Payments::ledger($team->id) as $r): ?>
                <tr><td><?php echo esc_html(date_i18n('j M Y', strtotime($r->created_at))); ?></td><td><?php echo esc_html(RSL_Payments::describe($r, $labels)); ?><?php echo $r->type === 'topup' && $r->order_id ? ' <span class="description">· order #' . intval($r->order_id) . '</span>' : ''; ?></td>
                    <td class="num <?php echo $r->amount < 0 ? 'is-owing' : 'is-ok'; ?>"><?php echo ($r->amount < 0 ? '&minus;' : '+') . esc_html(RSL_Payments::money(abs($r->amount))); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
