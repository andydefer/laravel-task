<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Repositories;

use AndyDefer\Task\Collections\UniqueTaskRecordCollection;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

interface UniqueTaskRepositoryInterface
{
    // ==================== CRUD ====================

    /**
     * Sauvegarde une tâche en AJOUTANT une ligne au fichier (versionning).
     * La dernière ligne est toujours la version la plus récente.
     */
    public function save(UniqueTaskRecord $task): void;

    /**
     * Récupère la dernière version d'une tâche par son ID.
     */
    public function find(TaskIdVO $id): ?UniqueTaskRecord;

    /**
     * Récupère toutes les versions d'une tâche par son ID.
     */
    public function findAllVersions(TaskIdVO $id): UniqueTaskRecordCollection;

    /**
     * Récupère toutes les tâches en attente par alias.
     */
    public function findByAlias(TaskSignatureVO $alias): UniqueTaskRecordCollection;

    /**
     * Supprime une tâche (toutes ses versions).
     */
    public function delete(TaskIdVO $id): void;

    // ==================== FINDERS ====================

    /**
     * Récupère la dernière version de toutes les tâches.
     */
    public function findAll(?int $limit = null): UniqueTaskRecordCollection;

    /**
     * Récupère la dernière version des tâches en attente.
     */
    public function findPending(?int $limit = null): UniqueTaskRecordCollection;

    /**
     * Récupère la dernière version des tâches terminées avec succès.
     */
    public function findCompleted(?int $limit = null): UniqueTaskRecordCollection;

    /**
     * Récupère la dernière version des tâches ayant échoué.
     */
    public function findFailed(?int $limit = null): UniqueTaskRecordCollection;

    /**
     * Récupère les tâches prêtes à être exécutées.
     */
    public function findReadyToRun(string $now): UniqueTaskRecordCollection;

    /**
     * Récupère les tâches expirées.
     */
    public function findExpired(string $now): UniqueTaskRecordCollection;

    // ==================== MOVES ====================

    /**
     * Déplace une tâche de PENDING vers COMPLETED.
     */
    public function moveToCompleted(TaskIdVO $id, UniqueTaskRecord $task): void;

    /**
     * Déplace une tâche de PENDING vers FAILED.
     */
    public function moveToFailed(TaskIdVO $id, UniqueTaskRecord $task): void;

    // ==================== COUNTS ====================

    public function count(): int;

    public function countPending(): int;

    public function countCompleted(): int;

    public function countFailed(): int;
}
