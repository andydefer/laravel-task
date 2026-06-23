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
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use Illuminate\Support\Carbon;

final class RecurringTaskValidatorTest extends IntegrationTestCase
{
    private RecurringTaskValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new RecurringTaskValidator;

        // ✅ Freezer le temps pour tous les tests
        $frozenNow = Carbon::create(2026, 6, 23, 12, 0, 0);
        Carbon::setTestNow($frozenNow);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        parent::tearDown();
    }

    // ==================== TESTS canRun ====================

    public function test_can_run_returns_true_for_valid_playing_task(): void
    {
        $record = new RecurringTaskRecord(
            alias: new TaskSignatureVO('test'),
            fqcn: TestRecurringTask::class,
            payload: StrictDataObject::from([]),
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(Carbon::now()->subHours(2)->toIso8601String()),
            end_at: new Iso8601DateTimeVO(Carbon::now()->addDays(1)->toIso8601String()),
            last_run_at: new Iso8601DateTimeVO(Carbon::now()->subHours(2)->toIso8601String()),
            status: RecurringTaskStatus::PLAYING,
        );

        $this->assertTrue($this->validator->canRun($record));
    }

    public function test_can_run_returns_false_when_status_is_waiting(): void
    {
        $record = new RecurringTaskRecord(
            alias: new TaskSignatureVO('test'),
            fqcn: TestRecurringTask::class,
            payload: StrictDataObject::from([]),
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(Carbon::now()->subHours(2)->toIso8601String()),
            end_at: new Iso8601DateTimeVO(Carbon::now()->addDays(1)->toIso8601String()),
            last_run_at: new Iso8601DateTimeVO(Carbon::now()->subHours(2)->toIso8601String()),
            status: RecurringTaskStatus::WAITING,
        );

        $this->assertFalse($this->validator->canRun($record));
    }

    public function test_can_run_returns_false_when_status_is_paused(): void
    {
        $record = new RecurringTaskRecord(
            alias: new TaskSignatureVO('test'),
            fqcn: TestRecurringTask::class,
            payload: StrictDataObject::from([]),
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(Carbon::now()->subHours(2)->toIso8601String()),
            end_at: new Iso8601DateTimeVO(Carbon::now()->addDays(1)->toIso8601String()),
            last_run_at: new Iso8601DateTimeVO(Carbon::now()->subHours(2)->toIso8601String()),
            status: RecurringTaskStatus::PAUSED,
        );

        $this->assertFalse($this->validator->canRun($record));
    }

    public function test_can_run_returns_false_when_status_is_finished(): void
    {
        $record = new RecurringTaskRecord(
            alias: new TaskSignatureVO('test'),
            fqcn: TestRecurringTask::class,
            payload: StrictDataObject::from([]),
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(Carbon::now()->subHours(2)->toIso8601String()),
            end_at: new Iso8601DateTimeVO(Carbon::now()->addDays(1)->toIso8601String()),
            last_run_at: new Iso8601DateTimeVO(Carbon::now()->subHours(2)->toIso8601String()),
            status: RecurringTaskStatus::FINISHED,
        );

        $this->assertFalse($this->validator->canRun($record));
    }

    public function test_can_run_returns_false_when_expired(): void
    {
        $record = new RecurringTaskRecord(
            alias: new TaskSignatureVO('test'),
            fqcn: TestRecurringTask::class,
            payload: StrictDataObject::from([]),
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(Carbon::now()->subHours(48)->toIso8601String()),
            end_at: new Iso8601DateTimeVO(Carbon::now()->subHours(24)->toIso8601String()),
            last_run_at: new Iso8601DateTimeVO(Carbon::now()->subHours(2)->toIso8601String()),
            status: RecurringTaskStatus::PLAYING,
        );

        $this->assertFalse($this->validator->canRun($record));
        $this->assertTrue($this->validator->isExpired($record));
    }

    public function test_can_run_returns_false_when_class_does_not_exist(): void
    {
        $record = new RecurringTaskRecord(
            alias: new TaskSignatureVO('test'),
            fqcn: 'NonExistentClass',
            payload: StrictDataObject::from([]),
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(Carbon::now()->subHours(2)->toIso8601String()),
            end_at: new Iso8601DateTimeVO(Carbon::now()->addDays(1)->toIso8601String()),
            last_run_at: new Iso8601DateTimeVO(Carbon::now()->subHours(2)->toIso8601String()),
            status: RecurringTaskStatus::PLAYING,
        );

        $this->assertFalse($this->validator->canRun($record));
    }

    public function test_can_run_returns_false_when_class_does_not_extend_recurring_task(): void
    {
        $record = new RecurringTaskRecord(
            alias: new TaskSignatureVO('test'),
            fqcn: TestUniqueTask::class,
            payload: StrictDataObject::from([]),
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(Carbon::now()->subHours(2)->toIso8601String()),
            end_at: new Iso8601DateTimeVO(Carbon::now()->addDays(1)->toIso8601String()),
            last_run_at: new Iso8601DateTimeVO(Carbon::now()->subHours(2)->toIso8601String()),
            status: RecurringTaskStatus::PLAYING,
        );

        $this->assertFalse($this->validator->canRun($record));
    }

    // ==================== TESTS isReadyToRun ====================

    public function test_is_ready_to_run_returns_true_for_waiting_task_with_start_at_passed(): void
    {
        $record = new RecurringTaskRecord(
            alias: new TaskSignatureVO('test'),
            fqcn: TestRecurringTask::class,
            payload: StrictDataObject::from([]),
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(Carbon::now()->subHours(2)->toIso8601String()),
            status: RecurringTaskStatus::WAITING,
        );

        $this->assertTrue($this->validator->isReadyToRun($record));
    }

    public function test_is_ready_to_run_returns_false_for_waiting_task_with_start_at_future(): void
    {
        $record = new RecurringTaskRecord(
            alias: new TaskSignatureVO('test'),
            fqcn: TestRecurringTask::class,
            payload: StrictDataObject::from([]),
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(Carbon::now()->addHours(2)->toIso8601String()),
            status: RecurringTaskStatus::WAITING,
        );

        $this->assertFalse($this->validator->isReadyToRun($record));
    }

    public function test_is_ready_to_run_returns_false_for_waiting_task_with_null_start_at(): void
    {
        $record = new RecurringTaskRecord(
            alias: new TaskSignatureVO('test'),
            fqcn: TestRecurringTask::class,
            payload: StrictDataObject::from([]),
            interval_seconds: new CounterVO(3600),
            start_at: null,
            status: RecurringTaskStatus::WAITING,
        );

        $this->assertFalse($this->validator->isReadyToRun($record));
    }

    public function test_is_ready_to_run_returns_false_for_non_waiting_task(): void
    {
        $record = new RecurringTaskRecord(
            alias: new TaskSignatureVO('test'),
            fqcn: TestRecurringTask::class,
            payload: StrictDataObject::from([]),
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(Carbon::now()->subHours(2)->toIso8601String()),
            status: RecurringTaskStatus::PLAYING,
        );

        $this->assertFalse($this->validator->isReadyToRun($record));
    }

    public function test_is_ready_to_run_returns_false_when_class_does_not_exist(): void
    {
        $record = new RecurringTaskRecord(
            alias: new TaskSignatureVO('test'),
            fqcn: 'NonExistentClass',
            payload: StrictDataObject::from([]),
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(Carbon::now()->subHours(2)->toIso8601String()),
            status: RecurringTaskStatus::WAITING,
        );

        $this->assertFalse($this->validator->isReadyToRun($record));
    }

    // ==================== TESTS isExpired ====================

    public function test_is_expired_returns_true_when_end_at_passed(): void
    {
        $record = new RecurringTaskRecord(
            alias: new TaskSignatureVO('test'),
            fqcn: TestRecurringTask::class,
            payload: StrictDataObject::from([]),
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(Carbon::now()->subDays(7)->toIso8601String()),
            end_at: new Iso8601DateTimeVO(Carbon::now()->subHours(24)->toIso8601String()),
            status: RecurringTaskStatus::PLAYING,
        );

        $this->assertTrue($this->validator->isExpired($record));
    }

    public function test_is_expired_returns_false_when_end_at_in_future(): void
    {
        $record = new RecurringTaskRecord(
            alias: new TaskSignatureVO('test'),
            fqcn: TestRecurringTask::class,
            payload: StrictDataObject::from([]),
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(Carbon::now()->subHours(2)->toIso8601String()),
            end_at: new Iso8601DateTimeVO(Carbon::now()->addDays(1)->toIso8601String()),
            status: RecurringTaskStatus::PLAYING,
        );

        $this->assertFalse($this->validator->isExpired($record));
    }

    public function test_is_expired_returns_false_when_end_at_null(): void
    {
        $record = new RecurringTaskRecord(
            alias: new TaskSignatureVO('test'),
            fqcn: TestRecurringTask::class,
            payload: StrictDataObject::from([]),
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(Carbon::now()->subHours(2)->toIso8601String()),
            end_at: null,
            status: RecurringTaskStatus::PLAYING,
        );

        $this->assertFalse($this->validator->isExpired($record));
    }

    // ==================== TESTS shouldMoveToFinished ====================

    public function test_should_move_to_finished_returns_true_when_expired(): void
    {
        $record = new RecurringTaskRecord(
            alias: new TaskSignatureVO('test'),
            fqcn: TestRecurringTask::class,
            payload: StrictDataObject::from([]),
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(Carbon::now()->subDays(7)->toIso8601String()),
            end_at: new Iso8601DateTimeVO(Carbon::now()->subHours(24)->toIso8601String()),
            status: RecurringTaskStatus::PLAYING,
        );

        $this->assertTrue($this->validator->shouldMoveToFinished($record));
    }

    public function test_should_move_to_finished_returns_false_when_not_expired(): void
    {
        $record = new RecurringTaskRecord(
            alias: new TaskSignatureVO('test'),
            fqcn: TestRecurringTask::class,
            payload: StrictDataObject::from([]),
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(Carbon::now()->subHours(2)->toIso8601String()),
            end_at: new Iso8601DateTimeVO(Carbon::now()->addDays(1)->toIso8601String()),
            status: RecurringTaskStatus::PLAYING,
        );

        $this->assertFalse($this->validator->shouldMoveToFinished($record));
    }

    // ==================== TESTS shouldRunAgain ====================

    public function test_should_run_again_returns_true_when_interval_reached(): void
    {
        $record = new RecurringTaskRecord(
            alias: new TaskSignatureVO('test'),
            fqcn: TestRecurringTask::class,
            payload: StrictDataObject::from([]),
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(Carbon::now()->subDays(1)->toIso8601String()),
            end_at: new Iso8601DateTimeVO(Carbon::now()->addDays(7)->toIso8601String()),
            last_run_at: new Iso8601DateTimeVO(Carbon::now()->subHours(2)->toIso8601String()),
            status: RecurringTaskStatus::PLAYING,
        );

        $this->assertTrue($this->validator->shouldRunAgain($record));
    }

    public function test_should_run_again_returns_false_when_interval_not_reached(): void
    {
        $record = new RecurringTaskRecord(
            alias: new TaskSignatureVO('test'),
            fqcn: TestRecurringTask::class,
            payload: StrictDataObject::from([]),
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(Carbon::now()->subDays(1)->toIso8601String()),
            end_at: new Iso8601DateTimeVO(Carbon::now()->addDays(7)->toIso8601String()),
            last_run_at: new Iso8601DateTimeVO(Carbon::now()->subMinutes(30)->toIso8601String()),
            status: RecurringTaskStatus::PLAYING,
        );

        $this->assertFalse($this->validator->shouldRunAgain($record));
    }

    public function test_should_run_again_returns_true_when_last_run_at_null(): void
    {
        $record = new RecurringTaskRecord(
            alias: new TaskSignatureVO('test'),
            fqcn: TestRecurringTask::class,
            payload: StrictDataObject::from([]),
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(Carbon::now()->subDays(1)->toIso8601String()),
            end_at: new Iso8601DateTimeVO(Carbon::now()->addDays(7)->toIso8601String()),
            last_run_at: null,
            status: RecurringTaskStatus::PLAYING,
        );

        $this->assertTrue($this->validator->shouldRunAgain($record));
    }

    public function test_should_run_again_returns_false_when_not_playing(): void
    {
        $record = new RecurringTaskRecord(
            alias: new TaskSignatureVO('test'),
            fqcn: TestRecurringTask::class,
            payload: StrictDataObject::from([]),
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(Carbon::now()->subDays(1)->toIso8601String()),
            end_at: new Iso8601DateTimeVO(Carbon::now()->addDays(7)->toIso8601String()),
            last_run_at: new Iso8601DateTimeVO(Carbon::now()->subHours(2)->toIso8601String()),
            status: RecurringTaskStatus::WAITING,
        );

        $this->assertFalse($this->validator->shouldRunAgain($record));
    }

    public function test_should_run_again_returns_false_when_expired(): void
    {
        $record = new RecurringTaskRecord(
            alias: new TaskSignatureVO('test'),
            fqcn: TestRecurringTask::class,
            payload: StrictDataObject::from([]),
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(Carbon::now()->subDays(7)->toIso8601String()),
            end_at: new Iso8601DateTimeVO(Carbon::now()->subHours(24)->toIso8601String()),
            last_run_at: new Iso8601DateTimeVO(Carbon::now()->subHours(2)->toIso8601String()),
            status: RecurringTaskStatus::PLAYING,
        );

        $this->assertFalse($this->validator->shouldRunAgain($record));
        $this->assertTrue($this->validator->isExpired($record));
    }

    public function test_should_run_again_returns_false_when_class_does_not_exist(): void
    {
        $record = new RecurringTaskRecord(
            alias: new TaskSignatureVO('test'),
            fqcn: 'NonExistentClass',
            payload: StrictDataObject::from([]),
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(Carbon::now()->subDays(1)->toIso8601String()),
            end_at: new Iso8601DateTimeVO(Carbon::now()->addDays(7)->toIso8601String()),
            last_run_at: new Iso8601DateTimeVO(Carbon::now()->subHours(2)->toIso8601String()),
            status: RecurringTaskStatus::PLAYING,
        );

        $this->assertFalse($this->validator->shouldRunAgain($record));
    }

    // ==================== TESTS getValidationErrors ====================

    public function test_get_validation_errors_returns_empty_for_valid_playing_task(): void
    {
        $record = new RecurringTaskRecord(
            alias: new TaskSignatureVO('test'),
            fqcn: TestRecurringTask::class,
            payload: StrictDataObject::from([]),
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(Carbon::now()->subHours(2)->toIso8601String()),
            end_at: new Iso8601DateTimeVO(Carbon::now()->addDays(1)->toIso8601String()),
            last_run_at: new Iso8601DateTimeVO(Carbon::now()->subHours(2)->toIso8601String()),
            status: RecurringTaskStatus::PLAYING,
        );

        $errors = $this->validator->getValidationErrors($record);
        $this->assertInstanceOf(StringTypedCollection::class, $errors);
        $this->assertCount(0, $errors);
    }

    public function test_get_validation_errors_returns_waiting_state_error(): void
    {
        $record = new RecurringTaskRecord(
            alias: new TaskSignatureVO('test'),
            fqcn: TestRecurringTask::class,
            payload: StrictDataObject::from([]),
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(Carbon::now()->subHours(2)->toIso8601String()),
            end_at: new Iso8601DateTimeVO(Carbon::now()->addDays(1)->toIso8601String()),
            status: RecurringTaskStatus::WAITING,
        );

        $errors = $this->validator->getValidationErrors($record);
        $this->assertContains('Task is in WAITING state, not PLAYING', $errors);
    }

    public function test_get_validation_errors_returns_paused_state_error(): void
    {
        $record = new RecurringTaskRecord(
            alias: new TaskSignatureVO('test'),
            fqcn: TestRecurringTask::class,
            payload: StrictDataObject::from([]),
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(Carbon::now()->subHours(2)->toIso8601String()),
            end_at: new Iso8601DateTimeVO(Carbon::now()->addDays(1)->toIso8601String()),
            status: RecurringTaskStatus::PAUSED,
        );

        $errors = $this->validator->getValidationErrors($record);
        $this->assertContains('Task is in PAUSED state', $errors);
    }

    public function test_get_validation_errors_returns_finished_state_error(): void
    {
        $record = new RecurringTaskRecord(
            alias: new TaskSignatureVO('test'),
            fqcn: TestRecurringTask::class,
            payload: StrictDataObject::from([]),
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(Carbon::now()->subHours(2)->toIso8601String()),
            end_at: new Iso8601DateTimeVO(Carbon::now()->addDays(1)->toIso8601String()),
            status: RecurringTaskStatus::FINISHED,
        );

        $errors = $this->validator->getValidationErrors($record);
        $this->assertContains('Task is already FINISHED', $errors);
    }

    public function test_get_validation_errors_returns_expired_error(): void
    {
        $record = new RecurringTaskRecord(
            alias: new TaskSignatureVO('test'),
            fqcn: TestRecurringTask::class,
            payload: StrictDataObject::from([]),
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(Carbon::now()->subHours(48)->toIso8601String()),
            end_at: new Iso8601DateTimeVO(Carbon::now()->subHours(24)->toIso8601String()),
            status: RecurringTaskStatus::PLAYING,
        );

        $errors = $this->validator->getValidationErrors($record);
        $this->assertContains('Task has expired (end_at reached)', $errors);
    }

    public function test_get_validation_errors_returns_not_ready_error_for_waiting_task(): void
    {
        $record = new RecurringTaskRecord(
            alias: new TaskSignatureVO('test'),
            fqcn: TestRecurringTask::class,
            payload: StrictDataObject::from([]),
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(Carbon::now()->addHours(2)->toIso8601String()),
            status: RecurringTaskStatus::WAITING,
        );

        $errors = $this->validator->getValidationErrors($record);
        $this->assertContains('Task is not ready to run (start_at not reached)', $errors);
    }

    public function test_get_validation_errors_returns_multiple_errors(): void
    {
        $record = new RecurringTaskRecord(
            alias: new TaskSignatureVO('test'),
            fqcn: TestRecurringTask::class,
            payload: StrictDataObject::from([]),
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(Carbon::now()->addHours(2)->toIso8601String()),
            end_at: new Iso8601DateTimeVO(Carbon::now()->subHours(24)->toIso8601String()),
            status: RecurringTaskStatus::WAITING,
        );

        $errors = $this->validator->getValidationErrors($record);

        $this->assertContains('Task is in WAITING state, not PLAYING', $errors);
        $this->assertContains('Task has expired (end_at reached)', $errors);
        $this->assertContains('Task is not ready to run (start_at not reached)', $errors);
    }

    public function test_get_validation_errors_returns_invalid_class_error(): void
    {
        $record = new RecurringTaskRecord(
            alias: new TaskSignatureVO('test'),
            fqcn: 'NonExistentClass',
            payload: StrictDataObject::from([]),
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(Carbon::now()->subHours(2)->toIso8601String()),
            end_at: new Iso8601DateTimeVO(Carbon::now()->addDays(1)->toIso8601String()),
            status: RecurringTaskStatus::PLAYING,
        );

        $errors = $this->validator->getValidationErrors($record);
        $this->assertStringContainsString('Invalid task class', $errors->first());
    }

    public function test_get_validation_errors_returns_class_not_extending_error(): void
    {
        $record = new RecurringTaskRecord(
            alias: new TaskSignatureVO('test'),
            fqcn: TestUniqueTask::class,
            payload: StrictDataObject::from([]),
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(Carbon::now()->subHours(2)->toIso8601String()),
            end_at: new Iso8601DateTimeVO(Carbon::now()->addDays(1)->toIso8601String()),
            status: RecurringTaskStatus::PLAYING,
        );

        $errors = $this->validator->getValidationErrors($record);
        $this->assertStringContainsString('Invalid task class', $errors->first());
    }
}
