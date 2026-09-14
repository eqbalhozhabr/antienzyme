<?php

declare(strict_types=1);

namespace Bga\Games\AntiEnzyme\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\AntiEnzyme\Game;
use Bga\Games\AntiEnzyme\StainCards;

/**
 * "If you draw a 7th [card], discard one" -- hand limit is 6. Only reachable right after
 * OptionalDraw::actDrawCard pushes a hand over the limit.
 */
class DiscardExcess extends GameState
{
    function __construct(
        protected Game $game,
    ) {
        parent::__construct($game,
            id: 16,
            type: StateType::ACTIVE_PLAYER,
        );
    }

    public function getArgs(): array
    {
        $playerId = (int) $this->game->getActivePlayerId();
        return [
            'handCardIds' => $this->game->getCollectionFromDb(
                "SELECT `card_id` FROM `card` WHERE `card_deck`='stain' AND `card_location`='hand'
                 AND `card_location_arg`=$playerId",
                true
            ),
        ];
    }

    #[PossibleAction]
    public function actDiscardCard(int $card_id, int $activePlayerId)
    {
        $row = $this->game->getObjectFromDB(
            "SELECT `card_id` FROM `card` WHERE `card_id`=$card_id AND `card_deck`='stain'
             AND `card_location`='hand' AND `card_location_arg`=$activePlayerId"
        );
        if ($row === null) {
            throw new UserException('That is not in your hand');
        }
        $this->game->discardStainCard($card_id);

        $this->bga->notify->all('cardDiscarded', clienttranslate('${player_name} discards down to the hand limit'), [
            'player_id' => $activePlayerId,
            'player_name' => $this->game->getPlayerNameById($activePlayerId),
        ]);

        if ($this->game->enforceHandLimitNotice($activePlayerId) > StainCards::HAND_LIMIT) {
            return null; // still over the limit somehow (shouldn't normally happen) -- stay here
        }
        return NextPlayer::class;
    }

    function zombie(int $playerId)
    {
        $ids = $this->getArgs()['handCardIds'];
        return $this->actDiscardCard($ids[array_rand($ids)], $playerId);
    }
}
