<?php

declare(strict_types=1);

namespace AndyDefer\Task\Repositories;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\LaravelJsonl\JsonlService;
use AndyDefer\PhpServices\Contracts\FileSystemInterface;
use AndyDefer\PhpServices\Enums\PermissionMode;
use AndyDefer\Task\Collections\RecurringTaskRecordCollection;
use AndyDefer\Task\Contracts\Repositories\RecurringTaskRepositoryInterface;
use AndyDefer\Task\Enums\ExecutionStatus;
use AndyDefer\Task\Enums\RecurringTaskStatus;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskExecutionDebugRecord;
use AndyDefer\Task\Strategies\RecurringTaskPathStrategy;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

final class RecurringTaskRepository implements RecurringTaskRepositoryInterface
{
    private string $basePath;

    private RecurringTaskPathStrategy $pathStrategy;

    public function __construct(
        private readonly JsonlService $jsonl,
        private readonly HydrationService $hydration,
        private readonly FileSystemInterface $fs,
        string $storagePath,
    ) {
        $this->basePath = rtrim($storagePath, '/').'/recurring';
        $this->pathStrategy = new RecurringTaskPathStrategy($this->basePath);
    }

    private function ensureDirectory(string $path): void
    {
        if (! $this->fs->isDirectory($path)) {
            $this->fs->makeDirectory($path, PermissionMode::DIRECTORY, true);
        }
    }

    private function getStatusDirectory(RecurringTaskStatus $status): string
    {
        return match ($status) {
            RecurringTaskStatus::PENDING => $this->basePath.'/pending',
            RecurringTaskStatus::RUNNING => $this->basePath.'/running',
            RecurringTaskStatus::FINISHED => $this->basePath.'/finished',
        };
    }

    private function getFilePath(TaskSignatureVO $alias, RecurringTaskStatus $status): string
    {
        return $this->getStatusDirectory($status).'/'.$alias->value.'.jsonl';
    }

    public function save(RecurringTaskRecord $task): void
    {
        $dir = $this->getStatusDirectory($task->status);
        $this->ensureDirectory($dir);

        $path = $dir.'/'.$task->alias->value.'.jsonl';

        $this->jsonl->write($task);
    }

    public function find(TaskSignatureVO $alias): ?RecurringTaskRecord
    {
        $statuses = [RecurringTaskStatus::PENDING, RecurringTaskStatus::RUNNING, RecurringTaskStatus::FINISHED];

        foreach ($statuses as $status) {
            $path = $this->getFilePath($alias, $status);

            if ($this->fs->exists($path)) {
                $lines = $this->jsonl->readAll($path);
                if (! empty($lines)) {
                    $last = end($lines);

                    return $this->hydration->hydrate(RecurringTaskRecord::class, $last);
                }
            }
        }

        return null;
    }

    public function findAllVersions(TaskSignatureVO $alias): RecurringTaskRecordCollection
    {
        $collection = new RecurringTaskRecordCollection;
        $statuses = [RecurringTaskStatus::PENDING, RecurringTaskStatus::RUNNING, RecurringTaskStatus::FINISHED];

        foreach ($statuses as $status) {
            $path = $this->getFilePath($alias, $status);

            if ($this->fs->exists($path)) {
                $lines = $this->jsonl->readAll($path);
                foreach ($lines as $line) {
                    $task = $this->hydration->hydrate(RecurringTaskRecord::class, $line);
                    $collection->add($task);
                }
            }
        }

        return $collection;
    }

    public function findAll(?int $limit = null): RecurringTaskRecordCollection
    {
        $collection = new RecurringTaskRecordCollection;
        $statuses = [RecurringTaskStatus::PENDING, RecurringTaskStatus::RUNNING, RecurringTaskStatus::FINISHED];

        $count = 0;
        foreach ($statuses as $status) {
            $dir = $this->getStatusDirectory($status);
            if (! $this->fs->isDirectory($dir)) {
                continue;
            }

            $files = $this->fs->glob($dir.'/*.jsonl');

            foreach ($files as $file) {
                if ($limit !== null && $count >= $limit) {
                    break 2;
                }

                $lines = $this->jsonl->readAll($file);
                if (! empty($lines)) {
                    $task = $this->hydration->hydrate(RecurringTaskRecord::class, end($lines));
                    $collection->add($task);
                    $count++;
                }
            }
        }

        return $collection;
    }

    public function findPending(?int $limit = null): RecurringTaskRecordCollection
    {
        $collection = new RecurringTaskRecordCollection;
        $dir = $this->getStatusDirectory(RecurringTaskStatus::PENDING);

        if (! $this->fs->isDirectory($dir)) {
            return $collection;
        }

        $files = $this->fs->glob($dir.'/*.jsonl');
        $count = 0;

        foreach ($files as $file) {
            if ($limit !== null && $count >= $limit) {
                break;
            }

            $lines = $this->jsonl->readAll($file);
            if (! empty($lines)) {
                $task = $this->hydration->hydrate(RecurringTaskRecord::class, end($lines));
                $collection->add($task);
                $count++;
            }
        }

        return $collection;
    }

    public function findRunning(?int $limit = null): RecurringTaskRecordCollection
    {
        $collection = new RecurringTaskRecordCollection;
        $dir = $this->getStatusDirectory(RecurringTaskStatus::RUNNING);

        if (! $this->fs->isDirectory($dir)) {
            return $collection;
        }

        $files = $this->fs->glob($dir.'/*.jsonl');
        $count = 0;

        foreach ($files as $file) {
            if ($limit !== null && $count >= $limit) {
                break;
            }

            $lines = $this->jsonl->readAll($file);
            if (! empty($lines)) {
                $task = $this->hydration->hydrate(RecurringTaskRecord::class, end($lines));
                $collection->add($task);
                $count++;
            }
        }

        return $collection;
    }

    public function findFinished(?int $limit = null): RecurringTaskRecordCollection
    {
        $collection = new RecurringTaskRecordCollection;
        $dir = $this->getStatusDirectory(RecurringTaskStatus::FINISHED);

        if (! $this->fs->isDirectory($dir)) {
            return $collection;
        }

        $files = $this->fs->glob($dir.'/*.jsonl');
        $count = 0;

        foreach ($files as $file) {
            if ($limit !== null && $count >= $limit) {
                break;
            }

            $lines = $this->jsonl->readAll($file);
            if (! empty($lines)) {
                $task = $this->hydration->hydrate(RecurringTaskRecord::class, end($lines));
                $collection->add($task);
                $count++;
            }
        }

        return $collection;
    }

    public function findReadyToRun(string $now): RecurringTaskRecordCollection
    {
        $collection = new RecurringTaskRecordCollection;
        $dir = $this->getStatusDirectory(RecurringTaskStatus::PENDING);

        if (! $this->fs->isDirectory($dir)) {
            return $collection;
        }

        $files = $this->fs->glob($dir.'/*.jsonl');

        foreach ($files as $file) {
            $lines = $this->jsonl->readAll($file);
            if (empty($lines)) {
                continue;
            }

            $task = $this->hydration->hydrate(RecurringTaskRecord::class, end($lines));

            $lastRun = $task->last_run_at ?? $task->start_at;
            $nextRun = $lastRun !== null
                ? strtotime($lastRun->value) + $task->interval_seconds->value
                : strtotime($task->start_at->value);

            if ($task->status === RecurringTaskStatus::PENDING && $nextRun <= strtotime($now)) {
                $collection->add($task);
            }
        }

        return $collection;
    }

    public function delete(TaskSignatureVO $alias): void
    {
        $statuses = [RecurringTaskStatus::PENDING, RecurringTaskStatus::RUNNING, RecurringTaskStatus::FINISHED];

        foreach ($statuses as $status) {
            $path = $this->getFilePath($alias, $status);
            if ($this->fs->exists($path)) {
                $this->fs->delete($path);

                return;
            }
        }

    }

    public function moveToRunning(TaskSignatureVO $alias, RecurringTaskRecord $task): void
    {
        $source = $this->getFilePath($alias, RecurringTaskStatus::PENDING);

        if (! $this->fs->exists($source)) {

            return;
        }

        // ✅ 1. Lire TOUTES les lignes du fichier source
        $lines = $this->jsonl->readAll($source);
        if (empty($lines)) {
            return;
        }

        // ✅ 2. Prendre la dernière ligne et la modifier avec le nouveau statut
        $lastLine = end($lines);
        $lastLine['status'] = RecurringTaskStatus::RUNNING->value;

        // ✅ 3. Ajouter la nouvelle ligne au fichier source
        $this->fs->append($source, json_encode($lastLine)."\n");

        // ✅ 4. Déplacer le fichier entier vers le nouveau dossier
        $targetDir = $this->getStatusDirectory(RecurringTaskStatus::RUNNING);
        $this->ensureDirectory($targetDir);

        $target = $targetDir.'/'.$alias->value.'.jsonl';

        // ✅ 5. Déplacer (move) le fichier source vers target
        $this->fs->move($source, $target);

    }

    public function moveToFinished(TaskSignatureVO $alias, RecurringTaskRecord $task): void
    {
        $source = $this->getFilePath($alias, RecurringTaskStatus::RUNNING);

        if (! $this->fs->exists($source)) {

            return;
        }

        // ✅ 1. Lire TOUTES les lignes du fichier source
        $lines = $this->jsonl->readAll($source);
        if (empty($lines)) {
            return;
        }

        // ✅ 2. Prendre la dernière ligne et la modifier avec le nouveau statut
        $lastLine = end($lines);
        $lastLine['status'] = RecurringTaskStatus::FINISHED->value;
        $lastLine['finished_at'] = (new Iso8601DateTimeVO)->value;

        // ✅ 3. Ajouter la nouvelle ligne au fichier source
        $this->fs->append($source, json_encode($lastLine)."\n");

        // ✅ 4. Déplacer le fichier entier vers le nouveau dossier
        $targetDir = $this->getStatusDirectory(RecurringTaskStatus::FINISHED);
        $this->ensureDirectory($targetDir);

        $target = $targetDir.'/'.$alias->value.'.jsonl';

        // ✅ 5. Déplacer (move) le fichier source vers target
        $this->fs->move($source, $target);

    }

    public function moveToPending(TaskSignatureVO $alias, RecurringTaskRecord $task): void
    {
        $source = $this->getFilePath($alias, RecurringTaskStatus::RUNNING);

        if (! $this->fs->exists($source)) {

            return;
        }

        // ✅ 1. Lire TOUTES les lignes du fichier source
        $lines = $this->jsonl->readAll($source);
        if (empty($lines)) {
            return;
        }

        // ✅ 2. Prendre la dernière ligne et la modifier avec le nouveau statut
        $lastLine = end($lines);
        $lastLine['status'] = RecurringTaskStatus::PENDING->value;

        // ✅ 3. Ajouter la nouvelle ligne au fichier source
        $this->fs->append($source, json_encode($lastLine)."\n");

        // ✅ 4. Déplacer le fichier entier vers le nouveau dossier
        $targetDir = $this->getStatusDirectory(RecurringTaskStatus::PENDING);
        $this->ensureDirectory($targetDir);

        $target = $targetDir.'/'.$alias->value.'.jsonl';

        // ✅ 5. Déplacer (move) le fichier source vers target
        $this->fs->move($source, $target);

    }

    public function updateAfterRun(RecurringTaskRecord $task, bool $success, ?string $error = null): void
    {
        $now = new Iso8601DateTimeVO;

        $debugEntry = new TaskExecutionDebugRecord(
            acted_at: $now,
            status: $success ? ExecutionStatus::SUCCEEDED : ExecutionStatus::FAILED,
            info: $success ? 'Task executed successfully' : ($error ?? 'Task execution failed'),
        );

        $newDebug = clone $task->debug;
        $newDebug->add($debugEntry);

        $updated = new RecurringTaskRecord(
            alias: $task->alias,
            fqcn: $task->fqcn,
            payload: $task->payload,
            interval_seconds: $task->interval_seconds,
            start_at: $task->start_at,
            end_at: $task->end_at,
            status: RecurringTaskStatus::PENDING,
            last_run_at: $now,
            debug: $newDebug,
        );

        $this->save($updated);
    }

    public function count(): int
    {
        $count = 0;
        $statuses = [RecurringTaskStatus::PENDING, RecurringTaskStatus::RUNNING, RecurringTaskStatus::FINISHED];

        foreach ($statuses as $status) {
            $dir = $this->getStatusDirectory($status);
            if ($this->fs->isDirectory($dir)) {
                $count += count($this->fs->glob($dir.'/*.jsonl'));
            }
        }

        return $count;
    }

    public function countPending(): int
    {
        $dir = $this->getStatusDirectory(RecurringTaskStatus::PENDING);
        $count = $this->fs->isDirectory($dir) ? count($this->fs->glob($dir.'/*.jsonl')) : 0;

        return $count;
    }

    public function countRunning(): int
    {
        $dir = $this->getStatusDirectory(RecurringTaskStatus::RUNNING);
        $count = $this->fs->isDirectory($dir) ? count($this->fs->glob($dir.'/*.jsonl')) : 0;

        return $count;
    }

    public function countFinished(): int
    {
        $dir = $this->getStatusDirectory(RecurringTaskStatus::FINISHED);
        $count = $this->fs->isDirectory($dir) ? count($this->fs->glob($dir.'/*.jsonl')) : 0;

        return $count;
    }
}
