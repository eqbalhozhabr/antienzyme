<?php

declare(strict_types=1);

namespace Bga\Games\AntiEnzyme\States;

use Bga\GameFramework\StateType;
use Bga\Games\AntiEnzyme\Game;

const ST_END_GAME = 99;

/**
 * All Washing Machine cards are used up: count points (1/3/5/10 per stain by ring) and apply the
 * rulebook tiebreakers (center Hat ownership wins outright; otherwise most inner-row stains wins)
 * via `player_score_aux`, since BGA ranks players by score then by that auxiliary value.
 */
class EndScore extends \Bga\GameFramework\States\GameState
{

    function __construct(
        protected Game $game,
    ) {
        parent::__construct($game,
            id: 98,
            type: StateType::GAME,
        );
    }

    public function onEnteringState()
    {
        $standings = $this->game->computeFinalStandings();

        foreach ($this->game->getPlayersIds() as $playerId) {
            $score = $standings['scores'][$playerId] ?? 0;
            $innerCount = $standings['innerCounts'][$playerId] ?? 0;
            $hasHat = $standings['hatOwner'] === $playerId;
            // Owning the Hat must outrank ANY inner-stain count, hence the large offset.
            $tiebreak = ($hasHat ? 1000000 : 0) + $innerCount;

            $this->game->dbQuery("UPDATE `player` SET `player_score` = $score, `player_score_aux` = $tiebreak WHERE `player_id` = $playerId");
        }

        $this->bga->notify->all('finalScores', clienttranslate('Final scores are in!'), [
            'scores' => $standings['scores'],
            'hatOwner' => $standings['hatOwner'],
            'innerCounts' => $standings['innerCounts'],
        ]);

        return ST_END_GAME;
    }
}
