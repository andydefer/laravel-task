<?php

// src/Records/RecurringTaskRecord.php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;

final class RecurringTaskRecord extends AbstractRecord
{
    public function __construct(
        public readonly string $signature,
        public readonly string $class,
        public readonly TaskPayloadRecord $payload,
        public readonly string $startAt,
        public readonly ?string $endAt,
        public readonly int $delaySeconds,
        public readonly ?string $lastRunAt,
        public readonly string $nextRunAt,
        public readonly int $successCount,
        public readonly int $failureCount,
        public readonly ?string $lastError = null,
    ) {}
}
