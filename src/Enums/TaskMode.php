<?php

// src/Enums/TaskMode.php

declare(strict_types=1);

namespace AndyDefer\Task\Enums;

use AndyDefer\Records\Traits\Enumable;

enum TaskMode: string
{
    use Enumable;

    case SYNC = 'sync';   // Execute immediately in same process
    case DEFER = 'defer'; // Execute via poller (async)

    public function getLabel(): string
    {
        return match ($this) {
            self::SYNC => 'Synchronous',
            self::DEFER => 'Deferred',
        };
    }

    public function isSync(): bool
    {
        return $this === self::SYNC;
    }

    public function isDefer(): bool
    {
        return $this === self::DEFER;
    }
}
