<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\Task\Collections\RecurringResultCollection;
use AndyDefer\Task\Collections\RecurringTaskErrorCollection;
use AndyDefer\Task\Collections\TaskErrorCollection;
use AndyDefer\Task\Collections\UniqueResultCollection;
use AndyDefer\Task\Contracts\Services\BatchResultServiceInterface;
use AndyDefer\Task\Enums\ErrorType;
use AndyDefer\Task\Records\BatchResultRecord;
use AndyDefer\Task\Records\RecurringResultRecord;
use AndyDefer\Task\Records\RecurringTaskErrorRecord;
use AndyDefer\Task\Records\RecurringTaskResultRecord;
use AndyDefer\Task\Records\TaskErrorRecord;
use AndyDefer\Task\Records\UniqueResultRecord;
use AndyDefer\Task\Records\UniqueTaskResultRecord;
use AndyDefer\Task\ValueObjects\CounterVO;

class BatchResultService implements BatchResultServiceInterface
{
    public function __construct(
        private readonly HydrationService $hydration,
    ) {}

    public function withUniqueTask(BatchResultRecord $record, UniqueTaskResultRecord $result): BatchResultRecord
    {
        $unique_results = clone $record->unique_results;
        $unique_errors = clone $record->unique_errors;

        $unique_results->add($this->hydration->hydrate(UniqueResultRecord::class, [
            'task_id' => $result->task_id,
            'success' => $result->success,
        ]));

        if (! $result->success && $result->error !== null) {
            $unique_errors->add($this->hydration->hydrate(TaskErrorRecord::class, [
                'task_id' => $result->task_id,
                'error_type' => ErrorType::TASK_EXECUTION_FAILED,
                'details' => $result->error,
            ]));
        }

        return $this->createResult(
            $record,
            $unique_results,
            $record->recurring_results,
            $unique_errors,
            $record->recurring_errors,
            $record->unique_success->increment($result->success ? 1 : 0),
            $record->unique_failed->increment($result->success ? 0 : 1),
            $record->recurring_success,
            $record->recurring_failed,
        );
    }

    public function withRecurringTask(BatchResultRecord $record, RecurringTaskResultRecord $result): BatchResultRecord
    {
        $recurring_results = clone $record->recurring_results;
        $recurring_errors = clone $record->recurring_errors;

        $recurring_results->add($this->hydration->hydrate(RecurringResultRecord::class, [
            'signature' => $result->signature,
            'success' => $result->success,
        ]));

        if (! $result->success && $result->error !== null) {
            $recurring_errors->add($this->hydration->hydrate(RecurringTaskErrorRecord::class, [
                'signature' => $result->signature,
                'error_type' => ErrorType::TASK_EXECUTION_FAILED,
                'details' => $result->error,
            ]));
        }

        return $this->createResult(
            $record,
            $record->unique_results,
            $recurring_results,
            $record->unique_errors,
            $recurring_errors,
            $record->unique_success,
            $record->unique_failed,
            $record->recurring_success->increment($result->success ? 1 : 0),
            $record->recurring_failed->increment($result->success ? 0 : 1),
        );
    }

    private function createResult(
        BatchResultRecord $record,
        UniqueResultCollection $unique_results,
        RecurringResultCollection $recurring_results,
        TaskErrorCollection $unique_errors,
        RecurringTaskErrorCollection $recurring_errors,
        CounterVO $unique_success,
        CounterVO $unique_failed,
        CounterVO $recurring_success,
        CounterVO $recurring_failed,
    ): BatchResultRecord {
        return $this->hydration->hydrate(BatchResultRecord::class, [
            'started_at' => $record->started_at,
            'unique_success' => $unique_success,
            'unique_failed' => $unique_failed,
            'recurring_success' => $recurring_success,
            'recurring_failed' => $recurring_failed,
            'unique_results' => $unique_results,
            'recurring_results' => $recurring_results,
            'unique_errors' => $unique_errors,
            'recurring_errors' => $recurring_errors,
        ]);
    }
}
