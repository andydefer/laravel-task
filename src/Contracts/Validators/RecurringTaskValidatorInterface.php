<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Validators;

use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\Task\Records\RecurringTaskRecord;

interface RecurringTaskValidatorInterface
{
    public function canRun(RecurringTaskRecord $record): bool;

    public function isExpired(RecurringTaskRecord $record): bool;

    public function isReadyToRun(RecurringTaskRecord $record): bool;

    public function shouldMoveToFinished(RecurringTaskRecord $record): bool;

    /**
     * Vérifie si une tâche en PLAYING doit être exécutée à nouveau
     * selon son intervalle.
     */
    public function shouldRunAgain(RecurringTaskRecord $record): bool;

    public function getValidationErrors(RecurringTaskRecord $record): StringTypedCollection;
}
