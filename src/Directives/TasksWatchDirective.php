<?php

declare(strict_types=1);

namespace AndyDefer\Task\Directives;

use AndyDefer\Directive\AbstractDirective;
use AndyDefer\Directive\Contexts\DirectiveContext;
use AndyDefer\Directive\Enums\ExitCode;
use AndyDefer\Directive\Services\DirectiveInteractionService;
use AndyDefer\Directive\Services\DirectiveTestingService;
use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\Task\Contracts\Services\WatchRendererServiceInterface;
use AndyDefer\Task\Contracts\Services\WatchServiceInterface;
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

    private bool $shouldStop = false;

    private CounterVO $cycleCount;

    private CounterVO $totalSuccess;

    private CounterVO $totalFailed;

    private CounterVO $totalErrors;

    private ?Iso8601DateTimeVO $startedAt = null;

    private WatchServiceInterface $service;

    private WatchRendererServiceInterface $renderer;

    public function __construct(
        DirectiveContext $context,
        DirectiveInteractionService $interaction,
    ) {
        parent::__construct($context, $interaction);

        $this->cycleCount = new CounterVO(0);
        $this->totalSuccess = new CounterVO(0);
        $this->totalFailed = new CounterVO(0);
        $this->totalErrors = new CounterVO(0);
    }

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
        $this->initializeServices();
        $this->handleTestingMode();

        $validationResult = $this->validateOptions();
        if ($validationResult !== null) {
            return $validationResult;
        }

        $this->installSignalHandlers();

        $this->startedAt = new Iso8601DateTimeVO;
        $this->displayStartMessage();

        $hasErrors = false;
        $lastException = null;

        while ($this->shouldContinue()) {
            $this->cycleCount = $this->cycleCount->increment();

            $cycleResult = $this->executeCycle();

            if ($cycleResult !== null) {
                $hasErrors = $hasErrors || $cycleResult->hasErrors;

                $this->totalSuccess = $this->totalSuccess->add($cycleResult->success);
                $this->totalFailed = $this->totalFailed->add($cycleResult->failed);
                $this->totalErrors = $this->totalErrors->add($cycleResult->errors);

                $lastException = $cycleResult->message;
            }

            if ($this->shouldStop) {
                $this->renderer->renderInterruptSignal($this->getSignalName(SIGINT));
                break;
            }

            if ($this->shouldContinue()) {
                $this->waitForInterval();
            }
        }

        $this->renderSummary($lastException);

        return $hasErrors ? ExitCode::FAILURE : ExitCode::SUCCESS;
    }

    private function initializeServices(): void
    {
        $this->service = $this->getLaravel()->make(WatchServiceInterface::class);
        $this->renderer = $this->getLaravel()->make(WatchRendererServiceInterface::class);
    }

    private function handleTestingMode(): void
    {
        if (! $this->hasOption('testing') || ! $this->service instanceof WatchService) {
            return;
        }

        $testingService = new DirectiveTestingService($this->getLaravel());
        $this->service->enableTestingMode($testingService);
        $this->renderer->renderTestingModeEnabled();
    }

    private function validateOptions(): ?ExitCode
    {
        $uniqueOnly = $this->hasOption('unique-only');
        $recurringOnly = $this->hasOption('recurring-only');

        if ($uniqueOnly && $recurringOnly) {
            $this->interaction->error('Cannot use both --unique-only and --recurring-only');

            return ExitCode::INVALID_ARGUMENT;
        }

        $duration = $this->option('duration');
        if ($duration !== null && (int) $duration <= 0) {
            $this->interaction->error('Duration must be a positive integer (in seconds)');

            return ExitCode::INVALID_ARGUMENT;
        }

        $interval = $this->option('interval');
        if ($interval !== null && (int) $interval < self::MIN_INTERVAL_SECONDS) {
            $this->interaction->error(sprintf(
                'Interval must be at least %d seconds',
                self::MIN_INTERVAL_SECONDS
            ));

            return ExitCode::INVALID_ARGUMENT;
        }

        $limit = $this->option('limit');
        if ($limit !== null && (int) $limit <= 0) {
            $this->interaction->error('Limit must be a positive integer');

            return ExitCode::INVALID_ARGUMENT;
        }

        return null;
    }

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
        $this->renderer->renderInterruptSignal($this->getSignalName($signal));
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

    private function executeCycle(): ?CycleResultRecord
    {
        $this->dispatchSignals();

        if ($this->shouldStop) {
            return null;
        }

        $cycleStartedAt = new Iso8601DateTimeVO;
        $this->renderer->renderCycleStart($this->cycleCount, $cycleStartedAt);

        $limit = $this->option('limit') !== null
            ? new LimitVO((int) $this->option('limit'))
            : null;

        $arguments = $this->service->buildArguments(
            uniqueOnly: $this->hasOption('unique-only'),
            recurringOnly: $this->hasOption('recurring-only'),
            limit: $limit,
            verbose: $this->hasOption('verbose')
        );

        $result = $this->service->executeCycle(
            $this->cycleCount,
            $arguments,
            $cycleStartedAt
        );

        $intervalSeconds = new DurationVO((float) ($this->option('interval') ?? 60));
        $this->renderer->renderCycleEnd($result, $cycleStartedAt, $intervalSeconds);

        return $result;
    }

    private function displayStartMessage(): void
    {
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

        $this->renderer->renderStartMessage(
            duration: $duration,
            intervalSeconds: $intervalSeconds,
            options: $options,
            testingMode: $this->hasOption('testing')
        );
    }

    private function renderSummary(?DescriptionVO $exception = null): void
    {
        $duration = $this->option('duration');
        $durationReached = $duration !== null;

        $exceptionVO = $exception !== null ? $exception : null;

        $this->renderer->renderSummary(
            cycleCount: $this->cycleCount,
            totalSuccess: $this->totalSuccess,
            totalFailed: $this->totalFailed,
            totalErrors: $this->totalErrors,
            startedAt: $this->startedAt,
            testingMode: $this->hasOption('testing'),
            stoppedBySignal: $this->shouldStop,
            durationReached: $durationReached,
            exception: $exceptionVO
        );
    }

    private function shouldContinue(): bool
    {
        $this->dispatchSignals();

        $duration = $this->option('duration') !== null
            ? new DurationVO((float) $this->option('duration'))
            : null;

        return $this->service->shouldContinue(
            $this->shouldStop,
            $duration,
            $this->startedAt
        );
    }

    private function waitForInterval(): void
    {
        $intervalSeconds = new DurationVO((float) ($this->option('interval') ?? 60));

        $this->service->waitForInterval($intervalSeconds, function (): bool {
            return $this->shouldContinue();
        });
    }
}
