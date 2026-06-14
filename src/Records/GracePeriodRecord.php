<?php

// src/Records/GracePeriodRecord.php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use AndyDefer\Task\ValueObjects\UnixTimestampVO;

final class GracePeriodRecord extends AbstractRecord
{
    public function __construct(
        public readonly TaskIdVO $task_id,
        public readonly TaskSignatureVO $signature,
        public readonly UnixTimestampVO $original_end_at,
        public readonly UnixTimestampVO $executed_at,
        public readonly CounterVO $delay_seconds,
    ) {}
}
