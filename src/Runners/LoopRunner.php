<?php

declare(strict_types=1);

namespace AndyDefer\Task\Runners;

use AndyDefer\Task\Contracts\Directives\WatchLoopStrategyInterface;
use AndyDefer\Task\Contracts\Services\WatchRendererInterface;
use AndyDefer\Task\Executors\CycleExecutor;
use AndyDefer\Task\Handlers\SignalHandler;
use AndyDefer\Task\Records\CycleResultRecord;
use AndyDefer\Task\Records\LoopResultRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;

/**
 * Runs the main watch loop for continuous task processing.
 *
 * Orchestrates the execution of cycles, handles signals,
 * and aggregates results across multiple iterations.
 */
final class LoopRunner
{
    /**
     * @var int The current iteration number
     */
    private int $iteration = 0;

    /**
     * @var CounterVO The current cycle count
     */
    private CounterVO $cycleCount;

    /**
     * @var CounterVO Total successful tasks across all cycles
     */
    private CounterVO $totalSuccess;

    /**
     * @var CounterVO Total failed tasks across all cycles
     */
    private CounterVO $totalFailed;

    /**
     * @var CounterVO Total errors across all cycles
     */
    private CounterVO $totalErrors;

    /**
     * @var bool Whether any errors occurred during the loop
     */
    private bool $hasErrors = false;

    /**
     * @var DescriptionVO|null The last exception message
     */
    private ?DescriptionVO $lastException = null;

    /**
     * Constructor for the loop runner.
     *
     * @param  CycleExecutor  $cycleExecutor  The cycle executor
     * @param  SignalHandler  $signalHandler  The signal handler
     * @param  WatchRendererInterface  $renderer  The renderer for output
     */
    public function __construct(
        private readonly CycleExecutor $cycleExecutor,
        private readonly SignalHandler $signalHandler,
        private readonly WatchRendererInterface $renderer
    ) {
        $this->cycleCount = new CounterVO(0);
        $this->totalSuccess = new CounterVO(0);
        $this->totalFailed = new CounterVO(0);
        $this->totalErrors = new CounterVO(0);
    }

    /**
     * Runs the main watch loop.
     *
     * @param  WatchLoopStrategyInterface  $strategy  The loop strategy
     * @param  bool  $hasOptionUniqueOnly  Whether to process only unique tasks
     * @param  bool  $hasOptionRecurringOnly  Whether to process only recurring tasks
     * @param  LimitVO|null  $limit  Optional limit on tasks to process
     * @param  bool  $verbose  Whether verbose output is enabled
     * @param  DurationVO|null  $duration  Maximum duration (null = unlimited)
     * @param  Iso8601DateTimeVO|null  $startedAt  When the loop started
     * @param  DurationVO  $intervalSeconds  The interval between cycles
     * @return LoopResultRecord The loop execution result
     */
    public function run(
        WatchLoopStrategyInterface $strategy,
        bool $hasOptionUniqueOnly,
        bool $hasOptionRecurringOnly,
        ?LimitVO $limit,
        bool $verbose,
        ?DurationVO $duration,
        ?Iso8601DateTimeVO $startedAt,
        DurationVO $intervalSeconds
    ): LoopResultRecord {
        while ($this->shouldContinueLoop($strategy, $duration, $startedAt)) {
            $this->iteration++;
            $this->cycleCount = $this->cycleCount->increment();

            $cycleResult = $this->executeCycle(
                $hasOptionUniqueOnly,
                $hasOptionRecurringOnly,
                $limit,
                $verbose,
                $intervalSeconds
            );

            if ($cycleResult !== null) {
                $this->aggregateCycleResult($cycleResult);
            }

            if ($this->shouldStopLoop($strategy, $duration, $startedAt)) {
                break;
            }

            $strategy->waitForInterval($intervalSeconds);
        }

        return $this->buildLoopResult();
    }

    /**
     * Checks if the loop should continue.
     *
     * @param  WatchLoopStrategyInterface  $strategy  The loop strategy
     * @param  DurationVO|null  $duration  Maximum duration
     * @param  Iso8601DateTimeVO|null  $startedAt  When the loop started
     * @return bool True if the loop should continue
     */
    private function shouldContinueLoop(
        WatchLoopStrategyInterface $strategy,
        ?DurationVO $duration,
        ?Iso8601DateTimeVO $startedAt
    ): bool {
        $this->signalHandler->dispatch();

        return $strategy->shouldContinue($this->signalHandler->shouldStop(), $duration, $startedAt);
    }

    /**
     * Checks if the loop should stop.
     *
     * @param  WatchLoopStrategyInterface  $strategy  The loop strategy
     * @param  DurationVO|null  $duration  Maximum duration
     * @param  Iso8601DateTimeVO|null  $startedAt  When the loop started
     * @return bool True if the loop should stop
     */
    private function shouldStopLoop(
        WatchLoopStrategyInterface $strategy,
        ?DurationVO $duration,
        ?Iso8601DateTimeVO $startedAt
    ): bool {
        if ($this->signalHandler->shouldStop()) {
            return true;
        }

        return ! $this->shouldContinueLoop($strategy, $duration, $startedAt);
    }

    /**
     * Executes a single cycle.
     *
     * @param  bool  $uniqueOnly  Whether to process only unique tasks
     * @param  bool  $recurringOnly  Whether to process only recurring tasks
     * @param  LimitVO|null  $limit  Optional limit on tasks
     * @param  bool  $verbose  Whether verbose output is enabled
     * @param  DurationVO  $intervalSeconds  The interval between cycles
     * @return CycleResultRecord|null The cycle result or null if stopped
     */
    private function executeCycle(
        bool $uniqueOnly,
        bool $recurringOnly,
        ?LimitVO $limit,
        bool $verbose,
        DurationVO $intervalSeconds
    ): ?CycleResultRecord {
        return $this->cycleExecutor->execute(
            $this->cycleCount,
            $uniqueOnly,
            $recurringOnly,
            $limit,
            $verbose,
            $this->signalHandler->shouldStop(),
            $intervalSeconds
        );
    }

    /**
     * Aggregates a cycle result into the totals.
     *
     * @param  CycleResultRecord  $cycleResult  The cycle result to aggregate
     */
    private function aggregateCycleResult(CycleResultRecord $cycleResult): void
    {
        $this->hasErrors = $this->hasErrors || $cycleResult->hasErrors;
        $this->totalSuccess = $this->totalSuccess->add($cycleResult->success);
        $this->totalFailed = $this->totalFailed->add($cycleResult->failed);
        $this->totalErrors = $this->totalErrors->add($cycleResult->errors);
        $this->lastException = $cycleResult->message;
    }

    /**
     * Builds the loop result record.
     *
     * @return LoopResultRecord The loop result
     */
    private function buildLoopResult(): LoopResultRecord
    {
        return new LoopResultRecord(
            cycleCount: $this->cycleCount,
            totalSuccess: $this->totalSuccess,
            totalFailed: $this->totalFailed,
            totalErrors: $this->totalErrors,
            hasErrors: $this->hasErrors,
            lastException: $this->lastException
        );
    }
}
