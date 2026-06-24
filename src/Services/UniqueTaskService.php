<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Logger\Records\LogDataRecord;
use AndyDefer\Task\Abstract\AbstractUniqueTask;
use AndyDefer\Task\Collections\TaskErrorRecordCollection;
use AndyDefer\Task\Collections\UniqueTaskRecordCollection;
use AndyDefer\Task\Contexts\UniqueTaskContext;
use AndyDefer\Task\Contracts\Repositories\UniqueTaskRepositoryInterface;
use AndyDefer\Task\Contracts\Services\UniqueTaskServiceInterface;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\Models\UniqueTask as ModelsUniqueTask;
use AndyDefer\Task\Records\ProcessResultRecord;
use AndyDefer\Task\Records\TaskErrorRecord;
use AndyDefer\Task\Records\TaskRunResultRecord;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\UniqueTaskConfigVO;
use AndyDefer\Task\ValueObjects\UniqueTaskFqcnVO;
use Illuminate\Contracts\Foundation\Application;
use Ramsey\Uuid\Uuid;

final class UniqueTaskService implements UniqueTaskServiceInterface
{
    public function __construct(
        private readonly UniqueTaskRepositoryInterface $repository,
        private readonly LoggerInterface $logger,
        private readonly HydrationService $hydration,
        private readonly Application $app,
    ) {}

    public function register(
        UniqueTaskFqcnVO $fqcn,
        StrictDataObject $payload,
        UniqueTaskConfigVO $config
    ): TaskAliasVO {
        $uuid = (string) Uuid::uuid4();

        $alias = new TaskAliasVO(
            type: $config->type,
            uuid: $uuid
        );

        $record = UniqueTaskRecord::from([
            'alias' => $alias,
            'fqcn' => $fqcn,
            'payload' => $payload,
            'scheduled_at' => $config->getScheduledAt(),
            'status' => UniqueTaskStatus::PENDING,
            'max_attempts' => $config->getMaxAttempts(),
        ]);

        $model = $this->repository->create($record);

        return $model->getAlias();
    }

    public function run(TaskAliasVO $alias): TaskRunResultRecord
    {
        $startTime = new Iso8601DateTimeVO;

        $model = $this->repository->findByAlias($alias);
        if ($model === null) {
            return TaskRunResultRecord::from([
                'alias' => $alias,
                'success' => false,
                'error' => 'Task not found',
                'execution_time' => new DurationVO(0.0),
            ]);
        }

        $taskRecord = $this->modelToRecord($model);

        if ($taskRecord->status !== UniqueTaskStatus::PENDING) {
            return TaskRunResultRecord::from([
                'alias' => $alias,
                'success' => false,
                'error' => sprintf(
                    'Task is not in PENDING state (current: %s)',
                    $taskRecord->status->value
                ),
                'execution_time' => new DurationVO(0.0),
            ]);
        }

        $now = new Iso8601DateTimeVO;
        $scheduledAt = $taskRecord->scheduled_at;

        if ($scheduledAt->isAfter($now)) {
            return TaskRunResultRecord::from([
                'alias' => $alias,
                'success' => false,
                'error' => 'Task is scheduled in the future',
                'execution_time' => new DurationVO(0.0),
            ]);
        }

        if ($taskRecord->attempts->value >= $taskRecord->max_attempts->value) {
            $this->repository->moveToFailed($taskRecord);

            return TaskRunResultRecord::from([
                'alias' => $alias,
                'success' => false,
                'error' => 'Maximum attempts reached',
                'execution_time' => new DurationVO(0.0),
            ]);
        }

        $task = $this->instantiateTask($taskRecord->fqcn, $taskRecord);

        try {
            $task->execute($taskRecord->payload);
            $this->repository->moveToCompleted($taskRecord);

            return TaskRunResultRecord::from([
                'alias' => $alias,
                'success' => true,
                'execution_time' => $startTime->elapsed(),
            ]);
        } catch (\Throwable $e) {
            $newAttempts = $taskRecord->attempts->increment();

            if ($newAttempts->value >= $taskRecord->max_attempts->value) {
                $this->repository->moveToFailed($taskRecord);
            } else {
                $model->update(['attempts' => $newAttempts->value]);
            }

            return TaskRunResultRecord::from([
                'alias' => $alias,
                'success' => false,
                'error' => $e->getMessage(),
                'execution_time' => $startTime->elapsed(),
            ]);
        }
    }

    public function process(LimitVO $limit = new LimitVO): ProcessResultRecord
    {
        $startedAt = new Iso8601DateTimeVO;
        $success = 0;
        $failed = 0;
        $errors = new TaskErrorRecordCollection;

        $now = new Iso8601DateTimeVO;

        $tasks = $this->repository->findReadyToRun($now);

        if ($limit !== null) {
            $tasks = $tasks->take($limit->getValue());
        }

        foreach ($tasks as $task) {
            $record = $this->modelToRecord($task);

            try {
                $result = $this->run($record->alias);
                if ($result->success) {
                    $success++;
                } else {
                    $failed++;
                    $errors->add(TaskErrorRecord::from([
                        'alias' => $result->alias->getValue(),
                        'fqcn' => $record->fqcn->getValue(),
                        'error' => $result->error ?? 'Task execution failed',
                        'context' => sprintf(
                            'attempts: %d/%d',
                            $record->attempts->value,
                            $record->max_attempts->value
                        ),
                    ]));
                }
            } catch (\Throwable $e) {
                $failed++;
                $errors->add(TaskErrorRecord::from([
                    'alias' => $record->alias->getValue(),
                    'fqcn' => $record->fqcn->getValue(),
                    'error' => $e->getMessage(),
                    'context' => 'Exception during execution',
                ]));
            }
        }

        $expiredTasks = $this->repository->findExpired($now);
        foreach ($expiredTasks as $task) {
            $taskRecord = $this->modelToRecord($task);
            $this->repository->moveToFailed($taskRecord);
            $failed++;
            $errors->add(TaskErrorRecord::from([
                'alias' => $taskRecord->alias->getValue(),
                'fqcn' => $taskRecord->fqcn->getValue(),
                'error' => 'Task expired',
                'context' => sprintf(
                    'scheduled_at: %s, grace_period: %d',
                    $taskRecord->scheduled_at->value,
                    $taskRecord->grace_period_seconds
                ),
            ]));
        }

        return ProcessResultRecord::from([
            'started_at' => $startedAt,
            'ended_at' => new Iso8601DateTimeVO,
            'success' => new CounterVO($success),
            'failed' => new CounterVO($failed),
            'finished' => new CounterVO(0),
            'errors' => $errors,
        ]);
    }

    public function cancel(TaskAliasVO $alias, ?DescriptionVO $reason = null): bool
    {
        try {
            $model = $this->repository->findByAlias($alias);
            if ($model === null) {
                return false;
            }

            $taskRecord = $this->modelToRecord($model);

            if ($taskRecord->status !== UniqueTaskStatus::PENDING) {
                return false;
            }

            $this->repository->moveToCanceled($taskRecord);

            $this->logger->warning(LogDataRecord::from([
                'type' => 'unique_task_cancelled',
                'payload' => $this->hydration->hydrate(StrictDataObject::class, [
                    'alias' => $alias->getValue(),
                    'reason' => $reason?->getValue() ?? 'Cancelled by user',
                ]),
            ]));

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function reschedule(TaskAliasVO $alias, Iso8601DateTimeVO $newScheduledAt): bool
    {
        try {
            $model = $this->repository->findByAlias($alias);
            if ($model === null) {
                return false;
            }

            $taskRecord = $this->modelToRecord($model);

            if ($taskRecord->status !== UniqueTaskStatus::PENDING) {
                return false;
            }

            $model->update(['scheduled_at' => $newScheduledAt->forDatabase()]);

            $this->logger->info(LogDataRecord::from([
                'type' => 'unique_task_rescheduled',
                'payload' => $this->hydration->hydrate(StrictDataObject::class, [
                    'alias' => $alias->getValue(),
                    'new_scheduled_at' => $newScheduledAt->value,
                ]),
            ]));

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function extendGracePeriod(TaskAliasVO $alias, DurationVO $extraSeconds): bool
    {
        try {
            if ($extraSeconds->seconds <= 0) {
                return false;
            }

            $model = $this->repository->findByAlias($alias);
            if ($model === null) {
                return false;
            }

            $taskRecord = $this->modelToRecord($model);

            if ($taskRecord->status !== UniqueTaskStatus::PENDING) {
                return false;
            }

            $currentGracePeriod = $model->getGracePeriodSeconds();
            $model->update(['grace_period_seconds' => $currentGracePeriod + (int) $extraSeconds->seconds]);

            $this->logger->info(LogDataRecord::from([
                'type' => 'unique_task_grace_period_extended',
                'payload' => $this->hydration->hydrate(StrictDataObject::class, [
                    'alias' => $alias->getValue(),
                    'extra_seconds' => (int) $extraSeconds->seconds,
                    'new_grace_period' => $currentGracePeriod + (int) $extraSeconds->seconds,
                ]),
            ]));

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function find(TaskAliasVO $alias): ?UniqueTaskRecord
    {
        $model = $this->repository->findByAlias($alias);
        if ($model === null) {
            return null;
        }

        return $this->modelToRecord($model);
    }

    public function findPending(LimitVO $limit = new LimitVO): UniqueTaskRecordCollection
    {
        $models = $this->repository->findPending($limit);

        $collection = new UniqueTaskRecordCollection;
        foreach ($models as $model) {
            $collection->add($this->modelToRecord($model));
        }

        return $collection;
    }

    public function findCompleted(LimitVO $limit = new LimitVO): UniqueTaskRecordCollection
    {
        $models = $this->repository->findCompleted($limit);

        $collection = new UniqueTaskRecordCollection;
        foreach ($models as $model) {
            $collection->add($this->modelToRecord($model));
        }

        return $collection;
    }

    public function findFailed(LimitVO $limit = new LimitVO): UniqueTaskRecordCollection
    {
        $models = $this->repository->findFailed($limit);

        $collection = new UniqueTaskRecordCollection;
        foreach ($models as $model) {
            $collection->add($this->modelToRecord($model));
        }

        return $collection;
    }

    public function findCanceled(LimitVO $limit = new LimitVO): UniqueTaskRecordCollection
    {
        $models = $this->repository->findCanceled($limit);

        $collection = new UniqueTaskRecordCollection;
        foreach ($models as $model) {
            $collection->add($this->modelToRecord($model));
        }

        return $collection;
    }

    public function exists(TaskAliasVO $alias): bool
    {
        return $this->repository->findByAlias($alias) !== null;
    }

    public function delete(TaskAliasVO $alias): bool
    {
        try {
            $model = $this->repository->findByAlias($alias);
            if ($model === null) {
                return false;
            }
            $model->delete();

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function count(): CounterVO
    {
        return new CounterVO($this->repository->count());
    }

    public function countPending(): CounterVO
    {
        return $this->repository->countPending();
    }

    public function countCompleted(): CounterVO
    {
        return $this->repository->countCompleted();
    }

    public function countFailed(): CounterVO
    {
        return $this->repository->countFailed();
    }

    public function countCanceled(): CounterVO
    {
        return $this->repository->countCanceled();
    }

    private function instantiateTask(UniqueTaskFqcnVO $fqcn, UniqueTaskRecord $record): AbstractUniqueTask
    {
        $context = new UniqueTaskContext;
        $context->setTaskId($record->id);
        $context->setAlias($record->alias);
        $context->setScheduledAt($record->scheduled_at);
        $context->setLaravelApp($this->app);

        $className = $fqcn->getValue();

        return new $className($context, $this->logger, $this->hydration);
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
