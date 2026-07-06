<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Logger\Records\LogDataRecord;
use AndyDefer\Task\Abstract\AbstractRecurringTask;
use AndyDefer\Task\Collections\RecurringTaskRecordCollection;
use AndyDefer\Task\Collections\TaskErrorRecordCollection;
use AndyDefer\Task\Contexts\RecurringTaskContext;
use AndyDefer\Task\Contracts\Repositories\RecurringTaskRepositoryInterface;
use AndyDefer\Task\Contracts\Services\RecurringTaskServiceInterface;
use AndyDefer\Task\Enums\RecurringTaskStatus;
use AndyDefer\Task\Enums\TaskType;
use AndyDefer\Task\Models\RecurringTask as ModelsRecurringTask;
use AndyDefer\Task\Records\ProcessResultRecord;
use AndyDefer\Task\Records\RecurringTaskConfigRecord;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskErrorRecord;
use AndyDefer\Task\Records\TaskRunResultRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use AndyDefer\Task\ValueObjects\RecurringTaskFqcnVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\UuidVO;
use Illuminate\Contracts\Foundation\Application;
use Ramsey\Uuid\Uuid;

/**
 * Service for managing and executing recurring tasks.
 *
 * Handles registration, execution, and lifecycle management of recurring tasks
 * including state transitions and processing of ready-to-run tasks.
 */
final class RecurringTaskService implements RecurringTaskServiceInterface
{
    public function __construct(
        private readonly RecurringTaskRepositoryInterface $repository,
        private readonly LoggerInterface $logger,
        private readonly HydrationService $hydration,
        private readonly Application $app,
    ) {}

    public function register(
        RecurringTaskFqcnVO $fqcn,
        StrictDataObject $payload,
        RecurringTaskConfigRecord $config
    ): TaskAliasVO {
        $className = $fqcn->getValue();

        if (! class_exists($className)) {
            throw new \InvalidArgumentException("Task class \"{$className}\" does not exist.");
        }

        if (! is_subclass_of($className, AbstractRecurringTask::class)) {
            throw new \InvalidArgumentException(
                "Class \"{$className}\" must extend ".AbstractRecurringTask::class
            );
        }

        $uuid = (string) Uuid::uuid4();

        $alias = new TaskAliasVO(TaskType::RECURRING->value.'@'.$uuid);
        $now = new Iso8601DateTimeVO;
        $startAt = $config->start_at ?? $now;

        $record = RecurringTaskRecord::from([
            'id' => new UuidVO($uuid),
            'alias' => $alias,
            'fqcn' => $fqcn,
            'payload' => $payload,
            'interval_seconds' => $config->interval_seconds->getValue(),
            'start_at' => $startAt,
            'end_at' => $config->end_at,
            'status' => RecurringTaskStatus::WAITING,
            'failed_attempts' => 0,
            'max_failed_attempts' => $config->max_attempts->getValue(),
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
            ]);
        }

        $record = $this->modelToRecord($model);

        if ($record->status !== RecurringTaskStatus::PLAYING) {
            return TaskRunResultRecord::from([
                'alias' => $alias,
                'success' => false,
                'error' => sprintf(
                    'Task is not in PLAYING state (current: %s)',
                    $record->status->value
                ),
            ]);
        }

        $now = new Iso8601DateTimeVO;
        if ($record->end_at !== null && $record->end_at->isBefore($now)) {
            $this->repository->moveToFinished($record);

            return TaskRunResultRecord::from([
                'alias' => $alias,
                'success' => false,
                'error' => 'Task has expired (end_at reached)',
            ]);
        }

        $task = $this->instantiateTask($record->fqcn, $record);

        try {
            $task->execute($record->payload);
            $this->repository->updateAfterRun($record, true);

            return TaskRunResultRecord::from([
                'alias' => $alias,
                'success' => true,
                'execution_time_ms' => $startTime->elapsedInMilliseconds(),
            ]);
        } catch (\Throwable $e) {
            $this->repository->updateAfterRun($record, false, new DescriptionVO($e->getMessage()));

            return TaskRunResultRecord::from([
                'alias' => $alias,
                'success' => false,
                'error' => $e->getMessage(),
                'execution_time_ms' => $startTime->elapsedInMilliseconds(),
            ]);
        }
    }

    public function process(LimitVO $limit = new LimitVO): ProcessResultRecord
    {
        $startedAt = new Iso8601DateTimeVO;
        $success = 0;
        $failed = 0;
        $finished = 0;
        $errors = new TaskErrorRecordCollection;

        $now = new Iso8601DateTimeVO;

        $result = $this->repository->findReadyToRun($now, $limit);

        $finished += $result->fresh_state->playing_to_finished->getValue();

        foreach ($result->tasks as $record) {
            if (! $this->shouldRunAgain($record)) {
                continue;
            }

            try {
                $runResult = $this->run($record->alias);
                if ($runResult->success) {
                    $success++;
                } else {
                    $failed++;
                    $errors->add(TaskErrorRecord::from([
                        'alias' => $runResult->alias,
                        'fqcn' => $record->fqcn,
                        'description' => $runResult->error ?? 'Task execution failed',
                        'context' => sprintf(
                            'end_at: %s',
                            $record->end_at?->getValue() ?? 'null'
                        ),
                    ]));
                }
            } catch (\Throwable $e) {
                $failed++;
                $errors->add(TaskErrorRecord::from([
                    'alias' => $record->alias,
                    'fqcn' => $record->fqcn,
                    'description' => $e->getMessage(),
                    'context' => 'Exception during execution',
                ]));
            }
        }

        return ProcessResultRecord::from([
            'started_at' => $startedAt,
            'ended_at' => new Iso8601DateTimeVO,
            'success' => new CounterVO($success),
            'failed' => new CounterVO($failed),
            'finished' => new CounterVO($finished),
            'errors' => $errors,
        ]);
    }

    private function shouldRunAgain(RecurringTaskRecord $record): bool
    {
        if ($record->status !== RecurringTaskStatus::PLAYING) {
            return false;
        }

        if ($record->last_run_at === null) {
            return true;
        }

        $now = new Iso8601DateTimeVO;
        $lastRun = $record->last_run_at;
        $interval = $record->interval_seconds;

        return $now->diffInSeconds($lastRun)->getValue() >= $interval->getValue();
    }

    public function pause(TaskAliasVO $alias): bool
    {
        try {
            $model = $this->repository->findByAlias($alias);
            if ($model === null) {
                return false;
            }

            $record = $this->modelToRecord($model);

            if ($record->status !== RecurringTaskStatus::PLAYING) {
                return false;
            }

            $this->repository->moveToPaused($record);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function resume(TaskAliasVO $alias): bool
    {
        try {
            $model = $this->repository->findByAlias($alias);
            if ($model === null) {
                return false;
            }

            $record = $this->modelToRecord($model);

            if ($record->status !== RecurringTaskStatus::PAUSED) {
                return false;
            }

            $this->repository->moveToPlaying($record);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function finish(TaskAliasVO $alias): bool
    {
        try {
            $model = $this->repository->findByAlias($alias);
            if ($model === null) {
                return false;
            }

            $record = $this->modelToRecord($model);

            if ($record->status === RecurringTaskStatus::CANCELED) {
                return false;
            }

            $this->repository->moveToFinished($record);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function cancel(TaskAliasVO $alias, ?DescriptionVO $reason = null): bool
    {
        try {
            $model = $this->repository->findByAlias($alias);
            if ($model === null) {
                return false;
            }

            $record = $this->modelToRecord($model);
            $this->repository->moveToCanceled($record);

            $this->logger->warning(LogDataRecord::from([
                'type' => 'recurring_task_cancelled',
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

    public function advanceStartAt(TaskAliasVO $alias, Iso8601DateTimeVO $newStartAt): bool
    {
        try {
            $model = $this->repository->findByAlias($alias);
            if ($model === null) {
                return false;
            }

            $record = $this->modelToRecord($model);

            if ($record->status === RecurringTaskStatus::CANCELED) {
                return false;
            }

            $this->repository->updateRaw(
                $model->getId()->getValue(),
                ['start_at' => $newStartAt->forDatabase()]
            );

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function postponeStartAt(TaskAliasVO $alias, Iso8601DateTimeVO $newStartAt): bool
    {
        return $this->advanceStartAt($alias, $newStartAt);
    }

    public function changeInterval(TaskAliasVO $alias, DurationVO $intervalSeconds): bool
    {
        try {
            $model = $this->repository->findByAlias($alias);
            if ($model === null) {
                return false;
            }

            $record = $this->modelToRecord($model);

            if ($record->status === RecurringTaskStatus::CANCELED) {
                return false;
            }

            $this->repository->updateRaw(
                $model->getId()->getValue(),
                ['interval_seconds' => (int) $intervalSeconds->getValue()]
            );

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function extendEndAt(TaskAliasVO $alias, Iso8601DateTimeVO $newEndAt): bool
    {
        try {
            $model = $this->repository->findByAlias($alias);
            if ($model === null) {
                return false;
            }

            $record = $this->modelToRecord($model);

            if ($record->status === RecurringTaskStatus::CANCELED) {
                return false;
            }

            $this->repository->updateRaw(
                $model->getId()->getValue(),
                ['end_at' => $newEndAt->forDatabase()]
            );

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function find(TaskAliasVO $alias): ?RecurringTaskRecord
    {
        $model = $this->repository->findByAlias($alias);
        if ($model === null) {
            return null;
        }

        return $this->modelToRecord($model);
    }

    public function findWaiting(LimitVO $limit = new LimitVO): RecurringTaskRecordCollection
    {
        $models = $this->repository->findWaiting($limit);

        $collection = new RecurringTaskRecordCollection;
        foreach ($models as $model) {
            $collection->add($this->modelToRecord($model));
        }

        return $collection;
    }

    public function findPlaying(LimitVO $limit = new LimitVO): RecurringTaskRecordCollection
    {
        $models = $this->repository->findPlaying($limit);

        $collection = new RecurringTaskRecordCollection;
        foreach ($models as $model) {
            $collection->add($this->modelToRecord($model));
        }

        return $collection;
    }

    public function findPaused(LimitVO $limit = new LimitVO): RecurringTaskRecordCollection
    {
        $models = $this->repository->findPaused($limit);

        $collection = new RecurringTaskRecordCollection;
        foreach ($models as $model) {
            $collection->add($this->modelToRecord($model));
        }

        return $collection;
    }

    public function findFinished(LimitVO $limit = new LimitVO): RecurringTaskRecordCollection
    {
        $models = $this->repository->findFinished($limit);

        $collection = new RecurringTaskRecordCollection;
        foreach ($models as $model) {
            $collection->add($this->modelToRecord($model));
        }

        return $collection;
    }

    public function findCanceled(LimitVO $limit = new LimitVO): RecurringTaskRecordCollection
    {
        $models = $this->repository->findCanceled($limit);

        $collection = new RecurringTaskRecordCollection;
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
            $this->repository->delete($model->getId()->getValue());

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function count(): CounterVO
    {
        return new CounterVO($this->repository->count());
    }

    public function countWaiting(): CounterVO
    {
        return $this->repository->countWaiting();
    }

    public function countPlaying(): CounterVO
    {
        return $this->repository->countPlaying();
    }

    public function countPaused(): CounterVO
    {
        return $this->repository->countPaused();
    }

    public function countFinished(): CounterVO
    {
        return $this->repository->countFinished();
    }

    public function countCanceled(): CounterVO
    {
        return $this->repository->countCanceled();
    }

    private function instantiateTask(RecurringTaskFqcnVO $fqcn, RecurringTaskRecord $record): AbstractRecurringTask
    {
        $context = new RecurringTaskContext;
        $context->setAlias($record->alias);
        $context->setIntervalSeconds($record->interval_seconds);
        $context->setStartAt($record->start_at);
        $context->setEndAt($record->end_at);
        $context->setLastRunAt($record->last_run_at);
        $context->setLaravelApp($this->app);
        $context->setPayload($record->payload);

        $className = $fqcn->getValue();

        return new $className($context, $this->logger, $this->hydration);
    }

    private function modelToRecord(ModelsRecurringTask $model): RecurringTaskRecord
    {
        return RecurringTaskRecord::from([
            'id' => $model->getId(),
            'alias' => $model->getAlias(),
            'fqcn' => $model->getFqcn(),
            'payload' => $model->getPayload(),
            'interval_seconds' => $model->getIntervalSeconds(),
            'start_at' => $model->getStartAt(),
            'end_at' => $model->getEndAt(),
            'status' => $model->getStatus(),
            'last_run_at' => $model->getLastRunAt(),
            'finished_at' => $model->getFinishedAt(),
            'cancelled_at' => $model->getCancelledAt(),
            'failed_attempts' => $model->getFailedAttempts(),
            'max_failed_attempts' => $model->getMaxFailedAttempts(),
        ]);
    }
}
