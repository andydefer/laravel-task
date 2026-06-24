<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Services;

use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\Task\Enums\SignalName;
use AndyDefer\Task\Records\CycleResultRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;

interface WatchRendererServiceInterface
{
    public function renderStartMessage(
        ?DurationVO $duration,
        DurationVO $intervalSeconds,
        StringTypedCollection $options,
        bool $testingMode
    ): void;

    public function renderCycleStart(CounterVO $cycleNumber, Iso8601DateTimeVO $startedAt): void;

    public function renderCycleEnd(
        CycleResultRecord $result,
        Iso8601DateTimeVO $startedAt,
        DurationVO $intervalSeconds
    ): void;

    public function renderSummary(
        CounterVO $cycleCount,
        CounterVO $totalSuccess,
        CounterVO $totalFailed,
        CounterVO $totalErrors,
        Iso8601DateTimeVO $startedAt,
        bool $testingMode,
        bool $stoppedBySignal,
        bool $durationReached,
        ?DescriptionVO $exception = null
    ): void;

    public function renderInterruptSignal(SignalName $signalName): void;

    public function renderTestingModeEnabled(): void;
}
