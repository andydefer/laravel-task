<?php

declare(strict_types=1);

namespace AndyDefer\Task;

use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Logger;
use AndyDefer\Logger\Records\LogDataRecord;
use AndyDefer\Task\Enums\TaskMode;
use AndyDefer\Task\Records\TaskConfigRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;

/**
 * Abstract base class for all tasks in the system.
 *
 * Provides common functionality for task execution including:
 * - Logging with structured data
 * - Lifecycle hooks (before/after)
 * - Error handling
 *
 * @author Andy Defer
 */
abstract class AbstractTask
{
    protected TaskPayloadRecord $payload;

    protected TaskMode $mode;

    protected string $taskId;

    protected string $signature;

    protected Logger $logger;

    /**
     * Get the configuration for this task.
     */
    abstract public function getConfig(): TaskConfigRecord;

    /**
     * Execute the main business logic of the task.
     */
    abstract protected function process(): void;

    /**
     * Hook called before task execution.
     */
    protected function before(): void {}

    /**
     * Hook called after task execution.
     *
     * @param  bool  $success  Whether the task succeeded
     * @param  string|null  $error  Error message if failed
     */
    protected function after(bool $success, ?string $error = null): void {}

    /**
     * Execute the task with the given mode and payload.
     *
     * @throws \Throwable Re-throws any exception from process()
     */
    public function execute(TaskMode $mode, TaskPayloadRecord $payload): void
    {
        $this->mode = $mode;
        $this->payload = $payload;

        $payloadLog = StrictDataObject::from([
            'event' => 'task_started',
            'task_id' => $this->taskId,
            'signature' => $this->signature,
            'mode' => $mode->value,
        ]);
        $this->logger->info(new LogDataRecord(type: 'task', payload: $payloadLog));

        $this->before();

        try {
            $this->process();
            $this->after(true);

            $payloadLog = StrictDataObject::from([
                'event' => 'task_completed',
                'task_id' => $this->taskId,
                'signature' => $this->signature,
                'status' => 'success',
            ]);
            $this->logger->info(new LogDataRecord(type: 'task', payload: $payloadLog));
        } catch (\Throwable $e) {
            $this->after(false, $e->getMessage());

            $payloadLog = StrictDataObject::from([
                'event' => 'task_failed',
                'task_id' => $this->taskId,
                'signature' => $this->signature,
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);
            $this->logger->error(new LogDataRecord(type: 'task', payload: $payloadLog));

            throw $e;
        }
    }

    /**
     * Log an informational message.
     */
    public function info(string $message): void
    {
        $payload = StrictDataObject::from([
            'event' => 'info',
            'message' => $message,
        ]);
        $this->logger->info(new LogDataRecord(type: 'task_output', payload: $payload));
    }

    /**
     * Log an error message.
     */
    public function error(string $message): void
    {
        $payload = StrictDataObject::from([
            'event' => 'error',
            'message' => $message,
        ]);
        $this->logger->error(new LogDataRecord(type: 'task_output', payload: $payload));
    }

    /**
     * Set the logger instance.
     */
    public function setLogger(Logger $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * Set the task ID.
     */
    public function setTaskId(string $id): self
    {
        $this->taskId = $id;

        return $this;
    }

    /**
     * Set the task signature.
     */
    public function setSignature(string $signature): self
    {
        $this->signature = $signature;

        return $this;
    }
}
