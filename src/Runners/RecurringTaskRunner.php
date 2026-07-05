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
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\MillisecondsVO;
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

        // ✅ Vérification canRun
        $canRun = $this->validator->canRun($record);

        if (! $canRun) {
            $errors = $this->validator->getValidationErrors($record);
            $errorMessage = $errors->count() > 0 ? $errors->join(', ') : 'Task cannot run';

            $result = ExecutionResultRecord::from([
                'success' => false,
                'error' => TaskErrorRecord::from([
                    'alias' => $record->alias,
                    'fqcn' => $record->fqcn->getValue(),
                    'error' => 'Validation failed: '.$errorMessage,
                ]),
                'execution_time' => new DurationVO(0.0),
            ]);

            return $result;
        }

        // ✅ Vérification shouldRunAgain
        $shouldRunAgain = $this->validator->shouldRunAgain($record);

        if (! $shouldRunAgain) {
            $result = ExecutionResultRecord::from([
                'success' => true,
                'error' => null,
                'execution_time' => new DurationVO(0.0),
            ]);

            return $result;
        }

        // ✅ Exécution
        $this->logger->logStart($record);

        $task = $this->instantiateTask($record);

        $error = null;
        $success = false;

        try {
            $task->execute($record->payload);
            $success = true;

            $duration = $startTime->elapsed();
            $this->logger->logSuccess($record, new MillisecondsVO((int) $duration->toMilliseconds()));
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            $this->logger->logFailure($record, new DescriptionVO($error));
        }

        // ✅ Mise à jour après exécution
        $updateResult = $this->repository->updateAfterRun($record, $success, $error !== null ? new DescriptionVO($error) : null);

        $executionTime = $startTime->elapsed();

        $result = ExecutionResultRecord::from([
            'success' => $success,
            'error' => $error ? TaskErrorRecord::from([
                'alias' => $record->alias,
                'fqcn' => $record->fqcn->getValue(),
                'error' => $error,
            ]) : null,
            'execution_time' => $executionTime,
        ]);

        return $result;
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

        // ✅ Passer le payload directement
        $context->setPayload($record->payload);

        $className = $record->fqcn->getValue();

        return new $className($context, $this->app->make(LoggerInterface::class), $this->hydration);
    }
}
