<?php

declare(strict_types=1);

namespace AndyDefer\Task\Configs;

use AndyDefer\Task\Contracts\Configs\UniqueTaskConfigInterface;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\MaxFailedAttemptsVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

final class UniqueTaskConfig implements UniqueTaskConfigInterface
{
    public function __construct(
        public readonly TaskSignatureVO $alias,
        public readonly string $description,
        public readonly Iso8601DateTimeVO $scheduled_at,
        public readonly MaxFailedAttemptsVO $max_attempts = new MaxFailedAttemptsVO(3),
    ) {}

    public function getAlias(): TaskSignatureVO
    {
        return $this->alias;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getScheduledAt(): Iso8601DateTimeVO
    {
        return $this->scheduled_at;
    }

    public function getMaxAttempts(): MaxFailedAttemptsVO
    {
        return $this->max_attempts;
    }

    public function toArray(): array
    {
        return [
            'alias' => $this->alias->value,
            'description' => $this->description,
            'scheduled_at' => $this->scheduled_at->value,
            'max_attempts' => $this->max_attempts->value,
        ];
    }
}
