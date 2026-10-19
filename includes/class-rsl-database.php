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
    const SCHEMA_VERSION = '6';
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
        // est_level: the captain's rough level at sign-up — admin-only, the
        // seed for first-week Ranked matchmaking (never public). pref_*: the
        // captain's fixturing preferences.
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
            est_level varchar(20) NOT NULL DEFAULT '',
            pref_time varchar(10) NOT NULL DEFAULT 'any',
            pref_double tinyint(1) NOT NULL DEFAULT 0,
            pref_double_shape varchar(10) NOT NULL DEFAULT 'back',
            pref_standby tinyint(1) NOT NULL DEFAULT 0,
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

        // Venues and their courts. A venue's division is where it normally
        // hosts (e.g. Ranked at one venue, Unranked at the other); a court's
        // default hours are its usual game-night hours.
        dbDelta("CREATE TABLE " . self::table('venues') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            division varchar(20) NOT NULL DEFAULT 'ranked',
            sort int(11) NOT NULL DEFAULT 0,
            active tinyint(1) NOT NULL DEFAULT 1,
            PRIMARY KEY  (id)
        ) $charset;");

        dbDelta("CREATE TABLE " . self::table('courts') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            venue_id bigint(20) unsigned NOT NULL,
            name varchar(50) NOT NULL,
            default_start time NOT NULL DEFAULT '19:00:00',
            default_end time NOT NULL DEFAULT '23:00:00',
            sort int(11) NOT NULL DEFAULT 0,
            active tinyint(1) NOT NULL DEFAULT 1,
            PRIMARY KEY  (id),
            KEY venue_id (venue_id)
        ) $charset;");

        // Game nights of a season (normally Sundays): regular, finals or bye.
        // published_at: when that night's fixtures went public (NULL = not yet).
        dbDelta("CREATE TABLE " . self::table('nights') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            season varchar(20) NOT NULL,
            night_date date NOT NULL,
            type varchar(20) NOT NULL DEFAULT 'regular',
            note varchar(255) NOT NULL DEFAULT '',
            published_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY season_date (season, night_date)
        ) $charset;");

        // Per-night exceptions only — no row means "the usual". A venue can
        // host the other division for a night; a court can be off or run
        // different hours.
        dbDelta("CREATE TABLE " . self::table('night_venues') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            night_id bigint(20) unsigned NOT NULL,
            venue_id bigint(20) unsigned NOT NULL,
            division varchar(20) NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY night_venue (night_id, venue_id)
        ) $charset;");

        dbDelta("CREATE TABLE " . self::table('night_courts') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            night_id bigint(20) unsigned NOT NULL,
            court_id bigint(20) unsigned NOT NULL,
            available tinyint(1) NOT NULL DEFAULT 1,
            start_time time DEFAULT NULL,
            end_time time DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY night_court (night_id, court_id)
        ) $charset;");

        // One game in one slot (night, court, start). Status: scheduled,
        // played (sets holds "25-20,23-25,15-12", home score first), or
        // forfeit (forfeit_team_id gave it away — not a played game, so it
        // never moves ratings).
        dbDelta("CREATE TABLE " . self::table('games') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            season varchar(20) NOT NULL,
            night_id bigint(20) unsigned NOT NULL,
            division varchar(20) NOT NULL DEFAULT 'ranked',
            venue_id bigint(20) unsigned NOT NULL,
            court_id bigint(20) unsigned NOT NULL,
            start_time time NOT NULL,
            end_time time NOT NULL,
            home_team_id bigint(20) unsigned NOT NULL,
            away_team_id bigint(20) unsigned NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'scheduled',
            forfeit_team_id bigint(20) unsigned DEFAULT NULL,
            sets varchar(100) NOT NULL DEFAULT '',
            home_sets tinyint(3) unsigned NOT NULL DEFAULT 0,
            away_sets tinyint(3) unsigned NOT NULL DEFAULT 0,
            result_by bigint(20) unsigned DEFAULT NULL,
            result_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY night_id (night_id),
            KEY season (season),
            KEY home_team_id (home_team_id),
            KEY away_team_id (away_team_id)
        ) $charset;");

        // A captain's request about one night: off (week off, before
        // fixtures are out), double (two games please, before fixtures are
        // out) or cant_make (after fixtures are out — goes to the admins).
        // Status: active, cancelled, or handled (an admin dealt with it).
        dbDelta("CREATE TABLE " . self::table('requests') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            team_id bigint(20) unsigned NOT NULL,
            night_id bigint(20) unsigned NOT NULL,
            type varchar(20) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            requested_by bigint(20) unsigned NOT NULL,
            requested_at datetime DEFAULT CURRENT_TIMESTAMP,
            handled_by bigint(20) unsigned DEFAULT NULL,
            handled_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY team_night (team_id, night_id),
            KEY night_id (night_id)
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
