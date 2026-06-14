<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Services;

use AndyDefer\DomainStructures\Collections\Utility\StrictDataObjectCollection;
use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Task\Configs\TaskConfig;
use AndyDefer\Task\Contexts\TaskContext;
use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\Services\TaskValidatorService;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use Carbon\Carbon;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

final class TaskValidatorServiceTest extends IntegrationTestCase
{
    private Carbon $now;

    private ConfigRepository $configRepository;

    private HydrationService $hydration;

    private TaskContext $taskContext;

    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = Carbon::create(2026, 5, 24, 12, 15, 0, 'UTC');
        Carbon::setTestNow($this->now);

        $this->configRepository = $this->app->make(ConfigRepository::class);
        $this->hydration = new HydrationService;
        $this->logger = $this->app->make(LoggerInterface::class);
        $this->setConfigDefaults();

        // Contexte partagé pour les tâches (utilise l'Application réelle)
        $this->taskContext = new TaskContext;
        $this->taskContext->setTaskId(new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'));
        $this->taskContext->setSignature(new TaskSignatureVO('test'));
        $this->taskContext->setLaravelApp($this->app);
    }

    private function setConfigDefaults(): void
    {
        $this->configRepository->set('task.grace_period.enabled', true);
        $this->configRepository->set('task.grace_period.seconds', 86400);
    }

    private function createValidator(): TaskValidatorService
    {
        $config = new TaskConfig($this->configRepository);

        return new TaskValidatorService(
            config: $config,
            hydration: $this->hydration,
            logger: $this->logger,
            app: $this->app,
        );
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

        // Créer une instance de TestTask avec le contexte et les dépendances réelles
        $testTask = new TestTask($this->taskContext, $this->logger, $this->hydration);
        $config = $testTask->getConfig();

        return new TaskRecord(
            id: new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'),
            signature: new TaskSignatureVO('test'),
            class: TestTask::class,
            payload: $payload,
            status: $status,
            created_at: new Iso8601DateTimeVO($this->now->toIso8601String()),
            start_at: new Iso8601DateTimeVO($startAt),
            end_at: $endAt !== null ? new Iso8601DateTimeVO($endAt) : null,
            delay_seconds: new CounterVO($delaySeconds),
            attempts: new CounterVO($attempts),
            max_attempts: new CounterVO(3),
            enforce_exact_schedule: $enforceExactSchedule,
        );
    }

    private function getRelativeDate(string $modifier): string
    {
        return $this->now->copy()->modify($modifier)->toIso8601String();
    }

    // ==================== Validation Tests ====================

    public function test_validate_task_class_returns_true_for_valid_class(): void
    {
        $validator = $this->createValidator();
        $result = $validator->validateTaskClass(TestTask::class);
        $this->assertTrue($result);
    }

    public function test_validate_task_class_returns_false_for_invalid_class(): void
    {
        $validator = $this->createValidator();
        $result = $validator->validateTaskClass('NonExistentClass');
        $this->assertFalse($result);
    }

    // ==================== Can Run Task Tests ====================

    public function test_can_run_task_returns_true_for_pending_task_with_valid_dates(): void
    {
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-1 hour'),
            endAt: $this->getRelativeDate('+1 hour'),
        );

        $result = $validator->canRunTask($task);
        $this->assertTrue($result);
    }

    public function test_can_run_task_returns_false_for_task_not_started_yet(): void
    {
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('+1 hour'),
            endAt: $this->getRelativeDate('+2 hours'),
        );

        $result = $validator->canRunTask($task);
        $this->assertFalse($result);
    }

    public function test_can_run_task_returns_false_for_completed_task(): void
    {
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-1 hour'),
            endAt: $this->getRelativeDate('+1 hour'),
            status: TaskStatus::SUCCESS,
        );

        $result = $validator->canRunTask($task);
        $this->assertFalse($result);
    }

    public function test_can_run_task_returns_false_when_max_attempts_reached(): void
    {
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-1 hour'),
            endAt: $this->getRelativeDate('+1 hour'),
            attempts: 3,
        );

        $result = $validator->canRunTask($task);
        $this->assertFalse($result);
    }

    public function test_can_run_task_returns_false_for_expired_task_without_grace_period(): void
    {
        $this->configRepository->set('task.grace_period.enabled', false);
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-2 days'),
            endAt: $this->getRelativeDate('-1 day'),
        );

        $result = $validator->canRunTask($task);
        $this->assertFalse($result);
        $this->setConfigDefaults();
    }

    public function test_can_run_task_returns_true_for_expired_task_with_grace_period(): void
    {
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-2 days'),
            endAt: $this->getRelativeDate('-1 day'),
            delaySeconds: 0,
        );

        $result = $validator->canRunTask($task);
        $this->assertTrue($result);
    }

    // ==================== Is Task Expired Tests ====================

    public function test_is_task_expired_returns_true_for_expired_task(): void
    {
        $this->configRepository->set('task.grace_period.enabled', false);
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-2 days'),
            endAt: $this->getRelativeDate('-1 day'),
        );

        $result = $validator->isTaskExpired($task);
        $this->assertTrue($result);
        $this->setConfigDefaults();
    }

    public function test_is_task_expired_returns_false_for_non_expired_task(): void
    {
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-1 hour'),
            endAt: $this->getRelativeDate('+1 hour'),
        );

        $result = $validator->isTaskExpired($task);
        $this->assertFalse($result);
    }

    // ==================== Enforce Exact Schedule Tests ====================

    public function test_can_run_task_with_enforce_exact_schedule_not_executable_when_expired(): void
    {
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-2 days'),
            endAt: $this->getRelativeDate('-1 day'),
            delaySeconds: 0,
            enforceExactSchedule: true,
        );

        $result = $validator->canRunTask($task);
        $this->assertFalse($result);
    }

    public function test_can_run_task_with_enforce_exact_schedule_executable_when_within_window(): void
    {
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-1 hour'),
            endAt: $this->getRelativeDate('+1 hour'),
            enforceExactSchedule: true,
        );

        $result = $validator->canRunTask($task);
        $this->assertTrue($result);
    }

    public function test_is_task_expired_with_enforce_exact_schedule_returns_true(): void
    {
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-2 days'),
            endAt: $this->getRelativeDate('-1 day'),
            enforceExactSchedule: true,
        );

        $result = $validator->isTaskExpired($task);
        $this->assertTrue($result);
    }

    // ==================== Unique Task With Grace Period Tests ====================

    public function test_is_unique_task_with_grace_period_true(): void
    {
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: $this->now->toIso8601String(),
            endAt: $this->getRelativeDate('+1 hour'),
            delaySeconds: 0,
        );

        $result = $validator->isUniqueTaskWithGracePeriod($task);
        $this->assertTrue($result);
    }

    public function test_is_unique_task_with_grace_period_false_when_enforce_exact_schedule(): void
    {
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: $this->now->toIso8601String(),
            endAt: $this->getRelativeDate('+1 hour'),
            delaySeconds: 0,
            enforceExactSchedule: true,
        );

        $result = $validator->isUniqueTaskWithGracePeriod($task);
        $this->assertFalse($result);
    }

    public function test_is_unique_task_with_grace_period_false_for_recurring_task(): void
    {
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: $this->now->toIso8601String(),
            endAt: $this->getRelativeDate('+1 hour'),
            delaySeconds: 300,
        );

        $result = $validator->isUniqueTaskWithGracePeriod($task);
        $this->assertFalse($result);
    }

    // ==================== Grace Period Specific Tests ====================

    private function getIso8601DateTime(string $dateTime): string
    {
        return Carbon::parse($dateTime)->toIso8601String();
    }

    public function test_unique_task_expired_but_within_grace_period_is_executable(): void
    {
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: $this->getIso8601DateTime('2026-05-24 12:00:00'),
            endAt: $this->getIso8601DateTime('2026-05-24 12:10:00'),
            delaySeconds: 0,
        );

        $result = $validator->canRunTask($task);
        $this->assertTrue($result);
    }

    public function test_unique_task_expired_and_outside_grace_period_is_not_executable(): void
    {
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: $this->getIso8601DateTime('2026-05-23 12:00:00'),
            endAt: $this->getIso8601DateTime('2026-05-23 12:10:00'),
            delaySeconds: 0,
        );

        $result = $validator->canRunTask($task);
        $this->assertFalse($result);
    }

    public function test_recurring_task_does_not_get_grace_period(): void
    {
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: $this->getIso8601DateTime('2026-05-24 12:00:00'),
            endAt: $this->getIso8601DateTime('2026-05-24 12:10:00'),
            delaySeconds: 300,
        );

        $result = $validator->canRunTask($task);
        $this->assertFalse($result);
    }

    public function test_unique_task_within_time_window_is_executable(): void
    {
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: $this->getIso8601DateTime('2026-05-24 12:00:00'),
            endAt: $this->getIso8601DateTime('2026-05-24 12:30:00'),
            delaySeconds: 0,
        );

        $result = $validator->canRunTask($task);
        $this->assertTrue($result);
    }

    public function test_get_grace_period_delay(): void
    {
        $validator = $this->createValidator();
        $endAt = $this->now->copy()->subMinutes(5);

        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-1 hour'),
            endAt: $endAt->toIso8601String(),
            delaySeconds: 0,
        );

        $delay = $validator->getGracePeriodDelay($task);
        $this->assertGreaterThanOrEqual(300, $delay);
    }

    public function test_is_task_expired_with_grace_period(): void
    {
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: $this->getIso8601DateTime('2026-05-24 12:00:00'),
            endAt: $this->getIso8601DateTime('2026-05-24 12:10:00'),
            delaySeconds: 0,
        );

        $isExpired = $validator->isTaskExpired($task);
        $this->assertFalse($isExpired);
    }

    public function test_is_task_expired_outside_grace_period(): void
    {
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: $this->getIso8601DateTime('2026-05-23 12:00:00'),
            endAt: $this->getIso8601DateTime('2026-05-23 12:10:00'),
            delaySeconds: 0,
        );

        $isExpired = $validator->isTaskExpired($task);
        $this->assertTrue($isExpired);
    }

    // ==================== Grace Period Config Tests ====================

    public function test_grace_period_disabled_via_config(): void
    {
        $this->configRepository->set('task.grace_period.enabled', false);
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: $this->getIso8601DateTime('2026-05-24 12:00:00'),
            endAt: $this->getIso8601DateTime('2026-05-24 12:10:00'),
            delaySeconds: 0,
        );

        $result = $validator->canRunTask($task);
        $this->assertFalse($result);
        $this->setConfigDefaults();
    }

    public function test_grace_period_seconds_can_be_customized_via_config(): void
    {
        $this->configRepository->set('task.grace_period.enabled', true);
        $this->configRepository->set('task.grace_period.seconds', 3600);
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: $this->getRelativeDate('-2 days'),
            endAt: $this->getRelativeDate('-1 day'),
            delaySeconds: 0,
        );

        $result = $validator->canRunTask($task);
        $this->assertFalse($result);
        $this->setConfigDefaults();
    }

    public function test_grace_period_seconds_customized_allows_execution_within_window(): void
    {
        $this->configRepository->set('task.grace_period.enabled', true);
        $this->configRepository->set('task.grace_period.seconds', 3600);
        $validator = $this->createValidator();
        $task = $this->createTestTask(
            startAt: $this->getIso8601DateTime('2026-05-24 12:00:00'),
            endAt: $this->getIso8601DateTime('2026-05-24 12:10:00'),
            delaySeconds: 0,
        );

        $result = $validator->canRunTask($task);
        $this->assertTrue($result);
        $this->setConfigDefaults();
    }
}
