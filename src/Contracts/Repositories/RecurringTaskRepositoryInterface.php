<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Repositories;

use AndyDefer\Repository\AbstractRepositoryInterface;
use AndyDefer\Task\Models\RecurringTask;
use AndyDefer\Task\Records\RecurringTaskReadyToRunResultRecord;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use Illuminate\Support\Collection;

interface RecurringTaskRepositoryInterface extends AbstractRepositoryInterface
{
    // ==================== FINDERS ====================

    public function findWaiting(LimitVO $limit = new LimitVO): Collection;

    public function findPlaying(LimitVO $limit = new LimitVO): Collection;

    public function findPaused(LimitVO $limit = new LimitVO): Collection;

    public function findFinished(LimitVO $limit = new LimitVO): Collection;

    public function findCanceled(LimitVO $limit = new LimitVO): Collection;

    public function findReadyToRun(?Iso8601DateTimeVO $now = new Iso8601DateTimeVO, ?LimitVO $limit = new LimitVO): RecurringTaskReadyToRunResultRecord;

    /**
     * Trouve une tâche par son alias.
     *
     * @param  TaskAliasVO  $alias  Alias de la tâche
     */
    public function findByAlias(TaskAliasVO $alias): ?RecurringTask;

    // ==================== MOVES ====================

    public function moveToPlaying(RecurringTaskRecord $task): bool;

    public function moveToPaused(RecurringTaskRecord $task): bool;

    public function moveToWaiting(RecurringTaskRecord $task): bool;

    public function moveToFinished(RecurringTaskRecord $task): bool;

    public function moveToCanceled(RecurringTaskRecord $task): bool;

    // ==================== UPDATE ====================

    public function updateAfterRun(RecurringTaskRecord $task, bool $success, ?DescriptionVO $error = new DescriptionVO('Simple updateAfterRun')): bool;

    // ==================== COUNTS ====================

    public function countWaiting(): CounterVO;

    public function countPlaying(): CounterVO;

    public function countPaused(): CounterVO;

    public function countFinished(): CounterVO;

    public function countCanceled(): CounterVO;
}
