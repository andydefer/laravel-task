<?php

declare(strict_types=1);

namespace AndyDefer\Task\ValueObjects;

use AndyDefer\DomainStructures\Abstracts\AbstractValueObject;
use InvalidArgumentException;

final class TaskDateVO extends AbstractValueObject
{
    public function __construct(public readonly string $value)
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new InvalidArgumentException("Invalid date format: {$value}. Expected YYYY-MM-DD.");
        }
    }

    public function getValue(): string
    {
        return $this->value;
    }
}
