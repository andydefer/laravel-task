<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Repositories;

use AndyDefer\DomainStructures\Collections\Utility\StrictDataObjectCollection;
use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\LaravelJsonl\Contexts\JsonlContext;
use AndyDefer\LaravelJsonl\JsonlService;
use AndyDefer\PhpServices\Contracts\FileSystemInterface;
use AndyDefer\PhpServices\Services\FileSystemService;
use AndyDefer\Task\Configs\TaskConfig;
use AndyDefer\Task\Contracts\Configs\TaskConfigInterface;
use AndyDefer\Task\Contexts\TaskStorageContext;
use AndyDefer\Task\Enums\TaskOrder;
use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\Repositories\TaskRepository;
use AndyDefer\Task\Strategies\TaskPathStrategy;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

final class TaskRepositoryTest extends IntegrationTestCase
{
    private TaskRepository $repository;
    private string $tempDir;
    private ConfigRepository $configRepository;
    private HydrationService $hydration;
    private FileSystemInterface $fs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/task_repo_test_' . uniqid();
        $this->configRepository = $this->app->make(ConfigRepository::class);
        $this->hydration = new HydrationService();
        $this->fs = new FileSystemService();

        $this->setConfigDefaults();

        $config = new TaskConfig($this->configRepository);
        $context = new TaskStorageContext($config);
        $strategy = new TaskPathStrategy($config->storagePath());
        $jsonlContext = new JsonlContext();
        $jsonlService = new JsonlService(
            pathStrategy: $strategy,
            fileSystem: $this->fs,
            context: $jsonlContext,
        );

        $this->repository = new TaskRepository(
            context: $context,
            jsonl: $jsonlService,
            hydration: $this->hydration,
            fs: $this->fs,
        );
    }

    private function setConfigDefaults(): void
    {
        $this->configRepository->set('task.storage_path', $this->tempDir);
        $this->configRepository->set('task.storage_pending_path', $this->tempDir . '/pending');
        $this->configRepository->set('task.storage_recurring_path', $this->tempDir . '/recurring');
        $this->configRepository->set('task.storage_completed_path', $this->tempDir . '/completed');
        $this->configRepository->set('task.grace_period.enabled', false);
        $this->configRepository->set('task.grace_period.seconds', 86400);
        $this->configRepository->set('task.batch.limit', 1000);
        $this->configRepository->set('task.batch.order', 'oldest');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->deleteDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') as $file) {
            is_dir($file) ? $this->deleteDirectory($file) : unlink($file);
        }
        rmdir($dir);
    }

    private function createTaskPayload(): TaskPayloadRecord
    {
        $payloadCollection = new StrictDataObjectCollection();
        $payloadCollection->add(StrictDataObject::from([
            'test_data' => 'repository_test',
        ]));

        return new TaskPayloadRecord(
            type: 'test',
            data: $payloadCollection,
        );
    }

    private function createTask(
        string $id,
        TaskStatus $status = TaskStatus::PENDING
    ): TaskRecord {
        return new TaskRecord(
            id: new TaskIdVO($id),
            signature: new TaskSignatureVO('test'),
            class: 'TestClass',
            payload: $this->createTaskPayload(),
            status: $status,
            created_at: new Iso8601DateTimeVO(),
            start_at: new Iso8601DateTimeVO(),
            end_at: null,
            delay_seconds: new CounterVO(0),
            attempts: new CounterVO(0),
            max_attempts: new CounterVO(3),
            enforce_exact_schedule: false,
        );
    }

    private function generateUuid(int $number): string
    {
        return sprintf('550e8400-e29b-41d4-a716-44665544%04d', $number);
    }

    // ==================== Save Tests ====================

    public function test_save_creates_task_file(): void
    {
        $task = $this->createTask($this->generateUuid(1));
        $this->repository->save($task);

        $found = $this->repository->find($task->id);
        $this->assertNotNull($found);
        $this->assertSame($task->id->value, $found->id->value);
    }

    public function test_save_overwrites_existing_task(): void
    {
        $id = $this->generateUuid(2);
        $task1 = $this->createTask($id, TaskStatus::PENDING);
        $this->repository->save($task1);

        // Créer une nouvelle tâche avec le même ID mais des propriétés modifiées
        $task2 = new TaskRecord(
            id: new TaskIdVO($id),
            signature: new TaskSignatureVO('test'),
            class: 'TestClass',
            payload: $this->createTaskPayload(),
            status: TaskStatus::PENDING,
            created_at: new Iso8601DateTimeVO(),
            start_at: new Iso8601DateTimeVO(),
            end_at: null,
            delay_seconds: new CounterVO(0),
            attempts: new CounterVO(0),
            max_attempts: new CounterVO(3),
            enforce_exact_schedule: true,  // ← modifié dans le constructeur
        );
        $this->repository->save($task2);

        $found = $this->repository->find(new TaskIdVO($id));
        $this->assertNotNull($found);
        $this->assertTrue($found->enforce_exact_schedule);
    }

    // ==================== Find Tests ====================

    public function test_find_returns_task_when_exists(): void
    {
        $id = $this->generateUuid(3);
        $task = $this->createTask($id);
        $this->repository->save($task);

        $found = $this->repository->find(new TaskIdVO($id));
        $this->assertNotNull($found);
        $this->assertSame($id, $found->id->value);
    }

    public function test_find_returns_null_when_not_exists(): void
    {
        $found = $this->repository->find(new TaskIdVO($this->generateUuid(999)));
        $this->assertNull($found);
    }

    public function test_find_returns_null_for_non_pending_task(): void
    {
        $id = $this->generateUuid(4);
        $task = $this->createTask($id, TaskStatus::RUNNING);
        $this->repository->save($task);

        $found = $this->repository->find(new TaskIdVO($id));
        $this->assertNull($found);
    }

    // ==================== FindAll Tests ====================

    public function test_findAll_returns_all_pending_tasks(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $task = $this->createTask($this->generateUuid(10 + $i));
            $this->repository->save($task);
        }

        $tasks = $this->repository->findAll();
        $this->assertSame(3, $tasks->count());
    }

    public function test_findAll_returns_only_pending_tasks(): void
    {
        $this->repository->save($this->createTask($this->generateUuid(20), TaskStatus::PENDING));
        $this->repository->save($this->createTask($this->generateUuid(21), TaskStatus::RUNNING));

        $tasks = $this->repository->findAll();
        $this->assertSame(1, $tasks->count());
    }

    public function test_findAll_with_limit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $task = $this->createTask($this->generateUuid(30 + $i));
            $this->repository->save($task);
        }

        $tasks = $this->repository->findAll(3);
        $this->assertSame(3, $tasks->count());
    }

    public function test_findAll_with_limit_zero_returns_empty(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $task = $this->createTask($this->generateUuid(40 + $i));
            $this->repository->save($task);
        }

        $tasks = $this->repository->findAll(0);
        $this->assertSame(0, $tasks->count());
    }

    public function test_findAll_with_order_oldest(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $task = $this->createTask($this->generateUuid(50 + $i));
            $this->repository->save($task);
        }

        $tasks = $this->repository->findAll(null, TaskOrder::OLDEST);
        $this->assertSame(3, $tasks->count());
    }

    public function test_findAll_with_order_newest(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $task = $this->createTask($this->generateUuid(60 + $i));
            $this->repository->save($task);
        }

        $tasks = $this->repository->findAll(null, TaskOrder::NEWEST);
        $this->assertSame(3, $tasks->count());
    }

    public function test_findAll_returns_empty_when_no_tasks(): void
    {
        $tasks = $this->repository->findAll();
        $this->assertSame(0, $tasks->count());
    }

    // ==================== Delete Tests ====================

    public function test_delete_removes_task(): void
    {
        $id = $this->generateUuid(70);
        $task = $this->createTask($id);
        $this->repository->save($task);

        $this->repository->delete(new TaskIdVO($id));
        $found = $this->repository->find(new TaskIdVO($id));
        $this->assertNull($found);
    }

    public function test_delete_nonexistent_task_does_nothing(): void
    {
        $this->repository->delete(new TaskIdVO($this->generateUuid(999)));
        $this->assertTrue(true);
    }

    // ==================== MoveToCompleted Tests ====================

    public function test_moveToCompleted_moves_task(): void
    {
        $id = $this->generateUuid(80);
        $task = $this->createTask($id);
        $this->repository->save($task);

        $this->repository->moveToCompleted($task, true);

        $found = $this->repository->find(new TaskIdVO($id));
        $this->assertNull($found);
    }
}
