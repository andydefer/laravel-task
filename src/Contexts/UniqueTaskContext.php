<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contexts;

use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Contracts\Contexts\UniqueTaskContextInterface;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use Illuminate\Contracts\Foundation\Application;

final class UniqueTaskContext implements UniqueTaskContextInterface
{
    private StrictDataObject $payload;

    private TaskIdVO $taskId;

    private TaskAliasVO $alias;

    private Iso8601DateTimeVO $scheduledAt;

    private ?Application $app = null;

    public function setPayload(StrictDataObject $payload): void
    {
        $this->payload = $payload;
    }

    public function getPayload(): StrictDataObject
    {
        return $this->payload;
    }

    public function setTaskId(TaskIdVO $taskId): void
    {
        $this->taskId = $taskId;
    }

    public function getTaskId(): TaskIdVO
    {
        return $this->taskId;
    }

    public function setAlias(TaskAliasVO $alias): void
    {
        $this->alias = $alias;
    }

    public function getAlias(): TaskAliasVO
    {
        return $this->alias;
    }

    public function setScheduledAt(Iso8601DateTimeVO $scheduledAt): void
    {
        $this->scheduledAt = $scheduledAt;
    }

    public function getScheduledAt(): Iso8601DateTimeVO
    {
        return $this->scheduledAt;
    }

    public function setLaravelApp(Application $app): void
    {
        $this->app = $app;
    }

    public function getLaravelApp(): ?Application
    {
        return $this->app;
    }
}
