<?php

declare(strict_types=1);

namespace AndyDefer\Task\CircuitBreaker\Exceptions;

use RuntimeException;

final class CircuitOpenException extends RuntimeException
{
    public static function forKey(string $key, int $retryAfterSeconds): self
    {
        return new self(sprintf(
            'Circuit breaker [%s] is open. Retry after %d seconds.',
            $key,
            $retryAfterSeconds,
        ));
    }
}
