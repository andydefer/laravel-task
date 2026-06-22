<?php

declare(strict_types=1);

namespace AndyDefer\Task\Enums;

enum UniqueTaskStatus: string
{
    case PENDING = 'pending';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELED = 'canceled';

    public function getLabel(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::COMPLETED => 'Completed',
            self::FAILED => 'Failed',
            self::CANCELED => 'Canceled',
        };
    }

    public function isPending(): bool
    {
        return $this === self::PENDING;
    }

    public function isCompleted(): bool
    {
        return $this === self::COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this === self::FAILED;
    }

    public function isCanceled(): bool
    {
        return $this === self::CANCELED;
    }

    public function isTerminal(): bool
    {
        return $this === self::COMPLETED || $this === self::FAILED || $this === self::CANCELED;
    }
}
