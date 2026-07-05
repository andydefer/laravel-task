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

        $now = Carbon::now();

        // ✅ Si start_at est null, considérer que la tâche commence maintenant
        if ($record->start_at === null) {
            return true;
        }

        $startAt = Carbon::parse($record->start_at->getValue());

        return $startAt->lte($now);
    }

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
        $lastRunAt = Carbon::parse($record->last_run_at->getValue());
        $interval = $record->interval_seconds->getValue();

        return $lastRunAt->addSeconds($interval)->lte($now);
    }

    public function getValidationErrors(RecurringTaskRecord $record): StringTypedCollection
    {
        $errors = new StringTypedCollection;

        if (! $this->isValidTaskClass($record)) {
            $errors->add('Invalid task class: '.$record->fqcn->getValue());
        }

        // ✅ Messages spécifiques par statut
        if ($record->status === RecurringTaskStatus::WAITING) {
            $errors->add('Task is in WAITING state, not PLAYING');
        } elseif ($record->status === RecurringTaskStatus::PAUSED) {
            $errors->add('Task is in PAUSED state');
        } elseif ($record->status === RecurringTaskStatus::FINISHED) {
            $errors->add('Task is already FINISHED');
        } elseif ($record->status === RecurringTaskStatus::CANCELED) {
            $errors->add('Task is CANCELED');
        } elseif ($record->status !== RecurringTaskStatus::PLAYING) {
            $errors->add('Task is in '.strtoupper($record->status->value).' state, not PLAYING');
        }

        if ($this->isExpired($record)) {
            $errors->add('Task has expired (end_at reached)');
        }

        if ($record->status === RecurringTaskStatus::WAITING && ! $this->isReadyToRun($record)) {
            $errors->add('Task is not ready to run (start_at not reached)');
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
