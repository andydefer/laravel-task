<?php

namespace AndyDefer\Task\Bootstrap;

use AndyDefer\Directive\Bootstrap\Paths;
use AndyDefer\Directive\DirectiveServiceProvider;
use AndyDefer\Repository\RepositoryServiceProvider;
use AndyDefer\Task\Enums\ConnectionType;
use AndyDefer\Task\TaskServiceProvider;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Events\EventServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Providers\ArtisanServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Facade;

class InternalApplicationFactory
{
    public static function create(ConnectionType $type = ConnectionType::MEMORY): Application
    {
        // ✅ Créer le dossier cache
        $cachePath = Paths::projectRoot().'/bootstrap/cache';
        if (! is_dir($cachePath)) {
            mkdir($cachePath, 0755, true);
        }

        // ✅ Créer l'application
        $app = Application::configure(basePath: Paths::projectRoot())
            ->withExceptions(function (Exceptions $exceptions): void {
                //
            })
            ->withProviders([
                ArtisanServiceProvider::class,
                DatabaseServiceProvider::class,
                EventServiceProvider::class,
            ])
            ->create();

        // ✅ DÉFINIR LE FACADE ROOT
        Facade::setFacadeApplication($app);

        // ✅ Déterminer le chemin de la base de données
        $databasePath = match ($type) {
            ConnectionType::MEMORY => ':memory:',
            ConnectionType::PERSISTENT => Paths::projectRoot().'/database/task.sqlite',
        };

        // ✅ Créer le dossier database si nécessaire (pour persistent)
        if ($type === ConnectionType::PERSISTENT) {
            $databaseDir = Paths::projectRoot().'/database';
            if (! is_dir($databaseDir)) {
                mkdir($databaseDir, 0755, true);
            }
        }

        // ✅ CONFIGURATION DE LA BASE DE DONNÉES
        $app['config'] = new ConfigRepository([
            'database' => [
                'default' => 'sqlite',
                'connections' => [
                    'sqlite' => [
                        'driver' => 'sqlite',
                        'database' => $databasePath,
                        'prefix' => '',
                    ],
                ],
                'migrations' => 'migrations',
            ],
        ]);

        // ✅ Enregistrer les providers
        $app->register(RepositoryServiceProvider::class);
        $app->register(DirectiveServiceProvider::class);
        $app->register(TaskServiceProvider::class);

        // ✅ Exécuter les migrations
        Artisan::setFacadeApplication($app);
        Artisan::call('migrate', ['--force' => true]);

        return $app;
    }
}
