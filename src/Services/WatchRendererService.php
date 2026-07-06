<?php

declare(strict_types=1);

namespace AndyDefer\Task\Services;

use AndyDefer\ConsoleWriter\Console\Components\KeyValue;
use AndyDefer\ConsoleWriter\Console\Console;
use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\DomainStructures\Utils\MapCollection;
use AndyDefer\Task\Contracts\Services\WatchRendererInterface;
use AndyDefer\Task\Enums\SignalName;
use AndyDefer\Task\Records\CycleResultRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;

final class WatchRendererService implements WatchRendererInterface
{
    public function __construct(
        private readonly Console $console,
    ) {}

    public function renderStartMessage(
        ?DurationVO $duration,
        DurationVO $intervalSeconds,
        StringTypedCollection $options,
        bool $testingMode
    ): void {
        $this->console->title('🚀 Starting tasks watch loop...');

        if ($testingMode) {
            $this->console->info('🔬 Mode: TESTING (in-process execution)');
        }

        if ($duration !== null) {
            $this->console->info(sprintf(
                'Duration: %s (%s)',
                (string) $duration->seconds,
                $duration->format()
            ));
        } else {
            $this->console->info('Duration: unlimited (Ctrl+C to stop)');
        }

        $this->console->info(sprintf(
            'Interval: %s (%s)',
            (string) $intervalSeconds->seconds,
            $intervalSeconds->format()
        ));

        if ($options->isNotEmpty()) {
            $this->console->info('Options: '.$options->join(' '));
        }

        $this->console->line();
        $this->console->line(str_repeat('=', 80));
        $this->console->newLine(2);
    }

    public function renderCycleStart(CounterVO $cycleNumber, Iso8601DateTimeVO $startedAt): void
    {
        $time = $startedAt->format('H:i:s');

        $this->console->info(sprintf(
            '🔄 Cycle #%d (started at %s):',
            $cycleNumber->getValue(),
            $time
        ));
    }

    public function renderCycleEnd(
        CycleResultRecord $result,
        Iso8601DateTimeVO $startedAt,
        DurationVO $intervalSeconds
    ): void {
        $totalProcessed = $result->success->getValue() + $result->failed->getValue();

        if ($totalProcessed === 0) {
            $this->console->info('⏳ No tasks to process');
        } else {
            $this->console->info(sprintf(
                '✅ %d tasks succeeded, ❌ %d tasks failed',
                $result->success->getValue(),
                $result->failed->getValue()
            ));
        }

        $elapsed = $startedAt->elapsed();
        $this->console->info(sprintf(
            '⏱️  Cycle duration: %.2f seconds',
            $elapsed->seconds
        ));

        $remaining = $intervalSeconds->seconds - $elapsed->seconds;
        if ($remaining > 0) {
            $this->console->info(sprintf(
                '⏳ Next cycle in %.0f seconds...',
                $remaining
            ));
        }

        $this->console->line();
    }

    public function renderSummary(
        CounterVO $cycleCount,
        CounterVO $totalSuccess,
        CounterVO $totalFailed,
        CounterVO $totalErrors,
        Iso8601DateTimeVO $startedAt,
        bool $testingMode,
        bool $stoppedBySignal,
        bool $durationReached,
        ?DescriptionVO $exception = null
    ): void {
        $this->console->line();
        $this->console->line(str_repeat('=', 80));
        $this->console->title('📊 Summary');

        if ($testingMode) {
            $this->console->info('🔬 Mode: TESTING');
        }

        $totalDuration = $startedAt->elapsed();

        // ✅ Utilisation de KeyValue pour un affichage plus propre
        $data = MapCollection::from([
            'Cycles executed' => $cycleCount->getValue(),
            'Total success' => $totalSuccess->getValue(),
            'Total failures' => $totalFailed->getValue(),
            'Total errors' => $totalErrors->getValue(),
            'Total duration' => $totalDuration->format(),
        ]);

        $this->console->raw(KeyValue::renderWithValueColor($data, 'cyan'));

        if ($stoppedBySignal) {
            $this->console->info('🛑 Stopped by user signal');
        } elseif ($durationReached) {
            $this->console->info('⏰ Duration reached. Stopping gracefully...');
        }

        $this->console->line(str_repeat('=', 80));
        $this->console->line();

        if ($exception !== null) {
            $this->console->error('Error: '.$exception->getValue());
        }
    }

    public function renderInterruptSignal(SignalName $signalName): void
    {
        $this->console->logWarning(sprintf(
            '⚠️  Received %s, stopping after current cycle...',
            $signalName->getLabel()
        ));
    }

    public function renderTestingModeEnabled(): void
    {
        $this->console->info('🧪 Testing mode enabled');
    }
}
