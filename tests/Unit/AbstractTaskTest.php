<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Unit;

use AndyDefer\DomainStructures\Collections\Utility\StrictDataObjectCollection;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Logger;
use AndyDefer\Logger\Services\LogPathService;
use AndyDefer\Logger\Services\LogSerializerService;
use AndyDefer\Logger\Tasks\QueryLogsTask;
use AndyDefer\Logger\Tasks\StreamLogsTask;
use AndyDefer\Logger\Tasks\WriteLogTask;
use AndyDefer\Logger\ValueObjects\LoggerConfig;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Tests\Fixtures\Tasks\FailingTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestTask;
use AndyDefer\Task\Tests\UnitTestCase;

final class AbstractTaskTest extends UnitTestCase
{
    private TestTask $task;
    private FailingTask $failingTask;
    private Logger $logger;
    private string $tempLogDir;
    private string $expectedDate;
    private string $expectedHour;

    protected function setUp(): void
    {
        parent::setUp();

        $realNow = new \DateTime('now', new \DateTimeZone('UTC'));
        $this->expectedDate = $realNow->format('Y-m-d');
        $currentHourNum = (int) $realNow->format('H');
        $nextHour = ($currentHourNum + 1) % 24;
        $this->expectedHour = sprintf('%02d-%02d', $currentHourNum, $nextHour);

        $this->tempLogDir = sys_get_temp_dir() . '/logger_test_' . uniqid();

        $this->setupLogDirectory();

        $config = new LoggerConfig($this->tempLogDir, 30);
        $pathService = new LogPathService($config);
        $serializer = new LogSerializerService();

        $writeTask = new WriteLogTask($pathService, $serializer);
        $queryTask = new QueryLogsTask($pathService, $serializer);
        $streamTask = new StreamLogsTask($pathService, $serializer);

        $this->logger = new Logger($writeTask, $queryTask, $streamTask);
        $this->logger->disableBuffer();
        $this->logger->flush();

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
            payload: $payloadCollection,
        );
    }

    private function getLogFileContent(): string
    {
        $logFile = $this->tempLogDir . '/' . $this->expectedDate . '/' . $this->expectedHour . '.jsonl';

        $this->logger->flush();
        clearstatcache();

        $maxRetries = 10;
        $retryDelay = 10000;

        for ($i = 0; $i < $maxRetries; $i++) {
            if (file_exists($logFile)) {
                $content = file_get_contents($logFile);
                if ($content !== false && $content !== '') {
                    return $content;
                }
            }
            usleep($retryDelay);
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
        // Arrange
        $newLogger = clone $this->logger;

        // Act
        $result = $this->task->setLogger($newLogger);

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
        $this->logger->flush();

        // Assert
        $content = $this->getLogFileContent();
        $this->assertStringContainsString($message, $content);
    }
}
