<?php

declare(strict_types=1);

namespace Bga\Games\AntiEnzyme\States;

use Bga\GameFramework\StateType;
use Bga\Games\AntiEnzyme\Game;
use Bga\Games\AntiEnzyme\WashingMachineCards;

/**
 * The "AI's turn": draw the top Washing Machine card, rotate the rings accordingly, then wash
 * whatever it targets (subject to Anti-Enzyme protection, same as a player-played Enzyme card).
 * This state never has an active player -- it runs automatically and always moves on to
 * NextRound once done (possibly by way of EnzymeResponse if someone can protect their stains).
 */
class WashingMachinePhase extends \Bga\GameFramework\States\GameState
{
    function __construct(
        protected Game $game,
    ) {
        parent::__construct($game,
            id: 50,
            type: StateType::GAME,
            updateGameProgression: true,
        );
    }

    function onEnteringState()
    {
        $wmCardId = $this->game->drawWashingMachineCard();
        if ($wmCardId === null) {
            // Deck should already have been checked empty by NextRound before coming here, but
            // fail safe rather than crash on a missing card.
            return NextRound::class;
        }

        $card = WashingMachineCards::CARDS[$wmCardId];
        $isClockwise = WashingMachineCards::isClockwise($card);
        $delta = $isClockwise ? $card['rotationAmount'] : -$card['rotationAmount'];

        $ring1Offset = (((int) $this->game->getGameStateValue(Game::G_RING1_OFFSET) + $delta) % 5 + 5) % 5;
        $ring3Offset = (((int) $this->game->getGameStateValue(Game::G_RING3_OFFSET) + $delta) % 5 + 5) % 5;
        $this->game->setGameStateValue(Game::G_RING1_OFFSET, $ring1Offset);
        $this->game->setGameStateValue(Game::G_RING3_OFFSET, $ring3Offset);

        $this->bga->notify->all('washingMachineCardDrawn', clienttranslate('The machine draws card #${card_id}: enzyme ${number}, drum turns ${amount} step(s) ${direction}'), [
            'card_id' => $wmCardId,
            'number' => $card['number'],
            'amount' => $card['rotationAmount'],
            'direction' => $isClockwise ? clienttranslate('clockwise') : clienttranslate('counterclockwise'),
            'activeWedges' => $card['activeWedges'],
            'ring1Offset' => $ring1Offset,
            'ring3Offset' => $ring3Offset,
        ]);

        $result = $this->game->beginWash($card['number'], $card['activeWedges']);
        foreach ($result['autoRemoved'] as $playerId => $cellIds) {
            $this->bga->notify->all('stainsWashedAway', clienttranslate('${player_name} has no way to protect their stains -- they are washed away'), [
                'player_id' => $playerId,
                'player_name' => $this->game->getPlayerNameById($playerId),
                'cellIds' => $cellIds,
            ]);
        }
        if (empty($result['pending'])) {
            return NextRound::class;
        }
        EnzymeResponse::begin($this->game, $card['number'], $card['activeWedges'], $result['pending'], NextRound::class);
        return EnzymeResponse::class;
    }
}
