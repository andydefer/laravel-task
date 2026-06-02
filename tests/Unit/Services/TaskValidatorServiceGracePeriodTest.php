<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Unit\Services;

use AndyDefer\DomainStructures\Collections\Utility\StrictDataObjectCollection;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Configs\TaskConfig;
use AndyDefer\Task\Enums\TaskMode;
use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\Services\TaskValidatorService;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestTask;
use AndyDefer\Task\Tests\UnitTestCase;
use Carbon\Carbon;
use PHPUnit\Framework\MockObject\Stub;

final class TaskValidatorServiceGracePeriodTest extends UnitTestCase
{
    private TaskValidatorService $validator;

    private TaskConfig&Stub $config;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock config with grace period enabled
        $this->config = $this->createStub(TaskConfig::class);
        $this->config->method('gracePeriodEnabled')->willReturn(true);
        $this->config->method('gracePeriodSeconds')->willReturn(86400); // 24 hours

        $this->validator = new TaskValidatorService($this->config);

        // Freeze time to 12:15 on May 24, 2026
        Carbon::setTestNow(Carbon::create(2026, 5, 24, 12, 15, 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createTaskPayload(): TaskPayloadRecord
    {
        $payloadCollection = new StrictDataObjectCollection;
        $payloadCollection->add(StrictDataObject::from([
            'test_data' => 'grace_period_test',
        ]));

        return new TaskPayloadRecord(
            type: 'test',
            payload: $payloadCollection,
        );
    }

    private function createTestTask(
        string $startAt,
        ?string $endAt = null,
        int $delaySeconds = 0,
        TaskStatus $status = TaskStatus::PENDING,
        int $attempts = 0,
        bool $enforceExactSchedule = false
    ): TaskRecord {
        $payload = $this->createTaskPayload();

        return new TaskRecord(
            id: '123',
            signature: 'test',
            class: TestTask::class,
            payload: $payload,
            mode: TaskMode::SYNC,
            status: $status,
            createdAt: date('c'),
            startAt: $startAt,
            endAt: $endAt,
            delaySeconds: $delaySeconds,
            attempts: $attempts,
            maxAttempts: 3,
            enforceExactSchedule: $enforceExactSchedule,
        );
    }

    public function test_unique_task_expired_but_within_grace_period_is_executable(): void
    {
        // Arrange: Create an expired unique task (ended at 12:10, now is 12:15)
        $task = $this->createTestTask(
            startAt: '2026-05-24T12:00:00Z',
            endAt: '2026-05-24T12:10:00Z',
            delaySeconds: 0,
        );

        // Act: Check if task can run
        $result = $this->validator->canRunTask($task);

        // Assert: Task should be executable within grace period
        $this->assertTrue($result);
    }

    public function test_unique_task_expired_and_outside_grace_period_is_not_executable(): void
    {
        // Arrange: Create a task expired outside grace period (ended yesterday)
        $task = $this->createTestTask(
            startAt: '2026-05-23T12:00:00Z',
            endAt: '2026-05-23T12:10:00Z',
            delaySeconds: 0,
        );

        // Act: Check if task can run
        $result = $this->validator->canRunTask($task);

        // Assert: Task should NOT be executable (outside grace period)
        $this->assertFalse($result);
    }

    public function test_recurring_task_does_not_get_grace_period(): void
    {
        // Arrange: Create a recurring task (delaySeconds > 0 indicates recurring)
        $task = $this->createTestTask(
            startAt: '2026-05-24T12:00:00Z',
            endAt: '2026-05-24T12:10:00Z',
            delaySeconds: 300, // Recurring task
        );

        // Act: Check if task can run
        $result = $this->validator->canRunTask($task);

        // Assert: Recurring tasks should not benefit from grace period
        $this->assertFalse($result);
    }

    public function test_unique_task_within_time_window_is_executable(): void
    {
        // Arrange: Create a task within its execution window
        $task = $this->createTestTask(
            startAt: '2026-05-24T12:00:00Z',
            endAt: '2026-05-24T12:30:00Z',
            delaySeconds: 0,
        );

        // Act: Check if task can run
        $result = $this->validator->canRunTask($task);

        // Assert: Task should be executable
        $this->assertTrue($result);
    }

    public function test_task_not_started_yet_is_not_executable(): void
    {
        // Arrange: Create a task that starts in the future
        $task = $this->createTestTask(
            startAt: '2026-05-24T13:00:00Z',
            endAt: '2026-05-24T14:00:00Z',
            delaySeconds: 0,
        );

        // Act: Check if task can run
        $result = $this->validator->canRunTask($task);

        // Assert: Task should not be executable (not started)
        $this->assertFalse($result);
    }

    public function test_task_with_max_attempts_reached_is_not_executable(): void
    {
        // Arrange: Create a task that has reached max attempts
        $task = $this->createTestTask(
            startAt: '2026-05-24T12:00:00Z',
            endAt: '2026-05-24T12:30:00Z',
            delaySeconds: 0,
            attempts: 3, // Max attempts is 3
        );

        // Act: Check if task can run
        $result = $this->validator->canRunTask($task);

        // Assert: Task should not be executable (max attempts reached)
        $this->assertFalse($result);
    }

    public function test_task_with_non_pending_status_is_not_executable(): void
    {
        // Arrange: Create a task with SUCCESS status
        $task = $this->createTestTask(
            startAt: '2026-05-24T12:00:00Z',
            endAt: '2026-05-24T12:30:00Z',
            delaySeconds: 0,
            status: TaskStatus::SUCCESS,
        );

        // Act: Check if task can run
        $result = $this->validator->canRunTask($task);

        // Assert: Task should not be executable (not pending)
        $this->assertFalse($result);
    }

    public function test_grace_period_disabled_via_config(): void
    {
        // Arrange: Create mock config with grace period disabled
        $config = $this->createStub(TaskConfig::class);
        $config->method('gracePeriodEnabled')->willReturn(false);
        $config->method('gracePeriodSeconds')->willReturn(86400);

        $validator = new TaskValidatorService($config);

        $task = $this->createTestTask(
            startAt: '2026-05-24T12:00:00Z',
            endAt: '2026-05-24T12:10:00Z',
            delaySeconds: 0,
        );

        // Act: Check if task can run
        $result = $validator->canRunTask($task);

        // Assert: Task should not be executable (grace period disabled)
        $this->assertFalse($result);
    }

    public function test_is_task_expired_with_grace_period(): void
    {
        // Arrange: Create an expired task (ended at 12:10, now is 12:15)
        $task = $this->createTestTask(
            startAt: '2026-05-24T12:00:00Z',
            endAt: '2026-05-24T12:10:00Z',
            delaySeconds: 0,
        );

        // Act: Check if task is considered expired
        $isExpired = $this->validator->isTaskExpired($task);

        // Assert: Task should NOT be considered expired (within grace period)
        $this->assertFalse($isExpired);
    }

    public function test_is_task_expired_outside_grace_period(): void
    {
        // Arrange: Create a task expired outside grace period (ended yesterday)
        $task = $this->createTestTask(
            startAt: '2026-05-23T12:00:00Z',
            endAt: '2026-05-23T12:10:00Z',
            delaySeconds: 0,
        );

        // Act: Check if task is considered expired
        $isExpired = $this->validator->isTaskExpired($task);

        // Assert: Task should be considered expired
        $this->assertTrue($isExpired);
    }

    public function test_get_grace_period_delay(): void
    {
        // Arrange: Create an expired task to calculate grace delay
        $task = $this->createTestTask(
            startAt: '2026-05-24T12:00:00Z',
            endAt: '2026-05-24T12:10:00Z',
            delaySeconds: 0,
        );

        // Act: Get grace period delay
        $delay = $this->validator->getGracePeriodDelay($task);

        // Assert: Delay should be at least 300 seconds (5 minutes)
        $this->assertGreaterThanOrEqual(300, $delay);
    }

    public function test_is_unique_task_with_grace_period(): void
    {
        // Arrange: Create a unique task (delaySeconds = 0)
        $task = $this->createTestTask(
            startAt: '2026-05-24T12:00:00Z',
            endAt: '2026-05-24T12:10:00Z',
            delaySeconds: 0,
        );

        // Act: Check if task qualifies for unique task grace period
        $isUnique = $this->validator->isUniqueTaskWithGracePeriod($task);

        // Assert: Task should be considered unique with grace period
        $this->assertTrue($isUnique);
    }

    public function test_recurring_task_is_not_unique_with_grace_period(): void
    {
        // Arrange: Create a recurring task (delaySeconds > 0)
        $task = $this->createTestTask(
            startAt: '2026-05-24T12:00:00Z',
            endAt: null,
            delaySeconds: 300,
        );

        // Act: Check if task qualifies for unique task grace period
        $isUnique = $this->validator->isUniqueTaskWithGracePeriod($task);

        // Assert: Recurring tasks should not qualify
        $this->assertFalse($isUnique);
    }

    public function test_grace_period_seconds_customized_via_config(): void
    {
        // Arrange: Create mock config with custom grace period (1 hour = 3600 seconds)
        $config = $this->createStub(TaskConfig::class);
        $config->method('gracePeriodEnabled')->willReturn(true);
        $config->method('gracePeriodSeconds')->willReturn(3600);

        $validator = new TaskValidatorService($config);

        $task = $this->createTestTask(
            startAt: '2026-05-24T12:00:00Z',
            endAt: '2026-05-24T12:10:00Z',
            delaySeconds: 0,
        );

        // Act: Check if task can run
        $result = $validator->canRunTask($task);

        // Assert: Task should be executable within custom grace period (5 minutes < 1 hour)
        $this->assertTrue($result);
    }
}
