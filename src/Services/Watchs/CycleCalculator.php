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

    /**
     * Calcule le nombre total de cycles.
     *
     * Avec interval=3s, duration=30s :
     * - Cycle #1 : t=0s
     * - Cycle #2 : t=3s
     * - ...
     * - Cycle #10 : t=27s
     * - Cycle #11 : t=30s
     *
     * Donc total = (duration / interval) + 1
     */
    public function getTotalCycles(): int
    {
        if ($this->duration === null) {
            return PHP_INT_MAX;
        }

        // ✅ +1 pour compenser le premier cycle à t=0
        $total = (int) floor($this->duration->getValue() / $this->interval->getValue()) + 1;

        return max(1, $total);
    }

    public function getEstimatedDuration(): float
    {
        if ($this->duration === null) {
            return PHP_FLOAT_MAX;
        }

        return ($this->getTotalCycles() - 1) * $this->interval->getValue();
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

        // On attend après chaque cycle sauf le dernier
        if ($currentCycle < $this->getTotalCycles()) {
            return $this->interval;
        }

        return new DurationVO(0);
    }
}
