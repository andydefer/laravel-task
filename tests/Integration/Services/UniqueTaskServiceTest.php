<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Services;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Task\Contracts\Services\UniqueTaskServiceInterface;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\Models\UniqueTask;
use AndyDefer\Task\Records\UniqueTaskConfigRecord;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\Repositories\TaskExecutionDebugRepository;
use AndyDefer\Task\Repositories\UniqueTaskRepository;
use AndyDefer\Task\Services\UniqueTaskService;
use AndyDefer\Task\Tests\Fixtures\Tasks\FailingTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\SomeClass;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestUniqueTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestUniqueTaskWithCustomConfig;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use AndyDefer\Task\ValueObjects\MaxFailedAttemptsVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\UniqueTaskFqcnVO;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Ramsey\Uuid\Uuid;

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
        $this->repository = new UniqueTaskRepository(
            $this->debugRepository,
            App::make(LoggerInterface::class)
        );

        $logger = App::make(LoggerInterface::class);

        $this->service = new UniqueTaskService(
            repository: $this->repository,
            logger: $logger,
            hydration: App::make(HydrationService::class),
            app: App::getFacadeApplication(),
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Carbon::setTestNow(null);
    }

    // ==================== HELPERS ====================

    private function generateAliasFromName(string $name): TaskAliasVO
    {
        $uuid = Uuid::uuid4()->toString();

        return new TaskAliasVO('unique@'.$uuid);
    }

    private function findTaskByAlias(TaskAliasVO $alias): ?UniqueTask
    {
        return $this->repository->findByAlias($alias);
    }

    private function getAliasValue(TaskAliasVO $alias): string
    {
        return $alias->getValue();
    }

    private function updateTaskStatus(TaskAliasVO $alias, UniqueTaskStatus $status): void
    {
        $task = $this->repository->findByAlias($alias);
        if ($task !== null) {
            $this->repository->updateRaw(
                $task->getId()->getValue(),
                ['status' => $status->value]
            );
        }
    }

    private function updateTaskAttempts(TaskAliasVO $alias, int $attempts): void
    {
        $task = $this->repository->findByAlias($alias);
        if ($task !== null) {
            $this->repository->updateRaw(
                $task->getId()->getValue(),
                ['attempts' => $attempts]
            );
        }
    }

    private function createConfig(
        string $type = 'unique',
        string $description = 'Test task',
        ?Iso8601DateTimeVO $scheduledAt = null,
        int $maxAttempts = 3,
        int $gracePeriod = 86400
    ): UniqueTaskConfigRecord {
        return new UniqueTaskConfigRecord(
            description: new DescriptionVO($description),
            scheduled_at: $scheduledAt ?? new Iso8601DateTimeVO,
            max_attempts: new MaxFailedAttemptsVO($maxAttempts),
            grace_period: new DurationVO($gracePeriod),
        );
    }

    // ==================== TESTS REGISTER ====================

    public function test_register_creates_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $fqcn = new UniqueTaskFqcnVO(TestUniqueTask::class);

        $config = $this->createConfig(
            description: 'Test task',
            scheduledAt: new Iso8601DateTimeVO
        );

        $alias = $this->service->register($fqcn, $payload, $config);

        $this->assertInstanceOf(TaskAliasVO::class, $alias);
        $this->assertStringContainsString('@', $this->getAliasValue($alias));

        $task = $this->findTaskByAlias($alias);
        $this->assertNotNull($task);
        $this->assertEquals(TestUniqueTask::class, $task->getFqcn());
        $this->assertEquals(UniqueTaskStatus::PENDING, $task->getStatus());
    }

    public function test_register_throws_exception_for_class_not_extending_abstract(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Class "AndyDefer\Task\Tests\Fixtures\Tasks\SomeClass" must extend AndyDefer\Task\Abstract\AbstractUniqueTask'
        );

        $fqcn = new UniqueTaskFqcnVO(SomeClass::class);
        $config = $this->createConfig();

        $this->service->register($fqcn, StrictDataObject::from([]), $config);
    }

    public function test_register_with_custom_config(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $fqcn = new UniqueTaskFqcnVO(TestUniqueTaskWithCustomConfig::class);

        $scheduledAt = (new Iso8601DateTimeVO)->addSeconds(604800);

        $config = $this->createConfig(
            description: 'Custom config',
            scheduledAt: $scheduledAt,
            maxAttempts: 5
        );

        $alias = $this->service->register($fqcn, $payload, $config);

        $task = $this->findTaskByAlias($alias);
        $this->assertNotNull($task);
        $this->assertEquals(5, $task->getMaxAttempts()->getValue());
    }

    // ==================== TESTS RUN ====================

    public function test_run_executes_pending_task(): void
    {
        $fqcn = new UniqueTaskFqcnVO(TestUniqueTask::class);
        $config = $this->createConfig(
            description: 'Test',
            scheduledAt: (new Iso8601DateTimeVO)->addSeconds(-7200)
        );

        $alias = $this->service->register($fqcn, StrictDataObject::from(['test' => 'data']), $config);

        $result = $this->service->run($alias);

        $this->assertTrue($result->success);

        $task = $this->findTaskByAlias($alias);
        $this->assertEquals(UniqueTaskStatus::COMPLETED, $task->getStatus());
        $this->assertNotNull($task->getFinishedAt());
    }

    public function test_run_returns_false_for_non_existing_task(): void
    {
        $alias = $this->generateAliasFromName('non-existent');
        $result = $this->service->run($alias);

        $this->assertFalse($result->success);
        $this->assertEquals('Task not found', $result->error);
    }

    public function test_run_returns_false_for_completed_task(): void
    {
        $fqcn = new UniqueTaskFqcnVO(TestUniqueTask::class);
        $config = $this->createConfig();

        $alias = $this->service->register($fqcn, StrictDataObject::from(['test' => 'data']), $config);
        $this->updateTaskStatus($alias, UniqueTaskStatus::COMPLETED);

        $result = $this->service->run($alias);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('not in PENDING state', $result->error->getValue());
    }

    public function test_run_handles_task_failure(): void
    {
        $fqcn = new UniqueTaskFqcnVO(FailingTask::class);
        $config = $this->createConfig(
            description: 'Test',
            scheduledAt: (new Iso8601DateTimeVO)->addSeconds(-7200)
        );

        $alias = $this->service->register($fqcn, StrictDataObject::from(['test' => 'data']), $config);
        $this->updateTaskAttempts($alias, 2);

        $result = $this->service->run($alias);

        $this->assertFalse($result->success);

        $task = $this->findTaskByAlias($alias);
        $this->assertEquals(UniqueTaskStatus::FAILED, $task->getStatus());
        $this->assertNotNull($task->getFinishedAt());
    }

    // ==================== TESTS CANCEL ====================

    public function test_cancel_cancels_pending_task(): void
    {
        $fqcn = new UniqueTaskFqcnVO(TestUniqueTask::class);
        $config = $this->createConfig();

        $alias = $this->service->register($fqcn, StrictDataObject::from(['test' => 'data']), $config);

        $result = $this->service->cancel($alias, new DescriptionVO('Test cancellation'));

        $this->assertTrue($result);

        $task = $this->findTaskByAlias($alias);
        $this->assertEquals(UniqueTaskStatus::CANCELED, $task->getStatus());
        $this->assertNotNull($task->getFinishedAt());
    }

    public function test_cancel_returns_false_for_non_existing_task(): void
    {
        $alias = $this->generateAliasFromName('non-existent');
        $result = $this->service->cancel($alias, new DescriptionVO('Test'));

        $this->assertFalse($result);
    }

    public function test_cancel_returns_false_for_completed_task(): void
    {
        $fqcn = new UniqueTaskFqcnVO(TestUniqueTask::class);
        $config = $this->createConfig();

        $alias = $this->service->register($fqcn, StrictDataObject::from(['test' => 'data']), $config);
        $this->updateTaskStatus($alias, UniqueTaskStatus::COMPLETED);

        $result = $this->service->cancel($alias, new DescriptionVO('Test'));

        $this->assertFalse($result);
    }

    public function test_cancel_returns_false_for_failed_task(): void
    {
        $fqcn = new UniqueTaskFqcnVO(FailingTask::class);
        $config = $this->createConfig();

        $alias = $this->service->register($fqcn, StrictDataObject::from(['test' => 'data']), $config);
        $this->updateTaskStatus($alias, UniqueTaskStatus::FAILED);

        $result = $this->service->cancel($alias, new DescriptionVO('Test'));

        $this->assertFalse($result);
    }

    // ==================== TESTS RESCHEDULE ====================

    public function test_reschedule_updates_scheduled_at(): void
    {
        $fqcn = new UniqueTaskFqcnVO(TestUniqueTask::class);
        $config = $this->createConfig();

        $alias = $this->service->register($fqcn, StrictDataObject::from(['test' => 'data']), $config);

        $newScheduledAt = (new Iso8601DateTimeVO)->addSeconds(432000);

        $result = $this->service->reschedule($alias, $newScheduledAt);

        $this->assertTrue($result);

        $task = $this->findTaskByAlias($alias);
        $this->assertEquals(
            $newScheduledAt->format('Y-m-d H:i:s'),
            $task->getScheduledAt()->format('Y-m-d H:i:s')
        );
    }

    public function test_reschedule_returns_false_for_non_existing_task(): void
    {
        $alias = $this->generateAliasFromName('non-existent');
        $result = $this->service->reschedule($alias, new Iso8601DateTimeVO);

        $this->assertFalse($result);
    }

    public function test_reschedule_returns_false_for_completed_task(): void
    {
        $fqcn = new UniqueTaskFqcnVO(TestUniqueTask::class);
        $config = $this->createConfig();

        $alias = $this->service->register($fqcn, StrictDataObject::from(['test' => 'data']), $config);
        $this->updateTaskStatus($alias, UniqueTaskStatus::COMPLETED);

        $result = $this->service->reschedule($alias, new Iso8601DateTimeVO);

        $this->assertFalse($result);
    }

    // ==================== TESTS EXTEND GRACE PERIOD ====================

    public function test_extend_grace_period_adds_seconds(): void
    {
        $fqcn = new UniqueTaskFqcnVO(TestUniqueTask::class);
        $config = $this->createConfig();

        $alias = $this->service->register($fqcn, StrictDataObject::from(['test' => 'data']), $config);

        $task = $this->findTaskByAlias($alias);
        $originalGracePeriod = $task->getGracePeriodSeconds();

        $result = $this->service->extendGracePeriod($alias, new DurationVO(3600));

        $this->assertTrue($result);

        $updatedTask = $this->findTaskByAlias($alias);
        $this->assertEquals($originalGracePeriod + 3600, $updatedTask->getGracePeriodSeconds());
    }

    public function test_extend_grace_period_returns_false_for_negative_seconds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duration cannot be negative');

        $fqcn = new UniqueTaskFqcnVO(TestUniqueTask::class);
        $config = $this->createConfig();

        $alias = $this->service->register($fqcn, StrictDataObject::from(['test' => 'data']), $config);

        $this->service->extendGracePeriod($alias, new DurationVO(-3600));
    }

    public function test_extend_grace_period_returns_false_for_non_existing_task(): void
    {
        $alias = $this->generateAliasFromName('non-existent');
        $result = $this->service->extendGracePeriod($alias, new DurationVO(3600));

        $this->assertFalse($result);
    }

    public function test_extend_grace_period_returns_false_for_completed_task(): void
    {
        $fqcn = new UniqueTaskFqcnVO(TestUniqueTask::class);
        $config = $this->createConfig();

        $alias = $this->service->register($fqcn, StrictDataObject::from(['test' => 'data']), $config);
        $this->updateTaskStatus($alias, UniqueTaskStatus::COMPLETED);

        $result = $this->service->extendGracePeriod($alias, new DurationVO(3600));

        $this->assertFalse($result);
    }

    // ==================== TESTS PROCESS ====================

    public function test_process_executes_ready_tasks(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $fqcn = new UniqueTaskFqcnVO(TestUniqueTask::class);
            $config = $this->createConfig(
                description: "Task {$i}",
                scheduledAt: (new Iso8601DateTimeVO)->addSeconds(-7200)
            );

            $this->service->register(
                $fqcn,
                StrictDataObject::from(['test' => "task-{$i}"]),
                $config
            );
        }

        $result = $this->service->process();

        $this->assertEquals(3, $result->success->getValue());
        $this->assertEquals(0, $result->failed->getValue());
        $this->assertEquals(0, $result->finished->getValue());
    }

    public function test_process_respects_limit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $fqcn = new UniqueTaskFqcnVO(TestUniqueTask::class);
            $config = $this->createConfig(
                description: "Task {$i}",
                scheduledAt: (new Iso8601DateTimeVO)->addSeconds(-7200)
            );

            $this->service->register(
                $fqcn,
                StrictDataObject::from(['test' => "task-{$i}"]),
                $config
            );
        }

        $result = $this->service->process(new LimitVO(3));

        $this->assertEquals(3, $result->success->getValue());
        $this->assertEquals(0, $result->failed->getValue());
        $this->assertEquals(0, $result->finished->getValue());
    }

    // ==================== TESTS FIND ====================

    public function test_find_returns_task_record(): void
    {
        $fqcn = new UniqueTaskFqcnVO(TestUniqueTask::class);
        $config = $this->createConfig();

        $alias = $this->service->register($fqcn, StrictDataObject::from(['test' => 'data']), $config);

        $record = $this->service->find($alias);

        $this->assertInstanceOf(UniqueTaskRecord::class, $record);
        $this->assertEquals($this->getAliasValue($alias), $this->getAliasValue($record->alias));
        $this->assertEquals(TestUniqueTask::class, $record->fqcn->getValue());
    }

    public function test_find_returns_null_for_non_existing_task(): void
    {
        $alias = $this->generateAliasFromName('non-existent');
        $record = $this->service->find($alias);

        $this->assertNull($record);
    }

    // ==================== TESTS EXISTS ====================

    public function test_exists_returns_true_for_existing_task(): void
    {
        $fqcn = new UniqueTaskFqcnVO(TestUniqueTask::class);
        $config = $this->createConfig();

        $alias = $this->service->register($fqcn, StrictDataObject::from(['test' => 'data']), $config);

        $this->assertTrue($this->service->exists($alias));
    }

    public function test_exists_returns_true_for_canceled_task(): void
    {
        $fqcn = new UniqueTaskFqcnVO(TestUniqueTask::class);
        $config = $this->createConfig();

        $alias = $this->service->register($fqcn, StrictDataObject::from(['test' => 'data']), $config);
        $this->service->cancel($alias, new DescriptionVO('Test'));

        $this->assertTrue($this->service->exists($alias));
    }

    public function test_exists_returns_false_for_non_existing_task(): void
    {
        $alias = $this->generateAliasFromName('non-existent');
        $this->assertFalse($this->service->exists($alias));
    }

    // ==================== TESTS DELETE ====================

    public function test_delete_removes_task(): void
    {
        $fqcn = new UniqueTaskFqcnVO(TestUniqueTask::class);
        $config = $this->createConfig();

        $alias = $this->service->register($fqcn, StrictDataObject::from(['test' => 'data']), $config);

        $result = $this->service->delete($alias);

        $this->assertTrue($result);

        $task = $this->findTaskByAlias($alias);
        $this->assertNull($task);
    }

    public function test_delete_removes_canceled_task(): void
    {
        $fqcn = new UniqueTaskFqcnVO(TestUniqueTask::class);
        $config = $this->createConfig();

        $alias = $this->service->register($fqcn, StrictDataObject::from(['test' => 'data']), $config);
        $this->service->cancel($alias, new DescriptionVO('Test'));

        $result = $this->service->delete($alias);

        $this->assertTrue($result);

        $task = $this->findTaskByAlias($alias);
        $this->assertNull($task);
    }

    public function test_delete_returns_false_for_non_existing_task(): void
    {
        $alias = $this->generateAliasFromName('non-existent');
        $result = $this->service->delete($alias);

        $this->assertFalse($result);
    }

    // ==================== TESTS COUNTS ====================

    public function test_count_returns_total_tasks(): void
    {
        $fqcn = new UniqueTaskFqcnVO(TestUniqueTask::class);
        $config = $this->createConfig();

        $this->service->register($fqcn, StrictDataObject::from([]), $config);
        $this->service->register($fqcn, StrictDataObject::from([]), $config);

        $this->assertEquals(2, $this->service->count()->getValue());
    }

    public function test_count_pending_returns_pending_tasks(): void
    {
        $fqcn = new UniqueTaskFqcnVO(TestUniqueTask::class);
        $config = $this->createConfig();

        $this->service->register($fqcn, StrictDataObject::from([]), $config);
        $this->service->register($fqcn, StrictDataObject::from([]), $config);

        $this->assertEquals(2, $this->service->countPending()->getValue());
    }

    public function test_count_completed_returns_completed_tasks(): void
    {
        $fqcn = new UniqueTaskFqcnVO(TestUniqueTask::class);
        $config = $this->createConfig(
            scheduledAt: (new Iso8601DateTimeVO)->addSeconds(-7200)
        );

        $alias = $this->service->register($fqcn, StrictDataObject::from(['test' => 'data']), $config);
        $this->service->run($alias);

        $this->assertEquals(1, $this->service->countCompleted()->getValue());
    }

    public function test_count_failed_returns_failed_tasks(): void
    {
        $fqcn = new UniqueTaskFqcnVO(FailingTask::class);
        $config = $this->createConfig(
            scheduledAt: (new Iso8601DateTimeVO)->addSeconds(-7200)
        );

        $alias = $this->service->register($fqcn, StrictDataObject::from(['test' => 'data']), $config);
        $this->updateTaskAttempts($alias, 3);

        $result = $this->service->run($alias);
        $this->assertFalse($result->success);

        $this->assertEquals(1, $this->service->countFailed()->getValue());
    }

    public function test_count_canceled_returns_canceled_tasks(): void
    {
        $fqcn = new UniqueTaskFqcnVO(TestUniqueTask::class);
        $config = $this->createConfig();

        $alias = $this->service->register($fqcn, StrictDataObject::from(['test' => 'data']), $config);
        $this->service->cancel($alias, new DescriptionVO('Test'));

        $this->assertEquals(1, $this->service->countCanceled()->getValue());
    }
}
