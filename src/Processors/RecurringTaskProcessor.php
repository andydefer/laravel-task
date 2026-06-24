<?php

declare(strict_types=1);

namespace AndyDefer\Task\Processors;

use AndyDefer\Task\Collections\TaskErrorRecordCollection;
use AndyDefer\Task\Contracts\Processors\ProcessorInterface;
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

final class RecurringTaskProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly RecurringTaskRepositoryInterface $repository,
        private readonly RecurringTaskRunnerInterface $runner,
        private readonly RecurringTaskValidatorInterface $validator,
    ) {}

    public function process(LimitVO $limit = new LimitVO): ProcessResultRecord
    {
        $startedAt = new Iso8601DateTimeVO;
        $success = 0;
        $failed = 0;
        $finished = 0;
        $errors = new TaskErrorRecordCollection;

        $now = new Iso8601DateTimeVO(Carbon::now()->toIso8601String());

        $result = $this->repository->findReadyToRun($now, $limit);

        $finished += $result->fresh_state->playing_to_finished->value;

        foreach ($result->tasks as $record) {
            if (! $this->validator->shouldRunAgain($record)) {
                continue;
            }

            $runResult = $this->runner->run($record);

            if ($runResult->success) {
                $success++;
            } else {
                $failed++;
                if ($runResult->error !== null) {
                    $errors->add($runResult->error);
                }
            }

            $finalModel = $this->repository->findByAlias($record->alias->value);
            if ($finalModel !== null) {
                $finalRecord = $this->modelToRecord($finalModel);
                if ($this->validator->shouldMoveToFinished($finalRecord)) {
                    $this->repository->moveToFinished($finalRecord);
                    $finished++;
                }
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

    /**
     * Convertit un modèle Eloquent RecurringTask en RecurringTaskRecord.
     */
    private function modelToRecord(RecurringTask $model): RecurringTaskRecord
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
        ]);
    }
}
