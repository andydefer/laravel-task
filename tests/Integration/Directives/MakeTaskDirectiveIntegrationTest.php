<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Integration\Directives;

use AndyDefer\Directive\Collections\ParameterCollection;
use AndyDefer\Directive\Enums\ExitCode;
use AndyDefer\Directive\Records\ParameterRecord;
use AndyDefer\Directive\Services\DirectiveInteractionService;
use AndyDefer\Task\Directives\MakeTaskDirective;
use AndyDefer\Task\Tests\IntegrationTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;

#[AllowMockObjectsWithoutExpectations]
final class MakeTaskDirectiveIntegrationTest extends IntegrationTestCase
{
    private MockObject&DirectiveInteractionService $interaction;
    private MakeTaskDirective $directive;

    protected function setUp(): void
    {
        parent::setUp();

        $this->interaction = $this->createMock(DirectiveInteractionService::class);
        $this->directive = new MakeTaskDirective($this->interaction);
    }

    public function test_get_signature_returns_correct_signature(): void
    {
        $signature = $this->directive->getSignature();

        $this->assertStringContainsString('make-task', $signature);
        $this->assertStringContainsString('{name', $signature);
        $this->assertStringContainsString('--force', $signature);
        $this->assertStringContainsString('--signature', $signature);
        $this->assertStringContainsString('--description', $signature);
        $this->assertStringContainsString('--delay', $signature);
        $this->assertStringContainsString('--max-attempts', $signature);
    }

    public function test_get_description_returns_correct_description(): void
    {
        $description = $this->directive->getDescription();

        $this->assertStringContainsString('Create a new Task class', $description);
    }

    public function test_get_aliases_returns_correct_aliases(): void
    {
        $aliases = $this->directive->getAliases();

        $this->assertTrue($aliases->contains('task-make'));
        $this->assertTrue($aliases->contains('create-task'));
        $this->assertSame(2, $aliases->count());
    }

    public function test_should_boot_laravel_returns_true(): void
    {
        $this->assertTrue($this->directive->shouldBootLaravel());
    }

    public function test_execute_with_valid_task_returns_success(): void
    {
        $arguments = new ParameterCollection;
        $arguments->add(new ParameterRecord(name: 'name', value: 'Users/SendWelcomeEmailTask'));
        $this->directive->setArguments($arguments);

        $options = new ParameterCollection;
        $options->add(new ParameterRecord(name: 'force', value: false));
        $this->directive->setOptions($options);

        $this->interaction->expects($this->atLeastOnce())
            ->method('info');

        $result = $this->directive->execute();

        $this->assertSame(ExitCode::SUCCESS, $result);
    }

    public function test_execute_without_name_returns_failure(): void
    {
        $arguments = new ParameterCollection;
        $this->directive->setArguments($arguments);

        $options = new ParameterCollection;
        $options->add(new ParameterRecord(name: 'force', value: false));
        $this->directive->setOptions($options);

        $result = $this->directive->execute();

        $this->assertSame(ExitCode::FAILURE, $result);
    }

    public function test_execute_with_custom_signature(): void
    {
        $arguments = new ParameterCollection;
        $arguments->add(new ParameterRecord(name: 'name', value: 'CustomTask'));
        $this->directive->setArguments($arguments);

        $options = new ParameterCollection;
        $options->add(new ParameterRecord(name: 'signature', value: 'custom:signature'));
        $options->add(new ParameterRecord(name: 'force', value: false));
        $this->directive->setOptions($options);

        $this->interaction->expects($this->atLeastOnce())
            ->method('info');

        $result = $this->directive->execute();

        $this->assertSame(ExitCode::SUCCESS, $result);
    }

    public function test_execute_with_force_flag_success(): void
    {
        $arguments = new ParameterCollection;
        $arguments->add(new ParameterRecord(name: 'name', value: 'Users/ExistingTask'));
        $this->directive->setArguments($arguments);

        $options = new ParameterCollection;
        $options->add(new ParameterRecord(name: 'force', value: true));
        $this->directive->setOptions($options);

        $this->interaction->expects($this->atLeastOnce())
            ->method('info');

        $result = $this->directive->execute();

        $this->assertSame(ExitCode::SUCCESS, $result);
    }

    public function test_execute_creates_task_file(): void
    {
        $tempDir = sys_get_temp_dir() . '/task_test_' . uniqid();
        mkdir($tempDir, 0755, true);

        // Mock app_path
        $originalAppPath = app_path();
        $this->app->instance('path', function () use ($tempDir) {
            return $tempDir;
        });

        $arguments = new ParameterCollection;
        $arguments->add(new ParameterRecord(name: 'name', value: 'TestTask'));
        $this->directive->setArguments($arguments);

        $options = new ParameterCollection;
        $options->add(new ParameterRecord(name: 'force', value: false));
        $this->directive->setOptions($options);

        $this->interaction->expects($this->atLeastOnce())
            ->method('info');

        $result = $this->directive->execute();

        $this->assertSame(ExitCode::SUCCESS, $result);

        // Nettoyer
        if (is_dir($tempDir)) {
            $this->deleteDirectory($tempDir);
        }
    }

    private function deleteDirectory(string $dir): void
    {
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
