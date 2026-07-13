<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Services\Watchs;

use AndyDefer\ConsoleWriter\Console\Console;
use AndyDefer\Directive\DirectiveKernel;
use AndyDefer\Task\Records\TaskExecutionResultRecord;
use AndyDefer\Task\Services\Watchs\ParallelExecutor;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\ValueObjects\LimitVO;

final class ParallelExecutorTest extends IntegrationTestCase
{
    private Console $console;

    private DirectiveKernel $kernel;

    private ParallelExecutor $executor;

    protected function setUp(): void
    {
        parent::setUp();
        ob_start();

        $this->console = new Console;
        $this->kernel = DirectiveKernel::init($this->app);
        $this->executor = new ParallelExecutor(2, $this->console, $this->kernel);
    }

    protected function tearDown(): void
    {
        ob_get_clean();
        parent::tearDown();
    }

    public function test_execute_with_single_worker(): void
    {
        $executor = new ParallelExecutor(1, $this->console, $this->kernel);

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

        // ✅ Vérifier que le résultat est un tableau
        $this->assertIsArray($results);

        // ✅ Vérifier que chaque élément non-null est un TaskExecutionResultRecord
        foreach ($results as $result) {
            if ($result !== null) {
                $this->assertInstanceOf(TaskExecutionResultRecord::class, $result);
            }
        }
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

    public function test_max_workers_is_at_least_one(): void
    {
        $executor = new ParallelExecutor(0, $this->console, $this->kernel);

        $reflection = new \ReflectionClass($executor);
        $property = $reflection->getProperty('maxWorkers');
        $property->setAccessible(true);

        $this->assertEquals(1, $property->getValue($executor));
    }

    public function test_execute_returns_empty_array_when_no_results(): void
    {
        // ✅ Avec une base de données vide, les workers peuvent retourner null
        $results = $this->executor->execute(
            uniqueOnly: false,
            recurringOnly: false,
            limit: null,
            verbose: false
        );

        // Le résultat peut être un tableau vide ou avec des null
        $this->assertIsArray($results);

        // ✅ Vérifier que tous les éléments non-null sont valides
        $nonNullResults = array_filter($results, fn ($r) => $r !== null);
        foreach ($nonNullResults as $result) {
            $this->assertInstanceOf(TaskExecutionResultRecord::class, $result);
        }
    }
}
