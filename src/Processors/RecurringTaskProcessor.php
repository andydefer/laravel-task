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
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use Illuminate\Support\Carbon;

final class RecurringTaskProcessor implements RecurringTaskProcessorInterface
{
    public function __construct(
        private readonly RecurringTaskRepositoryInterface $repository,
        private readonly RecurringTaskRunnerInterface $runner,
        private readonly RecurringTaskValidatorInterface $validator,
    ) {}

    public function process(?int $limit = null): ProcessResultRecord
    {
        $startedAt = new Iso8601DateTimeVO;
        $success = 0;
        $failed = 0;
        $finished = 0;
        $errors = new TaskErrorRecordCollection;

        $now = Carbon::now()->toIso8601String();

        // ✅ Récupérer les tâches prêtes et les statistiques
        $result = $this->repository->findReadyToRun($now, $limit);

        // ✅ Le repository nous donne le nombre de tâches terminées
        $finished += $result->fresh_state->playing_to_finished->value;

        // ✅ Exécuter les tâches
        foreach ($result->tasks as $record) {
            // ✅ Vérifier si la tâche doit être exécutée (intervalle)
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

            // ✅ Vérifier si la tâche doit être terminée après exécution
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
            'success' => $success,
            'failed' => $failed,
            'finished' => $finished,
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
