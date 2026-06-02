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

final class TaskValidatorServiceTest extends UnitTestCase
{
    private TaskValidatorService $validator;

    private TaskConfig&Stub $config;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mock config with grace period enabled
        $this->config = $this->createStub(TaskConfig::class);
        $this->config->method('gracePeriodEnabled')->willReturn(true);
        $this->config->method('gracePeriodSeconds')->willReturn(86400); // 24 hours

        // Freeze time in UTC to avoid timezone issues
        $this->now = Carbon::create(2026, 5, 24, 12, 15, 0, 'UTC');
        Carbon::setTestNow($this->now);

        $this->validator = new TaskValidatorService($this->config);
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
            'test_data' => 'validator_test',
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
            createdAt: $this->now->toIso8601String(),
            startAt: $startAt,
            endAt: $endAt,
            delaySeconds: $delaySeconds,
            attempts: $attempts,
            maxAttempts: 3,
            enforceExactSchedule: $enforceExactSchedule,
        );
    }

    private function getRelativeDate(string $modifier): string
    {
        return $this->now->copy()->modify($modifier)->toIso8601String();
    }

    public function test_validate_task_class_returns_true_for_valid_class(): void
    {
        // Arrange: Valid task class name
        $className = TestTask::class;

        // Act: Validate the task class
        $result = $this->validator->validateTaskClass($className);

        // Assert: Should return true for valid class
        $this->assertTrue($result);
    }

    public function test_validate_task_class_returns_false_for_invalid_class(): void
    {
        // Arrange: Invalid task class name (does not exist)
        $className = 'NonExistentClass';

        // Act: Validate the task class
        $result = $this->validator->validateTaskClass($className);

        // Assert: Should return false for invalid class
        $this->assertFalse($result);
    }

    public function test_can_run_task_returns_true_for_pending_task_with_valid_dates(): void
    {
        // Arrange: Create a pending task within its execution window
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-1 hour'),
            endAt: $this->getRelativeDate('+1 hour'),
        );

        // Act: Check if task can run
        $result = $this->validator->canRunTask($task);

        // Assert: Task should be executable
        $this->assertTrue($result);
    }

    public function test_can_run_task_returns_false_for_task_not_started_yet(): void
    {
        // Arrange: Create a task that starts in the future
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('+1 hour'),
            endAt: $this->getRelativeDate('+2 hours'),
        );

        // Act: Check if task can run
        $result = $this->validator->canRunTask($task);

        // Assert: Task should not be executable (not started)
        $this->assertFalse($result);
    }

    public function test_can_run_task_returns_false_for_completed_task(): void
    {
        // Arrange: Create a completed task (SUCCESS status)
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-1 hour'),
            endAt: $this->getRelativeDate('+1 hour'),
            status: TaskStatus::SUCCESS,
        );

        // Act: Check if task can run
        $result = $this->validator->canRunTask($task);

        // Assert: Task should not be executable (already completed)
        $this->assertFalse($result);
    }

    public function test_can_run_task_returns_false_when_max_attempts_reached(): void
    {
        // Arrange: Create a task that has reached max attempts
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-1 hour'),
            endAt: $this->getRelativeDate('+1 hour'),
            attempts: 3, // Max attempts is 3
        );

        // Act: Check if task can run
        $result = $this->validator->canRunTask($task);

        // Assert: Task should not be executable (max attempts reached)
        $this->assertFalse($result);
    }

    public function test_can_run_task_returns_false_for_expired_task_without_grace_period(): void
    {
        // Arrange: Create mock config with grace period disabled
        $config = $this->createStub(TaskConfig::class);
        $config->method('gracePeriodEnabled')->willReturn(false);
        $config->method('gracePeriodSeconds')->willReturn(86400);

        $validator = new TaskValidatorService($config);

        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-2 days'),
            endAt: $this->getRelativeDate('-1 day'),
        );

        // Act: Check if task can run
        $result = $validator->canRunTask($task);

        // Assert: Task should not be executable (expired, no grace period)
        $this->assertFalse($result);
    }

    public function test_can_run_task_returns_true_for_expired_task_with_grace_period(): void
    {
        // Arrange: Create an expired task with grace period enabled
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-2 days'),
            endAt: $this->getRelativeDate('-1 day'),
            delaySeconds: 0,
        );

        // Act: Check if task can run
        $result = $this->validator->canRunTask($task);

        // Assert: Task should be executable due to grace period
        $this->assertTrue($result);
    }

    public function test_is_task_expired_returns_true_for_expired_task(): void
    {
        // Arrange: Create mock config with grace period disabled
        $config = $this->createStub(TaskConfig::class);
        $config->method('gracePeriodEnabled')->willReturn(false);
        $config->method('gracePeriodSeconds')->willReturn(86400);

        $validator = new TaskValidatorService($config);

        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-2 days'),
            endAt: $this->getRelativeDate('-1 day'),
        );

        // Act: Check if task is expired
        $result = $validator->isTaskExpired($task);

        // Assert: Task should be considered expired
        $this->assertTrue($result);
    }

    public function test_is_task_expired_returns_false_for_non_expired_task(): void
    {
        // Arrange: Create a non-expired task
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-1 hour'),
            endAt: $this->getRelativeDate('+1 hour'),
        );

        // Act: Check if task is expired
        $result = $this->validator->isTaskExpired($task);

        // Assert: Task should not be considered expired
        $this->assertFalse($result);
    }

    public function test_can_run_task_with_enforce_exact_schedule_not_executable_when_expired(): void
    {
        // Arrange: Create expired task with exact schedule enforcement
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-2 days'),
            endAt: $this->getRelativeDate('-1 day'),
            delaySeconds: 0,
            enforceExactSchedule: true,
        );

        // Act: Check if task can run
        $result = $this->validator->canRunTask($task);

        // Assert: Task should not be executable (exact schedule + expired)
        $this->assertFalse($result);
    }

    public function test_can_run_task_with_enforce_exact_schedule_executable_when_within_window(): void
    {
        // Arrange: Create task within window with exact schedule enforcement
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-1 hour'),
            endAt: $this->getRelativeDate('+1 hour'),
            enforceExactSchedule: true,
        );

        // Act: Check if task can run
        $result = $this->validator->canRunTask($task);

        // Assert: Task should be executable (within window)
        $this->assertTrue($result);
    }

    public function test_is_task_expired_with_enforce_exact_schedule_returns_true(): void
    {
        // Arrange: Create expired task with exact schedule enforcement
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-2 days'),
            endAt: $this->getRelativeDate('-1 day'),
            enforceExactSchedule: true,
        );

        // Act: Check if task is expired
        $result = $this->validator->isTaskExpired($task);

        // Assert: Task should be considered expired
        $this->assertTrue($result);
    }

    public function test_is_unique_task_with_grace_period_true(): void
    {
        // Arrange: Create a unique task (delaySeconds = 0)
        $task = $this->createTestTask(
            startAt: $this->now->toIso8601String(),
            endAt: $this->getRelativeDate('+1 hour'),
            delaySeconds: 0,
        );

        // Act: Check if task qualifies for unique task grace period
        $result = $this->validator->isUniqueTaskWithGracePeriod($task);

        // Assert: Task should qualify for grace period
        $this->assertTrue($result);
    }

    public function test_is_unique_task_with_grace_period_false_when_enforce_exact_schedule(): void
    {
        // Arrange: Create unique task with exact schedule enforcement
        $task = $this->createTestTask(
            startAt: $this->now->toIso8601String(),
            endAt: $this->getRelativeDate('+1 hour'),
            delaySeconds: 0,
            enforceExactSchedule: true,
        );

        // Act: Check if task qualifies for unique task grace period
        $result = $this->validator->isUniqueTaskWithGracePeriod($task);

        // Assert: Task should NOT qualify (exact schedule)
        $this->assertFalse($result);
    }

    public function test_is_unique_task_with_grace_period_false_for_recurring_task(): void
    {
        // Arrange: Create a recurring task (delaySeconds > 0)
        $task = $this->createTestTask(
            startAt: $this->now->toIso8601String(),
            endAt: $this->getRelativeDate('+1 hour'),
            delaySeconds: 300, // Recurring task
        );

        // Act: Check if task qualifies for unique task grace period
        $result = $this->validator->isUniqueTaskWithGracePeriod($task);

        // Assert: Recurring tasks should NOT qualify
        $this->assertFalse($result);
    }

    public function test_get_grace_period_delay(): void
    {
        // Arrange: Create a task whose endAt is 5 minutes in the past
        $endAt = $this->now->copy()->subMinutes(5);

        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-1 hour'),
            endAt: $endAt->toIso8601String(),
            delaySeconds: 0,
        );

        // Act: Get grace period delay
        $delay = $this->validator->getGracePeriodDelay($task);

        // Assert: Delay should be at least 300 seconds (5 minutes)
        $this->assertGreaterThanOrEqual(300, $delay);
    }

    public function test_grace_period_seconds_customized_via_config(): void
    {
        // Arrange: Create mock config with custom grace period (1 hour = 3600 seconds)
        $config = $this->createStub(TaskConfig::class);
        $config->method('gracePeriodEnabled')->willReturn(true);
        $config->method('gracePeriodSeconds')->willReturn(3600);

        $validator = new TaskValidatorService($config);

        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-2 days'),
            endAt: $this->getRelativeDate('-1 day'),
            delaySeconds: 0,
        );

        // Act: Check if task can run (task ended yesterday, now is today)
        // 1 day = 86400 seconds > 3600 seconds grace period → should be expired
        $result = $validator->canRunTask($task);

        // Assert: Task should NOT be executable (outside custom grace period)
        $this->assertFalse($result);
    }
}
