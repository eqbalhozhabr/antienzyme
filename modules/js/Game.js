/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * AntiEnzyme implementation : (c) Eqbal Hozhabrosadati
 *
 * This code has been produced on the BGA studio platform for use on http://boardgamearena.com.
 * See http://en.boardgamearena.com/#!doc/Studio for more information.
 * -----
 *
 * Front-end skeleton: one State class per PHP game state, wired up to the action buttons the
 * status bar can offer today (no board rendering yet -- that needs the real board/card SVG
 * assets, which is a separate follow-up step once the PHP rules engine is validated).
 */

class PlayerTurn {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args, isCurrentPlayerActive) {
        this.bga.statusBar.setTitle(isCurrentPlayerActive ?
            _('${you} must place a stain, move a stain, or play an Enzyme card') :
            _('${actplayer} must place a stain, move a stain, or play an Enzyme card')
        );

        if (!isCurrentPlayerActive) {
            return;
        }

        // TODO: replace these placeholder buttons with real board-cell clicks once the board UI
        // exists. For now this at least makes every state reachable/testable end to end.
        args.emptyOuterCells.forEach(cellId =>
            this.bga.statusBar.addActionButton(_('Place stain on ${cell}').replace('${cell}', cellId), () =>
                this.bga.actions.performAction('actPlaceStain', { cellId })
            )
        );
        args.playableEnzymeCardIds.forEach(cardId =>
            this.bga.statusBar.addActionButton(_('Play Enzyme card ${id}').replace('${id}', cardId), () =>
                this.bga.actions.performAction('actPlayEnzymeCard', { card_id: cardId })
            )
        );
    }

    onLeavingState() {}
    onPlayerActivationChange() {}
}

class OptionalDraw {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args, isCurrentPlayerActive) {
        this.bga.statusBar.setTitle(isCurrentPlayerActive ?
            _('${you} may draw a card (2+ stains on the same number)') :
            _('${actplayer} may draw a card')
        );
        if (!isCurrentPlayerActive) {
            return;
        }
        this.bga.statusBar.addActionButton(_('Draw a card'), () => this.bga.actions.performAction('actDrawCard'));
        this.bga.statusBar.addActionButton(_('Skip'), () => this.bga.actions.performAction('actSkipDraw'), { color: 'secondary' });
    }

    onLeavingState() {}
    onPlayerActivationChange() {}
}

class DiscardExcess {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args, isCurrentPlayerActive) {
        this.bga.statusBar.setTitle(_('${actplayer} must discard down to the hand limit'));
        if (!isCurrentPlayerActive) {
            return;
        }
        (args.handCardIds || []).forEach(cardId =>
            this.bga.statusBar.addActionButton(_('Discard card ${id}').replace('${id}', cardId), () =>
                this.bga.actions.performAction('actDiscardCard', { card_id: cardId })
            )
        );
    }

    onLeavingState() {}
    onPlayerActivationChange() {}
}

class Battle {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args, isCurrentPlayerActive) {
        this.bga.statusBar.setTitle(_('A battle is underway -- both sides choose a card secretly'));
        const myId = this.bga.players?.me?.id;
        const myCardIds = (args.playableBattleCardIds || {})[myId];
        if (!isCurrentPlayerActive || !myCardIds) {
            return;
        }
        myCardIds.forEach(cardId =>
            this.bga.statusBar.addActionButton(_('Play battle card ${id}').replace('${id}', cardId), () =>
                this.bga.actions.performAction('actChooseBattleCard', { card_id: cardId })
            )
        );
    }

    onLeavingState() {}
    onPlayerActivationChange(args, isCurrentPlayerActive) {
        this.onEnteringState(args, isCurrentPlayerActive);
    }
}

class EnzymeResponse {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }

    onEnteringState(args, isCurrentPlayerActive) {
        this.bga.statusBar.setTitle(_('Reveal Anti-Enzyme to protect your stains, or decline'));
        if (!isCurrentPlayerActive) {
            return;
        }
        this.bga.statusBar.addActionButton(_('Reveal Anti-Enzyme'), () => this.bga.actions.performAction('actRevealAntiEnzyme'));
        this.bga.statusBar.addActionButton(_('Decline'), () => this.bga.actions.performAction('actDeclineProtect'), { color: 'secondary' });
    }

    onLeavingState() {}
    onPlayerActivationChange(args, isCurrentPlayerActive) {
        this.onEnteringState(args, isCurrentPlayerActive);
    }
}

class WashingMachinePhase {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }
    onEnteringState() {
        this.bga.statusBar.setTitle(_('The washing machine churns...'));
    }
    onLeavingState() {}
}

class NextRound {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
    }
    onEnteringState() {
        this.bga.statusBar.setTitle(_('Starting the next round...'));
    }
    onLeavingState() {}
}

export class Game {
    constructor(bga) {
        console.log('antienzyme constructor');
        this.bga = bga;

        this.playerTurn = new PlayerTurn(this, bga);
        this.bga.states.register('PlayerTurn', this.playerTurn);
        this.bga.states.register('OptionalDraw', new OptionalDraw(this, bga));
        this.bga.states.register('DiscardExcess', new DiscardExcess(this, bga));
        this.bga.states.register('Battle', new Battle(this, bga));
        this.bga.states.register('EnzymeResponse', new EnzymeResponse(this, bga));
        this.bga.states.register('WashingMachinePhase', new WashingMachinePhase(this, bga));
        this.bga.states.register('NextRound', new NextRound(this, bga));

        // Uncomment to see state-change debug info in the console:
        // this.bga.states.logger = console.log;
    }

    /*
        setup:

        Called once when the game interface is displayed (game start, or F5 refresh).
        "gamedatas" contains everything Game::getAllDatas() returned.

        TODO: this is intentionally minimal -- the real board (rotating rings, wedge art, cards)
        needs the actual SVG/graphic assets before it can be built, which is a separate follow-up
        step. For now this just proves the data makes it to the client so states can be exercised
        end to end.
    */
    setup(gamedatas) {
        console.log('Starting game setup', gamedatas);
        this.gamedatas = gamedatas;

        this.bga.gameArea.getElement().insertAdjacentHTML('beforeend', `
            <div id="ae-board-placeholder">
                <p>${_('Board + card art coming soon. Current state:')}</p>
                <div id="ae-stains"></div>
            </div>
        `);
        this.renderStainsPlaceholder();

        this.setupNotifications();

        console.log('Ending game setup');
    }

    renderStainsPlaceholder() {
        const el = document.getElementById('ae-stains');
        if (!el) return;
        const stains = Object.values(this.gamedatas.stains || {});
        el.innerHTML = stains.map(s => `<div>${s.cellId}: player ${s.playerId}</div>`).join('') ||
            `<div>${_('No stains on the board yet.')}</div>`;
    }

    ///////////////////////////////////////////////////
    //// Reaction to cometD notifications

    setupNotifications() {
        console.log('notifications subscriptions setup');
        this.bga.notifications.setupPromiseNotifications({
            // logger: console.log
        });
    }

    async notif_stainPlaced(args) {
        (this.gamedatas.stains ||= {})[Object.keys(this.gamedatas.stains || {}).length] = {
            playerId: args.player_id,
            cellId: args.cellId,
        };
        this.renderStainsPlaceholder();
    }

    async notif_stainMoved(args) {
        const stain = Object.values(this.gamedatas.stains || {}).find(s => s.cellId === args.fromCellId);
        if (stain) {
            stain.cellId = args.toCellId;
        }
        this.renderStainsPlaceholder();
    }

    async notif_stainsWashedAway(args) {
        const removed = new Set(args.cellIds || []);
        this.gamedatas.stains = Object.fromEntries(
            Object.entries(this.gamedatas.stains || {}).filter(([, s]) => !removed.has(s.cellId))
        );
        this.renderStainsPlaceholder();
    }
}
