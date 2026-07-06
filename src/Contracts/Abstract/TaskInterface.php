<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Abstract;

use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use Throwable;

/**
 * Interface for all task types in the task execution system.
 *
 * Defines the contract for task execution with logging capabilities
 * and lifecycle management hooks.
 */
interface TaskInterface
{
    /**
     * Executes the task with the given payload.
     *
     * This is the main entry point for task execution. It orchestrates
     * the complete workflow including validation, processing, and logging.
     *
     * @param  StrictDataObject  $payload  The input data for the task
     *
     * @throws Throwable When the task execution fails
     */
    public function execute(StrictDataObject $payload): void;

    /**
     * Logs an informational message from the task.
     *
     * @param  DescriptionVO  $message  The informational message to log
     */
    public function info(DescriptionVO $message): void;

    /**
     * Logs an error message from the task.
     *
     * @param  DescriptionVO  $message  The error message to log
     */
    public function error(DescriptionVO $message): void;
}
