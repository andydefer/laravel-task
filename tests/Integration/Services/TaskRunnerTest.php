<?php

// tests/Integration/Services/TaskRunnerTest.php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Services;

use AndyDefer\Logger\Collections\MixedPayloadCollection;
use AndyDefer\Logger\Logger;
use AndyDefer\Task\Enums\TaskMode;
use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\Services\TaskRunner;
use AndyDefer\Task\Services\TaskStorage;
use AndyDefer\Task\Services\TaskValidator;
use AndyDefer\Task\Tests\Fixtures\Tasks\FailingTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestTask;
use AndyDefer\Task\Tests\IntegrationTestCase;

final class TaskRunnerTest extends IntegrationTestCase
{
    private TaskStorage $storage;

    private TaskRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();

        // Désactiver la période de grâce pour ces tests
        config()->set('task.grace_period.enabled', false);

        $this->storage = $this->app->make(TaskStorage::class);
        $logger = $this->app->make(Logger::class);
        $validator = $this->app->make(TaskValidator::class);
        $this->runner = new TaskRunner($this->storage, $logger, $validator);
    }

    protected function tearDown(): void
    {
        // Réactiver la période de grâce
        config()->set('task.grace_period.enabled', true);
        parent::tearDown();
    }

    public function test_run_task_success(): void
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection,
        );

        $task = new TaskRecord(
            id: '123',
            signature: 'test',
            class: TestTask::class,
            payload: $payload,
            mode: TaskMode::SYNC,
            status: TaskStatus::PENDING,
            createdAt: date('c'),
            startAt: date('c', strtotime('-1 minute')),
            endAt: date('c', strtotime('+1 hour')),
            delaySeconds: 0,
            attempts: 0,
            maxAttempts: 3,
        );

        $this->storage->savePending($task);

        $result = $this->runner->runTask($task);

        $this->assertTrue($result);
    }

    public function test_run_task_failure(): void
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection,
        );

        $task = new TaskRecord(
            id: '456',
            signature: 'failing',
            class: FailingTask::class,
            payload: $payload,
            mode: TaskMode::SYNC,
            status: TaskStatus::PENDING,
            createdAt: date('c'),
            startAt: date('c', strtotime('-1 minute')),
            endAt: date('c', strtotime('+1 hour')),
            delaySeconds: 0,
            attempts: 0,
            maxAttempts: 3,
        );

        $this->storage->savePending($task);

        $result = $this->runner->runTask($task);

        $this->assertFalse($result);
    }

    public function test_run_task_returns_false_when_task_not_pending(): void
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection,
        );

        $task = new TaskRecord(
            id: '789',
            signature: 'test',
            class: TestTask::class,
            payload: $payload,
            mode: TaskMode::SYNC,
            status: TaskStatus::RUNNING,
            createdAt: date('c'),
            startAt: date('c', strtotime('-1 minute')),
            endAt: date('c', strtotime('+1 hour')),
            delaySeconds: 0,
            attempts: 0,
            maxAttempts: 3,
        );

        $this->storage->savePending($task);

        $result = $this->runner->runTask($task);

        $this->assertFalse($result);
    }

    public function test_run_task_returns_false_when_max_attempts_reached(): void
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection,
        );

        $task = new TaskRecord(
            id: '999',
            signature: 'failing',
            class: FailingTask::class,
            payload: $payload,
            mode: TaskMode::SYNC,
            status: TaskStatus::PENDING,
            createdAt: date('c'),
            startAt: date('c', strtotime('-1 minute')),
            endAt: date('c', strtotime('+1 hour')),
            delaySeconds: 0,
            attempts: 3,
            maxAttempts: 3,
        );

        $this->storage->savePending($task);

        $result = $this->runner->runTask($task);

        $this->assertFalse($result);
    }

    public function test_run_task_returns_false_when_task_expired(): void
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection,
        );

        $task = new TaskRecord(
            id: '111',
            signature: 'test',
            class: TestTask::class,
            payload: $payload,
            mode: TaskMode::SYNC,
            status: TaskStatus::PENDING,
            createdAt: date('c', strtotime('-2 days')),
            startAt: date('c', strtotime('-2 days')),
            endAt: date('c', strtotime('-1 day')),
            delaySeconds: 0,
            attempts: 0,
            maxAttempts: 3,
        );

        $this->storage->savePending($task);

        $result = $this->runner->runTask($task);

        $this->assertFalse($result);
    }

    public function test_run_task_increments_attempts_on_failure(): void
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection,
        );

        $task = new TaskRecord(
            id: '222',
            signature: 'failing',
            class: FailingTask::class,
            payload: $payload,
            mode: TaskMode::SYNC,
            status: TaskStatus::PENDING,
            createdAt: date('c'),
            startAt: date('c', strtotime('-1 minute')),
            endAt: date('c', strtotime('+1 hour')),
            delaySeconds: 0,
            attempts: 0,
            maxAttempts: 3,
        );

        $this->storage->savePending($task);

        $result = $this->runner->runTask($task);

        $this->assertFalse($result);

        $pending = $this->storage->findPending();
        $this->assertSame(1, $pending->count());

        $updatedTask = $pending->firstItem();
        $this->assertSame(1, $updatedTask->attempts);
        $this->assertNotNull($updatedTask->lastError);
    }

    public function test_run_task_archives_after_max_attempts(): void
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection,
        );

        $task = new TaskRecord(
            id: '333',
            signature: 'failing',
            class: FailingTask::class,
            payload: $payload,
            mode: TaskMode::SYNC,
            status: TaskStatus::PENDING,
            createdAt: date('c'),
            startAt: date('c', strtotime('-1 minute')),
            endAt: date('c', strtotime('+1 hour')),
            delaySeconds: 0,
            attempts: 2,
            maxAttempts: 3,
        );

        $this->storage->savePending($task);

        $result = $this->runner->runTask($task);

        $this->assertFalse($result);

        $pending = $this->storage->findPending();
        $this->assertSame(0, $pending->count());
    }

    public function test_run_recurring_task_success(): void
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection,
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
    }

    public function test_run_recurring_task_failure(): void
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection,
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
            successCount: 0,
            failureCount: 0,
        );

        $this->storage->saveRecurring($task);

        $result = $this->runner->runRecurringTask($task);

        $this->assertFalse($result);

        $updated = $this->storage->getRecurring('recurring-failing');
        $this->assertNotNull($updated);
        $this->assertSame(1, $updated->failureCount);
        $this->assertNotNull($updated->lastError);
    }

    public function test_run_recurring_task_increments_success_count(): void
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection,
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

    public function test_run_recurring_task_updates_next_run_at(): void
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection,
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
        $this->assertNotNull($updated->lastRunAt);
    }

    public function test_run_task_with_invalid_class_returns_false(): void
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection,
        );

        $task = new TaskRecord(
            id: 'invalid',
            signature: 'invalid',
            class: 'NonExistentClass',
            payload: $payload,
            mode: TaskMode::SYNC,
            status: TaskStatus::PENDING,
            createdAt: date('c'),
            startAt: date('c', strtotime('-1 minute')),
            endAt: date('c', strtotime('+1 hour')),
            delaySeconds: 0,
            attempts: 0,
            maxAttempts: 3,
        );

        $this->storage->savePending($task);

        $result = $this->runner->runTask($task);

        $this->assertFalse($result);

        $pending = $this->storage->findPending();
        $this->assertSame(0, $pending->count());
    }

    public function test_run_recurring_task_with_invalid_class_returns_false(): void
    {
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: new MixedPayloadCollection,
        );

        $task = new RecurringTaskRecord(
            signature: 'invalid-recurring',
            class: 'NonExistentClass',
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

        $this->assertFalse($result);

        $updated = $this->storage->getRecurring('invalid-recurring');
        $this->assertNotNull($updated);
        $this->assertSame(1, $updated->failureCount);
        $this->assertNotNull($updated->lastError);
    }
}
