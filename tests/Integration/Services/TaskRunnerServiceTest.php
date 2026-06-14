<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Services;

use AndyDefer\DomainStructures\Collections\Utility\StrictDataObjectCollection;
use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\LaravelJsonl\Contexts\JsonlContext;
use AndyDefer\LaravelJsonl\JsonlService;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\PhpServices\Contracts\FileSystemInterface;
use AndyDefer\PhpServices\Services\FileSystemService;
use AndyDefer\Task\Configs\TaskConfig;
use AndyDefer\Task\Contracts\Configs\TaskConfigInterface;
use AndyDefer\Task\Contracts\Repositories\RecurringTaskRepositoryInterface;
use AndyDefer\Task\Contracts\Repositories\TaskRepositoryInterface;
use AndyDefer\Task\Contexts\TaskStorageContext;
use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\Repositories\RecurringTaskRepository;
use AndyDefer\Task\Repositories\TaskRepository;
use AndyDefer\Task\Services\TaskRunnerService;
use AndyDefer\Task\Services\TaskValidatorService;
use AndyDefer\Task\Strategies\TaskPathStrategy;
use AndyDefer\Task\Tests\Fixtures\Tasks\FailingTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use Carbon\Carbon;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

final class TaskRunnerServiceTest extends IntegrationTestCase
{
    private TaskRepositoryInterface $taskRepository;
    private RecurringTaskRepositoryInterface $recurringTaskRepository;
    private TaskRunnerService $runner;
    private string $storagePath;
    private TaskConfigInterface $config;
    private ConfigRepository $configRepository;
    private HydrationService $hydration;
    private FileSystemInterface $fs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storagePath = sys_get_temp_dir() . '/task_storage_' . uniqid();
        $this->configRepository = $this->app->make(ConfigRepository::class);
        $this->hydration = new HydrationService();
        $this->fs = new FileSystemService();

        $this->setConfigDefaults();
    }

    private function setConfigDefaults(array $configOverrides = []): void
    {
        $defaults = [
            'task.storage_path' => $this->storagePath,
            'task.grace_period.enabled' => false,
            'task.grace_period.seconds' => 86400,
            'task.batch.limit' => 1000,
            'task.batch.order' => 'oldest',
        ];

        $config = array_merge($defaults, $configOverrides);

        foreach ($config as $key => $value) {
            $this->configRepository->set($key, $value);
        }

        $this->config = new TaskConfig($this->configRepository);
    }

    private function createService(): void
    {
        $context = new TaskStorageContext($this->config);
        $strategy = new TaskPathStrategy($this->config->storagePath());
        $jsonlContext = new JsonlContext();
        $jsonlService = new JsonlService(
            pathStrategy: $strategy,
            fileSystem: $this->fs,
            context: $jsonlContext,
        );

        // Repositories
        $this->taskRepository = new TaskRepository(
            context: $context,
            jsonl: $jsonlService,
            hydration: $this->hydration,
            fs: $this->fs,
        );

        $this->recurringTaskRepository = new RecurringTaskRepository(
            context: $context,
            jsonl: $jsonlService,
            hydration: $this->hydration,
            fs: $this->fs,
        );

        $logger = $this->app->make(LoggerInterface::class);
        $validator = new TaskValidatorService(
            config: $this->config,
            hydration: $this->hydration,
            logger: $logger,
            app: $this->app,
        );

        $this->runner = new TaskRunnerService(
            taskRepository: $this->taskRepository,
            recurringTaskRepository: $this->recurringTaskRepository,
            logger: $logger,
            validator: $validator,
            config: $this->config,
            hydration: $this->hydration,
            fs: $this->fs,
            app: $this->app,
        );
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
            data: $payloadCollection,
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
            id: new TaskIdVO($id),
            signature: new TaskSignatureVO($signature),
            class: $class,
            payload: $payload,
            status: $status,
            created_at: new Iso8601DateTimeVO(),
            start_at: new Iso8601DateTimeVO(date('c', strtotime('-1 minute'))),
            end_at: $endAt !== null ? new Iso8601DateTimeVO($endAt) : new Iso8601DateTimeVO(date('c', strtotime('+1 hour'))),
            delay_seconds: new CounterVO(0),
            attempts: new CounterVO($attempts),
            max_attempts: new CounterVO($maxAttempts),
            enforce_exact_schedule: $enforceExactSchedule,
        );
    }

    private function createExpiredTask(bool $enforceExactSchedule = false): TaskRecord
    {
        $payload = $this->createTaskPayload();

        return new TaskRecord(
            id: new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'),
            signature: new TaskSignatureVO('test-task'),
            class: TestTask::class,
            payload: $payload,
            status: TaskStatus::PENDING,
            created_at: new Iso8601DateTimeVO(),
            start_at: new Iso8601DateTimeVO('2026-05-24T12:00:00+00:00'),
            end_at: new Iso8601DateTimeVO('2026-05-24T12:10:00+00:00'),
            delay_seconds: new CounterVO(0),
            attempts: new CounterVO(0),
            max_attempts: new CounterVO(3),
            enforce_exact_schedule: $enforceExactSchedule,
        );
    }

    private function createRecurringTask(string $signature, int $delaySeconds = 300): RecurringTaskRecord
    {
        $payload = $this->createTaskPayload();

        return new RecurringTaskRecord(
            signature: new TaskSignatureVO($signature),
            class: TestTask::class,
            payload: $payload,
            start_at: new Iso8601DateTimeVO(date('c', strtotime('-1 hour'))),
            end_at: null,
            delay_seconds: new CounterVO($delaySeconds),
            last_run_at: null,
            next_run_at: new Iso8601DateTimeVO(date('c', strtotime('-5 minutes'))),
            success_count: new CounterVO(0),
            failure_count: new CounterVO(0),
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
            signature: new TaskSignatureVO($signature),
            class: TestTask::class,
            payload: $payload,
            start_at: new Iso8601DateTimeVO(date('c', strtotime('-1 hour'))),
            end_at: null,
            delay_seconds: new CounterVO($delaySeconds),
            last_run_at: null,
            next_run_at: new Iso8601DateTimeVO(date('c', strtotime('-5 minutes'))),
            success_count: new CounterVO($successCount),
            failure_count: new CounterVO($failureCount),
        );
    }

    // ==================== Basic Task Execution Tests ====================

    public function test_run_task_success(): void
    {
        $this->setConfigDefaults();
        $this->createService();

        $task = $this->createTaskRecord('550e8400-e29b-41d4-a716-446655440000', 'test', TestTask::class);
        $this->taskRepository->save($task);

        $result = $this->runner->runTask($task);

        $this->assertTrue($result);
    }

    public function test_run_task_failure(): void
    {
        $this->setConfigDefaults();
        $this->createService();

        $task = $this->createTaskRecord('660e8400-e29b-41d4-a716-446655440001', 'failing', FailingTask::class);
        $this->taskRepository->save($task);

        $result = $this->runner->runTask($task);

        $this->assertFalse($result);
    }

    public function test_run_task_returns_false_when_task_not_pending(): void
    {
        $this->setConfigDefaults();
        $this->createService();

        $task = $this->createTaskRecord('770e8400-e29b-41d4-a716-446655440002', 'test', TestTask::class, 0, 3, TaskStatus::RUNNING);
        $this->taskRepository->save($task);

        $result = $this->runner->runTask($task);

        $this->assertFalse($result);
    }

    public function test_run_task_returns_false_when_max_attempts_reached(): void
    {
        $this->setConfigDefaults();
        $this->createService();

        $task = $this->createTaskRecord('880e8400-e29b-41d4-a716-446655440003', 'failing', FailingTask::class, 3, 3);
        $this->taskRepository->save($task);

        $result = $this->runner->runTask($task);

        $this->assertFalse($result);
    }

    public function test_run_task_returns_false_when_task_expired(): void
    {
        $this->setConfigDefaults();
        $this->createService();

        $task = $this->createTaskRecord(
            id: '990e8400-e29b-41d4-a716-446655440004',
            signature: 'test',
            class: TestTask::class,
            endAt: date('c', strtotime('-1 day'))
        );
        $this->taskRepository->save($task);

        $result = $this->runner->runTask($task);

        $this->assertFalse($result);
    }

    public function test_run_task_increments_attempts_on_failure(): void
    {
        $this->setConfigDefaults();
        $this->createService();

        $task = $this->createTaskRecord('aaae8400-e29b-41d4-a716-446655440005', 'failing', FailingTask::class, 0, 3);
        $this->taskRepository->save($task);

        $result = $this->runner->runTask($task);

        $this->assertFalse($result);

        $pending = $this->taskRepository->findAll();
        $this->assertSame(1, $pending->count());

        $updatedTask = $pending->first();
        $this->assertSame(1, $updatedTask->attempts->value);
        $this->assertNotNull($updatedTask->last_error);
    }

    public function test_run_task_archives_after_max_attempts(): void
    {
        $this->setConfigDefaults();
        $this->createService();

        $task = $this->createTaskRecord('bbbe8400-e29b-41d4-a716-446655440006', 'failing', FailingTask::class, 2, 3);
        $this->taskRepository->save($task);

        $result = $this->runner->runTask($task);

        $this->assertFalse($result);

        $pending = $this->taskRepository->findAll();
        $this->assertSame(0, $pending->count());
    }

    public function test_run_task_with_invalid_class_returns_false(): void
    {
        $this->setConfigDefaults();
        $this->createService();

        $task = $this->createTaskRecord('ccce8400-e29b-41d4-a716-446655440007', 'invalid', 'NonExistentClass');
        $this->taskRepository->save($task);

        $result = $this->runner->runTask($task);

        $this->assertFalse($result);

        $pending = $this->taskRepository->findAll();
        $this->assertSame(0, $pending->count());
    }

    // ==================== Recurring Task Tests ====================

    public function test_run_recurring_task_success(): void
    {
        $this->setConfigDefaults();
        $this->createService();

        $task = $this->createRecurringTask('recurring-test');
        $this->recurringTaskRepository->save($task);

        $result = $this->runner->runRecurringTask($task);

        $this->assertTrue($result);

        $updated = $this->recurringTaskRepository->find(new TaskSignatureVO('recurring-test'));
        $this->assertNotNull($updated);
        $this->assertSame(1, $updated->success_count->value);
        $this->assertNotNull($updated->last_run_at);
    }

    public function test_run_recurring_task_failure(): void
    {
        $this->setConfigDefaults();
        $this->createService();

        $task = new RecurringTaskRecord(
            signature: new TaskSignatureVO('recurring-failing'),
            class: FailingTask::class,
            payload: $this->createTaskPayload(),
            start_at: new Iso8601DateTimeVO(date('c', strtotime('-1 hour'))),
            end_at: null,
            delay_seconds: new CounterVO(300),
            last_run_at: null,
            next_run_at: new Iso8601DateTimeVO(date('c', strtotime('-5 minutes'))),
            success_count: new CounterVO(0),
            failure_count: new CounterVO(0),
        );
        $this->recurringTaskRepository->save($task);

        $result = $this->runner->runRecurringTask($task);

        $this->assertFalse($result);

        $updated = $this->recurringTaskRepository->find(new TaskSignatureVO('recurring-failing'));
        $this->assertNotNull($updated);
        $this->assertSame(1, $updated->failure_count->value);
        $this->assertNotNull($updated->last_error);
    }

    public function test_run_recurring_task_increments_success_count(): void
    {
        $this->setConfigDefaults();
        $this->createService();

        $task = $this->createRecurringTaskWithCounts('recurring-counter', 5, 2);
        $this->recurringTaskRepository->save($task);

        $result = $this->runner->runRecurringTask($task);

        $this->assertTrue($result);

        $updated = $this->recurringTaskRepository->find(new TaskSignatureVO('recurring-counter'));
        $this->assertNotNull($updated);
        $this->assertSame(6, $updated->success_count->value);
        $this->assertSame(2, $updated->failure_count->value);
    }

    public function test_run_recurring_task_updates_next_run_at(): void
    {
        $this->setConfigDefaults();
        $this->createService();

        $payload = $this->createTaskPayload();

        $task = new RecurringTaskRecord(
            signature: new TaskSignatureVO('recurring-next-run'),
            class: TestTask::class,
            payload: $payload,
            start_at: new Iso8601DateTimeVO(date('c', strtotime('-1 hour'))),
            end_at: null,
            delay_seconds: new CounterVO(300),
            last_run_at: null,
            next_run_at: new Iso8601DateTimeVO(date('c', strtotime('-10 minutes'))),
            success_count: new CounterVO(0),
            failure_count: new CounterVO(0),
        );
        $this->recurringTaskRepository->save($task);

        $oldNextRunAt = $task->next_run_at->value;

        $result = $this->runner->runRecurringTask($task);

        $this->assertTrue($result);

        $updated = $this->recurringTaskRepository->find(new TaskSignatureVO('recurring-next-run'));
        $this->assertNotNull($updated);
        $this->assertNotSame($oldNextRunAt, $updated->next_run_at->value);
        $this->assertNotNull($updated->last_run_at);
    }

    public function test_run_recurring_task_with_invalid_class_returns_false(): void
    {
        $this->setConfigDefaults();
        $this->createService();

        $task = new RecurringTaskRecord(
            signature: new TaskSignatureVO('invalid-recurring'),
            class: 'NonExistentClass',
            payload: $this->createTaskPayload(),
            start_at: new Iso8601DateTimeVO(date('c', strtotime('-1 hour'))),
            end_at: null,
            delay_seconds: new CounterVO(300),
            last_run_at: null,
            next_run_at: new Iso8601DateTimeVO(date('c', strtotime('-5 minutes'))),
            success_count: new CounterVO(0),
            failure_count: new CounterVO(0),
        );
        $this->recurringTaskRepository->save($task);

        $result = $this->runner->runRecurringTask($task);

        $this->assertFalse($result);

        $updated = $this->recurringTaskRepository->find(new TaskSignatureVO('invalid-recurring'));
        $this->assertNotNull($updated);
        $this->assertSame(1, $updated->failure_count->value);
        $this->assertNotNull($updated->last_error);
    }

    // ==================== Grace Period Tests ====================

    public function test_expired_unique_task_is_executed_during_grace_period(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 24, 12, 15, 0));
        $this->setConfigDefaults([
            'task.grace_period.enabled' => true,
            'task.grace_period.seconds' => 86400,
        ]);
        $this->createService();

        $task = $this->createExpiredTask(false);
        $this->taskRepository->save($task);

        $result = $this->runner->runTask($task);
        $pending = $this->taskRepository->findAll();

        $this->assertTrue($result);
        $this->assertSame(0, $pending->count());
    }

    public function test_expired_unique_task_archived_if_grace_period_expired(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 24, 12, 15, 0));
        $this->setConfigDefaults([
            'task.grace_period.enabled' => true,
            'task.grace_period.seconds' => 86400,
        ]);
        $this->createService();

        $task = $this->createExpiredTask(true);
        $this->taskRepository->save($task);

        $result = $this->runner->runTask($task);
        $pending = $this->taskRepository->findAll();

        $this->assertFalse($result);
        $this->assertSame(0, $pending->count());
    }

    public function test_recurring_task_not_affected_by_grace_period(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 24, 12, 15, 0));
        $this->setConfigDefaults([
            'task.grace_period.enabled' => true,
            'task.grace_period.seconds' => 86400,
        ]);
        $this->createService();

        $task = $this->createRecurringTask('recurring-task');
        $this->recurringTaskRepository->save($task);

        $result = $this->runner->runRecurringTask($task);

        $this->assertTrue($result);
    }

    public function test_unique_task_outside_grace_period_is_not_executed(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 24, 12, 15, 0));
        $this->setConfigDefaults([
            'task.grace_period.enabled' => true,
            'task.grace_period.seconds' => 86400,
        ]);
        $this->createService();

        $task = $this->createExpiredTask(true);
        $this->taskRepository->save($task);

        $result = $this->runner->runTask($task);

        $this->assertFalse($result);
    }

    public function test_grace_period_can_be_disabled_via_config(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 24, 12, 15, 0));
        $this->setConfigDefaults([
            'task.grace_period.enabled' => false,
            'task.grace_period.seconds' => 86400,
        ]);
        $this->createService();

        $task = $this->createExpiredTask(false);
        $this->taskRepository->save($task);

        $result = $this->runner->runTask($task);
        $pending = $this->taskRepository->findAll();

        $this->assertFalse($result);
        $this->assertSame(0, $pending->count());
    }

    public function test_grace_period_seconds_can_be_customized_via_config(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 5, 24, 12, 15, 0));
        $this->setConfigDefaults([
            'task.grace_period.enabled' => true,
            'task.grace_period.seconds' => 3600,
        ]);
        $this->createService();

        $task = $this->createExpiredTask(false);
        $this->taskRepository->save($task);

        $result = $this->runner->runTask($task);
        $pending = $this->taskRepository->findAll();

        $this->assertTrue($result);
        $this->assertSame(0, $pending->count());
    }
}
