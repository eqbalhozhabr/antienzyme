<?php

declare(strict_types=1);

namespace Bga\Games\AntiEnzyme\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\Games\AntiEnzyme\Game;
use Bga\Games\AntiEnzyme\StainCards;

/**
 * Rulebook action D: after placing or moving a stain without starting a battle, if the active
 * player now has 2+ of their own stains showing the same number anywhere on the board, they may
 * draw one Stain card (never mandatory). Hand limit is 6 -- drawing past it forces a discard.
 */
class OptionalDraw extends GameState
{
    function __construct(
        protected Game $game,
    ) {
        parent::__construct($game,
            id: 15,
            type: StateType::ACTIVE_PLAYER,
        );
    }

    #[PossibleAction]
    public function actDrawCard(int $activePlayerId)
    {
        $type = $this->game->drawStainCardForPlayer($activePlayerId);
        if ($type !== null) {
            $this->bga->notify->player($activePlayerId, 'privateCardDrawn', '', ['type' => $type]);
            $this->bga->notify->all('cardDrawn', clienttranslate('${player_name} draws a card'), [
                'player_id' => $activePlayerId,
                'player_name' => $this->game->getPlayerNameById($activePlayerId),
            ]);
        }

        if ($this->game->enforceHandLimitNotice($activePlayerId) > StainCards::HAND_LIMIT) {
            return DiscardExcess::class;
        }
        return NextPlayer::class;
    }

    #[PossibleAction]
    public function actSkipDraw(int $activePlayerId)
    {
        return NextPlayer::class;
    }

    function zombie(int $playerId)
    {
        return $this->actSkipDraw($playerId);
    }
}
