<?php

declare(strict_types=1);

namespace AndyDefer\Task\Tests\Unit\Directives;

use AndyDefer\Directive\Collections\ParameterCollection;
use AndyDefer\Directive\Enums\ExitCode;
use AndyDefer\Directive\Records\ParameterRecord;
use AndyDefer\Directive\Testing\InteractsWithDirectives;
use AndyDefer\Directive\Services\DirectiveInteractionService;
use AndyDefer\Directive\Services\LaravelBootstrapper;
use AndyDefer\Logger\Logger;
use AndyDefer\Task\Directives\RunTaskDirective;
use AndyDefer\Task\Services\ProcessManager;
use AndyDefer\Task\Services\TaskRunner;
use AndyDefer\Task\Services\TaskStorage;
use AndyDefer\Task\Services\TaskValidator;
use AndyDefer\Task\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;

#[AllowMockObjectsWithoutExpectations]
final class RunTaskDirectiveTest extends UnitTestCase
{
    use InteractsWithDirectives;

    private TaskStorage&MockObject $storage;
    private TaskRunner&MockObject $runner;
    private TaskValidator&MockObject $validator;
    private Logger&MockObject $logger;
    private DirectiveInteractionService&MockObject $interaction;
    private LaravelBootstrapper&MockObject $bootstrapper;
    private ProcessManager&MockObject $processManager;
    private RunTaskDirective $directive;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initDirectiveTesting(bootLaravel: true);

        $this->storage = $this->createMock(TaskStorage::class);
        $this->runner = $this->createMock(TaskRunner::class);
        $this->validator = $this->createMock(TaskValidator::class);
        $this->logger = $this->createMock(Logger::class);
        $this->interaction = $this->createMock(DirectiveInteractionService::class);
        $this->bootstrapper = $this->createMock(LaravelBootstrapper::class);
        $this->processManager = $this->createMock(ProcessManager::class);

        $this->directive = new RunTaskDirective(
            $this->interaction,
            $this->storage,
            $this->runner,
            $this->validator,
            $this->logger,
            $this->bootstrapper,
            $this->processManager,
        );

        $this->registerDirective($this->directive);
    }

    protected function tearDown(): void
    {
        $this->destroyDirectiveTesting();
        parent::tearDown();
    }

    // ==================== Tests de base ====================

    public function test_get_signature_returns_correct_string(): void
    {
        $signature = $this->directive->getSignature();

        $this->assertStringContainsString('run-task', $signature);
        $this->assertStringContainsString('--duration=', $signature);
        $this->assertStringContainsString('--dry-run', $signature);
        $this->assertStringContainsString('--no-fork', $signature);
        $this->assertStringContainsString('--lock-path=', $signature);
    }

    public function test_get_description_returns_string(): void
    {
        $description = $this->directive->getDescription();

        $this->assertIsString($description);
        $this->assertNotEmpty($description);
        $this->assertStringContainsString('pending and recurring tasks', $description);
    }

    public function test_get_aliases_returns_aliases(): void
    {
        $aliases = $this->directive->getAliases();

        $this->assertTrue($aliases->contains('task-run'));
        $this->assertTrue($aliases->contains('tasks-run'));
        $this->assertSame(2, $aliases->count());
    }

    public function test_should_boot_laravel_returns_true(): void
    {
        $this->assertTrue($this->directive->shouldBootLaravel());
    }

    // ==================== Tests d'exécution avec ProcessManager mocké ====================

    public function test_execute_with_default_duration(): void
    {
        $this->processManager->expects($this->once())
            ->method('run')
            ->with(60, false);

        $infoCallCount = 0;
        $this->interaction->expects($this->exactly(2))
            ->method('info')
            ->willReturnCallback(function ($message) use (&$infoCallCount) {
                $infoCallCount++;
                if ($infoCallCount === 1) {
                    $this->assertStringContainsString('Starting task poller for 60 seconds', $message);
                } elseif ($infoCallCount === 2) {
                    $this->assertStringContainsString('Task poller finished', $message);
                }
            });

        $result = $this->directive->execute();

        $this->assertSame(ExitCode::SUCCESS, $result);
    }

    public function test_execute_with_custom_duration(): void
    {
        $options = new ParameterCollection();
        $options->add(new ParameterRecord(name: 'duration', value: '120'));
        $this->directive->setOptions($options);

        $this->processManager->expects($this->once())
            ->method('run')
            ->with(120, false);

        $infoCallCount = 0;
        $this->interaction->expects($this->exactly(2))
            ->method('info')
            ->willReturnCallback(function ($message) use (&$infoCallCount) {
                $infoCallCount++;
                if ($infoCallCount === 1) {
                    $this->assertStringContainsString('Starting task poller for 120 seconds', $message);
                } elseif ($infoCallCount === 2) {
                    $this->assertStringContainsString('Task poller finished', $message);
                }
            });

        $result = $this->directive->execute();

        $this->assertSame(ExitCode::SUCCESS, $result);
    }

    public function test_execute_with_dry_run_flag(): void
    {
        $options = new ParameterCollection();
        $options->add(new ParameterRecord(name: 'dry-run', value: true));
        $this->directive->setOptions($options);

        $this->processManager->expects($this->once())
            ->method('run')
            ->with(60, true);

        $this->interaction->expects($this->once())
            ->method('warn')
            ->with($this->stringContains('Dry run mode'));

        $infoCallCount = 0;
        $this->interaction->expects($this->exactly(2))
            ->method('info')
            ->willReturnCallback(function ($message) use (&$infoCallCount) {
                $infoCallCount++;
                if ($infoCallCount === 1) {
                    $this->assertStringContainsString('Starting task poller for 60 seconds', $message);
                } elseif ($infoCallCount === 2) {
                    $this->assertStringContainsString('Task poller finished', $message);
                }
            });

        $result = $this->directive->execute();

        $this->assertSame(ExitCode::SUCCESS, $result);
    }

    public function test_execute_with_no_fork_flag(): void
    {
        $options = new ParameterCollection();
        $options->add(new ParameterRecord(name: 'no-fork', value: true));
        $this->directive->setOptions($options);

        $this->processManager->expects($this->once())
            ->method('run')
            ->with(60, false);

        $this->interaction->expects($this->never())
            ->method('warn');

        $result = $this->directive->execute();

        $this->assertSame(ExitCode::SUCCESS, $result);
    }

    public function test_execute_with_custom_lock_path(): void
    {
        $options = new ParameterCollection();
        $options->add(new ParameterRecord(name: 'lock-path', value: '/custom/lock/path'));
        $this->directive->setOptions($options);

        $this->processManager->expects($this->once())
            ->method('run')
            ->with(60, false);

        $result = $this->directive->execute();

        $this->assertSame(ExitCode::SUCCESS, $result);
    }

    public function test_execute_with_both_duration_and_dry_run(): void
    {
        $options = new ParameterCollection();
        $options->add(new ParameterRecord(name: 'duration', value: '45'));
        $options->add(new ParameterRecord(name: 'dry-run', value: true));
        $this->directive->setOptions($options);

        $this->processManager->expects($this->once())
            ->method('run')
            ->with(45, true);

        $this->interaction->expects($this->once())
            ->method('warn')
            ->with($this->stringContains('Dry run mode'));

        $result = $this->directive->execute();

        $this->assertSame(ExitCode::SUCCESS, $result);
    }

    public function test_execute_with_all_options(): void
    {
        $options = new ParameterCollection();
        $options->add(new ParameterRecord(name: 'duration', value: '30'));
        $options->add(new ParameterRecord(name: 'dry-run', value: true));
        $options->add(new ParameterRecord(name: 'no-fork', value: true));
        $options->add(new ParameterRecord(name: 'lock-path', value: '/tmp/test.lock'));
        $this->directive->setOptions($options);

        $this->processManager->expects($this->once())
            ->method('run')
            ->with(30, true);

        $result = $this->directive->execute();

        $this->assertSame(ExitCode::SUCCESS, $result);
    }

    // ==================== Tests de messages de fin ====================

    public function test_execute_displays_finished_message(): void
    {
        $this->processManager->expects($this->once())
            ->method('run')
            ->with(60, false);

        $foundStartMessage = false;
        $foundFinishedMessage = false;

        $this->interaction->expects($this->exactly(2))
            ->method('info')
            ->willReturnCallback(function ($message) use (&$foundStartMessage, &$foundFinishedMessage) {
                if (str_contains($message, 'Starting task poller')) {
                    $foundStartMessage = true;
                }
                if (str_contains($message, 'Task poller finished')) {
                    $foundFinishedMessage = true;
                }
            });

        $result = $this->directive->execute();

        $this->assertTrue($foundStartMessage, 'Le message de démarrage n\'a pas été trouvé');
        $this->assertTrue($foundFinishedMessage, 'Le message de fin n\'a pas été trouvé');
        $this->assertSame(ExitCode::SUCCESS, $result);
    }

    // ==================== Tests avec valeurs par défaut non fournies ====================

    public function test_execute_when_duration_option_not_provided_uses_default(): void
    {
        $options = new ParameterCollection();
        $this->directive->setOptions($options);

        $this->processManager->expects($this->once())
            ->method('run')
            ->with(60, false);

        $result = $this->directive->execute();

        $this->assertSame(ExitCode::SUCCESS, $result);
    }

    public function test_execute_when_lock_path_empty_uses_null(): void
    {
        $options = new ParameterCollection();
        $options->add(new ParameterRecord(name: 'lock-path', value: ''));
        $this->directive->setOptions($options);

        $this->processManager->expects($this->once())
            ->method('run')
            ->with(60, false);

        $result = $this->directive->execute();

        $this->assertSame(ExitCode::SUCCESS, $result);
    }

    // ==================== Tests de bord avec valeurs non numériques ====================

    public function test_execute_with_non_numeric_duration_uses_casting(): void
    {
        $options = new ParameterCollection();
        $options->add(new ParameterRecord(name: 'duration', value: 'abc'));
        $this->directive->setOptions($options);

        $this->processManager->expects($this->once())
            ->method('run')
            ->with(0, false);

        $result = $this->directive->execute();

        $this->assertSame(ExitCode::SUCCESS, $result);
    }

    public function test_execute_with_negative_duration(): void
    {
        $options = new ParameterCollection();
        $options->add(new ParameterRecord(name: 'duration', value: '-10'));
        $this->directive->setOptions($options);

        $this->processManager->expects($this->once())
            ->method('run')
            ->with(-10, false);

        $result = $this->directive->execute();

        $this->assertSame(ExitCode::SUCCESS, $result);
    }

    // ==================== Tests de vérification des appels ====================

    public function test_execute_calls_info_at_least_twice(): void
    {
        $this->processManager->expects($this->once())
            ->method('run');

        $this->interaction->expects($this->atLeast(2))
            ->method('info');

        $this->directive->execute();
    }

    public function test_execute_passes_correct_parameters_to_process_manager(): void
    {
        $duration = 90;
        $dryRun = true;

        $options = new ParameterCollection();
        $options->add(new ParameterRecord(name: 'duration', value: (string) $duration));
        $options->add(new ParameterRecord(name: 'dry-run', value: $dryRun));
        $this->directive->setOptions($options);

        $this->processManager->expects($this->once())
            ->method('run')
            ->with($duration, $dryRun);

        $this->directive->execute();
    }

    // ==================== Test avec instance réelle ====================

    public function test_directive_can_be_instantiated_without_process_manager(): void
    {
        $directive = new RunTaskDirective(
            $this->interaction,
            $this->storage,
            $this->runner,
            $this->validator,
            $this->logger,
            $this->bootstrapper,
            null,
        );

        $this->assertInstanceOf(RunTaskDirective::class, $directive);
    }
}
