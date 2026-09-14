<?php

declare(strict_types=1);

namespace Bga\Games\AntiEnzyme;

/**
 * Static data for the 51-card Stain deck (Enzyme, Anti-Enzyme and Battle cards, all shuffled
 * together into one shared deck -- see setup step 3 in the rulebook: "Shuffle the Stain deck.
 * Deal 3 cards to each player."). Counts confirmed by the designer's own spreadsheet.
 */
class StainCards
{
    public const TYPE_ANTI_ENZYME = 'anti_enzyme';
    public const TYPE_PROTEASE = 'protease';
    public const TYPE_AMYLASE = 'amylase';
    public const TYPE_LIPASE = 'lipase';

    public const ENZYME_TYPES = ['enzyme_1', 'enzyme_2', 'enzyme_3', 'enzyme_4', 'enzyme_5'];
    public const BATTLE_TYPES = [self::TYPE_PROTEASE, self::TYPE_AMYLASE, self::TYPE_LIPASE];

    /** Quantity of each card type in the 51-card deck. */
    public const DECK_COMPOSITION = [
        'enzyme_1' => 3,
        'enzyme_2' => 3,
        'enzyme_3' => 3,
        'enzyme_4' => 3,
        'enzyme_5' => 3,
        self::TYPE_ANTI_ENZYME => 18,
        self::TYPE_AMYLASE => 6,
        self::TYPE_LIPASE => 6,
        self::TYPE_PROTEASE => 6,
    ];

    public const HAND_LIMIT = 6;

    /** The enzyme number (1-5) an 'enzyme_N' card type washes. */
    public static function enzymeNumber(string $cardType): int
    {
        return (int) substr($cardType, strlen('enzyme_'));
    }

    /**
     * Battle resolution: Protease beats Amylase, Amylase beats Lipase, Lipase beats Protease
     * ("rock-paper-scissors, but slimier"). Returns true if $a beats $b, false if it's a tie or
     * $a loses -- call it both ways to fully resolve a battle.
     */
    public static function beats(string $a, string $b): bool
    {
        $beats = [
            self::TYPE_PROTEASE => self::TYPE_AMYLASE,
            self::TYPE_LIPASE => self::TYPE_PROTEASE,
            self::TYPE_AMYLASE => self::TYPE_LIPASE,
        ];
        return $beats[$a] === $b;
    }
}
