<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services;

use AndyDefer\Task\Records\BatchResultRecord;
use AndyDefer\Task\Records\RecurringResultRecord;
use AndyDefer\Task\Records\TaskErrorRecord;
use AndyDefer\Task\Records\UniqueResultRecord;

/**
 * Service for building batch results.
 * Takes a BatchResultRecord and returns a new BatchResultRecord.
 */
class BatchResultService
{
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
