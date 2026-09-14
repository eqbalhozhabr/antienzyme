<?php

declare(strict_types=1);

namespace Bga\Games\AntiEnzyme\States;

use Bga\GameFramework\StateType;
use Bga\Games\AntiEnzyme\Game;

/**
 * Between the Washing Machine Phase and the next Stains Phase: end the game once the Washing
 * Machine deck is exhausted, otherwise start a fresh round with the same fixed first player.
 */
class NextRound extends \Bga\GameFramework\States\GameState
{
    function __construct(
        protected Game $game,
    ) {
        parent::__construct($game,
            id: 60,
            type: StateType::GAME,
        );
    }

    function onEnteringState()
    {
        $wmCardsLeft = (int) $this->game->getUniqueValue(
            "SELECT COUNT(*) FROM `card` WHERE `card_deck` = 'wm' AND `card_location` = 'deck'"
        );
        if ($wmCardsLeft === 0) {
            return EndScore::class;
        }

        $firstPlayerId = (int) $this->game->getGameStateValue(Game::G_FIRST_PLAYER);
        $this->gamestate->changeActivePlayer($firstPlayerId);
        return PlayerTurn::class;
    }
}
