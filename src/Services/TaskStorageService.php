<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services;

use AndyDefer\Task\Collections\RecurringTaskRecordCollection;
use AndyDefer\Task\Collections\TaskRecordCollection;
use AndyDefer\Task\Configs\TaskConfig;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskRecord;

/**
 * File-based storage for pending, recurring, and completed tasks.
 *
 * Uses JSON files to persist task data between requests.
 * Tasks are stored in separate directories based on their state.
 */
class TaskStorageService
{
    public function __construct(
        private readonly TaskConfig $config,
    ) {}

    private function ensureDirectories(): void
    {
        foreach (
            [
                $this->config->storagePendingPath(),
                $this->config->storageRecurringPath(),
                $this->config->storageCompletedPath(),
            ] as $path
        ) {
            if (! is_dir($path)) {
                mkdir($path, 0755, true);
            }
        }
    }

    private function sortFilesByTime(array $files, string $order): array
    {
        usort($files, function ($a, $b) use ($order) {
            $timeA = filemtime($a);
            $timeB = filemtime($b);

            if ($timeA === $timeB) {
                return strcmp(basename($a), basename($b));
            }

            if ($order === 'oldest') {
                return $timeA - $timeB;
            }

            return $timeB - $timeA;
        });

        return $files;
    }

    private function applyLimit(array $files, ?int $limit): array
    {
        if ($limit === 0) {
            return [];
        }

        if ($limit === null || $limit <= 0) {
            return $files;
        }

        return array_slice($files, 0, $limit);
    }

    // ==================== Unique Tasks ====================

    public function savePending(TaskRecord $task): void
    {
        $this->ensureDirectories();

        $filePath = $this->config->storagePendingPath().'/'.$task->id.'.json';
        file_put_contents($filePath, json_encode($task->toArray(), JSON_PRETTY_PRINT));
        usleep(1000);
    }

    public function findPending(?int $limit = null, string $order = 'oldest'): TaskRecordCollection
    {
        $results = new TaskRecordCollection;
        $files = glob($this->config->storagePendingPath().'/*.json');

        $files = $this->sortFilesByTime($files, $order);
        $files = $this->applyLimit($files, $limit);

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $data = json_decode($content, true);

            if ($data === null) {
                continue;
            }

            $task = TaskRecord::from($data);

            if ($this->shouldRunTaskNow($task)) {
                $results->add($task);
            }
        }

        return $results;
    }

    public function deletePending(string $id): void
    {
        $filePath = $this->config->storagePendingPath().'/'.$id.'.json';

        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    public function moveToCompleted(TaskRecord $task, bool $success = true): void
    {
        $date = date('Y-m-d');
        $completedDir = $this->config->storageCompletedPath().'/'.$date;

        if (! is_dir($completedDir)) {
            mkdir($completedDir, 0755, true);
        }

        $source = $this->config->storagePendingPath().'/'.$task->id.'.json';
        $target = $completedDir.'/'.$task->id.'.json';

        if (file_exists($source)) {
            rename($source, $target);
        }
    }

    // ==================== Recurring Tasks ====================

    public function saveRecurring(RecurringTaskRecord $task): void
    {
        $this->ensureDirectories();

        $filePath = $this->config->storageRecurringPath().'/'.$task->signature.'.json';
        file_put_contents($filePath, json_encode($task->toArray(), JSON_PRETTY_PRINT));
        usleep(1000);
    }

    public function findRecurring(?int $limit = null, string $order = 'oldest'): RecurringTaskRecordCollection
    {
        $results = new RecurringTaskRecordCollection;
        $files = glob($this->config->storageRecurringPath().'/*.json');

        $files = $this->sortFilesByTime($files, $order);
        $files = $this->applyLimit($files, $limit);

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $data = json_decode($content, true);

            if ($data === null) {
                continue;
            }

            $task = RecurringTaskRecord::from($data);

            if ($this->shouldRunRecurringNow($task)) {
                $results->add($task);
            }
        }

        return $results;
    }

    public function getRecurring(string $signature): ?RecurringTaskRecord
    {
        $filePath = $this->config->storageRecurringPath().'/'.$signature.'.json';

        if (! file_exists($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);
        $data = json_decode($content, true);

        if ($data === null) {
            return null;
        }

        return RecurringTaskRecord::from($data);
    }

    public function updateRecurringAfterRun(RecurringTaskRecord $task, bool $success, ?string $error = null): void
    {
        $now = date('c');
        $nextRunAt = date('c', strtotime($now) + $task->delaySeconds);

        $updated = RecurringTaskRecord::from([
            'signature' => $task->signature,
            'class' => $task->class,
            'payload' => $task->payload,
            'mode' => $task->mode,
            'startAt' => $task->startAt,
            'endAt' => $task->endAt,
            'delaySeconds' => $task->delaySeconds,
            'lastRunAt' => $now,
            'nextRunAt' => $nextRunAt,
            'successCount' => $success ? $task->successCount + 1 : $task->successCount,
            'failureCount' => $success ? $task->failureCount : $task->failureCount + 1,
            'lastError' => $error,
        ]);

        $this->saveRecurring($updated);
    }

    public function deleteRecurring(string $signature): void
    {
        $filePath = $this->config->storageRecurringPath().'/'.$signature.'.json';

        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    public function getAllRecurring(): RecurringTaskRecordCollection
    {
        $results = new RecurringTaskRecordCollection;
        $files = glob($this->config->storageRecurringPath().'/*.json');

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $data = json_decode($content, true);

            if ($data === null) {
                continue;
            }

            $results->add(RecurringTaskRecord::from($data));
        }

        return $results;
    }

    public function getAllPending(): TaskRecordCollection
    {
        $results = new TaskRecordCollection;
        $files = glob($this->config->storagePendingPath().'/*.json');

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $data = json_decode($content, true);

            if ($data === null) {
                continue;
            }

            $results->add(TaskRecord::from($data));
        }

        return $results;
    }

    // ==================== Helpers ====================

    private function shouldRunTaskNow(TaskRecord $task): bool
    {
        $now = time();

        $startAtTimestamp = strtotime($task->startAt);
        $endAtTimestamp = $task->endAt !== null ? strtotime($task->endAt) : PHP_INT_MAX;

        if ($now < $startAtTimestamp) {
            return false;
        }

        if ($now > $endAtTimestamp) {
            return false;
        }

        if (! $task->status->isPending()) {
            return false;
        }

        if ($task->attempts >= $task->maxAttempts) {
            return false;
        }

        return true;
    }

    private function shouldRunRecurringNow(RecurringTaskRecord $task): bool
    {
        $now = time();

        $startAtTimestamp = strtotime($task->startAt);
        $endAtTimestamp = $task->endAt !== null ? strtotime($task->endAt) : PHP_INT_MAX;
        $nextRunAtTimestamp = strtotime($task->nextRunAt);

        if ($now < $startAtTimestamp) {
            return false;
        }

        if ($now > $endAtTimestamp) {
            return false;
        }

        if ($now < $nextRunAtTimestamp) {
            return false;
        }

        return true;
    }
}
