<?php

declare(strict_types=1);

namespace AndyDefer\Task\Directives;

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
    private const MIN_INTERVAL_SECONDS = 3;

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
        return 'Watch and process tasks in a continuous loop with configurable interval (in seconds, min 3) and duration. Use --parallel=N for parallel execution with N workers.';
    }

    public function getAliases(): StringTypedCollection
    {
        return StringTypedCollection::from(['task-watch', 'tasks-watch']);
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

            while ($this->cycleCalculator->shouldContinue($this->aggregator->getCycleCount(), $this->signalHandler->shouldStop())) {
                $this->signalHandler->dispatch();

                if ($this->signalHandler->shouldStop()) {
                    break;
                }

                $cycleNumber = $this->aggregator->getCycleCount() + 1;
                $this->console->line(sprintf('🔄 Cycle #%d', $cycleNumber));

                $cycleResults = $this->executeCycle();

                if (! empty($cycleResults)) {
                    $this->aggregator->addResults($cycleResults);
                }

                $this->displayCycleSummary($cycleResults);

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
        $app = $this->getContainer();

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
        $interval = (float) ($this->argument('interval') ?? 60);

        return new DurationVO(max($interval, self::MIN_INTERVAL_SECONDS));
    }

    private function getDuration(): ?DurationVO
    {
        $duration = $this->argument('duration');

        return $duration !== null ? new DurationVO((float) $duration) : null;
    }

    private function getLimit(): ?LimitVO
    {
        $limit = $this->argument('limit');

        return $limit !== null ? new LimitVO((int) $limit) : null;
    }

    private function getParallelWorkers(): int
    {
        $parallel = $this->argument('parallel');

        return $parallel !== null ? max(1, (int) $parallel) : 1;
    }

    private function waitWithSignals(DurationVO $waitTime): void
    {
        $seconds = (int) $waitTime->getValue();

        for ($i = 0; $i < $seconds; $i++) {
            if ($this->signalHandler->shouldStop()) {
                break;
            }

            $this->signalHandler->dispatch();

            if ($i % 10 === 0 && $i > 0) {
                $remaining = $seconds - $i;
                $this->console->logDebug("⏳ Waiting... {$remaining}s remaining");
            }

            sleep(1);
        }
    }

    private function displayStartMessage(): void
    {
        $this->console->info(sprintf('  Interval: %ds', (int) $this->getInterval()->getValue()));

        $duration = $this->getDuration();
        if ($duration !== null) {
            $this->console->info(sprintf('  Duration: %ds', (int) $duration->getValue()));
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

    private function displayCycleSummary(array $results): void
    {
        $successCount = 0;
        $failedCount = 0;

        foreach ($results as $result) {
            if ($result instanceof TaskExecutionResultRecord) {
                $successCount += $result->success->getValue();
                $failedCount += $result->failed->getValue();
            }
        }

        $this->console->info(sprintf('  ✅ Success: %d, ❌ Failed: %d', $successCount, $failedCount));
        $this->console->line();
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
        $this->console->info(sprintf('  ⏱️  Duration: %.2fs', $elapsed->seconds));

        if ($this->signalHandler->shouldStop()) {
            $this->console->alert('  🛑 Stopped by signal');
        }

        $this->console->line();
    }
}
