<?php

declare(strict_types=1);

namespace AndyDefer\Task\Validators;

use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\Task\Abstract\AbstractRecurringTask;
use AndyDefer\Task\Contracts\Validators\RecurringTaskValidatorInterface;
use AndyDefer\Task\Enums\RecurringTaskStatus;
use AndyDefer\Task\Records\RecurringTaskRecord;
use Illuminate\Support\Carbon;

final class RecurringTaskValidator implements RecurringTaskValidatorInterface
{
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

    public function isReadyToRun(RecurringTaskRecord $record): bool
    {
        if (! $this->isValidTaskClass($record)) {
            return false;
        }

        if ($record->status !== RecurringTaskStatus::WAITING) {
            return false;
        }

        if ($record->start_at === null) {
            return false;
        }

        $now = Carbon::now();
        $startAt = Carbon::parse($record->start_at->value);

        return $startAt->lte($now);
    }

    public function isExpired(RecurringTaskRecord $record): bool
    {
        if ($record->end_at === null) {
            return false;
        }

        $now = Carbon::now();
        $endAt = Carbon::parse($record->end_at->value);

        return $now->gt($endAt);
    }

    public function shouldMoveToFinished(RecurringTaskRecord $record): bool
    {
        return $this->isExpired($record);
    }

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
        $lastRun = Carbon::parse($record->last_run_at->value);
        $interval = $record->interval_seconds->value;

        return $lastRun->diffInSeconds($now) >= $interval;
    }

    public function getValidationErrors(RecurringTaskRecord $record): StringTypedCollection
    {
        $errors = new StringTypedCollection;

        if (! $this->isValidTaskClass($record)) {
            $errors->add('Invalid task class: '.$record->fqcn.' does not exist or does not extend AbstractRecurringTask');
        }

        if ($record->status === RecurringTaskStatus::WAITING) {
            $errors->add('Task is in WAITING state, not PLAYING');
        }

        if ($record->status === RecurringTaskStatus::PAUSED) {
            $errors->add('Task is in PAUSED state');
        }

        if ($record->status === RecurringTaskStatus::FINISHED) {
            $errors->add('Task is already FINISHED');
        }

        if ($record->status !== RecurringTaskStatus::PLAYING && $record->status !== RecurringTaskStatus::WAITING) {
            $errors->add('Task is not in PLAYING or WAITING state');
        }

        if ($this->isExpired($record)) {
            $errors->add('Task has expired (end_at reached)');
        }

        if (! $this->isReadyToRun($record) && $record->status === RecurringTaskStatus::WAITING) {
            $errors->add('Task is not ready to run (start_at not reached)');
        }

        if ($record->status === RecurringTaskStatus::PLAYING && $record->last_run_at !== null) {
            $now = Carbon::now();
            $lastRun = Carbon::parse($record->last_run_at->value);
            $interval = $record->interval_seconds->value;
            $diff = $lastRun->diffInSeconds($now);

            if ($diff < $interval) {
                $errors->add('Interval not reached (next run in '.($interval - $diff).' seconds)');
            }
        }

        return $errors;
    }

    private function isValidTaskClass(RecurringTaskRecord $record): bool
    {
        if (! class_exists($record->fqcn->getValue())) {
            return false;
        }

        if (! is_subclass_of($record->fqcn->getValue(), AbstractRecurringTask::class)) {
            return false;
        }

        return true;
    }
}
