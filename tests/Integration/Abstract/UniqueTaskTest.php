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
use AndyDefer\Task\Contexts\UniqueTaskContext;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestUniqueTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

final class UniqueTaskTest extends IntegrationTestCase
{
    private TestUniqueTask $task;

    private UniqueTaskContext $context;

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
        $this->context = new UniqueTaskContext;
        $this->context->setTaskId(new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'));
        $this->context->setAlias(new TaskSignatureVO('test-unique'));
        $this->context->setScheduledAt(new Iso8601DateTimeVO(now()->addMinutes(5)->toIso8601String()));
        $this->context->setLaravelApp($this->app);

        // ✅ TÂCHE
        $this->task = new TestUniqueTask(
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

        $this->assertEquals('test-unique', $config->getAlias()->value);
        $this->assertEquals('Test unique task', $config->getDescription());
        $this->assertEquals(3, $config->getMaxAttempts()->value);
    }

    public function test_executes_task_successfully(): void
    {
        $payload = StrictDataObject::from([
            'test_data' => 'success_value',
        ]);

        $this->task->execute($payload);

        $log = $this->task->getExecutionLog();
        $this->assertCount(1, $log);
        $this->assertEquals('success_value', $log[0]['payload']['test_data']);
    }

    public function test_logs_error_on_failure(): void
    {
        $this->task->setFailOn('Test failure');

        $payload = StrictDataObject::from([
            'test_data' => 'fail_value',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Test failure');

        $this->task->execute($payload);
    }

    public function test_logs_info_messages(): void
    {
        // ✅ Vider le buffer pour écrire immédiatement
        $this->logger->flush();

        $this->task->info('Test info message');

        // ✅ Forcer l'écriture
        $this->logger->flush();

        $fs = new FileSystemService;

        // ✅ Chercher dans le bon chemin
        $today = date('Y-m-d');
        $logFiles = $fs->glob($this->logPath.'/'.$today.'/*.jsonl');

        $this->assertNotEmpty($logFiles, "No log files found in {$this->logPath}/{$today}/");

        $content = '';
        foreach ($logFiles as $file) {
            $content .= $fs->get($file);
        }
        $this->assertStringContainsString('Test info message', $content);
    }

    public function test_logs_error_messages(): void
    {
        // ✅ Vider le buffer pour écrire immédiatement
        $this->logger->flush();

        $this->task->error('Test error message');

        // ✅ Forcer l'écriture
        $this->logger->flush();

        $fs = new FileSystemService;

        // ✅ Chercher dans le bon chemin
        $today = date('Y-m-d');
        $logFiles = $fs->glob($this->logPath.'/'.$today.'/*.jsonl');

        $this->assertNotEmpty($logFiles, "No log files found in {$this->logPath}/{$today}/");

        $content = '';
        foreach ($logFiles as $file) {
            $content .= $fs->get($file);
        }
        $this->assertStringContainsString('Test error message', $content);
    }
}
