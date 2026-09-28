<?php

declare(strict_types=1);

namespace AndyDefer\Task\CircuitBreaker\Enums;

enum CircuitBreakerState: string
{
    case CLOSED = 'closed';
    case OPEN = 'open';
    case HALF_OPEN = 'half_open';

    public function allowsExecution(): bool
    {
        return $this !== self::OPEN;
    }
}
