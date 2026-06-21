<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Services;

use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Contracts\Configs\RecurringTaskConfigInterface;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

interface RecurringTaskServiceInterface
{
    public function register(string $taskClass, StrictDataObject $payload, RecurringTaskConfigInterface $config): TaskSignatureVO;

    public function run(TaskSignatureVO $alias): bool;

    public function find(TaskSignatureVO $alias): ?RecurringTaskRecord;

    public function delete(TaskSignatureVO $alias): void;

    public function process(?int $limit = null): array;
}
