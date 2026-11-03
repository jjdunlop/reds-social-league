<?php
/**
 * The season preview card (0.18.0) on the League Teams, League Ladder and Fixtures & Results
 * pages: when it shows and when it goes, its words (heading, countdown, teams in so far, the
 * tiers, Unranked, the free agent line in each phase), its buttons and the How the league works
 * page setting (Social League → Teams), escaping, the same card for every viewer, none on a
 * team's own page, and the countdown script printed once a page (counting in the site's time
 * zone). A throwaway far-future season dated from today (and a far-past one with no dates),
 * throwaway teams and pages, a throwaway menu item post in no menu, two @example.test accounts;
 * cleans up, after a failure too. Check 1 needs the process's first cards: run it with wp
 * eval-file, alone.
 *
 *   wp eval-file dev/tests/test-season-preview.php
 */

if (!defined('WP_CLI') || !WP_CLI || (wp_get_environment_type() === 'production' && !getenv('RSL_DEV'))) {
    exit("Local copy only: run with wp eval-file, with RSL_DEV=1 set (docker exec -e RSL_DEV=1 ...).\n");
}
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once dirname(__DIR__) . '/lib-mail-stop.php';
global $wpdb;

$season = '2096-pre';
$old = '1996-pre';    // no dates: its quarter, long finished
$later = '2096-mid1'; // no dates; only ever pinned, never given rows
$suffix = time();
$hold = rsl_dev_hold_mail($suffix);
$fails = 0;
$check = function ($label, $ok, $detail = '') use (&$fails, $hold) {
    rsl_dev_park_mail($hold);
    if (!$ok) { $fails++; }
    echo ($ok ? "OK: $label\n" : "FAIL: $label" . ($detail !== '' ? " — $detail" : '') . "\n");
};
// Set before the try, so the cleanup can always run.
$saved = array();
foreach (array(RSL_Seasons::CURRENT_OPTION, RSL_Seasons::SIGNUP_OPTION, RSL_Public::ABOUT_OPTION, RSL_Portal::PAGE_OPTION) as $opt) { $saved[$opt] = get_option($opt, '__unset__'); }
$made_portal = 0;
$posts = array();
$evil = null;
$users = array();
$mk = function ($tag, $role = 'subscriber') use ($suffix, &$users) {
    $u = wp_insert_user(array('user_login' => "rslsp-$tag-$suffix", 'user_email' => "rslsp-$tag-$suffix@example.test", 'user_pass' => wp_generate_password(),
        'display_name' => "RSLSP $tag $suffix", 'role' => $role));
    if (is_wp_error($u)) { // stop here (the cleanup only ever sees real account ids)
        throw new Exception("Couldn't make the test account $tag: " . $u->get_error_message());
    }
    $users[] = $u;
    return $u;
};
$today = current_time('Y-m-d');
$day = function ($n) use ($today) { return date('Y-m-d', strtotime($today . ' ' . ($n < 0 ? '' : '+') . intval($n) . ' days')); };
// The season's first night $in days from today, on that day's weekday, nine weekly nights.
$dates = function ($in) use ($season, $day) {
    $first = $day($in);
    RSL_Courts::save_settings($season, array('start' => $first, 'end' => $day($in + 56), 'weekday' => intval(date('w', strtotime($first)))));
    RSL_Courts::generate_nights($season);
    return $first;
};
$no_dates = function () use ($season, $wpdb) {
    $all = get_option(RSL_Courts::SETTINGS_OPTION, array());
    if (is_array($all) && isset($all[$season])) {
        unset($all[$season]);
        update_option(RSL_Courts::SETTINGS_OPTION, $all, false);
    }
    $wpdb->delete(RSL_Database::table('nights'), array('season' => $season));
};
$fa = function ($opens, $deadline, $confirm_by) use ($season, $day) {
    RSL_Free_Agents::save_dates($season, $day($opens), $day($deadline), $day($confirm_by));
};
$confirm = function ($on) use ($season) {
    $b = RSL_Free_Agents::building($season);
    $b['confirmed_at'] = $on ? current_time('mysql') : '';
    RSL_Free_Agents::save_building($season, $b);
};
$pages = function ($as = 0, $get = array()) {
    wp_set_current_user($as);
    $_GET = $get;
    set_query_var(RSL_Public::QUERY_VAR, '');
    return array('teams' => do_shortcode('[rsl_teams]'), 'ladder' => do_shortcode('[rsl_ladder]'), 'fixtures' => do_shortcode('[rsl_fixtures]'));
};
$card = function ($html) {
    $html = (string) $html;
    $a = strpos($html, '<section class="rsl-preview"');
    if ($a === false) { return ''; }
    $b = strpos($html, '</section>', $a);
    return (string) substr($html, $a, $b === false ? strlen($html) - $a : $b - $a + strlen('</section>'));
};
$cards = function ($p) { return substr_count(implode('', (array) $p), '<section class="rsl-preview"'); };
$before = function ($html, $a, $b) { $x = strpos((string) $html, $a); $y = strpos((string) $html, $b); return $x !== false && $y !== false && $x < $y; };
$fa_line = function ($c) {
    $open = '<p class="rsl-preview-fa">';
    $a = strpos($c, $open);
    if ($a === false) { return ''; }
    $b = strpos($c, '</p>', $a);
    return (string) substr($c, $a + strlen($open), $b - $a - strlen($open));
};
$marker = "querySelectorAll('.rsl-preview[data-rsl-first-night]')";
$long = function ($ymd) { return date_i18n('l j F', strtotime($ymd)); };
$short = function ($ymd) { return date_i18n('j F', strtotime($ymd)); };
$notice = function ($u) {
    $n = get_transient(RSL_Admin::NOTICE_PREFIX . $u);
    delete_transient(RSL_Admin::NOTICE_PREFIX . $u);
    return is_array($n) ? $n : array('type' => '', 'message' => '');
};
$admin_do = function ($u, $fields) use ($season) {
    wp_set_current_user($u);
    return (string) RSL_Admin::process(array_merge(array('rsl_nonce' => wp_create_nonce('rsl_team_action'), 'season' => $season), $fields));
};
// The Social League → Teams screen as $u sees it. Never printed: it lists the site's real
// applications (a screen that throws half-made is thrown away, never flushed).
$admin_screen = function ($u) {
    wp_set_current_user($u);
    $_GET = array('page' => RSL_Admin::PAGE);
    ob_start();
    try {
        (new RSL_Admin())->render();
        return (string) ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    } finally {
        $_GET = array();
    }
};

try {

// --- Setup ---------------------------------------------------------------------------------------
RSL_Seasons::set_current($season);
RSL_Seasons::set_signup($season);
delete_option(RSL_Public::ABOUT_OPTION);
$had = intval(get_option(RSL_Portal::PAGE_OPTION));
$portal_id = intval(RSL_Portal::ensure_page()); // the existing page when there is one
$made_portal = $portal_id !== $had ? $portal_id : 0;
$admin = $mk('admin', 'administrator');
$member = $mk('member');
$first = $dates(40);
$fa(5, 20, 25); // the free agent window opens in five days
$nights = RSL_Courts::nights($season);
$check('setup: nine weekly nights, the first 40 days from today', count($nights) === 9 && $nights[0]->night_date === $first, count($nights) . ' / ' . ($nights ? $nights[0]->night_date : ''));

// 1: the first cards this process prints.
$p = $pages();
$all = implode('', $p);
$check('1 one card on each page, and the countdown script printed once across the three (with the first card)',
    substr_count($p['teams'], '<section class="rsl-preview"') === 1 && substr_count($p['ladder'], '<section class="rsl-preview"') === 1
    && substr_count($p['fixtures'], '<section class="rsl-preview"') === 1 && substr_count($all, $marker) === 1 && strpos($p['teams'], $marker) !== false,
    (string) substr_count($all, $marker));
$s_at = strpos($p['teams'], $marker);
$s_from = $s_at !== false ? strrpos(substr($p['teams'], 0, $s_at), '<script>') : false;
$s_to = $s_at !== false ? strpos($p['teams'], '</script>', $s_at) : false;
$script = ($s_from !== false && $s_to !== false) ? substr($p['teams'], $s_from, $s_to - $s_from) : '';
$check('...the script sets text only (textContent, never innerHTML), from data-rsl-first-night, counting days in data-rsl-tz', $script !== '' && strpos($script, 'textContent') !== false
    && strpos($script, 'innerHTML') === false && strpos($script, "getAttribute('data-rsl-first-night')") !== false && strpos($script, "getAttribute('data-rsl-tz')") !== false);

// 2: where it sits.
$check('2 Teams: in place of No teams yet; Ladder: above How the ladder works; Fixtures: above the round strip',
    strpos($p['teams'], 'No teams yet this season.') === false && $before($p['teams'], '<h2 class="rsl-title">', '<section class="rsl-preview"')
    && $before($p['ladder'], '<section class="rsl-preview"', '<details class="rsl-how">')
    && $before($p['fixtures'], '<h2 class="rsl-title">', '<section class="rsl-preview"') && $before($p['fixtures'], '<section class="rsl-preview"', '<nav class="rsl-weeks"'));

// 3-4: heading, countdown, the date on the card; one card for all three pages.
$c = $card($p['teams']);
$check('3 the heading names the season and its first night; the countdown from the server; the date and the site\'s time zone ride on the card',
    strpos($c, '<h3 class="rsl-preview-title">2096 Pre-season (Summer) starts ' . esc_html($long($first)) . '</h3>') !== false
    && strpos($c, '<p class="rsl-preview-countdown">40 days to go</p>') !== false
    && strpos($c, '<section class="rsl-preview" data-rsl-first-night="' . $first . '" data-rsl-tz="' . esc_attr(wp_timezone_string()) . '">') !== false, $c);
$check('4 the same card on all three pages', $c !== '' && $c === $card($p['ladder']) && $c === $card($p['fixtures']));

// 5-7: teams in so far.
$pending = RSL_Teams::create($season, array('name' => "Waiting Side $suffix", 'division' => 'ranked', 'status' => 'pending'));
$p = $pages();
$check('5 no teams yet: Be the first team in; a pending application never counts and is never named',
    $pending && !is_wp_error($pending) && strpos($card($p['teams']), '<p class="rsl-preview-teams">Be the first team in.</p>') !== false
    && strpos(implode('', $p), "Waiting Side $suffix") === false, $card($p['teams']));
$aces = RSL_Teams::create($season, array('name' => "Aces $suffix", 'division' => 'ranked'));
$p = $pages();
$c = $card($p['teams']);
$check('6 one Ranked team: 1 Ranked team is in so far; the card above the list, never naming it',
    $aces && !is_wp_error($aces) && strpos($c, '<p class="rsl-preview-teams">1 Ranked team is in so far.</p>') !== false && strpos($c, "Aces $suffix") === false
    && $before($p['teams'], '<section class="rsl-preview"', "Aces $suffix") && strpos($p['teams'], 'No teams yet this season.') === false, $c);
foreach (array(array('Blockers', 'ranked'), array('Cobras', 'ranked'), array('Uni One', 'unranked'), array('Uni Two', 'unranked')) as $t) {
    RSL_Teams::create($season, array('name' => $t[0] . ' ' . $suffix, 'division' => $t[1]));
}
// Applications that went nowhere, one in each division: withdrawn and declined (set directly).
$gone_n = 0;
foreach (array(array('Gone Side', 'ranked', 'withdrawn'), array('Turned Down', 'unranked', 'declined')) as $t) {
    $g = RSL_Teams::create($season, array('name' => $t[0] . ' ' . $suffix, 'division' => $t[1], 'status' => 'pending'));
    $gone_n += ($g && !is_wp_error($g) && $wpdb->update(RSL_Database::table('teams'), array('status' => $t[2]), array('id' => $g->id))) ? 1 : 0;
}
$p = $pages();
$c = $card($p['teams']);
$named = false;
foreach (array('Aces', 'Blockers', 'Cobras', 'Uni One', 'Uni Two', 'Waiting Side', 'Gone Side', 'Turned Down') as $n) {
    $named = $named || strpos($c . $card($p['ladder']) . $card($p['fixtures']), "$n $suffix") !== false;
}
$check('7 several in each division: 3 Ranked teams and 2 Unranked teams are in so far (pending, withdrawn and declined never count), no team named',
    $gone_n === 2 && strpos($c, '<p class="rsl-preview-teams">3 Ranked teams and 2 Unranked teams are in so far.</p>') !== false && !$named
    && strpos(implode('', $p), "Gone Side $suffix") === false && strpos(implode('', $p), "Turned Down $suffix") === false, $c);
$check('...the words for any count (pure)', RSL_Public::teams_in_words(array()) === 'Be the first team in.'
    && RSL_Public::teams_in_words(array('unranked' => 1)) === '1 Unranked team is in so far.'
    && RSL_Public::teams_in_words(array('unranked' => 4)) === '4 Unranked teams are in so far.'
    && RSL_Public::teams_in_words(array('ranked' => 1, 'unranked' => 1)) === '1 Ranked team and 1 Unranked team are in so far.'
    && RSL_Public::teams_in_words(array('ranked' => 2, 'bogus' => 5)) === '2 Ranked teams are in so far.');

// 8: the tier strip, placements and Unranked.
$tiers = array_reverse(RSL_Stats::TIERS);
$at = -1;
$strip = true;
foreach ($tiers as $t) {
    $here = strpos($c, '<li><span class="rsl-preview-swatch" style="background:' . $t[3] . '" aria-hidden="true"></span>' . $t[1] . '</li>');
    $strip = $strip && $here !== false && $here > $at;
    $at = $here === false ? PHP_INT_MAX : $here;
}
$games = RSL_Ratings::PLACEMENT_GAMES;
$check('8 every tier lowest first (' . $tiers[0][1] . ' to ' . $tiers[count($tiers) - 1][1] . '), each on its own colour; placements and Unranked in words',
    $strip && strpos($c, '<p class="rsl-preview-note">' . RSL_Public::count_word($games) . ' placement game' . ($games === 1 ? '' : 's') . ', then climb.</p>') !== false
    && strpos($c, '<p class="rsl-preview-note">Just here for a hit? Unranked is a simple ladder of wins and sets.</p>') !== false
    && RSL_Public::count_word(3) === 'Three' && RSL_Public::count_word(12) === '12', $c);

// 9: the free agent line in each phase.
$check('9 the window still to open: when it opens and when everyone hears',
    $fa_line($c) === esc_html('No team? The free agent list opens ' . $short($day(5)) . ' and everyone hears their team by ' . $short($day(25)) . '.'), $fa_line($c));
$fa(-1, 20, 25);
$f = $fa_line($card($pages()['teams']));
$check('...the window open: open until the deadline', $f === esc_html('No team? The free agent list is open until ' . $short($day(20)) . ' and everyone hears their team by ' . $short($day(25)) . '.'), $f);
$fa(-10, -1, 25);
$c9 = $card($pages()['teams']);
$check('...past the deadline (placing): no free agent line', $c9 !== '' && strpos($c9, 'rsl-preview-fa') === false && strpos($c9, 'No team?') === false, $c9);
$fa(5, 20, 25);
$confirm(true);
$c9 = $card($pages()['teams']);
$check('...placements confirmed: no free agent line', $c9 !== '' && strpos($c9, 'No team?') === false, $c9);
$confirm(false);
RSL_Seasons::set_signup($later);
$c9 = $card($pages()['teams']);
$check('...a season not open for sign-up (the pin on a later season): no free agent line', $c9 !== '' && strpos($c9, 'No team?') === false, $c9);
RSL_Seasons::set_signup($season);

// 10: the buttons, and the How the league works page setting.
$c = $card($pages()['teams']);
$check('10 Register for the season\'s year, to the Social League page; no How the league works button while none is set',
    strpos($c, '<a class="rsl-preview-btn" href="' . esc_url(get_permalink($portal_id)) . '">Register for 2096</a>') !== false && strpos($c, 'How the league works') === false, $c);
$about = (int) wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => "RSL About $suffix", 'post_content' => 'How it works.'));
$posts[] = $about;
$draft = (int) wp_insert_post(array('post_type' => 'page', 'post_status' => 'draft', 'post_title' => "RSL Draft $suffix"));
$posts[] = $draft;
// A published post that isn't a page: a menu item post in no menu (as test-menu-label.php makes;
// no menu changes), never a blog post (publishing one runs the site's publish_post hooks).
$a_post = (int) wp_insert_post(array('post_type' => 'nav_menu_item', 'post_status' => 'publish', 'post_title' => "RSL Item $suffix"));
$posts[] = $a_post;
$admin_do($member, array('do' => 'save_about_page', 'about_page_id' => $about));
$check('...only an admin can set the page', intval(get_option(RSL_Public::ABOUT_OPTION)) === 0 && $notice($member)['type'] === 'error');
$to = $admin_do($admin, array('do' => 'save_about_page', 'about_page_id' => $about));
$n = $notice($admin);
$check('...an admin picks a published page: saved, and the notice names it', intval(get_option(RSL_Public::ABOUT_OPTION)) === $about && $n['type'] === 'success'
    && $n['message'] === "The season preview's How the league works button now goes to RSL About $suffix." && strpos($to, '#rsl-league-pages') !== false, $n['message']);
$c = $card($pages()['teams']);
$check('...the card links to it', strpos($c, '<a class="rsl-preview-btn is-secondary" href="' . esc_url(get_permalink($about)) . '">How the league works</a>') !== false, $c);
$bad_n = array();
foreach (array($draft, $a_post, 999999999) as $bad) {
    $admin_do($admin, array('do' => 'save_about_page', 'about_page_id' => $bad));
    $bad_n[] = $notice($admin);
}
$check('...a draft page, a published menu item post or no such page is refused, and the setting kept', $a_post > 0 && intval(get_option(RSL_Public::ABOUT_OPTION)) === $about
    && count(array_filter($bad_n, function ($x) { return $x['type'] === 'error' && $x['message'] === 'Pick a published page for How the league works.'; })) === 3);
$screen = $admin_screen($admin);
$check('...the Teams screen shows the League pages box with the page chosen, and no not-published line', strpos($screen, 'id="rsl-league-pages"') !== false
    && strpos($screen, 'name="about_page_id"') !== false && strpos($screen, 'value="' . $about . '" selected="selected"') !== false
    && strpos($screen, 'The page chosen before') === false);
$screen = '';
wp_update_post(array('ID' => $about, 'post_status' => 'draft'));
$c = $card($pages()['teams']);
$screen = $admin_screen($admin);
$lost = strpos($screen, '<p class="description">The page chosen before (' . esc_html("RSL About $suffix") . ') isn\'t published, so the button is hidden.</p>') !== false;
$screen = '';
$check('...a page no longer published: no button (the setting stays), and the League pages box says why', strpos($c, 'How the league works') === false
    && intval(get_option(RSL_Public::ABOUT_OPTION)) === $about && $lost, $c);
wp_update_post(array('ID' => $about, 'post_status' => 'publish'));
$admin_do($admin, array('do' => 'save_about_page', 'about_page_id' => 0));
$n = $notice($admin);
$check('...None clears it', intval(get_option(RSL_Public::ABOUT_OPTION)) === 0 && $n['message'] === 'The season preview has no How the league works button now.', $n['message']);
$admin_do($admin, array('do' => 'save_about_page', 'about_page_id' => $about));
$notice($admin);

// 11: escaping.
$evil = function ($link, $post_id) use ($about, $portal_id) {
    return in_array(intval($post_id), array($about, $portal_id), true) ? 'https://example.test/how?x="><script>alert(1)</script>' : $link;
};
add_filter('page_link', $evil, 10, 2);
$c = $card($pages()['teams']);
remove_filter('page_link', $evil, 10);
$evil = null;
$check('11 links are escaped: a page link carrying markup never leaves its href', $c !== '' && strpos($c, '<script>alert') === false
    && strpos($c, '"><script') === false && substr_count($c, 'href="https://example.test/how?x=') === 2, $c);

// 12: the same for every viewer; another season's Teams page has none.
$as0 = $card($pages(0)['teams']);
$check('12 the same card for every viewer (a cached page may go to anyone)', $as0 !== '' && $as0 === $card($pages($member)['teams']) && $as0 === $card($pages($admin)['teams']));
$other = $pages(0, array('season' => $later));
$check('...the Teams page showing another season: no card, and No teams yet', strpos($other['teams'], '<section class="rsl-preview"') === false
    && strpos($other['teams'], 'No teams yet this season.') !== false);
wp_set_current_user(0);
$_GET = array();
set_query_var(RSL_Public::QUERY_VAR, ($aces && !is_wp_error($aces)) ? $aces->slug : 'no-such-team');
$team_page = do_shortcode('[rsl_teams]');
set_query_var(RSL_Public::QUERY_VAR, '');
$check('...a team\'s own page: the team, and no card', strpos($team_page, '<h2 class="rsl-title">' . esc_html("Aces $suffix") . '</h2>') !== false
    && strpos($team_page, '<section class="rsl-preview"') === false);

// 13: the countdown's words, a bye on the first date, one day out, tonight.
$check('13 the countdown words and the day count (pure)', RSL_Public::countdown_words(2) === '2 days to go' && RSL_Public::countdown_words(1) === '1 day to go'
    && RSL_Public::countdown_words(0) === 'The first night is tonight' && RSL_Public::countdown_words(-1) === ''
    && RSL_Public::days_until($day(40), $today) === 40 && RSL_Public::days_until('2096-01-01', '2095-12-31') === 1
    && RSL_Public::days_until('2096-01-01', '2096-01-08') === -7 && RSL_Public::days_until('bogus', $today) === null);
$nights = RSL_Courts::nights($season);
RSL_Courts::set_night_type($nights[0]->id, 'bye', 'Holiday');
$c = $card($pages()['teams']);
$check('...a bye on the first date: the season starts on its first game night', strpos($c, ' starts ' . esc_html($long($day(47))) . '</h3>') !== false
    && strpos($c, '<p class="rsl-preview-countdown">47 days to go</p>') !== false && strpos($c, 'data-rsl-first-night="' . $day(47) . '"') !== false, $c);
RSL_Courts::set_night_type($nights[0]->id, 'regular', '');
$dates(1);
$c = $card($pages()['teams']);
$check('...one day out: 1 day to go', strpos($c, '<p class="rsl-preview-countdown">1 day to go</p>') !== false && strpos($c, ' starts ' . esc_html($long($day(1))) . '</h3>') !== false, $c);
$dates(0);
$c = $card($pages()['teams']);
$check('...the first night today: The first night is tonight, and the card still shows', strpos($c, '<p class="rsl-preview-countdown">The first night is tonight</p>') !== false, $c);

// 14-15: hidden once a night is published, or the first night has passed.
$first = $dates(40);
$nights = RSL_Courts::nights($season);
RSL_Fixtures::publish_night($nights[3]->id);
$check('14 a published night (any of them): no card on any page', $cards($pages()) === 0 && RSL_Public::preview_state($season) === array('show' => false, 'first' => ''));
RSL_Fixtures::publish_night($nights[3]->id, false);
$check('...unpublished again: the card is back on all three', $cards($pages()) === 3);
$dates(-7);
$check('15 the first night has passed (nothing published): no card', $cards($pages()) === 0 && RSL_Public::preview_state($season) === array('show' => false, 'first' => $day(-7)));

// 16-17: no dates; and a finished season with no dates.
$no_dates();
RSL_Free_Agents::save_dates($season, '', '', '');
$p = $pages();
$c = $card($p['teams']);
$check('16 no dates: dates coming soon, no countdown and no date on the card; the free agent list open with no deadline; all three pages',
    strpos($c, '<h3 class="rsl-preview-title">2096 Pre-season (Summer) dates coming soon</h3>') !== false && strpos($c, 'rsl-preview-countdown') === false
    && strpos($c, 'data-rsl-first-night') === false && strpos($c, 'data-rsl-tz') === false && $fa_line($c) === esc_html('No team? Join the free agent list and we\'ll find you a team.')
    && $cards($p) === 3 && $before($p['fixtures'], '<section class="rsl-preview"', 'fixture isn\'t out yet')
    && RSL_Public::preview_state($season) === array('show' => true, 'first' => ''), $c);
RSL_Seasons::set_current($old);
RSL_Seasons::set_signup($old);
$p = $pages();
$check('17 a season with no dates that has finished: no card, and the Teams page says No teams yet again; a bad key never shows',
    $cards($p) === 0 && strpos($p['teams'], 'No teams yet this season.') !== false && RSL_Public::preview_state($old)['show'] === false
    && RSL_Public::season_preview('bogus') === '');

} catch (Throwable $thrown) {
    $fails++;
    echo 'FAIL: threw ' . get_class($thrown) . ': ' . $thrown->getMessage() . ' (line ' . $thrown->getLine() . ")\n";
} finally {
// --- Cleanup (runs after a failure too) ------------------------------------------------------------
wp_set_current_user(0);
$_GET = array();
set_query_var(RSL_Public::QUERY_VAR, '');
if ($evil) {
    remove_filter('page_link', $evil, 10);
}
foreach (array($season, $old, $later) as $s_key) {
    foreach (RSL_Courts::nights($s_key) as $n) {
        $wpdb->delete(RSL_Database::table('night_courts'), array('night_id' => $n->id));
        $wpdb->delete(RSL_Database::table('night_venues'), array('night_id' => $n->id));
        $wpdb->delete(RSL_Database::table('requests'), array('night_id' => $n->id));
    }
    $wpdb->delete(RSL_Database::table('nights'), array('season' => $s_key));
    $wpdb->delete(RSL_Database::table('ledger'), array('season' => $s_key));
    foreach ($wpdb->get_col($wpdb->prepare("SELECT id FROM " . RSL_Database::table('teams') . " WHERE season = %s", $s_key)) as $tid) {
        $wpdb->delete(RSL_Database::table('team_members'), array('team_id' => $tid));
        $wpdb->delete(RSL_Database::table('invites'), array('team_id' => $tid));
    }
    $wpdb->delete(RSL_Database::table('teams'), array('season' => $s_key));
    delete_transient(RSL_Stats::CACHE_PREFIX . $s_key);
}
foreach (array(RSL_Courts::SETTINGS_OPTION, RSL_Free_Agents::BUILDING_OPTION) as $opt) {
    $all = get_option($opt, null);
    if (is_array($all) && (isset($all[$season]) || isset($all[$old]) || isset($all[$later]))) {
        unset($all[$season], $all[$old], $all[$later]);
        update_option($opt, $all, false);
    }
}
foreach ($posts as $pid) {
    if ($pid > 0) {
        wp_delete_post($pid, true);
    }
}
if ($made_portal) {
    wp_delete_post($made_portal, true);
}
// Each put back with the autoload the plugin writes it with: the season pins (set_current(),
// set_signup()) and the page id (ensure_page()) off, the How the league works page on.
$autoload = array(RSL_Seasons::CURRENT_OPTION => false, RSL_Seasons::SIGNUP_OPTION => false, RSL_Public::ABOUT_OPTION => true, RSL_Portal::PAGE_OPTION => false);
foreach ($saved as $opt => $value) {
    if ($value === '__unset__') { delete_option($opt); } else { update_option($opt, $value, $autoload[$opt]); }
}
rsl_dev_delete_users($users);
rsl_dev_release_mail($hold);
wp_set_current_user(0);
}
echo $fails ? "$fails FAILED\n" : "ALL PASSED\n";
