<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;

/**
 * Record representing the history of a single execution cycle.
 *
 * Stores success, failure, and error counts for both unique and recurring tasks.
 */
final class CycleHistoryRecord extends AbstractRecord
{
    public function __construct(
        public readonly int $success,
        public readonly int $failed,
        public readonly int $errors,
        public readonly int $unique_success,
        public readonly int $unique_failed,
        public readonly int $recurring_success,
        public readonly int $recurring_failed,
    ) {}
}
