<?php

declare(strict_types=1);

namespace AndyDefer\Task\Directives;

use AndyDefer\ConsoleWriter\Console\Console;
use AndyDefer\Directive\AbstractDirective;
use AndyDefer\Directive\Enums\ExitCode;
use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\Task\Contracts\Services\WatchInterface;
use AndyDefer\Task\Contracts\Services\WatchRendererInterface;
use AndyDefer\Task\Enums\WatchMode;
use AndyDefer\Task\Executors\CycleExecutor;
use AndyDefer\Task\Factories\WatchLoopStrategyFactory;
use AndyDefer\Task\Handlers\SignalHandler;
use AndyDefer\Task\Records\LoopResultRecord;
use AndyDefer\Task\Runners\LoopRunner;
use AndyDefer\Task\Validators\OptionValidator;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use RuntimeException;

/**
 * Console directive for watching and processing tasks continuously.
 *
 * Runs tasks in a continuous loop with configurable intervals and duration.
 * Supports both unique and recurring tasks with various filtering options.
 */
final class TasksWatchDirective extends AbstractDirective
{
    /**
     * Returns the command signature with available options.
     *
     * @return string The command signature
     */
    public function getSignature(): string
    {
        return 'tasks-watch {--duration=} {--interval=60} {--unique-only} {--recurring-only} {--limit=} {--verbose} {--testing} {--parallel=}';
    }

    /**
     * Returns the command description.
     *
     * @return string The command description
     */
    public function getDescription(): string
    {
        return 'Watch and process tasks in a continuous loop with configurable interval (in seconds, min 3) and duration. Use --testing for development without full Laravel environment. Use --parallel=N for parallel execution with N workers.';
    }

    /**
     * Returns the command aliases.
     *
     * @return StringTypedCollection Collection of command aliases
     */
    public function getAliases(): StringTypedCollection
    {
        return StringTypedCollection::from(['task-watch', 'tasks-watch']);
    }

    /**
     * Executes the task watching directive.
     *
     * @return ExitCode The exit code indicating success or failure
     *
     * @throws RuntimeException When Laravel container is not available
     */
    protected function execute(): ExitCode
    {
        $app = $this->getLaravel();

        if ($app === null) {
            throw new RuntimeException('Laravel container is not available');
        }

        $service = $app->make(WatchInterface::class);
        $renderer = $app->make(WatchRendererInterface::class);
        $console = $app->make(Console::class);

        $validationResult = $this->validateOptions($console);
        if ($validationResult !== null) {
            return $validationResult;
        }

        $strategy = WatchLoopStrategyFactory::create($this, $app, $service);

        if ($strategy->getMode()->isTesting()) {
            $renderer->renderTestingModeEnabled();
        }

        $signalHandler = new SignalHandler($renderer);
        $signalHandler->install();

        $cycleExecutor = new CycleExecutor($service, $renderer);
        $loopRunner = new LoopRunner($cycleExecutor, $signalHandler, $renderer);

        $startedAt = new Iso8601DateTimeVO;
        $duration = $this->getDurationOption();
        $limit = $this->getLimitOption();
        $intervalSeconds = $this->getIntervalOption();
        $parallelWorkers = $this->getParallelWorkers();

        $this->renderStartMessage($renderer, $console);

        /** @var LoopResultRecord $result */
        $result = $loopRunner->run(
            strategy: $strategy,
            hasOptionUniqueOnly: $this->hasOption('unique-only'),
            hasOptionRecurringOnly: $this->hasOption('recurring-only'),
            limit: $limit,
            verbose: $this->hasOption('verbose'),
            duration: $duration,
            startedAt: $startedAt,
            intervalSeconds: $intervalSeconds,
            parallelWorkers: $parallelWorkers
        );

        $this->renderSummary(
            $renderer,
            $result,
            $startedAt,
            $signalHandler->shouldStop(),
            $strategy->getMode()
        );

        return $result->has_errors ? ExitCode::FAILURE : ExitCode::SUCCESS;
    }

    /**
     * Validates the command options.
     *
     * @param  Console  $console  The console instance for error output
     * @return ExitCode|null Exit code if validation fails, null otherwise
     */
    private function validateOptions(Console $console): ?ExitCode
    {
        $validator = new OptionValidator;

        $result = $validator->validate(
            uniqueOnly: $this->hasOption('unique-only'),
            recurringOnly: $this->hasOption('recurring-only'),
            duration: $this->option('duration'),
            interval: $this->option('interval'),
            limit: $this->option('limit'),
            console: $console
        );

        if ($result !== null) {
            return $result;
        }

        $parallel = $this->option('parallel');
        if ($parallel !== null && (int) $parallel < 1) {
            $console->error('Parallel workers must be at least 1');

            return ExitCode::INVALID_ARGUMENT;
        }

        return null;
    }

    /**
     * Returns the duration option as a Value Object.
     *
     * @return DurationVO|null The duration or null if not set
     */
    private function getDurationOption(): ?DurationVO
    {
        $duration = $this->option('duration');

        return $duration !== null ? new DurationVO((float) $duration) : null;
    }

    /**
     * Returns the limit option as a Value Object.
     *
     * @return LimitVO|null The limit or null if not set
     */
    private function getLimitOption(): ?LimitVO
    {
        $limit = $this->option('limit');

        return $limit !== null ? new LimitVO((int) $limit) : null;
    }

    /**
     * Returns the interval option as a Value Object.
     *
     * @return DurationVO The interval duration
     */
    private function getIntervalOption(): DurationVO
    {
        return new DurationVO((float) ($this->option('interval') ?? 60));
    }

    /**
     * Returns the number of parallel workers.
     *
     * @return int|null The number of workers or null if not set
     */
    private function getParallelWorkers(): ?int
    {
        $parallel = $this->option('parallel');

        return $parallel !== null ? (int) $parallel : null;
    }

    /**
     * Renders the start message for the watch command.
     *
     * @param  WatchRendererInterface  $renderer  The renderer instance
     * @param  Console  $console  The console instance
     */
    private function renderStartMessage(WatchRendererInterface $renderer, Console $console): void
    {
        $duration = $this->getDurationOption();
        $intervalSeconds = $this->getIntervalOption();
        $options = $this->buildOptionsCollection();
        $parallelWorkers = $this->getParallelWorkers();

        $renderer->renderStartMessage(
            duration: $duration,
            intervalSeconds: $intervalSeconds,
            options: $options,
            testingMode: $this->hasOption('testing'),
            parallelWorkers: $parallelWorkers
        );
    }

    /**
     * Builds a collection of active command options.
     *
     * @return StringTypedCollection Collection of option strings
     */
    private function buildOptionsCollection(): StringTypedCollection
    {
        $options = new StringTypedCollection;

        if ($this->hasOption('unique-only')) {
            $options->add('--unique-only');
        }

        if ($this->hasOption('recurring-only')) {
            $options->add('--recurring-only');
        }

        $limit = $this->option('limit');
        if ($limit !== null) {
            $options->add("--limit={$limit}");
        }

        if ($this->hasOption('verbose')) {
            $options->add('--verbose');
        }

        if ($this->hasOption('testing')) {
            $options->add('--testing');
        }

        $parallel = $this->option('parallel');
        if ($parallel !== null) {
            $options->add("--parallel={$parallel}");
        }

        return $options;
    }

    /**
     * Renders the final summary after the watch loop completes.
     *
     * @param  WatchRendererInterface  $renderer  The renderer instance
     * @param  LoopResultRecord  $result  The loop execution result
     * @param  Iso8601DateTimeVO  $startedAt  The start timestamp
     * @param  bool  $shouldStop  Whether a signal stopped the loop
     * @param  WatchMode  $mode  The watch mode
     */
    private function renderSummary(
        WatchRendererInterface $renderer,
        LoopResultRecord $result,
        Iso8601DateTimeVO $startedAt,
        bool $shouldStop,
        WatchMode $mode
    ): void {
        $duration = $this->option('duration');
        $durationReached = $duration !== null;

        $renderer->renderSummary(
            cycleCount: $result->cycle_count,
            totalSuccess: $result->total_success,
            totalFailed: $result->total_failed,
            totalErrors: $result->total_errors,
            startedAt: $startedAt,
            testingMode: $mode->isTesting(),
            stoppedBySignal: $shouldStop,
            durationReached: $durationReached,
            exception: $result->last_exception
        );
    }
}
