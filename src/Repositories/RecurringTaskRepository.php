<?php

declare(strict_types=1);

namespace AndyDefer\Task\Repositories;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\LaravelJsonl\JsonlService;
use AndyDefer\PhpServices\Contracts\FileSystemInterface;
use AndyDefer\Task\Collections\RecurringTaskRecordCollection;
use AndyDefer\Task\Contracts\Repositories\RecurringTaskRepositoryInterface;
use AndyDefer\Task\Contexts\TaskStorageContext;
use AndyDefer\Task\Enums\TaskOrder;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

final class RecurringTaskRepository implements RecurringTaskRepositoryInterface
{
    public function __construct(
        private readonly TaskStorageContext $context,
        private readonly JsonlService $jsonl,
        private readonly HydrationService $hydration,
        private readonly FileSystemInterface $fs,
    ) {}

    public function save(RecurringTaskRecord $task): void
    {
        $recurringDir = $this->context->getRecurringDir();
        $recurringDir->ensureExists($this->fs);
        $this->jsonl->write($task);
    }

    public function find(TaskSignatureVO $signature): ?RecurringTaskRecord
    {
        $path = $this->context->getRecurringDir()->filePath($signature);

        if (!$this->fs->exists($path)) {
            return null;
        }

        $lines = $this->jsonl->readAll($path);

        if (empty($lines)) {
            return null;
        }

        // Prendre la dernière ligne (la plus récente)
        $lastLine = end($lines);

        return $this->hydration->hydrate(RecurringTaskRecord::class, $lastLine);
    }

    public function findAll(?int $limit = null, ?TaskOrder $order = TaskOrder::OLDEST): RecurringTaskRecordCollection
    {
        if ($limit === 0) {
            return new RecurringTaskRecordCollection();
        }

        $recurringDir = $this->context->getRecurringDir();

        if (!$this->fs->isDirectory($recurringDir->getValue())) {
            return new RecurringTaskRecordCollection();
        }

        $files = $recurringDir->allFiles($this->fs);

        if (empty($files)) {
            return new RecurringTaskRecordCollection();
        }

        usort($files, function ($a, $b) use ($order) {
            $timeA = $this->fs->lastModified($a);
            $timeB = $this->fs->lastModified($b);
            return $order->compare($timeA, $timeB);
        });

        if ($limit !== null && $limit > 0) {
            $files = array_slice($files, 0, $limit);
        }

        $tasks = new RecurringTaskRecordCollection();

        foreach ($files as $file) {
            $lines = $this->jsonl->readAll($file);
            foreach ($lines as $line) {
                $tasks->add($this->hydration->hydrate(RecurringTaskRecord::class, $line));
            }
        }

        return $tasks;
    }

    public function delete(TaskSignatureVO $signature): void
    {
        $path = $this->context->getRecurringDir()->filePath($signature);

        if ($this->fs->exists($path)) {
            $this->fs->delete($path);
        }
    }

    public function updateAfterRun(RecurringTaskRecord $task, bool $success, ?string $error = null): void
    {
        $now = new Iso8601DateTimeVO();
        $next_run_at = new Iso8601DateTimeVO(
            date('c', strtotime($now->value) + $task->delay_seconds->value)
        );

        $new_success_count = $success ? $task->success_count->increment() : $task->success_count;
        $new_failure_count = $success ? $task->failure_count : $task->failure_count->increment();

        $updated = new RecurringTaskRecord(
            signature: $task->signature,
            class: $task->class,
            payload: $task->payload,
            start_at: $task->start_at,
            end_at: $task->end_at,
            delay_seconds: $task->delay_seconds,
            last_run_at: $now,
            next_run_at: $next_run_at,
            success_count: $new_success_count,
            failure_count: $new_failure_count,
            last_error: $error,
        );

        $this->save($updated);
    }
}
