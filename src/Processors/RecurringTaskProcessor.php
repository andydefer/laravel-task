<?php

declare(strict_types=1);

namespace AndyDefer\Task\Processors;

use AndyDefer\Task\Collections\TaskErrorRecordCollection;
use AndyDefer\Task\Contracts\Processors\RecurringTaskProcessorInterface;
use AndyDefer\Task\Contracts\Repositories\RecurringTaskRepositoryInterface;
use AndyDefer\Task\Contracts\Runners\RecurringTaskRunnerInterface;
use AndyDefer\Task\Contracts\Validators\RecurringTaskValidatorInterface;
use AndyDefer\Task\Models\RecurringTask;
use AndyDefer\Task\Records\ProcessResultRecord;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use Illuminate\Support\Carbon;

/**
 * Processor for recurring tasks.
 *
 * Orchestrates the processing of ready-to-run recurring tasks by
 * validating, executing, and managing their state transitions.
 */
final class RecurringTaskProcessor implements RecurringTaskProcessorInterface
{
    /**
     * Constructor for the recurring task processor.
     *
     * @param  RecurringTaskRepositoryInterface  $repository  The repository for recurring tasks
     * @param  RecurringTaskRunnerInterface  $runner  The runner for executing tasks
     * @param  RecurringTaskValidatorInterface  $validator  The validator for task eligibility
     */
    public function __construct(
        private readonly RecurringTaskRepositoryInterface $repository,
        private readonly RecurringTaskRunnerInterface $runner,
        private readonly RecurringTaskValidatorInterface $validator,
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

        $result = $this->repository->findReadyToRun($now, $limit);

        $counters->finished += $result->fresh_state->playing_to_finished->getValue();

        foreach ($result->tasks as $record) {
            if (! $this->shouldProcessTask($record)) {
                continue;
            }

            $this->processSingleTask($record, $counters, $errors);
            $this->handlePostProcessing($record, $counters);
        }

        return $this->buildResult($startedAt, $counters, $errors);
    }

    /**
     * Initializes the processing counters.
     *
     * @return object{success: int, failed: int, finished: int} The counters
     */
    private function initializeCounters(): object
    {
        return (object) [
            'success' => 0,
            'failed' => 0,
            'finished' => 0,
        ];
    }

    /**
     * Checks if a task should be processed.
     *
     * @param  RecurringTaskRecord  $record  The task record
     * @return bool True if the task should be processed
     */
    private function shouldProcessTask(RecurringTaskRecord $record): bool
    {
        return $this->validator->shouldRunAgain($record);
    }

    /**
     * Processes a single task.
     *
     * @param  RecurringTaskRecord  $record  The task record
     * @param  object  $counters  The counters object
     * @param  TaskErrorRecordCollection  $errors  The error collection
     */
    private function processSingleTask(
        RecurringTaskRecord $record,
        object $counters,
        TaskErrorRecordCollection $errors
    ): void {
        $runResult = $this->runner->run($record);

        if ($runResult->success) {
            $counters->success++;
        } else {
            $counters->failed++;
            if ($runResult->error !== null) {
                $errors->add($runResult->error);
            }
        }
    }

    /**
     * Handles post-processing for a task.
     *
     * Checks if the task should be moved to finished state and updates
     * the repository accordingly.
     *
     * @param  RecurringTaskRecord  $record  The task record
     * @param  object  $counters  The counters object
     */
    private function handlePostProcessing(RecurringTaskRecord $record, object $counters): void
    {
        $finalModel = $this->repository->findByAlias($record->alias);

        if ($finalModel === null) {
            return;
        }

        $finalRecord = $this->convertModelToRecord($finalModel);

        if ($this->validator->shouldMoveToFinished($finalRecord)) {
            $this->repository->moveToFinished($finalRecord);
            $counters->finished++;
        }
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
            'finished' => new CounterVO($counters->finished),
            'errors' => $errors,
        ]);
    }

    /**
     * Converts an Eloquent model to a record object.
     *
     * @param  RecurringTask  $model  The model to convert
     * @return RecurringTaskRecord The converted record
     */
    private function convertModelToRecord(RecurringTask $model): RecurringTaskRecord
    {
        return RecurringTaskRecord::from([
            'alias' => $model->getAlias(),
            'fqcn' => $model->getFqcn(),
            'payload' => $model->getPayload(),
            'interval_seconds' => $model->getIntervalSeconds(),
            'start_at' => $model->getStartAt(),
            'end_at' => $model->getEndAt(),
            'status' => $model->getStatus(),
            'last_run_at' => $model->getLastRunAt(),
            'finished_at' => $model->getFinishedAt(),
        ]);
    }
}
