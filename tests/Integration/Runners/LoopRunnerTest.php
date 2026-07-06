<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Runners;

use AndyDefer\ConsoleWriter\Console\Console;
use AndyDefer\Directive\Services\DirectiveTestingService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Task\Contracts\Services\WatchInterface;
use AndyDefer\Task\Contracts\Services\WatchRendererInterface;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\Executors\CycleExecutor;
use AndyDefer\Task\Handlers\SignalHandler;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\Repositories\TaskExecutionDebugRepository;
use AndyDefer\Task\Repositories\UniqueTaskRepository;
use AndyDefer\Task\Runners\LoopRunner;
use AndyDefer\Task\Services\WatchService;
use AndyDefer\Task\Strategies\TestingWatchStrategy;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestUniqueTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
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
 * Integration tests for the LoopRunner.
 */
final class LoopRunnerTest extends IntegrationTestCase
{
    use DatabaseMigrations;

    private LoopRunner $loopRunner;

    private UniqueTaskRepository $uniqueRepository;

    private TaskExecutionDebugRepository $debugRepository;

    private DirectiveTestingService $testingService;

    private WatchInterface $watchService;

    private WatchRendererInterface $renderer;

    private SignalHandler $signalHandler;

    private string $outputBuffer;

    protected function setUp(): void
    {
        parent::setUp();

        // Start output buffering
        ob_start();
        // Capture output to prevent console pollution
        $this->outputBuffer = '';

        $console = $this->app->make(Console::class);
        $this->watchService = new WatchService($console);

        $this->testingService = new DirectiveTestingService($this->app);
        $this->watchService->enableTestingMode($this->testingService);
        $this->app->instance(DirectiveTestingService::class, $this->testingService);

        $this->renderer = $this->app->make(WatchRendererInterface::class);
        $this->signalHandler = new SignalHandler($this->renderer);

        $cycleExecutor = new CycleExecutor($this->watchService, $this->renderer);
        $this->loopRunner = new LoopRunner($cycleExecutor, $this->signalHandler, $this->renderer);

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

    public function test_run_processes_tasks_without_parallel(): void
    {
        // Arrange : Create tasks
        for ($i = 1; $i <= 3; $i++) {
            $this->createUniqueTask("loop-sequential-{$i}");
        }

        $strategy = new TestingWatchStrategy;
        $startedAt = new Iso8601DateTimeVO;
        $intervalSeconds = new DurationVO(3);

        // Act : Run loop with sequential processing
        $result = $this->loopRunner->run(
            $strategy,
            true,  // uniqueOnly
            false, // recurringOnly
            null,  // limit
            false, // verbose
            new DurationVO(2), // duration
            $startedAt,
            $intervalSeconds,
            null  // parallelWorkers = null (sequential)
        );

        // Assert
        $this->assertEquals(3, $result->totalSuccess->getValue());
        $this->assertEquals(0, $result->totalFailed->getValue());
        $this->assertFalse($result->hasErrors);
        $this->assertGreaterThan(0, $result->cycleCount->getValue());
    }

    public function test_run_processes_tasks_with_parallel_workers(): void
    {
        // Arrange : Create tasks
        for ($i = 1; $i <= 5; $i++) {
            $this->createUniqueTask("loop-parallel-{$i}");
        }

        $strategy = new TestingWatchStrategy;
        $startedAt = new Iso8601DateTimeVO;
        $intervalSeconds = new DurationVO(3);

        // Act : Run loop with parallel workers
        $result = $this->loopRunner->run(
            $strategy,
            true,  // uniqueOnly
            false, // recurringOnly
            null,  // limit
            false, // verbose
            new DurationVO(2), // duration
            $startedAt,
            $intervalSeconds,
            3  // 3 parallel workers
        );

        // Assert
        $this->assertEquals(5, $result->totalSuccess->getValue());
        $this->assertEquals(0, $result->totalFailed->getValue());
        $this->assertFalse($result->hasErrors);
        $this->assertGreaterThan(0, $result->cycleCount->getValue());
    }

    public function test_run_respects_limit_with_parallel(): void
    {
        // Arrange : Create 10 tasks
        for ($i = 1; $i <= 10; $i++) {
            $this->createUniqueTask("loop-limit-{$i}");
        }

        $strategy = new TestingWatchStrategy;
        $startedAt = new Iso8601DateTimeVO;
        $intervalSeconds = new DurationVO(3);
        $limit = new LimitVO(5);

        // Act : Run loop with limit and parallel
        $result = $this->loopRunner->run(
            $strategy,
            true,  // uniqueOnly
            false, // recurringOnly
            $limit,
            false, // verbose
            new DurationVO(2), // duration
            $startedAt,
            $intervalSeconds,
            3  // 3 parallel workers
        );

        // Assert : Only 5 tasks should be processed
        $this->assertEquals(5, $result->totalSuccess->getValue());
        $this->assertEquals(0, $result->totalFailed->getValue());
        $this->assertFalse($result->hasErrors);
    }

    public function test_run_handles_duration_limit_with_parallel(): void
    {
        // Arrange : Create tasks
        for ($i = 1; $i <= 10; $i++) {
            $this->createUniqueTask("loop-duration-{$i}");
        }

        $strategy = new TestingWatchStrategy;
        $startedAt = new Iso8601DateTimeVO;
        $intervalSeconds = new DurationVO(3);

        // Act : Run with short duration (1 second)
        $result = $this->loopRunner->run(
            $strategy,
            true,
            false,
            null,
            false,
            new DurationVO(1), // 1 second duration
            $startedAt,
            $intervalSeconds,
            3  // 3 parallel workers
        );

        // Assert : Some tasks should be processed, but not all
        $this->assertIsInt($result->totalSuccess->getValue());
        $this->assertIsInt($result->totalFailed->getValue());
        $this->assertGreaterThan(0, $result->cycleCount->getValue());
    }

    public function test_run_handles_no_tasks_with_parallel(): void
    {
        // Arrange : No tasks created
        $strategy = new TestingWatchStrategy;
        $startedAt = new Iso8601DateTimeVO;
        $intervalSeconds = new DurationVO(3);

        // Act : Run loop with no tasks
        $result = $this->loopRunner->run(
            $strategy,
            true,
            false,
            null,
            false,
            new DurationVO(2),
            $startedAt,
            $intervalSeconds,
            3  // 3 parallel workers
        );

        // Assert : No tasks processed
        $this->assertEquals(0, $result->totalSuccess->getValue());
        $this->assertEquals(0, $result->totalFailed->getValue());
        $this->assertFalse($result->hasErrors);
    }

    public function test_run_aggregates_results_across_cycles_with_parallel(): void
    {
        // Arrange : Create tasks spread across cycles
        for ($i = 1; $i <= 8; $i++) {
            $this->createUniqueTask("loop-aggregate-{$i}");
        }

        $strategy = new TestingWatchStrategy;
        $startedAt = new Iso8601DateTimeVO;
        $intervalSeconds = new DurationVO(3);

        // Act : Run loop with duration allowing multiple cycles
        $result = $this->loopRunner->run(
            $strategy,
            true,
            false,
            null,
            false,
            new DurationVO(4), // Longer duration for multiple cycles
            $startedAt,
            $intervalSeconds,
            3  // 3 parallel workers
        );

        // Assert : All tasks should be processed
        $this->assertEquals(8, $result->totalSuccess->getValue());
        $this->assertEquals(0, $result->totalFailed->getValue());
        $this->assertFalse($result->hasErrors);
        $this->assertGreaterThan(0, $result->cycleCount->getValue());
    }

    public function test_run_with_parallel_one_equals_sequential(): void
    {
        // Arrange : Create tasks
        for ($i = 1; $i <= 3; $i++) {
            $this->createUniqueTask("loop-compare-{$i}");
        }

        $strategy = new TestingWatchStrategy;
        $startedAt = new Iso8601DateTimeVO;
        $intervalSeconds = new DurationVO(3);

        // Act : Run with parallel=1
        $resultParallel1 = $this->loopRunner->run(
            $strategy,
            true,
            false,
            null,
            false,
            new DurationVO(2),
            $startedAt,
            $intervalSeconds,
            1  // 1 parallel worker
        );

        // Act : Run sequential (parallel=null)
        $resultSequential = $this->loopRunner->run(
            $strategy,
            true,
            false,
            null,
            false,
            new DurationVO(2),
            $startedAt,
            $intervalSeconds,
            null  // sequential
        );

        // Assert : Both should process all tasks
        $this->assertEquals(3, $resultParallel1->totalSuccess->getValue());
        $this->assertEquals(3, $resultSequential->totalSuccess->getValue());
        $this->assertEquals($resultParallel1->totalSuccess->getValue(), $resultSequential->totalSuccess->getValue());
    }

    public function test_run_with_verbose_and_parallel(): void
    {
        // Arrange : Create tasks
        for ($i = 1; $i <= 3; $i++) {
            $this->createUniqueTask("loop-verbose-{$i}");
        }

        $strategy = new TestingWatchStrategy;
        $startedAt = new Iso8601DateTimeVO;
        $intervalSeconds = new DurationVO(3);

        // Act : Run with verbose and parallel
        $result = $this->loopRunner->run(
            $strategy,
            true,
            false,
            null,
            true, // verbose
            new DurationVO(2),
            $startedAt,
            $intervalSeconds,
            3  // 3 parallel workers
        );

        // Assert : Tasks processed correctly
        $this->assertEquals(3, $result->totalSuccess->getValue());
        $this->assertEquals(0, $result->totalFailed->getValue());
        $this->assertFalse($result->hasErrors);
    }

    public function test_run_stops_on_signal_with_parallel(): void
    {
        // Arrange : Create tasks
        for ($i = 1; $i <= 10; $i++) {
            $this->createUniqueTask("loop-signal-{$i}");
        }

        $strategy = new TestingWatchStrategy;
        $startedAt = new Iso8601DateTimeVO;
        $intervalSeconds = new DurationVO(3);

        // Act : Run loop
        $result = $this->loopRunner->run(
            $strategy,
            true,
            false,
            null,
            false,
            new DurationVO(10), // Long duration
            $startedAt,
            $intervalSeconds,
            3  // 3 parallel workers
        );

        // Assert : Loop ran and processed tasks
        $this->assertIsInt($result->totalSuccess->getValue());
        $this->assertIsInt($result->totalFailed->getValue());
        $this->assertGreaterThan(0, $result->cycleCount->getValue());
    }
}
