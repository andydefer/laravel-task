<?php

declare(strict_types=1);

namespace AndyDefer\Task\Repositories;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\LaravelJsonl\JsonlService;
use AndyDefer\PhpServices\Contracts\FileSystemInterface;
use AndyDefer\Task\Collections\TaskRecordCollection;
use AndyDefer\Task\Contracts\Repositories\TaskRepositoryInterface;
use AndyDefer\Task\Contexts\TaskStorageContext;
use AndyDefer\Task\Enums\TaskOrder;
use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskDateVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;

final class TaskRepository implements TaskRepositoryInterface
{
    public function __construct(
        private readonly TaskStorageContext $context,
        private readonly JsonlService $jsonl,
        private readonly HydrationService $hydration,
        private readonly FileSystemInterface $fs,
    ) {}

    public function save(TaskRecord $task): void
    {
        $pendingDir = $this->context->getPendingDir();
        $pendingDir->ensureExists($this->fs);

        // Supprimer l'ancien fichier s'il existe (pour l'écrasement)
        $path = $pendingDir->filePath($task->id);
        if ($this->fs->exists($path)) {
            $this->fs->delete($path);
        }

        $this->jsonl->write($task);
    }

    public function find(TaskIdVO $id): ?TaskRecord
    {
        $path = $this->context->getPendingDir()->filePath($id);

        if (!$this->fs->exists($path)) {
            return null;
        }

        $lines = $this->jsonl->readAll($path);

        if (empty($lines)) {
            return null;
        }

        $task = $this->hydration->hydrate(TaskRecord::class, $lines[0]);

        return $task->status === TaskStatus::PENDING ? $task : null;
    }

    public function findAll(?int $limit = null, TaskOrder $order = TaskOrder::OLDEST): TaskRecordCollection
    {
        if ($limit === 0) {
            return new TaskRecordCollection();
        }

        $pendingDir = $this->context->getPendingDir();

        if (!$this->fs->isDirectory($pendingDir->getValue())) {
            return new TaskRecordCollection();
        }

        $files = $pendingDir->allFiles($this->fs);

        if (empty($files)) {
            return new TaskRecordCollection();
        }

        usort($files, function ($a, $b) use ($order) {
            $timeA = $this->fs->lastModified($a);
            $timeB = $this->fs->lastModified($b);
            return $order->compare($timeA, $timeB);
        });

        if ($limit !== null && $limit > 0) {
            $files = array_slice($files, 0, $limit);
        }

        $tasks = new TaskRecordCollection();

        foreach ($files as $file) {
            $lines = $this->jsonl->readAll($file);
            foreach ($lines as $line) {
                $task = $this->hydration->hydrate(TaskRecord::class, $line);
                if ($task->status === TaskStatus::PENDING) {
                    $tasks->add($task);
                }
            }
        }

        return $tasks;
    }

    public function delete(TaskIdVO $id): void
    {
        $path = $this->context->getPendingDir()->filePath($id);

        if ($this->fs->exists($path)) {
            $this->fs->delete($path);
        }
    }

    public function moveToCompleted(TaskRecord $task, bool $success = true): void
    {
        $source = $this->context->getPendingDir()->filePath($task->id);

        if (!$this->fs->exists($source)) {
            return;
        }

        $lines = $this->jsonl->readAll($source);

        if (empty($lines)) {
            return;
        }

        $taskData = $lines[0];
        $taskData['status'] = $success ? TaskStatus::SUCCESS->value : TaskStatus::FAILED->value;
        $taskData['completed_at'] = (new Iso8601DateTimeVO())->value;

        $date = new TaskDateVO(date('Y-m-d'));
        $completedDir = $this->context->getCompletedDir();
        $completedDir->ensureExists($this->fs);

        $target = $completedDir->filePathWithDate($task->id, $date);

        $this->fs->put($target, json_encode($taskData) . "\n");
        $this->fs->delete($source);
    }
}
