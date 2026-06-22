<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Repositories;

use AndyDefer\Repository\AbstractRepositoryInterface;
use AndyDefer\Task\Models\UniqueTask;
use AndyDefer\Task\Records\UniqueTaskRecord;
use Illuminate\Support\Collection;

interface UniqueTaskRepositoryInterface extends AbstractRepositoryInterface
{
    // ==================== FINDERS ====================

    /**
     * Récupère toutes les tâches en statut PENDING.
     *
     * @param  int|null  $limit  Nombre maximum de tâches à retourner
     * @return Collection<int, UniqueTask>
     */
    public function findPending(?int $limit = null): Collection;

    /**
     * Récupère toutes les tâches en statut COMPLETED.
     *
     * @param  int|null  $limit  Nombre maximum de tâches à retourner
     * @return Collection<int, UniqueTask>
     */
    public function findCompleted(?int $limit = null): Collection;

    /**
     * Récupère toutes les tâches en statut FAILED.
     *
     * @param  int|null  $limit  Nombre maximum de tâches à retourner
     * @return Collection<int, UniqueTask>
     */
    public function findFailed(?int $limit = null): Collection;

    /**
     * Récupère toutes les tâches en statut CANCELED.
     *
     * @param  int|null  $limit  Nombre maximum de tâches à retourner
     * @return Collection<int, UniqueTask>
     */
    public function findCanceled(?int $limit = null): Collection;

    /**
     * Récupère les tâches prêtes à être exécutées (PENDING et scheduled_at <= now).
     *
     * @param  string  $now  Date au format ISO 8601
     * @param  int|null  $limit  Nombre maximum de tâches à retourner
     * @return Collection<int, UniqueTask>
     */
    public function findReadyToRun(string $now, ?int $limit = null): Collection;

    /**
     * Récupère les tâches expirées (PENDING et scheduled_at + grace_period < now).
     *
     * @param  string  $now  Date au format ISO 8601
     * @param  int|null  $limit  Nombre maximum de tâches à retourner
     * @return Collection<int, UniqueTask>
     */
    public function findExpired(string $now, ?int $limit = null): Collection;

    /**
     * Trouve une tâche par son UUID.
     *
     * @param  string  $id  UUID de la tâche
     */
    public function findById(string $id): ?UniqueTask;

    // ==================== MOVES ====================

    /**
     * Met à jour le nombre de tentatives d'une tâche.
     *
     * @param  int  $newAttempts  Nouveau nombre de tentatives
     *
     * @throws \RuntimeException Si la tâche n'existe pas
     */
    public function updateAttempts(UniqueTaskRecord $task, int $newAttempts): void;

    /**
     * Ajoute une entrée de debug pour une tâche.
     */
    public function addDebug(UniqueTaskRecord $task, string $status, string $info): void;

    /**
     * Déplace une tâche de PENDING vers COMPLETED.
     *
     * @throws \RuntimeException Si la tâche n'existe pas
     */
    public function moveToCompleted(UniqueTaskRecord $task): void;

    /**
     * Déplace une tâche de PENDING vers FAILED.
     *
     * @throws \RuntimeException Si la tâche n'existe pas
     */
    public function moveToFailed(UniqueTaskRecord $task): void;

    /**
     * Déplace une tâche de PENDING vers CANCELED.
     *
     * @throws \RuntimeException Si la tâche n'existe pas
     */
    public function moveToCanceled(UniqueTaskRecord $task): void;

    // ==================== COUNTS ====================

    public function countPending(): int;

    public function countCompleted(): int;

    public function countFailed(): int;

    public function countCanceled(): int;
}
