<?php

declare(strict_types=1);

namespace AndyDefer\Task\Validators;

use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\Task\Abstract\AbstractUniqueTask;
use AndyDefer\Task\Contracts\Validators\UniqueTaskValidatorInterface;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\Records\UniqueTaskRecord;
use Illuminate\Support\Carbon;

final class UniqueTaskValidator implements UniqueTaskValidatorInterface
{
    public function canRun(UniqueTaskRecord $record): bool
    {
        if (! $this->isValidTaskClass($record)) {
            return false;
        }

        return $this->isReadyToRun($record) && ! $this->hasReachedMaxAttempts($record) && ! $this->isExpired($record);
    }

    public function isReadyToRun(UniqueTaskRecord $record): bool
    {
        if (! $this->isValidTaskClass($record)) {
            return false;
        }

        if ($record->status !== UniqueTaskStatus::PENDING) {
            return false;
        }

        $now = Carbon::now();
        $scheduledAt = Carbon::parse($record->scheduled_at->value);

        return $scheduledAt->lte($now);
    }

    public function isExpired(UniqueTaskRecord $record): bool
    {
        if (! $this->isValidTaskClass($record)) {
            return false;
        }

        $now = Carbon::now();
        $scheduledAt = Carbon::parse($record->scheduled_at->value);
        $graceEnd = $scheduledAt->copy()->addSeconds($record->grace_period_seconds);

        return $now->gt($graceEnd);
    }

    public function hasReachedMaxAttempts(UniqueTaskRecord $record): bool
    {
        return $record->attempts->value >= $record->max_attempts->value;
    }

    public function getValidationErrors(UniqueTaskRecord $record): StringTypedCollection
    {
        $errors = new StringTypedCollection;

        if (! $this->isValidTaskClass($record)) {
            $errors->add('Invalid task class: '.$record->fqcn.' does not exist or does not extend AbstractUniqueTask');
        }

        if ($record->status !== UniqueTaskStatus::PENDING) {
            $errors->add('Task is not in PENDING state');
        }

        if ($this->hasReachedMaxAttempts($record)) {
            $errors->add('Maximum attempts reached');
        }

        if ($this->isExpired($record)) {
            $errors->add('Task has expired');
        }

        if (! $this->isReadyToRun($record)) {
            $errors->add('Task is not ready to run (scheduled_at in the future)');
        }

        return $errors;
    }

    private function isValidTaskClass(UniqueTaskRecord $record): bool
    {
        if (! class_exists($record->fqcn)) {
            return false;
        }

        if (! is_subclass_of($record->fqcn, AbstractUniqueTask::class)) {
            return false;
        }

        return true;
    }
}
