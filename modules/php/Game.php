<?php
/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * AntiEnzyme implementation : (c) Eqbal Hozhabrosadati
 *
 * This code has been produced on the BGA studio platform for use on http://boardgamearena.com.
 * See http://en.boardgamearena.com/#!doc/Studio for more information.
 * -----
 *
 * Game.php
 *
 * Main game logic + helper methods shared by every game state. The state machine itself lives in
 * modules/php/States/*.php; the board/card *data* lives in Board.php, StainCards.php and
 * WashingMachineCards.php next to this file.
 *
 * NOTE for whoever picks this up in BGA Studio: this was written without access to a live Studio
 * table to test against, so exact framework call signatures (notify/, gamestate/, globals/, ...)
 * follow the patterns shown in the generated example states as closely as possible, but should be
 * double-checked the first time this actually runs. The game *rules* logic (board topology, deck
 * composition, wash/battle/scoring rules) is the well-researched part; the BGA plumbing around it
 * is a best-effort skeleton.
 */
declare(strict_types=1);

namespace Bga\Games\AntiEnzyme;

use Bga\Games\AntiEnzyme\States\PlayerTurn;

class Game extends \Bga\GameFramework\Table
{
    // Global variable ids (10-99 range reserved for game-specific globals).
    public const G_RING1_OFFSET = 10;   // 0..4 steps Ring 1 has rotated (clockwise = positive)
    public const G_RING3_OFFSET = 11;   // 0..4 steps Ring 3 has rotated (clockwise = positive)
    public const G_FIRST_PLAYER = 12;   // player_id who starts every Stains Phase, fixed for the whole game
    public const G_TURNS_THIS_ROUND = 13; // how many players have taken their Stains Phase turn so far this round

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Compute and return the current game progression (0-100), based on how many Washing
     * Machine cards have been used up out of the 20 that are actually in play (29 - 9 removed).
     */
    public function getGameProgression()
    {
        $remaining = (int) self::getUniqueValueFromDB(
            "SELECT COUNT(*) FROM `card` WHERE `card_deck` = 'wm' AND `card_location` = 'deck'"
        );
        $total = (int) self::getUniqueValueFromDB(
            "SELECT COUNT(*) FROM `card` WHERE `card_deck` = 'wm' AND `card_location` != 'removed'"
        );
        if ($total === 0) {
            return 0;
        }
        return (int) round(100 * (1 - $remaining / $total));
    }

    public function upgradeTableDb($from_version)
    {
        // No schema migrations yet.
    }

    /**
     * Gather all information about current game situation (visible by the current player).
     */
    protected function getAllDatas(int $currentPlayerId): array
    {
        $result = [];

        $result["players"] = $this->getCollectionFromDb(
            "SELECT `player_id` AS `id`, `player_score` AS `score`, `player_color` AS `color` FROM `player`"
        );

        // Every stain currently on the board (public information).
        $result["stains"] = $this->getCollectionFromDb(
            "SELECT `stain_id` AS `id`, `player_id` AS `playerId`, `cell_id` AS `cellId` FROM `stain`"
        );

        // Hand sizes are public; hand *contents* are private to their owner.
        $result["handCounts"] = $this->getCollectionFromDb(
            "SELECT `card_location_arg` AS `playerId`, COUNT(*) AS `count` FROM `card`
             WHERE `card_deck` = 'stain' AND `card_location` = 'hand' GROUP BY `card_location_arg`"
        );
        $result["hand"] = $this->getCollectionFromDb(
            "SELECT `card_id` AS `id`, `card_type` AS `type` FROM `card`
             WHERE `card_deck` = 'stain' AND `card_location` = 'hand' AND `card_location_arg` = " . (int) $currentPlayerId
        );

        $result["stainDeckCount"] = (int) self::getUniqueValueFromDB(
            "SELECT COUNT(*) FROM `card` WHERE `card_deck` = 'stain' AND `card_location` = 'deck'"
        );
        $result["stainDiscard"] = $this->getCollectionFromDb(
            "SELECT `card_id` AS `id`, `card_type` AS `type` FROM `card`
             WHERE `card_deck` = 'stain' AND `card_location` = 'discard'"
        );

        $result["wmDeckCount"] = (int) self::getUniqueValueFromDB(
            "SELECT COUNT(*) FROM `card` WHERE `card_deck` = 'wm' AND `card_location` = 'deck'"
        );
        $result["wmDiscard"] = $this->getCollectionFromDb(
            "SELECT `card_type` FROM `card` WHERE `card_deck` = 'wm' AND `card_location` = 'discard'",
            true
        );

        $result["ring1Offset"] = $this->getGameStateValue(self::G_RING1_OFFSET);
        $result["ring3Offset"] = $this->getGameStateValue(self::G_RING3_OFFSET);
        $result["firstPlayerId"] = $this->getGameStateValue(self::G_FIRST_PLAYER);

        return $result;
    }

    /**
     * This method is called only once, when a new game is launched. Sets up the board, shuffles
     * and deals both decks, and picks a random first player who will start every round.
     */
    protected function setupNewGame($players, $options = [])
    {
        $gameinfos = $this->getGameinfos();
        $default_colors = $gameinfos['player_colors'];

        $query_values = [];
        foreach ($players as $player_id => $player) {
            $query_values[] = vsprintf("(%s, '%s', '%s')", [
                $player_id,
                array_shift($default_colors),
                addslashes($player["player_name"]),
            ]);
        }
        static::DbQuery(sprintf(
            "INSERT INTO `player` (`player_id`, `player_color`, `player_name`) VALUES %s",
            implode(",", $query_values)
        ));

        $this->reattributeColorsBasedOnPreferences($players, $gameinfos["player_colors"]);
        $this->reloadPlayersBasicInfos();

        $playerIds = array_keys($players);

        // ---- Stain deck: build all 51 cards, shuffle, deal 3 to each player. ----
        $stainCards = [];
        foreach (StainCards::DECK_COMPOSITION as $type => $qty) {
            for ($i = 0; $i < $qty; $i++) {
                $stainCards[] = $type;
            }
        }
        shuffle($stainCards);
        $this->insertCards('stain', $stainCards, 'deck');

        foreach ($playerIds as $playerId) {
            for ($i = 0; $i < 3; $i++) {
                $this->drawStainCardForPlayer($playerId);
            }
        }

        // ---- Washing Machine deck: shuffle all 29, set aside 9 at random, rest becomes the deck. ----
        $wmCardIds = array_keys(WashingMachineCards::CARDS);
        shuffle($wmCardIds);
        $removed = array_splice($wmCardIds, 0, 9);
        $this->insertCards('wm', array_map('strval', $removed), 'removed');
        $this->insertCards('wm', array_map('strval', $wmCardIds), 'deck');

        // ---- Board starts empty; rings start unrotated. ----
        $this->setGameStateInitialValue(self::G_RING1_OFFSET, 0);
        $this->setGameStateInitialValue(self::G_RING3_OFFSET, 0);
        $this->setGameStateInitialValue(self::G_TURNS_THIS_ROUND, 0);

        // ---- First player is random, and (per the designer) stays the fixed round-starter all game. ----
        $firstPlayerId = $playerIds[array_rand($playerIds)];
        $this->setGameStateInitialValue(self::G_FIRST_PLAYER, $firstPlayerId);
        $this->gamestate->changeActivePlayer($firstPlayerId);

        return PlayerTurn::class;
    }

    // -----------------------------------------------------------------------
    // Card helpers (both decks share the `card` table -- see dbmodel.sql).
    // -----------------------------------------------------------------------

    /** @param string[] $types */
    private function insertCards(string $deck, array $types, string $location): void
    {
        if (empty($types)) {
            return;
        }
        $values = [];
        foreach (array_values($types) as $i => $type) {
            $values[] = sprintf(
                "('%s', '%s', '%s', %d)",
                addslashes($deck),
                addslashes($type),
                addslashes($location),
                $i
            );
        }
        static::DbQuery(
            "INSERT INTO `card` (`card_deck`, `card_type`, `card_location`, `card_location_arg`) VALUES " .
            implode(',', $values)
        );
    }

    /**
     * Draw the top Stain card into a player's hand, reshuffling the discard into a fresh deck if
     * the deck is empty. Returns the drawn card's type, or null if there are truly no cards left
     * anywhere (deck + discard both empty).
     */
    public function drawStainCardForPlayer(int $playerId): ?string
    {
        $topCardId = self::getUniqueValueFromDB(
            "SELECT `card_id` FROM `card` WHERE `card_deck` = 'stain' AND `card_location` = 'deck'
             ORDER BY `card_location_arg` ASC LIMIT 1"
        );

        if ($topCardId === null) {
            $this->reshuffleStainDiscardIntoDeck();
            $topCardId = self::getUniqueValueFromDB(
                "SELECT `card_id` FROM `card` WHERE `card_deck` = 'stain' AND `card_location` = 'deck'
                 ORDER BY `card_location_arg` ASC LIMIT 1"
            );
            if ($topCardId === null) {
                return null; // No Stain cards left anywhere.
            }
        }

        $type = self::getUniqueValueFromDB("SELECT `card_type` FROM `card` WHERE `card_id` = $topCardId");
        static::DbQuery(
            "UPDATE `card` SET `card_location` = 'hand', `card_location_arg` = $playerId WHERE `card_id` = $topCardId"
        );
        return $type;
    }

    private function reshuffleStainDiscardIntoDeck(): void
    {
        $discardIds = $this->getCollectionFromDb(
            "SELECT `card_id` FROM `card` WHERE `card_deck` = 'stain' AND `card_location` = 'discard'",
            true
        );
        if (empty($discardIds)) {
            return;
        }
        shuffle($discardIds);
        foreach (array_values($discardIds) as $i => $cardId) {
            static::DbQuery(
                "UPDATE `card` SET `card_location` = 'deck', `card_location_arg` = $i WHERE `card_id` = $cardId"
            );
        }
    }

    public function discardStainCard(int $cardId): void
    {
        static::DbQuery("UPDATE `card` SET `card_location` = 'discard', `card_location_arg` = 0 WHERE `card_id` = $cardId");
    }

    /** Enforce the 6-card hand limit; call after any action that could grow a hand past it. */
    public function enforceHandLimitNotice(int $playerId): int
    {
        return (int) self::getUniqueValueFromDB(
            "SELECT COUNT(*) FROM `card` WHERE `card_deck` = 'stain' AND `card_location` = 'hand' AND `card_location_arg` = $playerId"
        );
    }

    /** Draw the top Washing Machine card. Returns its numeric id, or null if the deck is empty. */
    public function drawWashingMachineCard(): ?int
    {
        $topCardId = self::getUniqueValueFromDB(
            "SELECT `card_id` FROM `card` WHERE `card_deck` = 'wm' AND `card_location` = 'deck'
             ORDER BY `card_location_arg` ASC LIMIT 1"
        );
        if ($topCardId === null) {
            return null;
        }
        $wmId = (int) self::getUniqueValueFromDB("SELECT `card_type` FROM `card` WHERE `card_id` = $topCardId");
        static::DbQuery("UPDATE `card` SET `card_location` = 'discard' WHERE `card_id` = $topCardId");
        return $wmId;
    }

    // -----------------------------------------------------------------------
    // Board / stain helpers.
    // -----------------------------------------------------------------------

    public function getStainOwner(string $cellId): ?int
    {
        $owner = self::getUniqueValueFromDB(
            "SELECT `player_id` FROM `stain` WHERE `cell_id` = '" . addslashes($cellId) . "'"
        );
        return $owner === null ? null : (int) $owner;
    }

    public function placeStain(int $playerId, string $cellId): void
    {
        static::DbQuery(
            "INSERT INTO `stain` (`player_id`, `cell_id`) VALUES ($playerId, '" . addslashes($cellId) . "')"
        );
    }

    public function moveStainCell(string $fromCellId, string $toCellId): void
    {
        static::DbQuery(
            "UPDATE `stain` SET `cell_id` = '" . addslashes($toCellId) . "' WHERE `cell_id` = '" . addslashes($fromCellId) . "'"
        );
    }

    public function removeStain(string $cellId): void
    {
        static::DbQuery("DELETE FROM `stain` WHERE `cell_id` = '" . addslashes($cellId) . "'");
    }

    /** How many of a player's stains currently sit anywhere on a given wedge (any of its numbered cells). */
    public function stainCountOnWedge(int $playerId, string $wedgeId): int
    {
        $count = 0;
        foreach (Board::numbersOf($wedgeId) as $n) {
            if ($this->getStainOwner(Board::cellId($wedgeId, $n)) === $playerId) {
                $count++;
            }
        }
        return $count;
    }

    /** How many of a player's stains anywhere on the board currently show a given number. */
    public function stainCountOnNumber(int $playerId, int $number): int
    {
        $rows = $this->getCollectionFromDb(
            "SELECT `cell_id` FROM `stain` WHERE `player_id` = $playerId", true
        );
        $count = 0;
        foreach ($rows as $cellId) {
            [$wedgeId, $n] = Board::parseCellId($cellId);
            if ($n === $number) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Cells matching an Enzyme/Washing-Machine wash: on one of $activeWedges AND showing $number,
     * grouped by current owner. The Hat is only ever a candidate when 'Hat' is itself in
     * $activeWedges (the 4 special HAT cards) since it carries no printed number.
     *
     * @param string[] $activeWedges
     * @return array<int, string[]> playerId => list of their cellIds that would be washed
     */
    public function findWashCandidates(int|string $number, array $activeWedges): array
    {
        $byPlayer = [];
        if ($number === 'HAT') {
            if (in_array(Board::HAT, $activeWedges, true)) {
                $owner = $this->getStainOwner(Board::HAT);
                if ($owner !== null) {
                    $byPlayer[$owner] = [Board::HAT];
                }
            }
            return $byPlayer;
        }
        foreach ($activeWedges as $wedgeId) {
            if ($wedgeId === Board::HAT || !in_array($number, Board::numbersOf($wedgeId), true)) {
                continue;
            }
            $cellId = Board::cellId($wedgeId, (int) $number);
            $owner = $this->getStainOwner($cellId);
            if ($owner !== null) {
                $byPlayer[$owner][] = $cellId;
            }
        }
        return $byPlayer;
    }

    // -----------------------------------------------------------------------
    // Short-lived cross-state context (see dbmodel.sql `global_data` for why this exists instead
    // of relying on the framework's own globals helper).
    // -----------------------------------------------------------------------

    public function setContext(string $key, $value): void
    {
        $json = addslashes(json_encode($value));
        static::DbQuery(
            "REPLACE INTO `global_data` (`name`, `value`) VALUES ('" . addslashes($key) . "', '$json')"
        );
    }

    public function getContext(string $key, $default = null)
    {
        $v = self::getUniqueValueFromDB("SELECT `value` FROM `global_data` WHERE `name` = '" . addslashes($key) . "'");
        return $v === null ? $default : json_decode($v, true);
    }

    public function clearContext(string $key): void
    {
        static::DbQuery("DELETE FROM `global_data` WHERE `name` = '" . addslashes($key) . "'");
    }

    /**
     * Thin public wrappers around the (likely protected/static) low-level DB helpers, so that
     * GameState subclasses -- which only hold a `Game $game` reference, not a guaranteed DB
     * connection of their own -- can run the small ad-hoc queries this skeleton needs without
     * duplicating table/column names all over the state machine.
     */
    public function dbQuery(string $sql): void
    {
        static::DbQuery($sql);
    }

    public function getUniqueValue(string $sql)
    {
        return self::getUniqueValueFromDB($sql);
    }

    // -----------------------------------------------------------------------
    // Wash resolution, shared by player-played Enzyme cards and the Washing Machine Phase.
    // -----------------------------------------------------------------------

    /**
     * Kick off a wash of $number restricted to $activeWedges: any candidate whose owner has no
     * Anti-Enzyme card in hand is removed immediately (there's no decision to make for them).
     *
     * Note: this deliberately does NOT send any notification -- `$this->bga->notify` is only
     * confirmed available from within GameState subclasses in this codebase (that's the only
     * place the generated template used it), so the caller (a GameState) is responsible for
     * notifying using the `autoRemoved` map this returns.
     *
     * @param string[] $activeWedges
     * @return array{pending: int[], autoRemoved: array<int,string[]>} `pending` = player ids who
     *   hold an Anti-Enzyme card and must be asked whether to reveal it (empty = wash fully
     *   resolved already, no EnzymeResponse state needed); `autoRemoved` = playerId => cellIds
     *   that were removed immediately because that player had no way to protect them.
     */
    public function beginWash(int|string $number, array $activeWedges): array
    {
        $candidates = $this->findWashCandidates($number, $activeWedges);
        $pending = [];
        $autoRemoved = [];
        foreach ($candidates as $playerId => $cellIds) {
            $hasAntiEnzyme = (int) self::getUniqueValueFromDB(
                "SELECT COUNT(*) FROM `card` WHERE `card_deck`='stain' AND `card_location`='hand'
                 AND `card_location_arg`=$playerId AND `card_type`='" . StainCards::TYPE_ANTI_ENZYME . "'"
            ) > 0;
            if ($hasAntiEnzyme) {
                $pending[] = $playerId;
            } else {
                foreach ($cellIds as $cellId) {
                    $this->removeStain($cellId);
                }
                $autoRemoved[$playerId] = $cellIds;
            }
        }
        return ['pending' => $pending, 'autoRemoved' => $autoRemoved];
    }

    // -----------------------------------------------------------------------
    // Scoring.
    // -----------------------------------------------------------------------

    /**
     * Final scores per the rulebook: 1/3/5/10 points per stain depending on ring, plus the
     * tiebreak info (who -- if anyone -- has the center, and each player's inner-ring stain count)
     * so EndScore can announce the winner even on a points tie.
     *
     * @return array{scores: array<int,int>, hatOwner: ?int, innerCounts: array<int,int>}
     */
    public function computeFinalStandings(): array
    {
        $scores = [];
        $innerCounts = [];
        $hatOwner = $this->getStainOwner(Board::HAT);

        // Select 3 columns (not 2) so getCollectionFromDb returns full row arrays keyed by
        // stain_id, rather than collapsing into a flat player_id => cell_id map (which would
        // silently drop all but one stain per player, since multiple stains share a player_id).
        $rows = $this->getCollectionFromDb("SELECT `stain_id`, `player_id`, `cell_id` FROM `stain`");
        foreach ($rows as $row) {
            $playerId = (int) $row['player_id'];
            [$wedgeId] = Board::parseCellId($row['cell_id']);
            $ring = $wedgeId === Board::HAT ? 0 : Board::WEDGES[$wedgeId]['ring'];
            $scores[$playerId] = ($scores[$playerId] ?? 0) + Board::POINTS_BY_RING[$ring];
            if ($ring === Board::RING_INNER || $ring === 0) {
                $innerCounts[$playerId] = ($innerCounts[$playerId] ?? 0) + 1;
            }
        }

        return ['scores' => $scores, 'hatOwner' => $hatOwner, 'innerCounts' => $innerCounts];
    }
}
