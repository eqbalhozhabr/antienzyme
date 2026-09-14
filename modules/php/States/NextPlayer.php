<?php

declare(strict_types=1);

namespace Bga\Games\AntiEnzyme\States;

use Bga\GameFramework\StateType;
use Bga\Games\AntiEnzyme\Game;

/**
 * End of one player's Stains Phase turn. Advances to the next player in turn order; once every
 * player has taken exactly one turn this round, the Stains Phase is over and the Washing Machine
 * Phase begins instead.
 */
class NextPlayer extends \Bga\GameFramework\States\GameState
{

    function __construct(
        protected Game $game,
    ) {
        parent::__construct($game,
            id: 90,
            type: StateType::GAME,
            updateGameProgression: true,
        );
    }

    function onEnteringState(int $activePlayerId)
    {
        $this->game->giveExtraTime($activePlayerId);

        $turnsThisRound = 1 + (int) $this->game->getGameStateValue(Game::G_TURNS_THIS_ROUND);
        $playerCount = count($this->game->getPlayersIds());

        if ($turnsThisRound >= $playerCount) {
            $this->game->setGameStateValue(Game::G_TURNS_THIS_ROUND, 0);
            return WashingMachinePhase::class;
        }

        $this->game->setGameStateValue(Game::G_TURNS_THIS_ROUND, $turnsThisRound);
        $this->game->activeNextPlayer();
        return PlayerTurn::class;
    }
}
