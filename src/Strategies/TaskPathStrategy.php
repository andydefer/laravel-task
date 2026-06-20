<?php

declare(strict_types=1);

namespace AndyDefer\Task\Strategies;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\LaravelJsonl\Contracts\JsonlPathStrategyInterface;
use AndyDefer\Task\Enums\TaskType;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\ValueObjects\TaskDateVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use InvalidArgumentException;

/**
 * Path strategy for task storage.
 *
 * Structure:
 * - Pending:   /pending/{task_id}.jsonl
 * - Recurring: /recurring/{signature}.jsonl
 * - Completed: /completed/{Y-m-d}/{task_id}.jsonl
 */
final class TaskPathStrategy implements JsonlPathStrategyInterface
{
    public function __construct(
        private readonly string $base_path,
    ) {}

    public function getFilePath(AbstractRecord $entity): string
    {
        return match (true) {
            $entity instanceof TaskRecord => $this->getPendingPath($entity),
            $entity instanceof RecurringTaskRecord => $this->getRecurringPath($entity),
            default => throw new InvalidArgumentException(
                sprintf('Expected TaskRecord or RecurringTaskRecord, got %s', get_class($entity))
            ),
        };
    }

    public function getFilesToScan(AbstractRecord $query): array
    {
        return [];
    }

    public function getBaseDirectory(): string
    {
        return $this->base_path;
    }

    private function buildPath(TaskType $type, string $fileName): string
    {
        return implode(DIRECTORY_SEPARATOR, [
            rtrim($this->base_path, DIRECTORY_SEPARATOR),
            $type->value,
            $fileName,
        ]);
    }

    private function getPendingPath(TaskRecord $task): string
    {
        return $this->buildPath(TaskType::PENDING, $task->id->fileName());
    }

    private function getRecurringPath(RecurringTaskRecord $task): string
    {
        return $this->buildPath(TaskType::RECURRING, $task->signature->fileName());
    }

    public function getCompletedPath(TaskIdVO $taskId, TaskDateVO $date): string
    {
        return implode(DIRECTORY_SEPARATOR, [
            rtrim($this->base_path, DIRECTORY_SEPARATOR),
            TaskType::COMPLETED->value,
            $date->value,
            $taskId->fileName(),
        ]);
    }
}
