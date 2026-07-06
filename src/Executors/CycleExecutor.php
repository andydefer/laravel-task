<?php

declare(strict_types=1);

namespace AndyDefer\Task\Executors;

use AndyDefer\Task\Contracts\Services\WatchInterface;
use AndyDefer\Task\Contracts\Services\WatchRendererInterface;
use AndyDefer\Task\Records\CycleResultRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;

final class CycleExecutor
{
    public function __construct(
        private readonly WatchInterface $service,
        private readonly WatchRendererInterface $renderer
    ) {}

    public function execute(
        CounterVO $cycleCount,
        bool $hasOptionUniqueOnly,
        bool $hasOptionRecurringOnly,
        ?LimitVO $limit,
        bool $verbose,
        bool $shouldStop,
        DurationVO $intervalSeconds
    ): ?CycleResultRecord {
        if ($shouldStop) {
            return null;
        }

        $cycleStartedAt = new Iso8601DateTimeVO;
        $this->renderer->renderCycleStart($cycleCount, $cycleStartedAt);

        $arguments = $this->service->buildArguments(
            uniqueOnly: $hasOptionUniqueOnly,
            recurringOnly: $hasOptionRecurringOnly,
            limit: $limit,
            verbose: $verbose
        );

        $result = $this->service->executeCycle(
            $cycleCount,
            $arguments,
            $cycleStartedAt
        );

        $this->renderer->renderCycleEnd($result, $cycleStartedAt, $intervalSeconds);

        return $result;
    }
}
