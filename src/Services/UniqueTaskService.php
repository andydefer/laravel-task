<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Task\Abstract\UniqueTask;
use AndyDefer\Task\Contexts\UniqueTaskContext;
use AndyDefer\Task\Contracts\Configs\UniqueTaskConfigInterface;
use AndyDefer\Task\Contracts\Repositories\UniqueTaskRepositoryInterface;
use AndyDefer\Task\Contracts\Services\UniqueTaskServiceInterface;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use Illuminate\Contracts\Foundation\Application;
use Ramsey\Uuid\UuidFactoryInterface;

final class UniqueTaskService implements UniqueTaskServiceInterface
{
    public function __construct(
        private readonly UniqueTaskRepositoryInterface $repository,
        private readonly LoggerInterface $logger,
        private readonly HydrationService $hydration,
        private readonly UuidFactoryInterface $uuidFactory,
        private readonly Application $app,
    ) {}

    public function register(string $taskClass, StrictDataObject $payload, ?UniqueTaskConfigInterface $config = null): TaskIdVO
    {
        $this->validateTaskClass($taskClass);

        $task = $this->app->make($taskClass);
        $baseConfig = $task->getConfig();
        $finalConfig = $config ?? $baseConfig;

        $taskId = new TaskIdVO((string) $this->uuidFactory->uuid4());

        $record = new UniqueTaskRecord(
            id: $taskId,
            alias: $finalConfig->getAlias(),
            fqcn: $taskClass,
            payload: $payload,
            scheduled_at: $finalConfig->getScheduledAt(),
            status: UniqueTaskStatus::PENDING,
            max_attempts: $finalConfig->getMaxAttempts(),
        );

        $this->repository->save($record);

        return $taskId;
    }

    public function run(TaskIdVO $taskId): bool
    {
        $record = $this->repository->find($taskId);

        if ($record === null) {
            return false;
        }

        if ($record->status !== UniqueTaskStatus::PENDING) {
            return false;
        }

        if ($record->attempts->value >= $record->max_attempts->value) {
            $this->repository->moveToCompleted($record, false);

            return false;
        }

        $task = $this->instantiateTask($record->fqcn, $record);

        try {
            $task->execute($record->payload);
            $this->repository->moveToCompleted($record, true);

            return true;
        } catch (\Throwable $e) {
            $newAttempts = $record->attempts->increment();

            if ($newAttempts->value >= $record->max_attempts->value) {
                $this->repository->moveToCompleted($record, false);
            } else {
                $updated = new UniqueTaskRecord(
                    id: $record->id,
                    alias: $record->alias,
                    fqcn: $record->fqcn,
                    payload: $record->payload,
                    scheduled_at: $record->scheduled_at,
                    status: UniqueTaskStatus::PENDING,
                    attempts: $newAttempts,
                    max_attempts: $record->max_attempts,
                    last_error: $e->getMessage(),
                );
                $this->repository->save($updated);
            }

            return false;
        }
    }

    public function find(TaskIdVO $taskId): ?UniqueTaskRecord
    {
        return $this->repository->find($taskId);
    }

    public function delete(TaskIdVO $taskId): void
    {
        $this->repository->delete($taskId);
    }

    public function process(?int $limit = null): array
    {
        $results = ['success' => 0, 'failed' => 0];
        $tasks = $this->repository->findReadyToRun(date('c'));

        if ($limit !== null) {
            $count = 0;
            $tasks = $tasks->filter(function () use ($limit, &$count) {
                return ++$count <= $limit;
            });
        }

        foreach ($tasks as $task) {
            $success = $this->run($task->id);
            $results[$success ? 'success' : 'failed']++;
        }

        return $results;
    }

    private function validateTaskClass(string $taskClass): void
    {
        if (! is_subclass_of($taskClass, UniqueTask::class)) {
            throw new \InvalidArgumentException('Task must extend UniqueTask');
        }
    }

    private function instantiateTask(string $fqcn, UniqueTaskRecord $record): UniqueTask
    {
        $context = new UniqueTaskContext;
        $context->setTaskId($record->id);
        $context->setAlias($record->alias);
        $context->setScheduledAt($record->scheduled_at);
        $context->setLaravelApp($this->app);

        return new $fqcn($context, $this->logger, $this->hydration);
    }
}
