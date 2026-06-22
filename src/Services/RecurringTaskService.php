<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Logger\Records\LogDataRecord;
use AndyDefer\Task\Abstract\AbstractRecurringTask;
use AndyDefer\Task\Contexts\RecurringTaskContext;
use AndyDefer\Task\Contracts\Configs\RecurringTaskConfigInterface;
use AndyDefer\Task\Contracts\Repositories\RecurringTaskRepositoryInterface;
use AndyDefer\Task\Contracts\Services\RecurringTaskServiceInterface;
use AndyDefer\Task\Enums\RecurringTaskStatus;
use AndyDefer\Task\Models\RecurringTask as ModelsRecurringTask;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use Illuminate\Contracts\Foundation\Application;

final class RecurringTaskService implements RecurringTaskServiceInterface
{
    public function __construct(
        private readonly RecurringTaskRepositoryInterface $repository,
        private readonly LoggerInterface $logger,
        private readonly HydrationService $hydration,
        private readonly Application $app,
    ) {}

    public function register(
        string $taskClass,
        StrictDataObject $payload,
        RecurringTaskConfigInterface $config
    ): TaskSignatureVO {
        $this->validateTaskClass($taskClass);

        $alias = $config->getAlias();

        if ($this->repository->findByAlias($alias->value) !== null) {
            throw new \RuntimeException("Recurring task '{$alias->value}' already exists");
        }

        $now = date('c');
        $start_at = $config->getStartAt()?->value ?? $now;

        $record = new RecurringTaskRecord(
            alias: $alias,
            fqcn: $taskClass,
            payload: $payload,
            interval_seconds: $config->getIntervalSeconds(),
            start_at: new Iso8601DateTimeVO($start_at),
            end_at: $config->getEndAt(),
            status: RecurringTaskStatus::WAITING,
        );

        $this->repository->create($record);

        return $alias;
    }

    public function run(TaskSignatureVO $alias): bool
    {
        $model = $this->repository->findByAlias($alias->value);
        if ($model === null) {
            return false;
        }

        $record = $this->modelToRecord($model);

        if ($record->status !== RecurringTaskStatus::PLAYING) {
            return false;
        }

        if ($record->end_at !== null && $record->end_at->value <= date('c')) {
            $this->repository->moveToFinished($record);

            return false;
        }

        $task = $this->instantiateTask($record->fqcn, $record);

        try {
            $task->execute($record->payload);
            $this->repository->updateAfterRun($record, true);

            return true;
        } catch (\Throwable $e) {
            $this->repository->updateAfterRun($record, false, $e->getMessage());

            return false;
        }
    }

    public function process(?int $limit = null): array
    {
        $results = ['success' => 0, 'failed' => 0, 'finished' => 0];
        $tasks = $this->repository->findReadyToRun(date('c'));

        if ($limit !== null) {
            $tasks = $tasks->take($limit);
        }

        foreach ($tasks as $task) {
            $record = $this->modelToRecord($task);
            $success = $this->run($record->alias);

            if ($success) {
                $results['success']++;
            } else {
                $results['failed']++;
            }
        }

        return $results;
    }

    public function pause(TaskSignatureVO $alias): void
    {
        $model = $this->repository->findByAlias($alias->value);
        if ($model === null) {
            throw new \RuntimeException("Task not found: {$alias->value}");
        }

        $record = $this->modelToRecord($model);

        if ($record->status !== RecurringTaskStatus::PLAYING) {
            throw new \RuntimeException("Task '{$alias->value}' is not in PLAYING state");
        }

        $this->repository->moveToPaused($record);
    }

    public function resume(TaskSignatureVO $alias): void
    {
        $model = $this->repository->findByAlias($alias->value);
        if ($model === null) {
            throw new \RuntimeException("Task not found: {$alias->value}");
        }

        $record = $this->modelToRecord($model);

        if ($record->status !== RecurringTaskStatus::PAUSED) {
            throw new \RuntimeException("Task '{$alias->value}' is not in PAUSED state");
        }

        $this->repository->moveToWaiting($record);
    }

    public function finish(TaskSignatureVO $alias): void
    {
        $model = $this->repository->findByAlias($alias->value);
        if ($model === null) {
            throw new \RuntimeException("Task not found: {$alias->value}");
        }

        $record = $this->modelToRecord($model);

        // Vérifier si la tâche est déjà annulée
        if ($record->status === RecurringTaskStatus::CANCELED) {
            throw new \RuntimeException("Task '{$alias->value}' is already canceled");
        }

        $this->repository->moveToFinished($record);
    }

    public function cancel(TaskSignatureVO $alias, ?string $reason = null): void
    {
        $model = $this->repository->findByAlias($alias->value);
        if ($model === null) {
            throw new \RuntimeException("Task not found: {$alias->value}");
        }

        $record = $this->modelToRecord($model);
        $this->repository->moveToCanceled($record);

        $model->update(['cancelled_at' => now()->toDateTimeString()]);

        $this->logger->warning(new LogDataRecord(
            type: 'recurring_task_cancelled',
            payload: $this->hydration->hydrate(StrictDataObject::class, [
                'alias' => $alias->value,
                'reason' => $reason ?? 'Cancelled by user',
            ])
        ));
    }

    public function advanceStartAt(TaskSignatureVO $alias, Iso8601DateTimeVO $newStartAt): void
    {
        $model = $this->repository->findByAlias($alias->value);
        if ($model === null) {
            throw new \RuntimeException("Task not found: {$alias->value}");
        }

        $record = $this->modelToRecord($model);

        // Vérifier si la tâche est déjà annulée
        if ($record->status === RecurringTaskStatus::CANCELED) {
            throw new \RuntimeException("Task '{$alias->value}' is already canceled");
        }

        $this->repository->updateRaw(
            $model->getId(),
            ['start_at' => $newStartAt->toDateTime()->format('Y-m-d H:i:s')]
        );
    }

    public function postponeStartAt(TaskSignatureVO $alias, Iso8601DateTimeVO $newStartAt): void
    {
        $this->advanceStartAt($alias, $newStartAt);
    }

    public function changeInterval(TaskSignatureVO $alias, int $intervalSeconds): void
    {
        $model = $this->repository->findByAlias($alias->value);
        if ($model === null) {
            throw new \RuntimeException("Task not found: {$alias->value}");
        }

        $record = $this->modelToRecord($model);

        // Vérifier si la tâche est déjà annulée
        if ($record->status === RecurringTaskStatus::CANCELED) {
            throw new \RuntimeException("Task '{$alias->value}' is already canceled");
        }

        $this->repository->updateRaw(
            $model->getId(),
            ['interval_seconds' => $intervalSeconds]
        );
    }

    public function extendEndAt(TaskSignatureVO $alias, Iso8601DateTimeVO $newEndAt): void
    {
        $model = $this->repository->findByAlias($alias->value);
        if ($model === null) {
            throw new \RuntimeException("Task not found: {$alias->value}");
        }

        $record = $this->modelToRecord($model);

        // Vérifier si la tâche est déjà annulée
        if ($record->status === RecurringTaskStatus::CANCELED) {
            throw new \RuntimeException("Task '{$alias->value}' is already canceled");
        }

        $this->repository->updateRaw(
            $model->getId(),
            ['end_at' => $newEndAt->toDateTime()->format('Y-m-d H:i:s')]
        );
    }

    public function find(TaskSignatureVO $alias): ?RecurringTaskRecord
    {
        $model = $this->repository->findByAlias($alias->value);
        if ($model === null) {
            return null;
        }

        return $this->modelToRecord($model);
    }

    public function findWaiting(?int $limit = null): array
    {
        $models = $this->repository->findWaiting($limit);

        return $models->map(fn ($model) => $this->modelToRecord($model))->all();
    }

    public function findPlaying(?int $limit = null): array
    {
        $models = $this->repository->findPlaying($limit);

        return $models->map(fn ($model) => $this->modelToRecord($model))->all();
    }

    public function findPaused(?int $limit = null): array
    {
        $models = $this->repository->findPaused($limit);

        return $models->map(fn ($model) => $this->modelToRecord($model))->all();
    }

    public function findFinished(?int $limit = null): array
    {
        $models = $this->repository->findFinished($limit);

        return $models->map(fn ($model) => $this->modelToRecord($model))->all();
    }

    public function findCanceled(?int $limit = null): array
    {
        $models = $this->repository->findCanceled($limit);

        return $models->map(fn ($model) => $this->modelToRecord($model))->all();
    }

    public function exists(TaskSignatureVO $alias): bool
    {
        return $this->repository->findByAlias($alias->value) !== null;
    }

    public function delete(TaskSignatureVO $alias): void
    {
        $model = $this->repository->findByAlias($alias->value);
        if ($model === null) {
            throw new \RuntimeException("Task not found: {$alias->value}");
        }
        $this->repository->delete($model->getId());
    }

    public function count(): int
    {
        return $this->repository->count();
    }

    public function countWaiting(): int
    {
        return $this->repository->countWaiting();
    }

    public function countPlaying(): int
    {
        return $this->repository->countPlaying();
    }

    public function countPaused(): int
    {
        return $this->repository->countPaused();
    }

    public function countFinished(): int
    {
        return $this->repository->countFinished();
    }

    public function countCanceled(): int
    {
        return $this->repository->countCanceled();
    }

    private function validateTaskClass(string $taskClass): void
    {
        if (! is_subclass_of($taskClass, AbstractRecurringTask::class)) {
            throw new \InvalidArgumentException('Task must extend AbstractRecurringTask');
        }
    }

    private function instantiateTask(string $fqcn, RecurringTaskRecord $record): AbstractRecurringTask
    {
        $context = new RecurringTaskContext;
        $context->setAlias($record->alias);
        $context->setIntervalSeconds($record->interval_seconds);
        $context->setStartAt($record->start_at);
        $context->setEndAt($record->end_at);
        $context->setLastRunAt($record->last_run_at);
        $context->setLaravelApp($this->app);

        return new $fqcn($context, $this->logger, $this->hydration);
    }

    private function modelToRecord(ModelsRecurringTask $model): RecurringTaskRecord
    {
        return RecurringTaskRecord::from([
            'alias' => $model->getAlias(),
            'fqcn' => $model->getFqcn(),
            'payload' => $model->getPayload(),
            'interval_seconds' => $model->getIntervalSeconds(),
            'start_at' => $model->getStartAt(),
            'end_at' => $model->getEndAtVO(),
            'status' => $model->getStatus(),
            'last_run_at' => $model->getLastRunAt(),
            'finished_at' => $model->getFinishedAt(),
            'cancelled_at' => $model->getCancelledAt(),
        ]);
    }
}
