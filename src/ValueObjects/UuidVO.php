<?php

declare(strict_types=1);

namespace AndyDefer\Task\ValueObjects;

use AndyDefer\DomainStructures\Abstracts\AbstractValueObject;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

final class UuidVO extends AbstractValueObject
{
    public readonly string $value;

    public function __construct(string $value)
    {
        if (! Uuid::isValid($value)) {
            throw new InvalidArgumentException(
                sprintf('Invalid UUID format: "%s"', $value)
            );
        }

        $this->value = $value;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public static function generate(): self
    {
        return new self(Uuid::uuid4()->toString());
    }
}
