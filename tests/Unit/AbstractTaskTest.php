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
use AndyDefer\Task\Enums\TaskMode;
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

        // Get REAL current date/time (not frozen)
        $realNow = new \DateTime('now', new \DateTimeZone('UTC'));
        $this->expectedDate = $realNow->format('Y-m-d');
        $currentHourNum = (int) $realNow->format('H');

        // Format correct : "HH-(HH+1)" avec modulo pour 23-00
        $nextHour = ($currentHourNum + 1) % 24;
        $this->expectedHour = sprintf('%02d-%02d', $currentHourNum, $nextHour);

        $this->tempLogDir = sys_get_temp_dir().'/logger_test_'.uniqid();

        // Create directory structure
        $dateDir = $this->tempLogDir.'/'.$this->expectedDate;
        if (! is_dir($dateDir)) {
            mkdir($dateDir, 0755, true);
        }

        $config = new LoggerConfig($this->tempLogDir, 30);
        $pathService = new LogPathService($config);
        $serializer = new LogSerializerService;

        $writeTask = new WriteLogTask($pathService, $serializer);
        $queryTask = new QueryLogsTask($pathService, $serializer);
        $streamTask = new StreamLogsTask($pathService, $serializer);

        $this->logger = new Logger($writeTask, $queryTask, $streamTask);

        // IMPORTANT: Disable buffer completely and flush to ensure synchronous writes
        $this->logger->disableBuffer();
        $this->logger->flush();

        $this->task = new TestTask;
        $this->task->setLogger($this->logger);
        $this->task->setTaskId('test-123');
        $this->task->setSignature('test-signature');

        $this->failingTask = new FailingTask;
        $this->failingTask->setLogger($this->logger);
        $this->failingTask->setTaskId('failing-123');
        $this->failingTask->setSignature('failing-signature');
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
        $payloadCollection = new StrictDataObjectCollection;
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
        $logFile = $this->tempLogDir.'/'.$this->expectedDate.'/'.$this->expectedHour.'.jsonl';

        // Force flush before checking
        $this->logger->flush();

        // Clear stat cache to ensure we get fresh file info
        clearstatcache();

        // Wait a bit for the file to be written (file system latency)
        $maxRetries = 10;
        $retryDelay = 10000; // 10ms

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

        return file_get_contents($logFile);
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
        $this->task->execute(TaskMode::SYNC, $payload);

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
            $this->failingTask->execute(TaskMode::SYNC, $payload);
        } finally {
            $this->assertTrue($this->failingTask->afterCalled);
            $this->assertFalse($this->failingTask->afterSuccess);
            $this->assertSame('Test exception', $this->failingTask->afterError);
        }
    }

    public function test_execute_logs_task_started(): void
    {
        $payload = $this->createTaskPayload();
        $this->task->execute(TaskMode::SYNC, $payload);

        $content = $this->getLogFileContent();
        $this->assertStringContainsString('task_started', $content);
        $this->assertStringContainsString('test-123', $content);
        $this->assertStringContainsString('test-signature', $content);
    }

    public function test_execute_logs_task_completed_on_success(): void
    {
        $payload = $this->createTaskPayload();
        $this->task->execute(TaskMode::SYNC, $payload);

        $content = $this->getLogFileContent();
        $this->assertStringContainsString('task_completed', $content);
        $this->assertStringContainsString('test-123', $content);
        $this->assertStringContainsString('success', $content);
    }

    public function test_execute_logs_task_failed_on_exception(): void
    {
        $payload = $this->createTaskPayload();

        try {
            $this->failingTask->execute(TaskMode::SYNC, $payload);
        } catch (\RuntimeException $e) {
            // Expected exception
        }

        $content = $this->getLogFileContent();
        $this->assertStringContainsString('task_failed', $content);
        $this->assertStringContainsString('failing-123', $content);
        $this->assertStringContainsString('failed', $content);
        $this->assertStringContainsString('Test exception', $content);
    }

    public function test_info_method_logs_info_message(): void
    {
        $message = 'Test info message';
        $this->task->info($message);

        $content = $this->getLogFileContent();
        $this->assertStringContainsString('task_output', $content);
        $this->assertStringContainsString('info', $content);
        $this->assertStringContainsString($message, $content);
    }

    public function test_error_method_logs_error_message(): void
    {
        $message = 'Test error message';
        $this->task->error($message);

        $content = $this->getLogFileContent();
        $this->assertStringContainsString('task_output', $content);
        $this->assertStringContainsString('error', $content);
        $this->assertStringContainsString($message, $content);
    }

    public function test_set_logger_returns_self(): void
    {
        $newLogger = clone $this->logger;
        $result = $this->task->setLogger($newLogger);
        $this->assertSame($this->task, $result);
    }

    public function test_set_task_id_returns_self(): void
    {
        $result = $this->task->setTaskId('new-id');
        $this->assertSame($this->task, $result);
    }

    public function test_set_signature_returns_self(): void
    {
        $result = $this->task->setSignature('new-signature');
        $this->assertSame($this->task, $result);
    }

    public function test_execute_with_valid_payload_processes_correctly(): void
    {
        $payload = $this->createTaskPayload();
        $this->task->execute(TaskMode::SYNC, $payload);

        $this->assertTrue($this->task->processCalled);
        $this->assertTrue($this->task->afterSuccess);
    }

    public function test_multiple_info_calls_log_all_messages(): void
    {
        $messages = ['First message', 'Second message', 'Third message'];

        foreach ($messages as $message) {
            $this->task->info($message);
        }

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

        $content = $this->getLogFileContent();
        foreach ($messages as $message) {
            $this->assertStringContainsString($message, $content);
        }
    }

    public function test_logger_flush_writes_all_buffered_logs(): void
    {
        // With buffer disabled, this test is trivial
        $this->task->info('Test message before flush');
        $this->logger->flush();

        $content = $this->getLogFileContent();
        $this->assertStringContainsString('Test message before flush', $content);
    }
}
