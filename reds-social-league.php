<?php
/**
 * Plugin Name: Reds Social League
 * Description: MURVC's social volleyball league: player registration and social rego, captain team applications, invites and rosters, courts and game nights, fixtures, results, ratings and ladders.
 * Version: 0.20.1
 * Author: MURVC
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: reds-social-league
 */

if (!defined('ABSPATH')) {
    exit;
}

define('RSL_VERSION', '0.20.1');
define('RSL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RSL_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once RSL_PLUGIN_DIR . 'includes/class-rsl-database.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-seasons.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-players.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-rego.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-teams.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-mail.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-free-agents.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-public.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-portal.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-portal-free-agents.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-courts.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-capacity.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-fixtures.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-requests.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-officials.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-payments.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-ref-page.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-ratings.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-pairing.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-placement.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-stats.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-public-fixtures.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-merge.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-admin.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-admin-courts.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-admin-fixtures.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-admin-officials.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-admin-payments.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-admin-free-agents.php';

register_activation_hook(__FILE__, 'rsl_activate');

function rsl_activate() {
    // Not install(): an update done as deactivate, replace files, activate
    // never runs plugins_loaded with the plugin loaded, so this is the only
    // chance for the upgrade's data steps (a fresh site installs here too).
    RSL_Database::maybe_upgrade();
    rsl_ensure_pages_and_routes();
    rsl_schedule_cron();
}

/** Hourly housekeeping: send queued email, expire old requests/invites, the placement-ready notice. */
function rsl_schedule_cron() {
    if (!wp_next_scheduled('rsl_hourly')) {
        wp_schedule_event(time() + 300, 'hourly', 'rsl_hourly');
    }
}

function rsl_hourly() {
    RSL_Mail::drain();
    RSL_Mail::prune();
    RSL_Free_Agents::expire_stale();
    RSL_Free_Agents::deadline_check();
}
add_action('rsl_hourly', 'rsl_hourly');
add_action(RSL_Mail::SEND_HOOK, array('RSL_Mail', 'drain'));

/** The plugin's pages exist and their pretty links work. */
function rsl_ensure_pages_and_routes() {
    RSL_Public::ensure_pages();
    RSL_Portal::ensure_page();
    RSL_Public::add_rewrite_rules();
    flush_rewrite_rules();
}

register_deactivation_hook(__FILE__, 'rsl_deactivate');

function rsl_deactivate() {
    wp_clear_scheduled_hook('rsl_hourly');
    wp_clear_scheduled_hook(RSL_Mail::SEND_HOOK);
    flush_rewrite_rules();
}

add_action('plugins_loaded', 'rsl_init');

function rsl_init() {
    // A schema upgrade (plugin updated in place, no re-activation) also
    // makes sure any new pages exist and the routes are refreshed.
    if (RSL_Database::maybe_upgrade()) {
        add_action('init', 'rsl_ensure_pages_and_routes', 99);
    }
    add_action('init', 'rsl_schedule_cron');
    // The Club Manager's account merges: hooked here, before any admin
    // page or admin-post request can preview or run one.
    RSL_Merge::init();
    RSL_Free_Agents::init();
    new RSL_Public();
    new RSL_Public_Fixtures();
    new RSL_Portal();
    new RSL_Ref_Page();
    new RSL_Payments();
    new RSL_Rego();
    if (is_admin()) {
        new RSL_Admin();
        new RSL_Admin_Courts();
        new RSL_Admin_Fixtures();
        new RSL_Admin_Officials();
        new RSL_Admin_Payments();
        new RSL_Admin_Free_Agents();
    }
}
