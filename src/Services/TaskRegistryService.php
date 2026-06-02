<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services;

use AndyDefer\Task\Enums\TaskMode;
use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Records\TaskRecord;
use Ramsey\Uuid\Uuid;

class TaskRegistryService
{
    public function __construct(
        private readonly TaskStorageService $storage,
        private readonly TaskValidatorService $validator,
    ) {}

    public function register(
        string $taskClass,
        TaskMode $mode,
        TaskPayloadRecord $payload,
        ?string $startAt = null,
        ?string $endAt = null,
        ?int $delaySeconds = null,
        bool $enforceExactSchedule = false,
    ): string {
        if (! $this->validator->validateTaskClass($taskClass)) {
            throw new \InvalidArgumentException('Task must extend AbstractTask');
        }

        $tempInstance = new $taskClass;
        $config = $tempInstance->getConfig();

        $now = date('c');
        $startAt = $startAt ?? $config->startAt ?? $now;
        $endAt = $endAt ?? $config->endAt;
        $delaySeconds = $delaySeconds ?? $config->delaySeconds;

        if ($this->isRecurring($config->endAt, $delaySeconds)) {
            return $this->registerRecurringTask($taskClass, $mode, $payload, $config, $startAt, $endAt, $delaySeconds);
        }

        return $this->registerUniqueTask($taskClass, $mode, $payload, $config, $startAt, $endAt, $delaySeconds, $enforceExactSchedule);
    }

    private function isRecurring(?string $endAt, int $delaySeconds): bool
    {
        return $endAt === null && $delaySeconds > 0;
    }

    private function registerRecurringTask(
        string $taskClass,
        TaskMode $mode,
        TaskPayloadRecord $payload,
        object $config,
        string $startAt,
        ?string $endAt,
        int $delaySeconds,
    ): string {
        $existing = $this->storage->getRecurring($config->signature);

        if ($existing !== null) {
            throw new \RuntimeException(
                "Recurring task '{$config->signature}' already exists. ".
                    'Delete it first if you want to re-register.'
            );
        }

        $recurringTask = new RecurringTaskRecord(
            signature: $config->signature,
            class: $taskClass,
            payload: $payload,
            mode: $mode,
            startAt: $startAt,
            endAt: $endAt,
            delaySeconds: $delaySeconds,
            lastRunAt: null,
            nextRunAt: $startAt,
            successCount: 0,
            failureCount: 0,
        );

        $this->storage->saveRecurring($recurringTask);

        return $config->signature;
    }

    private function registerUniqueTask(
        string $taskClass,
        TaskMode $mode,
        TaskPayloadRecord $payload,
        object $config,
        string $startAt,
        ?string $endAt,
        int $delaySeconds,
        bool $enforceExactSchedule = false,
    ): string {
        $id = Uuid::uuid4()->toString();

        $task = new TaskRecord(
            id: $id,
            signature: $config->signature,
            class: $taskClass,
            payload: $payload,
            mode: $mode,
            status: TaskStatus::PENDING,
            createdAt: date('c'),
            startAt: $startAt,
            endAt: $endAt,
            delaySeconds: $delaySeconds,
            attempts: 0,
            maxAttempts: $config->maxAttempts,
            enforceExactSchedule: $enforceExactSchedule,
        );

        $this->storage->savePending($task);

        return $id;
    }

    public function unregisterRecurring(string $signature): void
    {
        $this->storage->deleteRecurring($signature);
    }
}
