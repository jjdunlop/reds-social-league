<?php
/**
 * Teams and rosters: the data layer. No output, no request handling — the
 * admin and public classes call these, and tests call them directly.
 * Methods return a result or a WP_Error with a message a person can read.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RSL_Teams {

    const DIVISIONS = array('ranked' => 'Ranked', 'unranked' => 'Unranked');
    const ROLES = array('captain' => 'Captain', 'player' => 'Player');

    /**
     * The captain's rough level at sign-up, described by how the team plays
     * (not the old A/B/C grades, which mean nothing to new players).
     * Admin-only after that (never public), and the seed for a Ranked team's
     * first-week matchmaking until results take over. Each maps to a
     * starting rating on the ~1500-average scale — for the fixtures rating
     * only; the ladder rating starts every team on the same number, frozen
     * on the season's first published Ranked night from the mean of that
     * night's teams' seeds (RSL_Ratings::start_for()), and never reads a
     * level after that.
     */
    public static function levels() {
        return array(
            'new' => 'New to volleyball — here for fun',
            'social' => 'Social — we can keep a rally going',
            'solid' => 'Solid — we are able to set up an attack on most points',
            'strong' => 'Strong — VVL State League and friends',
            'advanced' => 'Advanced — VVL Premier League players and friends',
        );
    }

    public static function level_seed_rating($level) {
        $seeds = array('new' => 1200, 'social' => 1350, 'solid' => 1500, 'strong' => 1650, 'advanced' => 1800);
        return isset($seeds[$level]) ? $seeds[$level] : 1500;
    }

    public static function time_preferences() {
        return array('any' => 'No preference', 'early' => 'Earlier games', 'late' => 'Later games');
    }

    public static function double_shapes() {
        return array('back' => 'Back-to-back', 'break' => 'With a break between');
    }

    /** The captain (or an admin) sets the team's fixturing preferences. */
    public static function set_preferences($team_id, $user_id, $prefs) {
        global $wpdb;
        $team = self::get($team_id);
        if (!$team) {
            return new WP_Error('rsl_team', 'That team no longer exists.');
        }
        if (!self::is_captain($team->id, $user_id) && !user_can($user_id, 'manage_options')) {
            return new WP_Error('rsl_not_captain', 'Only the captain can change the team\'s preferences.');
        }
        $time = isset($prefs['time']) && isset(self::time_preferences()[$prefs['time']]) ? $prefs['time'] : 'any';
        $double = !empty($prefs['double']) ? 1 : 0;
        $shape = isset($prefs['shape']) && isset(self::double_shapes()[$prefs['shape']]) ? $prefs['shape'] : 'back';
        $wpdb->update(self::table_teams(), array(
            'pref_time' => $time,
            'pref_double' => $double,
            'pref_double_shape' => $shape,
            'pref_standby' => !empty($prefs['standby']) ? 1 : 0,
        ), array('id' => $team->id));
        return self::get($team->id);
    }

    /** Admin sets (or corrects) a team's estimated level. */
    public static function set_level($team_id, $level) {
        global $wpdb;
        if (!isset(self::levels()[$level]) || !self::get($team_id)) {
            return new WP_Error('rsl_level', 'Pick a level.');
        }
        $wpdb->update(self::table_teams(), array('est_level' => $level), array('id' => $team_id));
        return self::get($team_id);
    }

    /** One line summarising a team's preferences (admin views). */
    public static function preferences_summary($team) {
        $parts = array(self::time_preferences()[isset(self::time_preferences()[$team->pref_time]) ? $team->pref_time : 'any']);
        $parts[] = $team->pref_double ? 'Happy to double-header (' . strtolower(self::double_shapes()[isset(self::double_shapes()[$team->pref_double_shape]) ? $team->pref_double_shape : 'back']) . ')' : 'One game a night';
        if ($team->pref_standby) {
            $parts[] = 'On standby for late games';
        }
        return implode(' · ', $parts);
    }

    private static function table_teams() {
        return RSL_Database::table('teams');
    }

    // ------------------------------------------------------------------
    // Teams
    // ------------------------------------------------------------------

    /** Create a team. $args: name, division, colour. Returns the team or WP_Error. */
    public static function create($season, $args) {
        global $wpdb;
        if (!RSL_Seasons::is_valid($season)) {
            return new WP_Error('rsl_season', 'Pick a valid season.');
        }
        $name = trim(sanitize_text_field(isset($args['name']) ? $args['name'] : ''));
        if ($name === '') {
            return new WP_Error('rsl_name', 'A team needs a name.');
        }
        if (mb_strlen($name) > 100) {
            return new WP_Error('rsl_name', 'Team names can be up to 100 characters.');
        }
        $division = isset($args['division']) && isset(self::DIVISIONS[$args['division']]) ? $args['division'] : 'ranked';
        $colour = isset($args['colour']) ? sanitize_hex_color($args['colour']) : '';
        $colour = $colour ?: '#1d3d6e';

        if (self::find_by_name($season, $name)) {
            return new WP_Error('rsl_duplicate', 'There\'s already a team called "' . $name . '" this season.');
        }

        $saved = $wpdb->insert(RSL_Database::table('teams'), array(
            'season' => $season,
            'name' => $name,
            'slug' => self::unique_slug($season, $name),
            'division' => $division,
            'colour' => $colour,
            'status' => isset($args['status']) && in_array($args['status'], array('active', 'pending'), true) ? $args['status'] : 'active',
            'invite_code' => self::new_invite_code(),
            'est_level' => isset($args['level']) && isset(self::levels()[$args['level']]) ? $args['level'] : '',
            'applied_by' => isset($args['applied_by']) ? intval($args['applied_by']) : null,
            'applied_at' => isset($args['applied_by']) ? current_time('mysql') : null,
            'created_at' => current_time('mysql'),
        ));
        // A failed insert (two same-name applications at once taking one slug, a value the
        // table refuses, a database error) is an error, never a missing team taken for one.
        if (!$saved) {
            return new WP_Error('rsl_save', 'That team couldn\'t be saved — please try again.');
        }

        return self::get((int) $wpdb->insert_id);
    }

    // ------------------------------------------------------------------
    // Captain applications
    // ------------------------------------------------------------------

    /**
     * A registered player applies for a team; they become its captain. The
     * team stays pending (not public, no invites) until an admin approves.
     * A division that's full (RSL_Capacity, 0.19.0) never refuses one: the
     * application returned has ->waiting_list true when it's beyond the
     * places left (with_waiting_list()), and the captain is told.
     */
    public static function apply($season, $user_id, $args) {
        if (!RSL_Players::is_registered($user_id)) {
            return new WP_Error('rsl_unregistered', 'Register for the league first.');
        }
        if ($blocked = self::why_cant_join($user_id, $season)) {
            return $blocked;
        }
        // The name first (the form's first field), with create()'s own sentence (0.17.0).
        $name = trim(sanitize_text_field(isset($args['name']) ? (string) $args['name'] : ''));
        if ($name === '') {
            return new WP_Error('rsl_name', 'A team needs a name.');
        }
        if (!isset($args['level']) || !isset(self::levels()[$args['level']])) {
            return new WP_Error('rsl_level', 'Pick roughly what level your team plays at — it helps us give you even games from week one.');
        }
        if (self::withdrawn_count($user_id, $season) >= self::MAX_WITHDRAWN) {
            return new WP_Error('rsl_withdrawn', 'You\'ve withdrawn ' . self::MAX_WITHDRAWN . ' applications this season — talk to the league to apply again.');
        }
        $args['status'] = 'pending';
        $args['applied_by'] = $user_id;
        $team = self::create($season, $args);
        if (is_wp_error($team)) {
            return $team;
        }
        if (!$team) {
            return new WP_Error('rsl_save', 'That team couldn\'t be saved — please try again.');
        }
        self::add_member($team->id, $user_id, 'captain', true, array('how' => 'apply'));
        return self::with_waiting_list(self::get($team->id));
    }

    /**
     * The team with ->waiting_list set (0.19.0): true for a pending application
     * beyond its division's places left, worked out now (RSL_Capacity::
     * on_waiting_list(); nothing is stored), else false.
     */
    public static function with_waiting_list($team) {
        if ($team && is_object($team)) {
            $team->waiting_list = RSL_Capacity::on_waiting_list($team);
        }
        return $team;
    }

    /** Applying and withdrawing over and over would hold names; a few tries a season is plenty. */
    const MAX_WITHDRAWN = 3;

    private static function withdrawn_count($user_id, $season) {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . RSL_Database::table('teams') . " WHERE applied_by = %d AND season = %s AND status = 'withdrawn'", $user_id, $season));
    }

    /**
     * Admin approves: the team goes live and the captain can invite players.
     * Over its division's places is allowed (0.19.0); the Teams screen shows
     * what that costs first (RSL_Capacity::over_words()) and says it again after.
     */
    public static function approve($team_id) {
        global $wpdb;
        $team = self::get($team_id);
        if (!$team || $team->status !== 'pending') {
            return new WP_Error('rsl_not_pending', 'That application has already been dealt with.');
        }
        $wpdb->update(RSL_Database::table('teams'), array('status' => 'active', 'decided_by' => get_current_user_id() ?: null, 'decided_at' => current_time('mysql')), array('id' => $team->id));
        self::notify_captain($team->id, 'approved');
        return self::get($team->id);
    }

    /** Admin declines (with a reason the captain sees). The captain is freed up. */
    public static function decline($team_id, $note = '') {
        global $wpdb;
        $team = self::get($team_id);
        if (!$team || $team->status !== 'pending') {
            return new WP_Error('rsl_not_pending', 'That application has already been dealt with.');
        }
        $wpdb->update(RSL_Database::table('teams'), array('status' => 'declined', 'decided_by' => get_current_user_id() ?: null, 'decided_at' => current_time('mysql'), 'decision_note' => substr(sanitize_text_field($note), 0, 255)), array('id' => $team->id));
        self::release_members($team->id);
        self::notify_captain($team->id, 'declined');
        return self::get($team->id);
    }

    /** The captain withdraws their own pending application. */
    public static function withdraw($team_id, $user_id) {
        global $wpdb;
        $team = self::get($team_id);
        if (!$team || $team->status !== 'pending' || intval($team->applied_by) !== intval($user_id)) {
            return new WP_Error('rsl_not_pending', 'There\'s no pending application of yours to withdraw.');
        }
        $wpdb->update(RSL_Database::table('teams'), array('status' => 'withdrawn'), array('id' => $team->id));
        self::release_members($team->id);
        return true;
    }

    /** A pending team that won't happen: its members are freed, and the applicant goes back on the free agent list if they were on it. */
    private static function release_members($team_id) {
        global $wpdb;
        $wpdb->query($wpdb->prepare("UPDATE " . RSL_Database::table('team_members') . " SET left_at = %s WHERE team_id = %d AND left_at IS NULL", current_time('Y-m-d'), $team_id));
        RSL_Free_Agents::application_closed($team_id);
    }

    /** Pending applications, oldest first, with the captain's name. */
    public static function pending_applications() {
        global $wpdb;
        return $wpdb->get_results("
            SELECT t.*, u.display_name AS captain_name, u.user_email AS captain_email
            FROM " . RSL_Database::table('teams') . " t
            LEFT JOIN {$wpdb->users} u ON u.ID = t.applied_by
            WHERE t.status = 'pending'
            ORDER BY t.applied_at, t.id
        ");
    }

    /** The person's pending application this season, or null. */
    public static function pending_application_for_user($user_id, $season) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . RSL_Database::table('teams') . " WHERE applied_by = %d AND season = %s AND status = 'pending' LIMIT 1", $user_id, $season));
    }

    /** Their latest decided application this season (to show a decline reason), or null. */
    public static function last_decided_application($user_id, $season) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . RSL_Database::table('teams') . " WHERE applied_by = %d AND season = %s AND status = 'declined' ORDER BY decided_at DESC LIMIT 1", $user_id, $season));
    }

    /** Why this person can't join or apply for a team this season, or null. */
    public static function why_cant_join($user_id, $season, $except_team_id = 0) {
        $current = self::current_team_for_user($user_id, $season);
        if ($current && intval($current->id) !== intval($except_team_id)) {
            return new WP_Error('rsl_elsewhere', 'You\'re already on ' . $current->name . ' this season.');
        }
        $pending = self::pending_application_for_user($user_id, $season);
        if ($pending && intval($pending->id) !== intval($except_team_id)) {
            return new WP_Error('rsl_pending', 'You have a team application waiting for approval (' . $pending->name . '). Withdraw it first.');
        }
        return null;
    }

    /** Email the applicant; false when it couldn't be sent (the admin is told). */
    private static function notify_captain($team_id, $what) {
        $team = self::get($team_id);
        $captain = $team && $team->applied_by ? get_userdata($team->applied_by) : null;
        if (!$captain) {
            return false;
        }
        $portal = RSL_Portal::url();
        if ($what === 'approved') {
            $subject = 'Your team ' . $team->name . ' is approved';
            $body = "Hi {$captain->display_name},\n\n{$team->name} is approved for the " . RSL_Seasons::label($team->season) . " Reds Social League.\n\n"
                . "Invite your players with this link:\n" . RSL_Portal::invite_url($team) . "\n\n"
                . "Or add players who have already registered from your league page:\n$portal\n\n"
                . "Everyone needs a current social rego (or full/life club membership) before their first game.\n";
        } else {
            $subject = 'Your team application for ' . $team->name;
            $listed = RSL_Free_Agents::is_open(RSL_Free_Agents::get($captain->ID, $team->season));
            $body = "Hi {$captain->display_name},\n\nYour application for {$team->name} wasn't approved"
                . ($team->decision_note !== '' ? ":\n\n" . $team->decision_note . "\n" : ".\n")
                . ($listed ? "\nYou're back on the free agent list, so we'll still find you a team if you don't join one yourself.\n" : '')
                . "\nYou can apply again or join another team from your league page:\n$portal\n";
        }
        $sent = RSL_Mail::send_now($captain->user_email, $subject, $body);
        if (!$sent) {
            set_transient('rsl_mail_failed_' . get_current_user_id(), $captain->display_name, 60);
        }
        return $sent;
    }

    // ------------------------------------------------------------------
    // Invite codes (shared by the captain; using one joins straight away)
    // ------------------------------------------------------------------

    /** 8 characters, no look-alikes (0/O, 1/I/L), unique among teams. */
    public static function new_invite_code() {
        global $wpdb;
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while ($wpdb->get_var($wpdb->prepare("SELECT id FROM " . RSL_Database::table('teams') . " WHERE invite_code = %s", $code)));
        return $code;
    }

    /** The team's code, creating one for teams made before codes existed. */
    public static function invite_code($team) {
        global $wpdb;
        if ($team->invite_code === '') {
            $team->invite_code = self::new_invite_code();
            $wpdb->update(RSL_Database::table('teams'), array('invite_code' => $team->invite_code), array('id' => $team->id));
        }
        return $team->invite_code;
    }

    /** A new code; the old link stops working. */
    public static function regenerate_code($team_id) {
        global $wpdb;
        $code = self::new_invite_code();
        $wpdb->update(RSL_Database::table('teams'), array('invite_code' => $code), array('id' => $team_id));
        return $code;
    }

    /**
     * Seasons people can join teams in (invite codes and invites): the one being played, the one
     * signing up, and (0.17.0) every later season open for sign-up, since a captain can apply for
     * one of those. The current and sign-up seasons count whatever they are, finished or not (a
     * pinned season stays until the admin moves it on); later seasons count only while open. So
     * last season's links stop working once the current and sign-up seasons have moved past it.
     */
    public static function joinable_seasons() {
        return array_values(array_unique(array_merge(
            array(RSL_Seasons::current(), RSL_Seasons::signup()),
            array_keys(RSL_Seasons::open_for_signup())
        )));
    }

    /** The active team with this code in a season people can join, or null (last season's links stop working). */
    public static function get_by_code($code) {
        global $wpdb;
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $code));
        if ($code === '') {
            return null;
        }
        $seasons = self::joinable_seasons();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . RSL_Database::table('teams') . " WHERE invite_code = %s AND status = 'active' AND season IN (" . implode(',', array_fill(0, count($seasons), '%s')) . ")",
            array_merge(array($code), $seasons)
        ));
    }

    /** A registered player joins with the captain's code. */
    public static function join_by_code($user_id, $code) {
        if (!RSL_Players::is_registered($user_id)) {
            return new WP_Error('rsl_unregistered', 'Register for the league first.');
        }
        $team = self::get_by_code($code);
        if (!$team) {
            return new WP_Error('rsl_code', 'That invite code isn\'t valid any more — ask your captain for the current link.');
        }
        $result = self::add_member($team->id, $user_id, 'player', false, array('how' => 'code'));
        return is_wp_error($result) ? $result : $team;
    }

    // ------------------------------------------------------------------
    // Invites by email (the player must accept)
    // ------------------------------------------------------------------

    const EMAIL_INVITES_PER_DAY = 20;

    /**
     * The captain invites someone by their exact email; they're not on the
     * team until they accept. The answer is the same whether or not that
     * email belongs to a registered player — a captain can't use this to
     * find out who's registered. An email nobody has registered with yet is
     * kept (user_id 0) and offered to whoever registers with it later. Only
     * registered players are emailed. Returns the invite id or WP_Error.
     */
    public static function invite_by_email($team_id, $captain_id, $email) {
        global $wpdb;
        $team = self::get($team_id);
        if (!$team || $team->status !== 'active') {
            return new WP_Error('rsl_team', 'Invites open once your team is approved.');
        }
        if (!self::is_captain($team->id, $captain_id) && !user_can($captain_id, 'manage_options')) {
            return new WP_Error('rsl_not_captain', 'Only the captain can invite players.');
        }
        $email = sanitize_email($email);
        if (!$email || !is_email($email)) {
            return new WP_Error('rsl_email', 'That doesn\'t look like an email address.');
        }
        $table = RSL_Database::table('invites');
        $today = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE invited_by = %d AND source = 'email' AND created_at > %s", $captain_id, date('Y-m-d H:i:s', current_time('timestamp') - DAY_IN_SECONDS)));
        if ($today >= self::EMAIL_INVITES_PER_DAY && !user_can($captain_id, 'manage_options')) {
            return new WP_Error('rsl_invite_cap', 'That\'s ' . self::EMAIL_INVITES_PER_DAY . ' email invites today — share your invite link instead.');
        }
        $user = get_user_by('email', $email);
        $user_id = $user && RSL_Players::is_registered($user->ID) ? (int) $user->ID : 0;
        // Only the captain's own email invites count as "already invited" (a
        // pool invite must not confirm a free agent's email), and a teammate's
        // email gets the usual answer too (not emailed; their own page skips
        // invites to a team they're on).
        if ($wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE team_id = %d AND status = 'pending' AND source = 'email' AND (email = %s OR (user_id = %d AND user_id <> 0))", $team->id, $email, $user_id))) {
            return new WP_Error('rsl_invited', 'You\'ve already invited them — they need to accept it.');
        }
        $wpdb->insert($table, array('team_id' => $team->id, 'user_id' => $user_id, 'email' => $email, 'invited_by' => $captain_id, 'source' => 'email', 'created_at' => current_time('mysql')));
        $id = (int) $wpdb->insert_id;
        if ($user_id && !self::is_member($team->id, $user_id)) {
            RSL_Mail::send_now($user->user_email, 'You\'re invited to join ' . $team->name,
                "Hi {$user->display_name},\n\nYou've been invited to join {$team->name} in the Reds Social League.\n\nAccept or decline on your league page:\n" . RSL_Portal::url() . "\n");
        }
        return $id;
    }

    /**
     * Invites waiting on this player, with team names: those sent to them,
     * and email invites sent to their address before they registered. Only
     * for seasons people can still join; expired ones, and any to a team
     * they're already on, are left out.
     */
    public static function pending_invites_for_user($user_id) {
        global $wpdb;
        $user = get_userdata($user_id);
        if (!$user) {
            return array();
        }
        $seasons = self::joinable_seasons();
        return $wpdb->get_results($wpdb->prepare("
            SELECT i.*, t.name AS team_name, t.season, t.colour, t.slug, t.division
            FROM " . RSL_Database::table('invites') . " i
            JOIN " . RSL_Database::table('teams') . " t ON t.id = i.team_id
            WHERE (i.user_id = %d OR (i.user_id = 0 AND i.source = 'email' AND i.email = %s))
                AND i.status = 'pending' AND t.status = 'active'
                AND (i.expires_at IS NULL OR i.expires_at > %s)
                AND NOT EXISTS (SELECT 1 FROM " . RSL_Database::table('team_members') . " m WHERE m.team_id = i.team_id AND m.user_id = %d AND m.left_at IS NULL)
                AND t.season IN (" . implode(',', array_fill(0, count($seasons), '%s')) . ")
            ORDER BY i.created_at
        ", array_merge(array($user_id, $user->user_email, current_time('mysql'), $user_id), $seasons)));
    }

    /**
     * Invites the team is waiting on. For an email invite the captain sees
     * only the email they typed; for a pool invite, the player's first name.
     */
    public static function pending_invites_for_team($team_id) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, user_id, email, source, group_code, created_at, expires_at FROM " . RSL_Database::table('invites') . " WHERE team_id = %d AND status = 'pending' AND (expires_at IS NULL OR expires_at > %s) ORDER BY created_at", $team_id, current_time('mysql')));
        foreach ($rows as $r) {
            $r->label = $r->source === 'pool' ? RSL_Free_Agents::first_name($r->user_id) . ' (free agent' . ($r->group_code !== '' ? ' + friends' : '') . ')' : $r->email;
        }
        return $rows;
    }

    /**
     * The invited player accepts or declines. A pool invite to someone in a
     * friend group brings the whole group as it is now (if there's room for
     * everyone) — refused if the group changed since the invite or a friend
     * hasn't agreed to the team's division (RSL_Free_Agents::pool_invite_ids()).
     */
    public static function respond_invite($invite_id, $user_id, $accept) {
        global $wpdb;
        $table = RSL_Database::table('invites');
        $user = get_userdata($user_id);
        $invite = $user ? $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE id = %d AND status = 'pending' AND (user_id = %d OR (user_id = 0 AND source = 'email' AND email = %s))",
            $invite_id, $user_id, $user->user_email
        )) : null;
        $team = $invite ? self::get($invite->team_id) : null;
        if (!$invite || !$team || !in_array($team->season, self::joinable_seasons(), true)) {
            return new WP_Error('rsl_invite', 'That invite isn\'t open any more.');
        }
        if ($invite->expires_at && $invite->expires_at <= current_time('mysql')) {
            $wpdb->update($table, array('status' => 'expired'), array('id' => $invite->id));
            return new WP_Error('rsl_invite', 'That invite has expired — ask the captain to invite you again.');
        }
        $ids = array(intval($user_id));
        if ($accept) {
            if (!RSL_Players::is_registered($user_id)) {
                return new WP_Error('rsl_unregistered', 'Register for the league first.');
            }
            if ($invite->source === 'pool') {
                $ids = RSL_Free_Agents::pool_invite_ids($invite, $team, $user_id);
                $result = is_wp_error($ids) ? $ids : RSL_Free_Agents::add_unit($team->id, $ids, 'pool');
            } else {
                $result = self::add_member($team->id, $user_id, 'player', false, array('how' => 'invite'));
            }
            if (is_wp_error($result)) {
                return $result;
            }
        }
        $wpdb->update($table, array('status' => $accept ? 'accepted' : 'declined', 'user_id' => $user_id, 'responded_at' => current_time('mysql')), array('id' => $invite->id));
        if ($accept && $invite->source === 'pool') {
            RSL_Free_Agents::mail_contact_swap($team, $ids, 'inv-ok:' . $invite->id);
        }
        return true;
    }

    /** The captain cancels an invite they sent. */
    public static function cancel_invite($invite_id, $captain_id) {
        global $wpdb;
        $table = RSL_Database::table('invites');
        $invite = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d AND status = 'pending'", $invite_id));
        if (!$invite || (!self::is_captain($invite->team_id, $captain_id) && !user_can($captain_id, 'manage_options'))) {
            return new WP_Error('rsl_invite', 'That invite isn\'t open any more.');
        }
        $wpdb->update($table, array('status' => 'cancelled', 'responded_at' => current_time('mysql')), array('id' => $invite->id));
        return true;
    }

    public static function is_captain($team_id, $user_id) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM " . RSL_Database::table('team_members') . " WHERE team_id = %d AND user_id = %d AND role = 'captain' AND left_at IS NULL", $team_id, $user_id));
    }

    /** Update name / division / colour. The slug (public URL) never changes. */
    public static function update($team_id, $args) {
        global $wpdb;
        $team = self::get($team_id);
        if (!$team) {
            return new WP_Error('rsl_team', 'That team no longer exists.');
        }
        $data = array();
        if (isset($args['name'])) {
            $name = trim(sanitize_text_field($args['name']));
            if ($name === '') {
                return new WP_Error('rsl_name', 'A team needs a name.');
            }
            $other = self::find_by_name($team->season, $name);
            if ($other && intval($other->id) !== intval($team->id)) {
                return new WP_Error('rsl_duplicate', 'There\'s already a team called "' . $name . '" this season.');
            }
            $data['name'] = $name;
        }
        if (isset($args['division']) && isset(self::DIVISIONS[$args['division']])) {
            $data['division'] = $args['division'];
        }
        if (isset($args['colour']) && sanitize_hex_color($args['colour'])) {
            $data['colour'] = sanitize_hex_color($args['colour']);
        }
        if ($data) {
            $wpdb->update(RSL_Database::table('teams'), $data, array('id' => $team->id));
        }
        return self::get($team->id);
    }

    public static function get($team_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . RSL_Database::table('teams') . " WHERE id = %d", $team_id));
    }

    public static function get_by_slug($season, $slug) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . RSL_Database::table('teams') . " WHERE season = %s AND slug = %s", $season, $slug));
    }

    /** A live (active or pending) team with this name — withdrawn and declined applications free the name. */
    private static function find_by_name($season, $name) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . RSL_Database::table('teams') . " WHERE season = %s AND name = %s AND status IN ('active', 'pending')", $season, $name));
    }

    /** Active teams in a season, with a current member count, by division then name. */
    public static function list_for_season($season) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("
            SELECT t.*, COUNT(m.id) AS member_count
            FROM " . RSL_Database::table('teams') . " t
            LEFT JOIN " . RSL_Database::table('team_members') . " m ON m.team_id = t.id AND m.left_at IS NULL
            WHERE t.season = %s AND t.status = 'active'
            GROUP BY t.id
            ORDER BY FIELD(t.division, 'ranked', 'unranked'), t.name
        ", $season));
    }

    /** A URL-safe slug, unique within the season ("spikes", "spikes-2", ...). */
    private static function unique_slug($season, $name) {
        $base = sanitize_title($name);
        $base = $base !== '' ? $base : 'team';
        $slug = $base;
        $n = 2;
        while (self::get_by_slug($season, $slug)) {
            $slug = $base . '-' . $n++;
        }
        return $slug;
    }

    // ------------------------------------------------------------------
    // Rosters
    // ------------------------------------------------------------------

    /**
     * Put a person on a team (or back on it). One team per person per
     * season: someone already on another team this season is refused —
     * subs for a night come later, through the sub list.
     */
    public static function add_member($team_id, $user_id, $role = 'player', $allow_pending = false, $opts = array()) {
        global $wpdb;
        $team = self::get($team_id);
        $user = get_userdata($user_id);
        if (!$team || (!$allow_pending && $team->status !== 'active')) {
            return new WP_Error('rsl_team', 'That team isn\'t taking players.');
        }
        if (!$user) {
            return new WP_Error('rsl_user', 'No account found for that person.');
        }
        $role = isset(self::ROLES[$role]) ? $role : 'player';
        $how = isset($opts['how']) ? sanitize_key($opts['how']) : 'admin';
        $capped = $team->status === 'active' && empty($opts['admin']);

        // Two captains accepting the same player at once must not put them
        // on two teams, and two people joining a nearly full team at once
        // must not take it over the maximum: the checks and the insert run
        // under a per-person lock and, when the maximum applies, the team's
        // lock. Always team first, then person (RSL_Free_Agents::add_unit()
        // holds the team lock around several adds; it's re-entrant).
        $team_lock = $capped ? self::team_lock_name($team->id) : '';
        if ($team_lock !== '' && !self::lock($team_lock)) {
            return new WP_Error('rsl_busy', 'Lots of people are joining ' . $team->name . ' right now — please try again.');
        }
        $lock = 'rsl_join_' . intval($user_id);
        if (!self::lock($lock)) {
            if ($team_lock !== '') {
                self::unlock($team_lock);
            }
            return new WP_Error('rsl_busy', 'Something else is updating ' . $user->display_name . '\'s team right now — please try again.');
        }
        try {
            $elsewhere = self::current_team_for_user($user_id, $team->season);
            if ($elsewhere && intval($elsewhere->id) !== intval($team->id)) {
                return new WP_Error('rsl_elsewhere', $user->display_name . ' is already on ' . $elsewhere->name . ' this season.');
            }
            $pending = self::pending_application_for_user($user_id, $team->season);
            if ($pending && intval($pending->id) !== intval($team->id)) {
                return new WP_Error('rsl_pending', $user->display_name . ' has a team application waiting for approval (' . $pending->name . ').');
            }

            $table = RSL_Database::table('team_members');
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE team_id = %d AND user_id = %d", $team->id, $user_id));
            if ($row && $row->left_at === null) {
                return new WP_Error('rsl_already', $user->display_name . ' is already on this team.');
            }
            // The roster maximum holds on every route except an admin's.
            if ($capped) {
                $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE team_id = %d AND left_at IS NULL", $team->id));
                $max = RSL_Free_Agents::settings()['max_roster'];
                if ($count >= $max) {
                    return new WP_Error('rsl_full', $team->name . ' is full (' . $max . ' players) — ask the captain.');
                }
            }
            if ($row) {
                $wpdb->update($table, array('left_at' => null, 'role' => $role, 'joined_at' => current_time('Y-m-d')), array('id' => $row->id), array('%s', '%s', '%s'), array('%d'));
            } else {
                $wpdb->insert($table, array('team_id' => $team->id, 'user_id' => $user_id, 'role' => $role, 'joined_at' => current_time('Y-m-d')), array('%d', '%d', '%s', '%s'));
            }
            if ($role === 'captain') {
                self::set_captain($team->id, $user_id);
            }
        } finally {
            self::unlock($lock);
            if ($team_lock !== '') {
                self::unlock($team_lock);
            }
        }
        do_action('rsl_member_added', (int) $team->id, (int) $user_id, $how);
        return true;
    }

    /** The lock name guarding a team's roster maximum. */
    public static function team_lock_name($team_id) {
        return 'rsl_team_' . intval($team_id);
    }

    /** Take a named database lock (waits up to 5 seconds); true when held. Pair with unlock(). */
    public static function lock($name) {
        global $wpdb;
        return (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $name)) === '1';
    }

    public static function unlock($name) {
        global $wpdb;
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
    }

    /** Take a person off a team. Their row stays, with left_at, for history. */
    public static function remove_member($team_id, $user_id) {
        global $wpdb;
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE " . RSL_Database::table('team_members') . " SET left_at = %s, role = 'player' WHERE team_id = %d AND user_id = %d AND left_at IS NULL",
            current_time('Y-m-d'), $team_id, $user_id
        ));
        if (!$updated) {
            return new WP_Error('rsl_not_member', 'That person isn\'t on this team.');
        }
        do_action('rsl_member_removed', (int) $team_id, (int) $user_id);
        return true;
    }

    /** A player leaves their own team (a wrong link, a change of plan). Captains hand over first. */
    public static function leave($team_id, $user_id) {
        $team = self::get($team_id);
        if (!$team || !self::is_member($team->id, $user_id)) {
            return new WP_Error('rsl_not_member', 'You\'re not on that team.');
        }
        if (self::is_captain($team->id, $user_id)) {
            return new WP_Error('rsl_captain', 'Captains can\'t leave their own team — ask the league to hand the captaincy to someone else first.');
        }
        return self::remove_member($team->id, $user_id);
    }

    /** Make one current member the captain (one per team). */
    public static function set_captain($team_id, $user_id) {
        global $wpdb;
        $table = RSL_Database::table('team_members');
        $is_member = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE team_id = %d AND user_id = %d AND left_at IS NULL", $team_id, $user_id));
        if (!$is_member) {
            return new WP_Error('rsl_not_member', 'Only someone on the team can be its captain.');
        }
        $wpdb->query($wpdb->prepare("UPDATE $table SET role = 'player' WHERE team_id = %d AND role = 'captain'", $team_id));
        $wpdb->update($table, array('role' => 'captain'), array('id' => $is_member), array('%s'), array('%d'));
        return true;
    }

    /** Current members, captain first, then by name. */
    public static function members($team_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("
            SELECT m.*, u.display_name, u.user_email
            FROM " . RSL_Database::table('team_members') . " m
            JOIN {$wpdb->users} u ON u.ID = m.user_id
            WHERE m.team_id = %d AND m.left_at IS NULL
            ORDER BY FIELD(m.role, 'captain', 'player'), u.display_name
        ", $team_id));
    }

    public static function captain($team_id) {
        foreach (self::members($team_id) as $m) {
            if ($m->role === 'captain') {
                return $m;
            }
        }
        return null;
    }

    /** The (active) team a person is currently on this season, or null. */
    public static function current_team_for_user($user_id, $season) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("
            SELECT t.*, m.role AS member_role FROM " . RSL_Database::table('teams') . " t
            JOIN " . RSL_Database::table('team_members') . " m ON m.team_id = t.id
            WHERE m.user_id = %d AND m.left_at IS NULL AND t.season = %s AND t.status = 'active'
            LIMIT 1
        ", $user_id, $season));
    }

    public static function is_member($team_id, $user_id) {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT id FROM " . RSL_Database::table('team_members') . " WHERE team_id = %d AND user_id = %d AND left_at IS NULL", $team_id, $user_id));
    }

    /** Badge letters for a team without a logo: "Block Party" -> "BP". */
    public static function initials($name) {
        $words = preg_split('/\s+/', trim($name));
        $letters = '';
        foreach ($words as $w) {
            if ($w !== '' && preg_match('/[A-Za-z0-9]/', $w, $m)) {
                $letters .= strtoupper($m[0]);
            }
            if (strlen($letters) === 2) {
                break;
            }
        }
        return $letters !== '' ? $letters : '?';
    }
}
