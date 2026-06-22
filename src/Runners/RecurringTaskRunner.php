<?php

declare(strict_types=1);

namespace AndyDefer\Task\Runners;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Task\Abstract\AbstractRecurringTask;
use AndyDefer\Task\Contexts\RecurringTaskContext;
use AndyDefer\Task\Contracts\Loggers\RecurringTaskLoggerInterface;
use AndyDefer\Task\Contracts\Repositories\RecurringTaskRepositoryInterface;
use AndyDefer\Task\Contracts\Runners\RecurringTaskRunnerInterface;
use AndyDefer\Task\Contracts\Validators\RecurringTaskValidatorInterface;
use AndyDefer\Task\Records\ExecutionResultRecord;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskErrorRecord;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use Illuminate\Contracts\Foundation\Application;

final class RecurringTaskRunner implements RecurringTaskRunnerInterface
{
    public function __construct(
        private readonly RecurringTaskValidatorInterface $validator,
        private readonly RecurringTaskLoggerInterface $logger,
        private readonly HydrationService $hydration,
        private readonly Application $app,
        private readonly RecurringTaskRepositoryInterface $repository,
    ) {}

    public function run(RecurringTaskRecord $record): ExecutionResultRecord
    {
        $startTime = new Iso8601DateTimeVO;

        // ✅ 1. Valider que la tâche peut être exécutée
        if (! $this->validator->canRun($record)) {
            $errors = $this->validator->getValidationErrors($record);
            $errorMessage = $errors->count() > 0 ? $errors->join(', ') : 'Task cannot run';

            return new ExecutionResultRecord(
                success: false,
                error: new TaskErrorRecord(
                    alias: $record->alias->value,
                    fqcn: $record->fqcn,
                    error: 'Validation failed: '.$errorMessage,
                ),
            );
        }

        // ✅ 2. Vérifier si la tâche doit être exécutée à nouveau (intervalle)
        if (! $this->validator->shouldRunAgain($record)) {
            // La tâche est en PLAYING mais l'intervalle n'est pas encore atteint
            return new ExecutionResultRecord(
                success: true,  // Pas une erreur, juste pas encore le moment
                error: null,
                execution_time: 0.0,
            );
        }

        // ✅ 3. Logger le début
        $this->logger->logStart($record);

        // ✅ 4. Instancier
        $task = $this->instantiateTask($record);

        // ✅ 5. Exécuter
        $error = null;
        $success = false;

        try {
            $task->execute($record->payload);
            $success = true;
            $this->logger->logSuccess($record, $this->calculateDuration($startTime));
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            $this->logger->logFailure($record, $error);
        }

        // ✅ 6. Ajouter le debug et mettre à jour last_run_at
        $this->repository->updateAfterRun($record, $success, $error);

        return new ExecutionResultRecord(
            success: $success,
            error: $error ? new TaskErrorRecord(
                alias: $record->alias->value,
                fqcn: $record->fqcn,
                error: $error,
            ) : null,
            execution_time: $this->calculateDuration($startTime),
        );
    }

    private function instantiateTask(RecurringTaskRecord $record): AbstractRecurringTask
    {
        $context = new RecurringTaskContext;
        $context->setAlias($record->alias);
        $context->setIntervalSeconds($record->interval_seconds);
        $context->setStartAt($record->start_at);
        $context->setEndAt($record->end_at);
        $context->setLastRunAt($record->last_run_at);
        $context->setLaravelApp($this->app);

        return new $record->fqcn($context, $this->app->make(LoggerInterface::class), $this->hydration);
    }

    private function calculateDuration(Iso8601DateTimeVO $start): float
    {
        $end = new Iso8601DateTimeVO;

        return strtotime($end->value) - strtotime($start->value);
    }
}
