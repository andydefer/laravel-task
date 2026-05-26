<?php

// tests/Unit/Collections/TaskCollectionTest.php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Unit\Collections;

use AndyDefer\Logger\Collections\MixedPayloadCollection;
use AndyDefer\Task\Collections\TaskCollection;
use AndyDefer\Task\Enums\TaskMode;
use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\Tests\UnitTestCase;

final class TaskCollectionTest extends UnitTestCase
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

    public function test_add_task_and_count(): void
    {
        $collection = new TaskCollection;

        $task = new TaskRecord(
            id: '123',
            signature: 'test',
            class: 'TestClass',
            payload: $this->payload,
            mode: TaskMode::SYNC,
            status: TaskStatus::PENDING,
            createdAt: '2024-01-01T00:00:00Z',
            startAt: '2024-01-01T00:00:00Z',
            endAt: null,
            delaySeconds: 0,
            attempts: 0,
            maxAttempts: 3,
        );

        $collection->add($task);

        $this->assertSame(1, $collection->count());
    }

    public function test_get_pending_tasks(): void
    {
        $collection = new TaskCollection;

        $pendingTask = new TaskRecord(
            id: '123',
            signature: 'pending',
            class: 'TestClass',
            payload: $this->payload,
            mode: TaskMode::SYNC,
            status: TaskStatus::PENDING,
            createdAt: '2024-01-01T00:00:00Z',
            startAt: '2024-01-01T00:00:00Z',
            endAt: null,
            delaySeconds: 0,
            attempts: 0,
            maxAttempts: 3,
        );

        $runningTask = new TaskRecord(
            id: '456',
            signature: 'running',
            class: 'TestClass',
            payload: $this->payload,
            mode: TaskMode::SYNC,
            status: TaskStatus::RUNNING,
            createdAt: '2024-01-01T00:00:00Z',
            startAt: '2024-01-01T00:00:00Z',
            endAt: null,
            delaySeconds: 0,
            attempts: 0,
            maxAttempts: 3,
        );

        $collection->add($pendingTask, $runningTask);

        $pending = $collection->getPendingTasks();

        $this->assertSame(1, $pending->count());
    }

    public function test_get_recurring_tasks(): void
    {
        $collection = new TaskCollection;

        $recurringTask = new RecurringTaskRecord(
            signature: 'recurring',
            class: 'TestClass',
            payload: $this->payload,
            mode: TaskMode::DEFER,
            startAt: '2024-01-01T00:00:00Z',
            endAt: null,
            delaySeconds: 300,
            lastRunAt: null,
            nextRunAt: '2024-01-01T00:00:00Z',
            successCount: 0,
            failureCount: 0,
        );

        $uniqueTask = new TaskRecord(
            id: '123',
            signature: 'unique',
            class: 'TestClass',
            payload: $this->payload,
            mode: TaskMode::SYNC,
            status: TaskStatus::PENDING,
            createdAt: '2024-01-01T00:00:00Z',
            startAt: '2024-01-01T00:00:00Z',
            endAt: null,
            delaySeconds: 0,
            attempts: 0,
            maxAttempts: 3,
        );

        $collection->add($recurringTask, $uniqueTask);

        $recurring = $collection->getRecurringTasks();
        $unique = $collection->getUniqueTasks();

        $this->assertSame(1, $recurring->count());
        $this->assertSame(1, $unique->count());
    }
}
