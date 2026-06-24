<?php

declare(strict_types=1);

namespace AndyDefer\Task\ValueObjects;

use AndyDefer\DomainStructures\Abstracts\AbstractValueObject;

final class DescriptionVO extends AbstractValueObject
{
    public readonly string $value;

    public function __construct(string $value)
    {
        $trimmed = trim($value);

        if (strlen($trimmed) < 5) {
            $trimmed = 'Description: '.$trimmed;
        }

        if (strlen($trimmed) > 500) {
            $trimmed = substr($trimmed, 0, 497).'...';
        }

        $this->value = $trimmed;
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
