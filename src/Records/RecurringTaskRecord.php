<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

/**
 * Recurring task DTO.
 *
 * PURE DATA TRANSFER OBJECT - NO LOGIC.
 */
final class RecurringTaskRecord extends AbstractRecord
{
    public function __construct(
        public readonly TaskSignatureVO $signature,
        public readonly string $class,
        public readonly TaskPayloadRecord $payload,
        public readonly ?Iso8601DateTimeVO $start_at = null,
        public readonly ?Iso8601DateTimeVO $end_at = null,
        public readonly CounterVO $delay_seconds = new CounterVO(300),
        public readonly ?Iso8601DateTimeVO $last_run_at = null,
        public readonly ?Iso8601DateTimeVO $next_run_at = null,
        public readonly CounterVO $success_count = new CounterVO(0),
        public readonly CounterVO $failure_count = new CounterVO(0),
        public readonly ?string $last_error = null,
    ) {}
}
