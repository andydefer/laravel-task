<?php

// tests/IntegrationTestCase.php

declare(strict_types=1);

namespace AndyDefer\Task\Tests;

use AndyDefer\ConsoleWriter\Console\Contracts\ConsoleInterface;
use AndyDefer\Directive\DirectiveServiceProvider;
use AndyDefer\LaravelJsonl\LaravelJsonlServiceProvider;
use AndyDefer\Logger\LoggerServiceProvider;
use AndyDefer\Task\TaskServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use PHPUnit\Framework\MockObject\Stub;

/**
 * Base test case for integration tests that need Laravel.
 *
 * Full Laravel bootstrap, database support, HTTP client.
 */
abstract class IntegrationTestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
        ob_start();
    }

    protected function tearDown(): void
    {
        ob_get_clean();
        parent::tearDown();
    }

    /**
     * Creates a mocked ConsoleInterface with all output methods stubbed.
     *
     * @return ConsoleInterface&Stub
     */
    protected function createMockConsole(): ConsoleInterface
    {
        /** @var ConsoleInterface&Stub $console */
        $console = $this->createStub(ConsoleInterface::class);
        $this->mockConsoleMethods($console);

        return $console;
    }

    /**
     * Mocks all output methods of the ConsoleInterface to return self.
     */
    protected function mockConsoleMethods(ConsoleInterface&Stub $console): void
    {
        $console->method('info')->willReturnSelf();
        $console->method('success')->willReturnSelf();
        $console->method('error')->willReturnSelf();
        $console->method('title')->willReturnSelf();
        $console->method('alert')->willReturnSelf();
        $console->method('alertWarning')->willReturnSelf();
        $console->method('alertError')->willReturnSelf();
        $console->method('alertSuccess')->willReturnSelf();
        $console->method('alertInfo')->willReturnSelf();
        $console->method('logDebug')->willReturnSelf();
        $console->method('logInfo')->willReturnSelf();
        $console->method('logSuccess')->willReturnSelf();
        $console->method('logError')->willReturnSelf();
        $console->method('logWarning')->willReturnSelf();
        $console->method('line')->willReturnSelf();
        $console->method('newLine')->willReturnSelf();
        $console->method('raw')->willReturnSelf();
        $console->method('link')->willReturnSelf();
        $console->method('list')->willReturnSelf();
        $console->method('listColored')->willReturnSelf();
        $console->method('keyValue')->willReturnSelf();
        $console->method('keyValueWithColor')->willReturnSelf();
        $console->method('keyValueWithValueColor')->willReturnSelf();
        $console->method('keyValueWithSeparator')->willReturnSelf();
        $console->method('table')->willReturnSelf();
        $console->method('adaptiveTable')->willReturnSelf();
        $console->method('tree')->willReturnSelf();
        $console->method('treeWithColors')->willReturnSelf();
        $console->method('treeFromPaths')->willReturnSelf();
        $console->method('treeWithIcons')->willReturnSelf();
        $console->method('badge')->willReturnSelf();
        $console->method('badgeWithIcon')->willReturnSelf();
        $console->method('badgeSuccess')->willReturnSelf();
        $console->method('badgeDanger')->willReturnSelf();
        $console->method('badgeWarning')->willReturnSelf();
        $console->method('badgeInfo')->willReturnSelf();
        $console->method('badgePrimary')->willReturnSelf();
        $console->method('badgeDark')->willReturnSelf();
        $console->method('badgeLight')->willReturnSelf();
        $console->method('metric')->willReturnSelf();
        $console->method('metricWithIcon')->willReturnSelf();
        $console->method('metricWithTrend')->willReturnSelf();
        $console->method('metricInline')->willReturnSelf();
        $console->method('columns')->willReturnSelf();
        $console->method('columnsWithIcons')->willReturnSelf();
        $console->method('columnsWithColors')->willReturnSelf();
        $console->method('columnsWithHeaders')->willReturnSelf();
        $console->method('columnsCompact')->willReturnSelf();
        $console->method('separator')->willReturnSelf();
        $console->method('separatorDouble')->willReturnSelf();
        $console->method('separatorWithTitle')->willReturnSelf();
        $console->method('timeline')->willReturnSelf();
        $console->method('timelineWithColors')->willReturnSelf();
        $console->method('timelineWithIcons')->willReturnSelf();
        $console->method('timelineWithStatus')->willReturnSelf();
        $console->method('json')->willReturnSelf();
        $console->method('jsonRaw')->willReturnSelf();
        $console->method('jsonCompact')->willReturnSelf();
        $console->method('jsonWithDepth')->willReturnSelf();
        $console->method('space')->willReturnSelf();
        $console->method('ansi')->willReturnSelf();
        $console->method('notify')->willReturnSelf();
        $console->method('notifySuccess')->willReturnSelf();
        $console->method('notifyError')->willReturnSelf();
        $console->method('notifyWarning')->willReturnSelf();
        $console->method('notifyInfo')->willReturnSelf();
        $console->method('soundSuccess')->willReturnSelf();
        $console->method('soundError')->willReturnSelf();
        $console->method('soundInfo')->willReturnSelf();
        $console->method('sound')->willReturnSelf();
        $console->method('soundAsync')->willReturnSelf();
        $console->method('progressBar')->willReturnSelf();
        $console->method('progressBarStyled')->willReturnSelf();
        $console->method('advance')->willReturnSelf();
        $console->method('setProgress')->willReturnSelf();
        $console->method('setPrefix')->willReturnSelf();
        $console->method('setSuffix')->willReturnSelf();
        $console->method('finish')->willReturnSelf();
        $console->method('spinner')->willReturnSelf();
        $console->method('spinnerWait')->willReturnSelf();
        $console->method('startBuffer')->willReturnSelf();
        $console->method('render')->willReturnSelf();
        $console->method('clear')->willReturnSelf();
        $console->method('getAnsiConverter')->willReturnSelf();
        $console->method('getLines')->willReturn([]);
        $console->method('isBuffered')->willReturn(false);
        $console->method('hasProgressBar')->willReturn(false);
        $console->method('getProgressBar')->willReturn(null);
        $console->method('ask')->willReturn('');
        $console->method('secret')->willReturn('');
        $console->method('confirm')->willReturn(true);
        $console->method('choice')->willReturn('');
        $console->method('suggest')->willReturn('');
        $console->method('number')->willReturn(0);
        $console->method('confirmWithTimeout')->willReturn(true);
        $console->method('multiChoice')->willReturn([]);
        $console->method('form')->willReturnSelf();
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('queue.default', 'sync');

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

    public function runDatabaseMigrations()
    {
        $migrationPath = __DIR__.'/../database/migrations';

        if (is_dir($migrationPath)) {
            $this->loadMigrationsFrom($migrationPath);
        }

        $this->artisan('migrate', [
            '--database' => 'testbench',
            '--force' => true,
        ])->run();
    }

    protected function stripAnsi(string $text): string
    {
        return preg_replace('/\033\[[0-9;]+m/', '', $text);
    }
}
