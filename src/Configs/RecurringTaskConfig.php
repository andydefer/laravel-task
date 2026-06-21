<?php

declare(strict_types=1);

namespace AndyDefer\Task\Configs;

use AndyDefer\Task\Contracts\Configs\RecurringTaskConfigInterface;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

final class RecurringTaskConfig implements RecurringTaskConfigInterface
{
    public function __construct(
        public readonly TaskSignatureVO $alias,
        public readonly string $description,
        public readonly CounterVO $interval_seconds,
        public readonly ?Iso8601DateTimeVO $start_at = null,
        public readonly ?Iso8601DateTimeVO $end_at = null,
        public readonly CounterVO $max_attempts = new CounterVO(3),
    ) {}

    public function getAlias(): TaskSignatureVO
    {
        return $this->alias;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getIntervalSeconds(): CounterVO
    {
        return $this->interval_seconds;
    }

    public function getStartAt(): ?Iso8601DateTimeVO
    {
        return $this->start_at;
    }

    public function getEndAt(): ?Iso8601DateTimeVO
    {
        return $this->end_at;
    }

    public function getMaxAttempts(): CounterVO
    {
        return $this->max_attempts;
    }

    public function toArray(): array
    {
        return [
            'alias' => $this->alias->value,
            'description' => $this->description,
            'interval_seconds' => $this->interval_seconds->value,
            'start_at' => $this->start_at?->value,
            'end_at' => $this->end_at?->value,
            'max_attempts' => $this->max_attempts->value,
        ];
    }
}
