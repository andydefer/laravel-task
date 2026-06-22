<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\Task\Collections\TaskErrorRecordCollection;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;

final class ProcessResultRecord extends AbstractRecord
{
    public function __construct(
        public readonly Iso8601DateTimeVO $started_at,
        public readonly Iso8601DateTimeVO $ended_at,
        public readonly CounterVO $success,
        public readonly CounterVO $failed,
        public readonly CounterVO $finished,
        public readonly TaskErrorRecordCollection $errors,
    ) {}
}
