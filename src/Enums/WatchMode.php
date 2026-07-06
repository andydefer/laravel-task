<?php

declare(strict_types=1);

namespace AndyDefer\Task\Enums;

/**
 * Enum for watch loop modes.
 *
 * Defines the available modes for the task watch loop execution.
 */
enum WatchMode: string
{
    /**
     * Production mode - spawns real processes for task execution.
     */
    case PRODUCTION = 'PRODUCTION';

    /**
     * Testing mode - executes tasks in-process for development and testing.
     */
    case TESTING = 'TESTING';

    /**
     * Get the display label for the mode.
     *
     * @return string The display label
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::PRODUCTION => 'PRODUCTION',
            self::TESTING => 'TESTING',
        };
    }

    /**
     * Check if the mode is production.
     *
     * @return bool True if production mode
     */
    public function isProduction(): bool
    {
        return $this === self::PRODUCTION;
    }

    /**
     * Check if the mode is testing.
     *
     * @return bool True if testing mode
     */
    public function isTesting(): bool
    {
        return $this === self::TESTING;
    }
}
