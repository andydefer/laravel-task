<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services\Watchs;

use AndyDefer\DomainStructures\Utils\StrictAssociative;
use AndyDefer\Task\Collections\CycleHistoryRecordCollection;
use AndyDefer\Task\Collections\TaskExecutionResultRecordCollection;
use AndyDefer\Task\Contracts\Services\Watchs\ResultAggregatorInterface;
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
final class ResultAggregator implements ResultAggregatorInterface
{
    private CycleHistoryRecordCollection $cycleHistory;

    private int $cycleCount = 0;

    private int $totalSuccess = 0;

    private int $totalFailed = 0;

    private int $totalErrors = 0;

    private int $uniqueSuccess = 0;

    private int $uniqueFailed = 0;

    private int $recurringSuccess = 0;

    private int $recurringFailed = 0;

    public function __construct()
    {
        $this->cycleHistory = new CycleHistoryRecordCollection;
    }

    /**
     * {@inheritDoc}
     */
    public function startNewCycle(): void
    {
        $this->cycleCount++;

        $this->cycleHistory->add(
            new CycleHistoryRecord(
                success: 0,
                failed: 0,
                errors: 0,
                unique_success: 0,
                unique_failed: 0,
                recurring_success: 0,
                recurring_failed: 0,
            )
        );
    }

    /**
     * {@inheritDoc}
     */
    public function addResults(TaskExecutionResultRecordCollection $results): void
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

        if ($this->cycleCount > 0) {
            $history = $this->cycleHistory->last();

            $this->cycleHistory->offsetSet(
                $this->cycleCount - 1,
                new CycleHistoryRecord(
                    success: $history->success + $cycleSuccess,
                    failed: $history->failed + $cycleFailed,
                    errors: $history->errors + $cycleErrors,
                    unique_success: $history->unique_success + $cycleUniqueSuccess,
                    unique_failed: $history->unique_failed + $cycleUniqueFailed,
                    recurring_success: $history->recurring_success + $cycleRecurringSuccess,
                    recurring_failed: $history->recurring_failed + $cycleRecurringFailed,
                )
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getCycleCount(): int
    {
        return $this->cycleCount;
    }

    /**
     * {@inheritDoc}
     */
    public function getTotalSuccess(): CounterVO
    {
        return new CounterVO($this->totalSuccess);
    }

    /**
     * {@inheritDoc}
     */
    public function getTotalFailed(): CounterVO
    {
        return new CounterVO($this->totalFailed);
    }

    /**
     * {@inheritDoc}
     */
    public function getTotalErrors(): CounterVO
    {
        return new CounterVO($this->totalErrors);
    }

    /**
     * {@inheritDoc}
     */
    public function getUniqueSuccess(): CounterVO
    {
        return new CounterVO($this->uniqueSuccess);
    }

    /**
     * {@inheritDoc}
     */
    public function getUniqueFailed(): CounterVO
    {
        return new CounterVO($this->uniqueFailed);
    }

    /**
     * {@inheritDoc}
     */
    public function getRecurringSuccess(): CounterVO
    {
        return new CounterVO($this->recurringSuccess);
    }

    /**
     * {@inheritDoc}
     */
    public function getRecurringFailed(): CounterVO
    {
        return new CounterVO($this->recurringFailed);
    }

    /**
     * {@inheritDoc}
     */
    public function getCycleSuccess(int $cycleNumber): CounterVO
    {
        $index = $cycleNumber - 1;
        if (! $this->cycleHistory->offsetExists($index)) {
            return new CounterVO(0);
        }

        return new CounterVO($this->cycleHistory->offsetGet($index)->success);
    }

    /**
     * {@inheritDoc}
     */
    public function getCycleFailed(int $cycleNumber): CounterVO
    {
        $index = $cycleNumber - 1;
        if (! $this->cycleHistory->offsetExists($index)) {
            return new CounterVO(0);
        }

        return new CounterVO($this->cycleHistory->offsetGet($index)->failed);
    }

    /**
     * {@inheritDoc}
     */
    public function getCycleErrors(int $cycleNumber): CounterVO
    {
        $index = $cycleNumber - 1;
        if (! $this->cycleHistory->offsetExists($index)) {
            return new CounterVO(0);
        }

        return new CounterVO($this->cycleHistory->offsetGet($index)->errors);
    }

    /**
     * {@inheritDoc}
     */
    public function getCycleUniqueSuccess(int $cycleNumber): CounterVO
    {
        $index = $cycleNumber - 1;
        if (! $this->cycleHistory->offsetExists($index)) {
            return new CounterVO(0);
        }

        return new CounterVO($this->cycleHistory->offsetGet($index)->unique_success);
    }

    /**
     * {@inheritDoc}
     */
    public function getCycleUniqueFailed(int $cycleNumber): CounterVO
    {
        $index = $cycleNumber - 1;
        if (! $this->cycleHistory->offsetExists($index)) {
            return new CounterVO(0);
        }

        return new CounterVO($this->cycleHistory->offsetGet($index)->unique_failed);
    }

    /**
     * {@inheritDoc}
     */
    public function getCycleRecurringSuccess(int $cycleNumber): CounterVO
    {
        $index = $cycleNumber - 1;
        if (! $this->cycleHistory->offsetExists($index)) {
            return new CounterVO(0);
        }

        return new CounterVO($this->cycleHistory->offsetGet($index)->recurring_success);
    }

    /**
     * {@inheritDoc}
     */
    public function getCycleRecurringFailed(int $cycleNumber): CounterVO
    {
        $index = $cycleNumber - 1;
        if (! $this->cycleHistory->offsetExists($index)) {
            return new CounterVO(0);
        }

        return new CounterVO($this->cycleHistory->offsetGet($index)->recurring_failed);
    }

    /**
     * {@inheritDoc}
     */
    public function hasFailures(): bool
    {
        return $this->totalFailed > 0 || $this->totalErrors > 0;
    }

    /**
     * {@inheritDoc}
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
     * {@inheritDoc}
     */
    public function getCycleHistory(): CycleHistoryRecordCollection
    {
        return $this->cycleHistory;
    }

    /**
     * {@inheritDoc}
     */
    public function reset(): void
    {
        $this->cycleHistory = new CycleHistoryRecordCollection;
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
