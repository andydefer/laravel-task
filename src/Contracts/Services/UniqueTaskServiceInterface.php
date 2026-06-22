<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Services;

use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Contracts\Configs\UniqueTaskConfigInterface;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;

interface UniqueTaskServiceInterface
{
    // ==================== ENREGISTREMENT ====================

    /**
     * Enregistre une nouvelle tâche unique.
     *
     * @param  string  $taskClass  Classe de la tâche (doit étendre UniqueTask)
     * @param  StrictDataObject  $payload  Données de la tâche
     * @param  UniqueTaskConfigInterface|null  $config  Configuration personnalisée
     * @return TaskIdVO ID de la tâche créée
     *
     * @throws \InvalidArgumentException Si la classe est invalide
     */
    public function register(
        string $taskClass,
        StrictDataObject $payload,
        ?UniqueTaskConfigInterface $config = null
    ): TaskIdVO;

    // ==================== EXÉCUTION ====================

    /**
     * Exécute une tâche unique.
     *
     * @param  TaskIdVO  $taskId  ID de la tâche
     * @return bool Succès de l'exécution
     */
    public function run(TaskIdVO $taskId): bool;

    /**
     * Exécute toutes les tâches prêtes.
     *
     * @param  int|null  $limit  Nombre maximum de tâches à exécuter
     * @return array{success: int, failed: int} Résultats de l'exécution
     */
    public function process(?int $limit = null): array;

    // ==================== GESTION DES TÂCHES ====================

    /**
     * Annule une tâche en attente.
     * La tâche est marquée comme CANCELED avec le message d'annulation.
     *
     * @param  TaskIdVO  $taskId  ID de la tâche
     * @param  string|null  $reason  Raison de l'annulation
     *
     * @throws \RuntimeException Si la tâche n'existe pas ou est déjà terminée
     */
    public function cancel(TaskIdVO $taskId, ?string $reason = null): void;

    /**
     * Repousse la date d'exécution d'une tâche en attente.
     *
     * @param  TaskIdVO  $taskId  ID de la tâche
     * @param  Iso8601DateTimeVO  $newScheduledAt  Nouvelle date planifiée
     *
     * @throws \RuntimeException Si la tâche n'existe pas ou est déjà terminée
     */
    public function reschedule(TaskIdVO $taskId, Iso8601DateTimeVO $newScheduledAt): void;

    /**
     * Prolonge la période de grâce d'une tâche en attente.
     *
     * @param  TaskIdVO  $taskId  ID de la tâche
     * @param  int  $extraSeconds  Secondes supplémentaires à ajouter à la période de grâce
     *
     * @throws \RuntimeException Si la tâche n'existe pas ou est déjà terminée
     */
    public function extendGracePeriod(TaskIdVO $taskId, int $extraSeconds): void;

    // ==================== RECHERCHE ====================

    /**
     * Trouve une tâche par son ID.
     *
     * @param  TaskIdVO  $taskId  ID de la tâche
     */
    public function find(TaskIdVO $taskId): ?UniqueTaskRecord;

    /**
     * Récupère toutes les tâches en attente.
     *
     * @param  int|null  $limit  Nombre maximum de tâches
     * @return array<UniqueTaskRecord>
     */
    public function findPending(?int $limit = null): array;

    /**
     * Récupère toutes les tâches terminées avec succès.
     *
     * @param  int|null  $limit  Nombre maximum de tâches
     * @return array<UniqueTaskRecord>
     */
    public function findCompleted(?int $limit = null): array;

    /**
     * Récupère toutes les tâches en échec.
     *
     * @param  int|null  $limit  Nombre maximum de tâches
     * @return array<UniqueTaskRecord>
     */
    public function findFailed(?int $limit = null): array;

    /**
     * Récupère toutes les tâches annulées.
     *
     * @param  int|null  $limit  Nombre maximum de tâches
     * @return array<UniqueTaskRecord>
     */
    public function findCanceled(?int $limit = null): array;

    /**
     * Vérifie si une tâche existe.
     *
     * @param  TaskIdVO  $taskId  ID de la tâche
     */
    public function exists(TaskIdVO $taskId): bool;

    // ==================== SUPPRESSION ====================

    /**
     * Supprime une tâche.
     *
     * @param  TaskIdVO  $taskId  ID de la tâche
     *
     * @throws \RuntimeException Si la tâche n'existe pas
     */
    public function delete(TaskIdVO $taskId): void;

    // ==================== COMPTAGE ====================

    /**
     * Compte le nombre total de tâches.
     */
    public function count(): int;

    /**
     * Compte le nombre de tâches en attente.
     */
    public function countPending(): int;

    /**
     * Compte le nombre de tâches terminées avec succès.
     */
    public function countCompleted(): int;

    /**
     * Compte le nombre de tâches en échec.
     */
    public function countFailed(): int;

    /**
     * Compte le nombre de tâches annulées.
     */
    public function countCanceled(): int;
}
