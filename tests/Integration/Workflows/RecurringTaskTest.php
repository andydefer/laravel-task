<?php

// tests/Integration/Workflows/RecurringTaskTest.php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Workflows;

use AndyDefer\Logger\Collections\MixedPayloadCollection;
use AndyDefer\Logger\Logger;
use AndyDefer\Task\Enums\TaskMode;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Services\TaskRunner;
use AndyDefer\Task\Services\TaskStorage;
use AndyDefer\Task\Services\TaskValidator;
use AndyDefer\Task\Tests\Fixtures\Tasks\FailingTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestTask;
use AndyDefer\Task\Tests\IntegrationTestCase;

final class RecurringTaskTest extends IntegrationTestCase
{
    private TaskStorage $storage;

    private TaskRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storage = $this->app->make(TaskStorage::class);
        $logger = $this->app->make(Logger::class);
        $validator = $this->app->make(TaskValidator::class);
        $this->runner = new TaskRunner($this->storage, $logger, $validator);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function test_recurring_task_updates_after_run(): void
    {
        $payloadCollection = new MixedPayloadCollection;
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: $payloadCollection,
        );

        $task = new RecurringTaskRecord(
            signature: 'recurring-test',
            class: TestTask::class,
            payload: $payload,
            mode: TaskMode::DEFER,
            startAt: date('c', strtotime('-1 hour')),
            endAt: null,
            delaySeconds: 300,
            lastRunAt: null,
            nextRunAt: date('c', strtotime('-5 minutes')),
            successCount: 0,
            failureCount: 0,
        );

        $this->storage->saveRecurring($task);

        $result = $this->runner->runRecurringTask($task);

        $this->assertTrue($result);

        $updated = $this->storage->getRecurring('recurring-test');

        $this->assertNotNull($updated);
        $this->assertSame(1, $updated->successCount);
        $this->assertNotNull($updated->lastRunAt);
        $this->assertNotNull($updated->nextRunAt);
    }

    public function test_recurring_task_updates_next_run_at(): void
    {
        $payloadCollection = new MixedPayloadCollection;
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: $payloadCollection,
        );

        $task = new RecurringTaskRecord(
            signature: 'recurring-next-run',
            class: TestTask::class,
            payload: $payload,
            mode: TaskMode::DEFER,
            startAt: date('c', strtotime('-1 hour')),
            endAt: null,
            delaySeconds: 300,
            lastRunAt: null,
            nextRunAt: date('c', strtotime('-10 minutes')),
            successCount: 0,
            failureCount: 0,
        );

        $this->storage->saveRecurring($task);

        $oldNextRunAt = $task->nextRunAt;

        $result = $this->runner->runRecurringTask($task);

        $this->assertTrue($result);

        $updated = $this->storage->getRecurring('recurring-next-run');

        $this->assertNotNull($updated);
        $this->assertNotSame($oldNextRunAt, $updated->nextRunAt);
        $this->assertGreaterThan(strtotime($oldNextRunAt), strtotime($updated->nextRunAt));
    }

    public function test_recurring_task_increments_success_count(): void
    {
        $payloadCollection = new MixedPayloadCollection;
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: $payloadCollection,
        );

        $task = new RecurringTaskRecord(
            signature: 'recurring-counter',
            class: TestTask::class,
            payload: $payload,
            mode: TaskMode::DEFER,
            startAt: date('c', strtotime('-1 hour')),
            endAt: null,
            delaySeconds: 300,
            lastRunAt: null,
            nextRunAt: date('c', strtotime('-5 minutes')),
            successCount: 5,
            failureCount: 2,
        );

        $this->storage->saveRecurring($task);

        $result = $this->runner->runRecurringTask($task);

        $this->assertTrue($result);

        $updated = $this->storage->getRecurring('recurring-counter');

        $this->assertNotNull($updated);
        $this->assertSame(6, $updated->successCount);
        $this->assertSame(2, $updated->failureCount);
    }

    public function test_recurring_task_increments_failure_count_on_error(): void
    {
        $payloadCollection = new MixedPayloadCollection;
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: $payloadCollection,
        );

        $task = new RecurringTaskRecord(
            signature: 'recurring-failing',
            class: FailingTask::class,
            payload: $payload,
            mode: TaskMode::DEFER,
            startAt: date('c', strtotime('-1 hour')),
            endAt: null,
            delaySeconds: 300,
            lastRunAt: null,
            nextRunAt: date('c', strtotime('-5 minutes')),
            successCount: 3,
            failureCount: 1,
        );

        $this->storage->saveRecurring($task);

        $result = $this->runner->runRecurringTask($task);

        $this->assertFalse($result);

        $updated = $this->storage->getRecurring('recurring-failing');

        $this->assertNotNull($updated);
        $this->assertSame(3, $updated->successCount);
        $this->assertSame(2, $updated->failureCount);
        $this->assertNotNull($updated->lastError);
    }

    public function test_recurring_task_stops_when_end_at_reached(): void
    {
        $payloadCollection = new MixedPayloadCollection;
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: $payloadCollection,
        );

        $task = new RecurringTaskRecord(
            signature: 'recurring-expired',
            class: TestTask::class,
            payload: $payload,
            mode: TaskMode::DEFER,
            startAt: date('c', strtotime('-2 days')),
            endAt: date('c', strtotime('-1 day')),
            delaySeconds: 300,
            lastRunAt: null,
            nextRunAt: date('c', strtotime('-5 minutes')),
            successCount: 10,
            failureCount: 0,
        );

        $this->storage->saveRecurring($task);

        $validator = $this->app->make(TaskValidator::class);
        $shouldRun = $validator->shouldRunRecurringNow($task);

        $this->assertFalse($shouldRun);
    }

    public function test_recurring_task_does_not_run_before_start_at(): void
    {
        $payloadCollection = new MixedPayloadCollection;
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: $payloadCollection,
        );

        $task = new RecurringTaskRecord(
            signature: 'recurring-future',
            class: TestTask::class,
            payload: $payload,
            mode: TaskMode::DEFER,
            startAt: date('c', strtotime('+1 hour')),
            endAt: null,
            delaySeconds: 300,
            lastRunAt: null,
            nextRunAt: date('c'),
            successCount: 0,
            failureCount: 0,
        );

        $this->storage->saveRecurring($task);

        $validator = $this->app->make(TaskValidator::class);
        $result = $validator->shouldRunRecurringNow($task);

        $this->assertFalse($result);
    }

    public function test_recurring_task_maintains_payload_across_runs(): void
    {
        $payloadCollection = new MixedPayloadCollection;
        $payloadCollection->add('config_key', 'test_value', 42);
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: $payloadCollection,
        );

        $task = new RecurringTaskRecord(
            signature: 'recurring-payload',
            class: TestTask::class,
            payload: $payload,
            mode: TaskMode::DEFER,
            startAt: date('c', strtotime('-1 hour')),
            endAt: null,
            delaySeconds: 300,
            lastRunAt: null,
            nextRunAt: date('c', strtotime('-5 minutes')),
            successCount: 0,
            failureCount: 0,
        );

        $this->storage->saveRecurring($task);

        $this->runner->runRecurringTask($task);

        $updated = $this->storage->getRecurring('recurring-payload');

        $this->assertNotNull($updated);
        $this->assertSame($task->payload->type, $updated->payload->type);
        $this->assertSame($task->payload->payload->count(), $updated->payload->payload->count());
    }

    public function test_multiple_recurring_tasks_can_coexist(): void
    {
        $payloadCollection1 = new MixedPayloadCollection;
        $payload1 = new TaskPayloadRecord(
            type: 'task1',
            payload: $payloadCollection1,
        );

        $payloadCollection2 = new MixedPayloadCollection;
        $payload2 = new TaskPayloadRecord(
            type: 'task2',
            payload: $payloadCollection2,
        );

        $task1 = new RecurringTaskRecord(
            signature: 'recurring-task-1',
            class: TestTask::class,
            payload: $payload1,
            mode: TaskMode::DEFER,
            startAt: date('c', strtotime('-1 hour')),
            endAt: null,
            delaySeconds: 300,
            lastRunAt: null,
            nextRunAt: date('c', strtotime('-5 minutes')),
            successCount: 0,
            failureCount: 0,
        );

        $task2 = new RecurringTaskRecord(
            signature: 'recurring-task-2',
            class: TestTask::class,
            payload: $payload2,
            mode: TaskMode::DEFER,
            startAt: date('c', strtotime('-1 hour')),
            endAt: null,
            delaySeconds: 600,
            lastRunAt: null,
            nextRunAt: date('c', strtotime('-5 minutes')),
            successCount: 0,
            failureCount: 0,
        );

        $this->storage->saveRecurring($task1);
        $this->storage->saveRecurring($task2);

        $result1 = $this->runner->runRecurringTask($task1);
        $result2 = $this->runner->runRecurringTask($task2);

        $this->assertTrue($result1);
        $this->assertTrue($result2);

        $updated1 = $this->storage->getRecurring('recurring-task-1');
        $updated2 = $this->storage->getRecurring('recurring-task-2');

        $this->assertSame(1, $updated1->successCount);
        $this->assertSame(1, $updated2->successCount);
    }

    public function test_recurring_task_respects_delay_seconds(): void
    {
        $payloadCollection = new MixedPayloadCollection;
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: $payloadCollection,
        );

        $delaySeconds = 600;

        $task = new RecurringTaskRecord(
            signature: 'recurring-delay',
            class: TestTask::class,
            payload: $payload,
            mode: TaskMode::DEFER,
            startAt: date('c', strtotime('-1 hour')),
            endAt: null,
            delaySeconds: $delaySeconds,
            lastRunAt: date('c', strtotime('-10 minutes')),
            nextRunAt: date('c', strtotime('-5 minutes')),
            successCount: 0,
            failureCount: 0,
        );

        $this->storage->saveRecurring($task);

        $oldNextRunAt = $task->nextRunAt;

        $this->runner->runRecurringTask($task);

        $updated = $this->storage->getRecurring('recurring-delay');

        $this->assertNotNull($updated);
        $this->assertGreaterThanOrEqual($delaySeconds, strtotime($updated->nextRunAt) - strtotime($oldNextRunAt));
    }
}
