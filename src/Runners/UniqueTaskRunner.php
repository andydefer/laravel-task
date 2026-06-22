<?php

declare(strict_types=1);

namespace AndyDefer\Task\Runners;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Task\Abstract\AbstractUniqueTask;
use AndyDefer\Task\Contexts\UniqueTaskContext;
use AndyDefer\Task\Contracts\Loggers\UniqueTaskLoggerInterface;
use AndyDefer\Task\Contracts\Repositories\UniqueTaskRepositoryInterface;
use AndyDefer\Task\Contracts\Runners\UniqueTaskRunnerInterface;
use AndyDefer\Task\Contracts\Validators\UniqueTaskValidatorInterface;
use AndyDefer\Task\Records\ExecutionResultRecord;
use AndyDefer\Task\Records\TaskErrorRecord;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use Illuminate\Contracts\Foundation\Application;

final class UniqueTaskRunner implements UniqueTaskRunnerInterface
{
    public function __construct(
        private readonly UniqueTaskValidatorInterface $validator,
        private readonly UniqueTaskLoggerInterface $logger,
        private readonly HydrationService $hydration,
        private readonly Application $app,
        private readonly UniqueTaskRepositoryInterface $repository,
    ) {}

    public function run(UniqueTaskRecord $record): ExecutionResultRecord
    {
        $startTime = new Iso8601DateTimeVO;

        // ✅ 1. Valider
        if (! $this->validator->canRun($record)) {
            $errors = $this->validator->getValidationErrors($record);

            return new ExecutionResultRecord(
                success: false,
                error: new TaskErrorRecord(
                    alias: $record->alias->value,
                    fqcn: $record->fqcn,
                    error: 'Validation failed: '.$errors->join(', '),
                ),
            );
        }

        // ✅ 2. Logger le début
        $this->logger->logStart($record);

        // ✅ 3. Instancier
        $task = $this->instantiateTask($record);

        // ✅ 4. Exécuter
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

        // ✅ 5. Ajouter le debug
        $this->repository->addDebug(
            $record,
            $success ? 'succeeded' : 'failed',
            $success ? 'Task executed successfully' : ($error ?? 'Unknown error')
        );

        // ✅ 6. Mettre à jour le statut
        if ($success) {
            $this->repository->moveToCompleted($record);
        } else {
            $this->repository->moveToFailed($record);
        }

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

    private function instantiateTask(UniqueTaskRecord $record): AbstractUniqueTask
    {
        $context = new UniqueTaskContext;
        $context->setTaskId($record->id);
        $context->setAlias($record->alias);
        $context->setScheduledAt($record->scheduled_at);
        $context->setLaravelApp($this->app);

        return new $record->fqcn($context, $this->app->make(LoggerInterface::class), $this->hydration);
    }

    private function calculateDuration(Iso8601DateTimeVO $start): float
    {
        $end = new Iso8601DateTimeVO;

        return strtotime($end->value) - strtotime($start->value);
    }
}
