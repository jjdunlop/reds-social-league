# Reds Social League

WordPress plugin for MURVC's social volleyball league: registration and
rego, teams and invites, courts and game nights, fixtures, results,
ratings and ladders so far; admin fixture tools, refs, team balances,
automatic pairing and finals to come (see `CLAUDE.md`).

## Automatic fixtures and the Fixtures admin (0.6.0)

Social League → **Fixtures**: one game night at a time — *Make fixtures
automatically*, check them (each game shows its expected score, earlier
meetings, double-headers and whether time preferences were met), adjust
by hand (remove / add a game in a free slot), **Publish**, then enter
results or forfeits. Published nights are locked; a night with results
can't be remade.

How the engine (`RSL_Pairing`) pairs a night — every possible pairing
gets a cost in one currency, "expected one-sided sets", and the cheapest
full set of games wins (exact matching up to 18 teams, a strong search above):

- **Closeness (Ranked):** (expected set margin)² from the two ratings —
  an even game costs 0, an expected 2–1 costs 1, an expected 2.5–0.5
  costs 4. New teams' ratings count as uncertain (pulled towards 50/50).
- **Variety:** a 2nd meeting costs 1 (an expected 2–1); a 3rd costs 25,
  so twice a season is the cap unless there's no alternative. Last
  week's opponent never; two rounds ago +1.
- **Unranked:** no ratings, so purely least-played, longest-ago.
- **Odd numbers:** the spare team plays a double-header team, or sits out
  (dearer each time for the same team).
- **Slots:** the night fills from the first slot (no idle middle courts);
  "earlier"/"later" means first/last games of that night, missed
  preferences get priority next time, the last slot is shared around,
  and spare slots become double-headers for opted-in teams (back-to-back
  or with a break; never a third meeting or a lopsided game).
- Weights are in `RSL_Pairing::weights()` (filter `rsl_pairing_weights`).

`dev/simulate-pairing.php` plays many pretend seasons through the real
code to compare strategies (14 teams, 10 rounds, 40 seasons each):

| Strategy | Expected set gap | 2–1 games | Opponents per team | 3rd+ meetings / season | Most meetings |
|---|---|---|---|---|---|
| **Engine** | 1.00 | 62% | 7.5 | 0 | 2 |
| Closeness only | 0.84 | 66% | 5.2 | 14.5 | 5 |
| Within 3 ladder places | 0.94 | 65% | 7.1 | 4.2 | 4 |
| Round robin | 1.36 | 54% | 10.0 | 0 | 1 |

## Fixtures, results, ratings and ladders (0.5.0)

- **Games** fill the game-night slots from Courts & Nights: two teams of
  the division that court hosts that night. Checks: no games on a bye,
  real slots only, one game per court at a time, no team in two places at
  once (double-headers later in the night are fine). Removing a night from
  the season removes its games.
- **Results** are set scores, home first (`25-20, 23-25, 15-12`). Timed
  games mean sets can end short, so the only rules are whole numbers, no
  tied sets, 1–5 sets. A **forfeit** records who gave the game away: a
  win/loss on the record, never a rating change.
- **Publishing** is per night — nothing shows publicly until that week's
  fixtures are released.
- **Ratings (Ranked)** — Bradley-Terry on set results, refitted from the
  whole season whenever read (`RSL_Ratings`), on an Elo-style scale. Each
  team starts from a prior on its level estimate, recent sets weigh a
  little more, and each team has an uncertainty: provisional until it
  settles (about three games). A team with no games yet shows as New
  with no number, so its hidden starting estimate is never revealed.
- **Public pages** (teams, never people):
  - **Fixtures & Results** — week strip (rounds, bye, finals), each
    week by venue and time: court, teams, score and sets, "Result to
    come", or when fixtures come out. A signed-in player's games are
    highlighted.
  - **League Ladder** — Ranked by rating (with record, sets and places
    moved since last week); Unranked by wins then set difference, no
    ratings.
  - **Team pages** — standing (rating and place, or ladder place) and the
    team's fixtures and results.
- Fixtures and results are managed on the Fixtures admin page (0.6.0).

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
