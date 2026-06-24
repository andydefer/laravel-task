<?php

declare(strict_types=1);

namespace AndyDefer\Task\Repositories;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\Repository\AbstractRepository;
use AndyDefer\Repository\Records\FindByRecord;
use AndyDefer\Task\Contracts\Repositories\UniqueTaskRepositoryInterface;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\Models\UniqueTask;
use AndyDefer\Task\Records\UniqueTaskFiltersRecord;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * @extends AbstractRepository<UniqueTask, UniqueTaskRecord>
 */
final class UniqueTaskRepository extends AbstractRepository implements UniqueTaskRepositoryInterface
{
    private TaskExecutionDebugRepository $debugRepository;

    public function __construct(
        TaskExecutionDebugRepository $debugRepository,
    ) {
        parent::__construct(UniqueTask::class, UniqueTaskRecord::class);
        $this->debugRepository = $debugRepository;
    }

    protected function applyFilters(Builder $query, AbstractRecord $filters): void
    {
        if (! $filters instanceof UniqueTaskFiltersRecord) {
            return;
        }

        if ($filters->id !== null) {
            $query->where('id', $filters->id->value);
        }

        if ($filters->alias !== null) {
            $query->where('alias', $filters->alias->value);
        }

        if ($filters->fqcn !== null) {
            $query->where('fqcn', $filters->fqcn->getValue());
        }

        if ($filters->status !== null) {
            $query->where('status', $filters->status->value);
        }

        if ($filters->scheduled_at_from !== null) {
            $query->where('scheduled_at', '>=', $filters->scheduled_at_from->value);
        }

        if ($filters->scheduled_at_to !== null) {
            $query->where('scheduled_at', '<=', $filters->scheduled_at_to->value);
        }

        if ($filters->finished_at_from !== null) {
            $query->where('finished_at', '>=', $filters->finished_at_from->value);
        }

        if ($filters->finished_at_to !== null) {
            $query->where('finished_at', '<=', $filters->finished_at_to->value);
        }

        if ($filters->attempts !== null) {
            $query->where('attempts', $filters->attempts);
        }

        if ($filters->max_attempts !== null) {
            $query->where('max_attempts', $filters->max_attempts);
        }

        if ($filters->include_deleted === true) {
            $query->withTrashed();
        }
    }

    // ==================== FINDERS ====================

    public function findPending(?int $limit = null): Collection
    {
        $filters = new UniqueTaskFiltersRecord(status: UniqueTaskStatus::PENDING);

        return $this->findBy(new FindByRecord(filters: $filters, limit: $limit));
    }

    public function findCompleted(?int $limit = null): Collection
    {
        $filters = new UniqueTaskFiltersRecord(status: UniqueTaskStatus::COMPLETED);

        return $this->findBy(new FindByRecord(filters: $filters, limit: $limit));
    }

    public function findFailed(?int $limit = null): Collection
    {
        $filters = new UniqueTaskFiltersRecord(status: UniqueTaskStatus::FAILED);

        return $this->findBy(new FindByRecord(filters: $filters, limit: $limit));
    }

    public function findCanceled(?int $limit = null): Collection
    {
        $filters = new UniqueTaskFiltersRecord(status: UniqueTaskStatus::CANCELED);

        return $this->findBy(new FindByRecord(filters: $filters, limit: $limit));
    }

    public function findReadyToRun(string $now, ?int $limit = null): Collection
    {
        $dateTime = new \DateTime($now);
        $dateTime->setTimezone(new \DateTimeZone('UTC'));
        $formattedNow = $dateTime->format('Y-m-d H:i:s');

        $query = $this->model->newQuery();
        $query->where('status', UniqueTaskStatus::PENDING->value);
        $query->where('scheduled_at', '<=', $formattedNow);

        if ($limit !== null) {
            $query->limit($limit);
        }

        /** @var Collection<int, UniqueTask> $result */
        $result = $query->get();

        return $result;
    }

    public function findExpired(string $now, ?int $limit = null): Collection
    {
        $tasks = $this->findPending();
        $expired = [];

        foreach ($tasks as $task) {
            $scheduledAt = strtotime($task->getScheduledAt()->value);
            $graceEnd = $scheduledAt + $task->getGracePeriodSeconds();

            if (strtotime($now) > $graceEnd) {
                $expired[] = $task;
            }
        }

        $collection = new Collection($expired);

        if ($limit !== null) {
            $collection = $collection->take($limit);
        }

        return $collection;
    }

    public function findById(string $id): ?UniqueTask
    {
        if (! preg_match('/^[a-f0-9-]{36}$/', $id)) {
            return null;
        }

        $filters = new UniqueTaskFiltersRecord(
            id: new TaskIdVO($id)
        );

        $results = $this->findBy(new FindByRecord(filters: $filters));

        return $results->first() ?? null;
    }

    // ==================== MOVES ====================

    public function updateAttempts(UniqueTaskRecord $task, int $newAttempts): void
    {
        $existingTask = $this->findById($task->id->value);
        if ($existingTask === null) {
            throw new \RuntimeException("Task not found: {$task->id->value}");
        }

        $existingTask->update(['attempts' => $newAttempts]);
    }

    public function addDebug(UniqueTaskRecord $task, string $status, string $info): void
    {
        $this->debugRepository->addDebug(
            taskType: 'unique',
            taskIdentifier: $task->id->value,
            status: $status,
            info: $info,
        );
    }

    public function moveToCompleted(UniqueTaskRecord $task): void
    {
        $existingTask = $this->findById($task->id->value);
        if ($existingTask === null) {
            throw new \RuntimeException("Task not found: {$task->id->value}");
        }

        $existingTask->update([
            'status' => UniqueTaskStatus::COMPLETED->value,
            'finished_at' => now()->toDateTimeString(),
        ]);
    }

    public function moveToFailed(UniqueTaskRecord $task): void
    {
        $existingTask = $this->findById($task->id->value);
        if ($existingTask === null) {
            throw new \RuntimeException("Task not found: {$task->id->value}");
        }

        $existingTask->update([
            'status' => UniqueTaskStatus::FAILED->value,
            'finished_at' => now()->toDateTimeString(),
        ]);
    }

    public function moveToCanceled(UniqueTaskRecord $task): void
    {
        $existingTask = $this->findById($task->id->value);
        if ($existingTask === null) {
            throw new \RuntimeException("Task not found: {$task->id->value}");
        }

        $existingTask->update([
            'status' => UniqueTaskStatus::CANCELED->value,
            'finished_at' => now()->toDateTimeString(),
        ]);
    }

    // ==================== COUNTS ====================

    public function countPending(): int
    {
        $filters = new UniqueTaskFiltersRecord(status: UniqueTaskStatus::PENDING);

        return $this->count($filters);
    }

    public function countCompleted(): int
    {
        $filters = new UniqueTaskFiltersRecord(status: UniqueTaskStatus::COMPLETED);

        return $this->count($filters);
    }

    public function countFailed(): int
    {
        $filters = new UniqueTaskFiltersRecord(status: UniqueTaskStatus::FAILED);

        return $this->count($filters);
    }

    public function countCanceled(): int
    {
        $filters = new UniqueTaskFiltersRecord(status: UniqueTaskStatus::CANCELED);

        return $this->count($filters);
    }

    // ==================== PRIVATE METHODS ====================

    /**
     * Convertit un modèle Eloquent UniqueTask en UniqueTaskRecord.
     */
    private function modelToRecord(UniqueTask $model): UniqueTaskRecord
    {
        return UniqueTaskRecord::from([
            'id' => $model->getId(),
            'alias' => $model->getAlias(),
            'fqcn' => $model->getFqcn(),
            'payload' => $model->getPayload(),
            'scheduled_at' => $model->getScheduledAt(),
            'grace_period_seconds' => $model->getGracePeriodSeconds(),
            'status' => $model->getStatus(),
            'attempts' => $model->getAttempts(),
            'max_attempts' => $model->getMaxAttempts(),
            'finished_at' => $model->getFinishedAt(),
        ]);
    }
}
