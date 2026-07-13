<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Services;

use AndyDefer\Task\Services\Watchs\CycleCalculator;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\ValueObjects\DurationVO;

final class CycleCalculatorTest extends IntegrationTestCase
{
    public function test_constructor_sets_interval_and_duration(): void
    {
        $interval = new DurationVO(10);
        $duration = new DurationVO(100);

        $calculator = new CycleCalculator($interval, $duration);

        $this->assertSame($interval, $calculator->getInterval());
        $this->assertSame($duration, $calculator->getDuration());
    }

    public function test_constructor_without_duration(): void
    {
        $interval = new DurationVO(10);

        $calculator = new CycleCalculator($interval);

        $this->assertSame($interval, $calculator->getInterval());
        $this->assertNull($calculator->getDuration());
    }

    public function test_get_total_cycles_with_duration(): void
    {
        $interval = new DurationVO(10);
        $duration = new DurationVO(100);

        $calculator = new CycleCalculator($interval, $duration);

        $this->assertEquals(11, $calculator->getTotalCycles());
    }

    public function test_get_total_cycles_without_duration_returns_int_max(): void
    {
        $interval = new DurationVO(10);

        $calculator = new CycleCalculator($interval);

        $this->assertEquals(PHP_INT_MAX, $calculator->getTotalCycles());
    }

    public function test_get_total_cycles_floor_plus_one(): void
    {
        $interval = new DurationVO(3);
        $duration = new DurationVO(30);

        $calculator = new CycleCalculator($interval, $duration);

        // 30 / 3 = 10, + 1 = 11 cycles
        $this->assertEquals(11, $calculator->getTotalCycles());
    }

    public function test_get_total_cycles_with_remaining_time(): void
    {
        $interval = new DurationVO(3);
        $duration = new DurationVO(10);

        $calculator = new CycleCalculator($interval, $duration);

        // 10 / 3 = 3.33, floor = 3, + 1 = 4 cycles
        // 4 cycles × 3s = 12s (couvre les 10s)
        $this->assertEquals(4, $calculator->getTotalCycles());
    }

    public function test_get_estimated_duration(): void
    {
        $interval = new DurationVO(3);
        $duration = new DurationVO(30);

        $calculator = new CycleCalculator($interval, $duration);

        // 11 cycles × 3s = 30s
        $this->assertEquals(30.0, $calculator->getEstimatedDuration());
    }

    public function test_get_estimated_duration_without_duration(): void
    {
        $interval = new DurationVO(3);

        $calculator = new CycleCalculator($interval);

        $this->assertEquals(PHP_FLOAT_MAX, $calculator->getEstimatedDuration());
    }

    public function test_get_remaining_cycles(): void
    {
        $interval = new DurationVO(10);
        $duration = new DurationVO(100);

        $calculator = new CycleCalculator($interval, $duration);

        // 100 / 10 = 10, + 1 = 11 cycles
        $this->assertEquals(9, $calculator->getRemainingCycles(2));
        $this->assertEquals(0, $calculator->getRemainingCycles(11));
        $this->assertEquals(0, $calculator->getRemainingCycles(15));
    }

    public function test_should_continue_without_duration(): void
    {
        $interval = new DurationVO(10);

        $calculator = new CycleCalculator($interval);

        $this->assertTrue($calculator->shouldContinue(0, false));
        $this->assertTrue($calculator->shouldContinue(100, false));
        $this->assertFalse($calculator->shouldContinue(0, true));
    }

    public function test_should_continue_with_duration(): void
    {
        $interval = new DurationVO(10);
        $duration = new DurationVO(100);

        $calculator = new CycleCalculator($interval, $duration);

        // 100 / 10 = 10, + 1 = 11 cycles
        $this->assertTrue($calculator->shouldContinue(0, false));
        $this->assertTrue($calculator->shouldContinue(10, false));
        $this->assertFalse($calculator->shouldContinue(11, false));
        $this->assertFalse($calculator->shouldContinue(0, true));
    }

    public function test_get_next_wait_time_without_duration(): void
    {
        $interval = new DurationVO(10);

        $calculator = new CycleCalculator($interval);

        $this->assertEquals(10, $calculator->getNextWaitTime(1)->getValue());
        $this->assertEquals(10, $calculator->getNextWaitTime(5)->getValue());
    }

    public function test_get_next_wait_time_with_duration(): void
    {
        $interval = new DurationVO(10);
        $duration = new DurationVO(100);

        $calculator = new CycleCalculator($interval, $duration);

        // 100 / 10 = 10, + 1 = 11 cycles
        // On attend après chaque cycle sauf le dernier (cycle 11)
        $this->assertEquals(10, $calculator->getNextWaitTime(1)->getValue());
        $this->assertEquals(10, $calculator->getNextWaitTime(10)->getValue());
        $this->assertEquals(0, $calculator->getNextWaitTime(11)->getValue());
        $this->assertEquals(0, $calculator->getNextWaitTime(12)->getValue());
    }

    public function test_min_interval_is_respected(): void
    {
        $interval = new DurationVO(1);
        $duration = new DurationVO(10);

        $calculator = new CycleCalculator($interval, $duration);

        $totalCycles = $calculator->getTotalCycles();

        $this->assertGreaterThanOrEqual(1, $totalCycles);
    }

    public function test_get_total_cycles_with_exact_division(): void
    {
        $interval = new DurationVO(5);
        $duration = new DurationVO(30);

        $calculator = new CycleCalculator($interval, $duration);

        // 30 / 5 = 6, + 1 = 7 cycles
        $this->assertEquals(7, $calculator->getTotalCycles());
    }

    public function test_get_total_cycles_with_one_cycle(): void
    {
        $interval = new DurationVO(5);
        $duration = new DurationVO(3);

        $calculator = new CycleCalculator($interval, $duration);

        // 3 / 5 = 0, + 1 = 1 cycle
        $this->assertEquals(1, $calculator->getTotalCycles());
    }
}
