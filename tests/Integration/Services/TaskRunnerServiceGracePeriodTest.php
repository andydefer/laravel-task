<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Services;

use AndyDefer\DomainStructures\Collections\Utility\StrictDataObjectCollection;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Logger;
use AndyDefer\Task\Configs\TaskConfig;
use AndyDefer\Task\Enums\TaskMode;
use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\Services\TaskRunnerService;
use AndyDefer\Task\Services\TaskStorageService;
use AndyDefer\Task\Services\TaskValidatorService;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\Tests\UnitTestCase;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\Stub;

final class TaskRunnerServiceGracePeriodTest extends IntegrationTestCase
{
    private TaskStorageService $storage;

    private TaskRunnerService $runner;

    private string $storagePath;

    private TaskConfig&Stub $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = sys_get_temp_dir() . '/task_storage_' . uniqid();

        // Create stub config with all required methods
        $this->config = $this->createStub(TaskConfig::class);
        $this->config->method('storagePath')->willReturn($this->storagePath);
        $this->config->method('storagePendingPath')->willReturn($this->storagePath . '/pending');
        $this->config->method('storageRecurringPath')->willReturn($this->storagePath . '/recurring');
        $this->config->method('storageCompletedPath')->willReturn($this->storagePath . '/completed');
        $this->config->method('gracePeriodEnabled')->willReturn(true);
        $this->config->method('gracePeriodSeconds')->willReturn(86400);

        // Freeze time to 12:15 (5 minutes after task end)
        Carbon::setTestNow(Carbon::create(2026, 5, 24, 12, 15, 0));

        // Create storage instance with config
        $this->storage = new TaskStorageService($this->config);

        $logger = $this->app->make(Logger::class);
        $validator = new TaskValidatorService($this->config);

        // Create TaskRunnerService with config
        $this->runner = new TaskRunnerService($this->storage, $logger, $validator);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

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

    private function createExpiredTask(bool $enforceExactSchedule = false): TaskRecord
    {
        $payloadCollection = new StrictDataObjectCollection;
        $payloadCollection->add(StrictDataObject::from([
            'test_data' => 'expired_task_test',
        ]));

        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: $payloadCollection,
        );

        return new TaskRecord(
            id: 'expired-task',
            signature: 'test-task',
            class: TestTask::class,
            payload: $payload,
            mode: TaskMode::SYNC,
            status: TaskStatus::PENDING,
            createdAt: date('c'),
            startAt: '2026-05-24T12:00:00Z',
            endAt: '2026-05-24T12:10:00Z',
            delaySeconds: 0,
            attempts: 0,
            maxAttempts: 3,
            enforceExactSchedule: $enforceExactSchedule,
        );
    }

    private function createRecurringTask(): TaskRecord
    {
        $payloadCollection = new StrictDataObjectCollection;
        $payloadCollection->add(StrictDataObject::from([
            'test_data' => 'recurring_task_test',
        ]));

        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: $payloadCollection,
        );

        return new TaskRecord(
            id: 'recurring-task',
            signature: 'recurring-test',
            class: TestTask::class,
            payload: $payload,
            mode: TaskMode::DEFER,
            status: TaskStatus::PENDING,
            createdAt: date('c'),
            startAt: '2026-05-24T12:00:00Z',
            endAt: '2026-05-24T12:10:00Z',
            delaySeconds: 300,
            attempts: 0,
            maxAttempts: 3,
        );
    }

    public function test_expired_unique_task_is_executed_during_grace_period(): void
    {
        // Arrange: Create an expired task without exact schedule enforcement
        $task = $this->createExpiredTask(false);
        $this->storage->savePending($task);

        // Act: Execute the expired task
        $result = $this->runner->runTask($task);
        $pending = $this->storage->findPending();

        // Assert: The expired task should be executed during grace period
        $this->assertTrue($result, 'Expired task should be executed during grace period');
        $this->assertSame(0, $pending->count(), 'Task should be archived after execution');
    }

    public function test_expired_unique_task_archived_if_grace_period_expired(): void
    {
        // Arrange: Create an expired task with exact schedule enforcement (disables grace period)
        $task = $this->createExpiredTask(true);
        $this->storage->savePending($task);

        // Act: Attempt to execute the expired task
        $result = $this->runner->runTask($task);
        $pending = $this->storage->findPending();

        // Assert: Task should not be executed and should be archived
        $this->assertFalse($result, 'Task should not be executed because it is expired and enforceExactSchedule is true');
        $this->assertSame(0, $pending->count(), 'Task should be archived');
    }

    public function test_recurring_task_not_affected_by_grace_period(): void
    {
        // Arrange: Create a recurring task
        $task = $this->createRecurringTask();
        $this->storage->savePending($task);

        // Act: Attempt to execute the recurring task
        $result = $this->runner->runTask($task);

        // Assert: Recurring tasks should not be executed during grace period
        $this->assertFalse($result, 'Recurring tasks should not benefit from grace period');
    }

    public function test_unique_task_outside_grace_period_is_not_executed(): void
    {
        // Arrange: Create an expired task with exact schedule enforcement
        $task = $this->createExpiredTask(true);
        $this->storage->savePending($task);

        // Act: Attempt to execute the expired task
        $result = $this->runner->runTask($task);

        // Assert: Task should not be executed as it is outside grace period
        $this->assertFalse($result, 'Task should not be executed because it is expired');
    }

    public function test_grace_period_can_be_disabled_via_config(): void
    {
        // Arrange: Create stub config with grace period disabled
        $config = $this->createStub(TaskConfig::class);
        $config->method('storagePath')->willReturn($this->storagePath);
        $config->method('storagePendingPath')->willReturn($this->storagePath . '/pending');
        $config->method('storageRecurringPath')->willReturn($this->storagePath . '/recurring');
        $config->method('storageCompletedPath')->willReturn($this->storagePath . '/completed');
        $config->method('gracePeriodEnabled')->willReturn(false);
        $config->method('gracePeriodSeconds')->willReturn(86400);

        $storage = new TaskStorageService($config);
        $logger = $this->app->make(Logger::class);
        $validator = new TaskValidatorService($config);
        $runner = new TaskRunnerService($storage, $logger, $validator);

        $task = $this->createExpiredTask(false);
        $storage->savePending($task);

        // Act
        $result = $runner->runTask($task);
        $pending = $storage->findPending();

        // Assert: Task should not be executed when grace period is disabled
        $this->assertFalse($result, 'Task should not be executed when grace period is disabled');
        $this->assertSame(0, $pending->count(), 'Task should be archived');
    }

    public function test_grace_period_seconds_can_be_customized_via_config(): void
    {
        // Arrange: Create stub config with custom grace period (1 hour = 3600 seconds)
        $config = $this->createStub(TaskConfig::class);
        $config->method('storagePath')->willReturn($this->storagePath);
        $config->method('storagePendingPath')->willReturn($this->storagePath . '/pending');
        $config->method('storageRecurringPath')->willReturn($this->storagePath . '/recurring');
        $config->method('storageCompletedPath')->willReturn($this->storagePath . '/completed');
        $config->method('gracePeriodEnabled')->willReturn(true);
        $config->method('gracePeriodSeconds')->willReturn(3600);

        $storage = new TaskStorageService($config);
        $logger = $this->app->make(Logger::class);
        $validator = new TaskValidatorService($config);
        $runner = new TaskRunnerService($storage, $logger, $validator);

        // Create task that expired 5 minutes ago (300 seconds)
        // With 3600 seconds grace period, it should still be executable
        $task = $this->createExpiredTask(false);
        $storage->savePending($task);

        // Act
        $result = $runner->runTask($task);
        $pending = $storage->findPending();

        // Assert: Task should be executed within custom grace period
        $this->assertTrue($result, 'Task should be executed within custom grace period');
        $this->assertSame(0, $pending->count(), 'Task should be archived');
    }
}
