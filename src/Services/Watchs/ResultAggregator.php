<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services\Watchs;

use AndyDefer\Task\Records\LoopResultRecord;
use AndyDefer\Task\Records\TaskExecutionResultRecord;
use AndyDefer\Task\ValueObjects\CounterVO;

final class ResultAggregator
{
    private CounterVO $totalSuccess;

    private CounterVO $totalFailed;

    private CounterVO $totalErrors;

    private int $cycleCount;

    public function __construct()
    {
        $this->totalSuccess = new CounterVO(0);
        $this->totalFailed = new CounterVO(0);
        $this->totalErrors = new CounterVO(0);
        $this->cycleCount = 0;
    }

    public function addResult(TaskExecutionResultRecord $result): self
    {
        $this->totalSuccess = $this->totalSuccess->add($result->success);
        $this->totalFailed = $this->totalFailed->add($result->failed);
        $this->totalErrors = $this->totalErrors->add(new CounterVO($result->errors->count()));
        $this->cycleCount++;

        return $this;
    }

    public function addResults(array $results): self
    {
        foreach ($results as $result) {
            if ($result instanceof TaskExecutionResultRecord) {
                $this->addResult($result);
            }
        }

        return $this;
    }

    public function getTotalSuccess(): CounterVO
    {
        return $this->totalSuccess;
    }

    public function getTotalFailed(): CounterVO
    {
        return $this->totalFailed;
    }

    public function getTotalErrors(): CounterVO
    {
        return $this->totalErrors;
    }

    public function getCycleCount(): int
    {
        return $this->cycleCount;
    }

    public function hasFailures(): bool
    {
        return $this->totalFailed->isPositive() || $this->totalErrors->isPositive();
    }

    public function reset(): self
    {
        $this->totalSuccess = new CounterVO(0);
        $this->totalFailed = new CounterVO(0);
        $this->totalErrors = new CounterVO(0);
        $this->cycleCount = 0;

        return $this;
    }

    public function toLoopResultRecord(): LoopResultRecord
    {
        return LoopResultRecord::from([
            'cycle_count' => new CounterVO($this->cycleCount),
            'total_success' => $this->totalSuccess,
            'total_failed' => $this->totalFailed,
            'total_errors' => $this->totalErrors,
            'has_errors' => $this->hasFailures(),
            'last_exception' => null,
        ]);
    }
}
