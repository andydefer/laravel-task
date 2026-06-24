<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contexts;

use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Contracts\Contexts\RecurringTaskContextInterface;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use Illuminate\Contracts\Foundation\Application;

final class RecurringTaskContext implements RecurringTaskContextInterface
{
    private StrictDataObject $payload;

    private TaskAliasVO $alias;

    private DurationVO $intervalSeconds;

    private ?Iso8601DateTimeVO $startAt = null;

    private ?Iso8601DateTimeVO $endAt = null;

    private ?Iso8601DateTimeVO $lastRunAt = null;

    private ?Iso8601DateTimeVO $nextRunAt = null;

    private ?Application $app = null;

    public function setPayload(StrictDataObject $payload): void
    {
        $this->payload = $payload;
    }

    public function getPayload(): StrictDataObject
    {
        return $this->payload;
    }

    public function setAlias(TaskAliasVO $alias): void
    {
        $this->alias = $alias;
    }

    public function getAlias(): TaskAliasVO
    {
        return $this->alias;
    }

    public function setIntervalSeconds(DurationVO $intervalSeconds): void
    {
        $this->intervalSeconds = $intervalSeconds;
    }

    public function getIntervalSeconds(): DurationVO
    {
        return $this->intervalSeconds;
    }

    public function setStartAt(?Iso8601DateTimeVO $startAt): void
    {
        $this->startAt = $startAt;
    }

    public function getStartAt(): ?Iso8601DateTimeVO
    {
        return $this->startAt;
    }

    public function setEndAt(?Iso8601DateTimeVO $endAt): void
    {
        $this->endAt = $endAt;
    }

    public function getEndAt(): ?Iso8601DateTimeVO
    {
        return $this->endAt;
    }

    public function setLastRunAt(?Iso8601DateTimeVO $lastRunAt): void
    {
        $this->lastRunAt = $lastRunAt;
    }

    public function getLastRunAt(): ?Iso8601DateTimeVO
    {
        return $this->lastRunAt;
    }

    public function setNextRunAt(?Iso8601DateTimeVO $nextRunAt): void
    {
        $this->nextRunAt = $nextRunAt;
    }

    public function getNextRunAt(): ?Iso8601DateTimeVO
    {
        return $this->nextRunAt;
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
