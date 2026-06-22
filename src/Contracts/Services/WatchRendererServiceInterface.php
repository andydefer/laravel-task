<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Services;

use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\Task\Records\CycleResultRecord;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;

interface WatchRendererServiceInterface
{
    public function renderStartMessage(
        ?int $duration,
        int $intervalSeconds,
        StringTypedCollection $options,
        bool $testingMode
    ): void;

    public function renderCycleStart(int $cycleNumber, Iso8601DateTimeVO $startedAt): void;

    public function renderCycleEnd(
        CycleResultRecord $result,
        Iso8601DateTimeVO $startedAt,
        int $intervalSeconds
    ): void;

    public function renderSummary(
        int $cycleCount,
        int $totalSuccess,
        int $totalFailed,
        int $totalErrors,
        Iso8601DateTimeVO $startedAt,
        bool $testingMode,
        bool $stoppedBySignal,
        bool $durationReached,
        ?string $exception = null
    ): void;

    public function renderInterruptSignal(string $signalName): void;

    public function renderTestingModeEnabled(): void;
}
