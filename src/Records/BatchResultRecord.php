<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\Task\Collections\RecurringResultCollection;
use AndyDefer\Task\Collections\TaskErrorCollection;
use AndyDefer\Task\Collections\UniqueResultCollection;
use AndyDefer\Task\ValueObjects\Iso8601DateTime;

/**
 * Batch processing result record.
 *
 * Pure data container with no business logic.
 */
final class BatchResultRecord extends AbstractRecord
{
    public function __construct(
        public readonly Iso8601DateTime $startedAt,
        public readonly int $uniqueSuccess,
        public readonly int $uniqueFailed,
        public readonly int $recurringSuccess,
        public readonly int $recurringFailed,
        public readonly UniqueResultCollection $uniqueResults,
        public readonly RecurringResultCollection $recurringResults,
        public readonly TaskErrorCollection $errors,
    ) {}
}
