<?php

declare(strict_types=1);

namespace AndyDefer\Task\Directives;

use AndyDefer\ConsoleWriter\Console\Components\Logger;
use AndyDefer\ConsoleWriter\Console\Console;
use AndyDefer\Directive\AbstractDirective;
use AndyDefer\Directive\Enums\ExitCode;
use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\Task\Handlers\SignalHandler;
use AndyDefer\Task\Records\TaskExecutionResultRecord;
use AndyDefer\Task\Services\Watchs\CycleCalculator;
use AndyDefer\Task\Services\Watchs\ParallelExecutor;
use AndyDefer\Task\Services\Watchs\ResultAggregator;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use RuntimeException;
use Throwable;

final class TasksWatchDirective extends AbstractDirective
{
    private const MIN_INTERVAL_SECONDS = 2;

    private Console $console;

    private SignalHandler $signalHandler;

    private CycleCalculator $cycleCalculator;

    private ParallelExecutor $parallelExecutor;

    private ResultAggregator $aggregator;

    public function getSignature(): string
    {
        return 'tasks:watch {interval=60} {duration=?} {limit=?} {parallel=?} {--unique-only} {--recurring-only} {--verbose}';
    }

    public function getDescription(): string
    {
        return 'Watch and process tasks in a continuous loop with configurable interval (in seconds, min 2) and duration. Use --parallel=N for parallel execution with N workers.';
    }

    public function getAliases(): StringTypedCollection
    {
        return StringTypedCollection::from(['task-watch', 'tw']);
    }

    protected function execute(): ExitCode
    {
        try {
            $this->boot();

            $this->console->info('👀 Starting task watch...');
            $this->displayStartMessage();

            $this->signalHandler->install();

            $startedAt = new Iso8601DateTimeVO;
            $hasFailures = false;
            $cycleNumber = 0;

            while ($this->cycleCalculator->shouldContinue($cycleNumber, $this->signalHandler->shouldStop())) {
                $this->signalHandler->dispatch();

                if ($this->signalHandler->shouldStop()) {
                    break;
                }

                // ✅ Incrémenter manuellement le cycle
                $cycleNumber++;
                $this->aggregator->startNewCycle();

                $this->console->line();
                $this->console->line(sprintf('🔄 Cycle #%d', $cycleNumber));

                $cycleResults = $this->executeCycle();

                if (! empty($cycleResults)) {
                    $this->aggregator->addResults($cycleResults);
                }

                $this->displayCycleSummary($cycleNumber, $cycleResults);

                $waitTime = $this->cycleCalculator->getNextWaitTime($cycleNumber);
                if ($waitTime->getValue() > 0 && $this->cycleCalculator->shouldContinue($cycleNumber, $this->signalHandler->shouldStop())) {
                    $this->waitWithSignals($waitTime);
                }

                if ($this->aggregator->hasFailures()) {
                    $hasFailures = true;
                }
            }

            $this->displayFinalSummary($startedAt);

            return $hasFailures ? ExitCode::FAILURE : ExitCode::SUCCESS;

        } catch (Throwable $e) {
            $this->getKernel()->addProblem(
                'tasks_watch_error',
                'Failed to watch tasks',
                $e->getMessage(),
                ['exception' => get_class($e)]
            );

            if (isset($this->console)) {
                $this->console->error('❌ Error: '.$e->getMessage());
            }

            return ExitCode::RUNTIME_ERROR;
        }
    }

    private function boot(): void
    {
        $app = $this->getApplication();

        if ($app === null) {
            throw new RuntimeException('Laravel container is not available');
        }

        $this->console = $app->make(Console::class);

        $interval = $this->getInterval();
        $duration = $this->getDuration();
        $kernel = $this->getKernel();

        if ($kernel === null) {
            throw new RuntimeException('Kernel is not available');
        }

        $this->signalHandler = new SignalHandler($this->console);
        $this->cycleCalculator = new CycleCalculator($interval, $duration);
        $this->parallelExecutor = new ParallelExecutor($this->getParallelWorkers(), $this->console, $kernel);
        $this->aggregator = new ResultAggregator;
    }

    private function executeCycle(): array
    {
        $uniqueOnly = $this->isFlagActive('unique-only');
        $recurringOnly = $this->isFlagActive('recurring-only');
        $verbose = $this->isFlagActive('verbose');
        $limit = $this->getLimit();

        return $this->parallelExecutor->execute(
            uniqueOnly: $uniqueOnly,
            recurringOnly: $recurringOnly,
            limit: $limit,
            verbose: $verbose
        );
    }

    private function getInterval(): DurationVO
    {
        $interval = (float) ($this->getArgument('interval') ?? 60);

        return new DurationVO(max($interval, self::MIN_INTERVAL_SECONDS));
    }

    private function getDuration(): ?DurationVO
    {
        $duration = $this->getArgument('duration');

        return $duration !== null ? new DurationVO((float) $duration) : null;
    }

    private function getLimit(): ?LimitVO
    {
        $limit = $this->getArgument('limit');

        return $limit !== null ? new LimitVO((int) $limit) : null;
    }

    private function getParallelWorkers(): int
    {
        $parallel = $this->getArgument('parallel');

        return $parallel !== null ? max(1, (int) $parallel) : 1;
    }

    /**
     * Attend avec précision en utilisant microtime pour un timing exact.
     *
     * Contrairement à sleep() qui peut être imprécis, cette méthode utilise
     * microtime() pour garantir une attente exacte.
     */
    private function waitWithSignals(DurationVO $waitTime): void
    {
        $seconds = $waitTime->getValue();
        $start = microtime(true);
        $elapsed = 0.0;

        while ($elapsed < $seconds) {
            if ($this->signalHandler->shouldStop()) {
                break;
            }

            $this->signalHandler->dispatch();

            // Calculer le temps restant
            $remaining = $seconds - $elapsed;
            $sleepTime = min(0.1, $remaining); // Dormir par petits morceaux de 0.1s

            if ($sleepTime > 0) {
                usleep((int) ($sleepTime * 1000000));
            }

            $elapsed = microtime(true) - $start;
        }
    }

    private function displayStartMessage(): void
    {
        $this->console->info(sprintf('  Interval: %ds', (int) $this->getInterval()->getValue()));

        $duration = $this->getDuration();
        if ($duration !== null) {
            $totalCycles = $this->cycleCalculator->getTotalCycles();
            $estimatedDuration = $this->cycleCalculator->getEstimatedDuration();

            $this->console->info(sprintf('  Duration: %ds (estimated: %ds, %d cycles)',
                (int) $duration->getValue(),
                (int) $estimatedDuration,
                $totalCycles
            ));
        }

        $workers = $this->getParallelWorkers();
        if ($workers > 1) {
            $this->console->info(sprintf('  Workers: %d', $workers));
        }

        $limit = $this->getLimit();
        if ($limit !== null) {
            $this->console->info(sprintf('  Limit: %d', $limit->getValue()));
        }

        $options = [];
        if ($this->isFlagActive('unique-only')) {
            $options[] = '--unique-only';
        }
        if ($this->isFlagActive('recurring-only')) {
            $options[] = '--recurring-only';
        }
        if ($this->isFlagActive('verbose')) {
            $options[] = '--verbose';
        }

        if (! empty($options)) {
            $this->console->info('  Options: '.implode(' ', $options));
        }

        $this->console->line('Press Ctrl+C to stop');
        $this->console->line();
    }

    private function displayCycleSummary(int $cycleNumber, array $results): void
    {
        $success = 0;
        $failed = 0;
        $total = 0;

        foreach ($results as $result) {
            if ($result instanceof TaskExecutionResultRecord) {
                $success += $result->success->getValue();
                $failed += $result->failed->getValue();
                $total += $result->total->getValue();
            }
        }

        echo Logger::debug(sprintf(
            'Cycle #%d | ✅ Success: %d | ❌ Failed: %d | 📦 Total: %d',
            $cycleNumber,
            $success,
            $failed,
            $total
        ));
    }

    private function displayFinalSummary(Iso8601DateTimeVO $startedAt): void
    {
        $elapsed = $startedAt->elapsed();
        $totalCycles = $this->aggregator->getCycleCount();

        $this->console->line();
        $this->console->title('📊 Watch Summary');
        $this->console->line();

        $this->console->info(sprintf('  🔄 Cycles: %d', $totalCycles));
        $this->console->info(sprintf('  ✅ Success: %d', $this->aggregator->getTotalSuccess()->getValue()));
        $this->console->info(sprintf('  ❌ Failed: %d', $this->aggregator->getTotalFailed()->getValue()));
        $this->console->info(sprintf('  ⚠️  Errors: %d', $this->aggregator->getTotalErrors()->getValue()));

        $duration = $this->getDuration();
        if ($duration !== null) {
            $this->console->info(sprintf('  ⏱️  Planned Duration: %ds', (int) $duration->getValue()));
        }
        $this->console->info(sprintf('  ⏱️  Elapsed: %.2fs', $elapsed->getValue()));

        if ($this->signalHandler->shouldStop()) {
            $this->console->alert('  🛑 Stopped by signal');
        }

        $this->console->line();
    }
}
