<?php

// tests/Unit/ValueObjects/TaskIdentifierTest.php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Unit\ValueObjects;

use AndyDefer\Logger\Collections\MixedPayloadCollection;
use AndyDefer\Task\Enums\TaskMode;
use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\Tests\UnitTestCase;
use AndyDefer\Task\ValueObjects\TaskIdentifier;

final class TaskIdentifierTest extends UnitTestCase
{
    private TaskPayloadRecord $payload;

    protected function setUp(): void
    {
        parent::setUp();
        $this->payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection,
        );
    }

    public function test_from_task_with_task_record_returns_id(): void
    {
        $task = new TaskRecord(
            id: '123',
            signature: 'test',
            class: 'TestClass',
            payload: $this->payload,
            mode: TaskMode::SYNC,
            status: TaskStatus::PENDING,
            createdAt: date('c'),
            startAt: date('c'),
            endAt: null,
            delaySeconds: 0,
            attempts: 0,
            maxAttempts: 3,
        );

        $identifier = TaskIdentifier::fromTask($task);

        $this->assertSame('123', $identifier->toString());
    }

    public function test_from_task_with_recurring_task_returns_recurring_prefix(): void
    {
        $task = new RecurringTaskRecord(
            signature: 'recurring-test',
            class: 'TestClass',
            payload: $this->payload,
            mode: TaskMode::DEFER,
            startAt: date('c'),
            endAt: null,
            delaySeconds: 300,
            lastRunAt: null,
            nextRunAt: date('c'),
            successCount: 0,
            failureCount: 0,
        );

        $identifier = TaskIdentifier::fromTask($task);

        $this->assertSame('recurring_recurring-test', $identifier->toString());
    }

    public function test_from_string_creates_identifier(): void
    {
        $identifier = TaskIdentifier::fromString('custom-id');

        $this->assertSame('custom-id', $identifier->toString());
    }

    public function test_equals_returns_true_for_same_value(): void
    {
        $id1 = TaskIdentifier::fromString('same-id');
        $id2 = TaskIdentifier::fromString('same-id');

        $this->assertTrue($id1->equals($id2));
    }

    public function test_equals_returns_false_for_different_values(): void
    {
        $id1 = TaskIdentifier::fromString('id-1');
        $id2 = TaskIdentifier::fromString('id-2');

        $this->assertFalse($id1->equals($id2));
    }
}
