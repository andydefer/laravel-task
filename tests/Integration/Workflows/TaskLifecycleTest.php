<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Workflows;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\LaravelJsonl\Contexts\JsonlContext;
use AndyDefer\LaravelJsonl\JsonlService;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\PhpServices\Contracts\FileSystemInterface;
use AndyDefer\PhpServices\Services\FileSystemService;
use AndyDefer\Task\Configs\TaskConfig;
use AndyDefer\Task\Contexts\TaskStorageContext;
use AndyDefer\Task\Contracts\Configs\TaskConfigInterface;
use AndyDefer\Task\Contracts\Repositories\RecurringTaskRepositoryInterface;
use AndyDefer\Task\Contracts\Repositories\TaskRepositoryInterface;
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

final class TaskLifecycleTest extends IntegrationTestCase
{
    private TaskRepositoryInterface $taskRepository;

    private TaskRunnerService $runner;

    private TaskValidatorService $validator;

    private string $storagePath;

    private TaskConfigInterface $config;

    private ConfigRepository $configRepository;

    private HydrationService $hydration;

    private FileSystemInterface $fs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = sys_get_temp_dir().'/task_storage_'.uniqid();
        $this->configRepository = $this->app->make(ConfigRepository::class);
        $this->hydration = new HydrationService;
        $this->fs = new FileSystemService;

        $this->setConfigDefaults();

        $this->config = new TaskConfig($this->configRepository);

        $context = new TaskStorageContext($this->config);
        $strategy = new TaskPathStrategy($this->config->storagePath());
        $jsonlContext = new JsonlContext;
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
        $this->validator = new TaskValidatorService(
            config: $this->config,
            hydration: $this->hydration,
            logger: $logger,
            app: $this->app,
        );

        $this->runner = new TaskRunnerService(
            taskRepository: $this->taskRepository,
            recurringTaskRepository: $this->app->make(RecurringTaskRepositoryInterface::class),
            logger: $logger,
            validator: $this->validator,
            config: $this->config,
            hydration: $this->hydration,
            fs: $this->fs,
            app: $this->app,
        );
    }

    private function setConfigDefaults(): void
    {
        $this->configRepository->set('task.storage_path', $this->storagePath);
        $this->configRepository->set('task.storage_pending_path', $this->storagePath.'/pending');
        $this->configRepository->set('task.storage_recurring_path', $this->storagePath.'/recurring');
        $this->configRepository->set('task.storage_completed_path', $this->storagePath.'/completed');
        $this->configRepository->set('task.storage_grace_period_path', $this->storagePath.'/grace_period');
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

    /**
     * Crée un payload avec un seul objet StrictDataObject.
     */
    private function createTaskPayload(): TaskPayloadRecord
    {
        $data = new StrictDataObject([
            'test_data' => 'lifecycle_test',
        ]);

        return new TaskPayloadRecord(
            type: 'test',
            data: $data,
        );
    }

    private function createTestTask(
        string $id,
        string $class = TestTask::class,
        string $signature = 'test',
        int $attempts = 0,
        int $maxAttempts = 3,
        ?string $startAt = null,
        ?string $endAt = null,
        bool $enforceExactSchedule = false
    ): TaskRecord {
        $payload = $this->createTaskPayload();

        return new TaskRecord(
            id: new TaskIdVO($id),
            signature: new TaskSignatureVO($signature),
            class: $class,
            payload: $payload,
            status: TaskStatus::PENDING,
            created_at: new Iso8601DateTimeVO,
            start_at: $startAt !== null ? new Iso8601DateTimeVO($startAt) : new Iso8601DateTimeVO(date('c', strtotime('-1 minute'))),
            end_at: $endAt !== null ? new Iso8601DateTimeVO($endAt) : new Iso8601DateTimeVO(date('c', strtotime('+1 hour'))),
            delay_seconds: new CounterVO(0),
            attempts: new CounterVO($attempts),
            max_attempts: new CounterVO($maxAttempts),
            enforce_exact_schedule: $enforceExactSchedule,
        );
    }

    public function test_complete_task_lifecycle(): void
    {
        $task = $this->createTestTask('550e8400-e29b-41d4-a716-446655440100');
        $this->taskRepository->save($task);

        $pendingBefore = $this->taskRepository->findAll();
        $result = $this->runner->runTask($task);
        $pendingAfter = $this->taskRepository->findAll();

        $this->assertSame(1, $pendingBefore->count());
        $this->assertTrue($result);
        $this->assertSame(0, $pendingAfter->count());
    }

    public function test_task_created_with_pending_status(): void
    {
        $task = $this->createTestTask('550e8400-e29b-41d4-a716-446655440101');

        $this->taskRepository->save($task);
        $pending = $this->taskRepository->findAll();
        $savedTask = $pending->first();

        $this->assertNotNull($savedTask);
        $this->assertSame(TaskStatus::PENDING, $savedTask->status);
    }

    public function test_task_moves_to_completed_after_success(): void
    {
        $task = $this->createTestTask('550e8400-e29b-41d4-a716-446655440102');
        $this->taskRepository->save($task);

        $this->runner->runTask($task);
        $pending = $this->taskRepository->findAll();

        $this->assertSame(0, $pending->count());
    }

    public function test_task_not_started_before_start_at(): void
    {
        $task = $this->createTestTask(
            id: '550e8400-e29b-41d4-a716-446655440103',
            startAt: date('c', strtotime('+1 hour')),
            endAt: date('c', strtotime('+2 hours'))
        );
        $this->taskRepository->save($task);

        $canRun = $this->validator->canRunTask($task);

        $this->assertFalse($canRun);
    }

    public function test_task_does_not_run_after_end_at(): void
    {
        $task = $this->createTestTask(
            id: '550e8400-e29b-41d4-a716-446655440104',
            startAt: date('c', strtotime('-2 days')),
            endAt: date('c', strtotime('-1 day')),
            enforceExactSchedule: true
        );
        $this->taskRepository->save($task);

        $result = $this->runner->runTask($task);

        $this->assertFalse($result);
    }

    public function test_task_can_be_deleted_before_execution(): void
    {
        $task = $this->createTestTask('550e8400-e29b-41d4-a716-446655440105');
        $this->taskRepository->save($task);

        $this->taskRepository->delete(new TaskIdVO('550e8400-e29b-41d4-a716-446655440105'));
        $pending = $this->taskRepository->findAll();

        $this->assertSame(0, $pending->count());
    }

    public function test_task_failure_does_not_remove_from_pending(): void
    {
        $task = $this->createTestTask(
            id: '550e8400-e29b-41d4-a716-446655440106',
            class: FailingTask::class,
            signature: 'failing'
        );
        $this->taskRepository->save($task);

        $this->runner->runTask($task);
        $pending = $this->taskRepository->findAll();

        $this->assertSame(1, $pending->count());
    }

    public function test_task_can_be_retrieved_by_id(): void
    {
        $taskId = '550e8400-e29b-41d4-a716-446655440107';
        $task = $this->createTestTask($taskId);
        $this->taskRepository->save($task);

        $pending = $this->taskRepository->findAll();
        $foundTask = $pending->first();

        $this->assertNotNull($foundTask);
        $this->assertSame($taskId, $foundTask->id->value);
        $this->assertSame(TestTask::class, $foundTask->class);
    }

    public function test_multiple_tasks_can_be_processed_sequentially(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $task = $this->createTestTask("550e8400-e29b-41d4-a716-44665544020{$i}");
            $this->taskRepository->save($task);
        }

        $pendingBefore = $this->taskRepository->findAll();

        foreach ($pendingBefore as $task) {
            $this->runner->runTask($task);
        }

        $pendingAfter = $this->taskRepository->findAll();

        $this->assertSame(3, $pendingBefore->count());
        $this->assertSame(0, $pendingAfter->count());
    }
}
