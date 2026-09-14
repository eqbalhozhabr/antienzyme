<?php

declare(strict_types=1);

namespace Bga\Games\AntiEnzyme;

/**
 * Static description of the Anti-Enzyme board and the geometry rules derived from it.
 *
 * The board has 5-fold radial symmetry: 5 "slots" (0..4) arranged in a circle, 72 degrees apart.
 * Three concentric rings of clothing wedges sit on that circle, plus a single immune center cell
 * ("Hat"):
 *
 *   - Ring 1 (outermost) : 5 wedges, each showing all 5 numbers (1-5). Rotates during the Washing
 *     Machine Phase by the amount/direction shown on the drawn card.
 *   - Ring 2 (middle)    : 5 wedges, each showing only 3 of the 5 numbers. NEVER rotates -- it is
 *     the fixed reference frame everything else moves against. Its wedges are centered exactly
 *     halfway between two Ring-1 home slots (a permanent 36 degree / half-wedge offset), which is
 *     why the board art looks like a pinwheel/spiral.
 *   - Ring 3 (innermost) : 5 wedges, each showing only 2 of the 5 numbers. Rotates during the
 *     Washing Machine Phase by the same amount as Ring 1, but in the OPPOSITE direction. Shares
 *     Ring 1's home-slot grid (both start aligned with slot 0 at the top).
 *   - Center ("Hat")     : 1 cell, no printed number, worth 10 points. Immune to player-played
 *     Enzyme cards; only a handful of specific Washing Machine cards can wash it (see
 *     data/washing_machine_cards.json, cards whose "number" is "HAT").
 *
 * Because rotation always moves a ring by a whole number of 72-degree steps, the angular offset
 * between Ring 1/3 and the fixed Ring 2 is *always* exactly half a wedge (36 degrees), no matter
 * how much rotation has happened. That means any given Ring-1 (or Ring-3) wedge always straddles
 * the boundary between exactly two Ring-2 wedges -- confirmed by the designer: moving a stain
 * inward lets the player pick which of the two adjacent garments to move it into.
 */
class Board
{
    public const RING_OUTER = 1;
    public const RING_MIDDLE = 2;
    public const RING_INNER = 3;
    public const HAT = 'Hat';

    /** Points scored per stain, by ring (0 is used for the center Hat). */
    public const POINTS_BY_RING = [
        self::RING_OUTER => 1,
        self::RING_MIDDLE => 3,
        self::RING_INNER => 5,
        0 => 10, // center
    ];

    /**
     * All 15 clothing wedges. `slot` is the wedge's home position (0..4) on its ring's own
     * angular grid -- see class docblock for how Ring 2's grid relates to Ring 1/3's.
     * `numbers` are the enzyme numbers printed on that wedge's cells (read off the board art).
     */
    public const WEDGES = [
        // Ring 1 -- outer, rotates with the card's rotationAmount/rotationArrow.
        'R1_BlueTop'    => ['ring' => self::RING_OUTER, 'slot' => 0, 'numbers' => [1, 2, 3, 4, 5]],
        'R1_Pink'       => ['ring' => self::RING_OUTER, 'slot' => 1, 'numbers' => [1, 2, 3, 4, 5]],
        'R1_BlueBottom' => ['ring' => self::RING_OUTER, 'slot' => 2, 'numbers' => [1, 2, 3, 4, 5]],
        'R1_GreenBL'    => ['ring' => self::RING_OUTER, 'slot' => 3, 'numbers' => [1, 2, 3, 4, 5]],
        'R1_Olive'      => ['ring' => self::RING_OUTER, 'slot' => 4, 'numbers' => [1, 2, 3, 4, 5]],

        // Ring 2 -- middle, fixed forever. slot j sits between Ring-1/3 physical slots j and j+1.
        'R2_Green'    => ['ring' => self::RING_MIDDLE, 'slot' => 0, 'numbers' => [1, 2, 5]],
        'R2_Mustard'  => ['ring' => self::RING_MIDDLE, 'slot' => 1, 'numbers' => [2, 3, 4]],
        'R2_Red'      => ['ring' => self::RING_MIDDLE, 'slot' => 2, 'numbers' => [1, 4, 5]],
        'R2_Orange'   => ['ring' => self::RING_MIDDLE, 'slot' => 3, 'numbers' => [1, 2, 3]],
        'R2_Lavender' => ['ring' => self::RING_MIDDLE, 'slot' => 4, 'numbers' => [3, 4, 5]],

        // Ring 3 -- inner, rotates opposite to Ring 1. Shares Ring 1's home-slot grid.
        'R3_RedMitten'   => ['ring' => self::RING_INNER, 'slot' => 0, 'numbers' => [1, 2]],
        'R3_BlueGlove'   => ['ring' => self::RING_INNER, 'slot' => 1, 'numbers' => [4, 5]],
        'R3_GraySock'    => ['ring' => self::RING_INNER, 'slot' => 2, 'numbers' => [2, 3]],
        'R3_GreenMitten' => ['ring' => self::RING_INNER, 'slot' => 3, 'numbers' => [1, 5]],
        'R3_KhakiGlove'  => ['ring' => self::RING_INNER, 'slot' => 4, 'numbers' => [3, 4]],
    ];

    /** @return string[] all wedge ids belonging to a given ring (1, 2 or 3). */
    public static function wedgesInRing(int $ring): array
    {
        return array_keys(array_filter(self::WEDGES, fn($w) => $w['ring'] === $ring));
    }

    /** @return int[] the enzyme numbers printed on a wedge's cells. */
    public static function numbersOf(string $wedgeId): array
    {
        return self::WEDGES[$wedgeId]['numbers'];
    }

    /** Build a cell id like "R1_BlueTop#3" for a numbered cell, or Board::HAT for the center. */
    public static function cellId(string $wedgeId, int $number): string
    {
        return "{$wedgeId}#{$number}";
    }

    /** @return array{0:string,1:?int} [wedgeId, number] parsed back out of a cell id. Number is null for the Hat. */
    public static function parseCellId(string $cellId): array
    {
        if ($cellId === self::HAT) {
            return [self::HAT, null];
        }
        [$wedgeId, $number] = explode('#', $cellId, 2);
        return [$wedgeId, (int) $number];
    }

    /** @return string[] every cell id that exists on the board (25 + 15 + 10 + 1 = 51). */
    public static function allCellIds(): array
    {
        $cells = [self::HAT];
        foreach (self::WEDGES as $wedgeId => $wedge) {
            foreach ($wedge['numbers'] as $n) {
                $cells[] = self::cellId($wedgeId, $n);
            }
        }
        return $cells;
    }

    /**
     * The wedge's current physical slot (0..4), after applying the ring's current rotation
     * offset. Ring 2 never rotates, so its physical slot always equals its home slot.
     */
    public static function physicalSlot(string $wedgeId, int $ring1Offset, int $ring3Offset): int
    {
        $wedge = self::WEDGES[$wedgeId];
        $offset = match ($wedge['ring']) {
            self::RING_OUTER => $ring1Offset,
            self::RING_INNER => -$ring3Offset, // opposite direction from ring 1
            default => 0,
        };
        return (($wedge['slot'] + $offset) % 5 + 5) % 5;
    }

    /**
     * The wedge currently sitting at a given (ring, physical slot).
     */
    public static function wedgeAt(int $ring, int $physicalSlot, int $ring1Offset, int $ring3Offset): string
    {
        foreach (self::wedgesInRing($ring) as $wedgeId) {
            if (self::physicalSlot($wedgeId, $ring1Offset, $ring3Offset) === $physicalSlot) {
                return $wedgeId;
            }
        }
        throw new \LogicException("No wedge found in ring $ring at slot $physicalSlot");
    }

    /**
     * The wedge(s) one step inward from $wedgeId, given the current rotation of the board.
     * - From Ring 1 or Ring 3: exactly 2 candidate Ring-2 wedges (see class docblock).
     * - From Ring 2: exactly 2 candidate Ring-3 wedges (symmetric relationship).
     * - From Ring 3: always just [Board::HAT] (every Ring-3 wedge touches the center).
     *
     * @return string[]
     */
    public static function inwardOptions(string $wedgeId, int $ring1Offset, int $ring3Offset): array
    {
        $ring = self::WEDGES[$wedgeId]['ring'];

        if ($ring === self::RING_INNER) {
            return [self::HAT];
        }

        if ($ring === self::RING_OUTER) {
            $s = self::physicalSlot($wedgeId, $ring1Offset, $ring3Offset);
            // Ring-2 wedge j sits between Ring-1 physical slots j and j+1, so Ring-1 slot s
            // touches Ring-2 wedges (s-1) and s (their home slot doubling as their fixed physical slot).
            return [
                self::wedgeAt(self::RING_MIDDLE, (($s - 1) % 5 + 5) % 5, 0, 0),
                self::wedgeAt(self::RING_MIDDLE, $s, 0, 0),
            ];
        }

        // Ring 2 -> Ring 3: symmetric to the Ring 1 -> Ring 2 case, but the neighbors are
        // wherever those Ring-3 wedges currently physically sit (Ring 3 rotates).
        $j = self::WEDGES[$wedgeId]['slot']; // ring 2's slot is always its physical slot
        return [
            self::wedgeAt(self::RING_INNER, $j, $ring1Offset, $ring3Offset),
            self::wedgeAt(self::RING_INNER, ($j + 1) % 5, $ring1Offset, $ring3Offset),
        ];
    }
}
