<?php
/**
 * Plugin Name: Reds Social League
 * Description: MURVC's social volleyball league: teams and rosters (more to come — registration, fixtures, results, ratings).
 * Version: 0.1.0
 * Author: MURVC
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: reds-social-league
 */

if (!defined('ABSPATH')) {
    exit;
}

define('RSL_VERSION', '0.1.0');
define('RSL_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RSL_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once RSL_PLUGIN_DIR . 'includes/class-rsl-database.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-seasons.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-teams.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-public.php';
require_once RSL_PLUGIN_DIR . 'includes/class-rsl-admin.php';

register_activation_hook(__FILE__, 'rsl_activate');

function rsl_activate() {
    RSL_Database::install();
    RSL_Public::ensure_pages();
    RSL_Public::add_rewrite_rules();
    flush_rewrite_rules();
}

register_deactivation_hook(__FILE__, 'flush_rewrite_rules');

add_action('plugins_loaded', 'rsl_init');

function rsl_init() {
    RSL_Database::maybe_upgrade();
    new RSL_Public();
    if (is_admin()) {
        new RSL_Admin();
    }
}
