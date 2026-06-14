<?php

declare(strict_types=1);

namespace AndyDefer\Task\ValueObjects;

use AndyDefer\DomainStructures\Abstracts\AbstractValueObject;

/**
 * Value Object for Grace Period file path.
 *
 * Encapsulates the logic of building the file path for grace period records.
 * Format: {basePath}/{task_id}.json
 *
 * @author Andy Defer
 */
final class GracePeriodFilePathVO extends AbstractValueObject
{
    public function __construct(
        private readonly string $basePath,
        private readonly TaskIdVO $taskId,
    ) {}

    public function getValue(): string
    {
        return $this->basePath.'/'.$this->taskId->value.'.json';
    }

    public function getDirectory(): string
    {
        return $this->basePath;
    }

    public function getFileName(): string
    {
        return $this->taskId->value.'.json';
    }
}
