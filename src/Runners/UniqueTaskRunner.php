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
use AndyDefer\Task\Enums\ExecutionStatus;
use AndyDefer\Task\Records\ExecutionResultRecord;
use AndyDefer\Task\Records\TaskErrorRecord;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\MillisecondsVO;
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

        if (! $this->validator->canRun($record)) {
            $errors = $this->validator->getValidationErrors($record);

            return ExecutionResultRecord::from([
                'success' => false,
                'error' => TaskErrorRecord::from([
                    'alias' => $record->alias,
                    'fqcn' => $record->fqcn->getValue(),
                    'description' => 'Validation failed: '.$errors->join(', '),
                ]),
                'execution_time' => new DurationVO(0.0), // ✅ Ajouté
            ]);
        }

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

        $this->repository->addDebug(
            $record,
            $success ? ExecutionStatus::SUCCEEDED : ExecutionStatus::FAILED,
            $success
                ? new DescriptionVO('Task executed successfully')
                : new DescriptionVO($error ?? 'Unknown error')
        );

        if ($success) {
            $this->repository->moveToCompleted($record);
        } else {
            $this->repository->moveToFailed($record);
        }

        return ExecutionResultRecord::from([
            'success' => $success,
            'error' => $error ? TaskErrorRecord::from([
                'alias' => $record->alias,
                'fqcn' => $record->fqcn,
                'description' => $error,
            ]) : null,
            'execution_time' => $startTime->elapsed(),
        ]);
    }

    private function instantiateTask(UniqueTaskRecord $record): AbstractUniqueTask
    {
        $context = new UniqueTaskContext;
        $context->setTaskId($record->id);
        $context->setAlias($record->alias);
        $context->setScheduledAt($record->scheduled_at);
        $context->setLaravelApp($this->app);

        $className = $record->fqcn->getValue();

        return new $className($context, $this->app->make(LoggerInterface::class), $this->hydration);
    }
}
