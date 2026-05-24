<?php

// src/ValueObjects/TaskIdentifier.php

declare(strict_types=1);

namespace AndyDefer\Task\ValueObjects;

use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskRecord;

final class TaskIdentifier
{
    private function __construct(
        private readonly string $value,
    ) {}

    public static function fromTask(object $task): self
    {
        if ($task instanceof TaskRecord) {
            return new self($task->id);
        }
        if ($task instanceof RecurringTaskRecord) {
            return new self('recurring_'.$task->signature);
        }

        return new self('unknown');
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(TaskIdentifier $other): bool
    {
        return $this->value === $other->value;
    }
}
