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
 * Two stains contest the same cell: both players secretly choose a Battle card (Protease / Amylase
 * / Lipase), reveal simultaneously, and the loser's cube is removed. A tie removes neither and
 * just discards both cards. The attacker's stain physically sits at `fromCellId` until this
 * resolves -- a win moves it into `toCellId`, a loss (or tie) leaves it where it was (a tie: still
 * at fromCellId, having spent its turn; a loss: removed from the board entirely).
 */
class Battle extends GameState
{
    private const CTX = 'battle';

    function __construct(
        protected Game $game,
    ) {
        parent::__construct($game,
            id: 20,
            type: StateType::MULTIPLE_ACTIVE_PLAYER,
        );
    }

    public static function begin(Game $game, int $attackerId, int $defenderId, string $fromCellId, string $toCellId): void
    {
        $game->setContext(self::CTX, [
            'attacker' => $attackerId,
            'defender' => $defenderId,
            'fromCellId' => $fromCellId,
            'toCellId' => $toCellId,
            'cards' => [], // playerId => card_id chosen, filled in as players act
        ]);
    }

    public function onEnteringState()
    {
        $ctx = $this->game->getContext(self::CTX);
        $this->gamestate->setPlayersMultiactive([$ctx['attacker'], $ctx['defender']], '', true);
    }

    public function getArgs(): array
    {
        $ctx = $this->game->getContext(self::CTX);
        return [
            'attacker' => $ctx['attacker'],
            'defender' => $ctx['defender'],
            'playableBattleCardIds' => [
                $ctx['attacker'] => $this->battleCardIds($ctx['attacker']),
                $ctx['defender'] => $this->battleCardIds($ctx['defender']),
            ],
        ];
    }

    private function battleCardIds(int $playerId): array
    {
        return $this->game->getCollectionFromDb(
            "SELECT `card_id` FROM `card` WHERE `card_deck`='stain' AND `card_location`='hand'
             AND `card_location_arg`=$playerId AND `card_type` IN ('" .
            implode("','", StainCards::BATTLE_TYPES) . "')",
            true
        );
    }

    #[PossibleAction]
    public function actChooseBattleCard(int $card_id, int $activePlayerId)
    {
        $row = $this->game->getObjectFromDB(
            "SELECT `card_type` FROM `card` WHERE `card_id`=$card_id AND `card_deck`='stain'
             AND `card_location`='hand' AND `card_location_arg`=$activePlayerId"
        );
        if ($row === null || !in_array($row['card_type'], StainCards::BATTLE_TYPES, true)) {
            throw new UserException('Invalid battle card choice');
        }

        $ctx = $this->game->getContext(self::CTX);
        $ctx['cards'][$activePlayerId] = $card_id;
        $this->gamestate->setPlayerNonMultiactive($activePlayerId, '');

        $this->bga->notify->all('battleCardChosen', clienttranslate('${player_name} secretly chooses a battle card'), [
            'player_id' => $activePlayerId,
            'player_name' => $this->game->getPlayerNameById($activePlayerId),
        ]);

        if (count($ctx['cards']) < 2) {
            $this->game->setContext(self::CTX, $ctx);
            return null; // waiting on the other player
        }

        return $this->resolveBattle($ctx);
    }

    private function resolveBattle(array $ctx)
    {
        $attackerId = $ctx['attacker'];
        $defenderId = $ctx['defender'];
        $attackerCardId = $ctx['cards'][$attackerId];
        $defenderCardId = $ctx['cards'][$defenderId];
        $attackerType = $this->game->getUniqueValue("SELECT `card_type` FROM `card` WHERE `card_id`=$attackerCardId");
        $defenderType = $this->game->getUniqueValue("SELECT `card_type` FROM `card` WHERE `card_id`=$defenderCardId");

        $this->game->discardStainCard($attackerCardId);
        $this->game->discardStainCard($defenderCardId);

        $this->bga->notify->all('battleRevealed', clienttranslate('${attacker_name} played ${attacker_card}, ${defender_name} played ${defender_card}'), [
            'attacker_name' => $this->game->getPlayerNameById($attackerId),
            'defender_name' => $this->game->getPlayerNameById($defenderId),
            'attacker_card' => $attackerType,
            'defender_card' => $defenderType,
        ]);

        if ($attackerType === $defenderType) {
            $this->bga->notify->all('battleTied', clienttranslate('It is a tie -- both stains stay put'), []);
        } elseif (StainCards::beats($attackerType, $defenderType)) {
            $this->game->removeStain($ctx['toCellId']);
            $this->game->moveStainCell($ctx['fromCellId'], $ctx['toCellId']);
            $this->bga->notify->all('battleWon', clienttranslate('${player_name} wins the battle and takes the spot'), [
                'player_id' => $attackerId,
                'player_name' => $this->game->getPlayerNameById($attackerId),
            ]);
        } else {
            $this->game->removeStain($ctx['fromCellId']);
            $this->bga->notify->all('battleWon', clienttranslate('${player_name} wins the battle and holds the spot'), [
                'player_id' => $defenderId,
                'player_name' => $this->game->getPlayerNameById($defenderId),
            ]);
        }

        $this->game->clearContext(self::CTX);
        // A battle never grants the optional card draw (rulebook: only place/move "and didn't
        // start a battle" does), so the turn ends here regardless of outcome.
        return NextPlayer::class;
    }

    function zombie(int $playerId)
    {
        $ids = $this->battleCardIds($playerId);
        if (empty($ids)) {
            // No battle card in hand at all: forfeit is the only option. Treat it as an
            // automatic loss for whichever side this zombie player is on.
            $ctx = $this->game->getContext(self::CTX);
            $isAttacker = $playerId === $ctx['attacker'];
            $this->game->clearContext(self::CTX);
            if ($isAttacker) {
                $this->game->removeStain($ctx['fromCellId']);
            } else {
                $this->game->removeStain($ctx['toCellId']);
                $this->game->moveStainCell($ctx['fromCellId'], $ctx['toCellId']);
            }
            return NextPlayer::class;
        }
        return $this->actChooseBattleCard($ids[array_rand($ids)], $playerId);
    }
}
