<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Loggers;

use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\MillisecondsVO;

interface UniqueTaskLoggerInterface
{
    public function logStart(UniqueTaskRecord $record): void;

    public function logSuccess(UniqueTaskRecord $record, MillisecondsVO $executionTime): void;

    public function logFailure(UniqueTaskRecord $record, DescriptionVO $error): void;

    public function logExpired(UniqueTaskRecord $record): void;

    public function logMaxAttemptsReached(UniqueTaskRecord $record): void;
}
