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

        // ✅ Forcer l'exécution des migrations
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
        $response = $this->runDirective(['2', '4', '4']);

        $this->assertStringContainsString('Starting task watch', $response->output);
        $this->assertStringContainsString('Interval: 2s', $response->output);
        $this->assertStringContainsString('Duration: 4s', $response->output);
        $this->assertStringContainsString('Press Ctrl+C to stop', $response->output);
    }

    public function test_execute_with_duration(): void
    {
        $response = $this->runDirective(['2', '6']);

        $this->assertStringContainsString('Interval: 2s', $response->output);
        $this->assertStringContainsString('Duration: 6s', $response->output);
        $this->assertStringContainsString('Final Status', $response->output);
    }

    public function test_execute_with_limit(): void
    {
        $response = $this->runDirective(['2', '4', '10']);

        $this->assertStringContainsString('Limit: 10', $response->output);
        $this->assertStringContainsString('Final Status', $response->output);
    }

    public function test_execute_with_parallel_workers(): void
    {
        $response = $this->runDirective(['2', '4', '10', '2']);

        $this->assertStringContainsString('Workers: 2', $response->output);
        $this->assertStringContainsString('Starting 2 parallel workers', $response->output);
        $this->assertStringContainsString('Final Status', $response->output);
    }

    public function test_execute_with_unique_only(): void
    {
        $response = $this->runDirective(['2', '4', '10', '1', '--unique-only']);

        $this->assertStringContainsString('Options: --unique-only', $response->output);
        $this->assertStringContainsString('Final Status', $response->output);
    }

    public function test_execute_with_recurring_only(): void
    {
        $response = $this->runDirective(['2', '4', '10', '1', '--recurring-only']);

        $this->assertStringContainsString('Options: --recurring-only', $response->output);
        $this->assertStringContainsString('Final Status', $response->output);
    }

    public function test_execute_with_verbose_mode(): void
    {
        $response = $this->runDirective(['2', '4', '10', '1', '--verbose']);

        $this->assertStringContainsString('Options: --verbose', $response->output);
        $this->assertStringContainsString('Final Status', $response->output);
    }

    public function test_execute_with_mute_option_should_not_output_anything(): void
    {
        $response = $this->runDirective(['2', '4', '10', '1', '--mute']);

        $this->assertEmpty($response->output);
    }

    public function test_execute_with_mute_and_parallel_should_not_output_anything(): void
    {
        $response = $this->runDirective(['2', '4', '10', '3', '--mute']);

        $this->assertEmpty($response->output);
    }

    public function test_execute_with_mute_and_verbose_should_not_output_anything(): void
    {
        $response = $this->runDirective(['2', '4', '10', '1', '--verbose', '--mute']);

        $this->assertEmpty($response->output);
    }

    public function test_execute_with_mute_and_duration_should_not_output_anything(): void
    {
        $response = $this->runDirective(['2', '8', '--mute']);

        $this->assertEmpty($response->output);
    }

    public function test_execute_with_mute_and_all_options_should_not_output_anything(): void
    {
        $response = $this->runDirective(['2', '6', '20', '4', '--unique-only', '--mute']);

        $this->assertEmpty($response->output);
    }

    public function test_mute_compared_to_normal_execution(): void
    {
        $responseWithMute = $this->runDirective(['2', '4', '10', '1', '--mute']);

        $this->assertEmpty($responseWithMute->output);
    }

    public function test_execute_with_mute_over_multiple_cycles_should_not_output_anything(): void
    {
        $response = $this->runDirective(['1', '5', '20', '2', '--mute']);

        $this->assertEmpty($response->output);
    }

    public function test_execute_with_mute_returns_correct_exit_code(): void
    {
        $response = $this->runDirective(['2', '4', '10', '1', '--mute']);

        $this->assertSame(0, $response->exit_code->value);
    }
}
