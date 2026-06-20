<?php

declare(strict_types=1);

namespace AndyDefer\Task\ValueObjects;

use AndyDefer\DomainStructures\Abstracts\AbstractValueObject;
use InvalidArgumentException;

final class TaskIdVO extends AbstractValueObject
{
    public function __construct(public readonly string $value)
    {
        if (! preg_match('/^[a-f0-9-]{36}$/', $value)) {
            throw new InvalidArgumentException("Invalid task ID format: {$value}");
        }
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function fileName(): string
    {
        return $this->value.'.jsonl';
    }
}
