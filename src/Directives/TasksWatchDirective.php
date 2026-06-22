<?php

declare(strict_types=1);

namespace AndyDefer\Task\Directives;

use AndyDefer\Directive\AbstractDirective;
use AndyDefer\Directive\Contexts\DirectiveContext;
use AndyDefer\Directive\Enums\ExitCode;
use AndyDefer\Directive\Services\DirectiveInteractionService;
use AndyDefer\Directive\Services\DirectiveTestingService;
use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\Task\Contracts\Services\TasksWatchServiceInterface;
use AndyDefer\Task\Records\CycleResultRecord;
use AndyDefer\Task\Services\TasksWatchService;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;

/**
 * Console directive for continuously watching and processing tasks in a loop.
 *
 * @example ./vendor/bin/directive tasks-watch --interval=30
 * @example ./vendor/bin/directive tasks-watch --duration=3600 --interval=60
 * @example ./vendor/bin/directive tasks-watch --recurring-only --limit=10 --verbose
 * @example ./vendor/bin/directive tasks-watch --unique-only --interval=15 --limit=5
 * @example ./vendor/bin/directive tasks-watch --testing --duration=2 --interval=5
 */
final class TasksWatchDirective extends AbstractDirective
{
    private bool $shouldStop = false;

    private int $cycleCount = 0;

    private int $totalSuccess = 0;

    private int $totalFailed = 0;

    private int $totalErrors = 0;

    private ?Iso8601DateTimeVO $startedAt = null;

    private TasksWatchServiceInterface $service;

    private const MIN_INTERVAL_SECONDS = 5;

    public function __construct(
        DirectiveContext $context,
        DirectiveInteractionService $interaction,
    ) {
        parent::__construct($context, $interaction);
    }

    public function getSignature(): string
    {
        return 'tasks-watch {--duration=} {--interval=60} {--unique-only} {--recurring-only} {--limit=} {--verbose} {--testing}';
    }

    public function shouldBootLaravel(): bool
    {
        return true;
    }

    public function getDescription(): string
    {
        return 'Watch and process tasks in a continuous loop with configurable interval (in seconds, min 5) and duration. Use --testing for development without full Laravel environment.';
    }

    public function getAliases(): StringTypedCollection
    {
        $aliases = new StringTypedCollection;
        $aliases->add('task-watch');
        $aliases->add('tasks-watch');

        return $aliases;
    }

    protected function execute(): ExitCode
    {
        // ✅ Récupérer le service via le conteneur Laravel
        $this->service = $this->getLaravel()->make(TasksWatchServiceInterface::class);

        // ✅ Mode testing : activer le DirectiveTestingService
        if ($this->hasOption('testing')) {
            $this->enableTestingMode();
        }

        $validationResult = $this->validateOptions();

        if ($validationResult !== null) {
            return $validationResult;
        }

        $this->installSignalHandlers();

        $this->startedAt = new Iso8601DateTimeVO;
        $this->displayStartMessage();

        $hasErrors = false;

        while ($this->shouldContinue()) {
            $this->cycleCount++;

            $cycleResult = $this->executeCycle();

            if ($cycleResult !== null) {
                $hasErrors = $hasErrors || $cycleResult->hasErrors;
                $this->totalSuccess += $cycleResult->success;
                $this->totalFailed += $cycleResult->failed;
                $this->totalErrors += $cycleResult->errors;
            }

            if ($this->shouldStop) {
                $this->info("\n🛑 Received interrupt signal. Stopping gracefully...");
                break;
            }

            if ($this->shouldContinue()) {
                $this->waitForInterval();
            }
        }

        $this->displayFinalSummary($cycleResult?->message ?? null);

        return $hasErrors ? ExitCode::FAILURE : ExitCode::SUCCESS;
    }

    // ==================== TESTING MODE ====================

    private function enableTestingMode(): void
    {
        if (! $this->service instanceof TasksWatchService) {
            return;
        }

        $testingService = new DirectiveTestingService($this->getLaravel());
        $this->service->enableTestingMode($testingService);

        $this->info('🧪 Testing mode enabled');
    }

    // ==================== VALIDATION ====================

    private function validateOptions(): ?ExitCode
    {
        $uniqueOnly = $this->hasOption('unique-only');
        $recurringOnly = $this->hasOption('recurring-only');

        if ($uniqueOnly && $recurringOnly) {
            $this->error('Cannot use both --unique-only and --recurring-only');

            return ExitCode::INVALID_ARGUMENT;
        }

        $duration = $this->option('duration');

        if ($duration !== null && (int) $duration <= 0) {
            $this->error('Duration must be a positive integer (in seconds)');

            return ExitCode::INVALID_ARGUMENT;
        }

        $interval = $this->option('interval');

        if ($interval !== null) {
            $intervalSeconds = (int) $interval;
            if ($intervalSeconds < self::MIN_INTERVAL_SECONDS) {
                $this->error(sprintf('Interval must be at least %d seconds', self::MIN_INTERVAL_SECONDS));

                return ExitCode::INVALID_ARGUMENT;
            }
        }

        $limit = $this->option('limit');

        if ($limit !== null && (int) $limit <= 0) {
            $this->error('Limit must be a positive integer');

            return ExitCode::INVALID_ARGUMENT;
        }

        return null;
    }

    // ==================== SIGNAL HANDLING ====================

    private function installSignalHandlers(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        pcntl_signal(SIGINT, [$this, 'handleSignal']);
        pcntl_signal(SIGTERM, [$this, 'handleSignal']);
    }

    public function handleSignal(int $signal): void
    {
        $this->shouldStop = true;

        $signalName = match ($signal) {
            SIGINT => 'SIGINT (Ctrl+C)',
            SIGTERM => 'SIGTERM',
            default => 'signal',
        };

        $this->warn("\n⚠️  Received {$signalName}, stopping after current cycle...");
    }

    private function dispatchSignals(): void
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }

    // ==================== CYCLE EXECUTION ====================

    private function executeCycle(): ?CycleResultRecord
    {
        $this->dispatchSignals();

        if ($this->shouldStop) {
            return null;
        }

        $cycleStartedAt = new Iso8601DateTimeVO;
        $this->displayCycleStart($this->cycleCount, $cycleStartedAt);

        $arguments = $this->service->buildProcessTasksArguments(
            uniqueOnly: $this->hasOption('unique-only'),
            recurringOnly: $this->hasOption('recurring-only'),
            limit: $this->option('limit') !== null ? (int) $this->option('limit') : null,
            verbose: $this->hasOption('verbose')
        );

        // Afficher la commande (adaptée au mode)
        if ($this->hasOption('testing')) {
            $this->info('  ➜ Running: [TEST MODE] process-tasks '.$arguments->join(' '));
        } else {
            $this->info('  ➜ Running: '.PHP_BINARY.' ./vendor/bin/directive process-tasks '.$arguments->join(' '));
        }

        $result = $this->service->executeCycle($this->cycleCount, $arguments, $cycleStartedAt);

        $this->displayCycleEnd($result, $cycleStartedAt);

        return $result;
    }

    // ==================== DISPLAY METHODS ====================

    private function displayStartMessage(): void
    {
        $this->newLine();
        $this->info('🚀 Starting tasks watch loop...');

        if ($this->hasOption('testing')) {
            $this->info('   🔬 Mode: TESTING (in-process execution)');
        }

        $duration = $this->option('duration');
        $intervalSeconds = (int) ($this->option('interval') ?? 60);

        if ($duration !== null) {
            $durationSeconds = (int) $duration;
            $this->info(sprintf(
                '   Duration: %d seconds (%s)',
                $durationSeconds,
                $this->service->formatDuration($durationSeconds)
            ));
        } else {
            $this->info('   Duration: unlimited (Ctrl+C to stop)');
        }

        $this->info(sprintf('   Interval: %d seconds (%s)',
            $intervalSeconds,
            $this->service->formatDuration($intervalSeconds)
        ));

        $options = new StringTypedCollection;

        if ($this->hasOption('unique-only')) {
            $options->add('--unique-only');
        }

        if ($this->hasOption('recurring-only')) {
            $options->add('--recurring-only');
        }

        if ($this->hasOption('limit')) {
            $options->add("--limit={$this->option('limit')}");
        }

        if ($this->hasOption('verbose')) {
            $options->add('--verbose');
        }

        if ($options->isNotEmpty()) {
            $this->info('   Options: '.$options->join(' '));
        }

        $this->newLine();
        $this->separator('=', 80);
    }

    private function displayCycleStart(int $cycleNumber, Iso8601DateTimeVO $startedAt): void
    {
        $time = $startedAt->toDateTime()->format('H:i:s');
        $this->newLine();
        $this->info(sprintf('🔄 Cycle #%d (started at %s):', $cycleNumber, $time));
    }

    private function displayCycleEnd(CycleResultRecord $result, Iso8601DateTimeVO $startedAt): void
    {
        $totalProcessed = $result->success + $result->failed;

        if ($totalProcessed === 0) {
            $this->info('  ⏳ No tasks to process');
        } else {
            $this->info(sprintf('  ✅ %d tasks succeeded, ❌ %d tasks failed', $result->success, $result->failed));
        }

        $elapsed = $this->service->calculateElapsedSeconds($startedAt);
        $this->info(sprintf('  ⏱️  Cycle duration: %.2f seconds', $elapsed));

        $intervalSeconds = (int) ($this->option('interval') ?? 60);
        $remaining = max(0, $intervalSeconds - $elapsed);

        if ($remaining > 0 && $this->shouldContinue()) {
            $this->info(sprintf('  ⏳ Next cycle in %.0f seconds...', $remaining));
        }
    }

    private function displayFinalSummary(?string $exception = null): void
    {
        $this->newLine();
        $this->separator('=', 80);
        $this->info('📊 === Summary ===');

        if ($this->hasOption('testing')) {
            $this->info('   🔬 Mode: TESTING');
        }

        $this->info(sprintf('  Cycles executed:  %d', $this->cycleCount));
        $this->info(sprintf('  Total success:    %d', $this->totalSuccess));
        $this->info(sprintf('  Total failures:   %d', $this->totalFailed));
        $this->info(sprintf('  Total errors:     %d', $this->totalErrors));

        if ($this->startedAt !== null) {
            $totalDuration = $this->service->calculateElapsedSeconds($this->startedAt);
            $this->info(sprintf('  Total duration:   %s', $this->service->formatDuration((int) $totalDuration)));
        }

        $this->newLine();

        if ($this->shouldStop) {
            $this->info('🛑 Stopped by user signal');
        } elseif ($this->option('duration') !== null) {
            $this->info('⏰ Duration reached. Stopping gracefully...');
        }

        $this->separator('=', 80);
        $this->newLine();

        if ($exception) {
            $this->error($exception);
        }
    }

    // ==================== UTILITY METHODS ====================

    private function shouldContinue(): bool
    {
        $this->dispatchSignals();

        $duration = $this->option('duration');

        return $this->service->shouldContinue(
            $this->shouldStop,
            $duration !== null ? (int) $duration : null,
            $this->startedAt
        );
    }

    private function waitForInterval(): void
    {
        $intervalSeconds = (int) ($this->option('interval') ?? 60);

        $this->service->waitForInterval($intervalSeconds, function (): bool {
            return $this->shouldContinue();
        });
    }
}
