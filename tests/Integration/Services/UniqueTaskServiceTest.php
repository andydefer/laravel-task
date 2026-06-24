<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Services;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Task\Configs\UniqueTaskConfig;
use AndyDefer\Task\Contracts\Services\UniqueTaskServiceInterface;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\Records\ProcessResultRecord;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\Repositories\TaskExecutionDebugRepository;
use AndyDefer\Task\Repositories\UniqueTaskRepository;
use AndyDefer\Task\Services\UniqueTaskService;
use AndyDefer\Task\Tests\Fixtures\Tasks\FailingTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestUniqueTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestUniqueTaskWithCustomConfig;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\MaxFailedAttemptsVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\App;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidFactoryInterface;

final class UniqueTaskServiceTest extends IntegrationTestCase
{
    use DatabaseMigrations;

    private UniqueTaskServiceInterface $service;

    private UniqueTaskRepository $repository;

    private TaskExecutionDebugRepository $debugRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->debugRepository = new TaskExecutionDebugRepository;
        $this->repository = new UniqueTaskRepository($this->debugRepository);

        $logger = App::make(LoggerInterface::class);

        $this->service = new UniqueTaskService(
            repository: $this->repository,
            logger: $logger,
            hydration: App::make(HydrationService::class),
            uuidFactory: App::make(UuidFactoryInterface::class),
            app: App::getFacadeApplication(),
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    // ==================== TESTS REGISTER ====================

    public function test_register_creates_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $taskId = $this->service->register(
            TestUniqueTask::class,
            $payload
        );

        $this->assertInstanceOf(TaskIdVO::class, $taskId);

        $task = $this->repository->findById($taskId->value);
        $this->assertNotNull($task);
        $this->assertEquals(TestUniqueTask::class, $task->getFqcn());
        $this->assertEquals(UniqueTaskStatus::PENDING, $task->getStatus());
    }

    public function test_register_throws_exception_for_invalid_class(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Task must extend AbstractUniqueTask');

        $this->service->register(
            'InvalidClass',
            StrictDataObject::from([])
        );
    }

    public function test_register_with_custom_config(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = new UniqueTaskConfig(
            alias: new TaskSignatureVO('custom-alias'),
            description: 'Custom config',
            scheduled_at: new Iso8601DateTimeVO(now()->addDays(7)->toIso8601String()),
            max_attempts: new MaxFailedAttemptsVO(5),
        );

        $taskId = $this->service->register(
            TestUniqueTaskWithCustomConfig::class,
            $payload,
            $config
        );

        $task = $this->repository->findById($taskId->value);
        $this->assertNotNull($task);
        $this->assertEquals('custom-alias', $task->getAlias()->getValue());
        $this->assertEquals(5, $task->getMaxAttempts()->value);
    }

    // ==================== TESTS RUN ====================

    public function test_run_executes_pending_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $taskId = $this->service->register(
            TestUniqueTask::class,
            $payload
        );

        $task = $this->repository->findById($taskId->value);
        $task->update(['scheduled_at' => now()->subHours(2)->toDateTimeString()]);

        $result = $this->service->run($taskId);

        $this->assertTrue($result);

        $updatedTask = $this->repository->findById($taskId->value);
        $this->assertEquals(UniqueTaskStatus::COMPLETED, $updatedTask->getStatus());
        $this->assertNotNull($updatedTask->getFinishedAt());
    }

    public function test_run_returns_false_for_non_existing_task(): void
    {
        $result = $this->service->run(new TaskIdVO((string) Uuid::uuid4()));
        $this->assertFalse($result);
    }

    public function test_run_returns_false_for_completed_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $taskId = $this->service->register(
            TestUniqueTask::class,
            $payload
        );

        $task = $this->repository->findById($taskId->value);
        $task->update(['status' => UniqueTaskStatus::COMPLETED->value]);

        $result = $this->service->run($taskId);
        $this->assertFalse($result);
    }

    public function test_run_handles_task_failure(): void
    {
        $payload = StrictDataObject::from(['test' => 'data', 'unique' => uniqid()]);
        $taskId = $this->service->register(
            FailingTask::class,
            $payload
        );

        $task = $this->repository->findById($taskId->value);
        $task->update([
            'scheduled_at' => now()->subHours(2)->toDateTimeString(),
            'attempts' => 2,
        ]);

        $result = $this->service->run($taskId);

        $this->assertFalse($result);

        $updatedTask = $this->repository->findById($taskId->value);
        $this->assertEquals(UniqueTaskStatus::FAILED, $updatedTask->getStatus());
        $this->assertNotNull($updatedTask->getFinishedAt());
    }

    // ==================== TESTS CANCEL ====================

    public function test_cancel_cancels_pending_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $taskId = $this->service->register(
            TestUniqueTask::class,
            $payload
        );

        $this->service->cancel($taskId, 'Test cancellation');

        $task = $this->repository->findById($taskId->value);
        $this->assertNotNull($task);
        $this->assertEquals(UniqueTaskStatus::CANCELED, $task->getStatus());
        $this->assertNotNull($task->getFinishedAt());
    }

    public function test_cancel_throws_exception_for_non_existing_task(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Task not found');

        $this->service->cancel(new TaskIdVO((string) Uuid::uuid4()));
    }

    public function test_cancel_throws_exception_for_completed_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $taskId = $this->service->register(
            TestUniqueTask::class,
            $payload
        );

        $task = $this->repository->findById($taskId->value);
        $task->update(['status' => UniqueTaskStatus::COMPLETED->value]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not in PENDING state');

        $this->service->cancel($taskId);
    }

    public function test_cancel_throws_exception_for_failed_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data', 'unique' => uniqid()]);
        $taskId = $this->service->register(
            FailingTask::class,
            $payload
        );

        $task = $this->repository->findById($taskId->value);
        $task->update(['status' => UniqueTaskStatus::FAILED->value]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not in PENDING state');

        $this->service->cancel($taskId);
    }

    // ==================== TESTS RESCHEDULE ====================

    public function test_reschedule_updates_scheduled_at(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $taskId = $this->service->register(
            TestUniqueTask::class,
            $payload
        );

        $newScheduledAt = new Iso8601DateTimeVO(now()->addDays(5)->toIso8601String());

        $this->service->reschedule($taskId, $newScheduledAt);

        $task = $this->repository->findById($taskId->value);
        $this->assertNotNull($task);
        $this->assertEquals(
            $newScheduledAt->toDateTime()->format('Y-m-d H:i:s'),
            $task->getScheduledAt()->toDateTime()->format('Y-m-d H:i:s')
        );
    }

    public function test_reschedule_throws_exception_for_non_existing_task(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Task not found');

        $this->service->reschedule(
            new TaskIdVO((string) Uuid::uuid4()),
            new Iso8601DateTimeVO(now()->addDays(1)->toIso8601String())
        );
    }

    public function test_reschedule_throws_exception_for_completed_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $taskId = $this->service->register(
            TestUniqueTask::class,
            $payload
        );

        $task = $this->repository->findById($taskId->value);
        $task->update(['status' => UniqueTaskStatus::COMPLETED->value]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not in PENDING state');

        $this->service->reschedule(
            $taskId,
            new Iso8601DateTimeVO(now()->addDays(1)->toIso8601String())
        );
    }

    // ==================== TESTS EXTEND GRACE PERIOD ====================

    public function test_extend_grace_period_adds_seconds(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $taskId = $this->service->register(
            TestUniqueTask::class,
            $payload
        );

        $task = $this->repository->findById($taskId->value);
        $originalGracePeriod = $task->getGracePeriodSeconds();

        $this->service->extendGracePeriod($taskId, 3600);

        $updatedTask = $this->repository->findById($taskId->value);
        $this->assertEquals($originalGracePeriod + 3600, $updatedTask->getGracePeriodSeconds());
    }

    public function test_extend_grace_period_throws_exception_for_negative_seconds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Extra seconds must be positive');

        $payload = StrictDataObject::from(['test' => 'data']);
        $taskId = $this->service->register(
            TestUniqueTask::class,
            $payload
        );

        $this->service->extendGracePeriod($taskId, -3600);
    }

    public function test_extend_grace_period_throws_exception_for_non_existing_task(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Task not found');

        $this->service->extendGracePeriod(
            new TaskIdVO((string) Uuid::uuid4()),
            3600
        );
    }

    public function test_extend_grace_period_throws_exception_for_completed_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $taskId = $this->service->register(
            TestUniqueTask::class,
            $payload
        );

        $task = $this->repository->findById($taskId->value);
        $task->update(['status' => UniqueTaskStatus::COMPLETED->value]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not in PENDING state');

        $this->service->extendGracePeriod($taskId, 3600);
    }

    // ==================== TESTS FIND CANCELED ====================

    public function test_find_canceled_returns_canceled_tasks(): void
    {
        $payload = StrictDataObject::from(['test' => 'cancelled']);
        $taskId = $this->service->register(
            TestUniqueTask::class,
            $payload
        );

        $this->service->cancel($taskId, 'Test cancellation');

        $cancelled = $this->service->findCanceled();

        $this->assertCount(1, $cancelled);
        $this->assertEquals($taskId->value, $cancelled[0]->id->value);
        $this->assertEquals(UniqueTaskStatus::CANCELED, $cancelled[0]->status);
    }

    public function test_find_canceled_returns_empty_when_no_canceled_tasks(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $this->service->register(
            TestUniqueTask::class,
            $payload
        );

        $cancelled = $this->service->findCanceled();

        $this->assertCount(0, $cancelled);
    }

    public function test_find_canceled_with_limit(): void
    {
        $payload = StrictDataObject::from(['test' => 'cancelled']);

        $taskId1 = $this->service->register(TestUniqueTask::class, $payload);
        $this->service->cancel($taskId1, 'Test cancellation 1');

        $taskId2 = $this->service->register(TestUniqueTask::class, $payload);
        $this->service->cancel($taskId2, 'Test cancellation 2');

        $taskId3 = $this->service->register(TestUniqueTask::class, $payload);
        $this->service->cancel($taskId3, 'Test cancellation 3');

        $cancelled = $this->service->findCanceled(2);

        $this->assertCount(2, $cancelled);
    }

    // ==================== TESTS COUNT CANCELED ====================

    public function test_count_canceled_returns_count(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);

        $taskId1 = $this->service->register(TestUniqueTask::class, $payload);
        $this->service->cancel($taskId1, 'Test cancellation 1');

        $taskId2 = $this->service->register(TestUniqueTask::class, $payload);
        $this->service->cancel($taskId2, 'Test cancellation 2');

        $this->service->register(TestUniqueTask::class, $payload);

        $this->assertEquals(2, $this->service->countCanceled());
    }

    public function test_count_canceled_returns_zero_when_no_canceled_tasks(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $this->service->register(TestUniqueTask::class, $payload);

        $this->assertEquals(0, $this->service->countCanceled());
    }

    // ==================== TESTS PROCESS ====================

    public function test_process_executes_ready_tasks(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $payload = StrictDataObject::from(['test' => "task-{$i}"]);
            $taskId = $this->service->register(
                TestUniqueTask::class,
                $payload
            );
            $task = $this->repository->findById($taskId->value);
            $task->update(['scheduled_at' => now()->subHours(2)->toDateTimeString()]);
        }

        /** @var ProcessResultRecord $result */
        $result = $this->service->process();

        $this->assertEquals(3, $result->success->value);
        $this->assertEquals(0, $result->failed->value);
    }

    public function test_process_respects_limit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $payload = StrictDataObject::from(['test' => "task-{$i}"]);
            $taskId = $this->service->register(
                TestUniqueTask::class,
                $payload
            );
            $task = $this->repository->findById($taskId->value);
            $task->update(['scheduled_at' => now()->subHours(2)->toDateTimeString()]);
        }

        /** @var ProcessResultRecord $result */
        $result = $this->service->process(2);

        $this->assertEquals(2, $result->success->value);
        $this->assertEquals(0, $result->failed->value);
    }

    // ==================== TESTS FIND ====================

    public function test_find_returns_task_record(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $taskId = $this->service->register(
            TestUniqueTask::class,
            $payload
        );

        $record = $this->service->find($taskId);

        $this->assertInstanceOf(UniqueTaskRecord::class, $record);
        $this->assertEquals($taskId->value, $record->id->value);
        $this->assertEquals(TestUniqueTask::class, $record->fqcn);
    }

    public function test_find_returns_null_for_non_existing_task(): void
    {
        $record = $this->service->find(new TaskIdVO((string) Uuid::uuid4()));
        $this->assertNull($record);
    }

    // ==================== TESTS FIND PENDING/COMPLETED/FAILED ====================

    public function test_find_pending_returns_only_pending_tasks(): void
    {
        $payload = StrictDataObject::from(['test' => 'pending']);
        $pendingId = $this->service->register(TestUniqueTask::class, $payload);

        $payload2 = StrictDataObject::from(['test' => 'completed']);
        $completedId = $this->service->register(TestUniqueTask::class, $payload2);
        $task = $this->repository->findById($completedId->value);
        $task->update(['status' => UniqueTaskStatus::COMPLETED->value]);

        $pendings = $this->service->findPending();

        $this->assertCount(1, $pendings);
        $this->assertEquals($pendingId->value, $pendings[0]->id->value);
    }

    public function test_find_pending_excludes_canceled_tasks(): void
    {
        $payload = StrictDataObject::from(['test' => 'cancelled']);
        $canceledId = $this->service->register(TestUniqueTask::class, $payload);
        $this->service->cancel($canceledId, 'Test cancellation');

        $payload2 = StrictDataObject::from(['test' => 'pending']);
        $pendingId = $this->service->register(TestUniqueTask::class, $payload2);

        $pendings = $this->service->findPending();

        $this->assertCount(1, $pendings);
        $this->assertEquals($pendingId->value, $pendings[0]->id->value);
        $this->assertNotEquals($canceledId->value, $pendings[0]->id->value);
    }

    public function test_find_completed_returns_only_completed_tasks(): void
    {
        $payload = StrictDataObject::from(['test' => 'completed']);
        $completedId = $this->service->register(TestUniqueTask::class, $payload);
        $task = $this->repository->findById($completedId->value);
        $task->update(['status' => UniqueTaskStatus::COMPLETED->value]);

        $payload2 = StrictDataObject::from(['test' => 'pending']);
        $this->service->register(TestUniqueTask::class, $payload2);

        $completeds = $this->service->findCompleted();

        $this->assertCount(1, $completeds);
        $this->assertEquals($completedId->value, $completeds[0]->id->value);
    }

    public function test_find_failed_returns_only_failed_tasks(): void
    {
        $payload = StrictDataObject::from(['test' => 'failed', 'unique' => uniqid()]);
        $failedId = $this->service->register(FailingTask::class, $payload);

        $task = $this->repository->findById($failedId->value);
        $task->update(['status' => UniqueTaskStatus::FAILED->value]);

        $payload2 = StrictDataObject::from(['test' => 'pending']);
        $this->service->register(TestUniqueTask::class, $payload2);

        $faileds = $this->service->findFailed();

        $this->assertCount(1, $faileds);
        $this->assertEquals($failedId->value, $faileds[0]->id->value);
    }

    public function test_find_canceled_excludes_failed_tasks(): void
    {
        $payload = StrictDataObject::from(['test' => 'failed', 'unique' => uniqid()]);
        $failedId = $this->service->register(FailingTask::class, $payload);
        $task = $this->repository->findById($failedId->value);
        $task->update(['status' => UniqueTaskStatus::FAILED->value]);

        $payload2 = StrictDataObject::from(['test' => 'cancelled']);
        $canceledId = $this->service->register(TestUniqueTask::class, $payload2);
        $this->service->cancel($canceledId, 'Test cancellation');

        $cancelled = $this->service->findCanceled();

        $this->assertCount(1, $cancelled);
        $this->assertEquals($canceledId->value, $cancelled[0]->id->value);
        $this->assertNotEquals($failedId->value, $cancelled[0]->id->value);
    }

    // ==================== TESTS EXISTS ====================

    public function test_exists_returns_true_for_existing_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $taskId = $this->service->register(TestUniqueTask::class, $payload);

        $this->assertTrue($this->service->exists($taskId));
    }

    public function test_exists_returns_true_for_canceled_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $taskId = $this->service->register(TestUniqueTask::class, $payload);
        $this->service->cancel($taskId, 'Test cancellation');

        $this->assertTrue($this->service->exists($taskId));
    }

    public function test_exists_returns_false_for_non_existing_task(): void
    {
        $this->assertFalse($this->service->exists(new TaskIdVO((string) Uuid::uuid4())));
    }

    // ==================== TESTS DELETE ====================

    public function test_delete_removes_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $taskId = $this->service->register(TestUniqueTask::class, $payload);

        $this->service->delete($taskId);

        $task = $this->repository->findById($taskId->value);
        $this->assertNull($task);
    }

    public function test_delete_removes_canceled_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $taskId = $this->service->register(TestUniqueTask::class, $payload);
        $this->service->cancel($taskId, 'Test cancellation');

        $this->service->delete($taskId);

        $task = $this->repository->findById($taskId->value);
        $this->assertNull($task);
    }

    public function test_delete_throws_exception_for_non_existing_task(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Task not found');

        $this->service->delete(new TaskIdVO((string) Uuid::uuid4()));
    }

    // ==================== TESTS COUNTS ====================

    public function test_count_returns_total_tasks(): void
    {
        $this->service->register(TestUniqueTask::class, StrictDataObject::from([]));
        $this->service->register(TestUniqueTask::class, StrictDataObject::from([]));

        $this->assertEquals(2, $this->service->count());
    }

    public function test_count_pending_returns_pending_tasks(): void
    {
        $this->service->register(TestUniqueTask::class, StrictDataObject::from([]));
        $this->service->register(TestUniqueTask::class, StrictDataObject::from([]));

        $this->assertEquals(2, $this->service->countPending());
    }

    public function test_count_pending_excludes_canceled_tasks(): void
    {
        $payload = StrictDataObject::from(['test' => 'cancelled']);
        $canceledId = $this->service->register(TestUniqueTask::class, $payload);
        $this->service->cancel($canceledId, 'Test cancellation');

        $this->service->register(TestUniqueTask::class, StrictDataObject::from([]));

        $this->assertEquals(1, $this->service->countPending());
    }

    public function test_count_completed_returns_completed_tasks(): void
    {
        $payload = StrictDataObject::from(['test' => 'completed']);
        $taskId = $this->service->register(TestUniqueTask::class, $payload);
        $task = $this->repository->findById($taskId->value);
        $task->update(['status' => UniqueTaskStatus::COMPLETED->value]);

        $this->assertEquals(1, $this->service->countCompleted());
    }

    public function test_count_failed_returns_failed_tasks(): void
    {
        $payload = StrictDataObject::from(['test' => 'failed']);
        $taskId = $this->service->register(FailingTask::class, $payload);
        $task = $this->repository->findById($taskId->value);
        $task->update(['status' => UniqueTaskStatus::FAILED->value]);

        $this->assertEquals(1, $this->service->countFailed());
    }

    public function test_count_canceled_returns_canceled_tasks(): void
    {
        $payload = StrictDataObject::from(['test' => 'cancelled']);
        $taskId = $this->service->register(TestUniqueTask::class, $payload);
        $this->service->cancel($taskId, 'Test cancellation');

        $this->assertEquals(1, $this->service->countCanceled());
    }
}
