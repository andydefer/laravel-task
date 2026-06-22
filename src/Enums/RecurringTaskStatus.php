<?php

declare(strict_types=1);

namespace AndyDefer\Task\Enums;

enum RecurringTaskStatus: string
{
    case WAITING = 'waiting';
    case PLAYING = 'playing';
    case PAUSED = 'paused';
    case FINISHED = 'finished';
    case CANCELED = 'canceled';

    public function getLabel(): string
    {
        return match ($this) {
            self::WAITING => 'Waiting',
            self::PLAYING => 'Playing',
            self::PAUSED => 'Paused',
            self::FINISHED => 'Finished',
            self::CANCELED => 'Canceled',
        };
    }

    public function isWaiting(): bool
    {
        return $this === self::WAITING;
    }

    public function isPlaying(): bool
    {
        return $this === self::PLAYING;
    }

    public function isPaused(): bool
    {
        return $this === self::PAUSED;
    }

    public function isFinished(): bool
    {
        return $this === self::FINISHED;
    }

    public function isCanceled(): bool
    {
        return $this === self::CANCELED;
    }

    public function isTerminal(): bool
    {
        return $this === self::FINISHED || $this === self::CANCELED;
    }

    public function canRun(): bool
    {
        return $this === self::PLAYING;
    }

    public function isActive(): bool
    {
        return $this === self::PLAYING || $this === self::WAITING;
    }
}
