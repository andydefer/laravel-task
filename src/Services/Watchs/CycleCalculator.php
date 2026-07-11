<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services\Watchs;

use AndyDefer\Task\ValueObjects\DurationVO;

final class CycleCalculator
{
    private DurationVO $interval;

    private ?DurationVO $duration;

    public function __construct(DurationVO $interval, ?DurationVO $duration = null)
    {
        $this->interval = $interval;
        $this->duration = $duration;
    }

    public function getInterval(): DurationVO
    {
        return $this->interval;
    }

    public function getDuration(): ?DurationVO
    {
        return $this->duration;
    }

    public function getTotalCycles(): int
    {
        if ($this->duration === null) {
            return PHP_INT_MAX;
        }

        $total = (int) ceil($this->duration->getValue() / $this->interval->getValue());

        return max(1, $total);
    }

    public function getRemainingCycles(int $currentCycle): int
    {
        $total = $this->getTotalCycles();

        return max(0, $total - $currentCycle);
    }

    public function shouldContinue(int $currentCycle, bool $shouldStop): bool
    {
        if ($shouldStop) {
            return false;
        }

        if ($this->duration === null) {
            return true;
        }

        return $currentCycle < $this->getTotalCycles();
    }

    public function getNextWaitTime(int $currentCycle): DurationVO
    {
        if ($this->duration === null) {
            return $this->interval;
        }

        // On attend seulement si on n'est pas au dernier cycle
        if ($currentCycle < $this->getTotalCycles()) {
            return $this->interval;
        }

        return new DurationVO(0);
    }
}
