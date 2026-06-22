<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Repositories;

use AndyDefer\Repository\AbstractRepositoryInterface;
use Illuminate\Support\Collection;

interface TaskExecutionDebugRepositoryInterface extends AbstractRepositoryInterface
{
    /**
     * Récupère tous les logs de debug pour une tâche spécifique.
     *
     * @param  string  $taskType  Type de tâche (ex: 'recurring', 'unique')
     * @param  string  $taskIdentifier  Identifiant de la tâche (alias ou UUID)
     * @return Collection<int, object> Collection des entrées de debug
     */
    public function findByTask(string $taskType, string $taskIdentifier): Collection;

    /**
     * Ajoute une entrée de debug pour une tâche.
     *
     * @param  string  $taskType  Type de tâche (ex: 'recurring', 'unique')
     * @param  string  $taskIdentifier  Identifiant de la tâche (alias ou UUID)
     * @param  string  $status  Statut de l'opération (ex: 'started', 'completed', 'failed')
     * @param  string  $info  Informations supplémentaires sur l'opération
     */
    public function addDebug(string $taskType, string $taskIdentifier, string $status, string $info): void;

    /**
     * Supprime tous les logs de debug pour une tâche spécifique.
     *
     * @param  string  $taskType  Type de tâche (ex: 'recurring', 'unique')
     * @param  string  $taskIdentifier  Identifiant de la tâche (alias ou UUID)
     */
    public function clearTaskDebug(string $taskType, string $taskIdentifier): void;

    /**
     * Compte le nombre d'entrées de debug pour une tâche spécifique.
     *
     * @param  string  $taskType  Type de tâche (ex: 'recurring', 'unique')
     * @param  string  $taskIdentifier  Identifiant de la tâche (alias ou UUID)
     * @return int Nombre d'entrées de debug
     */
    public function countTaskDebug(string $taskType, string $taskIdentifier): int;
}
