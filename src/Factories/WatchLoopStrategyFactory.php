<?php

declare(strict_types=1);

namespace AndyDefer\Task\Factories;

use AndyDefer\Directive\AbstractDirective;
use AndyDefer\Directive\Services\DirectiveTestingService;
use AndyDefer\Task\Contracts\Directives\WatchLoopStrategyInterface;
use AndyDefer\Task\Services\WatchService;
use AndyDefer\Task\Strategies\ProductionWatchStrategy;
use AndyDefer\Task\Strategies\TestingWatchStrategy;
use Illuminate\Contracts\Foundation\Application;

final class WatchLoopStrategyFactory
{
    public static function create(
        AbstractDirective $directive,
        Application $app,
        WatchService $service
    ): WatchLoopStrategyInterface {
        if ($directive->hasOption('testing') && $service instanceof WatchService) {
            $testingService = $app->make(DirectiveTestingService::class);
            $service->enableTestingMode($testingService);

            return new TestingWatchStrategy;
        }

        return new ProductionWatchStrategy;
    }
}
