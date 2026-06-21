<?php

declare(strict_types=1);

namespace AndyDefer\Task\Enums;

enum ExecutionStatus: string
{
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::SUCCEEDED => 'Succeeded',
            self::FAILED => 'Failed',
        };
    }

    public function isSucceeded(): bool
    {
        return $this === self::SUCCEEDED;
    }

    public function isFailed(): bool
    {
        return $this === self::FAILED;
    }
}
