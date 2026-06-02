<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services;

use AndyDefer\Task\Records\BatchResultRecord;
use AndyDefer\Task\Records\RecurringResultRecord;
use AndyDefer\Task\Records\TaskErrorRecord;
use AndyDefer\Task\Records\UniqueResultRecord;

/**
 * Service for building batch results.
 *
 * This service provides immutable operations to add task results to a batch result record.
 * Each operation returns a new instance of BatchResultRecord, preserving immutability.
 *
 * @example
 * $service = new BatchResultService();
 * $record = $service->withUniqueTask($emptyRecord, 'task-1', true);
 * $record = $service->withRecurringTask($record, 'recurring-1', false, 'Error message');
 */
final class BatchResultService
{
    /**
     * Adds a unique (non-recurring) task result to the batch.
     *
     * @param BatchResultRecord $record The current batch result record
     * @param string $id Unique identifier of the task
     * @param bool $success Whether the task executed successfully
     * @param string|null $error Error message if the task failed
     *
     * @return BatchResultRecord A new batch result record with the task added
     */
    public function withUniqueTask(BatchResultRecord $record, string $id, bool $success, ?string $error = null): BatchResultRecord
    {
        $uniqueResults = clone $record->uniqueResults;
        $uniqueResults->add(new UniqueResultRecord($id, $success));

        $errors = clone $record->errors;
        $uniqueSuccess = $record->uniqueSuccess;
        $uniqueFailed = $record->uniqueFailed;

        if ($success) {
            $uniqueSuccess++;
        } else {
            $uniqueFailed++;

            if ($error !== null) {
                $errors->add(new TaskErrorRecord($id, $error));
            }
        }

        return new BatchResultRecord(
            startedAt: $record->startedAt,
            uniqueSuccess: $uniqueSuccess,
            uniqueFailed: $uniqueFailed,
            recurringSuccess: $record->recurringSuccess,
            recurringFailed: $record->recurringFailed,
            uniqueResults: $uniqueResults,
            recurringResults: clone $record->recurringResults,
            errors: $errors,
        );
    }

    /**
     * Adds a recurring task result to the batch.
     *
     * @param BatchResultRecord $record The current batch result record
     * @param string $signature Unique signature of the recurring task
     * @param bool $success Whether the task executed successfully
     * @param string|null $error Error message if the task failed
     *
     * @return BatchResultRecord A new batch result record with the task added
     */
    public function withRecurringTask(BatchResultRecord $record, string $signature, bool $success, ?string $error = null): BatchResultRecord
    {
        $recurringResults = clone $record->recurringResults;
        $recurringResults->add(new RecurringResultRecord($signature, $success));

        $errors = clone $record->errors;
        $recurringSuccess = $record->recurringSuccess;
        $recurringFailed = $record->recurringFailed;

        if ($success) {
            $recurringSuccess++;
        } else {
            $recurringFailed++;

            if ($error !== null) {
                $errors->add(new TaskErrorRecord($signature, $error));
            }
        }

        return new BatchResultRecord(
            startedAt: $record->startedAt,
            uniqueSuccess: $record->uniqueSuccess,
            uniqueFailed: $record->uniqueFailed,
            recurringSuccess: $recurringSuccess,
            recurringFailed: $recurringFailed,
            uniqueResults: clone $record->uniqueResults,
            recurringResults: $recurringResults,
            errors: $errors,
        );
    }
}
