<?php
/**
 * DEV ONLY — gives a pretend account the club profile that registering for
 * the league needs (0.13.0, RSL_Players::profile_fields()): first and last
 * name, mobile, date of birth and gender, saved the way the club's profile
 * form saves them (date of birth as YYYY/MM/DD, gender in gender_dropdown).
 * Names already on the account are kept. The mobile is made up.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('rsl_dev_profile')) {
    function rsl_dev_profile($user_id, $dob = '1995/06/15', $gender = 'Female') {
        $user = get_userdata($user_id);
        if (!$user) {
            return;
        }
        $words = preg_split('/\s+/', trim($user->display_name));
        if (trim((string) get_user_meta($user_id, 'first_name', true)) === '') {
            update_user_meta($user_id, 'first_name', $words[0] !== '' ? $words[0] : 'Test');
        }
        if (trim((string) get_user_meta($user_id, 'last_name', true)) === '') {
            update_user_meta($user_id, 'last_name', count($words) > 1 ? end($words) : 'Player');
        }
        update_user_meta($user_id, 'mobile_number', '0491 570 156');
        update_user_meta($user_id, 'birth_date', $dob);
        update_user_meta($user_id, 'gender_dropdown', $gender);
    }

    /**
     * A YYYY/MM/DD date of birth $years years before $from (default today),
     * moved $days_ago days earlier (negative: later, so that birthday is
     * still to come).
     */
    function rsl_dev_dob($years, $days_ago = 30, $from = '') {
        $from = $from !== '' ? $from : current_time('Y-m-d');
        $base = strtotime($from . ' -' . intval($years) . ' years');
        return date('Y/m/d', strtotime(($days_ago >= 0 ? '-' : '+') . abs(intval($days_ago)) . ' days', $base));
    }
}
