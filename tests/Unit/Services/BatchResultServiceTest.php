<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Unit\Services;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\Task\Collections\RecurringResultCollection;
use AndyDefer\Task\Collections\RecurringTaskErrorCollection;
use AndyDefer\Task\Collections\TaskErrorCollection;
use AndyDefer\Task\Collections\UniqueResultCollection;
use AndyDefer\Task\Records\BatchResultRecord;
use AndyDefer\Task\Records\RecurringTaskResultRecord;
use AndyDefer\Task\Records\UniqueTaskResultRecord;
use AndyDefer\Task\Services\BatchResultService;
use AndyDefer\Task\Tests\UnitTestCase;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

final class BatchResultServiceTest extends UnitTestCase
{
    private BatchResultService $service;

    private Iso8601DateTimeVO $startedAt;

    protected function setUp(): void
    {
        parent::setUp();
        $hydration = new HydrationService;
        $this->service = new BatchResultService($hydration);
        $this->startedAt = new Iso8601DateTimeVO;
    }

    private function createEmptyRecord(): BatchResultRecord
    {
        return new BatchResultRecord(
            started_at: $this->startedAt,
            unique_success: new CounterVO(0),
            unique_failed: new CounterVO(0),
            recurring_success: new CounterVO(0),
            recurring_failed: new CounterVO(0),
            unique_results: new UniqueResultCollection,
            recurring_results: new RecurringResultCollection,
            unique_errors: new TaskErrorCollection,
            recurring_errors: new RecurringTaskErrorCollection,
        );
    }

    public function test_with_unique_task_success(): void
    {
        $record = $this->createEmptyRecord();
        $result = $this->service->withUniqueTask($record, new UniqueTaskResultRecord(
            task_id: new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'),
            success: true,
        ));

        $this->assertSame(1, $result->unique_success->value);
        $this->assertSame(0, $result->unique_failed->value);
        $this->assertSame(0, $result->recurring_success->value);
        $this->assertSame(0, $result->recurring_failed->value);
        $this->assertSame(1, $result->unique_results->count());
        $this->assertSame(0, $result->unique_errors->count());
        $this->assertSame($this->startedAt, $result->started_at);
    }

    public function test_with_unique_task_failure_without_error(): void
    {
        $record = $this->createEmptyRecord();
        $result = $this->service->withUniqueTask($record, new UniqueTaskResultRecord(
            task_id: new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'),
            success: false,
        ));

        $this->assertSame(0, $result->unique_success->value);
        $this->assertSame(1, $result->unique_failed->value);
        $this->assertSame(1, $result->unique_results->count());
        $this->assertSame(0, $result->unique_errors->count());
    }

    public function test_with_unique_task_failure_with_error(): void
    {
        $record = $this->createEmptyRecord();
        $result = $this->service->withUniqueTask($record, new UniqueTaskResultRecord(
            task_id: new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'),
            success: false,
            error: 'Something went wrong',
        ));

        $this->assertSame(0, $result->unique_success->value);
        $this->assertSame(1, $result->unique_failed->value);
        $this->assertSame(1, $result->unique_results->count());
        $this->assertSame(1, $result->unique_errors->count());

        $error = $result->unique_errors->first();
        $this->assertNotNull($error);
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $error->task_id->value);
        $this->assertSame('Something went wrong', $error->details);
    }

    public function test_with_recurring_task_success(): void
    {
        $record = $this->createEmptyRecord();
        $result = $this->service->withRecurringTask($record, new RecurringTaskResultRecord(
            signature: new TaskSignatureVO('recurring-1'),
            success: true,
        ));

        $this->assertSame(0, $result->unique_success->value);
        $this->assertSame(0, $result->unique_failed->value);
        $this->assertSame(1, $result->recurring_success->value);
        $this->assertSame(0, $result->recurring_failed->value);
        $this->assertSame(1, $result->recurring_results->count());
        $this->assertSame(0, $result->recurring_errors->count());
    }

    public function test_with_recurring_task_failure_without_error(): void
    {
        $record = $this->createEmptyRecord();
        $result = $this->service->withRecurringTask($record, new RecurringTaskResultRecord(
            signature: new TaskSignatureVO('recurring-1'),
            success: false,
        ));

        $this->assertSame(0, $result->recurring_success->value);
        $this->assertSame(1, $result->recurring_failed->value);
        $this->assertSame(1, $result->recurring_results->count());
        $this->assertSame(0, $result->recurring_errors->count());
    }

    public function test_with_recurring_task_failure_with_error(): void
    {
        $record = $this->createEmptyRecord();
        $result = $this->service->withRecurringTask($record, new RecurringTaskResultRecord(
            signature: new TaskSignatureVO('recurring-1'),
            success: false,
            error: 'Recurring task failed',
        ));

        $this->assertSame(0, $result->recurring_success->value);
        $this->assertSame(1, $result->recurring_failed->value);
        $this->assertSame(1, $result->recurring_results->count());
        $this->assertSame(1, $result->recurring_errors->count());

        $error = $result->recurring_errors->first();
        $this->assertNotNull($error);
        $this->assertSame('recurring-1', $error->signature->value);
        $this->assertSame('Recurring task failed', $error->details);
    }

    public function test_multiple_unique_tasks(): void
    {
        $record = $this->createEmptyRecord();

        $result = $this->service->withUniqueTask($record, new UniqueTaskResultRecord(
            task_id: new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'),
            success: true,
        ));
        $result = $this->service->withUniqueTask($result, new UniqueTaskResultRecord(
            task_id: new TaskIdVO('660e8400-e29b-41d4-a716-446655440001'),
            success: false,
            error: 'Error 2',
        ));
        $result = $this->service->withUniqueTask($result, new UniqueTaskResultRecord(
            task_id: new TaskIdVO('770e8400-e29b-41d4-a716-446655440002'),
            success: true,
        ));

        $this->assertSame(2, $result->unique_success->value);
        $this->assertSame(1, $result->unique_failed->value);
        $this->assertSame(3, $result->unique_results->count());
        $this->assertSame(1, $result->unique_errors->count());

        $error = $result->unique_errors->first();
        $this->assertSame('660e8400-e29b-41d4-a716-446655440001', $error->task_id->value);
        $this->assertSame('Error 2', $error->details);
    }

    public function test_multiple_recurring_tasks(): void
    {
        $record = $this->createEmptyRecord();

        $result = $this->service->withRecurringTask($record, new RecurringTaskResultRecord(
            signature: new TaskSignatureVO('recurring-1'),
            success: true,
        ));
        $result = $this->service->withRecurringTask($result, new RecurringTaskResultRecord(
            signature: new TaskSignatureVO('recurring-2'),
            success: false,
            error: 'Error 2',
        ));
        $result = $this->service->withRecurringTask($result, new RecurringTaskResultRecord(
            signature: new TaskSignatureVO('recurring-3'),
            success: true,
        ));

        $this->assertSame(2, $result->recurring_success->value);
        $this->assertSame(1, $result->recurring_failed->value);
        $this->assertSame(3, $result->recurring_results->count());
        $this->assertSame(1, $result->recurring_errors->count());

        $error = $result->recurring_errors->first();
        $this->assertSame('recurring-2', $error->signature->value);
        $this->assertSame('Error 2', $error->details);
    }

    public function test_mixed_unique_and_recurring_tasks(): void
    {
        $record = $this->createEmptyRecord();

        $result = $this->service->withUniqueTask($record, new UniqueTaskResultRecord(
            task_id: new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'),
            success: true,
        ));
        $result = $this->service->withRecurringTask($result, new RecurringTaskResultRecord(
            signature: new TaskSignatureVO('recurring-1'),
            success: true,
        ));
        $result = $this->service->withUniqueTask($result, new UniqueTaskResultRecord(
            task_id: new TaskIdVO('660e8400-e29b-41d4-a716-446655440001'),
            success: false,
            error: 'Unique error',
        ));
        $result = $this->service->withRecurringTask($result, new RecurringTaskResultRecord(
            signature: new TaskSignatureVO('recurring-2'),
            success: false,
            error: 'Recurring error',
        ));

        $this->assertSame(1, $result->unique_success->value);
        $this->assertSame(1, $result->unique_failed->value);
        $this->assertSame(1, $result->recurring_success->value);
        $this->assertSame(1, $result->recurring_failed->value);
        $this->assertSame(2, $result->unique_results->count());
        $this->assertSame(2, $result->recurring_results->count());
        $this->assertSame(1, $result->unique_errors->count());
        $this->assertSame(1, $result->recurring_errors->count());
    }

    public function test_immutability_original_record_unchanged(): void
    {
        $original = $this->createEmptyRecord();

        $result = $this->service->withUniqueTask($original, new UniqueTaskResultRecord(
            task_id: new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'),
            success: true,
        ));

        $this->assertSame(0, $original->unique_success->value);
        $this->assertSame(0, $original->unique_results->count());
        $this->assertSame(1, $result->unique_success->value);
        $this->assertSame(1, $result->unique_results->count());
    }
}
