<?php

declare(strict_types=1);

namespace AndyDefer\Task\Enums;

/**
 * Represents the type of task directory.
 *
 * Defines the storage location for different task states:
 * - pending: Tasks waiting to be executed
 * - recurring: Tasks that repeat on a schedule
 * - completed: Archived tasks (successful or failed)
 * - unique: Unique tasks (one-time execution)
 *
 * @author Andy Defer
 */
enum TaskType: string
{
    case PENDING = 'pending';
    case RECURRING = 'recurring';
    case COMPLETED = 'completed';
    case UNIQUE = 'unique';

    /**
     * Returns a human-readable label for the task type.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::RECURRING => 'Recurring',
            self::COMPLETED => 'Completed',
            self::UNIQUE => 'Unique',
        };
    }

    /**
     * Checks if this is the pending type.
     */
    public function isPending(): bool
    {
        return $this === self::PENDING;
    }

    /**
     * Checks if this is the recurring type.
     */
    public function isRecurring(): bool
    {
        return $this === self::RECURRING;
    }

    /**
     * Checks if this is the completed type.
     */
    public function isCompleted(): bool
    {
        return $this === self::COMPLETED;
    }

    /**
     * Checks if this is the unique type.
     */
    public function isUnique(): bool
    {
        return $this === self::UNIQUE;
    }
}
