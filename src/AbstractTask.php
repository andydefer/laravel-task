<?php

// src/AbstractTask.php

declare(strict_types=1);

namespace AndyDefer\Task;

use AndyDefer\Logger\Collections\MixedPayloadCollection;
use AndyDefer\Logger\Logger;
use AndyDefer\Logger\Records\LogDataRecord;
use AndyDefer\Task\Enums\TaskMode;
use AndyDefer\Task\Records\TaskConfigRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;

abstract class AbstractTask
{
    protected TaskPayloadRecord $payload;
    protected TaskMode $mode;
    protected string $taskId;
    protected string $signature;
    protected Logger $logger;

    abstract public function getConfig(): TaskConfigRecord;
    abstract protected function process(): void;

    protected function before(): void {}
    protected function after(bool $success, ?string $error = null): void {}

    public function execute(TaskMode $mode, TaskPayloadRecord $payload): void
    {
        $this->mode = $mode;
        $this->payload = $payload;

        $payloadLog = new MixedPayloadCollection();
        $payloadLog->add('task_started', $this->taskId, $this->signature, $mode->value);
        $this->logger->info(new LogDataRecord(type: 'task', payload: $payloadLog));

        $this->before();

        try {
            $this->process();
            $this->after(true);

            $payloadLog = new MixedPayloadCollection();
            $payloadLog->add('task_completed', $this->taskId, $this->signature, 'success');
            $this->logger->info(new LogDataRecord(type: 'task', payload: $payloadLog));
        } catch (\Throwable $e) {
            $this->after(false, $e->getMessage());

            $payloadLog = new MixedPayloadCollection();
            $payloadLog->add('task_failed', $this->taskId, $this->signature, 'failed', $e->getMessage());
            $this->logger->error(new LogDataRecord(type: 'task', payload: $payloadLog));

            throw $e;
        }
    }

    public function info(string $message): void
    {
        $payload = new MixedPayloadCollection();
        $payload->add('info', $message);
        $this->logger->info(new LogDataRecord(type: 'task_output', payload: $payload));
    }

    public function error(string $message): void
    {
        $payload = new MixedPayloadCollection();
        $payload->add('error', $message);
        $this->logger->error(new LogDataRecord(type: 'task_output', payload: $payload));
    }

    public function setLogger(Logger $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    public function setTaskId(string $id): self
    {
        $this->taskId = $id;
        return $this;
    }

    public function setSignature(string $signature): self
    {
        $this->signature = $signature;
        return $this;
    }
}
