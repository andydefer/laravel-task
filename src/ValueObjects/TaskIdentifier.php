<?php

declare(strict_types=1);

namespace AndyDefer\Task\ValueObjects;

use AndyDefer\DomainStructures\Abstracts\AbstractValueObject;

/**
 * Value Object representing a unique task identifier.
 *
 * Uses the task ID as the identifier value.
 *
 * @author Andy Defer
 */
final class TaskIdentifier extends AbstractValueObject
{
    private function __construct(
        private readonly string $value
    ) {}

    /**
     * Get the string representation of the identifier.
     */
    public function toString(): string
    {
        return $this->value;
    }

    /**
     * {@inheritDoc}
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * Convert to string.
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
