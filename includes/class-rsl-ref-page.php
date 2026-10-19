<?php
/**
 * The Ref jobs page ([rsl_ref_jobs], auto-created as "Ref Jobs"):
 *  - for approved referees and court supervisors: their night as a
 *    timeline (supervise, ref, play…), the night's open jobs to claim,
 *    their pay so far, and a tap-to-score scoreboard for the games they
 *    referee (saved through admin-ajax, kept in the browser as it goes so
 *    a refresh or patchy reception doesn't lose the score);
 *  - for everyone else: how to offer to referee.
 * Forms post back here (template_redirect); one nonce, server-side checks
 * in RSL_Officials.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RSL_Ref_Page {

    const PAGE_OPTION = 'rsl_refs_page_id';

    public function __construct() {
        add_shortcode('rsl_ref_jobs', array($this, 'render'));
        add_action('template_redirect', array($this, 'maybe_handle_post'));
        add_action('wp_ajax_rsl_save_score', array(__CLASS__, 'ajax_save_score'));
        add_action('wp_ajax_rsl_live_score', array(__CLASS__, 'ajax_live_score'));
    }

    /** The score so far, sent quietly from the scoreboard after each point (the game's ref or an admin). */
    public static function ajax_live_score() {
        if (!check_ajax_referer('rsl_score', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Session expired.'), 403);
        }
        $game = RSL_Fixtures::get(isset($_POST['game_id']) ? intval($_POST['game_id']) : 0);
        if (!RSL_Officials::can_score(get_current_user_id(), $game)) {
            wp_send_json_error(array('message' => 'Not this game\'s referee.'), 403);
        }
        $state = json_decode(isset($_POST['state']) ? wp_unslash($_POST['state']) : '', true);
        $stored = RSL_Fixtures::set_live($game->id, is_array($state) ? $state : array(), isset($_POST['seq']) ? intval($_POST['seq']) : 0);
        wp_send_json_success(array('stored' => $stored));
    }

    public static function ensure_page() {
        return RSL_Public::ensure_page(self::PAGE_OPTION, 'Ref Jobs', 'ref-jobs', '[rsl_ref_jobs]');
    }

    public static function url($night = null) {
        $page_id = intval(get_option(self::PAGE_OPTION));
        $url = $page_id ? get_permalink($page_id) : home_url('/');
        return $night ? add_query_arg('night', intval($night->id), $url) : $url;
    }

    // ------------------------------------------------------------------
    // Actions
    // ------------------------------------------------------------------

    public function maybe_handle_post() {
        if (!isset($_POST['rsl_ref'])) {
            return;
        }
        wp_safe_redirect(self::process($_POST));
        exit;
    }

    /** One action; returns where to go next. Testable. */
    public static function process($post) {
        $user_id = get_current_user_id();
        $night_id = isset($post['night_id']) ? intval($post['night_id']) : 0;
        $back = $night_id ? add_query_arg('night', $night_id, self::url()) : self::url();
        if (!$user_id || !isset($post['rsl_nonce']) || !wp_verify_nonce($post['rsl_nonce'], 'rsl_ref')) {
            RSL_Portal::notify('error', 'Your session expired — please try again.');
            return $back;
        }
        $do = isset($post['do']) ? sanitize_key($post['do']) : '';
        switch ($do) {
            case 'claim_ref':
                $r = RSL_Officials::claim_ref(isset($post['game_id']) ? intval($post['game_id']) : 0, $user_id);
                RSL_Portal::notify(is_wp_error($r) ? 'error' : 'success', is_wp_error($r) ? $r->get_error_message() : 'It\'s yours — it\'s on your night below.');
                return $back;
            case 'claim_supervisor':
                $r = RSL_Officials::claim_supervisor($night_id, isset($post['venue_id']) ? intval($post['venue_id']) : 0, $user_id);
                RSL_Portal::notify(is_wp_error($r) ? 'error' : 'success', is_wp_error($r) ? $r->get_error_message() : 'You\'re the court supervisor for the night — thanks!');
                return $back;
            case 'release':
                $r = RSL_Officials::release(isset($post['job_id']) ? intval($post['job_id']) : 0, $user_id);
                RSL_Portal::notify(is_wp_error($r) ? 'error' : 'success', is_wp_error($r) ? $r->get_error_message() : 'Job dropped — it\'s open for someone else.');
                return $back;
        }
        RSL_Portal::notify('error', 'Unknown action.');
        return $back;
    }

    /** Save a game's score from the scoreboard (the game's ref or an admin). */
    public static function ajax_save_score() {
        $user_id = get_current_user_id();
        if (!check_ajax_referer('rsl_score', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Your session expired — reload the page and try again.'), 403);
        }
        $game = RSL_Fixtures::get(isset($_POST['game_id']) ? intval($_POST['game_id']) : 0);
        if (!RSL_Officials::can_score($user_id, $game)) {
            wp_send_json_error(array('message' => 'Only this game\'s referee can enter its score (on the night, or the two days after).'), 403);
        }
        $r = RSL_Fixtures::record_result($game->id, isset($_POST['sets']) ? sanitize_text_field(wp_unslash($_POST['sets'])) : '', $user_id);
        if (is_wp_error($r)) {
            wp_send_json_error(array('message' => $r->get_error_message()));
        }
        wp_send_json_success(array('message' => 'Saved: ' . $r->home_name . ' ' . intval($r->home_sets) . '–' . intval($r->away_sets) . ' ' . $r->away_name . ' (' . RSL_Fixtures::format_sets($r->sets, ' · ') . ')'));
    }

    // ------------------------------------------------------------------
    // Page
    // ------------------------------------------------------------------

    public function render($atts = array()) {
        $user_id = get_current_user_id();
        ob_start();
        echo '<div class="rsl rsl-refs">' . RSL_Portal::flash_html();
        if (!$user_id) {
            echo '<p>Log in to see referee jobs.</p><p><a class="rsl-req-btn" href="' . esc_url(wp_login_url(self::url())) . '">Log in</a></p>';
        } elseif (!RSL_Officials::is_official($user_id)) {
            $this->render_not_official($user_id);
        } else {
            $this->render_official($user_id);
        }
        echo '</div>' . RSL_Public::styles() . RSL_Public_Fixtures::styles() . self::styles();
        return ob_get_clean();
    }

    private function render_not_official($user_id) {
        $s = RSL_Officials::settings();
        $status = RSL_Officials::get($user_id)->ref;
        echo '<h2>Referee for the Reds Social League</h2>';
        if ($status === 'applied') {
            echo '<p>Thanks for offering to referee — the league will approve you shortly, and jobs will appear here.</p>';
        } elseif ($status === 'declined') {
            echo '<p>Your offer to referee isn\'t approved at the moment. Talk to the league if you\'d like to know more.</p>';
        } else {
            echo '<p>Paid referees earn <strong>' . esc_html(RSL_Officials::money($s['ref_rate'])) . ' a game</strong>, picking the games that suit them — before, after or between your own.</p>'
                . '<p>Interested? Tick <em>"Happy to be a paid referee"</em> under <strong>My details</strong> on the <a href="' . esc_url(RSL_Portal::url()) . '">Social League page</a>.</p>';
        }
    }

    private function render_official($user_id) {
        $season = RSL_Seasons::current();
        $s = RSL_Officials::settings();
        $today = current_time('Y-m-d');
        $recent = date('Y-m-d', strtotime($today . ' -2 days'));
        $nights = array_values(array_filter(RSL_Courts::nights($season), function ($n) use ($recent) {
            return $n->type !== 'bye' && RSL_Fixtures::is_published($n) && $n->night_date >= $recent;
        }));
        $labels = RSL_Fixtures::round_labels($season);
        $e = RSL_Officials::earnings($user_id, $season);
        $roles = array();
        if (RSL_Officials::is_ref($user_id)) { $roles[] = 'Referee (' . RSL_Officials::money($s['ref_rate']) . ' a game)'; }
        if (RSL_Officials::is_supervisor($user_id)) { $roles[] = 'Court supervisor (' . RSL_Officials::money($s['supervisor_rate']) . ' a night)'; }
        ?>
        <div class="rsl-ref-summary">
            <div><small>You're a</small><strong><?php echo esc_html(implode(' · ', $roles)); ?></strong></div>
            <div><small>This season</small><strong><?php echo intval($e['done']); ?> job<?php echo $e['done'] === 1 ? '' : 's'; ?> · <?php echo esc_html(RSL_Officials::money($e['earned'])); ?></strong></div>
            <div><small>Booked ahead</small><strong><?php echo intval($e['booked']); ?> · <?php echo esc_html(RSL_Officials::money($e['booked_pay'])); ?></strong></div>
        </div>
        <?php if (!$nights): ?>
            <p class="rsl-empty">No jobs yet — they open when each week's fixtures come out (Mondays).</p>
            <?php return; ?>
        <?php endif; ?>
        <?php
        $night = $nights[0];
        foreach ($nights as $n) {
            if (isset($_GET['night']) && intval($_GET['night']) === intval($n->id)) {
                $night = $n;
            } elseif (!isset($_GET['night']) && $n->night_date >= $today) {
                $night = $n;
                break;
            }
        }
        ?>
        <?php if (count($nights) > 1): ?>
            <nav class="rsl-weeks">
                <?php foreach ($nights as $n): ?>
                    <a class="rsl-week<?php echo intval($n->id) === intval($night->id) ? ' is-current' : ''; ?>" href="<?php echo esc_url(self::url($n)); ?>"><strong><?php echo esc_html($labels[$n->id]); ?></strong><span><?php echo esc_html(date_i18n('D j M', strtotime($n->night_date))); ?></span></a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
        <h3 class="rsl-night-title"><?php echo esc_html($labels[$night->id]); ?> <span>&middot; <?php echo esc_html(date_i18n('l j F', strtotime($night->night_date))); ?></span></h3>
        <?php echo $this->my_night($user_id, $night, $today); ?>
        <?php echo $this->open_jobs($user_id, $night, $today); ?>
        <?php
    }

    /** The person's night in time order, with release buttons and the scoreboard for their games. */
    private function my_night($user_id, $night, $today) {
        $items = RSL_Officials::commitments($user_id, $night->id);
        $s = RSL_Officials::settings();
        $opened = false; // only the next game still to score starts open
        $start = RSL_Officials::night_start($night);
        $can_release = !$start || current_time('timestamp') <= $start - $s['release_hours'] * HOUR_IN_SECONDS;
        ob_start();
        ?>
        <section class="rsl-mynight">
            <h4>Your night</h4>
            <?php if (!$items): ?>
                <p class="rsl-empty">Nothing yet — take a job below.</p>
            <?php else: ?>
                <ol class="rsl-timeline">
                    <?php foreach ($items as $c): ?>
                        <li class="rsl-tl rsl-tl-<?php echo esc_attr($c['kind']); ?>">
                            <span class="rsl-tl-time"><?php echo $c['start'] ? esc_html(RSL_Courts::pretty_time($c['start'])) : 'All night'; ?></span>
                            <span class="rsl-tl-what">
                                <?php if ($c['kind'] === 'supervisor'): ?>
                                    <strong>Court supervisor</strong> · <?php echo esc_html($c['venue']); ?><br><small>Questions, first aid, keeping the night running · <?php echo esc_html(RSL_Officials::money($c['job']->rate)); ?></small>
                                <?php else: ?>
                                    <strong><?php echo $c['kind'] === 'ref' ? 'Ref' : 'Play'; ?></strong> · <?php echo esc_html($c['court'] . ', ' . $c['venue']); ?><br>
                                    <small><?php echo esc_html($c['game']->home_name . ' v ' . $c['game']->away_name); ?><?php echo $c['kind'] === 'ref' ? ' · ' . esc_html(RSL_Officials::money($c['job']->rate)) : ''; ?></small>
                                <?php endif; ?>
                            </span>
                            <span class="rsl-tl-act">
                                <?php if ($c['job'] && $can_release && $night->night_date >= $today): ?>
                                    <form method="post"><?php echo self::fields('release', $night->id, array('job_id' => $c['job']->id)); ?><button type="submit" class="rsl-req-btn">Drop</button></form>
                                <?php endif; ?>
                            </span>
                            <?php if ($c['kind'] === 'ref' && RSL_Officials::can_score($user_id, $c['game'])): ?>
                                <?php $open = !$opened && $c['game']->status === 'scheduled'; $opened = $opened || $open; ?>
                                <?php echo self::scoreboard($c['game'], $open); ?>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <?php if (!$can_release && $night->night_date >= $today): ?><p class="rsl-hint">It's too close to the night to drop jobs here — contact the league if something comes up.</p><?php endif; ?>
            <?php endif; ?>
        </section>
        <?php
        return ob_get_clean();
    }

    /** Every game (and supervisor role) of the night: take it, yours, or taken. */
    private function open_jobs($user_id, $night, $today) {
        if ($night->night_date < $today) {
            return '';
        }
        $s = RSL_Officials::settings();
        $jobs = RSL_Officials::jobs_for_night($night->id);
        $is_ref = RSL_Officials::is_ref($user_id);
        $is_sup = RSL_Officials::is_supervisor($user_id);
        $by_venue = array();
        foreach (RSL_Fixtures::games_for_night($night->id) as $g) {
            $by_venue[intval($g->venue_id)]['name'] = $g->venue_name;
            $by_venue[intval($g->venue_id)]['division'] = $g->division;
            $by_venue[intval($g->venue_id)]['games'][] = $g;
        }
        $open = 0;
        foreach ($by_venue as $vid => $v) {
            $open += count(array_filter($v['games'], function ($g) use ($jobs) { return !isset($jobs['ref'][intval($g->id)]); }));
        }
        ob_start();
        ?>
        <section class="rsl-jobs">
            <h4>Jobs this night <span class="rsl-empty">· <?php echo intval($open); ?> game<?php echo $open === 1 ? '' : 's'; ?> still need a ref</span></h4>
            <?php foreach ($by_venue as $vid => $v): ?>
                <h5 class="rsl-division"><?php echo esc_html(RSL_Teams::DIVISIONS[$v['division']]); ?> <span>&middot; <?php echo esc_html($v['name']); ?></span></h5>
                <?php if ($is_sup || isset($jobs['supervisor'][$vid])): ?>
                    <?php $sj = isset($jobs['supervisor'][$vid]) ? $jobs['supervisor'][$vid] : null; ?>
                    <div class="rsl-job rsl-job-sup">
                        <span class="rsl-job-time">All night</span>
                        <span class="rsl-job-what"><strong>Court supervisor</strong><small>Questions, first aid, keeping the night running · <?php echo esc_html(RSL_Officials::money($s['supervisor_rate'])); ?></small></span>
                        <span class="rsl-job-act"><?php echo self::job_action($sj, $user_id, $is_sup, function () use ($night, $vid) {
                            return self::fields('claim_supervisor', $night->id, array('venue_id' => $vid));
                        }, null); ?></span>
                    </div>
                <?php endif; ?>
                <?php foreach ($v['games'] as $g): ?>
                    <?php $j = isset($jobs['ref'][intval($g->id)]) ? $jobs['ref'][intval($g->id)] : null; ?>
                    <?php $why = !$j && $is_ref ? RSL_Officials::check_ref($g->id, $user_id) : null; ?>
                    <div class="rsl-job">
                        <span class="rsl-job-time"><?php echo esc_html(RSL_Courts::pretty_time(RSL_Courts::hm($g->start_time))); ?></span>
                        <span class="rsl-job-what"><strong><?php echo esc_html($g->home_name . ' v ' . $g->away_name); ?></strong><small><?php echo esc_html($g->court_name); ?> · <?php echo esc_html(RSL_Officials::money($s['ref_rate'])); ?></small></span>
                        <span class="rsl-job-act"><?php echo self::job_action($j, $user_id, $is_ref, function () use ($night, $g) {
                            return self::fields('claim_ref', $night->id, array('game_id' => $g->id));
                        }, is_wp_error($why) ? $why->get_error_message() : null); ?></span>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </section>
        <?php
        return ob_get_clean();
    }

    /** "Take it" / "Yours" / "Taken" / why not. */
    private static function job_action($job, $user_id, $eligible, $fields, $why) {
        if ($job) {
            return intval($job->user_id) === intval($user_id) ? '<span class="rsl-req-chip is-good">Yours</span>' : '<span class="rsl-req-chip">Taken</span>';
        }
        if (!$eligible) {
            return '<span class="rsl-req-chip">Open</span>';
        }
        if ($why) {
            return '<span class="rsl-job-why">' . esc_html($why) . '</span>';
        }
        return '<form method="post">' . $fields() . '<button type="submit" class="rsl-req-btn rsl-take">Take it</button></form>';
    }

    private static function fields($do, $night_id, $extra = array()) {
        $html = wp_nonce_field('rsl_ref', 'rsl_nonce', true, false) . '<input type="hidden" name="rsl_ref" value="1"><input type="hidden" name="do" value="' . esc_attr($do) . '"><input type="hidden" name="night_id" value="' . intval($night_id) . '">';
        foreach ($extra as $k => $v) {
            $html .= '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
        }
        return $html;
    }

    // ------------------------------------------------------------------
    // Scoreboard
    // ------------------------------------------------------------------

    /**
     * Tap-to-score for one game: tap a team to add a point, undo, swap
     * sides, sets end at 25 (third set 15 or 25, chosen by the ref) by two
     * clear, "Time's up" ends the set as it stands.
     * - Every tap is recorded on the phone first (never waits for signal).
     * - The score so far is sent quietly after each point (live on the
     *   public Fixtures page). Each update carries the whole score and a
     *   time-based sequence number, so a lost update doesn't matter and a
     *   late one never overwrites a newer one.
     * - "Save result" records it (or type it in, as a fallback). Only one
     *   scoreboard is open at a time; it closes after saving.
     */
    private static function scoreboard($game, $open = false) {
        static $script = false;
        $done = $game->status === 'played';
        $data = array(
            'game' => intval($game->id),
            'nonce' => wp_create_nonce('rsl_score'),
            'ajax' => admin_url('admin-ajax.php'),
        );
        $html = '<details class="rsl-sb-wrap"' . ($open ? ' open' : '') . '><summary>' . ($done ? 'Result saved: ' . intval($game->home_sets) . '–' . intval($game->away_sets) . ' (' . esc_html(RSL_Fixtures::format_sets($game->sets, ' · ')) . ') — change' : 'Open the scoreboard') . '</summary>'
            . '<div class="rsl-sb" data-sb="' . esc_attr(wp_json_encode($data)) . '">'
            . '<div class="rsl-sb-head"><span class="rsl-sb-set">Set 1 · to 25</span><span class="rsl-sb-live" data-state="idle">Not sent yet</span></div>'
            . '<div class="rsl-sb-tools"><button type="button" class="rsl-req-btn" data-act="swap" title="Swap which team is on which side">&#8644; Swap sides</button>'
            . '<label class="rsl-sb-third">Third set to <select><option value="15">15</option><option value="25">25</option></select></label></div>'
            . '<div class="rsl-sb-teams">'
            . '<button type="button" class="rsl-sb-team" data-side="0" style="--team:' . esc_attr($game->home_colour) . '"><span class="rsl-sb-name">' . esc_html($game->home_name) . '</span><span class="rsl-sb-score">0</span><span class="rsl-sb-plus">tap +1</span></button>'
            . '<button type="button" class="rsl-sb-team" data-side="1" style="--team:' . esc_attr($game->away_colour) . '"><span class="rsl-sb-name">' . esc_html($game->away_name) . '</span><span class="rsl-sb-score">0</span><span class="rsl-sb-plus">tap +1</span></button>'
            . '</div>'
            . '<div class="rsl-sb-sets"></div>'
            . '<div class="rsl-sb-actions"><button type="button" class="rsl-req-btn" data-act="undo">Undo</button>'
            . '<button type="button" class="rsl-req-btn" data-act="time">Time\'s up — end set here</button>'
            . '<button type="button" class="rsl-req-btn rsl-take" data-act="save">Save result</button></div>'
            . '<details class="rsl-sb-manual"><summary>Type the score instead</summary><input type="text" placeholder="e.g. 25-20, 18-25, 15-12 (' . esc_attr($game->home_name) . ' first)"> <button type="button" class="rsl-req-btn" data-act="manual">Save</button></details>'
            . '<p class="rsl-sb-msg" role="status"></p>'
            . '</div></details>';
        if (!$script) {
            $script = true;
            $html .= self::scoreboard_script();
        }
        return $html;
    }

    private static function scoreboard_script() {
        return <<<'JS'
<script>
(function () {
    // Only one scoreboard open at a time ('toggle' doesn't bubble, so listen while capturing).
    document.addEventListener('toggle', function (e) {
        var w = e.target;
        if (w.classList && w.classList.contains('rsl-sb-wrap') && w.open) {
            document.querySelectorAll('.rsl-sb-wrap').forEach(function (o) { if (o !== w) { o.open = false; } });
        }
    }, true);
    function init(el) {
        var d = JSON.parse(el.getAttribute('data-sb')), key = 'rsl_sb_' + d.game;
        var st = { sets: [], s: [0, 0], third: 15, done: false, flip: false }, hist = [];
        try { var saved = JSON.parse(localStorage.getItem(key) || 'null'); if (saved) { st = saved; } } catch (e) {}
        var q = function (s) { return el.querySelector(s); }, qa = function (s) { return el.querySelectorAll(s); };
        var msg = q('.rsl-sb-msg'), third = q('.rsl-sb-third select'), live = q('.rsl-sb-live');
        var wrap = el.closest('.rsl-sb-wrap'), timer = null, sending = false, again = false;
        third.value = String(st.third);
        function target() { return st.sets.length === 2 ? st.third : 25; }
        function won() { var w = [0, 0]; st.sets.forEach(function (x) { w[x[0] > x[1] ? 0 : 1]++; }); return w; }
        function keep() { try { localStorage.setItem(key, JSON.stringify(st)); } catch (e) {} }
        function liveState(state, text) { live.setAttribute('data-state', state); live.textContent = text; }
        // Send the whole score so far; a newer tap while sending just sends again after.
        function push() {
            if (sending) { again = true; return; }
            sending = true; liveState('sending', 'Sending…');
            var seq = Math.floor((Date.now() - 1704067200000) / 100);
            var body = new URLSearchParams({ action: 'rsl_live_score', nonce: d.nonce, game_id: d.game, seq: seq, state: JSON.stringify({ sets: st.sets, current: st.s }) });
            fetch(d.ajax, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) { return r.json(); }).then(function (r) {
                liveState(r.success ? 'live' : 'error', r.success ? 'Live' : 'Not sent');
            }).catch(function () {
                liveState('offline', 'No signal — kept on this phone');
                clearTimeout(timer); timer = setTimeout(push, 8000); // try again shortly
            }).then(function () { sending = false; if (again) { again = false; push(); } });
        }
        function schedule() { clearTimeout(timer); timer = setTimeout(push, 700); }
        function draw() {
            var sc = qa('.rsl-sb-score');
            sc[0].textContent = st.s[0];
            sc[1].textContent = st.s[1];
            // Swap sides by moving the buttons (home stays home in the score — only where it sits changes).
            var teams = q('.rsl-sb-teams'), homeBtn = teams.querySelector('[data-side="0"]'), awayBtn = teams.querySelector('[data-side="1"]');
            if (!!st.flip !== (teams.firstElementChild === awayBtn)) { teams.insertBefore(st.flip ? awayBtn : homeBtn, teams.firstElementChild); }
            q('.rsl-sb-set').textContent = st.done ? 'Game over — save the result' : 'Set ' + (st.sets.length + 1) + ' · to ' + target();
            q('.rsl-sb-third').style.display = st.sets.length >= 1 && !st.done ? '' : 'none';
            q('.rsl-sb-sets').innerHTML = st.sets.map(function (x, i) { return '<span>Set ' + (i + 1) + ': ' + x[0] + '–' + x[1] + '</span>'; }).join('');
            qa('.rsl-sb-team').forEach(function (b) { b.disabled = st.done; });
            keep();
        }
        function snapshot() { hist.push(JSON.stringify(st)); if (hist.length > 300) { hist.shift(); } }
        function endSet() {
            st.sets.push([st.s[0], st.s[1]]); st.s = [0, 0];
            var w = won(); if (w[0] === 2 || w[1] === 2) { st.done = true; }
        }
        qa('.rsl-sb-team').forEach(function (b) {
            b.addEventListener('click', function () {
                if (st.done) { return; }
                snapshot();
                st.s[+b.getAttribute('data-side')]++;
                var t = target();
                if ((st.s[0] >= t || st.s[1] >= t) && Math.abs(st.s[0] - st.s[1]) >= 2) { endSet(); }
                msg.textContent = ''; draw(); schedule();
            });
        });
        third.addEventListener('change', function () { st.third = +third.value; draw(); });
        function save(sets) {
            msg.textContent = 'Saving…';
            var body = new URLSearchParams({ action: 'rsl_save_score', nonce: d.nonce, game_id: d.game, sets: sets });
            fetch(d.ajax, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) { return r.json(); }).then(function (r) {
                msg.textContent = r.data && r.data.message ? r.data.message : (r.success ? 'Saved.' : 'Couldn\'t save — try again.');
                if (r.success) {
                    try { localStorage.removeItem(key); } catch (e) {}
                    clearTimeout(timer); liveState('saved', 'Result saved');
                    if (wrap) {
                        wrap.querySelector('summary').textContent = r.data.message + ' — change';
                        setTimeout(function () { wrap.open = false; }, 1500);
                    }
                }
            }).catch(function () { msg.textContent = 'No signal — the score is kept on this phone. Press Save again when you have signal.'; });
        }
        el.addEventListener('click', function (e) {
            var act = e.target.getAttribute && e.target.getAttribute('data-act');
            if (!act) { return; }
            if (act === 'swap') { st.flip = !st.flip; draw(); }
            if (act === 'undo' && hist.length) { var flip = st.flip; st = JSON.parse(hist.pop()); st.flip = flip; third.value = String(st.third); draw(); schedule(); }
            if (act === 'time') {
                if (st.s[0] === 0 && st.s[1] === 0 && st.sets.length) { snapshot(); st.done = true; draw(); schedule(); return; }
                if (st.s[0] === st.s[1]) { msg.textContent = st.s[0] ? 'The set is tied — play one more point.' : 'No points in this set yet.'; return; }
                snapshot(); endSet(); st.done = true; draw(); schedule();
            }
            if (act === 'save') {
                var sets = st.sets.slice();
                if (!st.done && st.s[0] !== st.s[1]) { sets.push([st.s[0], st.s[1]]); }
                if (!sets.length) { msg.textContent = 'No sets to save yet.'; return; }
                save(sets.map(function (x) { return x[0] + '-' + x[1]; }).join(','));
            }
            if (act === 'manual') { save(q('.rsl-sb-manual input').value); }
        });
        draw();
        if (!st.done && (st.sets.length || st.s[0] || st.s[1])) { schedule(); } // a game in progress kept on this phone
    }
    // Start once the whole page is there (this script sits beside the first scoreboard).
    function boot() { document.querySelectorAll('.rsl-sb').forEach(init); }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
</script>
JS;
    }

    private static function styles() {
        return '<style>
            .rsl-ref-summary { display: flex; flex-wrap: wrap; gap: 8px; margin: 10px 0 16px; }
            .rsl-ref-summary > div { flex: 1 1 160px; padding: 10px 12px; border: 1px solid var(--rsl-border); border-radius: 10px; background: linear-gradient(180deg, #f7f9fc, #fff); display: flex; flex-direction: column; gap: 2px; }
            .rsl-ref-summary small { font-size: 11px; text-transform: uppercase; letter-spacing: .4px; color: var(--rsl-muted); font-weight: 700; }
            .rsl-mynight, .rsl-jobs { margin: 14px 0 20px; }
            .rsl-mynight h4, .rsl-jobs h4 { margin: 0 0 8px; }
            .rsl-timeline { list-style: none; margin: 0; padding: 0; border-left: 3px solid var(--rsl-border); }
            .rsl-tl { display: grid; grid-template-columns: 70px 1fr auto; gap: 8px; align-items: start; padding: 8px 10px 8px 14px; position: relative; }
            .rsl-tl::before { content: ""; position: absolute; left: -8px; top: 13px; width: 13px; height: 13px; border-radius: 50%; background: #fff; border: 3px solid var(--rsl-navy); }
            .rsl-tl-play::before { border-color: #2e7d32; }
            .rsl-tl-supervisor::before { border-color: #b98a00; }
            .rsl-tl-time { font-weight: 800; font-size: 13px; }
            .rsl-tl-what small { color: var(--rsl-muted); }
            .rsl-tl .rsl-sb-wrap { grid-column: 1 / -1; }
            .rsl-job { display: grid; grid-template-columns: 70px 1fr auto; gap: 8px; align-items: center; padding: 8px 10px; margin-bottom: 6px; border: 1px solid var(--rsl-border); border-radius: 8px; }
            .rsl-job-sup { border-color: #e7cf7a; background: #fffbea; }
            .rsl-job-time { font-weight: 800; font-size: 13px; }
            .rsl-job-what { display: flex; flex-direction: column; }
            .rsl-job-what small { color: var(--rsl-muted); font-size: 12px; }
            .rsl-job-why { font-size: 11px; color: var(--rsl-muted); max-width: 220px; display: inline-block; text-align: right; }
            .rsl .rsl-take { background: var(--rsl-navy); color: #fff; }
            .rsl-sb-wrap { margin-top: 8px; }
            .rsl-sb-wrap summary { cursor: pointer; font-weight: 700; color: var(--rsl-navy); font-size: 13px; }
            .rsl-sb { margin-top: 8px; padding: 12px; border: 1px solid var(--rsl-border); border-radius: 12px; background: #f7f9fc; }
            .rsl-sb-head { display: flex; justify-content: space-between; align-items: center; gap: 8px; font-weight: 800; margin-bottom: 8px; }
            .rsl-sb-third { font-size: 12px; font-weight: 400; }
            .rsl-sb-tools { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-bottom: 8px; }
            .rsl-sb-live { display: inline-flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 700; color: var(--rsl-muted); }
            .rsl-sb-live::before { content: ""; width: 8px; height: 8px; border-radius: 50%; background: #c3c4c7; }
            .rsl-sb-live[data-state="live"] { color: #b32d2e; }
            .rsl-sb-live[data-state="live"]::before { background: #e0262a; box-shadow: 0 0 0 3px rgba(224, 38, 42, .2); }
            .rsl-sb-live[data-state="sending"]::before { background: #dba617; }
            .rsl-sb-live[data-state="offline"], .rsl-sb-live[data-state="error"] { color: #8a4600; }
            .rsl-sb-live[data-state="offline"]::before, .rsl-sb-live[data-state="error"]::before { background: #dba617; }
            .rsl-sb-live[data-state="saved"] { color: #2e7d32; }
            .rsl-sb-live[data-state="saved"]::before { background: #2e7d32; }            .rsl-sb-teams { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
            .rsl .rsl-sb-team { display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 16px 8px; border: 0; border-radius: 12px; background: var(--team); color: #fff; cursor: pointer; touch-action: manipulation; user-select: none; }
            .rsl .rsl-sb-team:active { transform: scale(.98); }
            .rsl .rsl-sb-team:disabled { opacity: .6; cursor: default; }
            .rsl-sb-name { font-weight: 700; font-size: 14px; text-align: center; }
            .rsl-sb-score { font-size: 56px; font-weight: 800; line-height: 1; font-variant-numeric: tabular-nums; }
            .rsl-sb-plus { font-size: 11px; opacity: .85; }
            .rsl-sb-sets { display: flex; flex-wrap: wrap; gap: 6px; margin: 10px 0; min-height: 4px; }
            .rsl-sb-sets span { padding: 2px 8px; border-radius: 10px; background: #fff; border: 1px solid var(--rsl-border); font-size: 12px; font-weight: 700; }
            .rsl-sb-actions { display: flex; flex-wrap: wrap; gap: 6px; }
            .rsl-sb-manual { margin-top: 8px; font-size: 12px; }
            .rsl-sb-manual input { width: 100%; max-width: 320px; padding: 5px 8px; margin-top: 4px; }
            .rsl-sb-msg { font-size: 13px; font-weight: 700; color: var(--rsl-navy); min-height: 1em; margin: 8px 0 0; }
            @media (max-width: 600px) { .rsl-tl, .rsl-job { grid-template-columns: 56px 1fr; } .rsl-tl-act, .rsl-job-act { grid-column: 2; } }
        </style>';
    }
}
