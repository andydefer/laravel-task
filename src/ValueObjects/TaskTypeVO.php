<?php

declare(strict_types=1);

namespace AndyDefer\Task\ValueObjects;

use AndyDefer\DomainStructures\Abstracts\AbstractValueObject;
use InvalidArgumentException;

final class TaskTypeVO extends AbstractValueObject
{
    public readonly string $value;

    public function __construct(string $value)
    {
        if (! preg_match('/^[a-z][a-z0-9-]*$/', $value)) {
            throw new InvalidArgumentException(
                sprintf('Invalid task type: "%s". Must be lowercase alphanumeric with hyphens.', $value)
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
}
