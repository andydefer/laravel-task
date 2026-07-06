<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Directives;

use AndyDefer\Task\Enums\WatchMode;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;

/**
 * Interface for watch loop strategies.
 *
 * Defines the contract for different execution strategies
 * in the task watch loop.
 */
interface WatchLoopStrategyInterface
{
    /**
     * Determines whether the watch loop should continue.
     *
     * @param  bool  $shouldStop  Whether a stop signal was received
     * @param  DurationVO|null  $duration  Maximum duration (null = unlimited)
     * @param  Iso8601DateTimeVO|null  $startedAt  When the watch started
     * @return bool True if the loop should continue
     */
    public function shouldContinue(
        bool $shouldStop,
        ?DurationVO $duration,
        ?Iso8601DateTimeVO $startedAt
    ): bool;

    /**
     * Waits for the specified interval.
     *
     * @param  DurationVO  $interval  The interval to wait
     */
    public function waitForInterval(DurationVO $interval): void;

    /**
     * Gets the current watch mode.
     *
     * @return WatchMode The current mode
     */
    public function getMode(): WatchMode;
}
