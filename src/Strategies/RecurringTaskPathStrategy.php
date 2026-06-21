<?php

declare(strict_types=1);

namespace AndyDefer\Task\Strategies;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\LaravelJsonl\Contracts\JsonlPathStrategyInterface;
use AndyDefer\Task\Enums\RecurringTaskStatus;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use InvalidArgumentException;

final class RecurringTaskPathStrategy implements JsonlPathStrategyInterface
{
    public function __construct(
        private readonly string $base_path,
    ) {}

    public function getFilePath(AbstractRecord $entity): string
    {
        if (! $entity instanceof RecurringTaskRecord) {
            throw new InvalidArgumentException(
                sprintf('Expected RecurringTaskRecord, got %s', get_class($entity))
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

    private function getStatusPath(RecurringTaskRecord $task): string
    {
        $statusDir = match ($task->status) {
            RecurringTaskStatus::PENDING => 'pending',
            RecurringTaskStatus::RUNNING => 'running',
            RecurringTaskStatus::FINISHED => 'finished',
        };

        return implode(DIRECTORY_SEPARATOR, [
            rtrim($this->base_path, DIRECTORY_SEPARATOR),
            'recurring',
            $statusDir,
            $task->alias->value.'.jsonl',
        ]);
    }

    public function getPendingPath(TaskSignatureVO $alias): string
    {
        return implode(DIRECTORY_SEPARATOR, [
            rtrim($this->base_path, DIRECTORY_SEPARATOR),
            'recurring',
            'pending',
            $alias->value.'.jsonl',
        ]);
    }

    public function getRunningPath(TaskSignatureVO $alias): string
    {
        return implode(DIRECTORY_SEPARATOR, [
            rtrim($this->base_path, DIRECTORY_SEPARATOR),
            'recurring',
            'running',
            $alias->value.'.jsonl',
        ]);
    }

    public function getFinishedPath(TaskSignatureVO $alias): string
    {
        return implode(DIRECTORY_SEPARATOR, [
            rtrim($this->base_path, DIRECTORY_SEPARATOR),
            'recurring',
            'finished',
            $alias->value.'.jsonl',
        ]);
    }
}
