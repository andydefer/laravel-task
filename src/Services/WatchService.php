<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services;

use AndyDefer\ConsoleWriter\Console\Console;
use AndyDefer\Directive\Services\DirectiveTestingService;
use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\Task\Contracts\Services\WatchInterface;
use AndyDefer\Task\Directives\ProcessTasksDirective;
use AndyDefer\Task\Records\CycleResultRecord;
use AndyDefer\Task\Records\FullBatchJsonResultRecord;
use AndyDefer\Task\Records\TaskExecutionJsonResultRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Service for watching and executing tasks in continuous loops.
 *
 * Handles the execution of task processing cycles with support for
 * testing mode, signal handling, and configurable intervals.
 */
final class WatchService implements WatchInterface
{
    private ?DirectiveTestingService $testingService = null;

    private bool $testingMode = false;

    /**
     * Constructor for the watch service.
     *
     * @param  Console  $console  The console instance for output
     */
    public function __construct(
        private readonly Console $console,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function enableTestingMode(DirectiveTestingService $testingService): void
    {
        $this->testingMode = true;
        $this->testingService = $testingService;
        $this->console->info('🧪 Testing mode enabled');
    }

    /**
     * {@inheritDoc}
     */
    public function disableTestingMode(): void
    {
        $this->testingMode = false;
        $this->testingService = null;
        $this->console->info('🔬 Testing mode disabled');
    }

    /**
     * {@inheritDoc}
     */
    public function isTestingMode(): bool
    {
        return $this->testingMode;
    }

    /**
     * {@inheritDoc}
     */
    public function executeCycle(
        CounterVO $cycleNumber,
        bool $uniqueOnly,
        bool $recurringOnly,
        ?LimitVO $limit,
        bool $verbose,
        Iso8601DateTimeVO $cycleStartedAt
    ): CycleResultRecord {
        try {
            $this->console->line(sprintf(
                '🔄 Cycle #%d started at %s',
                $cycleNumber->getValue(),
                $cycleStartedAt->format('H:i:s')
            ));

            $arguments = $this->buildArguments($uniqueOnly, $recurringOnly, $limit, $verbose);
            $jsonArguments = $arguments->merge(StringTypedCollection::from(['--format=json']));
            $output = $this->callProcessTasks($jsonArguments);

            $cleanOutput = $this->stripAnsi($output);
            $data = json_decode($cleanOutput, true);

            if ($this->isFullBatchResponse($data)) {
                $result = FullBatchJsonResultRecord::from($cleanOutput);
                $success = $result->total_success;
                $failed = $result->total_failed;
                $errors = $result->errors->count();
                $hasErrors = $result->has_failures;
            } else {
                $result = TaskExecutionJsonResultRecord::from($cleanOutput);
                $success = $result->success;
                $failed = $result->failed;
                $errors = $result->errors->count();
                $hasErrors = $result->has_failures;
            }

            $elapsed = $cycleStartedAt->elapsed();

            $this->console->info(sprintf(
                '✅ %d tasks succeeded, ❌ %d tasks failed (%.2f s)',
                $success->getValue(),
                $failed->getValue(),
                $elapsed->seconds
            ));

            return CycleResultRecord::from([
                'success' => $success,
                'failed' => $failed,
                'errors' => new CounterVO($errors),
                'hasErrors' => $hasErrors,
            ]);
        } catch (Throwable $e) {
            $this->console->error('❌ Cycle failed: '.$e->getMessage());

            return CycleResultRecord::from([
                'success' => new CounterVO(0),
                'failed' => new CounterVO(0),
                'errors' => new CounterVO(1),
                'hasErrors' => true,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Builds the CLI arguments for the process-tasks directive.
     *
     * @param  bool  $uniqueOnly  Whether to process only unique tasks
     * @param  bool  $recurringOnly  Whether to process only recurring tasks
     * @param  LimitVO|null  $limit  Maximum number of tasks to process
     * @param  bool  $verbose  Whether to enable verbose output
     * @return StringTypedCollection Collection of CLI arguments
     */
    private function buildArguments(
        bool $uniqueOnly,
        bool $recurringOnly,
        ?LimitVO $limit,
        bool $verbose
    ): StringTypedCollection {
        $arguments = new StringTypedCollection;

        if ($uniqueOnly) {
            $arguments->add('--unique-only');
        }

        if ($recurringOnly) {
            $arguments->add('--recurring-only');
        }

        if ($limit !== null) {
            $arguments->add("--limit={$limit->getValue()}");
        }

        if ($verbose) {
            $arguments->add('--verbose');
        }

        return $arguments;
    }

    /**
     * Calls the process-tasks directive.
     *
     * @param  StringTypedCollection  $arguments  The CLI arguments
     * @return string The command output
     *
     * @throws RuntimeException When the command fails
     */
    private function callProcessTasks(StringTypedCollection $arguments): string
    {
        if ($this->testingMode && $this->testingService !== null) {
            $args = $arguments->toArray();
            $this->console->logDebug('🔬 Running in testing mode...');
            $response = $this->testingService->run(ProcessTasksDirective::class, $args);

            return $this->stripAnsi($response->output);
        }

        $directivePath = $this->getDirectivePath();

        $command = new StringTypedCollection;
        $command->add(PHP_BINARY);
        $command->add($directivePath);
        $command->add('process-tasks');
        $command = $command->merge($arguments);

        $this->console->logDebug('🔧 Executing: '.$command->join(' '));

        $process = new Process($command->toArray());
        $process->setTimeout(null);
        $process->run();

        if (! $process->isSuccessful()) {
            $errorOutput = $process->getErrorOutput() ?: $process->getOutput();
            throw new RuntimeException('process-tasks failed: '.$errorOutput);
        }

        return $this->stripAnsi($process->getOutput());
    }

    /**
     * Gets the absolute path to the directive executable.
     *
     * @return string The absolute path
     *
     * @throws RuntimeException When the directive executable is not found
     */
    private function getDirectivePath(): string
    {
        $path = getcwd().'/vendor/bin/directive';

        if (file_exists($path)) {
            return $path;
        }

        throw new RuntimeException('Could not find directive executable at: '.$path);
    }

    /**
     * Strips ANSI color codes from text.
     *
     * @param  string  $text  The text to clean
     * @return string The cleaned text
     */
    private function stripAnsi(string $text): string
    {
        return preg_replace('/\033\[[0-9;]*m/', '', $text) ?? $text;
    }

    /**
     * Determines if the response is a full batch response.
     *
     * @param  array<string, mixed>  $data  The decoded JSON data
     * @return bool True if it's a full batch response
     */
    private function isFullBatchResponse(array $data): bool
    {
        return isset($data['unique']) && isset($data['recurring']);
    }

    /**
     * {@inheritDoc}
     */
    public function shouldContinue(
        bool $shouldStop,
        ?DurationVO $duration,
        ?Iso8601DateTimeVO $startedAt
    ): bool {
        if ($shouldStop) {
            return false;
        }

        if ($duration === null) {
            return true;
        }

        $elapsed = $startedAt !== null ? $startedAt->elapsed()->seconds : 0;

        return $elapsed < $duration->seconds;
    }

    /**
     * {@inheritDoc}
     */
    public function waitForInterval(DurationVO $interval, callable $shouldContinueCallback): void
    {
        $intervalSeconds = (int) $interval->seconds;

        for ($i = 0; $i < $intervalSeconds; $i++) {
            if (! $shouldContinueCallback()) {
                break;
            }

            if ($i % 10 === 0 && $i > 0) {
                $this->console->logDebug(sprintf('⏳ Waiting... %d/%d seconds', $i, $intervalSeconds));
            }

            sleep(1);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function calculateElapsedSeconds(?Iso8601DateTimeVO $start): float
    {
        if ($start === null) {
            return 0.0;
        }

        return $start->elapsed()->seconds;
    }

    /**
     * {@inheritDoc}
     */
    public function formatDuration(DurationVO $duration): string
    {
        return $duration->format();
    }
}
