<?php

declare(strict_types=1);

namespace AndyDefer\Task\CircuitBreaker\ValueObjects;

use AndyDefer\DomainStructures\Abstracts\AbstractValueObject;
use InvalidArgumentException;

final class CircuitBreakerKeyVO extends AbstractValueObject
{
    private readonly string $value;

    public function __construct(string $value)
    {
        $normalized = trim($value);

        if ($normalized === '') {
            throw new InvalidArgumentException('Circuit breaker key cannot be empty.');
        }

        if (strlen($normalized) > 128) {
            throw new InvalidArgumentException('Circuit breaker key is too long (max 128 chars).');
        }

        $this->value = $normalized;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
