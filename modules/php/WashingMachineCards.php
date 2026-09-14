<?php

declare(strict_types=1);

namespace Bga\Games\AntiEnzyme;

/**
 * Static data for the 29 unique Washing Machine cards, transcribed by hand from the card artwork
 * (see data/washing_machine_cards.json in the repo root for the original extraction notes and
 * confidence caveats -- keep the two files in sync if either is corrected).
 *
 * Each card:
 *  - `number`: the enzyme number it washes (1-5), or the string 'HAT' for the 4 special cards
 *    that wash the center instead (their top-left box shows a cap icon instead of a digit).
 *  - `rotationAmount`: how many 72-degree steps Ring 1 rotates (Ring 3 rotates the same amount
 *    the opposite way; Ring 2 never moves).
 *  - `rotationArrow`: 'up' or 'down', matching the two visually distinct hook-arrow icons on the
 *    cards ('up' points toward the top-right, 'down' toward the bottom-right). Confirmed by the
 *    designer: 'down' is clockwise, 'up' is counterclockwise -- see self::isClockwise() below.
 *  - `activeWedges`: wedge ids (see Board::WEDGES) whose stains matching `number` get washed.
 *    Stains with a matching number on a wedge NOT listed here are unaffected by this card.
 *
 * Cards 25-29 are flagged `confidence => 'medium'`: their active-wedge combinations were denser
 * and harder to read than the others and should be spot-checked against the source images
 * (Google Drive folder "WashingMachine Cards") before this data is treated as final.
 */
class WashingMachineCards
{
    /**
     * Confirmed by the designer: the hook-arrow pointing toward the bottom-right ('down') is
     * clockwise; the one pointing toward the top-right ('up') is counterclockwise. Everything
     * else in the codebase should call self::isClockwise($card) rather than comparing
     * rotationArrow directly, so this is the only place that would ever need to change.
     */
    public static function isClockwise(array $card): bool
    {
        return $card['rotationArrow'] === 'down';
    }

    public const CARDS = [
        1  => ['number' => 1,     'rotationAmount' => 2, 'rotationArrow' => 'up',   'activeWedges' => ['R1_Olive', 'R1_Pink', 'R2_Green']],
        2  => ['number' => 1,     'rotationAmount' => 1, 'rotationArrow' => 'up',   'activeWedges' => ['R1_BlueTop', 'R1_Pink', 'R1_BlueBottom', 'R1_GreenBL', 'R1_Olive']],
        3  => ['number' => 1,     'rotationAmount' => 1, 'rotationArrow' => 'down', 'activeWedges' => ['R2_Lavender', 'R2_Green', 'R2_Red']],
        4  => ['number' => 1,     'rotationAmount' => 2, 'rotationArrow' => 'down', 'activeWedges' => ['R3_RedMitten', 'R3_BlueGlove', 'R3_GraySock', 'R3_GreenMitten', 'R3_KhakiGlove']],
        5  => ['number' => 2,     'rotationAmount' => 2, 'rotationArrow' => 'up',   'activeWedges' => ['R1_Olive', 'R1_Pink', 'R2_Green']],
        6  => ['number' => 2,     'rotationAmount' => 1, 'rotationArrow' => 'up',   'activeWedges' => ['R1_BlueTop', 'R1_Pink', 'R1_BlueBottom', 'R1_GreenBL', 'R1_Olive']],
        7  => ['number' => 2,     'rotationAmount' => 1, 'rotationArrow' => 'down', 'activeWedges' => ['R2_Lavender', 'R2_Green', 'R2_Red']],
        8  => ['number' => 2,     'rotationAmount' => 2, 'rotationArrow' => 'down', 'activeWedges' => ['R3_RedMitten', 'R3_BlueGlove', 'R3_GraySock', 'R3_GreenMitten', 'R3_KhakiGlove']],
        9  => ['number' => 3,     'rotationAmount' => 2, 'rotationArrow' => 'up',   'activeWedges' => ['R1_Pink', 'R1_GreenBL', 'R2_Lavender', 'R3_KhakiGlove']],
        10 => ['number' => 3,     'rotationAmount' => 1, 'rotationArrow' => 'up',   'activeWedges' => ['R1_BlueTop', 'R1_Pink', 'R1_BlueBottom', 'R1_GreenBL', 'R1_Olive']],
        11 => ['number' => 3,     'rotationAmount' => 1, 'rotationArrow' => 'down', 'activeWedges' => ['R2_Lavender', 'R2_Green', 'R2_Red']],
        12 => ['number' => 3,     'rotationAmount' => 2, 'rotationArrow' => 'down', 'activeWedges' => ['R3_RedMitten', 'R3_BlueGlove', 'R3_GraySock', 'R3_GreenMitten', 'R3_KhakiGlove']],
        13 => ['number' => 4,     'rotationAmount' => 2, 'rotationArrow' => 'up',   'activeWedges' => ['R1_Pink', 'R1_GreenBL', 'R2_Lavender', 'R3_KhakiGlove']],
        14 => ['number' => 4,     'rotationAmount' => 1, 'rotationArrow' => 'up',   'activeWedges' => ['R1_BlueTop', 'R1_Pink', 'R1_BlueBottom', 'R1_GreenBL', 'R1_Olive']],
        15 => ['number' => 4,     'rotationAmount' => 1, 'rotationArrow' => 'down', 'activeWedges' => ['R2_Lavender', 'R2_Green', 'R2_Red']],
        16 => ['number' => 4,     'rotationAmount' => 2, 'rotationArrow' => 'down', 'activeWedges' => ['R3_RedMitten', 'R3_BlueGlove', 'R3_GraySock', 'R3_GreenMitten', 'R3_KhakiGlove']],
        17 => ['number' => 5,     'rotationAmount' => 2, 'rotationArrow' => 'up',   'activeWedges' => ['R1_BlueTop', 'R3_BlueGlove']],
        18 => ['number' => 5,     'rotationAmount' => 1, 'rotationArrow' => 'up',   'activeWedges' => ['R1_Olive', 'R1_Pink', 'R2_Green']],
        19 => ['number' => 5,     'rotationAmount' => 1, 'rotationArrow' => 'down', 'activeWedges' => ['R2_Lavender', 'R2_Green', 'R2_Red']],
        20 => ['number' => 5,     'rotationAmount' => 2, 'rotationArrow' => 'down', 'activeWedges' => ['R3_RedMitten', 'R3_BlueGlove', 'R3_GraySock', 'R3_GreenMitten', 'R3_KhakiGlove']],
        21 => ['number' => 'HAT', 'rotationAmount' => 2, 'rotationArrow' => 'up',   'activeWedges' => ['Hat']],
        22 => ['number' => 'HAT', 'rotationAmount' => 1, 'rotationArrow' => 'up',   'activeWedges' => ['Hat']],
        23 => ['number' => 'HAT', 'rotationAmount' => 1, 'rotationArrow' => 'down', 'activeWedges' => ['Hat']],
        24 => ['number' => 'HAT', 'rotationAmount' => 2, 'rotationArrow' => 'down', 'activeWedges' => ['Hat']],
        25 => ['number' => 1,     'rotationAmount' => 2, 'rotationArrow' => 'up',   'activeWedges' => ['R1_BlueTop', 'R1_Olive', 'R2_Orange', 'R2_Red', 'R3_GreenMitten'], 'confidence' => 'medium'],
        26 => ['number' => 2,     'rotationAmount' => 1, 'rotationArrow' => 'down', 'activeWedges' => ['R1_BlueTop', 'R2_Orange', 'R2_Mustard', 'R2_Lavender', 'R2_Red'], 'confidence' => 'medium'],
        27 => ['number' => 3,     'rotationAmount' => 1, 'rotationArrow' => 'down', 'activeWedges' => ['R1_BlueTop', 'R2_Orange', 'R2_Mustard', 'R2_Lavender', 'R2_Red'], 'confidence' => 'medium'],
        28 => ['number' => 4,     'rotationAmount' => 2, 'rotationArrow' => 'down', 'activeWedges' => ['R1_BlueTop', 'R3_BlueGlove', 'R2_Red'], 'confidence' => 'medium'],
        29 => ['number' => 5,     'rotationAmount' => 2, 'rotationArrow' => 'up',   'activeWedges' => ['R1_BlueTop', 'R3_BlueGlove', 'R3_GreenMitten', 'R2_Red'], 'confidence' => 'medium'],
    ];
}
