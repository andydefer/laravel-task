<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Services;

use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Contracts\Configs\RecurringTaskConfigInterface;
use AndyDefer\Task\Records\ProcessResultRecord;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

interface RecurringTaskServiceInterface
{
    // ==================== ENREGISTREMENT ====================

    /**
     * Enregistre une nouvelle tâche récurrente.
     *
     * @param  string  $taskClass  Classe de la tâche (doit étendre RecurringTask)
     * @param  StrictDataObject  $payload  Données de la tâche
     * @param  RecurringTaskConfigInterface  $config  Configuration
     * @return TaskSignatureVO Signature de la tâche créée
     *
     * @throws \InvalidArgumentException Si la classe est invalide
     * @throws \RuntimeException Si une tâche avec le même alias existe déjà
     */
    public function register(
        string $taskClass,
        StrictDataObject $payload,
        RecurringTaskConfigInterface $config
    ): TaskSignatureVO;

    // ==================== EXÉCUTION ====================

    /**
     * Exécute une tâche récurrente.
     *
     * @param  TaskSignatureVO  $alias  Alias de la tâche
     * @return bool Succès de l'exécution
     */
    public function run(TaskSignatureVO $alias): bool;

    /**
     * Exécute toutes les tâches récurrentes prêtes.
     *
     * @param  int|null  $limit  Nombre maximum de tâches à exécuter
     * @return ProcessResultRecord Résultats de l'exécution
     */
    public function process(?int $limit = null): ProcessResultRecord;

    // ==================== GESTION D'ÉTAT ====================

    /**
     * Met une tâche en pause.
     *
     * @param  TaskSignatureVO  $alias  Alias de la tâche
     *
     * @throws \RuntimeException Si la tâche n'existe pas ou n'est pas en PLAYING
     */
    public function pause(TaskSignatureVO $alias): void;

    /**
     * Reprend une tâche en pause.
     *
     * @param  TaskSignatureVO  $alias  Alias de la tâche
     *
     * @throws \RuntimeException Si la tâche n'existe pas ou n'est pas en PAUSED
     */
    public function resume(TaskSignatureVO $alias): void;

    /**
     * Termine une tâche prématurément.
     *
     * @param  TaskSignatureVO  $alias  Alias de la tâche
     *
     * @throws \RuntimeException Si la tâche n'existe pas
     */
    public function finish(TaskSignatureVO $alias): void;

    /**
     * Annule une tâche récurrente.
     * La tâche est marquée comme CANCELED avec le message d'annulation.
     *
     * @param  TaskSignatureVO  $alias  Alias de la tâche
     * @param  string|null  $reason  Raison de l'annulation
     *
     * @throws \RuntimeException Si la tâche n'existe pas
     */
    public function cancel(TaskSignatureVO $alias, ?string $reason = null): void;

    // ==================== MODIFICATION ====================

    /**
     * Avance la date de début d'une tâche.
     *
     * @param  TaskSignatureVO  $alias  Alias de la tâche
     * @param  Iso8601DateTimeVO  $newStartAt  Nouvelle date de début
     *
     * @throws \RuntimeException Si la tâche n'existe pas
     */
    public function advanceStartAt(TaskSignatureVO $alias, Iso8601DateTimeVO $newStartAt): void;

    /**
     * Repousse la date de début d'une tâche.
     *
     * @param  TaskSignatureVO  $alias  Alias de la tâche
     * @param  Iso8601DateTimeVO  $newStartAt  Nouvelle date de début
     *
     * @throws \RuntimeException Si la tâche n'existe pas
     */
    public function postponeStartAt(TaskSignatureVO $alias, Iso8601DateTimeVO $newStartAt): void;

    /**
     * Modifie l'intervalle d'une tâche.
     *
     * @param  TaskSignatureVO  $alias  Alias de la tâche
     * @param  int  $intervalSeconds  Nouvel intervalle en secondes
     *
     * @throws \RuntimeException Si la tâche n'existe pas
     */
    public function changeInterval(TaskSignatureVO $alias, int $intervalSeconds): void;

    /**
     * Prolonge la date de fin d'une tâche.
     *
     * @param  TaskSignatureVO  $alias  Alias de la tâche
     * @param  Iso8601DateTimeVO  $newEndAt  Nouvelle date de fin
     *
     * @throws \RuntimeException Si la tâche n'existe pas
     */
    public function extendEndAt(TaskSignatureVO $alias, Iso8601DateTimeVO $newEndAt): void;

    // ==================== RECHERCHE ====================

    /**
     * Trouve une tâche par son alias.
     *
     * @param  TaskSignatureVO  $alias  Alias de la tâche
     */
    public function find(TaskSignatureVO $alias): ?RecurringTaskRecord;

    /**
     * Récupère toutes les tâches en attente.
     *
     * @param  int|null  $limit  Nombre maximum de tâches
     * @return array<RecurringTaskRecord>
     */
    public function findWaiting(?int $limit = null): array;

    /**
     * Récupère toutes les tâches en cours d'exécution.
     *
     * @param  int|null  $limit  Nombre maximum de tâches
     * @return array<RecurringTaskRecord>
     */
    public function findPlaying(?int $limit = null): array;

    /**
     * Récupère toutes les tâches en pause.
     *
     * @param  int|null  $limit  Nombre maximum de tâches
     * @return array<RecurringTaskRecord>
     */
    public function findPaused(?int $limit = null): array;

    /**
     * Récupère toutes les tâches terminées.
     *
     * @param  int|null  $limit  Nombre maximum de tâches
     * @return array<RecurringTaskRecord>
     */
    public function findFinished(?int $limit = null): array;

    /**
     * Récupère toutes les tâches annulées.
     *
     * @param  int|null  $limit  Nombre maximum de tâches
     * @return array<RecurringTaskRecord>
     */
    public function findCanceled(?int $limit = null): array;

    /**
     * Vérifie si une tâche existe.
     *
     * @param  TaskSignatureVO  $alias  Alias de la tâche
     */
    public function exists(TaskSignatureVO $alias): bool;

    // ==================== SUPPRESSION ====================

    /**
     * Supprime une tâche récurrente.
     *
     * @param  TaskSignatureVO  $alias  Alias de la tâche
     *
     * @throws \RuntimeException Si la tâche n'existe pas
     */
    public function delete(TaskSignatureVO $alias): void;

    // ==================== COMPTAGE ====================

    /**
     * Compte le nombre total de tâches récurrentes.
     */
    public function count(): int;

    /**
     * Compte le nombre de tâches en attente.
     */
    public function countWaiting(): int;

    /**
     * Compte le nombre de tâches en cours d'exécution.
     */
    public function countPlaying(): int;

    /**
     * Compte le nombre de tâches en pause.
     */
    public function countPaused(): int;

    /**
     * Compte le nombre de tâches terminées.
     */
    public function countFinished(): int;

    /**
     * Compte le nombre de tâches annulées.
     */
    public function countCanceled(): int;
}
