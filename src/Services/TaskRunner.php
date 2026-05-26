<?php

// src/Services/TaskRunner.php

declare(strict_types=1);

namespace AndyDefer\Task\Services;

use AndyDefer\Logger\Collections\MixedPayloadCollection;
use AndyDefer\Logger\Logger;
use AndyDefer\Logger\Records\LogDataRecord;
use AndyDefer\Task\AbstractTask;
use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\Records\GracePeriodRecord;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskRecord;

class TaskRunner
{
    public function __construct(
        private readonly TaskStorage $storage,
        private readonly Logger $logger,
        private readonly TaskValidator $validator,
    ) {}

    public function runTask(TaskRecord $task): bool
    {
        if (! $this->validator->canRunTask($task)) {
            return false;
        }

        // 🔥 Log si la tâche est exécutée pendant la période de grâce
        $this->logGracePeriodIfNeeded($task);

        $className = $task->class;

        if (! $this->validator->validateTaskClass($className)) {
            $this->markTaskFailed($task, "Invalid task class: {$className}");

            return false;
        }

        $taskInstance = $this->instantiateTask($className, $task);

        try {
            $taskInstance->execute($task->mode, $task->payload);
            $this->markTaskSuccess($task);

            return true;
        } catch (\Throwable $e) {
            $this->markTaskFailed($task, $e->getMessage());

            return false;
        }
    }

    public function runRecurringTask(RecurringTaskRecord $task): bool
    {
        $className = $task->class;

        if (! $this->validator->validateTaskClass($className)) {
            $this->markRecurringFailed($task, "Invalid task class: {$className}");

            return false;
        }

        $taskInstance = $this->instantiateRecurringTask($className, $task);

        try {
            $taskInstance->execute($task->mode, $task->payload);
            $this->markRecurringSuccess($task);

            return true;
        } catch (\Throwable $e) {
            $this->markRecurringFailed($task, $e->getMessage());

            return false;
        }
    }

    private function logGracePeriodIfNeeded(TaskRecord $task): void
    {
        if (! $this->validator->isUniqueTaskWithGracePeriod($task)) {
            return;
        }

        $endAtTimestamp = $task->endAt ? strtotime($task->endAt) : time();
        $now = time();

        if ($now > $endAtTimestamp) {
            $delay = $now - $endAtTimestamp;

            $payload = new MixedPayloadCollection;
            $payload->add(
                'task_executed_during_grace_period',
                $task->id,
                $task->signature,
                $delay,
                'seconds_late'
            );

            $this->logger->warning(new LogDataRecord(
                type: 'task',
                payload: $payload,
            ));

            // Optionnel : créer un record pour tracer
            $graceRecord = new GracePeriodRecord(
                taskId: $task->id,
                signature: $task->signature,
                originalEndAt: $endAtTimestamp,
                executedAt: $now,
                delaySeconds: $delay,
            );

            // Stocker le record si besoin (dans un fichier dédié)
            $this->storeGracePeriodRecord($graceRecord);
        }
    }

    private function storeGracePeriodRecord(GracePeriodRecord $record): void
    {
        $gracePath = storage_path('tasks/grace_period');
        if (! is_dir($gracePath)) {
            mkdir($gracePath, 0755, true);
        }

        $fileName = $gracePath.'/'.$record->taskId.'.json';
        file_put_contents($fileName, json_encode($record->toArray(), JSON_PRETTY_PRINT));
    }

    private function instantiateTask(string $className, TaskRecord $task): AbstractTask
    {
        $instance = new $className;
        $instance->setLogger($this->logger);
        $instance->setTaskId($task->id);
        $instance->setSignature($task->signature);

        return $instance;
    }

    private function instantiateRecurringTask(string $className, RecurringTaskRecord $task): AbstractTask
    {
        $instance = new $className;
        $instance->setLogger($this->logger);
        $instance->setTaskId('recurring_'.$task->signature);
        $instance->setSignature($task->signature);

        return $instance;
    }

    private function markTaskSuccess(TaskRecord $task): void
    {
        $this->storage->moveToCompleted($task, true);
    }

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
            mode: $task->mode,
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

    private function markRecurringSuccess(RecurringTaskRecord $task): void
    {
        $this->storage->updateRecurringAfterRun($task, true, null);
    }

    private function markRecurringFailed(RecurringTaskRecord $task, string $error): void
    {
        $this->storage->updateRecurringAfterRun($task, false, $error);
    }
}
