<?php

declare(strict_types=1);

namespace AndyDefer\Task\Repositories;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\Repository\AbstractRepository;
use AndyDefer\Repository\Records\FindByRecord;
use AndyDefer\Repository\ValueObjects\SortColumns;
use AndyDefer\Task\Contracts\Repositories\TaskExecutionDebugRepositoryInterface;
use AndyDefer\Task\Enums\ExecutionStatus;
use AndyDefer\Task\Models\TaskExecutionDebug;
use AndyDefer\Task\Records\TaskExecutionDebugFiltersRecord;
use AndyDefer\Task\Records\TaskExecutionDebugRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use AndyDefer\Task\ValueObjects\MillisecondsVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\TaskFqcnVO;
use AndyDefer\Task\ValueObjects\UuidVO;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Repository for task execution debug records.
 *
 * Handles storage, retrieval, and management of task execution debug information
 * including status, timing, and error details for both unique and recurring tasks.
 *
 * @extends AbstractRepository<TaskExecutionDebug, TaskExecutionDebugRecord>
 */
final class TaskExecutionDebugRepository extends AbstractRepository implements TaskExecutionDebugRepositoryInterface
{
    /**
     * Constructor for the task execution debug repository.
     */
    public function __construct()
    {
        parent::__construct(TaskExecutionDebug::class, TaskExecutionDebugRecord::class);
    }

    /**
     * {@inheritDoc}
     */
    protected function applyFilters(Builder $query, AbstractRecord $filters): void
    {
        if (! $filters instanceof TaskExecutionDebugFiltersRecord) {
            return;
        }

        if ($filters->alias !== null) {
            $query->where('alias', $filters->alias->getValue());
        }

        if ($filters->fqcn !== null) {
            $query->where('fqcn', $filters->fqcn->getValue());
        }

        if ($filters->status !== null) {
            $query->where('status', $filters->status->value);
        }

        if ($filters->started_at_from !== null) {
            $query->where('started_at', '>=', $filters->started_at_from->forDatabase());
        }

        if ($filters->started_at_to !== null) {
            $query->where('started_at', '<=', $filters->started_at_to->forDatabase());
        }

        if ($filters->ended_at_from !== null) {
            $query->where('ended_at', '>=', $filters->ended_at_from->forDatabase());
        }

        if ($filters->ended_at_to !== null) {
            $query->where('ended_at', '<=', $filters->ended_at_to->forDatabase());
        }

        if ($filters->include_deleted === true) {
            $query->withTrashed();
        }
    }

    /**
     * {@inheritDoc}
     */
    public function findByAlias(TaskAliasVO $alias, ?LimitVO $limit = null): Collection
    {
        $filters = TaskExecutionDebugFiltersRecord::from([
            'alias' => $alias,
        ]);

        $sortBy = new SortColumns('created_at:desc');

        $data = [
            'filters' => $filters,
            'sortBy' => $sortBy,
        ];

        if ($limit !== null) {
            $data['limit'] = $limit->getValue();
        }

        return $this->findBy(FindByRecord::from($data));
    }

    /**
     * {@inheritDoc}
     */
    public function findByFqcn(TaskFqcnVO $fqcn, ?LimitVO $limit = null): Collection
    {
        $data = [
            'filters' => TaskExecutionDebugFiltersRecord::from([
                'fqcn' => $fqcn,
            ]),
        ];

        if ($limit !== null) {
            $data['limit'] = $limit->getValue();
        }

        return $this->findBy(FindByRecord::from($data));
    }

    /**
     * {@inheritDoc}
     */
    public function findByAliasAndFqcn(TaskAliasVO $alias, TaskFqcnVO $fqcn, ?LimitVO $limit = null): Collection
    {
        $data = [
            'filters' => TaskExecutionDebugFiltersRecord::from([
                'alias' => $alias,
                'fqcn' => $fqcn,
            ]),
        ];

        if ($limit !== null) {
            $data['limit'] = $limit->getValue();
        }

        return $this->findBy(FindByRecord::from($data));
    }

    /**
     * {@inheritDoc}
     */
    public function findByStatus(ExecutionStatus $status, ?LimitVO $limit = null): Collection
    {
        $data = [
            'filters' => TaskExecutionDebugFiltersRecord::from([
                'status' => $status,
            ]),
        ];

        if ($limit !== null) {
            $data['limit'] = $limit->getValue();
        }

        return $this->findBy(FindByRecord::from($data));
    }

    /**
     * {@inheritDoc}
     */
    public function addDebug(
        TaskAliasVO $alias,
        TaskFqcnVO $fqcn,
        ExecutionStatus $status,
        DescriptionVO $info,
        ?MillisecondsVO $duration_ms = null,
        ?DescriptionVO $error = null
    ): void {
        $now = Carbon::now();

        $data = [
            'info' => $info->getValue(),
        ];

        if ($error !== null) {
            $data['error'] = $error->getValue();
        }

        if ($duration_ms !== null) {
            $data['duration_ms'] = $duration_ms->getValue();
        }

        $record = TaskExecutionDebugRecord::from([
            'id' => UuidVO::generate(),
            'alias' => $alias,
            'fqcn' => $fqcn,
            'status' => $status,
            'started_at' => new Iso8601DateTimeVO($now->toIso8601String()),
            'ended_at' => new Iso8601DateTimeVO($now->toIso8601String()),
            'data' => $data,
        ]);

        $this->create($record);
    }

    /**
     * {@inheritDoc}
     */
    public function addDebugWithStart(
        TaskAliasVO $alias,
        TaskFqcnVO $fqcn,
        ExecutionStatus $status,
        DescriptionVO $info
    ): void {
        $now = Carbon::now();

        $data = [
            'info' => $info->getValue(),
        ];

        $record = TaskExecutionDebugRecord::from([
            'id' => UuidVO::generate(),
            'alias' => $alias,
            'fqcn' => $fqcn,
            'status' => $status,
            'started_at' => new Iso8601DateTimeVO($now->toIso8601String()),
            'ended_at' => null,
            'data' => $data,
        ]);

        $this->create($record);
    }

    /**
     * {@inheritDoc}
     */
    public function updateDebugWithEnd(
        TaskAliasVO $alias,
        TaskFqcnVO $fqcn,
        ExecutionStatus $status,
        ?DescriptionVO $error = null,
        ?MillisecondsVO $duration_ms = null
    ): void {
        $existing = $this->findByAliasAndFqcn($alias, $fqcn, new LimitVO(1));

        if ($existing->isEmpty()) {
            return;
        }

        $debug = $existing->first();
        $endedAt = Carbon::now();

        $data = $debug->getData()->toArray();
        $data['info'] = $status === ExecutionStatus::SUCCEEDED
            ? 'Task executed successfully'
            : ($error?->getValue() ?? 'Task execution failed');

        if ($error !== null) {
            $data['error'] = $error->getValue();
        }

        if ($duration_ms !== null) {
            $data['duration_ms'] = $duration_ms->getValue();
        }

        $this->update($debug->getId(), TaskExecutionDebugRecord::from([
            'id' => $debug->getId(),
            'alias' => $debug->getAlias(),
            'fqcn' => $debug->getFqcn(),
            'status' => $status,
            'started_at' => $debug->getStartedAt(),
            'ended_at' => new Iso8601DateTimeVO($endedAt->toIso8601String()),
            'data' => $data,
        ]));
    }

    /**
     * {@inheritDoc}
     */
    public function clearByAlias(TaskAliasVO $alias): void
    {
        $filters = TaskExecutionDebugFiltersRecord::from([
            'alias' => $alias,
        ]);

        $records = $this->findBy(FindByRecord::from(['filters' => $filters]));

        foreach ($records as $record) {
            $this->delete($record->getId());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function clearByFqcn(TaskFqcnVO $fqcn): void
    {
        $filters = TaskExecutionDebugFiltersRecord::from([
            'fqcn' => $fqcn,
        ]);

        $records = $this->findBy(FindByRecord::from(['filters' => $filters]));

        foreach ($records as $record) {
            $this->delete($record->getId());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function countByAlias(TaskAliasVO $alias): CounterVO
    {
        $filters = TaskExecutionDebugFiltersRecord::from([
            'alias' => $alias,
        ]);

        return new CounterVO($this->count($filters));
    }

    /**
     * {@inheritDoc}
     */
    public function countByFqcn(TaskFqcnVO $fqcn): CounterVO
    {
        $filters = TaskExecutionDebugFiltersRecord::from([
            'fqcn' => $fqcn,
        ]);

        return new CounterVO($this->count($filters));
    }

    /**
     * {@inheritDoc}
     */
    public function countByStatus(ExecutionStatus $status): CounterVO
    {
        $filters = TaskExecutionDebugFiltersRecord::from([
            'status' => $status,
        ]);

        return new CounterVO($this->count($filters));
    }

    /**
     * {@inheritDoc}
     */
    public function modelToRecord(TaskExecutionDebug $model): TaskExecutionDebugRecord
    {
        return TaskExecutionDebugRecord::from([
            'id' => $model->getId(),
            'alias' => $model->getAlias(),
            'fqcn' => $model->getFqcn(),
            'status' => $model->getStatus(),
            'started_at' => $model->getStartedAt(),
            'ended_at' => $model->getEndedAt(),
            'data' => $model->getData(),
        ]);
    }
}
