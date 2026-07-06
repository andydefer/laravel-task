<?php

declare(strict_types=1);

namespace AndyDefer\Task\Directives;

use AndyDefer\ConsoleWriter\Console\Console;
use AndyDefer\Directive\AbstractDirective;
use AndyDefer\Directive\Enums\ExitCode;
use AndyDefer\Directive\Services\DirectiveTestingService;
use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\Task\Contracts\Services\WatchInterface;
use AndyDefer\Task\Contracts\Services\WatchRendererInterface;
use AndyDefer\Task\Enums\SignalName;
use AndyDefer\Task\Records\CycleResultRecord;
use AndyDefer\Task\Services\WatchService;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;

final class TasksWatchDirective extends AbstractDirective
{
    private const MIN_INTERVAL_SECONDS = 3;

    public function getSignature(): string
    {
        return 'tasks-watch {--duration=} {--interval=60} {--unique-only} {--recurring-only} {--limit=} {--verbose} {--testing}';
    }

    public function getDescription(): string
    {
        return 'Watch and process tasks in a continuous loop with configurable interval (in seconds, min 3) and duration. Use --testing for development without full Laravel environment.';
    }

    public function getAliases(): StringTypedCollection
    {
        return StringTypedCollection::from(['task-watch', 'tasks-watch']);
    }

    protected function execute(): ExitCode
    {
        // ✅ TOUTE L'INITIALISATION ICI
        $app = $this->getLaravel();

        if ($app === null) {
            throw new \RuntimeException('Laravel container is not available');
        }

        $service = $app->make(WatchInterface::class);
        $renderer = $app->make(WatchRendererInterface::class);
        $console = $app->make(Console::class);

        // ✅ Variables locales
        $shouldStop = false;
        $cycleCount = new CounterVO(0);
        $totalSuccess = new CounterVO(0);
        $totalFailed = new CounterVO(0);
        $totalErrors = new CounterVO(0);
        $startedAt = null;

        // ✅ Mode test
        if ($this->hasOption('testing') && $service instanceof WatchService) {
            $testingService = new DirectiveTestingService($app);
            $service->enableTestingMode($testingService);
            $renderer->renderTestingModeEnabled();
        }

        // ✅ Validation
        $validationResult = $this->validateOptions($console);
        if ($validationResult !== null) {
            return $validationResult;
        }

        // ✅ Signaux
        $this->installSignalHandlers($renderer, $shouldStop);

        $startedAt = new Iso8601DateTimeVO;
        $this->displayStartMessage($renderer, $console);

        $hasErrors = false;
        $lastException = null;

        while ($this->shouldContinue($service, $shouldStop, $startedAt)) {
            $cycleCount = $cycleCount->increment();

            $cycleResult = $this->executeCycle(
                $service,
                $renderer,
                $cycleCount,
                $shouldStop
            );

            if ($cycleResult !== null) {
                $hasErrors = $hasErrors || $cycleResult->hasErrors;

                $totalSuccess = $totalSuccess->add($cycleResult->success);
                $totalFailed = $totalFailed->add($cycleResult->failed);
                $totalErrors = $totalErrors->add($cycleResult->errors);

                $lastException = $cycleResult->message;
            }

            if ($shouldStop) {
                $renderer->renderInterruptSignal($this->getSignalName(SIGINT));
                break;
            }

            if ($this->shouldContinue($service, $shouldStop, $startedAt)) {
                $this->waitForInterval($service, $shouldStop, $startedAt);
            }
        }

        $this->renderSummary(
            $renderer,
            $cycleCount,
            $totalSuccess,
            $totalFailed,
            $totalErrors,
            $startedAt,
            $shouldStop,
            $lastException
        );

        return $hasErrors ? ExitCode::FAILURE : ExitCode::SUCCESS;
    }

    private function validateOptions(Console $console): ?ExitCode
    {
        $uniqueOnly = $this->hasOption('unique-only');
        $recurringOnly = $this->hasOption('recurring-only');

        if ($uniqueOnly && $recurringOnly) {
            $console->error('Cannot use both --unique-only and --recurring-only');

            return ExitCode::INVALID_ARGUMENT;
        }

        $duration = $this->option('duration');
        if ($duration !== null && (int) $duration <= 0) {
            $console->error('Duration must be a positive integer (in seconds)');

            return ExitCode::INVALID_ARGUMENT;
        }

        $interval = $this->option('interval');
        if ($interval !== null && (int) $interval < self::MIN_INTERVAL_SECONDS) {
            $console->error(sprintf(
                'Interval must be at least %d seconds',
                self::MIN_INTERVAL_SECONDS
            ));

            return ExitCode::INVALID_ARGUMENT;
        }

        $limit = $this->option('limit');
        if ($limit !== null && (int) $limit <= 0) {
            $console->error('Limit must be a positive integer');

            return ExitCode::INVALID_ARGUMENT;
        }

        return null;
    }

    private function installSignalHandlers(WatchRendererInterface $renderer, bool &$shouldStop): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        pcntl_signal(SIGINT, function () use ($renderer, &$shouldStop) {
            $shouldStop = true;
            $renderer->renderInterruptSignal($this->getSignalName(SIGINT));
        });

        pcntl_signal(SIGTERM, function () use ($renderer, &$shouldStop) {
            $shouldStop = true;
            $renderer->renderInterruptSignal($this->getSignalName(SIGTERM));
        });
    }

    private function getSignalName(int $signal): SignalName
    {
        return SignalName::fromNumber($signal) ?? SignalName::SIGTERM;
    }

    private function dispatchSignals(): void
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }

    private function displayStartMessage(
        WatchRendererInterface $renderer,
        Console $console
    ): void {
        $duration = $this->option('duration') !== null
            ? new DurationVO((float) $this->option('duration'))
            : null;

        $intervalSeconds = new DurationVO((float) ($this->option('interval') ?? 60));

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

        $renderer->renderStartMessage(
            duration: $duration,
            intervalSeconds: $intervalSeconds,
            options: $options,
            testingMode: $this->hasOption('testing')
        );
    }

    private function executeCycle(
        WatchInterface $service,
        WatchRendererInterface $renderer,
        CounterVO $cycleCount,
        bool &$shouldStop
    ): ?CycleResultRecord {
        $this->dispatchSignals();

        if ($shouldStop) {
            return null;
        }

        $cycleStartedAt = new Iso8601DateTimeVO;
        $renderer->renderCycleStart($cycleCount, $cycleStartedAt);

        $limit = $this->option('limit') !== null
            ? new LimitVO((int) $this->option('limit'))
            : null;

        $arguments = $service->buildArguments(
            uniqueOnly: $this->hasOption('unique-only'),
            recurringOnly: $this->hasOption('recurring-only'),
            limit: $limit,
            verbose: $this->hasOption('verbose')
        );

        $result = $service->executeCycle(
            $cycleCount,
            $arguments,
            $cycleStartedAt
        );

        $intervalSeconds = new DurationVO((float) ($this->option('interval') ?? 60));
        $renderer->renderCycleEnd($result, $cycleStartedAt, $intervalSeconds);

        return $result;
    }

    private function shouldContinue(
        WatchInterface $service,
        bool $shouldStop,
        ?Iso8601DateTimeVO $startedAt
    ): bool {
        $this->dispatchSignals();

        $duration = $this->option('duration') !== null
            ? new DurationVO((float) $this->option('duration'))
            : null;

        return $service->shouldContinue(
            $shouldStop,
            $duration,
            $startedAt
        );
    }

    private function waitForInterval(
        WatchInterface $service,
        bool &$shouldStop,
        ?Iso8601DateTimeVO $startedAt
    ): void {
        $intervalSeconds = new DurationVO((float) ($this->option('interval') ?? 60));

        $service->waitForInterval($intervalSeconds, function () use ($service, $shouldStop, $startedAt): bool {
            return $this->shouldContinue($service, $shouldStop, $startedAt);
        });
    }

    private function renderSummary(
        WatchRendererInterface $renderer,
        CounterVO $cycleCount,
        CounterVO $totalSuccess,
        CounterVO $totalFailed,
        CounterVO $totalErrors,
        Iso8601DateTimeVO $startedAt,
        bool $shouldStop,
        ?DescriptionVO $lastException = null
    ): void {
        $duration = $this->option('duration');
        $durationReached = $duration !== null;

        $renderer->renderSummary(
            cycleCount: $cycleCount,
            totalSuccess: $totalSuccess,
            totalFailed: $totalFailed,
            totalErrors: $totalErrors,
            startedAt: $startedAt,
            testingMode: $this->hasOption('testing'),
            stoppedBySignal: $shouldStop,
            durationReached: $durationReached,
            exception: $lastException
        );
    }
}
