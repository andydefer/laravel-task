<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Logger\Records\LogDataRecord;
use AndyDefer\Task\Collections\RecurringResultCollection;
use AndyDefer\Task\Collections\TaskErrorCollection;
use AndyDefer\Task\Collections\UniqueResultCollection;
use AndyDefer\Task\Contracts\Configs\TaskConfigInterface;
use AndyDefer\Task\Contracts\Repositories\RecurringTaskRepositoryInterface;
use AndyDefer\Task\Contracts\Repositories\TaskRepositoryInterface;
use AndyDefer\Task\Contracts\Services\BatchResultServiceInterface;
use AndyDefer\Task\Contracts\Services\TaskBatchServiceInterface;
use AndyDefer\Task\Contracts\Services\TaskRunnerServiceInterface;
use AndyDefer\Task\Contracts\Services\TaskValidatorServiceInterface;
use AndyDefer\Task\Contracts\TaskProcessorInterface;
use AndyDefer\Task\Enums\BatchMode;
use AndyDefer\Task\Enums\TaskOrder;
use AndyDefer\Task\Enums\TaskType;
use AndyDefer\Task\Records\BatchResultRecord;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\RecurringTaskResultRecord;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\Records\UniqueTaskResultRecord;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;

/**
 * Service for processing pending tasks in batches.
 */
class TaskBatchService implements TaskBatchServiceInterface, TaskProcessorInterface
{
    public function __construct(
        private readonly TaskRepositoryInterface $taskRepository,
        private readonly RecurringTaskRepositoryInterface $recurringTaskRepository,
        private readonly TaskRunnerServiceInterface $runner,
        private readonly TaskValidatorServiceInterface $validator,
        private readonly LoggerInterface $logger,
        private readonly BatchResultServiceInterface $batchResultService,
        private readonly TaskConfigInterface $config,
        private readonly HydrationService $hydration,
    ) {}

    public function process(?int $limit = null): BatchResultRecord
    {
        $this->logBatchStart(BatchMode::FULL, $limit);
        $result = $this->createEmptyRecord();

        $effectiveLimit = $this->config->getEffectiveLimit($limit);

        [$result, $remaining_limit] = $this->processUniqueTasksWithLimit($result, $effectiveLimit);
        $result = $this->processRecurringTasksIfNeeded($result, $remaining_limit);

        $this->logBatchComplete($result);

        return $result;
    }

    public function processUniqueOnly(?int $limit = null): BatchResultRecord
    {
        $this->logBatchStart(BatchMode::UNIQUE_ONLY, $limit);
        $result = $this->createEmptyRecord();
        $result = $this->processUniqueTasks($result, $this->config->getEffectiveLimit($limit));
        $this->logBatchComplete($result);

        return $result;
    }

    public function processRecurringOnly(?int $limit = null): BatchResultRecord
    {
        $this->logBatchStart(BatchMode::RECURRING_ONLY, $limit);
        $result = $this->createEmptyRecord();
        $result = $this->processRecurringTasks($result, $this->config->getEffectiveLimit($limit));
        $this->logBatchComplete($result);

        return $result;
    }

    private function createEmptyRecord(): BatchResultRecord
    {
        return $this->hydration->hydrate(BatchResultRecord::class, [
            'started_at' => new Iso8601DateTimeVO,
            'unique_success' => 0,
            'unique_failed' => 0,
            'recurring_success' => 0,
            'recurring_failed' => 0,
            'unique_results' => new UniqueResultCollection,
            'recurring_results' => new RecurringResultCollection,
            'unique_errors' => new TaskErrorCollection,
            'recurring_errors' => new TaskErrorCollection,
        ]);
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

        $processedCount = $processedResult->unique_success->value + $processedResult->unique_failed->value;
        $remaining = $limit - $processedCount;

        return [$processedResult, $remaining > 0 ? $remaining : 0];
    }

    private function processRecurringTasksIfNeeded(BatchResultRecord $result, ?int $remaining_limit): BatchResultRecord
    {
        if ($remaining_limit !== null && $remaining_limit > 0) {
            return $this->processRecurringTasks($result, $remaining_limit);
        }

        return $remaining_limit === null ? $this->processRecurringTasks($result, null) : $result;
    }

    private function processUniqueTasks(BatchResultRecord $result, ?int $limit = null): BatchResultRecord
    {
        if ($limit === 0) {
            return $result;
        }

        $order = $this->config->isOldestOrder() ? TaskOrder::OLDEST : TaskOrder::NEWEST;

        foreach ($this->taskRepository->findAll($limit, $order) as $task) {
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
                $error = $success ? null : $this->getTaskFailureError($task->id->value);
            } else {
                $error = 'Task cannot be run (invalid state, expired, or max attempts reached)';
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $this->logTaskResult(TaskType::UNIQUE, $task->id->value, $task->signature->value, $success, $error);

        return $this->batchResultService->withUniqueTask($result, new UniqueTaskResultRecord(
            $task->id,
            $success,
            $error,
        ));
    }

    private function getTaskFailureError(string $task_id): string
    {
        $order = $this->config->isOldestOrder() ? TaskOrder::OLDEST : TaskOrder::NEWEST;
        $tasks = $this->taskRepository->findAll(1, $order);
        $updatedTask = $tasks->first();

        return ($updatedTask && $updatedTask->id->value === $task_id)
            ? ($updatedTask->last_error ?? 'Task failed without specific error')
            : 'Task execution failed';
    }

    private function processRecurringTasks(BatchResultRecord $result, ?int $limit = null): BatchResultRecord
    {
        if ($limit === 0) {
            return $result;
        }

        $order = $this->config->isOldestOrder() ? TaskOrder::OLDEST : TaskOrder::NEWEST;

        foreach ($this->recurringTaskRepository->findAll($limit, $order) as $task) {
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
                $error = $success ? null : 'Recurring task execution failed';
            } else {
                $error = 'Recurring task not ready to run';
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $this->logTaskResult(TaskType::RECURRING, $task->signature->value, $task->signature->value, $success, $error);

        return $this->batchResultService->withRecurringTask($result, new RecurringTaskResultRecord(
            $task->signature,
            $success,
            $error,
        ));
    }

    private function logBatchStart(BatchMode $mode, ?int $limit = null): void
    {
        $payload = $this->hydration->hydrate(StrictDataObject::class, [
            'event' => 'batch_started',
            'mode' => $mode->value,
            'limit' => $this->config->getEffectiveLimit($limit) ?? 'unlimited',
            'order' => $this->config->batchOrder(),
        ]);

        $this->logger->info(new LogDataRecord(type: 'batch', payload: $payload));
    }

    private function logBatchComplete(BatchResultRecord $result): void
    {
        $payload = $this->hydration->hydrate(
            StrictDataObject::class,
            $result->toArray() + ['event' => 'batch_completed']
        );

        $this->logger->info(new LogDataRecord(type: 'batch', payload: $payload));
    }

    private function logTaskResult(TaskType $type, string $id, string $signature, bool $success, ?string $error): void
    {
        $data = [
            'event' => $success ? 'task_succeeded' : 'task_failed',
            'type' => $type->value,
            'id' => $id,
            'signature' => $signature,
        ];

        if ($error !== null) {
            $data['error'] = $error;
        }

        $this->logger->info(new LogDataRecord(
            type: 'batch',
            payload: $this->hydration->hydrate(StrictDataObject::class, $data),
        ));
    }
}
