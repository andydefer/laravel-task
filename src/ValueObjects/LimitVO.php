<?php

declare(strict_types=1);

namespace AndyDefer\Task\ValueObjects;

use AndyDefer\DomainStructures\Abstracts\AbstractValueObject;

/**
 * Value Object representing a limit with clamp between 1 and 10000.
 *
 * @author Andy Defer
 */
final class LimitVO extends AbstractValueObject
{
    private const MIN = 1;

    private const MAX = 10000;

    public readonly int $value;

    /**
     * Create a new LimitVO instance.
     *
     * @param  int  $value  The limit value (will be clamped between 1 and 10000)
     */
    public function __construct(int $value = 10000)
    {
        // Clamp entre 1 et 10000
        $clamped = max(self::MIN, min(self::MAX, $value));
        $this->value = $clamped;
    }

    /**
     * Get the limit value.
     *
     * @return int The clamped limit value
     */
    public function getValue(): int
    {
        return $this->value;
    }

    /**
     * Get the minimum allowed value.
     *
     * @return int The minimum value (1)
     */
    public static function getMin(): int
    {
        return self::MIN;
    }

    /**
     * Get the maximum allowed value.
     *
     * @return int The maximum value (10000)
     */
    public static function getMax(): int
    {
        return self::MAX;
    }

    /**
     * Check if the limit is at the minimum.
     *
     * @return bool True if limit is 1, false otherwise
     */
    public function isMin(): bool
    {
        return $this->value === self::MIN;
    }

    /**
     * Check if the limit is at the maximum.
     *
     * @return bool True if limit is 10000, false otherwise
     */
    public function isMax(): bool
    {
        return $this->value === self::MAX;
    }

    /**
     * Convert to string.
     *
     * @return string The limit value as string
     */
    public function __toString(): string
    {
        return (string) $this->value;
    }
}
