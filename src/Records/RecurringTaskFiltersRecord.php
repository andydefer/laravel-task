<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\Task\Enums\RecurringTaskStatus;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

final class RecurringTaskFiltersRecord extends AbstractRecord
{
    public function __construct(
        public readonly ?TaskSignatureVO $alias = null,
        public readonly ?string $fqcn = null,
        public readonly ?RecurringTaskStatus $status = null,
        public readonly ?Iso8601DateTimeVO $start_at_from = null,
        public readonly ?Iso8601DateTimeVO $start_at_to = null,
        public readonly ?Iso8601DateTimeVO $end_at_from = null,
        public readonly ?Iso8601DateTimeVO $end_at_to = null,
        public readonly ?Iso8601DateTimeVO $last_run_at_from = null,
        public readonly ?Iso8601DateTimeVO $last_run_at_to = null,
        public readonly ?Iso8601DateTimeVO $cancelled_at_from = null,
        public readonly ?Iso8601DateTimeVO $cancelled_at_to = null,
        public readonly ?int $failed_attempts = null,
        public readonly ?int $max_failed_attempts = null,
        public readonly ?bool $include_deleted = false,
    ) {}
}
