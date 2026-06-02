<?php

declare(strict_types=1);

namespace AndyDefer\Task\ValueObjects;

use AndyDefer\DomainStructures\Abstracts\AbstractValueObject;
use AndyDefer\Task\Records\TaskRecord;
use InvalidArgumentException;

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
     * Create a TaskIdentifier from a TaskRecord.
     *
     * @throws InvalidArgumentException If the task ID is empty
     */
    public static function fromTask(TaskRecord $task): self
    {
        if ($task->id === '') {
            throw new InvalidArgumentException('Task ID cannot be empty');
        }

        return new self($task->id);
    }

    /**
     * Create a TaskIdentifier from a string value.
     *
     * @throws InvalidArgumentException If the value is empty
     */
    public static function fromString(string $value): self
    {
        if ($value === '') {
            throw new InvalidArgumentException('Task identifier cannot be empty');
        }

        return new self($value);
    }

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
