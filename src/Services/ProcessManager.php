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

class ProcessManager
{
    private int $startTime;
    private int $maxDuration;
    private ProcessInfoCollection $runningProcesses;
    private bool $shuttingDown = false;

    public function __construct(
        private readonly TaskRunner $runner,
        private readonly TaskStorage $storage,
        private readonly Logger $logger,
        private readonly TaskValidator $validator,
    ) {
        $this->runningProcesses = new ProcessInfoCollection();
    }

    public function run(int $maxDurationSeconds, bool $dryRun = false): void
    {
        $this->startTime = time();
        $this->maxDuration = $maxDurationSeconds;

        $context = new MixedPayloadCollection();
        $context->add($maxDurationSeconds, $dryRun);
        $this->logPollerEvent('poller_started', $context);

        if ($dryRun) {
            $this->listTasks();
            return;
        }

        pcntl_signal(SIGTERM, [$this, 'shutdown']);
        pcntl_signal(SIGINT, [$this, 'shutdown']);

        while (!$this->shouldStop()) {
            pcntl_signal_dispatch();
            $this->runningProcesses = $this->runningProcesses->removeCompleted();

            $allTasks = $this->getAllPendingTasks();

            foreach ($allTasks as $task) {
                if (!$this->canStartNewTask()) {
                    $context = new MixedPayloadCollection();
                    $context->add('cannot_start_new_task', time() - $this->startTime);
                    $this->logPollerEvent('time_limit_reached', $context);
                    break;
                }

                $this->forkAndRun($task);
            }

            sleep(1);
        }

        $this->waitForRunningTasks();

        $context = new MixedPayloadCollection();
        $context->add(time() - $this->startTime);
        $this->logPollerEvent('poller_finished', $context);
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

    private function forkAndRun(object $task): void
    {
        $pid = pcntl_fork();
        $taskId = TaskIdentifier::fromTask($task);

        if ($pid === -1) {
            $context = new MixedPayloadCollection();
            $context->add($taskId->toString());
            $this->logPollerEvent('fork_failed', $context);
            return;
        }

        if ($pid === 0) {
            try {
                if ($task instanceof TaskRecord) {
                    $this->runner->runTask($task);
                } else {
                    $this->runner->runRecurringTask($task);
                }
            } catch (\Throwable $e) {
                $context = new MixedPayloadCollection();
                $context->add($e->getMessage());
                $this->logPollerEvent('child_process_error', $context);
            }
            exit(0);
        }

        $processInfo = new ProcessInfoRecord(
            pid: $pid,
            taskIdentifier: $taskId->toString(),
            startedAt: time(),
        );
        $this->runningProcesses->add($processInfo);
    }

    private function waitForRunningTasks(): void
    {
        $timeout = 30;
        $start = time();

        $context = new MixedPayloadCollection();
        $context->add($this->runningProcesses->count());
        $this->logPollerEvent('waiting_for_tasks', $context);

        while ($this->runningProcesses->isNotEmpty() && (time() - $start) < $timeout) {
            $this->runningProcesses = $this->runningProcesses->removeCompleted();
            sleep(1);
        }

        $this->runningProcesses->forceKillAll();
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
}
