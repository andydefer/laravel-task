<?php

declare(strict_types=1);

namespace AndyDefer\Task\Enums;

/**
 * Represents the mode of batch processing.
 *
 * @author Andy Defer
 */
enum BatchMode: string
{
    case FULL = 'full';
    case UNIQUE_ONLY = 'unique_only';
    case RECURRING_ONLY = 'recurring_only';

    public function getLabel(): string
    {
        return match ($this) {
            self::FULL => 'Full (Unique + Recurring)',
            self::UNIQUE_ONLY => 'Unique Tasks Only',
            self::RECURRING_ONLY => 'Recurring Tasks Only',
        };
    }

    public function isFull(): bool
    {
        return $this === self::FULL;
    }

    public function isUniqueOnly(): bool
    {
        return $this === self::UNIQUE_ONLY;
    }

    public function isRecurringOnly(): bool
    {
        return $this === self::RECURRING_ONLY;
    }
}
