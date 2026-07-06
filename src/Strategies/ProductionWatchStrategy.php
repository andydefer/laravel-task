<?php

declare(strict_types=1);

namespace AndyDefer\Task\Strategies;

use AndyDefer\Task\Contracts\Directives\WatchLoopStrategyInterface;
use AndyDefer\Task\Enums\WatchMode;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;

/**
 * Production strategy for the watch loop.
 *
 * Executes tasks in a production environment by spawning real processes
 * and waiting with standard sleep intervals.
 */
final class ProductionWatchStrategy implements WatchLoopStrategyInterface
{
    /**
     * {@inheritDoc}
     */
    public function shouldContinue(
        bool $shouldStop,
        ?DurationVO $duration,
        ?Iso8601DateTimeVO $startedAt
    ): bool {
        if ($shouldStop) {
            return false;
        }

        if ($duration === null || $startedAt === null) {
            return true;
        }

        return $startedAt->elapsed()->getValue() < $duration->getValue();
    }

    /**
     * {@inheritDoc}
     */
    public function waitForInterval(DurationVO $interval): void
    {
        sleep((int) $interval->getValue());
    }

    /**
     * {@inheritDoc}
     */
    public function getMode(): WatchMode
    {
        return WatchMode::PRODUCTION;
    }
}
