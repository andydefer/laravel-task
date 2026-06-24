<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\Task\Collections\TaskErrorRecordCollection;
use AndyDefer\Task\ValueObjects\CounterVO;

final class BatchResultRecord extends AbstractRecord
{
    public function __construct(
        public readonly CounterVO $success,
        public readonly CounterVO $failed,
        public readonly TaskErrorRecordCollection $errors,
    ) {}
}
