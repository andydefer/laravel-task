<?php

declare(strict_types=1);

namespace AndyDefer\Task\ValueObjects;

use AndyDefer\DomainStructures\Abstracts\AbstractValueObject;

/**
 * Value Object for Unix timestamp.
 *
 * Represents a point in time as seconds since Unix epoch (1970-01-01).
 *
 * @author Andy Defer
 */
final class UnixTimestampVO extends AbstractValueObject
{
    public function __construct(?int $value = null)
    {
        $this->value = $value ?? time();
    }

    public readonly int $value;

    public function getValue(): int
    {
        return $this->value;
    }

    /**
     * Check if this timestamp is after another.
     */
    public function isAfter(self $other): bool
    {
        return $this->value > $other->value;
    }

    /**
     * Check if this timestamp is before another.
     */
    public function isBefore(self $other): bool
    {
        return $this->value < $other->value;
    }

    /**
     * Get the delay in seconds between this timestamp and another.
     */
    public function diff(self $other): int
    {
        return abs($this->value - $other->value);
    }
}
