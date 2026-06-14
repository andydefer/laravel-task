<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Workflows;

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
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Records\TaskRecord;
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
use Illuminate\Contracts\Config\Repository as ConfigRepository;

final class FailedTaskRetryTest extends IntegrationTestCase
{
    private TaskRepositoryInterface $taskRepository;
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

        $this->config = new TaskConfig($this->configRepository);

        $context = new TaskStorageContext($this->config);
        $strategy = new TaskPathStrategy($this->config->storagePath());
        $jsonlContext = new JsonlContext();
        $jsonlService = new JsonlService(
            pathStrategy: $strategy,
            fileSystem: $this->fs,
            context: $jsonlContext,
        );

        $this->taskRepository = new TaskRepository(
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
            recurringTaskRepository: $this->app->make(RecurringTaskRepositoryInterface::class),
            logger: $logger,
            validator: $validator,
            config: $this->config,
            hydration: $this->hydration,
            fs: $this->fs,
            app: $this->app,
        );
    }

    private function setConfigDefaults(): void
    {
        $this->configRepository->set('task.storage_path', $this->storagePath);
        $this->configRepository->set('task.storage_pending_path', $this->storagePath . '/pending');
        $this->configRepository->set('task.storage_recurring_path', $this->storagePath . '/recurring');
        $this->configRepository->set('task.storage_completed_path', $this->storagePath . '/completed');
        $this->configRepository->set('task.storage_grace_period_path', $this->storagePath . '/grace_period');
        $this->configRepository->set('task.grace_period.enabled', false);
        $this->configRepository->set('task.grace_period.seconds', 86400);
        $this->configRepository->set('task.batch.limit', 1000);
        $this->configRepository->set('task.batch.order', 'oldest');
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

    private function createTaskPayload(?array $customData = null): TaskPayloadRecord
    {
        $payloadCollection = new StrictDataObjectCollection();

        if ($customData !== null) {
            $payloadCollection->add(StrictDataObject::from($customData));
        } else {
            $payloadCollection->add(StrictDataObject::from([
                'test_data' => 'retry_test',
            ]));
        }

        return new TaskPayloadRecord(
            type: 'test',
            data: $payloadCollection,
        );
    }

    private function createFailingTask(
        string $id,
        int $attempts = 0,
        int $maxAttempts = 3,
        ?string $endAt = null
    ): TaskRecord {
        $payload = $this->createTaskPayload();

        return new TaskRecord(
            id: new TaskIdVO($id),
            signature: new TaskSignatureVO('failing'),
            class: FailingTask::class,
            payload: $payload,
            status: TaskStatus::PENDING,
            created_at: new Iso8601DateTimeVO(),
            start_at: new Iso8601DateTimeVO(date('c', strtotime('-1 minute'))),
            end_at: $endAt !== null ? new Iso8601DateTimeVO($endAt) : new Iso8601DateTimeVO(date('c', strtotime('+1 hour'))),
            delay_seconds: new CounterVO(0),
            attempts: new CounterVO($attempts),
            max_attempts: new CounterVO($maxAttempts),
        );
    }

    private function createSuccessfulTask(): TaskRecord
    {
        $payload = $this->createTaskPayload();

        return new TaskRecord(
            id: new TaskIdVO('ddde8400-e29b-41d4-a716-446655440008'),
            signature: new TaskSignatureVO('test'),
            class: TestTask::class,
            payload: $payload,
            status: TaskStatus::PENDING,
            created_at: new Iso8601DateTimeVO(),
            start_at: new Iso8601DateTimeVO(date('c', strtotime('-1 minute'))),
            end_at: new Iso8601DateTimeVO(date('c', strtotime('+1 hour'))),
            delay_seconds: new CounterVO(0),
            attempts: new CounterVO(0),
            max_attempts: new CounterVO(3),
        );
    }

    public function test_failed_task_increments_attempts(): void
    {
        $task = $this->createFailingTask('550e8400-e29b-41d4-a716-446655440000', attempts: 0, maxAttempts: 3);
        $this->taskRepository->save($task);

        $result = $this->runner->runTask($task);
        $pending = $this->taskRepository->findAll();

        $this->assertFalse($result);
        $this->assertSame(1, $pending->count());

        $updatedTask = $pending->first();
        $this->assertSame(1, $updatedTask->attempts->value);
        $this->assertNotNull($updatedTask->last_error);
    }

    public function test_failed_task_increments_attempts_multiple_times(): void
    {
        $task = $this->createFailingTask('660e8400-e29b-41d4-a716-446655440001', attempts: 0, maxAttempts: 3);
        $this->taskRepository->save($task);

        $this->runner->runTask($task);
        $pending = $this->taskRepository->findAll();
        $updatedTask = $pending->first();

        $this->runner->runTask($updatedTask);
        $pending = $this->taskRepository->findAll();

        $this->assertSame(1, $pending->count());
        $finalTask = $pending->first();
        $this->assertSame(2, $finalTask->attempts->value);
    }

    public function test_task_is_archived_after_max_attempts(): void
    {
        $task = $this->createFailingTask('770e8400-e29b-41d4-a716-446655440002', attempts: 2, maxAttempts: 3);
        $this->taskRepository->save($task);

        $result = $this->runner->runTask($task);
        $pending = $this->taskRepository->findAll();

        $this->assertFalse($result);
        $this->assertSame(0, $pending->count());
    }

    public function test_task_with_no_retry_possible_is_archived_immediately(): void
    {
        $task = $this->createFailingTask('880e8400-e29b-41d4-a716-446655440003', attempts: 0, maxAttempts: 1);
        $this->taskRepository->save($task);

        $result = $this->runner->runTask($task);
        $pending = $this->taskRepository->findAll();

        $this->assertFalse($result);
        $this->assertSame(0, $pending->count());
    }

    public function test_successful_task_does_not_retry(): void
    {
        $task = $this->createSuccessfulTask();
        $this->taskRepository->save($task);

        $result = $this->runner->runTask($task);
        $pending = $this->taskRepository->findAll();

        $this->assertTrue($result);
        $this->assertSame(0, $pending->count());
    }

    public function test_failed_task_preserves_payload_after_retry(): void
    {
        $customPayload = $this->createTaskPayload([
            'custom_data' => 123,
            'test_value' => 'test_value',
        ]);

        $task = new TaskRecord(
            id: new TaskIdVO('990e8400-e29b-41d4-a716-446655440004'),
            signature: new TaskSignatureVO('failing'),
            class: FailingTask::class,
            payload: $customPayload,
            status: TaskStatus::PENDING,
            created_at: new Iso8601DateTimeVO(),
            start_at: new Iso8601DateTimeVO(date('c', strtotime('-1 minute'))),
            end_at: new Iso8601DateTimeVO(date('c', strtotime('+1 hour'))),
            delay_seconds: new CounterVO(0),
            attempts: new CounterVO(0),
            max_attempts: new CounterVO(3),
        );

        $this->taskRepository->save($task);

        $this->runner->runTask($task);
        $pending = $this->taskRepository->findAll();
        $updatedTask = $pending->first();

        $this->assertSame($task->payload->type, $updatedTask->payload->type);
        $this->assertSame($task->payload->data->count(), $updatedTask->payload->data->count());
    }

    public function test_expired_task_does_not_retry(): void
    {
        $task = $this->createFailingTask(
            id: 'aaae8400-e29b-41d4-a716-446655440005',
            attempts: 0,
            maxAttempts: 3,
            endAt: date('c', strtotime('-1 day'))
        );
        $this->taskRepository->save($task);

        $result = $this->runner->runTask($task);
        $pending = $this->taskRepository->findAll();

        $this->assertFalse($result);
        $this->assertSame(0, $pending->count());
    }

    public function test_task_retry_respects_max_attempts_boundary(): void
    {
        $maxAttempts = 5;
        $task = $this->createFailingTask('bbbe8400-e29b-41d4-a716-446655440006', attempts: 0, maxAttempts: $maxAttempts);
        $this->taskRepository->save($task);

        $currentTask = $task;
        for ($i = 0; $i < $maxAttempts; $i++) {
            $this->runner->runTask($currentTask);
            $pending = $this->taskRepository->findAll();
            if ($pending->isNotEmpty()) {
                $currentTask = $pending->first();
            }
        }

        $pending = $this->taskRepository->findAll();
        $this->assertSame(0, $pending->count());
    }

    public function test_task_retry_stores_error_message_each_time(): void
    {
        $task = $this->createFailingTask('ccce8400-e29b-41d4-a716-446655440007', attempts: 0, maxAttempts: 3);
        $this->taskRepository->save($task);

        $this->runner->runTask($task);
        $pending = $this->taskRepository->findAll();
        $updatedTask = $pending->first();

        $this->assertNotNull($updatedTask->last_error, 'First error message should not be null');
        $firstError = $updatedTask->last_error;
        $this->assertIsString($firstError);
        $this->assertStringContainsString('Test exception', $firstError);

        $this->runner->runTask($updatedTask);
        $pending = $this->taskRepository->findAll();
        $finalTask = $pending->first();

        $this->assertNotNull($finalTask->last_error, 'Second error message should not be null');
        $secondError = $finalTask->last_error;
        $this->assertIsString($secondError);
        $this->assertStringContainsString('Test exception', $secondError);

        $this->assertNotEmpty($firstError);
        $this->assertNotEmpty($secondError);
    }
}
