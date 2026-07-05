<?php

declare(strict_types=1);

namespace AndyDefer\Task\ValueObjects;

use AndyDefer\DomainStructures\Abstracts\AbstractValueObject;
use InvalidArgumentException;

/**
 * Value Object representing a duration in milliseconds.
 *
 * Provides methods for milliseconds manipulation, conversion to other units,
 * and human-readable formatting.
 *
 * @author Andy Defer
 */
final class MillisecondsVO extends AbstractValueObject
{
    /**
     * The duration value in milliseconds.
     */
    public readonly int $value;

    /**
     * Create a new MillisecondsVO instance.
     *
     * @param  int  $milliseconds  The duration in milliseconds (must be >= 0)
     *
     * @throws InvalidArgumentException If milliseconds is negative
     */
    public function __construct(int $milliseconds = 1)
    {
        if ($milliseconds < 0) {
            throw new InvalidArgumentException(sprintf(
                'Milliseconds cannot be negative. Got: %d ms',
                $milliseconds
            ));
        }

        $this->value = $milliseconds;
    }

    /**
     * Get the duration value in milliseconds.
     *
     * @return int The duration in milliseconds
     */
    public function getValue(): float
    {
        return (float) $this->value;
    }

    /**
     * Convert milliseconds to seconds.
     *
     * @return float The duration in seconds
     */
    public function toSeconds(): float
    {
        return $this->value / 1000;
    }

    /**
     * Convert milliseconds to DurationVO.
     *
     * @return DurationVO The duration in seconds
     */
    public function toDuration(): DurationVO
    {
        return new DurationVO($this->value / 1000);
    }

    /**
     * Add another MillisecondsVO to this one.
     *
     * @param  MillisecondsVO  $other  The milliseconds to add
     * @return self New MillisecondsVO with the sum of both values
     */
    public function add(MillisecondsVO $other): self
    {
        return new self($this->value + $other->value);
    }

    /**
     * Subtract another MillisecondsVO from this one.
     *
     * @param  MillisecondsVO  $other  The milliseconds to subtract
     * @return self New MillisecondsVO with the difference
     *
     * @throws InvalidArgumentException If result would be negative
     */
    public function subtract(MillisecondsVO $other): self
    {
        $newValue = $this->value - $other->value;

        if ($newValue < 0) {
            throw new InvalidArgumentException(sprintf(
                'Milliseconds cannot be negative. Result would be: %d ms',
                $newValue
            ));
        }

        return new self($newValue);
    }

    /**
     * Check if the duration is zero.
     *
     * @return bool True if duration is zero, false otherwise
     */
    public function isZero(): bool
    {
        return $this->value === 0;
    }

    /**
     * Check if the duration is positive (greater than zero).
     *
     * @return bool True if duration is positive, false otherwise
     */
    public function isPositive(): bool
    {
        return $this->value > 0;
    }

    /**
     * Convert the duration to a string.
     *
     * @return string The duration in milliseconds as a string
     */
    public function __toString(): string
    {
        return (string) $this->value;
    }
}
