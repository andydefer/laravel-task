<?php

declare(strict_types=1);

namespace AndyDefer\Task\ValueObjects;

use AndyDefer\DomainStructures\Abstracts\AbstractValueObject;
use DateTime;
use InvalidArgumentException;

/**
 * Value Object representing an ISO 8601 datetime.
 */
final class Iso8601DateTimeVO extends AbstractValueObject
{
    private const FORMAT = 'Y-m-d\TH:i:sP';

    public function __construct(?string $value = null)
    {
        $value = $value ?? (new DateTime)->format(self::FORMAT);

        $date = DateTime::createFromFormat(self::FORMAT, $value);

        if (! $date || $date->format(self::FORMAT) !== $value) {
            throw new InvalidArgumentException("Invalid ISO 8601 datetime: {$value}");
        }

        $this->value = $value;
    }

    public readonly string $value;

    public function getValue(): string
    {
        return $this->value;
    }

    public function toDateTime(): DateTime
    {
        return DateTime::createFromFormat(self::FORMAT, $this->value);
    }

    public function isAfter(self $other): bool
    {
        return $this->toDateTime() > $other->toDateTime();
    }

    public function isBefore(self $other): bool
    {
        return $this->toDateTime() < $other->toDateTime();
    }

    /**
     * Calculate the difference in seconds between this datetime and another.
     *
     * @param  self  $other  The other datetime
     * @return DurationVO The duration between the two datetimes
     */
    public function diffInSeconds(self $other): DurationVO
    {
        $diff = $this->toDateTime()->getTimestamp() - $other->toDateTime()->getTimestamp();

        return new DurationVO((float) abs($diff));
    }

    /**
     * Calculate the duration since this datetime.
     *
     * @return DurationVO The duration from this datetime to now
     */
    public function elapsed(): DurationVO
    {
        return $this->diffInSeconds(new self);
    }

    /**
     * Format the datetime using a custom format.
     *
     * @param  string  $format  The format string (default: 'Y-m-d H:i:s')
     * @return string The formatted datetime
     */
    public function format(string $format = 'Y-m-d H:i:s'): string
    {
        return $this->toDateTime()->format($format);
    }

    /**
     * Format the datetime for database storage.
     *
     * @return string The datetime in database format (Y-m-d H:i:s)
     */
    public function forDatabase(): string
    {
        return $this->format('Y-m-d H:i:s');
    }

    /**
     * Get the current timestamp formatted for database storage.
     *
     * @return string The current timestamp in database format (Y-m-d H:i:s)
     */
    public static function nowForDatabase(): string
    {
        return (new self)->forDatabase();
    }

    /**
     * Format the datetime for human-readable display.
     *
     * @return string The datetime in human-readable format (d/m/Y H:i:s)
     */
    public function forDisplay(): string
    {
        return $this->format('d/m/Y H:i:s');
    }

    /**
     * Format the datetime for filename.
     *
     * @return string The datetime in filename-safe format (Y-m-d_H-i-s)
     */
    public function forFilename(): string
    {
        return $this->format('Y-m-d_H-i-s');
    }

    /**
     * Format the datetime as RFC 2822.
     *
     * @return string The datetime in RFC 2822 format
     */
    public function toRfc2822(): string
    {
        return $this->toDateTime()->format(DateTime::RFC2822);
    }

    /**
     * Format the datetime as Atom.
     *
     * @return string The datetime in Atom format
     */
    public function toAtom(): string
    {
        return $this->toDateTime()->format(DateTime::ATOM);
    }

    /**
     * Convert to string (alias of getValue).
     *
     * @return string The datetime in ISO 8601 format
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
