<?php

declare(strict_types=1);

namespace AndyDefer\Task\Strategies;

use AndyDefer\Task\Contracts\Directives\WatchLoopStrategyInterface;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;

final class ProductionWatchStrategy implements WatchLoopStrategyInterface
{
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

    public function waitForInterval(DurationVO $interval): void
    {
        sleep((int) $interval->getValue());
    }

    public function isTesting(): bool
    {
        return false;
    }

    public function getModeLabel(): string
    {
        return 'PRODUCTION';
    }
}
