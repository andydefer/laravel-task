<?php

declare(strict_types=1);

namespace AndyDefer\Task\Repositories;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Logger\Records\LogDataRecord;
use AndyDefer\Repository\AbstractRepository;
use AndyDefer\Repository\Records\FindByRecord;
use AndyDefer\Task\Contracts\Repositories\TaskExecutionDebugRepositoryInterface;
use AndyDefer\Task\Contracts\Repositories\UniqueTaskRepositoryInterface;
use AndyDefer\Task\Enums\ExecutionStatus;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\Models\UniqueTask;
use AndyDefer\Task\Records\UniqueTaskFiltersRecord;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\UuidVO;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Repository for unique task management.
 *
 * Handles storage, retrieval, and state transitions for unique tasks.
 * Provides methods for finding tasks by status, updating states,
 * and processing ready-to-run tasks with row locking.
 *
 * @extends AbstractRepository<UniqueTask, UniqueTaskRecord>
 */
final class UniqueTaskRepository extends AbstractRepository implements UniqueTaskRepositoryInterface
{
    private readonly TaskExecutionDebugRepositoryInterface $debugRepository;

    private readonly LoggerInterface $logger;

    /**
     * Constructor for the unique task repository.
     *
     * @param  TaskExecutionDebugRepositoryInterface  $debugRepository  The debug repository
     * @param  LoggerInterface  $logger  The logger instance
     */
    public function __construct(
        TaskExecutionDebugRepositoryInterface $debugRepository,
        LoggerInterface $logger,
    ) {
        parent::__construct(UniqueTask::class, UniqueTaskRecord::class);
        $this->debugRepository = $debugRepository;
        $this->logger = $logger;
    }

    /**
     * {@inheritDoc}
     */
    protected function applyFilters(Builder $query, AbstractRecord $filters): void
    {
        if (! $filters instanceof UniqueTaskFiltersRecord) {
            return;
        }

        if ($filters->id !== null) {
            $query->where('id', $filters->id->getValue());
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

        if ($filters->scheduled_at_from !== null) {
            $query->where('scheduled_at', '>=', $filters->scheduled_at_from->forDatabase());
        }

        if ($filters->scheduled_at_to !== null) {
            $query->where('scheduled_at', '<=', $filters->scheduled_at_to->forDatabase());
        }

        if ($filters->finished_at_from !== null) {
            $query->where('finished_at', '>=', $filters->finished_at_from->forDatabase());
        }

        if ($filters->finished_at_to !== null) {
            $query->where('finished_at', '<=', $filters->finished_at_to->forDatabase());
        }

        if ($filters->attempts !== null) {
            $query->where('attempts', $filters->attempts->getValue());
        }

        if ($filters->max_attempts !== null) {
            $query->where('max_attempts', $filters->max_attempts->getValue());
        }

        if ($filters->include_deleted === true) {
            $query->withTrashed();
        }
    }

    // ==================== FINDERS ====================

    /**
     * {@inheritDoc}
     */
    public function findPending(LimitVO $limit = new LimitVO): Collection
    {
        $filters = UniqueTaskFiltersRecord::from([
            'status' => UniqueTaskStatus::PENDING,
        ]);

        $limitValue = $limit !== null ? $limit->getValue() : null;

        return $this->findBy(FindByRecord::from([
            'filters' => $filters,
            'limit' => $limitValue,
        ]));
    }

    /**
     * {@inheritDoc}
     */
    public function findCompleted(LimitVO $limit = new LimitVO): Collection
    {
        $filters = UniqueTaskFiltersRecord::from([
            'status' => UniqueTaskStatus::COMPLETED,
        ]);

        $limitValue = $limit !== null ? $limit->getValue() : null;

        return $this->findBy(FindByRecord::from([
            'filters' => $filters,
            'limit' => $limitValue,
        ]));
    }

    /**
     * {@inheritDoc}
     */
    public function findFailed(LimitVO $limit = new LimitVO): Collection
    {
        $filters = UniqueTaskFiltersRecord::from([
            'status' => UniqueTaskStatus::FAILED,
        ]);

        $limitValue = $limit !== null ? $limit->getValue() : null;

        return $this->findBy(FindByRecord::from([
            'filters' => $filters,
            'limit' => $limitValue,
        ]));
    }

    /**
     * {@inheritDoc}
     */
    public function findCanceled(LimitVO $limit = new LimitVO): Collection
    {
        $filters = UniqueTaskFiltersRecord::from([
            'status' => UniqueTaskStatus::CANCELED,
        ]);

        $limitValue = $limit !== null ? $limit->getValue() : null;

        return $this->findBy(FindByRecord::from([
            'filters' => $filters,
            'limit' => $limitValue,
        ]));
    }

    /**
     * {@inheritDoc}
     *
     * Uses lockForUpdate() to prevent concurrency issues and ensure
     * each task is executed only once.
     */
    public function findReadyToRun(Iso8601DateTimeVO $now, ?LimitVO $limit = null): Collection
    {
        $limit = $limit ?? new LimitVO;
        $formattedNow = $now->forDatabase();

        return DB::transaction(function () use ($formattedNow, $limit) {
            $tasks = $this->model->newQuery()
                ->where('status', UniqueTaskStatus::PENDING->value)
                ->where('scheduled_at', '<=', $formattedNow)
                ->lockForUpdate()
                ->limit($limit->getValue() ?? PHP_INT_MAX)
                ->get();

            // ✅ UPDATE en lot (une seule requête pour toutes les tâches)
            if ($tasks->isNotEmpty()) {
                $taskIds = $tasks->pluck('id')->toArray();
                $this->model->newQuery()
                    ->whereIn('id', $taskIds)
                    ->update(['status' => UniqueTaskStatus::IN_PROGRESS->value]);
            }

            return $tasks;
        });
    }

    /**
     * {@inheritDoc}
     */
    public function findExpired(Iso8601DateTimeVO $now, ?LimitVO $limit = null): Collection
    {
        $nowTimestamp = $now->getTimestamp();

        $query = $this->model->newQuery()
            ->where('status', UniqueTaskStatus::PENDING->value);

        if ($limit !== null) {
            $query->limit($limit->getValue());
        }

        $tasks = $query->get();
        $expired = [];

        foreach ($tasks as $task) {
            $scheduledAt = $task->getScheduledAt()->getTimestamp();
            $graceEnd = $scheduledAt + $task->getGracePeriodSeconds();

            if ($nowTimestamp > $graceEnd) {
                $expired[] = $task;
            }
        }

        return new Collection($expired);
    }

    /**
     * {@inheritDoc}
     */
    public function findById(UuidVO $id): ?UniqueTask
    {
        $filters = UniqueTaskFiltersRecord::from([
            'id' => $id,
        ]);

        $results = $this->findBy(FindByRecord::from(['filters' => $filters]));

        return $results->first() ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function findByAlias(TaskAliasVO $alias): ?UniqueTask
    {
        $filters = UniqueTaskFiltersRecord::from([
            'alias' => $alias,
        ]);

        $results = $this->findBy(FindByRecord::from(['filters' => $filters]));

        return $results->first() ?? null;
    }

    // ==================== MOVES ====================

    /**
     * {@inheritDoc}
     */
    public function updateAttempts(UniqueTaskRecord $task, CounterVO $newAttempts): bool
    {
        try {
            $updated = $this->model->newQuery()
                ->where('id', $task->id->getValue())
                ->update(['attempts' => $newAttempts->getValue()]);

            if ($updated === 0) {
                $this->logger->error(LogDataRecord::from([
                    'type' => 'unique_task_update_attempts_not_found',
                    'payload' => [
                        'task_id' => $task->id->getValue(),
                        'new_attempts' => $newAttempts->getValue(),
                    ],
                ]));

                return false;
            }

            return true;
        } catch (Throwable $e) {
            $this->logger->error(LogDataRecord::from([
                'type' => 'unique_task_update_attempts_error',
                'payload' => [
                    'task_id' => $task->id->getValue(),
                    'new_attempts' => $newAttempts->getValue(),
                    'error' => $e->getMessage(),
                ],
            ]));

            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function addDebug(UniqueTaskRecord $task, ExecutionStatus $status, DescriptionVO $info): bool
    {
        try {
            $this->debugRepository->addDebug(
                alias: $task->alias,
                fqcn: $task->fqcn,
                status: $status,
                info: $info,
            );

            return true;
        } catch (Throwable $e) {
            $this->logger->error(LogDataRecord::from([
                'type' => 'unique_task_add_debug_error',
                'payload' => [
                    'alias' => $task->alias->getValue(),
                    'fqcn' => $task->fqcn->getValue(),
                    'status' => $status->value,
                    'error' => $e->getMessage(),
                ],
            ]));

            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function moveToCompleted(UniqueTaskRecord $task): bool
    {
        try {
            $now = Carbon::now()->toDateTimeString();

            $updated = $this->model->newQuery()
                ->where('id', $task->id->getValue())
                ->where('status', '!=', UniqueTaskStatus::COMPLETED->value)
                ->update([
                    'status' => UniqueTaskStatus::COMPLETED->value,
                    'finished_at' => $now,
                ]);

            if ($updated === 0) {
                $this->logger->error(LogDataRecord::from([
                    'type' => 'unique_task_move_to_completed_not_found_or_already_completed',
                    'payload' => [
                        'task_id' => $task->id->getValue(),
                    ],
                ]));

                return false;
            }

            return true;
        } catch (Throwable $e) {
            $this->logger->error(LogDataRecord::from([
                'type' => 'unique_task_move_to_completed_error',
                'payload' => [
                    'task_id' => $task->id->getValue(),
                    'error' => $e->getMessage(),
                ],
            ]));

            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function moveToFailed(UniqueTaskRecord $task): bool
    {
        try {
            $now = Carbon::now()->toDateTimeString();

            $updated = $this->model->newQuery()
                ->where('id', $task->id->getValue())
                ->where('status', '!=', UniqueTaskStatus::FAILED->value)
                ->update([
                    'status' => UniqueTaskStatus::FAILED->value,
                    'finished_at' => $now,
                ]);

            if ($updated === 0) {
                $this->logger->error(LogDataRecord::from([
                    'type' => 'unique_task_move_to_failed_not_found_or_already_failed',
                    'payload' => [
                        'task_id' => $task->id->getValue(),
                    ],
                ]));

                return false;
            }

            return true;
        } catch (Throwable $e) {
            $this->logger->error(LogDataRecord::from([
                'type' => 'unique_task_move_to_failed_error',
                'payload' => [
                    'task_id' => $task->id->getValue(),
                    'error' => $e->getMessage(),
                ],
            ]));

            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function moveToCanceled(UniqueTaskRecord $task): bool
    {
        try {
            $now = Carbon::now()->toDateTimeString();

            $updated = $this->model->newQuery()
                ->where('id', $task->id->getValue())
                ->where('status', '!=', UniqueTaskStatus::CANCELED->value)
                ->update([
                    'status' => UniqueTaskStatus::CANCELED->value,
                    'finished_at' => $now,
                ]);

            if ($updated === 0) {
                $this->logger->error(LogDataRecord::from([
                    'type' => 'unique_task_move_to_canceled_not_found_or_already_canceled',
                    'payload' => [
                        'task_id' => $task->id->getValue(),
                    ],
                ]));

                return false;
            }

            return true;
        } catch (Throwable $e) {
            $this->logger->error(LogDataRecord::from([
                'type' => 'unique_task_move_to_canceled_error',
                'payload' => [
                    'task_id' => $task->id->getValue(),
                    'error' => $e->getMessage(),
                ],
            ]));

            return false;
        }
    }

    // ==================== COUNTS ====================

    /**
     * {@inheritDoc}
     */
    public function countPending(): CounterVO
    {
        $filters = UniqueTaskFiltersRecord::from([
            'status' => UniqueTaskStatus::PENDING,
        ]);

        return new CounterVO($this->count($filters));
    }

    /**
     * {@inheritDoc}
     */
    public function countCompleted(): CounterVO
    {
        $filters = UniqueTaskFiltersRecord::from([
            'status' => UniqueTaskStatus::COMPLETED,
        ]);

        return new CounterVO($this->count($filters));
    }

    /**
     * {@inheritDoc}
     */
    public function countFailed(): CounterVO
    {
        $filters = UniqueTaskFiltersRecord::from([
            'status' => UniqueTaskStatus::FAILED,
        ]);

        return new CounterVO($this->count($filters));
    }

    /**
     * {@inheritDoc}
     */
    public function countCanceled(): CounterVO
    {
        $filters = UniqueTaskFiltersRecord::from([
            'status' => UniqueTaskStatus::CANCELED,
        ]);

        return new CounterVO($this->count($filters));
    }
}
