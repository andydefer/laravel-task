<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Logger\Records\LogDataRecord;
use AndyDefer\Task\Abstract\AbstractUniqueTask;
use AndyDefer\Task\Contexts\UniqueTaskContext;
use AndyDefer\Task\Contracts\Configs\UniqueTaskConfigInterface;
use AndyDefer\Task\Contracts\Repositories\UniqueTaskRepositoryInterface;
use AndyDefer\Task\Contracts\Services\UniqueTaskServiceInterface;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\Models\UniqueTask as ModelsUniqueTask;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
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

    public function register(
        string $taskClass,
        StrictDataObject $payload,
        ?UniqueTaskConfigInterface $config = null
    ): TaskIdVO {
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

        $this->repository->create($record);

        return $taskId;
    }

    public function run(TaskIdVO $taskId): bool
    {
        $model = $this->repository->findById($taskId->value);
        if ($model === null) {
            return false;
        }

        $taskRecord = $this->modelToRecord($model);

        if ($taskRecord->status !== UniqueTaskStatus::PENDING) {
            return false;
        }

        if ($taskRecord->attempts->value >= $taskRecord->max_attempts->value) {
            $this->repository->moveToFailed($taskRecord);

            return false;
        }

        $task = $this->instantiateTask($taskRecord->fqcn, $taskRecord);

        try {
            $task->execute($taskRecord->payload);
            $this->repository->moveToCompleted($taskRecord);

            return true;
        } catch (\Throwable $e) {
            $newAttempts = $taskRecord->attempts->increment();

            if ($newAttempts->value >= $taskRecord->max_attempts->value) {
                $this->repository->moveToFailed($taskRecord);
            } else {
                $model->update(['attempts' => $newAttempts->value]);
            }

            return false;
        }
    }

    public function process(?int $limit = null): array
    {
        $results = ['success' => 0, 'failed' => 0];
        $tasks = $this->repository->findReadyToRun(date('c'));

        if ($limit !== null) {
            $tasks = $tasks->take($limit);
        }

        foreach ($tasks as $task) {
            $record = $this->modelToRecord($task);
            $success = $this->run($record->id);
            $results[$success ? 'success' : 'failed']++;
        }

        return $results;
    }

    public function cancel(TaskIdVO $taskId, ?string $reason = null): void
    {
        $model = $this->repository->findById($taskId->value);
        if ($model === null) {
            throw new \RuntimeException("Task not found: {$taskId->value}");
        }

        $taskRecord = $this->modelToRecord($model);

        if ($taskRecord->status !== UniqueTaskStatus::PENDING) {
            throw new \RuntimeException("Task '{$taskId->value}' is not in PENDING state");
        }

        $this->repository->moveToCanceled($taskRecord);

        $this->logger->warning(new LogDataRecord(
            type: 'unique_task_cancelled',
            payload: $this->hydration->hydrate(StrictDataObject::class, [
                'task_id' => $taskId->value,
                'reason' => $reason ?? 'Cancelled by user',
            ])
        ));
    }

    public function reschedule(TaskIdVO $taskId, Iso8601DateTimeVO $newScheduledAt): void
    {
        $model = $this->repository->findById($taskId->value);
        if ($model === null) {
            throw new \RuntimeException("Task not found: {$taskId->value}");
        }

        $taskRecord = $this->modelToRecord($model);

        if ($taskRecord->status !== UniqueTaskStatus::PENDING) {
            throw new \RuntimeException("Task '{$taskId->value}' is not in PENDING state");
        }

        $model->update(['scheduled_at' => $newScheduledAt->toDateTime()->format('Y-m-d H:i:s')]);

        $this->logger->info(new LogDataRecord(
            type: 'unique_task_rescheduled',
            payload: $this->hydration->hydrate(StrictDataObject::class, [
                'task_id' => $taskId->value,
                'new_scheduled_at' => $newScheduledAt->value,
            ])
        ));
    }

    public function extendGracePeriod(TaskIdVO $taskId, int $extraSeconds): void
    {
        if ($extraSeconds <= 0) {
            throw new \InvalidArgumentException('Extra seconds must be positive');
        }

        $model = $this->repository->findById($taskId->value);
        if ($model === null) {
            throw new \RuntimeException("Task not found: {$taskId->value}");
        }

        $taskRecord = $this->modelToRecord($model);

        if ($taskRecord->status !== UniqueTaskStatus::PENDING) {
            throw new \RuntimeException("Task '{$taskId->value}' is not in PENDING state");
        }

        $currentGracePeriod = $model->getGracePeriodSeconds();
        $model->update(['grace_period_seconds' => $currentGracePeriod + $extraSeconds]);

        $this->logger->info(new LogDataRecord(
            type: 'unique_task_grace_period_extended',
            payload: $this->hydration->hydrate(StrictDataObject::class, [
                'task_id' => $taskId->value,
                'extra_seconds' => $extraSeconds,
                'new_grace_period' => $currentGracePeriod + $extraSeconds,
            ])
        ));
    }

    public function find(TaskIdVO $taskId): ?UniqueTaskRecord
    {
        $model = $this->repository->findById($taskId->value);
        if ($model === null) {
            return null;
        }

        return $this->modelToRecord($model);
    }

    public function findPending(?int $limit = null): array
    {
        $models = $this->repository->findPending($limit);

        return $models->map(fn ($model) => $this->modelToRecord($model))->all();
    }

    public function findCompleted(?int $limit = null): array
    {
        $models = $this->repository->findCompleted($limit);

        return $models->map(fn ($model) => $this->modelToRecord($model))->all();
    }

    public function findFailed(?int $limit = null): array
    {
        $models = $this->repository->findFailed($limit);

        return $models->map(fn ($model) => $this->modelToRecord($model))->all();
    }

    public function findCanceled(?int $limit = null): array
    {
        $models = $this->repository->findCanceled($limit);

        return $models->map(fn ($model) => $this->modelToRecord($model))->all();
    }

    public function exists(TaskIdVO $taskId): bool
    {
        return $this->repository->findById($taskId->value) !== null;
    }

    public function delete(TaskIdVO $taskId): void
    {
        $model = $this->repository->findById($taskId->value);
        if ($model === null) {
            throw new \RuntimeException("Task not found: {$taskId->value}");
        }
        $model->delete();
    }

    public function count(): int
    {
        return $this->repository->count();
    }

    public function countPending(): int
    {
        return $this->repository->countPending();
    }

    public function countCompleted(): int
    {
        return $this->repository->countCompleted();
    }

    public function countFailed(): int
    {
        return $this->repository->countFailed();
    }

    public function countCanceled(): int
    {
        return $this->repository->countCanceled();
    }

    private function validateTaskClass(string $taskClass): void
    {
        if (! is_subclass_of($taskClass, AbstractUniqueTask::class)) {
            throw new \InvalidArgumentException('Task must extend AbstractUniqueTask');
        }
    }

    private function instantiateTask(string $fqcn, UniqueTaskRecord $record): AbstractUniqueTask
    {
        $context = new UniqueTaskContext;
        $context->setTaskId($record->id);
        $context->setAlias($record->alias);
        $context->setScheduledAt($record->scheduled_at);
        $context->setLaravelApp($this->app);

        return new $fqcn($context, $this->logger, $this->hydration);
    }

    private function modelToRecord(ModelsUniqueTask $model): UniqueTaskRecord
    {
        return UniqueTaskRecord::from([
            'id' => $model->getId(),
            'alias' => $model->getAlias(),
            'fqcn' => $model->getFqcn(),
            'payload' => $model->getPayload(),
            'scheduled_at' => $model->getScheduledAt(),
            'grace_period_seconds' => $model->getGracePeriodSeconds(),
            'status' => $model->getStatus(),
            'attempts' => $model->getAttempts(),
            'max_attempts' => $model->getMaxAttempts(),
            'finished_at' => $model->getFinishedAt(),
        ]);
    }
}
