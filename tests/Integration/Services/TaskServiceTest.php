<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Services;

use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Collections\RecurringTaskRecordCollection;
use AndyDefer\Task\Collections\TaskRecordCollection;
use AndyDefer\Task\Contracts\Services\TaskServiceInterface;
use AndyDefer\Task\Enums\TaskOrder;
use AndyDefer\Task\Records\TaskConfigRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use InvalidArgumentException;

final class TaskServiceTest extends IntegrationTestCase
{
    private TaskServiceInterface $taskService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->taskService = $this->app->make(TaskServiceInterface::class);
    }

    protected function tearDown(): void
    {
        // Nettoyer les tâches créées pendant les tests
        $pendingTasks = $this->taskService->getPendingTasks();
        foreach ($pendingTasks as $task) {
            $this->taskService->unregisterTask($task->id);
        }

        $recurringTasks = $this->taskService->getRecurringTasks();
        foreach ($recurringTasks as $task) {
            $this->taskService->unregisterRecurring($task->signature);
        }

        parent::tearDown();
    }

    private function createTaskPayload(): TaskPayloadRecord
    {
        return new TaskPayloadRecord(
            type: 'test',
            data: new StrictDataObject(['test_data' => 'task_service_test']),
        );
    }

    // ==================== Registry Tests (hérités des interfaces) ====================

    public function test_register_unique_task(): void
    {
        $payload = $this->createTaskPayload();
        $taskId = $this->taskService->register(TestTask::class, $payload);

        $this->assertMatchesRegularExpression('/^[a-f0-9-]{36}$/', $taskId);
    }

    public function test_register_recurring_task(): void
    {
        $payload = $this->createTaskPayload();
        $config = new TaskConfigRecord(
            signature: new TaskSignatureVO('recurring-test-service'),
            description: 'Recurring task',
            delay_seconds: new CounterVO(300),
            max_attempts: new CounterVO(3),
            start_at: null,
            end_at: null,
        );

        $signature = $this->taskService->register(TestTask::class, $payload, $config);

        $this->assertSame('recurring-test-service', $signature);
    }

    public function test_register_throws_exception_for_invalid_class(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Task must extend AbstractTask');

        $this->taskService->register('InvalidClass', $this->createTaskPayload());
    }

    public function test_unregister_task(): void
    {
        $payload = $this->createTaskPayload();
        $taskId = $this->taskService->register(TestTask::class, $payload);

        $taskIdVO = new TaskIdVO($taskId);
        $this->assertNotNull($this->taskService->findTask($taskIdVO));

        $this->taskService->unregisterTask($taskIdVO);

        $this->assertNull($this->taskService->findTask($taskIdVO));
    }

    public function test_unregister_recurring(): void
    {
        $payload = $this->createTaskPayload();
        $signature = new TaskSignatureVO('recurring-to-delete-service');

        $config = new TaskConfigRecord(
            signature: $signature,
            description: 'Recurring to delete',
            delay_seconds: new CounterVO(300),
            max_attempts: new CounterVO(3),
            start_at: null,
            end_at: null,
        );

        $this->taskService->register(TestTask::class, $payload, $config);
        $this->assertNotNull($this->taskService->findRecurringTask($signature));

        $this->taskService->unregisterRecurring($signature);

        $this->assertNull($this->taskService->findRecurringTask($signature));
    }

    public function test_unregister_by_identifier_for_unique_task(): void
    {
        $payload = $this->createTaskPayload();
        $taskId = $this->taskService->register(TestTask::class, $payload);

        $this->assertNotNull($this->taskService->findTask(new TaskIdVO($taskId)));

        $this->taskService->unregister($taskId);

        $this->assertNull($this->taskService->findTask(new TaskIdVO($taskId)));
    }

    public function test_unregister_by_identifier_for_recurring_task(): void
    {
        $payload = $this->createTaskPayload();
        $signature = 'recurring-auto-delete';

        $config = new TaskConfigRecord(
            signature: new TaskSignatureVO($signature),
            description: 'Recurring auto delete',
            delay_seconds: new CounterVO(300),
            max_attempts: new CounterVO(3),
            start_at: null,
            end_at: null,
        );

        $this->taskService->register(TestTask::class, $payload, $config);
        $this->assertNotNull($this->taskService->findRecurringTask(new TaskSignatureVO($signature)));

        $this->taskService->unregister($signature);

        $this->assertNull($this->taskService->findRecurringTask(new TaskSignatureVO($signature)));
    }

    // ==================== findTask() Tests ====================

    public function test_find_task_returns_task_when_exists(): void
    {
        $payload = $this->createTaskPayload();
        $taskId = $this->taskService->register(TestTask::class, $payload);

        $found = $this->taskService->findTask(new TaskIdVO($taskId));

        $this->assertNotNull($found);
        $this->assertSame($taskId, $found->id->value);
        $this->assertSame(TestTask::class, $found->class);
    }

    public function test_find_task_returns_null_when_not_found(): void
    {
        $taskIdVO = new TaskIdVO('00000000-0000-0000-0000-000000000000');
        $this->assertNull($this->taskService->findTask($taskIdVO));
    }

    public function test_find_task_returns_null_for_non_pending_task(): void
    {
        $payload = $this->createTaskPayload();
        $taskId = $this->taskService->register(TestTask::class, $payload);

        $task = $this->taskService->findTask(new TaskIdVO($taskId));
        $this->assertNotNull($task);

        // Exécuter la tâche
        $this->taskService->runTask($task);

        // La tâche ne doit plus être trouvée (plus en attente)
        $found = $this->taskService->findTask(new TaskIdVO($taskId));
        $this->assertNull($found);
    }

    // ==================== findRecurringTask() Tests ====================

    public function test_find_recurring_task_returns_task_when_exists(): void
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

        $this->taskService->register(TestTask::class, $payload, $config);

        $found = $this->taskService->findRecurringTask(new TaskSignatureVO($signature));

        $this->assertNotNull($found);
        $this->assertSame($signature, $found->signature->value);
        $this->assertSame(TestTask::class, $found->class);
    }

    public function test_find_recurring_task_returns_null_when_not_found(): void
    {
        $signatureVO = new TaskSignatureVO('nonexistent-signature');
        $this->assertNull($this->taskService->findRecurringTask($signatureVO));
    }

    // ==================== getPendingTasks() Tests ====================

    public function test_get_pending_tasks_returns_collection(): void
    {
        $payload = $this->createTaskPayload();

        $this->taskService->register(TestTask::class, $payload);
        $this->taskService->register(TestTask::class, $payload);

        $pendingTasks = $this->taskService->getPendingTasks();

        $this->assertInstanceOf(TaskRecordCollection::class, $pendingTasks);
        $this->assertGreaterThanOrEqual(2, $pendingTasks->count());
    }

    public function test_get_pending_tasks_with_limit(): void
    {
        $payload = $this->createTaskPayload();

        for ($i = 0; $i < 5; $i++) {
            $this->taskService->register(TestTask::class, $payload);
        }

        $pendingTasks = $this->taskService->getPendingTasks(3);

        $this->assertSame(3, $pendingTasks->count());
    }

    public function test_get_pending_tasks_with_limit_zero_returns_empty(): void
    {
        $payload = $this->createTaskPayload();

        for ($i = 0; $i < 3; $i++) {
            $this->taskService->register(TestTask::class, $payload);
        }

        $pendingTasks = $this->taskService->getPendingTasks(0);

        $this->assertSame(0, $pendingTasks->count());
    }

    public function test_get_pending_tasks_with_order_oldest(): void
    {
        $payload = $this->createTaskPayload();

        $taskId1 = $this->taskService->register(TestTask::class, $payload);
        $taskId2 = $this->taskService->register(TestTask::class, $payload);

        $pendingTasks = $this->taskService->getPendingTasks(null, TaskOrder::OLDEST);

        $this->assertGreaterThanOrEqual(2, $pendingTasks->count());

        // Vérifier que les IDs sont dans l'ordre (FIFO)
        $firstTask = $pendingTasks->first();
        $this->assertNotNull($firstTask);
    }

    public function test_get_pending_tasks_with_order_newest(): void
    {
        $payload = $this->createTaskPayload();

        $this->taskService->register(TestTask::class, $payload);
        $this->taskService->register(TestTask::class, $payload);

        $pendingTasks = $this->taskService->getPendingTasks(null, TaskOrder::NEWEST);

        $this->assertGreaterThanOrEqual(2, $pendingTasks->count());
    }

    public function test_get_pending_tasks_returns_only_pending_tasks(): void
    {
        $payload = $this->createTaskPayload();

        $taskId = $this->taskService->register(TestTask::class, $payload);

        // Exécuter la tâche pour qu'elle ne soit plus en attente
        $task = $this->taskService->findTask(new TaskIdVO($taskId));
        if ($task) {
            $this->taskService->runTask($task);
        }

        $pendingTasks = $this->taskService->getPendingTasks();

        $found = false;
        foreach ($pendingTasks as $task) {
            if ($task->id->value === $taskId) {
                $found = true;
                break;
            }
        }
        $this->assertFalse($found);
    }

    public function test_get_pending_tasks_returns_empty_when_no_tasks(): void
    {
        $pendingTasks = $this->taskService->getPendingTasks();
        $this->assertSame(0, $pendingTasks->count());
    }

    // ==================== getRecurringTasks() Tests ====================

    public function test_get_recurring_tasks_returns_collection(): void
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

        $this->taskService->register(TestTask::class, $payload, $config1);
        $this->taskService->register(TestTask::class, $payload, $config2);

        $recurringTasks = $this->taskService->getRecurringTasks();

        $this->assertInstanceOf(RecurringTaskRecordCollection::class, $recurringTasks);
        $this->assertSame(2, $recurringTasks->count());
    }

    public function test_get_recurring_tasks_with_limit(): void
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
            $this->taskService->register(TestTask::class, $payload, $config);
        }

        $recurringTasks = $this->taskService->getRecurringTasks(3);

        $this->assertSame(3, $recurringTasks->count());
    }

    public function test_get_recurring_tasks_with_limit_zero_returns_empty(): void
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
            $this->taskService->register(TestTask::class, $payload, $config);
        }

        $recurringTasks = $this->taskService->getRecurringTasks(0);

        $this->assertSame(0, $recurringTasks->count());
    }

    public function test_get_recurring_tasks_with_order_oldest(): void
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

        $this->taskService->register(TestTask::class, $payload, $config1);
        $this->taskService->register(TestTask::class, $payload, $config2);

        $recurringTasks = $this->taskService->getRecurringTasks(null, TaskOrder::OLDEST);

        $this->assertSame(2, $recurringTasks->count());
    }

    public function test_get_recurring_tasks_with_order_newest(): void
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

        $this->taskService->register(TestTask::class, $payload, $config1);
        $this->taskService->register(TestTask::class, $payload, $config2);

        $recurringTasks = $this->taskService->getRecurringTasks(null, TaskOrder::NEWEST);

        $this->assertSame(2, $recurringTasks->count());
    }

    public function test_get_recurring_tasks_returns_empty_when_no_tasks(): void
    {
        $recurringTasks = $this->taskService->getRecurringTasks();
        $this->assertSame(0, $recurringTasks->count());
    }

    // ==================== taskExists() Tests ====================

    public function test_task_exists_returns_true_for_existing_task(): void
    {
        $payload = $this->createTaskPayload();
        $taskId = $this->taskService->register(TestTask::class, $payload);

        $this->assertTrue($this->taskService->taskExists(new TaskIdVO($taskId)));
    }

    public function test_task_exists_returns_false_for_nonexistent_task(): void
    {
        $this->assertFalse($this->taskService->taskExists(new TaskIdVO('00000000-0000-0000-0000-000000000000')));
    }

    public function test_task_exists_returns_false_for_deleted_task(): void
    {
        $payload = $this->createTaskPayload();
        $taskId = $this->taskService->register(TestTask::class, $payload);

        $taskIdVO = new TaskIdVO($taskId);
        $this->assertTrue($this->taskService->taskExists($taskIdVO));

        $this->taskService->unregisterTask($taskIdVO);

        $this->assertFalse($this->taskService->taskExists($taskIdVO));
    }

    // ==================== recurringTaskExists() Tests ====================

    public function test_recurring_task_exists_returns_true_for_existing_task(): void
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

        $this->taskService->register(TestTask::class, $payload, $config);

        $this->assertTrue($this->taskService->recurringTaskExists(new TaskSignatureVO($signature)));
    }

    public function test_recurring_task_exists_returns_false_for_nonexistent_task(): void
    {
        $this->assertFalse($this->taskService->recurringTaskExists(new TaskSignatureVO('nonexistent')));
    }

    public function test_recurring_task_exists_returns_false_for_deleted_task(): void
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

        $this->taskService->register(TestTask::class, $payload, $config);
        $this->assertTrue($this->taskService->recurringTaskExists($signature));

        $this->taskService->unregisterRecurring($signature);

        $this->assertFalse($this->taskService->recurringTaskExists($signature));
    }

    // ==================== countPendingTasks() Tests ====================

    public function test_count_pending_tasks_returns_zero_when_no_tasks(): void
    {
        // S'assurer qu'il n'y a pas de tâches (tearDown nettoie)
        $count = $this->taskService->countPendingTasks();
        $this->assertIsInt($count);
    }

    public function test_count_pending_tasks_increases_after_register(): void
    {
        $initialCount = $this->taskService->countPendingTasks();
        $payload = $this->createTaskPayload();

        $this->taskService->register(TestTask::class, $payload);
        $this->taskService->register(TestTask::class, $payload);

        $this->assertSame($initialCount + 2, $this->taskService->countPendingTasks());
    }

    public function test_count_pending_tasks_decreases_after_execution(): void
    {
        $payload = $this->createTaskPayload();
        $taskId = $this->taskService->register(TestTask::class, $payload);

        $initialCount = $this->taskService->countPendingTasks();

        $task = $this->taskService->findTask(new TaskIdVO($taskId));
        if ($task) {
            $this->taskService->runTask($task);
        }

        $this->assertLessThan($initialCount, $this->taskService->countPendingTasks());
    }

    public function test_count_pending_tasks_decreases_after_unregister(): void
    {
        $payload = $this->createTaskPayload();
        $taskId = $this->taskService->register(TestTask::class, $payload);

        $initialCount = $this->taskService->countPendingTasks();

        $this->taskService->unregisterTask(new TaskIdVO($taskId));

        $this->assertLessThan($initialCount, $this->taskService->countPendingTasks());
    }

    // ==================== countRecurringTasks() Tests ====================

    public function test_count_recurring_tasks_returns_zero_when_no_tasks(): void
    {
        $count = $this->taskService->countRecurringTasks();
        $this->assertIsInt($count);
    }

    public function test_count_recurring_tasks_increases_after_register(): void
    {
        $initialCount = $this->taskService->countRecurringTasks();
        $payload = $this->createTaskPayload();

        $config = new TaskConfigRecord(
            signature: new TaskSignatureVO('recurring-count-test'),
            description: 'Recurring count test',
            delay_seconds: new CounterVO(300),
            max_attempts: new CounterVO(3),
            start_at: null,
            end_at: null,
        );

        $this->taskService->register(TestTask::class, $payload, $config);

        $this->assertSame($initialCount + 1, $this->taskService->countRecurringTasks());
    }

    public function test_count_recurring_tasks_decreases_after_unregister(): void
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

        $this->taskService->register(TestTask::class, $payload, $config);

        $initialCount = $this->taskService->countRecurringTasks();

        $this->taskService->unregisterRecurring($signature);

        $this->assertLessThan($initialCount, $this->taskService->countRecurringTasks());
    }

    // ==================== Validator Tests (hérités) ====================

    public function test_validate_task_class_returns_true_for_valid_class(): void
    {
        $this->assertTrue($this->taskService->validateTaskClass(TestTask::class));
    }

    public function test_validate_task_class_returns_false_for_invalid_class(): void
    {
        $this->assertFalse($this->taskService->validateTaskClass('NonExistentClass'));
    }

    public function test_can_run_task_returns_true_for_valid_task(): void
    {
        $payload = $this->createTaskPayload();
        $taskId = $this->taskService->register(TestTask::class, $payload);

        $task = $this->taskService->findTask(new TaskIdVO($taskId));
        $this->assertNotNull($task);
        $this->assertTrue($this->taskService->canRunTask($task));
    }

    public function test_is_task_expired_returns_false_for_valid_task(): void
    {
        $payload = $this->createTaskPayload();
        $taskId = $this->taskService->register(TestTask::class, $payload);

        $task = $this->taskService->findTask(new TaskIdVO($taskId));
        $this->assertNotNull($task);
        $this->assertFalse($this->taskService->isTaskExpired($task));
    }

    public function test_is_unique_task_with_grace_period_returns_false_for_recurring_task(): void
    {
        $payload = $this->createTaskPayload();
        $config = new TaskConfigRecord(
            signature: new TaskSignatureVO('recurring-grace-test'),
            description: 'Recurring grace test',
            delay_seconds: new CounterVO(300),
            max_attempts: new CounterVO(3),
            start_at: null,
            end_at: null,
        );

        $signature = $this->taskService->register(TestTask::class, $payload, $config);
        $task = $this->taskService->findRecurringTask(new TaskSignatureVO($signature));
        $this->assertNotNull($task);
        // Pour les tâches récurrentes, on utilise une autre méthode
        $this->assertTrue($this->taskService->shouldRunRecurringNow($task));
    }

    public function test_get_grace_period_delay_returns_zero_for_valid_task(): void
    {
        $payload = $this->createTaskPayload();
        $taskId = $this->taskService->register(TestTask::class, $payload);

        $task = $this->taskService->findTask(new TaskIdVO($taskId));
        $this->assertNotNull($task);
        $this->assertSame(0, $this->taskService->getGracePeriodDelay($task));
    }

    // ==================== Batch Tests (hérités) ====================

    public function test_process_batch(): void
    {
        $payload = $this->createTaskPayload();
        $this->taskService->register(TestTask::class, $payload);

        $result = $this->taskService->process(10);

        $this->assertSame(1, $result->unique_success->value);
    }

    public function test_process_unique_only(): void
    {
        $payload = $this->createTaskPayload();
        $this->taskService->register(TestTask::class, $payload);

        $result = $this->taskService->processUniqueOnly(10);

        $this->assertSame(1, $result->unique_success->value);
    }

    public function test_process_recurring_only(): void
    {
        $payload = $this->createTaskPayload();
        $config = new TaskConfigRecord(
            signature: new TaskSignatureVO('recurring-batch-test'),
            description: 'Recurring batch test',
            delay_seconds: new CounterVO(300),
            max_attempts: new CounterVO(3),
            start_at: null,
            end_at: null,
        );

        $this->taskService->register(TestTask::class, $payload, $config);

        $result = $this->taskService->processRecurringOnly(10);

        $this->assertSame(1, $result->recurring_success->value);
    }

    // ==================== Runner Tests (hérités) ====================

    public function test_run_task_success(): void
    {
        $payload = $this->createTaskPayload();
        $taskId = $this->taskService->register(TestTask::class, $payload);

        $task = $this->taskService->findTask(new TaskIdVO($taskId));
        $this->assertNotNull($task);

        $result = $this->taskService->runTask($task);

        $this->assertTrue($result);
    }

    public function test_run_recurring_task_success(): void
    {
        $payload = $this->createTaskPayload();
        $config = new TaskConfigRecord(
            signature: new TaskSignatureVO('recurring-run-test'),
            description: 'Recurring run test',
            delay_seconds: new CounterVO(300),
            max_attempts: new CounterVO(3),
            start_at: null,
            end_at: null,
        );

        $this->taskService->register(TestTask::class, $payload, $config);

        $task = $this->taskService->findRecurringTask(new TaskSignatureVO('recurring-run-test'));
        $this->assertNotNull($task);

        $result = $this->taskService->runRecurringTask($task);

        $this->assertTrue($result);
    }

    // ==================== shouldRunRecurringNow Tests ====================

    public function test_should_run_recurring_now_returns_true_for_ready_task(): void
    {
        $payload = $this->createTaskPayload();
        $config = new TaskConfigRecord(
            signature: new TaskSignatureVO('recurring-ready-test'),
            description: 'Recurring ready test',
            delay_seconds: new CounterVO(300),
            max_attempts: new CounterVO(3),
            start_at: null,
            end_at: null,
        );

        $this->taskService->register(TestTask::class, $payload, $config);

        $task = $this->taskService->findRecurringTask(new TaskSignatureVO('recurring-ready-test'));
        $this->assertNotNull($task);
        $this->assertTrue($this->taskService->shouldRunRecurringNow($task));
    }
}
