<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Services\Watchs;

use AndyDefer\Task\Collections\CycleHistoryRecordCollection;
use AndyDefer\Task\Collections\TaskExecutionResultRecordCollection;
use AndyDefer\Task\Records\DetailedSummaryRecord;
use AndyDefer\Task\ValueObjects\CounterVO;

/**
 * Interface for aggregating results from multiple task execution cycles.
 */
interface ResultAggregatorInterface
{
    /**
     * Start a new execution cycle.
     */
    public function startNewCycle(): void;

    /**
     * Add results from a set of task executions.
     *
     * @param  TaskExecutionResultRecordCollection  $results  The execution results to aggregate
     */
    public function addResults(TaskExecutionResultRecordCollection $results): void;

    /**
     * Get the total number of cycles executed.
     *
     * @return int The cycle count
     */
    public function getCycleCount(): int;

    /**
     * Get the total number of successful task executions.
     *
     * @return CounterVO The total success count
     */
    public function getTotalSuccess(): CounterVO;

    /**
     * Get the total number of failed task executions.
     *
     * @return CounterVO The total failure count
     */
    public function getTotalFailed(): CounterVO;

    /**
     * Get the total number of errors encountered.
     *
     * @return CounterVO The total error count
     */
    public function getTotalErrors(): CounterVO;

    /**
     * Get the number of successful unique task executions.
     *
     * @return CounterVO The unique task success count
     */
    public function getUniqueSuccess(): CounterVO;

    /**
     * Get the number of failed unique task executions.
     *
     * @return CounterVO The unique task failure count
     */
    public function getUniqueFailed(): CounterVO;

    /**
     * Get the number of successful recurring task executions.
     *
     * @return CounterVO The recurring task success count
     */
    public function getRecurringSuccess(): CounterVO;

    /**
     * Get the number of failed recurring task executions.
     *
     * @return CounterVO The recurring task failure count
     */
    public function getRecurringFailed(): CounterVO;

    /**
     * Get the success count for a specific cycle.
     *
     * @param  int  $cycleNumber  The cycle number (1-indexed)
     * @return CounterVO The success count for that cycle
     */
    public function getCycleSuccess(int $cycleNumber): CounterVO;

    /**
     * Get the failure count for a specific cycle.
     *
     * @param  int  $cycleNumber  The cycle number (1-indexed)
     * @return CounterVO The failure count for that cycle
     */
    public function getCycleFailed(int $cycleNumber): CounterVO;

    /**
     * Get the error count for a specific cycle.
     *
     * @param  int  $cycleNumber  The cycle number (1-indexed)
     * @return CounterVO The error count for that cycle
     */
    public function getCycleErrors(int $cycleNumber): CounterVO;

    /**
     * Get the unique task success count for a specific cycle.
     *
     * @param  int  $cycleNumber  The cycle number (1-indexed)
     * @return CounterVO The unique task success count for that cycle
     */
    public function getCycleUniqueSuccess(int $cycleNumber): CounterVO;

    /**
     * Get the unique task failure count for a specific cycle.
     *
     * @param  int  $cycleNumber  The cycle number (1-indexed)
     * @return CounterVO The unique task failure count for that cycle
     */
    public function getCycleUniqueFailed(int $cycleNumber): CounterVO;

    /**
     * Get the recurring task success count for a specific cycle.
     *
     * @param  int  $cycleNumber  The cycle number (1-indexed)
     * @return CounterVO The recurring task success count for that cycle
     */
    public function getCycleRecurringSuccess(int $cycleNumber): CounterVO;

    /**
     * Get the recurring task failure count for a specific cycle.
     *
     * @param  int  $cycleNumber  The cycle number (1-indexed)
     * @return CounterVO The recurring task failure count for that cycle
     */
    public function getCycleRecurringFailed(int $cycleNumber): CounterVO;

    /**
     * Check if any failures or errors have been recorded.
     *
     * @return bool True if there are failures or errors
     */
    public function hasFailures(): bool;

    /**
     * Get a detailed summary of all aggregated results.
     *
     * @return DetailedSummaryRecord The detailed summary
     */
    public function getDetailedSummary(): DetailedSummaryRecord;

    /**
     * Get the complete cycle history.
     *
     * @return CycleHistoryRecordCollection The cycle history collection
     */
    public function getCycleHistory(): CycleHistoryRecordCollection;

    /**
     * Reset all aggregated data.
     */
    public function reset(): void;
}
