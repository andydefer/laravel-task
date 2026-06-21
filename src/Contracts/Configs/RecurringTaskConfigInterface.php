<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Configs;

use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

interface RecurringTaskConfigInterface
{
    public function getAlias(): TaskSignatureVO;

    public function getDescription(): string;

    public function getIntervalSeconds(): CounterVO;

    public function getStartAt(): ?Iso8601DateTimeVO;

    public function getEndAt(): ?Iso8601DateTimeVO;

    public function getMaxAttempts(): CounterVO;

    public function toArray(): array;
}
