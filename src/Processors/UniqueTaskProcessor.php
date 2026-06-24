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
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use Illuminate\Support\Carbon;

final class UniqueTaskProcessor implements UniqueTaskProcessorInterface
{
    public function __construct(
        private readonly UniqueTaskRepositoryInterface $repository,
        private readonly UniqueTaskRunnerInterface $runner,
        private readonly UniqueTaskValidatorInterface $validator,
    ) {}

    public function process(?int $limit = null): ProcessResultRecord
    {
        $startedAt = new Iso8601DateTimeVO;
        $success = 0;
        $failed = 0;
        $errors = new TaskErrorRecordCollection;

        $now = Carbon::now()->toIso8601String();

        $tasks = $this->repository->findReadyToRun($now);

        if ($limit !== null) {
            $tasks = $tasks->take($limit);
        }

        foreach ($tasks as $task) {
            $taskRecord = $this->modelToRecord($task);

            if (! $this->validator->canRun($taskRecord)) {
                $errorsList = $this->validator->getValidationErrors($taskRecord);
                $errorMessage = $errorsList->count() > 0 ? $errorsList->join(', ') : 'Task cannot run';

                $this->repository->moveToFailed($taskRecord);
                $failed++;
                $errors->add(TaskErrorRecord::from([
                    'alias' => $taskRecord->alias->value,
                    'fqcn' => $taskRecord->fqcn->getValue(),
                    'error' => 'Validation failed: '.$errorMessage,
                    'context' => 'scheduled_at: '.$taskRecord->scheduled_at->value.', attempts: '.$taskRecord->attempts->value,
                ]));

                continue;
            }

            $result = $this->runner->run($taskRecord);

            if ($result->success) {
                $success++;
            } else {
                $failed++;
                if ($result->error !== null) {
                    $errors->add($result->error);
                }
            }
        }

        $expiredTasks = $this->repository->findExpired($now);
        foreach ($expiredTasks as $task) {
            $taskRecord = $this->modelToRecord($task);

            if ($this->validator->isExpired($taskRecord)) {
                $this->repository->moveToFailed($taskRecord);
                $failed++;
                $errors->add(TaskErrorRecord::from([
                    'alias' => $taskRecord->alias->value,
                    'fqcn' => $taskRecord->fqcn->getValue(),
                    'error' => 'Task expired',
                    'context' => 'scheduled_at: '.$taskRecord->scheduled_at->value.', grace_period: '.$taskRecord->grace_period_seconds,
                ]));
            }
        }

        return ProcessResultRecord::from([
            'started_at' => $startedAt,
            'ended_at' => new Iso8601DateTimeVO,
            'success' => $success,
            'failed' => $failed,
            'finished' => 0,
            'errors' => $errors,
        ]);
    }

    private function modelToRecord(UniqueTask $model): UniqueTaskRecord
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
