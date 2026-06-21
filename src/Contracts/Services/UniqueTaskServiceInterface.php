<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Services;

use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Contracts\Configs\UniqueTaskConfigInterface;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\ValueObjects\TaskIdVO;

interface UniqueTaskServiceInterface
{
    public function register(string $taskClass, StrictDataObject $payload, ?UniqueTaskConfigInterface $config = null): TaskIdVO;

    public function run(TaskIdVO $taskId): bool;

    public function find(TaskIdVO $taskId): ?UniqueTaskRecord;

    public function delete(TaskIdVO $taskId): void;

    public function process(?int $limit = null): array;
}
