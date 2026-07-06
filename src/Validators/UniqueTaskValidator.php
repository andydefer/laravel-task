<?php

declare(strict_types=1);

namespace AndyDefer\Task\Validators;

use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\Task\Abstract\AbstractUniqueTask;
use AndyDefer\Task\Contracts\Validators\UniqueTaskValidatorInterface;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\Records\UniqueTaskRecord;
use Illuminate\Support\Carbon;

/**
 * Validator for unique tasks.
 *
 * Provides validation methods to determine if a unique task can run,
 * is ready, expired, or has reached maximum attempts.
 */
final class UniqueTaskValidator implements UniqueTaskValidatorInterface
{
    /**
     * {@inheritDoc}
     */
    public function canRun(UniqueTaskRecord $record): bool
    {
        if (! $this->isValidTaskClass($record)) {
            return false;
        }

        return $this->isReadyToRun($record)
            && ! $this->hasReachedMaxAttempts($record)
            && ! $this->isExpired($record);
    }

    /**
     * {@inheritDoc}
     */
    public function isReadyToRun(UniqueTaskRecord $record): bool
    {
        if (! $this->isValidTaskClass($record)) {
            return false;
        }

        if ($record->status !== UniqueTaskStatus::PENDING) {
            return false;
        }

        $now = Carbon::now();
        $scheduledAt = Carbon::parse($record->scheduled_at->getValue());

        return $scheduledAt->lte($now);
    }

    /**
     * {@inheritDoc}
     */
    public function isExpired(UniqueTaskRecord $record): bool
    {
        if (! $this->isValidTaskClass($record)) {
            return false;
        }

        $now = Carbon::now();
        $scheduledAt = Carbon::parse($record->scheduled_at->getValue());
        $graceEnd = $scheduledAt->copy()->addSeconds($record->grace_period_seconds->getValue());

        return $now->gt($graceEnd);
    }

    /**
     * {@inheritDoc}
     */
    public function hasReachedMaxAttempts(UniqueTaskRecord $record): bool
    {
        return $record->attempts->getValue() >= $record->max_attempts->getValue();
    }

    /**
     * {@inheritDoc}
     */
    public function getValidationErrors(UniqueTaskRecord $record): StringTypedCollection
    {
        $errors = new StringTypedCollection;

        if (! $this->isValidTaskClass($record)) {
            $errors->add(sprintf(
                'Invalid task class: %s does not exist or does not extend AbstractUniqueTask',
                $record->fqcn->getValue()
            ));
        }

        $this->addStatusErrors($record, $errors);

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

    /**
     * Adds status-specific errors to the collection.
     *
     * @param  UniqueTaskRecord  $record  The task record
     * @param  StringTypedCollection  $errors  The error collection
     */
    private function addStatusErrors(UniqueTaskRecord $record, StringTypedCollection $errors): void
    {
        if ($record->status !== UniqueTaskStatus::PENDING) {
            $errors->add(sprintf(
                'Task is in %s state, not PENDING',
                strtoupper($record->status->value)
            ));
        }
    }

    /**
     * Validates that the task class exists and extends the correct abstract class.
     *
     * @param  UniqueTaskRecord  $record  The task record
     * @return bool True if the task class is valid
     */
    private function isValidTaskClass(UniqueTaskRecord $record): bool
    {
        $className = $record->fqcn->getValue();

        if (! class_exists($className)) {
            return false;
        }

        if (! is_subclass_of($className, AbstractUniqueTask::class)) {
            return false;
        }

        return true;
    }
}
