<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Workflows;

use AndyDefer\DomainStructures\Collections\Utility\StrictDataObjectCollection;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Logger;
use AndyDefer\Task\Configs\TaskConfig;
use AndyDefer\Task\Enums\TaskMode;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Services\TaskRunnerService;
use AndyDefer\Task\Services\TaskStorageService;
use AndyDefer\Task\Services\TaskValidatorService;
use AndyDefer\Task\Tests\Fixtures\Tasks\FailingTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\Stub;

final class RecurringTaskTest extends IntegrationTestCase
{
    private TaskStorageService $storage;

    private TaskRunnerService $runner;

    private TaskValidatorService $validator;

    private string $storagePath;

    private TaskConfig&Stub $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = sys_get_temp_dir() . '/task_storage_' . uniqid();

        // Create mock config with all required methods
        $this->config = $this->createStub(TaskConfig::class);
        $this->config->method('storagePath')->willReturn($this->storagePath);
        $this->config->method('storagePendingPath')->willReturn($this->storagePath . '/pending');
        $this->config->method('storageRecurringPath')->willReturn($this->storagePath . '/recurring');
        $this->config->method('storageCompletedPath')->willReturn($this->storagePath . '/completed');
        $this->config->method('gracePeriodEnabled')->willReturn(false);
        $this->config->method('gracePeriodSeconds')->willReturn(86400);

        $this->storage = new TaskStorageService($this->config);
        $logger = $this->app->make(Logger::class);
        $this->validator = new TaskValidatorService($this->config);
        $this->runner = new TaskRunnerService($this->storage, $logger, $this->validator);
    }

    protected function tearDown(): void
    {
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

        $files = glob($path . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            } elseif (is_dir($file)) {
                $this->removeDirectory($file);
            }
        }

        rmdir($path);
    }

    private function createTaskPayload(?array $customData = null): TaskPayloadRecord
    {
        $payloadCollection = new StrictDataObjectCollection;

        if ($customData !== null) {
            $payloadCollection->add(StrictDataObject::from($customData));
        } else {
            $payloadCollection->add(StrictDataObject::from([
                'test_data' => 'recurring_test',
            ]));
        }

        return new TaskPayloadRecord(
            type: 'test',
            payload: $payloadCollection,
        );
    }

    private function createRecurringTask(
        string $signature,
        string $class,
        int $delaySeconds = 300,
        int $successCount = 0,
        int $failureCount = 0,
        ?string $startAt = null,
        ?string $endAt = null,
        ?string $lastRunAt = null,
        ?string $nextRunAt = null
    ): RecurringTaskRecord {
        $payload = $this->createTaskPayload();

        return new RecurringTaskRecord(
            signature: $signature,
            class: $class,
            payload: $payload,
            mode: TaskMode::DEFER,
            startAt: $startAt ?? date('c', strtotime('-1 hour')),
            endAt: $endAt,
            delaySeconds: $delaySeconds,
            lastRunAt: $lastRunAt,
            nextRunAt: $nextRunAt ?? date('c', strtotime('-5 minutes')),
            successCount: $successCount,
            failureCount: $failureCount,
        );
    }

    public function test_recurring_task_updates_after_run(): void
    {
        // Arrange: Create a recurring task ready to run
        $task = $this->createRecurringTask(
            signature: 'recurring-test',
            class: TestTask::class,
            delaySeconds: 300,
            nextRunAt: date('c', strtotime('-5 minutes'))
        );
        $this->storage->saveRecurring($task);

        // Act: Execute the recurring task
        $result = $this->runner->runRecurringTask($task);
        $updated = $this->storage->getRecurring('recurring-test');

        // Assert: Task was updated correctly
        $this->assertTrue($result);
        $this->assertNotNull($updated);
        $this->assertSame(1, $updated->successCount);
        $this->assertNotNull($updated->lastRunAt);
        $this->assertNotNull($updated->nextRunAt);
    }

    public function test_recurring_task_updates_next_run_at(): void
    {
        // Arrange: Create a recurring task with known next run time
        $task = $this->createRecurringTask(
            signature: 'recurring-next-run',
            class: TestTask::class,
            delaySeconds: 300,
            nextRunAt: date('c', strtotime('-10 minutes'))
        );
        $this->storage->saveRecurring($task);

        $oldNextRunAt = $task->nextRunAt;

        // Act: Execute the recurring task
        $result = $this->runner->runRecurringTask($task);
        $updated = $this->storage->getRecurring('recurring-next-run');

        // Assert: Next run time was advanced
        $this->assertTrue($result);
        $this->assertNotNull($updated);
        $this->assertNotSame($oldNextRunAt, $updated->nextRunAt);
        $this->assertGreaterThan(strtotime($oldNextRunAt), strtotime($updated->nextRunAt));
    }

    public function test_recurring_task_increments_success_count(): void
    {
        // Arrange: Create a recurring task with existing counts
        $task = $this->createRecurringTask(
            signature: 'recurring-counter',
            class: TestTask::class,
            delaySeconds: 300,
            successCount: 5,
            failureCount: 2,
            nextRunAt: date('c', strtotime('-5 minutes'))
        );
        $this->storage->saveRecurring($task);

        // Act: Execute the recurring task
        $result = $this->runner->runRecurringTask($task);
        $updated = $this->storage->getRecurring('recurring-counter');

        // Assert: Success count incremented, failure count unchanged
        $this->assertTrue($result);
        $this->assertNotNull($updated);
        $this->assertSame(6, $updated->successCount);
        $this->assertSame(2, $updated->failureCount);
    }

    public function test_recurring_task_increments_failure_count_on_error(): void
    {
        // Arrange: Create a failing recurring task with existing counts
        $task = $this->createRecurringTask(
            signature: 'recurring-failing',
            class: FailingTask::class,
            delaySeconds: 300,
            successCount: 3,
            failureCount: 1,
            nextRunAt: date('c', strtotime('-5 minutes'))
        );
        $this->storage->saveRecurring($task);

        // Act: Execute the failing recurring task
        $result = $this->runner->runRecurringTask($task);
        $updated = $this->storage->getRecurring('recurring-failing');

        // Assert: Failure count incremented, success count unchanged
        $this->assertFalse($result);
        $this->assertNotNull($updated);
        $this->assertSame(3, $updated->successCount);
        $this->assertSame(2, $updated->failureCount);
        $this->assertNotNull($updated->lastError);
    }

    public function test_recurring_task_stops_when_end_at_reached(): void
    {
        // Arrange: Create a recurring task that has expired (endAt in the past)
        $task = $this->createRecurringTask(
            signature: 'recurring-expired',
            class: TestTask::class,
            delaySeconds: 300,
            successCount: 10,
            failureCount: 0,
            startAt: date('c', strtotime('-2 days')),
            endAt: date('c', strtotime('-1 day')),
            nextRunAt: date('c', strtotime('-5 minutes'))
        );
        $this->storage->saveRecurring($task);

        // Act: Check if task should run
        $shouldRun = $this->validator->shouldRunRecurringNow($task);

        // Assert: Task should not run (expired)
        $this->assertFalse($shouldRun);
    }

    public function test_recurring_task_does_not_run_before_start_at(): void
    {
        // Arrange: Create a recurring task that starts in the future
        $task = $this->createRecurringTask(
            signature: 'recurring-future',
            class: TestTask::class,
            delaySeconds: 300,
            startAt: date('c', strtotime('+1 hour')),
            nextRunAt: date('c')
        );
        $this->storage->saveRecurring($task);

        // Act: Check if task should run
        $shouldRun = $this->validator->shouldRunRecurringNow($task);

        // Assert: Task should not run (not started yet)
        $this->assertFalse($shouldRun);
    }

    public function test_recurring_task_maintains_payload_across_runs(): void
    {
        // Arrange: Create a recurring task with custom payload
        $customPayload = $this->createTaskPayload([
            'config_key' => 'test_value',
            'numeric_value' => 42,
        ]);

        $task = new RecurringTaskRecord(
            signature: 'recurring-payload',
            class: TestTask::class,
            payload: $customPayload,
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
        $this->runner->runRecurringTask($task);
        $updated = $this->storage->getRecurring('recurring-payload');

        // Assert: Payload was preserved
        $this->assertNotNull($updated);
        $this->assertSame($task->payload->type, $updated->payload->type);
        $this->assertSame($task->payload->payload->count(), $updated->payload->payload->count());
    }

    public function test_multiple_recurring_tasks_can_coexist(): void
    {
        // Arrange: Create two different recurring tasks
        $task1 = $this->createRecurringTask(
            signature: 'recurring-task-1',
            class: TestTask::class,
            delaySeconds: 300,
            nextRunAt: date('c', strtotime('-5 minutes'))
        );

        $task2 = $this->createRecurringTask(
            signature: 'recurring-task-2',
            class: TestTask::class,
            delaySeconds: 600,
            nextRunAt: date('c', strtotime('-5 minutes'))
        );

        $this->storage->saveRecurring($task1);
        $this->storage->saveRecurring($task2);

        // Act: Execute both tasks
        $result1 = $this->runner->runRecurringTask($task1);
        $result2 = $this->runner->runRecurringTask($task2);

        $updated1 = $this->storage->getRecurring('recurring-task-1');
        $updated2 = $this->storage->getRecurring('recurring-task-2');

        // Assert: Both tasks executed successfully and counts incremented
        $this->assertTrue($result1);
        $this->assertTrue($result2);
        $this->assertSame(1, $updated1->successCount);
        $this->assertSame(1, $updated2->successCount);
    }

    public function test_recurring_task_respects_delay_seconds(): void
    {
        // Arrange: Create a recurring task with specific delay
        $delaySeconds = 600;

        $task = $this->createRecurringTask(
            signature: 'recurring-delay',
            class: TestTask::class,
            delaySeconds: $delaySeconds,
            lastRunAt: date('c', strtotime('-10 minutes')),
            nextRunAt: date('c', strtotime('-5 minutes'))
        );
        $this->storage->saveRecurring($task);

        $oldNextRunAt = $task->nextRunAt;

        // Act: Execute the recurring task
        $this->runner->runRecurringTask($task);
        $updated = $this->storage->getRecurring('recurring-delay');

        // Assert: Next run time respects the delay
        $this->assertNotNull($updated);
        $this->assertGreaterThanOrEqual(
            $delaySeconds,
            strtotime($updated->nextRunAt) - strtotime($oldNextRunAt)
        );
    }
}
