# Reds Social League

WordPress plugin for MURVC's social volleyball league: teams and rosters
now; registration, payments, fixtures, results, ladders and ratings to
come, in three phases (see `CLAUDE.md`).

## Team level and captain preferences (0.4.0)

- **Approximate level** — a captain applying for a team picks roughly
  how the team plays: New, Social, Solid, Strong (VVL State League and
  friends) or Advanced (VVL Premier League). It's an admin-only estimate,
  never shown on public pages or to players, and admins can correct it on
  the team page. Later it seeds each team's first Ranked rating
  (`RSL_Teams::level_seed_rating()`: 1200–1800, average 1500) so week-one
  games are even; real results take over from there.
- **Fixturing preferences** — on the Social League page the captain sets,
  for the season: earlier / later / no preference, happy to double-header
  (and if so back-to-back or with a break), and standby for late games
  when another team pulls out. Admins see a one-line summary on the team
  page. Automatic fixturing will read these each week; they're wishes,
  not guarantees.

## Courts and game nights (0.3.0)

Social League → **Courts & Nights** — what fixtures will be made from.

- **Venues & courts** — each venue has a home division (e.g. Ranked at
  one venue, Unranked at the other) and its courts, each with its usual
  game-night hours (a court can finish earlier than the rest).
- **Season calendar** — set the first and last game night, the weekday
  (Sundays), and the **game length for Ranked and for Unranked**. Saving
  lists every game night; re-saving keeps nights already set up and drops
  any outside new dates.
- **The grid** — one row per night, one column per venue: courts, hours,
  how many games and for which division, with byes greyed, finals marked
  and nights that differ from the usual flagged. Each night is Regular,
  Finals or Bye, with an optional note (e.g. a public holiday).
- **One night** — click it for a timeline: a bar per court across the
  evening, divided into game slots. Turn a court off, change its hours, or
  have a venue host the other division — for that night only — then
  optionally copy the setup to every later night. Setting a court back to
  its usual hours removes the exception.

The game slots (court, start, end, division) are what fixtures will fill
and refs will claim.

## Registration and team building (0.2.0)

A **Social League** page is created automatically (`[rsl_my_league]`).

- **Register** — free, a short profile: highest level played, years
  playing, positions, and an optional free agent flag.
- **Cleared to play** — needed before a player's first game, not to join
  a team: a **full or life club member** (read live from the MURVC Club
  Manager) is covered; anyone else needs a **social rego** — 12 months
  from payment, bought through the rego product (set on Social League →
  Registration) or added by an admin. Renewing early starts when the
  current rego ends. A membership that lapses stops covering them the day
  it ends. Past rego purchases (13 months) can be picked up in one click.
- **Captain path** — a registered player applies for a team (name,
  division, colour) and becomes its captain. It's pending — not public, no
  players — until an admin approves (the captain is emailed the invite
  link) or declines with a reason (also emailed). Captains can withdraw.
- **Player path** — join with the captain's **invite link or code**
  (straight onto the team; registering through the link joins too), or
  accept an **email invite** from a captain. A brand-new player who opens
  the link logged out can create a club account first: the invite is
  remembered (a 30-day cookie holding just the code, copied onto the new
  account so it survives switching devices), they're brought back to the
  league page after sign-up — never pulled away from account or profile
  pages, so the profile-update step can finish — and offered "Register and
  join". It's forgotten once they join, or if the captain makes a new link. Email invites need the
  player to accept; until then the captain sees only the email they typed.
- **Captain tools** — roster with ✓ cleared / ✗ needs rego (never *why* —
  membership or rego is personal; admins see it), copy invite link, make a
  new link, invite by email, cancel invites, remove players.
- One team per person per season; a pending application counts.

## Teams and rosters (0.1.0)

- **Seasons** — four a year (Pre-season, Mid-season 1, Mid-season 2,
  Post-season). An admin picks the current season; public pages show it.
- **Teams** — Social League → Teams: add a team (name, Ranked or Unranked
  division, colour), rename it, change division or colour. Each team gets
  a permanent public link from its name.
- **Rosters** — add players by account email or username, choose a
  captain (one per team), remove players (kept in history). One team per
  person per season.
- **Public pages** — a **League Teams** page is created automatically:
  teams grouped by division, each with a badge (initials on the team
  colour) and size. Each team has its own page at `/league-teams/{team}/`.
  Public pages never show player names; a team's roster is visible only
  to its members and admins.

## Development

Local Docker copy of the site, WP-CLI test scripts in `dev/tests/`, and a
pretend league from `dev/fake-season.php`. See `CLAUDE.md`.

Requires WordPress 6.0+ and PHP 7.4+ (tested on 7.4 and 8.3).
