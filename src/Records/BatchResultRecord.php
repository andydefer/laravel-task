<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\Task\Collections\RecurringResultCollection;
use AndyDefer\Task\Collections\RecurringTaskErrorCollection;
use AndyDefer\Task\Collections\TaskErrorCollection;
use AndyDefer\Task\Collections\UniqueResultCollection;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;

final class BatchResultRecord extends AbstractRecord
{
    public function __construct(
        public readonly Iso8601DateTimeVO $started_at,
        public readonly CounterVO $unique_success,
        public readonly CounterVO $unique_failed,
        public readonly CounterVO $recurring_success,
        public readonly CounterVO $recurring_failed,
        public readonly UniqueResultCollection $unique_results,
        public readonly RecurringResultCollection $recurring_results,
        public readonly TaskErrorCollection $unique_errors,
        public readonly RecurringTaskErrorCollection $recurring_errors,
    ) {}
}
