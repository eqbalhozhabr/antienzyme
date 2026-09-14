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
 * "Anti-Enzyme window": one or more players hold an Anti-Enzyme card and have at least one stain
 * that a just-played Enzyme card (or Washing Machine card) is about to wash away. Each of them
 * simultaneously decides whether to reveal their Anti-Enzyme (saving ALL of their own matching
 * stains, and only their own -- "your stains stay safe, everyone else loses theirs") or decline.
 *
 * Context (which number/wedges are being washed, who's still pending, and which state to return
 * to once resolved) is stashed via Game::setContext/getContext by whoever transitions in here --
 * see EnzymeResponse::begin().
 */
class EnzymeResponse extends GameState
{
    private const CTX = 'enzyme_response';

    function __construct(
        protected Game $game,
    ) {
        parent::__construct($game,
            id: 30,
            type: StateType::MULTIPLE_ACTIVE_PLAYER,
        );
    }

    /**
     * @param int[] $pendingPlayerIds players who hold an Anti-Enzyme card and must be asked
     * @param class-string $returnTo the state to transition to once every response is in
     */
    public static function begin(Game $game, int|string $number, array $activeWedges, array $pendingPlayerIds, string $returnTo): void
    {
        $game->setContext(self::CTX, [
            'number' => $number,
            'activeWedges' => $activeWedges,
            'pending' => $pendingPlayerIds,
            'returnTo' => $returnTo,
        ]);
    }

    public function onEnteringState()
    {
        $ctx = $this->game->getContext(self::CTX);
        $this->gamestate->setPlayersMultiactive($ctx['pending'], '', true);
    }

    public function getArgs(): array
    {
        $ctx = $this->game->getContext(self::CTX);
        return ['number' => $ctx['number']];
    }

    #[PossibleAction]
    public function actRevealAntiEnzyme(int $activePlayerId)
    {
        $cardId = $this->game->getUniqueValue(
            "SELECT `card_id` FROM `card` WHERE `card_deck`='stain' AND `card_location`='hand'
             AND `card_location_arg`=$activePlayerId AND `card_type`='" . StainCards::TYPE_ANTI_ENZYME . "' LIMIT 1"
        );
        if ($cardId === null) {
            throw new UserException('You do not have an Anti-Enzyme card');
        }
        $this->game->discardStainCard((int) $cardId);

        $this->bga->notify->all('antiEnzymeRevealed', clienttranslate('${player_name} reveals Anti-Enzyme -- their stains are safe!'), [
            'player_id' => $activePlayerId,
            'player_name' => $this->game->getPlayerNameById($activePlayerId),
        ]);

        return $this->resolvePlayer($activePlayerId, protected: true);
    }

    #[PossibleAction]
    public function actDeclineProtect(int $activePlayerId)
    {
        return $this->resolvePlayer($activePlayerId, protected: false);
    }

    private function resolvePlayer(int $playerId, bool $protected)
    {
        $ctx = $this->game->getContext(self::CTX);

        if (!$protected) {
            $candidates = $this->game->findWashCandidates($ctx['number'], $ctx['activeWedges']);
            foreach ($candidates[$playerId] ?? [] as $cellId) {
                $this->game->removeStain($cellId);
            }
            $this->bga->notify->all('stainsWashedAway', clienttranslate('${player_name} could not protect their stains -- they are washed away'), [
                'player_id' => $playerId,
                'player_name' => $this->game->getPlayerNameById($playerId),
                'cellIds' => $candidates[$playerId] ?? [],
            ]);
        }

        $ctx['pending'] = array_values(array_diff($ctx['pending'], [$playerId]));
        $this->gamestate->setPlayerNonMultiactive($playerId, '');

        if (empty($ctx['pending'])) {
            $returnTo = $ctx['returnTo'];
            $this->game->clearContext(self::CTX);
            return $returnTo;
        }

        $this->game->setContext(self::CTX, $ctx);
        return null; // stay in this state, waiting for the remaining players
    }

    function zombie(int $playerId)
    {
        // A disconnected player never reveals a card they can't be asked about -- decline for them.
        return $this->actDeclineProtect($playerId);
    }
}
