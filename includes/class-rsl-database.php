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
    const SCHEMA_VERSION = '2';
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
        // its public URL, unique within the season. Status: active, or for
        // a captain's application pending / declined / withdrawn. The
        // invite code is what the captain shares so players can join.
        dbDelta("CREATE TABLE " . self::table('teams') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            season varchar(20) NOT NULL,
            name varchar(100) NOT NULL,
            slug varchar(120) NOT NULL,
            division varchar(20) NOT NULL DEFAULT 'ranked',
            colour varchar(7) NOT NULL DEFAULT '#1d3d6e',
            status varchar(20) NOT NULL DEFAULT 'active',
            invite_code varchar(16) NOT NULL DEFAULT '',
            applied_by bigint(20) unsigned DEFAULT NULL,
            applied_at datetime DEFAULT NULL,
            decided_by bigint(20) unsigned DEFAULT NULL,
            decided_at datetime DEFAULT NULL,
            decision_note varchar(255) NOT NULL DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY season_slug (season, slug),
            KEY season (season),
            KEY invite_code (invite_code)
        ) $charset;");

        // League players: someone who has registered for the social league.
        // The experience answers seed ratings and help place free agents.
        dbDelta("CREATE TABLE " . self::table('players') . " (
            user_id bigint(20) unsigned NOT NULL,
            level varchar(20) NOT NULL DEFAULT '',
            years varchar(20) NOT NULL DEFAULT '',
            positions varchar(100) NOT NULL DEFAULT '',
            looking tinyint(1) NOT NULL DEFAULT 0,
            registered_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT NULL,
            PRIMARY KEY  (user_id)
        ) $charset;");

        // Social rego: 12-month grants. One per paid order item (UNIQUE —
        // an insert failure there is the dedupe working), or added by an
        // admin (manual, no order).
        dbDelta("CREATE TABLE " . self::table('regos') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            start_date date NOT NULL,
            end_date date NOT NULL,
            source varchar(20) NOT NULL DEFAULT 'purchase',
            order_id bigint(20) unsigned DEFAULT NULL,
            order_item_id bigint(20) unsigned DEFAULT NULL,
            note varchar(255) NOT NULL DEFAULT '',
            created_by bigint(20) unsigned DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY order_item_id (order_item_id),
            KEY user_id (user_id),
            KEY end_date (end_date)
        ) $charset;");

        // A captain's invitation to a registered player (by email). The
        // player must accept before they're on the team — until then the
        // captain sees only the email they typed.
        dbDelta("CREATE TABLE " . self::table('invites') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            team_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            email varchar(255) NOT NULL,
            invited_by bigint(20) unsigned NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            responded_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY team_id (team_id),
            KEY user_id (user_id)
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

    /** Runs on every load; cheap unless the schema version moved. True when it upgraded. */
    public static function maybe_upgrade() {
        if (get_option(self::SCHEMA_OPTION) !== self::SCHEMA_VERSION) {
            self::install();
            return true;
        }
        return false;
    }
}
