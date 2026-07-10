<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Services;

use AndyDefer\ConsoleWriter\Console\Console;
use AndyDefer\Directive\Services\DirectiveTestingService;
use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Task\Contracts\Services\UniqueTaskServiceInterface;
use AndyDefer\Task\Contracts\Services\WatchInterface;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\Models\UniqueTask;
use AndyDefer\Task\Records\CycleResultRecord;
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
use ReflectionClass;

/**
 * Integration tests for the WatchService.
 *
 * Tests the complete functionality of the watch service including
 * testing mode, argument building, cycle execution, and utility methods.
 */
final class WatchServiceTest extends IntegrationTestCase
{
    use DatabaseMigrations;

    private WatchInterface $service;

    private UniqueTaskRepository $uniqueRepository;

    private UniqueTaskServiceInterface $uniqueService;

    private TaskExecutionDebugRepository $debugRepository;

    private DirectiveTestingService $testingService;

    protected function setUp(): void
    {
        parent::setUp();

        ob_start();

        $console = $this->app->make(Console::class);
        $this->service = new WatchService($console);

        $this->testingService = new DirectiveTestingService($this->app);
        $this->service->enableTestingMode($this->testingService);

        $this->debugRepository = new TaskExecutionDebugRepository;
        $this->uniqueRepository = new UniqueTaskRepository(
            $this->debugRepository,
            $this->app->make(LoggerInterface::class)
        );

        $this->uniqueService = $this->app->make(UniqueTaskServiceInterface::class);
    }

    protected function tearDown(): void
    {
        $this->service->disableTestingMode();
        ob_end_clean();

        $this->testingService->destroy();
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

    private function findTaskByAlias(TaskAliasVO $alias): ?UniqueTask
    {
        return $this->uniqueRepository->findByAlias($alias);
    }

    /**
     * Builds arguments for the executeCycle call.
     */
    private function buildArguments(
        bool $uniqueOnly,
        bool $recurringOnly,
        ?LimitVO $limit,
        bool $verbose,
        bool $testing,
        ?int $parallel = null,
        ?int $duration = null,
        ?int $interval = null
    ): StringTypedCollection {
        return $this->service->buildArguments(
            uniqueOnly: $uniqueOnly,
            recurringOnly: $recurringOnly,
            limit: $limit,
            verbose: $verbose,
            testing: $testing,
            parallel: $parallel,
            duration: $duration,
            interval: $interval
        );
    }

    // ==================== TESTS: Mode test ====================

    public function test_enable_testing_mode(): void
    {
        $testingService = new DirectiveTestingService($this->app);
        $this->service->enableTestingMode($testingService);

        $this->assertTrue($this->service->isTestingMode());
    }

    public function test_disable_testing_mode(): void
    {
        $testingService = new DirectiveTestingService($this->app);
        $this->service->enableTestingMode($testingService);
        $this->assertTrue($this->service->isTestingMode());

        $this->service->disableTestingMode();
        $this->assertFalse($this->service->isTestingMode());
    }

    // ==================== TESTS: buildArguments ====================

    public function test_build_arguments_with_no_options(): void
    {
        $arguments = $this->service->buildArguments(
            uniqueOnly: false,
            recurringOnly: false,
            limit: null,
            verbose: false,
            testing: false,
            parallel: null,
            duration: null,
            interval: null
        );

        $this->assertInstanceOf(StringTypedCollection::class, $arguments);

        // ✅ Ordre strict: interval, duration, limit, parallel, flags
        $this->assertCount(4, $arguments);
        $this->assertEquals('60', $arguments->first());
        $this->assertEquals('~', $arguments[1] ?? null);
        $this->assertEquals('~', $arguments[2] ?? null);
        $this->assertEquals('~', $arguments[3] ?? null);
    }

    public function test_build_arguments_with_unique_only(): void
    {
        $arguments = $this->service->buildArguments(
            uniqueOnly: true,
            recurringOnly: false,
            limit: null,
            verbose: false,
            testing: false,
            parallel: null,
            duration: null,
            interval: null
        );

        // ✅ Ordre strict: interval, duration, limit, parallel, flags
        $this->assertCount(5, $arguments);
        $this->assertEquals('60', $arguments->first());
        $this->assertEquals('~', $arguments[1] ?? null);
        $this->assertEquals('~', $arguments[2] ?? null);
        $this->assertEquals('~', $arguments[3] ?? null);
        $this->assertEquals('--unique-only', $arguments[4] ?? null);
    }

    public function test_build_arguments_with_recurring_only(): void
    {
        $arguments = $this->service->buildArguments(
            uniqueOnly: false,
            recurringOnly: true,
            limit: null,
            verbose: false,
            testing: false,
            parallel: null,
            duration: null,
            interval: null
        );

        // ✅ Ordre strict: interval, duration, limit, parallel, flags
        $this->assertCount(5, $arguments);
        $this->assertEquals('60', $arguments->first());
        $this->assertEquals('~', $arguments[1] ?? null);
        $this->assertEquals('~', $arguments[2] ?? null);
        $this->assertEquals('~', $arguments[3] ?? null);
        $this->assertEquals('--recurring-only', $arguments[4] ?? null);
    }

    public function test_build_arguments_with_limit(): void
    {
        $limit = new LimitVO(10);
        $arguments = $this->service->buildArguments(
            uniqueOnly: false,
            recurringOnly: false,
            limit: $limit,
            verbose: false,
            testing: false,
            parallel: null,
            duration: null,
            interval: null
        );

        // ✅ Ordre strict: interval, duration, limit, parallel, flags
        $this->assertCount(4, $arguments);
        $this->assertEquals('60', $arguments->first());
        $this->assertEquals('~', $arguments[1] ?? null);
        $this->assertEquals('10', $arguments[2] ?? null);
        $this->assertEquals('~', $arguments[3] ?? null);
    }

    public function test_build_arguments_with_verbose(): void
    {
        $arguments = $this->service->buildArguments(
            uniqueOnly: false,
            recurringOnly: false,
            limit: null,
            verbose: true,
            testing: false,
            parallel: null,
            duration: null,
            interval: null
        );

        // ✅ Ordre strict: interval, duration, limit, parallel, flags
        $this->assertCount(5, $arguments);
        $this->assertEquals('60', $arguments->first());
        $this->assertEquals('~', $arguments[1] ?? null);
        $this->assertEquals('~', $arguments[2] ?? null);
        $this->assertEquals('~', $arguments[3] ?? null);
        $this->assertEquals('--verbose', $arguments[4] ?? null);
    }

    public function test_build_arguments_with_testing(): void
    {
        $arguments = $this->service->buildArguments(
            uniqueOnly: false,
            recurringOnly: false,
            limit: null,
            verbose: false,
            testing: true,
            parallel: null,
            duration: null,
            interval: null
        );

        // ✅ Ordre strict: interval, duration, limit, parallel, flags
        $this->assertCount(5, $arguments);
        $this->assertEquals('60', $arguments->first());
        $this->assertEquals('~', $arguments[1] ?? null);
        $this->assertEquals('~', $arguments[2] ?? null);
        $this->assertEquals('~', $arguments[3] ?? null);
        $this->assertEquals('--testing', $arguments[4] ?? null);
    }

    public function test_build_arguments_with_parallel(): void
    {
        $arguments = $this->service->buildArguments(
            uniqueOnly: false,
            recurringOnly: false,
            limit: null,
            verbose: false,
            testing: false,
            parallel: 3,
            duration: null,
            interval: null
        );

        // ✅ Ordre strict: interval, duration, limit, parallel, flags
        $this->assertCount(4, $arguments);
        $this->assertEquals('60', $arguments->first());
        $this->assertEquals('~', $arguments[1] ?? null);
        $this->assertEquals('~', $arguments[2] ?? null);
        $this->assertEquals('3', $arguments[3] ?? null);
    }

    public function test_build_arguments_with_parallel_one(): void
    {
        $arguments = $this->service->buildArguments(
            uniqueOnly: false,
            recurringOnly: false,
            limit: null,
            verbose: false,
            testing: false,
            parallel: 1,
            duration: null,
            interval: null
        );

        // ✅ parallel=1 devient '1'
        $this->assertCount(4, $arguments);
        $this->assertEquals('60', $arguments->first());
        $this->assertEquals('~', $arguments[1] ?? null);
        $this->assertEquals('~', $arguments[2] ?? null);
        $this->assertEquals('1', $arguments[3] ?? null);
    }

    public function test_build_arguments_with_custom_interval_and_duration(): void
    {
        $arguments = $this->service->buildArguments(
            uniqueOnly: false,
            recurringOnly: false,
            limit: null,
            verbose: false,
            testing: false,
            parallel: null,
            duration: 30,
            interval: 10
        );

        // ✅ Ordre strict: interval, duration, limit, parallel, flags
        $this->assertCount(4, $arguments);
        $this->assertEquals('10', $arguments->first());
        $this->assertEquals('30', $arguments[1] ?? null);
        $this->assertEquals('~', $arguments[2] ?? null);
        $this->assertEquals('~', $arguments[3] ?? null);
    }

    public function test_build_arguments_with_all_options(): void
    {
        $limit = new LimitVO(5);
        $arguments = $this->service->buildArguments(
            uniqueOnly: true,
            recurringOnly: true,
            limit: $limit,
            verbose: true,
            testing: true,
            parallel: 4,
            duration: 60,
            interval: 30
        );

        // ✅ Ordre strict: interval, duration, limit, parallel, flags
        $this->assertCount(9, $arguments);
        $this->assertEquals('30', $arguments->first());
        $this->assertEquals('60', $arguments[1] ?? null);
        $this->assertEquals('5', $arguments[2] ?? null);
        $this->assertEquals('4', $arguments[3] ?? null);
        $this->assertEquals('--unique-only', $arguments[4] ?? null);
        $this->assertEquals('--recurring-only', $arguments[5] ?? null);
        $this->assertEquals('--verbose', $arguments[6] ?? null);
        $this->assertEquals('--testing', $arguments[7] ?? null);
    }

    // ==================== TESTS: executeCycle ====================

    public function test_execute_cycle_creates_and_executes_real_task(): void
    {
        $alias = $this->createUniqueTask(
            alias: 'test-real-task',
            scheduledAt: Carbon::now()->subMinutes(5),
            attempts: 0,
            maxAttempts: 3
        );

        $task = $this->findTaskByAlias($alias);
        $this->assertNotNull($task);
        $this->assertEquals(UniqueTaskStatus::PENDING, $task->getStatus());

        $cycleNumber = new CounterVO(1);
        $cycleStartedAt = new Iso8601DateTimeVO;
        $arguments = $this->buildArguments(
            uniqueOnly: true,
            recurringOnly: false,
            limit: null,
            verbose: false,
            testing: true,
            parallel: null,
            duration: null,
            interval: null
        );

        $result = $this->service->executeCycle(
            $cycleNumber,
            $arguments,
            $cycleStartedAt
        );

        $this->assertInstanceOf(CycleResultRecord::class, $result);
        $this->assertEquals(1, $result->success->getValue());
        $this->assertEquals(0, $result->failed->getValue());

        $taskAfter = $this->findTaskByAlias($alias);
        $this->assertEquals(UniqueTaskStatus::COMPLETED, $taskAfter->getStatus());
        $this->assertNotNull($taskAfter->getFinishedAt());
    }

    public function test_execute_cycle_with_multiple_real_tasks(): void
    {
        $aliases = [];
        for ($i = 1; $i <= 3; $i++) {
            $aliases[] = $this->createUniqueTask(
                alias: "test-real-task-{$i}",
                scheduledAt: Carbon::now()->subMinutes(5),
                attempts: 0,
                maxAttempts: 3
            );
        }

        $cycleNumber = new CounterVO(1);
        $cycleStartedAt = new Iso8601DateTimeVO;
        $arguments = $this->buildArguments(
            uniqueOnly: true,
            recurringOnly: false,
            limit: null,
            verbose: false,
            testing: true,
            parallel: null,
            duration: null,
            interval: null
        );

        $result = $this->service->executeCycle(
            $cycleNumber,
            $arguments,
            $cycleStartedAt
        );

        $this->assertEquals(3, $result->success->getValue());
        $this->assertEquals(0, $result->failed->getValue());

        foreach ($aliases as $alias) {
            $task = $this->findTaskByAlias($alias);
            $this->assertEquals(UniqueTaskStatus::COMPLETED, $task->getStatus());
        }
    }

    public function test_execute_cycle_with_limit_on_real_tasks(): void
    {
        $aliases = [];
        for ($i = 1; $i <= 5; $i++) {
            $aliases[] = $this->createUniqueTask(
                alias: "test-limit-task-{$i}",
                scheduledAt: Carbon::now()->subMinutes(5),
                attempts: 0,
                maxAttempts: 3
            );
        }

        $cycleNumber = new CounterVO(1);
        $cycleStartedAt = new Iso8601DateTimeVO;
        $limit = new LimitVO(3);
        $arguments = $this->buildArguments(
            uniqueOnly: true,
            recurringOnly: false,
            limit: $limit,
            verbose: false,
            testing: true,
            parallel: null,
            duration: null,
            interval: null
        );

        $result = $this->service->executeCycle(
            $cycleNumber,
            $arguments,
            $cycleStartedAt
        );

        $this->assertEquals(3, $result->success->getValue());
        $this->assertEquals(0, $result->failed->getValue());

        $completedCount = 0;
        $pendingCount = 0;

        foreach ($aliases as $alias) {
            $task = $this->findTaskByAlias($alias);
            if ($task->getStatus() === UniqueTaskStatus::COMPLETED) {
                $completedCount++;
            } else {
                $pendingCount++;
            }
        }

        $this->assertEquals(3, $completedCount);
        $this->assertEquals(2, $pendingCount);
    }

    public function test_execute_cycle_with_parallel_arguments(): void
    {
        $aliases = [];
        for ($i = 1; $i <= 5; $i++) {
            $aliases[] = $this->createUniqueTask(
                alias: "test-parallel-task-{$i}",
                scheduledAt: Carbon::now()->subMinutes(5),
                attempts: 0,
                maxAttempts: 3
            );
        }

        $cycleNumber = new CounterVO(1);
        $cycleStartedAt = new Iso8601DateTimeVO;
        $limit = new LimitVO(5);
        $arguments = $this->buildArguments(
            uniqueOnly: true,
            recurringOnly: false,
            limit: $limit,
            verbose: false,
            testing: true,
            parallel: 3,
            duration: null,
            interval: null
        );

        // ✅ 6 éléments au total
        $this->assertCount(6, $arguments);
        $this->assertEquals('60', $arguments->first());       // 0: interval
        $this->assertEquals('~', $arguments[1] ?? null);      // 1: duration
        $this->assertEquals('5', $arguments[2] ?? null);      // 2: limit
        $this->assertEquals('3', $arguments[3] ?? null);      // 3: parallel
        $this->assertEquals('--unique-only', $arguments[4] ?? null); // 4: flag
        $this->assertEquals('--testing', $arguments[5] ?? null);     // 5: flag testing

        $result = $this->service->executeCycle(
            $cycleNumber,
            $arguments,
            $cycleStartedAt
        );

        dd($result);
        $this->assertInstanceOf(CycleResultRecord::class, $result);
        $this->assertEquals(5, $result->success->getValue());
        $this->assertEquals(0, $result->failed->getValue());
    }

    // ==================== TESTS: shouldContinue ====================

    public function test_should_continue_returns_false_when_should_stop(): void
    {
        $result = $this->service->shouldContinue(
            shouldStop: true,
            duration: null,
            startedAt: null
        );

        $this->assertFalse($result);
    }

    public function test_should_continue_returns_true_when_no_duration_and_not_stopped(): void
    {
        $result = $this->service->shouldContinue(
            shouldStop: false,
            duration: null,
            startedAt: null
        );

        $this->assertTrue($result);
    }

    public function test_should_continue_returns_true_when_duration_not_reached(): void
    {
        $startedAt = new Iso8601DateTimeVO;
        $duration = new DurationVO(60);

        $result = $this->service->shouldContinue(
            shouldStop: false,
            duration: $duration,
            startedAt: $startedAt
        );

        $this->assertTrue($result);
    }

    public function test_should_continue_returns_false_when_duration_reached(): void
    {
        $startedAt = (new Iso8601DateTimeVO)->subSeconds(65);
        $duration = new DurationVO(60);

        $result = $this->service->shouldContinue(
            shouldStop: false,
            duration: $duration,
            startedAt: $startedAt
        );

        $this->assertFalse($result);
    }

    // ==================== TESTS: waitForInterval ====================

    public function test_wait_for_interval_breaks_when_callback_returns_false(): void
    {
        $interval = new DurationVO(10);
        $called = 0;

        $this->service->waitForInterval($interval, function () use (&$called) {
            $called++;

            return $called < 3;
        });

        $this->assertEquals(3, $called);
    }

    // ==================== TESTS: calculateElapsedSeconds ====================

    public function test_calculate_elapsed_seconds_returns_zero_for_null_start(): void
    {
        $result = $this->service->calculateElapsedSeconds(null);

        $this->assertEquals(0.0, $result);
    }

    public function test_calculate_elapsed_seconds_returns_positive_value(): void
    {
        $start = new Iso8601DateTimeVO;

        sleep(1);

        $result = $this->service->calculateElapsedSeconds($start);

        $this->assertGreaterThan(0.0, $result);
    }

    // ==================== TESTS: formatDuration ====================

    public function test_format_duration_formats_seconds_correctly(): void
    {
        $this->assertEquals('1h 30m 45s', $this->service->formatDuration(new DurationVO(5445)));
        $this->assertEquals('30m 45s', $this->service->formatDuration(new DurationVO(1845)));
        $this->assertEquals('45s', $this->service->formatDuration(new DurationVO(45)));
        $this->assertEquals('1h', $this->service->formatDuration(new DurationVO(3600)));
        $this->assertEquals('1h 1s', $this->service->formatDuration(new DurationVO(3601)));
        $this->assertEquals('0s', $this->service->formatDuration(new DurationVO(0)));
        $this->assertEquals('2h 30m 30s', $this->service->formatDuration(new DurationVO(9030)));
    }

    // ==================== TESTS: isFullBatchResponse ====================

    public function test_is_full_batch_response_returns_true_when_has_unique_and_recurring(): void
    {
        $reflection = new ReflectionClass(WatchService::class);
        $method = $reflection->getMethod('isFullBatchResponse');

        $data = [
            'unique' => ['success' => 3],
            'recurring' => ['success' => 2],
        ];

        $result = $method->invoke($this->service, $data);
        $this->assertTrue($result);
    }

    public function test_is_full_batch_response_returns_false_when_missing_unique(): void
    {
        $reflection = new ReflectionClass(WatchService::class);
        $method = $reflection->getMethod('isFullBatchResponse');

        $data = [
            'recurring' => ['success' => 2],
        ];

        $result = $method->invoke($this->service, $data);
        $this->assertFalse($result);
    }

    public function test_is_full_batch_response_returns_false_when_missing_recurring(): void
    {
        $reflection = new ReflectionClass(WatchService::class);
        $method = $reflection->getMethod('isFullBatchResponse');

        $data = [
            'unique' => ['success' => 3],
        ];

        $result = $method->invoke($this->service, $data);
        $this->assertFalse($result);
    }

    public function test_is_full_batch_response_returns_false_when_empty(): void
    {
        $reflection = new ReflectionClass(WatchService::class);
        $method = $reflection->getMethod('isFullBatchResponse');

        $data = [];

        $result = $method->invoke($this->service, $data);
        $this->assertFalse($result);
    }

    // ==================== TESTS: disable testing mode after enable ====================

    public function test_disable_testing_mode_clears_testing_service(): void
    {
        $testingService = new DirectiveTestingService($this->app);
        $this->service->enableTestingMode($testingService);

        $this->assertTrue($this->service->isTestingMode());

        $this->service->disableTestingMode();

        $this->assertFalse($this->service->isTestingMode());

        $reflection = new ReflectionClass(WatchService::class);
        $property = $reflection->getProperty('testingService');
        $value = $property->getValue($this->service);

        $this->assertNull($value);
    }
}
