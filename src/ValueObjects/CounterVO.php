<?php

declare(strict_types=1);

namespace AndyDefer\Task\ValueObjects;

use AndyDefer\DomainStructures\Abstracts\AbstractValueObject;
use InvalidArgumentException;

/**
 * Value Object for counter with increment/decrement logic.
 *
 * Encapsulates counter operations without static methods.
 *
 * @author Andy Defer
 */
final class CounterVO extends AbstractValueObject
{
    private int $value;

    public function __construct(int $value = 0)
    {
        if ($value < 0) {
            throw new InvalidArgumentException("Counter cannot be negative: {$value}");
        }

        $this->value = $value;
    }

    public function getValue(): int
    {
        return $this->value;
    }

    public function increment(int $by = 1): self
    {
        return new self($this->value + $by);
    }

    public function decrement(int $by = 1): self
    {
        $newValue = $this->value - $by;

        if ($newValue < 0) {
            throw new InvalidArgumentException('Counter cannot go below zero');
        }

        return new self($newValue);
    }

    /**
     * Add another CounterVO to this one
     *
     * @param  CounterVO  $other  The counter to add
     * @return self New CounterVO with the sum of both values
     */
    public function add(CounterVO $other): self
    {
        return new self($this->value + $other->value);
    }

    /**
     * Subtract another CounterVO from this one
     *
     * @param  CounterVO  $other  The counter to subtract
     * @return self New CounterVO with the difference
     *
     * @throws InvalidArgumentException If result would be negative
     */
    public function subtract(CounterVO $other): self
    {
        $newValue = $this->value - $other->value;

        if ($newValue < 0) {
            throw new InvalidArgumentException('Counter cannot go below zero');
        }

        return new self($newValue);
    }

    public function isZero(): bool
    {
        return $this->value === 0;
    }

    public function isPositive(): bool
    {
        return $this->value > 0;
    }
}
