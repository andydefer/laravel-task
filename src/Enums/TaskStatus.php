<?php

// src/Enums/TaskStatus.php

declare(strict_types=1);

namespace AndyDefer\Task\Enums;

use AndyDefer\DomainStructures\Traits\Enumable;

enum TaskStatus: string
{
    use Enumable;

    case PENDING = 'pending';   // Waiting to be executed
    case RUNNING = 'running';   // Currently being executed
    case SUCCESS = 'success';   // Completed successfully
    case FAILED = 'failed';     // Failed after max attempts

    public function getLabel(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::RUNNING => 'Running',
            self::SUCCESS => 'Success',
            self::FAILED => 'Failed',
        };
    }

    public function isPending(): bool
    {
        return $this === self::PENDING;
    }

    public function isRunning(): bool
    {
        return $this === self::RUNNING;
    }

    public function isSuccess(): bool
    {
        return $this === self::SUCCESS;
    }

    public function isFailed(): bool
    {
        return $this === self::FAILED;
    }
}
