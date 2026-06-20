<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contexts;

use AndyDefer\Task\Contracts\Configs\TaskConfigInterface;
use AndyDefer\Task\Enums\TaskType;
use AndyDefer\Task\ValueObjects\TaskDirectoryVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

final class TaskStorageContext
{
    private TaskDirectoryVO $pendingDir;

    private TaskDirectoryVO $recurringDir;

    private TaskDirectoryVO $completedDir;

    public function __construct(TaskConfigInterface $config)
    {
        $basePath = $config->storagePath();

        $this->pendingDir = new TaskDirectoryVO($basePath, TaskType::PENDING);
        $this->recurringDir = new TaskDirectoryVO($basePath, TaskType::RECURRING);
        $this->completedDir = new TaskDirectoryVO($basePath, TaskType::COMPLETED);
    }

    public function getPendingDir(): TaskDirectoryVO
    {
        return $this->pendingDir;
    }

    public function getRecurringDir(): TaskDirectoryVO
    {
        return $this->recurringDir;
    }

    public function getCompletedDir(): TaskDirectoryVO
    {
        return $this->completedDir;
    }

    public function getRecurringFilePath(TaskSignatureVO $signature): string
    {
        return $this->recurringDir->getValue().DIRECTORY_SEPARATOR.$signature->fileName();
    }

    public function getPendingFilePath(TaskIdVO $taskId): string
    {
        return $this->pendingDir->getValue().DIRECTORY_SEPARATOR.$taskId->fileName();
    }
}
