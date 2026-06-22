<?php

declare(strict_types=1);

namespace AndyDefer\Task\Validators;

use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\Task\Abstract\AbstractRecurringTask;
use AndyDefer\Task\Contracts\Validators\RecurringTaskValidatorInterface;
use AndyDefer\Task\Enums\RecurringTaskStatus;
use AndyDefer\Task\Records\RecurringTaskRecord;

final class RecurringTaskValidator implements RecurringTaskValidatorInterface
{
    public function canRun(RecurringTaskRecord $record): bool
    {
        // ✅ Vérifier que la classe de la tâche existe et est valide
        if (! $this->isValidTaskClass($record)) {
            return false;
        }

        // ✅ UNIQUEMENT si la tâche est en PLAYING
        if ($record->status !== RecurringTaskStatus::PLAYING) {
            return false;
        }

        // ✅ Vérifier que end_at n'est pas dépassé
        if ($this->isExpired($record)) {
            return false;
        }

        return true;
    }

    public function isReadyToRun(RecurringTaskRecord $record): bool
    {
        // ✅ Vérifier que la classe de la tâche existe et est valide
        if (! $this->isValidTaskClass($record)) {
            return false;
        }

        // ✅ Vérifier que la tâche est en WAITING
        if ($record->status !== RecurringTaskStatus::WAITING) {
            return false;
        }

        $now = strtotime(date('c'));

        // ✅ start_at doit être atteint
        if ($record->start_at === null) {
            return false;
        }

        $startAt = strtotime($record->start_at->value);

        return $startAt <= $now;
    }

    public function isExpired(RecurringTaskRecord $record): bool
    {
        if ($record->end_at === null) {
            return false;
        }

        $now = strtotime(date('c'));
        $endAt = strtotime($record->end_at->value);

        return $now > $endAt;
    }

    public function shouldMoveToFinished(RecurringTaskRecord $record): bool
    {
        return $this->isExpired($record);
    }

    /**
     * Vérifie si une tâche en PLAYING doit être exécutée à nouveau
     * selon son intervalle.
     */
    public function shouldRunAgain(RecurringTaskRecord $record): bool
    {
        // ✅ Vérifier que la classe de la tâche existe et est valide
        if (! $this->isValidTaskClass($record)) {
            return false;
        }

        // ✅ Si la tâche n'est pas en PLAYING, elle ne doit pas être ré-exécutée
        if ($record->status !== RecurringTaskStatus::PLAYING) {
            return false;
        }

        // ✅ Si elle est expirée, elle ne doit pas être ré-exécutée
        if ($this->isExpired($record)) {
            return false;
        }

        // ✅ Si elle n'a jamais été exécutée, on l'exécute
        if ($record->last_run_at === null) {
            return true;
        }

        // ✅ Vérifier si l'intervalle est dépassé
        $now = strtotime(date('c'));
        $lastRun = strtotime($record->last_run_at->value);
        $interval = $record->interval_seconds->value;

        return ($now - $lastRun) >= $interval;
    }

    public function getValidationErrors(RecurringTaskRecord $record): StringTypedCollection
    {
        $errors = new StringTypedCollection;

        // ✅ Validation de la classe
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
            $now = strtotime(date('c'));
            $lastRun = strtotime($record->last_run_at->value);
            $interval = $record->interval_seconds->value;

            if (($now - $lastRun) < $interval) {
                $errors->add('Interval not reached (next run in '.($interval - ($now - $lastRun)).' seconds)');
            }
        }

        return $errors;
    }

    /**
     * Vérifie que la classe de la tâche existe et étend AbstractRecurringTask.
     */
    private function isValidTaskClass(RecurringTaskRecord $record): bool
    {
        // ✅ Vérifier que la classe existe
        if (! class_exists($record->fqcn)) {
            return false;
        }

        // ✅ Vérifier que la classe étend AbstractRecurringTask
        if (! is_subclass_of($record->fqcn, AbstractRecurringTask::class)) {
            return false;
        }

        return true;
    }
}
