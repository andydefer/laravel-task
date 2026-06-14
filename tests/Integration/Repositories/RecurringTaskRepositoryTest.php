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
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Repositories\RecurringTaskRepository;
use AndyDefer\Task\Strategies\TaskPathStrategy;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

final class RecurringTaskRepositoryTest extends IntegrationTestCase
{
    private RecurringTaskRepository $repository;
    private string $tempDir;
    private ConfigRepository $configRepository;
    private HydrationService $hydration;
    private FileSystemInterface $fs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/recurring_repo_test_' . uniqid();
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

        $this->repository = new RecurringTaskRepository(
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
            'test_data' => 'recurring_repo_test',
        ]));

        return new TaskPayloadRecord(
            type: 'test',
            data: $payloadCollection,
        );
    }

    private function createRecurringTask(string $signature): RecurringTaskRecord
    {
        return new RecurringTaskRecord(
            signature: new TaskSignatureVO($signature),
            class: 'TestClass',
            payload: $this->createTaskPayload(),
            start_at: new Iso8601DateTimeVO(),
            end_at: null,
            delay_seconds: new CounterVO(300),
            last_run_at: null,
            next_run_at: new Iso8601DateTimeVO(),
            success_count: new CounterVO(0),
            failure_count: new CounterVO(0),
        );
    }

    // ==================== Save Tests ====================

    public function test_save_creates_recurring_task_file(): void
    {
        $task = $this->createRecurringTask('test-recurring-1');
        $this->repository->save($task);

        $found = $this->repository->find(new TaskSignatureVO('test-recurring-1'));
        $this->assertNotNull($found);
        $this->assertSame('test-recurring-1', $found->signature->value);
    }
    public function test_save_updates_existing_recurring_task(): void
    {
        $task = $this->createRecurringTask('test-recurring-2');
        $this->repository->save($task);

        // Créer une nouvelle tâche avec la même signature mais des compteurs modifiés
        $task2 = new RecurringTaskRecord(
            signature: new TaskSignatureVO('test-recurring-2'),
            class: 'TestClass',
            payload: $this->createTaskPayload(),
            start_at: new Iso8601DateTimeVO(),
            end_at: null,
            delay_seconds: new CounterVO(300),
            last_run_at: null,
            next_run_at: new Iso8601DateTimeVO(),
            success_count: new CounterVO(5),  // ← modifié dans le constructeur
            failure_count: new CounterVO(0),
        );
        $this->repository->save($task2);

        $found = $this->repository->find(new TaskSignatureVO('test-recurring-2'));
        $this->assertNotNull($found);
        $this->assertSame(5, $found->success_count->value);
    }

    // ==================== Find Tests ====================

    public function test_find_returns_recurring_task_when_exists(): void
    {
        $task = $this->createRecurringTask('test-recurring-3');
        $this->repository->save($task);

        $found = $this->repository->find(new TaskSignatureVO('test-recurring-3'));
        $this->assertNotNull($found);
        $this->assertSame('test-recurring-3', $found->signature->value);
    }

    public function test_find_returns_null_when_not_exists(): void
    {
        $found = $this->repository->find(new TaskSignatureVO('nonexistent'));
        $this->assertNull($found);
    }

    public function test_find_returns_last_version_of_recurring_task(): void
    {
        $signature = new TaskSignatureVO('test-recurring-4');

        // Créer et sauvegarder la tâche initiale
        $task = $this->createRecurringTask('test-recurring-4');
        $this->repository->save($task);

        // Premier update - succès
        $this->repository->updateAfterRun($task, true, null);

        // Récupérer la version mise à jour depuis le repository
        $updatedTask = $this->repository->find($signature);
        $this->assertNotNull($updatedTask);
        $this->assertSame(1, $updatedTask->success_count->value);

        // Second update - échec (en utilisant la version récupérée)
        $this->repository->updateAfterRun($updatedTask, false, 'Error');

        // Vérifier le résultat final
        $found = $this->repository->find($signature);
        $this->assertNotNull($found);
        $this->assertSame(1, $found->success_count->value);
        $this->assertSame(1, $found->failure_count->value);
    }

    // ==================== FindAll Tests ====================

    public function test_findAll_returns_all_recurring_tasks(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $task = $this->createRecurringTask("recurring-{$i}");
            $this->repository->save($task);
        }

        $tasks = $this->repository->findAll();
        $this->assertSame(3, $tasks->count());
    }

    public function test_findAll_with_limit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $task = $this->createRecurringTask("recurring-limit-{$i}");
            $this->repository->save($task);
        }

        $tasks = $this->repository->findAll(3);
        $this->assertSame(3, $tasks->count());
    }

    public function test_findAll_with_limit_zero_returns_empty(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $task = $this->createRecurringTask("recurring-zero-{$i}");
            $this->repository->save($task);
        }

        $tasks = $this->repository->findAll(0);
        $this->assertSame(0, $tasks->count());
    }

    public function test_findAll_with_order_oldest(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $task = $this->createRecurringTask("recurring-oldest-{$i}");
            $this->repository->save($task);
        }

        $tasks = $this->repository->findAll(null, TaskOrder::OLDEST);
        $this->assertSame(3, $tasks->count());
    }

    public function test_findAll_with_order_newest(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $task = $this->createRecurringTask("recurring-newest-{$i}");
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

    public function test_delete_removes_recurring_task(): void
    {
        $task = $this->createRecurringTask('to-delete');
        $this->repository->save($task);

        $this->repository->delete(new TaskSignatureVO('to-delete'));
        $found = $this->repository->find(new TaskSignatureVO('to-delete'));
        $this->assertNull($found);
    }

    public function test_delete_nonexistent_task_does_nothing(): void
    {
        $this->repository->delete(new TaskSignatureVO('nonexistent'));
        $this->assertTrue(true);
    }

    // ==================== UpdateAfterRun Tests ====================

    public function test_updateAfterRun_increments_success_count(): void
    {
        $task = $this->createRecurringTask('update-success');
        $this->repository->save($task);

        $this->repository->updateAfterRun($task, true, null);

        $found = $this->repository->find(new TaskSignatureVO('update-success'));
        $this->assertNotNull($found);
        $this->assertSame(1, $found->success_count->value);
        $this->assertSame(0, $found->failure_count->value);
        $this->assertNotNull($found->last_run_at);
    }

    public function test_updateAfterRun_increments_failure_count(): void
    {
        $task = $this->createRecurringTask('update-failure');
        $this->repository->save($task);

        $this->repository->updateAfterRun($task, false, 'Something went wrong');

        $found = $this->repository->find(new TaskSignatureVO('update-failure'));
        $this->assertNotNull($found);
        $this->assertSame(0, $found->success_count->value);
        $this->assertSame(1, $found->failure_count->value);
        $this->assertNotNull($found->last_error);
    }

    public function test_updateAfterRun_updates_next_run_at(): void
    {
        $task = $this->createRecurringTask('update-next-run');
        $this->repository->save($task);

        $oldNextRunAt = $task->next_run_at->value;

        $this->repository->updateAfterRun($task, true, null);

        $found = $this->repository->find(new TaskSignatureVO('update-next-run'));
        $this->assertNotNull($found);
        $this->assertNotSame($oldNextRunAt, $found->next_run_at->value);
    }

    public function test_updateAfterRun_updates_last_run_at(): void
    {
        $task = $this->createRecurringTask('update-last-run');
        $this->repository->save($task);

        $this->repository->updateAfterRun($task, true, null);

        $found = $this->repository->find(new TaskSignatureVO('update-last-run'));
        $this->assertNotNull($found);
        $this->assertNotNull($found->last_run_at);
    }
}
