<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Directives;

use AndyDefer\Directive\Services\DirectiveTestingService;
use AndyDefer\Task\Directives\TasksWatchDirective;
use AndyDefer\Task\Tests\IntegrationTestCase;
use Illuminate\Foundation\Testing\DatabaseMigrations;

/**
 * Integration tests for the TasksWatchDirective.
 *
 * Validates the watch command with various configurations including
 * interval, duration, limit, parallel workers, and filtering options.
 */
final class TasksWatchDirectiveTest extends IntegrationTestCase
{
    use DatabaseMigrations;

    private DirectiveTestingService $testingService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runDatabaseMigrations();

        $this->testingService = new DirectiveTestingService(
            $this->app,
        );
    }

    protected function tearDown(): void
    {
        $this->testingService->destroy();
        parent::tearDown();
    }

    private function runDirective(array $arguments): object
    {
        return $this->testingService->runDirective(
            TasksWatchDirective::class,
            $arguments
        );
    }

    public function test_get_signature_returns_correct_string(): void
    {
        $directive = $this->app->make(TasksWatchDirective::class);
        $signature = $directive->getSignature();

        $this->assertStringContainsString('tasks:watch', $signature);
        $this->assertStringContainsString('interval', $signature);
        $this->assertStringContainsString('duration', $signature);
        $this->assertStringContainsString('limit', $signature);
        $this->assertStringContainsString('parallel', $signature);
        $this->assertStringContainsString('fqcnNames*', $signature);
        $this->assertStringContainsString('--unique-only', $signature);
        $this->assertStringContainsString('--recurring-only', $signature);
        $this->assertStringContainsString('--verbose', $signature);
        $this->assertStringContainsString('--mute', $signature);
    }

    public function test_get_description_returns_string(): void
    {
        $directive = $this->app->make(TasksWatchDirective::class);
        $description = $directive->getDescription();

        $this->assertIsString($description);
        $this->assertNotEmpty($description);
        $this->assertStringContainsString('--mute', $description);
    }

    public function test_get_aliases_returns_aliases(): void
    {
        $directive = $this->app->make(TasksWatchDirective::class);
        $aliases = $directive->getAliases();

        $this->assertTrue($aliases->contains('task-watch'));
        $this->assertTrue($aliases->contains('tw'));
        $this->assertSame(2, $aliases->count());
    }

    public function test_execute_with_interval_only(): void
    {
        $response = $this->runDirective(['2', '3', '4']);

        $this->assertStringContainsString('Starting task watch', $response->output);
        $this->assertStringContainsString('Interval: 2s', $response->output);
        $this->assertStringContainsString('Duration: 3s', $response->output);
        $this->assertStringContainsString('Press Ctrl+C to stop', $response->output);
    }

    /**
     * Combine duration and limit tests
     */
    public function test_execute_with_duration_and_limit(): void
    {
        $response = $this->runDirective(['2', '3', '10']);

        $this->assertStringContainsString('Interval: 2s', $response->output);
        $this->assertStringContainsString('Duration: 3s', $response->output);
        $this->assertStringContainsString('Limit: 10', $response->output);
        $this->assertStringContainsString('Final Status', $response->output);
    }

    /**
     * Combine parallel workers and unique/recurring flags
     */
    public function test_execute_with_parallel_and_flags(): void
    {
        // Test avec parallel + unique-only
        $response = $this->runDirective(['2', '3', '10', '2', '--unique-only']);

        $this->assertStringContainsString('Workers: 2', $response->output);
        $this->assertStringContainsString('Starting 2 parallel workers', $response->output);
        $this->assertStringContainsString('Options: --unique-only', $response->output);
        $this->assertStringContainsString('Final Status', $response->output);

        // Test avec parallel + recurring-only
        $response2 = $this->runDirective(['2', '3', '10', '2', '--recurring-only']);

        $this->assertStringContainsString('Workers: 2', $response2->output);
        $this->assertStringContainsString('Options: --recurring-only', $response2->output);
        $this->assertStringContainsString('Final Status', $response2->output);

        // Test avec parallel + verbose
        $response3 = $this->runDirective(['2', '3', '10', '2', '--verbose']);

        $this->assertStringContainsString('Workers: 2', $response3->output);
        $this->assertStringContainsString('Options: --verbose', $response3->output);
        $this->assertStringContainsString('Final Status', $response3->output);
    }

    /**
     * Combine all mute tests into one data provider
     */
    public function test_execute_with_mute_combinations(): void
    {
        // Mute seul
        $response = $this->runDirective(['2', '3', '10', '1', '--mute']);
        $this->assertEmpty($response->output);
        $this->assertSame(0, $response->exit_code->value);

        // Mute + parallel
        $response = $this->runDirective(['2', '3', '10', '3', '--mute']);
        $this->assertEmpty($response->output);

        // Mute + verbose
        $response = $this->runDirective(['2', '3', '10', '1', '--verbose', '--mute']);
        $this->assertEmpty($response->output);

        // Mute + duration
        $response = $this->runDirective(['2', '3', '--mute']);
        $this->assertEmpty($response->output);

        // Mute + all options
        $response = $this->runDirective(['2', '3', '20', '4', '--unique-only', '--mute']);
        $this->assertEmpty($response->output);

        // Mute over multiple cycles
        $response = $this->runDirective(['1', '3', '20', '2', '--mute']);
        $this->assertEmpty($response->output);
    }

    // ==================== TESTS FQCN FILTER ====================

    /**
     * Combine FQCN filter tests using different notations and options
     */
    public function test_execute_with_fqcn_filter_combinations(): void
    {
        // Test avec dot notation
        $response = $this->runDirective([
            '2', '3', '10', '1',
            '[AndyDefer.Task.Tests.Fixtures.Tasks.TestUniqueTask]',
        ]);

        $this->assertStringContainsString('Starting task watch', $response->output);
        $this->assertStringContainsString('Final Status', $response->output);

        // Test avec backslash notation
        $response = $this->runDirective([
            '2', '3', '10', '1',
            '[AndyDefer\\Task\\Tests\\Fixtures\\Tasks\\TestUniqueTask]',
        ]);

        $this->assertStringContainsString('Starting task watch', $response->output);
        $this->assertStringContainsString('Final Status', $response->output);

        // Test avec FQCN vide
        $response = $this->runDirective([
            '2', '3', '10', '1',
            '[]',
        ]);

        $this->assertStringContainsString('Starting task watch', $response->output);
        $this->assertStringContainsString('Final Status', $response->output);

        // Test avec FQCN invalide
        $response = $this->runDirective([
            '2', '3', '10', '1',
            '[Invalid.Task.Class]',
        ]);

        $this->assertStringContainsString('Error', $response->output);
        $this->assertSame(5, $response->exit_code->value);
    }

    public function test_execute_with_fqcn_filter_and_flags(): void
    {
        // Test avec FQCN + unique-only
        $response = $this->runDirective([
            '2', '3', '10', '1',
            '[AndyDefer.Task.Tests.Fixtures.Tasks.TestUniqueTask]',
            '--unique-only',
        ]);

        $this->assertStringContainsString('Options: --unique-only', $response->output);
        $this->assertStringContainsString('Final Status', $response->output);

        // Test avec FQCN + recurring-only
        $response = $this->runDirective([
            '2', '3', '10', '1',
            '[AndyDefer.Task.Tests.Fixtures.Tasks.TestRecurringTask]',
            '--recurring-only',
        ]);

        $this->assertStringContainsString('Options: --recurring-only', $response->output);
        $this->assertStringContainsString('Final Status', $response->output);

        // Test avec FQCN + verbose
        $response = $this->runDirective([
            '2', '3', '10', '1',
            '[AndyDefer.Task.Tests.Fixtures.Tasks.TestUniqueTask]',
            '--verbose',
        ]);

        $this->assertStringContainsString('Options: --verbose', $response->output);
        $this->assertStringContainsString('Final Status', $response->output);

        // Test avec FQCN + mute
        $response = $this->runDirective([
            '2', '3', '10', '1',
            '[AndyDefer.Task.Tests.Fixtures.Tasks.TestUniqueTask]',
            '--mute',
        ]);

        $this->assertEmpty($response->output);
    }

    public function test_execute_with_fqcn_filter_parallel_and_options(): void
    {
        // Test avec FQCN + parallel + verbose
        $response = $this->runDirective([
            '2', '3', '10', '3',
            '[AndyDefer.Task.Tests.Fixtures.Tasks.TestUniqueTask]',
            '--verbose',
        ]);

        $this->assertStringContainsString('Workers: 3', $response->output);
        $this->assertStringContainsString('Options: --verbose', $response->output);
        $this->assertStringContainsString('Final Status', $response->output);

        // Test avec FQCN + parallel + mute
        $response = $this->runDirective([
            '2', '3', '10', '4',
            '[AndyDefer.Task.Tests.Fixtures.Tasks.TestUniqueTask]',
            '--mute',
        ]);

        $this->assertEmpty($response->output);

        // Test avec FQCN + limit + parallel
        $response = $this->runDirective([
            '2', '3', '20', '4',
            '[AndyDefer.Task.Tests.Fixtures.Tasks.TestUniqueTask]',
        ]);

        $this->assertStringContainsString('Limit: 20', $response->output);
        $this->assertStringContainsString('Workers: 4', $response->output);
        $this->assertStringContainsString('Final Status', $response->output);
    }

    public function test_execute_with_multiple_fqcn_filters_and_all_options(): void
    {
        $response = $this->runDirective([
            '2', '3', '30', '3',
            '[AndyDefer.Task.Tests.Fixtures.Tasks.TestUniqueTask, AndyDefer.Task.Tests.Fixtures.Tasks.HelloUniqueTask]',
            '--unique-only',
            '--verbose',
        ]);

        $this->assertStringContainsString('Limit: 30', $response->output);
        $this->assertStringContainsString('Workers: 3', $response->output);
        $this->assertStringContainsString('Options: --unique-only --verbose', $response->output);
        $this->assertStringContainsString('Final Status', $response->output);
    }
}
