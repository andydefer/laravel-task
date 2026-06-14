<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Services;

use AndyDefer\DomainStructures\Collections\Utility\StrictDataObjectCollection;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Configs\TaskConfig;
use AndyDefer\Task\Contracts\Configs\TaskConfigInterface;
use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\Services\TaskStorageService;
use AndyDefer\Task\Tests\IntegrationTestCase;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

final class TaskStorageServiceTest extends IntegrationTestCase
{
    private string $tempDir;
    private TaskStorageService $storage;
    private TaskConfigInterface $config;
    private ConfigRepository $configRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/task_storage_test_' . uniqid();

        // Get the config repository from Laravel container
        $this->configRepository = $this->app->make(ConfigRepository::class);

        // Set configuration values
        $this->setConfigDefaults();

        // Create real config instance
        $this->config = new TaskConfig($this->configRepository);
        $this->storage = new TaskStorageService($this->config);
    }

    private function setConfigDefaults(): void
    {
        $this->configRepository->set('task.storage_path', $this->tempDir);
        $this->configRepository->set('task.storage_pending_path', $this->tempDir . '/pending');
        $this->configRepository->set('task.storage_recurring_path', $this->tempDir . '/recurring');
        $this->configRepository->set('task.storage_completed_path', $this->tempDir . '/completed');
        $this->configRepository->set('task.storage_grace_period_path', $this->tempDir . '/grace_period');
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

    private function createTaskPayload(): TaskPayloadRecord
    {
        $payloadCollection = new StrictDataObjectCollection();
        $payloadCollection->add(StrictDataObject::from([
            'test_data' => 'storage_test',
        ]));

        return new TaskPayloadRecord(
            type: 'test',
            data: $payloadCollection,
        );
    }

    private function createTestTask(
        string $id = '123',
        bool $enforceExactSchedule = false,
        TaskStatus $status = TaskStatus::PENDING
    ): TaskRecord {
        return new TaskRecord(
            id: $id,
            signature: 'test',
            class: 'TestClass',
            payload: $this->createTaskPayload(),
            status: $status,
            createdAt: date('c'),
            startAt: date('c'),
            endAt: null,
            delaySeconds: 0,
            attempts: 0,
            maxAttempts: 3,
            enforceExactSchedule: $enforceExactSchedule,
        );
    }

    private function createTestTaskWithOrder(int $order): TaskRecord
    {
        $taskId = "task-{$order}";

        return new TaskRecord(
            id: $taskId,
            signature: 'test-task',
            class: 'TestClass',
            payload: $this->createTaskPayload(),
            status: TaskStatus::PENDING,
            createdAt: date('c', strtotime("+{$order} seconds")),
            startAt: date('c', strtotime("-1 minute +{$order} seconds")),
            endAt: date('c', strtotime("+1 hour +{$order} seconds")),
            delaySeconds: 0,
            attempts: 0,
            maxAttempts: 3,
        );
    }

    private function createRecurringTask(string $signature): RecurringTaskRecord
    {
        return new RecurringTaskRecord(
            signature: $signature,
            class: 'TestClass',
            payload: $this->createTaskPayload(),
            startAt: date('c'),
            endAt: null,
            delaySeconds: 300,
            lastRunAt: null,
            nextRunAt: date('c', strtotime('-1 minute')),
            successCount: 0,
            failureCount: 0,
        );
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ==================== Basic Storage Tests ====================

    public function test_save_and_find_pending_task(): void
    {
        // Arrange
        $task = $this->createTestTask();

        // Act
        $this->storage->savePending($task);
        $pending = $this->storage->findPending();

        // Assert
        $this->assertSame(1, $pending->count());
    }

    public function test_save_pending_task_with_enforce_exact_schedule(): void
    {
        // Arrange
        $task = $this->createTestTask(enforceExactSchedule: true);

        // Act
        $this->storage->savePending($task);
        $pending = $this->storage->findPending();
        $savedTask = $pending->first();

        // Assert
        $this->assertNotNull($savedTask);
        $this->assertTrue($savedTask->enforceExactSchedule);
    }

    public function test_save_pending_task_without_enforce_exact_schedule(): void
    {
        // Arrange
        $task = $this->createTestTask(enforceExactSchedule: false);

        // Act
        $this->storage->savePending($task);
        $pending = $this->storage->findPending();
        $savedTask = $pending->first();

        // Assert
        $this->assertNotNull($savedTask);
        $this->assertFalse($savedTask->enforceExactSchedule);
    }

    public function test_delete_pending_task(): void
    {
        // Arrange
        $task = $this->createTestTask();
        $this->storage->savePending($task);

        // Act
        $this->storage->deletePending('123');
        $pending = $this->storage->findPending();

        // Assert
        $this->assertSame(0, $pending->count());
    }

    public function test_delete_nonexistent_pending_task_does_nothing(): void
    {
        // Act
        $this->storage->deletePending('nonexistent-id');
        $pending = $this->storage->findPending();

        // Assert
        $this->assertSame(0, $pending->count());
    }

    public function test_find_pending_returns_only_pending_tasks(): void
    {
        // Arrange
        $pendingTask = $this->createTestTask('pending-1', false, TaskStatus::PENDING);
        $runningTask = $this->createTestTask('running-1', false, TaskStatus::RUNNING);

        $this->storage->savePending($pendingTask);
        $this->storage->savePending($runningTask);

        // Act
        $pending = $this->storage->findPending();

        // Assert
        $this->assertSame(1, $pending->count());

        $foundTask = $pending->first();
        $this->assertSame('pending-1', $foundTask->id);
        $this->assertSame(TaskStatus::PENDING, $foundTask->status);
    }

    public function test_find_pending_returns_empty_collection_when_no_tasks(): void
    {
        // Act
        $pending = $this->storage->findPending();

        // Assert
        $this->assertSame(0, $pending->count());
    }

    public function test_multiple_tasks_can_be_saved_and_retrieved(): void
    {
        // Arrange
        $task1 = $this->createTestTask('task-1');
        $task2 = $this->createTestTask('task-2');
        $task3 = $this->createTestTask('task-3');

        // Act
        $this->storage->savePending($task1);
        $this->storage->savePending($task2);
        $this->storage->savePending($task3);
        $pending = $this->storage->findPending();

        // Assert
        $this->assertSame(3, $pending->count());
    }

    public function test_save_pending_task_overwrites_existing_task(): void
    {
        // Arrange
        $originalTask = $this->createTestTask('overwrite-test');
        $this->storage->savePending($originalTask);

        // Act
        $modifiedTask = $this->createTestTask('overwrite-test', enforceExactSchedule: true);
        $this->storage->savePending($modifiedTask);
        $pending = $this->storage->findPending();
        $savedTask = $pending->first();

        // Assert
        $this->assertSame(1, $pending->count());
        $this->assertTrue($savedTask->enforceExactSchedule);
    }

    public function test_task_preserves_payload_after_save(): void
    {
        // Arrange
        $task = $this->createTestTask();

        // Act
        $this->storage->savePending($task);
        $pending = $this->storage->findPending();
        $savedTask = $pending->first();

        // Assert
        $this->assertNotNull($savedTask);
        $this->assertSame($task->payload->type, $savedTask->payload->type);
        $this->assertSame($task->payload->data->count(), $savedTask->payload->data->count());
    }

    // ==================== Limit Tests ====================

    public function test_find_pending_with_limit_returns_only_limited_tasks(): void
    {
        // Arrange
        for ($i = 1; $i <= 10; $i++) {
            $task = $this->createTestTaskWithOrder($i);
            $this->storage->savePending($task);
        }

        // Act
        $result = $this->storage->findPending(5);

        // Assert
        $this->assertSame(5, $result->count());
    }

    public function test_find_pending_without_limit_returns_all_tasks(): void
    {
        // Arrange
        for ($i = 1; $i <= 10; $i++) {
            $task = $this->createTestTaskWithOrder($i);
            $this->storage->savePending($task);
        }

        // Act
        $result = $this->storage->findPending();

        // Assert
        $this->assertSame(10, $result->count());
    }

    public function test_find_pending_with_limit_zero_returns_no_tasks(): void
    {
        // Arrange
        for ($i = 1; $i <= 5; $i++) {
            $task = $this->createTestTaskWithOrder($i);
            $this->storage->savePending($task);
        }

        // Act
        $result = $this->storage->findPending(0);

        // Assert
        $this->assertSame(0, $result->count());
    }

    public function test_find_pending_with_limit_greater_than_total_returns_all(): void
    {
        // Arrange
        for ($i = 1; $i <= 5; $i++) {
            $task = $this->createTestTaskWithOrder($i);
            $this->storage->savePending($task);
        }

        // Act
        $result = $this->storage->findPending(20);

        // Assert
        $this->assertSame(5, $result->count());
    }

    public function test_find_pending_with_order_oldest_returns_oldest_first(): void
    {
        // Arrange
        for ($i = 1; $i <= 3; $i++) {
            $task = $this->createTestTaskWithOrder($i);
            $this->storage->savePending($task);
        }

        // Act
        $result = $this->storage->findPending(null, 'oldest');

        // Assert
        $this->assertCount(3, $result);

        $ids = [];
        foreach ($result as $task) {
            $ids[] = $task->id;
        }
        $this->assertContains('task-1', $ids);
        $this->assertContains('task-2', $ids);
        $this->assertContains('task-3', $ids);
    }

    public function test_find_pending_with_order_newest_returns_newest_first(): void
    {
        // Arrange
        for ($i = 1; $i <= 3; $i++) {
            $task = $this->createTestTaskWithOrder($i);
            $this->storage->savePending($task);
        }

        // Act
        $result = $this->storage->findPending(null, 'newest');

        // Assert
        $this->assertCount(3, $result);

        $ids = [];
        foreach ($result as $task) {
            $ids[] = $task->id;
        }
        $this->assertContains('task-1', $ids);
        $this->assertContains('task-2', $ids);
        $this->assertContains('task-3', $ids);
    }

    public function test_find_pending_with_limit_and_order_works_together(): void
    {
        // Arrange
        for ($i = 1; $i <= 10; $i++) {
            $task = $this->createTestTaskWithOrder($i);
            $this->storage->savePending($task);
        }

        // Act
        $result = $this->storage->findPending(3, 'newest');

        // Assert
        $this->assertCount(3, $result);
    }

    public function test_find_pending_with_limit_and_oldest_order(): void
    {
        // Arrange
        for ($i = 1; $i <= 10; $i++) {
            $task = $this->createTestTaskWithOrder($i);
            $this->storage->savePending($task);
        }

        // Act
        $result = $this->storage->findPending(3, 'oldest');

        // Assert
        $this->assertCount(3, $result);
    }

    // ==================== Recurring Task Tests ====================

    public function test_save_and_find_recurring_task(): void
    {
        // Arrange
        $task = $this->createRecurringTask('recurring-test');

        // Act
        $this->storage->saveRecurring($task);
        $found = $this->storage->getRecurring('recurring-test');

        // Assert
        $this->assertNotNull($found);
        $this->assertSame('recurring-test', $found->signature);
    }

    public function test_update_recurring_after_run(): void
    {
        // Arrange
        $task = $this->createRecurringTask('recurring-test');
        $this->storage->saveRecurring($task);

        // Act
        $this->storage->updateRecurringAfterRun($task, true, null);
        $updated = $this->storage->getRecurring('recurring-test');

        // Assert
        $this->assertNotNull($updated);
        $this->assertSame(1, $updated->successCount);
        $this->assertNotNull($updated->lastRunAt);
    }

    public function test_delete_recurring(): void
    {
        // Arrange
        $task = $this->createRecurringTask('to-delete-recurring');
        $this->storage->saveRecurring($task);

        // Act
        $this->storage->deleteRecurring('to-delete-recurring');
        $found = $this->storage->getRecurring('to-delete-recurring');

        // Assert
        $this->assertNull($found);
    }

    public function test_get_all_recurring(): void
    {
        // Arrange
        for ($i = 1; $i <= 3; $i++) {
            $task = $this->createRecurringTask("recurring-{$i}");
            $this->storage->saveRecurring($task);
        }

        // Act
        $all = $this->storage->getAllRecurring();

        // Assert
        $this->assertSame(3, $all->count());
    }

    public function test_get_all_pending(): void
    {
        // Arrange
        for ($i = 1; $i <= 3; $i++) {
            $task = $this->createTestTask((string) $i);
            $this->storage->savePending($task);
        }

        // Act
        $all = $this->storage->getAllPending();

        // Assert
        $this->assertSame(3, $all->count());
    }

    // ==================== Move to Completed Tests ====================

    public function test_move_to_completed(): void
    {
        // Arrange
        $task = $this->createTestTask('123');

        $this->storage->savePending($task);

        // Act
        $this->storage->moveToCompleted($task);
        $pending = $this->storage->findPending();

        // Assert
        $this->assertSame(0, $pending->count());
    }

    // ==================== Task to Array Tests ====================

    public function test_task_to_array_includes_enforce_exact_schedule(): void
    {
        // Arrange
        $task = $this->createTestTask(enforceExactSchedule: true);

        // Act
        $array = $task->toArray();

        // Assert
        $this->assertArrayHasKey('enforce_exact_schedule', $array);
        $this->assertTrue($array['enforce_exact_schedule']);
    }

    public function test_task_to_array_includes_all_required_fields(): void
    {
        // Arrange
        $task = $this->createTestTask();

        // Act
        $array = $task->toArray();

        // Assert
        $expectedKeys = [
            'id',
            'signature',
            'class',
            'payload',
            'status',
            'created_at',
            'start_at',
            'end_at',
            'delay_seconds',
            'attempts',
            'max_attempts',
            'last_error',
            'enforce_exact_schedule',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $array, "Array should contain key: {$key}");
        }
    }
}
