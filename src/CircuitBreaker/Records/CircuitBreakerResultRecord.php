<?php

declare(strict_types=1);

namespace AndyDefer\Task\CircuitBreaker\Records;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\Task\CircuitBreaker\Enums\CircuitBreakerState;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;

final class CircuitBreakerResultRecord extends AbstractRecord
{
    public function __construct(
        public readonly string $key,
        public readonly CircuitBreakerState $state,
        public readonly CounterVO $failures,
        public readonly CounterVO $successes,
        public readonly ?Iso8601DateTimeVO $opened_at = null,
        public readonly ?Iso8601DateTimeVO $last_failure_at = null,
    ) {}
}
