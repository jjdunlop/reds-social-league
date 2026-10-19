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
        ), array('%s', '%s', '%s', '%s', '%s'));

        return self::get($wpdb->insert_id);
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
    public static function add_member($team_id, $user_id, $role = 'player') {
        global $wpdb;
        $team = self::get($team_id);
        $user = get_userdata($user_id);
        if (!$team) {
            return new WP_Error('rsl_team', 'That team no longer exists.');
        }
        if (!$user) {
            return new WP_Error('rsl_user', 'No account found for that person.');
        }
        $role = isset(self::ROLES[$role]) ? $role : 'player';

        $elsewhere = self::current_team_for_user($user_id, $team->season);
        if ($elsewhere && intval($elsewhere->id) !== intval($team->id)) {
            return new WP_Error('rsl_elsewhere', $user->display_name . ' is already on ' . $elsewhere->name . ' this season.');
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

    /** The team a person is currently on this season, or null. */
    public static function current_team_for_user($user_id, $season) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("
            SELECT t.* FROM " . RSL_Database::table('teams') . " t
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
