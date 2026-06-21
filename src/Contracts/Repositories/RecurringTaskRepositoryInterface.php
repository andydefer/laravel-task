<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Repositories;

use AndyDefer\Task\Collections\RecurringTaskRecordCollection;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

interface RecurringTaskRepositoryInterface
{
    // ==================== CRUD ====================

    /**
     * Sauvegarde une tâche récurrente en AJOUTANT une ligne au fichier (versionning).
     * La dernière ligne est toujours la version la plus récente.
     */
    public function save(RecurringTaskRecord $task): void;

    /**
     * Récupère la dernière version d'une tâche récurrente par son alias.
     */
    public function find(TaskSignatureVO $alias): ?RecurringTaskRecord;

    /**
     * Récupère toutes les versions d'une tâche récurrente par son alias.
     */
    public function findAllVersions(TaskSignatureVO $alias): RecurringTaskRecordCollection;

    /**
     * Supprime une tâche récurrente (toutes ses versions).
     */
    public function delete(TaskSignatureVO $alias): void;

    // ==================== FINDERS ====================

    /**
     * Récupère la dernière version de toutes les tâches récurrentes.
     */
    public function findAll(?int $limit = null): RecurringTaskRecordCollection;

    /**
     * Récupère la dernière version des tâches récurrentes en attente.
     */
    public function findPending(?int $limit = null): RecurringTaskRecordCollection;

    /**
     * Récupère la dernière version des tâches récurrentes en cours.
     */
    public function findRunning(?int $limit = null): RecurringTaskRecordCollection;

    /**
     * Récupère la dernière version des tâches récurrentes terminées.
     */
    public function findFinished(?int $limit = null): RecurringTaskRecordCollection;

    /**
     * Récupère les tâches récurrentes prêtes à être exécutées.
     */
    public function findReadyToRun(string $now): RecurringTaskRecordCollection;

    // ==================== MOVES ====================

    /**
     * Déplace une tâche de PENDING vers RUNNING.
     */
    public function moveToRunning(TaskSignatureVO $alias, RecurringTaskRecord $task): void;

    /**
     * Déplace une tâche de RUNNING vers FINISHED.
     */
    public function moveToFinished(TaskSignatureVO $alias, RecurringTaskRecord $task): void;

    /**
     * Déplace une tâche de RUNNING vers PENDING.
     */
    public function moveToPending(TaskSignatureVO $alias, RecurringTaskRecord $task): void;

    // ==================== UPDATE ====================

    /**
     * Met à jour une tâche après exécution.
     */
    public function updateAfterRun(RecurringTaskRecord $task, bool $success, ?string $error = null): void;

    // ==================== COUNTS ====================

    public function count(): int;

    public function countPending(): int;

    public function countRunning(): int;

    public function countFinished(): int;
}
