<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\Task\Contracts\Repositories\RecurringTaskRepositoryInterface;
use AndyDefer\Task\Contracts\Repositories\TaskRepositoryInterface;
use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskConfigRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use Illuminate\Contracts\Foundation\Application;
use Ramsey\Uuid\UuidFactoryInterface;

final class TaskRegistryService
{
    public function __construct(
        private readonly TaskRepositoryInterface $taskRepository,
        private readonly RecurringTaskRepositoryInterface $recurringTaskRepository,
        private readonly TaskValidatorService $validator,
        private readonly HydrationService $hydration,
        private readonly UuidFactoryInterface $uuidFactory,
        private readonly Application $laravelApp,
    ) {}

    public function register(
        string $taskClass,
        TaskPayloadRecord $payload,
        ?TaskConfigRecord $override_config = null,
    ): string {
        $this->validateTaskClass($taskClass);

        $config = $this->getTaskConfig($taskClass);

        if ($override_config !== null) {
            $config = $this->mergeConfig($config, $override_config);
        }

        return $config->end_at === null && $config->delay_seconds->value > 0
            ? $this->registerRecurringTask($taskClass, $payload, $config)
            : $this->registerUniqueTask($taskClass, $payload, $config);
    }

    public function unregisterRecurring(TaskSignatureVO $signature): void
    {
        $this->recurringTaskRepository->delete($signature);
    }

    private function validateTaskClass(string $taskClass): void
    {
        if (!$this->validator->validateTaskClass($taskClass)) {
            throw new \InvalidArgumentException('Task must extend AbstractTask');
        }
    }

    private function getTaskConfig(string $taskClass): TaskConfigRecord
    {
        $instance = $this->laravelApp->make($taskClass);
        return $this->hydration->hydrate(TaskConfigRecord::class, $instance->getConfig()->toArray());
    }

    private function mergeConfig(TaskConfigRecord $original, TaskConfigRecord $override): TaskConfigRecord
    {
        return $this->hydration->hydrate(TaskConfigRecord::class, [
            'signature' => $override->signature ?? $original->signature,
            'description' => $override->description ?? $original->description,
            'delay_seconds' => $override->delay_seconds ?? $original->delay_seconds,
            'max_attempts' => $override->max_attempts ?? $original->max_attempts,
            'start_at' => $override->start_at ?? $original->start_at,
            'end_at' => $override->end_at ?? $original->end_at,
        ]);
    }

    private function registerRecurringTask(
        string $taskClass,
        TaskPayloadRecord $payload,
        TaskConfigRecord $config,
    ): string {
        $signature_vo = $config->signature;
        $existing = $this->recurringTaskRepository->find($signature_vo);

        if ($existing !== null) {
            throw new \RuntimeException(
                "Recurring task '{$signature_vo->value}' already exists. " .
                    'Delete it first if you want to re-register.'
            );
        }

        $now = date('c');
        $start_at = $config->start_at?->value ?? $now;

        $recurring_task = $this->hydration->hydrate(RecurringTaskRecord::class, [
            'signature' => $signature_vo,
            'class' => $taskClass,
            'payload' => $payload,
            'start_at' => $start_at,
            'end_at' => $config->end_at,
            'delay_seconds' => $config->delay_seconds,
            'last_run_at' => null,
            'next_run_at' => $start_at,
            'success_count' => 0,
            'failure_count' => 0,
        ]);

        $this->recurringTaskRepository->save($recurring_task);

        return $signature_vo->value;
    }

    private function registerUniqueTask(
        string $taskClass,
        TaskPayloadRecord $payload,
        TaskConfigRecord $config,
    ): string {
        $taskId = (string) $this->uuidFactory->uuid4();

        $task = $this->hydration->hydrate(TaskRecord::class, [
            'id' => new TaskIdVO($taskId),
            'signature' => $config->signature,
            'class' => $taskClass,
            'payload' => $payload,
            'status' => TaskStatus::PENDING,
            'created_at' => date('c'),
            'start_at' => $config->start_at?->value ?? date('c'),
            'end_at' => $config->end_at,
            'delay_seconds' => $config->delay_seconds,
            'attempts' => 0,
            'max_attempts' => $config->max_attempts,
            'last_error' => null,
            'enforce_exact_schedule' => false,
        ]);

        $this->taskRepository->save($task);

        return $task->id->value;
    }
}
