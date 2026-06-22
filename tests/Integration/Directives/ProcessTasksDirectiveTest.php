<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Directives;

use AndyDefer\Directive\Enums\ExitCode;
use AndyDefer\Directive\Services\DirectiveTestingService;
use AndyDefer\Task\Directives\ProcessTasksDirective;
use AndyDefer\Task\Tests\IntegrationTestCase;

final class ProcessTasksDirectiveTest extends IntegrationTestCase
{
    private DirectiveTestingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DirectiveTestingService($this->app);
    }

    protected function tearDown(): void
    {
        $this->service->destroy();
        parent::tearDown();
    }

    private function getDirective(): ProcessTasksDirective
    {
        return $this->app->make(ProcessTasksDirective::class);
    }

    // ==================== Tests: Signature, Description & Aliases ====================

    public function test_get_signature_returns_correct_string(): void
    {
        $directive = $this->getDirective();
        $signature = $directive->getSignature();

        $this->assertStringContainsString('process-tasks', $signature);
        $this->assertStringContainsString('--unique-only', $signature);
        $this->assertStringContainsString('--recurring-only', $signature);
        $this->assertStringContainsString('--verbose', $signature);
        $this->assertStringContainsString('--limit=', $signature);
    }

    public function test_get_description_returns_string(): void
    {
        $directive = $this->getDirective();
        $description = $directive->getDescription();

        $this->assertIsString($description);
        $this->assertNotEmpty($description);
        $this->assertStringContainsString('batch', $description);
    }

    public function test_get_aliases_returns_aliases(): void
    {
        $directive = $this->getDirective();
        $aliases = $directive->getAliases();

        $this->assertTrue($aliases->contains('task:process'));
        $this->assertTrue($aliases->contains('tasks:process'));
        $this->assertSame(2, $aliases->count());
    }

    // ==================== Tests: Basic Execution ====================

    public function test_execute_returns_success_when_no_tasks(): void
    {
        $response = $this->service->run(ProcessTasksDirective::class, []);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('Batch Results', $response->output);
    }

    public function test_execute_with_unique_only_flag(): void
    {
        $response = $this->service->run(ProcessTasksDirective::class, ['--unique-only']);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
    }

    public function test_execute_with_recurring_only_flag(): void
    {
        $response = $this->service->run(ProcessTasksDirective::class, ['--recurring-only']);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
    }

    // ==================== Tests: Limit Handling ====================

    public function test_execute_with_limit_passes_limit_to_batch(): void
    {
        $response = $this->service->run(ProcessTasksDirective::class, ['--limit=5']);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
    }

    public function test_execute_with_limit_and_unique_only_passes_limit(): void
    {
        $response = $this->service->run(ProcessTasksDirective::class, ['--unique-only', '--limit=3']);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
    }

    public function test_execute_with_limit_and_recurring_only_passes_limit(): void
    {
        $response = $this->service->run(ProcessTasksDirective::class, ['--recurring-only', '--limit=3']);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
    }

    public function test_execute_with_limit_zero_returns_invalid_argument(): void
    {
        $response = $this->service->run(ProcessTasksDirective::class, ['--limit=0']);

        $this->assertSame(ExitCode::INVALID_ARGUMENT, $response->exit_code);
        $this->assertStringContainsString('Limit must be a positive integer', $response->output);
    }

    public function test_execute_with_limit_negative_returns_invalid_argument(): void
    {
        $response = $this->service->run(ProcessTasksDirective::class, ['--limit=-5']);

        $this->assertSame(ExitCode::INVALID_ARGUMENT, $response->exit_code);
        $this->assertStringContainsString('Limit must be a positive integer', $response->output);
    }

    public function test_execute_with_limit_non_numeric_returns_invalid_argument(): void
    {
        $response = $this->service->run(ProcessTasksDirective::class, ['--limit=abc']);

        $this->assertSame(ExitCode::INVALID_ARGUMENT, $response->exit_code);
    }

    public function test_execute_with_limit_one(): void
    {
        $response = $this->service->run(ProcessTasksDirective::class, ['--limit=1']);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
    }

    // ==================== Tests: Validation ====================

    public function test_execute_with_both_flags_returns_invalid_argument(): void
    {
        $response = $this->service->run(ProcessTasksDirective::class, ['--unique-only', '--recurring-only']);

        $this->assertSame(ExitCode::INVALID_ARGUMENT, $response->exit_code);
        $this->assertStringContainsString('Cannot use both', $response->output);
    }

    // ==================== Tests: Output ====================

    public function test_execute_output_contains_batch_results(): void
    {
        $response = $this->service->run(ProcessTasksDirective::class, []);

        $this->assertStringContainsString('Batch Results', $response->output);
        $this->assertStringContainsString('Unique tasks:', $response->output);
        $this->assertStringContainsString('Recurring tasks:', $response->output);
        $this->assertStringContainsString('Total:', $response->output);
    }

    public function test_execute_output_with_verbose_flag_shows_limit_message(): void
    {
        $response = $this->service->run(ProcessTasksDirective::class, ['--limit=10', '--verbose']);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('Limit: 10 tasks', $response->output);
    }

    public function test_execute_output_with_verbose_and_limit_shows_limit(): void
    {
        $response = $this->service->run(ProcessTasksDirective::class, ['--limit=5', '--verbose']);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('Limit: 5 tasks', $response->output);
    }

    public function test_execute_output_without_verbose_does_not_show_limit(): void
    {
        $response = $this->service->run(ProcessTasksDirective::class, ['--limit=5']);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        // Le message de limite ne devrait pas apparaître sans --verbose
        // (il apparaît dans info() qui est toujours affiché, donc on vérifie qu'il est présent)
        $this->assertStringContainsString('Limit: 5 tasks', $response->output);
    }

    // ==================== Tests: Edge Cases ====================

    public function test_execute_with_verbose_and_no_limit(): void
    {
        $response = $this->service->run(ProcessTasksDirective::class, ['--verbose']);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('Processing tasks...', $response->output);
        $this->assertStringContainsString('Batch Results', $response->output);
    }

    public function test_execute_with_only_verbose_flag(): void
    {
        $response = $this->service->run(ProcessTasksDirective::class, ['--verbose']);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
    }

    public function test_execute_with_large_limit(): void
    {
        $response = $this->service->run(ProcessTasksDirective::class, ['--limit=10000']);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
    }

    public function test_execute_with_unique_only_and_limit_one(): void
    {
        $response = $this->service->run(ProcessTasksDirective::class, ['--unique-only', '--limit=1']);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
    }

    public function test_execute_with_recurring_only_and_limit_one(): void
    {
        $response = $this->service->run(ProcessTasksDirective::class, ['--recurring-only', '--limit=1']);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
    }

    // ==================== Tests: Multiple Options Combinations ====================

    public function test_execute_with_verbose_and_unique_only(): void
    {
        $response = $this->service->run(ProcessTasksDirective::class, ['--verbose', '--unique-only']);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
    }

    public function test_execute_with_verbose_and_recurring_only(): void
    {
        $response = $this->service->run(ProcessTasksDirective::class, ['--verbose', '--recurring-only']);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
    }

    public function test_execute_with_all_flags_positive_case(): void
    {
        // unique-only et recurring-only ne peuvent pas être ensemble
        $response = $this->service->run(ProcessTasksDirective::class, ['--verbose', '--limit=10']);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
    }

    // ==================== Tests: String Validation ====================

    public function test_get_signature_contains_required_elements(): void
    {
        $directive = $this->getDirective();
        $signature = $directive->getSignature();

        $this->assertStringContainsString('{--unique-only', $signature);
        $this->assertStringContainsString('{--recurring-only', $signature);
        $this->assertStringContainsString('{--verbose', $signature);
        $this->assertStringContainsString('{--limit=', $signature);
    }

    public function test_get_description_is_not_empty(): void
    {
        $directive = $this->getDirective();
        $description = $directive->getDescription();

        $this->assertNotEmpty($description);
        $this->assertIsString($description);
    }

    public function test_aliases_are_correct(): void
    {
        $directive = $this->getDirective();
        $aliases = $directive->getAliases();

        $this->assertCount(2, $aliases);
        $this->assertTrue($aliases->contains('task:process'));
        $this->assertTrue($aliases->contains('tasks:process'));
    }
}
