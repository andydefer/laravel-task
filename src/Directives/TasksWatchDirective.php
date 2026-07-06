<?php

declare(strict_types=1);

namespace AndyDefer\Task\Directives;

use AndyDefer\ConsoleWriter\Console\Console;
use AndyDefer\Directive\AbstractDirective;
use AndyDefer\Directive\Enums\ExitCode;
use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\Task\Contracts\Services\WatchInterface;
use AndyDefer\Task\Contracts\Services\WatchRendererInterface;
use AndyDefer\Task\Executors\CycleExecutor;
use AndyDefer\Task\Factories\WatchLoopStrategyFactory;
use AndyDefer\Task\Handlers\SignalHandler;
use AndyDefer\Task\Records\LoopResultRecord;
use AndyDefer\Task\Runners\LoopRunner;
use AndyDefer\Task\Validators\OptionValidator;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;

final class TasksWatchDirective extends AbstractDirective
{
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
        $app = $this->getLaravel();

        if ($app === null) {
            throw new \RuntimeException('Laravel container is not available');
        }

        $service = $app->make(WatchInterface::class);
        $renderer = $app->make(WatchRendererInterface::class);
        $console = $app->make(Console::class);

        // ✅ Validation des options
        $validator = new OptionValidator;
        $validationResult = $validator->validate(
            uniqueOnly: $this->hasOption('unique-only'),
            recurringOnly: $this->hasOption('recurring-only'),
            duration: $this->option('duration'),
            interval: $this->option('interval'),
            limit: $this->option('limit'),
            console: $console
        );

        if ($validationResult !== null) {
            return $validationResult;
        }

        // ✅ Créer la stratégie
        $strategy = WatchLoopStrategyFactory::create($this, $app, $service);

        if ($strategy->isTesting()) {
            $renderer->renderTestingModeEnabled();
        }

        // ✅ Signaux
        $signalHandler = new SignalHandler($renderer);
        $signalHandler->install();

        // ✅ Exécution
        $cycleExecutor = new CycleExecutor($service, $renderer);
        $loopRunner = new LoopRunner($cycleExecutor, $signalHandler, $renderer);

        $startedAt = new Iso8601DateTimeVO;
        $duration = $this->option('duration') !== null
            ? new DurationVO((float) $this->option('duration'))
            : null;

        $limit = $this->option('limit') !== null
            ? new LimitVO((int) $this->option('limit'))
            : null;

        $intervalSeconds = new DurationVO((float) ($this->option('interval') ?? 60));

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
            intervalSeconds: $intervalSeconds
        );

        // ✅ Résumé
        $this->renderSummary(
            $renderer,
            $result,
            $startedAt,
            $signalHandler->shouldStop(),
            $strategy->isTesting()
        );

        return $result->hasErrors ? ExitCode::FAILURE : ExitCode::SUCCESS;
    }

    private function renderStartMessage(WatchRendererInterface $renderer, Console $console): void
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

        $renderer->renderStartMessage(
            duration: $duration,
            intervalSeconds: $intervalSeconds,
            options: $options,
            testingMode: $this->hasOption('testing')
        );
    }

    private function renderSummary(
        WatchRendererInterface $renderer,
        LoopResultRecord $result,
        Iso8601DateTimeVO $startedAt,
        bool $shouldStop,
        bool $isTesting
    ): void {
        $duration = $this->option('duration');
        $durationReached = $duration !== null;

        $renderer->renderSummary(
            cycleCount: $result->cycleCount,
            totalSuccess: $result->totalSuccess,
            totalFailed: $result->totalFailed,
            totalErrors: $result->totalErrors,
            startedAt: $startedAt,
            testingMode: $isTesting,
            stoppedBySignal: $shouldStop,
            durationReached: $durationReached,
            exception: $result->lastException
        );
    }
}
