<?php

declare(strict_types=1);

namespace AndyDefer\Task\Enums;

/**
 * Represents the type of error that occurred during task execution.
 *
 * @author Andy Defer
 */
enum ErrorType: string
{
    case INVALID_TASK_CLASS = 'invalid_task_class';
    case TASK_VALIDATION_FAILED = 'task_validation_failed';
    case TASK_EXECUTION_FAILED = 'task_execution_failed';
    case TASK_EXPIRED = 'task_expired';
    case MAX_ATTEMPTS_REACHED = 'max_attempts_reached';
    case GRACE_PERIOD_EXPIRED = 'grace_period_expired';
    case RECURRING_NOT_READY = 'recurring_not_ready';
    case STORAGE_ERROR = 'storage_error';

    public function getLabel(): string
    {
        return match ($this) {
            self::INVALID_TASK_CLASS => 'Invalid Task Class',
            self::TASK_VALIDATION_FAILED => 'Task Validation Failed',
            self::TASK_EXECUTION_FAILED => 'Task Execution Failed',
            self::TASK_EXPIRED => 'Task Expired',
            self::MAX_ATTEMPTS_REACHED => 'Max Attempts Reached',
            self::GRACE_PERIOD_EXPIRED => 'Grace Period Expired',
            self::RECURRING_NOT_READY => 'Recurring Task Not Ready',
            self::STORAGE_ERROR => 'Storage Error',
        };
    }

    public function getMessage(): string
    {
        return match ($this) {
            self::INVALID_TASK_CLASS => 'Invalid task class',
            self::TASK_VALIDATION_FAILED => 'Task cannot be run (invalid state, expired, or max attempts reached)',
            self::TASK_EXECUTION_FAILED => 'Task execution failed',
            self::TASK_EXPIRED => 'Task has expired',
            self::MAX_ATTEMPTS_REACHED => 'Maximum attempts reached',
            self::GRACE_PERIOD_EXPIRED => 'Grace period expired',
            self::RECURRING_NOT_READY => 'Recurring task not ready to run',
            self::STORAGE_ERROR => 'Storage error occurred',
        };
    }

    public function isRecoverable(): bool
    {
        return match ($this) {
            self::TASK_EXECUTION_FAILED => true,
            self::STORAGE_ERROR => true,
            default => false,
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            self::INVALID_TASK_CLASS => true,
            self::TASK_EXPIRED => true,
            self::MAX_ATTEMPTS_REACHED => true,
            self::GRACE_PERIOD_EXPIRED => true,
            default => false,
        };
    }
}
