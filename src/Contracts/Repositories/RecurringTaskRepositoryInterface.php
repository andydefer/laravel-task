<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Repositories;

use AndyDefer\Task\Collections\RecurringTaskRecordCollection;
use AndyDefer\Task\Enums\TaskOrder;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

interface RecurringTaskRepositoryInterface
{
    public function save(RecurringTaskRecord $task): void;

    public function find(TaskSignatureVO $signature): ?RecurringTaskRecord;

    public function findAll(?int $limit = null, ?TaskOrder $order = TaskOrder::OLDEST): RecurringTaskRecordCollection;

    public function delete(TaskSignatureVO $signature): void;

    public function updateAfterRun(RecurringTaskRecord $task, bool $success, ?string $error = null): void;
}
