<?php

declare(strict_types=1);

namespace AndyDefer\Task\Executors;

use AndyDefer\Task\Contracts\Services\WatchInterface;
use AndyDefer\Task\Contracts\Services\WatchRendererInterface;
use AndyDefer\Task\Records\CycleResultRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;

/**
 * Executes a single cycle of task processing.
 *
 * Orchestrates the execution of a watch cycle by delegating to the service
 * and rendering the results.
 */
final class CycleExecutor
{
    /**
     * Constructor for the cycle executor.
     *
     * @param  WatchInterface  $service  The watch service for task processing
     * @param  WatchRendererInterface  $renderer  The renderer for output display
     */
    public function __construct(
        private readonly WatchInterface $service,
        private readonly WatchRendererInterface $renderer
    ) {}

    /**
     * Executes a single cycle of task processing.
     *
     * @param  CounterVO  $cycleCount  The current cycle number
     * @param  bool  $hasOptionUniqueOnly  Whether to process only unique tasks
     * @param  bool  $hasOptionRecurringOnly  Whether to process only recurring tasks
     * @param  LimitVO|null  $limit  Optional limit on tasks to process
     * @param  bool  $verbose  Whether verbose output is enabled
     * @param  bool  $shouldStop  Whether the cycle should be skipped due to stop signal
     * @param  DurationVO  $intervalSeconds  The interval between cycles
     * @return CycleResultRecord|null The cycle result or null if stopped
     */
    public function execute(
        CounterVO $cycleCount,
        bool $hasOptionUniqueOnly,
        bool $hasOptionRecurringOnly,
        ?LimitVO $limit,
        bool $verbose,
        bool $shouldStop,
        DurationVO $intervalSeconds
    ): ?CycleResultRecord {
        if ($shouldStop) {
            return null;
        }

        $cycleStartedAt = new Iso8601DateTimeVO;
        $this->renderer->renderCycleStart($cycleCount, $cycleStartedAt);

        $arguments = $this->service->buildArguments(
            uniqueOnly: $hasOptionUniqueOnly,
            recurringOnly: $hasOptionRecurringOnly,
            limit: $limit,
            verbose: $verbose
        );

        $result = $this->service->executeCycle(
            $cycleCount,
            $arguments,
            $cycleStartedAt
        );

        $this->renderer->renderCycleEnd($result, $cycleStartedAt, $intervalSeconds);

        return $result;
    }
}
