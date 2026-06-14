<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Services;

use AndyDefer\Task\Collections\RecurringTaskRecordCollection;
use AndyDefer\Task\Collections\TaskRecordCollection;
use AndyDefer\Task\Enums\TaskOrder;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

/**
 * Service dédié à la recherche et à l'interrogation des tâches.
 *
 * @author Andy Defer
 */
interface TaskFinderServiceInterface
{
    /**
     * Récupère une tâche unique par son ID.
     *
     * @param TaskIdVO $taskId Identifiant de la tâche
     * @return TaskRecord|null La tâche ou null si non trouvée
     */
    public function findTask(TaskIdVO $taskId): ?TaskRecord;

    /**
     * Récupère une tâche récurrente par sa signature.
     *
     * @param TaskSignatureVO $signature Signature de la tâche
     * @return RecurringTaskRecord|null La tâche ou null si non trouvée
     */
    public function findRecurringTask(TaskSignatureVO $signature): ?RecurringTaskRecord;

    /**
     * Récupère toutes les tâches uniques en attente.
     *
     * @param int|null $limit Nombre maximum de tâches (null = toutes)
     * @param TaskOrder $order Ordre de tri (OLDEST/NEWEST)
     * @return TaskRecordCollection Collection de tâches
     */
    public function getPendingTasks(?int $limit = null, TaskOrder $order = TaskOrder::OLDEST): TaskRecordCollection;

    /**
     * Récupère toutes les tâches récurrentes.
     *
     * @param int|null $limit Nombre maximum de tâches (null = toutes)
     * @param TaskOrder|null $order Ordre de tri (OLDEST/NEWEST)
     * @return RecurringTaskRecordCollection Collection de tâches récurrentes
     */
    public function getRecurringTasks(?int $limit = null, ?TaskOrder $order = TaskOrder::OLDEST): RecurringTaskRecordCollection;

    /**
     * Vérifie si une tâche unique existe.
     *
     * @param TaskIdVO $taskId Identifiant de la tâche
     * @return bool True si la tâche existe
     */
    public function taskExists(TaskIdVO $taskId): bool;

    /**
     * Vérifie si une tâche récurrente existe.
     *
     * @param TaskSignatureVO $signature Signature de la tâche
     * @return bool True si la tâche existe
     */
    public function recurringTaskExists(TaskSignatureVO $signature): bool;

    /**
     * Compte le nombre de tâches uniques en attente.
     *
     * @return int Nombre de tâches en attente
     */
    public function countPendingTasks(): int;

    /**
     * Compte le nombre de tâches récurrentes.
     *
     * @return int Nombre de tâches récurrentes
     */
    public function countRecurringTasks(): int;
}
