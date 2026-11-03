# Reds Social League

WordPress plugin for MURVC's social volleyball league: registration and
rego, teams and invites, free agents and placement, courts and game
nights, automatic fixtures, results, ratings and ladders, referees and a
live scoreboard, and team balances. Still to come: the sub list and
finals (see `CLAUDE.md`).

## Duplicate accounts: an account in use in the league isn't merged away (0.14.0)

- **The Club Manager's merge tool (Duplicate accounts) refuses to merge
  away an account that's in use in the league.** The preview lists what
  it's in (registered as a player, on a team or the free agent list in a
  season, an invite or a request to join, a social rego, the referees and
  court supervisors list or a job, a team balance line or top-up order,
  league email, the placement draft, or something it did as a captain, ref
  or admin) and says to keep that account instead and merge the other one
  into it.
- **The account kept can be in use**: its league records stay exactly as
  they are. When both accounts are in use, the preview says so and that
  they can't be merged.
- **A merge never changes anything in the league**: nothing moves, and
  nothing happens on Undo. The same check runs again inside the merge, so
  something that changed since the preview still stops it, and a check
  that fails stops the merge rather than letting it through.
- Dev: `RSL_Merge::columns()` is the one list of columns that name an
  account; `dev/tests/test-merge.php` tries a row in each, and fails if a
  `user_id` / `*_by` column in the schema isn't on the list.

## Club profile first, and ages from it (0.13.0)

- **Registering needs a complete club profile**, the same details as a VVL
  trial application: first and last name, mobile, date of birth and gender
  (`RSL_Players::profile_fields()`). The league reads them from the
  player's account and never keeps a copy. The register page shows them
  back ("Your details — from your club profile") with an *Edit your
  profile* link; if any are missing (or the date of birth isn't a real
  date) it lists what's missing and links to the profile instead of
  showing the form, and `RSL_Players::register()` refuses a new
  registration without them. An invite link is remembered while they fill
  it in. Updating your experience answers later doesn't ask again.
- **Nobody is asked whether they're under 18.** It comes from the date of
  birth (`RSL_Players::is_under18()`), judged on the earlier of today and
  the season's first night: 17 while captains are browsing counts, and so
  does 17 on the first night, for the whole season. An account with no
  date of birth counts as under 18 (the safe side). Under-18s are still
  left out of the list captains browse (unless an admin allows it), and
  admins see each free agent's age (or *No date of birth*).
- The free agent row's `under18` column is now only an admin's **Treat as
  under 18** tick (Free Agents, for an account whose date of birth is
  wrong); players can't set it, and it adds to the date of birth rather
  than replacing it (`RSL_Free_Agents::under18_for()`). Ticks saved by
  players before 0.13.0 count as admin ticks.
- Dev: `dev/lib-profile.php` gives pretend accounts a profile
  (`rsl_dev_profile()`, `rsl_dev_dob()`); the tests and both fake-league
  scripts use it.

## Players without a team: free agents and placement (0.12.0)

- **Sign-up season.** `RSL_Seasons::signup()` is the season people join,
  apply for and go on the free agent list for. It's the current season
  until next season's first night is set and its team-building window has
  opened — then it's next season, while this one finishes. An admin can pin
  it. The league page shows "This season" and "Next season" while both run.
- **Four routes at registration:** join a team I know (invite link or code),
  start a team (captain application), **find me a team** (the free agent
  list), or not yet.
- **The free agent list** (`rsl_free_agents`, one row per person per
  season): division (Unranked by default for beginners; Ranked or Either is
  the player's consent to Ranked), earlier/later games, weeks away, a friend
  group (up to 3, joined with a shareable code — never looked up by email),
  happy to captain, under 18 (admin-only; from 0.13.0 worked out from the
  date of birth), let captains find me.
- **The team-building window** (settings, counted back from night 1): the
  list opens 21 days before; until the deadline (10 days before) captains
  with open spots ("Looking for players?") can browse the list and invite
  (the player accepts), and free agents can ask up to 3 teams (only that
  team's captain can say yes; requests expire after 3 days and never past
  the deadline; "not this time" is final for that team that season).
- **What captains see:** first name, level, positions and "+N friends" —
  built in one place (`RSL_Free_Agents::browse_for_captain()`), never
  emails, surnames, ages, the under-18 box, weeks away or bands. Under-18s
  are left out of browsing (unless an admin turns that on), though an
  under-18 can still ask a team themselves. No free text from players. When someone joins through the league (pool invite, request,
  placement, admin), the player and their captain are emailed each other's
  name and email; rosters still show names only.
- **Placement** (`RSL_Placement`, pure core like the pairing engine): after
  the deadline it fills short teams first (under 7), tops up teams that
  asked (to 8), then forms new teams of about 8 from the rest — grouped by
  level and game time, friend groups never split, a volunteer captain in
  each, Unranked-only players never in Ranked, nobody over 10. The admin
  reviews it on **Social League → Free Agents → Placement**, moves anyone
  (whole groups move together; a stale tab is refused), names new teams,
  and confirms (safe to run twice). Anyone not placed is told why, charged
  nothing, and first in line. Placed players get 3 days to say "Not for me".
- **One team per person per season** holds on every route: joining runs
  under a per-person database lock; the roster maximum (10) applies to
  every route except an admin's, under a per-team lock so two people
  joining at once can't push a team over it. Joining by any route marks the free agent
  placed and closes their other requests and invites.
- **Email queue** (`rsl_outbox`, `RSL_Mail`): free-agent emails are queued
  with a dedupe key and sent in batches of 25 by cron (`rsl_hourly`,
  `rsl_send_outbox`); sent mail is deleted after 30 days. The Free Agents
  screen shows queued/failed mail and can send now or retry.
- **Money:** registering, the list and being placed are free; the rego is
  needed before a first game. Team cards offer "Pay my share": each
  player's fair part of what the rest of the season still needs, less what
  they have already topped up, so it comes off as they pay and together the
  shares cover the season.

### Review fixes (0.12.0)

A five-lens review (scalability, security, privacy, correctness,
robustness) with skeptical verification of every finding kept 46 issues;
they are fixed (or found not to be real) and each fix has a test. What
changed for people:

- **Money.** `RSL_Payments::sync_night()` is the one place game fees and
  late fees are worked out, under a per-night lock, and every row carries a
  unique charge key — two overlapping publishes can't double-charge. **Late
  fees are per game** (a late double-header withdrawal is $300). Only the
  first team out of a game pays: pulling out of a game the other team had
  already left costs nothing. A game that
  was played after a "can't make it" is charged normally to both teams; a
  forfeit by the withdrawing team never charges its opponent. A captain
  can't withdraw a "can't make it" once the night has started or a result is
  in; admins can (also for handled ones). Publishing refuses while a team
  with a week off still has a game in the draft; a night with results, or
  one that has already started, can't be unpublished (an admin can still
  unpublish a played night as called off, which takes its fees off).
- **Refunds.** WooCommerce refunds and cancelled orders reverse top-ups
  (capped at what was credited) and end the rego bought in that order; a
  cancelled order set back to Processing or Completed puts the top-up back
  (less any refunds) and gives the rego back (not one an admin removed);
  admins can remove a rego by hand. The rego is sold one at a time.
- **Seasons.** Admins carry a team's balance to next season (paired rows);
  earlier seasons' non-zero balances stay listed until cleared. A "League
  contact email(s)" setting (Team Balances) gets the league's notices.
- **Calendar.** Re-saving season dates only removes empty nights; a night
  with fixtures, results, ref jobs, "can't make it" requests or charges is
  kept and marked until it's cleared on the Fixtures page; week-off and
  two-games requests on a removed night go with it (the save notice names
  those teams). Making a night a Bye also withdraws any leftover "can't
  make it". A night with games can't be made a Bye or Finals. Both ask
  before changing a published night.
- **Refs.** Refs can't take a game once it has finished (supervisors: a
  venue once its last game ends) — admins assign those. A result or forfeit
  the league enters is locked to the ref, who can correct only their own
  save. Removing a ref or supervisor releases their jobs still to come; a
  venue's supervisor goes with its last game that night, but remaking a
  night's fixtures keeps the supervisor of a venue that still has games. The
  pay report CSV is safe to open in a spreadsheet. Scoreboard Undo survives a
  reload.
- **Public pages.** The season's stats are cached between views (keyed by a
  one-query fingerprint of the data, so they're never stale), "hype" no
  longer runs a query per game, live scores are polled only during the
  night's games (from 15 minutes before to 30 after, slower when nothing
  changes, stopping when every result is in). No "Even match", upset or
  giant-killer percentage while a team is in placements (it would leak the
  hidden starting level). Ratings and ladders count published nights only.
  "Can't make it" shows "Free until …" or the charge before confirming.
- **Teams.** Last season's invite links stop working; an email invite gets
  the same answer whether or not the email is registered (unregistered
  emails wait for the person to register); captains get 20 email invites a
  day; withdrawn and declined applications free the team name; players can
  leave a team; admins can use the captain buttons.
- **Housekeeping.** Options read on every request are autoloaded; only one
  request runs a schema upgrade at a time; timestamps are written in the
  site's time; the dev scripts and tests refuse to run on a production site
  unless `RSL_DEV=1` is set, and are left out of release downloads
  (`.gitattributes`).

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
  with the sets so far and the current set; during the night's games the
  page updates itself about every 20 seconds (no reload) — see 0.12.0.
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
  lists every game night; re-saving keeps nights already set up and
  removes empty nights outside the new dates (a night already in use is
  kept and marked — see 0.12.0).
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
  playing, positions, and an optional free agent flag (from 0.13.0, only
  with a complete club profile).
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
