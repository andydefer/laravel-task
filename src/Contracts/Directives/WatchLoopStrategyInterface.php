<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Directives;

use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;

interface WatchLoopStrategyInterface
{
    public function shouldContinue(
        bool $shouldStop,
        ?DurationVO $duration,
        ?Iso8601DateTimeVO $startedAt
    ): bool;

    public function waitForInterval(DurationVO $interval): void;

    public function isTesting(): bool;

    public function getModeLabel(): string;
}
