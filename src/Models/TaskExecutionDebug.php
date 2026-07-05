<?php

declare(strict_types=1);

namespace AndyDefer\Task\Models;

use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Enums\ExecutionStatus;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\TaskFqcnVO;
use AndyDefer\Task\ValueObjects\TaskTypeVO;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class TaskExecutionDebug extends Model
{
    use SoftDeletes;

    protected $table = 'task_execution_debugs';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'alias',
        'fqcn',
        'status',
        'started_at',
        'ended_at',
        'data',
        'deleted_at',
    ];

    protected $casts = [
        'status' => ExecutionStatus::class,
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'data' => 'array',
    ];

    public function getId(): string
    {
        return (string) $this->id;
    }

    public function getAlias(): TaskAliasVO
    {
        [$type, $uuid] = explode('@', $this->alias, 2);

        return new TaskAliasVO(
            type: new TaskTypeVO($type),
            uuid: $uuid
        );
    }

    public function getAliasString(): string
    {
        return $this->alias;
    }

    public function getFqcn(): TaskFqcnVO
    {
        return new TaskFqcnVO($this->fqcn);
    }

    public function getFqcnString(): string
    {
        return $this->fqcn;
    }

    public function getStatus(): ExecutionStatus
    {
        return $this->status;
    }

    public function getStartedAt(): ?Iso8601DateTimeVO
    {
        return $this->started_at ? new Iso8601DateTimeVO($this->started_at->toIso8601String()) : null;
    }

    public function getEndedAt(): ?Iso8601DateTimeVO
    {
        return $this->ended_at ? new Iso8601DateTimeVO($this->ended_at->toIso8601String()) : null;
    }

    public function getData(): StrictDataObject
    {
        return new StrictDataObject($this->data ?? []);
    }

    public function getDuration(): ?float
    {
        if ($this->started_at === null || $this->ended_at === null) {
            return null;
        }

        $startTimestamp = $this->started_at->getTimestamp();
        $endTimestamp = $this->ended_at->getTimestamp();

        return (float) ($endTimestamp - $startTimestamp);
    }

    public function isSucceeded(): bool
    {
        return $this->status === ExecutionStatus::SUCCEEDED;
    }

    public function isFailed(): bool
    {
        return $this->status === ExecutionStatus::FAILED;
    }
}
