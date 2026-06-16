<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\LaravelJsonl\Contexts\JsonlContext;
use AndyDefer\LaravelJsonl\JsonlService;
use AndyDefer\LaravelJsonl\Strategies\TemporalPathStrategy;
use AndyDefer\Logger\Configs\LoggerConfig;
use AndyDefer\Logger\LoggerService;
use AndyDefer\PhpServices\Services\FileSystemService;
use AndyDefer\Task\Contexts\TaskContext;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Tests\Fixtures\Tasks\FailingTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use Carbon\Carbon;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

#[AllowMockObjectsWithoutExpectations]
final class AbstractTaskTest extends IntegrationTestCase
{
    private TestTask $task;

    private FailingTask $failingTask;

    private LoggerService $logger;

    private HydrationService $hydration;

    private TaskContext $taskContext;

    private TaskContext $failingContext;

    private string $tempLogDir;

    private string $expectedDate;

    private string $expectedHour;

    private Carbon $fixedNow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->expectedDate = Carbon::now()->format('Y-m-d');  // ← au lieu de $this->fixedNow
        $this->expectedHour = Carbon::now()->format('H');       // ← au lieu de $this->fixedNow

        $this->tempLogDir = sys_get_temp_dir().'/logger_test_'.uniqid();

        $this->setupLogDirectory();

        // Configuration du logger
        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->method('get')->willReturnMap([
            ['logger.path', null, $this->tempLogDir],
            ['logger.retention_days', null, 30],
            ['logger.buffer_size', null, null],
        ]);

        $loggerConfig = new LoggerConfig($configRepository);

        $fileSystemService = new FileSystemService;
        $jsonlContext = new JsonlContext;
        $pathStrategy = new TemporalPathStrategy($loggerConfig->basePath());
        $this->hydration = new HydrationService;

        $jsonlService = new JsonlService(
            pathStrategy: $pathStrategy,
            fileSystem: $fileSystemService,
            context: $jsonlContext,
            defaultBufferSize: $loggerConfig->bufferSize()
        );

        $this->logger = new LoggerService(
            jsonlService: $jsonlService,
            hydrationService: $this->hydration
        );

        // Désactiver le buffer pour les tests
        $this->logger->disableBuffer();

        // Utiliser l'Application réelle du test
        $this->app = $this->app;

        // Contexte pour TestTask avec UUID valide
        $this->taskContext = new TaskContext;
        $this->taskContext->setTaskId(new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'));
        $this->taskContext->setSignature(new TaskSignatureVO('test-signature'));
        $this->taskContext->setLaravelApp($this->app);

        // Contexte pour FailingTask avec UUID valide
        $this->failingContext = new TaskContext;
        $this->failingContext->setTaskId(new TaskIdVO('660e8400-e29b-41d4-a716-446655440001'));
        $this->failingContext->setSignature(new TaskSignatureVO('failing-signature'));
        $this->failingContext->setLaravelApp($this->app);

        // Création des tâches avec injection dans le constructeur
        $this->task = new TestTask($this->taskContext, $this->logger, $this->hydration);
        $this->failingTask = new FailingTask($this->failingContext, $this->logger, $this->hydration);

    }

    private function setupLogDirectory(): void
    {
        $dateDir = $this->tempLogDir.'/'.$this->expectedDate;
        if (! is_dir($dateDir)) {
            mkdir($dateDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if (is_dir($this->tempLogDir)) {
            $this->deleteDirectory($this->tempLogDir);
        }
        parent::tearDown();
    }

    /**
     * Crée un payload avec un seul objet StrictDataObject.
     */
    private function createTaskPayload(): TaskPayloadRecord
    {
        $data = new StrictDataObject([
            'test_data' => 'abstract_task_test',
        ]);

        return new TaskPayloadRecord(
            type: 'test',
            data: $data,
        );
    }

    private function getLogFileContent(): string
    {
        $logFile = $this->tempLogDir.'/'.$this->expectedDate.'/'.$this->expectedHour.'.jsonl';

        // Lister tous les fichiers existants dans tempLogDir
        if (is_dir($this->tempLogDir)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempLogDir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                }
            }
        } else {
        }

        $maxRetries = 20;
        $retryDelay = 50000;

        for ($i = 0; $i < $maxRetries; $i++) {
            if (file_exists($logFile)) {
                $content = file_get_contents($logFile);
                if ($content !== false && $content !== '') {

                    return $content;
                }
            }
            usleep($retryDelay);
        }

        $dateDir = $this->tempLogDir.'/'.$this->expectedDate;
        if (is_dir($dateDir)) {
            $files = glob($dateDir.'/*.jsonl');
            if (! empty($files)) {
                foreach ($files as $f) {
                    $content = file_get_contents($f);
                    if ($content !== false && $content !== '') {

                        return $content;
                    }
                }
            }
        }

        $this->assertFileExists($logFile, "Log file does not exist at: {$logFile}");

        return (string) file_get_contents($logFile);
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir.'/'.$file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ==================== Tests ====================

    public function test_execute_calls_before_process_and_after(): void
    {
        $payload = $this->createTaskPayload();
        $this->task->execute($payload);

        $this->assertTrue($this->task->beforeCalled);
        $this->assertTrue($this->task->processCalled);
        $this->assertTrue($this->task->afterCalled);
        $this->assertTrue($this->task->afterSuccess);
    }

    public function test_execute_calls_after_with_false_on_exception(): void
    {
        $payload = $this->createTaskPayload();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Test exception');

        try {
            $this->failingTask->execute($payload);
        } finally {
            $this->assertTrue($this->failingTask->afterCalled);
            $this->assertFalse($this->failingTask->afterSuccess);
            $this->assertSame('Test exception', $this->failingTask->afterError);
        }
    }

    public function test_execute_logs_task_started(): void
    {
        $payload = $this->createTaskPayload();
        $this->task->execute($payload);

        $this->logger->flush();

        $content = $this->getLogFileContent();

        $this->assertStringContainsString('task_started', $content);
        $this->assertStringContainsString('550e8400-e29b-41d4-a716-446655440000', $content);
        $this->assertStringContainsString('test-signature', $content);
    }

    public function test_execute_logs_task_completed_on_success(): void
    {
        $payload = $this->createTaskPayload();
        $this->task->execute($payload);

        $this->logger->flush();

        $content = $this->getLogFileContent();
        $this->assertStringContainsString('task_completed', $content);
        $this->assertStringContainsString('550e8400-e29b-41d4-a716-446655440000', $content);
        $this->assertStringContainsString('success', $content);
    }

    public function test_execute_logs_task_failed_on_exception(): void
    {
        $payload = $this->createTaskPayload();

        try {
            $this->failingTask->execute($payload);
        } catch (\RuntimeException $e) {
        }

        $this->logger->flush();

        $content = $this->getLogFileContent();
        $this->assertStringContainsString('task_failed', $content);
        $this->assertStringContainsString('660e8400-e29b-41d4-a716-446655440001', $content);
        $this->assertStringContainsString('failed', $content);
        $this->assertStringContainsString('Test exception', $content);
    }

    public function test_info_method_logs_info_message(): void
    {
        $message = 'Test info message';
        $this->task->info($message);

        $this->logger->flush();

        $content = $this->getLogFileContent();
        $this->assertStringContainsString('task_output', $content);
        $this->assertStringContainsString('info', $content);
        $this->assertStringContainsString($message, $content);
    }

    public function test_error_method_logs_error_message(): void
    {
        $message = 'Test error message';
        $this->task->error($message);

        $this->logger->flush();

        $content = $this->getLogFileContent();
        $this->assertStringContainsString('task_output', $content);
        $this->assertStringContainsString('error', $content);
        $this->assertStringContainsString($message, $content);
    }

    public function test_execute_with_valid_payload_processes_correctly(): void
    {
        $payload = $this->createTaskPayload();
        $this->task->execute($payload);

        $this->assertTrue($this->task->processCalled);
        $this->assertTrue($this->task->afterSuccess);
    }

    public function test_multiple_info_calls_log_all_messages(): void
    {
        $messages = ['First message', 'Second message', 'Third message'];

        foreach ($messages as $message) {
            $this->task->info($message);
        }

        $this->logger->flush();

        $content = $this->getLogFileContent();
        foreach ($messages as $message) {
            $this->assertStringContainsString($message, $content);
        }
    }

    public function test_multiple_error_calls_log_all_messages(): void
    {
        $messages = ['Error 1', 'Error 2', 'Error 3'];

        foreach ($messages as $message) {
            $this->task->error($message);
        }

        $this->logger->flush();

        $content = $this->getLogFileContent();
        foreach ($messages as $message) {
            $this->assertStringContainsString($message, $content);
        }
    }
}
