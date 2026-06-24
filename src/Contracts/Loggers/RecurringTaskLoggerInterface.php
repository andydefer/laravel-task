<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Loggers;

use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\MillisecondsVO;

interface RecurringTaskLoggerInterface
{
    public function logStart(RecurringTaskRecord $record): void;

    public function logSuccess(RecurringTaskRecord $record, MillisecondsVO $executionTime): void;

    public function logFailure(RecurringTaskRecord $record, DescriptionVO $error): void;

    public function logMoveToRunning(RecurringTaskRecord $record): void;

    public function logMoveToFinished(RecurringTaskRecord $record): void;
}
