<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Logger\Records\LogDataRecord;
use AndyDefer\PhpServices\Contracts\FileSystemInterface;
use AndyDefer\Task\AbstractTask;
use AndyDefer\Task\Contexts\TaskContext;
use AndyDefer\Task\Contracts\Configs\TaskConfigInterface;
use AndyDefer\Task\Contracts\Repositories\RecurringTaskRepositoryInterface;
use AndyDefer\Task\Contracts\Repositories\TaskRepositoryInterface;
use AndyDefer\Task\Contracts\Services\TaskRunnerServiceInterface;
use AndyDefer\Task\Contracts\Services\TaskValidatorServiceInterface;
use AndyDefer\Task\Enums\ErrorType;
use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\Records\GracePeriodRecord;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\GracePeriodFilePathVO;
use AndyDefer\Task\ValueObjects\UnixTimestampVO;
use Illuminate\Contracts\Foundation\Application;

class TaskRunnerService implements TaskRunnerServiceInterface
{
    public function __construct(
        private readonly TaskRepositoryInterface $taskRepository,
        private readonly RecurringTaskRepositoryInterface $recurringTaskRepository,
        private readonly LoggerInterface $logger,
        private readonly TaskValidatorServiceInterface $validator,
        private readonly TaskConfigInterface $config,
        private readonly HydrationService $hydration,
        private readonly FileSystemInterface $fs,
        private readonly Application $app,
    ) {}

    public function runTask(TaskRecord $task): bool
    {
        if (! $this->validator->canRunTask($task)) {
            $this->markTaskFailed($task, ErrorType::TASK_VALIDATION_FAILED);

            return false;
        }

        $this->logGracePeriodIfNeeded($task);

        $className = $task->class;

        if (! $this->validator->validateTaskClass($className)) {
            $this->markTaskFailed($task, ErrorType::INVALID_TASK_CLASS, $className);

            return false;
        }

        $taskInstance = $this->instantiateTask($className, $task);

        try {
            $taskInstance->execute($task->payload);
            $this->markTaskSuccess($task);

            return true;
        } catch (\Throwable $e) {
            $this->markTaskFailed($task, ErrorType::TASK_EXECUTION_FAILED, $e->getMessage());

            return false;
        }
    }

    public function runRecurringTask(RecurringTaskRecord $task): bool
    {
        $className = $task->class;

        if (! $this->validator->validateTaskClass($className)) {
            $this->markRecurringFailed($task, ErrorType::INVALID_TASK_CLASS, $className);

            return false;
        }

        $taskInstance = $this->instantiateRecurringTask($className, $task);

        try {
            $taskInstance->execute($task->payload);
            $this->markRecurringSuccess($task);

            return true;
        } catch (\Throwable $e) {
            $this->markRecurringFailed($task, ErrorType::TASK_EXECUTION_FAILED, $e->getMessage());

            return false;
        }
    }

    private function logGracePeriodIfNeeded(TaskRecord $task): void
    {
        if (! $this->validator->isUniqueTaskWithGracePeriod($task)) {
            return;
        }

        $end_at_timestamp = $task->end_at !== null
            ? new UnixTimestampVO(strtotime($task->end_at->value))
            : new UnixTimestampVO;

        $now = new UnixTimestampVO;

        if ($now->isAfter($end_at_timestamp)) {
            $delay = $now->diff($end_at_timestamp);

            $payload = $this->hydration->hydrate(StrictDataObject::class, [
                'event' => 'task_executed_during_grace_period',
                'task_id' => $task->id->value,
                'task_signature' => $task->signature->value,
                'delay_seconds' => $delay,
            ]);

            $this->logger->warning(new LogDataRecord(
                type: 'task',
                payload: $payload,
            ));

            $this->storeGracePeriodRecord(new GracePeriodRecord(
                task_id: $task->id,
                signature: $task->signature,
                original_end_at: $end_at_timestamp,
                executed_at: $now,
                delay_seconds: new CounterVO($delay),
            ));
        }
    }

    private function storeGracePeriodRecord(GracePeriodRecord $record): void
    {
        $grace_path = $this->config->storageGracePeriodPath();

        $filePath = new GracePeriodFilePathVO($grace_path, $record->task_id);

        if (! $this->fs->isDirectory($filePath->getDirectory())) {
            $this->fs->makeDirectory($filePath->getDirectory());
        }

        $this->fs->put($filePath->getValue(), json_encode($record->toArray(), JSON_PRETTY_PRINT));
    }

    private function instantiateTask(string $className, TaskRecord $task): AbstractTask
    {
        $context = new TaskContext;
        $context->setTaskId($task->id);
        $context->setSignature($task->signature);
        $context->setLaravelApp($this->app);

        return new $className($context, $this->logger, $this->hydration);
    }

    private function instantiateRecurringTask(string $className, RecurringTaskRecord $task): AbstractTask
    {
        $context = new TaskContext;
        $context->setSignature($task->signature);
        $context->setLaravelApp($this->app);

        return new $className($context, $this->logger, $this->hydration);
    }

    private function markTaskSuccess(TaskRecord $task): void
    {
        $this->taskRepository->moveToCompleted($task, true);
    }

    private function markTaskFailed(TaskRecord $task, ErrorType $error_type, ?string $details = null): void
    {
        if ($error_type->isTerminal()) {
            $this->taskRepository->moveToCompleted($task, false);

            return;
        }

        $new_attempts = $task->attempts->increment();
        $is_expired = $this->validator->isTaskExpired($task);

        if ($new_attempts->value >= $task->max_attempts->value || $is_expired) {
            $this->taskRepository->moveToCompleted($task, false);

            return;
        }

        $this->taskRepository->delete($task->id);

        $updated_task = new TaskRecord(
            id: $task->id,
            signature: $task->signature,
            class: $task->class,
            payload: $task->payload,
            status: TaskStatus::PENDING,
            created_at: $task->created_at,
            start_at: $task->start_at,
            end_at: $task->end_at,
            delay_seconds: $task->delay_seconds,
            attempts: $new_attempts,
            max_attempts: $task->max_attempts,
            last_error: $details ?? $error_type->getMessage(),
            enforce_exact_schedule: $task->enforce_exact_schedule,
        );

        $this->taskRepository->save($updated_task);
    }

    private function markRecurringSuccess(RecurringTaskRecord $task): void
    {
        $this->recurringTaskRepository->updateAfterRun($task, true, null);
    }

    private function markRecurringFailed(RecurringTaskRecord $task, ErrorType $error_type, ?string $details = null): void
    {
        $error_message = $details ?? $error_type->getMessage();
        $this->recurringTaskRepository->updateAfterRun($task, false, $error_message);
    }
}
