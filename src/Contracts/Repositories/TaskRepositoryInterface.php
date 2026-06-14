<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Repositories;

use AndyDefer\Task\Collections\TaskRecordCollection;
use AndyDefer\Task\Enums\TaskOrder;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\ValueObjects\TaskIdVO;

interface TaskRepositoryInterface
{
    public function save(TaskRecord $task): void;

    public function find(TaskIdVO $id): ?TaskRecord;

    public function findAll(?int $limit = null, TaskOrder $order = TaskOrder::OLDEST): TaskRecordCollection;

    public function delete(TaskIdVO $id): void;

    public function moveToCompleted(TaskRecord $task, bool $success = true): void;
}
