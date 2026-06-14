<?php

declare(strict_types=1);

namespace AndyDefer\Task;

use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Logger\Records\LogDataRecord;
use AndyDefer\Task\Records\TaskConfigRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;

/**
 * Abstract base class for all tasks in the system.
 *
 * Provides common functionality for task execution including:
 * - Logging with structured data
 * - Lifecycle hooks (before/after)
 * - Error handling
 */
abstract class AbstractTask
{
    protected TaskPayloadRecord $payload;
    protected string $taskId;
    protected string $signature;
    protected LoggerInterface $logger;

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
     * @param bool $success Whether the task succeeded
     * @param string|null $error Error message if failed
     */
    protected function after(bool $success, ?string $error = null): void {}

    /**
     * Execute the task with the given payload.
     *
     * @throws \Throwable Re-throws any exception from process()
     */
    public function execute(TaskPayloadRecord $payload): void
    {
        $this->payload = $payload;

        $this->logger->info(new LogDataRecord(
            type: 'task',
            payload: new StrictDataObject([
                'event' => 'task_started',
                'task_id' => $this->taskId,
                'signature' => $this->signature,
            ])
        ));

        $this->before();

        try {
            $this->process();
            $this->after(true);

            $this->logger->info(new LogDataRecord(
                type: 'task',
                payload: new StrictDataObject([
                    'event' => 'task_completed',
                    'task_id' => $this->taskId,
                    'signature' => $this->signature,
                    'status' => 'success',
                ])
            ));
        } catch (\Throwable $e) {
            $this->after(false, $e->getMessage());

            $this->logger->error(new LogDataRecord(
                type: 'task',
                payload: new StrictDataObject([
                    'event' => 'task_failed',
                    'task_id' => $this->taskId,
                    'signature' => $this->signature,
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ])
            ));

            throw $e;
        }
    }

    /**
     * Log an informational message.
     */
    public function info(string $message): void
    {
        $this->logger->info(new LogDataRecord(
            type: 'task_output',
            payload: new StrictDataObject([
                'event' => 'info',
                'message' => $message,
            ])
        ));
    }

    /**
     * Log an error message.
     */
    public function error(string $message): void
    {
        $this->logger->error(new LogDataRecord(
            type: 'task_output',
            payload: new StrictDataObject([
                'event' => 'error',
                'message' => $message,
            ])
        ));
    }

    /**
     * Set the logger instance.
     *
     * @return $this
     */
    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * Set the task ID.
     *
     * @return $this
     */
    public function setTaskId(string $id): self
    {
        $this->taskId = $id;
        return $this;
    }

    /**
     * Set the task signature.
     *
     * @return $this
     */
    public function setSignature(string $signature): self
    {
        $this->signature = $signature;
        return $this;
    }
}
