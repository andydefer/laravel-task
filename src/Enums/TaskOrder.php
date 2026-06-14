<?php

declare(strict_types=1);

namespace AndyDefer\Task\Enums;

/**
 * Represents the order for processing tasks.
 *
 * Defines the sorting strategy when fetching tasks:
 * - OLDEST: Process tasks in FIFO order (first created, first executed)
 * - NEWEST: Process tasks in LIFO order (last created, first executed)
 *
 * @author Andy Defer
 */
enum TaskOrder: string
{
    case OLDEST = 'oldest';
    case NEWEST = 'newest';

    /**
     * Returns a human-readable label for the order.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::OLDEST => 'Oldest First (FIFO)',
            self::NEWEST => 'Newest First (LIFO)',
        };
    }

    /**
     * Checks if order is oldest (FIFO).
     */
    public function isOldest(): bool
    {
        return $this === self::OLDEST;
    }

    /**
     * Checks if order is newest (LIFO).
     */
    public function isNewest(): bool
    {
        return $this === self::NEWEST;
    }

    /**
     * Compares two timestamps according to the order.
     *
     * @param  int  $timeA  First timestamp
     * @param  int  $timeB  Second timestamp
     * @return int Negative if A < B, positive if A > B, 0 if equal
     */
    public function compare(int $timeA, int $timeB): int
    {
        if ($timeA === $timeB) {
            return 0;
        }

        return $this->isOldest() ? $timeA - $timeB : $timeB - $timeA;
    }
}
