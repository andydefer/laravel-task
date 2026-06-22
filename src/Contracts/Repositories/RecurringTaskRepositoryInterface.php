<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Repositories;

use AndyDefer\Repository\AbstractRepositoryInterface;
use AndyDefer\Task\Models\RecurringTask;
use AndyDefer\Task\Records\RecurringTaskReadyToRunResultRecord;
use AndyDefer\Task\Records\RecurringTaskRecord;
use Illuminate\Support\Collection;

interface RecurringTaskRepositoryInterface extends AbstractRepositoryInterface
{
    // ==================== FINDERS ====================

    public function findWaiting(?int $limit = null): Collection;

    public function findPlaying(?int $limit = null): Collection;

    public function findPaused(?int $limit = null): Collection;

    public function findFinished(?int $limit = null): Collection;

    public function findCanceled(?int $limit = null): Collection;

    public function findReadyToRun(string $now, ?int $limit = null): RecurringTaskReadyToRunResultRecord;

    public function findByAlias(string $alias): ?RecurringTask;

    // ==================== MOVES ====================

    public function moveToPlaying(RecurringTaskRecord $task): void;

    public function moveToPaused(RecurringTaskRecord $task): void;

    public function moveToWaiting(RecurringTaskRecord $task): void;

    public function moveToFinished(RecurringTaskRecord $task): void;

    public function moveToCanceled(RecurringTaskRecord $task): void;

    // ==================== UPDATE ====================

    public function updateAfterRun(RecurringTaskRecord $task, bool $success, ?string $error = null): void;

    // ==================== COUNTS ====================

    public function countWaiting(): int;

    public function countPlaying(): int;

    public function countPaused(): int;

    public function countFinished(): int;

    public function countCanceled(): int;
}
