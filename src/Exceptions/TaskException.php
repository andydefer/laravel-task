<?php

// src/Exceptions/TaskException.php

declare(strict_types=1);

namespace AndyDefer\Task\Exceptions;

use RuntimeException;

final class TaskException extends RuntimeException
{
    public static function taskNotFound(string $id): self
    {
        return new self("Task not found: {$id}");
    }

    public static function classNotFound(string $class): self
    {
        return new self("Task class not found: {$class}");
    }

    public static function invalidClass(string $class): self
    {
        return new self("Task class must extend AbstractTask: {$class}");
    }

    public static function recurringAlreadyExists(string $signature): self
    {
        return new self("Recurring task already exists: {$signature}");
    }
}
