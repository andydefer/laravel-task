<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Validators;

use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestRecurringTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestUniqueTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\Validators\UniqueTaskValidator;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\MaxFailedAttemptsVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\TaskTypeVO;
use AndyDefer\Task\ValueObjects\UniqueTaskFqcnVO;
use AndyDefer\Task\ValueObjects\UuidVO;
use Illuminate\Support\Carbon;
use Ramsey\Uuid\Uuid;

final class UniqueTaskValidatorTest extends IntegrationTestCase
{
    private UniqueTaskValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new UniqueTaskValidator;

        $frozenNow = Carbon::create(2026, 6, 23, 12, 0, 0);
        Carbon::setTestNow($frozenNow);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    private function createTaskRecord(array $data): UniqueTaskRecord
    {
        $uuid = $data['id'] ?? (string) Uuid::uuid4();
        $fqcn = $data['fqcn'] ?? TestUniqueTask::class;
        $payload = $data['payload'] ?? ['test' => 'payload'];
        $scheduledAt = $data['scheduled_at'] ?? Carbon::now()->toIso8601String();
        $gracePeriodSeconds = $data['grace_period_seconds'] ?? 86400;
        $status = $data['status'] ?? UniqueTaskStatus::PENDING;
        $attempts = $data['attempts'] ?? 0;
        $maxAttempts = $data['max_attempts'] ?? 3;

        $alias = new TaskAliasVO(
            new TaskTypeVO('unique'),
            $uuid
        );

        return UniqueTaskRecord::from([
            'id' => new UuidVO($uuid),
            'alias' => $alias,
            'fqcn' => new UniqueTaskFqcnVO($fqcn),
            'payload' => StrictDataObject::from($payload),
            'scheduled_at' => new Iso8601DateTimeVO($scheduledAt),
            'grace_period_seconds' => new DurationVO($gracePeriodSeconds),
            'status' => $status,
            'attempts' => new CounterVO($attempts),
            'max_attempts' => new MaxFailedAttemptsVO($maxAttempts),
        ]);
    }

    public function test_can_run_returns_true_for_valid_task(): void
    {
        $record = $this->createTaskRecord([
            'fqcn' => TestUniqueTask::class,
            'scheduled_at' => Carbon::now()->subMinutes(10)->toIso8601String(),
            'status' => UniqueTaskStatus::PENDING,
        ]);

        $this->assertTrue($this->validator->canRun($record));
    }

    public function test_can_run_returns_false_for_expired_task(): void
    {
        $record = $this->createTaskRecord([
            'fqcn' => TestUniqueTask::class,
            'scheduled_at' => Carbon::now()->subHours(48)->toIso8601String(),
            'grace_period_seconds' => 3600,
            'status' => UniqueTaskStatus::PENDING,
        ]);

        $this->assertFalse($this->validator->canRun($record));
    }

    public function test_can_run_returns_false_when_max_attempts_reached(): void
    {
        $record = $this->createTaskRecord([
            'fqcn' => TestUniqueTask::class,
            'scheduled_at' => Carbon::now()->subMinutes(10)->toIso8601String(),
            'status' => UniqueTaskStatus::PENDING,
            'attempts' => 3,
            'max_attempts' => 3,
        ]);

        $this->assertFalse($this->validator->canRun($record));
        $this->assertTrue($this->validator->hasReachedMaxAttempts($record));
    }

    public function test_can_run_throws_exception_when_class_does_not_exist(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Task class "NonExistentClass" does not exist.');

        $this->createTaskRecord([
            'fqcn' => 'NonExistentClass',
            'scheduled_at' => Carbon::now()->subMinutes(10)->toIso8601String(),
            'status' => UniqueTaskStatus::PENDING,
        ]);
    }

    public function test_can_run_throws_exception_when_class_does_not_extend_unique_task(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Class "AndyDefer\Task\Tests\Fixtures\Tasks\TestRecurringTask" must extend AndyDefer\Task\Abstract\AbstractUniqueTask');

        $this->createTaskRecord([
            'fqcn' => TestRecurringTask::class,
            'scheduled_at' => Carbon::now()->subMinutes(10)->toIso8601String(),
            'status' => UniqueTaskStatus::PENDING,
        ]);
    }

    public function test_is_ready_to_run_returns_true_when_scheduled_at_passed(): void
    {
        $record = $this->createTaskRecord([
            'fqcn' => TestUniqueTask::class,
            'scheduled_at' => Carbon::now()->subMinutes(5)->toIso8601String(),
            'status' => UniqueTaskStatus::PENDING,
        ]);

        $this->assertTrue($this->validator->isReadyToRun($record));
    }

    public function test_is_ready_to_run_returns_false_when_scheduled_at_future(): void
    {
        $record = $this->createTaskRecord([
            'fqcn' => TestUniqueTask::class,
            'scheduled_at' => Carbon::now()->addHours(2)->toIso8601String(),
            'status' => UniqueTaskStatus::PENDING,
        ]);

        $this->assertFalse($this->validator->isReadyToRun($record));
    }

    public function test_is_ready_to_run_throws_exception_when_class_does_not_exist(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Task class "NonExistentClass" does not exist.');

        $this->createTaskRecord([
            'fqcn' => 'NonExistentClass',
            'scheduled_at' => Carbon::now()->subMinutes(5)->toIso8601String(),
            'status' => UniqueTaskStatus::PENDING,
        ]);
    }

    public function test_is_expired_returns_true_after_grace_period(): void
    {
        $record = $this->createTaskRecord([
            'fqcn' => TestUniqueTask::class,
            'scheduled_at' => Carbon::now()->subHours(48)->toIso8601String(),
            'grace_period_seconds' => 3600,
            'status' => UniqueTaskStatus::PENDING,
        ]);

        $this->assertTrue($this->validator->isExpired($record));
    }

    public function test_is_expired_throws_exception_when_class_does_not_exist(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Task class "NonExistentClass" does not exist.');

        $this->createTaskRecord([
            'fqcn' => 'NonExistentClass',
            'scheduled_at' => Carbon::now()->subHours(48)->toIso8601String(),
            'grace_period_seconds' => 3600,
            'status' => UniqueTaskStatus::PENDING,
        ]);
    }

    public function test_get_validation_errors_returns_all_errors(): void
    {
        $record = $this->createTaskRecord([
            'fqcn' => TestUniqueTask::class,
            'scheduled_at' => Carbon::now()->subHours(48)->toIso8601String(),
            'grace_period_seconds' => 3600,
            'status' => UniqueTaskStatus::PENDING,
            'attempts' => 3,
            'max_attempts' => 3,
        ]);

        $errors = $this->validator->getValidationErrors($record);
        $this->assertNotEmpty($errors);
        $this->assertContains('Maximum attempts reached', $errors);
        $this->assertContains('Task has expired', $errors);
    }

    public function test_get_validation_errors_throws_exception_when_class_does_not_exist(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Task class "NonExistentClass" does not exist.');

        $this->createTaskRecord([
            'fqcn' => 'NonExistentClass',
            'scheduled_at' => Carbon::now()->subMinutes(10)->toIso8601String(),
            'status' => UniqueTaskStatus::PENDING,
        ]);
    }

    public function test_get_validation_errors_throws_exception_when_class_not_extending(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Class "AndyDefer\Task\Tests\Fixtures\Tasks\TestRecurringTask" must extend AndyDefer\Task\Abstract\AbstractUniqueTask');

        $this->createTaskRecord([
            'fqcn' => TestRecurringTask::class,
            'scheduled_at' => Carbon::now()->subMinutes(10)->toIso8601String(),
            'status' => UniqueTaskStatus::PENDING,
        ]);
    }

    public function test_get_validation_errors_returns_not_ready_error(): void
    {
        $record = $this->createTaskRecord([
            'fqcn' => TestUniqueTask::class,
            'scheduled_at' => Carbon::now()->addHours(2)->toIso8601String(),
            'status' => UniqueTaskStatus::PENDING,
        ]);

        $errors = $this->validator->getValidationErrors($record);
        $this->assertContains('Task is not ready to run (scheduled_at in the future)', $errors);
    }
}
