<?php
/**
 * Free agents on the player's league page: the "How are you joining?"
 * choice at registration, the free-agent fields, the "Your season" card,
 * the teams board and requests, friend groups, "Not for me", and the
 * captain's "Looking for players?" tools. Page pieces only — every rule
 * lives in RSL_Free_Agents. RSL_Portal places these on the page and hands
 * the actions below to process() after its own nonce and login check
 * (Post/Redirect/Get, as for the rest of the portal).
 *
 * Privacy: a captain only ever sees what RSL_Free_Agents hands over in
 * browse_for_captain() and requests_for_team() — first name, level,
 * positions and "+N friends". Nothing a player types is shown to anyone
 * (the captain's 100-character note is the only free text, and it's the
 * captain's own). Emails, surnames, ages, weeks away, bands and notes never
 * reach a captain's screen, and under-18s (from the account's date of birth)
 * are left out of browsing (unless an admin allows it) — though an under-18 can still ask a
 * team themselves, and that captain then sees their request card. Contact
 * details are swapped by email only, when someone joins through the league.
 * Errors a captain sees go through for_captain() (no names, no other teams).
 */

if (!defined('ABSPATH')) {
    exit;
}

class RSL_Portal_FA {

    /** The front-end actions process() answers for. */
    const ACTIONS = array(
        'fa_join', 'fa_update', 'fa_leave', 'fa_group_create', 'fa_group_join', 'fa_group_leave',
        'fa_request', 'fa_withdraw_request', 'fa_not_for_me',
        'cap_spots', 'cap_accept_request', 'cap_decline_request', 'cap_pool_invite',
    );

    // ------------------------------------------------------------------
    // Actions
    // ------------------------------------------------------------------

    /**
     * Do one free-agent or captain action. null when $do isn't one of
     * these; otherwise array('type' => success|error, 'message' => ...,
     * optionally 'back' => url). Call it only after RSL_Portal has checked
     * the nonce and the login. Who may do what is checked again here (and
     * once more in RSL_Free_Agents). $post is raw $_POST.
     */
    public static function process($do, $post, $user_id) {
        $do = (string) $do;
        if (!in_array($do, self::ACTIONS, true)) {
            return null;
        }
        $user_id = intval($user_id);
        $post = is_array($post) ? $post : array();
        if (!$user_id) {
            return self::result('error', 'Please log in first.');
        }
        if (strpos($do, 'cap_') === 0) {
            return self::process_captain($do, $post, $user_id);
        }
        $season = RSL_Seasons::signup();
        // Actions on an existing row carry its season (0.17.0).
        if (in_array($do, array('fa_update', 'fa_leave', 'fa_group_create', 'fa_group_join', 'fa_group_leave', 'fa_not_for_me'), true)) {
            $season = self::row_season($post);
            if (is_wp_error($season)) {
                return self::result('error', $season->get_error_message());
            }
        }

        switch ($do) {
            case 'fa_join':
                // The season the player picked (Find me a team), checked against the open list.
                $season = RSL_Seasons::pick_open(self::post_text($post, 'fa_season'));
                $row = is_wp_error($season) ? $season : RSL_Free_Agents::join($user_id, $season, self::agent_data($post));
                if (is_wp_error($row)) {
                    return self::result('error', $row->get_error_message());
                }
                return self::result('success', 'You\'re on the free agent list for ' . RSL_Seasons::label($season) . '. You don\'t have to do anything else — we\'ll find you a team.' . self::friend_code_after_join($user_id, $season, $post));

            case 'fa_update':
                $row = RSL_Free_Agents::update($user_id, $season, self::agent_data($post));
                return is_wp_error($row) ? self::result('error', $row->get_error_message()) : self::result('success', 'Your free agent details are saved.');

            case 'fa_leave':
                $done = RSL_Free_Agents::leave($user_id, $season);
                // "Rejoin any time" only while that season is still open for sign-up (0.18.0).
                return is_wp_error($done) ? self::result('error', $done->get_error_message())
                    : self::result('success', 'You\'ve left the free agent list.' . (isset(RSL_Seasons::signup_choices()[$season]) ? ' You can rejoin any time from this page.' : ''));

            case 'fa_group_create':
                $code = RSL_Free_Agents::group_create($user_id, $season);
                if (is_wp_error($code)) {
                    return self::result('error', $code->get_error_message());
                }
                $left = RSL_Free_Agents::settings()['group_max'] - count(RSL_Free_Agents::group_members($season, $code));
                return self::result('success', 'Your friend code is ' . $code . '.' . ($left > 0 ? ' Share it with ' . ($left === 1 ? 'one friend' : 'up to ' . $left . ' friends') . ' — everyone who enters it is placed on the same team.' : ''));

            case 'fa_group_join':
                $had = self::own_waiting_requests($user_id, $season);
                $done = RSL_Free_Agents::group_join($user_id, $season, self::post_text($post, 'group_code'));
                if (is_wp_error($done)) {
                    return self::result('error', $done->get_error_message());
                }
                return self::result('success', 'You\'re in the friend group — you\'ll be placed on the same team.' . ($had ? ' Requests you\'d sent on your own were withdrawn; ask again for the group.' : ''));

            case 'fa_group_leave':
                $had = self::own_waiting_requests($user_id, $season);
                $done = RSL_Free_Agents::group_leave($user_id, $season);
                if (is_wp_error($done)) {
                    return self::result('error', $done->get_error_message());
                }
                return self::result('success', 'You\'ve left the friend group.' . ($had ? ' Requests you\'d sent for the group were withdrawn.' : ''));

            case 'fa_request':
                $team_id = self::post_int($post, 'team_id');
                $id = RSL_Free_Agents::request_join($team_id, $user_id);
                if (is_wp_error($id)) {
                    return self::result('error', $id->get_error_message());
                }
                $team = RSL_Teams::get($team_id);
                $until = '';
                foreach (RSL_Free_Agents::requests_for_user($user_id, $team->season) as $r) {
                    if (intval($r->id) === intval($id)) {
                        $until = $r->expires_at;
                    }
                }
                return self::result('success', 'Request sent to ' . $team->name . '. ' . ($until ? 'Their captain has until ' . self::day($until) . ' to answer' : 'Their captain will answer soon') . ' — we\'ll email you.');

            case 'fa_withdraw_request':
                $done = RSL_Free_Agents::withdraw_request(self::post_int($post, 'request_id'), $user_id);
                return is_wp_error($done) ? self::result('error', $done->get_error_message()) : self::result('success', 'Request withdrawn.');

            case 'fa_not_for_me':
                // The season of the team they were placed on (row_season(): the sign-up
                // season, the one being played or one open for sign-up).
                $in = $season;
                $row = RSL_Free_Agents::get($user_id, $in);
                $team = $row && $row->team_id ? RSL_Teams::get($row->team_id) : null;
                $done = RSL_Free_Agents::not_for_me($user_id, $in);
                if (is_wp_error($done)) {
                    return self::result('error', $done->get_error_message());
                }
                return self::result('success', 'You\'ve stepped back from ' . ($team ? $team->name : 'the team') . ', at no cost. You\'re back on the free agent list — teams with room can invite you, or you can ask them.');
        }
        return null;
    }

    /** Captain actions: the person must captain THIS (approved) team. */
    private static function process_captain($do, $post, $user_id) {
        $team = RSL_Teams::get(self::post_int($post, 'team_id'));
        if (!$team || !RSL_Teams::is_captain($team->id, $user_id)) {
            return self::result('error', 'Only the team captain can do that.');
        }
        if ($team->status !== 'active') {
            return self::result('error', 'That opens once the league has approved your team.');
        }

        switch ($do) {
            case 'cap_spots':
                $saved = RSL_Free_Agents::set_spots($team->id, $user_id, array(
                    'spots' => self::post_int($post, 'spots'),
                    'positions' => self::post_keys($post, 'spots_positions'),
                    'level' => self::post_key($post, 'spots_level'),
                    'note' => sanitize_text_field(self::post_text($post, 'spots_note')),
                ));
                if (is_wp_error($saved)) {
                    return self::result('error', $saved->get_error_message());
                }
                return self::result('success', intval($saved->spots_open) > 0
                    ? 'Saved — ' . $team->name . ' is on the board for free agents looking for a team.'
                    : 'Saved — ' . $team->name . ' isn\'t listed as looking for players.');

            case 'cap_accept_request':
            case 'cap_decline_request':
                $accept = $do === 'cap_accept_request';
                $request_id = self::post_int($post, 'request_id');
                $card = null;
                foreach (RSL_Free_Agents::requests_for_team($team->id) as $c) {
                    if ($c['request_id'] === $request_id) {
                        $card = $c;
                    }
                }
                if (!$card) {
                    return self::result('error', 'That request isn\'t open any more.');
                }
                $done = RSL_Free_Agents::respond_request($request_id, $user_id, $accept);
                if (is_wp_error($done)) {
                    return self::result('error', self::for_captain($done, $team, $card['friends_n']));
                }
                return $accept
                    ? self::result('success', $card['first_name'] . self::plus_friends($card['friends_n']) . ' joined ' . $team->name . '. We\'ve emailed you each other\'s contact details — say hi and add them to your team chat.')
                    : self::result('success', 'Done — ' . $card['first_name'] . ' has been told you can\'t take them this time.');

            case 'cap_pool_invite':
                $fa_id = self::post_int($post, 'fa_id');
                $filters = self::filters($post);
                $base = !empty($post['back']) ? wp_validate_redirect(esc_url_raw(wp_unslash($post['back'])), RSL_Portal::url()) : RSL_Portal::url();
                $back = array_filter($filters) ? add_query_arg(array_filter($filters), $base) : null;
                $card = null;
                foreach (RSL_Free_Agents::browse_for_captain($team->id, $user_id) as $c) {
                    if ($c['fa_id'] === $fa_id) {
                        $card = $c;
                    }
                }
                $done = RSL_Free_Agents::invite_from_pool($team->id, $user_id, $fa_id);
                if (is_wp_error($done)) {
                    return self::result('error', $done->get_error_message(), $back);
                }
                return self::result('success', 'Invite sent to ' . ($card ? $card['first_name'] . self::plus_friends($card['friends_n']) : 'them') . '. They join once they accept — the invite lasts until ' . self::day(RSL_Free_Agents::expiry_for($team->season)) . '.', $back);
        }
        return null;
    }

    /**
     * After the portal registers someone: act on their "How are you
     * joining?" choice. find → onto the list for the season they picked
     * (fa_season, through RSL_Seasons::pick_open(); and their friend's group,
     * if they typed a code); start → the captain application goes in at once
     * with the team fields (RSL_Portal::apply_from_post(), 0.17.0); later → a
     * plain thank-you. Returns array(type, message[, open_apply]), or null for
     * join (the portal uses the invite code itself) and for no choice.
     * open_apply is set only on a failed application that can be sent again
     * from the #rsl-apply card (RSL_Portal::apply_retry()); the registration
     * stands either way. Each message already says they're registered — flash
     * it as it is.
     */
    public static function after_register($user_id, $post) {
        $post = is_array($post) ? $post : array();
        $user_id = intval($user_id);
        switch (self::post_key($post, 'route')) {
            case 'find':
                $season = RSL_Seasons::pick_open(self::post_text($post, 'fa_season'));
                $row = is_wp_error($season) ? $season : RSL_Free_Agents::join($user_id, $season, self::agent_data($post));
                if (is_wp_error($row)) {
                    return self::result('error', 'You\'re registered, but we couldn\'t put you on the free agent list: ' . $row->get_error_message());
                }
                return self::result('success', 'You\'re registered and on the free agent list for ' . RSL_Seasons::label($season) . '. You don\'t have to do anything else — we\'ll find you a team.' . self::friend_code_after_join($user_id, $season, $post));
            case 'start':
                $team = RSL_Portal::apply_from_post($user_id, $post);
                if (is_wp_error($team)) {
                    $retry = RSL_Portal::apply_retry($team); // the card will be there to send it again
                    return array('type' => 'error', 'message' => 'You\'re registered, but your team application didn\'t go through: ' . $team->get_error_message() . ($retry ? ' Check the details below and send it again.' : ''), 'open_apply' => $retry);
                }
                // A full division never refuses it (0.19.0): the captain hears it's on the waiting list instead
                // (apply_from_post() says whether it is; the words leave out a season the aside has just named).
                $aside = RSL_Portal::season_aside($team->season);
                $waiting = !empty($team->waiting_list) ? RSL_Capacity::waiting_note($team, $aside === '') : '';
                return self::result('success', 'You\'re registered and your application for ' . $team->name . $aside . ' is in. '
                    . ($waiting !== '' ? $waiting : 'Once the league approves it you\'ll get an invite link for your players.'));
            case 'later':
                // With nothing open for sign-up (0.18.0) an invite code is the only way onto a team.
                return self::result('success', 'Thanks — you\'re registered for the Reds Social League. Whenever you\'re ready, '
                    . (RSL_Seasons::signup_choices() ? 'join a team, start one or ask us to find you one' : 'join a team with an invite code') . ' from this page.');
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Registration: the route choice and the free-agent fields
    // ------------------------------------------------------------------

    /**
     * Inside the register form: "How are you joining?" — a radio named route
     * (join|start|find|later). The matching panel opens with CSS :checked, so
     * no JavaScript. Start a team holds the team fields (the application goes
     * in with the registration) and Find me a team the season picker, both
     * over RSL_Seasons::signup_choices() (0.17.0). Nothing inside a panel is
     * required: a hidden required field would block the form. Arriving on an
     * invite link, joining that team comes first and is chosen (the form
     * already carries the code), and since 0.23.3 the other routes stay for
     * someone who'd rather not (choosing one forgets the invite,
     * RSL_Portal::process()). With nothing open for
     * sign-up (0.18.0) the routes are an invite code or Not yet, under
     * RSL_Seasons::closed_words(), and the legend names the league.
     */
    public static function route_fields($player, $season, $invited_to) {
        $id = self::uid('rsl-route');
        // Worked out first: the legend, before the invite branch, names the season offered.
        $choices = RSL_Seasons::signup_choices();
        $multi = count($choices) > 1;
        $pick = RSL_Seasons::default_pick($choices); // the season the pickers start on
        $routes = array(
            'join' => array('Join a team I know', 'I have an invite code or link from a captain.'),
            'start' => array('Start a team', 'I\'ll captain it — the application goes in with my registration.'),
            'find' => array('Find me a team', 'Put me on the free agent list and the league finds me a team.'),
            'later' => array('Not yet', 'Just register for now.'),
        );
        if (!$choices) {
            // Nothing open for sign-up (0.18.0): no team to start and no list to join; an invite
            // code (its team's own season) and Not yet stay, and the form says why.
            unset($routes['start'], $routes['find']);
        }
        $invite_season = $invited_to ? (!empty($invited_to->season) ? $invited_to->season : $season) : '';
        if ($invited_to && !$multi && ($pick === '' || $pick === $invite_season)) {
            // The invite's own season (a team in a later season open for sign-up can invite too).
            $legend = 'How are you joining ' . RSL_Seasons::label($invite_season) . '?';
        } else {
            $legend = $multi || $pick === '' ? 'How are you joining the Reds Social League?' : 'How are you joining ' . RSL_Seasons::label($pick) . '?';
        }
        if ($invited_to) {
            // Invited (0.23.3): joining that team comes first and is chosen, and the other ways in
            // stay (start a team, the free agent list, not yet) for someone who'd rather not. No
            // second code box: the form carries the invite's code, and another code can be used
            // from the league page once registered.
            $routes = array('join' => array('Join ' . $invited_to->name, 'Your captain\'s invite — you\'ll go straight onto the team when you register.')) + array_diff_key($routes, array('join' => true));
        }
        ob_start();
        ?>
        <fieldset class="rsl-fa-routes">
            <legend><?php echo esc_html($legend); ?></legend>
                <?php if (!$choices && !$invited_to): ?><p class="rsl-hint"><?php echo esc_html(RSL_Seasons::closed_words()); ?></p><?php endif; ?>
                <?php foreach ($routes as $key => $words): ?>
                    <input type="radio" class="rsl-fa-radio rsl-fa-r-<?php echo esc_attr($key); ?>" name="route" value="<?php echo esc_attr($key); ?>" id="<?php echo esc_attr($id . '-' . $key); ?>"<?php echo $key === 'join' ? ($invited_to ? ' checked' : ' required') : ''; ?>>
                    <label class="rsl-fa-route" for="<?php echo esc_attr($id . '-' . $key); ?>"><?php echo esc_html($words[0]); ?><small><?php echo esc_html($words[1]); ?></small></label>
                <?php endforeach; ?>
                <?php if (!$invited_to): ?>
                <div class="rsl-fa-panel rsl-fa-p-join">
                    <label class="rsl-field">Invite code
                        <input type="text" name="join_code" maxlength="16" autocapitalize="characters" autocomplete="off" placeholder="e.g. ABCD2345">
                    </label>
                    <p class="rsl-hint">Your captain can find it on their league page — or just open the invite link they sent you.</p>
                </div>
                <?php endif; ?>
                <?php if (isset($routes['start'])): ?>
                <div class="rsl-fa-panel rsl-fa-p-start">
                    <p class="rsl-hint">Tell us about your team — your application goes in when you register.</p>
                    <?php echo RSL_Portal::team_fields(array(), $choices, false); ?>
                </div>
                <?php endif; ?>
                <?php if (isset($routes['find'])): ?>
                <div class="rsl-fa-panel rsl-fa-p-find">
                    <?php echo RSL_Portal::season_field('fa_season', $choices, $pick); ?>
                    <?php echo self::fields_html(null, $player, $pick, $multi); ?>
                </div>
                <?php endif; ?>
                <div class="rsl-fa-panel rsl-fa-p-later">
                    <p class="rsl-hint"><?php if ($choices): ?>No problem — you can join a team, start one or ask us to find you one from this page any time.<?php else: ?>No problem — you can join a team with an invite code from this page any time.<?php endif; ?></p>
                </div>
        </fieldset>
        <?php
        return ob_get_clean() . self::styles();
    }

    /**
     * The free agent's choices: division (Ranked and Either are consent to
     * Ranked), game time, weeks away (once the season's nights exist),
     * happy to captain, let captains find me — plus a friend code
     * when joining, what it costs, and the contact-details consent line.
     * Used at registration, on the "Find me a team" card and to edit.
     */
    public static function agent_fields($row, $player, $season) {
        return self::fields_html($row, $player, $season) . self::styles();
    }

    /**
     * $season: the season whose nights the weeks away list. $picker (0.17.0): a season picker
     * sits beside these fields, so the weeks away name their season and say how to set another
     * season's (without JavaScript the list can't follow the picker; RSL_Free_Agents::clean()
     * drops dates that aren't the chosen season's nights).
     */
    private static function fields_html($row, $player, $season, $picker = false) {
        $s = RSL_Free_Agents::settings();
        $prefs = RSL_Free_Agents::division_prefs();
        // Never assume consent to Ranked: before we know their level, Unranked.
        $pref = $row && isset($prefs[$row->division_pref]) ? $row->division_pref : ($player ? RSL_Free_Agents::default_division($player) : 'unranked');
        $time = $row ? $row->time_pref : 'any';
        $away = $row && $row->away_dates !== '' ? explode(',', $row->away_dates) : array();
        $nights = array();
        foreach (RSL_Courts::nights($season) as $n) {
            if ($n->type === 'regular') {
                $nights[] = $n->night_date;
            }
        }
        ob_start();
        ?>
        <div class="rsl-fa-fields">
            <fieldset class="rsl-field">
                <legend>Which division?</legend>
                <?php foreach ($prefs as $key => $label): ?>
                    <label class="rsl-check"><input type="radio" name="division_pref" value="<?php echo esc_attr($key); ?>" <?php checked($pref, $key); ?>> <?php echo esc_html($label); ?></label>
                <?php endforeach; ?>
                <p class="rsl-hint">Picking Ranked or Either is your OK to be placed in a Ranked team. Pick Unranked and you'll only ever be offered Unranked teams.</p>
            </fieldset>
            <label class="rsl-field">Game time
                <select name="time_pref">
                    <?php foreach (RSL_Teams::time_preferences() as $key => $label): ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected($time, $key); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php if ($nights): ?>
                <fieldset class="rsl-field">
                    <legend><?php echo esc_html($picker ? 'Weeks you\'ll miss in ' . RSL_Seasons::short_label($season) . ' (optional)' : 'Weeks you\'ll miss (optional)'); ?></legend>
                    <?php foreach ($nights as $date): ?>
                        <label class="rsl-check rsl-inline"><input type="checkbox" name="away_dates[]" value="<?php echo esc_attr($date); ?>" <?php checked(in_array($date, $away, true)); ?>> <?php echo esc_html(self::day($date)); ?></label>
                    <?php endforeach; ?>
                    <?php if ($picker): ?>
                        <p class="rsl-hint">Picked another season? Set its weeks after you join, under Your free agent details.</p>
                    <?php endif; ?>
                    <p class="rsl-hint">Helps us avoid leaving a team short. It's never shown to captains.</p>
                </fieldset>
            <?php endif; ?>
            <label class="rsl-check"><input type="checkbox" name="captain_ok" value="1" <?php checked($row && $row->captain_ok); ?>> Happy to captain if we form a new team</label>
            <p class="rsl-hint rsl-fa-sub">Captains start the team chat, make sure everyone's cleared to play and keep the team balance topped up.</p>
            <label class="rsl-check"><input type="checkbox" name="browsable" value="1" <?php checked(!$row || $row->browsable); ?>> Let captains find me</label>
            <p class="rsl-hint rsl-fa-sub">Captains with open spots see only your first name, level and positions, and can invite you. You choose whether to accept.</p>
            <?php if (!RSL_Free_Agents::is_open($row) && $s['group_max'] > 1): ?>
                <label class="rsl-field">Coming with friends? Their friend code (optional)
                    <input type="text" name="fa_friend_code" maxlength="12" autocapitalize="characters" autocomplete="off">
                </label>
                <p class="rsl-hint">No code yet? Once you're on the list you can make one to share — up to <?php echo intval($s['group_max']); ?> of you are placed on the same team.</p>
            <?php endif; ?>
            <?php echo self::costs_box(); ?>
            <p class="rsl-hint">When you join a team through the league, you and your captain are emailed each other's name and email.</p>
        </div>
        <?php
        return ob_get_clean();
    }

    /** Registering and the list are free; rego after placement; the team pays per game. */
    private static function costs_box() {
        $fee = (float) RSL_Payments::settings()['game_fee'];
        $n = max(1, intval(RSL_Free_Agents::settings()['new_team_target']));
        $lo = (int) floor($fee / $n);
        $hi = (int) ceil($fee / $n);
        $share = $lo === $hi ? '$' . $lo : '$' . $lo . '–' . $hi;
        ob_start();
        ?>
        <div class="rsl-fa-costs">
            <strong>What it costs</strong>
            <ul>
                <li>Registering and the free agent list are free.</li>
                <li>Once you're placed, pay your social rego (<?php echo esc_html(self::rego_price()); ?>) before your first game. Full and life club members don't need one.</li>
                <?php if ($fee > 0): ?>
                    <li>Your team pays <?php echo esc_html(RSL_Payments::money($fee)); ?> a game from its team balance — about <?php echo esc_html($share); ?> each with <?php echo intval($n); ?> players.</li>
                <?php endif; ?>
            </ul>
        </div>
        <?php
        return ob_get_clean();
    }

    // ------------------------------------------------------------------
    // A player without a team
    // ------------------------------------------------------------------

    /**
     * Everything for a registered player with no team (and no team
     * application) in $season: on the list → the "Your season" card (or, if
     * a placement run couldn't fit them, the unplaced card), the teams board,
     * their requests, friend group and details; not on the list and $find →
     * the "Find me a team" card over $choices (key => label with dates; null:
     * RSL_Seasons::signup_choices(); empty: '' when every season on offer already
     * has their team, application or list entry; the closed sentence when nothing
     * is open, 0.18.0); not on the list and not
     * $find (a later season's part of the page) → ''.
     */
    public static function dashboard($user_id, $season, $choices = null, $find = true) {
        $user_id = intval($user_id);
        if (!RSL_Seasons::is_valid($season)) {
            $season = RSL_Seasons::signup();
        }
        if (!$user_id || !RSL_Players::is_registered($user_id) || RSL_Teams::why_cant_join($user_id, $season)) {
            return '';
        }
        $player = RSL_Players::get($user_id);
        $row = RSL_Free_Agents::get($user_id, $season);
        if (RSL_Free_Agents::is_open($row)) {
            $html = $row->status === 'unplaced' ? self::unplaced_card($row) : self::season_card($row, $season);
            $html .= self::teams_board($row, $season);
            $html .= self::my_requests($user_id, $season);
            $html .= self::friends_card($row, $season);
            $html .= self::edit_card($row, $player, $season);
        } elseif ($find) {
            $choices = $choices === null ? RSL_Seasons::signup_choices() : (is_array($choices) ? $choices : array());
            if (!$choices) {
                // Nothing open for sign-up at all (0.18.0): say so where Find me a team would be.
                // Every season on offer already taken (a team, application or list entry): nothing.
                return RSL_Seasons::signup_choices() ? '' : '<div class="rsl-fa"><div class="rsl-card rsl-fa-closed"><p>' . esc_html(RSL_Seasons::closed_words()) . '</p></div></div>' . self::styles();
            }
            $html = self::find_card($row, $player, $season, $choices);
        } else {
            return '';
        }
        return '<div class="rsl-fa">' . $html . '</div>' . self::styles();
    }

    /** A card's id: $base for the sign-up season, "$base-<season>" for another (two seasons' cards on one page). */
    private static function anchor($base, $season) {
        return $season === RSL_Seasons::signup() ? $base : $base . '-' . $season;
    }

    /** The step card with the season's real dates (or none yet). */
    private static function season_card($row, $season) {
        $s = RSL_Free_Agents::settings();
        $d = RSL_Free_Agents::dates($season);
        $phase = RSL_Free_Agents::phase($season);
        $others = max(0, RSL_Free_Agents::counts($season)['looking'] - 1);
        $later = $phase === 'season'; // placements confirmed (or night 1 passed): placed as soon as a team has room
        $started = self::started($d); // night 1 has come — about a week after confirmation

        if ($later) {
            $ask = 'Teams with room can invite you, or ask them yourself';
        } elseif ($d) {
            $ask = $phase === 'before' ? 'From ' . self::day($d['opens']) . ' to ' . self::day($d['deadline']) : 'Until ' . self::day($d['deadline']);
        } else {
            $ask = 'Ask up to ' . $s['max_requests'] . ' teams, or a captain invites you';
        }
        // Already cleared for their first game (a rego still running or paid ahead for this
        // season, or a full/life membership): nothing to pay. Judged on the first night until it comes.
        $clear = RSL_Rego::clearance_for(intval($row->user_id), $season);
        $rego_step = !empty($clear['cleared'])
            ? array('Cleared to play', $clear['by'] === 'member' ? 'Your club membership covers the rego' : 'Your social rego is paid', 'done')
            : array('Pay your rego (' . self::rego_price() . ')', 'Once you\'re placed, before ' . ($d && !$started ? self::day($d['first_night']) : 'your first game'), '');
        $steps = array(
            array('Registered', '', 'done'),
            array('On the free agent list', '', 'done'),
            array('Ask a team, or get invited', $ask, $phase === 'placing' ? 'done' : 'now'),
            array('We place everyone else', $later ? 'As soon as a team has room' : ($d ? 'By ' . self::day($d['confirm_by']) : 'Before the season starts'), $phase === 'placing' ? 'now' : ''),
            $rego_step,
            array('First game', $d && !$started ? self::day($d['first_night']) : '', ''),
        );
        if ($phase === 'before' && $d) {
            $note = 'Teams start listing open spots on ' . self::day($d['opens']) . '.';
        } elseif ($phase === 'placing') {
            $note = 'The deadline has passed — we\'re making placements now. ' . ($d && $d['confirm_by'] >= current_time('Y-m-d') ? 'You\'ll hear from us by ' . self::day($d['confirm_by']) . '.' : 'You\'ll hear from us soon.');
        } elseif ($later && !$started) {
            $note = 'Placements are done' . ($d ? ' — the season starts ' . self::day($d['first_night']) : '') . '. Teams with room can still invite you, or ask them below.';
        } elseif ($later) {
            $note = 'The season\'s under way. We\'ll place you as soon as a team has room — and you can ask teams with open spots below.';
        } elseif (!$d) {
            $note = 'Dates are set once the season\'s calendar is out — check back here.';
        } else {
            $note = '';
        }
        ob_start();
        ?>
        <div class="rsl-card rsl-card-accent rsl-fa-season">
            <h3>Your season</h3>
            <p>You're on the free agent list for <?php echo esc_html(RSL_Seasons::label($season)); ?>.</p>
            <ol class="rsl-fa-steps">
                <?php foreach ($steps as $step): ?>
                    <li class="<?php echo esc_attr($step[2] !== '' ? 'is-' . $step[2] : ''); ?>"><?php echo esc_html($step[0]); ?><?php if ($step[1] !== ''): ?><small><?php echo esc_html($step[1]); ?></small><?php endif; ?></li>
                <?php endforeach; ?>
            </ol>
            <?php if ($note !== ''): ?><p class="rsl-hint"><?php echo esc_html($note); ?></p><?php endif; ?>
            <p><strong>You don't have to do anything else.</strong><?php if ($others > 0): ?> <?php echo $others === 1 ? '1 other player is' : intval($others) . ' others are'; ?> looking too.<?php endif; ?></p>
            <p class="rsl-fa-links"><a href="#<?php echo esc_attr(self::anchor('rsl-fa-edit', $season)); ?>">Edit my details</a><?php if ($s['group_max'] > 1): ?> &middot; <a href="#<?php echo esc_attr(self::anchor('rsl-fa-friends', $season)); ?>">Friend group</a><?php endif; ?> &middot; <a href="#<?php echo esc_attr(self::anchor('rsl-fa-edit', $season)); ?>">Leave the list</a></p>
        </div>
        <?php
        return ob_get_clean();
    }

    /** A placement run couldn't fit them: why, not charged, first in line. */
    private static function unplaced_card($row) {
        $why = self::unplaced_why($row);
        ob_start();
        ?>
        <div class="rsl-card rsl-card-warn rsl-fa-unplaced">
            <h3>We couldn't place you on a team this time</h3>
            <?php if ($why !== ''): ?><p><?php echo esc_html($why); ?></p><?php endif; ?>
            <p>You haven't been charged anything. You're first in line if a spot opens on a team, and the league will be in touch if one does.</p>
            <p class="rsl-hint">Meanwhile you can ask teams with open spots below, or update your details.</p>
        </div>
        <?php
        return ob_get_clean();
    }

    /** "Teams looking for players": active teams in their division with open spots. */
    private static function teams_board($row, $season) {
        $s = RSL_Free_Agents::settings();
        $d = RSL_Free_Agents::dates($season);
        $phase = RSL_Free_Agents::phase($season);
        $items = $phase === 'placing' ? array() : RSL_Free_Agents::teams_looking($season, $row->user_id);
        $levels = RSL_Free_Agents::spots_levels();
        ob_start();
        ?>
        <div class="rsl-card rsl-fa-board" id="<?php echo esc_attr(self::anchor('rsl-fa-teams', $season)); ?>">
            <h3>Teams looking for players</h3>
            <?php if ($phase === 'placing'): ?>
                <p class="rsl-hint">Asking teams is paused while we make placements.</p>
            <?php else: ?>
                <p class="rsl-hint">
                    <?php if ($phase === 'before'): ?>
                        <?php echo esc_html(($d ? 'From ' . self::day($d['opens']) : 'Soon') . ', you can ask up to ' . $s['max_requests'] . ' teams at a time to take you.'); ?>
                    <?php else: ?>
                        <?php echo esc_html('Ask up to ' . $s['max_requests'] . ' teams at a time. The captain sees only your first name, level and positions, and says yes or not this time.'); ?>
                    <?php endif; ?>
                </p>
                <?php if (!$items): ?>
                    <p><?php echo esc_html($phase === 'season'
                        ? 'No teams in your division are listing open spots right now — check back here. You stay on the list, so the league can still place you.'
                        : 'No teams in your division are listing open spots right now. No need to worry — we place everyone else' . ($d ? ' after ' . self::day($d['deadline']) : '') . '.'); ?></p>
                <?php else: ?>
                    <ul class="rsl-fa-list">
                        <?php foreach ($items as $item): ?>
                            <?php
                            $t = $item['team'];
                            $why = $item['why'];
                            if ($phase === 'before' && !$item['waiting']) {
                                $why = $d ? 'Asking opens ' . self::day($d['opens']) : 'Asking opens soon';
                            }
                            ?>
                            <li>
                                <?php echo RSL_Public::badge($t); ?>
                                <div class="rsl-fa-main">
                                    <strong><a href="<?php echo esc_url(RSL_Public::team_url($t)); ?>"><?php echo esc_html($t->name); ?></a></strong>
                                    <span class="rsl-meta"><?php echo esc_html(self::division_label($t->division) . ' · ' . intval($item['members']) . ' of ' . intval($s['max_roster']) . ' players'); ?></span>
                                    <p>
                                        <span class="rsl-fa-tag">Needs <?php echo intval($t->spots_open); ?></span>
                                        <?php foreach (RSL_Free_Agents::position_labels($t->spots_positions) as $pos): ?><span class="rsl-fa-tag"><?php echo esc_html($pos); ?></span><?php endforeach; ?>
                                        <?php if (isset($levels[$t->spots_level]) && $t->spots_level !== 'any'): ?><span class="rsl-fa-tag"><?php echo esc_html($levels[$t->spots_level]); ?></span><?php endif; ?>
                                    </p>
                                    <?php if ($t->spots_note !== ''): ?><p class="rsl-fa-note">&ldquo;<?php echo esc_html($t->spots_note); ?>&rdquo;</p><?php endif; ?>
                                </div>
                                <div class="rsl-fa-act">
                                    <?php if ($item['can_ask'] && $phase !== 'before'): ?>
                                        <form method="post" class="rsl-inline-form"><?php echo RSL_Portal::form_fields('fa_request', array('team_id' => $t->id)); ?>
                                            <button type="submit" class="rsl-btn">Ask to join<?php echo $item['for_n'] > 0 ? esc_html(' (for you + ' . self::friends_words($item['for_n']) . ')') : ''; ?></button></form>
                                    <?php else: ?>
                                        <span class="rsl-fa-why"><?php echo esc_html($why); ?></span>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /** Their requests (and their group's): waiting, accepted, not this time, expired. */
    private static function my_requests($user_id, $season) {
        $words = array('pending' => '', 'accepted' => 'Accepted', 'declined' => 'Not this time', 'expired' => 'No answer in time');
        $rows = array();
        foreach (RSL_Free_Agents::requests_for_user($user_id, $season) as $r) {
            if (isset($words[$r->status])) {
                $rows[] = $r;
            }
        }
        if (!$rows) {
            return '';
        }
        ob_start();
        ?>
        <div class="rsl-card rsl-fa-requests">
            <h3>Your requests</h3>
            <ul class="rsl-fa-list">
                <?php foreach ($rows as $r): ?>
                    <li>
                        <?php echo RSL_Public::badge((object) array('colour' => $r->colour, 'name' => $r->team_name)); ?>
                        <div class="rsl-fa-main">
                            <strong><a href="<?php echo esc_url(RSL_Public::team_url((object) array('slug' => $r->slug, 'season' => $r->season))); ?>"><?php echo esc_html($r->team_name); ?></a></strong>
                            <?php if (intval($r->user_id) !== intval($user_id)): ?><span class="rsl-meta">asked by <?php echo esc_html(RSL_Free_Agents::first_name($r->user_id)); ?> for your group</span><?php endif; ?>
                            <?php if ($r->status === 'pending'): ?>
                                <p class="rsl-fa-status is-wait">Waiting — the captain has until <?php echo esc_html(self::day($r->expires_at)); ?></p>
                            <?php else: ?>
                                <p class="rsl-fa-status <?php echo $r->status === 'accepted' ? 'is-ok' : 'is-no'; ?>"><?php echo esc_html($words[$r->status]); ?></p>
                            <?php endif; ?>
                        </div>
                        <?php if ($r->status === 'pending'): ?>
                            <form method="post" class="rsl-inline-form"><?php echo RSL_Portal::form_fields('fa_withdraw_request', array('request_id' => $r->id)); ?><button type="submit" class="rsl-link">Withdraw</button></form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
        return ob_get_clean();
    }

    /** Friend group: make a code, enter a code, see first names, leave. */
    private static function friends_card($row, $season) {
        $max = intval(RSL_Free_Agents::settings()['group_max']);
        if ($max < 2) {
            return '';
        }
        $members = $row->group_code !== '' ? RSL_Free_Agents::group_members($season, $row->group_code) : array();
        $names = array();
        foreach ($members as $m) {
            $names[] = intval($m->user_id) === intval($row->user_id) ? 'You' : RSL_Free_Agents::first_name($m->user_id);
        }
        $left = $max - count($members);
        $join_form = '<form method="post" class="rsl-row">' . RSL_Portal::form_fields('fa_group_join', array('season' => $season))
            . '<input type="text" name="group_code" placeholder="Friend code" required maxlength="12" autocapitalize="characters" autocomplete="off">'
            . '<button type="submit" class="rsl-btn rsl-btn-secondary">Join their group</button></form>';
        ob_start();
        ?>
        <div class="rsl-card rsl-fa-friends" id="<?php echo esc_attr(self::anchor('rsl-fa-friends', $season)); ?>">
            <h3>Coming with friends?</h3>
            <?php if (count($members) > 1): ?>
                <p>You're in a friend group: <strong><?php echo esc_html(implode(', ', $names)); ?></strong>. You'll be placed on the same team.</p>
                <?php if ($left > 0): ?>
                    <p>Friend code: <span class="rsl-fa-code"><?php echo esc_html($row->group_code); ?></span> — <?php echo esc_html($left === 1 ? 'one more friend' : 'up to ' . $left . ' more friends'); ?> can join with it.</p>
                <?php endif; ?>
                <form method="post" onsubmit="return confirm('<?php echo esc_js('Leave your friend group? You\'ll be placed on your own.'); ?>');"><?php echo RSL_Portal::form_fields('fa_group_leave', array('season' => $season)); ?><button type="submit" class="rsl-link">Leave the group</button></form>
            <?php elseif ($row->group_code !== ''): ?>
                <p>Your friend code: <span class="rsl-fa-code"><?php echo esc_html($row->group_code); ?></span></p>
                <p class="rsl-hint">Share it with <?php echo esc_html($left === 1 ? 'one friend' : 'up to ' . $left . ' friends'); ?> on the free agent list — everyone who enters it is placed on the same team. Nobody has joined yet.</p>
                <p class="rsl-hint">Got a friend's code instead?</p>
                <?php echo $join_form; ?>
                <form method="post"><?php echo RSL_Portal::form_fields('fa_group_leave', array('season' => $season)); ?><button type="submit" class="rsl-link">Stop using this code</button></form>
            <?php else: ?>
                <p>Up to <?php echo intval($max); ?> of you can be placed on the same team. One of you makes a code and shares it; the others enter it here.</p>
                <form method="post" class="rsl-row"><?php echo RSL_Portal::form_fields('fa_group_create', array('season' => $season)); ?><button type="submit" class="rsl-btn rsl-btn-secondary">Make a friend code</button></form>
                <?php echo $join_form; ?>
            <?php endif; ?>
            <p class="rsl-hint">Your friends need to be on the free agent list too, with divisions that overlap yours. Codes are shared by you — we never look anyone up by email.</p>
        </div>
        <?php
        return ob_get_clean();
    }

    /** Edit their choices, or leave the list. */
    private static function edit_card($row, $player, $season) {
        ob_start();
        ?>
        <details class="rsl-card rsl-fa-edit" id="<?php echo esc_attr(self::anchor('rsl-fa-edit', $season)); ?>">
            <summary><strong>Your free agent details</strong></summary>
            <form method="post" class="rsl-form"><?php echo RSL_Portal::form_fields('fa_update', array('season' => $season)); ?><?php echo self::fields_html($row, $player, $season); ?><button type="submit" class="rsl-btn">Save</button></form>
            <form method="post" class="rsl-fa-leave" onsubmit="return confirm('<?php echo esc_js('Leave the free agent list? Requests you\'ve sent are withdrawn and you leave your friend group.'); ?>');"><?php echo RSL_Portal::form_fields('fa_leave', array('season' => $season)); ?><button type="submit" class="rsl-link">Leave the free agent list</button></form>
        </details>
        <?php
        return ob_get_clean();
    }

    /**
     * Not on the list: "Find me a team" with the season picker and the fields in a form.
     * $row: $season's row (the sign-up season's), maybe withdrawn; $choices (never empty): the
     * seasons the picker offers. The words, dates, phase, teams looking and weeks away are for
     * the season the picker starts on (RSL_Seasons::default_pick()), which with a stale sign-up
     * pin is a later season, not $season.
     */
    private static function find_card($row, $player, $season, $choices) {
        $pick = RSL_Seasons::default_pick($choices); // the season the picker starts on
        $multi = count($choices) > 1;
        $own = $pick === $season ? $row : null;     // $row is $season's row (the sign-up season)
        $s = RSL_Free_Agents::settings();
        $d = RSL_Free_Agents::dates($pick);
        $phase = RSL_Free_Agents::phase($pick);
        $listing = 0;
        if (RSL_Free_Agents::matching_open($pick)) {
            foreach (RSL_Teams::list_for_season($pick) as $t) {
                if (intval($t->spots_open) > 0 && intval($t->member_count) < $s['max_roster']) {
                    $listing++;
                }
            }
        }
        if ($own && $own->status === 'withdrawn') {
            $intro = 'You\'re not on the free agent list for ' . RSL_Seasons::label($pick) . ' right now — join any time.';
        } elseif ($multi) {
            $intro = 'No team yet? Join the free agent list and we\'ll find you one.';
        } else {
            $intro = 'No team yet? Join the free agent list for ' . RSL_Seasons::label($pick) . ' and we\'ll find you one.';
        }
        $for = $multi ? 'For ' . RSL_Seasons::short_label($pick) . ': ' : '';
        ob_start();
        ?>
        <div class="rsl-card rsl-fa-find" id="<?php echo esc_attr(self::anchor('rsl-fa-find', $season)); ?>">
            <h3>Find me a team</h3>
            <p><?php echo esc_html($intro); ?></p>
            <?php if ($phase === 'placing'): ?>
                <p class="rsl-hint"><?php echo esc_html($for . 'The deadline has passed, but join anyway — we\'ll still try to fit you in.'); ?></p>
            <?php elseif ($phase === 'season'): ?>
                <p class="rsl-hint"><?php echo esc_html($for . (self::started($d) ? 'The season\'s under way' : 'Placements are done' . ($d ? ' and the season starts ' . self::day($d['first_night']) : '')) . ' — teams with room can invite you, you can ask them, and we\'ll place you when a spot opens.'); ?></p>
            <?php elseif ($d): ?>
                <p class="rsl-hint"><?php echo esc_html($for . ($phase === 'before' ? 'From ' . self::day($d['opens']) . ' to ' . self::day($d['deadline']) : 'Until ' . self::day($d['deadline'])) . ', teams with open spots can invite you and you can ask up to ' . $s['max_requests'] . ' teams yourself. We place everyone else by ' . self::day($d['confirm_by']) . '.'); ?></p>
            <?php else: ?>
                <p class="rsl-hint"><?php echo esc_html($for . 'Teams with open spots can invite you, you can ask up to ' . $s['max_requests'] . ' teams yourself, and we place everyone else before the season starts.'); ?></p>
            <?php endif; ?>
            <?php if ($listing): ?>
                <p class="rsl-hint"><?php echo esc_html($listing === 1 ? '1 team is looking for players right now.' : $listing . ' teams are looking for players right now.'); ?></p>
            <?php endif; ?>
            <details class="rsl-fa-open">
                <summary class="rsl-btn">Put me on the list</summary>
                <form method="post" class="rsl-form"><?php echo RSL_Portal::form_fields('fa_join'); ?><?php echo RSL_Portal::season_field('fa_season', $choices, $pick); ?><?php echo self::fields_html($own, $player, $pick, $multi); ?>
                    <button type="submit" class="rsl-btn">Put me on the free agent list</button></form>
            </details>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * For someone the league placed on a team who's still inside the
     * "Not for me" window: what happened, and a way to step back at no cost.
     */
    public static function placed_notice($user_id, $season) {
        $user_id = intval($user_id);
        $row = RSL_Free_Agents::get($user_id, $season);
        if (!$row || $row->status !== 'placed' || empty($row->optout_until) || $row->optout_until <= current_time('mysql')) {
            return '';
        }
        $team = RSL_Teams::get($row->team_id);
        if (!$team || $team->status !== 'active' || !RSL_Teams::is_member($team->id, $user_id)) {
            return '';
        }
        $captain = RSL_Teams::is_captain($team->id, $user_id);
        $confirm = 'Step back from ' . $team->name . '? You\'ll leave the team and go back on the free agent list.';
        ob_start();
        ?>
        <div class="rsl-card rsl-card-accent rsl-fa-placed">
            <p><strong>The league has placed you on <?php echo esc_html($team->name); ?></strong> (<?php echo esc_html(self::division_label($team->division)); ?>).</p>
            <?php if ($captain): ?>
                <p>You're the captain. We've emailed you your players' names and emails — start a group chat and say hi. If captaining isn't for you after all, contact the league.</p>
            <?php else: ?>
                <p>Your captain has been emailed your name and email, and you've been sent theirs — they'll add you to the team chat.</p>
                <p>Not for you? You can step back until <?php echo esc_html(self::day_time($row->optout_until)); ?>, at no cost — you'll go back on the free agent list.</p>
                <form method="post" onsubmit="return confirm('<?php echo esc_js($confirm); ?>');"><?php echo RSL_Portal::form_fields('fa_not_for_me', array('season' => $season)); ?><button type="submit" class="rsl-btn rsl-btn-secondary">Not for me</button></form>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean() . self::styles();
    }

    // ------------------------------------------------------------------
    // Captains
    // ------------------------------------------------------------------

    /**
     * The captain's free-agent tools for an active team: short / full
     * banner, "Looking for players?", requests to join, and browsing the
     * list (only first name, level, positions and +N friends ever shown).
     * Requests and browsing only while matching is open; otherwise why.
     */
    public static function captain_tools($team, $user_id, $back = '') {
        $user_id = intval($user_id);
        $back = (string) $back; // the page the forms come back to (the team page, 0.20.0); '' = the league page
        if (!$team || $team->status !== 'active' || !RSL_Teams::is_captain($team->id, $user_id)) {
            return '';
        }
        $s = RSL_Free_Agents::settings();
        $d = RSL_Free_Agents::dates($team->season);
        $phase = RSL_Free_Agents::phase($team->season);
        $open = RSL_Free_Agents::matching_open($team->season);
        $n = count(RSL_Teams::members($team->id));
        $room = max(0, $s['max_roster'] - $n);
        $listed = intval($team->spots_open) > 0;
        $short = $n < $s['min_roster'];

        if ($n >= $s['max_roster']) {
            $banner = array('is-full', 'Full (' . $n . ' of ' . $s['max_roster'] . ').');
        } elseif ($short) {
            $have = 'You have ' . $n . '; teams need ' . $s['min_roster'];
            if ($phase === 'placing') {
                $text = $have . '. We\'re making placements now and will add free agents to short teams.';
            } elseif ($phase === 'season') {
                $text = $have . '. Open some spots below so free agents can find you.';
            } else {
                $text = $have . ($d ? ' by ' . self::day($d['deadline']) : ' before the season starts') . '. We\'ll add free agents at the deadline unless you fill the spots.';
            }
            $banner = array('is-short', $text);
        } else {
            $banner = array('', $n . ' players — room for ' . $room . ' more (the most a team can have is ' . $s['max_roster'] . ').');
        }
        ob_start();
        ?>
        <div class="rsl-fa-captain">
            <p class="rsl-fa-banner <?php echo esc_attr($banner[0]); ?>"><?php echo esc_html($banner[1]); ?></p>
            <?php echo self::requests_section($team, $open, $room, $back); ?>
            <details class="rsl-fa-box"<?php echo $short && !$listed && $room > 0 ? ' open' : ''; ?>>
                <summary><strong>Looking for players?</strong> <span class="rsl-meta"><?php echo esc_html($listed ? 'Listed — needs ' . intval($team->spots_open) : 'Not listed'); ?></span></summary>
                <?php echo self::spots_form($team, $back); ?>
            </details>
            <?php echo self::browse_section($team, $user_id, $open, $room, $back); ?>
        </div>
        <?php
        return ob_get_clean() . self::styles();
    }

    /** "Looking for players?": how many, positions, who's welcome, a short note. */
    private static function spots_form($team, $back = '') {
        $positions = array_filter(explode(',', (string) $team->spots_positions));
        $level = $team->spots_level !== '' ? $team->spots_level : 'any';
        ob_start();
        ?>
        <form method="post" class="rsl-form rsl-fa-spots">
            <?php echo RSL_Portal::form_fields('cap_spots', self::with_back(array('team_id' => $team->id), $back)); ?>
            <label class="rsl-field">Players you're after
                <select name="spots">
                    <?php for ($i = 0; $i <= 4; $i++): ?>
                        <option value="<?php echo intval($i); ?>" <?php selected(intval($team->spots_open), $i); ?>><?php echo $i === 0 ? 'None — we\'re not looking' : intval($i); ?></option>
                    <?php endfor; ?>
                </select>
            </label>
            <fieldset class="rsl-field"><legend>Positions you need (optional)</legend>
                <?php foreach (RSL_Players::positions() as $key => $label): ?>
                    <label class="rsl-check rsl-inline"><input type="checkbox" name="spots_positions[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $positions, true)); ?>> <?php echo esc_html($label); ?></label>
                <?php endforeach; ?>
            </fieldset>
            <label class="rsl-field">Who's welcome
                <select name="spots_level">
                    <?php foreach (RSL_Free_Agents::spots_levels() as $key => $label): ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected($level, $key); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="rsl-field">A short note for free agents (optional)
                <input type="text" name="spots_note" maxlength="100" value="<?php echo esc_attr($team->spots_note); ?>" placeholder="e.g. Relaxed team, here for fun">
            </label>
            <p class="rsl-hint">With spots open, your team appears on the board for free agents in <?php echo esc_html(self::division_label($team->division)); ?>: they see your team's name, how many you need and this note — keep it about the team. Free agents can't message you; they can ask to join, and you decide.</p>
            <button type="submit" class="rsl-btn">Save</button>
        </form>
        <?php
        return ob_get_clean();
    }

    /** Requests waiting on the team, as RSL_Free_Agents::requests_for_team() gives them. */
    private static function requests_section($team, $open, $room, $back = '') {
        $requests = $open ? RSL_Free_Agents::requests_for_team($team->id) : array();
        ob_start();
        ?>
        <h4>Requests to join</h4>
        <?php if (!$open): ?>
            <p class="rsl-hint"><?php echo esc_html(self::closed_why($team->season)); ?></p>
        <?php elseif (!$requests): ?>
            <p class="rsl-hint">No requests waiting. Free agents can ask to join while your team has open spots.</p>
        <?php else: ?>
            <ul class="rsl-fa-list">
                <?php foreach ($requests as $c): ?>
                    <?php $fits = 1 + intval($c['friends_n']) <= $room; ?>
                    <li>
                        <div class="rsl-fa-main">
                            <strong><?php echo esc_html($c['first_name']); ?></strong>
                            <?php if ($c['friends_n'] > 0): ?><span class="rsl-fa-tag"><?php echo esc_html('+' . self::friends_words($c['friends_n'])); ?></span><?php endif; ?>
                            <p><?php echo esc_html(implode(' · ', array_filter(array_merge(array($c['level_label']), $c['positions'])))); ?></p>
                            <p class="rsl-hint">Answer by <?php echo esc_html(self::day($c['expires_at'])); ?></p>
                        </div>
                        <div class="rsl-fa-act">
                            <?php if ($fits): ?>
                                <form method="post" class="rsl-inline-form"><?php echo RSL_Portal::form_fields('cap_accept_request', self::with_back(array('team_id' => $team->id, 'request_id' => $c['request_id']), $back)); ?><button type="submit" class="rsl-btn">Accept</button></form>
                            <?php else: ?>
                                <span class="rsl-fa-why">Not enough room</span>
                            <?php endif; ?>
                            <form method="post" class="rsl-inline-form" onsubmit="return confirm('<?php echo esc_js('Say not this time to ' . $c['first_name'] . '? That\'s final for this season.'); ?>');"><?php echo RSL_Portal::form_fields('cap_decline_request', self::with_back(array('team_id' => $team->id, 'request_id' => $c['request_id']), $back)); ?><button type="submit" class="rsl-btn rsl-btn-secondary">Not this time</button></form>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="rsl-hint">Accepting adds them (and any friends with them) straight away, and emails you each other's name and email.</p>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }

    /** Browse the list (filters by GET rsl_pos / rsl_lvl) and invite. */
    private static function browse_section($team, $user_id, $open, $room, $back = '') {
        $s = RSL_Free_Agents::settings();
        $filters = self::filters($_GET);
        $cap = intval($team->spots_open) + $s['pool_invites_extra'];
        $waiting = self::waiting_pool_invites($team->id);
        $action = $back !== '' ? $back : RSL_Portal::url();
        ob_start();
        ?>
        <h4 id="rsl-fa-browse">Find free agents</h4>
        <?php if (!$open): ?>
            <p class="rsl-hint"><?php echo esc_html(self::closed_why($team->season)); ?></p>
        <?php elseif ($room < 1): ?>
            <p class="rsl-hint">Your team is full, so there's nobody to invite.</p>
        <?php else: ?>
            <?php $cards = RSL_Free_Agents::browse_for_captain($team->id, $user_id, array('position' => $filters['rsl_pos'], 'level' => $filters['rsl_lvl'])); ?>
            <form method="get" action="<?php echo esc_url($action . '#rsl-fa-browse'); ?>" class="rsl-fa-filters">
                <?php echo self::query_fields($action); ?>
                <label>Position
                    <select name="rsl_pos"><option value="">Any</option>
                        <?php foreach (RSL_Players::positions() as $key => $label): ?><option value="<?php echo esc_attr($key); ?>" <?php selected($filters['rsl_pos'], $key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label>Level
                    <select name="rsl_lvl"><option value="">Any</option>
                        <?php foreach (RSL_Players::levels() as $key => $label): ?><option value="<?php echo esc_attr($key); ?>" <?php selected($filters['rsl_lvl'], $key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="rsl-btn rsl-btn-secondary">Show</button>
                <?php if (array_filter($filters)): ?><a href="<?php echo esc_url($action . '#rsl-fa-browse'); ?>">Clear</a><?php endif; ?>
            </form>
            <p class="rsl-hint"><?php echo esc_html('You can have ' . $cap . ' invites from the list waiting at once (your open spots + ' . $s['pool_invites_extra'] . ') — you have ' . $waiting . '. Invites last ' . $s['request_days'] . ' days, and they join once they accept.'); ?></p>
            <?php if (!$cards): ?>
                <p><?php echo esc_html(array_filter($filters) ? 'Nobody matches those filters right now.' : 'Nobody on the free agent list fits your team right now — check back later.'); ?></p>
            <?php else: ?>
                <ul class="rsl-fa-list">
                    <?php foreach ($cards as $c): ?>
                        <?php $fits = 1 + intval($c['friends_n']) <= $room; ?>
                        <li>
                            <div class="rsl-fa-main">
                                <strong><?php echo esc_html($c['first_name']); ?></strong>
                                <?php if ($c['friends_n'] > 0): ?><span class="rsl-fa-tag"><?php echo esc_html('+' . self::friends_words($c['friends_n'])); ?></span><?php endif; ?>
                                <p><?php echo esc_html(implode(' · ', array_filter(array_merge(array($c['level_label']), $c['positions'])))); ?></p>
                            </div>
                            <div class="rsl-fa-act">
                                <?php if ($cap < 1): ?>
                                    <span class="rsl-fa-why">Open a spot first</span>
                                <?php elseif ($waiting >= $cap): ?>
                                    <span class="rsl-fa-why">Wait for answers first</span>
                                <?php elseif (!$fits): ?>
                                    <span class="rsl-fa-why">Not enough room</span>
                                <?php else: ?>
                                    <form method="post" class="rsl-inline-form"><?php echo RSL_Portal::form_fields('cap_pool_invite', self::with_back(array('team_id' => $team->id, 'fa_id' => $c['fa_id'], 'rsl_pos' => $filters['rsl_pos'], 'rsl_lvl' => $filters['rsl_lvl']), $back)); ?><button type="submit" class="rsl-btn">Invite</button></form>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }

    /** A form's hidden fields plus the page to come back to, when there is one (0.20.0). */
    private static function with_back($fields, $back) {
        if ($back !== '') {
            $fields['back'] = $back;
        }
        return $fields;
    }

    /** Pool invites still waiting on the team (needs source/expires_at from pending_invites_for_team()). */
    private static function waiting_pool_invites($team_id) {
        $now = current_time('mysql');
        $n = 0;
        foreach (RSL_Teams::pending_invites_for_team($team_id) as $inv) {
            if (isset($inv->source) && $inv->source === 'pool' && (empty($inv->expires_at) || $inv->expires_at > $now)) {
                $n++;
            }
        }
        return $n;
    }

    /** Why requests and browsing are shut right now. */
    private static function closed_why($season) {
        $d = RSL_Free_Agents::dates($season);
        if (RSL_Free_Agents::phase($season) === 'before') {
            return $d ? 'Requests and the free agent list open on ' . self::day($d['opens']) . '.' : 'Requests and the free agent list aren\'t open yet.';
        }
        return 'Paused while the league makes placements' . ($d && $d['confirm_by'] >= current_time('Y-m-d') ? ' — they\'re confirmed by ' . self::day($d['confirm_by']) : '') . '.';
    }

    // ------------------------------------------------------------------
    // Input
    // ------------------------------------------------------------------

    /** The free agent's choices from a form, unslashed (RSL_Free_Agents validates them). */
    private static function agent_data($post) {
        $away = array();
        foreach (isset($post['away_dates']) ? (array) $post['away_dates'] : array() as $date) {
            $date = is_scalar($date) ? trim((string) wp_unslash($date)) : '';
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $away[] = $date;
            }
        }
        return array(
            'division_pref' => self::post_key($post, 'division_pref'),
            'time_pref' => self::post_key($post, 'time_pref'),
            'away_dates' => $away,
            'captain_ok' => !empty($post['captain_ok']),
            'browsable' => !empty($post['browsable']),
        );
    }

    /**
     * The season an action on an existing row is for (0.17.0): the form's `season` when it's
     * the sign-up season, the season being played or one open for sign-up (the seasons the
     * league page shows). No `season` field at all (a page opened before 0.17.0): the sign-up
     * season, as before. A `season` that isn't one of those: WP_Error. The page is older than
     * the season pins, and acting on the sign-up season's row instead would change the wrong
     * list.
     */
    private static function row_season($post) {
        if (!isset($post['season'])) {
            return RSL_Seasons::signup();
        }
        $in = self::post_text($post, 'season');
        $ok = array_merge(array(RSL_Seasons::signup(), RSL_Seasons::current()), array_keys(RSL_Seasons::signup_choices()));
        return in_array($in, $ok, true) ? $in : new WP_Error('rsl_season_gone', 'That season isn\'t open any more — reload the page.');
    }

    /** A friend code typed while joining the list: join that group too. Returns words to add. */
    private static function friend_code_after_join($user_id, $season, $post) {
        $code = RSL_Free_Agents::clean_code(self::post_text($post, 'fa_friend_code'));
        if ($code === '') {
            return '';
        }
        $done = RSL_Free_Agents::group_join($user_id, $season, $code);
        return is_wp_error($done)
            ? ' About your friend code: ' . $done->get_error_message()
            : ' You\'re in your friends\' group, so you\'ll be placed on the same team.';
    }

    /** Requests this person sent themselves that are still waiting. */
    private static function own_waiting_requests($user_id, $season) {
        $n = 0;
        foreach (RSL_Free_Agents::requests_for_user($user_id, $season) as $r) {
            if ($r->status === 'pending' && intval($r->user_id) === intval($user_id)) {
                $n++;
            }
        }
        return $n;
    }

    /** Browse filters (from GET, or carried through a POST): known keys only. */
    private static function filters($source) {
        $source = is_array($source) ? $source : array();
        $pos = self::post_key($source, 'rsl_pos');
        $lvl = self::post_key($source, 'rsl_lvl');
        return array(
            'rsl_pos' => isset(RSL_Players::positions()[$pos]) ? $pos : '',
            'rsl_lvl' => isset(RSL_Players::levels()[$lvl]) ? $lvl : '',
        );
    }

    private static function post_text($post, $name) {
        return isset($post[$name]) && is_scalar($post[$name]) ? trim((string) wp_unslash($post[$name])) : '';
    }

    private static function post_key($post, $name) {
        return sanitize_key(self::post_text($post, $name));
    }

    private static function post_int($post, $name) {
        return isset($post[$name]) && is_scalar($post[$name]) ? intval($post[$name]) : 0;
    }

    private static function post_keys($post, $name) {
        $out = array();
        foreach (isset($post[$name]) ? (array) $post[$name] : array() as $value) {
            if (is_scalar($value)) {
                $out[] = sanitize_key(wp_unslash((string) $value));
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Small helpers
    // ------------------------------------------------------------------

    private static function result($type, $message, $back = null) {
        $out = array('type' => $type, 'message' => $message);
        if ($back) {
            $out['back'] = $back;
        }
        return $out;
    }

    /**
     * An error from answering a request, in words a captain may see.
     * RSL_Teams::add_member()'s messages name the player (display name, often
     * a full name) and any other team they're on — e.g. when two captains
     * accept the same person at once. The captain only knows a first name.
     */
    private static function for_captain($error, $team, $friends_n) {
        switch ($error->get_error_code()) {
            case 'rsl_elsewhere':
                return 'They\'ve joined a team already.';
            case 'rsl_pending':
                return 'They can\'t join a team right now.';
            case 'rsl_already':
                return 'They\'re on ' . $team->name . ' already.';
            case 'rsl_busy':
                return 'Please try again in a moment.';
            case 'rsl_full':
                return $team->name . ' doesn\'t have room for them' . (intval($friends_n) > 0 ? ' all' : '') . '.';
        }
        return $error->get_error_message();
    }

    /**
     * Why a placement run left them out, in words for them — or ''
     * (RSL_Free_Agents::unplaced_words(), the same rule as their email).
     */
    private static function unplaced_why($row) {
        return RSL_Free_Agents::unplaced_words($row->unplaced_reason);
    }

    /** Night 1 has come. (phase() says 'season' from confirmation, about a week before.) */
    private static function started($d) {
        return $d && current_time('Y-m-d') >= $d['first_night'];
    }

    /** What the rego costs ("$15 a season or $40 a year", or "$40, 12 months"), as the portal shows it; read once a page. */
    private static function rego_price() {
        static $label = null;
        if ($label === null) {
            $label = RSL_Rego::cost_words();
        }
        return $label;
    }

    /** "Sun 3 Jan" from a date or datetime. */
    private static function day($when) {
        return date_i18n('D j M', strtotime($when));
    }

    /** "Sun 3 Jan, 6:00pm". */
    private static function day_time($when) {
        return date_i18n('D j M, g:ia', strtotime($when));
    }

    private static function division_label($key) {
        return isset(RSL_Teams::DIVISIONS[$key]) ? RSL_Teams::DIVISIONS[$key] : '';
    }

    /** "1 friend", "2 friends". */
    private static function friends_words($n) {
        $n = intval($n);
        return $n . ' friend' . ($n === 1 ? '' : 's');
    }

    /** " + 1 friend" (or nothing). */
    private static function plus_friends($n) {
        return intval($n) > 0 ? ' + ' . self::friends_words($n) : '';
    }

    /** Ids unique within the page (label for= on the route radios). */
    private static function uid($base) {
        static $n = 0;
        $n++;
        return $base . '-' . $n;
    }

    /** Hidden inputs for a URL's own query (a GET form replaces it, e.g. ?page_id=12). */
    private static function query_fields($url) {
        $query = wp_parse_url($url, PHP_URL_QUERY);
        if (!$query) {
            return '';
        }
        parse_str($query, $args);
        $html = '';
        foreach ($args as $name => $value) {
            if (is_string($value) && !in_array($name, array('rsl_pos', 'rsl_lvl'), true)) {
                $html .= '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
            }
        }
        return $html;
    }

    // ------------------------------------------------------------------
    // Styles (once per page; the pieces above add them themselves)
    // ------------------------------------------------------------------

    public static function styles() {
        static $done = false;
        if ($done) {
            return '';
        }
        $done = true;
        return '<style>
            .rsl-fa .rsl-hint, .rsl-fa-fields .rsl-hint, .rsl-fa-routes .rsl-hint, .rsl-fa-captain .rsl-hint { font-weight: 400; }
            .rsl-fa h3 { margin: 0 0 6px; }
            .rsl-fa-routes { position: relative; border: 0; padding: 0; margin: 16px 0; min-width: 0; }
            .rsl-fa-routes > legend { font-weight: 700; font-size: 1.05em; margin-bottom: 6px; padding: 0; }
            .rsl-fa-radio { position: absolute; opacity: 0; width: 1px; height: 1px; margin: 0; }
            .rsl-fa-route { display: block; position: relative; margin: 6px 0; padding: 10px 12px 10px 40px; border: 1px solid var(--rsl-border, #dcdcde); border-radius: 8px; background: #fff; font-weight: 600; cursor: pointer; }
            .rsl-fa-route::before { content: ""; position: absolute; left: 13px; top: 13px; width: 16px; height: 16px; box-sizing: border-box; border: 2px solid #9aa3af; border-radius: 50%; background: #fff; }
            .rsl-fa-route small { display: block; margin-top: 2px; font-weight: 400; font-size: 13px; color: var(--rsl-muted, #6b7280); }
            .rsl-fa-radio:checked + .rsl-fa-route { border-color: var(--rsl-navy, #1d3d6e); background: #f5f8fc; }
            .rsl-fa-radio:checked + .rsl-fa-route::before { border-color: var(--rsl-navy, #1d3d6e); box-shadow: inset 0 0 0 3px #fff; background: var(--rsl-navy, #1d3d6e); }
            .rsl-fa-radio:focus-visible + .rsl-fa-route { outline: 2px solid var(--rsl-navy, #1d3d6e); outline-offset: 2px; }
            .rsl-fa-panel { display: none; margin: 10px 0 4px; padding: 4px 14px 8px; border-left: 3px solid var(--rsl-navy, #1d3d6e); background: #fafbfc; }
            .rsl-fa-r-join:checked ~ .rsl-fa-p-join, .rsl-fa-r-start:checked ~ .rsl-fa-p-start, .rsl-fa-r-find:checked ~ .rsl-fa-p-find, .rsl-fa-r-later:checked ~ .rsl-fa-p-later { display: block; }
            .rsl-fa-fields .rsl-fa-sub { margin: -6px 0 8px 24px; font-size: 13px; }
            .rsl-fa-fields legend { padding: 0; }
            .rsl-fa-costs { margin: 14px 0 8px; padding: 10px 14px; border: 1px solid #e6e1c8; border-radius: 6px; background: #fbfaf3; font-size: 14px; font-weight: 400; }
            .rsl-fa-costs ul { margin: 6px 0 0; padding-left: 18px; }
            .rsl-fa-costs li { margin: 3px 0; }
            .rsl-fa-steps { list-style: none; margin: 12px 0; padding: 0; counter-reset: rslfa; }
            .rsl-fa-steps li { position: relative; padding: 2px 0 12px 34px; counter-increment: rslfa; color: #374151; }
            .rsl-fa-steps li::before { content: counter(rslfa); position: absolute; left: 0; top: 0; width: 22px; height: 22px; box-sizing: border-box; display: flex; align-items: center; justify-content: center; border: 2px solid #c3c8d0; border-radius: 50%; background: #fff; color: var(--rsl-muted, #6b7280); font-size: 12px; font-weight: 700; }
            .rsl-fa-steps li:not(:last-child)::after { content: ""; position: absolute; left: 10px; top: 24px; bottom: 0; width: 2px; background: #e3e6ea; }
            .rsl-fa-steps li.is-done::before { content: "\2713"; border-color: #2e8540; background: #2e8540; color: #fff; }
            .rsl-fa-steps li.is-now { font-weight: 700; color: var(--rsl-navy, #1d3d6e); }
            .rsl-fa-steps li.is-now::before { border-color: var(--rsl-navy, #1d3d6e); background: var(--rsl-navy, #1d3d6e); color: #fff; }
            .rsl-fa-steps small { display: block; font-weight: 400; font-size: 13px; color: var(--rsl-muted, #6b7280); }
            .rsl-fa-links { font-size: 14px; }
            .rsl-fa-list { list-style: none; margin: 8px 0; padding: 0; }
            .rsl-fa-list > li { display: flex; flex-wrap: wrap; gap: 8px 12px; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--rsl-border, #dcdcde); }
            .rsl-fa-list > li:last-child { border-bottom: 0; }
            .rsl-fa-main { flex: 1 1 200px; min-width: 0; }
            .rsl-fa-main p { margin: 3px 0; }
            .rsl-fa-main .rsl-meta { margin-left: 6px; color: var(--rsl-muted, #6b7280); font-size: 13px; }
            .rsl-fa-act { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
            .rsl-fa-note { font-style: italic; color: #374151; }
            .rsl-fa-why { font-size: 13px; color: var(--rsl-muted, #6b7280); }
            .rsl-fa-tag { display: inline-block; margin: 2px 4px 2px 0; padding: 1px 8px; border-radius: 10px; background: #eef2f8; color: var(--rsl-navy, #1d3d6e); font-size: 12px; font-weight: 600; }
            .rsl-fa-status { font-size: 13px; font-weight: 600; }
            .rsl-fa-status.is-wait { color: #8a4600; }
            .rsl-fa-status.is-ok { color: #1e6b2a; }
            .rsl-fa-status.is-no { color: var(--rsl-muted, #6b7280); }
            .rsl-fa-code { font-family: monospace; font-size: 1.1em; letter-spacing: 2px; padding: 2px 8px; border-radius: 4px; background: #f1f3f6; }
            .rsl-fa-edit .rsl-fa-leave { margin-top: 12px; }
            .rsl-fa-open { margin-top: 10px; }
            .rsl-fa-open > summary { list-style: none; display: inline-block; }
            .rsl-fa-open > summary::-webkit-details-marker { display: none; }
            .rsl-fa-open[open] > summary { display: none; }
            .rsl-fa-banner { margin: 12px 0; padding: 8px 12px; border-radius: 6px; background: #f5f6f8; }
            .rsl-fa-banner.is-short { background: #fff6e8; color: #8a4600; font-weight: 600; }
            .rsl-fa-banner.is-full { background: #f2f8f2; color: #1e6b2a; font-weight: 600; }
            .rsl-fa-box { margin: 14px 0; padding: 10px 12px; border: 1px solid var(--rsl-border, #dcdcde); border-radius: 8px; }
            .rsl-fa-box > summary { cursor: pointer; }
            .rsl-fa-box > summary .rsl-meta { display: inline; margin-left: 6px; font-size: 13px; color: var(--rsl-muted, #6b7280); }
            .rsl-fa-filters { display: flex; flex-wrap: wrap; gap: 8px 12px; align-items: flex-end; margin: 8px 0; }
            .rsl-fa-filters label { display: flex; flex-direction: column; font-size: 13px; font-weight: 600; }
            .rsl-fa-filters select { margin-top: 3px; padding: 6px 8px; border: 1px solid #c3c8d0; border-radius: 4px; font-weight: 400; max-width: 100%; }
        </style>';
    }
}
