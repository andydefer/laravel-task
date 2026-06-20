<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Services;

use AndyDefer\Task\Records\TaskConfigRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

interface TaskRegistryServiceInterface
{
    /**
     * Registers a new task (unique or recurring).
     *
     * @return string Task ID (for unique tasks) or signature (for recurring tasks)
     *
     * @throws \InvalidArgumentException If task class is invalid
     * @throws \RuntimeException If recurring task already exists
     */
    public function register(
        string $taskClass,
        TaskPayloadRecord $payload,
        ?TaskConfigRecord $override_config = null,
    ): string;

    /**
     * Removes a unique task by its ID.
     *
     * @throws \RuntimeException If the task does not exist
     */
    public function unregisterTask(TaskIdVO $taskId): void;

    /**
     * Removes a recurring task by its signature.
     */
    public function unregisterRecurring(TaskSignatureVO $signature): void;

    /**
     * Removes a task (unique or recurring) automatically.
     * Detects type by identifier format (UUID → unique task, otherwise → recurring).
     *
     * @throws \RuntimeException If the task does not exist or format is invalid
     */
    public function unregister(string $identifier): void;
}
