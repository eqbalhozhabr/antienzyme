<?php

declare(strict_types=1);

namespace Bga\Games\AntiEnzyme\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\AntiEnzyme\Board;
use Bga\Games\AntiEnzyme\Game;
use Bga\Games\AntiEnzyme\StainCards;

/**
 * Stains Phase, one player's turn: place a new stain, move an existing one (possibly starting a
 * Battle), or play an Enzyme card. Exactly one of these per turn -- see rulebook "How to Play".
 */
class PlayerTurn extends GameState
{
    function __construct(
        protected Game $game,
    ) {
        parent::__construct($game,
            id: 10,
            type: StateType::ACTIVE_PLAYER,
        );
    }

    public function getArgs(): array
    {
        $playerId = (int) $this->game->getActivePlayerId();
        return [
            "emptyOuterCells" => $this->emptyOuterCells(),
            "movableWedges" => $this->movableWedgesFor($playerId),
            "playableEnzymeCardIds" => $this->playableEnzymeCardIds($playerId),
        ];
    }

    /** Every Ring-1 (outer) cell that has no stain on it yet -- valid "place a new stain" targets. */
    private function emptyOuterCells(): array
    {
        $cells = [];
        foreach (Board::wedgesInRing(Board::RING_OUTER) as $wedgeId) {
            foreach (Board::numbersOf($wedgeId) as $n) {
                $cellId = Board::cellId($wedgeId, $n);
                if ($this->game->getStainOwner($cellId) === null) {
                    $cells[] = $cellId;
                }
            }
        }
        return $cells;
    }

    /**
     * Wedges where this player has >=2 stains, i.e. they're allowed to move one of them inward.
     * Applies to all 3 rings alike -- even a Ring-3 wedge with 2+ stains can send one to the Hat.
     */
    private function movableWedgesFor(int $playerId): array
    {
        $wedges = [];
        foreach (Board::WEDGES as $wedgeId => $w) {
            if ($this->game->stainCountOnWedge($playerId, $wedgeId) >= 2) {
                $wedges[] = $wedgeId;
            }
        }
        return $wedges;
    }

    private function playableEnzymeCardIds(int $playerId): array
    {
        return $this->game->getCollectionFromDb(
            "SELECT `card_id` FROM `card` WHERE `card_deck`='stain' AND `card_location`='hand'
             AND `card_location_arg`=$playerId AND `card_type` LIKE 'enzyme\\_%'",
            true
        );
    }

    #[PossibleAction]
    public function actPlaceStain(string $cellId, int $activePlayerId)
    {
        [$wedgeId, $number] = Board::parseCellId($cellId);
        if ($wedgeId === Board::HAT || Board::WEDGES[$wedgeId]['ring'] !== Board::RING_OUTER) {
            throw new UserException('You can only place a new stain on an outer-ring cell');
        }
        if ($this->game->getStainOwner($cellId) !== null) {
            throw new UserException('That cell is already occupied');
        }

        $this->game->placeStain($activePlayerId, $cellId);
        $this->bga->notify->all('stainPlaced', clienttranslate('${player_name} places a new stain'), [
            'player_id' => $activePlayerId,
            'player_name' => $this->game->getPlayerNameById($activePlayerId),
            'cellId' => $cellId,
        ]);

        return $this->afterPlaceOrMove($activePlayerId);
    }

    #[PossibleAction]
    public function actMoveStain(string $fromCellId, string $toCellId, int $activePlayerId)
    {
        if ($this->game->getStainOwner($fromCellId) !== $activePlayerId) {
            throw new UserException('You do not have a stain there');
        }
        [$fromWedge] = Board::parseCellId($fromCellId);
        if ($fromWedge === Board::HAT) {
            throw new UserException('A stain in the Hat cannot move any further');
        }
        if ($this->game->stainCountOnWedge($activePlayerId, $fromWedge) < 2) {
            throw new UserException('You need at least 2 stains on that garment to move one inward');
        }

        $ring1Offset = (int) $this->game->getGameStateValue(Game::G_RING1_OFFSET);
        $ring3Offset = (int) $this->game->getGameStateValue(Game::G_RING3_OFFSET);
        $validTargets = Board::inwardOptions($fromWedge, $ring1Offset, $ring3Offset);

        [$toWedge] = Board::parseCellId($toCellId);
        if (!in_array($toWedge, $validTargets, true)) {
            throw new UserException('That is not a valid inward destination from there right now');
        }
        if ($toWedge !== Board::HAT && !in_array((Board::parseCellId($toCellId))[1], Board::numbersOf($toWedge), true)) {
            throw new UserException('Invalid destination cell');
        }

        $defenderId = $this->game->getStainOwner($toCellId);
        if ($defenderId === $activePlayerId) {
            throw new UserException('You already have a stain there');
        }

        if ($defenderId !== null) {
            // Occupied by an opponent: Battle time. The moving stain stays put at $fromCellId
            // until the battle resolves -- Battle::class will move it on an attacker win, or
            // remove it on an attacker loss.
            $this->bga->notify->all('battleStarted', clienttranslate('${player_name} challenges ${defender_name} for a spot!'), [
                'player_id' => $activePlayerId,
                'player_name' => $this->game->getPlayerNameById($activePlayerId),
                'defender_name' => $this->game->getPlayerNameById($defenderId),
                'fromCellId' => $fromCellId,
                'toCellId' => $toCellId,
            ]);
            Battle::begin($this->game, $activePlayerId, $defenderId, $fromCellId, $toCellId);
            return Battle::class;
        }

        $this->game->moveStainCell($fromCellId, $toCellId);
        $this->bga->notify->all('stainMoved', clienttranslate('${player_name} moves a stain inward'), [
            'player_id' => $activePlayerId,
            'player_name' => $this->game->getPlayerNameById($activePlayerId),
            'fromCellId' => $fromCellId,
            'toCellId' => $toCellId,
        ]);

        return $this->afterPlaceOrMove($activePlayerId);
    }

    #[PossibleAction]
    public function actPlayEnzymeCard(int $card_id, int $activePlayerId)
    {
        $row = $this->game->getObjectFromDB(
            "SELECT `card_type` FROM `card` WHERE `card_id`=$card_id AND `card_deck`='stain'
             AND `card_location`='hand' AND `card_location_arg`=$activePlayerId"
        );
        if ($row === null || !str_starts_with($row['card_type'], 'enzyme_')) {
            throw new UserException('Invalid card choice');
        }
        $number = StainCards::enzymeNumber($row['card_type']);

        $this->game->discardStainCard($card_id);
        $this->bga->notify->all('enzymePlayed', clienttranslate('${player_name} plays Enzyme ${number} -- every matching stain on the board is at risk!'), [
            'player_id' => $activePlayerId,
            'player_name' => $this->game->getPlayerNameById($activePlayerId),
            'number' => $number,
        ]);

        // Player-played Enzyme cards are NOT restricted to "active clothes" -- they hit every
        // wedge on the board (the Hat is separately immune, and Board::WEDGES excludes it).
        $result = $this->game->beginWash($number, array_keys(Board::WEDGES));
        foreach ($result['autoRemoved'] as $playerId => $cellIds) {
            $this->bga->notify->all('stainsWashedAway', clienttranslate('${player_name} has no way to protect their stains -- they are washed away'), [
                'player_id' => $playerId,
                'player_name' => $this->game->getPlayerNameById($playerId),
                'cellIds' => $cellIds,
            ]);
        }
        if (empty($result['pending'])) {
            return NextPlayer::class;
        }
        EnzymeResponse::begin($this->game, $number, array_keys(Board::WEDGES), $result['pending'], NextPlayer::class);
        return EnzymeResponse::class;
    }

    /**
     * Shared tail end of "place a new stain" and "move a stain (no battle)": rulebook action D --
     * if the player now has 2+ of their own stains showing the same number anywhere on the board,
     * they may optionally draw one Stain card (max hand size 6).
     */
    private function afterPlaceOrMove(int $playerId)
    {
        for ($n = 1; $n <= 5; $n++) {
            if ($this->game->stainCountOnNumber($playerId, $n) >= 2) {
                return OptionalDraw::class;
            }
        }
        return NextPlayer::class;
    }

    function zombie(int $playerId)
    {
        // Simplest safe zombie behavior: never place/move/play (all require the physical board
        // state to pick a good target, and this game has no fully "free" action) -- just let the
        // round pass this player's turn. Replace with `getRandomZombieChoice` over real legal
        // moves once the UI/legality helpers above are wired up and tested.
        return NextPlayer::class;
    }
}
