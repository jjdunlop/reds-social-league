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
captain sees only the email they typed — and gets the same answer whether
or not that email is registered). Being on a team never needs payment;
being cleared is what's needed before a first game.

Free agents (0.12.0): **captains browsing the list see first name, level,
positions and "+N friends" only** (built only by
`RSL_Free_Agents::browse_for_captain()`; a test pins the keys). No free
text from players anywhere. Under-18s are left out of the captain's browse
list (the `under18_in_pool` setting is off by default). Since 0.13.0 nobody
is asked: under 18 comes from the date of birth on the account
(`RSL_Players::is_under18()`, judged on the earlier of today and the
season's first night; no date of birth counts as under 18), plus an
admin-only *Treat as under 18* tick (the `under18` column, written only by
`RSL_Free_Agents::admin_update()`). Every check goes through
`RSL_Free_Agents::under18_for()`, never `$row->under18` alone. The league
reads the date of birth and never stores it, and an age is never shown to
a captain. An under-18 can still ask a team themselves;
that captain then sees their request card (first name, level, positions),
and an accepted request swaps emails, as placement does (spec O11 — the
committee's call). Friend groups use shareable codes,
never email lookups. Contact details move only by email, only between a
player and their own captain, only when joining through the league. Admin-
only: est_level, band, band_override, admin_note, placement reasons.

**One team per person per season** holds on every route because
`RSL_Teams::add_member()` is the only way onto a roster: it takes a
per-person `GET_LOCK` and, for capped adds (active team, not admin), the
team's lock first (`RSL_Teams::team_lock_name()`; `RSL_Free_Agents::add_unit()`
holds it across a whole group), so two people can't push a team over the
maximum; it enforces the roster maximum (except `admin`), and fires
`rsl_member_added`. Don't insert into `rsl_team_members` anywhere else.
Locks are always taken team first, then person.

## File map

- `reds-social-league.php` — bootstrap: constants, requires, activation (tables, pages, rewrites).
- `includes/class-rsl-database.php` — table creation (`dbDelta`) + `maybe_upgrade()`; bump `SCHEMA_VERSION` when the schema changes.
- `includes/class-rsl-seasons.php` — league seasons: four a year, keys like `2027-pre`, `2027-mid1`, `2027-mid2`, `2027-post`; the current season.
- `includes/class-rsl-teams.php` — teams, rosters, approximate level (`est_level`: an admin-only seed for first Ranked ratings, never public or shown to players), captain fixturing preferences (`pref_*`), captain applications (pending → approved/declined/withdrawn), invite codes, email invites. Data layer (no output); returns results or `WP_Error` with a readable message.
- `includes/class-rsl-players.php` — league registration profile (free; separate from paying). A new registration needs a complete club profile (`profile_fields()`: first and last name, mobile, date of birth, gender — as for VVL trials; `profile()` reads them from the account, gender through the Club Manager's `get_member_gender()` when it's there). `birth_dates()` / `age_on()` / `is_under18()` / `under18_map()` are the one age rule. `set_looking()` puts someone on / takes them off the sign-up season's free agent list.
- `includes/class-rsl-free-agents.php` — the free agent list (`rsl_free_agents`, per person per season), the team-building window (`dates()`, `phase()`: before / open / placing / season), friend groups (codes), captains' open spots, pool invites, join requests (`rsl_join_requests` — only the team's captain can answer), `on_member_added` (closes everything else), admin place-now, "Not for me", expiry. Accepting a pool invite brings the invitee's current friend group only if it's unchanged since the invite and every friend agreed to the team's division (`pool_invite_ids()`). `unplaced_words()` is the one rule for which placement reasons a player may see. `browse_for_captain()` / `card()` are the ONLY builders of what a captain sees about a free agent (first name, level, positions, +friends) — never add personal fields there. Emails through `RSL_Mail`.
- `includes/class-rsl-placement.php` — placing free agents after the deadline. Pure core `propose()` (no DB, deterministic; invariants: groups never split, Unranked-only never in Ranked, never over max, new teams within min..max with a volunteer captain) simulated by `dev/simulate-placement.php`; `suggest()` / `draft()` / `move()` / `update_new()` / `blockers()` / `confirm()` (idempotent) wrap it, each under the season's placement lock; `destinations()` / `destinations_all()` say where an admin may move someone.
- `includes/class-rsl-mail.php` — queued email (`rsl_outbox`, UNIQUE dedupe key), sent in batches by cron (`rsl_hourly`, `rsl_send_outbox`); sent rows pruned after 30 days.
- `includes/class-rsl-merge.php` — account merges (the Club Manager's Duplicate accounts tool; hooked in `rsl_init()`). The league never moves records between accounts: `blockers()` (on `murvc_account_merge_blockers`) refuses a merge while an account being merged away is in use in the league (keep that one instead; both in use = can't be merged), and `apply()` (on `murvc_account_merge_apply`, inside the merge's transaction) checks again and throws an `Exception` to roll the merge back — any other error becomes an `Exception` too, as the Club Manager catches only those. `columns()` is the ONE list of table columns that name an account; `uses()` adds a pending email invite typed to the account's address (`user_id` 0), the top-up order meta (`_rsl_topup_user`) and the placement draft option. Changes nothing; nothing on undo or after a merge.
- `includes/class-rsl-portal-free-agents.php` — the league page's free agent and captain "looking for players" pieces (route choice at registration, the "Your season" step card, teams looking, requests, browse/invite, Not for me), wired in by `RSL_Portal`.
- `includes/class-rsl-admin-free-agents.php` — Social League → Free Agents: overview (pool, teams, outbox), placement review/confirm, settings (window, roster sizes, the sign-up season pin).
- `includes/class-rsl-rego.php` — who's cleared to play: full/life membership via `TeamOversight_Memberships::covered_until()` (Club Manager, checked with `method_exists`), else a 12-month social rego (`rsl_regos`, one per paid order item — UNIQUE, the dedupe). WooCommerce hooks, past-order scan, pay link.
- `includes/class-rsl-courts.php` — venues, courts (usual hours), season settings (dates, weekday, game length per division — option `rsl_season_settings`), game nights (regular/finals/bye), per-night exceptions stored only when different from the usual (`rsl_night_courts`, `rsl_night_venues`), and `night_plan()` / `slots_for_night()` — the game slots fixtures fill. Data only.
- `includes/class-rsl-fixtures.php` — games in game-night slots (clash checks), set-score results, forfeits, per-night publishing, round labels. `delete_game()` takes the game's ref job and, with a venue's last game, its supervisor (`drop_idle_supervisors()`; a remake defers that until the new games are in). `has_started()`; unpublishing a started night needs `check_publish(..., false, $called_off = true)`. Data only.
- `includes/class-rsl-ratings.php` — Bradley-Terry ratings for Ranked on the points of every set plus a small set-win bonus (timed games: cut-short sets, third sets to 15, 1–1 draws); `fit()` is pure (prior on the level seed, recency weighting, uncertainty → provisional), and both ladders. Forfeits never count; unplayed teams never show their seed.
- `includes/class-rsl-requests.php` — captains' night requests: week off / two games (before fixtures are out), can't make it (after; emails admin, admin marks handled). The pairing engine and stats read them. Visible only to the team and admins.
- `includes/class-rsl-officials.php` — referees and court supervisors: roles (ref applied → approved by admin; supervisor added by admin), jobs (claim with clash/travel/cap checks, release before a cut-off, admin assign/remove), each person's night, earnings, pay report, who may score a game. Nothing already finished can be claimed by a ref; a result the league entered is locked to the ref (`can_score`); removing a ref/supervisor releases their jobs still to come. `ref_eligibility()` / `supervisor_eligibility()` are the query-free rule helpers the Ref Jobs page uses — keep `check_ref()` delegating to them; `open_to_refs($game)` (no result yet, not over) is what the Ref Jobs page counts as still needing a ref. Data only.
- `includes/class-rsl-ref-page.php` — `[rsl_ref_jobs]` (Ref Jobs page): my night, open jobs, pay; the tap-to-score scoreboard (phone-first: taps kept in localStorage, live updates via `rsl_live_score` with a sequence number, `rsl_save_score` to record the result).
- `includes/class-rsl-admin-officials.php` — Social League → Refs & Supervisors: approvals, supervisors, pay settings, pay report + CSV.
- `includes/class-rsl-payments.php` — team balances (`rsl_ledger`): game fees and late "can't make it" fees (per game) charged on publish and kept in step by `sync_night()` — the ONLY writer of `game` and `late_fee` rows; it runs under a per-night `GET_LOCK` and every row has a UNIQUE `charge_key`, so call it again rather than inserting charges; refunds/cancellations (`refund` rows; a cancelled order paid again is put back by a positive `refund` row keyed `uncancel:<item>:<cancel row id>`, and later cancellations are keyed `cancel:<item>:<uncancel id>`), only the first team out of a game pays its late fee (`late_fees_for_request()` says what a "can't make it" carries), season carry-over (`carry` rows), the owing limit (`blocked()`, read by the pairing engine), WooCommerce top-ups (placeholder product, GST-inclusive `cart_price()`, UNIQUE order_item_id dedupe), admin adjustments, the team page balance card. Money moves only through this class.
- `includes/class-rsl-admin-payments.php` — Social League → Team Balances: overview, a team's history + adjust, rules.
- `includes/class-rsl-pairing.php` — automatic fixtures. Pure core (`pair_cost`, `pair_division`, `match`, `assign_slots`, `set_win_probability`) with no database, so it's testable and simulated by `dev/simulate-pairing.php`; `context()`/`plan()`/`generate()`/`explain_night()` wrap it for a real night. Closeness is each team's chance of winning a set (unit: a 67/33 matchup costs 1); weights in `weights()`. Re-run the simulator before changing weights.
- `includes/class-rsl-stats.php` — the season's story for public pages. The rating stays honest; what's shown as earned is designed to be fair: earned tiers with instant promotion and slow, protected demotion (`step_tier()`, pure), Season Points/levels that only go up (`POINTS`, `LEVEL_POINTS`), "In placements" while provisional, per-night rating changes, form/streaks, upsets, close games, achievements, season leaders, and `hype()` tags for coming games. Teams only; never shows a rating, change, odds, upset or "Even match" while a team is in placements (protects the hidden seed). Public pages show hype, not odds (`RSL_Public_Fixtures::SHOW_SET_CHANCES`). `season()` is cached between page views in a transient (`RSL_Stats::CACHE_PREFIX` + season) keyed by a one-query fingerprint of the season's data — no write-side cache bumps needed; `RSL_Ratings::season_data()` is a per-request snapshot, so tests must call `RSL_Ratings::flush()` after changing results in the same request. Ratings and ladders count published nights only.
- `dev/lib-profile.php` — `rsl_dev_profile()` gives a pretend account the club profile registering needs (made-up mobile; date of birth as YYYY/MM/DD), `rsl_dev_dob()` a date of birth for a given age. Any test or dev script that registers a player must give them a profile first.
- `dev/lib-sim.php` — plays a pretend timed game rally by rally (best of three in an hour, third set to 15 or 25, score stands at time). Used by `fake-season.php`, `simulate-pairing.php` and `simulate-ratings.php` (rating model comparison — re-run before changing `RSL_Ratings` weights).
- `includes/class-rsl-admin-fixtures.php` — Social League → Fixtures: per night, make automatically / adjust / publish / enter results.
- `includes/class-rsl-public-fixtures.php` — `[rsl_fixtures]` (Fixtures & Results page), `[rsl_ladder]` (League Ladder page), and the fixtures/standing section on team pages. Published nights only.
- `includes/class-rsl-admin-courts.php` — Social League → Courts & Nights: venues & courts, season calendar grid, per-night timeline editor, copy forward.
- `includes/class-rsl-portal.php` — `[rsl_my_league]` on the auto-created Social League page: register, clearance + pay, join by code, invites, apply as captain, captain tools. Post/Redirect/Get on `template_redirect`.
- `includes/class-rsl-public.php` — `[rsl_teams]`, the auto-created League Teams page, team pages at `/league-teams/{slug}/`.
- `includes/class-rsl-admin.php` — Social League → Teams admin (admin-post handlers, Post/Redirect/Get).
- `dev/fake-season.php` — DEV ONLY: fills the current season with a pretend league (`reset` removes it). `dev/fake-signup.php` — next season's team-building window with pretend teams and ~45 free agents (run after fake-season).
- `dev/simulate-placement.php` — runs `RSL_Placement::propose()` over pretend pools; re-run before changing placement weights.
- `dev/tests/*.php` — tests, run with `wp eval-file`. They live **in the repo** (not a temp folder) so they're never lost. `test-merge.php` covers account merges (schema guard, every kind of row, refused / allowed merges and undo); its merge steps need the Club Manager and print SKIP without it. Every dev script refuses to run unless under WP-CLI and either the site isn't `production` or `RSL_DEV=1` is set; `dev/` is left out of release downloads (`.gitattributes`).

## Rules

- **PHP 7.4 and 8.x.** The site is on 7.4 now and moving to 8.x. No PHP 8-only syntax (no `str_contains`, `match`, nullsafe `?->`, named args, union types). Lint on both before every push.
- **Tables are `{$wpdb->prefix}rsl_*`** via `RSL_Database::table()`; never hardcode a prefix. Changes are additive (never rename/drop; add and backfill).
- **Anything that names an account is listed for merges.** A new table or column holding an account id goes into `RSL_Merge::columns()` in the same commit; a new option entry (or any other place outside the tables) holding one goes into `RSL_Merge::uses()`. `dev/tests/test-merge.php` reads the schema and fails on a `user_id` / `*_by` column, or any other `bigint` column not on its list of ids that aren't accounts, that isn't listed; nothing checks the places outside the tables. The wording of a refusal never weakens it: every row naming the account counts.
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
4. Run the tests, one file at a time (they share the database): `docker exec -e RSL_DEV=1 murvc_cli wp eval-file wp-content/plugins/reds-social-league/dev/tests/test-teams.php --path=/var/www/html`. Each prints OK/FAIL lines and ends with ALL PASSED or a count. (The local copy reports itself as `production`, hence `RSL_DEV=1`.)
5. Look at it: `wp eval-file .../dev/fake-season.php` fills the current season, then `.../dev/fake-signup.php` adds next season's sign-ups; `... reset` removes each.
6. Bump the version (header + `RSL_VERSION`), update the README if behaviour changed, commit, push.
