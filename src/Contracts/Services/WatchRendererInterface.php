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

/**
 * Interface for rendering watch loop output.
 *
 * Provides methods for rendering start messages, cycle progress,
 * summaries, and signal notifications during task watch execution.
 */
interface WatchRendererInterface
{
    /**
     * Renders the start message for the watch loop.
     *
     * @param  DurationVO|null  $duration  The maximum duration (null = unlimited)
     * @param  DurationVO  $intervalSeconds  The interval between cycles
     * @param  StringTypedCollection  $options  The active command options
     * @param  bool  $testingMode  Whether testing mode is enabled
     * @param  int|null  $parallelWorkers  Number of parallel workers (null = sequential)
     */
    public function renderStartMessage(
        ?DurationVO $duration,
        DurationVO $intervalSeconds,
        StringTypedCollection $options,
        bool $testingMode,
        ?int $parallelWorkers = null
    ): void;

    /**
     * Renders the start of a cycle.
     *
     * @param  CounterVO  $cycleNumber  The current cycle number
     * @param  Iso8601DateTimeVO  $startedAt  When the cycle started
     */
    public function renderCycleStart(CounterVO $cycleNumber, Iso8601DateTimeVO $startedAt): void;

    /**
     * Renders the end of a cycle.
     *
     * @param  CycleResultRecord  $result  The cycle result
     * @param  Iso8601DateTimeVO  $startedAt  When the cycle started
     * @param  DurationVO  $intervalSeconds  The interval between cycles
     */
    public function renderCycleEnd(
        CycleResultRecord $result,
        Iso8601DateTimeVO $startedAt,
        DurationVO $intervalSeconds
    ): void;

    /**
     * Renders the final summary after the watch loop completes.
     *
     * @param  CounterVO  $cycleCount  Number of cycles executed
     * @param  CounterVO  $totalSuccess  Total successful tasks
     * @param  CounterVO  $totalFailed  Total failed tasks
     * @param  CounterVO  $totalErrors  Total errors
     * @param  Iso8601DateTimeVO  $startedAt  When the watch started
     * @param  bool  $testingMode  Whether testing mode was enabled
     * @param  bool  $stoppedBySignal  Whether a signal stopped the loop
     * @param  bool  $durationReached  Whether the duration limit was reached
     * @param  DescriptionVO|null  $exception  The last exception (if any)
     */
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

    /**
     * Renders a notification when an interrupt signal is received.
     *
     * @param  SignalName  $signalName  The signal that was received
     */
    public function renderInterruptSignal(SignalName $signalName): void;

    /**
     * Renders a notification when testing mode is enabled.
     */
    public function renderTestingModeEnabled(): void;

    /**
     * Renders a notification when parallel execution is enabled.
     *
     * @param  int  $workerCount  The number of parallel workers
     */
    public function renderParallelExecution(int $workerCount): void;
}
