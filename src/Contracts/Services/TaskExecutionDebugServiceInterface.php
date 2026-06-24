<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Services;

use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Collections\TaskExecutionDebugRecordCollection;
use AndyDefer\Task\Enums\ExecutionStatus;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\TaskFqcnVO;

interface TaskExecutionDebugServiceInterface
{
    /**
     * Récupère tous les logs de debug pour un alias spécifique.
     *
     * @param  TaskAliasVO  $alias  Alias de la tâche
     * @return TaskExecutionDebugRecordCollection Collection des enregistrements de debug
     */
    public function findByAlias(TaskAliasVO $alias): TaskExecutionDebugRecordCollection;

    /**
     * Récupère tous les logs de debug pour un FQCN spécifique.
     *
     * @param  TaskFqcnVO  $fqcn  FQCN de la tâche
     * @return TaskExecutionDebugRecordCollection Collection des enregistrements de debug
     */
    public function findByFqcn(TaskFqcnVO $fqcn): TaskExecutionDebugRecordCollection;

    /**
     * Récupère les logs de debug pour une tâche récurrente.
     *
     * @param  TaskAliasVO  $alias  Alias de la tâche récurrente
     * @return TaskExecutionDebugRecordCollection Collection des enregistrements de debug
     */
    public function findByRecurringTask(TaskAliasVO $alias): TaskExecutionDebugRecordCollection;

    /**
     * Récupère les logs de debug pour une tâche unique.
     *
     * @param  TaskAliasVO  $alias  Alias de la tâche unique
     * @return TaskExecutionDebugRecordCollection Collection des enregistrements de debug
     */
    public function findByUniqueTask(TaskAliasVO $alias): TaskExecutionDebugRecordCollection;

    /**
     * Ajoute une entrée de debug pour une tâche.
     *
     * @param  TaskAliasVO  $alias  Alias de la tâche
     * @param  TaskFqcnVO  $fqcn  FQCN de la tâche
     * @param  ExecutionStatus  $status  Statut de l'opération
     * @param  DescriptionVO  $info  Informations supplémentaires sur l'opération
     * @param  StrictDataObject|null  $data  Données supplémentaires optionnelles
     */
    public function addDebug(
        TaskAliasVO $alias,
        TaskFqcnVO $fqcn,
        ExecutionStatus $status,
        DescriptionVO $info,
        ?StrictDataObject $data = null
    ): bool;

    /**
     * Ajoute une entrée de debug pour une tâche récurrente.
     *
     * @param  TaskAliasVO  $alias  Alias de la tâche récurrente
     * @param  TaskFqcnVO  $fqcn  FQCN de la tâche récurrente
     * @param  ExecutionStatus  $status  Statut de l'opération
     * @param  DescriptionVO  $info  Informations supplémentaires sur l'opération
     * @param  StrictDataObject|null  $data  Données supplémentaires optionnelles
     */
    public function addDebugForRecurringTask(
        TaskAliasVO $alias,
        TaskFqcnVO $fqcn,
        ExecutionStatus $status,
        DescriptionVO $info,
        ?StrictDataObject $data = null
    ): bool;

    /**
     * Ajoute une entrée de debug pour une tâche unique.
     *
     * @param  TaskAliasVO  $alias  Alias de la tâche unique
     * @param  TaskFqcnVO  $fqcn  FQCN de la tâche unique
     * @param  ExecutionStatus  $status  Statut de l'opération
     * @param  DescriptionVO  $info  Informations supplémentaires sur l'opération
     * @param  StrictDataObject|null  $data  Données supplémentaires optionnelles
     */
    public function addDebugForUniqueTask(
        TaskAliasVO $alias,
        TaskFqcnVO $fqcn,
        ExecutionStatus $status,
        DescriptionVO $info,
        ?StrictDataObject $data = null
    ): bool;

    /**
     * Supprime tous les logs de debug pour un alias spécifique.
     *
     * @param  TaskAliasVO  $alias  Alias de la tâche
     */
    public function clearTaskDebug(TaskAliasVO $alias): bool;

    /**
     * Supprime tous les logs de debug pour un FQCN spécifique.
     *
     * @param  TaskFqcnVO  $fqcn  FQCN de la tâche
     */
    public function clearTaskDebugByFqcn(TaskFqcnVO $fqcn): bool;

    /**
     * Compte le nombre d'entrées de debug pour un alias spécifique.
     *
     * @param  TaskAliasVO  $alias  Alias de la tâche
     * @return CounterVO Nombre d'entrées de debug
     */
    public function countTaskDebug(TaskAliasVO $alias): CounterVO;

    /**
     * Compte le nombre d'entrées de debug pour un FQCN spécifique.
     *
     * @param  TaskFqcnVO  $fqcn  FQCN de la tâche
     * @return CounterVO Nombre d'entrées de debug
     */
    public function countTaskDebugByFqcn(TaskFqcnVO $fqcn): CounterVO;

    /**
     * Vérifie si un alias a des logs de debug.
     *
     * @param  TaskAliasVO  $alias  Alias de la tâche
     * @return bool True si des logs existent, false sinon
     */
    public function hasDebug(TaskAliasVO $alias): bool;

    /**
     * Vérifie si un FQCN a des logs de debug.
     *
     * @param  TaskFqcnVO  $fqcn  FQCN de la tâche
     * @return bool True si des logs existent, false sinon
     */
    public function hasDebugByFqcn(TaskFqcnVO $fqcn): bool;
}
