<?php

declare(strict_types=1);

namespace AndyDefer\Task\Processors;

use AndyDefer\Task\Collections\TaskErrorRecordCollection;
use AndyDefer\Task\Contracts\Processors\UniqueTaskProcessorInterface;
use AndyDefer\Task\Contracts\Repositories\UniqueTaskRepositoryInterface;
use AndyDefer\Task\Contracts\Runners\UniqueTaskRunnerInterface;
use AndyDefer\Task\Contracts\Validators\UniqueTaskValidatorInterface;
use AndyDefer\Task\Models\UniqueTask;
use AndyDefer\Task\Records\ProcessResultRecord;
use AndyDefer\Task\Records\TaskErrorRecord;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use Illuminate\Support\Carbon;

/**
 * Processor for unique tasks.
 *
 * Orchestrates the processing of ready-to-run unique tasks by
 * validating, executing, and managing their state transitions.
 * Handles expiration and validation errors gracefully.
 */
final class UniqueTaskProcessor implements UniqueTaskProcessorInterface
{
    /**
     * Constructor for the unique task processor.
     *
     * @param  UniqueTaskRepositoryInterface  $repository  The repository for unique tasks
     * @param  UniqueTaskRunnerInterface  $runner  The runner for executing tasks
     * @param  UniqueTaskValidatorInterface  $validator  The validator for task eligibility
     */
    public function __construct(
        private readonly UniqueTaskRepositoryInterface $repository,
        private readonly UniqueTaskRunnerInterface $runner,
        private readonly UniqueTaskValidatorInterface $validator,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function process(LimitVO $limit = new LimitVO): ProcessResultRecord
    {
        $startedAt = new Iso8601DateTimeVO;
        $counters = $this->initializeCounters();
        $errors = new TaskErrorRecordCollection;

        $now = new Iso8601DateTimeVO(Carbon::now()->toIso8601String());

        $tasks = $this->getReadyTasks($now, $limit);

        foreach ($tasks as $task) {
            $taskRecord = $this->convertModelToRecord($task);

            if (! $this->validator->canRun($taskRecord)) {
                $this->handleValidationFailure($taskRecord, $counters, $errors);

                continue;
            }

            $this->processSingleTask($taskRecord, $counters, $errors);
        }

        $this->handleExpiredTasks($now, $counters, $errors);

        return $this->buildResult($startedAt, $counters, $errors);
    }

    /**
     * Initializes the processing counters.
     *
     * @return object{success: int, failed: int} The counters
     */
    private function initializeCounters(): object
    {
        return (object) [
            'success' => 0,
            'failed' => 0,
        ];
    }

    /**
     * Retrieves ready-to-run tasks with optional limit.
     *
     * @param  Iso8601DateTimeVO  $now  The current timestamp
     * @param  LimitVO  $limit  The limit for processing
     * @return iterable<UniqueTask> The ready tasks
     */
    private function getReadyTasks(Iso8601DateTimeVO $now, LimitVO $limit): iterable
    {
        $tasks = $this->repository->findReadyToRun($now);

        $limitValue = $limit->getValue();

        if ($limitValue !== null && $limitValue > 0) {
            return $tasks->take($limitValue);
        }

        return $tasks;
    }

    /**
     * Handles validation failure for a task.
     *
     * @param  UniqueTaskRecord  $taskRecord  The task record
     * @param  object  $counters  The counters object
     * @param  TaskErrorRecordCollection  $errors  The error collection
     */
    private function handleValidationFailure(
        UniqueTaskRecord $taskRecord,
        object $counters,
        TaskErrorRecordCollection $errors
    ): void {
        $errorsList = $this->validator->getValidationErrors($taskRecord);
        $errorMessage = $errorsList->count() > 0
            ? $errorsList->join(', ')
            : 'Task cannot run';

        $this->repository->moveToFailed($taskRecord);
        $counters->failed++;

        $errors->add($this->createValidationError($taskRecord, $errorMessage));
    }

    /**
     * Processes a single task.
     *
     * @param  UniqueTaskRecord  $taskRecord  The task record
     * @param  object  $counters  The counters object
     * @param  TaskErrorRecordCollection  $errors  The error collection
     */
    private function processSingleTask(
        UniqueTaskRecord $taskRecord,
        object $counters,
        TaskErrorRecordCollection $errors
    ): void {
        $result = $this->runner->run($taskRecord);

        if ($result->success) {
            $counters->success++;
        } else {
            $counters->failed++;
            if ($result->error !== null) {
                $errors->add($result->error);
            }
        }
    }

    /**
     * Handles expired tasks.
     *
     * @param  Iso8601DateTimeVO  $now  The current timestamp
     * @param  object  $counters  The counters object
     * @param  TaskErrorRecordCollection  $errors  The error collection
     */
    private function handleExpiredTasks(
        Iso8601DateTimeVO $now,
        object $counters,
        TaskErrorRecordCollection $errors
    ): void {
        $expiredTasks = $this->repository->findExpired($now);

        foreach ($expiredTasks as $task) {
            $taskRecord = $this->convertModelToRecord($task);

            if ($this->validator->isExpired($taskRecord)) {
                $this->repository->moveToFailed($taskRecord);
                $counters->failed++;

                $errors->add($this->createExpirationError($taskRecord));
            }
        }
    }

    /**
     * Creates a validation error record.
     *
     * @param  UniqueTaskRecord  $taskRecord  The task record
     * @param  string  $errorMessage  The error message
     * @return TaskErrorRecord The created error record
     */
    private function createValidationError(
        UniqueTaskRecord $taskRecord,
        string $errorMessage
    ): TaskErrorRecord {
        return TaskErrorRecord::from([
            'alias' => $taskRecord->alias,
            'fqcn' => $taskRecord->fqcn->getValue(),
            'description' => 'Validation failed: '.$errorMessage,
            'context' => sprintf(
                'scheduled_at: %s, attempts: %s',
                $taskRecord->scheduled_at->getValue(),
                $taskRecord->attempts->getValue()
            ),
        ]);
    }

    /**
     * Creates an expiration error record.
     *
     * @param  UniqueTaskRecord  $taskRecord  The task record
     * @return TaskErrorRecord The created error record
     */
    private function createExpirationError(UniqueTaskRecord $taskRecord): TaskErrorRecord
    {
        return TaskErrorRecord::from([
            'alias' => $taskRecord->alias->getValue(),
            'fqcn' => $taskRecord->fqcn->getValue(),
            'description' => 'Task expired',
            'context' => sprintf(
                'scheduled_at: %s, grace_period: %s',
                $taskRecord->scheduled_at->getValue(),
                $taskRecord->grace_period_seconds->getValue()
            ),
        ]);
    }

    /**
     * Builds the process result record.
     *
     * @param  Iso8601DateTimeVO  $startedAt  The start timestamp
     * @param  object  $counters  The counters object
     * @param  TaskErrorRecordCollection  $errors  The error collection
     * @return ProcessResultRecord The process result
     */
    private function buildResult(
        Iso8601DateTimeVO $startedAt,
        object $counters,
        TaskErrorRecordCollection $errors
    ): ProcessResultRecord {
        return ProcessResultRecord::from([
            'started_at' => $startedAt,
            'ended_at' => new Iso8601DateTimeVO,
            'success' => new CounterVO($counters->success),
            'failed' => new CounterVO($counters->failed),
            'finished' => new CounterVO(0),
            'errors' => $errors,
        ]);
    }

    /**
     * Converts an Eloquent model to a record object.
     *
     * @param  UniqueTask  $model  The model to convert
     * @return UniqueTaskRecord The converted record
     */
    private function convertModelToRecord(UniqueTask $model): UniqueTaskRecord
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
