<?php

declare(strict_types=1);

namespace AndyDefer\Task\Models;

use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Enums\ExecutionStatus;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use Illuminate\Database\Eloquent\Model;

final class TaskExecutionDebug extends Model
{
    protected $table = 'task_execution_debugs';

    protected $fillable = [
        'task_type',
        'task_identifier',
        'data',
    ];

    protected $casts = [
        'data' => 'array',
    ];

    public function getId(): int
    {
        return $this->id;
    }

    public function getTaskType(): string
    {
        return $this->task_type;
    }

    public function getTaskIdentifier(): string
    {
        return $this->task_identifier;
    }

    public function getData(): StrictDataObject
    {
        return new StrictDataObject($this->data ?? []);
    }

    public function getActedAtVO(): Iso8601DateTimeVO
    {
        return new Iso8601DateTimeVO($this->data['acted_at'] ?? now()->toIso8601String());
    }

    public function getStatusVO(): ExecutionStatus
    {
        return ExecutionStatus::from($this->data['status'] ?? 'failed');
    }

    public function getInfo(): string
    {
        return $this->data['info'] ?? '';
    }
}
