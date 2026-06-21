<?php

// tests/IntegrationTestCase.php

declare(strict_types=1);

namespace AndyDefer\Task\Tests;

use AndyDefer\Directive\DirectiveServiceProvider;
use AndyDefer\LaravelJsonl\LaravelJsonlServiceProvider;
use AndyDefer\Logger\LoggerServiceProvider;
use AndyDefer\Task\TaskServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Base test case for integration tests that need Laravel.
 * Full Laravel bootstrap, database support, HTTP client.
 *
 * ⚠️ RULE: Tests extending this class:
 * - CAN use the database (SQLite memory)
 * - CAN use Laravel facades
 * - CAN make HTTP requests
 */
abstract class IntegrationTestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Database configuration for tests
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        // Cache disabled for tests
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('queue.default', 'sync');

        // Task storage for tests (temporary directory)
        $app['config']->set('task.storage_path', sys_get_temp_dir().'/task_tests_'.uniqid());
    }

    protected function getPackageProviders($app): array
    {
        return [
            LaravelJsonlServiceProvider::class,
            DirectiveServiceProvider::class,
            LoggerServiceProvider::class,
            TaskServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('view.paths', [__DIR__.'/Fixtures/views']);
    }

    protected function runDatabaseMigrations(): void
    {
        $migrationPath = __DIR__.'/database/migrations';

        if (is_dir($migrationPath)) {
            $this->loadMigrationsFrom($migrationPath);
        }

        $this->artisan('migrate', [
            '--database' => 'testbench',
            '--force' => true,
        ])->run();
    }
}
