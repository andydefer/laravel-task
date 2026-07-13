<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services\Watchs;

use AndyDefer\Task\Enums\TaskType;
use AndyDefer\Task\Records\TaskExecutionResultRecord;
use AndyDefer\Task\ValueObjects\CounterVO;

final class ResultAggregator
{
    private int $cycleCount = 0;

    private int $totalSuccess = 0;

    private int $totalFailed = 0;

    private int $totalErrors = 0;

    private int $uniqueSuccess = 0;

    private int $uniqueFailed = 0;

    private int $recurringSuccess = 0;

    private int $recurringFailed = 0;

    public function startNewCycle(): void
    {
        $this->cycleCount++;
    }

    public function addResults(array $results): void
    {
        foreach ($results as $result) {
            if ($result instanceof TaskExecutionResultRecord) {
                $success = $result->success->getValue();
                $failed = $result->failed->getValue();
                $errors = $result->errors->count();

                $this->totalSuccess += $success;
                $this->totalFailed += $failed;
                $this->totalErrors += $errors;

                if ($result->type === TaskType::UNIQUE) {
                    $this->uniqueSuccess += $success;
                    $this->uniqueFailed += $failed;
                } elseif ($result->type === TaskType::RECURRING) {
                    $this->recurringSuccess += $success;
                    $this->recurringFailed += $failed;
                }
            }
        }
    }

    public function getCycleCount(): int
    {
        return $this->cycleCount;
    }

    public function getTotalSuccess(): CounterVO
    {
        return new CounterVO($this->totalSuccess);
    }

    public function getTotalFailed(): CounterVO
    {
        return new CounterVO($this->totalFailed);
    }

    public function getTotalErrors(): CounterVO
    {
        return new CounterVO($this->totalErrors);
    }

    public function getUniqueSuccess(): CounterVO
    {
        return new CounterVO($this->uniqueSuccess);
    }

    public function getUniqueFailed(): CounterVO
    {
        return new CounterVO($this->uniqueFailed);
    }

    public function getRecurringSuccess(): CounterVO
    {
        return new CounterVO($this->recurringSuccess);
    }

    public function getRecurringFailed(): CounterVO
    {
        return new CounterVO($this->recurringFailed);
    }

    public function hasFailures(): bool
    {
        return $this->totalFailed > 0;
    }
}
