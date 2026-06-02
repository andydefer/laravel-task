<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Services;

use AndyDefer\DomainStructures\Collections\Utility\StrictDataObjectCollection;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
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

    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = sys_get_temp_dir().'/task_storage_'.uniqid();

        // Disable grace period for these tests
        config()->set('task.grace_period.enabled', false);

        $this->storage = new TaskStorage($this->storagePath);
        $logger = $this->app->make(Logger::class);
        $validator = $this->app->make(TaskValidator::class);
        $this->runner = new TaskRunner($this->storage, $logger, $validator);
    }

    protected function tearDown(): void
    {
        // Re-enable grace period
        config()->set('task.grace_period.enabled', true);

        if (is_dir($this->storagePath)) {
            $this->removeDirectory($this->storagePath);
        }

        parent::tearDown();
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $files = glob($path.'/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            } elseif (is_dir($file)) {
                $this->removeDirectory($file);
            }
        }

        rmdir($path);
    }

    private function createTaskPayload(): TaskPayloadRecord
    {
        $payloadCollection = new StrictDataObjectCollection;
        $payloadCollection->add(StrictDataObject::from([
            'test_data' => 'sample',
        ]));

        return new TaskPayloadRecord(
            type: 'test',
            payload: $payloadCollection,
        );
    }

    private function createTaskRecord(
        string $id,
        string $signature,
        string $class,
        int $attempts = 0,
        int $maxAttempts = 3,
        TaskStatus $status = TaskStatus::PENDING,
        ?string $endAt = null
    ): TaskRecord {
        $payload = $this->createTaskPayload();

        return new TaskRecord(
            id: $id,
            signature: $signature,
            class: $class,
            payload: $payload,
            mode: TaskMode::SYNC,
            status: $status,
            createdAt: date('c'),
            startAt: date('c', strtotime('-1 minute')),
            endAt: $endAt ?? date('c', strtotime('+1 hour')),
            delaySeconds: 0,
            attempts: $attempts,
            maxAttempts: $maxAttempts,
        );
    }

    public function test_run_task_success(): void
    {
        // Arrange: Create a successful task
        $task = $this->createTaskRecord('123', 'test', TestTask::class);
        $this->storage->savePending($task);

        // Act: Execute the task
        $result = $this->runner->runTask($task);

        // Assert: Task executed successfully
        $this->assertTrue($result);
    }

    public function test_run_task_failure(): void
    {
        // Arrange: Create a failing task
        $task = $this->createTaskRecord('456', 'failing', FailingTask::class);
        $this->storage->savePending($task);

        // Act: Execute the task
        $result = $this->runner->runTask($task);

        // Assert: Task failed
        $this->assertFalse($result);
    }

    public function test_run_task_returns_false_when_task_not_pending(): void
    {
        // Arrange: Create a task that is already running
        $task = $this->createTaskRecord('789', 'test', TestTask::class, 0, 3, TaskStatus::RUNNING);
        $this->storage->savePending($task);

        // Act: Attempt to execute the task
        $result = $this->runner->runTask($task);

        // Assert: Task should not be executed
        $this->assertFalse($result);
    }

    public function test_run_task_returns_false_when_max_attempts_reached(): void
    {
        // Arrange: Create a task that has reached max attempts
        $task = $this->createTaskRecord('999', 'failing', FailingTask::class, 3, 3);
        $this->storage->savePending($task);

        // Act: Attempt to execute the task
        $result = $this->runner->runTask($task);

        // Assert: Task should not be executed
        $this->assertFalse($result);
    }

    public function test_run_task_returns_false_when_task_expired(): void
    {
        // Arrange: Create an expired task
        $task = $this->createTaskRecord(
            id: '111',
            signature: 'test',
            class: TestTask::class,
            endAt: date('c', strtotime('-1 day'))
        );
        $this->storage->savePending($task);

        // Act: Attempt to execute the expired task
        $result = $this->runner->runTask($task);

        // Assert: Expired task should not be executed
        $this->assertFalse($result);
    }

    public function test_run_task_increments_attempts_on_failure(): void
    {
        // Arrange: Create a failing task
        $task = $this->createTaskRecord('222', 'failing', FailingTask::class, 0, 3);
        $this->storage->savePending($task);

        // Act: Execute the task
        $result = $this->runner->runTask($task);

        // Assert: Task failed and attempts were incremented
        $this->assertFalse($result);

        $pending = $this->storage->findPending();
        $this->assertSame(1, $pending->count());

        $updatedTask = $pending->first();
        $this->assertSame(1, $updatedTask->attempts);
        $this->assertNotNull($updatedTask->lastError);
    }

    public function test_run_task_archives_after_max_attempts(): void
    {
        // Arrange: Create a failing task with 2 attempts out of 3
        $task = $this->createTaskRecord('333', 'failing', FailingTask::class, 2, 3);
        $this->storage->savePending($task);

        // Act: Execute the task (3rd attempt)
        $result = $this->runner->runTask($task);

        // Assert: Task failed and was archived
        $this->assertFalse($result);

        $pending = $this->storage->findPending();
        $this->assertSame(0, $pending->count());
    }

    public function test_run_recurring_task_success(): void
    {
        // Arrange: Create a successful recurring task
        $payload = $this->createTaskPayload();

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

        // Act: Execute the recurring task
        $result = $this->runner->runRecurringTask($task);

        // Assert: Task succeeded and counters were updated
        $this->assertTrue($result);

        $updated = $this->storage->getRecurring('recurring-test');
        $this->assertNotNull($updated);
        $this->assertSame(1, $updated->successCount);
        $this->assertNotNull($updated->lastRunAt);
    }

    public function test_run_recurring_task_failure(): void
    {
        // Arrange: Create a failing recurring task
        $payload = $this->createTaskPayload();

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

        // Act: Execute the recurring task
        $result = $this->runner->runRecurringTask($task);

        // Assert: Task failed and failure count was incremented
        $this->assertFalse($result);

        $updated = $this->storage->getRecurring('recurring-failing');
        $this->assertNotNull($updated);
        $this->assertSame(1, $updated->failureCount);
        $this->assertNotNull($updated->lastError);
    }

    public function test_run_recurring_task_increments_success_count(): void
    {
        // Arrange: Create a recurring task with existing success count
        $payload = $this->createTaskPayload();

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

        // Act: Execute the recurring task
        $result = $this->runner->runRecurringTask($task);

        // Assert: Success count was incremented
        $this->assertTrue($result);

        $updated = $this->storage->getRecurring('recurring-counter');
        $this->assertNotNull($updated);
        $this->assertSame(6, $updated->successCount);
        $this->assertSame(2, $updated->failureCount);
    }

    public function test_run_recurring_task_updates_next_run_at(): void
    {
        // Arrange: Create a recurring task
        $payload = $this->createTaskPayload();

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

        // Act: Execute the recurring task
        $result = $this->runner->runRecurringTask($task);

        // Assert: Next run date was updated
        $this->assertTrue($result);

        $updated = $this->storage->getRecurring('recurring-next-run');
        $this->assertNotNull($updated);
        $this->assertNotSame($oldNextRunAt, $updated->nextRunAt);
        $this->assertNotNull($updated->lastRunAt);
    }

    public function test_run_task_with_invalid_class_returns_false(): void
    {
        // Arrange: Create a task with non-existent class
        $task = $this->createTaskRecord('invalid', 'invalid', 'NonExistentClass');
        $this->storage->savePending($task);

        // Act: Attempt to execute the task
        $result = $this->runner->runTask($task);

        // Assert: Task failed and was archived
        $this->assertFalse($result);

        $pending = $this->storage->findPending();
        $this->assertSame(0, $pending->count());
    }

    public function test_run_recurring_task_with_invalid_class_returns_false(): void
    {
        // Arrange: Create a recurring task with non-existent class
        $payload = $this->createTaskPayload();

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

        // Act: Attempt to execute the recurring task
        $result = $this->runner->runRecurringTask($task);

        // Assert: Task failed and failure count was incremented
        $this->assertFalse($result);

        $updated = $this->storage->getRecurring('invalid-recurring');
        $this->assertNotNull($updated);
        $this->assertSame(1, $updated->failureCount);
        $this->assertNotNull($updated->lastError);
    }
}
