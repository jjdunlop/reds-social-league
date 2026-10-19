<?php
/**
 * Plugin Name: Reds Social League
 * Description: MURVC's social volleyball league: player registration and social rego, captain team applications, invites and rosters, courts and game nights, fixtures, results, ratings and ladders.
 * Version: 0.10.0
 * Author: MURVC
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: reds-social-league
 */

if (!defined('ABSPATH')) {
    exit;
}

define('RSL_VERSION', '0.10.0');
define('RSL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RSL_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once RSL_PLUGIN_DIR . 'includes/class-rsl-database.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-seasons.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-players.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-rego.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-teams.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-public.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-portal.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-courts.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-fixtures.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-requests.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-officials.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-ref-page.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-ratings.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-pairing.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-stats.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-public-fixtures.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-admin.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-admin-courts.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-admin-fixtures.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-admin-officials.php';

register_activation_hook(__FILE__, 'rsl_activate');

function rsl_activate() {
    RSL_Database::install();
    rsl_ensure_pages_and_routes();
}

/** The plugin's pages exist and their pretty links work. */
function rsl_ensure_pages_and_routes() {
    RSL_Public::ensure_pages();
    RSL_Portal::ensure_page();
    RSL_Public::add_rewrite_rules();
    flush_rewrite_rules();
}

register_deactivation_hook(__FILE__, 'flush_rewrite_rules');

add_action('plugins_loaded', 'rsl_init');

function rsl_init() {
    // A schema upgrade (plugin updated in place, no re-activation) also
    // makes sure any new pages exist and the routes are refreshed.
    if (RSL_Database::maybe_upgrade()) {
        add_action('init', 'rsl_ensure_pages_and_routes', 99);
    }
    new RSL_Public();
    new RSL_Public_Fixtures();
    new RSL_Portal();
    new RSL_Ref_Page();
    new RSL_Rego();
    if (is_admin()) {
        new RSL_Admin();
        new RSL_Admin_Courts();
        new RSL_Admin_Fixtures();
        new RSL_Admin_Officials();
    }
}
