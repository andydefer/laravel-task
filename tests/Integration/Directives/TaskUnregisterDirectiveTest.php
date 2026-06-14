<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Directives;

use AndyDefer\Directive\Enums\ExitCode;
use AndyDefer\Directive\Services\DirectiveTestingService;
use AndyDefer\Task\Directives\TaskUnregisterDirective;
use AndyDefer\Task\Records\TaskConfigRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Services\TaskRegistryService;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use AndyDefer\DomainStructures\Utils\StrictDataObject;

final class TaskUnregisterDirectiveTest extends IntegrationTestCase
{
    private DirectiveTestingService $service;
    private TaskRegistryService $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DirectiveTestingService($this->app);
        $this->registry = $this->app->make(TaskRegistryService::class);
    }

    protected function tearDown(): void
    {
        $this->service->destroy();
        parent::tearDown();
    }

    private function createTaskPayload(): TaskPayloadRecord
    {
        return new TaskPayloadRecord(
            type: 'test',
            data: new StrictDataObject(['test_data' => 'unregister_test']),
        );
    }

    private function createUniqueTask(): string
    {
        $payload = $this->createTaskPayload();
        return $this->registry->register(
            taskClass: TestTask::class,
            payload: $payload,
        );
    }

    private function createRecurringTask(string $signature): string
    {
        $payload = $this->createTaskPayload();
        $config = new TaskConfigRecord(
            signature: new TaskSignatureVO($signature),
            description: 'Recurring task for testing',
            delay_seconds: new CounterVO(300),
            max_attempts: new CounterVO(3),
            start_at: null,
            end_at: null,
        );

        return $this->registry->register(
            taskClass: TestTask::class,
            payload: $payload,
            override_config: $config,
        );
    }

    public function test_get_signature_returns_correct_string(): void
    {
        $directive = $this->app->make(TaskUnregisterDirective::class);
        $signature = $directive->getSignature();

        $this->assertStringContainsString('task-unregister', $signature);
        $this->assertStringContainsString('{identifier?}', $signature);
        $this->assertStringContainsString('{--force}', $signature);
    }

    public function test_get_description_returns_string(): void
    {
        $directive = $this->app->make(TaskUnregisterDirective::class);
        $description = $directive->getDescription();

        $this->assertIsString($description);
        $this->assertNotEmpty($description);
    }

    public function test_get_aliases_returns_aliases(): void
    {
        $directive = $this->app->make(TaskUnregisterDirective::class);
        $aliases = $directive->getAliases();

        $this->assertTrue($aliases->contains('unregister-task'));
        $this->assertSame(1, $aliases->count());
    }

    public function test_execute_unregisters_unique_task_with_force(): void
    {
        $taskId = $this->createUniqueTask();

        $response = $this->service->run(
            TaskUnregisterDirective::class,
            [$taskId, '--force']
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('unregistered successfully', $response->output);
    }

    public function test_execute_unregisters_recurring_task_with_force(): void
    {
        $signature = 'recurring-test-unregister';
        $this->createRecurringTask($signature);

        $response = $this->service->run(
            TaskUnregisterDirective::class,
            [$signature, '--force']
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('unregistered successfully', $response->output);
    }

    public function test_execute_returns_error_when_no_identifier(): void
    {
        $response = $this->service->run(TaskUnregisterDirective::class, ['--force']);

        $this->assertSame(ExitCode::INVALID_ARGUMENT, $response->exit_code);
        $this->assertStringContainsString('Task identifier is required', $response->output);
    }

    public function test_execute_returns_error_for_nonexistent_unique_task(): void
    {
        $response = $this->service->run(
            TaskUnregisterDirective::class,
            ['550e8400-e29b-41d4-a716-446655449999', '--force']
        );

        $this->assertSame(ExitCode::FAILURE, $response->exit_code);
        $this->assertStringContainsString('Unique task not found', $response->output);
    }

    public function test_execute_succeeds_for_nonexistent_recurring_task(): void
    {
        $response = $this->service->run(
            TaskUnregisterDirective::class,
            ['nonexistent-signature', '--force']
        );

        // La suppression d'une tâche récurrente inexistante est silencieuse
        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
    }

    public function test_execute_returns_error_for_invalid_identifier(): void
    {
        $response = $this->service->run(
            TaskUnregisterDirective::class,
            ['INVALID!!!', '--force']
        );

        $this->assertSame(ExitCode::FAILURE, $response->exit_code);
    }
}
