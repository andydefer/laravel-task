<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services\Watchs;

use AndyDefer\Task\Contracts\Services\Watchs\CycleCalculatorInterface;
use AndyDefer\Task\ValueObjects\DurationVO;

/**
 * Calculates cycle timing for task watch loops.
 *
 * Determines the number of cycles, estimated duration, and whether to continue
 * based on the configured interval and total duration.
 *
 * A cycle runs at t=0, t=interval, t=2*interval, etc. The total number of
 * cycles is (duration / interval) + 1 (to include the initial cycle).
 */
final class CycleCalculator implements CycleCalculatorInterface
{
    private DurationVO $interval;

    private ?DurationVO $duration;

    /**
     * Create a new cycle calculator.
     *
     * @param  DurationVO  $interval  The time between cycles in seconds
     * @param  DurationVO|null  $duration  The total execution duration, or null for unlimited
     */
    public function __construct(DurationVO $interval, ?DurationVO $duration = null)
    {
        $this->interval = $interval;
        $this->duration = $duration;
    }

    /**
     * {@inheritDoc}
     */
    public function getInterval(): DurationVO
    {
        return $this->interval;
    }

    /**
     * {@inheritDoc}
     */
    public function getDuration(): ?DurationVO
    {
        return $this->duration;
    }

    /**
     * {@inheritDoc}
     */
    public function getTotalCycles(): int
    {
        if ($this->duration === null) {
            return PHP_INT_MAX;
        }

        $total = (int) floor($this->duration->getValue() / $this->interval->getValue()) + 1;

        return max(1, $total);
    }

    /**
     * {@inheritDoc}
     */
    public function getEstimatedDuration(): float
    {
        if ($this->duration === null) {
            return PHP_FLOAT_MAX;
        }

        return ($this->getTotalCycles() - 1) * $this->interval->getValue();
    }

    /**
     * {@inheritDoc}
     */
    public function getRemainingCycles(int $currentCycle): int
    {
        $total = $this->getTotalCycles();

        return max(0, $total - $currentCycle);
    }

    /**
     * {@inheritDoc}
     */
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

    /**
     * {@inheritDoc}
     */
    public function getNextWaitTime(int $currentCycle): DurationVO
    {
        if ($this->duration === null) {
            return $this->interval;
        }

        if ($currentCycle < $this->getTotalCycles()) {
            return $this->interval;
        }

        return new DurationVO(0);
    }
}
