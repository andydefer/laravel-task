<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Services;

use AndyDefer\DomainStructures\Collections\Utility\StrictDataObjectCollection;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Logger;
use AndyDefer\Task\Configs\TaskConfig;
use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\Services\TaskRunnerService;
use AndyDefer\Task\Services\TaskStorageService;
use AndyDefer\Task\Services\TaskValidatorService;
use AndyDefer\Task\Tests\Fixtures\Tasks\FailingTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use Carbon\Carbon;
use PHPUnit\Framework\MockObject\Stub;

final class TaskRunnerServiceTest extends IntegrationTestCase
{
    private TaskStorageService $storage;
    private TaskRunnerService $runner;
    private string $storagePath;
    private TaskConfig&Stub $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storagePath = sys_get_temp_dir() . '/task_storage_' . uniqid();
    }

    private function createServiceWithConfig(array $configOverrides = []): void
    {
        $this->config = $this->createStub(TaskConfig::class);

        $defaults = [
            'storagePath' => $this->storagePath,
            'storagePendingPath' => $this->storagePath . '/pending',
            'storageRecurringPath' => $this->storagePath . '/recurring',
            'storageCompletedPath' => $this->storagePath . '/completed',
            'gracePeriodEnabled' => false,
            'gracePeriodSeconds' => 86400,
            'batchLimit' => 1000,
            'batchOrder' => 'oldest',
        ];

        $config = array_merge($defaults, $configOverrides);

        $this->config->method('storagePath')->willReturn($config['storagePath']);
        $this->config->method('storagePendingPath')->willReturn($config['storagePendingPath']);
        $this->config->method('storageRecurringPath')->willReturn($config['storageRecurringPath']);
        $this->config->method('storageCompletedPath')->willReturn($config['storageCompletedPath']);
        $this->config->method('gracePeriodEnabled')->willReturn($config['gracePeriodEnabled']);
        $this->config->method('gracePeriodSeconds')->willReturn($config['gracePeriodSeconds']);
        $this->config->method('batchLimit')->willReturn($config['batchLimit']);
        $this->config->method('batchOrder')->willReturn($config['batchOrder']);

        $this->storage = new TaskStorageService($this->config);
        $logger = $this->app->make(Logger::class);
        $validator = new TaskValidatorService($this->config);
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
        if (!is_dir($path)) {
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

    private function createTaskPayload(): TaskPayloadRecord
    {
        $payloadCollection = new StrictDataObjectCollection();
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
        ?string $endAt = null,
        bool $enforceExactSchedule = false,
    ): TaskRecord {
        $payload = $this->createTaskPayload();

        return new TaskRecord(
            id: $id,
            signature: $signature,
            class: $class,
            payload: $payload,
            status: $status,
            createdAt: date('c'),
            startAt: date('c', strtotime('-1 minute')),
            endAt: $endAt ?? date('c', strtotime('+1 hour')),
            delaySeconds: 0,
            attempts: $attempts,
            maxAttempts: $maxAttempts,
            enforceExactSchedule: $enforceExactSchedule,
        );
    }

    private function createExpiredTask(bool $enforceExactSchedule = false): TaskRecord
    {
        return new TaskRecord(
            id: 'expired-task',
            signature: 'test-task',
            class: TestTask::class,
            payload: $this->createTaskPayload(),
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

    private function createRecurringTask(string $signature, int $delaySeconds = 300): RecurringTaskRecord
    {
        $payload = $this->createTaskPayload();

        return new RecurringTaskRecord(
            signature: $signature,
            class: TestTask::class,
            payload: $payload,
            startAt: date('c', strtotime('-1 hour')),
            endAt: null,
            delaySeconds: $delaySeconds,
            lastRunAt: null,
            nextRunAt: date('c', strtotime('-5 minutes')),
            successCount: 0,
            failureCount: 0,
        );
    }

    private function createRecurringTaskWithCounts(
        string $signature,
        int $successCount,
        int $failureCount,
        int $delaySeconds = 300
    ): RecurringTaskRecord {
        $payload = $this->createTaskPayload();

        return new RecurringTaskRecord(
            signature: $signature,
            class: TestTask::class,
            payload: $payload,
            startAt: date('c', strtotime('-1 hour')),
            endAt: null,
            delaySeconds: $delaySeconds,
            lastRunAt: null,
            nextRunAt: date('c', strtotime('-5 minutes')),
            successCount: $successCount,
            failureCount: $failureCount,
        );
    }

    // ==================== Basic Task Execution Tests ====================

    public function test_run_task_success(): void
    {
        // Arrange
        $this->createServiceWithConfig();

        $task = $this->createTaskRecord('123', 'test', TestTask::class);
        $this->storage->savePending($task);

        // Act
        $result = $this->runner->runTask($task);

        // Assert
        $this->assertTrue($result);
    }

    public function test_run_task_failure(): void
    {
        // Arrange
        $this->createServiceWithConfig();

        $task = $this->createTaskRecord('456', 'failing', FailingTask::class);
        $this->storage->savePending($task);

        // Act
        $result = $this->runner->runTask($task);

        // Assert
        $this->assertFalse($result);
    }

    public function test_run_task_returns_false_when_task_not_pending(): void
    {
        // Arrange
        $this->createServiceWithConfig();

        $task = $this->createTaskRecord('789', 'test', TestTask::class, 0, 3, TaskStatus::RUNNING);
        $this->storage->savePending($task);

        // Act
        $result = $this->runner->runTask($task);

        // Assert
        $this->assertFalse($result);
    }

    public function test_run_task_returns_false_when_max_attempts_reached(): void
    {
        // Arrange
        $this->createServiceWithConfig();

        $task = $this->createTaskRecord('999', 'failing', FailingTask::class, 3, 3);
        $this->storage->savePending($task);

        // Act
        $result = $this->runner->runTask($task);

        // Assert
        $this->assertFalse($result);
    }

    public function test_run_task_returns_false_when_task_expired(): void
    {
        // Arrange
        $this->createServiceWithConfig();

        $task = $this->createTaskRecord(
            id: '111',
            signature: 'test',
            class: TestTask::class,
            endAt: date('c', strtotime('-1 day'))
        );
        $this->storage->savePending($task);

        // Act
        $result = $this->runner->runTask($task);

        // Assert
        $this->assertFalse($result);
    }

    public function test_run_task_increments_attempts_on_failure(): void
    {
        // Arrange
        $this->createServiceWithConfig();

        $task = $this->createTaskRecord('222', 'failing', FailingTask::class, 0, 3);
        $this->storage->savePending($task);

        // Act
        $result = $this->runner->runTask($task);

        // Assert
        $this->assertFalse($result);

        $pending = $this->storage->findPending();
        $this->assertSame(1, $pending->count());

        $updatedTask = $pending->first();
        $this->assertSame(1, $updatedTask->attempts);
        $this->assertNotNull($updatedTask->lastError);
    }

    public function test_run_task_archives_after_max_attempts(): void
    {
        // Arrange
        $this->createServiceWithConfig();

        $task = $this->createTaskRecord('333', 'failing', FailingTask::class, 2, 3);
        $this->storage->savePending($task);

        // Act
        $result = $this->runner->runTask($task);

        // Assert
        $this->assertFalse($result);

        $pending = $this->storage->findPending();
        $this->assertSame(0, $pending->count());
    }

    public function test_run_task_with_invalid_class_returns_false(): void
    {
        // Arrange
        $this->createServiceWithConfig();

        $task = $this->createTaskRecord('invalid', 'invalid', 'NonExistentClass');
        $this->storage->savePending($task);

        // Act
        $result = $this->runner->runTask($task);

        // Assert
        $this->assertFalse($result);

        $pending = $this->storage->findPending();
        $this->assertSame(0, $pending->count());
    }

    // ==================== Recurring Task Tests ====================

    public function test_run_recurring_task_success(): void
    {
        // Arrange
        $this->createServiceWithConfig();

        $task = $this->createRecurringTask('recurring-test');
        $this->storage->saveRecurring($task);

        // Act
        $result = $this->runner->runRecurringTask($task);

        // Assert
        $this->assertTrue($result);

        $updated = $this->storage->getRecurring('recurring-test');
        $this->assertNotNull($updated);
        $this->assertSame(1, $updated->successCount);
        $this->assertNotNull($updated->lastRunAt);
    }

    public function test_run_recurring_task_failure(): void
    {
        // Arrange
        $this->createServiceWithConfig();

        $task = new RecurringTaskRecord(
            signature: 'recurring-failing',
            class: FailingTask::class,
            payload: $this->createTaskPayload(),
            startAt: date('c', strtotime('-1 hour')),
            endAt: null,
            delaySeconds: 300,
            lastRunAt: null,
            nextRunAt: date('c', strtotime('-5 minutes')),
            successCount: 0,
            failureCount: 0,
        );
        $this->storage->saveRecurring($task);

        // Act
        $result = $this->runner->runRecurringTask($task);

        // Assert
        $this->assertFalse($result);

        $updated = $this->storage->getRecurring('recurring-failing');
        $this->assertNotNull($updated);
        $this->assertSame(1, $updated->failureCount);
        $this->assertNotNull($updated->lastError);
    }

    public function test_run_recurring_task_increments_success_count(): void
    {
        // Arrange
        $this->createServiceWithConfig();

        $task = $this->createRecurringTaskWithCounts('recurring-counter', 5, 2);
        $this->storage->saveRecurring($task);

        // Act
        $result = $this->runner->runRecurringTask($task);

        // Assert
        $this->assertTrue($result);

        $updated = $this->storage->getRecurring('recurring-counter');
        $this->assertNotNull($updated);
        $this->assertSame(6, $updated->successCount);
        $this->assertSame(2, $updated->failureCount);
    }

    public function test_run_recurring_task_updates_next_run_at(): void
    {
        // Arrange
        $this->createServiceWithConfig();

        $payload = $this->createTaskPayload();

        $task = new RecurringTaskRecord(
            signature: 'recurring-next-run',
            class: TestTask::class,
            payload: $payload,
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

        // Act
        $result = $this->runner->runRecurringTask($task);

        // Assert
        $this->assertTrue($result);

        $updated = $this->storage->getRecurring('recurring-next-run');
        $this->assertNotNull($updated);
        $this->assertNotSame($oldNextRunAt, $updated->nextRunAt);
        $this->assertNotNull($updated->lastRunAt);
    }

    public function test_run_recurring_task_with_invalid_class_returns_false(): void
    {
        // Arrange
        $this->createServiceWithConfig();

        $task = new RecurringTaskRecord(
            signature: 'invalid-recurring',
            class: 'NonExistentClass',
            payload: $this->createTaskPayload(),
            startAt: date('c', strtotime('-1 hour')),
            endAt: null,
            delaySeconds: 300,
            lastRunAt: null,
            nextRunAt: date('c', strtotime('-5 minutes')),
            successCount: 0,
            failureCount: 0,
        );
        $this->storage->saveRecurring($task);

        // Act
        $result = $this->runner->runRecurringTask($task);

        // Assert
        $this->assertFalse($result);

        $updated = $this->storage->getRecurring('invalid-recurring');
        $this->assertNotNull($updated);
        $this->assertSame(1, $updated->failureCount);
        $this->assertNotNull($updated->lastError);
    }

    // ==================== Grace Period Tests ====================

    public function test_expired_unique_task_is_executed_during_grace_period(): void
    {
        // Arrange
        Carbon::setTestNow(Carbon::create(2026, 5, 24, 12, 15, 0));
        $this->createServiceWithConfig([
            'gracePeriodEnabled' => true,
            'gracePeriodSeconds' => 86400,
        ]);

        $task = $this->createExpiredTask(false);
        $this->storage->savePending($task);

        // Act
        $result = $this->runner->runTask($task);
        $pending = $this->storage->findPending();

        // Assert
        $this->assertTrue($result);
        $this->assertSame(0, $pending->count());
    }

    public function test_expired_unique_task_archived_if_grace_period_expired(): void
    {
        // Arrange
        Carbon::setTestNow(Carbon::create(2026, 5, 24, 12, 15, 0));
        $this->createServiceWithConfig([
            'gracePeriodEnabled' => true,
            'gracePeriodSeconds' => 86400,
        ]);

        $task = $this->createExpiredTask(true);
        $this->storage->savePending($task);

        // Act
        $result = $this->runner->runTask($task);
        $pending = $this->storage->findPending();

        // Assert
        $this->assertFalse($result);
        $this->assertSame(0, $pending->count());
    }

    public function test_recurring_task_not_affected_by_grace_period(): void
    {
        // Arrange
        Carbon::setTestNow(Carbon::create(2026, 5, 24, 12, 15, 0));
        $this->createServiceWithConfig([
            'gracePeriodEnabled' => true,
            'gracePeriodSeconds' => 86400,
        ]);

        $task = $this->createRecurringTask('recurring-task');
        $this->storage->saveRecurring($task);

        // Act
        $result = $this->runner->runRecurringTask($task);

        // Assert
        $this->assertTrue($result);
    }

    public function test_unique_task_outside_grace_period_is_not_executed(): void
    {
        // Arrange
        Carbon::setTestNow(Carbon::create(2026, 5, 24, 12, 15, 0));
        $this->createServiceWithConfig([
            'gracePeriodEnabled' => true,
            'gracePeriodSeconds' => 86400,
        ]);

        $task = $this->createExpiredTask(true);
        $this->storage->savePending($task);

        // Act
        $result = $this->runner->runTask($task);

        // Assert
        $this->assertFalse($result);
    }

    public function test_grace_period_can_be_disabled_via_config(): void
    {
        // Arrange
        Carbon::setTestNow(Carbon::create(2026, 5, 24, 12, 15, 0));
        $this->createServiceWithConfig([
            'gracePeriodEnabled' => false,
            'gracePeriodSeconds' => 86400,
        ]);

        $task = $this->createExpiredTask(false);
        $this->storage->savePending($task);

        // Act
        $result = $this->runner->runTask($task);
        $pending = $this->storage->findPending();

        // Assert
        $this->assertFalse($result);
        $this->assertSame(0, $pending->count());
    }

    public function test_grace_period_seconds_can_be_customized_via_config(): void
    {
        // Arrange
        Carbon::setTestNow(Carbon::create(2026, 5, 24, 12, 15, 0));
        $this->createServiceWithConfig([
            'gracePeriodEnabled' => true,
            'gracePeriodSeconds' => 3600,
        ]);

        $task = $this->createExpiredTask(false);
        $this->storage->savePending($task);

        // Act
        $result = $this->runner->runTask($task);
        $pending = $this->storage->findPending();

        // Assert
        $this->assertTrue($result);
        $this->assertSame(0, $pending->count());
    }
}
