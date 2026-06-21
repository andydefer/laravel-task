<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Task\Abstract\RecurringTask;
use AndyDefer\Task\Contexts\RecurringTaskContext;
use AndyDefer\Task\Contracts\Configs\RecurringTaskConfigInterface;
use AndyDefer\Task\Contracts\Repositories\RecurringTaskRepositoryInterface;
use AndyDefer\Task\Contracts\Services\RecurringTaskServiceInterface;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use Illuminate\Contracts\Foundation\Application;

final class RecurringTaskService implements RecurringTaskServiceInterface
{
    public function __construct(
        private readonly RecurringTaskRepositoryInterface $repository,
        private readonly LoggerInterface $logger,
        private readonly HydrationService $hydration,
        private readonly Application $app,
    ) {}

    public function register(string $taskClass, StrictDataObject $payload, RecurringTaskConfigInterface $config): TaskSignatureVO
    {
        $this->validateTaskClass($taskClass);

        $alias = $config->getAlias();

        if ($this->repository->find($alias) !== null) {
            throw new \RuntimeException("Recurring task '{$alias->value}' already exists");
        }

        $now = date('c');
        $start_at = $config->getStartAt()?->value ?? $now;

        $record = new RecurringTaskRecord(
            alias: $alias,
            fqcn: $taskClass,
            payload: $payload,
            interval_seconds: $config->getIntervalSeconds(),
            start_at: new Iso8601DateTimeVO($start_at),
            end_at: $config->getEndAt(),
            next_run_at: new Iso8601DateTimeVO($start_at),
        );

        $this->repository->save($record);

        return $alias;
    }

    public function run(TaskSignatureVO $alias): bool
    {
        $record = $this->repository->find($alias);

        if ($record === null) {
            return false;
        }

        if ($record->end_at !== null && $record->end_at->value <= date('c')) {
            return false;
        }

        if ($record->next_run_at !== null && $record->next_run_at->value > date('c')) {
            return false;
        }

        $task = $this->instantiateTask($record->fqcn, $record);

        try {
            $task->execute($record->payload);
            $this->repository->updateAfterRun($record, true);

            return true;
        } catch (\Throwable $e) {
            $this->repository->updateAfterRun($record, false, $e->getMessage());

            return false;
        }
    }

    public function find(TaskSignatureVO $alias): ?RecurringTaskRecord
    {
        return $this->repository->find($alias);
    }

    public function delete(TaskSignatureVO $alias): void
    {
        $this->repository->delete($alias);
    }

    public function process(?int $limit = null): array
    {
        $results = ['success' => 0, 'failed' => 0];
        $tasks = $this->repository->findReadyToRun(date('c'));

        if ($limit !== null) {
            $count = 0;
            $tasks = $tasks->filter(function () use ($limit, &$count) {
                return ++$count <= $limit;
            });
        }

        foreach ($tasks as $task) {
            $success = $this->run($task->alias);
            $results[$success ? 'success' : 'failed']++;
        }

        return $results;
    }

    private function validateTaskClass(string $taskClass): void
    {
        if (! is_subclass_of($taskClass, RecurringTask::class)) {
            throw new \InvalidArgumentException('Task must extend RecurringTask');
        }
    }

    private function instantiateTask(string $fqcn, RecurringTaskRecord $record): RecurringTask
    {
        $context = new RecurringTaskContext;
        $context->setAlias($record->alias);
        $context->setIntervalSeconds($record->interval_seconds);
        $context->setStartAt($record->start_at);
        $context->setEndAt($record->end_at);
        $context->setLastRunAt($record->last_run_at);
        $context->setNextRunAt($record->next_run_at);
        $context->setLaravelApp($this->app);

        return new $fqcn($context, $this->logger, $this->hydration);
    }
}
