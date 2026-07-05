<?php

declare(strict_types=1);

namespace AndyDefer\Task\Repositories;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Logger\Records\LogDataRecord;
use AndyDefer\Repository\AbstractRepository;
use AndyDefer\Repository\Records\FindByRecord;
use AndyDefer\Task\Collections\RecurringTaskRecordCollection;
use AndyDefer\Task\Contracts\Repositories\RecurringTaskRepositoryInterface;
use AndyDefer\Task\Contracts\Repositories\TaskExecutionDebugRepositoryInterface;
use AndyDefer\Task\Enums\ExecutionStatus;
use AndyDefer\Task\Enums\RecurringTaskStatus;
use AndyDefer\Task\Models\RecurringTask;
use AndyDefer\Task\Records\FreshStateResultRecord;
use AndyDefer\Task\Records\RecurringTaskFiltersRecord;
use AndyDefer\Task\Records\RecurringTaskReadyToRunResultRecord;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use AndyDefer\Task\ValueObjects\MaxFailedAttemptsVO;
use AndyDefer\Task\ValueObjects\MillisecondsVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * @extends AbstractRepository<RecurringTask, RecurringTaskRecord>
 */
final class RecurringTaskRepository extends AbstractRepository implements RecurringTaskRepositoryInterface
{
    private readonly TaskExecutionDebugRepositoryInterface $debugRepository;

    private readonly LoggerInterface $logger;

    public function __construct(
        TaskExecutionDebugRepositoryInterface $debugRepository,
        LoggerInterface $logger,
    ) {
        parent::__construct(RecurringTask::class, RecurringTaskRecord::class);
        $this->debugRepository = $debugRepository;
        $this->logger = $logger;
    }

    protected function applyFilters(Builder $query, AbstractRecord $filters): void
    {
        if (! $filters instanceof RecurringTaskFiltersRecord) {
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

        if ($filters->start_at_from !== null) {
            $query->where('start_at', '>=', $filters->start_at_from->forDatabase());
        }

        if ($filters->start_at_to !== null) {
            $query->where('start_at', '<=', $filters->start_at_to->forDatabase());
        }

        if ($filters->end_at_from !== null) {
            $query->where('end_at', '>=', $filters->end_at_from->forDatabase());
        }

        if ($filters->end_at_to !== null) {
            $query->where('end_at', '<=', $filters->end_at_to->forDatabase());
        }

        if ($filters->last_run_at_from !== null) {
            $query->where('last_run_at', '>=', $filters->last_run_at_from->forDatabase());
        }

        if ($filters->last_run_at_to !== null) {
            $query->where('last_run_at', '<=', $filters->last_run_at_to->forDatabase());
        }

        if ($filters->cancelled_at_from !== null) {
            $query->where('cancelled_at', '>=', $filters->cancelled_at_from->forDatabase());
        }

        if ($filters->cancelled_at_to !== null) {
            $query->where('cancelled_at', '<=', $filters->cancelled_at_to->forDatabase());
        }

        if ($filters->failed_attempts !== null) {
            $query->where('failed_attempts', $filters->failed_attempts->getValue());
        }

        if ($filters->max_failed_attempts !== null) {
            $query->where('max_failed_attempts', $filters->max_failed_attempts->getValue());
        }

        if ($filters->include_deleted === true) {
            $query->withTrashed();
        }
    }

    private function freshState(?Iso8601DateTimeVO $now = null): FreshStateResultRecord
    {
        $now = $now ?? new Iso8601DateTimeVO;

        try {
            $formattedNow = $now->forDatabase();

            $waitingToPlaying = $this->model->newQuery()
                ->where('status', RecurringTaskStatus::WAITING->value)
                ->where('start_at', '<=', $formattedNow)
                ->update(['status' => RecurringTaskStatus::PLAYING->value]);

            $playingToFinished = $this->model->newQuery()
                ->where('status', RecurringTaskStatus::PLAYING->value)
                ->where('end_at', '<=', $formattedNow)
                ->update([
                    'status' => RecurringTaskStatus::FINISHED->value,
                    'finished_at' => $formattedNow,
                ]);

            $playingToCanceled = $this->model->newQuery()
                ->where('status', RecurringTaskStatus::PLAYING->value)
                ->whereRaw('failed_attempts >= max_failed_attempts')
                ->update([
                    'status' => RecurringTaskStatus::CANCELED->value,
                    'cancelled_at' => $formattedNow,
                ]);

            return FreshStateResultRecord::from([
                'waiting_to_playing' => new CounterVO($waitingToPlaying),
                'playing_to_finished' => new CounterVO($playingToFinished),
                'playing_to_canceled' => new CounterVO($playingToCanceled),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error(LogDataRecord::from([
                'type' => 'recurring_task_fresh_state_error',
                'payload' => [
                    'now' => $now->getValue(),
                    'error' => $e->getMessage(),
                ],
            ]));

            return FreshStateResultRecord::from([
                'waiting_to_playing' => new CounterVO(0),
                'playing_to_finished' => new CounterVO(0),
                'playing_to_canceled' => new CounterVO(0),
            ]);
        }
    }

    // ==================== FINDERS ====================

    public function findWaiting(LimitVO $limit = new LimitVO): Collection
    {
        try {
            $this->freshState();
            $filters = RecurringTaskFiltersRecord::from([
                'status' => RecurringTaskStatus::WAITING,
            ]);

            $limitValue = $limit !== null ? $limit->getValue() : null;

            return $this->findBy(FindByRecord::from([
                'filters' => $filters,
                'limit' => $limitValue,
            ]));
        } catch (\Throwable $e) {
            $this->logger->error(LogDataRecord::from([
                'type' => 'recurring_task_find_waiting_error',
                'payload' => [
                    'limit' => $limit?->getValue() ?? 'null',
                    'error' => $e->getMessage(),
                ],
            ]));

            return new Collection;
        }
    }

    public function findPlaying(LimitVO $limit = new LimitVO): Collection
    {
        try {
            $this->freshState();
            $filters = RecurringTaskFiltersRecord::from([
                'status' => RecurringTaskStatus::PLAYING,
            ]);

            $limitValue = $limit !== null ? $limit->getValue() : null;

            return $this->findBy(FindByRecord::from([
                'filters' => $filters,
                'limit' => $limitValue,
            ]));
        } catch (\Throwable $e) {
            $this->logger->error(LogDataRecord::from([
                'type' => 'recurring_task_find_playing_error',
                'payload' => [
                    'limit' => $limit?->getValue() ?? 'null',
                    'error' => $e->getMessage(),
                ],
            ]));

            return new Collection;
        }
    }

    public function findPaused(LimitVO $limit = new LimitVO): Collection
    {
        try {
            $this->freshState();
            $filters = RecurringTaskFiltersRecord::from([
                'status' => RecurringTaskStatus::PAUSED,
            ]);

            $limitValue = $limit !== null ? $limit->getValue() : null;

            return $this->findBy(FindByRecord::from([
                'filters' => $filters,
                'limit' => $limitValue,
            ]));
        } catch (\Throwable $e) {
            $this->logger->error(LogDataRecord::from([
                'type' => 'recurring_task_find_paused_error',
                'payload' => [
                    'limit' => $limit?->getValue() ?? 'null',
                    'error' => $e->getMessage(),
                ],
            ]));

            return new Collection;
        }
    }

    public function findFinished(LimitVO $limit = new LimitVO): Collection
    {
        try {
            $this->freshState();
            $filters = RecurringTaskFiltersRecord::from([
                'status' => RecurringTaskStatus::FINISHED,
            ]);

            $limitValue = $limit !== null ? $limit->getValue() : null;

            return $this->findBy(FindByRecord::from([
                'filters' => $filters,
                'limit' => $limitValue,
            ]));
        } catch (\Throwable $e) {
            $this->logger->error(LogDataRecord::from([
                'type' => 'recurring_task_find_finished_error',
                'payload' => [
                    'limit' => $limit?->getValue() ?? 'null',
                    'error' => $e->getMessage(),
                ],
            ]));

            return new Collection;
        }
    }

    public function findCanceled(LimitVO $limit = new LimitVO): Collection
    {
        try {
            $this->freshState();
            $filters = RecurringTaskFiltersRecord::from([
                'status' => RecurringTaskStatus::CANCELED,
            ]);

            $limitValue = $limit !== null ? $limit->getValue() : null;

            return $this->findBy(FindByRecord::from([
                'filters' => $filters,
                'limit' => $limitValue,
            ]));
        } catch (\Throwable $e) {
            $this->logger->error(LogDataRecord::from([
                'type' => 'recurring_task_find_canceled_error',
                'payload' => [
                    'limit' => $limit?->getValue() ?? 'null',
                    'error' => $e->getMessage(),
                ],
            ]));

            return new Collection;
        }
    }

    public function findReadyToRun(?Iso8601DateTimeVO $now = null, ?LimitVO $limit = null): RecurringTaskReadyToRunResultRecord
    {
        try {
            $freshStateResult = $this->freshState($now);

            $query = $this->model->newQuery();
            $query->where('status', RecurringTaskStatus::PLAYING->value);

            if ($limit !== null) {
                $query->limit($limit->getValue());
            }

            /** @var Collection<int, RecurringTask> $models */
            $models = $query->get();

            $records = new RecurringTaskRecordCollection;
            foreach ($models as $model) {
                $records->add($this->modelToRecord($model));
            }

            return RecurringTaskReadyToRunResultRecord::from([
                'tasks' => $records,
                'fresh_state' => $freshStateResult,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error(LogDataRecord::from([
                'type' => 'recurring_task_find_ready_to_run_error',
                'payload' => [
                    'now' => $now?->getValue() ?? 'null',
                    'limit' => $limit?->getValue() ?? 'null',
                    'error' => $e->getMessage(),
                ],
            ]));

            return RecurringTaskReadyToRunResultRecord::from([
                'tasks' => new RecurringTaskRecordCollection,
                'fresh_state' => new FreshStateResultRecord(
                    waiting_to_playing: new CounterVO(0),
                    playing_to_finished: new CounterVO(0),
                    playing_to_canceled: new CounterVO(0),
                ),
            ]);
        }
    }

    public function findByAlias(TaskAliasVO $alias): ?RecurringTask
    {
        try {
            $this->freshState();

            $filters = RecurringTaskFiltersRecord::from([
                'alias' => $alias,
            ]);

            $results = $this->findBy(FindByRecord::from(['filters' => $filters]));

            return $results->first() ?? null;
        } catch (\Throwable $e) {
            $this->logger->error(LogDataRecord::from([
                'type' => 'recurring_task_find_by_alias_error',
                'payload' => [
                    'alias' => $alias->getValue(),
                    'error' => $e->getMessage(),
                ],
            ]));

            return null;
        }
    }

    // ==================== MOVES ====================

    public function moveToPlaying(RecurringTaskRecord $task): bool
    {
        try {
            $existingTask = $this->findByAlias($task->alias);
            if ($existingTask === null) {
                return false;
            }

            $this->update($existingTask->getId()->getValue(), RecurringTaskRecord::from([
                'alias' => $task->alias,
                'fqcn' => $task->fqcn,
                'payload' => $task->payload,
                'interval_seconds' => $task->interval_seconds,
                'start_at' => $task->start_at,
                'end_at' => $task->end_at,
                'status' => RecurringTaskStatus::PLAYING,
                'last_run_at' => $task->last_run_at,
                'failed_attempts' => $task->failed_attempts ?? new CounterVO(0),
                'max_failed_attempts' => $task->max_failed_attempts ?? new MaxFailedAttemptsVO(3),
            ]));

            return true;
        } catch (\Throwable $e) {
            $this->logger->error(LogDataRecord::from([
                'type' => 'recurring_task_move_to_playing_error',
                'payload' => [
                    'alias' => $task->alias->getValue(),
                    'error' => $e->getMessage(),
                ],
            ]));

            return false;
        }
    }

    public function moveToPaused(RecurringTaskRecord $task): bool
    {
        try {
            $existingTask = $this->findByAlias($task->alias);
            if ($existingTask === null) {
                return false;
            }

            $this->update($existingTask->getId()->getValue(), RecurringTaskRecord::from([
                'alias' => $task->alias,
                'fqcn' => $task->fqcn,
                'payload' => $task->payload,
                'interval_seconds' => $task->interval_seconds,
                'start_at' => $task->start_at,
                'end_at' => $task->end_at,
                'status' => RecurringTaskStatus::PAUSED,
                'last_run_at' => $task->last_run_at,
                'failed_attempts' => $task->failed_attempts ?? new CounterVO(0),
                'max_failed_attempts' => $task->max_failed_attempts ?? new MaxFailedAttemptsVO(3),
            ]));

            return true;
        } catch (\Throwable $e) {
            $this->logger->error(LogDataRecord::from([
                'type' => 'recurring_task_move_to_paused_error',
                'payload' => [
                    'alias' => $task->alias->getValue(),
                    'error' => $e->getMessage(),
                ],
            ]));

            return false;
        }
    }

    public function moveToWaiting(RecurringTaskRecord $task): bool
    {
        try {
            $existingTask = $this->findByAlias($task->alias);
            if ($existingTask === null) {
                return false;
            }

            $this->update($existingTask->getId()->getValue(), RecurringTaskRecord::from([
                'alias' => $task->alias,
                'fqcn' => $task->fqcn,
                'payload' => $task->payload,
                'interval_seconds' => $task->interval_seconds,
                'start_at' => $task->start_at,
                'end_at' => $task->end_at,
                'status' => RecurringTaskStatus::WAITING,
                'last_run_at' => $task->last_run_at,
                'failed_attempts' => $task->failed_attempts ?? new CounterVO(0),
                'max_failed_attempts' => $task->max_failed_attempts ?? new MaxFailedAttemptsVO(3),
            ]));

            return true;
        } catch (\Throwable $e) {
            $this->logger->error(LogDataRecord::from([
                'type' => 'recurring_task_move_to_waiting_error',
                'payload' => [
                    'alias' => $task->alias->getValue(),
                    'error' => $e->getMessage(),
                ],
            ]));

            return false;
        }
    }

    public function moveToFinished(RecurringTaskRecord $task): bool
    {
        try {
            $existingTask = $this->findByAlias($task->alias);
            if ($existingTask === null) {
                return false;
            }

            $now = new Iso8601DateTimeVO;

            $this->update($existingTask->getId()->getValue(), RecurringTaskRecord::from([
                'alias' => $task->alias,
                'fqcn' => $task->fqcn,
                'payload' => $task->payload,
                'interval_seconds' => $task->interval_seconds,
                'start_at' => $task->start_at,
                'end_at' => $task->end_at,
                'status' => RecurringTaskStatus::FINISHED,
                'last_run_at' => $task->last_run_at,
                'finished_at' => $now,
                'failed_attempts' => $task->failed_attempts ?? new CounterVO(0),
                'max_failed_attempts' => $task->max_failed_attempts ?? new MaxFailedAttemptsVO(3),
            ]));

            return true;
        } catch (\Throwable $e) {
            $this->logger->error(LogDataRecord::from([
                'type' => 'recurring_task_move_to_finished_error',
                'payload' => [
                    'alias' => $task->alias->getValue(),
                    'error' => $e->getMessage(),
                ],
            ]));

            return false;
        }
    }

    public function moveToCanceled(RecurringTaskRecord $task): bool
    {
        try {
            $existingTask = $this->findByAlias($task->alias);
            if ($existingTask === null) {
                return false;
            }

            $now = new Iso8601DateTimeVO;

            $this->update($existingTask->getId()->getValue(), RecurringTaskRecord::from([
                'alias' => $task->alias,
                'fqcn' => $task->fqcn,
                'payload' => $task->payload,
                'interval_seconds' => $task->interval_seconds,
                'start_at' => $task->start_at,
                'end_at' => $task->end_at,
                'status' => RecurringTaskStatus::CANCELED,
                'last_run_at' => $task->last_run_at,
                'finished_at' => $now,
                'cancelled_at' => $now,
                'failed_attempts' => $task->failed_attempts ?? new CounterVO(0),
                'max_failed_attempts' => $task->max_failed_attempts ?? new MaxFailedAttemptsVO(3),
            ]));

            return true;
        } catch (\Throwable $e) {
            $this->logger->error(LogDataRecord::from([
                'type' => 'recurring_task_move_to_canceled_error',
                'payload' => [
                    'alias' => $task->alias->getValue(),
                    'error' => $e->getMessage(),
                ],
            ]));

            return false;
        }
    }

    // ==================== UPDATE ====================

    public function updateAfterRun(RecurringTaskRecord $task, bool $success, ?DescriptionVO $error = null): bool
    {
        try {
            $now = new Iso8601DateTimeVO;

            $existingTask = $this->findByAlias($task->alias);
            if ($existingTask === null) {
                return false;
            }

            $currentFailedAttempts = $existingTask->getFailedAttempts()->getValue();
            $maxFailedAttempts = $existingTask->getMaxFailedAttempts()->getValue();

            if ($success) {
                $newFailedAttempts = 0;
            } else {
                $newFailedAttempts = $currentFailedAttempts + 1;
            }

            $this->update($existingTask->getId()->getValue(), RecurringTaskRecord::from([
                'alias' => $task->alias,
                'fqcn' => $task->fqcn,
                'payload' => $task->payload,
                'interval_seconds' => $task->interval_seconds,
                'start_at' => $task->start_at,
                'end_at' => $task->end_at,
                'status' => RecurringTaskStatus::PLAYING,
                'last_run_at' => $now,
                'failed_attempts' => new CounterVO($newFailedAttempts),
                'max_failed_attempts' => new MaxFailedAttemptsVO($maxFailedAttempts),
            ]));

            $duration = (int) ($task->last_run_at?->diffInSeconds($now)->getValue() * 1000);

            $durationMs = $task->last_run_at !== null
                ? new MillisecondsVO($duration)
                : null;

            $this->debugRepository->addDebug(
                alias: $task->alias,
                fqcn: $task->fqcn,
                status: $success ? ExecutionStatus::SUCCEEDED : ExecutionStatus::FAILED,
                info: $success
                    ? new DescriptionVO('Recurring task executed successfully')
                    : ($error ?? new DescriptionVO('Recurring task execution failed')),
                duration_ms: $durationMs,
                error: $error,
            );

            return true;
        } catch (\Throwable $e) {
            $this->logger->error(LogDataRecord::from([
                'type' => 'recurring_task_update_after_run_error',
                'payload' => [
                    'alias' => $task->alias->getValue(),
                    'success' => $success,
                    'error' => $e->getMessage(),
                ],
            ]));

            return false;
        }
    }

    // ==================== COUNTS ====================

    public function countWaiting(): CounterVO
    {
        try {
            $this->freshState();
            $filters = RecurringTaskFiltersRecord::from([
                'status' => RecurringTaskStatus::WAITING,
            ]);

            return new CounterVO($this->count($filters));
        } catch (\Throwable $e) {
            $this->logger->error(LogDataRecord::from([
                'type' => 'recurring_task_count_waiting_error',
                'payload' => ['error' => $e->getMessage()],
            ]));

            return new CounterVO(0);
        }
    }

    public function countPlaying(): CounterVO
    {
        try {
            $this->freshState();
            $filters = RecurringTaskFiltersRecord::from([
                'status' => RecurringTaskStatus::PLAYING,
            ]);

            return new CounterVO($this->count($filters));
        } catch (\Throwable $e) {
            $this->logger->error(LogDataRecord::from([
                'type' => 'recurring_task_count_playing_error',
                'payload' => ['error' => $e->getMessage()],
            ]));

            return new CounterVO(0);
        }
    }

    public function countPaused(): CounterVO
    {
        try {
            $this->freshState();
            $filters = RecurringTaskFiltersRecord::from([
                'status' => RecurringTaskStatus::PAUSED,
            ]);

            return new CounterVO($this->count($filters));
        } catch (\Throwable $e) {
            $this->logger->error(LogDataRecord::from([
                'type' => 'recurring_task_count_paused_error',
                'payload' => ['error' => $e->getMessage()],
            ]));

            return new CounterVO(0);
        }
    }

    public function countFinished(): CounterVO
    {
        try {
            $this->freshState();
            $filters = RecurringTaskFiltersRecord::from([
                'status' => RecurringTaskStatus::FINISHED,
            ]);

            return new CounterVO($this->count($filters));
        } catch (\Throwable $e) {
            $this->logger->error(LogDataRecord::from([
                'type' => 'recurring_task_count_finished_error',
                'payload' => ['error' => $e->getMessage()],
            ]));

            return new CounterVO(0);
        }
    }

    public function countCanceled(): CounterVO
    {
        try {
            $this->freshState();
            $filters = RecurringTaskFiltersRecord::from([
                'status' => RecurringTaskStatus::CANCELED,
            ]);

            return new CounterVO($this->count($filters));
        } catch (\Throwable $e) {
            $this->logger->error(LogDataRecord::from([
                'type' => 'recurring_task_count_canceled_error',
                'payload' => ['error' => $e->getMessage()],
            ]));

            return new CounterVO(0);
        }
    }

    // ==================== PRIVATE METHODS ====================

    private function modelToRecord(RecurringTask $model): RecurringTaskRecord
    {
        return RecurringTaskRecord::from([
            'alias' => $model->getAlias(),
            'fqcn' => $model->getFqcn(),
            'payload' => $model->getPayload(),
            'interval_seconds' => $model->getIntervalSeconds(),
            'start_at' => $model->getStartAt(),
            'end_at' => $model->getEndAt(),
            'status' => $model->getStatus(),
            'last_run_at' => $model->getLastRunAt(),
            'finished_at' => $model->getFinishedAt(),
            'cancelled_at' => $model->getCancelledAt(),
            'failed_attempts' => $model->getFailedAttempts(),
            'max_failed_attempts' => $model->getMaxFailedAttempts(),
        ]);
    }
}
