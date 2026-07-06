<?php

declare(strict_types=1);

namespace AndyDefer\Task\Strategies;

use AndyDefer\Task\Contracts\Directives\WatchLoopStrategyInterface;
use AndyDefer\Task\Enums\WatchMode;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use Illuminate\Support\Carbon;

/**
 * Testing strategy for the watch loop.
 *
 * Executes tasks in-process for development and testing purposes.
 * Simulates time progression using Carbon's test mode.
 */
final class TestingWatchStrategy implements WatchLoopStrategyInterface
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
        $currentTime = Carbon::now();
        $newTime = $currentTime->addSeconds((int) $interval->getValue());
        Carbon::setTestNow($newTime);
    }

    /**
     * {@inheritDoc}
     */
    public function getMode(): WatchMode
    {
        return WatchMode::TESTING;
    }
}
