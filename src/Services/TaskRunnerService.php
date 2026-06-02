<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services;

use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Logger;
use AndyDefer\Logger\Records\LogDataRecord;
use AndyDefer\Task\AbstractTask;
use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\Records\GracePeriodRecord;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskRecord;

/**
 * Service for executing tasks.
 *
 * Handles the execution of both unique and recurring tasks, including
 * validation, logging, retry logic, and grace period handling.
 */
final class TaskRunnerService
{
    public function __construct(
        private readonly TaskStorageService $storage,
        private readonly Logger $logger,
        private readonly TaskValidatorService $validator,
    ) {}

    /**
     * Execute a unique task.
     *
     * @param TaskRecord $task The task to execute
     * @return bool True if task succeeded, false otherwise
     */
    public function runTask(TaskRecord $task): bool
    {
        if (!$this->validator->canRunTask($task)) {
            return false;
        }

        $this->logGracePeriodIfNeeded($task);

        $className = $task->class;

        if (!$this->validator->validateTaskClass($className)) {
            $this->markTaskFailed($task, "Invalid task class: {$className}");
            return false;
        }

        $taskInstance = $this->instantiateTask($className, $task);

        try {
            $taskInstance->execute($task->payload);
            $this->markTaskSuccess($task);
            return true;
        } catch (\Throwable $e) {
            $this->markTaskFailed($task, $e->getMessage());
            return false;
        }
    }

    /**
     * Execute a recurring task.
     *
     * @param RecurringTaskRecord $task The recurring task to execute
     * @return bool True if task succeeded, false otherwise
     */
    public function runRecurringTask(RecurringTaskRecord $task): bool
    {
        $className = $task->class;

        if (!$this->validator->validateTaskClass($className)) {
            $this->markRecurringFailed($task, "Invalid task class: {$className}");
            return false;
        }

        $taskInstance = $this->instantiateRecurringTask($className, $task);

        try {
            $taskInstance->execute($task->payload);
            $this->markRecurringSuccess($task);
            return true;
        } catch (\Throwable $e) {
            $this->markRecurringFailed($task, $e->getMessage());
            return false;
        }
    }

    /**
     * Log when a task is executed during its grace period.
     *
     * @param TaskRecord $task The task being executed
     */
    private function logGracePeriodIfNeeded(TaskRecord $task): void
    {
        if (!$this->validator->isUniqueTaskWithGracePeriod($task)) {
            return;
        }

        $endAtTimestamp = $task->endAt ? strtotime($task->endAt) : time();
        $now = time();

        if ($now > $endAtTimestamp) {
            $delay = $now - $endAtTimestamp;

            $this->logger->warning(new LogDataRecord(
                type: 'task',
                payload: StrictDataObject::from([
                    'event' => 'task_executed_during_grace_period',
                    'task_id' => $task->id,
                    'task_signature' => $task->signature,
                    'delay_seconds' => $delay,
                ])
            ));

            $this->storeGracePeriodRecord(new GracePeriodRecord(
                taskId: $task->id,
                signature: $task->signature,
                originalEndAt: $endAtTimestamp,
                executedAt: $now,
                delaySeconds: $delay,
            ));
        }
    }

    /**
     * Store a grace period record to disk.
     *
     * @param GracePeriodRecord $record The grace period record to store
     */
    private function storeGracePeriodRecord(GracePeriodRecord $record): void
    {
        $gracePath = storage_path('tasks/grace_period');

        if (!is_dir($gracePath)) {
            mkdir($gracePath, 0755, true);
        }

        $fileName = $gracePath . '/' . $record->taskId . '.json';
        file_put_contents($fileName, json_encode($record->toArray(), JSON_PRETTY_PRINT));
    }

    /**
     * Instantiate a unique task.
     *
     * @param string $className The task class name
     * @param TaskRecord $task The task record containing metadata
     * @return AbstractTask The instantiated task
     */
    private function instantiateTask(string $className, TaskRecord $task): AbstractTask
    {
        $instance = new $className();
        $instance
            ->setLogger($this->logger)
            ->setTaskId($task->id)
            ->setSignature($task->signature);

        return $instance;
    }

    /**
     * Instantiate a recurring task.
     *
     * @param string $className The task class name
     * @param RecurringTaskRecord $task The recurring task record containing metadata
     * @return AbstractTask The instantiated task
     */
    private function instantiateRecurringTask(string $className, RecurringTaskRecord $task): AbstractTask
    {
        $instance = new $className();
        $instance->setLogger($this->logger);
        $instance->setTaskId('recurring_' . $task->signature);
        $instance->setSignature($task->signature);

        return $instance;
    }

    /**
     * Mark a unique task as successful and archive it.
     *
     * @param TaskRecord $task The task to mark as successful
     */
    private function markTaskSuccess(TaskRecord $task): void
    {
        $this->storage->moveToCompleted($task, true);
    }

    /**
     * Mark a unique task as failed and handle retry logic.
     *
     * @param TaskRecord $task The task that failed
     * @param string $error The error message
     */
    private function markTaskFailed(TaskRecord $task, string $error): void
    {
        $isInvalidClass = str_contains($error, 'Invalid task class');

        if ($isInvalidClass) {
            $this->storage->moveToCompleted($task, false);
            return;
        }

        $newAttempts = $task->attempts + 1;
        $isExpired = $this->validator->isTaskExpired($task);

        if ($newAttempts >= $task->maxAttempts || $isExpired) {
            $this->storage->moveToCompleted($task, false);
            return;
        }

        $this->storage->deletePending($task->id);

        $updatedTask = new TaskRecord(
            id: $task->id,
            signature: $task->signature,
            class: $task->class,
            payload: $task->payload,
            status: TaskStatus::PENDING,
            createdAt: $task->createdAt,
            startAt: $task->startAt,
            endAt: $task->endAt,
            delaySeconds: $task->delaySeconds,
            attempts: $newAttempts,
            maxAttempts: $task->maxAttempts,
            lastError: $error,
        );

        $this->storage->savePending($updatedTask);
    }

    /**
     * Mark a recurring task as successful.
     *
     * @param RecurringTaskRecord $task The recurring task to mark as successful
     */
    private function markRecurringSuccess(RecurringTaskRecord $task): void
    {
        $this->storage->updateRecurringAfterRun($task, true, null);
    }

    /**
     * Mark a recurring task as failed.
     *
     * @param RecurringTaskRecord $task The recurring task that failed
     * @param string $error The error message
     */
    private function markRecurringFailed(RecurringTaskRecord $task, string $error): void
    {
        $this->storage->updateRecurringAfterRun($task, false, $error);
    }
}
