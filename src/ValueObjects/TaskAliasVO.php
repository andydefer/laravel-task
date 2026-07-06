<?php

declare(strict_types=1);

namespace AndyDefer\Task\ValueObjects;

use AndyDefer\DomainStructures\Abstracts\AbstractValueObject;
use AndyDefer\Task\Enums\TaskType;
use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

/**
 * Value Object representing a task alias.
 *
 * Format: "type@uuid"
 * Example: "unique@550e8400-e29b-41d4-a716-446655440000"
 *
 * @example
 * new TaskAliasVO('unique@550e8400-e29b-41d4-a716-446655440000');
 */
final class TaskAliasVO extends AbstractValueObject
{
    private readonly string $value;

    private readonly TaskType $type;

    private readonly string $uuid;

    public function __construct(string $alias)
    {
        if (! str_contains($alias, '@')) {
            throw new InvalidArgumentException(
                sprintf('Invalid alias format: "%s". Expected "type@uuid"', $alias)
            );
        }

        $parts = explode('@', $alias, 2);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException(
                sprintf('Invalid alias format: "%s". Expected "type@uuid"', $alias)
            );
        }

        [$typeString, $uuidString] = $parts;

        // Validation du type via l'énumération
        try {
            $this->type = TaskType::from($typeString);
        } catch (\ValueError $e) {
            $validTypes = implode(', ', array_column(TaskType::cases(), 'value'));
            throw new InvalidArgumentException(
                sprintf('Invalid task type: "%s". Valid types are: %s', $typeString, $validTypes)
            );
        }

        // Validation de l'UUID
        if (! Uuid::isValid($uuidString)) {
            throw new InvalidArgumentException(
                sprintf('Invalid UUID format: "%s"', $uuidString)
            );
        }

        $this->uuid = $uuidString;
        $this->value = $this->type->value.'@'.$this->uuid;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getType(): TaskType
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
