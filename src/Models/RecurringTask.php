<?php

declare(strict_types=1);

namespace AndyDefer\Task\Models;

use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Enums\RecurringTaskStatus;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\MaxFailedAttemptsVO;
use AndyDefer\Task\ValueObjects\RecurringTaskFqcnVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class RecurringTask extends Model
{
    use SoftDeletes;

    protected $table = 'recurring_tasks';

    protected $fillable = [
        'alias',
        'fqcn',
        'payload',
        'interval_seconds',
        'start_at',
        'end_at',
        'status',
        'last_run_at',
        'failed_attempts',
        'max_failed_attempts',
        'finished_at',
        'cancelled_at',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'last_run_at' => 'datetime',
        'finished_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'interval_seconds' => 'integer',
        'failed_attempts' => 'integer',
        'max_failed_attempts' => 'integer',
        'status' => RecurringTaskStatus::class,
        'payload' => 'array',
    ];

    public function getId(): int
    {
        return $this->id;
    }

    public function getAlias(): TaskSignatureVO
    {
        return new TaskSignatureVO($this->alias);
    }

    public function getFqcn(): RecurringTaskFqcnVO
    {
        return new RecurringTaskFqcnVO($this->fqcn);
    }

    public function getFqcnString(): string
    {
        return $this->fqcn;
    }

    public function getIntervalSeconds(): CounterVO
    {
        return new CounterVO($this->interval_seconds);
    }

    public function getFailedAttempts(): CounterVO
    {
        return new CounterVO($this->failed_attempts ?? 0);
    }

    public function getMaxFailedAttempts(): MaxFailedAttemptsVO
    {
        return new MaxFailedAttemptsVO($this->max_failed_attempts ?? 3);
    }

    public function getStartAt(): ?Iso8601DateTimeVO
    {
        return $this->start_at ? new Iso8601DateTimeVO($this->start_at->toIso8601String()) : null;
    }

    public function getEndAtVO(): ?Iso8601DateTimeVO
    {
        return $this->end_at ? new Iso8601DateTimeVO($this->end_at->toIso8601String()) : null;
    }

    public function getLastRunAt(): ?Iso8601DateTimeVO
    {
        return $this->last_run_at ? new Iso8601DateTimeVO($this->last_run_at->toIso8601String()) : null;
    }

    public function getFinishedAt(): ?Iso8601DateTimeVO
    {
        return $this->finished_at ? new Iso8601DateTimeVO($this->finished_at->toIso8601String()) : null;
    }

    public function getCancelledAt(): ?Iso8601DateTimeVO
    {
        return $this->cancelled_at ? new Iso8601DateTimeVO($this->cancelled_at->toIso8601String()) : null;
    }

    public function getStatus(): RecurringTaskStatus
    {
        return $this->status;
    }

    public function getPayload(): StrictDataObject
    {
        return new StrictDataObject($this->payload ?? []);
    }
}
