<?php

declare(strict_types=1);

namespace AndyDefer\Task\ValueObjects;

use AndyDefer\DomainStructures\Abstracts\AbstractValueObject;
use InvalidArgumentException;

/**
 * Value Object representing a duration in seconds.
 *
 * Provides methods for duration manipulation, conversion to other units,
 * and human-readable formatting.
 *
 * @author Andy Defer
 */
final class DurationVO extends AbstractValueObject
{
    /**
     * The duration value in seconds.
     */
    public readonly float $seconds;

    /**
     * Create a new DurationVO instance.
     *
     * @param  float  $seconds  The duration in seconds (must be >= 0)
     *
     * @throws InvalidArgumentException If seconds is negative
     */
    public function __construct(float $seconds = 0.0)
    {
        if ($seconds < 0) {
            throw new InvalidArgumentException(sprintf(
                'Duration cannot be negative. Got: %f seconds',
                $seconds
            ));
        }

        $this->seconds = $seconds;
    }

    /**
     * Get the duration value in seconds.
     *
     * @return float The duration in seconds
     */
    public function getValue(): float
    {
        return $this->seconds;
    }

    /**
     * Convert the duration to milliseconds.
     *
     * @return float The duration in milliseconds
     */
    public function toMilliseconds(): float
    {
        return (float) ($this->seconds * 1000);
    }

    /**
     * Convert the duration to minutes.
     *
     * @return float The duration in minutes
     */
    public function toMinutes(): float
    {
        return $this->seconds / 60;
    }

    /**
     * Convert the duration to hours.
     *
     * @return float The duration in hours
     */
    public function toHours(): float
    {
        return $this->seconds / 3600;
    }

    /**
     * Convert the duration to days.
     *
     * @return float The duration in days
     */
    public function toDays(): float
    {
        return $this->seconds / 86400;
    }

    /**
     * Format the duration in a human-readable string.
     *
     * Examples:
     * - 45s → "45s"
     * - 90s → "1m 30s"
     * - 3661s → "1h 1m 1s"
     * - 90061s → "1d 1h 1m 1s"
     *
     * @return string Human-readable duration string
     */
    public function format(): string
    {
        $seconds = (int) $this->seconds;
        $parts = [];

        if ($seconds >= 86400) {
            $days = (int) floor($seconds / 86400);
            $parts[] = $days.'d';
            $seconds = $seconds % 86400;
        }

        if ($seconds >= 3600) {
            $hours = (int) floor($seconds / 3600);
            $parts[] = $hours.'h';
            $seconds = $seconds % 3600;
        }

        if ($seconds >= 60) {
            $minutes = (int) floor($seconds / 60);
            $parts[] = $minutes.'m';
            $seconds = $seconds % 60;
        }

        if ($seconds > 0) {
            $parts[] = $seconds.'s';
        }

        // Si aucun part (durée = 0)
        if (empty($parts)) {
            return '0s';
        }

        return implode(' ', $parts);
    }

    /**
     * Add another duration to this one.
     *
     * @param  DurationVO  $other  The duration to add
     * @return self New DurationVO with the sum of both durations
     */
    public function add(DurationVO $other): self
    {
        return new self($this->seconds + $other->seconds);
    }

    /**
     * Subtract another duration from this one.
     *
     * @param  DurationVO  $other  The duration to subtract
     * @return self New DurationVO with the difference
     *
     * @throws InvalidArgumentException If result would be negative
     */
    public function subtract(DurationVO $other): self
    {
        $newValue = $this->seconds - $other->seconds;

        if ($newValue < 0) {
            throw new InvalidArgumentException(sprintf(
                'Duration cannot be negative. Result would be: %f seconds',
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
        return $this->seconds === 0.0;
    }

    /**
     * Check if the duration is positive (greater than zero).
     *
     * @return bool True if duration is positive, false otherwise
     */
    public function isPositive(): bool
    {
        return $this->seconds > 0;
    }

    /**
     * Convert the duration to a string.
     *
     * @return string The duration in seconds as a string
     */
    public function __toString(): string
    {
        return (string) $this->seconds;
    }
}
