<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Services;

use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\Task\Records\CycleResultRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;

interface WatchServiceInterface
{
    public function buildArguments(
        bool $uniqueOnly,
        bool $recurringOnly,
        ?LimitVO $limit,
        bool $verbose
    ): StringTypedCollection;

    public function executeCycle(
        CounterVO $cycleNumber,
        StringTypedCollection $arguments,
        Iso8601DateTimeVO $cycleStartedAt
    ): CycleResultRecord;

    public function shouldContinue(
        bool $shouldStop,
        ?DurationVO $duration,
        ?Iso8601DateTimeVO $startedAt
    ): bool;

    public function waitForInterval(DurationVO $interval, callable $shouldContinueCallback): void;

    public function calculateElapsedSeconds(?Iso8601DateTimeVO $start): float;

    public function formatDuration(DurationVO $seconds): string;
}
