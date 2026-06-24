<?php

declare(strict_types=1);

namespace AndyDefer\Task\ValueObjects;

use AndyDefer\DomainStructures\Abstracts\AbstractValueObject;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

final class TaskAliasVO extends AbstractValueObject
{
    private readonly string $value;

    private readonly TaskTypeVO $type;

    private readonly string $uuid;

    public function __construct(TaskTypeVO $type, string $uuid)
    {
        $this->validateUuid($uuid);

        $this->type = $type;
        $this->uuid = $uuid;
        $this->value = $type->getValue().'@'.$uuid;
    }

    private function validateUuid(string $uuid): void
    {
        if (! Uuid::isValid($uuid)) {
            throw new InvalidArgumentException(
                sprintf('Invalid UUID format: "%s"', $uuid)
            );
        }
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getType(): TaskTypeVO
    {
        return $this->type;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
