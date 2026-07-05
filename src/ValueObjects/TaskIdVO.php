<?php

declare(strict_types=1);

namespace AndyDefer\Task\ValueObjects;

use AndyDefer\DomainStructures\Abstracts\AbstractValueObject;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

final class UuidVO extends AbstractValueObject
{
    public function __construct(public readonly string $value)
    {
        if (! Uuid::isValid($value)) {
            throw new InvalidArgumentException("Invalid task ID format: {$value}");
        }
    }

    public function getValue(): string
    {
        return $this->value;
    }
}
