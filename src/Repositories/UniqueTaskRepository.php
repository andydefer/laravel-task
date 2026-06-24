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
use AndyDefer\Task\ValueObjects\TaskIdVO;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * @extends AbstractRepository<UniqueTask, UniqueTaskRecord>
 */
final class UniqueTaskRepository extends AbstractRepository implements UniqueTaskRepositoryInterface
{
    private readonly TaskExecutionDebugRepositoryInterface $debugRepository;

    private readonly LoggerInterface $logger;

    public function __construct(
        TaskExecutionDebugRepositoryInterface $debugRepository,
        LoggerInterface $logger,
    ) {
        parent::__construct(UniqueTask::class, UniqueTaskRecord::class);
        $this->debugRepository = $debugRepository;
        $this->logger = $logger;
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

    public function findReadyToRun(Iso8601DateTimeVO $now, ?LimitVO $limit = null): Collection
    {
        $formattedNow = $now->forDatabase();

        $query = $this->model->newQuery();
        $query->where('status', UniqueTaskStatus::PENDING->value);
        $query->where('scheduled_at', '<=', $formattedNow);

        if ($limit !== null) {
            $query->limit($limit->getValue());
        }

        /** @var Collection<int, UniqueTask> $result */
        $result = $query->get();

        return $result;
    }

    public function findExpired(Iso8601DateTimeVO $now, ?LimitVO $limit = null): Collection
    {
        $tasks = $this->findPending();
        $expired = [];
        $nowTimestamp = strtotime($now->value);

        foreach ($tasks as $task) {
            $scheduledAt = strtotime($task->getScheduledAt()->value);
            $graceEnd = $scheduledAt + $task->getGracePeriodSeconds();

            if ($nowTimestamp > $graceEnd) {
                $expired[] = $task;
            }
        }

        $collection = new Collection($expired);

        if ($limit !== null) {
            $collection = $collection->take($limit->getValue());
        }

        return $collection;
    }

    public function findById(TaskIdVO $id): ?UniqueTask
    {
        $filters = UniqueTaskFiltersRecord::from([
            'id' => $id,
        ]);

        $results = $this->findBy(FindByRecord::from(['filters' => $filters]));

        return $results->first() ?? null;
    }

    public function findByAlias(TaskAliasVO $alias): ?UniqueTask
    {
        $filters = UniqueTaskFiltersRecord::from([
            'alias' => $alias,
        ]);

        $results = $this->findBy(FindByRecord::from(['filters' => $filters]));

        return $results->first() ?? null;
    }

    // ==================== MOVES ====================

    public function updateAttempts(UniqueTaskRecord $task, CounterVO $newAttempts): bool
    {
        try {
            $existingTask = $this->findById($task->id);
            if ($existingTask === null) {
                $this->logger->error(LogDataRecord::from([
                    'type' => 'unique_task_update_attempts_not_found',
                    'payload' => [
                        'task_id' => $task->id->value,
                        'new_attempts' => $newAttempts->getValue(),
                    ],
                ]));

                return false;
            }

            $existingTask->update(['attempts' => $newAttempts->getValue()]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error(LogDataRecord::from([
                'type' => 'unique_task_update_attempts_error',
                'payload' => [
                    'task_id' => $task->id->value,
                    'new_attempts' => $newAttempts->getValue(),
                    'error' => $e->getMessage(),
                ],
            ]));

            return false;
        }
    }

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
        } catch (\Throwable $e) {
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

    public function moveToCompleted(UniqueTaskRecord $task): bool
    {
        try {
            $existingTask = $this->findById($task->id);
            if ($existingTask === null) {
                $this->logger->error(LogDataRecord::from([
                    'type' => 'unique_task_move_to_completed_not_found',
                    'payload' => [
                        'task_id' => $task->id->value,
                    ],
                ]));

                return false;
            }

            $now = Carbon::now()->toDateTimeString();

            $existingTask->update([
                'status' => UniqueTaskStatus::COMPLETED->value,
                'finished_at' => $now,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error(LogDataRecord::from([
                'type' => 'unique_task_move_to_completed_error',
                'payload' => [
                    'task_id' => $task->id->value,
                    'error' => $e->getMessage(),
                ],
            ]));

            return false;
        }
    }

    public function moveToFailed(UniqueTaskRecord $task): bool
    {
        try {
            $existingTask = $this->findById($task->id);
            if ($existingTask === null) {
                $this->logger->error(LogDataRecord::from([
                    'type' => 'unique_task_move_to_failed_not_found',
                    'payload' => [
                        'task_id' => $task->id->value,
                    ],
                ]));

                return false;
            }

            $now = Carbon::now()->toDateTimeString();

            $existingTask->update([
                'status' => UniqueTaskStatus::FAILED->value,
                'finished_at' => $now,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error(LogDataRecord::from([
                'type' => 'unique_task_move_to_failed_error',
                'payload' => [
                    'task_id' => $task->id->value,
                    'error' => $e->getMessage(),
                ],
            ]));

            return false;
        }
    }

    public function moveToCanceled(UniqueTaskRecord $task): bool
    {
        try {
            $existingTask = $this->findById($task->id);
            if ($existingTask === null) {
                $this->logger->error(LogDataRecord::from([
                    'type' => 'unique_task_move_to_canceled_not_found',
                    'payload' => [
                        'task_id' => $task->id->value,
                    ],
                ]));

                return false;
            }

            $now = Carbon::now()->toDateTimeString();

            $existingTask->update([
                'status' => UniqueTaskStatus::CANCELED->value,
                'finished_at' => $now,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error(LogDataRecord::from([
                'type' => 'unique_task_move_to_canceled_error',
                'payload' => [
                    'task_id' => $task->id->value,
                    'error' => $e->getMessage(),
                ],
            ]));

            return false;
        }
    }

    // ==================== COUNTS ====================

    public function countPending(): CounterVO
    {
        $filters = UniqueTaskFiltersRecord::from([
            'status' => UniqueTaskStatus::PENDING,
        ]);

        return new CounterVO($this->count($filters));
    }

    public function countCompleted(): CounterVO
    {
        $filters = UniqueTaskFiltersRecord::from([
            'status' => UniqueTaskStatus::COMPLETED,
        ]);

        return new CounterVO($this->count($filters));
    }

    public function countFailed(): CounterVO
    {
        $filters = UniqueTaskFiltersRecord::from([
            'status' => UniqueTaskStatus::FAILED,
        ]);

        return new CounterVO($this->count($filters));
    }

    public function countCanceled(): CounterVO
    {
        $filters = UniqueTaskFiltersRecord::from([
            'status' => UniqueTaskStatus::CANCELED,
        ]);

        return new CounterVO($this->count($filters));
    }
}
