<?php

declare(strict_types=1);

namespace AndyDefer\Task\Models;

use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\UniqueTaskFqcnVO;
use AndyDefer\Task\ValueObjects\UuidVO;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class UniqueTask extends Model
{
    use SoftDeletes;

    protected $table = 'unique_tasks';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'alias',
        'fqcn',
        'payload',
        'scheduled_at',
        'grace_period_seconds',
        'status',
        'attempts',
        'max_attempts',
        'finished_at',
        'cancelled_at',
        'deleted_at',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'finished_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'grace_period_seconds' => 'integer',
        'attempts' => 'integer',
        'max_attempts' => 'integer',
        'status' => UniqueTaskStatus::class,
        'payload' => 'array',
    ];

    public function getId(): UuidVO
    {
        return new UuidVO((string) $this->id);
    }

    public function getAlias(): TaskAliasVO
    {
        return new TaskAliasVO($this->alias);
    }

    public function getFqcn(): UniqueTaskFqcnVO
    {
        return new UniqueTaskFqcnVO($this->fqcn);
    }

    public function getFqcnString(): string
    {
        return $this->fqcn;
    }

    public function getScheduledAt(): Iso8601DateTimeVO
    {
        return new Iso8601DateTimeVO($this->scheduled_at->toIso8601String());
    }

    public function getFinishedAt(): ?Iso8601DateTimeVO
    {
        return $this->finished_at ? new Iso8601DateTimeVO($this->finished_at->toIso8601String()) : null;
    }

    public function getCancelledAt(): ?Iso8601DateTimeVO
    {
        return $this->cancelled_at ? new Iso8601DateTimeVO($this->cancelled_at->toIso8601String()) : null;
    }

    public function getStatus(): UniqueTaskStatus
    {
        return $this->status;
    }

    public function getAttempts(): CounterVO
    {
        return new CounterVO($this->attempts);
    }

    public function getMaxAttempts(): CounterVO
    {
        return new CounterVO($this->max_attempts);
    }

    public function getGracePeriodSeconds(): int
    {
        return $this->grace_period_seconds;
    }

    public function getPayload(): StrictDataObject
    {
        return new StrictDataObject($this->payload ?? []);
    }
}
