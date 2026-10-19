# Reds Social League

WordPress plugin for MURVC's social volleyball league: registration and
rego, teams and invites, courts and game nights, fixtures, results,
ratings and ladders so far; admin fixture tools, refs, team balances,
automatic pairing and finals to come (see `CLAUDE.md`).

## Team balances and payments (0.11.0)

- **Each team has a balance** (`rsl_ledger`: money in positive, charges
  negative). **Game fees ($75 incl. GST by default) are charged when a
  week's fixtures are published** — per game, so a double-header is two.
  The charges follow the published fixture: unpublish and they come off;
  add or remove a game and they follow. Teams are encouraged to pay before
  Monday.
- **Not fixtured when owing more than $75** (setting): one forgotten week
  is fine, two isn't. The fixtures engine leaves the team out ("Owes $150
  — not fixtured until paid"), records it so it counts like a week off
  (no bye points, no finals round), and the team page says so.
- **"Can't make it"** after fixtures are out: before the cut-off (48 hours
  before the night by default) neither team in that game pays; after it
  the withdrawing team pays both fees ($150) and the opponent pays nothing.
  "We can make it after all" undoes it. Admins see free/late on the night.
- **Top-ups:** any player on the team pays any amount on the team page
  (what's owing, next game, rest of the season, or other) through the
  club's WooCommerce checkout — a placeholder product (Published, catalog
  Hidden) whose price is set from the amount, **GST included** whatever the
  shop's tax setting. Each paid order item is credited once (UNIQUE
  `order_item_id`). The captain sees who has paid what.
- **Admin, Social League → Team Balances:** every team's balance (owed /
  credit totals), each team's history, credits and charges with a note
  (cash, refunds, corrections), and the rules (fee, cut-off, owing limit,
  top-up product).
- Team balance is private to the team's players and admins.

## Referees, court supervisors and the live scoreboard (0.10.0)

- **Who:** players tick *Happy to be a paid referee* when they register
  (or in My details); an admin approves them on **Social League → Refs &
  Supervisors**. Court supervisors are added there by email (one per
  venue per night: questions, first aid, keeping the night running).
- **Ref Jobs page** (auto-created): once a week's fixtures are
  published, approved refs take games one at a time — so a night can be
  ref, ref, play, ref — and supervisors take their venue. Each person
  sees **their night** as a timeline, the open jobs (with the reason when
  one isn't possible), and their pay so far and booked ahead. Checks:
  never your own team's game, never two places at once, 45 minutes to get
  between venues, a cap on games per night, a supervisor stays at their
  venue. Refs can drop a job until a cut-off before the night.
- **Pay:** flat rates (default $25 a game refereed, $30 a night
  supervised), recorded on each job when it's taken. **Pay report** for
  any dates (nights that have happened) with a CSV for the treasurer;
  admins remove no-shows on the Fixtures page, which also shows each
  game's ref and each venue's supervisor with quick *Assign*.
- **Live scoreboard** for the ref's games (on the night and two days
  after): tap a team for a point, undo, **swap sides**, sets end at 25 by
  two (third set 15 or 25), *Time's up* ends a set as it stands, *Save
  result* records it (or type it in). Every tap is kept on the phone
  first, so slow or no signal never loses a point or a reload; the
  score so far is sent quietly after each point (whole score + a
  time-based sequence, so a late update never overwrites a newer one).
  One scoreboard open at a time; it closes after saving.
- **Live on the public Fixtures page:** games in progress show **LIVE**
  with the sets so far and the current set; on game night the page
  updates itself every 15 seconds (no reload).
- Tables `rsl_officials`, `rsl_jobs`; `games.live` (schema 8).
  Classes `RSL_Officials`, `RSL_Ref_Page`, `RSL_Admin_Officials`.

## Team season schedule and night requests (0.9.0)

- **Season schedule** on every team page: each night of the season — its
  games and results once published, **TBA** ("fixtures out Mon 26 Oct")
  before, bye weeks and finals.
- **Captain requests** (buttons on the schedule; captains and admins only):
  - **Week off** — before that week's fixtures are out; free; the
    fixtures leave the team out. Not a bye: no turn-up points, no finals round.
  - **Two games** — before fixtures are out; first in line for a spare-slot
    double-header (a wider gap is accepted than for season-long opt-ins;
    never a third meeting).
  - **Can't make it** — after fixtures are out; emails the league admin
    and shows on that night in Social League → Fixtures with **Mark
    handled** (the automatic replacement offer comes later).
  One live request per team per night; week off / two games can be
  withdrawn until fixtures are out. Requests are visible only to the
  team's members and admins.
- Table `rsl_requests` (schema 6), class `RSL_Requests`.

## Fair however many games: placements, finals eligibility (0.8.1)

- **Ratings don't depend on how many teams there are** — they come only
  from games played (checked: a strong team joining changes no one's
  rating; beating two teams moves those two and others by at most a few
  points). **Ladder places do**, so teams **in placements aren't
  numbered**: they're listed under the settled teams and take their
  place after 3 games, instead of pushing everyone down after one night.
  Their rating number isn't shown either.
- **Season Points: +10 for turning up is once per night** (double-headers
  don't double it); a week the fixtures leave a team out (odd numbers)
  still earns it. Wins, close games and upsets still count per game.
- **Finals eligibility (Ranked):** a team needs 5 regular-season
  **rounds** (nights, not games — double-headers don't fast-track),
  counting weeks the fixtures left it out but not forfeits. Ladder
  column: ✓, "2 to go", or blank once it can't get there. Setting:
  option `rsl_finals_min_rounds` (filter of the same name).
- The Ranked ladder no longer has a Level column (rating and tier tell
  that story); Unranked keeps **Level**; every team page shows it.

## Fair progression: earned tiers, Season Points, hype not odds (0.8.0)

The **rating stays honest** (it orders the Ranked ladder and makes the
fixtures, and it can go down). What a team **earns** is designed to feel
fair, the way ranked modes in games work:

- **Earned tiers (middle ground):** promotion is instant; demotion only
  after **2 rounds in a row more than 50 points below** the tier's floor
  (the first such round shows "At risk"), and one tier at a time; a team
  that reaches **Silver keeps at least Silver** all season (and never
  shows at risk there). Team pages show a progress bar to the next tier.
  Rules in `RSL_Stats::step_tier()`; numbers are constants.
- **Season Points and levels (both divisions — Unranked teams get
  progression too):** +10 per game played, +5 win, +3 draw, +3 close game
  (third set, 1–1, or within 6 points overall) win or lose, +5 upset win,
  +10 per achievement. Only ever go up; every 40 points is a level. Level
  on the ladder, level + progress bar on the team page.
- **Close one** tag on close results; **Promoted** achievement.
- **Season leaders** on the ladder: Most improved, Giant killers, top
  Season Points per division.
- **Hype, not odds, on coming games:** Top-of-table clash, Even match,
  Rematch (with last time's result), First meeting, win streak on the
  line. No public "30% chance" before a game; **Upset!** still celebrates
  it afterwards. Set chances stay on the admin page
  (`RSL_Public_Fixtures::SHOW_SET_CHANCES` turns them back on publicly).

## Timed-game ratings, set chances and game-style pages (0.7.0)

**Ratings for timed games.** Games are best of three inside an hour: a set
can end when time's up (the score as it stands counts as a set win), a
third set may go to 15 or just run to time, and a game can end 1–1 (a
draw on the record). Ratings now use the **points in every set** (each
rally a small contest, discounted because rallies aren't independent)
plus a **small bonus for winning sets**, with sets counted in proportion
to how complete they were. A 1–1 draw where one team won far more points
still moves the ratings. `dev/simulate-ratings.php` plays timed seasons
rally by rally: points + set bonus finds the true order faster than sets
alone (after 2 rounds about as well as sets alone after 4) and is closer
at season's end (48 vs 53 rating points off). Uncertainty is calibrated
from the same simulations. A team is in placements (provisional) for its
first 3 games — a clear rule rather than a hidden uncertainty threshold
(which left 9 of 14 teams in placements after round 2 for reasons
players couldn't see); the uncertainty only keeps a team in placements
after that if its rating is unusually unsettled.

**Set chance, not expected score.** Closeness is each team's chance of
winning any one set (independent of how many sets fit in the hour). The
admin Fixtures page shows it as "62% · 38%"; costs: 50/50 = 0, 67/33 = 1,
75/25 = 2.25, 80/20 = 3.2. Compared in simulation with ladder places
apart, raw rating points and standard deviations apart — all similar in
an evenly spread league; win chance edges ahead in a lumpy one (a few
strong teams, a bunched middle).

**Game-style public pages** (`RSL_Stats`, teams only):
- **Tiers** (Ranked): Bronze < 1350 ≤ Silver < 1500 ≤ Gold < 1650 ≤
  Platinum < 1800 ≤ Diamond. New teams are **In placements** until their
  rating settles — no tier or rating change shown (which also keeps the
  hidden starting estimate hidden). Names/bands in `RSL_Stats::TIERS`.
- **Fixtures:** tier and ladder place under each Ranked team; the night's
  rating change on results; an **Upset!** flag when the winner had under
  a 35% set chance; for coming games a match-up bar in the teams' colours
  with each side's set chance and a verdict (Even match / Close — slight
  edge / Upset alert).
- **Ladder:** tier and last-5 form for each team.
- **Team page:** a profile card under the name — tier (and peak), form,
  streak, journey (e.g. In placements → Silver R2 → Gold R4) and
  achievements: Giant killer, Comeback, Flawless, On fire (3 wins in a
  row), Double trouble (won both games of a double-header), Top spot.
  Each result shows the rating change and any upset win.

## Automatic fixtures and the Fixtures admin (0.6.0)

Social League → **Fixtures**: one game night at a time — *Make fixtures
automatically*, check them (each game shows each team's set chance, earlier
meetings, double-headers and whether time preferences were met), adjust
by hand (remove / add a game in a free slot), **Publish**, then enter
results or forfeits. Published nights are locked; a night with results
can't be remade.

How the engine (`RSL_Pairing`) pairs a night — every possible pairing
gets a cost in one unit, "a 2-to-1 matchup", and the cheapest full set
of games wins (exact matching up to 18 teams, a strong search above):

- **Closeness (Ranked):** from the two ratings, each team's chance of
  winning a set — 50/50 costs 0, 67/33 costs 1, 75/25 costs 2.25,
  80/20 costs 3.2. New teams' ratings count as uncertain (pulled towards 50/50).
- **Variety:** a 2nd meeting costs 1 (the same as a 67/33 game); a 3rd costs 25,
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

(Expected set gap = how one-sided games were by true strength, over a 3-set game; lower is closer.)

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
