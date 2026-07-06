<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Runners;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Task\Enums\RecurringTaskStatus;
use AndyDefer\Task\Loggers\RecurringTaskLogger;
use AndyDefer\Task\Models\RecurringTask;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Repositories\RecurringTaskRepository;
use AndyDefer\Task\Repositories\TaskExecutionDebugRepository;
use AndyDefer\Task\Runners\RecurringTaskRunner;
use AndyDefer\Task\Tests\Fixtures\Tasks\FailingRecurringTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestRecurringTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\Validators\RecurringTaskValidator;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\UuidVO;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Ramsey\Uuid\Uuid;

final class RecurringTaskRunnerTest extends IntegrationTestCase
{
    use DatabaseMigrations;

    private RecurringTaskRunner $runner;

    private RecurringTaskRepository $repository;

    private TaskExecutionDebugRepository $debugRepository;

    private RecurringTaskValidator $validator;

    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::create(2026, 7, 5, 18, 58, 52));

        $this->debugRepository = new TaskExecutionDebugRepository;
        $this->logger = $this->app->make(LoggerInterface::class);
        $this->repository = new RecurringTaskRepository($this->debugRepository, $this->logger);
        $this->validator = new RecurringTaskValidator;

        $logger = new RecurringTaskLogger(
            logger: App::make(LoggerInterface::class),
            hydration: App::make(HydrationService::class),
        );

        $this->runner = new RecurringTaskRunner(
            validator: $this->validator,
            logger: $logger,
            hydration: App::make(HydrationService::class),
            app: App::getFacadeApplication(),
            repository: $this->repository,
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    // ==================== HELPERS ====================

    private function getUuidForAlias(string $aliasName): string
    {
        return Uuid::uuid4()->toString();
    }

    private function generateAliasFromName(string $name, ?string $uuid = null): TaskAliasVO
    {
        $uuid = $uuid ?? $this->getUuidForAlias($name);

        return new TaskAliasVO(
            'recurring@'.$uuid
        );
    }

    private function createTaskRecord(
        string $aliasName,
        RecurringTaskStatus $status = RecurringTaskStatus::PLAYING,
        ?string $fqcn = null,
        ?Iso8601DateTimeVO $lastRunAt = null
    ): RecurringTaskRecord {
        $fqcn = $fqcn ?? TestRecurringTask::class;
        $now = new Iso8601DateTimeVO;
        $id = $this->getUuidForAlias($aliasName);
        $alias = $this->generateAliasFromName($aliasName, $id);

        $startAt = $now->addSeconds(-86400);
        $endAt = $now->addSeconds(604800);

        $record = RecurringTaskRecord::from([
            'id' => new UuidVO($id),
            'alias' => $alias,
            'fqcn' => $fqcn,
            'payload' => StrictDataObject::from(['test' => 'runner']),
            'interval_seconds' => new DurationVO(3600),
            'start_at' => $startAt,
            'end_at' => $endAt,
            'status' => $status,
            'last_run_at' => $lastRunAt,
        ]);

        $this->repository->create($record);

        return $record;
    }

    private function findTaskByAliasName(string $aliasName): ?RecurringTask
    {
        $id = $this->getUuidForAlias($aliasName);
        $alias = $this->generateAliasFromName($aliasName, $id);

        return $this->repository->findByAlias($alias);
    }

    // ==================== TESTS ====================

    public function test_run_successfully_executes_task(): void
    {
        $record = $this->createTaskRecord('test-run-success');

        $result = $this->runner->run($record);

        $this->assertTrue($result->success);
        $this->assertNull($result->error);
        $this->assertIsFloat($result->execution_time->getValue());

        $task = $this->findTaskByAliasName('test-run-success');
        $this->assertNotNull($task);
        $this->assertNotNull($task->getLastRunAt());

        $alias = $this->generateAliasFromName('test-run-success');
        $debugs = $this->debugRepository->findByAlias($alias);
        $this->assertCount(1, $debugs);

        $debug = $debugs->first();
        $debugData = $debug->getData();
        $this->assertEquals('succeeded', $debug->getStatus()->value);
        $this->assertEquals('Recurring task executed successfully', $debugData->toArray()['info']);
    }

    public function test_run_returns_failure_when_task_not_in_playing_status(): void
    {
        $record = $this->createTaskRecord('test-run-waiting', RecurringTaskStatus::WAITING);

        $result = $this->runner->run($record);

        $this->assertFalse($result->success);
        $this->assertNotNull($result->error);
        $this->assertStringContainsString('Validation failed', $result->error->error->getValue());

        $task = $this->findTaskByAliasName('test-run-waiting');
        $this->assertNotNull($task);
        $this->assertNull($task->getLastRunAt());
    }

    public function test_run_returns_failure_when_task_expired(): void
    {
        $now = new Iso8601DateTimeVO;

        $id = $this->getUuidForAlias('test-run-expired');
        $alias = $this->generateAliasFromName('test-run-expired', $id);

        $record = RecurringTaskRecord::from([
            'id' => new UuidVO($id),
            'alias' => $alias,
            'fqcn' => TestRecurringTask::class,
            'payload' => StrictDataObject::from(['test' => 'runner']),
            'interval_seconds' => new DurationVO(3600),
            'start_at' => $now->addSeconds(-604800),
            'end_at' => $now->addSeconds(-86400),
            'status' => RecurringTaskStatus::PLAYING,
        ]);

        $this->repository->create($record);

        $result = $this->runner->run($record);

        $this->assertFalse($result->success);
        $this->assertNotNull($result->error);
        $this->assertStringContainsString('Validation failed', $result->error->error->getValue());
    }

    public function test_run_skips_execution_when_interval_not_reached(): void
    {
        $now = new Iso8601DateTimeVO;

        $lastRunAt = $now->addSeconds(-1800);

        $record = $this->createTaskRecord(
            'test-run-skip',
            RecurringTaskStatus::PLAYING,
            TestRecurringTask::class,
            $lastRunAt
        );

        $result = $this->runner->run($record);

        $this->assertTrue($result->success);
        $this->assertNull($result->error);
        $this->assertEquals(0.0, $result->execution_time->getValue());

        $task = $this->findTaskByAliasName('test-run-skip');
        $this->assertNotNull($task);
        $this->assertEquals(
            $lastRunAt->format('Y-m-d H:i'),
            $task->getLastRunAt()->format('Y-m-d H:i')
        );

        $alias = $this->generateAliasFromName('test-run-skip');
        $debugs = $this->debugRepository->findByAlias($alias);
        $this->assertCount(0, $debugs);
    }

    public function test_run_handles_task_exception(): void
    {
        $now = new Iso8601DateTimeVO;

        $id = $this->getUuidForAlias('test-run-failing');
        $alias = $this->generateAliasFromName('test-run-failing', $id);

        $record = RecurringTaskRecord::from([
            'id' => new UuidVO($id),
            'alias' => $alias,
            'fqcn' => FailingRecurringTask::class,
            'payload' => StrictDataObject::from(['should_fail' => true, 'fail_message' => 'Test failure']),
            'interval_seconds' => new DurationVO(3600),
            'start_at' => $now->addSeconds(-86400),
            'end_at' => $now->addSeconds(604800),
            'status' => RecurringTaskStatus::PLAYING,
        ]);

        $this->repository->create($record);

        $result = $this->runner->run($record);

        $this->assertFalse($result->success);
        $this->assertNotNull($result->error);
        $this->assertEquals('Test failure', $result->error->error->getValue());

        $task = $this->findTaskByAliasName('test-run-failing');
        $this->assertNotNull($task);
        $this->assertNotNull($task->getLastRunAt());

        $alias = $this->generateAliasFromName('test-run-failing');
        $debugs = $this->debugRepository->findByAlias($alias);
        $this->assertCount(1, $debugs);

        $debug = $debugs->first();
        $debugData = $debug->getData();
        $this->assertEquals('failed', $debug->getStatus()->value);
        $this->assertEquals('Test failure', $debugData->toArray()['info']);
    }

    public function test_run_returns_execution_time(): void
    {
        $record = $this->createTaskRecord('test-run-time');

        $result = $this->runner->run($record);

        $this->assertTrue($result->success);
        $this->assertIsFloat($result->execution_time->getValue());
        $this->assertGreaterThanOrEqual(0, $result->execution_time->getValue());
    }

    public function test_run_logs_start_and_success(): void
    {
        $record = $this->createTaskRecord('test-run-logs');

        $result = $this->runner->run($record);

        $this->assertTrue($result->success);

        $alias = $this->generateAliasFromName('test-run-logs');
        $debugs = $this->debugRepository->findByAlias($alias);
        $this->assertCount(1, $debugs);

        $debug = $debugs->first();
        $debugData = $debug->getData();
        $this->assertEquals('succeeded', $debug->getStatus()->value);
        $this->assertEquals('Recurring task executed successfully', $debugData->toArray()['info']);
    }

    public function test_run_preserves_task_in_playing_status(): void
    {
        $record = $this->createTaskRecord('test-run-preserve');

        $result = $this->runner->run($record);

        $this->assertTrue($result->success);

        $task = $this->findTaskByAliasName('test-run-preserve');
        $this->assertNotNull($task);
        $this->assertEquals(RecurringTaskStatus::PLAYING, $task->getStatus());
    }

    public function test_run_does_not_change_other_task_data(): void
    {
        $aliasName = 'test-run-data';
        $now = new Iso8601DateTimeVO;

        $id = $this->getUuidForAlias($aliasName);
        $alias = $this->generateAliasFromName($aliasName, $id);

        $record = RecurringTaskRecord::from([
            'id' => new UuidVO($id),
            'alias' => $alias,
            'fqcn' => TestRecurringTask::class,
            'payload' => StrictDataObject::from(['test' => 'runner', 'data' => 'should_persist']),
            'interval_seconds' => new DurationVO(7200),
            'start_at' => $now->addSeconds(-172800),
            'end_at' => $now->addSeconds(1209600),
            'status' => RecurringTaskStatus::PLAYING,
        ]);

        $this->repository->create($record);

        $result = $this->runner->run($record);
        $this->assertTrue($result->success);

        $task = $this->findTaskByAliasName($aliasName);
        $this->assertNotNull($task);
        $this->assertEquals(TestRecurringTask::class, $task->getFqcn());
        $this->assertEquals(7200, $task->getIntervalSeconds()->getValue());
        $this->assertEquals('should_persist', $task->getPayload()->toArray()['data']);
    }

    public function test_run_handles_null_last_run_at(): void
    {
        $record = $this->createTaskRecord(
            'test-run-first',
            RecurringTaskStatus::PLAYING,
            null,
            null
        );

        $result = $this->runner->run($record);

        $this->assertTrue($result->success);

        $task = $this->findTaskByAliasName('test-run-first');
        $this->assertNotNull($task);
        $this->assertNotNull($task->getLastRunAt());
    }
}
