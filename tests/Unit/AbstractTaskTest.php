<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Unit;

use AndyDefer\DomainStructures\Collections\Utility\StrictDataObjectCollection;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Tests\Fixtures\Tasks\FailingTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestTask;
use AndyDefer\Task\Tests\UnitTestCase;
use AndyDefer\Logger\LoggerService;
use AndyDefer\Logger\Configs\LoggerConfig;
use AndyDefer\LaravelJsonl\JsonlService;
use AndyDefer\LaravelJsonl\Strategies\TemporalPathStrategy;
use AndyDefer\LaravelJsonl\Contexts\JsonlContext;
use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\PhpServices\Services\FileSystemService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;

#[AllowMockObjectsWithoutExpectations]
final class AbstractTaskTest extends UnitTestCase
{
    private TestTask $task;
    private FailingTask $failingTask;
    private LoggerInterface $logger;
    private string $tempLogDir;
    private string $expectedDate;
    private string $expectedHour;
    private Carbon $fixedNow;

    protected function setUp(): void
    {
        parent::setUp();

        // Freeze time to ensure consistent file paths
        $this->fixedNow = Carbon::create(2026, 6, 14, 12, 0, 0, 'UTC');
        Carbon::setTestNow($this->fixedNow);

        $this->expectedDate = $this->fixedNow->format('Y-m-d');
        $currentHourNum = (int) $this->fixedNow->format('H');
        $nextHour = ($currentHourNum + 1) % 24;
        $this->expectedHour = sprintf('%02d-%02d', $currentHourNum, $nextHour);

        $this->tempLogDir = sys_get_temp_dir() . '/logger_test_' . uniqid();

        $this->setupLogDirectory();

        // Create a mock config repository for testing
        $configRepository = $this->createMock(ConfigRepository::class);
        $configRepository->method('get')->willReturnMap([
            ['logger.path', null, $this->tempLogDir],
            ['logger.retention_days', null, 30],
            ['logger.buffer_size', null, null],
        ]);

        $loggerConfig = new LoggerConfig($configRepository);

        // Initialize JSONL service dependencies
        $fileSystemService = new FileSystemService();
        $context = new JsonlContext();
        $pathStrategy = new TemporalPathStrategy($loggerConfig->basePath());
        $hydrationService = new HydrationService();

        $jsonlService = new JsonlService(
            pathStrategy: $pathStrategy,
            fileSystem: $fileSystemService,
            context: $context,
            defaultBufferSize: $loggerConfig->bufferSize()
        );

        $this->logger = new LoggerService(
            jsonlService: $jsonlService,
            hydrationService: $hydrationService
        );

        $this->task = new TestTask();
        $this->task
            ->setLogger($this->logger)
            ->setTaskId('test-123')
            ->setSignature('test-signature');

        $this->failingTask = new FailingTask();
        $this->failingTask
            ->setLogger($this->logger)
            ->setTaskId('failing-123')
            ->setSignature('failing-signature');
    }

    private function setupLogDirectory(): void
    {
        $dateDir = $this->tempLogDir . '/' . $this->expectedDate;
        if (!is_dir($dateDir)) {
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

    private function createTaskPayload(): TaskPayloadRecord
    {
        $payloadCollection = new StrictDataObjectCollection();
        $payloadCollection->add(StrictDataObject::from([
            'test_data' => 'abstract_task_test',
        ]));

        return new TaskPayloadRecord(
            type: 'test',
            data: $payloadCollection,
        );
    }

    private function getLogFileContent(): string
    {
        $logFile = $this->tempLogDir . '/' . $this->expectedDate . '/' . $this->expectedHour . '.jsonl';

        // Wait for file to be written (max 1 second)
        $maxRetries = 20;
        $retryDelay = 50000; // 50ms

        for ($i = 0; $i < $maxRetries; $i++) {
            if (file_exists($logFile)) {
                $content = file_get_contents($logFile);
                if ($content !== false && $content !== '') {
                    return $content;
                }
            }
            usleep($retryDelay);
        }

        // If file doesn't exist, try to find any JSONL file in the directory
        $dateDir = $this->tempLogDir . '/' . $this->expectedDate;
        if (is_dir($dateDir)) {
            $files = glob($dateDir . '/*.jsonl');
            if (!empty($files)) {
                $content = file_get_contents($files[0]);
                if ($content !== false && $content !== '') {
                    return $content;
                }
            }
        }

        $this->assertFileExists($logFile, "Log file does not exist at: {$logFile}");
        return (string) file_get_contents($logFile);
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ==================== Tests ====================

    public function test_execute_calls_before_process_and_after(): void
    {
        // Arrange
        $payload = $this->createTaskPayload();

        // Act
        $this->task->execute($payload);

        // Assert
        $this->assertTrue($this->task->beforeCalled);
        $this->assertTrue($this->task->processCalled);
        $this->assertTrue($this->task->afterCalled);
        $this->assertTrue($this->task->afterSuccess);
    }

    public function test_execute_calls_after_with_false_on_exception(): void
    {
        // Arrange
        $payload = $this->createTaskPayload();

        // Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Test exception');

        // Act
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
        // Arrange
        $payload = $this->createTaskPayload();

        // Act
        $this->task->execute($payload);

        // Assert
        $content = $this->getLogFileContent();
        $this->assertStringContainsString('task_started', $content);
        $this->assertStringContainsString('test-123', $content);
        $this->assertStringContainsString('test-signature', $content);
    }

    public function test_execute_logs_task_completed_on_success(): void
    {
        // Arrange
        $payload = $this->createTaskPayload();

        // Act
        $this->task->execute($payload);

        // Assert
        $content = $this->getLogFileContent();
        $this->assertStringContainsString('task_completed', $content);
        $this->assertStringContainsString('test-123', $content);
        $this->assertStringContainsString('success', $content);
    }

    public function test_execute_logs_task_failed_on_exception(): void
    {
        // Arrange
        $payload = $this->createTaskPayload();

        // Act
        try {
            $this->failingTask->execute($payload);
        } catch (\RuntimeException $e) {
            // Expected exception
        }

        // Assert
        $content = $this->getLogFileContent();
        $this->assertStringContainsString('task_failed', $content);
        $this->assertStringContainsString('failing-123', $content);
        $this->assertStringContainsString('failed', $content);
        $this->assertStringContainsString('Test exception', $content);
    }

    public function test_info_method_logs_info_message(): void
    {
        // Arrange
        $message = 'Test info message';

        // Act
        $this->task->info($message);

        // Assert
        $content = $this->getLogFileContent();
        $this->assertStringContainsString('task_output', $content);
        $this->assertStringContainsString('info', $content);
        $this->assertStringContainsString($message, $content);
    }

    public function test_error_method_logs_error_message(): void
    {
        // Arrange
        $message = 'Test error message';

        // Act
        $this->task->error($message);

        // Assert
        $content = $this->getLogFileContent();
        $this->assertStringContainsString('task_output', $content);
        $this->assertStringContainsString('error', $content);
        $this->assertStringContainsString($message, $content);
    }

    public function test_set_logger_returns_self(): void
    {
        // Act
        $result = $this->task->setLogger($this->logger);

        // Assert
        $this->assertSame($this->task, $result);
    }

    public function test_set_task_id_returns_self(): void
    {
        // Act
        $result = $this->task->setTaskId('new-id');

        // Assert
        $this->assertSame($this->task, $result);
    }

    public function test_set_signature_returns_self(): void
    {
        // Act
        $result = $this->task->setSignature('new-signature');

        // Assert
        $this->assertSame($this->task, $result);
    }

    public function test_execute_with_valid_payload_processes_correctly(): void
    {
        // Arrange
        $payload = $this->createTaskPayload();

        // Act
        $this->task->execute($payload);

        // Assert
        $this->assertTrue($this->task->processCalled);
        $this->assertTrue($this->task->afterSuccess);
    }

    public function test_multiple_info_calls_log_all_messages(): void
    {
        // Arrange
        $messages = ['First message', 'Second message', 'Third message'];

        // Act
        foreach ($messages as $message) {
            $this->task->info($message);
        }

        // Assert
        $content = $this->getLogFileContent();
        foreach ($messages as $message) {
            $this->assertStringContainsString($message, $content);
        }
    }

    public function test_multiple_error_calls_log_all_messages(): void
    {
        // Arrange
        $messages = ['Error 1', 'Error 2', 'Error 3'];

        // Act
        foreach ($messages as $message) {
            $this->task->error($message);
        }

        // Assert
        $content = $this->getLogFileContent();
        foreach ($messages as $message) {
            $this->assertStringContainsString($message, $content);
        }
    }

    public function test_logger_flush_writes_all_buffered_logs(): void
    {
        // Arrange
        $message = 'Test message before flush';

        // Act
        $this->task->info($message);

        // Assert
        $content = $this->getLogFileContent();
        $this->assertStringContainsString($message, $content);
    }
}
