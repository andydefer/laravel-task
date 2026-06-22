<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Loggers;

use AndyDefer\Task\Records\RecurringTaskRecord;

interface RecurringTaskLoggerInterface
{
    public function logStart(RecurringTaskRecord $record): void;

    public function logSuccess(RecurringTaskRecord $record, float $executionTime): void;

    public function logFailure(RecurringTaskRecord $record, string $error): void;

    public function logMoveToRunning(RecurringTaskRecord $record): void;

    public function logMoveToFinished(RecurringTaskRecord $record): void;
}
