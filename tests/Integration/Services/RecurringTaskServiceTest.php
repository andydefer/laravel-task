<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Services;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Task\Configs\RecurringTaskConfig;
use AndyDefer\Task\Contracts\Services\RecurringTaskServiceInterface;
use AndyDefer\Task\Enums\RecurringTaskStatus;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Repositories\RecurringTaskRepository;
use AndyDefer\Task\Repositories\TaskExecutionDebugRepository;
use AndyDefer\Task\Services\RecurringTaskService;
use AndyDefer\Task\Tests\Fixtures\Tasks\FailingRecurringTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestRecurringTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;

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
        $this->repository = new RecurringTaskRepository($this->debugRepository);

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
        Carbon::setTestNow();
    }

    // ==================== HELPERS ====================

    private function createConfig(
        string $alias,
        int $intervalSeconds = 3600,
        ?Carbon $startAt = null,
        ?Carbon $endAt = null
    ): RecurringTaskConfig {
        // ✅ Pour les tests qui veulent garder le statut WAITING, start_at doit être dans le futur
        $startAt = $startAt ?? now()->addHours(2);
        $endAt = $endAt ?? now()->addDays(7);

        return new RecurringTaskConfig(
            alias: new TaskSignatureVO($alias),
            description: 'Test recurring task',
            interval_seconds: new CounterVO($intervalSeconds),
            start_at: new Iso8601DateTimeVO($startAt->toIso8601String()),
            end_at: new Iso8601DateTimeVO($endAt->toIso8601String()),
            max_attempts: new CounterVO(3),
        );
    }

    private function createConfigWithPastStart(
        string $alias,
        int $intervalSeconds = 3600,
        ?Carbon $startAt = null,
        ?Carbon $endAt = null
    ): RecurringTaskConfig {
        // ✅ Pour les tests qui veulent que start_at soit dans le passé
        $startAt = $startAt ?? now()->subHours(2);
        $endAt = $endAt ?? now()->addDays(7);

        return new RecurringTaskConfig(
            alias: new TaskSignatureVO($alias),
            description: 'Test recurring task',
            interval_seconds: new CounterVO($intervalSeconds),
            start_at: new Iso8601DateTimeVO($startAt->toIso8601String()),
            end_at: new Iso8601DateTimeVO($endAt->toIso8601String()),
            max_attempts: new CounterVO(3),
        );
    }

    // ==================== TESTS REGISTER ====================

    public function test_register_creates_task(): void
    {
        // ✅ start_at dans le futur pour garder le statut WAITING
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig('test-register', 3600, now()->addHours(2));

        $alias = $this->service->register(
            TestRecurringTask::class,
            $payload,
            $config
        );

        $this->assertInstanceOf(TaskSignatureVO::class, $alias);
        $this->assertEquals('test-register', $alias->value);

        $task = $this->repository->findByAlias('test-register');
        $this->assertNotNull($task);
        $this->assertEquals(TestRecurringTask::class, $task->getFqcn());
        $this->assertEquals(RecurringTaskStatus::WAITING, $task->getStatus());
    }

    public function test_register_throws_exception_for_duplicate_alias(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig('test-duplicate', 3600, now()->addHours(2));

        $this->service->register(TestRecurringTask::class, $payload, $config);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already exists');

        $this->service->register(TestRecurringTask::class, $payload, $config);
    }

    public function test_register_throws_exception_for_invalid_class(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Task must extend AbstractRecurringTask');

        $this->service->register(
            'InvalidClass',
            StrictDataObject::from([]),
            $this->createConfig('test-invalid', 3600, now()->addHours(2))
        );
    }

    // ==================== TESTS RUN ====================

    public function test_run_executes_playing_task(): void
    {
        // ✅ start_at dans le passé pour que freshState passe la tâche en PLAYING
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfigWithPastStart('test-run', 3600, now()->subHours(2));

        $alias = $this->service->register(TestRecurringTask::class, $payload, $config);

        // ✅ Déjà en PLAYING après freshState, mais on s'assure
        $task = $this->repository->findByAlias($alias->value);
        $task->update(['status' => RecurringTaskStatus::PLAYING->value]);

        $result = $this->service->run($alias);

        $this->assertTrue($result);

        $updatedTask = $this->repository->findByAlias($alias->value);
        $this->assertEquals(RecurringTaskStatus::PLAYING, $updatedTask->getStatus());
        $this->assertNotNull($updatedTask->getLastRunAt());
    }

    public function test_run_returns_false_for_non_existing_task(): void
    {
        $result = $this->service->run(new TaskSignatureVO('non-existent'));
        $this->assertFalse($result);
    }

    public function test_run_returns_false_for_waiting_task(): void
    {
        // ✅ start_at dans le futur pour garder le statut WAITING
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig('test-waiting', 3600, now()->addHours(2));

        $alias = $this->service->register(TestRecurringTask::class, $payload, $config);

        $result = $this->service->run($alias);
        $this->assertFalse($result);
    }

    public function test_run_returns_false_for_canceled_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfigWithPastStart('test-canceled-run', 3600, now()->subHours(2));

        $alias = $this->service->register(TestRecurringTask::class, $payload, $config);
        $this->service->cancel($alias, 'Test cancellation');

        $result = $this->service->run($alias);
        $this->assertFalse($result);
    }

    public function test_run_handles_task_failure(): void
    {
        $payload = StrictDataObject::from(['should_fail' => true]);
        $config = $this->createConfigWithPastStart('test-failing', 3600, now()->subHours(2));

        $alias = $this->service->register(FailingRecurringTask::class, $payload, $config);

        $task = $this->repository->findByAlias($alias->value);
        $task->update(['status' => RecurringTaskStatus::PLAYING->value]);

        $result = $this->service->run($alias);

        $this->assertFalse($result);

        $updatedTask = $this->repository->findByAlias($alias->value);
        $this->assertEquals(RecurringTaskStatus::PLAYING, $updatedTask->getStatus());
        $this->assertNotNull($updatedTask->getLastRunAt());
    }

    // ==================== TESTS CANCEL ====================

    public function test_cancel_cancels_recurring_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfigWithPastStart('test-cancel', 3600, now()->subHours(2));

        $alias = $this->service->register(TestRecurringTask::class, $payload, $config);

        $this->service->cancel($alias, 'Test cancellation');

        $task = $this->repository->findByAlias($alias->value);
        $this->assertNotNull($task);
        $this->assertEquals(RecurringTaskStatus::CANCELED, $task->getStatus());
        $this->assertNotNull($task->getFinishedAt());
        $this->assertNotNull($task->getCancelledAt());
    }

    public function test_cancel_throws_exception_for_non_existing_task(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Task not found');

        $this->service->cancel(new TaskSignatureVO('non-existent'));
    }

    public function test_cancel_can_cancel_playing_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfigWithPastStart('test-cancel-playing', 3600, now()->subHours(2));

        $alias = $this->service->register(TestRecurringTask::class, $payload, $config);

        $task = $this->repository->findByAlias($alias->value);
        $task->update(['status' => RecurringTaskStatus::PLAYING->value]);

        $this->service->cancel($alias, 'Cancelled while playing');

        $updatedTask = $this->repository->findByAlias($alias->value);
        $this->assertEquals(RecurringTaskStatus::CANCELED, $updatedTask->getStatus());
        $this->assertNotNull($updatedTask->getCancelledAt());
    }

    public function test_cancel_can_cancel_waiting_task(): void
    {
        // ✅ start_at dans le futur pour garder le statut WAITING
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig('test-cancel-waiting', 3600, now()->addHours(2));

        $alias = $this->service->register(TestRecurringTask::class, $payload, $config);

        $this->service->cancel($alias, 'Cancelled while waiting');

        $updatedTask = $this->repository->findByAlias($alias->value);
        $this->assertEquals(RecurringTaskStatus::CANCELED, $updatedTask->getStatus());
        $this->assertNotNull($updatedTask->getCancelledAt());
    }

    // ==================== TESTS FIND CANCELED ====================

    public function test_find_canceled_returns_canceled_tasks(): void
    {
        $payload = StrictDataObject::from(['test' => 'cancelled']);
        $config = $this->createConfig('test-find-canceled', 3600, now()->addHours(2));

        $alias = $this->service->register(TestRecurringTask::class, $payload, $config);
        $this->service->cancel($alias, 'Test cancellation');

        $cancelled = $this->service->findCanceled();

        $this->assertCount(1, $cancelled);
        $this->assertEquals($alias->value, $cancelled[0]->alias->value);
        $this->assertEquals(RecurringTaskStatus::CANCELED, $cancelled[0]->status);
    }

    public function test_find_canceled_returns_empty_when_no_canceled_tasks(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig('test-no-canceled', 3600, now()->addHours(2));

        $this->service->register(TestRecurringTask::class, $payload, $config);

        $cancelled = $this->service->findCanceled();

        $this->assertCount(0, $cancelled);
    }

    public function test_find_canceled_excludes_finished_tasks(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);

        $config1 = $this->createConfig('finished-task', 3600, now()->addHours(2));
        $alias1 = $this->service->register(TestRecurringTask::class, $payload, $config1);
        $this->service->finish($alias1);

        $config2 = $this->createConfig('canceled-task', 3600, now()->addHours(2));
        $alias2 = $this->service->register(TestRecurringTask::class, $payload, $config2);
        $this->service->cancel($alias2, 'Test cancellation');

        $cancelled = $this->service->findCanceled();

        $this->assertCount(1, $cancelled);
        $this->assertEquals($alias2->value, $cancelled[0]->alias->value);
        $this->assertNotEquals($alias1->value, $cancelled[0]->alias->value);
    }

    public function test_find_canceled_with_limit(): void
    {
        $payload = StrictDataObject::from(['test' => 'cancelled']);

        $config1 = $this->createConfig('canceled-limit-1', 3600, now()->addHours(2));
        $alias1 = $this->service->register(TestRecurringTask::class, $payload, $config1);
        $this->service->cancel($alias1, 'Test cancellation 1');

        $config2 = $this->createConfig('canceled-limit-2', 3600, now()->addHours(2));
        $alias2 = $this->service->register(TestRecurringTask::class, $payload, $config2);
        $this->service->cancel($alias2, 'Test cancellation 2');

        $config3 = $this->createConfig('canceled-limit-3', 3600, now()->addHours(2));
        $alias3 = $this->service->register(TestRecurringTask::class, $payload, $config3);
        $this->service->cancel($alias3, 'Test cancellation 3');

        $cancelled = $this->service->findCanceled(2);

        $this->assertCount(2, $cancelled);
    }

    // ==================== TESTS COUNT CANCELED ====================

    public function test_count_canceled_returns_count(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);

        $config1 = $this->createConfig('count-canceled-1', 3600, now()->addHours(2));
        $alias1 = $this->service->register(TestRecurringTask::class, $payload, $config1);
        $this->service->cancel($alias1, 'Test cancellation 1');

        $config2 = $this->createConfig('count-canceled-2', 3600, now()->addHours(2));
        $alias2 = $this->service->register(TestRecurringTask::class, $payload, $config2);
        $this->service->cancel($alias2, 'Test cancellation 2');

        $config3 = $this->createConfig('count-canceled-3', 3600, now()->addHours(2));
        $this->service->register(TestRecurringTask::class, $payload, $config3);

        $this->assertEquals(2, $this->service->countCanceled());
    }

    public function test_count_canceled_returns_zero_when_no_canceled_tasks(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig('count-canceled-zero', 3600, now()->addHours(2));

        $this->service->register(TestRecurringTask::class, $payload, $config);

        $this->assertEquals(0, $this->service->countCanceled());
    }

    // ==================== TESTS EXTEND END AT ====================

    public function test_extend_end_at_updates_end_at(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $originalEndAt = now()->addDays(7);
        $config = $this->createConfig('test-extend-end', 3600, now()->addHours(2), $originalEndAt);

        $alias = $this->service->register(TestRecurringTask::class, $payload, $config);

        $newEndAt = now()->addDays(14);
        $this->service->extendEndAt($alias, new Iso8601DateTimeVO($newEndAt->toIso8601String()));

        $updatedTask = $this->repository->findByAlias($alias->value);
        $this->assertEquals(
            $newEndAt->format('Y-m-d H:i'),
            $updatedTask->getEndAtVO()->toDateTime()->format('Y-m-d H:i')
        );
    }

    public function test_extend_end_at_throws_exception_for_non_existing_task(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Task not found');

        $this->service->extendEndAt(
            new TaskSignatureVO('non-existent'),
            new Iso8601DateTimeVO(now()->addDays(7)->toIso8601String())
        );
    }

    public function test_extend_end_at_throws_exception_for_canceled_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig('test-extend-canceled', 3600, now()->addHours(2));

        $alias = $this->service->register(TestRecurringTask::class, $payload, $config);
        $this->service->cancel($alias, 'Test cancellation');

        $this->expectException(\RuntimeException::class);

        $this->service->extendEndAt(
            $alias,
            new Iso8601DateTimeVO(now()->addDays(7)->toIso8601String())
        );
    }

    // ==================== TESTS PAUSE/RESUME/FINISH ====================

    public function test_pause_moves_task_to_paused(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfigWithPastStart('test-pause', 3600, now()->subHours(2));

        $alias = $this->service->register(TestRecurringTask::class, $payload, $config);

        $task = $this->repository->findByAlias($alias->value);
        $task->update(['status' => RecurringTaskStatus::PLAYING->value]);

        $this->service->pause($alias);

        $updatedTask = $this->repository->findByAlias($alias->value);
        $this->assertEquals(RecurringTaskStatus::PAUSED, $updatedTask->getStatus());
    }

    public function test_pause_throws_exception_for_non_playing_task(): void
    {
        // ✅ start_at dans le futur pour garder le statut WAITING
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig('test-pause-error', 3600, now()->addHours(2));

        $alias = $this->service->register(TestRecurringTask::class, $payload, $config);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not in PLAYING state');

        $this->service->pause($alias);
    }

    public function test_pause_throws_exception_for_canceled_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfigWithPastStart('test-pause-canceled', 3600, now()->subHours(2));

        $alias = $this->service->register(TestRecurringTask::class, $payload, $config);
        $this->service->cancel($alias, 'Test cancellation');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not in PLAYING state');

        $this->service->pause($alias);
    }

    public function test_resume_moves_task_to_waiting(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfigWithPastStart('test-resume', 3600, now()->subHours(2));

        $alias = $this->service->register(TestRecurringTask::class, $payload, $config);

        $task = $this->repository->findByAlias($alias->value);
        $task->update(['status' => RecurringTaskStatus::PLAYING->value]);
        $task->update(['status' => RecurringTaskStatus::PAUSED->value]);

        $this->service->resume($alias);

        $updatedTask = $this->repository->findByAlias($alias->value);

        // ✅ Corrigé : resume() doit passer en PLAYING, pas WAITING
        $this->assertEquals(RecurringTaskStatus::PLAYING, $updatedTask->getStatus());
    }

    public function test_resume_throws_exception_for_non_paused_task(): void
    {
        // ✅ start_at dans le futur pour garder le statut WAITING
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig('test-resume-error', 3600, now()->addHours(2));

        $alias = $this->service->register(TestRecurringTask::class, $payload, $config);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not in PAUSED state');

        $this->service->resume($alias);
    }

    public function test_finish_moves_task_to_finished(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig('test-finish', 3600, now()->addHours(2));

        $alias = $this->service->register(TestRecurringTask::class, $payload, $config);

        $this->service->finish($alias);

        $updatedTask = $this->repository->findByAlias($alias->value);
        $this->assertEquals(RecurringTaskStatus::FINISHED, $updatedTask->getStatus());
        $this->assertNotNull($updatedTask->getFinishedAt());
    }

    public function test_finish_throws_exception_for_canceled_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig('test-finish-canceled', 3600, now()->addHours(2));

        $alias = $this->service->register(TestRecurringTask::class, $payload, $config);
        $this->service->cancel($alias, 'Test cancellation');

        $this->expectException(\RuntimeException::class);

        $this->service->finish($alias);
    }

    // ==================== TESTS ADVANCE/POSTPONE START ====================

    public function test_advance_start_at_updates_start_at(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig('test-advance', 3600, now()->addHours(2));

        $alias = $this->service->register(TestRecurringTask::class, $payload, $config);

        $newStartAt = now()->addHours(1);
        $this->service->advanceStartAt($alias, new Iso8601DateTimeVO($newStartAt->toIso8601String()));

        $updatedTask = $this->repository->findByAlias($alias->value);
        $this->assertEquals(
            $newStartAt->format('Y-m-d H:i'),
            $updatedTask->getStartAt()->toDateTime()->format('Y-m-d H:i')
        );
    }

    public function test_advance_start_at_throws_exception_for_canceled_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig('test-advance-canceled', 3600, now()->addHours(2));

        $alias = $this->service->register(TestRecurringTask::class, $payload, $config);
        $this->service->cancel($alias, 'Test cancellation');

        $this->expectException(\RuntimeException::class);

        $this->service->advanceStartAt(
            $alias,
            new Iso8601DateTimeVO(now()->addHours(1)->toIso8601String())
        );
    }

    public function test_postpone_start_at_updates_start_at(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig('test-postpone', 3600, now()->addHours(2));

        $alias = $this->service->register(TestRecurringTask::class, $payload, $config);

        $newStartAt = now()->addDays(5);
        $this->service->postponeStartAt($alias, new Iso8601DateTimeVO($newStartAt->toIso8601String()));

        $updatedTask = $this->repository->findByAlias($alias->value);
        $this->assertEquals(
            $newStartAt->format('Y-m-d H:i'),
            $updatedTask->getStartAt()->toDateTime()->format('Y-m-d H:i')
        );
    }

    // ==================== TESTS CHANGE INTERVAL ====================

    public function test_change_interval_updates_interval(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig('test-interval', 3600, now()->addHours(2));

        $alias = $this->service->register(TestRecurringTask::class, $payload, $config);

        $this->service->changeInterval($alias, 7200);

        $updatedTask = $this->repository->findByAlias($alias->value);
        $this->assertEquals(7200, $updatedTask->getIntervalSeconds()->value);
    }

    public function test_change_interval_throws_exception_for_canceled_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig('test-interval-canceled', 3600, now()->addHours(2));

        $alias = $this->service->register(TestRecurringTask::class, $payload, $config);
        $this->service->cancel($alias, 'Test cancellation');

        $this->expectException(\RuntimeException::class);

        $this->service->changeInterval($alias, 7200);
    }

    // ==================== TESTS FIND ====================

    public function test_find_returns_task_record(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig('test-find', 3600, now()->addHours(2));

        $alias = $this->service->register(TestRecurringTask::class, $payload, $config);

        $record = $this->service->find($alias);

        $this->assertInstanceOf(RecurringTaskRecord::class, $record);
        $this->assertEquals($alias->value, $record->alias->value);
        $this->assertEquals(TestRecurringTask::class, $record->fqcn);
    }

    public function test_find_returns_canceled_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig('test-find-canceled', 3600, now()->addHours(2));

        $alias = $this->service->register(TestRecurringTask::class, $payload, $config);
        $this->service->cancel($alias, 'Test cancellation');

        $record = $this->service->find($alias);

        $this->assertNotNull($record);
        $this->assertEquals($alias->value, $record->alias->value);
        $this->assertEquals(RecurringTaskStatus::CANCELED, $record->status);
    }

    public function test_find_returns_null_for_non_existing_task(): void
    {
        $record = $this->service->find(new TaskSignatureVO('non-existent'));
        $this->assertNull($record);
    }

    // ==================== TESTS FIND STATUS ====================

    public function test_find_waiting_returns_only_waiting_tasks(): void
    {
        // ✅ start_at dans le futur pour garder le statut WAITING
        $payload = StrictDataObject::from(['test' => 'data']);
        $config1 = $this->createConfig('test-waiting-1', 3600, now()->addHours(2));
        $alias1 = $this->service->register(TestRecurringTask::class, $payload, $config1);

        $config2 = $this->createConfigWithPastStart('test-waiting-2', 3600, now()->subHours(2));
        $alias2 = $this->service->register(TestRecurringTask::class, $payload, $config2);
        $task = $this->repository->findByAlias($alias2->value);
        $task->update(['status' => RecurringTaskStatus::PLAYING->value]);

        $waitings = $this->service->findWaiting();

        $this->assertCount(1, $waitings);
        $this->assertEquals($alias1->value, $waitings[0]->alias->value);
    }

    public function test_find_waiting_excludes_canceled_tasks(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);

        $config1 = $this->createConfig('canceled-task', 3600, now()->addHours(2));
        $alias1 = $this->service->register(TestRecurringTask::class, $payload, $config1);
        $this->service->cancel($alias1, 'Test cancellation');

        $config2 = $this->createConfig('waiting-task', 3600, now()->addHours(2));
        $alias2 = $this->service->register(TestRecurringTask::class, $payload, $config2);

        $waitings = $this->service->findWaiting();

        $this->assertCount(1, $waitings);
        $this->assertEquals($alias2->value, $waitings[0]->alias->value);
        $this->assertNotEquals($alias1->value, $waitings[0]->alias->value);
    }

    public function test_find_playing_excludes_canceled_tasks(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);

        $config1 = $this->createConfigWithPastStart('canceled-task', 3600, now()->subHours(2));
        $alias1 = $this->service->register(TestRecurringTask::class, $payload, $config1);
        $task = $this->repository->findByAlias($alias1->value);
        $task->update(['status' => RecurringTaskStatus::PLAYING->value]);
        $this->service->cancel($alias1, 'Test cancellation');

        $config2 = $this->createConfigWithPastStart('playing-task', 3600, now()->subHours(2));
        $alias2 = $this->service->register(TestRecurringTask::class, $payload, $config2);
        $task = $this->repository->findByAlias($alias2->value);
        $task->update(['status' => RecurringTaskStatus::PLAYING->value]);

        $playings = $this->service->findPlaying();

        $this->assertCount(1, $playings);
        $this->assertEquals($alias2->value, $playings[0]->alias->value);
        $this->assertNotEquals($alias1->value, $playings[0]->alias->value);
    }

    // ==================== TESTS EXISTS ====================

    public function test_exists_returns_true_for_canceled_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig('test-exists-canceled', 3600, now()->addHours(2));

        $alias = $this->service->register(TestRecurringTask::class, $payload, $config);
        $this->service->cancel($alias, 'Test cancellation');

        $this->assertTrue($this->service->exists($alias));
    }

    public function test_exists_returns_false_for_non_existing_task(): void
    {
        $this->assertFalse($this->service->exists(new TaskSignatureVO('non-existent')));
    }

    // ==================== TESTS DELETE ====================

    public function test_delete_removes_canceled_task(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);
        $config = $this->createConfig('test-delete-canceled', 3600, now()->addHours(2));

        $alias = $this->service->register(TestRecurringTask::class, $payload, $config);
        $this->service->cancel($alias, 'Test cancellation');

        $this->service->delete($alias);

        $task = $this->repository->findByAlias($alias->value);
        $this->assertNull($task);
    }

    public function test_delete_throws_exception_for_non_existing_task(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Task not found');

        $this->service->delete(new TaskSignatureVO('non-existent'));
    }

    // ==================== TESTS COUNTS ====================

    public function test_count_returns_total_tasks_including_canceled(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);

        $config1 = $this->createConfig('count-1', 3600, now()->addHours(2));
        $alias1 = $this->service->register(TestRecurringTask::class, $payload, $config1);
        $this->service->cancel($alias1, 'Test cancellation');

        $this->service->register(TestRecurringTask::class, $payload, $this->createConfig('count-2', 3600, now()->addHours(2)));

        $this->assertEquals(2, $this->service->count());
    }

    public function test_count_waiting_excludes_canceled_tasks(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);

        $config1 = $this->createConfig('canceled-count', 3600, now()->addHours(2));
        $alias1 = $this->service->register(TestRecurringTask::class, $payload, $config1);
        $this->service->cancel($alias1, 'Test cancellation');

        $this->service->register(TestRecurringTask::class, $payload, $this->createConfig('waiting-count', 3600, now()->addHours(2)));

        $this->assertEquals(1, $this->service->countWaiting());
    }

    public function test_count_playing_excludes_canceled_tasks(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);

        $config1 = $this->createConfigWithPastStart('canceled-playing', 3600, now()->subHours(2));
        $alias1 = $this->service->register(TestRecurringTask::class, $payload, $config1);
        $task = $this->repository->findByAlias($alias1->value);
        $task->update(['status' => RecurringTaskStatus::PLAYING->value]);
        $this->service->cancel($alias1, 'Test cancellation');

        $config2 = $this->createConfigWithPastStart('playing-count', 3600, now()->subHours(2));
        $alias2 = $this->service->register(TestRecurringTask::class, $payload, $config2);
        $task = $this->repository->findByAlias($alias2->value);
        $task->update(['status' => RecurringTaskStatus::PLAYING->value]);

        $this->assertEquals(1, $this->service->countPlaying());
    }

    public function test_count_paused_excludes_canceled_tasks(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);

        $config1 = $this->createConfigWithPastStart('canceled-paused', 3600, now()->subHours(2));
        $alias1 = $this->service->register(TestRecurringTask::class, $payload, $config1);
        $task = $this->repository->findByAlias($alias1->value);
        $task->update(['status' => RecurringTaskStatus::PLAYING->value]);
        $task->update(['status' => RecurringTaskStatus::PAUSED->value]);
        $this->service->cancel($alias1, 'Test cancellation');

        $config2 = $this->createConfigWithPastStart('paused-count', 3600, now()->subHours(2));
        $alias2 = $this->service->register(TestRecurringTask::class, $payload, $config2);
        $task = $this->repository->findByAlias($alias2->value);
        $task->update(['status' => RecurringTaskStatus::PLAYING->value]);
        $task->update(['status' => RecurringTaskStatus::PAUSED->value]);

        $this->assertEquals(1, $this->service->countPaused());
    }

    public function test_count_finished_excludes_canceled_tasks(): void
    {
        $payload = StrictDataObject::from(['test' => 'data']);

        $config1 = $this->createConfig('canceled-finished', 3600, now()->addHours(2));
        $alias1 = $this->service->register(TestRecurringTask::class, $payload, $config1);
        $this->service->cancel($alias1, 'Test cancellation');

        $config2 = $this->createConfig('finished-count', 3600, now()->addHours(2));
        $alias2 = $this->service->register(TestRecurringTask::class, $payload, $config2);
        $this->service->finish($alias2);

        $this->assertEquals(1, $this->service->countFinished());
    }

    // ==================== TESTS PROCESS ====================

    public function test_process_executes_ready_tasks(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $payload = StrictDataObject::from(['test' => "task-{$i}"]);
            $config = $this->createConfigWithPastStart("process-{$i}", 3600, now()->subHours(2));
            $alias = $this->service->register(TestRecurringTask::class, $payload, $config);

            // ✅ Passer en PLAYING pour que le processeur les exécute
            $task = $this->repository->findByAlias($alias->value);
            $task->update(['status' => RecurringTaskStatus::PLAYING->value]);
        }

        $result = $this->service->process();

        $this->assertEquals(3, $result->success->value);
        $this->assertEquals(0, $result->failed->value);
        $this->assertEquals(0, $result->finished->value);
    }

    public function test_process_respects_limit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $payload = StrictDataObject::from(['test' => "task-{$i}"]);
            $config = $this->createConfigWithPastStart("process-limit-{$i}", 3600, now()->subHours(2));
            $alias = $this->service->register(TestRecurringTask::class, $payload, $config);

            $task = $this->repository->findByAlias($alias->value);
            $task->update(['status' => RecurringTaskStatus::PLAYING->value]);
        }

        $result = $this->service->process(3);

        $this->assertEquals(3, $result->success->value);
        $this->assertEquals(0, $result->failed->value);
        $this->assertEquals(0, $result->finished->value);
    }
}
