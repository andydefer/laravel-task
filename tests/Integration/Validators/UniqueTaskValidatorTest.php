<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Validators;

use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestRecurringTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestUniqueTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\Validators\UniqueTaskValidator;
use Illuminate\Support\Carbon;

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
        return UniqueTaskRecord::from($data);
    }

    public function test_can_run_returns_true_for_valid_task(): void
    {
        $record = $this->createTaskRecord([
            'id' => '550e8400-e29b-41d4-a716-446655440000',
            'alias' => 'test',
            'fqcn' => TestUniqueTask::class,
            'payload' => [],
            'scheduled_at' => Carbon::now()->subMinutes(10)->toIso8601String(),
            'grace_period_seconds' => 86400,
            'status' => UniqueTaskStatus::PENDING,
        ]);

        $this->assertTrue($this->validator->canRun($record));
    }

    public function test_can_run_returns_false_for_expired_task(): void
    {
        $record = $this->createTaskRecord([
            'id' => '550e8400-e29b-41d4-a716-446655440001',
            'alias' => 'test',
            'fqcn' => TestUniqueTask::class,
            'payload' => [],
            'scheduled_at' => Carbon::now()->subHours(48)->toIso8601String(),
            'grace_period_seconds' => 3600,
            'status' => UniqueTaskStatus::PENDING,
        ]);

        $this->assertFalse($this->validator->canRun($record));
    }

    public function test_can_run_returns_false_when_max_attempts_reached(): void
    {
        $record = $this->createTaskRecord([
            'id' => '550e8400-e29b-41d4-a716-446655440002',
            'alias' => 'test',
            'fqcn' => TestUniqueTask::class,
            'payload' => [],
            'scheduled_at' => Carbon::now()->subMinutes(10)->toIso8601String(),
            'grace_period_seconds' => 86400,
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
            'id' => '550e8400-e29b-41d4-a716-446655440007',
            'alias' => 'test',
            'fqcn' => 'NonExistentClass',
            'payload' => [],
            'scheduled_at' => Carbon::now()->subMinutes(10)->toIso8601String(),
            'grace_period_seconds' => 86400,
            'status' => UniqueTaskStatus::PENDING,
        ]);
    }

    public function test_can_run_throws_exception_when_class_does_not_extend_unique_task(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Class "AndyDefer\Task\Tests\Fixtures\Tasks\TestRecurringTask" must extend AndyDefer\Task\Abstract\AbstractUniqueTask');

        $this->createTaskRecord([
            'id' => '550e8400-e29b-41d4-a716-446655440008',
            'alias' => 'test',
            'fqcn' => TestRecurringTask::class,
            'payload' => [],
            'scheduled_at' => Carbon::now()->subMinutes(10)->toIso8601String(),
            'grace_period_seconds' => 86400,
            'status' => UniqueTaskStatus::PENDING,
        ]);
    }

    public function test_is_ready_to_run_returns_true_when_scheduled_at_passed(): void
    {
        $record = $this->createTaskRecord([
            'id' => '550e8400-e29b-41d4-a716-446655440003',
            'alias' => 'test',
            'fqcn' => TestUniqueTask::class,
            'payload' => [],
            'scheduled_at' => Carbon::now()->subMinutes(5)->toIso8601String(),
            'grace_period_seconds' => 86400,
            'status' => UniqueTaskStatus::PENDING,
        ]);

        $this->assertTrue($this->validator->isReadyToRun($record));
    }

    public function test_is_ready_to_run_returns_false_when_scheduled_at_future(): void
    {
        $record = $this->createTaskRecord([
            'id' => '550e8400-e29b-41d4-a716-446655440004',
            'alias' => 'test',
            'fqcn' => TestUniqueTask::class,
            'payload' => [],
            'scheduled_at' => Carbon::now()->addHours(2)->toIso8601String(),
            'grace_period_seconds' => 86400,
            'status' => UniqueTaskStatus::PENDING,
        ]);

        $this->assertFalse($this->validator->isReadyToRun($record));
    }

    public function test_is_ready_to_run_throws_exception_when_class_does_not_exist(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Task class "NonExistentClass" does not exist.');

        $this->createTaskRecord([
            'id' => '550e8400-e29b-41d4-a716-446655440009',
            'alias' => 'test',
            'fqcn' => 'NonExistentClass',
            'payload' => [],
            'scheduled_at' => Carbon::now()->subMinutes(5)->toIso8601String(),
            'grace_period_seconds' => 86400,
            'status' => UniqueTaskStatus::PENDING,
        ]);
    }

    public function test_is_expired_returns_true_after_grace_period(): void
    {
        $record = $this->createTaskRecord([
            'id' => '550e8400-e29b-41d4-a716-446655440005',
            'alias' => 'test',
            'fqcn' => TestUniqueTask::class,
            'payload' => [],
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
            'id' => '550e8400-e29b-41d4-a716-446655440010',
            'alias' => 'test',
            'fqcn' => 'NonExistentClass',
            'payload' => [],
            'scheduled_at' => Carbon::now()->subHours(48)->toIso8601String(),
            'grace_period_seconds' => 3600,
            'status' => UniqueTaskStatus::PENDING,
        ]);
    }

    public function test_get_validation_errors_returns_all_errors(): void
    {
        $record = $this->createTaskRecord([
            'id' => '550e8400-e29b-41d4-a716-446655440006',
            'alias' => 'test',
            'fqcn' => TestUniqueTask::class,
            'payload' => [],
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
            'id' => '550e8400-e29b-41d4-a716-446655440011',
            'alias' => 'test',
            'fqcn' => 'NonExistentClass',
            'payload' => [],
            'scheduled_at' => Carbon::now()->subMinutes(10)->toIso8601String(),
            'grace_period_seconds' => 86400,
            'status' => UniqueTaskStatus::PENDING,
        ]);
    }

    public function test_get_validation_errors_throws_exception_when_class_not_extending(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Class "AndyDefer\Task\Tests\Fixtures\Tasks\TestRecurringTask" must extend AndyDefer\Task\Abstract\AbstractUniqueTask');

        $this->createTaskRecord([
            'id' => '550e8400-e29b-41d4-a716-446655440012',
            'alias' => 'test',
            'fqcn' => TestRecurringTask::class,
            'payload' => [],
            'scheduled_at' => Carbon::now()->subMinutes(10)->toIso8601String(),
            'grace_period_seconds' => 86400,
            'status' => UniqueTaskStatus::PENDING,
        ]);
    }

    public function test_get_validation_errors_returns_not_ready_error(): void
    {
        $record = $this->createTaskRecord([
            'id' => '550e8400-e29b-41d4-a716-446655440013',
            'alias' => 'test',
            'fqcn' => TestUniqueTask::class,
            'payload' => [],
            'scheduled_at' => Carbon::now()->addHours(2)->toIso8601String(),
            'grace_period_seconds' => 86400,
            'status' => UniqueTaskStatus::PENDING,
        ]);

        $errors = $this->validator->getValidationErrors($record);
        $this->assertContains('Task is not ready to run (scheduled_at in the future)', $errors);
    }
}
