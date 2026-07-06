<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Services;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Task\Contracts\Services\RecurringTaskServiceInterface;
use AndyDefer\Task\Enums\RecurringTaskStatus;
use AndyDefer\Task\Enums\TaskType;
use AndyDefer\Task\Models\RecurringTask;
use AndyDefer\Task\Records\RecurringTaskConfigRecord;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Repositories\RecurringTaskRepository;
use AndyDefer\Task\Repositories\TaskExecutionDebugRepository;
use AndyDefer\Task\Services\RecurringTaskService;
use AndyDefer\Task\Tests\Fixtures\Tasks\FailingRecurringTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\SomeClass;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestRecurringTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use AndyDefer\Task\ValueObjects\RecurringTaskFqcnVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Ramsey\Uuid\Uuid;

final class RecurringTaskServiceTest extends IntegrationTestCase
{
    use DatabaseMigrations;

    private RecurringTaskServiceInterface $service;

    private RecurringTaskRepository $repository;

    private TaskExecutionDebugRepository $debugRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->debugRepository = new TaskExecutionDebugRepository;
        $this->repository = new RecurringTaskRepository(
            $this->debugRepository,
            App::make(LoggerInterface::class)
        );

        $logger = App::make(LoggerInterface::class);

        $this->service = new RecurringTaskService(
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

    private function findTaskByAlias(TaskAliasVO $alias): ?RecurringTask
    {
        return $this->repository->findByAlias($alias);
    }

    private function getAliasValue(TaskAliasVO $alias): string
    {
        return $alias->getValue();
    }

    private function updateTaskStatus(TaskAliasVO $alias, RecurringTaskStatus $status): void
    {
        $task = $this->repository->findByAlias($alias);
        if ($task !== null) {
            $this->repository->updateRaw(
                $task->getId()->getValue(),
                ['status' => $status->value]
            );
        }
    }

    private function createConfig(
        int $intervalSeconds = 3600,
        ?Iso8601DateTimeVO $startAt = null,
        ?Iso8601DateTimeVO $endAt = null
    ): RecurringTaskConfigRecord {
        $now = new Iso8601DateTimeVO;
        $startAt = $startAt ?? $now->addSeconds(7200);
        $endAt = $endAt ?? $now->addSeconds(604800);

        return RecurringTaskConfigRecord::from([
            'type' => TaskType::RECURRING->value,
            'description' => 'Test recurring task',
            'interval_seconds' => $intervalSeconds,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'max_attempts' => 3,
        ]);
    }

    private function createConfigWithPastStart(
        int $intervalSeconds = 3600,
        ?Iso8601DateTimeVO $startAt = null,
        ?Iso8601DateTimeVO $endAt = null
    ): RecurringTaskConfigRecord {
        $now = new Iso8601DateTimeVO;
        $startAt = $startAt ?? $now->addSeconds(-7200);
        $endAt = $endAt ?? $now->addSeconds(604800);

        return RecurringTaskConfigRecord::from([
            'type' => TaskType::RECURRING->value,
            'description' => 'Test recurring task',
            'interval_seconds' => $intervalSeconds,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'max_attempts' => 3,
        ]);
    }

    // ==================== TESTS REGISTER ====================

    public function test_register_creates_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig();

        $fqcn = new RecurringTaskFqcnVO(TestRecurringTask::class);
        $alias = $this->service->register($fqcn, $payload, $config);

        $this->assertInstanceOf(TaskAliasVO::class, $alias);
        $this->assertStringContainsString('@', $this->getAliasValue($alias));

        $task = $this->findTaskByAlias($alias);
        $this->assertNotNull($task);
        $this->assertEquals(TestRecurringTask::class, $task->getFqcn());
        $this->assertEquals(RecurringTaskStatus::WAITING, $task->getStatus());
    }

    /**
     * @test
     */
    public function register_throws_exception_for_duplicate_alias(): void
    {
        $this->markTestSkipped('Duplicate alias is impossible because UUID is generated randomly each time.');
    }

    public function test_register_throws_exception_for_invalid_class(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Class "AndyDefer\Task\Tests\Fixtures\Tasks\SomeClass" must extend AndyDefer\Task\Abstract\AbstractRecurringTask'
        );

        $payload = StrictDataObject::from([]);
        $config = $this->createConfig();
        $fqcn = new RecurringTaskFqcnVO(SomeClass::class);

        $this->service->register($fqcn, $payload, $config);
    }

    // ==================== TESTS RUN ====================

    public function test_run_executes_playing_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfigWithPastStart();

        $fqcn = new RecurringTaskFqcnVO(TestRecurringTask::class);
        $alias = $this->service->register($fqcn, $payload, $config);

        $this->updateTaskStatus($alias, RecurringTaskStatus::PLAYING);

        $result = $this->service->run($alias);

        $this->assertTrue($result->success);

        $updatedTask = $this->findTaskByAlias($alias);
        $this->assertEquals(RecurringTaskStatus::PLAYING, $updatedTask->getStatus());
        $this->assertNotNull($updatedTask->getLastRunAt());
    }

    public function test_run_returns_failure_for_non_existing_task(): void
    {
        $alias = new TaskAliasVO('recurring@'.Uuid::uuid4()->toString());
        $result = $this->service->run($alias);

        $this->assertFalse($result->success);
        $this->assertEquals('Task not found', $result->error);
    }

    public function test_run_returns_failure_for_waiting_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig();

        $fqcn = new RecurringTaskFqcnVO(TestRecurringTask::class);
        $alias = $this->service->register($fqcn, $payload, $config);

        $result = $this->service->run($alias);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('not in PLAYING state', $result->error->getValue());
    }

    public function test_run_handles_task_failure(): void
    {
        $payload = StrictDataObject::from(['should_fail' => true]);
        $config = $this->createConfigWithPastStart();

        $fqcn = new RecurringTaskFqcnVO(FailingRecurringTask::class);
        $alias = $this->service->register($fqcn, $payload, $config);

        $this->updateTaskStatus($alias, RecurringTaskStatus::PLAYING);

        $result = $this->service->run($alias);

        $this->assertFalse($result->success);

        $updatedTask = $this->findTaskByAlias($alias);
        $this->assertEquals(RecurringTaskStatus::PLAYING, $updatedTask->getStatus());
        $this->assertNotNull($updatedTask->getLastRunAt());
    }

    // ==================== TESTS CANCEL ====================

    public function test_cancel_cancels_recurring_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfigWithPastStart();

        $fqcn = new RecurringTaskFqcnVO(TestRecurringTask::class);
        $alias = $this->service->register($fqcn, $payload, $config);

        $this->updateTaskStatus($alias, RecurringTaskStatus::PLAYING);

        $result = $this->service->cancel($alias, new DescriptionVO('Test cancellation'));

        $this->assertTrue($result);

        $task = $this->findTaskByAlias($alias);
        $this->assertNotNull($task);
        $this->assertEquals(RecurringTaskStatus::CANCELED, $task->getStatus());
        $this->assertNotNull($task->getFinishedAt());
        $this->assertNotNull($task->getCancelledAt());
    }

    public function test_cancel_returns_false_for_non_existing_task(): void
    {
        $alias = new TaskAliasVO('recurring@'.Uuid::uuid4()->toString());
        $result = $this->service->cancel($alias, new DescriptionVO('Test'));

        $this->assertFalse($result);
    }

    // ==================== TESTS PAUSE/RESUME/FINISH ====================

    public function test_pause_moves_task_to_paused(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfigWithPastStart();

        $fqcn = new RecurringTaskFqcnVO(TestRecurringTask::class);
        $alias = $this->service->register($fqcn, $payload, $config);

        $this->updateTaskStatus($alias, RecurringTaskStatus::PLAYING);

        $result = $this->service->pause($alias);

        $this->assertTrue($result);

        $updatedTask = $this->findTaskByAlias($alias);
        $this->assertEquals(RecurringTaskStatus::PAUSED, $updatedTask->getStatus());
    }

    public function test_pause_returns_false_for_waiting_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig();

        $fqcn = new RecurringTaskFqcnVO(TestRecurringTask::class);
        $alias = $this->service->register($fqcn, $payload, $config);

        $result = $this->service->pause($alias);

        $this->assertFalse($result);
    }

    public function test_resume_moves_task_to_playing(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfigWithPastStart();

        $fqcn = new RecurringTaskFqcnVO(TestRecurringTask::class);
        $alias = $this->service->register($fqcn, $payload, $config);

        $this->updateTaskStatus($alias, RecurringTaskStatus::PLAYING);
        $this->updateTaskStatus($alias, RecurringTaskStatus::PAUSED);

        $result = $this->service->resume($alias);

        $this->assertTrue($result);

        $updatedTask = $this->findTaskByAlias($alias);
        $this->assertEquals(RecurringTaskStatus::PLAYING, $updatedTask->getStatus());
    }

    public function test_finish_moves_task_to_finished(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig();

        $fqcn = new RecurringTaskFqcnVO(TestRecurringTask::class);
        $alias = $this->service->register($fqcn, $payload, $config);

        $result = $this->service->finish($alias);

        $this->assertTrue($result);

        $updatedTask = $this->findTaskByAlias($alias);
        $this->assertEquals(RecurringTaskStatus::FINISHED, $updatedTask->getStatus());
        $this->assertNotNull($updatedTask->getFinishedAt());
    }

    // ==================== TESTS FIND ====================

    public function test_find_returns_task_record(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig();

        $fqcn = new RecurringTaskFqcnVO(TestRecurringTask::class);
        $alias = $this->service->register($fqcn, $payload, $config);

        $record = $this->service->find($alias);

        $this->assertInstanceOf(RecurringTaskRecord::class, $record);
        $this->assertEquals($this->getAliasValue($alias), $this->getAliasValue($record->alias));
        $this->assertEquals(TestRecurringTask::class, $record->fqcn->getValue());
    }

    // ==================== TESTS EXISTS ====================

    public function test_exists_returns_true_for_existing_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig();

        $fqcn = new RecurringTaskFqcnVO(TestRecurringTask::class);
        $alias = $this->service->register($fqcn, $payload, $config);

        $this->assertTrue($this->service->exists($alias));
    }

    public function test_exists_returns_false_for_non_existing_task(): void
    {
        $alias = new TaskAliasVO('recurring@'.Uuid::uuid4()->toString());
        $this->assertFalse($this->service->exists($alias));
    }

    // ==================== TESTS DELETE ====================

    public function test_delete_removes_canceled_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig();

        $fqcn = new RecurringTaskFqcnVO(TestRecurringTask::class);
        $alias = $this->service->register($fqcn, $payload, $config);

        $this->service->cancel($alias, new DescriptionVO('Test cancellation'));

        $result = $this->service->delete($alias);

        $this->assertTrue($result);

        $task = $this->findTaskByAlias($alias);
        $this->assertNull($task);
    }

    public function test_delete_returns_false_for_non_existing_task(): void
    {
        $alias = new TaskAliasVO('recurring@'.Uuid::uuid4()->toString());
        $result = $this->service->delete($alias);

        $this->assertFalse($result);
    }

    // ==================== TESTS COUNTS ====================

    public function test_count_returns_total_tasks(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig();

        $fqcn = new RecurringTaskFqcnVO(TestRecurringTask::class);
        $this->service->register($fqcn, $payload, $config);
        $this->service->register($fqcn, $payload, $config);

        $this->assertEquals(2, $this->service->count()->getValue());
    }

    // ==================== TESTS PROCESS ====================

    public function test_process_executes_ready_tasks(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $payload = StrictDataObject::from(['test' => "task-{$i}"]);
            $config = $this->createConfigWithPastStart();

            $fqcn = new RecurringTaskFqcnVO(TestRecurringTask::class);
            $alias = $this->service->register($fqcn, $payload, $config);
            $this->updateTaskStatus($alias, RecurringTaskStatus::PLAYING);
        }

        $result = $this->service->process(new LimitVO(10));

        $this->assertEquals(3, $result->success->getValue());
        $this->assertEquals(0, $result->failed->getValue());
        $this->assertEquals(0, $result->finished->getValue());
    }

    public function test_process_respects_limit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $payload = StrictDataObject::from(['test' => "task-{$i}"]);
            $config = $this->createConfigWithPastStart();

            $fqcn = new RecurringTaskFqcnVO(TestRecurringTask::class);
            $alias = $this->service->register($fqcn, $payload, $config);
            $this->updateTaskStatus($alias, RecurringTaskStatus::PLAYING);
        }

        $result = $this->service->process(new LimitVO(3));

        $this->assertEquals(3, $result->success->getValue());
        $this->assertEquals(0, $result->failed->getValue());
        $this->assertEquals(0, $result->finished->getValue());
    }

    // ==================== TESTS CHANGE INTERVAL ====================

    public function test_change_interval_updates_interval(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig();

        $fqcn = new RecurringTaskFqcnVO(TestRecurringTask::class);
        $alias = $this->service->register($fqcn, $payload, $config);

        $result = $this->service->changeInterval($alias, new DurationVO(7200));

        $this->assertTrue($result);

        $updatedTask = $this->findTaskByAlias($alias);
        $this->assertEquals(7200, $updatedTask->getIntervalSeconds()->getValue());
    }

    // ==================== TESTS EXTEND END AT ====================

    public function test_extend_end_at_updates_end_at(): void
    {
        $now = new Iso8601DateTimeVO;
        $originalEndAt = $now->addSeconds(604800);
        $config = $this->createConfig(endAt: $originalEndAt);

        $fqcn = new RecurringTaskFqcnVO(TestRecurringTask::class);
        $alias = $this->service->register($fqcn, StrictDataObject::from(['test' => 'data']), $config);

        $newEndAt = $now->addSeconds(1209600);

        $result = $this->service->extendEndAt($alias, $newEndAt);

        $this->assertTrue($result);

        $updatedTask = $this->findTaskByAlias($alias);
        $this->assertEquals(
            $newEndAt->format('Y-m-d H:i'),
            $updatedTask->getEndAt()->format('Y-m-d H:i')
        );
    }

    // ==================== TESTS ADVANCE/POSTPONE START ====================

    public function test_advance_start_at_updates_start_at(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig();

        $fqcn = new RecurringTaskFqcnVO(TestRecurringTask::class);
        $alias = $this->service->register($fqcn, $payload, $config);

        $newStartAt = (new Iso8601DateTimeVO)->addSeconds(3600);

        $result = $this->service->advanceStartAt($alias, $newStartAt);

        $this->assertTrue($result);

        $updatedTask = $this->findTaskByAlias($alias);
        $this->assertEquals(
            $newStartAt->format('Y-m-d H:i'),
            $updatedTask->getStartAt()->format('Y-m-d H:i')
        );
    }

    public function test_postpone_start_at_updates_start_at(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig();

        $fqcn = new RecurringTaskFqcnVO(TestRecurringTask::class);
        $alias = $this->service->register($fqcn, $payload, $config);

        $newStartAt = (new Iso8601DateTimeVO)->addSeconds(432000);

        $result = $this->service->postponeStartAt($alias, $newStartAt);

        $this->assertTrue($result);

        $updatedTask = $this->findTaskByAlias($alias);
        $this->assertEquals(
            $newStartAt->format('Y-m-d H:i'),
            $updatedTask->getStartAt()->format('Y-m-d H:i')
        );
    }
}
