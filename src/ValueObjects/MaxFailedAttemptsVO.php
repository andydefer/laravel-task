<?php

declare(strict_types=1);

namespace AndyDefer\Task\ValueObjects;

use AndyDefer\DomainStructures\Abstracts\AbstractValueObject;

final class MaxFailedAttemptsVO extends AbstractValueObject
{
    public readonly int $value;

    public function __construct(int $value)
    {
        // Clamp entre 1 et 10
        $clamped = max(1, min(10, $value));
        $this->value = $clamped;
    }

    public function getValue(): int
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}
