<?php

// src/Services/TaskStorage.php

declare(strict_types=1);

namespace AndyDefer\Task\Services;

use AndyDefer\Logger\Collections\MixedPayloadCollection;
use AndyDefer\Records\Collections\TypedCollection;
use AndyDefer\Task\Enums\TaskMode;
use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Records\TaskRecord;

class TaskStorage
{
    private string $pendingPath;

    private string $recurringPath;

    private string $completedPath;

    public function __construct(string $storagePath)
    {
        $this->pendingPath = $storagePath . '/pending';
        $this->recurringPath = $storagePath . '/recurring';
        $this->completedPath = $storagePath . '/completed';

        $this->ensureDirectories();
    }

    private function ensureDirectories(): void
    {
        foreach ([$this->pendingPath, $this->recurringPath, $this->completedPath] as $path) {
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }
        }
    }

    // ==================== Unique Tasks ====================

    public function savePending(TaskRecord $task): void
    {
        $filePath = $this->pendingPath . '/' . $task->id . '.json';
        file_put_contents($filePath, json_encode($this->taskToArray($task), JSON_PRETTY_PRINT));
    }

    public function findPending(): TypedCollection
    {
        $results = new TypedCollection(TaskRecord::class);
        $files = glob($this->pendingPath . '/*.json');

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $data = json_decode($content, true);

            if ($data && $this->shouldRunTaskNow($data)) {
                $results->add($this->arrayToTask($data));
            }
        }

        return $results;
    }

    public function deletePending(string $id): void
    {
        $filePath = $this->pendingPath . '/' . $id . '.json';
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

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

    public function saveRecurring(RecurringTaskRecord $task): void
    {
        $filePath = $this->recurringPath . '/' . $task->signature . '.json';
        file_put_contents($filePath, json_encode($this->recurringTaskToArray($task), JSON_PRETTY_PRINT));
    }

    public function findRecurring(): TypedCollection
    {
        $results = new TypedCollection(RecurringTaskRecord::class);
        $files = glob($this->recurringPath . '/*.json');

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $data = json_decode($content, true);

            if ($data && $this->shouldRunRecurringNow($data)) {
                $results->add($this->arrayToRecurringTask($data));
            }
        }

        return $results;
    }

    public function getRecurring(string $signature): ?RecurringTaskRecord
    {
        $filePath = $this->recurringPath . '/' . $signature . '.json';

        if (!file_exists($filePath)) {
            return null;
        }

        $content = file_get_contents($filePath);
        $data = json_decode($content, true);

        return $data ? $this->arrayToRecurringTask($data) : null;
    }

    public function updateRecurringAfterRun(RecurringTaskRecord $task, bool $success, ?string $error = null): void
    {
        $now = date('c');
        $nextRunAt = date('c', strtotime($now) + $task->delaySeconds);

        $updated = new RecurringTaskRecord(
            signature: $task->signature,
            class: $task->class,
            payload: $task->payload,
            mode: $task->mode,
            startAt: $task->startAt,
            endAt: $task->endAt,
            delaySeconds: $task->delaySeconds,
            lastRunAt: $now,
            nextRunAt: $nextRunAt,
            successCount: $success ? $task->successCount + 1 : $task->successCount,
            failureCount: $success ? $task->failureCount : $task->failureCount + 1,
            lastError: $error,
        );

        $this->saveRecurring($updated);
    }

    public function deleteRecurring(string $signature): void
    {
        $filePath = $this->recurringPath . '/' . $signature . '.json';
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    // ==================== Helpers ====================

    private function shouldRunTaskNow(array $data): bool
    {
        $now = time();
        $startAt = strtotime($data['start_at']);
        $endAt = isset($data['end_at']) ? strtotime($data['end_at']) : PHP_INT_MAX;

        if ($now < $startAt) {
            return false;
        }

        if ($now > $endAt) {
            return false;
        }

        if ($data['status'] !== 'pending') {
            return false;
        }

        if ($data['attempts'] >= $data['max_attempts']) {
            return false;
        }

        return true;
    }

    private function shouldRunRecurringNow(array $data): bool
    {
        $now = time();
        $startAt = strtotime($data['start_at']);
        $endAt = isset($data['end_at']) ? strtotime($data['end_at']) : PHP_INT_MAX;
        $nextRunAt = strtotime($data['next_run_at']);

        if ($now < $startAt) {
            return false;
        }

        if ($now > $endAt) {
            return false;
        }

        if ($now < $nextRunAt) {
            return false;
        }

        return true;
    }

    private function arrayToTask(array $data): TaskRecord
    {
        $payloadCollection = new MixedPayloadCollection();
        foreach ($data['payload']['payload'] as $item) {
            $payloadCollection->add($item);
        }

        $payload = new TaskPayloadRecord(
            type: $data['payload']['type'],
            payload: $payloadCollection,
        );

        return new TaskRecord(
            id: $data['id'],
            signature: $data['signature'],
            class: $data['class'],
            payload: $payload,
            mode: TaskMode::from($data['mode']),
            status: TaskStatus::from($data['status']),
            createdAt: $data['created_at'],
            startAt: $data['start_at'],
            endAt: $data['end_at'] ?? null,
            delaySeconds: $data['delay_seconds'],
            attempts: $data['attempts'],
            maxAttempts: $data['max_attempts'],
            lastError: $data['last_error'] ?? null,
            enforceExactSchedule: $data['enforce_exact_schedule'] ?? false,
        );
    }

    private function arrayToRecurringTask(array $data): RecurringTaskRecord
    {
        $payloadCollection = new MixedPayloadCollection();
        foreach ($data['payload']['payload'] as $item) {
            $payloadCollection->add($item);
        }

        $payload = new TaskPayloadRecord(
            type: $data['payload']['type'],
            payload: $payloadCollection,
        );

        return new RecurringTaskRecord(
            signature: $data['signature'],
            class: $data['class'],
            payload: $payload,
            mode: TaskMode::from($data['mode']),
            startAt: $data['start_at'],
            endAt: $data['end_at'] ?? null,
            delaySeconds: $data['delay_seconds'],
            lastRunAt: $data['last_run_at'] ?? null,
            nextRunAt: $data['next_run_at'],
            successCount: $data['success_count'],
            failureCount: $data['failure_count'],
            lastError: $data['last_error'] ?? null,
        );
    }

    private function taskToArray(TaskRecord $task): array
    {
        return [
            'id' => $task->id,
            'signature' => $task->signature,
            'class' => $task->class,
            'payload' => [
                'type' => $task->payload->type,
                'payload' => $task->payload->payload->toArray(),
            ],
            'mode' => $task->mode->value,
            'status' => $task->status->value,
            'created_at' => $task->createdAt,
            'start_at' => $task->startAt,
            'end_at' => $task->endAt,
            'delay_seconds' => $task->delaySeconds,
            'attempts' => $task->attempts,
            'max_attempts' => $task->maxAttempts,
            'last_error' => $task->lastError,
            'enforce_exact_schedule' => $task->enforceExactSchedule,
        ];
    }

    private function recurringTaskToArray(RecurringTaskRecord $task): array
    {
        return [
            'signature' => $task->signature,
            'class' => $task->class,
            'payload' => [
                'type' => $task->payload->type,
                'payload' => $task->payload->payload->toArray(),
            ],
            'mode' => $task->mode->value,
            'start_at' => $task->startAt,
            'end_at' => $task->endAt,
            'delay_seconds' => $task->delaySeconds,
            'last_run_at' => $task->lastRunAt,
            'next_run_at' => $task->nextRunAt,
            'success_count' => $task->successCount,
            'failure_count' => $task->failureCount,
            'last_error' => $task->lastError,
        ];
    }
}
