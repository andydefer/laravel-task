<?php

// tests/Integration/Directives/RunTaskDirectiveIntegrationTest.php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Directives;

use AndyDefer\Directive\Collections\ParameterCollection;
use AndyDefer\Directive\Enums\ExitCode;
use AndyDefer\Directive\Records\ParameterRecord;
use AndyDefer\Task\Directives\RunTaskDirective;
use AndyDefer\Task\Tests\IntegrationTestCase;

final class RunTaskDirectiveIntegrationTest extends IntegrationTestCase
{
    private RunTaskDirective $directive;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directive = $this->app->make(RunTaskDirective::class);
    }

    private function runAndCapture(callable $callback): void
    {
        ob_start();
        try {
            $callback();
        } finally {
            ob_end_clean();
        }
    }

    public function test_get_signature_returns_correct_string(): void
    {
        $signature = $this->directive->getSignature();

        $this->assertStringContainsString('run-task', $signature);
        $this->assertStringContainsString('--duration', $signature);
        $this->assertStringContainsString('--dry-run', $signature);
    }

    public function test_get_description_returns_string(): void
    {
        $description = $this->directive->getDescription();

        $this->assertIsString($description);
        $this->assertNotEmpty($description);
    }

    public function test_get_aliases_returns_aliases(): void
    {
        $aliases = $this->directive->getAliases();

        $this->assertTrue($aliases->contains('task-run'));
        $this->assertTrue($aliases->contains('tasks:run'));
    }

    public function test_should_boot_laravel_returns_true(): void
    {
        $this->assertTrue($this->directive->shouldBootLaravel());
    }

    public function test_execute_with_default_duration(): void
    {
        $this->directive->setOptions(new ParameterCollection());

        $result = null;
        $this->runAndCapture(function () use (&$result) {
            $result = $this->directive->execute();
        });

        $this->assertSame(ExitCode::SUCCESS, $result);
    }

    public function test_execute_with_custom_duration(): void
    {
        $options = new ParameterCollection();
        $options->add(new ParameterRecord(name: 'duration', value: '2'));
        $this->directive->setOptions($options);

        $result = null;
        $this->runAndCapture(function () use (&$result) {
            $result = $this->directive->execute();
        });

        $this->assertSame(ExitCode::SUCCESS, $result);
    }

    public function test_execute_with_dry_run_flag(): void
    {
        $options = new ParameterCollection();
        $options->add(new ParameterRecord(name: 'dry-run', value: true));
        $this->directive->setOptions($options);

        $result = null;
        $this->runAndCapture(function () use (&$result) {
            $result = $this->directive->execute();
        });

        $this->assertSame(ExitCode::SUCCESS, $result);
    }

    public function test_execute_with_both_duration_and_dry_run(): void
    {
        $options = new ParameterCollection();
        $options->add(new ParameterRecord(name: 'duration', value: '2'));
        $options->add(new ParameterRecord(name: 'dry-run', value: true));
        $this->directive->setOptions($options);

        $result = null;
        $this->runAndCapture(function () use (&$result) {
            $result = $this->directive->execute();
        });

        $this->assertSame(ExitCode::SUCCESS, $result);
    }

    public function test_execute_handles_no_pending_tasks(): void
    {
        $options = new ParameterCollection();
        $options->add(new ParameterRecord(name: 'duration', value: '1'));
        $this->directive->setOptions($options);

        $result = null;
        $this->runAndCapture(function () use (&$result) {
            $result = $this->directive->execute();
        });

        $this->assertSame(ExitCode::SUCCESS, $result);
    }
}
