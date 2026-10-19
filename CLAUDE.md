# Reds Social League — AI maintainer guide

Read this before changing anything. The README covers what the plugin
does; this file covers how to work on it safely.

## What this is

A WordPress plugin for MURVC's social volleyball league (Reds Social
League) on the club's members site. The full design — divisions,
ratings, weekly fixtures, payments, refs, finals, season calendar — is in
the club's design doc; build to it, phase by phase:

1. **Registration and payments** — players, teams, invites, free agents,
   sub list, team balances. Fixtures still made by hand.
2. **Results and ladders** — refs claim courts and enter scores, live
   ladder and fixtures pages, ref pay report.
3. **Ratings and fixturing** — Bradley-Terry ratings (shadowed first),
   rating-based weekly pairing, double-headers, finals.

It sits beside the club's other plugin, **MURVC Club Manager**
(`team-oversight-migration`), which runs memberships, VVL trials and VVL
fees. Keep the two separate: this plugin may *read* from the Club Manager
(membership status, activity log) through a small, checked API
(`class_exists` first, degrade gracefully), never write to its tables.

## This repo will be PUBLIC — privacy rules from day one

It's private now, but goes public when it's installed on the site (the
free Git Updater plugin needs a public repo). So write everything as if
it were public already:

- No member data: no dumps, CSVs, exports, screenshots of admin pages, or
  fixtures with real names, emails, phone numbers or DOBs. Test data is
  generated (`@example.test`) and deleted.
- No credentials, API keys, security-plugin details, or hosting specifics
  (server paths, DB names/prefixes, hosts) in code, docs or commits.
- Describe data issues by shape, never with real examples.

## Public pages show teams, not people

Public pages (team list, team pages, later fixtures/results/ladder) show
team names, badges, divisions and results — never player names. Rosters
and anything personal are for the people involved (the team's members,
captains, admins). Under-18s play in the league.

Two more privacy rules from registration: **a captain sees teammates'
names and ✓ cleared / ✗ needs rego only — never why** (membership vs paid
rego); and **joining by email needs the player to accept** (until then the
captain sees only the email they typed). Being on a team never needs
payment; being cleared is what's needed before a first game.

## File map

- `reds-social-league.php` — bootstrap: constants, requires, activation (tables, pages, rewrites).
- `includes/class-rsl-database.php` — table creation (`dbDelta`) + `maybe_upgrade()`; bump `SCHEMA_VERSION` when the schema changes.
- `includes/class-rsl-seasons.php` — league seasons: four a year, keys like `2027-pre`, `2027-mid1`, `2027-mid2`, `2027-post`; the current season.
- `includes/class-rsl-teams.php` — teams, rosters, approximate level (`est_level`: an admin-only seed for first Ranked ratings, never public or shown to players), captain fixturing preferences (`pref_*`), captain applications (pending → approved/declined/withdrawn), invite codes, email invites. Data layer (no output); returns results or `WP_Error` with a readable message.
- `includes/class-rsl-players.php` — league registration profile (free; separate from paying).
- `includes/class-rsl-rego.php` — who's cleared to play: full/life membership via `TeamOversight_Memberships::covered_until()` (Club Manager, checked with `method_exists`), else a 12-month social rego (`rsl_regos`, one per paid order item — UNIQUE, the dedupe). WooCommerce hooks, past-order scan, pay link.
- `includes/class-rsl-courts.php` — venues, courts (usual hours), season settings (dates, weekday, game length per division — option `rsl_season_settings`), game nights (regular/finals/bye), per-night exceptions stored only when different from the usual (`rsl_night_courts`, `rsl_night_venues`), and `night_plan()` / `slots_for_night()` — the game slots fixtures fill. Data only.
- `includes/class-rsl-fixtures.php` — games in game-night slots (clash checks), set-score results, forfeits, per-night publishing, round labels. Data only.
- `includes/class-rsl-ratings.php` — Bradley-Terry ratings for Ranked on the points of every set plus a small set-win bonus (timed games: cut-short sets, third sets to 15, 1–1 draws); `fit()` is pure (prior on the level seed, recency weighting, uncertainty → provisional), and both ladders. Forfeits never count; unplayed teams never show their seed.
- `includes/class-rsl-requests.php` — captains' night requests: week off / two games (before fixtures are out), can't make it (after; emails admin, admin marks handled). The pairing engine and stats read them. Visible only to the team and admins.
- `includes/class-rsl-pairing.php` — automatic fixtures. Pure core (`pair_cost`, `pair_division`, `match`, `assign_slots`, `set_win_probability`) with no database, so it's testable and simulated by `dev/simulate-pairing.php`; `context()`/`plan()`/`generate()`/`explain_night()` wrap it for a real night. Closeness is each team's chance of winning a set (unit: a 67/33 matchup costs 1); weights in `weights()`. Re-run the simulator before changing weights.
- `includes/class-rsl-stats.php` — the season's story for public pages. The rating stays honest; what's shown as earned is designed to be fair: earned tiers with instant promotion and slow, protected demotion (`step_tier()`, pure), Season Points/levels that only go up (`POINTS`, `LEVEL_POINTS`), "In placements" while provisional, per-night rating changes, form/streaks, upsets, close games, achievements, season leaders, and `hype()` tags for coming games. Teams only; never shows a rating or change while a team is in placements (protects the hidden seed). Public pages show hype, not odds (`RSL_Public_Fixtures::SHOW_SET_CHANCES`).
- `dev/lib-sim.php` — plays a pretend timed game rally by rally (best of three in an hour, third set to 15 or 25, score stands at time). Used by `fake-season.php`, `simulate-pairing.php` and `simulate-ratings.php` (rating model comparison — re-run before changing `RSL_Ratings` weights).
- `includes/class-rsl-admin-fixtures.php` — Social League → Fixtures: per night, make automatically / adjust / publish / enter results.
- `includes/class-rsl-public-fixtures.php` — `[rsl_fixtures]` (Fixtures & Results page), `[rsl_ladder]` (League Ladder page), and the fixtures/standing section on team pages. Published nights only.
- `includes/class-rsl-admin-courts.php` — Social League → Courts & Nights: venues & courts, season calendar grid, per-night timeline editor, copy forward.
- `includes/class-rsl-portal.php` — `[rsl_my_league]` on the auto-created Social League page: register, clearance + pay, join by code, invites, apply as captain, captain tools. Post/Redirect/Get on `template_redirect`.
- `includes/class-rsl-public.php` — `[rsl_teams]`, the auto-created League Teams page, team pages at `/league-teams/{slug}/`.
- `includes/class-rsl-admin.php` — Social League → Teams admin (admin-post handlers, Post/Redirect/Get).
- `dev/fake-season.php` — DEV ONLY: fills the current season with a pretend league (`reset` removes it). WP-CLI only.
- `dev/tests/*.php` — tests, run with `wp eval-file`. They live **in the repo** (not a temp folder) so they're never lost.

## Rules

- **PHP 7.4 and 8.x.** The site is on 7.4 now and moving to 8.x. No PHP 8-only syntax (no `str_contains`, `match`, nullsafe `?->`, named args, union types). Lint on both before every push.
- **Tables are `{$wpdb->prefix}rsl_*`** via `RSL_Database::table()`; never hardcode a prefix. Changes are additive (never rename/drop; add and backfill).
- **People are WordPress users, keyed by `user_id`.** Emails are for display only.
- **Every admin action**: nonce + `current_user_can('manage_options')`, processed in admin-post, then redirect with a notice. Front-end actions (later) check that the user may act on *that* team, server-side.
- **Always `wp_unslash()` typed text from `$_POST`/`$_GET` before sanitising** — WordPress adds magic-quote slashes to all input.
- **Data rules live in the data classes** (`RSL_Teams` …), not in admin or page code, so tests can call them directly.
- **Slugs are permanent**: renaming a team never changes its public URL.
- **Commit messages must not contain double quotes** (PowerShell argument passing on the maintainer's machine mangles them).

## Development workflow

Build and test on the **local Docker copy** of the site (containers
`murvc_wp`, `murvc_cli`, `murvc_db`; site at `localhost:8080`). The plugin
isn't deployed anywhere until Deployer / Git Updater is pointed at this
repo, so nothing reaches the live site by accident.

1. Edit code here.
2. Lint on both PHP versions:
   `docker run --rm -v <repo>:/code php:7.4-cli php -l /code/<file>.php` and the same with `php:8.3-cli`.
3. Copy to local: `docker cp <repo>/. murvc_wp:/var/www/html/wp-content/plugins/reds-social-league/` (and to `murvc_cli` for WP-CLI).
4. Run the tests: `docker exec murvc_cli wp eval-file wp-content/plugins/reds-social-league/dev/tests/test-teams.php --path=/var/www/html`. Each prints OK/FAIL lines and ends with ALL PASSED or a count.
5. Look at it: `wp eval-file .../dev/fake-season.php` fills the current season; `... reset` removes it.
6. Bump the version (header + `RSL_VERSION`), update the README if behaviour changed, commit, push.
