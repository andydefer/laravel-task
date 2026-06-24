<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Contexts;

use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use Illuminate\Contracts\Foundation\Application;

interface RecurringTaskContextInterface
{
    public function setPayload(StrictDataObject $payload): void;

    public function getPayload(): StrictDataObject;

    public function setAlias(TaskAliasVO $alias): void;

    public function getAlias(): TaskAliasVO;

    public function setIntervalSeconds(DurationVO $intervalSeconds): void;

    public function getIntervalSeconds(): DurationVO;

    public function setStartAt(?Iso8601DateTimeVO $startAt): void;

    public function getStartAt(): ?Iso8601DateTimeVO;

    public function setEndAt(?Iso8601DateTimeVO $endAt): void;

    public function getEndAt(): ?Iso8601DateTimeVO;

    public function setLaravelApp(Application $app): void;

    public function getLaravelApp(): ?Application;
}
