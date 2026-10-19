<?php
/**
 * A captain's requests about single nights of the season:
 *  - off:       "we need this week off" — before that week's fixtures are
 *               out; the fixtures simply leave the team out (free). A week
 *               off is not a bye: no turn-up points, no finals round.
 *  - double:    "we'd like two games" — before fixtures are out; the
 *               fixtures give the team a double-header if there's a spare
 *               slot (ahead of teams that only opted in for the season).
 *  - cant_make: "we can't make it" — after fixtures are out, before the
 *               night. Goes to the league admins to sort out (the
 *               automatic replacement offer comes later). Emails the admin.
 * Captains (or admins) only. Data only — returns rows or WP_Error.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RSL_Requests {

    const TYPES = array('off' => 'Week off', 'double' => 'Two games', 'cant_make' => "Can't make it");

    public static function request($team_id, $night_id, $type, $user_id) {
        global $wpdb;
        $team = RSL_Teams::get($team_id);
        $night = RSL_Courts::get_night($night_id);
        if (!$team || $team->status !== 'active' || !$night || $night->season !== $team->season) {
            return new WP_Error('rsl_request', 'That night isn\'t part of this team\'s season.');
        }
        if (!isset(self::TYPES[$type])) {
            return new WP_Error('rsl_request', 'Unknown request.');
        }
        if (!RSL_Teams::is_captain($team->id, $user_id) && !user_can($user_id, 'manage_options')) {
            return new WP_Error('rsl_captain', 'Only the team captain can do that.');
        }
        if ($night->type !== 'regular') {
            return new WP_Error('rsl_request', $night->type === 'bye' ? 'There are no games that week anyway.' : 'Finals nights are arranged separately — talk to the league.');
        }
        if ($night->night_date < current_time('Y-m-d')) {
            return new WP_Error('rsl_request', 'That night has already happened.');
        }
        $published = RSL_Fixtures::is_published($night);
        if (in_array($type, array('off', 'double'), true) && $published) {
            return new WP_Error('rsl_published', 'This week\'s fixtures are already out. If you can\'t make it, use "Can\'t make it" instead.');
        }
        if ($type === 'cant_make') {
            if (!$published) {
                return new WP_Error('rsl_request', 'Fixtures aren\'t out yet — request the week off instead (it\'s free).');
            }
            $playing = array_filter(RSL_Fixtures::games_for_night($night->id), function ($g) use ($team) {
                return in_array(intval($team->id), array(intval($g->home_team_id), intval($g->away_team_id)), true);
            });
            if (!$playing) {
                return new WP_Error('rsl_request', 'Your team doesn\'t have a game that night.');
            }
        }
        // One live request per team per night: a new one replaces the old.
        $wpdb->query($wpdb->prepare("UPDATE " . RSL_Database::table('requests') . " SET status = 'cancelled' WHERE team_id = %d AND night_id = %d AND status = 'active'", $team->id, $night->id));
        $wpdb->insert(RSL_Database::table('requests'), array('team_id' => $team->id, 'night_id' => $night->id, 'type' => $type, 'requested_by' => $user_id));
        $req = self::get($wpdb->insert_id);
        if ($type === 'cant_make') {
            self::notify_admins($req, $team, $night);
        }
        return $req;
    }

    /** Withdraw an active request (captain/admin). A can't-make-it can be withdrawn until handled. */
    public static function cancel($request_id, $user_id) {
        global $wpdb;
        $req = self::get($request_id);
        if (!$req || $req->status !== 'active') {
            return new WP_Error('rsl_request', 'That request is no longer open.');
        }
        if (!RSL_Teams::is_captain($req->team_id, $user_id) && !user_can($user_id, 'manage_options')) {
            return new WP_Error('rsl_captain', 'Only the team captain can do that.');
        }
        $night = RSL_Courts::get_night($req->night_id);
        if ($req->type !== 'cant_make' && $night && RSL_Fixtures::is_published($night)) {
            return new WP_Error('rsl_published', 'This week\'s fixtures are already out, so the request can\'t be changed now.');
        }
        $wpdb->update(RSL_Database::table('requests'), array('status' => 'cancelled'), array('id' => $req->id));
        return true;
    }

    /** An admin has dealt with a request. */
    public static function mark_handled($request_id, $user_id) {
        global $wpdb;
        $wpdb->update(RSL_Database::table('requests'), array('status' => 'handled', 'handled_by' => $user_id, 'handled_at' => current_time('mysql')), array('id' => intval($request_id)));
        return self::get($request_id);
    }

    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM " . RSL_Database::table('requests') . " WHERE id = %d", $id));
    }

    /** A team's live requests: night_id => request. */
    public static function for_team($team_id) {
        global $wpdb;
        $out = array();
        foreach ($wpdb->get_results($wpdb->prepare("SELECT * FROM " . RSL_Database::table('requests') . " WHERE team_id = %d AND status IN ('active', 'handled') ORDER BY id", $team_id)) as $r) {
            $out[intval($r->night_id)] = $r;
        }
        return $out;
    }

    /** Active requests of one type for a night: team_id => request. */
    public static function for_night($night_id, $type, $statuses = array('active')) {
        global $wpdb;
        $in = implode(',', array_map(function ($s) { return "'" . esc_sql($s) . "'"; }, $statuses));
        $out = array();
        foreach ($wpdb->get_results($wpdb->prepare("SELECT r.*, t.name AS team_name FROM " . RSL_Database::table('requests') . " r JOIN " . RSL_Database::table('teams') . " t ON t.id = r.team_id WHERE r.night_id = %d AND r.type = %s AND r.status IN ($in) ORDER BY r.id", $night_id, $type)) as $r) {
            $out[intval($r->team_id)] = $r;
        }
        return $out;
    }

    /** Weeks off for a season: night_date => array(team_id => true). */
    public static function weeks_off($season) {
        global $wpdb;
        $out = array();
        foreach ($wpdb->get_results($wpdb->prepare("SELECT r.team_id, n.night_date FROM " . RSL_Database::table('requests') . " r JOIN " . RSL_Database::table('nights') . " n ON n.id = r.night_id WHERE n.season = %s AND r.type = 'off' AND r.status IN ('active', 'handled')", $season)) as $r) {
            $out[$r->night_date][intval($r->team_id)] = true;
        }
        return $out;
    }

    private static function notify_admins($req, $team, $night) {
        $to = apply_filters('rsl_requests_email', get_option('admin_email'));
        if (!$to) {
            return;
        }
        $labels = RSL_Fixtures::round_labels($team->season);
        wp_mail($to, '[Social League] ' . $team->name . " can't make " . $labels[$night->id],
            $team->name . " has told us they can't make their game(s) on " . date_i18n('l j F', strtotime($night->night_date)) . ' (' . $labels[$night->id] . ").\n\n"
            . "Sort it out with the other team, then mark it handled on Social League > Fixtures:\n"
            . admin_url('admin.php?page=' . RSL_Admin_Fixtures::PAGE . '&season=' . $team->season . '&night=' . $night->id) . "\n");
    }
}
