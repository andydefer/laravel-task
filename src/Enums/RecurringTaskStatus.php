<?php

declare(strict_types=1);

namespace AndyDefer\Task\Enums;

enum RecurringTaskStatus: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case FINISHED = 'finished';

    public function getLabel(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::RUNNING => 'Running',
            self::FINISHED => 'Finished',
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

    public function isFinished(): bool
    {
        return $this === self::FINISHED;
    }

    public function isTerminal(): bool
    {
        return $this === self::FINISHED;
    }
}
