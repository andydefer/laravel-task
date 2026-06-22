<?php

declare(strict_types=1);

namespace AndyDefer\Task\Validators;

use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\Task\Abstract\AbstractUniqueTask;
use AndyDefer\Task\Contracts\Validators\UniqueTaskValidatorInterface;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\Records\UniqueTaskRecord;

final class UniqueTaskValidator implements UniqueTaskValidatorInterface
{
    public function canRun(UniqueTaskRecord $record): bool
    {
        // ✅ Vérifier que la classe de la tâche existe et est valide
        if (! $this->isValidTaskClass($record)) {
            return false;
        }

        return $this->isReadyToRun($record) && ! $this->hasReachedMaxAttempts($record) && ! $this->isExpired($record);
    }

    public function isReadyToRun(UniqueTaskRecord $record): bool
    {
        // ✅ Vérifier que la classe de la tâche existe et est valide
        if (! $this->isValidTaskClass($record)) {
            return false;
        }

        if ($record->status !== UniqueTaskStatus::PENDING) {
            return false;
        }

        $now = strtotime(date('c'));
        $scheduledAt = strtotime($record->scheduled_at->value);

        return $scheduledAt <= $now;
    }

    public function isExpired(UniqueTaskRecord $record): bool
    {
        // ✅ Vérifier que la classe de la tâche existe et est valide
        if (! $this->isValidTaskClass($record)) {
            return false;
        }

        $now = strtotime(date('c'));
        $scheduledAt = strtotime($record->scheduled_at->value);
        $graceEnd = $scheduledAt + $record->grace_period_seconds;

        return $now > $graceEnd;
    }

    public function hasReachedMaxAttempts(UniqueTaskRecord $record): bool
    {
        return $record->attempts->value >= $record->max_attempts->value;
    }

    public function getValidationErrors(UniqueTaskRecord $record): StringTypedCollection
    {
        $errors = new StringTypedCollection;

        // ✅ Validation de la classe
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

    /**
     * Vérifie que la classe de la tâche existe et étend AbstractUniqueTask.
     */
    private function isValidTaskClass(UniqueTaskRecord $record): bool
    {
        // ✅ Vérifier que la classe existe
        if (! class_exists($record->fqcn)) {
            return false;
        }

        // ✅ Vérifier que la classe étend AbstractUniqueTask
        if (! is_subclass_of($record->fqcn, AbstractUniqueTask::class)) {
            return false;
        }

        return true;
    }
}
