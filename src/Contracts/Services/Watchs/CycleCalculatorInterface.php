<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contracts\Services\Watchs;

use AndyDefer\Task\ValueObjects\DurationVO;

/**
 * Interface for cycle timing calculation in task watch loops.
 *
 * Determines the number of cycles, estimated duration, and whether to continue
 * based on the configured interval and total duration.
 */
interface CycleCalculatorInterface
{
    /**
     * Get the interval between cycles.
     *
     * @return DurationVO The interval in seconds
     */
    public function getInterval(): DurationVO;

    /**
     * Get the total execution duration.
     *
     * @return DurationVO|null The duration, or null if unlimited
     */
    public function getDuration(): ?DurationVO;

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
    public function getTotalCycles(): int;

    /**
     * Calculate the estimated total execution duration.
     *
     * This is the duration required to complete all cycles, which may be
     * slightly less than the configured duration due to flooring.
     *
     * @return float The estimated duration in seconds
     */
    public function getEstimatedDuration(): float;

    /**
     * Calculate the number of cycles remaining.
     *
     * @param  int  $currentCycle  The current cycle number (1-indexed)
     * @return int The number of cycles remaining, minimum 0
     */
    public function getRemainingCycles(int $currentCycle): int;

    /**
     * Determine whether the watch loop should continue.
     *
     * @param  int  $currentCycle  The current cycle number (1-indexed)
     * @param  bool  $shouldStop  Whether a stop signal has been received
     * @return bool True if the loop should continue
     */
    public function shouldContinue(int $currentCycle, bool $shouldStop): bool;

    /**
     * Calculate the wait time before the next cycle.
     *
     * @param  int  $currentCycle  The current cycle number (1-indexed)
     * @return DurationVO The wait time in seconds, or 0 if this is the last cycle
     */
    public function getNextWaitTime(int $currentCycle): DurationVO;
}
