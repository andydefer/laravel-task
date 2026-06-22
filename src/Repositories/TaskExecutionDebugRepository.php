<?php

declare(strict_types=1);

namespace AndyDefer\Task\Repositories;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Repository\AbstractRepository;
use AndyDefer\Repository\Records\FindByRecord;
use AndyDefer\Repository\ValueObjects\SortColumns;
use AndyDefer\Task\Contracts\Repositories\TaskExecutionDebugRepositoryInterface;
use AndyDefer\Task\Models\TaskExecutionDebug;
use AndyDefer\Task\Records\TaskExecutionDebugFiltersRecord;
use AndyDefer\Task\Records\TaskExecutionDebugRecord;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * @extends AbstractRepository<TaskExecutionDebug, TaskExecutionDebugRecord>
 */
final class TaskExecutionDebugRepository extends AbstractRepository implements TaskExecutionDebugRepositoryInterface
{
    public function __construct()
    {
        parent::__construct(TaskExecutionDebug::class, TaskExecutionDebugRecord::class);
    }

    protected function applyFilters(Builder $query, AbstractRecord $filters): void
    {
        if (! $filters instanceof TaskExecutionDebugFiltersRecord) {
            return;
        }

        if ($filters->task_type !== null) {
            $query->where('task_type', $filters->task_type);
        }

        if ($filters->task_identifier !== null) {
            $query->where('task_identifier', $filters->task_identifier);
        }

        if ($filters->status !== null) {
            $query->where('data->status', $filters->status->value);
        }

        if ($filters->acted_at_from !== null) {
            $query->where('data->acted_at', '>=', $filters->acted_at_from->value);
        }

        if ($filters->acted_at_to !== null) {
            $query->where('data->acted_at', '<=', $filters->acted_at_to->value);
        }
    }

    public function findByTask(string $taskType, string $taskIdentifier): Collection
    {
        $filters = new TaskExecutionDebugFiltersRecord(
            task_type: $taskType,
            task_identifier: $taskIdentifier,
        );

        return $this->findBy(new FindByRecord(
            filters: $filters,
            sortBy: new SortColumns('created_at:desc'),
        ));
    }

    public function addDebug(string $taskType, string $taskIdentifier, string $status, string $info): void
    {
        $this->create(new TaskExecutionDebugRecord(
            task_type: $taskType,
            task_identifier: $taskIdentifier,
            data: StrictDataObject::from([
                'acted_at' => (new Iso8601DateTimeVO)->value,
                'status' => $status,
                'info' => $info,
            ]),
        ));
    }

    public function clearTaskDebug(string $taskType, string $taskIdentifier): void
    {
        $filters = new TaskExecutionDebugFiltersRecord(
            task_type: $taskType,
            task_identifier: $taskIdentifier,
        );

        $records = $this->findBy(new FindByRecord(filters: $filters));

        foreach ($records as $record) {
            $this->delete($record->getId());
        }
    }

    public function countTaskDebug(string $taskType, string $taskIdentifier): int
    {
        $filters = new TaskExecutionDebugFiltersRecord(
            task_type: $taskType,
            task_identifier: $taskIdentifier,
        );

        return $this->count($filters);
    }
}
