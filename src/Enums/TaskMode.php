<?php

declare(strict_types=1);

namespace AndyDefer\Task\Enums;

use AndyDefer\DomainStructures\Traits\Enumable;

/**
 * Defines the execution mode for a task.
 *
 * Determines whether a task should be executed immediately in the current
 * process or deferred for asynchronous execution via a poller/worker.
 *
 * @see TaskStatus For task lifecycle status
 */
enum TaskMode: string
{
    use Enumable;

    case SYNC = 'sync';
    case DEFER = 'defer';

    /**
     * Returns a human-readable label for the mode.
     *
     * @return string Localized or user-friendly label
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::SYNC => 'Synchronous',
            self::DEFER => 'Deferred',
        };
    }

    /**
     * Checks if the mode is synchronous execution.
     *
     * Synchronous tasks execute immediately in the same process and block
     * until completion.
     *
     * @return bool True if mode is SYNC, false otherwise
     */
    public function isSync(): bool
    {
        return $this === self::SYNC;
    }

    /**
     * Checks if the mode is deferred execution.
     *
     * Deferred tasks are queued for asynchronous execution by a poller or
     * worker process. The calling code continues immediately without waiting.
     *
     * @return bool True if mode is DEFER, false otherwise
     */
    public function isDefer(): bool
    {
        return $this === self::DEFER;
    }
}
