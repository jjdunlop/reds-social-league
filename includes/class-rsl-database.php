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
    const SCHEMA_VERSION = '11';
    const SCHEMA_OPTION = 'rsl_schema_version';
    const UPGRADE_LOCK = 'rsl_upgrade_lock';

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
        // captain's fixturing preferences. spots_*: "looking for players" —
        // how many, which positions, who's welcome (the captain's own words,
        // shown to free agents) and a short note. formed_by: 'placement' for
        // a team made from free agents.
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
            spots_open tinyint(3) unsigned NOT NULL DEFAULT 0,
            spots_positions varchar(100) NOT NULL DEFAULT '',
            spots_level varchar(20) NOT NULL DEFAULT '',
            spots_note varchar(100) NOT NULL DEFAULT '',
            formed_by varchar(20) NOT NULL DEFAULT '',
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

        // Social rego: a yearly rego (season '': 12 months) or a season rego
        // (season = its key, e.g. 2027-pre: from that season's first game
        // night, or the payment date if later, to its last game night). One
        // per paid order item (UNIQUE — an insert failure there is the dedupe
        // working), or added by an admin (manual, no order). granted_end is
        // the end it was granted with: revoke() shortens end_date and never
        // this, and a cancelled order paid again puts end_date back to it
        // (NULL on rows from before schema 11 = start + 1 year - 1 day).
        dbDelta("CREATE TABLE " . self::table('regos') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            start_date date NOT NULL,
            end_date date NOT NULL,
            source varchar(20) NOT NULL DEFAULT 'purchase',
            order_id bigint(20) unsigned DEFAULT NULL,
            order_item_id bigint(20) unsigned DEFAULT NULL,
            note varchar(255) NOT NULL DEFAULT '',
            season varchar(16) NOT NULL DEFAULT '',
            granted_end date DEFAULT NULL,
            created_by bigint(20) unsigned DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY order_item_id (order_item_id),
            KEY user_id (user_id),
            KEY end_date (end_date)
        ) $charset;");

        // A captain's invitation to a registered player. The player must
        // accept before they're on the team. source: 'email' (the captain
        // typed their email — until they accept the captain sees only that)
        // or 'pool' (picked from the free agent list — email is '', the
        // captain sees their first name). A pool invite to someone in a
        // friend group carries group_code and takes the group. expires_at:
        // NULL = never (email invites). Status: pending, accepted, declined,
        // cancelled, expired, or closed (they joined another team).
        dbDelta("CREATE TABLE " . self::table('invites') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            team_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            email varchar(255) NOT NULL,
            invited_by bigint(20) unsigned NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            source varchar(10) NOT NULL DEFAULT 'email',
            group_code varchar(8) NOT NULL DEFAULT '',
            expires_at datetime DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            responded_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY team_id (team_id),
            KEY user_id (user_id),
            KEY status_expires (status, expires_at)
        ) $charset;");

        // The free agent list: one row per person per season. status:
        // looking, placed (on a team — team_id, placed_via, placed_at),
        // unplaced (the placement run couldn't fit them; unplaced_reason) or
        // withdrawn. division_pref: unranked / ranked / either — ranked and
        // either are the player's consent to a Ranked team. away_dates: CSV
        // of night dates (Y-m-d) they'll miss. group_code: a friend group
        // (up to a few people placed together). under18, band_override and
        // admin_note are admin-only; under18 is an admin's "treat as under
        // 18" mark (0.13.0: ages come from the account's date of birth, see
        // RSL_Free_Agents::under18_for()). proposed_*: the admin's placement draft
        // (dest 't:<team id>' | 'n:<new team ref>' | 'none').
        dbDelta("CREATE TABLE " . self::table('free_agents') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            season varchar(20) NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'looking',
            division_pref varchar(10) NOT NULL DEFAULT 'unranked',
            time_pref varchar(10) NOT NULL DEFAULT 'any',
            away_dates varchar(255) NOT NULL DEFAULT '',
            group_code varchar(8) NOT NULL DEFAULT '',
            captain_ok tinyint(1) NOT NULL DEFAULT 0,
            under18 tinyint(1) NOT NULL DEFAULT 0,
            browsable tinyint(1) NOT NULL DEFAULT 1,
            band_override decimal(3,1) DEFAULT NULL,
            admin_note varchar(255) NOT NULL DEFAULT '',
            team_id bigint(20) unsigned NOT NULL DEFAULT 0,
            placed_via varchar(20) NOT NULL DEFAULT '',
            placed_at datetime DEFAULT NULL,
            optout_until datetime DEFAULT NULL,
            unplaced_reason varchar(255) NOT NULL DEFAULT '',
            proposed_dest varchar(20) NOT NULL DEFAULT '',
            proposed_reason varchar(255) NOT NULL DEFAULT '',
            proposed_locked tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY season_user (season, user_id),
            KEY season_status (season, status),
            KEY season_group (season, group_code)
        ) $charset;");

        // A free agent asks a team to take them (and their friend group).
        // Only that team's captain can answer — kept apart from invites so a
        // player can never accept their own request. Status: pending,
        // accepted, declined (final for that team this season), withdrawn,
        // expired, or closed (they joined a team another way).
        dbDelta("CREATE TABLE " . self::table('join_requests') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            season varchar(20) NOT NULL,
            team_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            group_code varchar(8) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            expires_at datetime NOT NULL,
            responded_at datetime DEFAULT NULL,
            responded_by bigint(20) unsigned DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY team_status (team_id, status),
            KEY user_status (user_id, status),
            KEY status_expires (status, expires_at)
        ) $charset;");

        // Emails waiting to go (sent in small batches by cron). dedupe is
        // UNIQUE — queueing the same message twice is a no-op.
        dbDelta("CREATE TABLE " . self::table('outbox') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned DEFAULT NULL,
            to_email varchar(255) NOT NULL,
            subject varchar(255) NOT NULL,
            body text NOT NULL,
            dedupe varchar(120) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            sent_at datetime DEFAULT NULL,
            attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
            last_error varchar(255) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            UNIQUE KEY dedupe (dedupe),
            KEY pending (sent_at, attempts)
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
        // never moves ratings). live: the score in progress from the ref's
        // scoreboard (JSON), with live_seq so a late-arriving older update
        // never overwrites a newer one; cleared when the result is saved.
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
            live text DEFAULT NULL,
            live_seq bigint(20) NOT NULL DEFAULT 0,
            live_at datetime DEFAULT NULL,
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
            late tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY team_night (team_id, night_id),
            KEY night_id (night_id)
        ) $charset;");

        // Officials: paid referees (players tick "happy to ref" when they
        // register — ref = applied; an admin approves — approved / declined)
        // and court supervisors (added by an admin).
        dbDelta("CREATE TABLE " . self::table('officials') . " (
            user_id bigint(20) unsigned NOT NULL,
            ref varchar(20) NOT NULL DEFAULT '',
            supervisor tinyint(1) NOT NULL DEFAULT 0,
            updated_at datetime DEFAULT NULL,
            updated_by bigint(20) unsigned DEFAULT NULL,
            PRIMARY KEY  (user_id)
        ) $charset;");

        // A claimed job: refereeing one game, or supervising one venue for a
        // night (game_id 0). One person per job (UNIQUE — an insert failure
        // there means someone else got it first). rate: the pay at the time
        // it was claimed, in whole dollars.
        dbDelta("CREATE TABLE " . self::table('jobs') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            night_id bigint(20) unsigned NOT NULL,
            type varchar(20) NOT NULL,
            game_id bigint(20) unsigned NOT NULL DEFAULT 0,
            venue_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL,
            rate decimal(8,2) NOT NULL DEFAULT 0,
            claimed_at datetime DEFAULT CURRENT_TIMESTAMP,
            assigned_by bigint(20) unsigned DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY job (night_id, type, game_id, venue_id),
            KEY user_id (user_id)
        ) $charset;");

        // Team money. amount > 0 is money in (top-ups, credits), < 0 is a
        // charge; a team's balance is the sum. type: game (charged when a
        // night is published — managed to match the published fixtures),
        // late_fee (a late "can't make it"), topup (paid online — one row per
        // order item, UNIQUE: an insert failure there is the dedupe working),
        // adjust (an admin's credit or charge, with a note), refund (a
        // WooCommerce refund or cancellation of a top-up), carry (a balance
        // carried between seasons: one row out, one in). charge_key: UNIQUE
        // when set — one row per thing charged or credited ('game:<team>:
        // <game>', 'late:<request>:<game>' — late fees are per game,
        // 'refund:<item>:<refund item>', 'cancel:<item>', 'carry-out:' /
        // 'carry-in:'), so two overlapping syncs or a repeated webhook can't
        // write it twice. NULL for rows that don't need one.
        dbDelta("CREATE TABLE " . self::table('ledger') . " (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            team_id bigint(20) unsigned NOT NULL,
            season varchar(20) NOT NULL,
            type varchar(20) NOT NULL,
            amount decimal(10,2) NOT NULL,
            game_id bigint(20) unsigned NOT NULL DEFAULT 0,
            night_id bigint(20) unsigned NOT NULL DEFAULT 0,
            request_id bigint(20) unsigned NOT NULL DEFAULT 0,
            order_id bigint(20) unsigned DEFAULT NULL,
            order_item_id bigint(20) unsigned DEFAULT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            note varchar(255) NOT NULL DEFAULT '',
            created_by bigint(20) unsigned DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            charge_key varchar(64) DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY order_item_id (order_item_id),
            UNIQUE KEY charge_key (charge_key),
            KEY team_id (team_id),
            KEY night_id (night_id)
        ) $charset;");

        // Read on every request, so autoloaded.
        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, true);
    }

    /**
     * Runs on every load; cheap unless the schema version moved. True when
     * it upgraded. Only one request at a time runs the upgrade (a stale
     * lock — a request that died part-way — is taken over after a minute);
     * the others carry on with the old schema for that moment.
     */
    public static function maybe_upgrade() {
        if (get_option(self::SCHEMA_OPTION) === self::SCHEMA_VERSION) {
            return false;
        }
        if (!add_option(self::UPGRADE_LOCK, time(), '', false)) {
            $since = intval(get_option(self::UPGRADE_LOCK));
            if ($since && time() - $since < 60) {
                return false;
            }
            update_option(self::UPGRADE_LOCK, time(), false);
        }
        try {
            self::install();
            self::after_upgrade();
        } finally {
            delete_option(self::UPGRADE_LOCK);
        }
        return true;
    }

    /** One-off data steps that go with a schema change. Each is safe to repeat. */
    private static function after_upgrade() {
        // Options read on every request were once saved non-autoloaded.
        $hot = array(self::SCHEMA_OPTION, 'rsl_current_season', 'rsl_signup_season', 'rsl_portal_page_id', 'rsl_teams_page_id',
            'rsl_fixtures_page_id', 'rsl_ladder_page_id', 'rsl_refs_page_id', 'rsl_payment_settings', 'rsl_officials_settings',
            'rsl_topup_product_id', 'rsl_rego_product_id', 'rsl_rego_season_product_id', 'rsl_free_agent_settings');
        if (function_exists('wp_set_option_autoload_values')) {
            wp_set_option_autoload_values(array_fill_keys($hot, true));
        }
        if (method_exists('RSL_Payments', 'backfill_charge_keys')) {
            RSL_Payments::backfill_charge_keys();
        }
        // Once only (schema 10, when the list arrived): players.looking stays
        // set for anyone left on a season's list, so running this again on a
        // later upgrade would quietly sign them up for the next season.
        if (method_exists('RSL_Free_Agents', 'backfill') && !get_option('rsl_fa_backfill_10')) {
            RSL_Free_Agents::backfill();
            update_option('rsl_fa_backfill_10', 1, false);
        }
    }
}
