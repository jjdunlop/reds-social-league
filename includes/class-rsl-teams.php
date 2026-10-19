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

        $wpdb->insert(RSL_Database::table('teams'), array(
            'season' => $season,
            'name' => $name,
            'slug' => self::unique_slug($season, $name),
            'division' => $division,
            'colour' => $colour,
            'status' => isset($args['status']) && in_array($args['status'], array('active', 'pending'), true) ? $args['status'] : 'active',
            'invite_code' => self::new_invite_code(),
            'applied_by' => isset($args['applied_by']) ? intval($args['applied_by']) : null,
            'applied_at' => isset($args['applied_by']) ? current_time('mysql') : null,
        ));

        return self::get($wpdb->insert_id);
    }

    // ------------------------------------------------------------------
    // Captain applications
    // ------------------------------------------------------------------

    /**
     * A registered player applies for a team; they become its captain. The
     * team stays pending (not public, no invites) until an admin approves.
     */
    public static function apply($season, $user_id, $args) {
        if (!RSL_Players::is_registered($user_id)) {
            return new WP_Error('rsl_unregistered', 'Register for the league first.');
        }
        if ($blocked = self::why_cant_join($user_id, $season)) {
            return $blocked;
        }
        $args['status'] = 'pending';
        $args['applied_by'] = $user_id;
        $team = self::create($season, $args);
        if (is_wp_error($team)) {
            return $team;
        }
        self::add_member($team->id, $user_id, 'captain', true);
        return self::get($team->id);
    }

    /** Admin approves: the team goes live and the captain can invite players. */
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

    private static function release_members($team_id) {
        global $wpdb;
        $wpdb->query($wpdb->prepare("UPDATE " . RSL_Database::table('team_members') . " SET left_at = %s WHERE team_id = %d AND left_at IS NULL", current_time('Y-m-d'), $team_id));
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

    private static function notify_captain($team_id, $what) {
        $team = self::get($team_id);
        $captain = $team && $team->applied_by ? get_userdata($team->applied_by) : null;
        if (!$captain) {
            return;
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
            $body = "Hi {$captain->display_name},\n\nYour application for {$team->name} wasn't approved"
                . ($team->decision_note !== '' ? ":\n\n" . $team->decision_note . "\n" : ".\n")
                . "\nYou can apply again or join another team from your league page:\n$portal\n";
        }
        wp_mail($captain->user_email, $subject, $body);
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

    /** The active team with this code, or null. */
    public static function get_by_code($code) {
        global $wpdb;
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $code));
        if ($code === '') {
            return null;
        }
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . RSL_Database::table('teams') . " WHERE invite_code = %s AND status = 'active'", $code));
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
        $result = self::add_member($team->id, $user_id, 'player');
        return is_wp_error($result) ? $result : $team;
    }

    // ------------------------------------------------------------------
    // Invites by email (the player must accept)
    // ------------------------------------------------------------------

    /**
     * The captain invites a registered player by their exact email. Nothing
     * about the player is shown to the captain, and they're not on the team
     * until they accept.
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
        $user = $email ? get_user_by('email', $email) : null;
        if (!$user || !RSL_Players::is_registered($user->ID)) {
            return new WP_Error('rsl_unknown', 'Nobody has registered for the league with that email yet — send them your invite link instead.');
        }
        if (self::is_member($team->id, $user->ID)) {
            return new WP_Error('rsl_already', 'They\'re already on your team.');
        }
        $table = RSL_Database::table('invites');
        if ($wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE team_id = %d AND user_id = %d AND status = 'pending'", $team->id, $user->ID))) {
            return new WP_Error('rsl_invited', 'You\'ve already invited them — they need to accept it.');
        }
        $wpdb->insert($table, array('team_id' => $team->id, 'user_id' => $user->ID, 'email' => $email, 'invited_by' => $captain_id));
        wp_mail($user->user_email, 'You\'re invited to join ' . $team->name,
            "Hi {$user->display_name},\n\nYou've been invited to join {$team->name} in the Reds Social League.\n\nAccept or decline on your league page:\n" . RSL_Portal::url() . "\n");
        return (int) $wpdb->insert_id;
    }

    /** Invites waiting on this player, with team names. */
    public static function pending_invites_for_user($user_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("
            SELECT i.*, t.name AS team_name, t.season, t.colour, t.slug
            FROM " . RSL_Database::table('invites') . " i
            JOIN " . RSL_Database::table('teams') . " t ON t.id = i.team_id
            WHERE i.user_id = %d AND i.status = 'pending' AND t.status = 'active'
            ORDER BY i.created_at
        ", $user_id));
    }

    /** Invites the team is waiting on (email as typed by the captain only). */
    public static function pending_invites_for_team($team_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT id, email, created_at FROM " . RSL_Database::table('invites') . " WHERE team_id = %d AND status = 'pending' ORDER BY created_at", $team_id));
    }

    /** The invited player accepts or declines. */
    public static function respond_invite($invite_id, $user_id, $accept) {
        global $wpdb;
        $table = RSL_Database::table('invites');
        $invite = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d AND user_id = %d AND status = 'pending'", $invite_id, $user_id));
        if (!$invite) {
            return new WP_Error('rsl_invite', 'That invite isn\'t open any more.');
        }
        if ($accept) {
            $result = self::add_member($invite->team_id, $user_id, 'player');
            if (is_wp_error($result)) {
                return $result;
            }
        }
        $wpdb->update($table, array('status' => $accept ? 'accepted' : 'declined', 'responded_at' => current_time('mysql')), array('id' => $invite->id));
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

    private static function find_by_name($season, $name) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . RSL_Database::table('teams') . " WHERE season = %s AND name = %s", $season, $name));
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
    public static function add_member($team_id, $user_id, $role = 'player', $allow_pending = false) {
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
        if ($row) {
            $wpdb->update($table, array('left_at' => null, 'role' => $role, 'joined_at' => current_time('Y-m-d')), array('id' => $row->id), array('%s', '%s', '%s'), array('%d'));
        } else {
            $wpdb->insert($table, array('team_id' => $team->id, 'user_id' => $user_id, 'role' => $role, 'joined_at' => current_time('Y-m-d')), array('%d', '%d', '%s', '%s'));
        }
        if ($role === 'captain') {
            self::set_captain($team->id, $user_id);
        }
        return true;
    }

    /** Take a person off a team. Their row stays, with left_at, for history. */
    public static function remove_member($team_id, $user_id) {
        global $wpdb;
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE " . RSL_Database::table('team_members') . " SET left_at = %s, role = 'player' WHERE team_id = %d AND user_id = %d AND left_at IS NULL",
            current_time('Y-m-d'), $team_id, $user_id
        ));
        return $updated ? true : new WP_Error('rsl_not_member', 'That person isn\'t on this team.');
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
