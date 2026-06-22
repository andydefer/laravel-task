<?php

declare(strict_types=1);

namespace AndyDefer\Task\Processors;

use AndyDefer\Task\Collections\TaskErrorRecordCollection;
use AndyDefer\Task\Contracts\Processors\RecurringTaskProcessorInterface;
use AndyDefer\Task\Contracts\Repositories\RecurringTaskRepositoryInterface;
use AndyDefer\Task\Contracts\Runners\RecurringTaskRunnerInterface;
use AndyDefer\Task\Contracts\Validators\RecurringTaskValidatorInterface;
use AndyDefer\Task\Enums\RecurringTaskStatus;
use AndyDefer\Task\Models\RecurringTask;
use AndyDefer\Task\Records\ProcessResultRecord;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskErrorRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;

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

        // ✅ 1. Récupérer les tâches WAITING
        $waitingTasks = $this->repository->findWaiting();

        // ✅ 2. Récupérer les tâches PLAYING
        $playingTasks = $this->repository->findPlaying();

        $tasksToPlay = [];
        $tasksToFinish = [];

        // ✅ 3. Traiter les tâches WAITING
        foreach ($waitingTasks as $task) {
            $taskRecord = $this->modelToRecord($task);

            if ($this->validator->shouldMoveToFinished($taskRecord)) {
                $tasksToFinish[] = $taskRecord;

                continue;
            }

            if ($this->validator->isReadyToRun($taskRecord)) {
                $tasksToPlay[] = $taskRecord;
            }
        }

        // ✅ 4. Traiter les tâches PLAYING (déjà actives)
        foreach ($playingTasks as $task) {
            $taskRecord = $this->modelToRecord($task);

            // Si end_at est dépassé → FINISHED
            if ($this->validator->shouldMoveToFinished($taskRecord)) {
                $tasksToFinish[] = $taskRecord;

                continue;
            }

            // ✅ Vérifier si l'intervalle est dépassé (logique inline)
            $shouldRunAgain = $this->shouldRunAgain($taskRecord);
            if ($shouldRunAgain) {
                $tasksToPlay[] = $taskRecord;
            }
        }

        // ✅ 5. Terminer les tâches
        foreach ($tasksToFinish as $taskRecord) {
            $this->repository->moveToFinished($taskRecord);
            $finished++;
            $errors->add(new TaskErrorRecord(
                identifier: $taskRecord->alias->value,
                error: 'Task finished (end_at reached)',
                context: 'end_at: '.($taskRecord->end_at?->value ?? 'null'),
            ));
        }

        // ✅ 6. Appliquer la limite
        if ($limit !== null) {
            $tasksToPlay = array_slice($tasksToPlay, 0, $limit);
        }

        // ✅ 7. Exécuter les tâches
        foreach ($tasksToPlay as $taskRecord) {
            // Si la tâche est en WAITING, la déplacer en PLAYING
            if ($taskRecord->status === RecurringTaskStatus::WAITING) {
                $this->repository->moveToPlaying($taskRecord);
            }

            // Récupérer la tâche mise à jour
            $updatedModel = $this->repository->findByAlias($taskRecord->alias->value);
            if ($updatedModel === null) {
                continue;
            }

            $updatedRecord = $this->modelToRecord($updatedModel);

            // Exécuter
            $result = $this->runner->run($updatedRecord);

            if ($result->success) {
                $success++;
            } else {
                $failed++;
                if ($result->error !== null) {
                    $errors->add($result->error);
                }
            }

            // Vérifier si la tâche doit être terminée après exécution
            $finalModel = $this->repository->findByAlias($taskRecord->alias->value);
            if ($finalModel !== null) {
                $finalRecord = $this->modelToRecord($finalModel);
                if ($this->validator->shouldMoveToFinished($finalRecord)) {
                    $this->repository->moveToFinished($finalRecord);
                    $finished++;
                }
            }
        }

        return new ProcessResultRecord(
            started_at: $startedAt,
            ended_at: new Iso8601DateTimeVO,
            success: new CounterVO($success),
            failed: new CounterVO($failed),
            finished: new CounterVO($finished),
            errors: $errors,
        );
    }

    /**
     * Vérifie si une tâche en PLAYING doit être exécutée à nouveau
     * selon son intervalle.
     */
    private function shouldRunAgain(RecurringTaskRecord $record): bool
    {
        // Si la tâche n'est pas en PLAYING, elle ne doit pas être ré-exécutée
        if ($record->status !== RecurringTaskStatus::PLAYING) {
            return false;
        }

        // Si elle n'a jamais été exécutée, on l'exécute
        if ($record->last_run_at === null) {
            return true;
        }

        // Vérifier si l'intervalle est dépassé
        $now = strtotime(date('c'));
        $lastRun = strtotime($record->last_run_at->value);
        $interval = $record->interval_seconds->value;

        return ($now - $lastRun) >= $interval;
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
