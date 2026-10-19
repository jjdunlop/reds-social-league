<?php
/**
 * Public pages. The plugin creates a "League Teams" page holding
 * [rsl_teams]; one team's page lives under it at /league-teams/{slug}/
 * (a rewrite onto the same page — no WordPress page per team).
 *
 * Privacy: public pages show TEAMS, never people — name, division, badge,
 * size. A team's roster is shown only to its own members and admins.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RSL_Public {

    const PAGE_OPTION = 'rsl_teams_page_id';
    const QUERY_VAR = 'rsl_team';

    public function __construct() {
        add_shortcode('rsl_teams', array($this, 'render_teams'));
        add_action('init', array(__CLASS__, 'add_rewrite_rules'));
        add_filter('query_vars', function ($vars) {
            $vars[] = RSL_Public::QUERY_VAR;
            return $vars;
        });
    }

    /** Create the public league pages if they're missing (activation / upgrade). */
    public static function ensure_pages() {
        RSL_Public_Fixtures::ensure_pages();
        RSL_Ref_Page::ensure_page();
        return self::ensure_page(self::PAGE_OPTION, 'League Teams', 'league-teams', '[rsl_teams]');
    }

    /** A published page holding $shortcode, remembered in $option. Returns its id. */
    public static function ensure_page($option, $title, $slug, $shortcode) {
        $page_id = intval(get_option($option));
        if ($page_id && get_post_status($page_id)) {
            return $page_id;
        }
        $page_id = wp_insert_post(array(
            'post_title' => $title,
            'post_name' => $slug,
            'post_content' => $shortcode,
            'post_status' => 'publish',
            'post_type' => 'page',
        ));
        if ($page_id && !is_wp_error($page_id)) {
            update_option($option, $page_id, false);
        }
        return $page_id;
    }

    public static function add_rewrite_rules() {
        $page_id = intval(get_option(self::PAGE_OPTION));
        $page = $page_id ? get_post($page_id) : null;
        if (!$page) {
            return;
        }
        $path = get_page_uri($page);
        add_rewrite_rule('^' . preg_quote($path, '#') . '/([^/]+)/?$', 'index.php?page_id=' . $page_id . '&' . self::QUERY_VAR . '=$matches[1]', 'top');
    }

    /** Public link to a team's page. */
    public static function team_url($team) {
        $page_id = intval(get_option(self::PAGE_OPTION));
        if (!$page_id) {
            return '';
        }
        $base = get_permalink($page_id);
        return get_option('permalink_structure')
            ? trailingslashit($base) . rawurlencode($team->slug) . '/'
            : add_query_arg(self::QUERY_VAR, $team->slug, $base);
    }

    // ------------------------------------------------------------------
    // [rsl_teams]
    // ------------------------------------------------------------------

    public function render_teams($atts = array()) {
        $season = RSL_Seasons::current();
        $slug = get_query_var(self::QUERY_VAR);
        if ($slug === '' && isset($_GET[self::QUERY_VAR])) {
            $slug = sanitize_title(wp_unslash($_GET[self::QUERY_VAR]));
        }
        $html = $slug !== '' ? $this->render_team($season, sanitize_title($slug)) : $this->render_team_list($season);
        return '<div class="rsl">' . $html . '</div>' . self::styles();
    }

    private function render_team_list($season) {
        $teams = RSL_Teams::list_for_season($season);
        ob_start();
        ?>
        <h2 class="rsl-title"><?php echo esc_html(RSL_Seasons::label($season)); ?> teams</h2>
        <p class="rsl-links"><a href="<?php echo esc_url(RSL_Public_Fixtures::fixtures_url()); ?>">Fixtures &amp; results &rarr;</a> &nbsp; <a href="<?php echo esc_url(RSL_Public_Fixtures::ladder_url()); ?>">Ladder &rarr;</a></p>
        <?php if (empty($teams)): ?>
            <p class="rsl-empty">No teams yet this season.</p>
        <?php endif; ?>
        <?php foreach (RSL_Teams::DIVISIONS as $division => $label): ?>
            <?php $in = array_filter($teams, function ($t) use ($division) { return $t->division === $division; }); ?>
            <?php if (empty($in)) { continue; } ?>
            <h3 class="rsl-division"><?php echo esc_html($label); ?> <span>(<?php echo count($in); ?>)</span></h3>
            <ul class="rsl-team-list">
                <?php foreach ($in as $team): ?>
                    <li>
                        <a href="<?php echo esc_url(self::team_url($team)); ?>">
                            <?php echo self::badge($team); ?>
                            <span class="rsl-team-name"><?php echo esc_html($team->name); ?></span>
                            <span class="rsl-team-size"><?php echo intval($team->member_count); ?> player<?php echo intval($team->member_count) === 1 ? '' : 's'; ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>
        <?php
        return ob_get_clean();
    }

    private function render_team($season, $slug) {
        $team = RSL_Teams::get_by_slug($season, $slug);
        $back = get_permalink(intval(get_option(self::PAGE_OPTION)));
        if (!$team || $team->status !== 'active') {
            return '<p class="rsl-empty">That team isn\'t playing this season.</p><p><a href="' . esc_url($back) . '">&larr; All teams</a></p>';
        }
        $members = RSL_Teams::members($team->id);
        $user_id = get_current_user_id();
        $can_see_roster = $user_id && (RSL_Teams::is_member($team->id, $user_id) || current_user_can('manage_options'));
        ob_start();
        ?>
        <?php echo RSL_Portal::flash_html(); ?>
        <p class="rsl-back"><a href="<?php echo esc_url($back); ?>">&larr; All teams</a></p>
        <div class="rsl-team-head">
            <?php echo self::badge($team, 'large'); ?>
            <div>
                <h2 class="rsl-title"><?php echo esc_html($team->name); ?></h2>
                <p class="rsl-meta"><?php echo esc_html(RSL_Teams::DIVISIONS[$team->division]); ?> &middot; <?php echo esc_html(RSL_Seasons::label($season)); ?> &middot; <?php echo count($members); ?> player<?php echo count($members) === 1 ? '' : 's'; ?></p>
            </div>
        </div>
        <?php echo RSL_Public_Fixtures::team_profile($team); ?>
        <?php echo RSL_Payments::team_card($team); ?>
        <?php if ($can_see_roster): ?>
            <h3 class="rsl-division">Roster</h3>
            <ul class="rsl-roster">
                <?php foreach ($members as $m): ?>
                    <li><?php echo esc_html($m->display_name); ?><?php if ($m->role === 'captain'): ?> <span class="rsl-chip">Captain</span><?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php echo RSL_Public_Fixtures::team_section($team); ?>
        <?php
        return ob_get_clean();
    }

    /** The team's badge: its initials on its colour (logos come later). */
    public static function badge($team, $size = '') {
        return '<span class="rsl-badge' . ($size ? ' rsl-badge-' . esc_attr($size) : '') . '" style="background:' . esc_attr($team->colour) . '" aria-hidden="true">'
            . esc_html(RSL_Teams::initials($team->name)) . '</span>';
    }

    public static function styles() {
        static $done = false;
        if ($done) {
            return '';
        }
        $done = true;
        return '<style>
            .rsl { --rsl-navy: #1d3d6e; --rsl-muted: #6b7280; --rsl-border: #dcdcde; }
            .rsl-division { margin: 22px 0 8px; color: var(--rsl-navy); }
            .rsl-division span, .rsl-team-size, .rsl-meta, .rsl-empty { color: var(--rsl-muted); font-weight: 400; }
            .rsl-team-list, .rsl-roster { list-style: none; margin: 0; padding: 0; }
            .rsl-team-list li a { display: flex; align-items: center; gap: 12px; padding: 10px 12px; margin-bottom: 8px; border: 1px solid var(--rsl-border); border-radius: 8px; text-decoration: none; color: inherit; }
            .rsl-team-list li a:hover { border-color: var(--rsl-navy); }
            .rsl-team-name { font-weight: 600; flex: 1 1 auto; }
            .rsl-team-size { font-size: 13px; }
            .rsl-badge { display: inline-flex; align-items: center; justify-content: center; flex: 0 0 auto; width: 40px; height: 40px; border-radius: 50%; color: #fff; font-weight: 700; font-size: 15px; letter-spacing: 0.5px; }
            .rsl-badge-large { width: 72px; height: 72px; font-size: 26px; }
            .rsl-team-head { display: flex; align-items: center; gap: 16px; }
            .rsl-team-head .rsl-title { margin: 0; }
            .rsl-meta { margin: 4px 0 0; }
            .rsl-roster li { padding: 8px 0; border-bottom: 1px solid var(--rsl-border); }
            .rsl-chip { display: inline-block; margin-left: 6px; padding: 1px 8px; border-radius: 10px; font-size: 12px; background: #eef2f8; color: var(--rsl-navy); font-weight: 600; }
        </style>';
    }
}
