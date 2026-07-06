<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Services;

use AndyDefer\Directive\Services\DirectiveTestingService;
use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\Task\Records\CycleResultRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;

/**
 * Interface for the watch service.
 *
 * Provides core functionality for the tasks-watch directive:
 * - Building CLI arguments
 * - Executing process-tasks cycles
 * - Determining if the watch loop should continue
 * - Waiting between cycles
 * - Formatting durations
 *
 * @example
 * $service = app(WatchInterface::class);
 * $args = $service->buildArguments(true, false, new LimitVO(10), true);
 * $result = $service->executeCycle(new CounterVO(1), $args, new Iso8601DateTimeVO);
 */
interface WatchInterface
{
    /**
     * Enables testing mode for the watch service.
     *
     * In testing mode, the service runs process-tasks in-process
     * instead of spawning a real process.
     *
     * @param  DirectiveTestingService  $testingService  The testing service
     */
    public function enableTestingMode(DirectiveTestingService $testingService): void;

    /**
     * Disables testing mode.
     *
     * After this, the service will spawn real processes.
     */
    public function disableTestingMode(): void;

    /**
     * Checks if testing mode is currently enabled.
     *
     * @return bool True if testing mode is enabled
     */
    public function isTestingMode(): bool;

    /**
     * Builds the CLI arguments for the process-tasks directive.
     *
     * @param  bool  $uniqueOnly  Whether to process only unique tasks
     * @param  bool  $recurringOnly  Whether to process only recurring tasks
     * @param  LimitVO|null  $limit  Maximum number of tasks to process
     * @param  bool  $verbose  Whether to enable verbose output
     * @return StringTypedCollection Collection of CLI arguments
     */
    public function buildArguments(
        bool $uniqueOnly,
        bool $recurringOnly,
        ?LimitVO $limit,
        bool $verbose
    ): StringTypedCollection;

    /**
     * Executes a single cycle of process-tasks.
     *
     * @param  CounterVO  $cycleNumber  The current cycle number
     * @param  StringTypedCollection  $arguments  CLI arguments to pass
     * @param  Iso8601DateTimeVO  $cycleStartedAt  When the cycle started
     * @return CycleResultRecord Results of the cycle execution
     */
    public function executeCycle(
        CounterVO $cycleNumber,
        StringTypedCollection $arguments,
        Iso8601DateTimeVO $cycleStartedAt
    ): CycleResultRecord;

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
     * Waits for the specified interval, checking periodically if it should stop.
     *
     * @param  DurationVO  $interval  The interval to wait
     * @param  callable  $shouldContinueCallback  Callback to check if it should continue
     */
    public function waitForInterval(DurationVO $interval, callable $shouldContinueCallback): void;

    /**
     * Calculates the elapsed seconds since a given start time.
     *
     * @param  Iso8601DateTimeVO|null  $start  The start time
     * @return float Elapsed seconds
     */
    public function calculateElapsedSeconds(?Iso8601DateTimeVO $start): float;

    /**
     * Formats a duration as a human-readable string.
     *
     * Example: 5445 seconds → "1h 30m 45s"
     *
     * @param  DurationVO  $duration  The duration to format
     * @return string Formatted duration
     */
    public function formatDuration(DurationVO $duration): string;
}
