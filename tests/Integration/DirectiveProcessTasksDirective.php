<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Directives;

use AndyDefer\Directive\Enums\ExitCode;
use AndyDefer\Directive\Services\DirectiveTestingService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Configs\RecurringTaskConfig;
use AndyDefer\Task\Contracts\Repositories\RecurringTaskRepositoryInterface;
use AndyDefer\Task\Contracts\Services\RecurringTaskServiceInterface;
use AndyDefer\Task\Directives\ProcessTasksDirective;
use AndyDefer\Task\Enums\RecurringTaskStatus;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\Repositories\TaskExecutionDebugRepository;
use AndyDefer\Task\Repositories\UniqueTaskRepository;
use AndyDefer\Task\Tests\Fixtures\Tasks\FailingRecurringTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\FailingTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestRecurringTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestUniqueTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Ramsey\Uuid\Uuid;

final class ProcessTasksDirectiveTest extends IntegrationTestCase
{
    use DatabaseMigrations;

    private DirectiveTestingService $service;

    private UniqueTaskRepository $uniqueRepository;

    private TaskExecutionDebugRepository $debugRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runDatabaseMigrations();

        // Créer le service de test avec Laravel
        $this->service = new DirectiveTestingService($this->app);

        // Repository pour vérifier les données
        $this->debugRepository = new TaskExecutionDebugRepository;
        $this->uniqueRepository = new UniqueTaskRepository($this->debugRepository);
    }

    protected function tearDown(): void
    {
        $this->service->destroy();
        parent::tearDown();
    }

    // ==================== HELPERS ====================

    private function createUniqueTask(
        string $alias,
        ?string $id = null,
        UniqueTaskStatus $status = UniqueTaskStatus::PENDING,
        ?\DateTimeInterface $scheduledAt = null,
        int $gracePeriodSeconds = 86400
    ): void {
        $scheduledAt = $scheduledAt ?? now()->subHours(2);
        $id = $id ?? (string) Uuid::uuid4();

        $record = new UniqueTaskRecord(
            id: new TaskIdVO($id),
            alias: new TaskSignatureVO($alias),
            fqcn: TestUniqueTask::class,
            payload: StrictDataObject::from(['test' => 'unique']),
            scheduled_at: new Iso8601DateTimeVO($scheduledAt->format('Y-m-d\TH:i:sP')),
            grace_period_seconds: $gracePeriodSeconds,
            status: $status,
            attempts: new CounterVO(0),
            max_attempts: new CounterVO(3),
        );

        $this->uniqueRepository->create($record);
    }

    private function createRecurringTask(
        string $alias,
        RecurringTaskStatus $status = RecurringTaskStatus::WAITING,
        ?\DateTimeInterface $startAt = null
    ): void {
        $startAt = $startAt ?? now()->subHours(2);

        // Utiliser le service pour créer la tâche
        $config = new RecurringTaskConfig(
            alias: new TaskSignatureVO($alias),
            description: 'Test recurring task',
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO($startAt->format('Y-m-d\TH:i:sP')),
            end_at: new Iso8601DateTimeVO(now()->addDays(7)->format('Y-m-d\TH:i:sP')),
            max_attempts: new CounterVO(3),
        );

        $service = $this->app->make(RecurringTaskServiceInterface::class);
        $service->register(
            TestRecurringTask::class,
            StrictDataObject::from(['test' => 'recurring']),
            $config
        );
    }

    // ==================== TESTS ====================

    public function test_process_tasks_without_options(): void
    {
        // Créer des tâches
        $this->createUniqueTask('unique-1');
        $this->createUniqueTask('unique-2');
        $this->createRecurringTask('recurring-1');

        $response = $this->service->run(ProcessTasksDirective::class, []);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('Unique tasks: 2 processed', $response->output);
        $this->assertStringContainsString('Recurring tasks: 1 processed', $response->output);
    }

    public function test_process_tasks_with_unique_only_option(): void
    {
        $this->createUniqueTask('unique-1');
        $this->createUniqueTask('unique-2');
        $this->createRecurringTask('recurring-1');

        $response = $this->service->run(
            ProcessTasksDirective::class,
            ['--unique-only']
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('Unique tasks: 2 processed', $response->output);
        $this->assertStringContainsString('Recurring tasks: 0 processed', $response->output);
    }

    public function test_process_tasks_with_recurring_only_option(): void
    {
        $this->createUniqueTask('unique-1');
        $this->createUniqueTask('unique-2');
        $this->createRecurringTask('recurring-1');

        $response = $this->service->run(
            ProcessTasksDirective::class,
            ['--recurring-only']
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('Unique tasks: 0 processed', $response->output);
        $this->assertStringContainsString('Recurring tasks: 1 processed', $response->output);
    }

    public function test_process_tasks_with_limit_option(): void
    {
        // Créer 5 tâches uniques
        for ($i = 1; $i <= 5; $i++) {
            $this->createUniqueTask("unique-{$i}");
        }

        $response = $this->service->run(
            ProcessTasksDirective::class,
            ['--limit=3']
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('Limit: 3 tasks', $response->output);
        $this->assertStringContainsString('Unique tasks: 3 processed', $response->output);
    }

    public function test_process_tasks_with_verbose_option_shows_errors(): void
    {
        $this->createUniqueTask('unique-1');

        $response = $this->service->run(
            ProcessTasksDirective::class,
            ['--verbose']
        );

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('=== Batch Results ===', $response->output);
        $this->assertStringContainsString('=== Failed Tasks ===', $response->output);
    }

    public function test_process_tasks_returns_failure_when_tasks_fail(): void
    {
        // Créer une tâche qui va échouer (FailingTask)
        $id = (string) Uuid::uuid4();
        $record = new UniqueTaskRecord(
            id: new TaskIdVO($id),
            alias: new TaskSignatureVO('failing-task'),
            fqcn: FailingTask::class,
            payload: StrictDataObject::from(['test' => 'failing']),
            scheduled_at: new Iso8601DateTimeVO(now()->subHours(2)->format('Y-m-d\TH:i:sP')),
            grace_period_seconds: 86400,
            status: UniqueTaskStatus::PENDING,
            attempts: new CounterVO(2), // Sera max_attempts après échec
            max_attempts: new CounterVO(3),
        );

        $this->uniqueRepository->create($record);

        $response = $this->service->run(ProcessTasksDirective::class, []);

        $this->assertSame(ExitCode::FAILURE, $response->exit_code);
        $this->assertStringContainsString('Unique tasks: 0 processed', $response->output);
        $this->assertStringContainsString('Recurring tasks: 0 processed', $response->output);
    }

    public function test_process_tasks_with_invalid_limit_returns_error(): void
    {
        $response = $this->service->run(
            ProcessTasksDirective::class,
            ['--limit=0']
        );

        $this->assertSame(ExitCode::INVALID_ARGUMENT, $response->exit_code);
        $this->assertStringContainsString('Limit must be a positive integer', $response->output);
    }

    public function test_process_tasks_with_limit_negative_returns_error(): void
    {
        $response = $this->service->run(
            ProcessTasksDirective::class,
            ['--limit=-5']
        );

        $this->assertSame(ExitCode::INVALID_ARGUMENT, $response->exit_code);
        $this->assertStringContainsString('Limit must be a positive integer', $response->output);
    }

    public function test_process_tasks_with_both_unique_and_recurring_returns_error(): void
    {
        $response = $this->service->run(
            ProcessTasksDirective::class,
            ['--unique-only', '--recurring-only']
        );

        $this->assertSame(ExitCode::INVALID_ARGUMENT, $response->exit_code);
        $this->assertStringContainsString('Cannot use both --unique-only and --recurring-only', $response->output);
    }

    public function test_process_tasks_with_no_tasks_to_process(): void
    {
        // Aucune tâche créée
        $response = $this->service->run(ProcessTasksDirective::class, []);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertStringContainsString('Unique tasks: 0 processed', $response->output);
        $this->assertStringContainsString('Recurring tasks: 0 processed', $response->output);
    }

    public function test_process_tasks_shows_duration(): void
    {
        $this->createUniqueTask('unique-1');

        $response = $this->service->run(ProcessTasksDirective::class, []);

        $this->assertSame(ExitCode::SUCCESS, $response->exit_code);
        $this->assertMatchesRegularExpression('/in \d+ ms/', $response->output);
    }

    public function test_process_tasks_with_recurring_failure(): void
    {
        // Créer une tâche récurrente qui va échouer
        $config = new RecurringTaskConfig(
            alias: new TaskSignatureVO('failing-recurring'),
            description: 'Failing recurring task',
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(now()->subHours(2)->format('Y-m-d\TH:i:sP')),
            end_at: new Iso8601DateTimeVO(now()->addDays(7)->format('Y-m-d\TH:i:sP')),
            max_attempts: new CounterVO(3),
        );

        $service = $this->app->make(RecurringTaskServiceInterface::class);
        $alias = $service->register(
            FailingRecurringTask::class,
            StrictDataObject::from(['should_fail' => true]),
            $config
        );

        // Passer en PLAYING
        $repo = $this->app->make(RecurringTaskRepositoryInterface::class);
        $task = $repo->findByAlias($alias->value);
        $task->update(['status' => RecurringTaskStatus::PLAYING->value]);

        $response = $this->service->run(ProcessTasksDirective::class, []);

        $this->assertSame(ExitCode::FAILURE, $response->exit_code);
        $this->assertStringContainsString('Recurring tasks: 0 processed', $response->output);
    }
}
