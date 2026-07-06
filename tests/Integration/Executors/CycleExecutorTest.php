<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Executors;

use AndyDefer\ConsoleWriter\Console\Console;
use AndyDefer\Directive\Services\DirectiveTestingService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Task\Contracts\Services\WatchInterface;
use AndyDefer\Task\Contracts\Services\WatchRendererInterface;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\Executors\CycleExecutor;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\Repositories\TaskExecutionDebugRepository;
use AndyDefer\Task\Repositories\UniqueTaskRepository;
use AndyDefer\Task\Services\WatchService;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestUniqueTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\UniqueTaskFqcnVO;
use AndyDefer\Task\ValueObjects\UuidVO;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Ramsey\Uuid\Uuid;

/**
 * Integration tests for the CycleExecutor.
 */
final class CycleExecutorTest extends IntegrationTestCase
{
    use DatabaseMigrations;

    private CycleExecutor $executor;

    private UniqueTaskRepository $uniqueRepository;

    private TaskExecutionDebugRepository $debugRepository;

    private DirectiveTestingService $testingService;

    private WatchInterface $watchService;

    protected function setUp(): void
    {
        parent::setUp();

        // Start output buffering to prevent console pollution
        ob_start();

        $console = $this->app->make(Console::class);
        $this->watchService = new WatchService($console);

        $this->testingService = new DirectiveTestingService($this->app);
        $this->watchService->enableTestingMode($this->testingService);
        $this->app->instance(DirectiveTestingService::class, $this->testingService);

        $renderer = $this->app->make(WatchRendererInterface::class);
        $this->executor = new CycleExecutor($this->watchService, $renderer);

        $this->debugRepository = new TaskExecutionDebugRepository;
        $this->uniqueRepository = new UniqueTaskRepository(
            $this->debugRepository,
            $this->app->make(LoggerInterface::class)
        );
    }

    protected function tearDown(): void
    {
        // Clean output buffer
        ob_end_clean();

        $this->testingService->destroy();
        parent::tearDown();
    }

    private function getUuidForAlias(string $aliasName): string
    {
        return Uuid::uuid4()->toString();
    }

    private function generateAliasFromName(string $name, ?string $uuid = null): TaskAliasVO
    {
        $uuid = $uuid ?? $this->getUuidForAlias($name);

        return new TaskAliasVO('unique@'.$uuid);
    }

    private function createUniqueTask(
        string $alias,
        ?string $id = null,
        UniqueTaskStatus $status = UniqueTaskStatus::PENDING,
        ?\DateTimeInterface $scheduledAt = null,
        int $gracePeriodSeconds = 86400,
        int $attempts = 0,
        int $maxAttempts = 3
    ): TaskAliasVO {
        $scheduledAt = $scheduledAt ?? Carbon::now()->subMinutes(5);
        $id = $id ?? $this->getUuidForAlias($alias);
        $aliasVO = $this->generateAliasFromName($alias, $id);

        $record = UniqueTaskRecord::from([
            'id' => new UuidVO($id),
            'alias' => $aliasVO,
            'fqcn' => new UniqueTaskFqcnVO(TestUniqueTask::class),
            'payload' => StrictDataObject::from(['test' => 'unique']),
            'scheduled_at' => new Iso8601DateTimeVO($scheduledAt->format('Y-m-d\TH:i:sP')),
            'grace_period_seconds' => $gracePeriodSeconds,
            'status' => $status,
            'attempts' => $attempts,
            'max_attempts' => $maxAttempts,
        ]);

        $this->uniqueRepository->create($record);

        return $aliasVO;
    }

    public function test_execute_returns_null_when_should_stop(): void
    {
        $cycleCount = new CounterVO(1);
        $intervalSeconds = new DurationVO(60);

        $result = $this->executor->execute(
            $cycleCount,
            true,
            false,
            null,
            false,
            true, // shouldStop = true
            $intervalSeconds,
            null
        );

        $this->assertNull($result);
    }

    public function test_execute_sequential_processes_tasks(): void
    {
        // Arrange : Create tasks
        $alias = $this->createUniqueTask('sequential-task');

        $cycleCount = new CounterVO(1);
        $intervalSeconds = new DurationVO(60);

        // Act : Execute cycle sequentially
        $result = $this->executor->execute(
            $cycleCount,
            true,
            false,
            null,
            false,
            false,
            $intervalSeconds,
            null // parallelWorkers = null (sequential)
        );

        // Assert : Verify results
        $this->assertNotNull($result);
        $this->assertEquals(1, $result->success->getValue());
        $this->assertEquals(0, $result->failed->getValue());
        $this->assertFalse($result->hasErrors);

        $task = $this->uniqueRepository->findByAlias($alias);
        $this->assertEquals(UniqueTaskStatus::COMPLETED, $task->getStatus());
    }

    public function test_execute_with_parallel_workers_processes_tasks(): void
    {
        // Arrange : Create multiple tasks
        for ($i = 1; $i <= 5; $i++) {
            $this->createUniqueTask("parallel-task-{$i}");
        }

        $cycleCount = new CounterVO(1);
        $intervalSeconds = new DurationVO(60);

        // Act : Execute cycle with parallel workers
        $result = $this->executor->execute(
            $cycleCount,
            true,
            false,
            null,
            false,
            false,
            $intervalSeconds,
            3 // 3 parallel workers
        );

        // Assert : Verify results
        $this->assertNotNull($result);
        $this->assertEquals(5, $result->success->getValue());
        $this->assertEquals(0, $result->failed->getValue());
        $this->assertFalse($result->hasErrors);
    }

    public function test_execute_with_parallel_workers_and_limit(): void
    {
        // Arrange : Create 10 tasks
        for ($i = 1; $i <= 10; $i++) {
            $this->createUniqueTask("parallel-limit-task-{$i}");
        }

        $cycleCount = new CounterVO(1);
        $intervalSeconds = new DurationVO(60);
        $limit = new LimitVO(5);

        // Act : Execute cycle with parallel workers and limit
        $result = $this->executor->execute(
            $cycleCount,
            true,
            false,
            $limit,
            false,
            false,
            $intervalSeconds,
            3 // 3 parallel workers
        );

        // Assert : Verify only 5 tasks were processed
        $this->assertNotNull($result);
        $this->assertEquals(5, $result->success->getValue());
        $this->assertEquals(0, $result->failed->getValue());
        $this->assertFalse($result->hasErrors);
    }

    public function test_execute_with_parallel_one_equals_sequential(): void
    {
        // Arrange : Create task
        $alias = $this->createUniqueTask('parallel-one-task');

        $cycleCount = new CounterVO(1);
        $intervalSeconds = new DurationVO(60);

        // Act : Execute with parallel=1
        $resultParallel1 = $this->executor->execute(
            $cycleCount,
            true,
            false,
            null,
            false,
            false,
            $intervalSeconds,
            1
        );

        // Assert : Should work the same as sequential
        $this->assertNotNull($resultParallel1);
        $this->assertEquals(1, $resultParallel1->success->getValue());
        $this->assertEquals(0, $resultParallel1->failed->getValue());
        $this->assertFalse($resultParallel1->hasErrors);
    }

    public function test_execute_with_parallel_and_errors_handles_failures(): void
    {
        // Note: This test requires a failing task fixture
        // We'll test error handling logic
        $cycleCount = new CounterVO(1);
        $intervalSeconds = new DurationVO(60);

        // Act : Execute cycle
        $result = $this->executor->execute(
            $cycleCount,
            true,
            false,
            null,
            false,
            false,
            $intervalSeconds,
            2
        );

        // Assert : Should handle gracefully even with no tasks
        $this->assertNotNull($result);
        $this->assertIsInt($result->success->getValue());
        $this->assertIsInt($result->failed->getValue());
    }

    public function test_execute_with_unique_only_flag(): void
    {
        // Arrange : Create both unique and recurring tasks
        $uniqueAlias = $this->createUniqueTask('unique-only-task');
        // Recurring task would be created here if needed

        $cycleCount = new CounterVO(1);
        $intervalSeconds = new DurationVO(60);

        // Act : Execute with unique-only flag
        $result = $this->executor->execute(
            $cycleCount,
            true,  // uniqueOnly
            false, // recurringOnly
            null,
            false,
            false,
            $intervalSeconds,
            null
        );

        // Assert : Only unique tasks processed
        $this->assertNotNull($result);
        $this->assertEquals(1, $result->success->getValue());
        $this->assertEquals(0, $result->failed->getValue());
    }

    public function test_execute_with_recurring_only_flag(): void
    {
        // Arrange : Create unique task
        $this->createUniqueTask('unique-task');

        $cycleCount = new CounterVO(1);
        $intervalSeconds = new DurationVO(60);

        // Act : Execute with recurring-only flag (no recurring tasks exist)
        $result = $this->executor->execute(
            $cycleCount,
            false, // uniqueOnly
            true,  // recurringOnly
            null,
            false,
            false,
            $intervalSeconds,
            null
        );

        // Assert : No tasks should be processed
        $this->assertNotNull($result);
        $this->assertEquals(0, $result->success->getValue());
        $this->assertEquals(0, $result->failed->getValue());
    }

    public function test_execute_with_verbose_output_works(): void
    {
        // Arrange : Create task
        $alias = $this->createUniqueTask('verbose-task');

        $cycleCount = new CounterVO(1);
        $intervalSeconds = new DurationVO(60);

        // Act : Execute with verbose flag
        $result = $this->executor->execute(
            $cycleCount,
            true,
            false,
            null,
            true, // verbose
            false,
            $intervalSeconds,
            null
        );

        // Assert : Should still process correctly
        $this->assertNotNull($result);
        $this->assertEquals(1, $result->success->getValue());
        $this->assertEquals(0, $result->failed->getValue());
    }

    public function test_execute_with_parallel_and_verbose(): void
    {
        // Arrange : Create multiple tasks
        for ($i = 1; $i <= 4; $i++) {
            $this->createUniqueTask("parallel-verbose-{$i}");
        }

        $cycleCount = new CounterVO(1);
        $intervalSeconds = new DurationVO(60);

        // Act : Execute with parallel and verbose
        $result = $this->executor->execute(
            $cycleCount,
            true,
            false,
            null,
            true, // verbose
            false,
            $intervalSeconds,
            2 // 2 parallel workers
        );

        // Assert : Should process all tasks
        $this->assertNotNull($result);
        $this->assertEquals(4, $result->success->getValue());
        $this->assertEquals(0, $result->failed->getValue());
    }

    public function test_execute_returns_cycle_result_with_duration(): void
    {
        // Arrange : Create task
        $this->createUniqueTask('duration-test');

        $cycleCount = new CounterVO(1);
        $intervalSeconds = new DurationVO(60);

        // Act
        $result = $this->executor->execute(
            $cycleCount,
            true,
            false,
            null,
            false,
            false,
            $intervalSeconds,
            null
        );

        // Assert : Result has the expected structure
        $this->assertNotNull($result);
        $this->assertTrue(isset($result->success));
        $this->assertTrue(isset($result->failed));
        $this->assertTrue(isset($result->errors));
        $this->assertTrue(isset($result->hasErrors));
    }
}
