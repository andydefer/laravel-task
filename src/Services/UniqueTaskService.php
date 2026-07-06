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
use AndyDefer\Task\Enums\TaskType;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\Models\UniqueTask as ModelsUniqueTask;
use AndyDefer\Task\Records\ProcessResultRecord;
use AndyDefer\Task\Records\TaskErrorRecord;
use AndyDefer\Task\Records\TaskRunResultRecord;
use AndyDefer\Task\Records\UniqueTaskConfigRecord;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\UniqueTaskFqcnVO;
use AndyDefer\Task\ValueObjects\UuidVO;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Service for managing and executing unique tasks.
 *
 * Handles registration, execution, and lifecycle management of unique tasks
 * including state transitions, expiration handling, and processing of ready-to-run tasks.
 */
final class UniqueTaskService implements UniqueTaskServiceInterface
{
    /**
     * Constructor for the unique task service.
     *
     * @param  UniqueTaskRepositoryInterface  $repository  The task repository
     * @param  LoggerInterface  $logger  The logger instance
     * @param  HydrationService  $hydration  The hydration service
     * @param  Application  $app  The application container
     */
    public function __construct(
        private readonly UniqueTaskRepositoryInterface $repository,
        private readonly LoggerInterface $logger,
        private readonly HydrationService $hydration,
        private readonly Application $app,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function register(
        UniqueTaskFqcnVO $fqcn,
        StrictDataObject $payload,
        UniqueTaskConfigRecord $config
    ): TaskAliasVO {
        $className = $fqcn->getValue();

        if (! class_exists($className)) {
            throw new InvalidArgumentException("Task class \"{$className}\" does not exist.");
        }

        if (! is_subclass_of($className, AbstractUniqueTask::class)) {
            throw new InvalidArgumentException(
                "Class \"{$className}\" must extend ".AbstractUniqueTask::class
            );
        }

        $uuid = (string) Uuid::uuid4();
        $alias = new TaskAliasVO(TaskType::UNIQUE->value.'@'.$uuid);

        $record = UniqueTaskRecord::from([
            'id' => new UuidVO($uuid),
            'alias' => $alias,
            'fqcn' => $fqcn,
            'payload' => $payload,
            'scheduled_at' => $config->scheduled_at,
            'grace_period_seconds' => $config->grace_period,
            'status' => UniqueTaskStatus::PENDING,
            'attempts' => 0,
            'max_attempts' => $config->max_attempts,
        ]);

        $model = $this->repository->create($record);

        return $model->getAlias();
    }

    /**
     * {@inheritDoc}
     */
    public function run(TaskAliasVO $alias): TaskRunResultRecord
    {
        $startTime = new Iso8601DateTimeVO;

        $model = $this->repository->findByAlias($alias);
        if ($model === null) {
            return $this->createNotFoundResult($alias);
        }

        $taskRecord = $this->modelToRecord($model);

        $preExecutionCheck = $this->performPreExecutionChecks($taskRecord, $alias);
        if ($preExecutionCheck !== null) {
            return $preExecutionCheck;
        }

        $task = $this->instantiateTask($taskRecord->fqcn, $taskRecord);

        try {
            $task->execute($taskRecord->payload);
            $this->repository->moveToCompleted($taskRecord);

            return $this->createSuccessResult($alias, $startTime);
        } catch (Throwable $e) {
            return $this->handleExecutionFailure($taskRecord, $alias, $e, $startTime);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function process(LimitVO $limit = new LimitVO): ProcessResultRecord
    {
        $startedAt = new Iso8601DateTimeVO;
        $success = 0;
        $failed = 0;
        $errors = new TaskErrorRecordCollection;

        $now = new Iso8601DateTimeVO;

        $tasks = $this->repository->findReadyToRun($now, $limit);

        foreach ($tasks as $task) {
            $record = $this->modelToRecord($task);

            try {
                $result = $this->run($record->alias);
                if ($result->success) {
                    $success++;
                } else {
                    $failed++;
                    $errors->add($this->createTaskError(
                        $result->alias,
                        $record->fqcn,
                        $result->error->getValue() ?? 'Task execution failed',
                        sprintf(
                            'attempts: %d/%d',
                            $record->attempts->getValue(),
                            $record->max_attempts->getValue()
                        )
                    ));
                }
            } catch (Throwable $e) {
                $failed++;
                $errors->add($this->createTaskError(
                    $record->alias,
                    $record->fqcn,
                    $e->getMessage(),
                    'Exception during execution'
                ));
            }
        }

        $expiredTasks = $this->repository->findExpired($now, $limit);
        foreach ($expiredTasks as $task) {
            $taskRecord = $this->modelToRecord($task);
            $this->repository->moveToFailed($taskRecord);
            $failed++;
            $errors->add($this->createExpiredTaskError($taskRecord));
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

    /**
     * {@inheritDoc}
     */
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
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
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

            $this->repository->updateRaw(
                $model->getId()->getValue(),
                ['scheduled_at' => $newScheduledAt->forDatabase()]
            );

            $this->logger->info(LogDataRecord::from([
                'type' => 'unique_task_rescheduled',
                'payload' => $this->hydration->hydrate(StrictDataObject::class, [
                    'alias' => $alias->getValue(),
                    'new_scheduled_at' => $newScheduledAt->getValue(),
                ]),
            ]));

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function extendGracePeriod(TaskAliasVO $alias, DurationVO $extraSeconds): bool
    {
        try {
            if ($extraSeconds->getValue() <= 0) {
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
            $newGracePeriod = $currentGracePeriod + (int) $extraSeconds->getValue();

            $this->repository->updateRaw(
                $model->getId()->getValue(),
                ['grace_period_seconds' => $newGracePeriod]
            );

            $this->logger->info(LogDataRecord::from([
                'type' => 'unique_task_grace_period_extended',
                'payload' => $this->hydration->hydrate(StrictDataObject::class, [
                    'alias' => $alias->getValue(),
                    'extra_seconds' => (int) $extraSeconds->getValue(),
                    'new_grace_period' => $newGracePeriod,
                ]),
            ]));

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function find(TaskAliasVO $alias): ?UniqueTaskRecord
    {
        $model = $this->repository->findByAlias($alias);
        if ($model === null) {
            return null;
        }

        return $this->modelToRecord($model);
    }

    /**
     * {@inheritDoc}
     */
    public function findPending(LimitVO $limit = new LimitVO): UniqueTaskRecordCollection
    {
        $models = $this->repository->findPending($limit);

        return $this->convertModelsToCollection($models);
    }

    /**
     * {@inheritDoc}
     */
    public function findCompleted(LimitVO $limit = new LimitVO): UniqueTaskRecordCollection
    {
        $models = $this->repository->findCompleted($limit);

        return $this->convertModelsToCollection($models);
    }

    /**
     * {@inheritDoc}
     */
    public function findFailed(LimitVO $limit = new LimitVO): UniqueTaskRecordCollection
    {
        $models = $this->repository->findFailed($limit);

        return $this->convertModelsToCollection($models);
    }

    /**
     * {@inheritDoc}
     */
    public function findCanceled(LimitVO $limit = new LimitVO): UniqueTaskRecordCollection
    {
        $models = $this->repository->findCanceled($limit);

        return $this->convertModelsToCollection($models);
    }

    /**
     * {@inheritDoc}
     */
    public function exists(TaskAliasVO $alias): bool
    {
        return $this->repository->findByAlias($alias) !== null;
    }

    /**
     * {@inheritDoc}
     */
    public function delete(TaskAliasVO $alias): bool
    {
        try {
            $model = $this->repository->findByAlias($alias);
            if ($model === null) {
                return false;
            }
            $this->repository->delete($model->getId()->getValue());

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function count(): CounterVO
    {
        return new CounterVO($this->repository->count());
    }

    /**
     * {@inheritDoc}
     */
    public function countPending(): CounterVO
    {
        return $this->repository->countPending();
    }

    /**
     * {@inheritDoc}
     */
    public function countCompleted(): CounterVO
    {
        return $this->repository->countCompleted();
    }

    /**
     * {@inheritDoc}
     */
    public function countFailed(): CounterVO
    {
        return $this->repository->countFailed();
    }

    /**
     * {@inheritDoc}
     */
    public function countCanceled(): CounterVO
    {
        return $this->repository->countCanceled();
    }

    /**
     * Creates a not found result.
     *
     * @param  TaskAliasVO  $alias  The task alias
     * @return TaskRunResultRecord The not found result
     */
    private function createNotFoundResult(TaskAliasVO $alias): TaskRunResultRecord
    {
        return TaskRunResultRecord::from([
            'alias' => $alias,
            'success' => false,
            'error' => 'Task not found',
        ]);
    }

    /**
     * Creates a success result.
     *
     * @param  TaskAliasVO  $alias  The task alias
     * @param  Iso8601DateTimeVO  $startTime  The start timestamp
     * @return TaskRunResultRecord The success result
     */
    private function createSuccessResult(TaskAliasVO $alias, Iso8601DateTimeVO $startTime): TaskRunResultRecord
    {
        return TaskRunResultRecord::from([
            'alias' => $alias,
            'success' => true,
            'execution_time_ms' => $startTime->elapsedInMilliseconds(),
        ]);
    }

    /**
     * Creates an error result.
     *
     * @param  TaskAliasVO  $alias  The task alias
     * @param  string  $error  The error message
     * @param  Iso8601DateTimeVO|null  $startTime  The start timestamp
     * @return TaskRunResultRecord The error result
     */
    private function createErrorResult(TaskAliasVO $alias, string $error, ?Iso8601DateTimeVO $startTime = null): TaskRunResultRecord
    {
        $data = [
            'alias' => $alias,
            'success' => false,
            'error' => $error,
        ];

        if ($startTime !== null) {
            $data['execution_time_ms'] = $startTime->elapsedInMilliseconds();
        }

        return TaskRunResultRecord::from($data);
    }

    /**
     * Performs pre-execution checks for a task.
     *
     * @param  UniqueTaskRecord  $taskRecord  The task record
     * @param  TaskAliasVO  $alias  The task alias
     * @return TaskRunResultRecord|null The error result or null if checks pass
     */
    private function performPreExecutionChecks(UniqueTaskRecord $taskRecord, TaskAliasVO $alias): ?TaskRunResultRecord
    {
        if ($taskRecord->status !== UniqueTaskStatus::PENDING) {
            return $this->createErrorResult(
                $alias,
                sprintf(
                    'Task is not in PENDING state (current: %s)',
                    $taskRecord->status->value
                )
            );
        }

        $now = new Iso8601DateTimeVO;
        $scheduledAt = $taskRecord->scheduled_at;

        if ($scheduledAt->isAfter($now)) {
            return $this->createErrorResult($alias, 'Task is scheduled in the future');
        }

        if ($taskRecord->attempts->getValue() >= $taskRecord->max_attempts->getValue()) {
            $this->repository->moveToFailed($taskRecord);

            return $this->createErrorResult($alias, 'Maximum attempts reached');
        }

        return null;
    }

    /**
     * Handles execution failure.
     *
     * @param  UniqueTaskRecord  $taskRecord  The task record
     * @param  TaskAliasVO  $alias  The task alias
     * @param  Throwable  $e  The exception
     * @param  Iso8601DateTimeVO  $startTime  The start timestamp
     * @return TaskRunResultRecord The failure result
     */
    private function handleExecutionFailure(
        UniqueTaskRecord $taskRecord,
        TaskAliasVO $alias,
        Throwable $e,
        Iso8601DateTimeVO $startTime
    ): TaskRunResultRecord {
        $newAttempts = $taskRecord->attempts->increment();

        if ($newAttempts->getValue() >= $taskRecord->max_attempts->getValue()) {
            $this->repository->moveToFailed($taskRecord);
        } else {
            $this->repository->updateAttempts($taskRecord, $newAttempts);
        }

        return $this->createErrorResult($alias, $e->getMessage(), $startTime);
    }

    /**
     * Creates a task error record.
     *
     * @param  TaskAliasVO  $alias  The task alias
     * @param  UniqueTaskFqcnVO  $fqcn  The task FQCN
     * @param  string  $description  The error description
     * @param  string  $context  Additional context
     * @return TaskErrorRecord The created error record
     */
    private function createTaskError(
        TaskAliasVO $alias,
        UniqueTaskFqcnVO $fqcn,
        string $description,
        string $context
    ): TaskErrorRecord {
        return TaskErrorRecord::from([
            'alias' => $alias,
            'fqcn' => $fqcn,
            'description' => $description,
            'context' => $context,
        ]);
    }

    /**
     * Creates an expired task error record.
     *
     * @param  UniqueTaskRecord  $taskRecord  The task record
     * @return TaskErrorRecord The created error record
     */
    private function createExpiredTaskError(UniqueTaskRecord $taskRecord): TaskErrorRecord
    {
        return TaskErrorRecord::from([
            'alias' => $taskRecord->alias,
            'fqcn' => $taskRecord->fqcn,
            'description' => 'Task expired',
            'context' => sprintf(
                'scheduled_at: %s, grace_period: %d',
                $taskRecord->scheduled_at->getValue(),
                $taskRecord->grace_period_seconds->getValue()
            ),
        ]);
    }

    /**
     * Converts a collection of models to a collection of records.
     *
     * @param  iterable<ModelsUniqueTask>  $models  The models to convert
     * @return UniqueTaskRecordCollection The collection of records
     */
    private function convertModelsToCollection(iterable $models): UniqueTaskRecordCollection
    {
        $collection = new UniqueTaskRecordCollection;
        foreach ($models as $model) {
            $collection->add($this->modelToRecord($model));
        }

        return $collection;
    }

    /**
     * Instantiates the task class.
     *
     * @param  UniqueTaskFqcnVO  $fqcn  The task FQCN
     * @param  UniqueTaskRecord  $record  The task record
     * @return AbstractUniqueTask The instantiated task
     */
    private function instantiateTask(UniqueTaskFqcnVO $fqcn, UniqueTaskRecord $record): AbstractUniqueTask
    {
        $context = new UniqueTaskContext;
        $context->setTaskId($record->id);
        $context->setAlias($record->alias);
        $context->setScheduledAt($record->scheduled_at);
        $context->setLaravelApp($this->app);
        $context->setPayload($record->payload);

        $className = $fqcn->getValue();

        return new $className($context, $this->logger, $this->hydration);
    }

    /**
     * Converts an Eloquent model to a record object.
     *
     * @param  ModelsUniqueTask  $model  The model to convert
     * @return UniqueTaskRecord The converted record
     */
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
