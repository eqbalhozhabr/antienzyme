# Anti-Enzyme — Rules (as implemented)

This document describes the game rules exactly as they are currently coded in
`modules/php/`. It exists so the implementation can be checked, cell by cell,
against the physical rulebook and the card artwork before this project is
wired up in a live BGA Studio table. Where the code makes an assumption that
hasn't been confirmed against a physical copy of the game, it's called out
explicitly in **Open Questions** at the end — treat those as the punch list
for whoever has the physical rulebook/cards in hand.

Section references like `Board.php:120` point at the file and line the rule
comes from, so a reviewer can jump straight to the source.

## Theme

Two or more stain "cubes" fight for space on a shirt collar (the board),
while a hand of Enzyme and Anti-Enzyme cards lets players wash away, or
protect, each other's stains. Every round a Washing Machine card physically
turns two of the board's three rings and washes away whatever numbers/wedges
it targets. The game ends when the Washing Machine deck runs out; the stain
sitting deepest (closest to the center) wins the most points.

## Components

- **The board**: 5-fold radial symmetry, 5 "slots" 72° apart, 3 concentric
  rings of clothing wedges plus one center cell.
  - **Ring 1 (outer, 5 wedges)** — each wedge shows all 5 numbers (1–5).
    Rotates during the Washing Machine Phase.
  - **Ring 2 (middle, 5 wedges)** — each wedge shows only 3 of the 5 numbers.
    **Never rotates** — the fixed reference frame. Each Ring‑2 wedge sits
    centered exactly half a wedge (36°) between two Ring‑1 home slots.
  - **Ring 3 (inner, 5 wedges)** — each wedge shows only 2 of the 5 numbers.
    Rotates the *same amount* as Ring 1 but in the *opposite* direction.
    Shares Ring 1's home-slot grid.
  - **The Hat (center, 1 cell)** — no printed number, worth 10 points, immune
    to player-played Enzyme cards. Only the 4 special "HAT" Washing Machine
    cards can wash it.
  - Total numbered cells: 5×5 (Ring 1) + 5×3 (Ring 2) + 5×2 (Ring 3) + 1
    (Hat) = **51 cells**.
  - `Board.php` is the single source of truth for wedge ids, home slots, and
    printed numbers.

- **Stain cubes**: one color per player. Total supply is **50 cubes**, split
  evenly across the players at the table (e.g. 25 each at 2 players, 10 each
  at 5 players) — see `dbmodel.sql` comment. ⚠️ **Not currently enforced in
  code** — see Open Questions.

- **Stain deck — 51 cards**, shared by all players, shuffled together
  (`StainCards::DECK_COMPOSITION`):

  | Card | Qty |
  |---|---|
  | Enzyme 1 | 3 |
  | Enzyme 2 | 3 |
  | Enzyme 3 | 3 |
  | Enzyme 4 | 3 |
  | Enzyme 5 | 3 |
  | Anti-Enzyme | 18 |
  | Protease (battle) | 6 |
  | Amylase (battle) | 6 |
  | Lipase (battle) | 6 |

  Hand limit is **6 cards** (`StainCards::HAND_LIMIT`).

- **Washing Machine deck — 29 unique cards** (`WashingMachineCards::CARDS`,
  transcribed from card art into `data/washing_machine_cards.json`). Each
  card specifies:
  - `number`: the enzyme number (1–5) it washes, or `'HAT'` for the 4 cards
    that wash the center instead.
  - `rotationAmount` (1 or 2 steps) and `rotationArrow` (`'up'` =
    counterclockwise, `'down'` = clockwise — confirmed with the designer,
    see `WashingMachineCards::isClockwise()`).
  - `activeWedges`: which wedges are "live" for this wash — a stain with the
    matching number on a wedge *not* listed is safe from this particular
    card.

  Cards 25–29 are flagged `confidence => 'medium'` in the code: their active-
  wedge combinations were the hardest to read off the source images and
  should be the first thing spot-checked against the physical cards.

## Setup

1. Seat 2–5 players, assign colors.
2. Shuffle the 51-card Stain deck; deal **3 cards** to each player.
3. Shuffle the 29 Washing Machine cards; set **9 aside unused**, the
   remaining **20** become this game's Washing Machine deck.
4. Board starts empty (no stains placed) and unrotated (both rings at their
   home slots).
5. Pick a random first player. This player starts **every** round's Stains
   Phase for the rest of the game (turn order does not rotate between
   rounds).

## Round Structure

Each round is: **Stains Phase** (every player takes exactly one turn, in
turn order starting from the fixed first player) → **Washing Machine Phase**
(draw and resolve one Washing Machine card) → next round, unless the Washing
Machine deck is now empty, in which case the game ends and is scored.

### Stains Phase — one player's turn

On their turn, a player does **exactly one** of the following three things:

**A. Place a new stain.** Put one of your cubes on any *empty* Ring‑1
(outer) cell. (You may never place directly onto Ring 2, Ring 3, or the
Hat — new cubes always enter from the outside.)

**B. Move one of your stains one ring inward.** Requires you to already have
**2 or more** of your own stains on the wedge the moving stain currently sits
on (`Board.php` — "movable" check applies uniformly to all 3 rings, so a
Ring‑3 wedge with 2+ of your stains lets you send one into the Hat too). The
valid destination wedge(s) depend on the current rotation:
  - From Ring 1 → exactly 2 candidate Ring‑2 wedges (any Ring‑1 wedge always
    straddles the boundary between two Ring‑2 wedges, by construction).
  - From Ring 2 → exactly 2 candidate Ring‑3 wedges (symmetric relationship,
    following wherever those Ring‑3 wedges have currently rotated to).
  - From Ring 3 → always just the Hat (every Ring‑3 wedge touches the
    center).
  - You may not move into a cell you already occupy.
  - If the destination cell already holds an **opponent's** stain, this
    starts a **Battle** (see below) instead of completing the move
    immediately.

**C. Play an Enzyme card from your hand.** Discard an Enzyme‑N card to wash
every stain of number N **anywhere on the board** (unlike a Washing Machine
card, a player-played Enzyme card is not restricted to specific wedges — it
hits every wedge that shows that number). The Hat is never affected (it has
no printed number). Each affected opponent gets a chance to protect their
stains with Anti-Enzyme (see below) before they're removed.

**D. Optional bonus draw.** After completing A or B *without* triggering a
Battle, if you now have **2 or more** of your own stains showing the same
number anywhere on the board, you may optionally draw one card from the
Stain deck. (Not offered after action C, and not offered if a Battle was
triggered by action B — battles never grant this draw.) Drawing past the
6-card hand limit forces an immediate discard down to 6.

### Battle

Triggered when a move (action B) targets a cell already occupied by an
opponent's stain. Both the attacker and the defender **secretly and
simultaneously** choose one Battle card from hand (Protease / Amylase /
Lipase) and reveal together:

- **Protease beats Amylase, Amylase beats Lipase, Lipase beats Protease**
  (`StainCards::beats()`).
- **Win**: the attacker's stain moves into the contested cell; the
  defender's stain there is removed.
- **Tie**: nothing moves — both stains stay exactly where they were, both
  players just spent a Battle card.
- **Loss**: the attacker's stain (which was still sitting at its origin
  cell) is removed instead; the defender keeps their spot.

Both chosen Battle cards are discarded regardless of outcome. A player with
**no Battle card in hand** automatically forfeits the battle: if they're the
attacker, their moving stain is simply removed; if they're the defender,
their stain there is removed and the attacker takes the cell — see
`Battle::zombie()`.

### Anti-Enzyme response window

Whenever a wash is triggered (a player's Enzyme card, or a Washing Machine
card), every player who (a) has at least one stain the wash would remove,
and (b) holds an Anti-Enzyme card, is asked simultaneously: reveal it, or
decline.

- **Reveal**: discard the Anti-Enzyme card; **all** of that player's stains
  the wash would have removed are instead safe. (Only that player's stains —
  everyone else's matching stains are unaffected by someone else's reveal.)
- **Decline**: all of that player's matching stains are removed as normal.

Any player who has matching stains but **no** Anti-Enzyme card in hand has
their stains removed immediately, with no decision to make (`Game::beginWash`
— `autoRemoved`).

### Washing Machine Phase

Runs automatically once every player has taken one Stains Phase turn this
round:

1. Draw the top Washing Machine card.
2. Rotate Ring 1 by the card's `rotationAmount`, in the direction its arrow
   indicates; rotate Ring 3 the **same amount in the opposite direction**.
   Ring 2 never moves.
3. Resolve a wash of the card's `number` (or the Hat, for HAT cards),
   restricted to the card's `activeWedges` only — this is the one case where
   *not every* matching stain on the board is at risk, only ones on the
   specific wedges the card lists.
4. Anti-Enzyme response window applies exactly as above.
5. If the Washing Machine deck is now empty, the game ends and moves to
   scoring; otherwise a new round begins with the same fixed first player.

## End of Game & Scoring

The game ends the instant the 20-card Washing Machine deck (this game's
in-play subset of the 29) is exhausted. Final score per player is the sum,
over every stain still on the board, of points by the ring it's on:

| Location | Points per stain |
|---|---|
| Ring 1 (outer) | 1 |
| Ring 2 (middle) | 3 |
| Ring 3 (inner) | 5 |
| Hat (center) | 10 |

**Tiebreaker** (`gameinfos.inc.php` / `EndScore.php`): a player occupying the
center Hat outranks any score tie regardless of point totals; failing that,
whoever has more stains in the inner ring (Ring 3) + Hat combined wins the
tie. Implemented as an auxiliary score (`player_score_aux`) so BGA's own
ranking-by-score-then-by-aux mechanism handles it without special-casing.

## Card Reference

### Stain deck (51 cards)

- 15 Enzyme cards (3 each of numbers 1–5): wash every stain of that number,
  board-wide.
- 18 Anti-Enzyme cards: the only way to protect stains from a wash.
- 18 Battle cards (6 each of Protease / Amylase / Lipase): used only during
  Battles.

### Washing Machine deck (29 cards, 9 randomly removed each game)

See `data/washing_machine_cards.json` for the full per-card breakdown
(number washed, rotation amount/direction, active wedges). Cards 21–24 are
the 4 special "HAT" cards. Cards 25–29 are marked medium-confidence and
should be re-checked against the source card images before trusting them for
a real game.

## Open Questions / things to verify before this ships

These are gaps or unverified assumptions found while reading the code, not
yet confirmed against a physical copy of the game:

1. **Stain cube supply cap (50 total, split across players) is not
   enforced.** `PlayerTurn::actPlaceStain()` currently lets a player place a
   new stain any time an outer cell is empty, with no check against how many
   cubes they've already got in play. Needs either a running per-player
   supply count or a DB check before allowing placement.
2. **Washing Machine cards 25–29** are explicitly flagged low(er) confidence
   in `WashingMachineCards.php` — re-verify `activeWedges` for these against
   the original card images.
3. **BGA framework plumbing is unverified.** The code comments in `Game.php`
   and `Game.js` say this was written without access to a live BGA Studio
   table; state ids, `notify`/`gamestate` call signatures, and the state
   machine wiring should all be smoke-tested the first time this actually
   runs in Studio.
4. **Zombie-mode behavior is intentionally minimal** — a disconnected player
   in `PlayerTurn` always just passes their turn (no place/move/play),
   rather than picking a random legal action. Fine as a placeholder, but
   worth revisiting once the UI's legality helpers are wire-tested.
5. **No enforcement or UI yet** for a player choosing to move a stain
   without a valid destination existing at all (rare, but possible near the
   end of the game) — confirm the rulebook's intended fallback (skip the
   move option entirely? forced pass?).
6. **Fixed first player for the whole game** (never rotates between rounds)
   is called out as "per the designer" in `Game.php` — worth one more
   explicit confirmation since it's an easy rule to misremember either way.

## Where each rule lives in code

| Rule area | File |
|---|---|
| Board topology, rings, rotation math | `modules/php/Board.php` |
| Stain deck composition, battle resolution | `modules/php/StainCards.php` |
| Washing Machine card data | `modules/php/WashingMachineCards.php` |
| Setup, wash resolution, scoring | `modules/php/Game.php` |
| One player's turn (place/move/play) | `modules/php/States/PlayerTurn.php` |
| Battle | `modules/php/States/Battle.php` |
| Anti-Enzyme response window | `modules/php/States/EnzymeResponse.php` |
| Optional bonus draw / hand limit | `modules/php/States/OptionalDraw.php`, `DiscardExcess.php` |
| Washing Machine Phase | `modules/php/States/WashingMachinePhase.php` |
| Round/turn advancement | `modules/php/States/NextPlayer.php`, `NextRound.php` |
| End-game scoring | `modules/php/States/EndScore.php` |
