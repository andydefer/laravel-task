<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Services;

use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Collections\UniqueTaskRecordCollection;
use AndyDefer\Task\Records\ProcessResultRecord;
use AndyDefer\Task\Records\TaskRunResultRecord;
use AndyDefer\Task\Records\UniqueTaskConfigRecord;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\UniqueTaskFqcnVO;

interface UniqueTaskServiceInterface
{
    // ==================== ENREGISTREMENT ====================

    /**
     * Enregistre une nouvelle tâche unique.
     *
     * @param  UniqueTaskFqcnVO  $fqcn  Classe de la tâche (doit étendre AbstractUniqueTask)
     * @param  StrictDataObject  $payload  Données de la tâche
     * @param  UniqueTaskConfigRecord  $config  Configuration de la tâche
     * @return TaskAliasVO Alias de la tâche créée
     */
    public function register(
        UniqueTaskFqcnVO $fqcn,
        StrictDataObject $payload,
        UniqueTaskConfigRecord $config
    ): TaskAliasVO;

    // ==================== EXÉCUTION ====================

    /**
     * Exécute une tâche unique spécifique.
     *
     * @param  TaskAliasVO  $alias  Alias de la tâche à exécuter
     * @return TaskRunResultRecord Résultat de l'exécution
     */
    public function run(TaskAliasVO $alias): TaskRunResultRecord;

    /**
     * Exécute toutes les tâches uniques prêtes (scheduled_at <= now).
     *
     * @param  LimitVO  $limit  Nombre maximum de tâches à exécuter
     * @return ProcessResultRecord Résultats de l'exécution
     */
    public function process(LimitVO $limit = new LimitVO): ProcessResultRecord;

    // ==================== GESTION DES TÂCHES ====================

    /**
     * Annule une tâche en attente.
     *
     * @param  TaskAliasVO  $alias  Alias de la tâche
     * @param  DescriptionVO  $reason  Raison de l'annulation
     *
     * @throws \RuntimeException Si la tâche n'existe pas ou n'est pas en PENDING
     */
    public function cancel(TaskAliasVO $alias, ?DescriptionVO $reason = null): bool;

    /**
     * Repousse la date d'exécution d'une tâche en attente.
     *
     * @param  TaskAliasVO  $alias  Alias de la tâche
     * @param  Iso8601DateTimeVO  $newScheduledAt  Nouvelle date planifiée
     *
     * @throws \RuntimeException Si la tâche n'existe pas ou n'est pas en PENDING
     */
    public function reschedule(TaskAliasVO $alias, Iso8601DateTimeVO $newScheduledAt): bool;

    /**
     * Prolonge la période de grâce d'une tâche en attente.
     *
     * @param  TaskAliasVO  $alias  Alias de la tâche
     * @param  DurationVO  $extraSeconds  Secondes supplémentaires à ajouter
     *
     * @throws \RuntimeException Si la tâche n'existe pas ou n'est pas en PENDING
     * @throws \InvalidArgumentException Si $extraSeconds est négatif ou nul
     */
    public function extendGracePeriod(TaskAliasVO $alias, DurationVO $extraSeconds): bool;

    // ==================== RECHERCHE ====================

    /**
     * Trouve une tâche par son alias.
     *
     * @param  TaskAliasVO  $alias  Alias de la tâche
     * @return UniqueTaskRecord|null Le record de la tâche ou null si non trouvée
     */
    public function find(TaskAliasVO $alias): ?UniqueTaskRecord;

    /**
     * Récupère toutes les tâches en attente.
     *
     * @param  LimitVO  $limit  Nombre maximum de tâches
     */
    public function findPending(LimitVO $limit = new LimitVO): UniqueTaskRecordCollection;

    /**
     * Récupère toutes les tâches terminées avec succès.
     *
     * @param  LimitVO  $limit  Nombre maximum de tâches
     */
    public function findCompleted(LimitVO $limit = new LimitVO): UniqueTaskRecordCollection;

    /**
     * Récupère toutes les tâches en échec.
     *
     * @param  LimitVO  $limit  Nombre maximum de tâches
     */
    public function findFailed(LimitVO $limit = new LimitVO): UniqueTaskRecordCollection;

    /**
     * Récupère toutes les tâches annulées.
     *
     * @param  LimitVO  $limit  Nombre maximum de tâches
     */
    public function findCanceled(LimitVO $limit = new LimitVO): UniqueTaskRecordCollection;

    /**
     * Vérifie si une tâche existe.
     *
     * @param  TaskAliasVO  $alias  Alias de la tâche
     * @return bool True si la tâche existe
     */
    public function exists(TaskAliasVO $alias): bool;

    // ==================== SUPPRESSION ====================

    /**
     * Supprime définitivement une tâche.
     *
     * @param  TaskAliasVO  $alias  Alias de la tâche
     *
     * @throws \RuntimeException Si la tâche n'existe pas
     */
    public function delete(TaskAliasVO $alias): bool;

    // ==================== COMPTAGE ====================

    /** Compte le nombre total de tâches uniques. */
    public function count(): CounterVO;

    /** Compte le nombre de tâches en attente. */
    public function countPending(): CounterVO;

    /** Compte le nombre de tâches terminées avec succès. */
    public function countCompleted(): CounterVO;

    /** Compte le nombre de tâches en échec. */
    public function countFailed(): CounterVO;

    /** Compte le nombre de tâches annulées. */
    public function countCanceled(): CounterVO;
}
