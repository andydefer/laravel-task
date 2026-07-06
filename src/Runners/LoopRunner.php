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

final class LoopRunner
{
    private int $iteration = 0;

    private CounterVO $cycleCount;

    private CounterVO $totalSuccess;

    private CounterVO $totalFailed;

    private CounterVO $totalErrors;

    private bool $hasErrors = false;

    private ?DescriptionVO $lastException = null;

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
        while ($this->shouldContinue($strategy, $duration, $startedAt)) {
            $this->iteration++;
            $this->cycleCount = $this->cycleCount->increment();

            $cycleResult = $this->cycleExecutor->execute(
                $this->cycleCount,
                $hasOptionUniqueOnly,
                $hasOptionRecurringOnly,
                $limit,
                $verbose,
                $this->signalHandler->shouldStop(),
                $intervalSeconds
            );

            if ($cycleResult !== null) {
                $this->processCycleResult($cycleResult);
            }

            if ($this->signalHandler->shouldStop()) {
                break;
            }

            if (! $this->shouldContinue($strategy, $duration, $startedAt)) {
                break;
            }

            $strategy->waitForInterval($intervalSeconds);
        }

        return new LoopResultRecord(
            cycleCount: $this->cycleCount,
            totalSuccess: $this->totalSuccess,
            totalFailed: $this->totalFailed,
            totalErrors: $this->totalErrors,
            hasErrors: $this->hasErrors,
            lastException: $this->lastException
        );
    }

    private function shouldContinue(
        WatchLoopStrategyInterface $strategy,
        ?DurationVO $duration,
        ?Iso8601DateTimeVO $startedAt
    ): bool {
        $this->signalHandler->dispatch();

        return $strategy->shouldContinue($this->signalHandler->shouldStop(), $duration, $startedAt);
    }

    private function processCycleResult(CycleResultRecord $cycleResult): void
    {
        $this->hasErrors = $this->hasErrors || $cycleResult->hasErrors;
        $this->totalSuccess = $this->totalSuccess->add($cycleResult->success);
        $this->totalFailed = $this->totalFailed->add($cycleResult->failed);
        $this->totalErrors = $this->totalErrors->add($cycleResult->errors);
        $this->lastException = $cycleResult->message;
    }
}
