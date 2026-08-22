<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\DomainStructures\Utils\StrictAssociative;
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
        /**
         * Optional breakdown of success counts by task type.
         * Example: {'unique': 100, 'recurring': 100}
         *
         * @var StrictAssociative<string, int>|null
         */
        public readonly ?StrictAssociative $type_counts = null,
        /**
         * Optional breakdown of failure counts by task type.
         * Example: {'unique': 2, 'recurring': 1}
         *
         * @var StrictAssociative<string, int>|null
         */
        public readonly ?StrictAssociative $failed_counts = null,
        /**
         * Whether this result contains unique tasks.
         */
        public readonly bool $has_unique = false,
        /**
         * Whether this result contains recurring tasks.
         */
        public readonly bool $has_recurring = false,
    ) {}
}
