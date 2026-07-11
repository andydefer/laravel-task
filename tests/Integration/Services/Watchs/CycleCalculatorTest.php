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

        $this->assertEquals(10, $calculator->getTotalCycles());
    }

    public function test_get_total_cycles_without_duration_returns_int_max(): void
    {
        $interval = new DurationVO(10);

        $calculator = new CycleCalculator($interval);

        $this->assertEquals(PHP_INT_MAX, $calculator->getTotalCycles());
    }

    public function test_get_total_cycles_rounds_up(): void
    {
        $interval = new DurationVO(3);
        $duration = new DurationVO(10);

        $calculator = new CycleCalculator($interval, $duration);

        $this->assertEquals(4, $calculator->getTotalCycles());
    }

    public function test_get_remaining_cycles(): void
    {
        $interval = new DurationVO(10);
        $duration = new DurationVO(100);

        $calculator = new CycleCalculator($interval, $duration);

        $this->assertEquals(8, $calculator->getRemainingCycles(2));
        $this->assertEquals(0, $calculator->getRemainingCycles(10));
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

        $this->assertTrue($calculator->shouldContinue(0, false));
        $this->assertTrue($calculator->shouldContinue(9, false));
        $this->assertFalse($calculator->shouldContinue(10, false));
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

        $testCases = [
            ['cycle' => 1, 'expected' => 10],
            ['cycle' => 9, 'expected' => 10],
            ['cycle' => 10, 'expected' => 0],
            ['cycle' => 11, 'expected' => 0],
        ];

        // Assertions
        $this->assertEquals(10, $calculator->getNextWaitTime(1)->getValue());
        $this->assertEquals(10, $calculator->getNextWaitTime(9)->getValue());
        $this->assertEquals(0, $calculator->getNextWaitTime(10)->getValue());
        $this->assertEquals(0, $calculator->getNextWaitTime(11)->getValue());
    }

    public function test_min_interval_is_respected(): void
    {
        $interval = new DurationVO(1);
        $duration = new DurationVO(10);

        $calculator = new CycleCalculator($interval, $duration);

        $totalCycles = $calculator->getTotalCycles();

        $this->assertGreaterThanOrEqual(1, $totalCycles);
    }
}
