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
    private string $tempDir;
    private string $oldCwd;

    protected function setUp(): void
    {
        parent::setUp();

        $this->interaction = $this->createMock(DirectiveInteractionService::class);
        $this->tempDir = sys_get_temp_dir() . '/task_test_' . uniqid();

        $stubDir = $this->tempDir . '/stubs';
        mkdir($stubDir, 0755, true);
        $stubPath = $stubDir . '/task.stub';
        file_put_contents($stubPath, '<?php

declare(strict_types=1);

namespace App\Tasks;

use AndyDefer\Task\AbstractTask;
use AndyDefer\Task\Records\TaskConfigRecord;

final class {{ class }} extends AbstractTask
{
    public function getConfig(): TaskConfigRecord
    {
        return new TaskConfigRecord(
            signature: \'{{ signature }}\',
            description: \'Description for {{ class }}\',
            delaySeconds: 300,
            maxAttempts: 3,
        );
    }

    protected function process(): void
    {
        // Your task logic here
    }
}');

        $this->directive = new MakeTaskDirective($this->interaction, $stubPath);

        $this->oldCwd = getcwd();
        chdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        chdir($this->oldCwd);

        if (is_dir($this->tempDir)) {
            $this->deleteDirectory($this->tempDir);
        }

        parent::tearDown();
    }

    public function test_get_signature_returns_correct_signature(): void
    {
        $signature = $this->directive->getSignature();

        $this->assertStringContainsString('make-task', $signature);
        $this->assertStringContainsString('{name}', $signature);
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

    public function test_execute_with_valid_task_name_returns_success(): void
    {
        $arguments = new ParameterCollection;
        $arguments->add(new ParameterRecord(name: 'name', value: 'send-welcome-email'));
        $this->directive->setArguments($arguments);

        $options = new ParameterCollection;
        $this->directive->setOptions($options);

        $this->interaction->expects($this->atLeastOnce())
            ->method('info');

        $result = $this->directive->execute();

        $this->assertSame(ExitCode::SUCCESS, $result);

        $expectedPath = $this->tempDir . '/app/Tasks/SendWelcomeEmailTask.php';
        $this->assertFileExists($expectedPath);
    }

    public function test_execute_without_name_returns_failure(): void
    {
        $arguments = new ParameterCollection;
        $this->directive->setArguments($arguments);

        $options = new ParameterCollection;
        $this->directive->setOptions($options);

        $this->interaction->expects($this->once())
            ->method('error')
            ->with('Task name is required');

        $result = $this->directive->execute();

        $this->assertSame(ExitCode::INVALID_ARGUMENT, $result);
    }

    public function test_execute_with_pascal_case_name_works(): void
    {
        $arguments = new ParameterCollection;
        $arguments->add(new ParameterRecord(name: 'name', value: 'SendWelcomeEmail'));
        $this->directive->setArguments($arguments);

        $options = new ParameterCollection;
        $this->directive->setOptions($options);

        $this->interaction->expects($this->atLeastOnce())
            ->method('info');

        $result = $this->directive->execute();

        $this->assertSame(ExitCode::SUCCESS, $result);

        $expectedPath = $this->tempDir . '/app/Tasks/SendWelcomeEmailTask.php';
        $this->assertFileExists($expectedPath);
    }

    public function test_execute_creates_task_file_with_correct_content(): void
    {
        $arguments = new ParameterCollection;
        $arguments->add(new ParameterRecord(name: 'name', value: 'test-task'));
        $this->directive->setArguments($arguments);

        $options = new ParameterCollection;
        $this->directive->setOptions($options);

        $result = $this->directive->execute();

        $this->assertSame(ExitCode::SUCCESS, $result);

        $expectedPath = $this->tempDir . '/app/Tasks/TestTask.php';
        $this->assertFileExists($expectedPath);

        $content = file_get_contents($expectedPath);
        $this->assertStringContainsString('class TestTask extends AbstractTask', $content);
        $this->assertStringContainsString("signature: 'test-task'", $content);
    }

    public function test_execute_prevents_overwriting_existing_file(): void
    {
        $appDir = $this->tempDir . '/app/Tasks';
        mkdir($appDir, 0755, true);
        $existingFile = $appDir . '/ExistingTask.php';
        file_put_contents($existingFile, 'existing content');

        $arguments = new ParameterCollection;
        $arguments->add(new ParameterRecord(name: 'name', value: 'existing-task'));
        $this->directive->setArguments($arguments);

        $options = new ParameterCollection;
        $this->directive->setOptions($options);

        $this->interaction->expects($this->once())
            ->method('error')
            ->with($this->stringContains('Task already exists'));

        $result = $this->directive->execute();

        $this->assertSame(ExitCode::FAILURE, $result);
    }

    public function test_generate_class_name_from_kebab_case(): void
    {
        $reflection = new \ReflectionClass($this->directive);
        $method = $reflection->getMethod('generateClassName');
        $method->setAccessible(true);

        $result = $method->invoke($this->directive, 'send-welcome-email');

        $this->assertSame('SendWelcomeEmailTask', $result);
    }

    public function test_generate_class_name_from_pascal_case(): void
    {
        $reflection = new \ReflectionClass($this->directive);
        $method = $reflection->getMethod('generateClassName');
        $method->setAccessible(true);

        $result = $method->invoke($this->directive, 'SendWelcomeEmail');

        $this->assertSame('SendWelcomeEmailTask', $result);
    }

    public function test_generate_class_name_adds_task_suffix(): void
    {
        $reflection = new \ReflectionClass($this->directive);
        $method = $reflection->getMethod('generateClassName');
        $method->setAccessible(true);

        $result = $method->invoke($this->directive, 'cleanup');

        $this->assertSame('CleanupTask', $result);
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
