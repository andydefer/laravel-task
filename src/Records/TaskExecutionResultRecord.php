<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\Task\Collections\TaskErrorRecordCollection;
use AndyDefer\Task\Enums\TaskType;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\MillisecondsVO;

/**
 * Record representing the result of a task execution.
 *
 * This record stores the outcome of a single task execution, including
 * success/failure counts, duration, errors, and the type of tasks processed.
 */
final class TaskExecutionResultRecord extends AbstractRecord
{
    public function __construct(
        public readonly string $id,
        public readonly Iso8601DateTimeVO $started_at,
        public readonly Iso8601DateTimeVO $ended_at,
        public readonly MillisecondsVO $duration_ms,
        public readonly CounterVO $success,
        public readonly CounterVO $failed,
        public readonly CounterVO $total,
        public readonly TaskErrorRecordCollection $errors,
        public readonly bool $has_failures,
        public readonly TaskType $type,
    ) {}
}
