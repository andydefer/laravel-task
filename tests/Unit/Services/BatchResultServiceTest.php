<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Unit\Services;

use AndyDefer\Task\Collections\RecurringResultCollection;
use AndyDefer\Task\Collections\TaskErrorCollection;
use AndyDefer\Task\Collections\UniqueResultCollection;
use AndyDefer\Task\Records\BatchResultRecord;
use AndyDefer\Task\Services\BatchResultService;
use AndyDefer\Task\Tests\UnitTestCase;
use AndyDefer\Task\ValueObjects\Iso8601DateTime;

final class BatchResultServiceTest extends UnitTestCase
{
    private BatchResultService $service;
    private Iso8601DateTime $startedAt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BatchResultService();
        $this->startedAt = new Iso8601DateTime();
    }

    private function createEmptyRecord(): BatchResultRecord
    {
        return new BatchResultRecord(
            startedAt: $this->startedAt,
            uniqueSuccess: 0,
            uniqueFailed: 0,
            recurringSuccess: 0,
            recurringFailed: 0,
            uniqueResults: new UniqueResultCollection(),
            recurringResults: new RecurringResultCollection(),
            errors: new TaskErrorCollection(),
        );
    }

    public function test_with_unique_task_success(): void
    {
        // Arrange
        $record = $this->createEmptyRecord();

        // Act
        $result = $this->service->withUniqueTask($record, 'task-1', true);

        // Assert
        $this->assertSame(1, $result->uniqueSuccess);
        $this->assertSame(0, $result->uniqueFailed);
        $this->assertSame(0, $result->recurringSuccess);
        $this->assertSame(0, $result->recurringFailed);
        $this->assertSame(1, $result->uniqueResults->count());
        $this->assertSame(0, $result->errors->count());
        $this->assertSame($this->startedAt, $result->startedAt);
    }

    public function test_with_unique_task_failure_without_error(): void
    {
        // Arrange
        $record = $this->createEmptyRecord();

        // Act
        $result = $this->service->withUniqueTask($record, 'task-1', false);

        // Assert
        $this->assertSame(0, $result->uniqueSuccess);
        $this->assertSame(1, $result->uniqueFailed);
        $this->assertSame(1, $result->uniqueResults->count());
        $this->assertSame(0, $result->errors->count());
    }

    public function test_with_unique_task_failure_with_error(): void
    {
        // Arrange
        $record = $this->createEmptyRecord();

        // Act
        $result = $this->service->withUniqueTask($record, 'task-1', false, 'Something went wrong');

        // Assert
        $this->assertSame(0, $result->uniqueSuccess);
        $this->assertSame(1, $result->uniqueFailed);
        $this->assertSame(1, $result->uniqueResults->count());
        $this->assertSame(1, $result->errors->count());

        $error = $result->errors->first();
        $this->assertNotNull($error);
        $this->assertSame('task-1', $error->taskId);
        $this->assertSame('Something went wrong', $error->error);
    }

    public function test_with_recurring_task_success(): void
    {
        // Arrange
        $record = $this->createEmptyRecord();

        // Act
        $result = $this->service->withRecurringTask($record, 'recurring-1', true);

        // Assert
        $this->assertSame(0, $result->uniqueSuccess);
        $this->assertSame(0, $result->uniqueFailed);
        $this->assertSame(1, $result->recurringSuccess);
        $this->assertSame(0, $result->recurringFailed);
        $this->assertSame(1, $result->recurringResults->count());
        $this->assertSame(0, $result->errors->count());
    }

    public function test_with_recurring_task_failure_without_error(): void
    {
        // Arrange
        $record = $this->createEmptyRecord();

        // Act
        $result = $this->service->withRecurringTask($record, 'recurring-1', false);

        // Assert
        $this->assertSame(0, $result->recurringSuccess);
        $this->assertSame(1, $result->recurringFailed);
        $this->assertSame(1, $result->recurringResults->count());
        $this->assertSame(0, $result->errors->count());
    }

    public function test_with_recurring_task_failure_with_error(): void
    {
        // Arrange
        $record = $this->createEmptyRecord();

        // Act
        $result = $this->service->withRecurringTask($record, 'recurring-1', false, 'Recurring task failed');

        // Assert
        $this->assertSame(0, $result->recurringSuccess);
        $this->assertSame(1, $result->recurringFailed);
        $this->assertSame(1, $result->recurringResults->count());
        $this->assertSame(1, $result->errors->count());

        $error = $result->errors->first();
        $this->assertNotNull($error);
        $this->assertSame('recurring-1', $error->taskId);
        $this->assertSame('Recurring task failed', $error->error);
    }

    public function test_multiple_unique_tasks(): void
    {
        // Arrange
        $record = $this->createEmptyRecord();

        // Act
        $result = $this->service->withUniqueTask($record, 'task-1', true);
        $result = $this->service->withUniqueTask($result, 'task-2', false, 'Error 2');
        $result = $this->service->withUniqueTask($result, 'task-3', true);

        // Assert
        $this->assertSame(2, $result->uniqueSuccess);
        $this->assertSame(1, $result->uniqueFailed);
        $this->assertSame(3, $result->uniqueResults->count());
        $this->assertSame(1, $result->errors->count());

        $error = $result->errors->first();
        $this->assertSame('task-2', $error->taskId);
        $this->assertSame('Error 2', $error->error);
    }

    public function test_multiple_recurring_tasks(): void
    {
        // Arrange
        $record = $this->createEmptyRecord();

        // Act
        $result = $this->service->withRecurringTask($record, 'recurring-1', true);
        $result = $this->service->withRecurringTask($result, 'recurring-2', false, 'Error 2');
        $result = $this->service->withRecurringTask($result, 'recurring-3', true);

        // Assert
        $this->assertSame(2, $result->recurringSuccess);
        $this->assertSame(1, $result->recurringFailed);
        $this->assertSame(3, $result->recurringResults->count());
        $this->assertSame(1, $result->errors->count());

        $error = $result->errors->first();
        $this->assertSame('recurring-2', $error->taskId);
        $this->assertSame('Error 2', $error->error);
    }

    public function test_mixed_unique_and_recurring_tasks(): void
    {
        // Arrange
        $record = $this->createEmptyRecord();

        // Act
        $result = $this->service->withUniqueTask($record, 'unique-1', true);
        $result = $this->service->withRecurringTask($result, 'recurring-1', true);
        $result = $this->service->withUniqueTask($result, 'unique-2', false, 'Unique error');
        $result = $this->service->withRecurringTask($result, 'recurring-2', false, 'Recurring error');

        // Assert
        $this->assertSame(1, $result->uniqueSuccess);
        $this->assertSame(1, $result->uniqueFailed);
        $this->assertSame(1, $result->recurringSuccess);
        $this->assertSame(1, $result->recurringFailed);
        $this->assertSame(2, $result->uniqueResults->count());
        $this->assertSame(2, $result->recurringResults->count());
        $this->assertSame(2, $result->errors->count());
    }

    public function test_immutability_original_record_unchanged(): void
    {
        // Arrange
        $original = $this->createEmptyRecord();

        // Act
        $result = $this->service->withUniqueTask($original, 'task-1', true);

        // Assert
        $this->assertSame(0, $original->uniqueSuccess);
        $this->assertSame(0, $original->uniqueResults->count());
        $this->assertSame(1, $result->uniqueSuccess);
        $this->assertSame(1, $result->uniqueResults->count());
    }
}
