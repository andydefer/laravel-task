<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services\Watchs;

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
final class CycleCalculator
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
     * Get the interval between cycles.
     *
     * @return DurationVO The interval in seconds
     */
    public function getInterval(): DurationVO
    {
        return $this->interval;
    }

    /**
     * Get the total execution duration.
     *
     * @return DurationVO|null The duration, or null if unlimited
     */
    public function getDuration(): ?DurationVO
    {
        return $this->duration;
    }

    /**
     * Calculate the total number of cycles.
     *
     * With interval=3s and duration=30s:
     * - Cycle #1: t=0s
     * - Cycle #2: t=3s
     * - ...
     * - Cycle #10: t=27s
     * - Cycle #11: t=30s
     *
     * Total cycles = floor(duration / interval) + 1
     *
     * @return int The total number of cycles, or PHP_INT_MAX if unlimited
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
     * Calculate the estimated total execution duration.
     *
     * This is the duration required to complete all cycles, which may be
     * slightly less than the configured duration due to flooring.
     *
     * @return float The estimated duration in seconds
     */
    public function getEstimatedDuration(): float
    {
        if ($this->duration === null) {
            return PHP_FLOAT_MAX;
        }

        return ($this->getTotalCycles() - 1) * $this->interval->getValue();
    }

    /**
     * Calculate the number of cycles remaining.
     *
     * @param  int  $currentCycle  The current cycle number (1-indexed)
     * @return int The number of cycles remaining, minimum 0
     */
    public function getRemainingCycles(int $currentCycle): int
    {
        $total = $this->getTotalCycles();

        return max(0, $total - $currentCycle);
    }

    /**
     * Determine whether the watch loop should continue.
     *
     * @param  int  $currentCycle  The current cycle number (1-indexed)
     * @param  bool  $shouldStop  Whether a stop signal has been received
     * @return bool True if the loop should continue
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
     * Calculate the wait time before the next cycle.
     *
     * @param  int  $currentCycle  The current cycle number (1-indexed)
     * @return DurationVO The wait time in seconds, or 0 if this is the last cycle
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
