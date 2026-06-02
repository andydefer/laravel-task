<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Unit\Services;

use AndyDefer\Task\Services\BatchResult;
use AndyDefer\Task\Tests\UnitTestCase;

final class BatchResultTest extends UnitTestCase
{
    public function test_initial_result_is_empty(): void
    {
        // Arrange & Act
        $result = new BatchResult();

        // Assert
        $this->assertSame(0, $result->getTotal());
        $this->assertSame(0, $result->getTotalSuccess());
        $this->assertSame(0, $result->getTotalFailed());
        $this->assertSame(0, $result->getUniqueSuccess());
        $this->assertSame(0, $result->getUniqueFailed());
        $this->assertSame(0, $result->getRecurringSuccess());
        $this->assertSame(0, $result->getRecurringFailed());
        $this->assertTrue($result->isSuccessful());
        $this->assertFalse($result->hasFailures());
        $this->assertEmpty($result->getUniqueResults());
        $this->assertEmpty($result->getRecurringResults());
        $this->assertEmpty($result->getErrors());
    }

    public function test_add_unique_task_success(): void
    {
        // Arrange
        $result = new BatchResult();

        // Act
        $result->addUniqueTask('task-1', true);

        // Assert
        $this->assertSame(1, $result->getTotal());
        $this->assertSame(1, $result->getTotalSuccess());
        $this->assertSame(0, $result->getTotalFailed());
        $this->assertSame(1, $result->getUniqueSuccess());
        $this->assertSame(0, $result->getUniqueFailed());
        $this->assertTrue($result->isSuccessful());
        $this->assertFalse($result->hasFailures());
        $this->assertArrayHasKey('task-1', $result->getUniqueResults());
        $this->assertTrue($result->getUniqueResults()['task-1']);
    }

    public function test_add_unique_task_failure(): void
    {
        // Arrange
        $result = new BatchResult();

        // Act
        $result->addUniqueTask('task-1', false, 'Something went wrong');

        // Assert
        $this->assertSame(1, $result->getTotal());
        $this->assertSame(0, $result->getTotalSuccess());
        $this->assertSame(1, $result->getTotalFailed());
        $this->assertSame(0, $result->getUniqueSuccess());
        $this->assertSame(1, $result->getUniqueFailed());
        $this->assertFalse($result->isSuccessful());
        $this->assertTrue($result->hasFailures());
        $this->assertArrayHasKey('task-1', $result->getUniqueResults());
        $this->assertFalse($result->getUniqueResults()['task-1']);
        $this->assertArrayHasKey('task-1', $result->getErrors());
        $this->assertSame('Something went wrong', $result->getErrors()['task-1']);
    }

    public function test_add_recurring_task_success(): void
    {
        // Arrange
        $result = new BatchResult();

        // Act
        $result->addRecurringTask('recurring-1', true);

        // Assert
        $this->assertSame(1, $result->getTotal());
        $this->assertSame(1, $result->getTotalSuccess());
        $this->assertSame(0, $result->getTotalFailed());
        $this->assertSame(1, $result->getRecurringSuccess());
        $this->assertSame(0, $result->getRecurringFailed());
        $this->assertTrue($result->isSuccessful());
        $this->assertArrayHasKey('recurring-1', $result->getRecurringResults());
        $this->assertTrue($result->getRecurringResults()['recurring-1']);
    }

    public function test_add_recurring_task_failure(): void
    {
        // Arrange
        $result = new BatchResult();

        // Act
        $result->addRecurringTask('recurring-1', false, 'Recurring task failed');

        // Assert
        $this->assertSame(1, $result->getTotal());
        $this->assertSame(0, $result->getTotalSuccess());
        $this->assertSame(1, $result->getTotalFailed());
        $this->assertSame(0, $result->getRecurringSuccess());
        $this->assertSame(1, $result->getRecurringFailed());
        $this->assertFalse($result->isSuccessful());
        $this->assertTrue($result->hasFailures());
        $this->assertArrayHasKey('recurring-1', $result->getRecurringResults());
        $this->assertFalse($result->getRecurringResults()['recurring-1']);
        $this->assertArrayHasKey('recurring-1', $result->getErrors());
        $this->assertSame('Recurring task failed', $result->getErrors()['recurring-1']);
    }

    public function test_mixed_tasks_success_and_failure(): void
    {
        // Arrange
        $result = new BatchResult();

        // Act
        $result->addUniqueTask('unique-1', true);
        $result->addUniqueTask('unique-2', false, 'Failed');
        $result->addRecurringTask('recurring-1', true);
        $result->addRecurringTask('recurring-2', false, 'Failed');

        // Assert
        $this->assertSame(4, $result->getTotal());
        $this->assertSame(2, $result->getTotalSuccess());
        $this->assertSame(2, $result->getTotalFailed());
        $this->assertSame(1, $result->getUniqueSuccess());
        $this->assertSame(1, $result->getUniqueFailed());
        $this->assertSame(1, $result->getRecurringSuccess());
        $this->assertSame(1, $result->getRecurringFailed());
        $this->assertFalse($result->isSuccessful());
        $this->assertTrue($result->hasFailures());
        $this->assertCount(2, $result->getErrors());
    }

    public function test_partial_success_when_some_tasks_succeed(): void
    {
        // Arrange
        $result = new BatchResult();

        // Act
        $result->addUniqueTask('success-1', true);
        $result->addUniqueTask('failed-1', false);

        // Assert
        $this->assertFalse($result->isSuccessful());
        $this->assertTrue($result->hasFailures());
        $this->assertSame(1, $result->getTotalSuccess());
        $this->assertSame(1, $result->getTotalFailed());
    }

    public function test_get_started_at_returns_timestamp(): void
    {
        // Act
        $result = new BatchResult();
        $startedAt = $result->getStartedAt();

        // Assert
        $this->assertInstanceOf(\DateTimeImmutable::class, $startedAt);
        $this->assertLessThanOrEqual(time(), $startedAt->getTimestamp());
    }

    public function test_get_finished_at_returns_current_time(): void
    {
        // Arrange
        $result = new BatchResult();
        $before = time();

        // Act
        $finishedAt = $result->getFinishedAt();
        $after = time();

        // Assert
        $this->assertInstanceOf(\DateTimeImmutable::class, $finishedAt);
        $this->assertGreaterThanOrEqual($before, $finishedAt->getTimestamp());
        $this->assertLessThanOrEqual($after, $finishedAt->getTimestamp());
    }

    public function test_get_duration_milliseconds(): void
    {
        // Arrange
        $result = new BatchResult();

        // Act - simulate a small delay (no usleep to avoid timing issues)
        $duration = $result->getDurationMilliseconds();

        // Assert - duration should be >= 0 (not checking upper bound due to test environment variability)
        $this->assertGreaterThanOrEqual(0, $duration);
        $this->assertIsInt($duration);
    }

    public function test_to_array_returns_correct_structure(): void
    {
        // Arrange
        $result = new BatchResult();
        $result->addUniqueTask('task-1', true);
        $result->addUniqueTask('task-2', false, 'Error');
        $result->addRecurringTask('recur-1', true);

        // Act
        $array = $result->toArray();

        // Assert
        $this->assertArrayHasKey('started_at', $array);
        $this->assertArrayHasKey('unique_success', $array);
        $this->assertArrayHasKey('unique_failed', $array);
        $this->assertArrayHasKey('recurring_success', $array);
        $this->assertArrayHasKey('recurring_failed', $array);
        $this->assertArrayHasKey('total_success', $array);
        $this->assertArrayHasKey('total_failed', $array);
        $this->assertArrayHasKey('total', $array);
        $this->assertArrayHasKey('has_failures', $array);
        $this->assertArrayHasKey('duration_ms', $array);

        $this->assertSame(1, $array['unique_success']);
        $this->assertSame(1, $array['unique_failed']);
        $this->assertSame(1, $array['recurring_success']);
        $this->assertSame(0, $array['recurring_failed']);
        $this->assertSame(2, $array['total_success']);
        $this->assertSame(1, $array['total_failed']);
        $this->assertSame(3, $array['total']);
        $this->assertTrue($array['has_failures']);
    }
}
