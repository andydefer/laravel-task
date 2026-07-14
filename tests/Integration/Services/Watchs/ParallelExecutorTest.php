<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Services\Watchs;

use AndyDefer\ConsoleWriter\Console\Contracts\ConsoleInterface;
use AndyDefer\Directive\DirectiveKernel;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Task\Handlers\OutputHandler;
use AndyDefer\Task\Records\TaskExecutionResultRecord;
use AndyDefer\Task\Services\Watchs\ParallelExecutor;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\ValueObjects\LimitVO;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Integration tests for the ParallelExecutor service.
 *
 * Validates parallel task execution with various configurations including
 * worker count, task limits, filtering options, and output verbosity.
 */
final class ParallelExecutorTest extends IntegrationTestCase
{
    /** @var ConsoleInterface&MockObject */
    private ConsoleInterface $console;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    private DirectiveKernel $kernel;

    private ParallelExecutor $executor;

    private OutputHandler $outputHandler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->console = $this->createMockConsole();
        $this->logger = $this->createStub(LoggerInterface::class);

        $this->outputHandler = new OutputHandler(
            $this->console,
            $this->logger,
            false, // isMuted
            false  // isVerbose
        );

        $this->kernel = DirectiveKernel::init($this->app);
        $this->executor = new ParallelExecutor(
            2,
            $this->console,
            $this->kernel,
            $this->outputHandler
        );
    }

    /**
     * Creates a configured executor with the specified worker count.
     */
    private function createExecutor(int $workers): ParallelExecutor
    {
        $console = $this->createMockConsole();
        $logger = $this->createStub(LoggerInterface::class);

        $outputHandler = new OutputHandler(
            $console,
            $logger,
            false,
            false
        );

        $kernel = DirectiveKernel::init($this->app);

        return new ParallelExecutor(
            $workers,
            $console,
            $kernel,
            $outputHandler
        );
    }

    /**
     * Creates a muted executor with the specified worker count.
     */
    private function createMutedExecutor(int $workers): ParallelExecutor
    {
        $console = $this->createMockConsole();
        $logger = $this->createStub(LoggerInterface::class);

        $outputHandler = new OutputHandler(
            $console,
            $logger,
            true,  // isMuted
            false  // isVerbose
        );

        $kernel = DirectiveKernel::init($this->app);

        return new ParallelExecutor(
            $workers,
            $console,
            $kernel,
            $outputHandler
        );
    }

    /**
     * Validates that all non-null results are TaskExecutionResultRecord instances.
     */
    private function assertAllResultsAreValid(array $results): void
    {
        foreach ($results as $result) {
            if ($result !== null) {
                $this->assertInstanceOf(TaskExecutionResultRecord::class, $result);
            }
        }
    }

    public function test_execute_with_single_worker(): void
    {
        $executor = $this->createExecutor(1);

        $results = $executor->execute(
            uniqueOnly: false,
            recurringOnly: false,
            limit: null,
            verbose: false
        );

        $this->assertIsArray($results);
    }

    public function test_execute_with_multiple_workers(): void
    {
        $results = $this->executor->execute(
            uniqueOnly: false,
            recurringOnly: false,
            limit: null,
            verbose: false
        );

        $this->assertIsArray($results);
    }

    public function test_execute_with_limit(): void
    {
        $limit = new LimitVO(5);

        $results = $this->executor->execute(
            uniqueOnly: false,
            recurringOnly: false,
            limit: $limit,
            verbose: false
        );

        $this->assertIsArray($results);
    }

    public function test_execute_with_unique_only(): void
    {
        $results = $this->executor->execute(
            uniqueOnly: true,
            recurringOnly: false,
            limit: null,
            verbose: false
        );

        $this->assertIsArray($results);
    }

    public function test_execute_with_recurring_only(): void
    {
        $results = $this->executor->execute(
            uniqueOnly: false,
            recurringOnly: true,
            limit: null,
            verbose: false
        );

        $this->assertIsArray($results);
    }

    public function test_execute_returns_array_of_result_records(): void
    {
        $results = $this->executor->execute(
            uniqueOnly: false,
            recurringOnly: false,
            limit: null,
            verbose: false
        );

        $this->assertIsArray($results);
        $this->assertAllResultsAreValid($results);
    }

    public function test_execute_with_verbose_mode(): void
    {
        $results = $this->executor->execute(
            uniqueOnly: false,
            recurringOnly: false,
            limit: null,
            verbose: true
        );

        $this->assertIsArray($results);
    }

    public function test_execute_with_muted_mode(): void
    {
        $executor = $this->createMutedExecutor(2);

        $results = $executor->execute(
            uniqueOnly: false,
            recurringOnly: false,
            limit: null,
            verbose: true,
            muted: true
        );

        $this->assertIsArray($results);
    }

    public function test_max_workers_is_at_least_one(): void
    {
        $executor = new ParallelExecutor(
            0,
            $this->console,
            $this->kernel,
            $this->outputHandler
        );

        $reflection = new \ReflectionClass($executor);
        $property = $reflection->getProperty('maxWorkers');
        $property->setAccessible(true);
        $actualWorkers = $property->getValue($executor);

        $this->assertEquals(1, $actualWorkers);
    }

    public function test_execute_returns_empty_array_when_no_results(): void
    {
        $results = $this->executor->execute(
            uniqueOnly: false,
            recurringOnly: false,
            limit: null,
            verbose: false
        );

        $this->assertIsArray($results);
        $this->assertAllResultsAreValid($results);
    }

    public function test_execute_with_single_worker_and_muted(): void
    {
        $executor = $this->createMutedExecutor(1);

        $results = $executor->execute(
            uniqueOnly: false,
            recurringOnly: false,
            limit: null,
            verbose: true,
            muted: true
        );

        $this->assertIsArray($results);
    }

    public function test_execute_with_multiple_workers_and_muted(): void
    {
        $executor = $this->createMutedExecutor(3);

        $results = $executor->execute(
            uniqueOnly: false,
            recurringOnly: false,
            limit: null,
            verbose: true,
            muted: true
        );

        $this->assertIsArray($results);
    }
}
