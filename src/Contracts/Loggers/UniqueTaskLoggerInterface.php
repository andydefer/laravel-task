<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Loggers;

use AndyDefer\Task\Records\UniqueTaskRecord;

interface UniqueTaskLoggerInterface
{
    public function logStart(UniqueTaskRecord $record): void;

    public function logSuccess(UniqueTaskRecord $record, float $executionTime): void;

    public function logFailure(UniqueTaskRecord $record, string $error): void;

    public function logExpired(UniqueTaskRecord $record): void;

    public function logMaxAttemptsReached(UniqueTaskRecord $record): void;
}
