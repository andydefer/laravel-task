<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services\Watchs;

use AndyDefer\DomainStructures\Utils\StrictAssociative;
use AndyDefer\Task\Enums\TaskType;
use AndyDefer\Task\Records\CycleHistoryRecord;
use AndyDefer\Task\Records\DetailedSummaryRecord;
use AndyDefer\Task\Records\SummaryTotalsRecord;
use AndyDefer\Task\Records\SummaryTypeRecord;
use AndyDefer\Task\Records\TaskExecutionResultRecord;
use AndyDefer\Task\ValueObjects\CounterVO;

/**
 * Aggregates results from multiple task execution cycles.
 *
 * Collects and summarizes success, failure, and error counts across
 * multiple execution cycles and task types (unique and recurring).
 * Maintains per-cycle history for detailed analysis.
 */
final class ResultAggregator
{
    /**
     * @var array<int, CycleHistoryRecord>
     */
    private array $cycleHistory = [];

    private int $cycleCount = 0;

    private int $totalSuccess = 0;

    private int $totalFailed = 0;

    private int $totalErrors = 0;

    private int $uniqueSuccess = 0;

    private int $uniqueFailed = 0;

    private int $recurringSuccess = 0;

    private int $recurringFailed = 0;

    /**
     * Start a new execution cycle.
     *
     * Increments the cycle counter and initializes history for the new cycle.
     */
    public function startNewCycle(): void
    {
        $this->cycleCount++;

        $this->cycleHistory[$this->cycleCount] = new CycleHistoryRecord(
            success: 0,
            failed: 0,
            errors: 0,
            unique_success: 0,
            unique_failed: 0,
            recurring_success: 0,
            recurring_failed: 0,
        );
    }

    /**
     * Add results from a set of task executions.
     *
     * Extracts success, failure, and error counts from each result record
     * and aggregates them by task type.
     *
     * @param  array<TaskExecutionResultRecord>  $results  The execution results to aggregate
     */
    public function addResults(array $results): void
    {
        $cycleSuccess = 0;
        $cycleFailed = 0;
        $cycleErrors = 0;
        $cycleUniqueSuccess = 0;
        $cycleUniqueFailed = 0;
        $cycleRecurringSuccess = 0;
        $cycleRecurringFailed = 0;

        foreach ($results as $result) {
            if (! $result instanceof TaskExecutionResultRecord) {
                continue;
            }

            $success = $result->success->getValue();
            $failed = $result->failed->getValue();
            $errors = $result->errors->count();

            $this->totalSuccess += $success;
            $this->totalFailed += $failed;
            $this->totalErrors += $errors;

            $cycleSuccess += $success;
            $cycleFailed += $failed;
            $cycleErrors += $errors;

            $hasTypeCounts = $result->type_counts !== null && $result->type_counts instanceof StrictAssociative;
            $hasFailedCounts = $result->failed_counts !== null && $result->failed_counts instanceof StrictAssociative;

            if ($hasTypeCounts) {
                $uniqueSuccess = $result->type_counts->get('unique', 0);
                $recurringSuccess = $result->type_counts->get('recurring', 0);

                $this->uniqueSuccess += $uniqueSuccess;
                $cycleUniqueSuccess += $uniqueSuccess;

                $this->recurringSuccess += $recurringSuccess;
                $cycleRecurringSuccess += $recurringSuccess;

                if ($hasFailedCounts) {
                    $uniqueFailed = $result->failed_counts->get('unique', 0);
                    $recurringFailed = $result->failed_counts->get('recurring', 0);

                    $this->uniqueFailed += $uniqueFailed;
                    $cycleUniqueFailed += $uniqueFailed;

                    $this->recurringFailed += $recurringFailed;
                    $cycleRecurringFailed += $recurringFailed;
                }

            } elseif ($result->type === TaskType::UNIQUE) {
                $this->uniqueSuccess += $success;
                $this->uniqueFailed += $failed;
                $cycleUniqueSuccess += $success;
                $cycleUniqueFailed += $failed;

            } elseif ($result->type === TaskType::RECURRING) {
                $this->recurringSuccess += $success;
                $this->recurringFailed += $failed;
                $cycleRecurringSuccess += $success;
                $cycleRecurringFailed += $failed;
            }
        }

        if (isset($this->cycleHistory[$this->cycleCount])) {
            $history = $this->cycleHistory[$this->cycleCount];

            $this->cycleHistory[$this->cycleCount] = new CycleHistoryRecord(
                success: $history->success + $cycleSuccess,
                failed: $history->failed + $cycleFailed,
                errors: $history->errors + $cycleErrors,
                unique_success: $history->unique_success + $cycleUniqueSuccess,
                unique_failed: $history->unique_failed + $cycleUniqueFailed,
                recurring_success: $history->recurring_success + $cycleRecurringSuccess,
                recurring_failed: $history->recurring_failed + $cycleRecurringFailed,
            );
        }
    }

    /**
     * Get the total number of cycles executed.
     *
     * @return int The cycle count
     */
    public function getCycleCount(): int
    {
        return $this->cycleCount;
    }

    /**
     * Get the total number of successful task executions.
     *
     * @return CounterVO The total success count
     */
    public function getTotalSuccess(): CounterVO
    {
        return new CounterVO($this->totalSuccess);
    }

    /**
     * Get the total number of failed task executions.
     *
     * @return CounterVO The total failure count
     */
    public function getTotalFailed(): CounterVO
    {
        return new CounterVO($this->totalFailed);
    }

    /**
     * Get the total number of errors encountered.
     *
     * @return CounterVO The total error count
     */
    public function getTotalErrors(): CounterVO
    {
        return new CounterVO($this->totalErrors);
    }

    /**
     * Get the number of successful unique task executions.
     *
     * @return CounterVO The unique task success count
     */
    public function getUniqueSuccess(): CounterVO
    {
        return new CounterVO($this->uniqueSuccess);
    }

    /**
     * Get the number of failed unique task executions.
     *
     * @return CounterVO The unique task failure count
     */
    public function getUniqueFailed(): CounterVO
    {
        return new CounterVO($this->uniqueFailed);
    }

    /**
     * Get the number of successful recurring task executions.
     *
     * @return CounterVO The recurring task success count
     */
    public function getRecurringSuccess(): CounterVO
    {
        return new CounterVO($this->recurringSuccess);
    }

    /**
     * Get the number of failed recurring task executions.
     *
     * @return CounterVO The recurring task failure count
     */
    public function getRecurringFailed(): CounterVO
    {
        return new CounterVO($this->recurringFailed);
    }

    /**
     * Get the success count for a specific cycle.
     *
     * @param  int  $cycleNumber  The cycle number (1-indexed)
     * @return CounterVO The success count for that cycle
     */
    public function getCycleSuccess(int $cycleNumber): CounterVO
    {
        if (! isset($this->cycleHistory[$cycleNumber])) {
            return new CounterVO(0);
        }

        return new CounterVO($this->cycleHistory[$cycleNumber]->success);
    }

    /**
     * Get the failure count for a specific cycle.
     *
     * @param  int  $cycleNumber  The cycle number (1-indexed)
     * @return CounterVO The failure count for that cycle
     */
    public function getCycleFailed(int $cycleNumber): CounterVO
    {
        if (! isset($this->cycleHistory[$cycleNumber])) {
            return new CounterVO(0);
        }

        return new CounterVO($this->cycleHistory[$cycleNumber]->failed);
    }

    /**
     * Get the error count for a specific cycle.
     *
     * @param  int  $cycleNumber  The cycle number (1-indexed)
     * @return CounterVO The error count for that cycle
     */
    public function getCycleErrors(int $cycleNumber): CounterVO
    {
        if (! isset($this->cycleHistory[$cycleNumber])) {
            return new CounterVO(0);
        }

        return new CounterVO($this->cycleHistory[$cycleNumber]->errors);
    }

    /**
     * Get the unique task success count for a specific cycle.
     *
     * @param  int  $cycleNumber  The cycle number (1-indexed)
     * @return CounterVO The unique task success count for that cycle
     */
    public function getCycleUniqueSuccess(int $cycleNumber): CounterVO
    {
        if (! isset($this->cycleHistory[$cycleNumber])) {
            return new CounterVO(0);
        }

        return new CounterVO($this->cycleHistory[$cycleNumber]->unique_success);
    }

    /**
     * Get the unique task failure count for a specific cycle.
     *
     * @param  int  $cycleNumber  The cycle number (1-indexed)
     * @return CounterVO The unique task failure count for that cycle
     */
    public function getCycleUniqueFailed(int $cycleNumber): CounterVO
    {
        if (! isset($this->cycleHistory[$cycleNumber])) {
            return new CounterVO(0);
        }

        return new CounterVO($this->cycleHistory[$cycleNumber]->unique_failed);
    }

    /**
     * Get the recurring task success count for a specific cycle.
     *
     * @param  int  $cycleNumber  The cycle number (1-indexed)
     * @return CounterVO The recurring task success count for that cycle
     */
    public function getCycleRecurringSuccess(int $cycleNumber): CounterVO
    {
        if (! isset($this->cycleHistory[$cycleNumber])) {
            return new CounterVO(0);
        }

        return new CounterVO($this->cycleHistory[$cycleNumber]->recurring_success);
    }

    /**
     * Get the recurring task failure count for a specific cycle.
     *
     * @param  int  $cycleNumber  The cycle number (1-indexed)
     * @return CounterVO The recurring task failure count for that cycle
     */
    public function getCycleRecurringFailed(int $cycleNumber): CounterVO
    {
        if (! isset($this->cycleHistory[$cycleNumber])) {
            return new CounterVO(0);
        }

        return new CounterVO($this->cycleHistory[$cycleNumber]->recurring_failed);
    }

    /**
     * Check if any failures or errors have been recorded.
     *
     * @return bool True if there are failures or errors
     */
    public function hasFailures(): bool
    {
        return $this->totalFailed > 0 || $this->totalErrors > 0;
    }

    /**
     * Get a detailed summary of all aggregated results.
     *
     * Returns a DetailedSummaryRecord with breakdowns by task type.
     *
     * @return DetailedSummaryRecord The detailed summary
     */
    public function getDetailedSummary(): DetailedSummaryRecord
    {
        return new DetailedSummaryRecord(
            total: new SummaryTotalsRecord(
                success: $this->totalSuccess,
                failed: $this->totalFailed,
                errors: $this->totalErrors,
            ),
            unique: new SummaryTypeRecord(
                success: $this->uniqueSuccess,
                failed: $this->uniqueFailed,
            ),
            recurring: new SummaryTypeRecord(
                success: $this->recurringSuccess,
                failed: $this->recurringFailed,
            ),
        );
    }

    /**
     * Get the complete cycle history.
     *
     * @return array<int, CycleHistoryRecord>
     */
    public function getCycleHistory(): array
    {
        return $this->cycleHistory;
    }

    /**
     * Reset all aggregated data.
     */
    public function reset(): void
    {
        $this->cycleHistory = [];
        $this->cycleCount = 0;
        $this->totalSuccess = 0;
        $this->totalFailed = 0;
        $this->totalErrors = 0;
        $this->uniqueSuccess = 0;
        $this->uniqueFailed = 0;
        $this->recurringSuccess = 0;
        $this->recurringFailed = 0;
    }
}
