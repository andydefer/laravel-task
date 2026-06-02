<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services;

use AndyDefer\Logger\Logger;
use AndyDefer\Logger\Records\LogDataRecord;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Contracts\TaskProcessorInterface;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskRecord;

/**
 * Process all pending tasks in a single batch.
 * No polling, no waiting - just process and exit.
 * 
 * @author Andy Defer
 */
class TaskBatch implements TaskProcessorInterface
{
    private int $batchLimit;
    private string $batchOrder;

    public function __construct(
        private readonly TaskStorage $storage,
        private readonly TaskRunner $runner,
        private readonly TaskValidator $validator,
        private readonly Logger $logger,
    ) {
        $this->batchLimit = (int) config('task.batch.limit', 1000);
        $this->batchOrder = config('task.batch.order', 'oldest');
    }

    /**
     * Process all pending and recurring tasks.
     * Returns immediately after processing, no waiting.
     *
     * @param int|null $limit Maximum number of tasks to process (overrides config)
     * @return BatchResult
     */
    public function process(?int $limit = null): BatchResult
    {
        $this->logBatchStart('full', $limit);
        $result = new BatchResult();

        $effectiveLimit = $this->getEffectiveLimit($limit);

        // Process unique tasks with remaining limit
        $remainingLimit = $effectiveLimit;
        if ($remainingLimit !== null && $remainingLimit > 0) {
            $this->processUniqueTasks($result, $remainingLimit);
            $remainingLimit = $this->getRemainingLimit($effectiveLimit, $result->getTotal());
        }

        // Process recurring tasks with remaining limit
        if ($remainingLimit !== null && $remainingLimit > 0) {
            $this->processRecurringTasks($result, $remainingLimit);
        } elseif ($remainingLimit === null) {
            $this->processRecurringTasks($result, null);
        }

        $this->logBatchComplete($result);

        return $result;
    }

    /**
     * Process only unique tasks.
     *
     * @param int|null $limit Maximum number of tasks to process
     * @return BatchResult
     */
    public function processUniqueOnly(?int $limit = null): BatchResult
    {
        $this->logBatchStart('unique_only', $limit);
        $result = new BatchResult();

        $effectiveLimit = $this->getEffectiveLimit($limit);
        $this->processUniqueTasks($result, $effectiveLimit);

        $this->logBatchComplete($result);

        return $result;
    }

    /**
     * Process only recurring tasks.
     *
     * @param int|null $limit Maximum number of tasks to process
     * @return BatchResult
     */
    public function processRecurringOnly(?int $limit = null): BatchResult
    {
        $this->logBatchStart('recurring_only', $limit);
        $result = new BatchResult();

        $effectiveLimit = $this->getEffectiveLimit($limit);
        $this->processRecurringTasks($result, $effectiveLimit);

        $this->logBatchComplete($result);

        return $result;
    }

    /**
     * Get effective limit (0 = no tasks, null = no limit)
     */
    private function getEffectiveLimit(?int $limit): ?int
    {
        if ($limit === 0) {
            return 0;
        }

        if ($limit !== null) {
            return $limit;
        }

        return $this->batchLimit > 0 ? $this->batchLimit : null;
    }

    /**
     * Calculate remaining limit after processing some tasks.
     */
    private function getRemainingLimit(?int $originalLimit, int $processedCount): ?int
    {
        if ($originalLimit === null) {
            return null;
        }

        $remaining = $originalLimit - $processedCount;
        return $remaining > 0 ? $remaining : 0;
    }

    /**
     * Process all pending unique tasks.
     *
     * @param BatchResult $result Result accumulator
     * @param int|null $limit Maximum number of tasks to process (0 = none, null = no limit)
     */
    private function processUniqueTasks(BatchResult $result, ?int $limit = null): void
    {
        // Si limit = 0, ne rien faire
        if ($limit === 0) {
            return;
        }

        $pendingTasks = $this->storage->findPending($limit, $this->batchOrder);

        foreach ($pendingTasks as $task) {
            $success = false;
            $error = null;

            try {
                if ($this->validator->canRunTask($task)) {
                    $success = $this->runner->runTask($task);
                    if (!$success) {
                        $pending = $this->storage->findPending(1);
                        $updatedTask = $pending->first();
                        if ($updatedTask && $updatedTask->id === $task->id) {
                            $error = $updatedTask->lastError ?? 'Task failed without specific error';
                        } else {
                            $error = 'Task execution failed';
                        }
                    }
                } else {
                    $error = 'Task cannot be run (invalid state, expired, or max attempts reached)';
                }
            } catch (\Throwable $e) {
                $success = false;
                $error = $e->getMessage();
            }

            $result->addUniqueTask($task->id, $success, $error);

            $this->logTaskResult('unique', $task->id, $task->signature, $success, $error);
        }
    }

    /**
     * Process all pending recurring tasks.
     *
     * @param BatchResult $result Result accumulator
     * @param int|null $limit Maximum number of tasks to process (0 = none, null = no limit)
     */
    private function processRecurringTasks(BatchResult $result, ?int $limit = null): void
    {
        // Si limit = 0, ne rien faire
        if ($limit === 0) {
            return;
        }

        $recurringTasks = $this->storage->findRecurring($limit, $this->batchOrder);

        foreach ($recurringTasks as $task) {
            $success = false;
            $error = null;

            try {
                if ($this->validator->shouldRunRecurringNow($task)) {
                    $success = $this->runner->runRecurringTask($task);
                    if (!$success) {
                        $error = 'Recurring task execution failed';
                    }
                } else {
                    $error = 'Recurring task not ready to run';
                }
            } catch (\Throwable $e) {
                $success = false;
                $error = $e->getMessage();
            }

            $result->addRecurringTask($task->signature, $success, $error);

            $this->logTaskResult('recurring', $task->signature, $task->signature, $success, $error);
        }
    }

    private function logBatchStart(string $mode, ?int $limit = null): void
    {
        $effectiveLimit = $this->getEffectiveLimit($limit);

        $payload = StrictDataObject::from([
            'event' => 'batch_started',
            'mode' => $mode,
            'limit' => $effectiveLimit ?? 'unlimited',
            'order' => $this->batchOrder,
        ]);

        $this->logger->info(new LogDataRecord(
            type: 'batch',
            payload: $payload,
        ));
    }

    private function logBatchComplete(BatchResult $result): void
    {
        $this->logger->info(new LogDataRecord(
            type: 'batch',
            payload: StrictDataObject::from($result->toArray() + ['event' => 'batch_completed']),
        ));
    }

    private function logTaskResult(string $type, string $id, string $signature, bool $success, ?string $error): void
    {
        $payload = StrictDataObject::from([
            'event' => $success ? 'task_succeeded' : 'task_failed',
            'type' => $type,
            'id' => $id,
            'signature' => $signature,
        ]);

        if ($error !== null) {
            $payload = StrictDataObject::from($payload->toArray() + ['error' => $error]);
        }

        $this->logger->info(new LogDataRecord(
            type: 'batch',
            payload: $payload,
        ));
    }
}
