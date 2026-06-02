<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services;

use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Logger;
use AndyDefer\Logger\Records\LogDataRecord;
use AndyDefer\Task\Collections\RecurringResultCollection;
use AndyDefer\Task\Collections\TaskErrorCollection;
use AndyDefer\Task\Collections\UniqueResultCollection;
use AndyDefer\Task\Configs\TaskConfig;
use AndyDefer\Task\Contracts\TaskProcessorInterface;
use AndyDefer\Task\Records\BatchResultRecord;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\ValueObjects\Iso8601DateTime;

/**
 * Service for processing pending tasks in batches.
 *
 * Orchestrates the execution of unique and recurring tasks with configurable
 * limits and filtering options. Returns immutable batch result records.
 *
 * @example
 * $service = new TaskBatchService(...);
 * $result = $service->process(10);
 * echo $result->uniqueSuccess;
 */
class TaskBatchService implements TaskProcessorInterface
{
    public function __construct(
        private readonly TaskStorageService $storage,
        private readonly TaskRunnerService $runner,
        private readonly TaskValidatorService $validator,
        private readonly Logger $logger,
        private readonly BatchResultService $batchResultService,
        private readonly TaskConfig $config,
    ) {}

    public function process(?int $limit = null): BatchResultRecord
    {
        $this->logBatchStart('full', $limit);
        $result = $this->createEmptyRecord();

        $effectiveLimit = $this->config->getEffectiveLimit($limit);

        [$result, $remainingLimit] = $this->processUniqueTasksWithLimit($result, $effectiveLimit);

        $result = $this->processRecurringTasksIfNeeded($result, $remainingLimit);

        $this->logBatchComplete($result);

        return $result;
    }

    public function processUniqueOnly(?int $limit = null): BatchResultRecord
    {
        $this->logBatchStart('unique_only', $limit);
        $result = $this->createEmptyRecord();

        $effectiveLimit = $this->config->getEffectiveLimit($limit);
        $result = $this->processUniqueTasks($result, $effectiveLimit);

        $this->logBatchComplete($result);

        return $result;
    }

    public function processRecurringOnly(?int $limit = null): BatchResultRecord
    {
        $this->logBatchStart('recurring_only', $limit);
        $result = $this->createEmptyRecord();

        $effectiveLimit = $this->config->getEffectiveLimit($limit);
        $result = $this->processRecurringTasks($result, $effectiveLimit);

        $this->logBatchComplete($result);

        return $result;
    }

    private function createEmptyRecord(): BatchResultRecord
    {
        return new BatchResultRecord(
            startedAt: new Iso8601DateTime(),
            uniqueSuccess: 0,
            uniqueFailed: 0,
            recurringSuccess: 0,
            recurringFailed: 0,
            uniqueResults: new UniqueResultCollection(),
            recurringResults: new RecurringResultCollection(),
            errors: new TaskErrorCollection(),
        );
    }

    private function processUniqueTasksWithLimit(BatchResultRecord $result, ?int $limit): array
    {
        if ($limit === 0) {
            return [$result, $limit];
        }

        $processedResult = $this->processUniqueTasks($result, $limit);

        if ($limit === null) {
            return [$processedResult, null];
        }

        $processedCount = $processedResult->uniqueSuccess + $processedResult->uniqueFailed;
        $remaining = $limit - $processedCount;

        return [$processedResult, $remaining > 0 ? $remaining : 0];
    }

    private function processRecurringTasksIfNeeded(BatchResultRecord $result, ?int $remainingLimit): BatchResultRecord
    {
        if ($remainingLimit !== null && $remainingLimit > 0) {
            return $this->processRecurringTasks($result, $remainingLimit);
        }

        if ($remainingLimit === null) {
            return $this->processRecurringTasks($result, null);
        }

        return $result;
    }

    private function processUniqueTasks(BatchResultRecord $result, ?int $limit = null): BatchResultRecord
    {
        if ($limit === 0) {
            return $result;
        }

        $pendingTasks = $this->storage->findPending($limit, $this->config->batchOrder());

        foreach ($pendingTasks as $task) {
            $result = $this->executeUniqueTask($result, $task);
        }

        return $result;
    }

    private function executeUniqueTask(BatchResultRecord $result, TaskRecord $task): BatchResultRecord
    {
        $success = false;
        $error = null;

        try {
            if ($this->validator->canRunTask($task)) {
                $success = $this->runner->runTask($task);

                if (!$success) {
                    $error = $this->getTaskFailureError($task->id);
                }
            } else {
                $error = 'Task cannot be run (invalid state, expired, or max attempts reached)';
            }
        } catch (\Throwable $e) {
            $success = false;
            $error = $e->getMessage();
        }

        $this->logTaskResult('unique', $task->id, $task->signature, $success, $error);

        return $this->batchResultService->withUniqueTask($result, $task->id, $success, $error);
    }

    private function getTaskFailureError(string $taskId): string
    {
        $pending = $this->storage->findPending(1);
        $updatedTask = $pending->first();

        if ($updatedTask && $updatedTask->id === $taskId) {
            return $updatedTask->lastError ?? 'Task failed without specific error';
        }

        return 'Task execution failed';
    }

    private function processRecurringTasks(BatchResultRecord $result, ?int $limit = null): BatchResultRecord
    {
        if ($limit === 0) {
            return $result;
        }

        $recurringTasks = $this->storage->findRecurring($limit, $this->config->batchOrder());

        foreach ($recurringTasks as $task) {
            $result = $this->executeRecurringTask($result, $task);
        }

        return $result;
    }

    private function executeRecurringTask(BatchResultRecord $result, RecurringTaskRecord $task): BatchResultRecord
    {
        $success = false;
        $error = null;

        try {
            if ($this->validator->shouldRunRecurringNow($task)) {
                $success = $this->runner->runRecurringTask($task);

                if (!$success) {
                    $error = 'Recurring task execution failed';
                }
            } else {
                $error = 'Recurring task not ready to run';
            }
        } catch (\Throwable $e) {
            $success = false;
            $error = $e->getMessage();
        }

        $this->logTaskResult('recurring', $task->signature, $task->signature, $success, $error);

        return $this->batchResultService->withRecurringTask($result, $task->signature, $success, $error);
    }

    private function logBatchStart(string $mode, ?int $limit = null): void
    {
        $effectiveLimit = $this->config->getEffectiveLimit($limit);

        $payload = StrictDataObject::from([
            'event' => 'batch_started',
            'mode' => $mode,
            'limit' => $effectiveLimit ?? 'unlimited',
            'order' => $this->config->batchOrder(),
        ]);

        $this->logger->info(new LogDataRecord(
            type: 'batch',
            payload: $payload,
        ));
    }

    private function logBatchComplete(BatchResultRecord $result): void
    {
        $payload = StrictDataObject::from(
            $result->toArray() + ['event' => 'batch_completed']
        );

        $this->logger->info(new LogDataRecord(
            type: 'batch',
            payload: $payload,
        ));
    }

    private function logTaskResult(string $type, string $id, string $signature, bool $success, ?string $error): void
    {
        $payload = StrictDataObject::from([
            'event' => $success ? 'task_succeeded' : 'task_failed',
            'type' => $type,
            'id' => $id,
            'signature' => $signature,
        ]);

        if ($error !== null) {
            $payload = StrictDataObject::from($payload->toArray() + ['error' => $error]);
        }

        $this->logger->info(new LogDataRecord(
            type: 'batch',
            payload: $payload,
        ));
    }
}
