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
}
