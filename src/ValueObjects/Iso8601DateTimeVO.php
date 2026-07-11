<?php

declare(strict_types=1);

namespace AndyDefer\Task\ValueObjects;

use AndyDefer\DomainStructures\Abstracts\AbstractValueObject;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Value Object representing an ISO 8601 datetime.
 */
final class Iso8601DateTimeVO extends AbstractValueObject
{
    private const FORMAT = 'Y-m-d\TH:i:sP';

    public function __construct(?string $value = null)
    {
        $value = $value ?? Carbon::now()->format(self::FORMAT);

        $date = Carbon::createFromFormat(self::FORMAT, $value);

        if (! $date || $date->format(self::FORMAT) !== $value) {
            throw new InvalidArgumentException("Invalid ISO 8601 datetime: {$value}");
        }

        $this->value = $value;
    }

    private readonly string $value;

    public function getValue(): string
    {
        return $this->value;
    }

    public function toCarbon(): Carbon
    {
        return Carbon::createFromFormat(self::FORMAT, $this->value);
    }

    /**
     * Get the Unix timestamp.
     *
     * @return int The Unix timestamp
     */
    public function getTimestamp(): int
    {
        return $this->toCarbon()->timestamp;
    }

    public function isAfter(self $other): bool
    {
        return $this->toCarbon()->gt($other->toCarbon());
    }

    public function isBefore(self $other): bool
    {
        return $this->toCarbon()->lt($other->toCarbon());
    }

    /**
     * Calculate the difference in seconds between this datetime and another.
     *
     * @param  self  $other  The other datetime
     * @return DurationVO The duration between the two datetimes
     */
    public function diffInSeconds(self $other): DurationVO
    {
        $diff = $this->toCarbon()->diffInSeconds($other->toCarbon());

        return new DurationVO((float) abs($diff));
    }

    /**
     * Calculate the difference in milliseconds between this datetime and another.
     *
     * @param  self  $other  The other datetime
     * @return MillisecondsVO The duration in milliseconds between the two datetimes
     */
    public function diffInMilliseconds(self $other): MillisecondsVO
    {
        $startTimestamp = $this->toCarbon()->getTimestamp();
        $endTimestamp = $other->toCarbon()->getTimestamp();

        return new MillisecondsVO((int) (($endTimestamp - $startTimestamp) * 1000));
    }

    /**
     * Calculate the duration since this datetime in milliseconds.
     *
     * @return MillisecondsVO The duration from this datetime to now in milliseconds
     */
    public function elapsedInMilliseconds(): MillisecondsVO
    {
        $now = new self;
        $diff = $now->toCarbon()->diffInMilliseconds($this->toCarbon());

        return new MillisecondsVO((int) abs($diff));
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
        return $this->toCarbon()->format($format);
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
        return $this->toCarbon()->toRfc2822String();
    }

    /**
     * Format the datetime as Atom.
     *
     * @return string The datetime in Atom format
     */
    public function toAtom(): string
    {
        return $this->toCarbon()->toAtomString();
    }

    /**
     * Add seconds to the datetime.
     *
     * @param  int  $seconds  Number of seconds to add
     * @return self New instance with added seconds
     */
    public function addSeconds(int $seconds): self
    {
        $carbon = $this->toCarbon()->addSeconds($seconds);

        return new self($carbon->format(self::FORMAT));
    }

    /**
     * Subtract seconds from the datetime.
     *
     * @param  int  $seconds  Number of seconds to subtract
     * @return self New instance with subtracted seconds
     */
    public function subSeconds(int $seconds): self
    {
        $carbon = $this->toCarbon()->subSeconds($seconds);

        return new self($carbon->format(self::FORMAT));
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
