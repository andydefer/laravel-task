<?php

declare(strict_types=1);

namespace AndyDefer\Task\ValueObjects;

use AndyDefer\DomainStructures\Abstracts\AbstractValueObject;
use AndyDefer\Task\Records\RecurringTaskRecord;
use InvalidArgumentException;

/**
 * Value Object representing a recurring task identifier.
 *
 * Uses 'recurring_' prefix + signature as the identifier value.
 *
 * @author Andy Defer
 */
final class RecurringTaskIdentifier extends AbstractValueObject
{
    private const PREFIX = 'recurring_';

    private function __construct(
        private readonly string $signature,
        private readonly string $value
    ) {}

    /**
     * Create a RecurringTaskIdentifier from a RecurringTaskRecord.
     *
     * @throws InvalidArgumentException If the task signature is empty
     */
    public static function fromTask(RecurringTaskRecord $task): self
    {
        if ($task->signature === '') {
            throw new InvalidArgumentException('Recurring task signature cannot be empty');
        }

        return new self($task->signature, self::PREFIX.$task->signature);
    }

    /**
     * Create a RecurringTaskIdentifier from a string value.
     *
     * @throws InvalidArgumentException If the value is empty or doesn't have the recurring prefix
     */
    public static function fromString(string $value): self
    {
        if ($value === '') {
            throw new InvalidArgumentException('Recurring task identifier cannot be empty');
        }

        if (! str_starts_with($value, self::PREFIX)) {
            throw new InvalidArgumentException(
                sprintf('Recurring task identifier must start with "%s"', self::PREFIX)
            );
        }

        $signature = substr($value, strlen(self::PREFIX));

        if ($signature === '') {
            throw new InvalidArgumentException('Recurring task signature cannot be empty');
        }

        return new self($signature, $value);
    }

    /**
     * Get the signature without the prefix.
     */
    public function getSignature(): string
    {
        return $this->signature;
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
