<?php

declare(strict_types=1);

namespace AndyDefer\Task\Validators;

use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\Task\Abstract\AbstractRecurringTask;
use AndyDefer\Task\Contracts\Validators\RecurringTaskValidatorInterface;
use AndyDefer\Task\Enums\RecurringTaskStatus;
use AndyDefer\Task\Records\RecurringTaskRecord;
use Illuminate\Support\Carbon;

/**
 * Validator for recurring tasks.
 *
 * Provides validation methods to determine if a recurring task can run,
 * is ready, expired, should move to finished, or should run again.
 */
final class RecurringTaskValidator implements RecurringTaskValidatorInterface
{
    /**
     * {@inheritDoc}
     */
    public function canRun(RecurringTaskRecord $record): bool
    {
        if (! $this->isValidTaskClass($record)) {
            return false;
        }

        if ($record->status !== RecurringTaskStatus::PLAYING) {
            return false;
        }

        if ($this->isExpired($record)) {
            return false;
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function isReadyToRun(RecurringTaskRecord $record): bool
    {
        if (! $this->isValidTaskClass($record)) {
            return false;
        }

        if ($record->status !== RecurringTaskStatus::WAITING) {
            return false;
        }

        $now = Carbon::now();

        if ($record->start_at === null) {
            return true;
        }

        $startAt = Carbon::parse($record->start_at->getValue());

        return $startAt->lte($now);
    }

    /**
     * {@inheritDoc}
     */
    public function isExpired(RecurringTaskRecord $record): bool
    {
        if (! $this->isValidTaskClass($record)) {
            return false;
        }

        if ($record->end_at === null) {
            return false;
        }

        $now = Carbon::now();
        $endAt = Carbon::parse($record->end_at->getValue());

        return $endAt->lt($now);
    }

    /**
     * {@inheritDoc}
     */
    public function shouldMoveToFinished(RecurringTaskRecord $record): bool
    {
        return $this->isExpired($record);
    }

    /**
     * {@inheritDoc}
     */
    public function shouldRunAgain(RecurringTaskRecord $record): bool
    {
        if (! $this->isValidTaskClass($record)) {
            return false;
        }

        if ($record->status !== RecurringTaskStatus::PLAYING) {
            return false;
        }

        if ($this->isExpired($record)) {
            return false;
        }

        if ($record->last_run_at === null) {
            return true;
        }

        $now = Carbon::now();
        $lastRunAt = Carbon::parse($record->last_run_at->getValue());
        $interval = $record->interval_seconds->getValue();

        return $lastRunAt->addSeconds($interval)->lte($now);
    }

    /**
     * {@inheritDoc}
     */
    public function getValidationErrors(RecurringTaskRecord $record): StringTypedCollection
    {
        $errors = new StringTypedCollection;

        if (! $this->isValidTaskClass($record)) {
            $errors->add(sprintf(
                'Invalid task class: %s',
                $record->fqcn->getValue()
            ));
        }

        $this->addStatusErrors($record, $errors);

        if ($this->isExpired($record)) {
            $errors->add('Task has expired (end_at reached)');
        }

        if ($record->status === RecurringTaskStatus::WAITING && ! $this->isReadyToRun($record)) {
            $errors->add('Task is not ready to run (start_at not reached)');
        }

        return $errors;
    }

    /**
     * Adds status-specific errors to the collection.
     *
     * @param  RecurringTaskRecord  $record  The task record
     * @param  StringTypedCollection  $errors  The error collection
     */
    private function addStatusErrors(RecurringTaskRecord $record, StringTypedCollection $errors): void
    {
        $message = match ($record->status) {
            RecurringTaskStatus::WAITING => 'Task is in WAITING state, not PLAYING',
            RecurringTaskStatus::PAUSED => 'Task is in PAUSED state',
            RecurringTaskStatus::FINISHED => 'Task is already FINISHED',
            RecurringTaskStatus::CANCELED => 'Task is CANCELED',
            default => $record->status !== RecurringTaskStatus::PLAYING
                ? sprintf('Task is in %s state, not PLAYING', strtoupper($record->status->value))
                : null,
        };

        if ($message !== null) {
            $errors->add($message);
        }
    }

    /**
     * Validates that the task class exists and extends the correct abstract class.
     *
     * @param  RecurringTaskRecord  $record  The task record
     * @return bool True if the task class is valid
     */
    private function isValidTaskClass(RecurringTaskRecord $record): bool
    {
        $className = $record->fqcn->getValue();

        if (! class_exists($className)) {
            return false;
        }

        if (! is_subclass_of($className, AbstractRecurringTask::class)) {
            return false;
        }

        return true;
    }
}
