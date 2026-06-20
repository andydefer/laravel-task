<?php

declare(strict_types=1);

namespace AndyDefer\Task\Contexts;

use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use Illuminate\Contracts\Foundation\Application;

final class TaskContext
{
    private TaskPayloadRecord $payload;

    private ?TaskIdVO $taskId = null;

    private TaskSignatureVO $signature;

    private ?Application $app = null;

    public function setPayload(TaskPayloadRecord $payload): void
    {
        $this->payload = $payload;
    }

    public function getPayload(): TaskPayloadRecord
    {
        return $this->payload;
    }

    public function setTaskId(?TaskIdVO $taskId): void
    {
        $this->taskId = $taskId;
    }

    public function getTaskId(): ?TaskIdVO
    {
        return $this->taskId;
    }

    public function hasTaskId(): bool
    {
        return $this->taskId !== null;
    }

    public function setSignature(TaskSignatureVO $signature): void
    {
        $this->signature = $signature;
    }

    public function getSignature(): TaskSignatureVO
    {
        return $this->signature;
    }

    public function setLaravelApp(Application $app): void
    {
        $this->app = $app;
    }

    public function getLaravelApp(): ?Application
    {
        return $this->app;
    }

    public function hasLaravel(): bool
    {
        return $this->app !== null;
    }
}
