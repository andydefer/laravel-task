<?php

declare(strict_types=1);

namespace AndyDefer\Task\Strategies;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\LaravelJsonl\Contracts\JsonlPathStrategyInterface;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use InvalidArgumentException;

final class UniqueTaskPathStrategy implements JsonlPathStrategyInterface
{
    public function __construct(
        private readonly string $base_path,
    ) {}

    public function getFilePath(AbstractRecord $entity): string
    {
        if (! $entity instanceof UniqueTaskRecord) {
            throw new InvalidArgumentException(
                sprintf('Expected UniqueTaskRecord, got %s', get_class($entity))
            );
        }

        return $this->getStatusPath($entity);
    }

    public function getFilesToScan(AbstractRecord $query): array
    {
        return [];
    }

    public function getBaseDirectory(): string
    {
        return $this->base_path;
    }

    private function getStatusPath(UniqueTaskRecord $task): string
    {
        $statusDir = match ($task->status) {
            UniqueTaskStatus::PENDING => 'pending',
            UniqueTaskStatus::COMPLETED => 'completed',
            UniqueTaskStatus::FAILED => 'failed',
        };

        return implode(DIRECTORY_SEPARATOR, [
            rtrim($this->base_path, DIRECTORY_SEPARATOR),
            'unique',
            $statusDir,
            $task->id->value.'.jsonl',
        ]);
    }

    public function getPendingPath(TaskIdVO $taskId): string
    {
        return implode(DIRECTORY_SEPARATOR, [
            rtrim($this->base_path, DIRECTORY_SEPARATOR),
            'unique',
            'pending',
            $taskId->value.'.jsonl',
        ]);
    }

    public function getCompletedPath(TaskIdVO $taskId): string
    {
        return implode(DIRECTORY_SEPARATOR, [
            rtrim($this->base_path, DIRECTORY_SEPARATOR),
            'unique',
            'completed',
            $taskId->value.'.jsonl',
        ]);
    }

    public function getFailedPath(TaskIdVO $taskId): string
    {
        return implode(DIRECTORY_SEPARATOR, [
            rtrim($this->base_path, DIRECTORY_SEPARATOR),
            'unique',
            'failed',
            $taskId->value.'.jsonl',
        ]);
    }
}
