<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Configs;

use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\MaxFailedAttemptsVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

interface UniqueTaskConfigInterface
{
    public function getAlias(): TaskSignatureVO;

    public function getDescription(): string;

    public function getScheduledAt(): Iso8601DateTimeVO;

    public function getMaxAttempts(): MaxFailedAttemptsVO;

    public function toArray(): array;
}
