<?php

// src/Records/GracePeriodRecord.php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;

final class GracePeriodRecord extends AbstractRecord
{
    public function __construct(
        public readonly string $taskId,
        public readonly string $signature,
        public readonly int $originalEndAt,
        public readonly int $executedAt,
        public readonly int $delaySeconds,
    ) {}
}
