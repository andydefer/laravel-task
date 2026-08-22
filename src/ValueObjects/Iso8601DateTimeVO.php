<?php

declare(strict_types=1);

namespace AndyDefer\Task\ValueObjects;

use AndyDefer\DomainStructures\Abstracts\AbstractValueObject;
use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use InvalidArgumentException;

/**
 * Value Object representing a datetime.
 */
final class Iso8601DateTimeVO extends AbstractValueObject
{
    private const ISO_FORMAT = 'Y-m-d\TH:i:sP';

    private const DB_FORMAT = 'Y-m-d H:i:s';

    private Carbon $carbon;

    public function __construct(?string $value = null)
    {
        if ($value === null) {
            $this->carbon = Carbon::now();

            return;
        }

        try {
            $this->carbon = Carbon::createFromFormat(self::ISO_FORMAT, $value);

            return;
        } catch (InvalidFormatException $e) {
            try {
                $this->carbon = Carbon::createFromFormat(self::DB_FORMAT, $value);

                return;
            } catch (InvalidFormatException $e) {
                throw new InvalidArgumentException("Invalid datetime value: {$value}");
            }
        }
    }

    public function getValue(): string
    {
        return $this->carbon->format(self::DB_FORMAT);
    }

    public function toIso8601(): string
    {
        return $this->carbon->format(self::ISO_FORMAT);
    }

    public function forDatabase(): string
    {
        return $this->carbon->format(self::DB_FORMAT);
    }

    public function getCarbon(): Carbon
    {
        return $this->carbon;
    }

    public function toCarbon(): Carbon
    {
        return clone $this->carbon;
    }

    public function getTimestamp(): int
    {
        return $this->carbon->timestamp;
    }

    public function isAfter(self $other): bool
    {
        return $this->carbon->gt($other->carbon);
    }

    public function isBefore(self $other): bool
    {
        return $this->carbon->lt($other->carbon);
    }

    public function diffInSeconds(self $other): DurationVO
    {
        $diff = $this->carbon->diffInSeconds($other->carbon);

        return new DurationVO((float) abs($diff));
    }

    public function diffInMilliseconds(self $other): MillisecondsVO
    {
        $diff = $this->carbon->diffInMilliseconds($other->carbon);

        return new MillisecondsVO((int) abs($diff));
    }

    public function elapsedInMilliseconds(): MillisecondsVO
    {
        return $this->diffInMilliseconds(new self);
    }

    public function elapsed(): DurationVO
    {
        return $this->diffInSeconds(new self);
    }

    public function format(string $format = 'Y-m-d H:i:s'): string
    {
        return $this->carbon->format($format);
    }

    public static function nowForDatabase(): string
    {
        return (new self)->forDatabase();
    }

    public function forDisplay(): string
    {
        return $this->carbon->format('d/m/Y H:i:s');
    }

    public function forFilename(): string
    {
        return $this->carbon->format('Y-m-d_H-i-s');
    }

    public function toRfc2822(): string
    {
        return $this->carbon->toRfc2822String();
    }

    public function toAtom(): string
    {
        return $this->carbon->toAtomString();
    }

    public function addSeconds(int $seconds): self
    {
        $carbon = clone $this->carbon;
        $carbon->addSeconds($seconds);

        return new self($carbon->format(self::ISO_FORMAT));
    }

    public function subSeconds(int $seconds): self
    {
        $carbon = clone $this->carbon;
        $carbon->subSeconds($seconds);

        return new self($carbon->format(self::ISO_FORMAT));
    }

    public function __toString(): string
    {
        return $this->forDatabase();
    }
}
