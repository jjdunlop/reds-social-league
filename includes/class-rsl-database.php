<?php
/**
 * Tables. All are prefixed {$wpdb->prefix}rsl_ and created with dbDelta,
 * which only ever adds — changes must stay additive (never rename or drop a
 * column; add and backfill). People are WordPress users, keyed by user_id.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RSL_Database {

    /** Bump when the schema changes; maybe_upgrade() re-runs install(). */
    const SCHEMA_VERSION = '1';
    const SCHEMA_OPTION = 'rsl_schema_version';

    public static function table($name) {
        global $wpdb;
        return $wpdb->prefix . 'rsl_' . $name;
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        // A team belongs to one league season (e.g. 2027-pre). Its slug is
        // its public URL, unique within the season.
        dbDelta("CREATE TABLE " . self::table('teams') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            season varchar(20) NOT NULL,
            name varchar(100) NOT NULL,
            slug varchar(120) NOT NULL,
            division varchar(20) NOT NULL DEFAULT 'ranked',
            colour varchar(7) NOT NULL DEFAULT '#1d3d6e',
            status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY season_slug (season, slug),
            KEY season (season)
        ) $charset;");

        // Roster: one row per person per team. Leaving sets left_at (kept for
        // history, e.g. finals eligibility); rejoining clears it.
        dbDelta("CREATE TABLE " . self::table('team_members') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            team_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            role varchar(20) NOT NULL DEFAULT 'player',
            joined_at date NOT NULL,
            left_at date DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY team_user (team_id, user_id),
            KEY user_id (user_id)
        ) $charset;");

        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, false);
    }

    /** Runs on every load; cheap unless the schema version moved. */
    public static function maybe_upgrade() {
        if (get_option(self::SCHEMA_OPTION) !== self::SCHEMA_VERSION) {
            self::install();
        }
    }
}
