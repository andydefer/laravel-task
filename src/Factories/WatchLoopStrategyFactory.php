<?php

declare(strict_types=1);

namespace AndyDefer\Task\Factories;

use AndyDefer\Directive\AbstractDirective;
use AndyDefer\Directive\Services\DirectiveTestingService;
use AndyDefer\Task\Contracts\Directives\WatchLoopStrategyInterface;
use AndyDefer\Task\Contracts\Services\WatchInterface;
use AndyDefer\Task\Strategies\ProductionWatchStrategy;
use AndyDefer\Task\Strategies\TestingWatchStrategy;
use Illuminate\Contracts\Foundation\Application;

/**
 * Factory for creating the appropriate watch loop strategy.
 *
 * Determines whether to use production or testing strategy based on
 * the presence of the --testing option and enables testing mode on
 * the watch service when appropriate.
 */
final class WatchLoopStrategyFactory
{
    /**
     * Creates the appropriate watch loop strategy.
     *
     * @param  AbstractDirective  $directive  The directive instance containing options
     * @param  Application  $app  The Laravel application container
     * @param  WatchInterface  $service  The watch service to configure
     * @return WatchLoopStrategyInterface The created strategy instance
     */
    public static function create(
        AbstractDirective $directive,
        Application $app,
        WatchInterface $service
    ): WatchLoopStrategyInterface {
        if ($directive->hasOption('testing')) {
            $testingService = $app->make(DirectiveTestingService::class);
            $service->enableTestingMode($testingService);

            return $app->make(TestingWatchStrategy::class);
        }

        return $app->make(ProductionWatchStrategy::class);
    }
}
