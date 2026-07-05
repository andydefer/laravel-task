<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Services;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Task\Contracts\Services\RecurringTaskServiceInterface;
use AndyDefer\Task\Enums\RecurringTaskStatus;
use AndyDefer\Task\Models\RecurringTask;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Repositories\RecurringTaskRepository;
use AndyDefer\Task\Repositories\TaskExecutionDebugRepository;
use AndyDefer\Task\Services\RecurringTaskService;
use AndyDefer\Task\Tests\Fixtures\Tasks\FailingRecurringTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestRecurringTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use AndyDefer\Task\ValueObjects\MaxFailedAttemptsVO;
use AndyDefer\Task\ValueObjects\RecurringTaskConfigVO;
use AndyDefer\Task\ValueObjects\RecurringTaskFqcnVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\TaskTypeVO;
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

    private function generateAliasFromName(string $name): TaskAliasVO
    {
        $uuid = Uuid::uuid5(Uuid::NAMESPACE_DNS, $name);

        return new TaskAliasVO(
            new TaskTypeVO('recurring'),
            $uuid->toString()
        );
    }

    private function createConfig(
        string $aliasName,
        int $intervalSeconds = 3600,
        ?Iso8601DateTimeVO $startAt = null,
        ?Iso8601DateTimeVO $endAt = null
    ): RecurringTaskConfigVO {
        $now = new Iso8601DateTimeVO;
        $startAt = $startAt ?? $now->addSeconds(7200); // addHours(2)
        $endAt = $endAt ?? $now->addSeconds(604800); // addDays(7)

        $type = new TaskTypeVO('recurring');

        return new RecurringTaskConfigVO(
            type: $type,
            description: new DescriptionVO('Test recurring task'),
            interval_seconds: new CounterVO($intervalSeconds),
            start_at: $startAt,
            end_at: $endAt,
            max_attempts: new MaxFailedAttemptsVO(3),
        );
    }

    private function createConfigWithPastStart(
        string $aliasName,
        int $intervalSeconds = 3600,
        ?Iso8601DateTimeVO $startAt = null,
        ?Iso8601DateTimeVO $endAt = null
    ): RecurringTaskConfigVO {
        $now = new Iso8601DateTimeVO;
        $startAt = $startAt ?? $now->addSeconds(-7200); // subHours(2)
        $endAt = $endAt ?? $now->addSeconds(604800); // addDays(7)

        $type = new TaskTypeVO('recurring');

        return new RecurringTaskConfigVO(
            type: $type,
            description: new DescriptionVO('Test recurring task'),
            interval_seconds: new CounterVO($intervalSeconds),
            start_at: $startAt,
            end_at: $endAt,
            max_attempts: new MaxFailedAttemptsVO(3),
        );
    }

    private function findTaskByAliasName(string $aliasName): ?RecurringTask
    {
        $alias = $this->generateAliasFromName($aliasName);

        return $this->repository->findByAlias($alias);
    }

    private function updateTaskStatus(string $aliasName, RecurringTaskStatus $status): void
    {
        $alias = $this->generateAliasFromName($aliasName);
        $task = $this->repository->findByAlias($alias);
        if ($task !== null) {
            $this->repository->updateRaw(
                $task->getId()->getValue(),
                ['status' => $status->value]
            );
        }
    }

    // ==================== TESTS REGISTER ====================

    public function test_register_creates_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig('test-register', 3600);

        $alias = $this->service->register(
            new RecurringTaskFqcnVO(TestRecurringTask::class),
            $payload,
            $config
        );

        $this->assertInstanceOf(TaskAliasVO::class, $alias);
        $this->assertStringContainsString('@', $alias->getValue());

        $task = $this->findTaskByAliasName('test-register');
        $this->assertNotNull($task);
        $this->assertEquals(TestRecurringTask::class, $task->getFqcn());
        $this->assertEquals(RecurringTaskStatus::WAITING, $task->getStatus());
    }

    public function test_register_throws_exception_for_duplicate_alias(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig('test-duplicate', 3600);

        $fqcn = new RecurringTaskFqcnVO(TestRecurringTask::class);
        $this->service->register($fqcn, $payload, $config);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already exists');

        $this->service->register($fqcn, $payload, $config);
    }

    public function test_register_throws_exception_for_invalid_class(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Task must extend AbstractRecurringTask');

        $this->service->register(
            new RecurringTaskFqcnVO('InvalidClass'),
            StrictDataObject::from([]),
            $this->createConfig('test-invalid', 3600)
        );
    }

    // ==================== TESTS RUN ====================

    public function test_run_executes_playing_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfigWithPastStart('test-run', 3600);

        $alias = $this->service->register(
            new RecurringTaskFqcnVO(TestRecurringTask::class),
            $payload,
            $config
        );

        $this->updateTaskStatus('test-run', RecurringTaskStatus::PLAYING);

        $result = $this->service->run($alias);

        $this->assertTrue($result->success);

        $updatedTask = $this->findTaskByAliasName('test-run');
        $this->assertEquals(RecurringTaskStatus::PLAYING, $updatedTask->getStatus());
        $this->assertNotNull($updatedTask->getLastRunAt());
    }

    public function test_run_returns_false_for_non_existing_task(): void
    {
        $alias = $this->generateAliasFromName('non-existent');
        $result = $this->service->run($alias);
        $this->assertFalse($result->success);
        $this->assertEquals('Task not found', $result->error);
    }

    public function test_run_returns_false_for_waiting_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig('test-waiting', 3600);

        $alias = $this->service->register(
            new RecurringTaskFqcnVO(TestRecurringTask::class),
            $payload,
            $config
        );

        $result = $this->service->run($alias);
        $this->assertFalse($result->success);
        $this->assertStringContainsString('not in PLAYING state', $result->error->getValue());
    }

    public function test_run_returns_false_for_canceled_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfigWithPastStart('test-canceled-run', 3600);

        $alias = $this->service->register(
            new RecurringTaskFqcnVO(TestRecurringTask::class),
            $payload,
            $config
        );

        $this->service->cancel($alias, new DescriptionVO('Test cancellation'));

        $result = $this->service->run($alias);
        $this->assertFalse($result->success);
    }

    public function test_run_handles_task_failure(): void
    {
        $payload = StrictDataObject::from(['should_fail' => true]);
        $config = $this->createConfigWithPastStart('test-failing', 3600);

        $alias = $this->service->register(
            new RecurringTaskFqcnVO(FailingRecurringTask::class),
            $payload,
            $config
        );

        $this->updateTaskStatus('test-failing', RecurringTaskStatus::PLAYING);

        $result = $this->service->run($alias);

        $this->assertFalse($result->success);

        $updatedTask = $this->findTaskByAliasName('test-failing');
        $this->assertEquals(RecurringTaskStatus::PLAYING, $updatedTask->getStatus());
        $this->assertNotNull($updatedTask->getLastRunAt());
    }

    // ==================== TESTS CANCEL ====================

    public function test_cancel_cancels_recurring_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfigWithPastStart('test-cancel', 3600);

        $alias = $this->service->register(
            new RecurringTaskFqcnVO(TestRecurringTask::class),
            $payload,
            $config
        );

        $this->service->cancel($alias, new DescriptionVO('Test cancellation'));

        $task = $this->findTaskByAliasName('test-cancel');
        $this->assertNotNull($task);
        $this->assertEquals(RecurringTaskStatus::CANCELED, $task->getStatus());
        $this->assertNotNull($task->getFinishedAt());
        $this->assertNotNull($task->getCancelledAt());
    }

    public function test_cancel_returns_false_for_non_existing_task(): void
    {
        $alias = $this->generateAliasFromName('non-existent');
        $result = $this->service->cancel($alias, new DescriptionVO('Test'));
        $this->assertFalse($result);
    }

    public function test_cancel_can_cancel_playing_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfigWithPastStart('test-cancel-playing', 3600);

        $alias = $this->service->register(
            new RecurringTaskFqcnVO(TestRecurringTask::class),
            $payload,
            $config
        );

        $this->updateTaskStatus('test-cancel-playing', RecurringTaskStatus::PLAYING);

        $this->service->cancel($alias, new DescriptionVO('Cancelled while playing'));

        $updatedTask = $this->findTaskByAliasName('test-cancel-playing');
        $this->assertEquals(RecurringTaskStatus::CANCELED, $updatedTask->getStatus());
        $this->assertNotNull($updatedTask->getCancelledAt());
    }

    public function test_cancel_can_cancel_waiting_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig('test-cancel-waiting', 3600);

        $alias = $this->service->register(
            new RecurringTaskFqcnVO(TestRecurringTask::class),
            $payload,
            $config
        );

        $this->service->cancel($alias, new DescriptionVO('Cancelled while waiting'));

        $updatedTask = $this->findTaskByAliasName('test-cancel-waiting');
        $this->assertEquals(RecurringTaskStatus::CANCELED, $updatedTask->getStatus());
        $this->assertNotNull($updatedTask->getCancelledAt());
    }

    // ==================== TESTS FIND CANCELED ====================

    public function test_find_canceled_returns_canceled_tasks(): void
    {
        $payload = StrictDataObject::from(['test' => 'cancelled']);
        $config = $this->createConfig('test-find-canceled', 3600);

        $alias = $this->service->register(
            new RecurringTaskFqcnVO(TestRecurringTask::class),
            $payload,
            $config
        );

        $this->service->cancel($alias, new DescriptionVO('Test cancellation'));

        $cancelled = $this->service->findCanceled();

        $this->assertCount(1, $cancelled);
        $this->assertEquals($alias->getValue(), $cancelled->first()->alias->getValue());
        $this->assertEquals(RecurringTaskStatus::CANCELED, $cancelled->first()->status);
    }

    public function test_find_canceled_returns_empty_when_no_canceled_tasks(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig('test-no-canceled', 3600);

        $this->service->register(
            new RecurringTaskFqcnVO(TestRecurringTask::class),
            $payload,
            $config
        );

        $cancelled = $this->service->findCanceled();
        $this->assertCount(0, $cancelled);
    }

    public function test_find_canceled_excludes_finished_tasks(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);

        $config1 = $this->createConfig('finished-task', 3600);
        $alias1 = $this->service->register(
            new RecurringTaskFqcnVO(TestRecurringTask::class),
            $payload,
            $config1
        );
        $this->service->finish($alias1);

        $config2 = $this->createConfig('canceled-task', 3600);
        $alias2 = $this->service->register(
            new RecurringTaskFqcnVO(TestRecurringTask::class),
            $payload,
            $config2
        );
        $this->service->cancel($alias2, new DescriptionVO('Test cancellation'));

        $cancelled = $this->service->findCanceled();
        $this->assertCount(1, $cancelled);
        $this->assertEquals($alias2->getValue(), $cancelled->first()->alias->getValue());
    }

    public function test_find_canceled_with_limit(): void
    {
        $payload = StrictDataObject::from(['test' => 'cancelled']);

        for ($i = 1; $i <= 3; $i++) {
            $alias = $this->service->register(
                new RecurringTaskFqcnVO(TestRecurringTask::class),
                $payload,
                $this->createConfig("canceled-limit-{$i}", 3600)
            );
            $this->service->cancel($alias, new DescriptionVO("Test cancellation {$i}"));
        }

        $cancelled = $this->service->findCanceled(new LimitVO(2));
        $this->assertCount(2, $cancelled);
    }

    // ==================== TESTS EXTEND END AT ====================

    public function test_extend_end_at_updates_end_at(): void
    {
        $now = new Iso8601DateTimeVO;
        $originalEndAt = $now->addSeconds(604800); // addDays(7)
        $config = $this->createConfig('test-extend-end', 3600, null, $originalEndAt);

        $alias = $this->service->register(
            new RecurringTaskFqcnVO(TestRecurringTask::class),
            StrictDataObject::from(['test' => 'data']),
            $config
        );

        $newEndAt = $now->addSeconds(1209600); // addDays(14)
        $this->service->extendEndAt($alias, $newEndAt);

        $updatedTask = $this->findTaskByAliasName('test-extend-end');
        $this->assertEquals(
            $newEndAt->format('Y-m-d H:i'),
            $updatedTask->getEndAt()->format('Y-m-d H:i')
        );
    }

    public function test_extend_end_at_returns_false_for_non_existing_task(): void
    {
        $alias = $this->generateAliasFromName('non-existent');
        $result = $this->service->extendEndAt($alias, new Iso8601DateTimeVO);
        $this->assertFalse($result);
    }

    public function test_extend_end_at_returns_false_for_canceled_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig('test-extend-canceled', 3600);

        $alias = $this->service->register(
            new RecurringTaskFqcnVO(TestRecurringTask::class),
            $payload,
            $config
        );

        $this->service->cancel($alias, new DescriptionVO('Test cancellation'));

        $result = $this->service->extendEndAt($alias, new Iso8601DateTimeVO);
        $this->assertFalse($result);
    }

    // ==================== TESTS PAUSE/RESUME/FINISH ====================

    public function test_pause_moves_task_to_paused(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfigWithPastStart('test-pause', 3600);

        $alias = $this->service->register(
            new RecurringTaskFqcnVO(TestRecurringTask::class),
            $payload,
            $config
        );

        $this->updateTaskStatus('test-pause', RecurringTaskStatus::PLAYING);
        $this->service->pause($alias);

        $updatedTask = $this->findTaskByAliasName('test-pause');
        $this->assertEquals(RecurringTaskStatus::PAUSED, $updatedTask->getStatus());
    }

    public function test_pause_returns_false_for_non_playing_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig('test-pause-error', 3600);

        $alias = $this->service->register(
            new RecurringTaskFqcnVO(TestRecurringTask::class),
            $payload,
            $config
        );

        $result = $this->service->pause($alias);
        $this->assertFalse($result);
    }

    public function test_pause_returns_false_for_canceled_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfigWithPastStart('test-pause-canceled', 3600);

        $alias = $this->service->register(
            new RecurringTaskFqcnVO(TestRecurringTask::class),
            $payload,
            $config
        );

        $this->updateTaskStatus('test-pause-canceled', RecurringTaskStatus::PLAYING);
        $this->service->cancel($alias, new DescriptionVO('Test cancellation'));

        $result = $this->service->pause($alias);
        $this->assertFalse($result);
    }

    public function test_resume_moves_task_to_playing(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfigWithPastStart('test-resume', 3600);

        $alias = $this->service->register(
            new RecurringTaskFqcnVO(TestRecurringTask::class),
            $payload,
            $config
        );

        $this->updateTaskStatus('test-resume', RecurringTaskStatus::PLAYING);
        $this->updateTaskStatus('test-resume', RecurringTaskStatus::PAUSED);

        $this->service->resume($alias);

        $updatedTask = $this->findTaskByAliasName('test-resume');
        $this->assertEquals(RecurringTaskStatus::PLAYING, $updatedTask->getStatus());
    }

    public function test_resume_returns_false_for_non_paused_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig('test-resume-error', 3600);

        $alias = $this->service->register(
            new RecurringTaskFqcnVO(TestRecurringTask::class),
            $payload,
            $config
        );

        $result = $this->service->resume($alias);
        $this->assertFalse($result);
    }

    public function test_finish_moves_task_to_finished(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig('test-finish', 3600);

        $alias = $this->service->register(
            new RecurringTaskFqcnVO(TestRecurringTask::class),
            $payload,
            $config
        );

        $this->service->finish($alias);

        $updatedTask = $this->findTaskByAliasName('test-finish');
        $this->assertEquals(RecurringTaskStatus::FINISHED, $updatedTask->getStatus());
        $this->assertNotNull($updatedTask->getFinishedAt());
    }

    public function test_finish_returns_false_for_canceled_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig('test-finish-canceled', 3600);

        $alias = $this->service->register(
            new RecurringTaskFqcnVO(TestRecurringTask::class),
            $payload,
            $config
        );

        $this->service->cancel($alias, new DescriptionVO('Test cancellation'));

        $result = $this->service->finish($alias);
        $this->assertFalse($result);
    }

    // ==================== TESTS CHANGE INTERVAL ====================

    public function test_change_interval_updates_interval(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig('test-interval', 3600);

        $alias = $this->service->register(
            new RecurringTaskFqcnVO(TestRecurringTask::class),
            $payload,
            $config
        );

        $this->service->changeInterval($alias, new DurationVO(7200));

        $updatedTask = $this->findTaskByAliasName('test-interval');
        $this->assertEquals(7200, $updatedTask->getIntervalSeconds()->getValue());
    }

    public function test_change_interval_returns_false_for_canceled_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig('test-interval-canceled', 3600);

        $alias = $this->service->register(
            new RecurringTaskFqcnVO(TestRecurringTask::class),
            $payload,
            $config
        );

        $this->service->cancel($alias, new DescriptionVO('Test cancellation'));

        $result = $this->service->changeInterval($alias, new DurationVO(7200));
        $this->assertFalse($result);
    }

    // ==================== TESTS FIND ====================

    public function test_find_returns_task_record(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig('test-find', 3600);

        $alias = $this->service->register(
            new RecurringTaskFqcnVO(TestRecurringTask::class),
            $payload,
            $config
        );

        $record = $this->service->find($alias);

        $this->assertInstanceOf(RecurringTaskRecord::class, $record);
        $this->assertEquals($alias->getValue(), $record->alias->getValue());
        $this->assertEquals(TestRecurringTask::class, $record->fqcn->getValue());
    }

    public function test_find_returns_canceled_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig('test-find-canceled', 3600);

        $alias = $this->service->register(
            new RecurringTaskFqcnVO(TestRecurringTask::class),
            $payload,
            $config
        );

        $this->service->cancel($alias, new DescriptionVO('Test cancellation'));

        $record = $this->service->find($alias);

        $this->assertNotNull($record);
        $this->assertEquals($alias->getValue(), $record->alias->getValue());
        $this->assertEquals(RecurringTaskStatus::CANCELED, $record->status);
    }

    public function test_find_returns_null_for_non_existing_task(): void
    {
        $alias = $this->generateAliasFromName('non-existent');
        $record = $this->service->find($alias);
        $this->assertNull($record);
    }

    // ==================== TESTS EXISTS ====================

    public function test_exists_returns_true_for_canceled_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig('test-exists-canceled', 3600);

        $alias = $this->service->register(
            new RecurringTaskFqcnVO(TestRecurringTask::class),
            $payload,
            $config
        );

        $this->service->cancel($alias, new DescriptionVO('Test cancellation'));

        $this->assertTrue($this->service->exists($alias));
    }

    public function test_exists_returns_false_for_non_existing_task(): void
    {
        $alias = $this->generateAliasFromName('non-existent');
        $this->assertFalse($this->service->exists($alias));
    }

    // ==================== TESTS DELETE ====================

    public function test_delete_removes_canceled_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig('test-delete-canceled', 3600);

        $alias = $this->service->register(
            new RecurringTaskFqcnVO(TestRecurringTask::class),
            $payload,
            $config
        );

        $this->service->cancel($alias, new DescriptionVO('Test cancellation'));

        $this->service->delete($alias);

        $task = $this->findTaskByAliasName('test-delete-canceled');
        $this->assertNull($task);
    }

    public function test_delete_returns_false_for_non_existing_task(): void
    {
        $alias = $this->generateAliasFromName('non-existent');
        $result = $this->service->delete($alias);
        $this->assertFalse($result);
    }

    // ==================== TESTS COUNTS ====================

    public function test_count_returns_total_tasks_including_canceled(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);

        $config1 = $this->createConfig('count-1', 3600);
        $alias1 = $this->service->register(
            new RecurringTaskFqcnVO(TestRecurringTask::class),
            $payload,
            $config1
        );
        $this->service->cancel($alias1, new DescriptionVO('Test cancellation'));

        $this->service->register(
            new RecurringTaskFqcnVO(TestRecurringTask::class),
            $payload,
            $this->createConfig('count-2', 3600)
        );

        $this->assertEquals(2, $this->service->count()->getValue());
    }

    public function test_count_waiting_excludes_canceled_tasks(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);

        $config1 = $this->createConfig('canceled-count', 3600);
        $alias1 = $this->service->register(
            new RecurringTaskFqcnVO(TestRecurringTask::class),
            $payload,
            $config1
        );
        $this->service->cancel($alias1, new DescriptionVO('Test cancellation'));

        $this->service->register(
            new RecurringTaskFqcnVO(TestRecurringTask::class),
            $payload,
            $this->createConfig('waiting-count', 3600)
        );

        $this->assertEquals(1, $this->service->countWaiting()->getValue());
    }

    // ==================== TESTS PROCESS ====================

    public function test_process_executes_ready_tasks(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $payload = StrictDataObject::from(['test' => "task-{$i}"]);
            $config = $this->createConfigWithPastStart("process-{$i}", 3600);
            $alias = $this->service->register(
                new RecurringTaskFqcnVO(TestRecurringTask::class),
                $payload,
                $config
            );
            $this->updateTaskStatus("process-{$i}", RecurringTaskStatus::PLAYING);
        }

        $result = $this->service->process();

        $this->assertEquals(3, $result->success->getValue());
        $this->assertEquals(0, $result->failed->getValue());
        $this->assertEquals(0, $result->finished->getValue());
    }

    public function test_process_respects_limit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $payload = StrictDataObject::from(['test' => "task-{$i}"]);
            $config = $this->createConfigWithPastStart("process-limit-{$i}", 3600);
            $alias = $this->service->register(
                new RecurringTaskFqcnVO(TestRecurringTask::class),
                $payload,
                $config
            );
            $this->updateTaskStatus("process-limit-{$i}", RecurringTaskStatus::PLAYING);
        }

        $result = $this->service->process(new LimitVO(3));

        $this->assertEquals(3, $result->success->getValue());
        $this->assertEquals(0, $result->failed->getValue());
        $this->assertEquals(0, $result->finished->getValue());
    }
}
