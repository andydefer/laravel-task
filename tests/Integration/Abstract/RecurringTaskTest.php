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
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

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

        // ✅ CRÉER LA CONFIGURATION DU LOGGER
        $config = new LoggerConfig($this->app->make(ConfigRepository::class));

        // ✅ RÉCUPÉRER LE CHEMIN RÉEL DES LOGS
        $this->logPath = $config->basePath();
        $fs = new FileSystemService;

        if (! $fs->isDirectory($this->logPath)) {
            $fs->makeDirectory($this->logPath, PermissionMode::DIRECTORY, true);
        }

        // ✅ STRATEGIE DE CHEMIN
        $pathStrategy = new TemporalPathStrategy($this->logPath);

        // ✅ CONTEXTE JSONL
        $jsonlContext = new JsonlContext;

        // ✅ SERVICE JSONL
        $jsonlService = new JsonlService(
            pathStrategy: $pathStrategy,
            fileSystem: $fs,
            context: $jsonlContext,
            defaultBufferSize: $config->bufferSize(),
        );

        // ✅ HYDRATION SERVICE
        $this->hydration = new HydrationService;

        // ✅ LOGGER SERVICE
        $this->logger = new LoggerService(
            jsonlService: $jsonlService,
            hydrationService: $this->hydration,
        );

        // ✅ CONTEXTE DE LA TÂCHE
        $this->context = new RecurringTaskContext;
        $this->context->setAlias(new TaskSignatureVO('test-recurring'));
        $this->context->setIntervalSeconds(new CounterVO(3600));
        $this->context->setStartAt(new Iso8601DateTimeVO(now()->toIso8601String()));
        $this->context->setNextRunAt(new Iso8601DateTimeVO(now()->addSeconds(3600)->toIso8601String()));
        $this->context->setLaravelApp($this->app);

        // ✅ TÂCHE
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

    public function test_returns_config(): void
    {
        $config = $this->task->getConfig();

        $this->assertEquals('test-recurring', $config->getAlias()->value);
        $this->assertEquals('Test recurring task', $config->getDescription());
        $this->assertEquals(3600, $config->getIntervalSeconds()->value);
        $this->assertEquals(3, $config->getMaxAttempts()->value);
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

        // ✅ Vérifier les logs sur le disque
        $this->logger->flush();
        $fs = new FileSystemService;
        $today = date('Y-m-d');
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
            // ✅ Vérifier les logs d'erreur sur le disque
            $this->logger->flush();
            $fs = new FileSystemService;
            $today = date('Y-m-d');
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
        $this->assertEquals('test-recurring', $this->context->getAlias()->value);
        $this->assertEquals(3600, $this->context->getIntervalSeconds()->value);
        $this->assertNotNull($this->context->getStartAt());
        $this->assertNotNull($this->context->getNextRunAt());
        $this->assertNull($this->context->getLastRunAt());
        $this->assertNull($this->context->getEndAt());
    }

    public function test_handles_end_at_context(): void
    {
        $endAt = new Iso8601DateTimeVO(now()->addDays(7)->toIso8601String());
        $this->context->setEndAt($endAt);

        $this->assertEquals($endAt->value, $this->context->getEndAt()->value);
    }

    public function test_handles_last_run_at_context(): void
    {
        $lastRunAt = new Iso8601DateTimeVO(now()->subHours(2)->toIso8601String());
        $this->context->setLastRunAt($lastRunAt);

        $this->assertEquals($lastRunAt->value, $this->context->getLastRunAt()->value);
    }

    public function test_handles_start_at_context(): void
    {
        $startAt = new Iso8601DateTimeVO(now()->addHours(3)->toIso8601String());
        $this->context->setStartAt($startAt);

        $this->assertEquals($startAt->value, $this->context->getStartAt()->value);
    }

    public function test_handles_next_run_at_context(): void
    {
        $nextRunAt = new Iso8601DateTimeVO(now()->addHours(5)->toIso8601String());
        $this->context->setNextRunAt($nextRunAt);

        $this->assertEquals($nextRunAt->value, $this->context->getNextRunAt()->value);
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

        $this->task->info('Info without execution');

        $this->logger->flush();
        $fs = new FileSystemService;
        $today = date('Y-m-d');
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

        $this->task->error('Error without execution');

        $this->logger->flush();
        $fs = new FileSystemService;
        $today = date('Y-m-d');
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
        $newAlias = new TaskSignatureVO('modified-recurring');
        $this->context->setAlias($newAlias);

        $this->assertEquals('modified-recurring', $this->context->getAlias()->value);

        $newInterval = new CounterVO(7200);
        $this->context->setIntervalSeconds($newInterval);

        $this->assertEquals(7200, $this->context->getIntervalSeconds()->value);
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
        $this->assertEquals(123, $log[0]['payload']['user']['id']);
        $this->assertEquals('John Doe', $log[0]['payload']['user']['name']);
        $this->assertEquals('high', $log[0]['payload']['settings']['priority']);
        $this->assertEquals([1, 2, 3, 4, 5], $log[0]['payload']['items']);
    }
}
