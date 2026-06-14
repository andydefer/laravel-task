<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\Task\Enums\ErrorType;
use AndyDefer\Task\ValueObjects\TaskIdVO;

/**
 * Error information for a failed unique task.
 */
final class TaskErrorRecord extends AbstractRecord
{
    public function __construct(
        public readonly TaskIdVO $task_id,
        public readonly ErrorType $error_type,
        public readonly ?string $details = null,
    ) {}
}
