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

final class UniqueTaskProcessor implements UniqueTaskProcessorInterface
{
    public function __construct(
        private readonly UniqueTaskRepositoryInterface $repository,
        private readonly UniqueTaskRunnerInterface $runner,
        private readonly UniqueTaskValidatorInterface $validator,
    ) {}

    public function process(LimitVO $limit = new LimitVO): ProcessResultRecord
    {
        $startedAt = new Iso8601DateTimeVO;
        $success = 0;
        $failed = 0;
        $errors = new TaskErrorRecordCollection;

        $now = new Iso8601DateTimeVO(Carbon::now()->toIso8601String());

        $tasks = $this->repository->findReadyToRun($now);

        $limitValue = $limit !== null ? $limit->getValue() : null;

        if ($limitValue !== null) {
            $tasks = $tasks->take($limitValue);
        }

        foreach ($tasks as $task) {
            $taskRecord = $this->modelToRecord($task);

            if (! $this->validator->canRun($taskRecord)) {
                $errorsList = $this->validator->getValidationErrors($taskRecord);
                $errorMessage = $errorsList->count() > 0 ? $errorsList->join(', ') : 'Task cannot run';

                $this->repository->moveToFailed($taskRecord);
                $failed++;
                $errors->add(TaskErrorRecord::from([
                    'alias' => $taskRecord->alias,
                    'fqcn' => $taskRecord->fqcn->getValue(),
                    'error' => 'Validation failed: '.$errorMessage,
                    'context' => 'scheduled_at: '.$taskRecord->scheduled_at->getValue().', attempts: '.$taskRecord->attempts->getValue(),
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
                    'alias' => $taskRecord->alias->getValue(),
                    'fqcn' => $taskRecord->fqcn->getValue(),
                    'error' => 'Task expired',
                    'context' => 'scheduled_at: '.$taskRecord->scheduled_at->getValue().', grace_period: '.$taskRecord->grace_period_seconds->getValue(),
                ]));
            }
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
