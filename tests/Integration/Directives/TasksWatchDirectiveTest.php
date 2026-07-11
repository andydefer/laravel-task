<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Directives;

use AndyDefer\Directive\Services\DirectiveTestingService;
use AndyDefer\Task\Bootstrap\ApplicationFactory;
use AndyDefer\Task\Directives\TasksWatchDirective;
use AndyDefer\Task\Tests\IntegrationTestCase;

final class TasksWatchDirectiveTest extends IntegrationTestCase
{
    private DirectiveTestingService $testingService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testingService = new DirectiveTestingService(
            ApplicationFactory::create(),
        );
    }

    protected function tearDown(): void
    {
        $this->testingService->destroy();
        parent::tearDown();
    }

    public function test_get_signature_returns_correct_string(): void
    {
        $directive = $this->app->make(TasksWatchDirective::class);
        $signature = $directive->getSignature();

        // ✅ Correction: tasks:watch (avec deux-points)
        $this->assertStringContainsString('tasks:watch', $signature);
        $this->assertStringContainsString('interval', $signature);
        $this->assertStringContainsString('duration', $signature);
        $this->assertStringContainsString('limit', $signature);
        $this->assertStringContainsString('parallel', $signature);
        $this->assertStringContainsString('--unique-only', $signature);
        $this->assertStringContainsString('--recurring-only', $signature);
        $this->assertStringContainsString('--verbose', $signature);
    }

    public function test_get_description_returns_string(): void
    {
        $directive = $this->app->make(TasksWatchDirective::class);
        $description = $directive->getDescription();

        $this->assertIsString($description);
        $this->assertNotEmpty($description);
    }

    public function test_get_aliases_returns_aliases(): void
    {
        $directive = $this->app->make(TasksWatchDirective::class);
        $aliases = $directive->getAliases();

        $this->assertTrue($aliases->contains('task-watch'));
        $this->assertTrue($aliases->contains('tasks-watch'));
        $this->assertSame(2, $aliases->count());
    }

    public function test_execute_with_interval_only(): void
    {
        $response = $this->testingService->runDirective(
            TasksWatchDirective::class,
            ['3', '6']  // ✅ interval=3s, duration=6s (minimum interval est 3s)
        );

        $this->assertStringContainsString('Starting task watch', $response->output);
        $this->assertStringContainsString('Interval: 3s', $response->output);
        $this->assertStringContainsString('Duration: 6s', $response->output);
        $this->assertStringContainsString('Press Ctrl+C to stop', $response->output);
    }

    public function test_execute_with_duration(): void
    {
        $response = $this->testingService->runDirective(
            TasksWatchDirective::class,
            ['3', '9']  // ✅ interval=3s (minimum), duration=9s
        );

        $this->assertStringContainsString('Interval: 3s', $response->output);
        $this->assertStringContainsString('Duration: 9s', $response->output);
        $this->assertStringContainsString('Watch Summary', $response->output);
    }

    public function test_execute_with_limit(): void
    {
        $response = $this->testingService->runDirective(
            TasksWatchDirective::class,
            ['3', '9', '10']  // ✅ interval=3s, duration=9s, limit=10
        );

        $this->assertStringContainsString('Limit: 10', $response->output);
        $this->assertStringContainsString('Watch Summary', $response->output);
    }

    public function test_execute_with_parallel_workers(): void
    {
        $response = $this->testingService->runDirective(
            TasksWatchDirective::class,
            ['3', '9', '10', '3']  // ✅ interval=3s, duration=9s, limit=10, workers=3
        );

        $this->assertStringContainsString('Workers: 3', $response->output);
        $this->assertStringContainsString('Starting 3 parallel workers', $response->output);
        $this->assertStringContainsString('Watch Summary', $response->output);
    }

    public function test_execute_with_unique_only(): void
    {
        $response = $this->testingService->runDirective(
            TasksWatchDirective::class,
            ['3', '6', '10', '1', '--unique-only']  // ✅ interval=3s, duration=6s, limit=10
        );

        $this->assertStringContainsString('Options: --unique-only', $response->output);
        $this->assertStringContainsString('Watch Summary', $response->output);
    }

    public function test_execute_with_recurring_only(): void
    {
        $response = $this->testingService->runDirective(
            TasksWatchDirective::class,
            ['3', '6', '10', '1', '--recurring-only']  // ✅ interval=3s, duration=6s, limit=10
        );

        $this->assertStringContainsString('Options: --recurring-only', $response->output);
        $this->assertStringContainsString('Watch Summary', $response->output);
    }

    public function test_execute_with_verbose_mode(): void
    {
        $response = $this->testingService->runDirective(
            TasksWatchDirective::class,
            ['3', '6', '10', '1', '--verbose']  // ✅ interval=3s, duration=6s, limit=10
        );

        $this->assertStringContainsString('Options: --verbose', $response->output);
        $this->assertStringContainsString('Watch Summary', $response->output);
    }
}
