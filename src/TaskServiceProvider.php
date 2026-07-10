<?php

declare(strict_types=1);

namespace AndyDefer\Task;

use AndyDefer\ConsoleWriter\Console\Console;
use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\LaravelJsonl\Contexts\JsonlContext;
use AndyDefer\LaravelJsonl\JsonlService;
use AndyDefer\LaravelJsonl\Strategies\TemporalPathStrategy;
use AndyDefer\Logger\Configs\LoggerConfig;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Logger\LoggerService;
use AndyDefer\PhpServices\Services\FileSystemService;
use AndyDefer\Task\Contracts\Loggers\RecurringTaskLoggerInterface;
use AndyDefer\Task\Contracts\Loggers\UniqueTaskLoggerInterface;
use AndyDefer\Task\Contracts\Processors\RecurringTaskProcessorInterface;
use AndyDefer\Task\Contracts\Processors\UniqueTaskProcessorInterface;
use AndyDefer\Task\Contracts\Repositories\RecurringTaskRepositoryInterface;
use AndyDefer\Task\Contracts\Repositories\TaskExecutionDebugRepositoryInterface;
use AndyDefer\Task\Contracts\Repositories\UniqueTaskRepositoryInterface;
use AndyDefer\Task\Contracts\Runners\RecurringTaskRunnerInterface;
use AndyDefer\Task\Contracts\Runners\UniqueTaskRunnerInterface;
use AndyDefer\Task\Contracts\Services\RecurringTaskServiceInterface;
use AndyDefer\Task\Contracts\Services\TaskExecutionDebugServiceInterface;
use AndyDefer\Task\Contracts\Services\UniqueTaskServiceInterface;
use AndyDefer\Task\Contracts\Services\WatchInterface;
use AndyDefer\Task\Contracts\Services\WatchRendererInterface;
use AndyDefer\Task\Contracts\Validators\RecurringTaskValidatorInterface;
use AndyDefer\Task\Contracts\Validators\UniqueTaskValidatorInterface;
use AndyDefer\Task\Loggers\RecurringTaskLogger;
use AndyDefer\Task\Loggers\UniqueTaskLogger;
use AndyDefer\Task\Processors\RecurringTaskProcessor;
use AndyDefer\Task\Processors\UniqueTaskProcessor;
use AndyDefer\Task\Repositories\RecurringTaskRepository;
use AndyDefer\Task\Repositories\TaskExecutionDebugRepository;
use AndyDefer\Task\Repositories\UniqueTaskRepository;
use AndyDefer\Task\Runners\RecurringTaskRunner;
use AndyDefer\Task\Runners\UniqueTaskRunner;
use AndyDefer\Task\Services\RecurringTaskService;
use AndyDefer\Task\Services\TaskExecutionDebugService;
use AndyDefer\Task\Services\UniqueTaskService;
use AndyDefer\Task\Services\WatchRendererService;
use AndyDefer\Task\Services\WatchService;
use AndyDefer\Task\Validators\RecurringTaskValidator;
use AndyDefer\Task\Validators\UniqueTaskValidator;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Ramsey\Uuid\UuidFactory;
use Ramsey\Uuid\UuidFactoryInterface;

final class TaskServiceProvider extends ServiceProvider
{
    private LoggerService $logger;

    private HydrationService $hydration;

    public function register(): void
    {
        $this->registerLogger();

        // ✅ SERVICES DE BASE
        $this->app->singleton(
            abstract: HydrationService::class,
            concrete: function () {
                return $this->hydration;
            }
        );

        $this->app->singleton(
            abstract: LoggerInterface::class,
            concrete: function () {
                return $this->logger;
            }
        );

        $this->app->singleton(
            abstract: UuidFactoryInterface::class,
            concrete: function () {
                return new UuidFactory;
            }
        );

        // ✅ CONSOLE WRITER
        $this->app->singleton(
            abstract: Console::class,
            concrete: function () {
                return new Console;
            }
        );
        $this->app->alias(Console::class, 'console.writer');

        // ✅ REPOSITORIES
        $this->app->singleton(
            abstract: TaskExecutionDebugRepositoryInterface::class,
            concrete: function () {
                return new TaskExecutionDebugRepository;
            }
        );
        $this->app->alias(TaskExecutionDebugRepositoryInterface::class, TaskExecutionDebugRepository::class);

        $this->app->singleton(
            abstract: RecurringTaskRepositoryInterface::class,
            concrete: function (Application $app) {
                return new RecurringTaskRepository(
                    debugRepository: $app->make(TaskExecutionDebugRepositoryInterface::class),
                    logger: $app->make(LoggerInterface::class)
                );
            }
        );
        $this->app->alias(RecurringTaskRepositoryInterface::class, RecurringTaskRepository::class);

        $this->app->singleton(
            abstract: UniqueTaskRepositoryInterface::class,
            concrete: function (Application $app) {
                return new UniqueTaskRepository(
                    debugRepository: $app->make(TaskExecutionDebugRepositoryInterface::class),
                    logger: $app->make(LoggerInterface::class)
                );
            }
        );
        $this->app->alias(UniqueTaskRepositoryInterface::class, UniqueTaskRepository::class);

        // ✅ VALIDATORS
        $this->app->singleton(
            abstract: UniqueTaskValidatorInterface::class,
            concrete: function () {
                return new UniqueTaskValidator;
            }
        );
        $this->app->alias(UniqueTaskValidatorInterface::class, UniqueTaskValidator::class);

        $this->app->singleton(
            abstract: RecurringTaskValidatorInterface::class,
            concrete: function () {
                return new RecurringTaskValidator;
            }
        );
        $this->app->alias(RecurringTaskValidatorInterface::class, RecurringTaskValidator::class);

        // ✅ LOGGERS (Task Loggers)
        $this->app->singleton(
            abstract: UniqueTaskLoggerInterface::class,
            concrete: function (Application $app) {
                return new UniqueTaskLogger(
                    logger: $app->make(LoggerInterface::class),
                    hydration: $app->make(HydrationService::class)
                );
            }
        );
        $this->app->alias(UniqueTaskLoggerInterface::class, UniqueTaskLogger::class);

        $this->app->singleton(
            abstract: RecurringTaskLoggerInterface::class,
            concrete: function (Application $app) {
                return new RecurringTaskLogger(
                    logger: $app->make(LoggerInterface::class),
                    hydration: $app->make(HydrationService::class)
                );
            }
        );
        $this->app->alias(RecurringTaskLoggerInterface::class, RecurringTaskLogger::class);

        // ✅ RUNNERS
        $this->app->singleton(
            abstract: UniqueTaskRunnerInterface::class,
            concrete: function (Application $app) {
                return new UniqueTaskRunner(
                    validator: $app->make(UniqueTaskValidatorInterface::class),
                    logger: $app->make(UniqueTaskLoggerInterface::class),
                    hydration: $app->make(HydrationService::class),
                    app: $app,
                    repository: $app->make(UniqueTaskRepositoryInterface::class)
                );
            }
        );
        $this->app->alias(UniqueTaskRunnerInterface::class, UniqueTaskRunner::class);

        $this->app->singleton(
            abstract: RecurringTaskRunnerInterface::class,
            concrete: function (Application $app) {
                return new RecurringTaskRunner(
                    validator: $app->make(RecurringTaskValidatorInterface::class),
                    logger: $app->make(RecurringTaskLoggerInterface::class),
                    hydration: $app->make(HydrationService::class),
                    app: $app,
                    repository: $app->make(RecurringTaskRepositoryInterface::class)
                );
            }
        );
        $this->app->alias(RecurringTaskRunnerInterface::class, RecurringTaskRunner::class);

        // ✅ PROCESSORS
        $this->app->singleton(
            abstract: UniqueTaskProcessor::class,
            concrete: function (Application $app) {
                return new UniqueTaskProcessor(
                    repository: $app->make(UniqueTaskRepositoryInterface::class),
                    runner: $app->make(UniqueTaskRunnerInterface::class),
                    validator: $app->make(UniqueTaskValidatorInterface::class)
                );
            }
        );

        $this->app->singleton(
            abstract: RecurringTaskProcessor::class,
            concrete: function (Application $app) {
                return new RecurringTaskProcessor(
                    repository: $app->make(RecurringTaskRepositoryInterface::class),
                    runner: $app->make(RecurringTaskRunnerInterface::class),
                    validator: $app->make(RecurringTaskValidatorInterface::class)
                );
            }
        );

        // ✅ ALIASES
        $this->app->alias(UniqueTaskProcessor::class, UniqueTaskProcessorInterface::class);
        $this->app->alias(RecurringTaskProcessor::class, RecurringTaskProcessorInterface::class);

        // ✅ SERVICES

        // TaskExecutionDebugService
        $this->app->singleton(
            abstract: TaskExecutionDebugServiceInterface::class,
            concrete: function (Application $app) {
                return new TaskExecutionDebugService(
                    repository: $app->make(TaskExecutionDebugRepositoryInterface::class),
                    logger: $app->make(LoggerInterface::class)
                );
            }
        );
        $this->app->alias(TaskExecutionDebugServiceInterface::class, TaskExecutionDebugService::class);

        // UniqueTaskService
        $this->app->singleton(
            abstract: UniqueTaskServiceInterface::class,
            concrete: function (Application $app) {
                return new UniqueTaskService(
                    repository: $app->make(UniqueTaskRepositoryInterface::class),
                    logger: $app->make(LoggerInterface::class),
                    hydration: $app->make(HydrationService::class),
                    app: $app
                );
            }
        );
        $this->app->alias(UniqueTaskServiceInterface::class, UniqueTaskService::class);

        // RecurringTaskService
        $this->app->singleton(
            abstract: RecurringTaskServiceInterface::class,
            concrete: function (Application $app) {
                return new RecurringTaskService(
                    repository: $app->make(RecurringTaskRepositoryInterface::class),
                    logger: $app->make(LoggerInterface::class),
                    hydration: $app->make(HydrationService::class),
                    app: $app
                );
            }
        );
        $this->app->alias(RecurringTaskServiceInterface::class, RecurringTaskService::class);

        // ✅ WATCH SERVICES
        $this->registerWatchServices();
    }

    /**
     * Enregistre les services de watch.
     */
    private function registerWatchServices(): void
    {
        // ✅ WatchRendererService
        $this->app->singleton(
            abstract: WatchRendererInterface::class,
            concrete: function (Application $app) {
                return new WatchRendererService(
                    console: $app->make(Console::class)
                );
            }
        );
        $this->app->alias(WatchRendererInterface::class, WatchRendererService::class);

        // ✅ WatchService avec Console
        $this->app->singleton(
            abstract: WatchInterface::class,
            concrete: function (Application $app) {
                return new WatchService(
                    console: $app->make(Console::class)
                );
            }
        );
        $this->app->alias(WatchInterface::class, WatchService::class);
    }

    private function registerLogger(): void
    {
        $config = $this->app->make(ConfigRepository::class);
        $loggerConfig = new LoggerConfig($config);

        $logPath = $loggerConfig->basePath();
        $fs = new FileSystemService;

        $pathStrategy = new TemporalPathStrategy($logPath);
        $jsonlContext = new JsonlContext;

        $jsonlService = new JsonlService(
            pathStrategy: $pathStrategy,
            fileSystem: $fs,
            context: $jsonlContext,
            defaultBufferSize: $loggerConfig->bufferSize()
        );

        $this->hydration = new HydrationService;
        $this->logger = new LoggerService(
            jsonlService: $jsonlService,
        );
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'task-migrations');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
