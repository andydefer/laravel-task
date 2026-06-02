<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services;

use AndyDefer\DomainStructures\Collections\Utility\StrictDataObjectCollection;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Collections\RecurringTaskRecordCollection;
use AndyDefer\Task\Collections\TaskRecordCollection;
use AndyDefer\Task\Enums\TaskMode;
use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Records\TaskRecord;

/**
 * File-based storage for pending, recurring, and completed tasks.
 *
 * Uses JSON files to persist task data between requests.
 * Tasks are stored in separate directories based on their state.
 *
 * @author Andy Defer
 */
class TaskStorage
{
    private string $pendingPath;

    private string $recurringPath;

    private string $completedPath;

    public function __construct(?string $storagePath = null)
    {
        if ($storagePath === null) {
            $storagePath = config('task.storage_path', storage_path('tasks'));
        }

        $this->pendingPath = $storagePath . '/pending';
        $this->recurringPath = $storagePath . '/recurring';
        $this->completedPath = $storagePath . '/completed';

        $this->ensureDirectories();
    }

    /**
     * Create all required directories if they don't exist.
     */
    private function ensureDirectories(): void
    {
        foreach ([$this->pendingPath, $this->recurringPath, $this->completedPath] as $path) {
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }
        }
    }

    /**
     * Sort files by modification time.
     *
     * @param array<string> $files List of file paths
     * @param string $order 'oldest' or 'newest'
     * @return array<string> Sorted file paths
     */
    private function sortFilesByTime(array $files, string $order = 'oldest'): array
    {
        usort($files, function ($a, $b) use ($order) {
            $timeA = filemtime($a);
            $timeB = filemtime($b);

            // If timestamps are identical, sort by filename (alphabetical)
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

    /**
     * Apply limit to files array.
     *
     * @param array<string> $files List of file paths
     * @param int|null $limit Maximum number of files to return
     * @return array<string> Limited file paths
     */
    private function applyLimit(array $files, ?int $limit): array
    {
        // If limit is 0, return no files
        if ($limit === 0) {
            return [];
        }

        if ($limit === null || $limit <= 0) {
            return $files;
        }

        return array_slice($files, 0, $limit);
    }

    // ==================== Unique Tasks ====================

    /**
     * Save a pending task to storage.
     */
    public function savePending(TaskRecord $task): void
    {
        $filePath = $this->pendingPath . '/' . $task->id . '.json';
        file_put_contents($filePath, json_encode($task->toArray(), JSON_PRETTY_PRINT));

        // Small pause to ensure different timestamps for testing
        usleep(1000);
    }

    /**
     * Find all pending tasks that are ready to run.
     *
     * @param int|null $limit Maximum number of tasks to return
     * @param string $order 'oldest' or 'newest'
     */
    public function findPending(?int $limit = null, string $order = 'oldest'): TaskRecordCollection
    {
        $results = new TaskRecordCollection();
        $files = glob($this->pendingPath . '/*.json');

        // Sort files by modification time
        $files = $this->sortFilesByTime($files, $order);

        // Apply limit
        $files = $this->applyLimit($files, $limit);

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $data = json_decode($content, true);

            if ($data === null) {
                continue;
            }

            // ✅ Hydratation automatique !
            $task = TaskRecord::from($data);

            if ($this->shouldRunTaskNow($task)) {
                $results->add($task);
            }
        }

        return $results;
    }

    /**
     * Delete a pending task from storage.
     */
    public function deletePending(string $id): void
    {
        $filePath = $this->pendingPath . '/' . $id . '.json';

        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    /**
     * Move a completed task to the completed directory.
     */
    public function moveToCompleted(TaskRecord $task, bool $success): void
    {
        $date = date('Y-m-d');
        $completedDir = $this->completedPath . '/' . $date;

        if (!is_dir($completedDir)) {
            mkdir($completedDir, 0755, true);
        }

        $source = $this->pendingPath . '/' . $task->id . '.json';
        $target = $completedDir . '/' . $task->id . '.json';

        if (file_exists($source)) {
            rename($source, $target);
        }
    }

    // ==================== Recurring Tasks ====================

    /**
     * Save a recurring task to storage.
     */
    public function saveRecurring(RecurringTaskRecord $task): void
    {
        $filePath = $this->recurringPath . '/' . $task->signature . '.json';
        file_put_contents($filePath, json_encode($task->toArray(), JSON_PRETTY_PRINT));

        // Small pause to ensure different timestamps for testing
        usleep(1000);
    }

    /**
     * Find all recurring tasks that are ready to run.
     *
     * @param int|null $limit Maximum number of tasks to return
     * @param string $order 'oldest' or 'newest'
     */
    public function findRecurring(?int $limit = null, string $order = 'oldest'): RecurringTaskRecordCollection
    {
        $results = new RecurringTaskRecordCollection();
        $files = glob($this->recurringPath . '/*.json');

        // Sort files by modification time
        $files = $this->sortFilesByTime($files, $order);

        // Apply limit
        $files = $this->applyLimit($files, $limit);

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $data = json_decode($content, true);

            if ($data === null) {
                continue;
            }

            // ✅ Hydratation automatique !
            $task = RecurringTaskRecord::from($data);

            if ($this->shouldRunRecurringNow($task)) {
                $results->add($task);
            }
        }

        return $results;
    }

    /**
     * Get a specific recurring task by signature.
     */
    public function getRecurring(string $signature): ?RecurringTaskRecord
    {
        $filePath = $this->recurringPath . '/' . $signature . '.json';

        if (!file_exists($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);
        $data = json_decode($content, true);

        if ($data === null) {
            return null;
        }

        // ✅ Hydratation automatique !
        return RecurringTaskRecord::from($data);
    }

    /**
     * Update a recurring task after execution.
     */
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

    /**
     * Delete a recurring task from storage.
     */
    public function deleteRecurring(string $signature): void
    {
        $filePath = $this->recurringPath . '/' . $signature . '.json';

        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    /**
     * Get all recurring tasks (without filtering by run time).
     */
    public function getAllRecurring(): RecurringTaskRecordCollection
    {
        $results = new RecurringTaskRecordCollection();
        $files = glob($this->recurringPath . '/*.json');

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $data = json_decode($content, true);

            if ($data === null) {
                continue;
            }

            // ✅ Hydratation automatique !
            $results->add(RecurringTaskRecord::from($data));
        }

        return $results;
    }

    /**
     * Get all pending tasks (without filtering by run time).
     */
    public function getAllPending(): TaskRecordCollection
    {
        $results = new TaskRecordCollection();
        $files = glob($this->pendingPath . '/*.json');

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $data = json_decode($content, true);

            if ($data === null) {
                continue;
            }

            // ✅ Hydratation automatique !
            $results->add(TaskRecord::from($data));
        }

        return $results;
    }

    // ==================== Helpers ====================

    /**
     * Check if a pending task should run now.
     */
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

        if ($task->status !== TaskStatus::PENDING) {
            return false;
        }

        if ($task->attempts >= $task->maxAttempts) {
            return false;
        }

        return true;
    }

    /**
     * Check if a recurring task should run now.
     */
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
