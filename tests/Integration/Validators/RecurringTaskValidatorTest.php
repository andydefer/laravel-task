<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Validators;

use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Enums\RecurringTaskStatus;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestRecurringTask;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestUniqueTask;
use AndyDefer\Task\Tests\IntegrationTestCase;
use AndyDefer\Task\Validators\RecurringTaskValidator;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\RecurringTaskFqcnVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\UuidVO;
use Illuminate\Support\Carbon;
use Ramsey\Uuid\Uuid;

final class RecurringTaskValidatorTest extends IntegrationTestCase
{
    private RecurringTaskValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new RecurringTaskValidator;

        $frozenNow = Carbon::create(2026, 6, 23, 12, 0, 0);
        Carbon::setTestNow($frozenNow);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    private function generateAlias(string $uuid): TaskAliasVO
    {
        return new TaskAliasVO('recurring@'.$uuid);
    }

    private function createTaskRecord(array $data): RecurringTaskRecord
    {
        $uuid = $data['uuid'] ?? (string) Uuid::uuid4();
        $fqcn = $data['fqcn'] ?? TestRecurringTask::class;
        $payload = $data['payload'] ?? ['test' => 'payload'];
        $intervalSeconds = $data['interval_seconds'] ?? 3600;
        $startAt = $data['start_at'] ?? Carbon::now()->subHours(2)->toIso8601String();
        $endAt = $data['end_at'] ?? Carbon::now()->addDays(1)->toIso8601String();
        $lastRunAt = $data['last_run_at'] ?? Carbon::now()->subHours(2)->toIso8601String();
        $status = $data['status'] ?? RecurringTaskStatus::PLAYING;

        $alias = $this->generateAlias($uuid);

        return RecurringTaskRecord::from([
            'id' => new UuidVO($uuid),
            'alias' => $alias,
            'fqcn' => new RecurringTaskFqcnVO($fqcn),
            'payload' => StrictDataObject::from($payload),
            'interval_seconds' => $intervalSeconds,
            'start_at' => $startAt !== null ? new Iso8601DateTimeVO($startAt) : null,
            'end_at' => $endAt !== null ? new Iso8601DateTimeVO($endAt) : null,
            'last_run_at' => $lastRunAt !== null ? new Iso8601DateTimeVO($lastRunAt) : null,
            'status' => $status,
        ]);
    }

    // ==================== TESTS canRun ====================

    public function test_can_run_returns_true_for_valid_playing_task(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';

        $record = $this->createTaskRecord([
            'uuid' => $uuid,
            'fqcn' => TestRecurringTask::class,
            'payload' => ['test' => 'payload'],
            'interval_seconds' => 3600,
            'start_at' => Carbon::now()->subHours(2)->toIso8601String(),
            'end_at' => Carbon::now()->addDays(1)->toIso8601String(),
            'last_run_at' => Carbon::now()->subHours(2)->toIso8601String(),
            'status' => RecurringTaskStatus::PLAYING,
        ]);

        $this->assertTrue($this->validator->canRun($record));
    }

    public function test_can_run_returns_false_when_status_is_waiting(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440001';

        $record = $this->createTaskRecord([
            'uuid' => $uuid,
            'fqcn' => TestRecurringTask::class,
            'payload' => ['test' => 'payload'],
            'interval_seconds' => 3600,
            'start_at' => Carbon::now()->subHours(2)->toIso8601String(),
            'end_at' => Carbon::now()->addDays(1)->toIso8601String(),
            'last_run_at' => Carbon::now()->subHours(2)->toIso8601String(),
            'status' => RecurringTaskStatus::WAITING,
        ]);

        $this->assertFalse($this->validator->canRun($record));
    }

    public function test_can_run_returns_false_when_status_is_paused(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440002';

        $record = $this->createTaskRecord([
            'uuid' => $uuid,
            'fqcn' => TestRecurringTask::class,
            'payload' => ['test' => 'payload'],
            'interval_seconds' => 3600,
            'start_at' => Carbon::now()->subHours(2)->toIso8601String(),
            'end_at' => Carbon::now()->addDays(1)->toIso8601String(),
            'last_run_at' => Carbon::now()->subHours(2)->toIso8601String(),
            'status' => RecurringTaskStatus::PAUSED,
        ]);

        $this->assertFalse($this->validator->canRun($record));
    }

    public function test_can_run_returns_false_when_status_is_finished(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440003';

        $record = $this->createTaskRecord([
            'uuid' => $uuid,
            'fqcn' => TestRecurringTask::class,
            'payload' => ['test' => 'payload'],
            'interval_seconds' => 3600,
            'start_at' => Carbon::now()->subHours(2)->toIso8601String(),
            'end_at' => Carbon::now()->addDays(1)->toIso8601String(),
            'last_run_at' => Carbon::now()->subHours(2)->toIso8601String(),
            'status' => RecurringTaskStatus::FINISHED,
        ]);

        $this->assertFalse($this->validator->canRun($record));
    }

    public function test_can_run_returns_false_when_expired(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440004';

        $record = $this->createTaskRecord([
            'uuid' => $uuid,
            'fqcn' => TestRecurringTask::class,
            'payload' => ['test' => 'payload'],
            'interval_seconds' => 3600,
            'start_at' => Carbon::now()->subHours(48)->toIso8601String(),
            'end_at' => Carbon::now()->subHours(24)->toIso8601String(),
            'last_run_at' => Carbon::now()->subHours(2)->toIso8601String(),
            'status' => RecurringTaskStatus::PLAYING,
        ]);

        $this->assertFalse($this->validator->canRun($record));
        $this->assertTrue($this->validator->isExpired($record));
    }

    public function test_can_run_throws_exception_when_class_does_not_exist(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Task class "NonExistentClass" does not exist.');

        $uuid = '550e8400-e29b-41d4-a716-446655440005';

        $this->createTaskRecord([
            'uuid' => $uuid,
            'fqcn' => 'NonExistentClass',
            'payload' => ['test' => 'payload'],
            'interval_seconds' => 3600,
            'start_at' => Carbon::now()->subHours(2)->toIso8601String(),
            'end_at' => Carbon::now()->addDays(1)->toIso8601String(),
            'last_run_at' => Carbon::now()->subHours(2)->toIso8601String(),
            'status' => RecurringTaskStatus::PLAYING,
        ]);
    }

    public function test_can_run_throws_exception_when_class_does_not_extend_recurring_task(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Class "AndyDefer\Task\Tests\Fixtures\Tasks\TestUniqueTask" must extend AndyDefer\Task\Abstract\AbstractRecurringTask');

        $uuid = '550e8400-e29b-41d4-a716-446655440006';

        $this->createTaskRecord([
            'uuid' => $uuid,
            'fqcn' => TestUniqueTask::class,
            'payload' => ['test' => 'payload'],
            'interval_seconds' => 3600,
            'start_at' => Carbon::now()->subHours(2)->toIso8601String(),
            'end_at' => Carbon::now()->addDays(1)->toIso8601String(),
            'last_run_at' => Carbon::now()->subHours(2)->toIso8601String(),
            'status' => RecurringTaskStatus::PLAYING,
        ]);
    }

    // ==================== TESTS isReadyToRun ====================

    public function test_is_ready_to_run_returns_true_for_waiting_task_with_start_at_passed(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440007';

        $record = $this->createTaskRecord([
            'uuid' => $uuid,
            'fqcn' => TestRecurringTask::class,
            'payload' => ['test' => 'payload'],
            'interval_seconds' => 3600,
            'start_at' => Carbon::now()->subHours(2)->toIso8601String(),
            'status' => RecurringTaskStatus::WAITING,
        ]);

        $this->assertTrue($this->validator->isReadyToRun($record));
    }

    public function test_is_ready_to_run_returns_false_for_waiting_task_with_start_at_future(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440008';

        $record = $this->createTaskRecord([
            'uuid' => $uuid,
            'fqcn' => TestRecurringTask::class,
            'payload' => ['test' => 'payload'],
            'interval_seconds' => 3600,
            'start_at' => Carbon::now()->addHours(2)->toIso8601String(),
            'status' => RecurringTaskStatus::WAITING,
        ]);

        $this->assertFalse($this->validator->isReadyToRun($record));
    }

    public function test_is_ready_to_run_returns_true_for_waiting_task_with_null_start_at(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440009';

        $record = $this->createTaskRecord([
            'uuid' => $uuid,
            'fqcn' => TestRecurringTask::class,
            'payload' => ['test' => 'payload'],
            'interval_seconds' => 3600,
            'start_at' => null,
            'status' => RecurringTaskStatus::WAITING,
        ]);

        $this->assertTrue($this->validator->isReadyToRun($record));
    }

    public function test_is_ready_to_run_returns_false_for_non_waiting_task(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440010';

        $record = $this->createTaskRecord([
            'uuid' => $uuid,
            'fqcn' => TestRecurringTask::class,
            'payload' => ['test' => 'payload'],
            'interval_seconds' => 3600,
            'start_at' => Carbon::now()->subHours(2)->toIso8601String(),
            'status' => RecurringTaskStatus::PLAYING,
        ]);

        $this->assertFalse($this->validator->isReadyToRun($record));
    }

    // ==================== TESTS isExpired ====================

    public function test_is_expired_returns_true_when_end_at_passed(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440011';

        $record = $this->createTaskRecord([
            'uuid' => $uuid,
            'fqcn' => TestRecurringTask::class,
            'payload' => ['test' => 'payload'],
            'interval_seconds' => 3600,
            'start_at' => Carbon::now()->subDays(7)->toIso8601String(),
            'end_at' => Carbon::now()->subHours(24)->toIso8601String(),
            'status' => RecurringTaskStatus::PLAYING,
        ]);

        $this->assertTrue($this->validator->isExpired($record));
    }

    public function test_is_expired_returns_false_when_end_at_in_future(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440012';

        $record = $this->createTaskRecord([
            'uuid' => $uuid,
            'fqcn' => TestRecurringTask::class,
            'payload' => ['test' => 'payload'],
            'interval_seconds' => 3600,
            'start_at' => Carbon::now()->subHours(2)->toIso8601String(),
            'end_at' => Carbon::now()->addDays(1)->toIso8601String(),
            'status' => RecurringTaskStatus::PLAYING,
        ]);

        $this->assertFalse($this->validator->isExpired($record));
    }

    public function test_is_expired_returns_false_when_end_at_null(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440013';

        $record = $this->createTaskRecord([
            'uuid' => $uuid,
            'fqcn' => TestRecurringTask::class,
            'payload' => ['test' => 'payload'],
            'interval_seconds' => 3600,
            'start_at' => Carbon::now()->subHours(2)->toIso8601String(),
            'end_at' => null,
            'status' => RecurringTaskStatus::PLAYING,
        ]);

        $this->assertFalse($this->validator->isExpired($record));
    }

    // ==================== TESTS shouldMoveToFinished ====================

    public function test_should_move_to_finished_returns_true_when_expired(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440014';

        $record = $this->createTaskRecord([
            'uuid' => $uuid,
            'fqcn' => TestRecurringTask::class,
            'payload' => ['test' => 'payload'],
            'interval_seconds' => 3600,
            'start_at' => Carbon::now()->subDays(7)->toIso8601String(),
            'end_at' => Carbon::now()->subHours(24)->toIso8601String(),
            'status' => RecurringTaskStatus::PLAYING,
        ]);

        $this->assertTrue($this->validator->shouldMoveToFinished($record));
    }

    public function test_should_move_to_finished_returns_false_when_not_expired(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440015';

        $record = $this->createTaskRecord([
            'uuid' => $uuid,
            'fqcn' => TestRecurringTask::class,
            'payload' => ['test' => 'payload'],
            'interval_seconds' => 3600,
            'start_at' => Carbon::now()->subHours(2)->toIso8601String(),
            'end_at' => Carbon::now()->addDays(1)->toIso8601String(),
            'status' => RecurringTaskStatus::PLAYING,
        ]);

        $this->assertFalse($this->validator->shouldMoveToFinished($record));
    }

    // ==================== TESTS shouldRunAgain ====================

    public function test_should_run_again_returns_true_when_interval_reached(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440016';

        $record = $this->createTaskRecord([
            'uuid' => $uuid,
            'fqcn' => TestRecurringTask::class,
            'payload' => ['test' => 'payload'],
            'interval_seconds' => 3600,
            'start_at' => Carbon::now()->subDays(1)->toIso8601String(),
            'end_at' => Carbon::now()->addDays(7)->toIso8601String(),
            'last_run_at' => Carbon::now()->subHours(2)->toIso8601String(),
            'status' => RecurringTaskStatus::PLAYING,
        ]);

        $this->assertTrue($this->validator->shouldRunAgain($record));
    }

    public function test_should_run_again_returns_false_when_interval_not_reached(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440017';

        $record = $this->createTaskRecord([
            'uuid' => $uuid,
            'fqcn' => TestRecurringTask::class,
            'payload' => ['test' => 'payload'],
            'interval_seconds' => 3600,
            'start_at' => Carbon::now()->subDays(1)->toIso8601String(),
            'end_at' => Carbon::now()->addDays(7)->toIso8601String(),
            'last_run_at' => Carbon::now()->subMinutes(30)->toIso8601String(),
            'status' => RecurringTaskStatus::PLAYING,
        ]);

        $this->assertFalse($this->validator->shouldRunAgain($record));
    }

    public function test_should_run_again_returns_true_when_last_run_at_null(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440018';

        $record = $this->createTaskRecord([
            'uuid' => $uuid,
            'fqcn' => TestRecurringTask::class,
            'payload' => ['test' => 'payload'],
            'interval_seconds' => 3600,
            'start_at' => Carbon::now()->subDays(1)->toIso8601String(),
            'end_at' => Carbon::now()->addDays(7)->toIso8601String(),
            'last_run_at' => null,
            'status' => RecurringTaskStatus::PLAYING,
        ]);

        $this->assertTrue($this->validator->shouldRunAgain($record));
    }

    public function test_should_run_again_returns_false_when_not_playing(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440019';

        $record = $this->createTaskRecord([
            'uuid' => $uuid,
            'fqcn' => TestRecurringTask::class,
            'payload' => ['test' => 'payload'],
            'interval_seconds' => 3600,
            'start_at' => Carbon::now()->subDays(1)->toIso8601String(),
            'end_at' => Carbon::now()->addDays(7)->toIso8601String(),
            'last_run_at' => Carbon::now()->subHours(2)->toIso8601String(),
            'status' => RecurringTaskStatus::WAITING,
        ]);

        $this->assertFalse($this->validator->shouldRunAgain($record));
    }

    public function test_should_run_again_returns_false_when_expired(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440020';

        $record = $this->createTaskRecord([
            'uuid' => $uuid,
            'fqcn' => TestRecurringTask::class,
            'payload' => ['test' => 'payload'],
            'interval_seconds' => 3600,
            'start_at' => Carbon::now()->subDays(7)->toIso8601String(),
            'end_at' => Carbon::now()->subHours(24)->toIso8601String(),
            'last_run_at' => Carbon::now()->subHours(2)->toIso8601String(),
            'status' => RecurringTaskStatus::PLAYING,
        ]);

        $this->assertFalse($this->validator->shouldRunAgain($record));
        $this->assertTrue($this->validator->isExpired($record));
    }

    // ==================== TESTS getValidationErrors ====================

    public function test_get_validation_errors_returns_empty_for_valid_playing_task(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440021';

        $record = $this->createTaskRecord([
            'uuid' => $uuid,
            'fqcn' => TestRecurringTask::class,
            'payload' => ['test' => 'payload'],
            'interval_seconds' => 3600,
            'start_at' => Carbon::now()->subHours(2)->toIso8601String(),
            'end_at' => Carbon::now()->addDays(1)->toIso8601String(),
            'last_run_at' => Carbon::now()->subHours(2)->toIso8601String(),
            'status' => RecurringTaskStatus::PLAYING,
        ]);

        $errors = $this->validator->getValidationErrors($record);
        $this->assertInstanceOf(StringTypedCollection::class, $errors);
        $this->assertCount(0, $errors);
    }

    public function test_get_validation_errors_returns_waiting_state_error(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440022';

        $record = $this->createTaskRecord([
            'uuid' => $uuid,
            'fqcn' => TestRecurringTask::class,
            'payload' => ['test' => 'payload'],
            'interval_seconds' => 3600,
            'start_at' => Carbon::now()->subHours(2)->toIso8601String(),
            'end_at' => Carbon::now()->addDays(1)->toIso8601String(),
            'status' => RecurringTaskStatus::WAITING,
        ]);

        $errors = $this->validator->getValidationErrors($record);
        $this->assertContains('Task is in WAITING state, not PLAYING', $errors);
    }

    public function test_get_validation_errors_returns_paused_state_error(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440023';

        $record = $this->createTaskRecord([
            'uuid' => $uuid,
            'fqcn' => TestRecurringTask::class,
            'payload' => ['test' => 'payload'],
            'interval_seconds' => 3600,
            'start_at' => Carbon::now()->subHours(2)->toIso8601String(),
            'end_at' => Carbon::now()->addDays(1)->toIso8601String(),
            'status' => RecurringTaskStatus::PAUSED,
        ]);

        $errors = $this->validator->getValidationErrors($record);
        $this->assertContains('Task is in PAUSED state', $errors);
    }

    public function test_get_validation_errors_returns_finished_state_error(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440024';

        $record = $this->createTaskRecord([
            'uuid' => $uuid,
            'fqcn' => TestRecurringTask::class,
            'payload' => ['test' => 'payload'],
            'interval_seconds' => 3600,
            'start_at' => Carbon::now()->subHours(2)->toIso8601String(),
            'end_at' => Carbon::now()->addDays(1)->toIso8601String(),
            'status' => RecurringTaskStatus::FINISHED,
        ]);

        $errors = $this->validator->getValidationErrors($record);
        $this->assertContains('Task is already FINISHED', $errors);
    }

    public function test_get_validation_errors_returns_expired_error(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440025';

        $record = $this->createTaskRecord([
            'uuid' => $uuid,
            'fqcn' => TestRecurringTask::class,
            'payload' => ['test' => 'payload'],
            'interval_seconds' => 3600,
            'start_at' => Carbon::now()->subHours(48)->toIso8601String(),
            'end_at' => Carbon::now()->subHours(24)->toIso8601String(),
            'status' => RecurringTaskStatus::PLAYING,
        ]);

        $errors = $this->validator->getValidationErrors($record);
        $this->assertContains('Task has expired (end_at reached)', $errors);
    }

    public function test_get_validation_errors_returns_not_ready_error_for_waiting_task(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440026';

        $record = $this->createTaskRecord([
            'uuid' => $uuid,
            'fqcn' => TestRecurringTask::class,
            'payload' => ['test' => 'payload'],
            'interval_seconds' => 3600,
            'start_at' => Carbon::now()->addHours(2)->toIso8601String(),
            'status' => RecurringTaskStatus::WAITING,
        ]);

        $errors = $this->validator->getValidationErrors($record);
        $this->assertContains('Task is not ready to run (start_at not reached)', $errors);
    }

    public function test_get_validation_errors_returns_multiple_errors(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440027';

        $record = $this->createTaskRecord([
            'uuid' => $uuid,
            'fqcn' => TestRecurringTask::class,
            'payload' => ['test' => 'payload'],
            'interval_seconds' => 3600,
            'start_at' => Carbon::now()->addHours(2)->toIso8601String(),
            'end_at' => Carbon::now()->subHours(24)->toIso8601String(),
            'status' => RecurringTaskStatus::WAITING,
        ]);

        $errors = $this->validator->getValidationErrors($record);

        $this->assertContains('Task is in WAITING state, not PLAYING', $errors);
        $this->assertContains('Task has expired (end_at reached)', $errors);
        $this->assertContains('Task is not ready to run (start_at not reached)', $errors);
    }
}
