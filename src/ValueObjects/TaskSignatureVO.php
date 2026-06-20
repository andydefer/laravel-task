<?php

declare(strict_types=1);

namespace AndyDefer\Task\ValueObjects;

use AndyDefer\DomainStructures\Abstracts\AbstractValueObject;
use InvalidArgumentException;

final class TaskSignatureVO extends AbstractValueObject
{
    public function __construct(public readonly string $value)
    {
        if (! preg_match('/^[a-z0-9][a-z0-9-]*$/', $value)) {
            throw new InvalidArgumentException(
                "Invalid task signature: {$value}. Must be lowercase alphanumeric with hyphens."
            );
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
