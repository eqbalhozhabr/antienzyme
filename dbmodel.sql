
-- ------
-- BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
-- AntiEnzyme implementation : (c) Eqbal Hozhabrosadati
--
-- This code has been produced on the BGA studio platform for use on http://boardgamearena.com.
-- See http://en.boardgamearena.com/#!doc/Studio for more information.
-- -----

-- Note: The database schema is created from this file when the game starts. If you modify this file,
--       you have to restart a game to see your changes in database.

-- `stain` : one row per stain cube currently on the board (cubes still in a player's personal
-- supply, not yet placed, are not represented here at all -- they are simply implied by
-- "50 / number of players" minus the rows already in this table for that player).
--
-- `cell_id` identifies a board cell as "<wedge_id>#<number>", e.g. "R1_BlueTop#3", or the
-- single literal "Hat" for the immune center cell. See modules/php/Board.php for the full
-- topology (which wedges exist, which ring they belong to, which numbers they carry).
CREATE TABLE IF NOT EXISTS `stain` (
  `stain_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `player_id` INT UNSIGNED NOT NULL,
  `cell_id` VARCHAR(32) NOT NULL,
  PRIMARY KEY (`stain_id`),
  UNIQUE KEY `cell_unique` (`cell_id`),
  KEY `player_id` (`player_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1;

-- `card` : both the 51-card Stain deck (Enzyme/Anti-Enzyme/Battle cards, dealt to player hands)
-- and the 29-card Washing Machine deck (drawn face-up, never held in hand) live in this same
-- table, distinguished by `card_deck`. This mirrors the classic BGA "Deck" component pattern.
--
-- card_deck = 'stain':
--   card_type is one of: enzyme_1..enzyme_5, anti_enzyme, protease, amylase, lipase
--   card_location is one of: 'deck' (face-down draw pile), 'hand' (card_location_arg = player_id),
--     'discard', or 'battle' (played face-down as part of an in-progress Battle, card_location_arg
--     = player_id of the player who played it, revealed and moved to 'discard' once the battle resolves)
--
-- card_deck = 'wm':
--   card_type is the washing machine card id, '1'..'29' (see data/washing_machine_cards.json)
--   card_location is one of: 'deck', 'removed' (the 9 cards set aside at setup and never used),
--     'discard' (already resolved this game)
--
-- card_location_arg is used as the hand owner (player_id) for 'hand', and otherwise as a simple
-- ordering/shuffle index so cards can be drawn in a stable, shuffled order (lowest arg drawn first).
CREATE TABLE IF NOT EXISTS `card` (
  `card_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `card_deck` VARCHAR(8) NOT NULL,
  `card_type` VARCHAR(16) NOT NULL,
  `card_location` VARCHAR(16) NOT NULL,
  `card_location_arg` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`card_id`),
  KEY `card_deck_location` (`card_deck`, `card_location`, `card_location_arg`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1;

-- `global_data` : small self-contained key/value store for short-lived cross-state context that
-- isn't a simple int (a pending Battle's two cell ids, a pending wash's target list, etc.). Values
-- are JSON-encoded. This sidesteps depending on the exact shape of the framework's own
-- globals/state-argument helpers, which weren't available to check against while writing this
-- skeleton offline -- feel free to replace with the framework's native equivalent if it turns out
-- to offer the same thing more directly.
CREATE TABLE IF NOT EXISTS `global_data` (
  `name` VARCHAR(64) NOT NULL,
  `value` TEXT,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
