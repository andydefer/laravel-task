<?php

// src/Services/ProcessManager.php

declare(strict_types=1);

namespace AndyDefer\Task\Services;

use AndyDefer\Logger\Collections\MixedPayloadCollection;
use AndyDefer\Logger\Logger;
use AndyDefer\Logger\Records\LogDataRecord;
use AndyDefer\Task\Collections\ProcessInfoCollection;
use AndyDefer\Task\Collections\TaskCollection;
use AndyDefer\Task\Records\ProcessInfoRecord;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\ValueObjects\TaskIdentifier;

final class ProcessManager
{
    private $lockHandle = null;
    private string $lockPath;
    private int $startTime;
    private int $maxDuration;
    private bool $shuttingDown = false;
    private bool $useSequentialMode;
    private string $storagePath;

    public function __construct(
        private readonly TaskRunner $runner,
        private readonly TaskStorage $storage,
        private readonly Logger $logger,
        private readonly TaskValidator $validator,
        ?string $lockPath = null,
        bool $useSequentialMode = true,
    ) {
        $this->storagePath = config('task.storage_path', storage_path('tasks'));
        $this->lockPath = $lockPath ?? $this->storagePath . '/poller.lock';
        $this->useSequentialMode = $useSequentialMode;
        $this->ensureLockDirectory();
    }

    private function ensureLockDirectory(): void
    {
        $dir = dirname($this->lockPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    /**
     * Acquire file lock (non-blocking) to prevent multiple pollers
     */
    private function acquireLock(): bool
    {
        $this->lockHandle = fopen($this->lockPath, 'c');

        if ($this->lockHandle === false) {
            $this->logPollerEvent('lock_open_failed', new MixedPayloadCollection());
            return false;
        }

        // LOCK_EX | LOCK_NB = Exclusive lock without waiting
        if (!flock($this->lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($this->lockHandle);
            $this->lockHandle = null;
            $this->logPollerEvent('lock_busy', new MixedPayloadCollection());
            return false;
        }

        $this->logPollerEvent('lock_acquired', new MixedPayloadCollection());
        return true;
    }

    private function releaseLock(): void
    {
        if ($this->lockHandle !== null) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;

            // 🔥 SUPPRIMER LE FICHIER PHYSIQUEMENT
            if (file_exists($this->lockPath)) {
                unlink($this->lockPath);
            }

            $this->logPollerEvent('lock_released', new MixedPayloadCollection());
        }
    }
    public function run(int $maxDurationSeconds, bool $dryRun = false): void
    {
        // 🔒 Only one poller at a time
        if (!$this->acquireLock()) {
            $this->logPollerEvent('poller_already_running', new MixedPayloadCollection());
            return;
        }

        try {
            $this->startTime = time();
            $this->maxDuration = $maxDurationSeconds;

            $context = new MixedPayloadCollection();
            $context->add($maxDurationSeconds, $dryRun, $this->useSequentialMode);
            $this->logPollerEvent('poller_started', $context);

            if ($dryRun) {
                $this->listTasks();
                return;
            }

            pcntl_signal(SIGTERM, [$this, 'shutdown']);
            pcntl_signal(SIGINT, [$this, 'shutdown']);

            if ($this->useSequentialMode) {
                $this->runSequentially();
            } else {
                $this->runWithForks();
            }

            $context = new MixedPayloadCollection();
            $context->add(time() - $this->startTime);
            $this->logPollerEvent('poller_finished', $context);
        } finally {
            $this->releaseLock();
        }
    }

    private function runSequentially(): void
    {
        while (!$this->shouldStop()) {
            pcntl_signal_dispatch();

            $allTasks = $this->getAllPendingTasks();

            foreach ($allTasks as $task) {
                if (!$this->canStartNewTask()) {
                    $context = new MixedPayloadCollection();
                    $context->add('cannot_start_new_task', time() - $this->startTime);
                    $this->logPollerEvent('time_limit_reached', $context);
                    break;
                }

                $this->executeTaskSequentially($task);
            }

            if ($this->shouldStop()) {
                break;
            }

            sleep(1);
        }
    }

    private function runWithForks(): void
    {
        $runningProcesses = new ProcessInfoCollection();

        while (!$this->shouldStop()) {
            pcntl_signal_dispatch();

            // Clean up finished child processes
            $runningProcesses = $this->cleanupChildProcesses($runningProcesses);

            $allTasks = $this->getAllPendingTasks();

            foreach ($allTasks as $task) {
                if (!$this->canStartNewTask()) {
                    $context = new MixedPayloadCollection();
                    $context->add('cannot_start_new_task', time() - $this->startTime);
                    $this->logPollerEvent('time_limit_reached', $context);
                    break;
                }

                $runningProcesses = $this->forkAndRun($task, $runningProcesses);
            }

            if ($this->shouldStop()) {
                break;
            }

            sleep(1);
        }

        $this->waitForRunningTasks($runningProcesses);
    }

    private function executeTaskSequentially(object $task): void
    {
        $taskId = TaskIdentifier::fromTask($task);

        $context = new MixedPayloadCollection();
        $context->add('sequential_execution_started', $taskId->toString());
        $this->logPollerEvent('sequential_task_start', $context);

        $startTime = microtime(true);

        try {
            if ($task instanceof TaskRecord) {
                $this->runner->runTask($task);
            } else {
                $this->runner->runRecurringTask($task);
            }

            $duration = (microtime(true) - $startTime) * 1000;
            $context = new MixedPayloadCollection();
            $context->add('sequential_execution_completed', $taskId->toString(), round($duration, 2));
            $this->logPollerEvent('sequential_task_end', $context);
        } catch (\Throwable $e) {
            $duration = (microtime(true) - $startTime) * 1000;
            $context = new MixedPayloadCollection();
            $context->add('sequential_execution_error', $taskId->toString(), $e->getMessage(), round($duration, 2));
            $this->logPollerEvent('sequential_task_error', $context);
        }
    }

    private function forkAndRun(object $task, ProcessInfoCollection $runningProcesses): ProcessInfoCollection
    {
        $pid = pcntl_fork();
        $taskId = TaskIdentifier::fromTask($task);

        if ($pid === -1) {
            $context = new MixedPayloadCollection();
            $context->add('fork_failed', $taskId->toString());
            $this->logPollerEvent('fork_failed', $context);
            return $runningProcesses;
        }

        if ($pid === 0) {
            // Child process
            try {
                if ($task instanceof TaskRecord) {
                    $this->runner->runTask($task);
                } else {
                    $this->runner->runRecurringTask($task);
                }
            } catch (\Throwable $e) {
                $context = new MixedPayloadCollection();
                $context->add('child_process_error', $taskId->toString(), $e->getMessage());
                $this->logPollerEvent('child_process_error', $context);
            }
            exit(0);
        }

        // Parent process
        $processInfo = new ProcessInfoRecord(
            pid: $pid,
            taskIdentifier: $taskId->toString(),
            startedAt: time(),
        );
        $runningProcesses->add($processInfo);

        return $runningProcesses;
    }

    private function cleanupChildProcesses(ProcessInfoCollection $runningProcesses): ProcessInfoCollection
    {
        $remaining = new ProcessInfoCollection();

        foreach ($runningProcesses as $process) {
            $status = null;
            $res = pcntl_waitpid($process->pid, $status, WNOHANG);

            if ($res !== $process->pid) {
                $remaining->add($process);
            }
        }

        return $remaining;
    }

    private function waitForRunningTasks(ProcessInfoCollection $runningProcesses): void
    {
        $timeout = 30;
        $start = time();

        $context = new MixedPayloadCollection();
        $context->add($runningProcesses->count());
        $this->logPollerEvent('waiting_for_tasks', $context);

        $currentProcesses = $runningProcesses;
        while ($currentProcesses->isNotEmpty() && (time() - $start) < $timeout) {
            $currentProcesses = $this->cleanupChildProcesses($currentProcesses);
            sleep(1);
        }

        foreach ($currentProcesses as $process) {
            $context = new MixedPayloadCollection();
            $context->add($process->pid, $process->taskIdentifier);
            $this->logPollerEvent('force_killing_task', $context);
            posix_kill($process->pid, SIGKILL);
        }
    }

    private function getAllPendingTasks(): TaskCollection
    {
        $pendingTasks = $this->storage->findPending();
        $recurringTasks = $this->storage->findRecurring();

        $collection = new TaskCollection();

        foreach ($pendingTasks as $task) {
            $collection->add($task);
        }

        foreach ($recurringTasks as $task) {
            $collection->add($task);
        }

        return $collection;
    }

    private function canStartNewTask(): bool
    {
        if ($this->shuttingDown) {
            return false;
        }
        return (time() - $this->startTime) < $this->maxDuration;
    }

    private function shouldStop(): bool
    {
        return $this->shuttingDown || (time() - $this->startTime) >= $this->maxDuration;
    }

    private function listTasks(): void
    {
        $pending = $this->storage->findPending();
        $recurring = $this->storage->findRecurring();

        $context = new MixedPayloadCollection();
        $context->add($pending->count());
        $this->logPollerEvent('dry_run_pending_tasks', $context);

        foreach ($pending as $task) {
            $context = new MixedPayloadCollection();
            $context->add('pending', $task->signature, $task->id, "{$task->attempts}/{$task->maxAttempts}");
            $this->logPollerEvent('dry_run_task', $context);
        }

        $context = new MixedPayloadCollection();
        $context->add($recurring->count());
        $this->logPollerEvent('dry_run_recurring_tasks', $context);

        foreach ($recurring as $task) {
            $context = new MixedPayloadCollection();
            $context->add('recurring', $task->signature, $task->nextRunAt, $task->successCount, $task->failureCount);
            $this->logPollerEvent('dry_run_task', $context);
        }
    }

    public function shutdown(): void
    {
        $this->logPollerEvent('shutdown_signal_received', new MixedPayloadCollection());
        $this->shuttingDown = true;
    }

    private function logPollerEvent(string $event, MixedPayloadCollection $context): void
    {
        $payload = new MixedPayloadCollection();
        $payload->add($event);
        foreach ($context as $item) {
            $payload->add($item);
        }

        $this->logger->info(new LogDataRecord(
            type: 'poller',
            payload: $payload,
        ));
    }

    /**
     * Check if lock is acquired (for testing)
     */
    public function isLockAcquired(): bool
    {
        return $this->lockHandle !== null;
    }

    /**
     * Get lock path (for testing)
     */
    public function getLockPath(): string
    {
        return $this->lockPath;
    }
}
