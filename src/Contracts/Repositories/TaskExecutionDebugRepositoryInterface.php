<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Repositories;

use AndyDefer\Repository\AbstractRepositoryInterface;
use AndyDefer\Task\Enums\ExecutionStatus;
use AndyDefer\Task\Models\TaskExecutionDebug;
use AndyDefer\Task\Records\TaskExecutionDebugRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\MillisecondsVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\TaskFqcnVO;
use Illuminate\Support\Collection;

interface TaskExecutionDebugRepositoryInterface extends AbstractRepositoryInterface
{
    public function findByAlias(TaskAliasVO $alias): Collection;

    public function findByFqcn(TaskFqcnVO $fqcn): Collection;

    public function findByAliasAndFqcn(TaskAliasVO $alias, TaskFqcnVO $fqcn): Collection;

    public function findByStatus(ExecutionStatus $status): Collection;

    public function addDebug(
        TaskAliasVO $alias,
        TaskFqcnVO $fqcn,
        ExecutionStatus $status,
        DescriptionVO $info,
        ?MillisecondsVO $duration_ms = null,
        ?DescriptionVO $error = null
    ): void;

    public function addDebugWithStart(
        TaskAliasVO $alias,
        TaskFqcnVO $fqcn,
        ExecutionStatus $status,
        DescriptionVO $info
    ): void;

    public function updateDebugWithEnd(
        TaskAliasVO $alias,
        TaskFqcnVO $fqcn,
        ExecutionStatus $status,
        ?DescriptionVO $error = null,
        ?MillisecondsVO $duration_ms = null
    ): void;

    public function clearByAlias(TaskAliasVO $alias): void;

    public function clearByFqcn(TaskFqcnVO $fqcn): void;

    public function countByAlias(TaskAliasVO $alias): CounterVO;

    public function countByFqcn(TaskFqcnVO $fqcn): CounterVO;

    public function countByStatus(ExecutionStatus $status): CounterVO;

    /**
     * Convertit un modèle Eloquent en Record.
     *
     * @param  TaskExecutionDebug  $model  Modèle Eloquent
     * @return TaskExecutionDebugRecord Record correspondant
     */
    public function modelToRecord(TaskExecutionDebug $model): TaskExecutionDebugRecord;
}
