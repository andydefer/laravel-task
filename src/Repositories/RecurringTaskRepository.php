<?php

declare(strict_types=1);

namespace AndyDefer\Task\Repositories;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\Repository\AbstractRepository;
use AndyDefer\Repository\Records\FindByRecord;
use AndyDefer\Task\Contracts\Repositories\RecurringTaskRepositoryInterface;
use AndyDefer\Task\Enums\RecurringTaskStatus;
use AndyDefer\Task\Models\RecurringTask;
use AndyDefer\Task\Records\RecurringTaskFiltersRecord;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * @extends AbstractRepository<RecurringTask, RecurringTaskRecord>
 */
final class RecurringTaskRepository extends AbstractRepository implements RecurringTaskRepositoryInterface
{
    private TaskExecutionDebugRepository $debugRepository;

    public function __construct(
        TaskExecutionDebugRepository $debugRepository,
    ) {
        parent::__construct(RecurringTask::class, RecurringTaskRecord::class);
        $this->debugRepository = $debugRepository;
    }

    protected function applyFilters(Builder $query, AbstractRecord $filters): void
    {
        if (! $filters instanceof RecurringTaskFiltersRecord) {
            return;
        }

        if ($filters->alias !== null) {
            $query->where('alias', $filters->alias->value);
        }

        if ($filters->fqcn !== null) {
            $query->where('fqcn', $filters->fqcn);
        }

        if ($filters->status !== null) {
            $query->where('status', $filters->status->value);
        }

        if ($filters->start_at_from !== null) {
            $query->where('start_at', '>=', $this->formatDateForDatabase($filters->start_at_from));
        }

        if ($filters->start_at_to !== null) {
            $query->where('start_at', '<=', $this->formatDateForDatabase($filters->start_at_to));
        }

        if ($filters->end_at_from !== null) {
            $query->where('end_at', '>=', $this->formatDateForDatabase($filters->end_at_from));
        }

        if ($filters->end_at_to !== null) {
            $query->where('end_at', '<=', $this->formatDateForDatabase($filters->end_at_to));
        }

        if ($filters->last_run_at_from !== null) {
            $query->where('last_run_at', '>=', $this->formatDateForDatabase($filters->last_run_at_from));
        }

        if ($filters->last_run_at_to !== null) {
            $query->where('last_run_at', '<=', $this->formatDateForDatabase($filters->last_run_at_to));
        }

        if ($filters->cancelled_at_from !== null) {
            $query->where('cancelled_at', '>=', $this->formatDateForDatabase($filters->cancelled_at_from));
        }

        if ($filters->cancelled_at_to !== null) {
            $query->where('cancelled_at', '<=', $this->formatDateForDatabase($filters->cancelled_at_to));
        }

        if ($filters->include_deleted === true) {
            $query->withTrashed();
        }
    }

    // ==================== FINDERS ====================

    public function findWaiting(?int $limit = null): Collection
    {
        $filters = new RecurringTaskFiltersRecord(status: RecurringTaskStatus::WAITING);

        return $this->findBy(new FindByRecord(filters: $filters, limit: $limit));
    }

    public function findPlaying(?int $limit = null): Collection
    {
        $filters = new RecurringTaskFiltersRecord(status: RecurringTaskStatus::PLAYING);

        return $this->findBy(new FindByRecord(filters: $filters, limit: $limit));
    }

    public function findPaused(?int $limit = null): Collection
    {
        $filters = new RecurringTaskFiltersRecord(status: RecurringTaskStatus::PAUSED);

        return $this->findBy(new FindByRecord(filters: $filters, limit: $limit));
    }

    public function findFinished(?int $limit = null): Collection
    {
        $filters = new RecurringTaskFiltersRecord(status: RecurringTaskStatus::FINISHED);

        return $this->findBy(new FindByRecord(filters: $filters, limit: $limit));
    }

    public function findCanceled(?int $limit = null): Collection
    {
        $filters = new RecurringTaskFiltersRecord(status: RecurringTaskStatus::CANCELED);

        return $this->findBy(new FindByRecord(filters: $filters, limit: $limit));
    }

    public function findReadyToRun(string $now, ?int $limit = null): Collection
    {
        $dateTime = new \DateTime($now);
        $dateTime->setTimezone(new \DateTimeZone('UTC'));
        $formattedNow = $dateTime->format('Y-m-d H:i:s');

        $query = $this->model->newQuery();
        $query->where('status', RecurringTaskStatus::WAITING->value);
        $query->where('start_at', '<=', $formattedNow);

        if ($limit !== null) {
            $query->limit($limit);
        }

        /** @var Collection<int, RecurringTask> $result */
        $result = $query->get();

        return $result;
    }

    public function findExpired(string $now, ?int $limit = null): Collection
    {
        $dateTime = new \DateTime($now);
        $dateTime->setTimezone(new \DateTimeZone('UTC'));
        $formattedNow = $dateTime->format('Y-m-d H:i:s');

        $query = $this->model->newQuery();
        $query->where('status', RecurringTaskStatus::PLAYING->value);
        $query->where('end_at', '<=', $formattedNow);

        if ($limit !== null) {
            $query->limit($limit);
        }

        /** @var Collection<int, RecurringTask> $result */
        $result = $query->get();

        return $result;
    }

    public function findByAlias(string $alias): ?RecurringTask
    {
        $filters = new RecurringTaskFiltersRecord(alias: new TaskSignatureVO($alias));
        $results = $this->findBy(new FindByRecord(filters: $filters));

        return $results->first() ?? null;
    }

    // ==================== MOVES ====================

    public function moveToPlaying(RecurringTaskRecord $task): void
    {
        $existingTask = $this->findByAlias($task->alias->value);
        if ($existingTask === null) {
            throw new \RuntimeException("Task not found: {$task->alias->value}");
        }

        $this->update($existingTask->getId(), new RecurringTaskRecord(
            alias: $task->alias,
            fqcn: $task->fqcn,
            payload: $task->payload,
            interval_seconds: $task->interval_seconds,
            start_at: $task->start_at,
            end_at: $task->end_at,
            status: RecurringTaskStatus::PLAYING,
            last_run_at: $task->last_run_at,
        ));
    }

    public function moveToPaused(RecurringTaskRecord $task): void
    {
        $existingTask = $this->findByAlias($task->alias->value);
        if ($existingTask === null) {
            throw new \RuntimeException("Task not found: {$task->alias->value}");
        }

        $this->update($existingTask->getId(), new RecurringTaskRecord(
            alias: $task->alias,
            fqcn: $task->fqcn,
            payload: $task->payload,
            interval_seconds: $task->interval_seconds,
            start_at: $task->start_at,
            end_at: $task->end_at,
            status: RecurringTaskStatus::PAUSED,
            last_run_at: $task->last_run_at,
        ));
    }

    public function moveToWaiting(RecurringTaskRecord $task): void
    {
        $existingTask = $this->findByAlias($task->alias->value);
        if ($existingTask === null) {
            throw new \RuntimeException("Task not found: {$task->alias->value}");
        }

        $this->update($existingTask->getId(), new RecurringTaskRecord(
            alias: $task->alias,
            fqcn: $task->fqcn,
            payload: $task->payload,
            interval_seconds: $task->interval_seconds,
            start_at: $task->start_at,
            end_at: $task->end_at,
            status: RecurringTaskStatus::WAITING,
            last_run_at: $task->last_run_at,
        ));
    }

    public function moveToFinished(RecurringTaskRecord $task): void
    {
        $existingTask = $this->findByAlias($task->alias->value);
        if ($existingTask === null) {
            throw new \RuntimeException("Task not found: {$task->alias->value}");
        }

        $this->update($existingTask->getId(), new RecurringTaskRecord(
            alias: $task->alias,
            fqcn: $task->fqcn,
            payload: $task->payload,
            interval_seconds: $task->interval_seconds,
            start_at: $task->start_at,
            end_at: $task->end_at,
            status: RecurringTaskStatus::FINISHED,
            last_run_at: $task->last_run_at,
            finished_at: new Iso8601DateTimeVO,
        ));
    }

    public function moveToCanceled(RecurringTaskRecord $task): void
    {
        $existingTask = $this->findByAlias($task->alias->value);
        if ($existingTask === null) {
            throw new \RuntimeException("Task not found: {$task->alias->value}");
        }

        $this->update($existingTask->getId(), new RecurringTaskRecord(
            alias: $task->alias,
            fqcn: $task->fqcn,
            payload: $task->payload,
            interval_seconds: $task->interval_seconds,
            start_at: $task->start_at,
            end_at: $task->end_at,
            status: RecurringTaskStatus::CANCELED,
            last_run_at: $task->last_run_at,
            finished_at: new Iso8601DateTimeVO,
            cancelled_at: new Iso8601DateTimeVO,
        ));
    }

    // ==================== UPDATE ====================

    public function updateAfterRun(RecurringTaskRecord $task, bool $success, ?string $error = null): void
    {
        $now = new Iso8601DateTimeVO;

        $existingTask = $this->findByAlias($task->alias->value);
        if ($existingTask === null) {
            throw new \RuntimeException("Task not found: {$task->alias->value}");
        }

        $this->debugRepository->addDebug(
            taskType: 'recurring',
            taskIdentifier: $task->alias->value,
            status: $success ? 'succeeded' : 'failed',
            info: $success ? 'Recurring task executed successfully' : ($error ?? 'Recurring task execution failed'),
        );

        $this->update($existingTask->getId(), new RecurringTaskRecord(
            alias: $task->alias,
            fqcn: $task->fqcn,
            payload: $task->payload,
            interval_seconds: $task->interval_seconds,
            start_at: $task->start_at,
            end_at: $task->end_at,
            status: RecurringTaskStatus::PLAYING,
            last_run_at: $now,
        ));
    }

    // ==================== COUNTS ====================

    public function countWaiting(): int
    {
        $filters = new RecurringTaskFiltersRecord(status: RecurringTaskStatus::WAITING);

        return $this->count($filters);
    }

    public function countPlaying(): int
    {
        $filters = new RecurringTaskFiltersRecord(status: RecurringTaskStatus::PLAYING);

        return $this->count($filters);
    }

    public function countPaused(): int
    {
        $filters = new RecurringTaskFiltersRecord(status: RecurringTaskStatus::PAUSED);

        return $this->count($filters);
    }

    public function countFinished(): int
    {
        $filters = new RecurringTaskFiltersRecord(status: RecurringTaskStatus::FINISHED);

        return $this->count($filters);
    }

    public function countCanceled(): int
    {
        $filters = new RecurringTaskFiltersRecord(status: RecurringTaskStatus::CANCELED);

        return $this->count($filters);
    }

    /**
     * Convertit un Iso8601DateTimeVO en format MySQL datetime.
     */
    private function formatDateForDatabase(Iso8601DateTimeVO $date): string
    {
        $dateTime = new \DateTime($date->value);

        return $dateTime->format('Y-m-d H:i:s');
    }
}
