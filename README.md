# Laravel Task

**Un système de tâches léger pour Laravel avec exécution asynchrone, tâches récurrentes et stockage structuré.**

[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-blue)](https://php.net)
[![Laravel Version](https://img.shields.io/badge/Laravel-12.x%20%7C%2013.x%20%7C%2014.x%20%7C%2015.x-blue)](https://laravel.com)
[![License](https://img.shields.io/badge/License-MIT-green)](LICENSE)

---

## Table des matières

1. [Installation](#installation)
2. [Concepts fondamentaux](#concepts-fondamentaux)
3. [Créer une tâche unique](#créer-une-tâche-unique)
4. [Créer une tâche récurrente](#créer-une-tâche-récurrente)
5. [Exécuter les tâches](#exécuter-les-tâches)
6. [Surveillance continue avec TasksWatch](#surveillance-continue-avec-taskswatch)
7. [Gestion des tâches](#gestion-des-tâches)
8. [Mode test](#mode-test)
9. [Bonnes pratiques](#bonnes-pratiques)
10. [Référence des commandes](#référence-des-commandes)
11. [Licence](#licence)

---

## Installation

```bash
composer require andydefer/laravel-task
```

### Prérequis

- PHP 8.1 ou supérieur
- Laravel 12.x, 13.x, 14.x ou 15.x

### Publier les migrations

```bash
php artisan vendor:publish --tag=task-migrations
php artisan migrate
```

### Service Provider

Le package s'enregistre automatiquement via `TaskServiceProvider`. Aucune configuration supplémentaire n'est nécessaire.

---

## Concepts fondamentaux

### Types de tâches

| Type | Description | Cas d'usage |
|------|-------------|-------------|
| **Unique** | Tâche exécutée une seule fois à une date planifiée | Envoi d'email, génération de rapport, notification |
| **Récurrente** | Tâche exécutée en boucle selon un intervalle | Nettoyage de cache, synchronisation de données |

### Architecture

Le package suit une architecture en couches :

```
Directives (CLI) → Services (Métier) → Processors → Runners → Repositories → Base de données
```

**Principe clé :** Utilisez toujours les **services** (via `getLaravel()->make()`) plutôt que les facades pour une meilleure testabilité.

---

## Créer une tâche unique

### 1. Créer la classe de la tâche

```php
<?php

namespace App\Tasks;

use AndyDefer\Task\Abstract\AbstractUniqueTask;
use AndyDefer\Task\Configs\UniqueTaskConfig;
use AndyDefer\Task\Contracts\Configs\UniqueTaskConfigInterface;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

class SendWelcomeEmailTask extends AbstractUniqueTask
{
    public function getConfig(): UniqueTaskConfigInterface
    {
        return new UniqueTaskConfig(
            alias: new TaskSignatureVO('welcome-email'),
            description: 'Send welcome email to new user',
            scheduled_at: new Iso8601DateTimeVO(now()->addMinutes(5)->toIso8601String()),
            max_attempts: new CounterVO(3),
        );
    }

    protected function process(): void
    {
        $payload = $this->context->getPayload()->toArray();
        $userEmail = $payload['email'];
        $userName = $payload['name'];

        // Logique d'envoi d'email
        $this->info("Sending welcome email to {$userEmail}...");
        
        // ... envoyer l'email ...
        
        $this->info("Welcome email sent to {$userName}");
    }
}
```

### 2. Enregistrer et exécuter la tâche

```php
<?php

namespace App\Http\Controllers;

use AndyDefer\Task\Contracts\Services\UniqueTaskServiceInterface;
use AndyDefer\DomainStructures\Utils\StrictDataObject;

class UserController
{
    public function store(Request $request, UniqueTaskServiceInterface $taskService)
    {
        // Créer l'utilisateur...
        
        // Enregistrer la tâche
        $payload = StrictDataObject::from([
            'email' => $request->email,
            'name' => $request->name,
        ]);

        $taskId = $taskService->register(
            SendWelcomeEmailTask::class,
            $payload
        );

        return response()->json([
            'message' => 'User created. Welcome email will be sent.',
            'task_id' => $taskId->value,
        ]);
    }
}
```

### 3. Surcharger les méthodes de cycle de vie

```php
protected function before(): void
{
    // Exécuté avant le traitement
    $this->info("Preparing to send email...");
}

protected function after(bool $success, ?string $error = null): void
{
    // Exécuté après le traitement
    if ($success) {
        $this->info("Email sent successfully!");
    } else {
        $this->error("Email failed: {$error}");
    }
}
```

---

## Créer une tâche récurrente

### 1. Créer la classe de la tâche

```php
<?php

namespace App\Tasks;

use AndyDefer\Task\Abstract\AbstractRecurringTask;
use AndyDefer\Task\Configs\RecurringTaskConfig;
use AndyDefer\Task\Contracts\Configs\RecurringTaskConfigInterface;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

class CleanCacheTask extends AbstractRecurringTask
{
    public function getConfig(): RecurringTaskConfigInterface
    {
        return new RecurringTaskConfig(
            alias: new TaskSignatureVO('cache-cleaner'),
            description: 'Clean expired cache entries',
            interval_seconds: new CounterVO(3600), // Toutes les heures
            start_at: new Iso8601DateTimeVO(now()->toIso8601String()),
            max_attempts: new CounterVO(3),
        );
    }

    protected function process(): void
    {
        $this->info("Starting cache cleanup...");
        
        // Logique de nettoyage
        // cache()->cleanExpired();
        
        $this->info("Cache cleanup completed.");
    }
}
```

### 2. Enregistrer la tâche

```php
<?php

namespace App\Console\Commands;

use AndyDefer\Task\Contracts\Services\RecurringTaskServiceInterface;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Configs\RecurringTaskConfig;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

class SetupTasksCommand
{
    public function __construct(
        private RecurringTaskServiceInterface $taskService
    ) {}

    public function handle()
    {
        $config = new RecurringTaskConfig(
            alias: new TaskSignatureVO('cache-cleaner'),
            description: 'Clean expired cache entries',
            interval_seconds: new CounterVO(3600),
            start_at: new Iso8601DateTimeVO(now()->toIso8601String()),
        );

        $alias = $this->taskService->register(
            CleanCacheTask::class,
            StrictDataObject::from(['enabled' => true]),
            $config
        );

        $this->info("Task registered with alias: {$alias->value}");
    }
}
```

### 3. Gérer les états

```php
// Mettre en pause
$taskService->pause(new TaskSignatureVO('cache-cleaner'));

// Reprendre
$taskService->resume(new TaskSignatureVO('cache-cleaner'));

// Terminer (définitif)
$taskService->finish(new TaskSignatureVO('cache-cleaner'));

// Annuler
$taskService->cancel(new TaskSignatureVO('cache-cleaner'), 'Maintenance');
```

---

## Exécuter les tâches

### Via la directive `process-tasks`

```bash
# Traiter toutes les tâches
./vendor/bin/directive process-tasks

# Traiter uniquement les tâches uniques
./vendor/bin/directive process-tasks --unique-only

# Traiter uniquement les tâches récurrentes
./vendor/bin/directive process-tasks --recurring-only

# Limiter le nombre de tâches
./vendor/bin/directive process-tasks --limit=10

# Sortie en JSON
./vendor/bin/directive process-tasks --format=json

# Mode verbeux (affiche les erreurs)
./vendor/bin/directive process-tasks --verbose
```

#### Sortie textuelle

```
=== Batch Results ===
  Unique:    ✅ 3, ❌ 0
  Recurring: ✅ 2, ❌ 1
  Total:     ✅ 5, ❌ 1, 📦 6
  Has failures: Yes
```

#### Sortie JSON

```json
{
  "started_at": "2026-06-22T14:30:00+00:00",
  "ended_at": "2026-06-22T14:30:05+00:00",
  "duration_ms": 5000,
  "success": 5,
  "failed": 1,
  "total": 6,
  "errors": [
    {
      "alias": "failing-task",
      "fqcn": "App\\Tasks\\FailingTask",
      "error": "Task execution failed",
      "context": "Attempt 3/3"
    }
  ],
  "has_failures": true
}
```

---

## Surveillance continue avec TasksWatch

La directive `tasks-watch` exécute `process-tasks` en boucle à intervalle régulier.

```bash
# Exécution toutes les 60 secondes
./vendor/bin/directive tasks-watch

# Avec durée limitée (en secondes)
./vendor/bin/directive tasks-watch --duration=3600 --interval=30

# Traiter uniquement les tâches uniques
./vendor/bin/directive tasks-watch --unique-only --limit=10

# Mode verbeux
./vendor/bin/directive tasks-watch --verbose
```

### Options

| Option | Description | Défaut |
|--------|-------------|--------|
| `--duration` | Durée d'exécution en secondes | Illimité |
| `--interval` | Intervalle entre les cycles en secondes (min 3) | 60 |
| `--unique-only` | Traiter uniquement les tâches uniques | false |
| `--recurring-only` | Traiter uniquement les tâches récurrentes | false |
| `--limit` | Nombre max de tâches par cycle | Illimité |
| `--verbose` | Afficher les détails | false |
| `--testing` | Mode test (exécution en interne) | false |

### Exemple de sortie

```
🚀 Starting tasks watch loop...
   Duration: 3600 seconds (1h)
   Interval: 30 seconds (30s)

🔄 Cycle #1 (started at 14:30:00):
  ➜ Running: ./vendor/bin/directive process-tasks
  ✅ 5 tasks succeeded, ❌ 1 tasks failed
  ⏱️  Cycle duration: 0.45 seconds
  ⏳ Next cycle in 30 seconds...

🔄 Cycle #2 (started at 14:30:30):
  ✅ 3 tasks succeeded, ❌ 0 tasks failed
  ⏱️  Cycle duration: 0.23 seconds

📊 === Summary ===
  Cycles executed:  2
  Total success:    8
  Total failures:   1
  Total errors:     1
  Total duration:   1m 30s
```

---

## Gestion des tâches

### Via les services

```php
use AndyDefer\Task\Contracts\Services\UniqueTaskServiceInterface;
use AndyDefer\Task\Contracts\Services\RecurringTaskServiceInterface;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;

class TaskManager
{
    public function __construct(
        private UniqueTaskServiceInterface $uniqueService,
        private RecurringTaskServiceInterface $recurringService
    ) {}

    // Tâches uniques
    public function cancelUnique(string $taskId): void
    {
        $this->uniqueService->cancel(new TaskIdVO($taskId), 'Canceled by admin');
    }

    public function reschedule(string $taskId, \DateTimeInterface $newDate): void
    {
        $this->uniqueService->reschedule(
            new TaskIdVO($taskId),
            new Iso8601DateTimeVO($newDate->format('Y-m-d\TH:i:sP'))
        );
    }

    // Tâches récurrentes
    public function pauseRecurring(string $alias): void
    {
        $this->recurringService->pause(new TaskSignatureVO($alias));
    }

    public function resumeRecurring(string $alias): void
    {
        $this->recurringService->resume(new TaskSignatureVO($alias));
    }

    public function changeInterval(string $alias, int $seconds): void
    {
        $this->recurringService->changeInterval(new TaskSignatureVO($alias), $seconds);
    }
}
```

### États des tâches

**Tâches uniques :**

| État | Description |
|------|-------------|
| `PENDING` | En attente d'exécution |
| `COMPLETED` | Exécutée avec succès |
| `FAILED` | Échec (max attempts atteint) |
| `CANCELED` | Annulée par l'utilisateur |

**Tâches récurrentes :**

| État | Description |
|------|-------------|
| `WAITING` | En attente (start_at futur) |
| `PLAYING` | Active et prête à s'exécuter |
| `PAUSED` | En pause |
| `FINISHED` | Terminée (end_at atteint) |
| `CANCELED` | Annulée par l'utilisateur |

---

## Mode test

Le mode `--testing` permet d'exécuter les directives sans environnement Laravel complet. Utile pour le développement et les tests.

```bash
# Exécution en mode test
./vendor/bin/directive tasks-watch --testing --duration=5 --interval=3

# Sortie avec mention du mode test
🧪 Testing mode enabled
🚀 Starting tasks watch loop...
   🔬 Mode: TESTING (in-process execution)
```

---

## Bonnes pratiques

### 1. Utiliser les services, pas les facades

```php
// ✅ BON - Injection de service
class UserController
{
    public function __construct(
        private UniqueTaskServiceInterface $taskService
    ) {}
}

// ❌ MAUVAIS - Facade
use AndyDefer\Task\Facades\Task;

class UserController
{
    public function store()
    {
        Task::register(...); // Éviter
    }
}
```

### 2. Accéder à Laravel via getLaravel()

```php
class MyDirective extends AbstractDirective
{
    protected function execute(): ExitCode
    {
        $app = $this->getLaravel();
        $service = $app->make(UniqueTaskServiceInterface::class);
        // Utiliser le service...
        return ExitCode::SUCCESS;
    }
}
```

### 3. Structure des payloads

Utilisez `StrictDataObject` pour les payloads :

```php
$payload = StrictDataObject::from([
    'user_id' => $user->id,
    'email' => $user->email,
    'metadata' => [
        'source' => 'registration',
        'timestamp' => now()->toIso8601String(),
    ],
]);
```

### 4. Logging

Utilisez `$this->info()` et `$this->error()` dans vos tâches :

```php
protected function process(): void
{
    $this->info('Starting task...');
    
    try {
        // Logique...
        $this->info('Task completed successfully');
    } catch (\Exception $e) {
        $this->error('Task failed: ' . $e->getMessage());
        throw $e;
    }
}
```

### 5. Tests

```bash
# Exécuter tous les tests
./vendor/bin/phpunit

# Tester une directive spécifique
./vendor/bin/phpunit --filter ProcessTasksDirectiveTest

# Tester avec le mode testing
./vendor/bin/directive tasks-watch --testing --duration=2 --interval=3
```

---

## Référence des commandes

| Commande | Description |
|----------|-------------|
| `./vendor/bin/directive process-tasks` | Traite toutes les tâches en un lot |
| `./vendor/bin/directive process-tasks --unique-only` | Traite uniquement les tâches uniques |
| `./vendor/bin/directive process-tasks --recurring-only` | Traite uniquement les tâches récurrentes |
| `./vendor/bin/directive process-tasks --limit=10` | Limite à 10 tâches |
| `./vendor/bin/directive process-tasks --format=json` | Sortie en JSON |
| `./vendor/bin/directive process-tasks --verbose` | Affiche les erreurs |
| `./vendor/bin/directive tasks-watch` | Surveillance continue |
| `./vendor/bin/directive tasks-watch --duration=3600` | Pendant 1 heure |
| `./vendor/bin/directive tasks-watch --interval=30` | Toutes les 30 secondes |
| `./vendor/bin/directive tasks-watch --testing` | Mode test |
| `./vendor/bin/directive tasks-watch --recurring-only` | Uniquement récurrentes |

---

## Exemple complet

### 1. Créer une tâche de nettoyage

```php
<?php

namespace App\Tasks;

use AndyDefer\Task\Abstract\AbstractRecurringTask;
use AndyDefer\Task\Configs\RecurringTaskConfig;
use AndyDefer\Task\Contracts\Configs\RecurringTaskConfigInterface;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

class DatabaseCleanupTask extends AbstractRecurringTask
{
    public function getConfig(): RecurringTaskConfigInterface
    {
        return new RecurringTaskConfig(
            alias: new TaskSignatureVO('db-cleanup'),
            description: 'Clean old database records',
            interval_seconds: new CounterVO(86400), // Une fois par jour
            start_at: new Iso8601DateTimeVO(now()->toIso8601String()),
            end_at: new Iso8601DateTimeVO(now()->addDays(365)->toIso8601String()),
            max_attempts: new CounterVO(3),
        );
    }

    protected function process(): void
    {
        $payload = $this->context->getPayload()->toArray();
        $daysToKeep = $payload['days_to_keep'] ?? 30;

        $this->info("Starting cleanup of records older than {$daysToKeep} days...");

        // Logique de nettoyage
        // DB::table('logs')->where('created_at', '<', now()->subDays($daysToKeep))->delete();

        $this->info('Cleanup completed successfully.');
    }
}
```

### 2. Enregistrer la tâche

```php
<?php

namespace App\Console\Commands;

use AndyDefer\Task\Contracts\Services\RecurringTaskServiceInterface;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Configs\RecurringTaskConfig;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

class RegisterCleanupTaskCommand extends Command
{
    protected $signature = 'task:register-cleanup';

    public function handle(RecurringTaskServiceInterface $taskService)
    {
        $config = new RecurringTaskConfig(
            alias: new TaskSignatureVO('db-cleanup'),
            description: 'Clean old database records',
            interval_seconds: new CounterVO(86400),
            start_at: new Iso8601DateTimeVO(now()->toIso8601String()),
            end_at: new Iso8601DateTimeVO(now()->addDays(365)->toIso8601String()),
            max_attempts: new CounterVO(3),
        );

        $payload = StrictDataObject::from([
            'days_to_keep' => 30,
            'tables' => ['logs', 'audits', 'sessions'],
        ]);

        $alias = $taskService->register(
            DatabaseCleanupTask::class,
            $payload,
            $config
        );

        $this->info("Cleanup task registered: {$alias->value}");
    }
}
```

### 3. Surveiller l'exécution

```bash
# Lancer la surveillance en arrière-plan
./vendor/bin/directive tasks-watch --interval=60 --verbose

# Ou avec systemd/supervisor pour une exécution continue
```

---

## Licence

MIT © [Andy Defer](https://github.com/andydefer)