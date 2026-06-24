<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Contexts;

use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use Illuminate\Contracts\Foundation\Application;

interface UniqueTaskContextInterface
{
    public function setPayload(StrictDataObject $payload): void;

    public function getPayload(): StrictDataObject;

    public function setTaskId(TaskIdVO $taskId): void;

    public function getTaskId(): TaskIdVO;

    public function setAlias(TaskAliasVO $alias): void;

    public function getAlias(): TaskAliasVO;

    public function setScheduledAt(Iso8601DateTimeVO $scheduledAt): void;

    public function getScheduledAt(): Iso8601DateTimeVO;

    public function setLaravelApp(Application $app): void;

    public function getLaravelApp(): ?Application;
}
