<?php

declare(strict_types=1);

namespace AndyDefer\Task\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

final class UniqueTaskRecord extends AbstractRecord
{
    public function __construct(
        public readonly ?TaskIdVO $id = null,
        public readonly ?TaskSignatureVO $alias = null,
        public readonly ?string $fqcn = null,
        public readonly ?StrictDataObject $payload = null,
        public readonly ?Iso8601DateTimeVO $scheduled_at = null,
        public readonly ?int $grace_period_seconds = 86400,
        public readonly ?UniqueTaskStatus $status = UniqueTaskStatus::PENDING,
        public readonly ?CounterVO $attempts = new CounterVO(0),
        public readonly ?CounterVO $max_attempts = new CounterVO(3),
        public readonly ?Iso8601DateTimeVO $finished_at = null,
    ) {}
}
