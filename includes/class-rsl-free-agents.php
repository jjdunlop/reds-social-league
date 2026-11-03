<?php
/**
 * Free agents: players signing up without a team. The data layer — no
 * output; the portal, the admin screens and tests call these. Methods return
 * a result or a WP_Error with a message a person can read.
 *
 * How it works: a free agent joins the season's list (division, game time,
 * weeks away, an optional friend group, "happy to captain"). During the
 * team-building window captains with open spots can browse the list (first
 * name, level and positions only) and invite people, who accept; free agents
 * can ask up to a few teams to take them, and only that team's captain can
 * say yes. After the deadline the site proposes placements for everyone left
 * (RSL_Placement) and an admin confirms them. Anyone joining a team through
 * the league is emailed their captain's name and email (and the captain
 * theirs) — rosters still show names only.
 *
 * Rules kept here: one team per person per season (RSL_Teams::add_member);
 * nobody whose division choice is Unranked is ever offered, invited or
 * placed into Ranked; friend groups are never split; a captain's "not this
 * time" is final for that team this season; nobody pays anything to be on
 * the list or to be placed.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RSL_Free_Agents {

    const SETTINGS_OPTION = 'rsl_free_agent_settings';
    const BUILDING_OPTION = 'rsl_team_building';
    const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    /** Set while this class removes someone itself (no "you were removed" email). */
    private static $quiet = false;

    public static function init() {
        add_action('rsl_member_added', array(__CLASS__, 'on_member_added'), 10, 3);
        add_action('rsl_member_removed', array(__CLASS__, 'on_member_removed'), 10, 2);
    }

    // ------------------------------------------------------------------
    // Words
    // ------------------------------------------------------------------

    public static function division_prefs() {
        return array(
            'unranked' => 'Unranked — relaxed, beginners welcome',
            'ranked' => 'Ranked — competitive, games matched by results',
            'either' => 'Either — happy to be placed in Ranked too',
        );
    }

    /** The divisions a choice allows. Ranked and Either are consent to Ranked. */
    public static function allowed_divisions($pref) {
        if ($pref === 'ranked') {
            return array('ranked');
        }
        return $pref === 'either' ? array('unranked', 'ranked') : array('unranked');
    }

    /** "Who's welcome" — the captain's own words, shown to free agents. */
    public static function spots_levels() {
        return array(
            'any' => 'Anyone welcome',
            'beginners' => 'Beginners welcome',
            'some' => 'Some experience',
            'experienced' => 'Experienced players',
        );
    }

    public static function statuses() {
        return array('looking' => 'Looking', 'placed' => 'On a team', 'unplaced' => 'Not placed', 'withdrawn' => 'Left the list');
    }

    // ------------------------------------------------------------------
    // Settings and the team-building window
    // ------------------------------------------------------------------

    public static function defaults() {
        return array(
            'window_opens_days' => 21,   // the list, the board and requests open this many days before night 1
            'deadline_days' => 10,       // requests and pool invites stop; placement can run
            'confirm_by_days' => 7,      // the date players are told they'll hear by
            'min_roster' => 7,           // fewer is "short" (filled first at placement)
            'target_roster' => 8,        // existing teams are filled up to this
            'new_team_target' => 8,      // size of teams made from free agents
            'max_roster' => 10,          // hard cap on every route except an admin's
            'max_requests' => 3,         // requests waiting at once, per free agent (or group)
            'request_days' => 3,         // requests and pool invites expire (never past the deadline)
            'pool_invites_extra' => 2,   // a team can have open spots + this many pool invites waiting
            'group_max' => 3,            // friend group size
            'optout_days' => 3,          // "Not for me" after being placed
            'under18_in_pool' => 0,      // show under-18s to captains browsing the list
            'gap_fill' => 1.5,           // placement: widest level gap (bands) topping up open spots
            'gap_short' => 2.0,          // placement: widest level gap filling a short team
            'capacity_override' => array('ranked' => 0, 'unranked' => 0), // most teams per division (0 = from the first night's courts)
        );
    }

    /** Settings with their allowed ranges (min, max). */
    private static function ranges() {
        return array(
            'window_opens_days' => array(7, 90), 'deadline_days' => array(1, 60), 'confirm_by_days' => array(0, 60),
            'min_roster' => array(4, 20), 'target_roster' => array(4, 20), 'new_team_target' => array(4, 20), 'max_roster' => array(6, 30),
            'max_requests' => array(1, 10), 'request_days' => array(1, 14), 'pool_invites_extra' => array(0, 10),
            'group_max' => array(1, 6), 'optout_days' => array(0, 14), 'under18_in_pool' => array(0, 1),
        );
    }

    public static function settings() {
        $saved = get_option(self::SETTINGS_OPTION, array());
        return array_merge(self::defaults(), is_array($saved) ? array_intersect_key($saved, self::defaults()) : array());
    }

    public static function save_settings($input) {
        $out = self::defaults();
        foreach (self::ranges() as $key => $range) {
            if (isset($input[$key]) && $input[$key] !== '') {
                $out[$key] = max($range[0], min($range[1], intval($input[$key])));
            }
        }
        // Placement's level gaps (in bands, to one decimal) and the per-division team cap.
        foreach (array('gap_fill', 'gap_short') as $key) {
            if (isset($input[$key]) && is_scalar($input[$key]) && $input[$key] !== '' && is_numeric($input[$key])) {
                $out[$key] = round(max(0.5, min(4.0, (float) $input[$key])), 1);
            }
        }
        foreach (array_keys($out['capacity_override']) as $division) {
            if (isset($input['capacity_override'][$division]) && is_scalar($input['capacity_override'][$division]) && $input['capacity_override'][$division] !== '') {
                $out['capacity_override'][$division] = max(0, min(50, intval($input['capacity_override'][$division])));
            }
        }
        if ($out['gap_short'] < $out['gap_fill']) {
            return new WP_Error('rsl_fa_settings', 'The level gap for short teams can\'t be smaller than the one for open spots (short teams get a little more room).');
        }
        if ($out['deadline_days'] >= $out['window_opens_days']) {
            return new WP_Error('rsl_fa_settings', 'The deadline has to come after the window opens (fewer days before night 1).');
        }
        if ($out['confirm_by_days'] > $out['deadline_days']) {
            return new WP_Error('rsl_fa_settings', 'Players can\'t hear about placements before the deadline.');
        }
        if (!($out['min_roster'] <= $out['target_roster'] && $out['target_roster'] <= $out['max_roster'] && $out['new_team_target'] <= $out['max_roster'] && $out['new_team_target'] >= $out['min_roster'])) {
            return new WP_Error('rsl_fa_settings', 'Team sizes must go minimum ≤ target ≤ maximum (and new teams between minimum and maximum).');
        }
        update_option(self::SETTINGS_OPTION, $out, true);
        return $out;
    }

    /** One season's team-building record: date overrides, the placement draft, confirmation. */
    public static function building($season) {
        $all = get_option(self::BUILDING_OPTION, array());
        $b = is_array($all) && isset($all[$season]) && is_array($all[$season]) ? $all[$season] : array();
        return array_merge(array(
            'opens' => '', 'deadline' => '', 'confirm_by' => '',
            'draft_version' => 0, 'new_teams' => array(), 'summary' => array(),
            'proposed_at' => '', 'confirmed_at' => '', 'confirmed_by' => 0, 'deadline_mailed' => 0,
        ), $b);
    }

    public static function save_building($season, $b) {
        $all = get_option(self::BUILDING_OPTION, array());
        $all = is_array($all) ? $all : array();
        $all[$season] = $b;
        update_option(self::BUILDING_OPTION, $all, false);
    }

    /** Admin date overrides for one season ('' = worked out from night 1). */
    public static function save_dates($season, $opens, $deadline, $confirm_by) {
        foreach (array($opens, $deadline, $confirm_by) as $d) {
            if ($d !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                return new WP_Error('rsl_fa_dates', 'Dates need to look like 2027-01-03.');
            }
        }
        $b = self::building($season);
        $b['opens'] = $opens;
        $b['deadline'] = $deadline;
        $b['confirm_by'] = $confirm_by;
        self::save_building($season, $b);
        $d = self::dates($season);
        if ($d && !($d['opens'] <= $d['deadline'] && $d['deadline'] < $d['first_night'])) {
            return new WP_Error('rsl_fa_dates', 'Saved, but check the order: the window opens, then the deadline, then the first night.');
        }
        return true;
    }

    /**
     * The season's key dates (Y-m-d): first_night, opens, deadline (the last
     * day for requests and pool invites), confirm_by. null until the
     * season's first game night is set.
     */
    public static function dates($season) {
        $first = RSL_Courts::settings($season)['start'];
        if (!$first || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $first)) {
            return null;
        }
        $s = self::settings();
        $b = self::building($season);
        $before = function ($days) use ($first) {
            return date('Y-m-d', strtotime($first . ' -' . intval($days) . ' days'));
        };
        return array(
            'first_night' => $first,
            'opens' => $b['opens'] !== '' ? $b['opens'] : $before($s['window_opens_days']),
            'deadline' => $b['deadline'] !== '' ? $b['deadline'] : $before($s['deadline_days']),
            'confirm_by' => $b['confirm_by'] !== '' ? $b['confirm_by'] : $before($s['confirm_by_days']),
        );
    }

    /**
     * Where the season is: before (window not open yet), open (asking and
     * inviting), placing (after the deadline, placements not confirmed yet)
     * or season (placements confirmed, or the season has started). With no
     * first night set it's open, with no deadline.
     */
    public static function phase($season) {
        $b = self::building($season);
        if ($b['confirmed_at'] !== '') {
            return 'season';
        }
        $d = self::dates($season);
        if (!$d) {
            return 'open';
        }
        $today = current_time('Y-m-d');
        if ($today >= $d['first_night']) {
            return 'season';
        }
        if ($today < $d['opens']) {
            return 'before';
        }
        return $today <= $d['deadline'] ? 'open' : 'placing';
    }

    /** Captains can browse and invite, free agents can ask teams. */
    public static function matching_open($season) {
        return in_array(self::phase($season), array('open', 'season'), true);
    }

    /** When a new request or pool invite expires: request_days from now, never past the deadline. */
    public static function expiry_for($season) {
        $until = current_time('timestamp') + self::settings()['request_days'] * DAY_IN_SECONDS;
        $d = self::dates($season);
        if ($d && self::phase($season) === 'open') {
            $until = min($until, strtotime($d['deadline'] . ' 23:59:59'));
        }
        return date('Y-m-d H:i:s', $until);
    }

    // ------------------------------------------------------------------
    // The list
    // ------------------------------------------------------------------

    public static function get($user_id, $season) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . RSL_Database::table('free_agents') . " WHERE season = %s AND user_id = %d", $season, $user_id));
    }

    public static function get_by_id($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . RSL_Database::table('free_agents') . " WHERE id = %d", $id));
    }

    /** Still to be placed: looking, or left over by a placement run. */
    public static function is_open($row) {
        return $row && in_array($row->status, array('looking', 'unplaced'), true);
    }

    /**
     * Why a placement run left someone out, in words for them — or ''. An
     * admin hold ("Held back by an admin") is the placement screen's business:
     * the one rule for the player's page and their email.
     */
    public static function unplaced_words($reason) {
        $reason = trim((string) $reason);
        return $reason !== '' && stripos($reason, 'admin') === false ? $reason : '';
    }

    /** The division a player is pointed at by default: beginners Unranked. */
    public static function default_division($player) {
        return $player && in_array($player->level, array('never', 'school'), true) ? 'unranked' : 'either';
    }

    /** Validate the free agent's choices. Returns the row fields or WP_Error. */
    private static function clean($data, $user_id, $season) {
        $player = RSL_Players::get($user_id);
        $pref = isset($data['division_pref']) && isset(self::division_prefs()[$data['division_pref']]) ? $data['division_pref'] : self::default_division($player);
        $time = isset($data['time_pref']) && isset(RSL_Teams::time_preferences()[$data['time_pref']]) ? $data['time_pref'] : 'any';
        $nights = array();
        foreach (RSL_Courts::nights($season) as $n) {
            if ($n->type === 'regular') {
                $nights[$n->night_date] = true;
            }
        }
        $away = array();
        foreach ((array) (isset($data['away_dates']) ? $data['away_dates'] : array()) as $d) {
            $d = (string) $d;
            if (isset($nights[$d])) {
                $away[$d] = $d;
            }
        }
        sort($away);
        return array(
            'division_pref' => $pref,
            'time_pref' => $time,
            'away_dates' => substr(implode(',', $away), 0, 255),
            'captain_ok' => empty($data['captain_ok']) ? 0 : 1,
            'browsable' => empty($data['browsable']) ? 0 : 1,
        );
    }

    /**
     * Put a registered player on the season's list (or back on it). $data:
     * division_pref, time_pref, away_dates (array of night dates),
     * captain_ok, browsable. Returns the row or WP_Error. (Whether they're
     * under 18 comes from their date of birth — see under18_for().)
     */
    public static function join($user_id, $season, $data, $quiet = false) {
        global $wpdb;
        if (!RSL_Seasons::is_valid($season)) {
            return new WP_Error('rsl_season', 'Pick a valid season.');
        }
        if (!RSL_Players::is_registered($user_id)) {
            return new WP_Error('rsl_unregistered', 'Register for the league first.');
        }
        if ($blocked = RSL_Teams::why_cant_join($user_id, $season)) {
            return $blocked;
        }
        $fields = self::clean($data, $user_id, $season);
        $existing = self::get($user_id, $season);
        if ($existing && $existing->group_code !== '' && self::is_open($existing)) {
            if ($error = self::group_conflict($season, $existing->group_code, $user_id, $fields['division_pref'])) {
                return $error;
            }
        }
        $now = current_time('mysql');
        $fields['status'] = 'looking';
        $fields['updated_at'] = $now;
        $was_open = self::is_open($existing);
        if ($existing) {
            $fields += array('team_id' => 0, 'placed_via' => '', 'placed_at' => null, 'optout_until' => null, 'unplaced_reason' => '', 'proposed_dest' => '', 'proposed_reason' => '', 'proposed_locked' => 0);
            if (!$was_open) {
                $fields['group_code'] = '';
            }
            $wpdb->update(RSL_Database::table('free_agents'), $fields, array('id' => $existing->id));
        } else {
            $fields += array('season' => $season, 'user_id' => $user_id, 'created_at' => $now);
            $wpdb->insert(RSL_Database::table('free_agents'), $fields);
        }
        self::set_looking_flag($user_id, true);
        if (!$was_open && !$quiet) {
            self::mail_joined($user_id, $season);
        }
        return self::get($user_id, $season);
    }

    /**
     * Change a free agent's choices while they're still looking. Anything
     * not in $data keeps its value (forms pass every checkbox as 0/1).
     */
    public static function update($user_id, $season, $data) {
        global $wpdb;
        $row = self::get($user_id, $season);
        if (!self::is_open($row)) {
            return new WP_Error('rsl_fa_none', 'You\'re not on the free agent list for this season.');
        }
        $data = (array) $data + array(
            'division_pref' => $row->division_pref,
            'time_pref' => $row->time_pref,
            'away_dates' => array_filter(explode(',', $row->away_dates)),
            'captain_ok' => (int) $row->captain_ok,
            'browsable' => (int) $row->browsable,
        );
        $fields = self::clean($data, $user_id, $season);
        if ($row->group_code !== '' && ($error = self::group_conflict($season, $row->group_code, $user_id, $fields['division_pref']))) {
            return $error;
        }
        $fields['updated_at'] = current_time('mysql');
        $wpdb->update(RSL_Database::table('free_agents'), $fields, array('id' => $row->id));
        return self::get($user_id, $season);
    }

    /** Leave the list: requests are withdrawn, invites closed, the friend group left. */
    public static function leave($user_id, $season) {
        global $wpdb;
        $row = self::get($user_id, $season);
        if (!self::is_open($row)) {
            return new WP_Error('rsl_fa_none', 'You\'re not on the free agent list for this season.');
        }
        $wpdb->update(RSL_Database::table('free_agents'), array('status' => 'withdrawn', 'group_code' => '', 'proposed_dest' => '', 'proposed_locked' => 0, 'updated_at' => current_time('mysql')), array('id' => $row->id));
        self::close_open_offers($user_id, $season, 'withdrawn');
        self::set_looking_flag($user_id, false);
        return true;
    }

    /** players.looking mirrors "on a list" (kept for older screens). */
    private static function set_looking_flag($user_id, $on) {
        global $wpdb;
        $wpdb->update(RSL_Database::table('players'), array('looking' => $on ? 1 : 0, 'updated_at' => current_time('mysql')), array('user_id' => $user_id));
    }

    /**
     * Older registrations ticked "looking" before the list existed: put each
     * of them (not on a team) on the sign-up season's list, quietly. Safe to
     * repeat.
     */
    public static function backfill() {
        global $wpdb;
        $season = RSL_Seasons::signup();
        $ids = $wpdb->get_col("SELECT user_id FROM " . RSL_Database::table('players') . " WHERE looking = 1");
        $n = 0;
        foreach ($ids as $uid) {
            if (self::get($uid, $season) || RSL_Teams::why_cant_join($uid, $season)) {
                continue;
            }
            $player = RSL_Players::get($uid);
            $r = self::join($uid, $season, array('division_pref' => self::default_division($player), 'browsable' => 1), true);
            if (!is_wp_error($r)) {
                $n++;
            }
        }
        return $n;
    }

    // ------------------------------------------------------------------
    // Friend groups (shareable codes — never looked up by email)
    // ------------------------------------------------------------------

    public static function clean_code($code) {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $code));
    }

    private static function new_group_code($season) {
        global $wpdb;
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while ($wpdb->get_var($wpdb->prepare("SELECT id FROM " . RSL_Database::table('free_agents') . " WHERE season = %s AND group_code = %s", $season, $code)));
        return $code;
    }

    /** Everyone still to be placed in a group, oldest first. */
    public static function group_members($season, $code) {
        global $wpdb;
        if ($code === '') {
            return array();
        }
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM " . RSL_Database::table('free_agents') . " WHERE season = %s AND group_code = %s AND status IN ('looking', 'unplaced') ORDER BY created_at, user_id", $season, $code));
    }

    /** The divisions a group can all play in (an empty array = none). */
    public static function group_divisions($rows) {
        $allowed = array('unranked', 'ranked');
        foreach ($rows as $r) {
            $allowed = array_values(array_intersect($allowed, self::allowed_divisions($r->division_pref)));
        }
        return $allowed;
    }

    /** Would this member's division choice leave their group with no division in common? */
    private static function group_conflict($season, $code, $user_id, $pref) {
        $rows = array();
        foreach (self::group_members($season, $code) as $m) {
            if (intval($m->user_id) !== intval($user_id)) {
                $rows[] = $m;
            }
        }
        $rows[] = (object) array('division_pref' => $pref);
        return self::group_divisions($rows) ? null : new WP_Error('rsl_fa_group', 'Your friend group wouldn\'t share a division then — talk to them, or leave the group first.');
    }

    /** Start a friend group; returns the code to share. */
    public static function group_create($user_id, $season) {
        global $wpdb;
        $row = self::get($user_id, $season);
        if (!self::is_open($row)) {
            return new WP_Error('rsl_fa_none', 'Join the free agent list first.');
        }
        if ($row->group_code !== '') {
            if (count(self::group_members($season, $row->group_code)) > 1) {
                return new WP_Error('rsl_fa_group', 'You\'re already in a friend group — leave it first.');
            }
            return $row->group_code;
        }
        $code = self::new_group_code($season);
        $wpdb->update(RSL_Database::table('free_agents'), array('group_code' => $code, 'updated_at' => current_time('mysql')), array('id' => $row->id));
        return $code;
    }

    /** Join a friend's group with the code they shared. */
    public static function group_join($user_id, $season, $code) {
        global $wpdb;
        $code = self::clean_code($code);
        $row = self::get($user_id, $season);
        if (!self::is_open($row)) {
            return new WP_Error('rsl_fa_none', 'Join the free agent list first.');
        }
        $members = $code !== '' ? self::group_members($season, $code) : array();
        if (!$members) {
            return new WP_Error('rsl_fa_code', 'That friend code isn\'t valid — check it with your friend.');
        }
        if ($row->group_code === $code) {
            return true;
        }
        if ($row->group_code !== '' && count(self::group_members($season, $row->group_code)) > 1) {
            return new WP_Error('rsl_fa_group', 'You\'re already in another friend group — leave it first.');
        }
        if (count($members) >= self::settings()['group_max']) {
            return new WP_Error('rsl_fa_full', 'That group is full (' . self::settings()['group_max'] . ' people).');
        }
        $members[] = $row;
        if (!self::group_divisions($members)) {
            return new WP_Error('rsl_fa_group', 'You and your friends picked divisions that don\'t overlap — change your division first.');
        }
        $wpdb->update(RSL_Database::table('free_agents'), array('group_code' => $code, 'updated_at' => current_time('mysql')), array('id' => $row->id));
        // What you asked for, or were invited to, on your own doesn't speak for
        // the group: your requests are withdrawn and your pool invites close.
        self::close_open_offers($user_id, $season, 'withdrawn');
        // Likewise pool invites your friends got before the group grew.
        $in = implode(',', array_map(function ($m) { return intval($m->user_id); }, $members));
        $wpdb->query($wpdb->prepare("
            UPDATE " . RSL_Database::table('invites') . " i JOIN " . RSL_Database::table('teams') . " t ON t.id = i.team_id
            SET i.status = 'closed', i.responded_at = %s
            WHERE i.user_id IN ($in) AND i.status = 'pending' AND i.source = 'pool' AND i.group_code <> %s AND t.season = %s
        ", current_time('mysql'), $code, $season));
        return true;
    }

    public static function group_leave($user_id, $season) {
        global $wpdb;
        $row = self::get($user_id, $season);
        if (!$row || $row->group_code === '') {
            return new WP_Error('rsl_fa_group', 'You\'re not in a friend group.');
        }
        $now = current_time('mysql');
        $wpdb->update(RSL_Database::table('free_agents'), array('group_code' => '', 'updated_at' => $now), array('id' => $row->id));
        $wpdb->query($wpdb->prepare("UPDATE " . RSL_Database::table('join_requests') . " SET status = 'withdrawn', responded_at = %s WHERE user_id = %d AND season = %s AND status = 'pending'", $now, $user_id, $season));
        // Pool invites sent to you for the group don't stand for you on your own.
        $wpdb->query($wpdb->prepare("
            UPDATE " . RSL_Database::table('invites') . " i JOIN " . RSL_Database::table('teams') . " t ON t.id = i.team_id
            SET i.status = 'closed', i.responded_at = %s
            WHERE i.user_id = %d AND i.status = 'pending' AND i.source = 'pool' AND i.group_code = %s AND t.season = %s
        ", $now, $user_id, $row->group_code, $season));
        return true;
    }

    /** A person's unit: their group's open members, or just them. */
    public static function unit_rows($row) {
        if (!$row) {
            return array();
        }
        if ($row->group_code !== '') {
            $members = self::group_members($row->season, $row->group_code);
            if ($members) {
                return $members;
            }
        }
        return array($row);
    }

    // ------------------------------------------------------------------
    // What people see
    // ------------------------------------------------------------------

    /**
     * First name only: the first word of the first_name field (people type
     * their whole name there), else the first word of the display name —
     * unless that's really their login (WordPress's default) or email.
     * Never a surname, username or email.
     */
    public static function first_name($user_id) {
        $first = trim((string) strtok(trim((string) get_user_meta($user_id, 'first_name', true)), " \t"));
        if ($first === '') {
            $user = get_userdata($user_id);
            $display = $user ? trim((string) $user->display_name) : '';
            $local = $user ? strtolower((string) strtok((string) $user->user_email, '@')) : '';
            $first = $display !== '' && strcasecmp($display, (string) $user->user_login) !== 0 ? trim((string) strtok($display, " \t")) : '';
            if ($first !== '' && $local !== '' && strtolower($first) === $local) {
                $first = '';
            }
        }
        if ($first === '' || strpos($first, '@') !== false) {
            return 'Player';
        }
        return mb_substr($first, 0, 30);
    }

    public static function level_label($key) {
        $levels = RSL_Players::levels();
        return isset($levels[$key]) ? $levels[$key] : '';
    }

    /** Positions as labels, from a player's CSV. */
    public static function position_labels($csv) {
        $all = RSL_Players::positions();
        $out = array();
        foreach (array_filter(explode(',', (string) $csv)) as $p) {
            if (isset($all[$p])) {
                $out[] = $all[$p];
            }
        }
        return $out;
    }

    /**
     * A rough playing band, 1 (never played) to 5 (State League), from the
     * registration answers — admin-only, used to group free agents. An
     * admin's override wins.
     */
    public static function band($player, $row = null) {
        if ($row && isset($row->band_override) && $row->band_override !== null && $row->band_override !== '') {
            return max(1.0, min(5.0, (float) $row->band_override));
        }
        $base = array('never' => 1.0, 'school' => 2.0, 'social' => 2.5, 'vvl' => 3.5, 'state' => 4.5);
        $band = $player && isset($base[$player->level]) ? $base[$player->level] : 2.0;
        if ($player && $player->years === 'lt1') {
            $band -= 0.5;
        } elseif ($player && $player->years === 'gt3') {
            $band += 0.5;
        }
        return max(1.0, min(5.0, $band));
    }

    /**
     * The one place that builds what a captain may see about a free agent:
     * first name, level, positions, how many friends come with them. Tests
     * pin these keys — add nothing personal here.
     */
    private static function card($lead, $player, $friends_n) {
        return array(
            'fa_id' => (int) $lead->id,
            'first_name' => self::first_name($lead->user_id),
            'level_label' => $player ? self::level_label($player->level) : '',
            'positions' => $player ? self::position_labels($player->positions) : array(),
            'friends_n' => (int) $friends_n,
        );
    }

    /**
     * Open units of a season (everyone still to be placed — looking, or left
     * over by a placement run and first in line for spots) with each
     * member's player profile. Same people as is_open() and group_members().
     */
    private static function open_units($season) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT f.*, p.level, p.years, p.positions
            FROM " . RSL_Database::table('free_agents') . " f
            JOIN " . RSL_Database::table('players') . " p ON p.user_id = f.user_id
            WHERE f.season = %s AND f.status IN ('looking', 'unplaced')
            ORDER BY f.created_at, f.user_id
        ", $season));
        $units = array();
        foreach ($rows as $r) {
            $key = $r->group_code !== '' ? 'g:' . $r->group_code : 'u:' . $r->user_id;
            $units[$key][] = $r;
        }
        return $units;
    }

    /** Teams that have said no to someone (a declined request), or that they declined. */
    private static function declined_pairs($season) {
        global $wpdb;
        $out = array();
        foreach ($wpdb->get_results($wpdb->prepare("SELECT team_id, user_id FROM " . RSL_Database::table('join_requests') . " WHERE season = %s AND status = 'declined'", $season)) as $r) {
            $out[intval($r->team_id)][intval($r->user_id)] = true;
        }
        foreach ($wpdb->get_results($wpdb->prepare("SELECT i.team_id, i.user_id FROM " . RSL_Database::table('invites') . " i JOIN " . RSL_Database::table('teams') . " t ON t.id = i.team_id WHERE t.season = %s AND i.status = 'declined'", $season)) as $r) {
            $out[intval($r->team_id)][intval($r->user_id)] = true;
        }
        return $out;
    }

    /** Has this team said no to (or been turned down by) anyone in the unit? */
    private static function unit_declined($declined, $team_id, $unit) {
        foreach ($unit as $r) {
            if (isset($declined[intval($team_id)][intval($r->user_id)])) {
                return true;
            }
        }
        return false;
    }

    /**
     * The free agent list as a captain sees it: units that could join this
     * team (right division, room, nobody under 18 unless allowed — see
     * under18_for() — browsable,
     * not turned down either way, not already invited). Empty for anyone
     * but the team's captain, or outside the asking/inviting phases.
     * $filters: position (key), level (key).
     */
    public static function browse_for_captain($team_id, $captain_id, $filters = array()) {
        global $wpdb;
        $team = RSL_Teams::get($team_id);
        if (!$team || $team->status !== 'active' || !RSL_Teams::is_captain($team->id, $captain_id) || !self::matching_open($team->season)) {
            return array();
        }
        $settings = self::settings();
        $room = self::room_for($team);
        $declined = self::declined_pairs($team->season);
        $invited = array();
        foreach ($wpdb->get_col($wpdb->prepare("SELECT user_id FROM " . RSL_Database::table('invites') . " WHERE team_id = %d AND status = 'pending' AND (expires_at IS NULL OR expires_at > %s)", $team->id, current_time('mysql'))) as $uid) {
            $invited[intval($uid)] = true;
        }
        // Someone who has already asked this team is answered under "Requests to join", not invited again.
        $asked_codes = array();
        foreach ($wpdb->get_results($wpdb->prepare("SELECT user_id, group_code FROM " . RSL_Database::table('join_requests') . " WHERE team_id = %d AND status = 'pending' AND expires_at > %s", $team->id, current_time('mysql'))) as $req) {
            $invited[intval($req->user_id)] = true;
            if ($req->group_code !== '') {
                $asked_codes[$req->group_code] = true;
            }
        }
        $position = isset($filters['position']) ? (string) $filters['position'] : '';
        $level = isset($filters['level']) ? (string) $filters['level'] : '';
        $out = array();
        $units = self::open_units($team->season);
        $under18 = $settings['under18_in_pool'] ? array() : self::under18_for($units ? call_user_func_array('array_merge', array_values($units)) : array());
        foreach ($units as $unit) {
            $ok = count($unit) <= $room && in_array($team->division, self::group_divisions($unit), true) && !self::unit_declined($declined, $team->id, $unit);
            foreach ($unit as $r) {
                if (!$r->browsable || !empty($under18[intval($r->user_id)]) || isset($invited[intval($r->user_id)]) || ($r->group_code !== '' && isset($asked_codes[$r->group_code]))) {
                    $ok = false;
                }
            }
            $lead = $unit[0];
            if (!$ok || ($position !== '' && !in_array($position, explode(',', $lead->positions), true)) || ($level !== '' && $lead->level !== $level)) {
                continue;
            }
            $out[] = self::card($lead, $lead, count($unit) - 1);
        }
        return $out;
    }

    /** Free places on a team before it hits the maximum. */
    public static function room_for($team) {
        global $wpdb;
        $n = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . RSL_Database::table('team_members') . " WHERE team_id = %d AND left_at IS NULL", $team->id));
        return max(0, self::settings()['max_roster'] - $n);
    }

    // ------------------------------------------------------------------
    // Captains: open spots and pool invites
    // ------------------------------------------------------------------

    /** The captain says how many players they're after and who's welcome. */
    public static function set_spots($team_id, $user_id, $data) {
        global $wpdb;
        $team = RSL_Teams::get($team_id);
        if (!$team) {
            return new WP_Error('rsl_team', 'That team no longer exists.');
        }
        if (!RSL_Teams::is_captain($team->id, $user_id) && !user_can($user_id, 'manage_options')) {
            return new WP_Error('rsl_not_captain', 'Only the captain can change that.');
        }
        $positions = array_values(array_intersect((array) (isset($data['positions']) ? $data['positions'] : array()), array_keys(RSL_Players::positions())));
        $level = isset($data['level']) && isset(self::spots_levels()[$data['level']]) ? $data['level'] : 'any';
        $note = isset($data['note']) ? mb_substr(trim(sanitize_text_field($data['note'])), 0, 100) : '';
        $wpdb->update(RSL_Database::table('teams'), array(
            'spots_open' => max(0, min(4, intval(isset($data['spots']) ? $data['spots'] : 0))),
            'spots_positions' => implode(',', $positions),
            'spots_level' => $level,
            'spots_note' => $note,
        ), array('id' => $team->id));
        return RSL_Teams::get($team->id);
    }

    /** A team's pool invites still waiting. */
    private static function waiting_pool_invites($team_id) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . RSL_Database::table('invites') . " WHERE team_id = %d AND source = 'pool' AND status = 'pending' AND expires_at > %s", $team_id, current_time('mysql')));
    }

    /** The captain invites a free agent (their whole group) from the list. They must accept. */
    public static function invite_from_pool($team_id, $captain_id, $fa_id) {
        global $wpdb;
        $team = RSL_Teams::get($team_id);
        if (!$team || $team->status !== 'active') {
            return new WP_Error('rsl_team', 'Invites open once your team is approved.');
        }
        if (!RSL_Teams::is_captain($team->id, $captain_id)) {
            return new WP_Error('rsl_not_captain', 'Only the captain can invite players.');
        }
        if (!self::matching_open($team->season)) {
            return new WP_Error('rsl_fa_phase', 'Inviting from the free agent list is closed while placements are being made.');
        }
        $row = self::get_by_id($fa_id);
        $card = null;
        foreach (self::browse_for_captain($team->id, $captain_id) as $c) {
            if ($c['fa_id'] === intval($fa_id)) {
                $card = $c;
            }
        }
        if (!$row || $row->season !== $team->season || !$card) {
            return new WP_Error('rsl_fa_gone', 'That player isn\'t available to invite any more.');
        }
        $cap = intval($team->spots_open) + self::settings()['pool_invites_extra'];
        if (self::waiting_pool_invites($team->id) >= $cap) {
            return new WP_Error('rsl_fa_cap', 'You have ' . $cap . ' invites waiting — wait for answers, or open more spots, before inviting more.');
        }
        $unit = self::unit_rows($row);
        $now = current_time('mysql');
        $wpdb->insert(RSL_Database::table('invites'), array(
            'team_id' => $team->id,
            'user_id' => $unit[0]->user_id,
            'email' => '',
            'invited_by' => $captain_id,
            'source' => 'pool',
            'group_code' => count($unit) > 1 ? $unit[0]->group_code : '',
            'expires_at' => self::expiry_for($team->season),
            'created_at' => $now,
        ));
        $id = (int) $wpdb->insert_id;
        $invite = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . RSL_Database::table('invites') . " WHERE id = %d", $id));
        self::mail_pool_invite($invite, $team, count($unit) - 1);
        return $id;
    }

    /**
     * Put a whole unit on a team (used when an invite, a request or an admin
     * places a group). Checks there's room for everyone first, and holds the
     * team's lock from that check to the last add so nobody else fills the
     * team part-way. Returns true or WP_Error (anyone already added stays
     * added — e.g. one of them joined another team meanwhile).
     */
    public static function add_unit($team_id, $user_ids, $how, $admin = false) {
        $team = RSL_Teams::get($team_id);
        if (!$team) {
            return new WP_Error('rsl_team', 'That team no longer exists.');
        }
        $user_ids = array_values(array_unique(array_map('intval', $user_ids)));
        $lock = $admin ? '' : RSL_Teams::team_lock_name($team->id);
        if ($lock !== '' && !RSL_Teams::lock($lock)) {
            return new WP_Error('rsl_busy', 'Lots of people are joining ' . $team->name . ' right now — please try again.');
        }
        try {
            if (!$admin && count($user_ids) > self::room_for($team)) {
                return new WP_Error('rsl_full', $team->name . ' doesn\'t have room for ' . (count($user_ids) > 1 ? 'all ' . count($user_ids) . ' of you' : 'another player') . ' — ask the captain.');
            }
            foreach ($user_ids as $uid) {
                $r = RSL_Teams::add_member($team->id, $uid, 'player', false, array('admin' => $admin, 'how' => $how));
                if (is_wp_error($r)) {
                    return $r;
                }
            }
        } finally {
            if ($lock !== '') {
                RSL_Teams::unlock($lock);
            }
        }
        return true;
    }

    /** The user ids an invite or request brings onto the team. */
    public static function unit_user_ids($season, $user_id, $group_code) {
        $ids = array(intval($user_id));
        if ($group_code !== '') {
            foreach (self::group_members($season, $group_code) as $m) {
                $ids[] = intval($m->user_id);
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * Who accepting a pool invite brings onto the team: the invitee's friend
     * group as it is now (or just them). Accepting is consent for the invitee
     * alone, so it's refused when their group has changed since the invite
     * (the captain asked a different unit), or when a friend hasn't agreed to
     * the team's division. Returns user ids or WP_Error.
     */
    public static function pool_invite_ids($invite, $team, $user_id) {
        $row = self::get($user_id, $team->season);
        if (!self::is_open($row)) {
            return array(intval($user_id));
        }
        $unit = self::unit_rows($row);
        if (count($unit) > 1 && $row->group_code !== (string) $invite->group_code) {
            return new WP_Error('rsl_fa_group', 'Your friend group has changed since ' . $team->name . ' invited you — ask the captain to invite you again.');
        }
        $ids = array();
        $friends = array();
        foreach ($unit as $m) {
            $ids[] = intval($m->user_id);
            if (intval($m->user_id) !== intval($user_id)) {
                $friends[] = $m;
            }
        }
        if ($friends && !in_array($team->division, self::group_divisions($friends), true)) {
            return new WP_Error('rsl_fa_division', 'Not everyone in your friend group has agreed to play in ' . RSL_Teams::DIVISIONS[$team->division] . ' — once they\'ve changed their division choice on their league page, accept again.');
        }
        return $ids;
    }

    // ------------------------------------------------------------------
    // Free agents ask teams
    // ------------------------------------------------------------------

    /** Requests still waiting from a unit (a group shares the limit). */
    private static function waiting_requests($row) {
        global $wpdb;
        $now = current_time('mysql');
        if ($row->group_code !== '') {
            return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . RSL_Database::table('join_requests') . " WHERE season = %s AND status = 'pending' AND expires_at > %s AND (group_code = %s OR user_id = %d)", $row->season, $now, $row->group_code, $row->user_id));
        }
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . RSL_Database::table('join_requests') . " WHERE season = %s AND status = 'pending' AND expires_at > %s AND user_id = %d", $row->season, $now, $row->user_id));
    }

    /** Is there a waiting request from this unit to this team? */
    private static function waiting_request_to($row, $team_id) {
        global $wpdb;
        $now = current_time('mysql');
        $unit_ids = array_map(function ($r) { return intval($r->user_id); }, self::unit_rows($row));
        if (!$unit_ids) {
            return null;
        }
        $in = implode(',', array_map('intval', $unit_ids));
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . RSL_Database::table('join_requests') . " WHERE team_id = %d AND status = 'pending' AND expires_at > %s AND user_id IN ($in) LIMIT 1", $team_id, $now));
    }

    /**
     * Teams a free agent could ask: active, looking for players, the right
     * division for them (and their group), with room. Each item: team,
     * members, room, can_ask, why (when they can't), waiting (their request
     * if one is waiting), for_n (friends coming too).
     */
    public static function teams_looking($season, $user_id) {
        global $wpdb;
        $row = self::get($user_id, $season);
        $unit = self::is_open($row) ? self::unit_rows($row) : array();
        $allowed = $unit ? self::group_divisions($unit) : array();
        $declined = self::declined_pairs($season);
        $settings = self::settings();
        $full = $row && self::is_open($row) && self::waiting_requests($row) >= $settings['max_requests'];
        $open = self::matching_open($season);
        $out = array();
        foreach (RSL_Teams::list_for_season($season) as $t) {
            if (intval($t->spots_open) < 1 || !in_array($t->division, $allowed, true)) {
                continue;
            }
            $room = max(0, $settings['max_roster'] - intval($t->member_count));
            $waiting = self::waiting_request_to($row, $t->id);
            $why = '';
            if ($waiting) {
                $why = 'Waiting for the captain';
            } elseif (!$open) {
                $why = 'Requests are closed while placements are made';
            } elseif (self::unit_declined($declined, $t->id, $unit)) {
                $why = 'Not this time';
            } elseif ($room < count($unit)) {
                $why = 'Full';
            } elseif ($full) {
                $why = 'You have ' . $settings['max_requests'] . ' requests waiting';
            }
            $out[] = array('team' => $t, 'members' => intval($t->member_count), 'room' => $room, 'can_ask' => $why === '', 'why' => $why, 'waiting' => $waiting, 'for_n' => count($unit) - 1);
        }
        return $out;
    }

    /** A free agent (for their group) asks a team to take them. */
    public static function request_join($team_id, $user_id) {
        global $wpdb;
        $team = RSL_Teams::get($team_id);
        if (!$team || $team->status !== 'active') {
            return new WP_Error('rsl_team', 'That team isn\'t taking players.');
        }
        $row = self::get($user_id, $team->season);
        if (!self::is_open($row)) {
            return new WP_Error('rsl_fa_none', 'Join the free agent list first.');
        }
        foreach (self::teams_looking($team->season, $user_id) as $item) {
            if (intval($item['team']->id) !== intval($team->id)) {
                continue;
            }
            if (!$item['can_ask']) {
                return new WP_Error('rsl_fa_cant', $item['why'] === 'Waiting for the captain' ? 'You\'ve already asked ' . $team->name . '.' : $item['why'] . '.');
            }
            $unit = self::unit_rows($row);
            $now = current_time('mysql');
            $wpdb->insert(RSL_Database::table('join_requests'), array(
                'season' => $team->season,
                'team_id' => $team->id,
                'user_id' => $user_id,
                'group_code' => count($unit) > 1 ? $row->group_code : '',
                'status' => 'pending',
                'created_at' => $now,
                'expires_at' => self::expiry_for($team->season),
            ));
            $id = (int) $wpdb->insert_id;
            self::mail_request_received($id);
            return $id;
        }
        return new WP_Error('rsl_fa_cant', $team->name . ' isn\'t looking for players in your division right now.');
    }

    public static function withdraw_request($request_id, $user_id) {
        global $wpdb;
        $table = RSL_Database::table('join_requests');
        $req = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d AND status = 'pending'", $request_id));
        if (!$req) {
            return new WP_Error('rsl_fa_req', 'That request isn\'t open any more.');
        }
        $row = self::get($user_id, $req->season);
        $mine = intval($req->user_id) === intval($user_id) || ($row && $req->group_code !== '' && $row->group_code === $req->group_code);
        if (!$mine) {
            return new WP_Error('rsl_fa_req', 'That request isn\'t open any more.');
        }
        $wpdb->update($table, array('status' => 'withdrawn', 'responded_at' => current_time('mysql')), array('id' => $req->id));
        return true;
    }

    /** The team's captain (or an admin) answers a request. Accepting adds the whole unit. */
    public static function respond_request($request_id, $captain_id, $accept) {
        global $wpdb;
        $table = RSL_Database::table('join_requests');
        $req = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d AND status = 'pending' AND expires_at > %s", $request_id, current_time('mysql')));
        $team = $req ? RSL_Teams::get($req->team_id) : null;
        if (!$req || !$team) {
            return new WP_Error('rsl_fa_req', 'That request isn\'t open any more.');
        }
        if (!RSL_Teams::is_captain($team->id, $captain_id) && !user_can($captain_id, 'manage_options')) {
            return new WP_Error('rsl_not_captain', 'Only the team\'s captain can answer requests.');
        }
        if (!$accept) {
            $wpdb->update($table, array('status' => 'declined', 'responded_at' => current_time('mysql'), 'responded_by' => $captain_id), array('id' => $req->id));
            self::mail_request_answer($req, $team, false);
            return true;
        }
        if (!self::matching_open($team->season)) {
            return new WP_Error('rsl_fa_phase', 'Placements are being made right now — the league will be in touch.');
        }
        $requester = self::get($req->user_id, $req->season);
        if (!self::is_open($requester)) {
            $wpdb->update($table, array('status' => 'closed', 'responded_at' => current_time('mysql')), array('id' => $req->id));
            return new WP_Error('rsl_fa_gone', 'They\'ve joined a team already.');
        }
        if (!in_array($team->division, self::group_divisions(self::unit_rows($requester)), true)) {
            return new WP_Error('rsl_fa_division', 'They haven\'t agreed to play in ' . RSL_Teams::DIVISIONS[$team->division] . '.');
        }
        $ids = self::unit_user_ids($req->season, $req->user_id, $req->group_code);
        $result = self::add_unit($team->id, $ids, 'request');
        if (is_wp_error($result)) {
            // RSL_Teams::add_member()'s messages name the player (display name) and any
            // other team they're on; the captain only knows a first name. Same codes.
            $words = array(
                'rsl_elsewhere' => 'They\'ve joined a team already.',
                'rsl_pending' => 'They can\'t join a team right now.',
                'rsl_already' => 'They\'re on ' . $team->name . ' already.',
                'rsl_busy' => 'Please try again in a moment.',
                'rsl_full' => $team->name . ' doesn\'t have room for them' . (count($ids) > 1 ? ' all' : '') . '.',
            );
            $code = $result->get_error_code();
            return isset($words[$code]) ? new WP_Error($code, $words[$code]) : $result;
        }
        $wpdb->update($table, array('status' => 'accepted', 'responded_at' => current_time('mysql'), 'responded_by' => $captain_id), array('id' => $req->id));
        self::mail_contact_swap($team, $ids, 'req-ok:' . $req->id);
        return true;
    }

    /** Requests waiting on a team, as the captain sees them (see card()). */
    public static function requests_for_team($team_id) {
        global $wpdb;
        $out = array();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . RSL_Database::table('join_requests') . " WHERE team_id = %d AND status = 'pending' AND expires_at > %s ORDER BY created_at", $team_id, current_time('mysql')));
        foreach ($rows as $req) {
            $row = self::get($req->user_id, $req->season);
            if (!self::is_open($row)) {
                continue;
            }
            $card = self::card($row, RSL_Players::get($req->user_id), count(self::unit_user_ids($req->season, $req->user_id, $req->group_code)) - 1);
            $card['request_id'] = (int) $req->id;
            $card['expires_at'] = $req->expires_at;
            $out[] = $card;
        }
        return $out;
    }

    /**
     * Join requests waiting per team for a season — the same rule captains
     * see in requests_for_team(): pending, not expired, from someone still on
     * the list. team_id => n.
     */
    public static function request_counts($season) {
        global $wpdb;
        $out = array();
        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT r.team_id, COUNT(*) AS n
            FROM " . RSL_Database::table('join_requests') . " r
            JOIN " . RSL_Database::table('free_agents') . " f ON f.season = r.season AND f.user_id = r.user_id AND f.status IN ('looking', 'unplaced')
            WHERE r.season = %s AND r.status = 'pending' AND r.expires_at > %s
            GROUP BY r.team_id
        ", $season, current_time('mysql')));
        foreach ($rows as $row) {
            $out[intval($row->team_id)] = (int) $row->n;
        }
        return $out;
    }

    /** A free agent's requests this season (theirs and their group's), newest first, with team names. */
    public static function requests_for_user($user_id, $season) {
        global $wpdb;
        $row = self::get($user_id, $season);
        $code = $row ? $row->group_code : '';
        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT r.*, t.name AS team_name, t.slug, t.colour, t.division
            FROM " . RSL_Database::table('join_requests') . " r
            JOIN " . RSL_Database::table('teams') . " t ON t.id = r.team_id
            WHERE r.season = %s AND (r.user_id = %d OR (%s <> '' AND r.group_code = %s))
            ORDER BY r.created_at DESC
        ", $season, $user_id, $code, $code));
        $now = current_time('mysql');
        foreach ($rows as $r) {
            if ($r->status === 'pending' && $r->expires_at <= $now) {
                $r->status = 'expired';
            }
        }
        return $rows;
    }

    // ------------------------------------------------------------------
    // Joining, leaving, admin placing
    // ------------------------------------------------------------------

    /** Close someone's waiting requests and pool invites (and their group's requests if nobody's left). */
    private static function close_open_offers($user_id, $season, $status = 'closed') {
        global $wpdb;
        $now = current_time('mysql');
        $wpdb->query($wpdb->prepare("UPDATE " . RSL_Database::table('join_requests') . " SET status = %s, responded_at = %s WHERE season = %s AND user_id = %d AND status = 'pending'", $status, $now, $season, $user_id));
        $wpdb->query($wpdb->prepare("
            UPDATE " . RSL_Database::table('invites') . " i JOIN " . RSL_Database::table('teams') . " t ON t.id = i.team_id
            SET i.status = 'closed', i.responded_at = %s
            WHERE i.user_id = %d AND i.status = 'pending' AND i.source = 'pool' AND t.season = %s
        ", $now, $user_id, $season));
    }

    /**
     * Someone joined a team, by any route: their list entry is marked
     * placed, and their other requests and pool invites close.
     */
    public static function on_member_added($team_id, $user_id, $how) {
        global $wpdb;
        $team = RSL_Teams::get($team_id);
        if (!$team) {
            return;
        }
        $row = self::get($user_id, $team->season);
        self::set_looking_flag($user_id, false);
        if (!self::is_open($row)) {
            return;
        }
        $wpdb->update(RSL_Database::table('free_agents'), array(
            'status' => 'placed',
            'team_id' => $team->id,
            'placed_via' => substr((string) $how, 0, 20),
            'placed_at' => current_time('mysql'),
            'proposed_dest' => '',
            'proposed_locked' => 0,
            'updated_at' => current_time('mysql'),
        ), array('id' => $row->id));
        self::close_open_offers($user_id, $team->season);
        // A group request nobody is left to use closes too.
        if ($row->group_code !== '' && !self::group_members($team->season, $row->group_code)) {
            $wpdb->query($wpdb->prepare("UPDATE " . RSL_Database::table('join_requests') . " SET status = 'closed', responded_at = %s WHERE season = %s AND group_code = %s AND status = 'pending'", current_time('mysql'), $team->season, $row->group_code));
        }
    }

    /** Someone was taken off a team: point them back to the list. */
    public static function on_member_removed($team_id, $user_id) {
        global $wpdb;
        if (self::$quiet) {
            return;
        }
        $team = RSL_Teams::get($team_id);
        if (!$team || $team->status !== 'active') {
            return;
        }
        $row = self::get($user_id, $team->season);
        if ($row && $row->status === 'placed' && intval($row->team_id) === intval($team->id)) {
            $wpdb->update(RSL_Database::table('free_agents'), array('status' => 'withdrawn', 'team_id' => 0, 'optout_until' => null, 'updated_at' => current_time('mysql')), array('id' => $row->id));
        }
        self::mail(
            $user_id,
            'You\'re no longer on ' . $team->name,
            "You're no longer on {$team->name} in the Reds Social League.\n\n"
                . "Still keen to play this season? Put yourself on the free agent list and we'll find you a team:\n" . RSL_Portal::url(),
            'removed:' . $team->id . ':' . $user_id . ':' . current_time('Y-m-d')
        );
    }

    /**
     * A captain application was declined or withdrawn (RSL_Teams releases its
     * members without "removed" emails). Applying marked the applicant's list
     * entry placed on the pending team; it goes back to looking, so they
     * aren't left off the list "on" a team that never happened. The friend
     * group isn't restored (it may have changed) — they can rejoin with the
     * code. Returns how many entries went back.
     */
    public static function application_closed($team_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . RSL_Database::table('free_agents') . " WHERE team_id = %d AND status = 'placed'", $team_id));
        foreach ($rows as $row) {
            $wpdb->update(RSL_Database::table('free_agents'), array(
                'status' => 'looking', 'team_id' => 0, 'placed_via' => '', 'placed_at' => null, 'optout_until' => null,
                'group_code' => '', 'proposed_dest' => '', 'proposed_locked' => 0, 'updated_at' => current_time('mysql'),
            ), array('id' => $row->id));
            self::set_looking_flag($row->user_id, true);
        }
        return count($rows);
    }

    /**
     * An admin puts a free agent (with their group) straight onto a team.
     * Into a division someone in the unit hasn't agreed to, it becomes an
     * invite to that person instead (accepting speaks for them alone, so one
     * invite can only ask one person). Returns 'placed', 'invited' or WP_Error.
     */
    public static function place_now($user_id, $team_id, $admin_id) {
        global $wpdb;
        $team = RSL_Teams::get($team_id);
        if (!$team || $team->status !== 'active') {
            return new WP_Error('rsl_team', 'Pick an active team.');
        }
        $row = self::get($user_id, $team->season);
        if (!self::is_open($row)) {
            return new WP_Error('rsl_fa_none', 'They\'re not waiting to be placed this season.');
        }
        $unit = self::unit_rows($row);
        $ids = array_map(function ($r) { return intval($r->user_id); }, $unit);
        if (!in_array($team->division, self::group_divisions($unit), true)) {
            $ask = array_values(array_filter($unit, function ($r) use ($team) {
                return !in_array($team->division, self::allowed_divisions($r->division_pref), true);
            }));
            if (count($ask) > 1) {
                return new WP_Error('rsl_fa_division', count($ask) . ' of this group haven\'t agreed to play in ' . RSL_Teams::DIVISIONS[$team->division]
                    . ', and an invite can only ask one person. Pick a team in a division they all chose, or ask them to change their division choice first.');
            }
            $wpdb->insert(RSL_Database::table('invites'), array(
                'team_id' => $team->id, 'user_id' => $ask[0]->user_id, 'email' => '', 'invited_by' => $admin_id,
                'source' => 'pool', 'group_code' => count($unit) > 1 ? $unit[0]->group_code : '',
                'expires_at' => date('Y-m-d H:i:s', current_time('timestamp') + self::settings()['request_days'] * DAY_IN_SECONDS),
                'created_at' => current_time('mysql'),
            ));
            $invite = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . RSL_Database::table('invites') . " WHERE id = %d", $wpdb->insert_id));
            self::mail_pool_invite($invite, $team, count($unit) - 1);
            return 'invited';
        }
        $result = self::add_unit($team->id, $ids, 'admin', true);
        if (is_wp_error($result)) {
            return $result;
        }
        self::mail_contact_swap($team, $ids, 'admin-place:' . $team->id);
        return 'placed';
    }

    /** Someone placed by the league can say "not for me" for a few days, at no cost. */
    public static function not_for_me($user_id, $season) {
        global $wpdb;
        $row = self::get($user_id, $season);
        if (!$row || $row->status !== 'placed' || !$row->optout_until || $row->optout_until < current_time('mysql')) {
            return new WP_Error('rsl_fa_optout', 'You can\'t step back from this team here any more — talk to your captain or the league.');
        }
        $team = RSL_Teams::get($row->team_id);
        if ($team && RSL_Teams::is_captain($team->id, $user_id)) {
            return new WP_Error('rsl_fa_optout', 'You\'re the captain — ask the league to hand the team to someone else first.');
        }
        if ($team) {
            self::$quiet = true;
            RSL_Teams::remove_member($team->id, $user_id);
            self::$quiet = false;
        }
        $wpdb->update(RSL_Database::table('free_agents'), array('status' => 'looking', 'team_id' => 0, 'placed_via' => '', 'placed_at' => null, 'optout_until' => null, 'proposed_dest' => '', 'proposed_locked' => 0, 'updated_at' => current_time('mysql')), array('id' => $row->id));
        self::set_looking_flag($user_id, true);
        if ($team) {
            $name = self::first_name($user_id);
            $captain = RSL_Teams::captain($team->id);
            $text = "$name has stepped back from {$team->name} (they were placed by the league and chose 'Not for me'). They're back on the free agent list.";
            if ($captain) {
                self::mail($captain->user_id, $name . ' has stepped back from ' . $team->name, $text, 'optout:' . $season . ':' . $user_id . ':cap');
            }
            foreach (self::league_contacts() as $i => $email) {
                self::mail($email, '[Social League] ' . $name . ' stepped back from ' . $team->name, $text . "\n\n" . admin_url('admin.php?page=rsl-free-agents'), 'optout:' . $season . ':' . $user_id . ':admin' . $i);
            }
        }
        return true;
    }

    // ------------------------------------------------------------------
    // Housekeeping (hourly cron; nothing correct depends on it)
    // ------------------------------------------------------------------

    /** Mark requests and pool invites past their expiry, and say so. */
    public static function expire_stale() {
        global $wpdb;
        $now = current_time('mysql');
        $reqs = $wpdb->get_results($wpdb->prepare("SELECT * FROM " . RSL_Database::table('join_requests') . " WHERE status = 'pending' AND expires_at <= %s", $now));
        foreach ($reqs as $req) {
            $wpdb->update(RSL_Database::table('join_requests'), array('status' => 'expired'), array('id' => $req->id));
            $team = RSL_Teams::get($req->team_id);
            if ($team) {
                self::mail_request_answer($req, $team, null);
            }
        }
        $wpdb->query($wpdb->prepare("UPDATE " . RSL_Database::table('invites') . " SET status = 'expired' WHERE status = 'pending' AND expires_at IS NOT NULL AND expires_at <= %s", $now));
        return count($reqs);
    }

    /** Once a season, tell the league placements are ready to make. */
    public static function deadline_check() {
        foreach (array_unique(array(RSL_Seasons::signup(), RSL_Seasons::current())) as $season) {
            $b = self::building($season);
            if (self::phase($season) !== 'placing' || $b['deadline_mailed']) {
                continue;
            }
            $c = self::counts($season);
            $short = 0;
            foreach (RSL_Teams::list_for_season($season) as $t) {
                if (intval($t->member_count) < self::settings()['min_roster']) {
                    $short++;
                }
            }
            foreach (self::league_contacts() as $i => $email) {
                self::mail($email, '[Social League] Placements ready for ' . RSL_Seasons::label($season),
                    "The team-building deadline has passed.\n\n{$c['looking']} free agents are waiting to be placed and $short teams are short of players.\n\n"
                    . "Make and confirm placements on Social League > Free Agents:\n" . admin_url('admin.php?page=rsl-free-agents&tab=placement&season=' . $season),
                    'deadline:' . $season . ':' . $i);
            }
            $b['deadline_mailed'] = 1;
            self::save_building($season, $b);
        }
    }

    // ------------------------------------------------------------------
    // Admin lists
    // ------------------------------------------------------------------

    /** Counts by status for a season. */
    public static function counts($season) {
        global $wpdb;
        $out = array_fill_keys(array_keys(self::statuses()), 0);
        foreach ($wpdb->get_results($wpdb->prepare("SELECT status, COUNT(*) AS n FROM " . RSL_Database::table('free_agents') . " WHERE season = %s GROUP BY status", $season)) as $r) {
            $out[$r->status] = (int) $r->n;
        }
        return $out;
    }

    /**
     * Everyone on a season's list with their profile, for admins (one
     * query). Adds name, email, first_name, level, years, positions,
     * requests_waiting, team_name.
     */
    public static function pool($season, $statuses = array('looking', 'unplaced')) {
        global $wpdb;
        $statuses = array_values(array_intersect((array) $statuses, array_keys(self::statuses())));
        if (!$statuses) {
            return array();
        }
        $in = implode(',', array_map(function ($s) { return "'" . esc_sql($s) . "'"; }, $statuses));
        return $wpdb->get_results($wpdb->prepare("
            SELECT f.*, p.level, p.years, p.positions, u.display_name, u.user_email,
                fn.meta_value AS first_name_meta, t.name AS team_name,
                (SELECT COUNT(*) FROM " . RSL_Database::table('join_requests') . " r WHERE r.season = f.season AND r.user_id = f.user_id AND r.status = 'pending' AND r.expires_at > %s) AS requests_waiting
            FROM " . RSL_Database::table('free_agents') . " f
            JOIN " . RSL_Database::table('players') . " p ON p.user_id = f.user_id
            JOIN {$wpdb->users} u ON u.ID = f.user_id
            LEFT JOIN {$wpdb->usermeta} fn ON fn.user_id = f.user_id AND fn.meta_key = 'first_name'
            LEFT JOIN " . RSL_Database::table('teams') . " t ON t.id = f.team_id
            WHERE f.season = %s AND f.status IN ($in)
            ORDER BY f.status, f.created_at, f.user_id
        ", current_time('mysql'), $season));
    }

    /**
     * Who on the list counts as under 18: from the account's date of birth
     * for the row's season (RSL_Players::under18_map(), so no date of birth
     * counts too), or marked by an admin (the under18 column, which only
     * admin_update() writes, for an account whose date of birth is wrong).
     * $rows are free agent rows (user_id, under18, and season unless
     * $season is given); one query per season. Returns user id => true for
     * each under-18.
     */
    public static function under18_for($rows, $season = '') {
        $by_season = array();
        foreach ($rows as $r) {
            $by_season[isset($r->season) && $r->season !== '' ? (string) $r->season : (string) $season][] = intval($r->user_id);
        }
        $out = array();
        foreach ($by_season as $season => $ids) {
            foreach (RSL_Players::under18_map($ids, $season) as $id => $under) {
                if ($under) {
                    $out[$id] = true;
                }
            }
        }
        foreach ($rows as $r) {
            if (!empty($r->under18)) {
                $out[intval($r->user_id)] = true;
            }
        }
        return $out;
    }

    /** Admin-only fields: band override, a note, and "treat as under 18". */
    public static function admin_update($fa_id, $data) {
        global $wpdb;
        $row = self::get_by_id($fa_id);
        if (!$row) {
            return new WP_Error('rsl_fa_none', 'That free agent no longer exists.');
        }
        $band = isset($data['band_override']) ? trim((string) $data['band_override']) : '';
        $fields = array(
            'band_override' => $band === '' ? null : max(1, min(5, round((float) $band * 2) / 2)),
            'admin_note' => isset($data['admin_note']) ? mb_substr(sanitize_text_field($data['admin_note']), 0, 255) : $row->admin_note,
            'updated_at' => current_time('mysql'),
        );
        if (isset($data['under18'])) {
            $fields['under18'] = $data['under18'] ? 1 : 0;
        }
        $wpdb->update(RSL_Database::table('free_agents'), $fields, array('id' => $row->id));
        return self::get_by_id($row->id);
    }

    // ------------------------------------------------------------------
    // Email (all queued through RSL_Mail)
    // ------------------------------------------------------------------

    /**
     * Where league notices go: the league contact email(s) set under Team
     * Balances → Rules (the same people who hear about "can't make it"s),
     * else the site admin email.
     */
    public static function league_contacts() {
        $to = array_values(array_filter(array_map('sanitize_email', RSL_Payments::contact_emails())));
        return $to ? $to : array_values(array_filter(array(sanitize_email((string) get_option('admin_email')))));
    }

    /** Queue one email with a greeting and sign-off: queued as plain text; sent in the club look. */
    public static function mail($to, $subject, $text, $dedupe) {
        $hello = is_numeric($to) ? 'Hi ' . self::first_name($to) . ",\n\n" : '';
        return RSL_Mail::queue($to, $subject, $hello . $text . "\n\n— Reds Social League\n", $dedupe);
    }

    private static function date_words($ymd) {
        return date_i18n('D j M', strtotime($ymd));
    }

    private static function mail_joined($user_id, $season) {
        $d = self::dates($season);
        $lines = "You're on the free agent list for the " . RSL_Seasons::label($season) . " Reds Social League. You don't have to do anything else — we'll find you a team.\n\n";
        if ($d) {
            $lines .= "What happens next:\n"
                . "- Until " . self::date_words($d['deadline']) . ": teams looking for players can invite you, and you can ask up to " . self::settings()['max_requests'] . " teams yourself.\n"
                . "- By " . self::date_words($d['confirm_by']) . ": we place everyone else on a team and email you your team and captain.\n"
                . "- Before your first game (" . self::date_words($d['first_night']) . "): pay your social rego (" . RSL_Rego::cost_words() . ") unless you're a full or life club member.\n\n";
        }
        $lines .= "Your league page:\n" . RSL_Portal::url();
        self::mail($user_id, 'You\'re on the free agent list', $lines, 'fa-joined:' . $season . ':' . $user_id . ':' . current_time('Y-m-d'));
    }

    private static function mail_pool_invite($invite, $team, $friends_n) {
        $who = $friends_n > 0 ? 'you and your ' . ($friends_n === 1 ? 'friend' : $friends_n . ' friends') : 'you';
        self::mail($invite->user_id, $team->name . ' would like you to join',
            "{$team->name} (" . RSL_Teams::DIVISIONS[$team->division] . ") would like $who to join their team.\n\n"
                . "Accept or decline on your league page by " . date_i18n('D j M', strtotime($invite->expires_at)) . ":\n" . RSL_Portal::url() . "\n\n"
                . "See the team: " . RSL_Public::team_url($team),
            'inv:' . $invite->id);
    }

    private static function mail_request_received($request_id) {
        global $wpdb;
        $req = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . RSL_Database::table('join_requests') . " WHERE id = %d", $request_id));
        $team = $req ? RSL_Teams::get($req->team_id) : null;
        $captain = $team ? RSL_Teams::captain($team->id) : null;
        if (!$captain) {
            return;
        }
        $player = RSL_Players::get($req->user_id);
        $friends = count(self::unit_user_ids($req->season, $req->user_id, $req->group_code)) - 1;
        $positions = $player ? implode(', ', self::position_labels($player->positions)) : '';
        self::mail($captain->user_id, self::first_name($req->user_id) . ' would like to join ' . $team->name,
            self::first_name($req->user_id) . ($friends ? ' (with ' . $friends . ' friend' . ($friends > 1 ? 's' : '') . ')' : '') . " would like to join {$team->name}.\n"
                . 'Level: ' . ($player ? self::level_label($player->level) : '?') . ($positions !== '' ? "\nPositions: $positions" : '') . "\n\n"
                . "Accept or say not this time on your league page by " . date_i18n('D j M', strtotime($req->expires_at)) . ":\n" . RSL_Portal::url(),
            'req:' . $req->id);
    }

    /** $accepted false = declined, null = expired. */
    private static function mail_request_answer($req, $team, $accepted) {
        $d = self::dates($req->season);
        $after = $d && self::phase($req->season) === 'open' ? ' If you don\'t find a team by ' . self::date_words($d['deadline']) . ', we\'ll place you.' : '';
        foreach (self::unit_user_ids($req->season, $req->user_id, $req->group_code) as $uid) {
            if ($accepted === false) {
                self::mail($uid, 'About your request to join ' . $team->name, "{$team->name} can't take you this time. You can ask other teams from your league page." . $after . "\n\n" . RSL_Portal::url(), 'req-no:' . $req->id . ':' . $uid);
            } else {
                self::mail($uid, 'Your request to join ' . $team->name . ' expired', "{$team->name}'s captain didn't answer in time, so your request has lapsed. You can ask other teams from your league page." . $after . "\n\n" . RSL_Portal::url(), 'req-exp:' . $req->id . ':' . $uid);
            }
        }
    }

    /**
     * People who joined through the league (a request, a pool invite, an
     * admin) get their captain's name and email, and the captain theirs —
     * by email only; pages still show names only.
     */
    public static function mail_contact_swap($team, $user_ids, $dedupe_base) {
        $captain = RSL_Teams::captain($team->id);
        $d = self::dates($team->season);
        $first = $d ? "\nFirst night: " . self::date_words($d['first_night']) . '.' : '';
        $names = array();
        foreach ($user_ids as $uid) {
            $u = get_userdata($uid);
            if (!$u) {
                continue;
            }
            $names[] = $u->display_name . ' <' . $u->user_email . '>';
            $clear = RSL_Rego::clearance_for($uid, $team->season);
            self::mail($uid, 'You\'re on ' . $team->name,
                "You're on {$team->name} (" . RSL_Teams::DIVISIONS[$team->division] . ") in the Reds Social League." . $first . "\n\n"
                    . ($captain && intval($captain->user_id) !== intval($uid) ? "Your captain is {$captain->display_name} — {$captain->user_email}. Say hi, and they'll add you to the team chat.\n\n" : '')
                    . ($clear['cleared'] ? '' : "Before your first game, pay your social rego (" . RSL_Rego::cost_words() . ") unless you're a full or life club member:\n" . (RSL_Rego::pay_url() ?: RSL_Portal::url()) . "\n\n")
                    . "Your team page: " . RSL_Public::team_url($team),
                $dedupe_base . ':' . $uid);
        }
        if ($captain && $names && !in_array(intval($captain->user_id), array_map('intval', $user_ids), true)) {
            self::mail($captain->user_id, 'New player' . (count($names) > 1 ? 's' : '') . ' for ' . $team->name,
                (count($names) > 1 ? 'These players have' : 'This player has') . " joined {$team->name} through the league:\n\n- " . implode("\n- ", $names) . "\n\nPlease say hi and add them to your team chat. Their emails are for team contact only.",
                $dedupe_base . ':cap');
        }
    }
}
