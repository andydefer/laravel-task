<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Validators;

use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\Task\Records\UniqueTaskRecord;

interface UniqueTaskValidatorInterface
{
    public function canRun(UniqueTaskRecord $record): bool;

    public function isExpired(UniqueTaskRecord $record): bool;

    public function hasReachedMaxAttempts(UniqueTaskRecord $record): bool;

    public function isReadyToRun(UniqueTaskRecord $record): bool;

    public function getValidationErrors(UniqueTaskRecord $record): StringTypedCollection;
}
