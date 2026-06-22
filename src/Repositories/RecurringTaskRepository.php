<?php

declare(strict_types=1);

namespace AndyDefer\Task\Repositories;

use AndyDefer\DomainStructures\Abstracts\AbstractRecord;
use AndyDefer\Repository\AbstractRepository;
use AndyDefer\Repository\Records\FindByRecord;
use AndyDefer\Task\Collections\RecurringTaskRecordCollection;
use AndyDefer\Task\Contracts\Repositories\RecurringTaskRepositoryInterface;
use AndyDefer\Task\Enums\RecurringTaskStatus;
use AndyDefer\Task\Models\RecurringTask;
use AndyDefer\Task\Records\FreshStateResultRecord;
use AndyDefer\Task\Records\RecurringTaskFiltersRecord;
use AndyDefer\Task\Records\RecurringTaskReadyToRunResultRecord;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use Carbon\Carbon;
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

    /**
     * Récupère la date courante en respectant le freeze de Carbon (pour les tests)
     */
    private function getCurrentTimestamp(): string
    {
        return Carbon::now()->toIso8601String();
    }

    /**
     * Rafraîchit les états des tâches et retourne les statistiques.
     * - Les tâches WAITING dont start_at est atteint passent en PLAYING
     * - Les tâches PLAYING dont end_at est dépassé passent en FINISHED
     */
    private function freshState(?string $now = null): FreshStateResultRecord
    {
        $timestamp = $now ?? $this->getCurrentTimestamp();
        $dateTime = new \DateTime($timestamp);
        $dateTime->setTimezone(new \DateTimeZone('UTC'));
        $formattedNow = $dateTime->format('Y-m-d H:i:s');

        // ✅ Étape 1: WAITING → PLAYING (start_at <= now)
        $waitingToPlaying = $this->model->newQuery()
            ->where('status', RecurringTaskStatus::WAITING->value)
            ->where('start_at', '<=', $formattedNow)
            ->update(['status' => RecurringTaskStatus::PLAYING->value]);

        // ✅ Étape 2: PLAYING → FINISHED (end_at <= now)
        $playingToFinished = $this->model->newQuery()
            ->where('status', RecurringTaskStatus::PLAYING->value)
            ->where('end_at', '<=', $formattedNow)
            ->update(['status' => RecurringTaskStatus::FINISHED->value, 'finished_at' => $formattedNow]);

        return new FreshStateResultRecord(
            waiting_to_playing: new CounterVO($waitingToPlaying),
            playing_to_finished: new CounterVO($playingToFinished),
        );
    }

    // ==================== FINDERS ====================

    public function findWaiting(?int $limit = null): Collection
    {
        $this->freshState();
        $filters = new RecurringTaskFiltersRecord(status: RecurringTaskStatus::WAITING);

        return $this->findBy(new FindByRecord(filters: $filters, limit: $limit));
    }

    public function findPlaying(?int $limit = null): Collection
    {
        $this->freshState();
        $filters = new RecurringTaskFiltersRecord(status: RecurringTaskStatus::PLAYING);

        return $this->findBy(new FindByRecord(filters: $filters, limit: $limit));
    }

    public function findPaused(?int $limit = null): Collection
    {
        $this->freshState();
        $filters = new RecurringTaskFiltersRecord(status: RecurringTaskStatus::PAUSED);

        return $this->findBy(new FindByRecord(filters: $filters, limit: $limit));
    }

    public function findFinished(?int $limit = null): Collection
    {
        $this->freshState();
        $filters = new RecurringTaskFiltersRecord(status: RecurringTaskStatus::FINISHED);

        return $this->findBy(new FindByRecord(filters: $filters, limit: $limit));
    }

    public function findCanceled(?int $limit = null): Collection
    {
        $this->freshState();
        $filters = new RecurringTaskFiltersRecord(status: RecurringTaskStatus::CANCELED);

        return $this->findBy(new FindByRecord(filters: $filters, limit: $limit));
    }

    public function findReadyToRun(?string $now = null, ?int $limit = null): RecurringTaskReadyToRunResultRecord
    {
        $timestamp = $now ?? $this->getCurrentTimestamp();

        // ✅ freshState retourne les statistiques
        $freshStateResult = $this->freshState($timestamp);

        // ✅ Récupérer les tâches en PLAYING
        $query = $this->model->newQuery();
        $query->where('status', RecurringTaskStatus::PLAYING->value);

        if ($limit !== null) {
            $query->limit($limit);
        }

        /** @var Collection<int, RecurringTask> $models */
        $models = $query->get();

        // ✅ Convertir en RecurringTaskRecordCollection
        $records = new RecurringTaskRecordCollection;
        foreach ($models as $model) {
            $records->add($this->modelToRecord($model));
        }

        return new RecurringTaskReadyToRunResultRecord(
            tasks: $records,
            fresh_state: $freshStateResult,
        );
    }

    public function findByAlias(string $alias): ?RecurringTask
    {
        $this->freshState();
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
        $this->freshState();
        $filters = new RecurringTaskFiltersRecord(status: RecurringTaskStatus::WAITING);

        return $this->count($filters);
    }

    public function countPlaying(): int
    {
        $this->freshState();
        $filters = new RecurringTaskFiltersRecord(status: RecurringTaskStatus::PLAYING);

        return $this->count($filters);
    }

    public function countPaused(): int
    {
        $this->freshState();
        $filters = new RecurringTaskFiltersRecord(status: RecurringTaskStatus::PAUSED);

        return $this->count($filters);
    }

    public function countFinished(): int
    {
        $this->freshState();
        $filters = new RecurringTaskFiltersRecord(status: RecurringTaskStatus::FINISHED);

        return $this->count($filters);
    }

    public function countCanceled(): int
    {
        $this->freshState();
        $filters = new RecurringTaskFiltersRecord(status: RecurringTaskStatus::CANCELED);

        return $this->count($filters);
    }

    /**
     * Convertit un modèle Eloquent RecurringTask en RecurringTaskRecord.
     */
    private function modelToRecord(RecurringTask $model): RecurringTaskRecord
    {
        return RecurringTaskRecord::from([
            'alias' => $model->getAlias(),
            'fqcn' => $model->getFqcn(),
            'payload' => $model->getPayload(),
            'interval_seconds' => $model->getIntervalSeconds(),
            'start_at' => $model->getStartAt(),
            'end_at' => $model->getEndAtVO(),
            'status' => $model->getStatus(),
            'last_run_at' => $model->getLastRunAt(),
            'finished_at' => $model->getFinishedAt(),
            'cancelled_at' => $model->getCancelledAt(),
        ]);
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
