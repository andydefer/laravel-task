<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Services;

use AndyDefer\DomainStructures\Collections\Utility\StrictDataObjectCollection;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Configs\TaskConfig;
use AndyDefer\Task\Contracts\Configs\TaskConfigInterface;
use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\Services\TaskValidatorService;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use Carbon\Carbon;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

final class TaskValidatorServiceTest extends IntegrationTestCase
{
    private Carbon $now;
    private ConfigRepository $configRepository;

    protected function setUp(): void
    {
        parent::setUp();

        // Freeze time in UTC to avoid timezone issues
        $this->now = Carbon::create(2026, 5, 24, 12, 15, 0, 'UTC');
        Carbon::setTestNow($this->now);

        // Get the config repository from Laravel container
        $this->configRepository = $this->app->make(ConfigRepository::class);

        // Set default configuration values
        $this->setConfigDefaults();
    }

    private function setConfigDefaults(): void
    {
        $this->configRepository->set('task.grace_period.enabled', true);
        $this->configRepository->set('task.grace_period.seconds', 86400);
    }

    private function createValidator(): TaskValidatorService
    {
        $config = new TaskConfig($this->configRepository);
        return new TaskValidatorService($config);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createTaskPayload(): TaskPayloadRecord
    {
        $payloadCollection = new StrictDataObjectCollection();
        $payloadCollection->add(StrictDataObject::from([
            'test_data' => 'validator_test',
        ]));

        return new TaskPayloadRecord(
            type: 'test',
            data: $payloadCollection,
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

    // ==================== Validation Tests ====================

    public function test_validate_task_class_returns_true_for_valid_class(): void
    {
        // Arrange
        $validator = $this->createValidator();
        $className = TestTask::class;

        // Act
        $result = $validator->validateTaskClass($className);

        // Assert
        $this->assertTrue($result);
    }

    public function test_validate_task_class_returns_false_for_invalid_class(): void
    {
        // Arrange
        $validator = $this->createValidator();
        $className = 'NonExistentClass';

        // Act
        $result = $validator->validateTaskClass($className);

        // Assert
        $this->assertFalse($result);
    }

    // ==================== Can Run Task Tests ====================

    public function test_can_run_task_returns_true_for_pending_task_with_valid_dates(): void
    {
        // Arrange
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-1 hour'),
            endAt: $this->getRelativeDate('+1 hour'),
        );

        // Act
        $result = $validator->canRunTask($task);

        // Assert
        $this->assertTrue($result);
    }

    public function test_can_run_task_returns_false_for_task_not_started_yet(): void
    {
        // Arrange
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('+1 hour'),
            endAt: $this->getRelativeDate('+2 hours'),
        );

        // Act
        $result = $validator->canRunTask($task);

        // Assert
        $this->assertFalse($result);
    }

    public function test_can_run_task_returns_false_for_completed_task(): void
    {
        // Arrange
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-1 hour'),
            endAt: $this->getRelativeDate('+1 hour'),
            status: TaskStatus::SUCCESS,
        );

        // Act
        $result = $validator->canRunTask($task);

        // Assert
        $this->assertFalse($result);
    }

    public function test_can_run_task_returns_false_when_max_attempts_reached(): void
    {
        // Arrange
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-1 hour'),
            endAt: $this->getRelativeDate('+1 hour'),
            attempts: 3,
        );

        // Act
        $result = $validator->canRunTask($task);

        // Assert
        $this->assertFalse($result);
    }

    public function test_can_run_task_returns_false_for_expired_task_without_grace_period(): void
    {
        // Arrange
        $this->configRepository->set('task.grace_period.enabled', false);
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-2 days'),
            endAt: $this->getRelativeDate('-1 day'),
        );

        // Act
        $result = $validator->canRunTask($task);

        // Assert
        $this->assertFalse($result);

        // Reset to default
        $this->setConfigDefaults();
    }

    public function test_can_run_task_returns_true_for_expired_task_with_grace_period(): void
    {
        // Arrange
        $this->configRepository->set('task.grace_period.enabled', true);
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-2 days'),
            endAt: $this->getRelativeDate('-1 day'),
            delaySeconds: 0,
        );

        // Act
        $result = $validator->canRunTask($task);

        // Assert
        $this->assertTrue($result);
    }

    // ==================== Is Task Expired Tests ====================

    public function test_is_task_expired_returns_true_for_expired_task(): void
    {
        // Arrange
        $this->configRepository->set('task.grace_period.enabled', false);
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-2 days'),
            endAt: $this->getRelativeDate('-1 day'),
        );

        // Act
        $result = $validator->isTaskExpired($task);

        // Assert
        $this->assertTrue($result);

        // Reset to default
        $this->setConfigDefaults();
    }

    public function test_is_task_expired_returns_false_for_non_expired_task(): void
    {
        // Arrange
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-1 hour'),
            endAt: $this->getRelativeDate('+1 hour'),
        );

        // Act
        $result = $validator->isTaskExpired($task);

        // Assert
        $this->assertFalse($result);
    }

    // ==================== Enforce Exact Schedule Tests ====================

    public function test_can_run_task_with_enforce_exact_schedule_not_executable_when_expired(): void
    {
        // Arrange
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-2 days'),
            endAt: $this->getRelativeDate('-1 day'),
            delaySeconds: 0,
            enforceExactSchedule: true,
        );

        // Act
        $result = $validator->canRunTask($task);

        // Assert
        $this->assertFalse($result);
    }

    public function test_can_run_task_with_enforce_exact_schedule_executable_when_within_window(): void
    {
        // Arrange
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-1 hour'),
            endAt: $this->getRelativeDate('+1 hour'),
            enforceExactSchedule: true,
        );

        // Act
        $result = $validator->canRunTask($task);

        // Assert
        $this->assertTrue($result);
    }

    public function test_is_task_expired_with_enforce_exact_schedule_returns_true(): void
    {
        // Arrange
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-2 days'),
            endAt: $this->getRelativeDate('-1 day'),
            enforceExactSchedule: true,
        );

        // Act
        $result = $validator->isTaskExpired($task);

        // Assert
        $this->assertTrue($result);
    }

    // ==================== Unique Task With Grace Period Tests ====================

    public function test_is_unique_task_with_grace_period_true(): void
    {
        // Arrange
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: $this->now->toIso8601String(),
            endAt: $this->getRelativeDate('+1 hour'),
            delaySeconds: 0,
        );

        // Act
        $result = $validator->isUniqueTaskWithGracePeriod($task);

        // Assert
        $this->assertTrue($result);
    }

    public function test_is_unique_task_with_grace_period_false_when_enforce_exact_schedule(): void
    {
        // Arrange
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: $this->now->toIso8601String(),
            endAt: $this->getRelativeDate('+1 hour'),
            delaySeconds: 0,
            enforceExactSchedule: true,
        );

        // Act
        $result = $validator->isUniqueTaskWithGracePeriod($task);

        // Assert
        $this->assertFalse($result);
    }

    public function test_is_unique_task_with_grace_period_false_for_recurring_task(): void
    {
        // Arrange
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: $this->now->toIso8601String(),
            endAt: $this->getRelativeDate('+1 hour'),
            delaySeconds: 300,
        );

        // Act
        $result = $validator->isUniqueTaskWithGracePeriod($task);

        // Assert
        $this->assertFalse($result);
    }

    // ==================== Grace Period Specific Tests ====================

    public function test_unique_task_expired_but_within_grace_period_is_executable(): void
    {
        // Arrange
        $this->configRepository->set('task.grace_period.enabled', true);
        $this->configRepository->set('task.grace_period.seconds', 86400);
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: '2026-05-24T12:00:00Z',
            endAt: '2026-05-24T12:10:00Z',
            delaySeconds: 0,
        );

        // Act
        $result = $validator->canRunTask($task);

        // Assert
        $this->assertTrue($result);
    }

    public function test_unique_task_expired_and_outside_grace_period_is_not_executable(): void
    {
        // Arrange
        $this->configRepository->set('task.grace_period.enabled', true);
        $this->configRepository->set('task.grace_period.seconds', 86400);
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: '2026-05-23T12:00:00Z',
            endAt: '2026-05-23T12:10:00Z',
            delaySeconds: 0,
        );

        // Act
        $result = $validator->canRunTask($task);

        // Assert
        $this->assertFalse($result);
    }

    public function test_recurring_task_does_not_get_grace_period(): void
    {
        // Arrange
        $this->configRepository->set('task.grace_period.enabled', true);
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: '2026-05-24T12:00:00Z',
            endAt: '2026-05-24T12:10:00Z',
            delaySeconds: 300,
        );

        // Act
        $result = $validator->canRunTask($task);

        // Assert
        $this->assertFalse($result);
    }

    public function test_unique_task_within_time_window_is_executable(): void
    {
        // Arrange
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: '2026-05-24T12:00:00Z',
            endAt: '2026-05-24T12:30:00Z',
            delaySeconds: 0,
        );

        // Act
        $result = $validator->canRunTask($task);

        // Assert
        $this->assertTrue($result);
    }

    public function test_get_grace_period_delay(): void
    {
        // Arrange
        $validator = $this->createValidator();
        $endAt = $this->now->copy()->subMinutes(5);

        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-1 hour'),
            endAt: $endAt->toIso8601String(),
            delaySeconds: 0,
        );

        // Act
        $delay = $validator->getGracePeriodDelay($task);

        // Assert
        $this->assertGreaterThanOrEqual(300, $delay);
    }

    public function test_is_task_expired_with_grace_period(): void
    {
        // Arrange
        $this->configRepository->set('task.grace_period.enabled', true);
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: '2026-05-24T12:00:00Z',
            endAt: '2026-05-24T12:10:00Z',
            delaySeconds: 0,
        );

        // Act
        $isExpired = $validator->isTaskExpired($task);

        // Assert
        $this->assertFalse($isExpired);
    }

    public function test_is_task_expired_outside_grace_period(): void
    {
        // Arrange
        $this->configRepository->set('task.grace_period.enabled', true);
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: '2026-05-23T12:00:00Z',
            endAt: '2026-05-23T12:10:00Z',
            delaySeconds: 0,
        );

        // Act
        $isExpired = $validator->isTaskExpired($task);

        // Assert
        $this->assertTrue($isExpired);
    }

    // ==================== Grace Period Config Tests ====================

    public function test_grace_period_disabled_via_config(): void
    {
        // Arrange
        $this->configRepository->set('task.grace_period.enabled', false);
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: '2026-05-24T12:00:00Z',
            endAt: '2026-05-24T12:10:00Z',
            delaySeconds: 0,
        );

        // Act
        $result = $validator->canRunTask($task);

        // Assert
        $this->assertFalse($result);

        // Reset to default
        $this->setConfigDefaults();
    }

    public function test_grace_period_seconds_can_be_customized_via_config(): void
    {
        // Arrange
        $this->configRepository->set('task.grace_period.enabled', true);
        $this->configRepository->set('task.grace_period.seconds', 3600);
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-2 days'),
            endAt: $this->getRelativeDate('-1 day'),
            delaySeconds: 0,
        );

        // Act
        $result = $validator->canRunTask($task);

        // Assert
        $this->assertFalse($result);

        // Reset to default
        $this->setConfigDefaults();
    }

    public function test_grace_period_seconds_customized_allows_execution_within_window(): void
    {
        // Arrange
        $this->configRepository->set('task.grace_period.enabled', true);
        $this->configRepository->set('task.grace_period.seconds', 3600);
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: '2026-05-24T12:00:00Z',
            endAt: '2026-05-24T12:10:00Z',
            delaySeconds: 0,
        );

        // Act
        $result = $validator->canRunTask($task);

        // Assert
        $this->assertTrue($result);

        // Reset to default
        $this->setConfigDefaults();
    }
}
