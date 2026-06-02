<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;

/**
 * Error information for a failed task.
 */
final class TaskErrorRecord extends AbstractRecord
{
    public function __construct(
        public readonly string $taskId,
        public readonly string $error,
    ) {}
}
