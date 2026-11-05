# Reds Social League

WordPress plugin for MURVC's social volleyball league: registration and
rego, teams and invites, free agents and placement, courts and game
nights, automatic fixtures, results, ratings and ladders, referees and a
live scoreboard, and team balances. Still to come: the sub list and
finals (see `CLAUDE.md`).

## Places in each division and a waiting list (0.19.0)

- **The rule** (the club's, 5 Nov 2026). A division takes at most as many
  teams as lets every team sit out at most 2 nights a season, with every
  team there every night. With n teams and S game slots on a night, n − 2S
  teams sit out that night when that's above 0, and 0.18.2 shares those
  sit-outs out. So a division's places are the largest n where the sum,
  over the season's regular nights where the division has a slot, of
  max(0, n − 2S) is at most 2 × n. The allowance is the same for every
  season length. A team that comes in mid-season accepts it might not
  make finals.
- **The slots are each night's own** (`RSL_Courts::season_slot_counts()`,
  built by the same code as `slots_for_night()`), so a court off or a venue
  given to the other division on one night counts. Bye and finals nights
  don't count. A night where the division has no slot isn't a sit-out,
  as 0.18.2 counts only nights its division had games.
- **Places for 12 game slots a night** (every 2027 season has 12 slots a
  division on every regular night):

  | Regular nights | Allowance 0 | Allowance 1 | Allowance 2 |
  |---|---|---|---|
  | 8 (2027 Pre-season) | 24 | 27 | 32 |
  | 9 (Mid-season 1) | 24 | 27 | 30 |
  | 10 (Mid-season 2) | 24 | 26 | 30 |
  | 11 (Post-season) | 24 | 26 | 29 |

  Allowance 0 is twice the fewest slots on a night, so no team sits out.
  That is what placement used before, from the first night alone.
- **The setting.** Free Agents → Settings → *Places in each division*:
  *Most nights a team sits out in a season* (`sit_out_nights`, default 2,
  0 to 10). *Most teams per division* (`capacity_override`) moved there
  from *Placement*; above 0 it still wins over the worked-out places.
- **Edges.** A division with no slot on any regular night has 0 places,
  and the words say it has no game slots rather than that it's full. A
  season with no regular nights yet isn't checked, and the admin words
  stay as before: "This season has no game nights yet, so court space
  isn't checked." Places stop at a ceiling: 50, the most the override
  takes, or twice the fewest slots on a night when that's more. An
  allowance at or above the number of nights with a slot never binds, so
  it gives the ceiling. A large result is cut to it too: 12 slots on 3
  nights at allowance 2 would be 72, so it's 50. The second part of the
  ceiling keeps two things true for any courts: allowance 0 is always
  twice the fewest slots (30 slots a night gives 60), and a bigger
  allowance never gives fewer places. When the ceiling sets the places,
  no word blames the allowance: the admin line says "(the most a division
  takes)", placement's warning says "50 is the most places a division
  takes.", and the free agent's reason leaves the allowance out. None of
  this shows with the 2027 courts (29 to 32 places at allowance 2).
- **Taken places and the waiting list.** Active teams take places. The
  pending applications in a season and division are lined up oldest first
  (applied time, then id). The first ones up to the places left are
  ordinary applications; the rest are on the waiting list. With 28 active
  teams for 30 places and 4 applications, the two oldest are ordinary and
  the two newest wait. Nothing is stored (no schema change): the list is
  worked out on every read, so approving, declining, withdrawing and a
  team leaving move it. An admin change can also put an ordinary
  application on the waiting list: a team added or approved ahead of it,
  an older application moved into its division, or new teams from
  placement. Each says so (below).
- **Where it applies.** Placement (no new team past the places left, as
  before with the old number), Start a team (at registration and on the
  league page's application card), applying, the captain's application
  card, and the Teams screen: approving, *Add a team* and a division
  change. Placement confirm says when the new teams it made put a division
  over.
- **What a captain sees.** Only a logged-in player at the form. For each
  season offered where a division's teams and applications fill its
  places, beside the division: "Ranked is full for 2027 Pre-season. An
  Unranked team can still get a place; a new Ranked team goes on the
  waiting list." With both full: "Ranked and Unranked are both full for
  2027 Pre-season. A new team goes on the waiting list." Applying still
  works. After applying, and on their application card while it's on the
  waiting list: "Ranked is full for 2027 Pre-season, so your application
  is on the waiting list. If a place opens and the league approves it,
  you'll get the approval email with your team's invite link." A flash for
  a later season names it once, in brackets after the team ("Ranked is
  full, so your application is on the waiting list."). A division with no
  game slots all season says "Unranked has no game slots for 2027
  Post-season" in both places, and the card says the approval email comes
  if the league adds Unranked courts and then approves it. The card
  never names another team and gives no place in the queue: a place
  would tell the captain how many other applications there are, which no
  page shows anyone else, and the league doesn't have to approve in that
  order.
- **What an admin sees.** Above *Team applications*, a line for each season
  and division with applications: "Ranked, 2027 Mid-season 2: 28 of 30
  places taken, 4 applications waiting (2 on the waiting list)." Those two
  are labelled *On the waiting list*. A note in brackets says when the
  places didn't come from the allowance: "(set by hand)", "(no game slots
  this season)" or "(the most a division takes)". The sentence under the
  lines describes only what they show: the allowance when a line's places
  were worked out, Most teams per division when one was set by hand, and
  nothing when no line is checked. On each application whose approval
  would go over the places, from the nights still to come: "Approving makes
  31 teams for 30 places: about 2.3 nights each sat out this season, so
  some sit out 3." Its button reads *Approve over the cap*, and approving
  needs no extra step. The notice after it gives the numbers again. Once
  the season has started the words say "in the 6 nights left". The average
  is to one decimal and the most is the average rounded up, since 0.18.2
  keeps teams there every night within one sit-out of each other. "About",
  because teams away and odd numbers move it. *Add a team*, a division
  change and placement confirm are allowed over the places too, with the
  same numbers in the notice. An application moved into a full division
  says "Its application is on the waiting list in Ranked." When a change
  puts an ordinary application on the waiting list, the notice adds "1
  application in Ranked, 2027 Pre-season is now on the waiting list." ("1
  other application" when the change was about an application itself,
  such as approving one ahead of older ones). It never names the team.
- **Placement's words.** The warning before confirming: "Ranked would have
  31 teams for 30 places: about 2.3 nights each sat out this season, so
  some sit out 3. 30 is the most it takes with no team sitting out more
  than 2 nights a season." (or "30 is the Most teams per division
  setting.", or "50 is the most places a division takes."). Before, it set
  the count against the first night's slots alone. A free agent not placed
  for want of a place is told: "8 Ranked players left — no place for
  another Ranked team without teams sitting out more than 2 nights a
  season; Ranked teams full". Neither names the allowance when the
  override or the ceiling set the places, or the division has no slot on
  any night; there the reason keeps 0.18.2's "no court space for another
  Unranked team". Placement's room is the places less the active teams,
  as before, so new teams can take places that applications waiting for
  approval are next in line for. The warning before confirming says so:
  "Confirming fills places that 2 Ranked applications waiting for
  approval are next in line for, so they go on the waiting list." The
  confirm notice then says "2 applications in Ranked, 2027 Pre-season are
  now on the waiting list."
- **Before 0.19.0** only placement looked at courts, as 2 × the game slots
  of the season's first regular night, so no sit-outs at all and blind to
  a later night with fewer courts. Applying and approving checked nothing.
- **Public pages:** none change. The season preview still counts active
  teams only, and a logged-out visitor or a cached page never sees whether
  a division is full. No admin email goes out about a new application, so
  there's none to change.
- **Code.** `RSL_Capacity` (new). Pure: `places()`, `places_detail()`
  (with `clamped`), `ceiling()`, `sit_outs()`, `sit_out_numbers()`,
  `sit_out_words()` (the one writer of the numbers), `over_words()`,
  `over_line()`, `queue()`, `bumped()`, `bumped_words()`, `would_bump()`,
  `places_line()`, `full_words()`, `waiting_words()`. The one wrapper is
  `season()` (`seasons()` for several at once, as the Start a team form
  and the Teams screen need), with `on_waiting_list()`, `waiting_note()`,
  `over_notice()` and `allowance()` over it; nothing works out places
  another way. `RSL_Courts::season_slot_counts()` and
  `seasons_slot_counts()` (the venues and courts read once for several
  seasons) share `plans_for()` with `night_plan()`. `RSL_Teams::apply()`
  returns the application with `waiting_list` (`with_waiting_list()`); the
  flashes read it, so only an application on the waiting list reads the
  places again for its words. `RSL_Placement::capacity()` reads `season()`
  and passes `allowance` and `no_slots` to `propose()` for the reason's
  words; `check()` warns from `would_bump()`; `confirm()` returns `new_in`
  (division => new teams made), and the Free Agents notice compares the
  places before and after (`bumped()`).
- **Tests** (`test-capacity.php`): `places_detail()` against brute force on
  3,000 random seasons at allowances 0 to 4 (15,000 checks: nights with 0
  slots, the ceiling, clamped), the table above, the numbers and their
  words, the queue (a tie on applied time goes by id), the bump helpers,
  the admin line and the captain's words;
  `season_slot_counts()` against `slots_for_night()`; applying into a full
  division (the flash, the card, at registration too), the form's words for
  one division full and both full, nothing on the season preview or for a
  logged-out visitor, the admin line and labels, a withdrawal and a decline
  moving the queue, approving over the cap (the row's words, the button,
  the notice), *Add a team* and a division change over the cap, an
  application moved into a full division, placement's capacity and
  warning, a season with no nights, a season that has started, the
  override, the allowance setting (saves, clamps at 0 and 10, moves the
  places), the ceiling (clamped, no allowance named), a division with no
  slot on any night (0 places, no allowance named, its own words on the
  form), what `RSL_Teams::apply()` returns and a second submit, a later
  season named once in the flashes, the success notice after approving
  over the cap, and the Teams screen's counts over the test's own rows
  only. A fourth season runs placement: an older application moved into a
  division bumping a newer one, the warning that new teams take places
  applications are in line for, *Add a team* bumping one, and a confirm
  over the places (the notice's numbers and the bump).
  `test-placement.php` checks the free agent's new reasons.

## Fair sit-outs when courts are short (0.18.2)

- **Sitting out goes by a count of nights.** When a division has more
  teams than the night's games can hold (an odd number, or not enough
  courts), the teams that sit out are the ones with the lowest sit-out
  count. Each night a team sat out counts 1. A night with a double-header
  counts as one night played. Before, the court trim dropped the dearest
  games by the draw's cost and never looked at who had sat out, so the
  teams hardest to match (the top and bottom of Ranked, teams with many
  rematches) could sit out night after night.
- **Nights away count their share.** Each night a team was away (a week
  off, a night it said it couldn't make, a night the automatic draw left
  it out for owing money, a night before it joined, or a night whose
  fixtures were made before it was approved) adds that night's share of
  sit-outs in its division: the teams that sat out divided by the teams
  available. If 3 of the 12 teams there sat out, the team away gets 0.25.
  So the count measures sit-outs per night a team was there, as if it had
  sat out its share of the nights it missed. A night with no sit-outs adds
  nothing, so a team there every night has a whole count: the nights it
  sat out. In the first 0.18.2 build (9aa99da) the count was nights sat
  out only, so a team that joined late or came back from time away was
  first in line: in a season short of courts, a team joining on night 6
  sat out its first night in 37% of seasons, against 21% for the teams
  there every night that same night, and over its nights it sat out more
  than its share (simulated below).
- **Counts within 0.3 of a night are level.** A team sits out only when
  its count is at most 0.3 above every team in its division that plays
  (weight `sit_level`). Teams there every night have whole counts, a
  night apart, so none of them sits out while one of them that has sat
  out fewer nights plays, and their counts stay at most one apart. Among
  level teams the draw's cost decides, and that cost tends to keep a team
  new to the league or back from time away playing (it has had fewer
  rematches, which the cost avoids). So the wider the level, the further
  such a team can drift under its share: up to the level itself. Compared
  exactly, a count a fraction under a whole number puts the team out
  first every time, and it can end up over its share. 0.3 was chosen on
  the same nights (the simulation below): across 28 shapes of season, a
  late joiner's or a team with nights away's sit-outs came closest to its
  share at 0.3 (0.12 of a night a season off on average, 0.34 at worst),
  against 0.19 at 0.5 (the first build of this rule), 0.15 compared
  exactly and 0.17 with nights sat out only, and the night's ordinary
  games were as close as with nights sat out only. 0.5 is the most
  allowed: at 1, whole counts would be level and teams there every night
  could drift two nights apart.
- **No twice in a row while a team has never sat out.** A team that sat
  out its last night (the last night it was there) doesn't sit out again
  while a team in its division that has never sat out plays. A team that
  has never sat out can still have a count level with or above one that
  has, from its shares of nights away, so this is a rule of its own (with
  nights sat out only it followed from the count): among the choices the
  count allows, the draw takes one where no such team sits out, or one
  where every team that has never sat out sits out too. When the count
  leaves no such choice, the teams that have never sat out go first, and
  the reason says so. Without this rule the tight run below had 153 such
  nights in 1000 seasons (249 with counts within 0.5 level); with it,
  none, and the count never had to give way (no team was put first).
- **Ties go by cost.** Among level teams, the draw's own cost decides: the
  cheapest games for the teams that play, link term included. For who
  sits out, the link term only decides between level teams; it never
  puts a team out ahead of one whose count is more than 0.3 lower. It
  still shapes the games of the teams that play.
- **Odd nights.** With a court to spare, the odd team plays a team that
  opted into double-headers when one qualifies (the fill game, with the
  same checks as before: never a third meeting, last week's opponent, or
  a game more lopsided than about 83/17), and then nobody sits out. When
  the team with the lowest count has no such game but another team has,
  that other team is the odd team. The exception: when every way to give
  a fill game would need a third meeting or last week's opponent in
  another game, a team level with the lowest count sits out instead
  (weight `odd_sit`, 20: above any normal game, below those two). Among
  level teams a double-header team costs 8 more to be the odd one out
  (weight `odd_double`: the old +2 on its count, at 4 a count), so it is
  the last picked unless that saves more than 8 in the night's games.
- **Fill games are shared.** The fill partner is the double-header team
  with the lowest game cost plus 0.5 for each double-header night it has
  had, as spare-slot double-headers are shared. Before, it was always the
  closest double-header team.
- **Nobody plays twice at once.** When all of a division's slots that
  night overlap (one start time), there is no fill game and no spare-slot
  double-header (`can_play_twice()`). Before, the draw could give one, the
  second game then clashed and wasn't made, and a team was left with no
  game and no reason.
- **Double-headers stay an extra.** Spare courts still go to the teams
  that opted in, fairest first, as before. They are never handed out to
  make up for a sit-out, and the count never looks at them. They do add
  rematches, so on a tie a team that has had them is a little more likely
  to be the one that sits out (simulated below: 0.51 nights a season for
  a double-header team against 0.46 for the others).
- **What counts** (`RSL_Pairing::sat_out_counts()`, `context()`'s
  `byes`): only nights the team's division at the time had games. A
  team's division on a night is the one of its last game on or before it,
  else of its first game after it, else its division now; so a night
  before a team joined is judged by the division of its first game, else
  its division now. On such a night the team played (a game in any
  division), sat out (in the league, no game, not away) or was away. A
  team counts as in the league from its approval (or, for a team an admin
  made, its creation), and a night whose fixtures were made before then
  (its earliest game) is a night before it joined, though the night came
  after: the draw couldn't have had it. A remake after the approval
  counts the night as usual. Fixed: a team moved from Unranked to Ranked
  had the nights it played in Unranked counted as sat out, and a night
  its old division had no courts too.
- **Called-off nights don't count.** An earlier night that was never
  published and has started was called off (or never happened). Its draft
  games add no sit-outs, shares, meetings, home games or double-headers.
  Before, the teams left out of that draft counted as having sat out a
  night nobody played. A draft for a night still to come counts.
- **Known limits.**
  - The draw can't tell afterwards why a team had no game: a team taken
    out of a night by hand (its game removed, or a night's fixtures made
    by hand without it) counts as having sat out that night, not as away.
    Whether a night was drawn before a team joined goes by the night's
    earliest game, so a game added by hand later doesn't change it. On
    *Not playing this night* the same goes: a team taken out by hand, or
    one whose week off was cancelled after the draw, is listed with its
    count as if the draw had rested it, and can get the guard's words
    (below).
  - A team that moved division is judged by its old division on a night
    between its last game there and its first game in the new one, and
    counts among the old division's teams for that night's share, which
    moves both divisions' shares a little for the teams away that night.
    A team moved before its first game is judged by its division now on
    the nights before it joined, not the one it joined into.
  - A share is the night's average, so a team away on a night short of
    courts is counted as an average team that night, whatever it would
    have drawn.
  - Late joiners and teams back from nights away still sit out a little
    less than their share in most shapes. Across the 28 shapes below a
    late joiner sat out 0.13 of a night a season less than its share on
    average (less in 26 of them, 0.26 at most), a team with nights away
    0.04 less (from 0.32 less to 0.34 more). Part of it is the cost among
    level teams (above): without the rematch costs the tight run's late
    joiner is 0.11 under instead of 0.19, while capping every team's
    rating uncertainty for the draw changes nothing, so it isn't the new
    team's uncertain rating. The rest is the rotation: a count with a
    fraction puts a team at the same place in the rotation every season,
    and where the season ends decides whether its next sit-out comes. In
    seasons short of courts only some nights, every rule tried leaves a
    late joiner 0.1 to 0.2 under its share, and a late joiner almost never
    sits out its first night (0.3% of seasons in the standard run, against
    7.1% for the teams there every night; 0.8% against 24.6% in the tight
    run). A coin toss among level teams in place of the cost doesn't cure
    it: in the tight run the late joiner was 0.35 under, and ordinary
    games cost 1.073 against 0.897.
  - A double-header team's extra rematches make it a little more likely
    to lose a tie.
- **Admins can see the rule hold.** A sit-out's reason gives the team's
  count against the teams playing, to one decimal place when it has a
  share: "Not enough courts (nights sat out before: this team 1.3, the
  teams playing 1 or more)", or "Bye, odd number of teams (...)". A team
  the twice-in-a-row rule put first adds "it has never sat out, so it
  goes before a team that sat out its last night", in the reason and on
  *Not playing this night* (there, a resting team that was in the draw,
  has never sat out and is more than 0.3 above the teams playing while
  one that sat out its last night plays: the count alone never allows
  that). After *Make fixtures automatically* the notice lists who isn't
  playing and why (week off and owing reasons unchanged). *Not playing
  this night* gives each resting team's count and the lowest among the
  teams playing in its division; a team that said it can't make the night
  is listed as that, with no count. A team that joined after the night's
  fixtures were made is listed as "joined the league after this night was
  drawn", with "remake the night to give it a game" while the night is a
  draft with no results, and one that joined after the night as "joined
  the league after this night": no count, and never the guard's words,
  as the draw never had it. In the earlier builds of this release such a
  team got a count made of its shares of earlier nights and, from
  f60fc11, when a team that sat out its last night played, the guard's
  words too. *How automatic fixtures are made* has a *Sitting out* item.
- **Code.** `RSL_Pairing::pair_division()` takes the night's slots
  (`$room`), the double-header teams (`$opted`), their double-header
  nights (`$doubles`) and the twice-in-a-row guard (`$guard`: each team's
  nights actually sat out and whether it sat out its last night), and
  returns `bye`, `fill`, `out` (team => `courts` or `odd`) and `first`
  (teams the guard put first) beside `pairs`. `sit_out()` is the rule
  (pure): its options (teams that must sit out and teams that may, for
  each top count from the line up) and the guard. `sat_out_counts()` is
  the count (`byes`, with the shares), `sat` (nights actually sat out),
  `last` and `doubles`; a night may carry `made` (its earliest game's
  time) and a team `joined_at` (its approval or creation time);
  `joined_after()` is the one rule for whether a team wasn't in the
  league yet for a night (after its date, or after its fixtures were
  made), read by the count and by `explain_night()`.
  `fill_for()` is the fill game, `can_play_twice()` the one-start-time
  check, `match_out()` the matching with k sit-out places (exact up to
  `EXACT_LIMIT` teams and places together, the local search above, both
  kept within the rule by `NEVER`), `sit_out_words()` the admin reason,
  `resting_words()` the *Not playing this night* line, `late_words()` the
  line for a team that joined after the night was drawn (`explain_night()`'s
  `_late`), `went_first()` whether the guard put a resting team first
  (`explain_night()`'s `_first`, never for a `_late` team), `FIRST_WORDS`
  the guard's words and `count_words()` the numbers. `trim()` is gone:
  the draw never makes more games than slots. `plan()` still puts the
  games cheapest first, as `trim()` did, because `assign_slots()` starts
  from the games in that order and its search can end on different courts
  and times from a different start (the earlier builds of this release,
  17a3aa2 to abce4d3, left them in the matching's order: the same games
  and home teams, but most even nights then got different courts and
  some different start times). The `bye` weight is gone; `odd_double` (8)
  takes over its one remaining job, the double-header preference;
  `odd_sit` (20) and `sit_level` (0.3) are new. A `rsl_pairing_weights`
  filter that sets `bye` no longer does anything; a `sit_level` above 0.5
  is taken as 0.5. An even division with a slot for every game is drawn
  exactly as before: the same games, home teams, courts and times
  (checked: `simulate-ratings.php ladder rounds=9 runs=40 seed=3000
  age=0` and `simulate-pairing.php runs=6` print the same as 0.18.1, and
  0.18.1's and 0.18.2's `plan()`, run on the same history in random
  seasons of 5 to 14 Ranked and 3 to 10 Unranked teams with weeks off,
  late joiners and owing teams, gave the same draw on every such night:
  1514, 1534 and, on PHP 7.4, 1581 nights in 300 seasons each, and 296
  nights with 19 to 21 Ranked teams).
- **Simulated** (`wp eval-file dev/simulate-pairing.php courts runs=1000
  join=6`: a Ranked division of 12 to 14 teams over 10 nights, 5 game
  slots on 30% of nights, 6 on 30%, room for everyone on the rest, a week
  off on 7% of team-nights, one team joining on night 6, three
  double-header teams, the fixtures rating and link term, fill games and
  spare-slot double-headers; each season played under five rules from the
  same teams, slots, weeks off and joiners: before 0.18.2, nights sat out
  only (9aa99da), the live count, and the count with shares compared
  exactly and with counts within 0.5 level (`level=` sets other values
  for those two rows); spread = most minus fewest nights sat out among
  the teams available every night; most by one team = the season's
  highest; closeness = the closeness cost of the games played by the
  draw's rating, 0 for 50/50, 1 for 67/33, for every game and for the
  night's ordinary games (no fill or double-header games); favourite over
  0.80 = games whose favourite had over a 0.80 set chance by true
  strength):

  | Rule | Sat out per team | Spread | Worst spread | Seasons with a 2+ gap | Most by one team | Closeness, every game | Closeness, ordinary games | Favourite over 0.80 |
  |---|---|---|---|---|---|---|---|---|
  | Before 0.18.2: dearest games sit out | 0.54 | 1.60 | 6 | 45.7% | 1.99 | 0.955 | 0.935 | 12.4% |
  | Nights sat out only (9aa99da) | 0.47 | 0.73 | 1 | 0.0% | 1.00 | 1.014 | 1.037 | 13.3% |
  | Live (0.18.2): count with shares, within 0.3 level | 0.47 | 0.73 | 1 | 0.0% | 1.01 | 1.016 | 1.039 | 13.4% |
  | Count with shares, compared exactly | 0.47 | 0.69 | 1 | 0.0% | 1.00 | 1.024 | 1.046 | 13.4% |
  | Count with shares, within 0.5 level | 0.47 | 0.74 | 1 | 0.0% | 1.01 | 1.013 | 1.036 | 13.3% |

  | Rule | Nights sat out, double-header team | Nights sat out, other team | Double-header teams with 2+ sat out and 2+ double-header nights | Double-header spread |
  |---|---|---|---|---|
  | Before 0.18.2: dearest games sit out | 0.36 | 0.60 | 2.1% | 1.31 |
  | Nights sat out only (9aa99da) | 0.51 | 0.46 | 0.2% | 1.19 |
  | Live (0.18.2): count with shares, within 0.3 level | 0.51 | 0.46 | 0.2% | 1.19 |

  (Double-header spread = most minus fewest double-header nights among
  the double-header teams available every night.) Under the old rule
  nearly half of seasons had a team sit out two or more nights more than
  another team that was available every night, and in one season the gap
  was 6. Under every 0.18.2 rule it is never more than 1. The season a
  double-header team sits out two or more nights and also gets two or
  more double-headers goes from 2.1% to 0.2%; the old +2 on the count
  kept double-header teams out of the bye, so they now sit out their
  share. The price is closeness: ordinary games cost 0.10 more (0.935 to
  1.039), every game together 0.06 more, and games with a favourite over
  0.80 go from 12.4% to 13.4%. Teams sit out a little less in all (0.47
  nights a season against 0.54), because the odd team gets a fill game
  more often (3.07 a season against 2.15). No rule gave a third meeting.
  In this run nights away are few and short, and every count draws
  almost alike.

  Fairness is measured on the same nights. Nights early in a season can
  have fewer teams and fewer sit-outs than later ones, so a late joiner's
  rate is set beside the rate of the teams there every night on the
  nights it was there, not over the whole season. Sit-outs per night
  available, by team: mean over the teams in the group, with the highest
  of any one team in any season in brackets; there every night = teams
  available all ten nights; away = teams there from night 1 with a night
  or more away; late joiner = teams that joined after night 1; "same
  nights" = the teams there every night, on that group's nights. Standard
  = the run above. Tight = `slots=tight off=0 join=6 away=4`: 5 slots
  every night, no random weeks off, a team joining on night 6, team 3
  away nights 2 to 5. Short every night = `slots=every join=4,6 off=15`:
  5 or 6 slots every night, weeks off on 15% of team-nights, teams
  joining on nights 4 and 6. All with `runs=1000`:

  | Run | Rule | There every night | Away | Same nights | Late joiner | Same nights | Spread | Closeness, ordinary games | Favourite over 0.80 | Twice in a row |
  |---|---|---|---|---|---|---|---|---|---|---|
  | Standard | Nights sat out only (9aa99da) | 0.055 (0.200) | 0.051 (0.250) | 0.056 | 0.041 (0.400) | 0.069 | 0.73 | 1.037 | 13.3% | 0 |
  | Standard | Live: with shares, within 0.3 level | 0.055 (0.200) | 0.052 (0.250) | 0.056 | 0.029 (0.333) | 0.070 | 0.73 | 1.039 | 13.4% | 0 |
  | Standard | With shares, compared exactly | 0.058 (0.200) | 0.050 (0.250) | 0.059 | 0.021 (0.333) | 0.075 | 0.69 | 1.046 | 13.4% | 0 |
  | Standard | With shares, within 0.5 level | 0.055 (0.200) | 0.051 (0.250) | 0.056 | 0.034 (0.400) | 0.070 | 0.74 | 1.036 | 13.3% | 0 |
  | Tight | Before 0.18.2 | 0.177 (0.900) | 0.163 (0.833) | 0.230 | 0.142 (1.000) | 0.243 | 4.51 | 0.705 | 9.5% | 3589 |
  | Tight | Nights sat out only (9aa99da) | 0.168 (0.300) | 0.232 (0.500) | 0.216 | 0.259 (0.600) | 0.226 | 0.80 | 0.891 | 12.3% | 0 |
  | Tight | Live: with shares, within 0.3 level | 0.173 (0.300) | 0.210 (0.333) | 0.222 | 0.191 (0.400) | 0.234 | 0.80 | 0.897 | 12.3% | 0 |
  | Tight | With shares, compared exactly | 0.171 (0.300) | 0.224 (0.333) | 0.221 | 0.205 (0.400) | 0.232 | 0.68 | 0.916 | 12.6% | 0 |
  | Tight | With shares, within 0.5 level | 0.176 (0.300) | 0.179 (0.333) | 0.227 | 0.162 (0.400) | 0.239 | 0.80 | 0.880 | 12.2% | 0 |
  | Short every night | Nights sat out only (9aa99da) | 0.053 (0.200) | 0.046 (0.333) | 0.056 | 0.036 (0.500) | 0.074 | 0.35 | 0.995 | 13.1% | 0 |
  | Short every night | Live: with shares, within 0.3 level | 0.054 (0.200) | 0.046 (0.286) | 0.057 | 0.033 (0.333) | 0.076 | 0.34 | 0.996 | 13.1% | 0 |
  | Short every night | With shares, compared exactly | 0.058 (0.200) | 0.046 (0.250) | 0.062 | 0.024 (0.500) | 0.084 | 0.29 | 1.012 | 13.3% | 0 |
  | Short every night | With shares, within 0.5 level | 0.053 (0.200) | 0.046 (0.333) | 0.057 | 0.035 (0.333) | 0.075 | 0.35 | 0.994 | 13.1% | 0 |

  Each team against its own share, and first nights. Excess = a team's
  sit-outs minus the sum of the shares of the nights it was there (0 when
  it sat out exactly its share, below 0 when less), the mean over the
  group, with the lowest and highest of any one team in brackets. Out its
  first night = late joiners that sat out their first night; out first
  night back = team 3 sat out its first night back from nights 2 to 5
  away; beside each, the teams there every night that sat out that same
  night:

  | Run | Rule | Excess, away | Excess, late joiner | Out its first night | There every night, that night | Out first night back | There every night, that night |
  |---|---|---|---|---|---|---|---|
  | Standard | Nights sat out only (9aa99da) | -0.01 (-1.0 / +1.0) | -0.11 (-0.8 / +1.5) | 0.7% | 7.1% | – | – |
  | Standard | Live: with shares, within 0.3 level | -0.00 (-0.9 / +1.0) | -0.16 (-0.8 / +0.9) | 0.3% | 7.1% | – | – |
  | Standard | With shares, compared exactly | -0.02 (-0.9 / +1.0) | -0.20 (-0.8 / +0.9) | 0.2% | 7.7% | – | – |
  | Standard | With shares, within 0.5 level | -0.01 (-1.0 / +1.1) | -0.14 (-0.8 / +0.9) | 0.5% | 7.1% | – | – |
  | Tight | Before 0.18.2 | -0.33 (-1.7 / +4.1) | -0.43 (-1.4 / +3.8) | 7.7% | 25.3% | 11.4% | 25.3% |
  | Tight | Nights sat out only (9aa99da) | +0.08 (-0.9 / +1.3) | +0.15 (-0.8 / +1.6) | 37.4% | 21.1% | 32.9% | 21.1% |
  | Tight | Live: with shares, within 0.3 level | -0.05 (-0.9 / +0.3) | -0.19 (-0.8 / +0.6) | 0.8% | 24.6% | 28.2% | 24.6% |
  | Tight | With shares, compared exactly | +0.03 (-0.3 / +0.3) | -0.12 (-0.8 / +0.6) | 34.2% | 21.8% | 28.2% | 21.8% |
  | Tight | With shares, within 0.5 level | -0.24 (-0.9 / +0.7) | -0.34 (-1.2 / +0.6) | 1.1% | 26.3% | 6.9% | 26.3% |
  | Short every night | Nights sat out only (9aa99da) | +0.01 (-1.0 / +1.1) | -0.11 (-1.0 / +0.9) | 0.6% | 6.5% | – | – |
  | Short every night | Live: with shares, within 0.3 level | +0.01 (-1.0 / +1.1) | -0.13 (-1.0 / +0.9) | 0.4% | 6.5% | – | – |
  | Short every night | With shares, compared exactly | +0.01 (-0.8 / +0.9) | -0.17 (-0.9 / +0.9) | 0.3% | 7.0% | – | – |
  | Short every night | With shares, within 0.5 level | +0.01 (-1.0 / +1.1) | -0.12 (-1.0 / +0.9) | 0.6% | 6.5% | – | – |

  In the tight run, counting nights sat out only made a late joiner sit
  out 0.259 times per night against 0.226 for the teams there every
  night on the same nights, 0.15 of a night over its share, and a team
  back from four nights away 0.232 against 0.216; each sat out its first
  night (back) in about a third of seasons, against 21% for the teams
  there every night. With shares and counts within 0.3 level, the team
  back from away sits out its first night back in 28.2% of seasons
  against 24.6%, and 0.05 of a night under its share; the late joiner
  0.19 under (0.191 against 0.234 a night). It almost never sits out its
  first night (0.8%), for a reason that depends on the team count (the
  run has 12 to 14 teams). With 14 teams its credited count is about
  0.9, level with the teams on 1, so the cost decides. With 13 it is
  about 0.5, more than 0.3 above teams on 0 that play, so the count
  itself keeps it playing. With 12 it is about 0.1, level with the teams
  on 0, and the cost decides among nearly all of them. With counts
  within 0.5 level both drift further under (0.24 and 0.34), and the team
  back from away sits out its first night back in 6.9% of seasons. The
  spread among the teams there every night stays at most 1 under every
  0.18.2 rule, and ordinary games are about as close as with nights sat
  out only (0.897 against 0.891). Where courts are short only some
  nights, or weeks off are scattered, the shares are small and the counts
  draw almost alike.

  Across shapes (`courts runs=1000` with each of these options, and again
  with `seed=20000`; `level=0,0.2,0.25,0.35,0.5` for the other rows):

  ```
  join=6                                   slots=tight off=0 join=6 away=4
  slots=every join=4,6 off=15              size=14 slots=tight off=0 join=6 away=4
  slots=tight off=0 join=9 away=4          slots=tight off=0 join=6 away=8
  slots=tight off=0 join=6 away=5          size=13 slots=tight off=0 join=6 away=4
  slots=tight off=0 join=6 away=4 outliers=650
  slots=every off=0 join=6 away=4          slots=tight off=0 join=3,5,7
  size=15 slots=tight off=0 join=6 away=4  size=12 slots=tight off=0 join=6 away=4
  slots=tight off=25 join=6                join=6 away=4
  slots=tight off=0 join=4 away=2          slots=tight off=0 join=7 away=3
  slots=tight off=10 join=5                slots=tight off=0 join=8 away=3
  slots=every off=0 join=6 away=6          slots=tight off=10 join=4,7 away=3
  slots=tight off=0 join=6 away=4 seed=5000
  off=0 join=6 away=4                      size=13 slots=tight off=0 join=3 away=2
  size=14 slots=tight off=0 join=8 away=6  size=15 slots=tight off=5 join=4 away=3
  slots=every off=5 join=3,8 away=5        size=12 slots=every off=0 join=7 away=4
  ```

  Each shape's mean excess for its late joiners and for its teams with
  nights away (27 of the shapes have those), then over the shapes (seed
  0; seed 20000 gives the same order, every mean within 0.01, the worst
  shape within 0.03 and each count of shapes within one):

  | Rule | Late joiner, mean excess | Late joiner under its share in | Away, mean excess | Mean size of excess, both | Worst shape | Closeness, ordinary games, against 9aa99da |
  |---|---|---|---|---|---|---|
  | Nights sat out only (9aa99da) | +0.12 | 11 of 28 | +0.07 | 0.17 | 0.71 | – |
  | With shares, compared exactly | -0.13 | 22 of 28 | -0.03 | 0.15 | 0.83 | +0.016 |
  | With shares, within 0.2 level | -0.16 | 26 of 28 | -0.05 | 0.14 | 0.67 | +0.001 |
  | With shares, within 0.25 level | -0.14 | 26 of 28 | -0.05 | 0.13 | 0.43 | -0.001 |
  | Live: with shares, within 0.3 level | -0.13 | 26 of 28 | -0.04 | 0.12 | 0.34 | -0.004 |
  | With shares, within 0.35 level | -0.13 | 25 of 28 | -0.11 | 0.15 | 0.46 | -0.009 |
  | With shares, within 0.5 level | -0.20 | 26 of 28 | -0.12 | 0.19 | 0.50 | -0.015 |

  Other shapes (`courts runs=1000` plus the options, no late joiner; the
  live count gives almost the same numbers as nights sat out only in all
  three): with a very strong and a very weak Ranked team
  (`outliers=650`), those two sit out half as often as before 0.18.2
  (1.04 nights a season each against 2.09) and the price is larger:
  ordinary games cost 0.24 more and games with a favourite over 0.80 go
  from 31.8% to 35.1%. With courts short every night as well
  (`slots=every outliers=650`): 0.28 more, 30.5% to 34.5%. In an odd
  division with a court to spare every night (`size=13 slots=roomy off=0
  outliers=650`), those two teams almost never take the bye now (0.01
  nights a season against 1.21): another team is the odd team with a
  fill game. There, ordinary games cost 0.42 more, games with a favourite
  over 0.80 go from 33.2% to 39.4%, fill games from 5.6 to 10.0 a season,
  and the double-header spread from 3.25 to 3.96 nights.
- **Public pages:** nothing public describes who sits out, so no public
  words change.
- **Tests** (`test-pairing.php`): rotation (ten teams on four courts: each
  team sits out once before any team twice), ties by cost, a team that
  has sat out before plays, the link term deciding ties but never
  outweighing the count, room for every game changing nothing, the
  odd-night fill kept when the lowest-count team has none, the
  double-header preference, no fill game that leaves last week's game or
  forces a third meeting, fill games shared by double-header nights,
  `can_play_twice()`; `sat_out_counts()` (a moved team, a division with no
  courts, a double-header night, a week off, a late joiner) and the count
  with shares (a week off, a can't-make-it, an owing night, a late
  joiner, a night with no sit-outs adding 0, a moved team getting its old
  division's share, whole counts for a team there every night, a team
  approved after a night's fixtures were made); `count_words()` and the
  reasons with decimals; the rule on fractional counts (`sit_level` 0.3,
  counts within it level, at 0.2 and 0 not, whole counts never level, the
  cost choosing among level teams); the twice-in-a-row guard (kept within
  the count, the never-sat-out team put first when the count leaves no
  choice, an odd night), `went_first()`, `resting_words()`,
  `joined_after()` and `late_words()`; the rule
  against brute force on 400 random nights with fractional counts and the
  guard (every choice that keeps it, every pairing, the exact cost); every
  season the rule allows on 60 small random seasons with weeks off and
  late joiners, walked choice by choice (the rule's choices are exactly
  those that keep it, the teams there every night end at most one sit-out
  apart, no twice in a row); the local search above `EXACT_LIMIT`;
  `context()` for a team moved from Unranked to Ranked, for the share of
  a night away (approved after the night, approved after its fixtures
  were made, can't make it, a week off, left out for owing), for a night
  left out for owing and for a called-off night; `explain_night()` for a
  team approved after the night or after its fixtures were made (`_late`,
  never `_first`, also where its count and a team that sat out its last
  night playing would make `went_first()` say so); `plan()`'s court
  sit-out reason, the admin *Not playing this night* line (with a
  can't-make-it team, and a team approved after the night was drawn),
  and the *Make fixtures automatically* notice.

## A results-only ladder, finals on games played, no protected tier, and finished sets count whole (0.18.1)

- **The captain's level has no say in the ladder, at any point in the
  season.** The club's rule: a captain's level must have no influence on a
  team's ladder rating, tier or place after 5 games, so it can't affect
  finals. 0.18.1 goes further: the ladder never reads a level, for any
  team at any game count. Levels only steer the draw. Under the old single
  rating, a 200-point difference in starting estimate still left about 52%
  of it in a team's rating after 3 games and 32% after 9.

  There are two ratings, from the same model and the same results
  (`RSL_Ratings::priors()` says where each team starts, `fit_opts()` how
  wide its prior is):
  - the **fixtures rating** starts each Ranked team from its level seed
    with prior sd 200, as before, so games are even from week one. It is
    used only to make fixtures (`RSL_Pairing`), to place free agents
    (`RSL_Placement`) and for the *Set chance* column on the admin Fixtures
    screen;
  - the **ladder rating** starts every team on one number per season, with
    prior sd 350 (`LADDER_PRIOR_SD`), and from a team's first game only its
    results move it. A team on 2 games and a team on 9 are rated the same
    way. It drives everything public and everything a player sees: the
    ladder order, tiers and their buffer, placements, rating changes per
    night, upsets, close games, hype tags, season leaders and team pages
    (`RSL_Stats`).

  One API: `RSL_Ratings::compute($season, $as_of = null, $kind = 'ladder')`,
  `$kind` `'ladder'` or `'fixtures'` (anything else reads as `'ladder'`),
  each memoised separately; `flush()` clears both. `fit()` takes
  `prior_sd` and `provisional_sd` options (defaults `PRIOR_SD` 200 and
  `PROVISIONAL_SD` 95, which the fixtures rating keeps).

- **One start per season, frozen on the first night.** The ladder's start
  is set once, when the season's first Ranked night is published
  (`RSL_Fixtures::publish_night()` calls `RSL_Ratings::start_for()`): the
  mean level seed of the Ranked teams with a game that night, rounded to 10
  (`common_start()`, `frozen_start()`, `first_ranked_night()`). It is kept
  in the option `rsl_ladder_starts` (season => start, night, teams;
  autoload off) and never recomputed, so a level edited later, or a team
  joining late, moves nothing. A season already running when 0.18.1
  arrives freezes it on the first read. Only `publish_night()` forgets it
  (`forget_start()`): when unpublishing leaves the season no published
  Ranked night (`RSL_Fixtures::published_ranked_games()` is 0, never a
  failed count), so the next publish freezes it afresh. A read never
  forgets a stored start or freezes it again, even one that sees no
  published Ranked night (as a failed games read would), and no start is
  frozen from an empty list of Ranked teams (as a failed teams read would
  give); so a level edited after night one can't reach the ladder through
  either. With nothing stored and no Ranked night published the ladder uses
  the current mean seed without storing it (nothing public shows then).
  Fitted ratings
  always average their starting values, so the league's average rating sits
  at the start and the tier thresholds keep their meaning (Silver 1350,
  Gold 1500, Platinum 1650, Diamond 1800).

- **Why the ladder's prior is 350, not 200.** The prior says how far
  results can comfortably move a team from where it starts. A level seed is
  usually close to right, so 200 suits the fixtures rating. The shared
  start knows nothing about a team, and with 200 it held results back: a
  strong team needed most of the season to pull clear of a weak one. 300
  made the ratings overconfident and 400 made early moves bigger for
  little gain; 350 keeps the uncertainty honest (typical error / claimed
  sd 1.02, against 1.52 at 200). Most of the gain in the table below is the
  prior's: in the simulation study, prior 350 on the draw before 0.18.1 (no
  link term) gave 0.734 after round 4 and 0.891 after round 9, against
  0.612 and 0.835 at 200; the link term adds about 0.03 at round 4 and
  little by round 9.

- **Placements: 3 games, and the ladder's sd line is 120.** A team is in
  placements while it has fewer than 3 games or its ladder sd is over
  `LADDER_PROVISIONAL_SD` (120; the fixtures rating keeps 95). With the
  wider prior, 60-67% of simulated teams are over 95 after 3 games, and in
  600 simulated seasons 1 team in 8,400 was over 120 (at 120.1; the 90th
  percentile is about 105), so the old line would have kept most teams in
  placements for a fourth game.

- **The draw links groups that haven't met.** The ladder only has results
  to go on, so it can't compare teams that no chain of games connects (say
  the top five have only played each other). From the division's third
  night, while the published, played Ranked games split the teams that
  have played into groups with no chain between them, a game between two
  groups costs 2.25 less (the cost of a 75/25 game) for each of its nights
  in a row they've been split: 2.25, 4.5, 6.75 and so on
  (`RSL_Pairing::link()` / `groups()`, weights `link` 2.25 and `link_from`
  3, filterable like the rest). The nights are the division's own: a
  round with none of those games (no Ranked courts, called off, results
  not in yet, a division that started late) doesn't count, so it neither
  brings the term on after one night of results nor raises the bonus.
  Forfeits don't link, a team with no game yet is left out, and Unranked
  never gets the term. One group changes nothing. When a night has more
  games than courts, the games that sat out were the dearest by the draw's
  own cost, the link bonus included (`RSL_Pairing::trim()`), so a game
  that linked groups was the last to go (replaced in 0.18.2: the teams
  with the fewest nights sat out sit out, and for who sits out the link
  term only decides between teams on the same count; `trim()` is gone.
  See 0.18.2). The double-header checks judge games without it.
  `pair_cost()` and `pair_division()` take the link as a last argument
  (since 0.18.2 `pair_division()` takes `$room`, `$opted` and `$doubles`
  after it); `pair_cost()` returns it as `link`. The admin Fixtures
  screen shows it in the *Why* column ("Links two groups that haven't met
  yet: -2.25") and explains it under *How automatic fixtures are made*.

- **Simulated** (`wp eval-file dev/simulate-ratings.php ladder rounds=9
  runs=600 seed=3000 age=0`: 14 teams, 9 rounds, 600 fresh seasons, the
  latest night 0 weeks old as `compute()` counts it; rank correlation with
  the true strengths, 1 = perfect; top 4 = share of the true top 4 in the
  ladder's top 4 after the last round):

  | Ladder | Draw | after R2 | R4 | R6 | R9 | avg error R9 | top 4 |
  |---|---|---|---|---|---|---|---|
  | Seeded by captain levels (0.18.0) | before 0.18.1 | 0.860 | 0.885 | 0.907 | 0.922 | 50.9 | 0.836 |
  | Common start, prior sd 200 (first 0.18.1 draft) | before 0.18.1 | 0.381 | 0.612 | 0.739 | 0.835 | 77.4 | 0.767 |
  | Results only, prior sd 350 (0.18.1) | with link | 0.427 | 0.761 | 0.848 | 0.895 | 55.6 | 0.812 |

  After round 2 every team is still in placements, so nothing is shown;
  the first numbered ladder comes after round 3. Against the draft that
  kept prior sd 200, finals seeding gains 0.06 in rank correlation and
  0.045 in top 4; against the old seeded ladder, which let the level count,
  it gives up 0.027 and 0.024. Games stay as even as they were: the
  favourite is above a 0.80 set chance in 12.8% of games (12.7% with the
  old draw) and both teams are inside 0.35-0.65 in 51.2% (51.4%). The
  costs: rounds 3-4 are rougher than the old seeded ladder, and a team's
  early moves on the ladder are bigger (at its 4th game 1 in 10 moves over
  100 points, against 60), all of them from results. With identical
  results and one team's level 200 points higher from the start, the
  fixtures rating keeps 52% of the difference after 3 games and 32% after
  9; against the other teams the ladder keeps none (every ladder rating
  moves by the start's ~14 points), and a level edited after the first
  night moves no ladder rating at all. With no options the simulator
  reproduces the older 10-round table (seeded 0.85 / 0.87 / 0.90 / 0.93
  after rounds 2 / 4 / 6 / 10, prior-200 draft 0.38 / 0.59 / 0.72 / 0.85)
  and adds the 0.18.1 ladder (0.43 / 0.73 / 0.82 / 0.89). `seed=` starts
  from fresh seasons, `age=` sets how old the latest night counts, `ladder`
  prints these tables alone, `noise=` sets how far off the captains'
  guesses are.

- **How the ladder works** says every team starts the season on the same
  rating and only results move it from its first game, that the first 3
  games are placements whose rating shows once they're done, and that from
  round 3 the draw connects teams that have only played each other with the
  rest, even with a game where the favourite would win about 3 sets in 4,
  and accepts a wider gap each week they stay apart. The admin team page
  and Fixtures help say the level never moves a team on the ladder.

- **The Stats cache knows the ladder model.** `RSL_Stats`' cache key adds
  `RSL_Ratings::ladder_model()` (the ladder's prior, provisional line,
  placement games and fit settings), so a build that changes the ladder
  under the same version never serves stats worked out with the old one.

- **Finals count games played, not rounds** (the club's rule, 5 Nov 2026).
  A Ranked team needs 5 regular-season games played to be eligible for
  finals. A game counts once it has a result that isn't a forfeit, so a
  1–1 draw or a game the clock cut short counts, and a double-header is two
  games. A bye (the fixtures left the team out), a forfeit won or lost, a
  game with no result yet, a finals-night game and anything on an
  unpublished night count nothing, and nor does a week off or a night the
  team said it couldn't make. Only games in the team's own division count,
  as in its ladder record (P): a team the league moves from Unranked to
  Ranked starts its finals count at 0. (Before, its Unranked nights
  counted as rounds, so after 3 Unranked games and 2 Ranked ones it showed
  a tick beside P 2, still in placements.) The 0.8.1 rule counted nights
  instead: a double-header night was one, a bye counted, a forfeit win
  counted.
  Season Points are unchanged: the turn-up points are once a night and a
  bye still earns them (checked: `RSL_Stats::season()` is identical to the
  previous build's apart from the finals count, on the test season before
  and after a round of double-headers, forfeits and a finals night).
  - Renamed so the names say what is counted: `FINALS_MIN_ROUNDS` is
    `FINALS_MIN_GAMES` (5), `finals_min_rounds()` is `finals_min_games()`,
    and the option and filter `rsl_finals_min_rounds` are
    `rsl_finals_min_games`. The old option is not read; it was not set on
    the live site when checked on 5 Nov 2026. Each team's row in
    `RSL_Stats::season()` has `games` in place of `rounds`, and its
    `finals` array is `games`, `need`, `eligible`, `possible`.
  - `possible` (whether a team can still get there) is true while its
    games played plus its games to come reach the minimum. Games to come:
    its games in published regular-season fixtures with no result yet, in
    its own division (a published double-header is two, a published night
    it isn't in is none), leaving out a game either team has said it can't
    make, active or handled (the league calls it off or records a forfeit;
    the opponent's game is off too), plus two games for each regular night
    after the last night with a result whose fixtures aren't out yet.
    - Two a night, not one: until a night's fixtures are out any team can
      ask for Two games, and the draw gives a team at most two a night, so
      a blank mark (can't get there) never later turns into a tick. With
      one a night, a team on 3 games with one night left showed blank, then
      got a double-header and became eligible. The cost: a team that needs
      a double-header on every night left shows "N to go" though it may not
      get one.
    - Counting one game for every regular night after the last night with
      a result, as first planned, left the mark blank for a team whose
      result on that night wasn't in yet, and for a team whose published
      double-header would get it there.
    - Without the can't-make rule, a game left scheduled after a team said
      it couldn't make it (handled, no forfeit recorded) kept "1 to go"
      beside both teams for the rest of the season.
  - The ladder's How the ladder works says: Finals: a team needs 5
    regular-season games played in its division. A double-header counts as
    two games. A bye doesn't count, and nor does a forfeit, won or lost. The Finals
    column keeps its marks (✓, "2 to go", blank once a team can't get
    there), its titles now in games.
  - The Stats cache key names the rule (`finals-games:5`, where it had the
    bare number), so stats an earlier 0.18.1 build saved under the rounds
    rule are rebuilt.

- **Tests:** editing levels after night one leaves every ladder rating
  identical, teams under 5 games included, and nothing public moves; late
  joiners don't move the start; unpublishing every Ranked night forgets it
  and publishing re-freezes it, while unpublishing one of several keeps it;
  a read that sees no published night keeps it, and an empty teams read
  freezes nothing; `fit()`'s new options and the 120 line; the link term
  (pure cases, a late start, rounds without results, a real season's
  groups, forfeits, Unranked, the admin *Why* column); a linking game
  survives the court trim (since 0.18.2: the link term deciding between
  teams on the same nights sat out); the public draw sentence; the Stats
  cache key; finals eligibility (a double-header is two games, a bye, a forfeit win,
  a forfeit loss, a game with no result, a finals-night game and an
  unpublished night are none, a 1–1 draw cut short by the clock counts,
  eligible at exactly 5, need and possible as fixtures come out and
  results come in, two games to come for a night not yet drawn, a game
  either team can't make (active or handled) is none to come for both, a
  finals fixture and a game in an unpublished draft are none to come, an
  unpublished night before the last result is none to come, a team moved
  from Unranked to Ranked counts only its Ranked games, the setting's
  option and filter, the public wording); Season Points stay once a night
  for teams that played (exact points for a win, and for a loss plus a
  forfeit win), and a night whose only game a team forfeited earns it
  nothing.

- **A finished set counts as a whole set for the set bonus.** Before, a set
  needed 40 points in all to count whole, so the most decisive wins counted
  least (25-10 was 0.875 of a set, a finished third set 15-8 only 0.575).
  Now a set that finished — the winner at its target two clear: 25 for the
  first and second sets, 15 or more for the third (the ref picks 15 or 25)
  — counts whole, close or not. A set the clock cut short counts as far as
  the leader got: leader's points / target, at most 1 (a 14-11 first set
  14/25, a 10-8 third set 10/15). A tied set still counts nothing; the
  points evidence is unchanged, so a 1–1 draw still moves ratings by
  points. `RSL_Ratings::set_finished()` / `set_share()`, with
  `SET_TARGET` (25) and `THIRD_SET_TARGET` (15) in place of
  `FULL_SET_POINTS`. `dev/simulate-ratings.php` compares the live model
  with the old 40-point rule.

- **Silver is no longer kept for the season.** A team that has reached
  Silver drops to Bronze like any other tier: tiers follow the rating, with
  the same buffer as before — up as soon as the rating crosses into a tier,
  down one tier after 2 rounds in a row more than 50 points below the
  tier's floor (the first such round shows "At risk"). `RSL_Stats::step_tier()`
  no longer takes the season's peak tier; the team page still shows the peak.
- **How the ladder works** gives the reason for the buffer instead of
  "Up straight away; down only after two weeks well below": "They follow the
  rating with a small buffer so a single result doesn't flip a team between
  tiers: a team moves up as soon as its rating crosses into a tier, and down
  one tier after two rounds in a row more than 50 points below where its
  tier starts." (`RSL_Public_Fixtures::tier_rule_words()`, from the
  constants).

## A season preview while the ladder is empty, and three small fixes (0.18.0)

- **Season preview.** Until a season's first fixtures are published, the
  League Teams, League Ladder and Fixtures & Results pages show a preview
  card: when the season starts ("2027 Pre-season (Summer) starts Sunday
  24 January") with a countdown ("53 days to go", counted again in the
  visitor's browser by the site's own date and time zone, so a cached page
  never shows an old number and every visitor sees the same count), how many
  teams are in so far in each division (never their names, never
  applications still waiting), the Ranked tiers from Bronze to Diamond
  (three placement games, then climb) and what Unranked is, the free agent
  dates while the window is still to close, and buttons to register and to
  read how the league works. It goes as soon as a night's fixtures are
  published or the first night has passed. A season with no dates says its
  dates are coming soon. On the Teams page it sits above the team list (in
  place of "No teams yet"), on the Ladder above "How the ladder works", on
  Fixtures & Results above the weeks.
- **How the league works button.** Social League → Teams has a League pages
  box: pick the page that explains the league and the preview links to it.
  With none picked there's no button.
- **League pages box note.** If the chosen page is later unpublished, the
  box says so under the picker (the button is hidden until it is published
  again).
- Fixes: the menu's "Register for YEAR" names the year of the season open
  for sign-up (or, with none open, of the season after, never a year
  already gone) when the sign-up season it was pinned to has finished; with
  nothing open for sign-up, a team application or a free agent entry (the
  older "looking" form too) is refused with "Sign-ups for SEASON have
  closed and nothing is open yet." (and the league page says so where those
  forms were, and its Not yet words and messages offer only an invite code)
  instead of going into a finished season; a season rego that still counts
  after its season's dates moved later covers the player to the new last
  night on the league page, so the "runs out soon" note judges that night
  (and stays quiet when the next season is already paid for).
- Dev: `RSL_Public::season_preview()` and `preview_state()` (the one show
  rule; both take the season's nights when the caller has them),
  `preview_fa_words()`, `teams_in_words()`, `countdown_words()`,
  `days_until()`, `count_word()`, `about_url()` and `ABOUT_OPTION`
  (`rsl_about_page_id`); `RSL_Seasons::closed_words()` (error
  `rsl_signup_closed`; `signup_choices()` no longer falls back to a finished
  season); `RSL_Portal::menu_season()`; `RSL_Rego::paid_ahead()` counts a
  season rego for its whole season; `dev/tests/test-season-preview.php`.

## Start a team in one step, pick the season, and a menu that knows you (0.17.0)

- **Start a team at registration in one go.** Choosing *Start a team* when
  registering shows the team's details right there: which season, team
  name, division, roughly what level and colour. One *Register* sends the
  registration and the captain application together: "You're registered
  and your application for TEAM is in. Once the league approves it you'll
  get an invite link for your players."
- If the application can't go in (no name, a name already taken that
  season, already on a team that season…), the registration still stands
  and the page says why. Where sending it again can work, it also opens
  the team application with what was typed. Pressing *Register* twice
  never reports an error for an application that went in.
- **Pick the season.** *Find me a team* (when registering and on the league
  page) and the captain application ask which season, from the seasons
  open for sign-up with their dates; the season signing up now is chosen
  already. Joining with an invite code keeps the team's own season.
- The league page shows what you've signed up for in a later season too
  (your team, your application waiting, or your place on the free agent
  list) under *Later season*, and the season choices leave out seasons
  you're already in. A later season's team's invite link works as soon as
  the team is approved.
- **The league page's menu item follows the player** (logged in only):
  *Register for 2027* (the year of the season signing up now) until they've
  registered, *My team* once they're on a team or have a team application
  waiting, *My league* when they're registered without a team. Logged-out
  visitors see the menu as it's set up, and so does a menu item that heads
  a dropdown.
- Admin season lists (Teams, Free Agents, Courts & Nights, Add a rego by
  hand) now run from this year to two years ahead.
- Fixes: rego notes are cut on a whole character and always keep the
  newest reason, so a cancelled order paid again gets its rego back even
  when the note was long; adding a 12-month rego by hand says so only once
  it's saved, with its dates; the "runs out soon" note doesn't show when
  the next season is already paid for; a free agent waiting for a team who
  can't pay online is told why and to contact the league; a team that
  couldn't be saved is reported as an error, never as sent; a free agent
  form from a season the page no longer shows asks for a reload instead of
  changing another season's entry; team balance notes (top-ups, admin
  adjustments, carried balances) are cut on a whole character too.
- Dev: `RSL_Seasons::signup_choices()` and `pick_open()` (the one check of
  a season a player picks) and `default_pick()` (the season a picker
  starts on); `RSL_Portal::season_field()`, `team_fields()`,
  `apply_from_post()`, `apply_retry()` and the apply draft
  (`APPLY_DRAFT_PREFIX`, a minute, like the flash);
  `RSL_Portal::menu_labels()` on `wp_nav_menu_objects` (`menu_state()`,
  `flush_menu_state()`; a dropdown heading keeps its words);
  `RSL_Teams::joinable_seasons()` includes the later seasons open for
  sign-up; `RSL_Teams::create()` reports a team it couldn't save;
  `RSL_Rego::paid_ahead()`; `RSL_Rego::note_fit_to()` (public, for ledger
  notes too); `dev/lib-mail-stop.php`, used by every test that makes
  accounts and by the fake-data scripts; every test that makes accounts
  cleans up in a `finally` block, so a check that throws partway still
  removes its accounts and rows;
  `dev/tests/test-menu-label.php`.

## League emails in the club look (0.16.0)

- **Every league email now has the club's email look**, the same as the
  Club Manager's: the club's logo and name, REDS SOCIAL LEAGUE above the
  heading, the subject as the heading, the message, the club footer, and a
  plain-text version. That covers the free agent list, invites, requests,
  placements, team approvals, email invites and the "can't make it"
  notices to the league.
- The main link is a button ("Your league page", "Pay your rego"); invite
  links stay visible so captains can copy them; other links stay links.
- **Sender**: the Club Manager's sender (VVL Oversight → Emails → Sender);
  the league never sets its own. Replies go to the league contact emails
  (Team Balances → Rules) when they're set, so every player sees those
  addresses: use a league address there, not a personal one.
- Without the Club Manager, emails go out as plain text exactly as before.
- Nothing already queued changes: the outbox stores plain text and the
  look is added when it's sent. A send that fails, or throws, is recorded
  on its outbox row and tried again like any failure.
- The league page's "runs out soon" note says a yearly rego starts when the
  current one ends only when it would: the rego on the card is running
  today. For cover that starts later it just offers the yearly rego.
- While the plugin updates its tables after an update (a few seconds),
  regos still count by their own dates, so nobody covered is asked to pay
  in that moment.
- Dev: `RSL_Mail::send_now()` is the one send path (the club look when the
  Club Manager's `TeamOversight_Email` is there, else plain `wp_mail()`);
  `club_email()` is the pure formatter; the filter `rsl_mail_club_look`
  can only switch the look off (tests use it); `dev/tests/test-mail.php`
  covers both paths. `RSL_Database::schema_current()`: the rego reads
  leave out the schema 11 columns until the upgrade has finished. The
  tests' cleanups keep their mail stop on until the throwaway accounts are
  deleted (and turn off Ultimate Member's account deleted email), and
  `test-registration.php` holds the league send run off and parks its own
  queued mail, so nothing a test queues goes out from another request.

## Season rego or yearly rego, the player picks the season (0.15.0)

- **Players can pay for one season or for a year.** The league page offers
  whichever the league has set up, season first: "Pay the $15 season rego"
  with a choice of season, and "Pay the $40 yearly rego" (prices from the
  shop).
- **The player chooses the season** from the seasons open for sign-up, each
  shown with its dates, e.g. "2027 Pre-season (24 Jan to 21 Mar)": the
  season people are signing up for, plus later seasons whose dates are set.
  Finished seasons are never offered. The season shows in the cart and on
  the order.
- **A season rego covers that season only**: from its first game night (or
  the payment date, if later) to its last. A season with no game nights set
  yet covers its quarter (January to March, and so on), and says so. If the
  league moves the season's nights later on, a rego paid for it still
  counts for that season.
- **Already covered for every game night of a season** (by a rego or a full
  or life membership)? That season can't be added to the cart, so nobody
  pays twice.
- **A yearly rego is unchanged**: 12 months from payment, or from when the
  current rego ends.
- The league page says what covers you ("Season rego, 2027 Pre-season,
  until 21 Mar", "Yearly rego until 2 Nov 2027", "Club membership until …"),
  counts a rego paid ahead for the season you're playing in (and any cover
  that starts before that season does), and 30 days before a rego runs out
  offers the next one. Captains still see only ✓ / ✗, judged for their
  team's season.
- Emails that mention the rego say "$15 a season or $40 a year" and link to
  the league page (straight to checkout when only the yearly rego is on
  offer).
- One rego in the cart at a time: choosing another season, or the other
  kind, swaps it (also when a saved cart comes back at login).
- Between seasons, or while next season's sign-ups overlap this season, the
  league page asks about the season you play in next, and never sells a
  season rego that wouldn't clear you for it.
- Refunds, cancellations, an order paid again (the rego comes back with the
  same end) and an admin's Remove work the same for both kinds. A season
  rego refunded after its season has ended stays ended, even if the season's
  last night is moved later afterwards.
- A rego payment that can't be saved (rare: a moment while the plugin
  updates its tables) leaves a note on the order; *Pick up rego purchases*
  on Social League → Registration then gives it.
- **Admin** (Social League → Registration): a *Season rego product* beside
  the yearly one (both optional; they can't be the same product, and
  neither can be the team top-up product); *Add a rego by hand* takes 12
  months or a season (any season this year or next, finished ones too, to
  record a payment made another way); the regos list shows the season.
- Dev: `rsl_regos` gains `season` ('' = 12 months) and `granted_end`
  (schema 11); `RSL_Seasons::open_for_signup()`;
  `dev/tests/test-seasons-open.php`.

## Duplicate accounts: an account in use in the league isn't merged away (0.14.0)

- **The Club Manager's merge tool (Duplicate accounts) refuses to merge
  away an account that's in use in the league.** The preview lists what
  it's in (registered as a player, on a team or the free agent list in a
  season, an invite (one a captain typed to its email address included) or
  a request to join, a social rego, the referees and court supervisors list
  or a job, a team balance line or top-up order, league email, the
  placement draft, or something it did as a captain, ref or admin) and says
  to keep that account instead and merge the other one into it.
- **The account kept can be in use**: its league records stay exactly as
  they are. When both accounts are in use, the preview says so and that
  they can't be merged.
- **A merge never changes a league record**: nothing moves, and nothing
  happens on Undo. What the league reads from the account itself (date of
  birth, name, membership) follows the Club Manager's merge, as it does
  everywhere else. The same check runs again inside the merge, so
  something that changed since the preview still stops it, and a check
  that fails stops the merge rather than letting it through.
- Dev: `RSL_Merge::columns()` is the one list of columns that name an
  account; `dev/tests/test-merge.php` tries a row in each, and fails if a
  `user_id` / `*_by` column in the schema, or any other `bigint` column not
  on its list of ids that aren't accounts, isn't on the list.

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
  each, Unranked-only players never in Ranked, nobody over 10. New teams
  stay within the division's places (since 0.19.0 the places of
  `RSL_Capacity`; before, 2 × the first night's game slots). The admin
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
  (no bye points), and the team page says so.
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
    fixtures leave the team out. Not a bye: no turn-up points. (Since
    0.18.1 finals count games played, so neither a week off nor a bye
    counts towards them.)
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
- **Finals eligibility (Ranked), replaced in 0.18.1:** this rule counted 5
  regular-season **rounds** (nights, not games: a double-header night was
  one), including weeks the fixtures left a team out and nights it won by
  forfeit, but not a night it only forfeited. Since 0.18.1 finals count
  regular-season **games played** instead (a double-header is two; a bye
  or a forfeit, won or lost, is none), set by the option
  `rsl_finals_min_games`; the option `rsl_finals_min_rounds` named here is
  no longer read. See 0.18.1. Ladder column: ✓, "2 to go", or blank once
  a team can't get there.
- The Ranked ladder no longer has a Level column (rating and tier tell
  that story); Unranked keeps **Level**; every team page shows it.

## Fair progression: earned tiers, Season Points, hype not odds (0.8.0)

The **rating stays honest** (it orders the Ranked ladder and makes the
fixtures, and it can go down). What a team **earns** is designed to feel
fair, the way ranked modes in games work:

- **Earned tiers (middle ground):** promotion is instant; demotion only
  after **2 rounds in a row more than 50 points below** the tier's floor
  (the first such round shows "At risk"), and one tier at a time. (Until
  0.18.1 a team that reached Silver kept at least Silver all season; that
  protection is gone.) Team pages show a progress bar to the next tier.
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
  (dearer each time for the same team; since 0.18.2 the team that sits
  out, from an odd number or short courts, is one with the fewest nights
  sat out. See 0.18.2).
- **Sitting out (0.18.2):** `odd_double` (8) and `odd_sit` (20) are the
  two weights for who sits out; the `bye` weight is gone, so a
  `rsl_pairing_weights` filter that sets `bye` no longer does anything.
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
