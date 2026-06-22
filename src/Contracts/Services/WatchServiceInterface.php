<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Services;

use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\Task\Records\CycleResultRecord;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;

interface WatchServiceInterface
{
    public function buildArguments(
        bool $uniqueOnly,
        bool $recurringOnly,
        ?int $limit,
        bool $verbose
    ): StringTypedCollection;

    public function executeCycle(
        int $cycleNumber,
        StringTypedCollection $arguments,
        Iso8601DateTimeVO $cycleStartedAt
    ): CycleResultRecord;

    public function shouldContinue(
        bool $shouldStop,
        ?int $duration,
        ?Iso8601DateTimeVO $startedAt
    ): bool;

    public function waitForInterval(int $interval, callable $shouldContinueCallback): void;

    public function calculateElapsedSeconds(?Iso8601DateTimeVO $start): float;

    public function formatDuration(int $seconds): string;
}
