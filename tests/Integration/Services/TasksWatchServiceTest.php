<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Services;

use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\Task\Contracts\Services\TasksWatchServiceInterface;
use AndyDefer\Task\Records\CycleResultRecord;
use AndyDefer\Task\Services\TasksWatchService;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;

final class TasksWatchServiceTest extends IntegrationTestCase
{
    private TasksWatchServiceInterface $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TasksWatchService;
    }

    public function test_build_process_tasks_arguments_with_all_options(): void
    {
        $arguments = $this->service->buildProcessTasksArguments(
            uniqueOnly: true,
            recurringOnly: false,
            limit: 10,
            verbose: true
        );

        $this->assertInstanceOf(StringTypedCollection::class, $arguments);
        $this->assertTrue($arguments->contains('--unique-only'));
        $this->assertFalse($arguments->contains('--recurring-only'));
        $this->assertTrue($arguments->contains('--limit=10'));
        $this->assertTrue($arguments->contains('--verbose'));
    }

    public function test_build_process_tasks_arguments_with_recurring_only(): void
    {
        $arguments = $this->service->buildProcessTasksArguments(
            uniqueOnly: false,
            recurringOnly: true,
            limit: null,
            verbose: false
        );

        $this->assertFalse($arguments->contains('--unique-only'));
        $this->assertTrue($arguments->contains('--recurring-only'));
        $this->assertFalse($arguments->contains('--limit=10'));
        $this->assertFalse($arguments->contains('--verbose'));
    }

    public function test_build_process_tasks_arguments_with_limit_and_no_verbose(): void
    {
        $arguments = $this->service->buildProcessTasksArguments(
            uniqueOnly: false,
            recurringOnly: false,
            limit: 5,
            verbose: false
        );

        $this->assertTrue($arguments->contains('--limit=5'));
        $this->assertFalse($arguments->contains('--verbose'));
        $this->assertFalse($arguments->contains('--unique-only'));
        $this->assertFalse($arguments->contains('--recurring-only'));
    }

    public function test_calculate_elapsed_seconds_returns_zero_for_null_start(): void
    {
        $result = $this->service->calculateElapsedSeconds(null);
        $this->assertEquals(0.0, $result);
    }

    public function test_calculate_elapsed_seconds_returns_positive_value(): void
    {
        $start = new Iso8601DateTimeVO(now()->subSeconds(5)->toIso8601String());
        $result = $this->service->calculateElapsedSeconds($start);
        $this->assertGreaterThanOrEqual(4.5, $result);
        $this->assertLessThanOrEqual(6.0, $result);
    }

    public function test_format_duration_formats_seconds_correctly(): void
    {
        $this->assertEquals('1h 30m 45s', $this->service->formatDuration(5445));
        $this->assertEquals('30m 45s', $this->service->formatDuration(1845));
        $this->assertEquals('45s', $this->service->formatDuration(45));
        $this->assertEquals('1h', $this->service->formatDuration(3600));
        $this->assertEquals('1h 1s', $this->service->formatDuration(3601));
        $this->assertEquals('0s', $this->service->formatDuration(0));
    }

    public function test_should_continue_returns_false_when_should_stop(): void
    {
        $result = $this->service->shouldContinue(
            shouldStop: true,
            duration: null,
            startedAt: new Iso8601DateTimeVO
        );
        $this->assertFalse($result);
    }

    public function test_should_continue_returns_true_when_no_duration_and_not_stopped(): void
    {
        $result = $this->service->shouldContinue(
            shouldStop: false,
            duration: null,
            startedAt: new Iso8601DateTimeVO
        );
        $this->assertTrue($result);
    }

    public function test_should_continue_returns_true_when_duration_not_reached(): void
    {
        $startedAt = new Iso8601DateTimeVO(now()->subSeconds(5)->toIso8601String());

        $result = $this->service->shouldContinue(
            shouldStop: false,
            duration: 60,
            startedAt: $startedAt
        );
        $this->assertTrue($result);
    }

    public function test_should_continue_returns_false_when_duration_reached(): void
    {
        $startedAt = new Iso8601DateTimeVO(now()->subSeconds(65)->toIso8601String());

        $result = $this->service->shouldContinue(
            shouldStop: false,
            duration: 60,
            startedAt: $startedAt
        );
        $this->assertFalse($result);
    }

    public function test_wait_for_interval_breaks_when_callback_returns_false(): void
    {
        $called = 0;

        $this->service->waitForInterval(10, function () use (&$called) {
            $called++;

            return $called < 3;
        });

        $this->assertEquals(3, $called);
    }

    public function test_execute_cycle_handles_exception_gracefully(): void
    {
        // Créer un service avec un mock qui lève une exception
        $service = new class extends TasksWatchService
        {
            public function callProcessTasks(StringTypedCollection $arguments): string
            {
                throw new \RuntimeException('Test exception');
            }
        };

        $arguments = new StringTypedCollection;
        $arguments->add('--unique-only');

        $result = $service->executeCycle(
            cycleNumber: 1,
            arguments: $arguments,
            cycleStartedAt: new Iso8601DateTimeVO
        );

        $this->assertInstanceOf(CycleResultRecord::class, $result);
        $this->assertEquals(0, $result->success);
        $this->assertEquals(0, $result->failed);
        $this->assertEquals(1, $result->errors);
        $this->assertTrue($result->hasErrors);
    }
}
