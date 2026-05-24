<?php

// tests/Unit/AbstractTaskTest.php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Unit;

use AndyDefer\Logger\Collections\MixedPayloadCollection;
use AndyDefer\Logger\Config\LoggerConfig;
use AndyDefer\Logger\Logger;
use AndyDefer\Logger\Services\LogPathService;
use AndyDefer\Logger\Services\LogSerializerService;
use AndyDefer\Logger\Tasks\QueryLogsTask;
use AndyDefer\Logger\Tasks\StreamLogsTask;
use AndyDefer\Logger\Tasks\WriteLogTask;
use AndyDefer\Task\Enums\TaskMode;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Tests\Fixtures\Tasks\FailingTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use Carbon\Carbon;

final class AbstractTaskTest extends IntegrationTestCase
{
    private TestTask $task;
    private Logger $logger;
    private string $tempLogDir;
    private string $currentDate;
    private string $currentHour;

    protected function setUp(): void
    {
        parent::setUp();

        // Figer le temps pour les tests
        Carbon::setTestNow(Carbon::create(2026, 5, 24, 10, 26, 0));

        $this->currentDate = date('Y-m-d');
        $this->currentHour = '10-11';

        $this->tempLogDir = sys_get_temp_dir() . '/logger_test_' . uniqid();

        $config = new LoggerConfig($this->tempLogDir, 30);
        $pathService = new LogPathService($config);
        $serializer = new LogSerializerService();
        $writeTask = new WriteLogTask($pathService, $serializer);
        $queryTask = new QueryLogsTask($pathService, $serializer);
        $streamTask = new StreamLogsTask($pathService, $serializer);

        $this->logger = new Logger($writeTask, $queryTask, $streamTask);

        $this->task = new TestTask();
        $this->task->setLogger($this->logger);
        $this->task->setTaskId('test-123');
        $this->task->setSignature('test-signature');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if (is_dir($this->tempLogDir)) {
            $this->deleteDirectory($this->tempLogDir);
        }
        parent::tearDown();
    }

    public function test_execute_calls_before_process_and_after(): void
    {
        $payloadCollection = new MixedPayloadCollection();
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: $payloadCollection,
        );

        $this->task->execute(TaskMode::SYNC, $payload);

        $this->assertTrue($this->task->beforeCalled);
        $this->assertTrue($this->task->processCalled);
        $this->assertTrue($this->task->afterCalled);
        $this->assertTrue($this->task->afterSuccess);
    }

    public function test_execute_calls_after_with_false_on_exception(): void
    {
        $payloadCollection = new MixedPayloadCollection();
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: $payloadCollection,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Test exception');

        $failingTask = new FailingTask();
        $failingTask->setLogger($this->logger);
        $failingTask->setTaskId('failing-123');
        $failingTask->setSignature('failing-signature');

        try {
            $failingTask->execute(TaskMode::SYNC, $payload);
        } finally {
            $this->assertTrue($failingTask->afterCalled);
            $this->assertFalse($failingTask->afterSuccess);
            $this->assertSame('Test exception', $failingTask->afterError);
        }
    }

    public function test_execute_logs_task_started(): void
    {
        $payloadCollection = new MixedPayloadCollection();
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: $payloadCollection,
        );

        $this->task->execute(TaskMode::SYNC, $payload);

        $logFile = $this->tempLogDir . '/' . $this->currentDate . '/' . $this->currentHour . '.jsonl';
        $this->assertFileExists($logFile);

        $content = file_get_contents($logFile);
        $this->assertStringContainsString('task_started', $content);
        $this->assertStringContainsString('test-123', $content);
        $this->assertStringContainsString('test-signature', $content);
    }

    public function test_execute_logs_task_completed_on_success(): void
    {
        $payloadCollection = new MixedPayloadCollection();
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: $payloadCollection,
        );

        $this->task->execute(TaskMode::SYNC, $payload);

        $logFile = $this->tempLogDir . '/' . $this->currentDate . '/' . $this->currentHour . '.jsonl';
        $this->assertFileExists($logFile);

        $content = file_get_contents($logFile);
        $this->assertStringContainsString('task_completed', $content);
        $this->assertStringContainsString('test-123', $content);
        $this->assertStringContainsString('success', $content);
    }

    public function test_execute_logs_task_failed_on_exception(): void
    {
        $failingTask = new FailingTask();
        $failingTask->setLogger($this->logger);
        $failingTask->setTaskId('failing-123');
        $failingTask->setSignature('failing-signature');

        $payloadCollection = new MixedPayloadCollection();
        $payload = new TaskPayloadRecord(
            type: 'test',
            payload: $payloadCollection,
        );

        try {
            $failingTask->execute(TaskMode::SYNC, $payload);
        } catch (\RuntimeException $e) {
            // Exception attendue
        }

        $logFile = $this->tempLogDir . '/' . $this->currentDate . '/' . $this->currentHour . '.jsonl';
        $this->assertFileExists($logFile);

        $content = file_get_contents($logFile);
        $this->assertStringContainsString('task_failed', $content);
        $this->assertStringContainsString('failing-123', $content);
        $this->assertStringContainsString('failed', $content);
        $this->assertStringContainsString('Test exception', $content);
    }

    public function test_info_method_logs_info_message(): void
    {
        $this->task->info('Test info message');

        $logFile = $this->tempLogDir . '/' . $this->currentDate . '/' . $this->currentHour . '.jsonl';
        $this->assertFileExists($logFile);

        $content = file_get_contents($logFile);
        $this->assertStringContainsString('task_output', $content);
        $this->assertStringContainsString('info', $content);
        $this->assertStringContainsString('Test info message', $content);
    }

    public function test_error_method_logs_error_message(): void
    {
        $this->task->error('Test error message');

        $logFile = $this->tempLogDir . '/' . $this->currentDate . '/' . $this->currentHour . '.jsonl';
        $this->assertFileExists($logFile);

        $content = file_get_contents($logFile);
        $this->assertStringContainsString('task_output', $content);
        $this->assertStringContainsString('error', $content);
        $this->assertStringContainsString('Test error message', $content);
    }

    public function test_set_logger_returns_self(): void
    {
        $result = $this->task->setLogger($this->logger);

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
}
