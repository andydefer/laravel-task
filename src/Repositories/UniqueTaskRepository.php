<?php

declare(strict_types=1);

namespace AndyDefer\Task\Repositories;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\LaravelJsonl\JsonlService;
use AndyDefer\PhpServices\Contracts\FileSystemInterface;
use AndyDefer\PhpServices\Enums\PermissionMode;
use AndyDefer\Task\Collections\UniqueTaskRecordCollection;
use AndyDefer\Task\Contracts\Repositories\UniqueTaskRepositoryInterface;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\Strategies\UniqueTaskPathStrategy;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

final class UniqueTaskRepository implements UniqueTaskRepositoryInterface
{
    private string $basePath;

    private UniqueTaskPathStrategy $pathStrategy;

    public function __construct(
        private readonly JsonlService $jsonl,
        private readonly HydrationService $hydration,
        private readonly FileSystemInterface $fs,
        string $storagePath,
    ) {
        $this->basePath = rtrim($storagePath, '/').'/unique';
        $this->pathStrategy = new UniqueTaskPathStrategy($this->basePath);
    }

    private function ensureDirectory(string $path): void
    {
        if (! $this->fs->isDirectory($path)) {
            $this->fs->makeDirectory($path, PermissionMode::DIRECTORY, true);
        }
    }

    private function getStatusDirectory(UniqueTaskStatus $status): string
    {
        return match ($status) {
            UniqueTaskStatus::PENDING => $this->basePath.'/pending',
            UniqueTaskStatus::COMPLETED => $this->basePath.'/completed',
            UniqueTaskStatus::FAILED => $this->basePath.'/failed',
        };
    }

    private function getFilePath(TaskIdVO $id, UniqueTaskStatus $status): string
    {
        return $this->getStatusDirectory($status).'/'.$id->value.'.jsonl';
    }

    public function save(UniqueTaskRecord $task): void
    {
        $dir = $this->getStatusDirectory($task->status);
        $this->ensureDirectory($dir);

        $path = $dir.'/'.$task->id->value.'.jsonl';

        $this->jsonl->write($task);
    }

    public function find(TaskIdVO $id): ?UniqueTaskRecord
    {
        $statuses = [UniqueTaskStatus::PENDING, UniqueTaskStatus::COMPLETED, UniqueTaskStatus::FAILED];

        foreach ($statuses as $status) {
            $path = $this->getFilePath($id, $status);

            if ($this->fs->exists($path)) {
                $lines = $this->jsonl->readAll($path);
                if (! empty($lines)) {
                    $last = end($lines);

                    return $this->hydration->hydrate(UniqueTaskRecord::class, $last);
                }
            }
        }

        return null;
    }

    public function findAllVersions(TaskIdVO $id): UniqueTaskRecordCollection
    {
        $collection = new UniqueTaskRecordCollection;
        $statuses = [UniqueTaskStatus::PENDING, UniqueTaskStatus::COMPLETED, UniqueTaskStatus::FAILED];

        foreach ($statuses as $status) {
            $path = $this->getFilePath($id, $status);

            if ($this->fs->exists($path)) {
                $lines = $this->jsonl->readAll($path);
                echo '      📖 findAllVersions() - '.count($lines).' lignes trouvées dans '.$status->value."\n";
                foreach ($lines as $line) {
                    $task = $this->hydration->hydrate(UniqueTaskRecord::class, $line);
                    $collection->add($task);
                }
            }
        }

        return $collection;
    }

    public function findByAlias(TaskSignatureVO $alias): UniqueTaskRecordCollection
    {
        $collection = new UniqueTaskRecordCollection;
        $dir = $this->getStatusDirectory(UniqueTaskStatus::PENDING);

        if (! $this->fs->isDirectory($dir)) {
            return $collection;
        }

        $files = $this->fs->glob($dir.'/*.jsonl');

        foreach ($files as $file) {
            $lines = $this->jsonl->readAll($file);
            foreach ($lines as $line) {
                $task = $this->hydration->hydrate(UniqueTaskRecord::class, $line);
                if ($task->alias->value === $alias->value && $task->status === UniqueTaskStatus::PENDING) {
                    $collection->add($task);
                }
            }
        }

        return $collection;
    }

    public function findAll(?int $limit = null): UniqueTaskRecordCollection
    {
        $collection = new UniqueTaskRecordCollection;
        $statuses = [UniqueTaskStatus::PENDING, UniqueTaskStatus::COMPLETED, UniqueTaskStatus::FAILED];

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
                    $task = $this->hydration->hydrate(UniqueTaskRecord::class, end($lines));
                    $collection->add($task);
                    $count++;
                }
            }
        }

        return $collection;
    }

    public function findPending(?int $limit = null): UniqueTaskRecordCollection
    {
        $collection = new UniqueTaskRecordCollection;
        $dir = $this->getStatusDirectory(UniqueTaskStatus::PENDING);

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
                $task = $this->hydration->hydrate(UniqueTaskRecord::class, end($lines));
                $collection->add($task);
                $count++;
            }
        }

        return $collection;
    }

    public function findCompleted(?int $limit = null): UniqueTaskRecordCollection
    {
        $collection = new UniqueTaskRecordCollection;
        $dir = $this->getStatusDirectory(UniqueTaskStatus::COMPLETED);

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
                $task = $this->hydration->hydrate(UniqueTaskRecord::class, end($lines));
                $collection->add($task);
                $count++;
            }
        }

        return $collection;
    }

    public function findFailed(?int $limit = null): UniqueTaskRecordCollection
    {
        $collection = new UniqueTaskRecordCollection;
        $dir = $this->getStatusDirectory(UniqueTaskStatus::FAILED);

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
                $task = $this->hydration->hydrate(UniqueTaskRecord::class, end($lines));
                $collection->add($task);
                $count++;
            }
        }

        return $collection;
    }

    public function findReadyToRun(string $now): UniqueTaskRecordCollection
    {
        $collection = new UniqueTaskRecordCollection;
        $dir = $this->getStatusDirectory(UniqueTaskStatus::PENDING);

        if (! $this->fs->isDirectory($dir)) {
            return $collection;
        }

        $files = $this->fs->glob($dir.'/*.jsonl');

        foreach ($files as $file) {
            $lines = $this->jsonl->readAll($file);
            if (empty($lines)) {
                continue;
            }

            $task = $this->hydration->hydrate(UniqueTaskRecord::class, end($lines));
            if ($task->status === UniqueTaskStatus::PENDING && $task->scheduled_at->value <= $now) {
                $collection->add($task);
            }
        }

        return $collection;
    }

    public function findExpired(string $now): UniqueTaskRecordCollection
    {
        $collection = new UniqueTaskRecordCollection;
        $dir = $this->getStatusDirectory(UniqueTaskStatus::PENDING);

        if (! $this->fs->isDirectory($dir)) {
            return $collection;
        }

        $files = $this->fs->glob($dir.'/*.jsonl');

        foreach ($files as $file) {
            $lines = $this->jsonl->readAll($file);
            if (empty($lines)) {
                continue;
            }

            $task = $this->hydration->hydrate(UniqueTaskRecord::class, end($lines));

            $expirationTime = strtotime($task->scheduled_at->value) + $task->grace_period_seconds;
            if ($task->status === UniqueTaskStatus::PENDING && strtotime($now) > $expirationTime) {
                $collection->add($task);
            }
        }

        return $collection;
    }

    public function delete(TaskIdVO $id): void
    {
        $statuses = [UniqueTaskStatus::PENDING, UniqueTaskStatus::COMPLETED, UniqueTaskStatus::FAILED];

        foreach ($statuses as $status) {
            $path = $this->getFilePath($id, $status);
            if ($this->fs->exists($path)) {
                $this->fs->delete($path);

                return;
            }
        }

    }

    public function moveToCompleted(TaskIdVO $id, UniqueTaskRecord $task): void
    {
        $source = $this->getFilePath($id, UniqueTaskStatus::PENDING);

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
        $lastLine['status'] = UniqueTaskStatus::COMPLETED->value;
        $lastLine['finished_at'] = (new Iso8601DateTimeVO)->value;

        // ✅ 3. Ajouter la nouvelle ligne au fichier source
        $this->fs->append($source, json_encode($lastLine)."\n");

        // ✅ 4. Déplacer le fichier entier vers le nouveau dossier
        $targetDir = $this->getStatusDirectory(UniqueTaskStatus::COMPLETED);
        $this->ensureDirectory($targetDir);

        $target = $targetDir.'/'.$id->value.'.jsonl';

        // ✅ 5. Déplacer (move) le fichier source vers target
        $this->fs->move($source, $target);

    }

    public function moveToFailed(TaskIdVO $id, UniqueTaskRecord $task): void
    {
        $source = $this->getFilePath($id, UniqueTaskStatus::PENDING);

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
        $lastLine['status'] = UniqueTaskStatus::FAILED->value;
        $lastLine['finished_at'] = (new Iso8601DateTimeVO)->value;

        // ✅ 3. Ajouter la nouvelle ligne au fichier source
        $this->fs->append($source, json_encode($lastLine)."\n");

        // ✅ 4. Déplacer le fichier entier vers le nouveau dossier
        $targetDir = $this->getStatusDirectory(UniqueTaskStatus::FAILED);
        $this->ensureDirectory($targetDir);

        $target = $targetDir.'/'.$id->value.'.jsonl';

        // ✅ 5. Déplacer (move) le fichier source vers target
        $this->fs->move($source, $target);

    }

    public function count(): int
    {
        $count = 0;
        $statuses = [UniqueTaskStatus::PENDING, UniqueTaskStatus::COMPLETED, UniqueTaskStatus::FAILED];

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
        $dir = $this->getStatusDirectory(UniqueTaskStatus::PENDING);
        $count = $this->fs->isDirectory($dir) ? count($this->fs->glob($dir.'/*.jsonl')) : 0;

        return $count;
    }

    public function countCompleted(): int
    {
        $dir = $this->getStatusDirectory(UniqueTaskStatus::COMPLETED);
        $count = $this->fs->isDirectory($dir) ? count($this->fs->glob($dir.'/*.jsonl')) : 0;

        return $count;
    }

    public function countFailed(): int
    {
        $dir = $this->getStatusDirectory(UniqueTaskStatus::FAILED);
        $count = $this->fs->isDirectory($dir) ? count($this->fs->glob($dir.'/*.jsonl')) : 0;

        return $count;
    }
}
