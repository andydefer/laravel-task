<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Abstract;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\LaravelJsonl\Contexts\JsonlContext;
use AndyDefer\LaravelJsonl\JsonlService;
use AndyDefer\LaravelJsonl\Strategies\TemporalPathStrategy;
use AndyDefer\Logger\Configs\LoggerConfig;
use AndyDefer\Logger\LoggerService;
use AndyDefer\PhpServices\Enums\PermissionMode;
use AndyDefer\PhpServices\Services\FileSystemService;
use AndyDefer\Task\Contexts\RecurringTaskContext;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestRecurringTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;
use Ramsey\Uuid\Uuid;

final class RecurringTaskTest extends IntegrationTestCase
{
    private TestRecurringTask $task;

    private RecurringTaskContext $context;

    private LoggerService $logger;

    private HydrationService $hydration;

    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();

        $config = new LoggerConfig($this->app->make(ConfigRepository::class));

        $this->logPath = $config->basePath();
        $fs = new FileSystemService;

        if (! $fs->isDirectory($this->logPath)) {
            $fs->makeDirectory($this->logPath, PermissionMode::DIRECTORY, true);
        }

        $pathStrategy = new TemporalPathStrategy($this->logPath);
        $jsonlContext = new JsonlContext;

        $jsonlService = new JsonlService(
            pathStrategy: $pathStrategy,
            fileSystem: $fs,
            context: $jsonlContext,
            defaultBufferSize: $config->bufferSize(),
        );

        $this->hydration = new HydrationService;

        $this->logger = new LoggerService(
            jsonlService: $jsonlService,
            hydrationService: $this->hydration,
        );

        // ✅ Utiliser un UUID valide
        $this->context = new RecurringTaskContext;
        $this->context->setAlias(new TaskAliasVO(
            type: ('recurring'),
            uuid: (string) Uuid::uuid4()
        ));
        $this->context->setIntervalSeconds(new DurationVO(3600));
        $this->context->setStartAt(new Iso8601DateTimeVO(Carbon::now()->toIso8601String()));
        $this->context->setNextRunAt(new Iso8601DateTimeVO(Carbon::now()->addSeconds(3600)->toIso8601String()));
        $this->context->setLaravelApp($this->app);

        $this->task = new TestRecurringTask(
            $this->context,
            $this->logger,
            $this->hydration,
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $fs = new FileSystemService;
        if ($fs->isDirectory($this->logPath)) {
            $fs->deleteDirectory($this->logPath);
        }
    }

    // ✅ Récupérer l'alias pour les assertions
    private function getAliasValue(): string
    {
        return $this->context->getAlias()->getValue();
    }

    public function test_executes_task_successfully(): void
    {
        $payload = StrictDataObject::from([
            'test_data' => 'recurring_success',
        ]);

        $this->task->execute($payload);

        $log = $this->task->getExecutionLog();
        $this->assertCount(1, $log);
        $this->assertEquals('recurring_success', $log[0]['payload']['test_data']);

        $this->logger->flush();
        $fs = new FileSystemService;
        $today = Carbon::now()->format('Y-m-d');
        $logFiles = $fs->glob($this->logPath.'/'.$today.'/*.jsonl');
        $this->assertNotEmpty($logFiles);
    }

    public function test_logs_error_on_failure(): void
    {
        $this->task->setFailOn('Recurring failure');

        $payload = StrictDataObject::from([
            'test_data' => 'recurring_fail',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Recurring failure');

        try {
            $this->task->execute($payload);
        } catch (\Throwable $e) {
            $this->logger->flush();
            $fs = new FileSystemService;
            $today = Carbon::now()->format('Y-m-d');
            $logFiles = $fs->glob($this->logPath.'/'.$today.'/*.jsonl');
            $this->assertNotEmpty($logFiles);

            $content = '';
            foreach ($logFiles as $file) {
                $content .= $fs->get($file);
            }
            $this->assertStringContainsString('task_failed', $content);
            $this->assertStringContainsString('Recurring failure', $content);

            throw $e;
        }
    }

    public function test_preserves_context_data(): void
    {
        $aliasValue = $this->getAliasValue();
        $this->assertStringContainsString('recurring@', $aliasValue);
        $this->assertEquals(3600, $this->context->getIntervalSeconds()->seconds);
        $this->assertNotNull($this->context->getStartAt());
        $this->assertNotNull($this->context->getNextRunAt());
        $this->assertNull($this->context->getLastRunAt());
        $this->assertNull($this->context->getEndAt());
    }

    public function test_handles_end_at_context(): void
    {
        $endAt = new Iso8601DateTimeVO(Carbon::now()->addDays(7)->toIso8601String());
        $this->context->setEndAt($endAt);

        $this->assertEquals($endAt->getValue(), $this->context->getEndAt()->getValue());
    }

    public function test_handles_last_run_at_context(): void
    {
        $lastRunAt = new Iso8601DateTimeVO(Carbon::now()->subHours(2)->toIso8601String());
        $this->context->setLastRunAt($lastRunAt);

        $this->assertEquals($lastRunAt->getValue(), $this->context->getLastRunAt()->getValue());
    }

    public function test_handles_start_at_context(): void
    {
        $startAt = new Iso8601DateTimeVO(Carbon::now()->addHours(3)->toIso8601String());
        $this->context->setStartAt($startAt);

        $this->assertEquals($startAt->getValue(), $this->context->getStartAt()->getValue());
    }

    public function test_handles_next_run_at_context(): void
    {
        $nextRunAt = new Iso8601DateTimeVO(Carbon::now()->addHours(5)->toIso8601String());
        $this->context->setNextRunAt($nextRunAt);

        $this->assertEquals($nextRunAt->getValue(), $this->context->getNextRunAt()->getValue());
    }

    public function test_context_has_laravel_app(): void
    {
        $this->assertNotNull($this->context->getLaravelApp());
    }

    public function test_task_has_logger(): void
    {
        $this->assertNotNull($this->task);
        $this->assertInstanceOf(LoggerService::class, $this->logger);
    }

    public function test_task_can_log_info_without_execution(): void
    {
        $this->logger->flush();

        $this->task->info(new DescriptionVO('Info without execution'));

        $this->logger->flush();
        $fs = new FileSystemService;
        $today = Carbon::now()->format('Y-m-d');
        $logFiles = $fs->glob($this->logPath.'/'.$today.'/*.jsonl');
        $this->assertNotEmpty($logFiles);

        $content = '';
        foreach ($logFiles as $file) {
            $content .= $fs->get($file);
        }
        $this->assertStringContainsString('Info without execution', $content);
    }

    public function test_task_can_log_error_without_execution(): void
    {
        $this->logger->flush();

        $this->task->error(new DescriptionVO('Error without execution'));

        $this->logger->flush();
        $fs = new FileSystemService;
        $today = Carbon::now()->format('Y-m-d');
        $logFiles = $fs->glob($this->logPath.'/'.$today.'/*.jsonl');
        $this->assertNotEmpty($logFiles);

        $content = '';
        foreach ($logFiles as $file) {
            $content .= $fs->get($file);
        }
        $this->assertStringContainsString('Error without execution', $content);
    }

    public function test_context_can_be_modified_after_creation(): void
    {
        $newAlias = new TaskAliasVO(
            type: ('recurring'),
            uuid: (string) Uuid::uuid4()
        );
        $this->context->setAlias($newAlias);

        $this->assertStringContainsString('recurring@', $this->context->getAlias()->getValue());

        $newInterval = new DurationVO(7200);
        $this->context->setIntervalSeconds($newInterval);

        $this->assertEquals(7200, $this->context->getIntervalSeconds()->seconds);
    }

    public function test_task_execution_with_empty_payload(): void
    {
        $payload = StrictDataObject::from([]);

        $this->task->execute($payload);

        $log = $this->task->getExecutionLog();
        $this->assertCount(1, $log);
        $this->assertEquals([], $log[0]['payload']);
    }

    public function test_task_execution_with_complex_payload(): void
    {
        $payload = StrictDataObject::from([
            'user' => [
                'id' => 123,
                'name' => 'John Doe',
                'email' => 'john@example.com',
            ],
            'settings' => [
                'notify' => true,
                'priority' => 'high',
            ],
            'items' => [1, 2, 3, 4, 5],
        ]);

        $this->task->execute($payload);

        $log = $this->task->getExecutionLog();
        $this->assertCount(1, $log);

        $logPayload = $log[0]['payload'];
        $this->assertEquals(123, $logPayload['user']['id']);
        $this->assertEquals('John Doe', $logPayload['user']['name']);
        $this->assertEquals('high', $logPayload['settings']['priority']);
        $this->assertEquals([1, 2, 3, 4, 5], $logPayload['items']);
    }

    public function test_before_hook_executed(): void
    {
        $payload = StrictDataObject::from(['test' => 'before_hook']);

        $this->task->execute($payload);

        $this->assertTrue($this->task->wasBeforeCalled());
    }

    public function test_after_hook_executed_on_success(): void
    {
        $payload = StrictDataObject::from(['test' => 'after_hook_success']);

        $this->task->execute($payload);

        $this->assertTrue($this->task->wasAfterCalled());
        $this->assertNull($this->task->getAfterError());
    }

    public function test_after_hook_executed_on_failure(): void
    {
        $this->task->setFailOn('Hook failure test');

        $payload = StrictDataObject::from(['test' => 'after_hook_failure']);

        try {
            $this->task->execute($payload);
        } catch (\RuntimeException $e) {
            // Expected
        }

        $this->assertTrue($this->task->wasAfterCalled());
        $this->assertNotNull($this->task->getAfterError());
        $this->assertEquals('Hook failure test', $this->task->getAfterError()->getValue());
    }

    public function test_info_logs_are_written(): void
    {
        $this->logger->flush();

        $message = new DescriptionVO('Test info message');
        $this->task->info($message);

        $this->logger->flush();
        $fs = new FileSystemService;
        $today = Carbon::now()->format('Y-m-d');
        $logFiles = $fs->glob($this->logPath.'/'.$today.'/*.jsonl');
        $this->assertNotEmpty($logFiles);

        $content = '';
        foreach ($logFiles as $file) {
            $content .= $fs->get($file);
        }
        $this->assertStringContainsString('Test info message', $content);
    }

    public function test_error_logs_are_written(): void
    {
        $this->logger->flush();

        $message = new DescriptionVO('Test error message');
        $this->task->error($message);

        $this->logger->flush();
        $fs = new FileSystemService;
        $today = Carbon::now()->format('Y-m-d');
        $logFiles = $fs->glob($this->logPath.'/'.$today.'/*.jsonl');
        $this->assertNotEmpty($logFiles);

        $content = '';
        foreach ($logFiles as $file) {
            $content .= $fs->get($file);
        }
        $this->assertStringContainsString('Test error message', $content);
    }
}
