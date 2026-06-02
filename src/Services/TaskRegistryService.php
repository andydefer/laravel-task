<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services;

use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Records\TaskRecord;
use Ramsey\Uuid\Uuid;

final class TaskRegistryService
{
    public function __construct(
        private readonly TaskStorageService $storage,
        private readonly TaskValidatorService $validator,
    ) {}

    /**
     * Register a new task.
     *
     * @param  string  $taskClass  Fully qualified class name extending AbstractTask
     * @param  TaskPayloadRecord  $payload  Task payload data
     * @param  string|null  $startAt  ISO 8601 datetime when task can start
     * @param  string|null  $endAt  ISO 8601 datetime when task expires
     * @param  int|null  $delaySeconds  Delay between recurring executions
     * @param  bool  $enforceExactSchedule  Whether grace period is disabled
     * @return string Task ID (for unique tasks) or signature (for recurring tasks)
     *
     * @throws InvalidArgumentException If task class is invalid
     * @throws RuntimeException If recurring task already exists
     */
    public function register(
        string $taskClass,
        TaskPayloadRecord $payload,
        ?string $startAt = null,
        ?string $endAt = null,
        ?int $delaySeconds = null,
        bool $enforceExactSchedule = false,
    ): string {
        $this->validateTaskClass($taskClass);

        $config = $this->getTaskConfig($taskClass);
        $resolvedStartAt = $startAt ?? $config->startAt ?? date('c');
        $resolvedEndAt = $endAt ?? $config->endAt;
        $resolvedDelaySeconds = $delaySeconds ?? $config->delaySeconds ?? 0;

        if ($this->isRecurringTask($resolvedEndAt, $resolvedDelaySeconds)) {
            return $this->registerRecurringTask(
                taskClass: $taskClass,
                payload: $payload,
                signature: $config->signature,
                startAt: $resolvedStartAt,
                endAt: $resolvedEndAt,
                delaySeconds: $resolvedDelaySeconds,
            );
        }

        return $this->registerUniqueTask(
            taskClass: $taskClass,
            payload: $payload,
            signature: $config->signature,
            startAt: $resolvedStartAt,
            endAt: $resolvedEndAt,
            delaySeconds: $resolvedDelaySeconds,
            maxAttempts: $config->maxAttempts,
            enforceExactSchedule: $enforceExactSchedule,
        );
    }

    /**
     * Delete a recurring task by its signature.
     */
    public function unregisterRecurring(string $signature): void
    {
        $this->storage->deleteRecurring($signature);
    }

    private function validateTaskClass(string $taskClass): void
    {
        if (! $this->validator->validateTaskClass($taskClass)) {
            throw new \InvalidArgumentException('Task must extend AbstractTask');
        }
    }

    private function getTaskConfig(string $taskClass): object
    {
        $instance = new $taskClass;

        return $instance->getConfig();
    }

    private function isRecurringTask(?string $endAt, int $delaySeconds): bool
    {
        return $endAt === null && $delaySeconds > 0;
    }

    private function registerRecurringTask(
        string $taskClass,
        TaskPayloadRecord $payload,
        string $signature,
        string $startAt,
        ?string $endAt,
        int $delaySeconds,
    ): string {
        $existing = $this->storage->getRecurring($signature);

        if ($existing !== null) {
            throw new \RuntimeException(
                "Recurring task '{$signature}' already exists. " .
                    'Delete it first if you want to re-register.'
            );
        }

        $recurringTask = new RecurringTaskRecord(
            signature: $signature,
            class: $taskClass,
            payload: $payload,
            startAt: $startAt,
            endAt: $endAt,
            delaySeconds: $delaySeconds,
            lastRunAt: null,
            nextRunAt: $startAt,
            successCount: 0,
            failureCount: 0,
        );

        $this->storage->saveRecurring($recurringTask);

        return $signature;
    }

    private function registerUniqueTask(
        string $taskClass,
        TaskPayloadRecord $payload,
        string $signature,
        string $startAt,
        ?string $endAt,
        int $delaySeconds,
        int $maxAttempts,
        bool $enforceExactSchedule = false,
    ): string {
        $id = Uuid::uuid4()->toString();

        $task = new TaskRecord(
            id: $id,
            signature: $signature,
            class: $taskClass,
            payload: $payload,
            status: TaskStatus::PENDING,
            createdAt: date('c'),
            startAt: $startAt,
            endAt: $endAt,
            delaySeconds: $delaySeconds,
            attempts: 0,
            maxAttempts: $maxAttempts,
            enforceExactSchedule: $enforceExactSchedule,
        );

        $this->storage->savePending($task);

        return $id;
    }
}
