<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Services;

use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Collections\RecurringTaskRecordCollection;
use AndyDefer\Task\Records\ProcessResultRecord;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskRunResultRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use AndyDefer\Task\ValueObjects\RecurringTaskConfigVO;
use AndyDefer\Task\ValueObjects\RecurringTaskFqcnVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;

interface RecurringTaskServiceInterface
{
    // ==================== ENREGISTREMENT ====================

    public function register(
        RecurringTaskFqcnVO $fqcn,
        StrictDataObject $payload,
        RecurringTaskConfigVO $config
    ): TaskAliasVO;

    // ==================== EXÉCUTION ====================

    public function run(TaskAliasVO $alias): TaskRunResultRecord;

    public function process(LimitVO $limit = new LimitVO): ProcessResultRecord;

    // ==================== GESTION D'ÉTAT ====================

    /**
     * Met une tâche en pause.
     *
     * @return bool True si la pause a été effectuée, false sinon
     */
    public function pause(TaskAliasVO $alias): bool;

    /**
     * Reprend une tâche en pause.
     *
     * @return bool True si la reprise a été effectuée, false sinon
     */
    public function resume(TaskAliasVO $alias): bool;

    /**
     * Termine une tâche prématurément.
     *
     * @return bool True si la tâche a été terminée, false sinon
     */
    public function finish(TaskAliasVO $alias): bool;

    /**
     * Annule une tâche récurrente.
     *
     * @param  TaskAliasVO  $alias  Alias de la tâche
     * @param  DescriptionVO|null  $reason  Raison de l'annulation
     * @return bool True si la tâche a été annulée, false sinon
     */
    public function cancel(TaskAliasVO $alias, ?DescriptionVO $reason = null): bool;

    // ==================== MODIFICATION ====================

    /**
     * Avance la date de début d'une tâche.
     *
     * @return bool True si la date a été avancée, false sinon
     */
    public function advanceStartAt(TaskAliasVO $alias, Iso8601DateTimeVO $newStartAt): bool;

    /**
     * Repousse la date de début d'une tâche.
     *
     * @return bool True si la date a été repoussée, false sinon
     */
    public function postponeStartAt(TaskAliasVO $alias, Iso8601DateTimeVO $newStartAt): bool;

    /**
     * Modifie l'intervalle d'une tâche.
     *
     * @param  TaskAliasVO  $alias  Alias de la tâche
     * @param  DurationVO  $intervalSeconds  Nouvel intervalle en secondes
     * @return bool True si l'intervalle a été modifié, false sinon
     */
    public function changeInterval(TaskAliasVO $alias, DurationVO $intervalSeconds): bool;

    /**
     * Prolonge la date de fin d'une tâche.
     *
     * @return bool True si la date de fin a été prolongée, false sinon
     */
    public function extendEndAt(TaskAliasVO $alias, Iso8601DateTimeVO $newEndAt): bool;

    // ==================== RECHERCHE ====================

    public function find(TaskAliasVO $alias): ?RecurringTaskRecord;

    public function findWaiting(LimitVO $limit = new LimitVO): RecurringTaskRecordCollection;

    public function findPlaying(LimitVO $limit = new LimitVO): RecurringTaskRecordCollection;

    public function findPaused(LimitVO $limit = new LimitVO): RecurringTaskRecordCollection;

    public function findFinished(LimitVO $limit = new LimitVO): RecurringTaskRecordCollection;

    public function findCanceled(LimitVO $limit = new LimitVO): RecurringTaskRecordCollection;

    public function exists(TaskAliasVO $alias): bool;

    // ==================== SUPPRESSION ====================

    public function delete(TaskAliasVO $alias): bool;

    // ==================== COMPTAGE ====================

    public function count(): CounterVO;

    public function countWaiting(): CounterVO;

    public function countPlaying(): CounterVO;

    public function countPaused(): CounterVO;

    public function countFinished(): CounterVO;

    public function countCanceled(): CounterVO;
}
