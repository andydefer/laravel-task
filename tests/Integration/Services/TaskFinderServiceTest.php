<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Services;

use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Contracts\Services\TaskFinderServiceInterface;
use AndyDefer\Task\Enums\TaskOrder;
use AndyDefer\Task\Records\TaskConfigRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Services\TaskRegistryService;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

final class TaskFinderServiceTest extends IntegrationTestCase
{
    private TaskFinderServiceInterface $finder;
    private TaskRegistryService $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finder = $this->app->make(TaskFinderServiceInterface::class);
        $this->registry = $this->app->make(TaskRegistryService::class);
    }

    protected function tearDown(): void
    {
        // Nettoyer les tâches créées pendant les tests
        $pendingTasks = $this->finder->getPendingTasks();
        foreach ($pendingTasks as $task) {
            $this->registry->unregisterTask($task->id);
        }

        $recurringTasks = $this->finder->getRecurringTasks();
        foreach ($recurringTasks as $task) {
            $this->registry->unregisterRecurring($task->signature);
        }

        parent::tearDown();
    }

    private function createTaskPayload(): TaskPayloadRecord
    {
        return new TaskPayloadRecord(
            type: 'test',
            data: new StrictDataObject(['test_data' => 'finder_test']),
        );
    }

    // ==================== findTask() Tests ====================

    public function test_findTask_returns_task_when_exists(): void
    {
        $payload = $this->createTaskPayload();
        $taskId = $this->registry->register(TestTask::class, $payload);

        $found = $this->finder->findTask(new TaskIdVO($taskId));

        $this->assertNotNull($found);
        $this->assertSame($taskId, $found->id->value);
        $this->assertSame(TestTask::class, $found->class);
    }

    public function test_findTask_returns_null_when_not_found(): void
    {
        $taskIdVO = new TaskIdVO('00000000-0000-0000-0000-000000000000');
        $this->assertNull($this->finder->findTask($taskIdVO));
    }

    public function test_findTask_returns_null_for_non_pending_task(): void
    {
        $payload = $this->createTaskPayload();
        $taskId = $this->registry->register(TestTask::class, $payload);

        $task = $this->finder->findTask(new TaskIdVO($taskId));
        $this->assertNotNull($task);

        // Simuler l'exécution en supprimant la tâche (comme moveToCompleted)
        $this->registry->unregisterTask(new TaskIdVO($taskId));

        $found = $this->finder->findTask(new TaskIdVO($taskId));
        $this->assertNull($found);
    }

    // ==================== findRecurringTask() Tests ====================

    public function test_findRecurringTask_returns_task_when_exists(): void
    {
        $payload = $this->createTaskPayload();
        $signature = 'recurring-find-test';

        $config = new TaskConfigRecord(
            signature: new TaskSignatureVO($signature),
            description: 'Recurring find test',
            delay_seconds: new CounterVO(300),
            max_attempts: new CounterVO(3),
            start_at: null,
            end_at: null,
        );

        $this->registry->register(TestTask::class, $payload, $config);

        $found = $this->finder->findRecurringTask(new TaskSignatureVO($signature));

        $this->assertNotNull($found);
        $this->assertSame($signature, $found->signature->value);
        $this->assertSame(TestTask::class, $found->class);
    }

    public function test_findRecurringTask_returns_null_when_not_found(): void
    {
        $signatureVO = new TaskSignatureVO('nonexistent-signature');
        $this->assertNull($this->finder->findRecurringTask($signatureVO));
    }

    // ==================== getPendingTasks() Tests ====================

    public function test_getPendingTasks_returns_collection(): void
    {
        $payload = $this->createTaskPayload();

        $this->registry->register(TestTask::class, $payload);
        $this->registry->register(TestTask::class, $payload);

        $pendingTasks = $this->finder->getPendingTasks();

        $this->assertInstanceOf(\AndyDefer\Task\Collections\TaskRecordCollection::class, $pendingTasks);
        $this->assertGreaterThanOrEqual(2, $pendingTasks->count());
    }

    public function test_getPendingTasks_with_limit(): void
    {
        $payload = $this->createTaskPayload();

        for ($i = 0; $i < 5; $i++) {
            $this->registry->register(TestTask::class, $payload);
        }

        $pendingTasks = $this->finder->getPendingTasks(3);

        $this->assertSame(3, $pendingTasks->count());
    }

    public function test_getPendingTasks_with_limit_zero_returns_empty(): void
    {
        $payload = $this->createTaskPayload();

        for ($i = 0; $i < 3; $i++) {
            $this->registry->register(TestTask::class, $payload);
        }

        $pendingTasks = $this->finder->getPendingTasks(0);

        $this->assertSame(0, $pendingTasks->count());
    }

    public function test_getPendingTasks_with_order_oldest(): void
    {
        $payload = $this->createTaskPayload();

        $this->registry->register(TestTask::class, $payload);
        $this->registry->register(TestTask::class, $payload);

        $pendingTasks = $this->finder->getPendingTasks(null, TaskOrder::OLDEST);

        $this->assertGreaterThanOrEqual(2, $pendingTasks->count());
    }

    public function test_getPendingTasks_with_order_newest(): void
    {
        $payload = $this->createTaskPayload();

        $this->registry->register(TestTask::class, $payload);
        $this->registry->register(TestTask::class, $payload);

        $pendingTasks = $this->finder->getPendingTasks(null, TaskOrder::NEWEST);

        $this->assertGreaterThanOrEqual(2, $pendingTasks->count());
    }

    public function test_getPendingTasks_returns_only_pending_tasks(): void
    {
        $payload = $this->createTaskPayload();

        $taskId = $this->registry->register(TestTask::class, $payload);

        // Supprimer la tâche (simule une exécution)
        $this->registry->unregisterTask(new TaskIdVO($taskId));

        $pendingTasks = $this->finder->getPendingTasks();

        $found = false;
        foreach ($pendingTasks as $task) {
            if ($task->id->value === $taskId) {
                $found = true;
                break;
            }
        }
        $this->assertFalse($found);
    }

    public function test_getPendingTasks_returns_empty_when_no_tasks(): void
    {
        $pendingTasks = $this->finder->getPendingTasks();
        $this->assertSame(0, $pendingTasks->count());
    }

    // ==================== getRecurringTasks() Tests ====================

    public function test_getRecurringTasks_returns_collection(): void
    {
        $payload = $this->createTaskPayload();

        $config1 = new TaskConfigRecord(
            signature: new TaskSignatureVO('recurring-1'),
            description: 'Recurring 1',
            delay_seconds: new CounterVO(300),
            max_attempts: new CounterVO(3),
            start_at: null,
            end_at: null,
        );

        $config2 = new TaskConfigRecord(
            signature: new TaskSignatureVO('recurring-2'),
            description: 'Recurring 2',
            delay_seconds: new CounterVO(600),
            max_attempts: new CounterVO(3),
            start_at: null,
            end_at: null,
        );

        $this->registry->register(TestTask::class, $payload, $config1);
        $this->registry->register(TestTask::class, $payload, $config2);

        $recurringTasks = $this->finder->getRecurringTasks();

        $this->assertInstanceOf(\AndyDefer\Task\Collections\RecurringTaskRecordCollection::class, $recurringTasks);
        $this->assertSame(2, $recurringTasks->count());
    }

    public function test_getRecurringTasks_with_limit(): void
    {
        $payload = $this->createTaskPayload();

        for ($i = 1; $i <= 5; $i++) {
            $config = new TaskConfigRecord(
                signature: new TaskSignatureVO("recurring-limit-{$i}"),
                description: "Recurring limit {$i}",
                delay_seconds: new CounterVO(300),
                max_attempts: new CounterVO(3),
                start_at: null,
                end_at: null,
            );
            $this->registry->register(TestTask::class, $payload, $config);
        }

        $recurringTasks = $this->finder->getRecurringTasks(3);

        $this->assertSame(3, $recurringTasks->count());
    }

    public function test_getRecurringTasks_with_limit_zero_returns_empty(): void
    {
        $payload = $this->createTaskPayload();

        for ($i = 1; $i <= 3; $i++) {
            $config = new TaskConfigRecord(
                signature: new TaskSignatureVO("recurring-zero-{$i}"),
                description: "Recurring zero {$i}",
                delay_seconds: new CounterVO(300),
                max_attempts: new CounterVO(3),
                start_at: null,
                end_at: null,
            );
            $this->registry->register(TestTask::class, $payload, $config);
        }

        $recurringTasks = $this->finder->getRecurringTasks(0);

        $this->assertSame(0, $recurringTasks->count());
    }

    public function test_getRecurringTasks_with_order_oldest(): void
    {
        $payload = $this->createTaskPayload();

        $config1 = new TaskConfigRecord(
            signature: new TaskSignatureVO('recurring-oldest-1'),
            description: 'Recurring oldest 1',
            delay_seconds: new CounterVO(300),
            max_attempts: new CounterVO(3),
            start_at: null,
            end_at: null,
        );

        $config2 = new TaskConfigRecord(
            signature: new TaskSignatureVO('recurring-oldest-2'),
            description: 'Recurring oldest 2',
            delay_seconds: new CounterVO(600),
            max_attempts: new CounterVO(3),
            start_at: null,
            end_at: null,
        );

        $this->registry->register(TestTask::class, $payload, $config1);
        $this->registry->register(TestTask::class, $payload, $config2);

        $recurringTasks = $this->finder->getRecurringTasks(null, TaskOrder::OLDEST);

        $this->assertSame(2, $recurringTasks->count());
    }

    public function test_getRecurringTasks_with_order_newest(): void
    {
        $payload = $this->createTaskPayload();

        $config1 = new TaskConfigRecord(
            signature: new TaskSignatureVO('recurring-newest-1'),
            description: 'Recurring newest 1',
            delay_seconds: new CounterVO(300),
            max_attempts: new CounterVO(3),
            start_at: null,
            end_at: null,
        );

        $config2 = new TaskConfigRecord(
            signature: new TaskSignatureVO('recurring-newest-2'),
            description: 'Recurring newest 2',
            delay_seconds: new CounterVO(600),
            max_attempts: new CounterVO(3),
            start_at: null,
            end_at: null,
        );

        $this->registry->register(TestTask::class, $payload, $config1);
        $this->registry->register(TestTask::class, $payload, $config2);

        $recurringTasks = $this->finder->getRecurringTasks(null, TaskOrder::NEWEST);

        $this->assertSame(2, $recurringTasks->count());
    }

    public function test_getRecurringTasks_returns_empty_when_no_tasks(): void
    {
        $recurringTasks = $this->finder->getRecurringTasks();
        $this->assertSame(0, $recurringTasks->count());
    }

    // ==================== taskExists() Tests ====================

    public function test_taskExists_returns_true_for_existing_task(): void
    {
        $payload = $this->createTaskPayload();
        $taskId = $this->registry->register(TestTask::class, $payload);

        $this->assertTrue($this->finder->taskExists(new TaskIdVO($taskId)));
    }

    public function test_taskExists_returns_false_for_nonexistent_task(): void
    {
        $this->assertFalse($this->finder->taskExists(new TaskIdVO('00000000-0000-0000-0000-000000000000')));
    }

    public function test_taskExists_returns_false_for_deleted_task(): void
    {
        $payload = $this->createTaskPayload();
        $taskId = $this->registry->register(TestTask::class, $payload);

        $taskIdVO = new TaskIdVO($taskId);
        $this->assertTrue($this->finder->taskExists($taskIdVO));

        $this->registry->unregisterTask($taskIdVO);

        $this->assertFalse($this->finder->taskExists($taskIdVO));
    }

    // ==================== recurringTaskExists() Tests ====================

    public function test_recurringTaskExists_returns_true_for_existing_task(): void
    {
        $payload = $this->createTaskPayload();
        $signature = 'recurring-exists-test';

        $config = new TaskConfigRecord(
            signature: new TaskSignatureVO($signature),
            description: 'Recurring exists test',
            delay_seconds: new CounterVO(300),
            max_attempts: new CounterVO(3),
            start_at: null,
            end_at: null,
        );

        $this->registry->register(TestTask::class, $payload, $config);

        $this->assertTrue($this->finder->recurringTaskExists(new TaskSignatureVO($signature)));
    }

    public function test_recurringTaskExists_returns_false_for_nonexistent_task(): void
    {
        $this->assertFalse($this->finder->recurringTaskExists(new TaskSignatureVO('nonexistent')));
    }

    public function test_recurringTaskExists_returns_false_for_deleted_task(): void
    {
        $payload = $this->createTaskPayload();
        $signature = new TaskSignatureVO('recurring-delete-test');

        $config = new TaskConfigRecord(
            signature: $signature,
            description: 'Recurring delete test',
            delay_seconds: new CounterVO(300),
            max_attempts: new CounterVO(3),
            start_at: null,
            end_at: null,
        );

        $this->registry->register(TestTask::class, $payload, $config);
        $this->assertTrue($this->finder->recurringTaskExists($signature));

        $this->registry->unregisterRecurring($signature);

        $this->assertFalse($this->finder->recurringTaskExists($signature));
    }

    // ==================== countPendingTasks() Tests ====================

    public function test_countPendingTasks_returns_zero_when_no_tasks(): void
    {
        $count = $this->finder->countPendingTasks();
        $this->assertIsInt($count);
    }

    public function test_countPendingTasks_increases_after_register(): void
    {
        $initialCount = $this->finder->countPendingTasks();
        $payload = $this->createTaskPayload();

        $this->registry->register(TestTask::class, $payload);
        $this->registry->register(TestTask::class, $payload);

        $this->assertSame($initialCount + 2, $this->finder->countPendingTasks());
    }

    public function test_countPendingTasks_decreases_after_unregister(): void
    {
        $payload = $this->createTaskPayload();
        $taskId = $this->registry->register(TestTask::class, $payload);

        $initialCount = $this->finder->countPendingTasks();

        $this->registry->unregisterTask(new TaskIdVO($taskId));

        $this->assertLessThan($initialCount, $this->finder->countPendingTasks());
    }

    // ==================== countRecurringTasks() Tests ====================

    public function test_countRecurringTasks_returns_zero_when_no_tasks(): void
    {
        $count = $this->finder->countRecurringTasks();
        $this->assertIsInt($count);
    }

    public function test_countRecurringTasks_increases_after_register(): void
    {
        $initialCount = $this->finder->countRecurringTasks();
        $payload = $this->createTaskPayload();

        $config = new TaskConfigRecord(
            signature: new TaskSignatureVO('recurring-count-test'),
            description: 'Recurring count test',
            delay_seconds: new CounterVO(300),
            max_attempts: new CounterVO(3),
            start_at: null,
            end_at: null,
        );

        $this->registry->register(TestTask::class, $payload, $config);

        $this->assertSame($initialCount + 1, $this->finder->countRecurringTasks());
    }

    public function test_countRecurringTasks_decreases_after_unregister(): void
    {
        $payload = $this->createTaskPayload();
        $signature = new TaskSignatureVO('recurring-count-delete');

        $config = new TaskConfigRecord(
            signature: $signature,
            description: 'Recurring count delete',
            delay_seconds: new CounterVO(300),
            max_attempts: new CounterVO(3),
            start_at: null,
            end_at: null,
        );

        $this->registry->register(TestTask::class, $payload, $config);

        $initialCount = $this->finder->countRecurringTasks();

        $this->registry->unregisterRecurring($signature);

        $this->assertLessThan($initialCount, $this->finder->countRecurringTasks());
    }
}
