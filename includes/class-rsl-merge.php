<?php
/**
 * Account merges (the Club Manager's Duplicate accounts tool) and the
 * league. The league never moves its records from one account to another:
 * a merge is refused while an account being merged away is in use in the
 * league, and the admin is told to keep that account instead. The kept
 * account's league records are never touched, and nothing happens on undo
 * or after a merge.
 *
 * Two Club Manager hooks: murvc_account_merge_blockers (the preview says
 * why, in a plain sentence) and murvc_account_merge_apply (inside the
 * merge's transaction: the same check again, refusing by throwing an
 * Exception, which rolls the whole merge back).
 *
 * columns() is the ONE list of table columns that name an account; uses()
 * adds the places outside the tables (an email invite typed to the
 * account's address, a top-up order's member, the placement draft option).
 * A new table or column holding an account id goes into columns() in the
 * same change, and a new option entry (or any other place outside the
 * tables) into uses(). dev/tests/test-merge.php reads the schema and fails
 * on a user_id / *_by column, or any other bigint column not on its list
 * of ids that aren't accounts, missing from columns(); nothing checks the
 * places outside the tables, so those are on whoever adds one.
 *
 * Accepted to keep this simple: the check inside the merge reads the
 * transaction's snapshot and takes no league lock, so a league row
 * committed for an account being merged away while the merge runs can
 * still slip through. Data rules only, no output.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RSL_Merge {

    /** Order item meta: the member a team top-up was bought by (RSL_Payments). */
    const TOPUP_META = '_rsl_topup_user';

    /** When the check itself fails, the merge is stopped, never waved through. */
    const CHECK_FAILED = 'The Social League couldn\'t check whether these accounts are in use, so they can\'t be merged right now. Try again, or ask the website maintainer.';

    /** Per request: whether WooCommerce's order item meta table exists. */
    private static $woo_meta = null;

    public static function init() {
        add_filter('murvc_account_merge_blockers', array(__CLASS__, 'blockers'), 10, 3);
        add_action('murvc_account_merge_apply', array(__CLASS__, 'apply'), 10, 2);
    }

    /**
     * Every table column holding an account id, person and actor columns
     * alike: table (RSL_Database::table()), column, what (a short phrase
     * for the admin), season — 'season' (the row's own season column),
     * 'team' (the season of the row's team) or '' (none shown) — and two
     * that change the wording only, never whether the account is in use:
     * self, an actor column's person column on the same row (a row naming
     * the account in both reads as the person column's phrase alone: a
     * player who ticked happy to ref didn't change the list as an
     * official), and past, array(SQL true while the row is current, the
     * phrase when it isn't).
     */
    public static function columns() {
        $c = function ($table, $column, $what, $season = '', $self = '', $past = null) {
            return array('table' => RSL_Database::table($table), 'column' => $column, 'what' => $what, 'season' => $season, 'self' => $self, 'past' => $past);
        };
        return array(
            // The account's own league life.
            $c('players', 'user_id', 'registered as a player'),
            $c('team_members', 'user_id', 'on a team', 'team'),
            $c('teams', 'applied_by', 'applied to captain a team', 'season'),
            $c('free_agents', 'user_id', 'on the free agent list', 'season'),
            $c('join_requests', 'user_id', 'asked to join a team', 'season'),
            $c('invites', 'user_id', 'invited to a team'),
            $c('regos', 'user_id', 'has a social rego'),
            // A row with no role left (withdrawn or removed) stays.
            $c('officials', 'user_id', 'on the referees and court supervisors list', '', '',
                array("`ref` <> '' OR `supervisor` = 1", 'once on the referees and court supervisors list')),
            $c('jobs', 'user_id', 'has a referee or court supervisor job'),
            $c('ledger', 'user_id', 'named on a team balance line', 'season'),
            // RSL_Mail::prune() deletes sent email only.
            $c('outbox', 'user_id', 'has league email (sent in the last ' . RSL_Mail::KEEP_DAYS . ' days, waiting to go or failed to send)'),
            // What the account did as a captain, ref or admin.
            $c('teams', 'decided_by', 'decided a captain application', '', 'applied_by'),
            $c('regos', 'created_by', 'added a social rego', '', 'user_id'),
            $c('invites', 'invited_by', 'invited someone to a team', '', 'user_id'),
            $c('join_requests', 'responded_by', 'answered a request to join a team', '', 'user_id'),
            $c('games', 'result_by', 'entered a game result'),
            $c('requests', 'requested_by', 'made a night request'),
            $c('requests', 'handled_by', 'handled a night request'),
            $c('officials', 'updated_by', 'changed an entry on the referees and court supervisors list', '', 'user_id'),
            $c('jobs', 'assigned_by', 'assigned a referee or court supervisor job', '', 'user_id'),
            $c('ledger', 'created_by', 'made a team balance entry', '', 'user_id'),
        );
    }

    /**
     * What this account is in, as short phrases ("on a team in 2027
     * Pre-season (Summer)"); an empty array means it isn't in use in the
     * league. Existence checks only: every row naming the account counts,
     * whatever the wording. Throws RuntimeException when a query fails, so
     * a failed check never reads as "not in use".
     */
    public static function uses($user_id) {
        global $wpdb;
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return array();
        }
        $out = array();
        $own_only = array();
        $teams = RSL_Database::table('teams');
        foreach (self::columns() as $col) {
            $t = $col['table'];
            $c = $col['column'];
            // An actor column's phrase leaves out the account's own rows (see columns()).
            $not_own = $col['self'] !== '' ? " AND (x.`{$col['self']}` IS NULL OR x.`{$col['self']}` <> %d)" : '';
            $args = $col['self'] !== '' ? array($user_id, $user_id) : array($user_id);
            if ($col['season'] === 'season') {
                $seasons = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT x.`season` FROM `$t` x WHERE x.`$c` = %d$not_own ORDER BY x.`season`", $args));
            } elseif ($col['season'] === 'team') {
                $seasons = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT t.season FROM `$t` x LEFT JOIN `$teams` t ON t.id = x.team_id WHERE x.`$c` = %d$not_own ORDER BY t.season", $args));
            } else {
                $seasons = $wpdb->get_var($wpdb->prepare("SELECT 1 FROM `$t` x WHERE x.`$c` = %d$not_own LIMIT 1", $args)) ? array('') : array();
            }
            self::check_query();
            if ($seasons) {
                $what = $col['what'];
                if ($col['past']) {
                    $current = $wpdb->get_var($wpdb->prepare("SELECT 1 FROM `$t` x WHERE x.`$c` = %d$not_own AND ({$col['past'][0]}) LIMIT 1", $args));
                    self::check_query();
                    if (!$current) {
                        $what = $col['past'][1];
                    }
                }
                $out[] = self::phrase($what, $seasons, ' in ');
            } elseif ($not_own !== '') {
                // Own rows only: still a row naming the account.
                $own = $wpdb->get_var($wpdb->prepare("SELECT 1 FROM `$t` WHERE `$c` = %d LIMIT 1", $user_id));
                self::check_query();
                if ($own) {
                    $own_only[] = $col['what'];
                }
            }
        }

        // An email invite a captain typed to this account's address before
        // it registered (user_id 0, matched on the address when they sign
        // in): the address leaves with the account, and the invite with it.
        $email = $wpdb->get_var($wpdb->prepare("SELECT user_email FROM {$wpdb->users} WHERE ID = %d", $user_id));
        self::check_query();
        if (is_string($email) && $email !== '') {
            $hit = $wpdb->get_var($wpdb->prepare("SELECT 1 FROM `" . RSL_Database::table('invites') . "` WHERE user_id = 0 AND source = 'email' AND status = 'pending' AND email = %s LIMIT 1", $email));
            self::check_query();
            if ($hit) {
                $out[] = 'has a team invite waiting at its email address';
            }
        }

        // A top-up order's member: read when the order is paid, so an order
        // still unpaid would credit an account that's gone.
        if (self::$woo_meta === null) {
            $meta = $wpdb->prefix . 'woocommerce_order_itemmeta';
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($meta)));
            self::check_query();
            self::$woo_meta = $found ? $meta : '';
        }
        if (self::$woo_meta !== '') {
            $hit = $wpdb->get_var($wpdb->prepare('SELECT 1 FROM `' . self::$woo_meta . '` WHERE meta_key = %s AND meta_value = %s LIMIT 1', self::TOPUP_META, (string) $user_id));
            self::check_query();
            if ($hit) {
                $out[] = 'has a team top-up order';
            }
        }

        // The placement draft (RSL_Free_Agents::building()): a person's
        // destination, a draft team's captain, who confirmed placement.
        $draft = array();
        $confirmed = array();
        $all = get_option(RSL_Free_Agents::BUILDING_OPTION, array());
        foreach (is_array($all) ? $all : array() as $season => $b) {
            if (!is_array($b)) {
                continue;
            }
            if (isset($b['proposed_map']) && is_array($b['proposed_map']) && array_key_exists($user_id, $b['proposed_map'])) {
                $draft[] = (string) $season;
            } elseif (isset($b['new_teams']) && is_array($b['new_teams'])) {
                foreach ($b['new_teams'] as $d) {
                    if (is_array($d) && isset($d['captain_user_id']) && (int) $d['captain_user_id'] === $user_id) {
                        $draft[] = (string) $season;
                        break;
                    }
                }
            }
            if (isset($b['confirmed_by']) && is_scalar($b['confirmed_by']) && (int) $b['confirmed_by'] === $user_id) {
                $confirmed[] = (string) $season;
            }
        }
        if ($draft) {
            $out[] = self::phrase('in the placement draft', $draft, ' for ');
        }
        if ($confirmed) {
            $out[] = self::phrase('confirmed placement', $confirmed, ' for ');
        }
        // The wording never makes an account read as not in use: an own row
        // whose person column phrase isn't here (it was written between the
        // two reads) shows as the actor phrase.
        if (!$out && $own_only) {
            $out = $own_only;
        }
        return array_values(array_unique($out));
    }

    /**
     * The sentence refusing this merge, or '' when the league has nothing
     * against it (no account being merged away is in use; the kept account
     * may be). Throws when a check fails.
     */
    public static function refusal($keep_id, $merge_ids) {
        $keep_id = (int) $keep_id;
        $merge_ids = array_values(array_diff(array_unique(array_filter(array_map('intval', (array) $merge_ids), function ($id) {
            return $id > 0;
        })), array($keep_id)));
        if ($keep_id <= 0 || !$merge_ids) {
            return '';
        }
        $uses = array();
        foreach ($merge_ids as $id) {
            $uses[$id] = self::uses($id);
        }
        if (!array_filter($uses)) {
            return '';
        }
        $uses[$keep_id] = self::uses($keep_id);
        return self::sentence($keep_id, $merge_ids, $uses);
    }

    /**
     * Pure: the refusal for these accounts, given what each is in ($uses:
     * account id => phrases). One account being merged away in use: keep
     * that one instead. Two or more of the accounts in use: they can't be
     * merged (into one account, when some others aren't in use).
     */
    public static function sentence($keep_id, $merge_ids, $uses) {
        $all = array_merge(array((int) $keep_id), array_map('intval', $merge_ids));
        $in_use = array();
        foreach ($all as $id) {
            if (!empty($uses[$id])) {
                $in_use[$id] = array_values((array) $uses[$id]);
            }
        }
        if (!array_diff(array_keys($in_use), array((int) $keep_id))) {
            return '';
        }
        $tags = function ($ids) {
            return RSL_Players::list_words(array_map(function ($id) {
                return '#' . $id;
            }, array_values($ids)));
        };
        $never = 'league records never move from one account to another';
        if (count($in_use) === 1) {
            $id = key($in_use);
            return "#$id is in use in the Social League: " . implode('; ', $in_use[$id]) . '. ' . ucfirst($never)
                . ", so keep #$id instead and merge " . $tags(array_diff($all, array($id))) . ' into it.';
        }
        $ids = array_keys($in_use);
        $clean = array_diff($all, $ids);
        $out = (count($ids) === 2 ? 'Both ' . $tags($ids) . ' are' : $tags($ids) . ' are all') . " in use in the Social League, and $never, so ";
        $out .= $clean
            ? 'they can\'t be merged into one account. Merge ' . $tags($clean) . ' into one of them instead.'
            : 'these accounts can\'t be merged.';
        foreach ($in_use as $id => $phrases) {
            $out .= " #$id: " . implode('; ', $phrases) . '.';
        }
        return $out;
    }

    /**
     * murvc_account_merge_blockers: the preview's reasons not to merge.
     * Always returns an array; a failed check blocks the merge.
     */
    public static function blockers($blockers, $keep_id = 0, $merge_ids = array()) {
        if (!is_array($blockers)) {
            $blockers = is_string($blockers) && $blockers !== '' ? array($blockers) : array();
        }
        try {
            $sentence = self::refusal($keep_id, $merge_ids);
        } catch (Throwable $t) {
            self::log_failure($t);
            $sentence = self::CHECK_FAILED;
        }
        if ($sentence !== '') {
            $blockers[] = $sentence;
        }
        return $blockers;
    }

    /**
     * murvc_account_merge_apply, inside the merge's transaction: the safety
     * net for anything that changed since the preview. Changes nothing. An
     * account being merged away that's in use stops the merge with an
     * Exception (the Club Manager rolls back and shows the message); any
     * other error becomes an Exception too, as the Club Manager catches
     * only those.
     */
    public static function apply($keep_id, $merged_ids = array()) {
        try {
            $sentence = self::refusal($keep_id, $merged_ids);
        } catch (Throwable $t) {
            self::log_failure($t);
            $sentence = self::CHECK_FAILED;
        }
        if ($sentence !== '') {
            throw new Exception($sentence);
        }
    }

    /** "on a team in 2026 Post-season (Spring) and 2027 Pre-season (Summer)" */
    private static function phrase($what, $seasons, $joiner) {
        $keys = array_values(array_unique(array_filter(array_map('strval', $seasons), 'strlen')));
        // In calendar order: by year, then the part of the year.
        $part = array_flip(array_keys(RSL_Seasons::parts()));
        usort($keys, function ($a, $b) use ($part) {
            $x = explode('-', $a, 2) + array('', '');
            $y = explode('-', $b, 2) + array('', '');
            $px = isset($part[$x[1]]) ? $part[$x[1]] : 99;
            $py = isset($part[$y[1]]) ? $part[$y[1]] : 99;
            return array($x[0], $px, $a) <=> array($y[0], $py, $b);
        });
        $labels = array_map(array('RSL_Seasons', 'label'), $keys);
        return $labels ? $what . $joiner . RSL_Players::list_words($labels) : $what;
    }

    private static function check_query() {
        global $wpdb;
        if ($wpdb->last_error !== '') {
            throw new RuntimeException('Database error: ' . $wpdb->last_error);
        }
    }

    private static function log_failure($t) {
        error_log('Reds Social League: the account merge check failed: ' . get_class($t) . ': ' . $t->getMessage());
    }
}
