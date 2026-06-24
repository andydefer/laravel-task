<?php

declare(strict_types=1);

namespace AndyDefer\Task\Enums;

/**
 * Represents the type of task.
 * - recurring: Tasks that repeat on a schedule
 * - unique: Unique tasks (one-time execution)
 *
 * @author Andy Defer
 */
enum TaskType: string
{
    case RECURRING = 'recurring';
    case UNIQUE = 'unique';

    /**
     * Returns a human-readable label for the task type.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::RECURRING => 'Recurring',
            self::UNIQUE => 'Unique',
        };
    }

    /**
     * Checks if this is the recurring type.
     */
    public function isRecurring(): bool
    {
        return $this === self::RECURRING;
    }

    /**
     * Checks if this is the unique type.
     */
    public function isUnique(): bool
    {
        return $this === self::UNIQUE;
    }
}
