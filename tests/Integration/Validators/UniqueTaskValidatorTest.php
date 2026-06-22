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
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

final class UniqueTaskValidatorTest extends IntegrationTestCase
{
    private UniqueTaskValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new UniqueTaskValidator;
    }

    public function test_can_run_returns_true_for_valid_task(): void
    {
        $record = new UniqueTaskRecord(
            id: new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'),
            alias: new TaskSignatureVO('test'),
            fqcn: TestUniqueTask::class,
            payload: StrictDataObject::from([]),
            scheduled_at: new Iso8601DateTimeVO(now()->subMinutes(10)->toIso8601String()),
            grace_period_seconds: 86400,
            status: UniqueTaskStatus::PENDING,
        );

        $this->assertTrue($this->validator->canRun($record));
    }

    public function test_can_run_returns_false_for_expired_task(): void
    {
        $record = new UniqueTaskRecord(
            id: new TaskIdVO('550e8400-e29b-41d4-a716-446655440001'),
            alias: new TaskSignatureVO('test'),
            fqcn: TestUniqueTask::class,
            payload: StrictDataObject::from([]),
            scheduled_at: new Iso8601DateTimeVO(now()->subHours(48)->toIso8601String()),
            grace_period_seconds: 3600,
            status: UniqueTaskStatus::PENDING,
        );

        $this->assertFalse($this->validator->canRun($record));
    }

    public function test_can_run_returns_false_when_max_attempts_reached(): void
    {
        $record = new UniqueTaskRecord(
            id: new TaskIdVO('550e8400-e29b-41d4-a716-446655440002'),
            alias: new TaskSignatureVO('test'),
            fqcn: TestUniqueTask::class,
            payload: StrictDataObject::from([]),
            scheduled_at: new Iso8601DateTimeVO(now()->subMinutes(10)->toIso8601String()),
            grace_period_seconds: 86400,
            status: UniqueTaskStatus::PENDING,
            attempts: new CounterVO(3),
            max_attempts: new CounterVO(3),
        );

        $this->assertFalse($this->validator->canRun($record));
        $this->assertTrue($this->validator->hasReachedMaxAttempts($record));
    }

    public function test_can_run_returns_false_when_class_does_not_exist(): void
    {
        $record = new UniqueTaskRecord(
            id: new TaskIdVO('550e8400-e29b-41d4-a716-446655440007'),
            alias: new TaskSignatureVO('test'),
            fqcn: 'NonExistentClass',
            payload: StrictDataObject::from([]),
            scheduled_at: new Iso8601DateTimeVO(now()->subMinutes(10)->toIso8601String()),
            grace_period_seconds: 86400,
            status: UniqueTaskStatus::PENDING,
        );

        $this->assertFalse($this->validator->canRun($record));
    }

    public function test_can_run_returns_false_when_class_does_not_extend_unique_task(): void
    {
        $record = new UniqueTaskRecord(
            id: new TaskIdVO('550e8400-e29b-41d4-a716-446655440008'),
            alias: new TaskSignatureVO('test'),
            fqcn: TestRecurringTask::class,
            payload: StrictDataObject::from([]),
            scheduled_at: new Iso8601DateTimeVO(now()->subMinutes(10)->toIso8601String()),
            grace_period_seconds: 86400,
            status: UniqueTaskStatus::PENDING,
        );

        $this->assertFalse($this->validator->canRun($record));
    }

    public function test_is_ready_to_run_returns_true_when_scheduled_at_passed(): void
    {
        $record = new UniqueTaskRecord(
            id: new TaskIdVO('550e8400-e29b-41d4-a716-446655440003'),
            alias: new TaskSignatureVO('test'),
            fqcn: TestUniqueTask::class,
            payload: StrictDataObject::from([]),
            scheduled_at: new Iso8601DateTimeVO(now()->subMinutes(5)->toIso8601String()),
            grace_period_seconds: 86400,
            status: UniqueTaskStatus::PENDING,
        );

        $this->assertTrue($this->validator->isReadyToRun($record));
    }

    public function test_is_ready_to_run_returns_false_when_scheduled_at_future(): void
    {
        $record = new UniqueTaskRecord(
            id: new TaskIdVO('550e8400-e29b-41d4-a716-446655440004'),
            alias: new TaskSignatureVO('test'),
            fqcn: TestUniqueTask::class,
            payload: StrictDataObject::from([]),
            scheduled_at: new Iso8601DateTimeVO(now()->addHours(2)->toIso8601String()),
            grace_period_seconds: 86400,
            status: UniqueTaskStatus::PENDING,
        );

        $this->assertFalse($this->validator->isReadyToRun($record));
    }

    public function test_is_ready_to_run_returns_false_when_class_does_not_exist(): void
    {
        $record = new UniqueTaskRecord(
            id: new TaskIdVO('550e8400-e29b-41d4-a716-446655440009'),
            alias: new TaskSignatureVO('test'),
            fqcn: 'NonExistentClass',
            payload: StrictDataObject::from([]),
            scheduled_at: new Iso8601DateTimeVO(now()->subMinutes(5)->toIso8601String()),
            grace_period_seconds: 86400,
            status: UniqueTaskStatus::PENDING,
        );

        $this->assertFalse($this->validator->isReadyToRun($record));
    }

    public function test_is_expired_returns_true_after_grace_period(): void
    {
        $record = new UniqueTaskRecord(
            id: new TaskIdVO('550e8400-e29b-41d4-a716-446655440005'),
            alias: new TaskSignatureVO('test'),
            fqcn: TestUniqueTask::class,
            payload: StrictDataObject::from([]),
            scheduled_at: new Iso8601DateTimeVO(now()->subHours(48)->toIso8601String()),
            grace_period_seconds: 3600,
            status: UniqueTaskStatus::PENDING,
        );

        $this->assertTrue($this->validator->isExpired($record));
    }

    public function test_is_expired_returns_false_when_class_does_not_exist(): void
    {
        $record = new UniqueTaskRecord(
            id: new TaskIdVO('550e8400-e29b-41d4-a716-446655440010'),
            alias: new TaskSignatureVO('test'),
            fqcn: 'NonExistentClass',
            payload: StrictDataObject::from([]),
            scheduled_at: new Iso8601DateTimeVO(now()->subHours(48)->toIso8601String()),
            grace_period_seconds: 3600,
            status: UniqueTaskStatus::PENDING,
        );

        $this->assertFalse($this->validator->isExpired($record));
    }

    public function test_get_validation_errors_returns_all_errors(): void
    {
        $record = new UniqueTaskRecord(
            id: new TaskIdVO('550e8400-e29b-41d4-a716-446655440006'),
            alias: new TaskSignatureVO('test'),
            fqcn: TestUniqueTask::class,
            payload: StrictDataObject::from([]),
            scheduled_at: new Iso8601DateTimeVO(now()->subHours(48)->toIso8601String()),
            grace_period_seconds: 3600,
            status: UniqueTaskStatus::PENDING,
            attempts: new CounterVO(3),
            max_attempts: new CounterVO(3),
        );

        $errors = $this->validator->getValidationErrors($record);
        $this->assertNotEmpty($errors);
        $this->assertContains('Maximum attempts reached', $errors);
        $this->assertContains('Task has expired', $errors);
    }

    public function test_get_validation_errors_returns_invalid_class_error(): void
    {
        $record = new UniqueTaskRecord(
            id: new TaskIdVO('550e8400-e29b-41d4-a716-446655440011'),
            alias: new TaskSignatureVO('test'),
            fqcn: 'NonExistentClass',
            payload: StrictDataObject::from([]),
            scheduled_at: new Iso8601DateTimeVO(now()->subMinutes(10)->toIso8601String()),
            grace_period_seconds: 86400,
            status: UniqueTaskStatus::PENDING,
        );

        $errors = $this->validator->getValidationErrors($record);
        $this->assertStringContainsString('Invalid task class', $errors->first());
    }

    public function test_get_validation_errors_returns_class_not_extending_error(): void
    {
        $record = new UniqueTaskRecord(
            id: new TaskIdVO('550e8400-e29b-41d4-a716-446655440012'),
            alias: new TaskSignatureVO('test'),
            fqcn: TestRecurringTask::class,
            payload: StrictDataObject::from([]),
            scheduled_at: new Iso8601DateTimeVO(now()->subMinutes(10)->toIso8601String()),
            grace_period_seconds: 86400,
            status: UniqueTaskStatus::PENDING,
        );

        $errors = $this->validator->getValidationErrors($record);
        $this->assertStringContainsString('Invalid task class', $errors->first());
    }

    public function test_get_validation_errors_returns_not_ready_error(): void
    {
        $record = new UniqueTaskRecord(
            id: new TaskIdVO('550e8400-e29b-41d4-a716-446655440013'),
            alias: new TaskSignatureVO('test'),
            fqcn: TestUniqueTask::class,
            payload: StrictDataObject::from([]),
            scheduled_at: new Iso8601DateTimeVO(now()->addHours(2)->toIso8601String()),
            grace_period_seconds: 86400,
            status: UniqueTaskStatus::PENDING,
        );

        $errors = $this->validator->getValidationErrors($record);
        $this->assertContains('Task is not ready to run (scheduled_at in the future)', $errors);
    }
}
