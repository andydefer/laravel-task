<?php

// src/Records/ProcessInfoRecord.php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\Records\AbstractRecord;

final class ProcessInfoRecord extends AbstractRecord
{
    public function __construct(
        public readonly int $pid,
        public readonly string $taskIdentifier,
        public readonly int $startedAt,
    ) {}
}
