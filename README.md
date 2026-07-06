# Laravel Task

**Un système de tâches robuste pour Laravel avec exécution asynchrone, tâches récurrentes, surveillance continue et exécution parallèle.**

[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-blue)](https://php.net)
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
7. [Exécution parallèle](#exécution-parallèle)
8. [Gestion des tâches](#gestion-des-tâches)
9. [Mode test](#mode-test)
10. [Bonnes pratiques](#bonnes-pratiques)
11. [Référence des commandes](#référence-des-commandes)
12. [Licence](#licence)

---

## Installation

```bash
composer require andydefer/laravel-task
```

### Prérequis

| Version PHP | Version Laravel |
|-------------|-----------------|
| PHP 8.2+ | Laravel 12.x, 13.x, 14.x ou 15.x |

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

Le package suit une architecture en couches avec injection de dépendances :

```
Directives (CLI) → Services (Métier) → Processors → Runners → Repositories → Base de données
```

**Principe clé :** Utilisez toujours les **services** injectés plutôt que les facades pour une meilleure testabilité.

### Cycle de vie d'une tâche

```
┌─────────────────────────────────────────────────────────────┐
│                     AbstractTask                            │
├─────────────────────────────────────────────────────────────┤
│  1. before($payload)    ← Hook de préparation               │
│  2. process()           ← Logique métier (à implémenter)    │
│  3. after($success)     ← Hook de post-traitement           │
├─────────────────────────────────────────────────────────────┤
│  Logging automatique : start → completed/failed             │
│  Gestion des exceptions : capture et propagation            │
└─────────────────────────────────────────────────────────────┘
```

---

## Créer une tâche unique

### 1. Créer la classe de la tâche

```php
<?php

namespace App\Tasks;

use AndyDefer\Task\Abstract\AbstractUniqueTask;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\DomainStructures\Utils\StrictDataObject;

class SendWelcomeEmailTask extends AbstractUniqueTask
{
    /**
     * Validate the payload before execution.
     */
    protected function before(StrictDataObject $payload): void
    {
        if (!$payload->has('email')) {
            throw new \InvalidArgumentException('Email is required');
        }
    }

    /**
     * Execute the main business logic.
     */
    protected function process(): void
    {
        $payload = $this->context->getPayload();
        $email = $payload->email;
        $name = $payload->name ?? 'User';

        $this->info(new DescriptionVO("Sending welcome email to {$email}..."));

        // Logique métier
        // Mail::to($email)->send(new WelcomeEmail($name));

        $this->info(new DescriptionVO("Welcome email sent to {$name}"));
    }

    /**
     * Hook executed after the main processing.
     */
    protected function after(bool $success, ?DescriptionVO $error = null): void
    {
        if ($success) {
            $this->info(new DescriptionVO('Task completed successfully'));
        } else {
            $this->error(new DescriptionVO("Task failed: {$error->getValue()}"));
        }
    }
}
```

### 2. Enregistrer la tâche

```php
<?php

namespace App\Http\Controllers;

use AndyDefer\Task\Contracts\Services\UniqueTaskServiceInterface;
use AndyDefer\Task\Records\UniqueTaskConfigRecord;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use Illuminate\Support\Carbon;

class UserController extends Controller
{
    public function __construct(
        private readonly UniqueTaskServiceInterface $taskService
    ) {}

    public function store(Request $request)
    {
        // Créer l'utilisateur...

        // Enregistrer la tâche
        $config = UniqueTaskConfigRecord::from([
            'scheduled_at' => new Iso8601DateTimeVO(Carbon::now()->addMinutes(5)->toIso8601String()),
            'grace_period' => 86400, // 24h en secondes
            'max_attempts' => 3,
        ]);

        $payload = StrictDataObject::from([
            'email' => $request->email,
            'name' => $request->name,
        ]);

        $alias = $this->taskService->register(
            new UniqueTaskFqcnVO(SendWelcomeEmailTask::class),
            $payload,
            $config
        );

        return response()->json([
            'message' => 'User created. Welcome email scheduled.',
            'task_alias' => $alias->getValue(),
        ]);
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
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\DomainStructures\Utils\StrictDataObject;

class CleanCacheTask extends AbstractRecurringTask
{
    protected function process(): void
    {
        $this->info(new DescriptionVO('Starting cache cleanup...'));

        // Logique métier
        // Cache::cleanExpired();

        $this->info(new DescriptionVO('Cache cleanup completed.'));
    }

    protected function after(bool $success, ?DescriptionVO $error = null): void
    {
        if (!$success) {
            $this->error(new DescriptionVO("Cache cleanup failed: {$error->getValue()}"));
        }
    }
}
```

### 2. Enregistrer la tâche

```php
<?php

namespace App\Console\Commands;

use AndyDefer\Task\Contracts\Services\RecurringTaskServiceInterface;
use AndyDefer\Task\Records\RecurringTaskConfigRecord;
use AndyDefer\Task\ValueObjects\RecurringTaskFqcnVO;
use AndyDefer\DomainStructures\Utils\StrictDataObject;

class RegisterTasksCommand extends Command
{
    public function __construct(
        private readonly RecurringTaskServiceInterface $taskService
    ) {
        parent::__construct();
    }

    public function handle()
    {
        $config = RecurringTaskConfigRecord::from([
            'interval_seconds' => 3600, // Toutes les heures
            'start_at' => Carbon::now()->toIso8601String(),
            'end_at' => Carbon::now()->addDays(30)->toIso8601String(),
            'max_attempts' => 3,
        ]);

        $alias = $this->taskService->register(
            new RecurringTaskFqcnVO(CleanCacheTask::class),
            StrictDataObject::from(['enabled' => true]),
            $config
        );

        $this->info("Task registered: {$alias->getValue()}");
    }
}
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

### Sortie textuelle

```
Processing tasks...

=== Batch Results ===
  Unique:    ✅ 3, ❌ 0
  Recurring: ✅ 2, ❌ 1
  Total:     ✅ 5, ❌ 1, 📦 6
  Has failures: Yes

=== Failed Tasks ===
  Unique tasks:
    ❌ unique@abc-123: Connection timeout (attempts: 2/3)
```

### Sortie JSON

```json
{
  "started_at": "2026-01-01T12:00:00+00:00",
  "ended_at": "2026-01-01T12:00:05+00:00",
  "duration_ms": 5000,
  "total_success": 5,
  "total_failed": 1,
  "total": 6,
  "has_failures": true,
  "unique": {
    "success": 3,
    "failed": 0,
    "errors": []
  },
  "recurring": {
    "success": 2,
    "failed": 1,
    "errors": [
      {
        "alias": "recurring@def-456",
        "fqcn": "App\\Tasks\\FailingTask",
        "description": "Task execution failed",
        "context": "end_at: null"
      }
    ]
  }
}
```

---

## Surveillance continue avec TasksWatch

La directive `tasks-watch` exécute `process-tasks` en boucle à intervalle régulier.

```bash
# Exécution toutes les 60 secondes (illimité)
./vendor/bin/directive tasks-watch

# Avec durée limitée
./vendor/bin/directive tasks-watch --duration=3600 --interval=30

# Traiter uniquement les tâches uniques
./vendor/bin/directive tasks-watch --unique-only --limit=10

# Mode verbeux
./vendor/bin/directive tasks-watch --verbose

# Arrêt gracieux : Ctrl+C
```

### Options

| Option | Description | Défaut |
|--------|-------------|--------|
| `--duration` | Durée d'exécution en secondes | Illimité |
| `--interval` | Intervalle entre les cycles (min 3s) | 60 |
| `--unique-only` | Traiter uniquement les tâches uniques | false |
| `--recurring-only` | Traiter uniquement les tâches récurrentes | false |
| `--limit` | Nombre max de tâches par cycle | Illimité |
| `--verbose` | Afficher les détails des erreurs | false |
| `--testing` | Mode test (exécution in-process) | false |
| `--parallel` | Nombre de workers parallèles | 1 (séquentiel) |

### Exemple de sortie

```
🚀 Starting tasks watch loop...
Duration: unlimited (Ctrl+C to stop)
Interval: 60 (1m)
Options: --unique-only --limit=10

================================================================================


🔄 Cycle #1 (started at 14:30:00):
✅ 5 tasks succeeded, ❌ 1 tasks failed
⏱️  Cycle duration: 0.45 seconds
⏳ Next cycle in 59 seconds...

🔄 Cycle #2 (started at 14:31:00):
✅ 3 tasks succeeded, ❌ 0 tasks failed
⏱️  Cycle duration: 0.12 seconds
⏳ Next cycle in 60 seconds...

...

================================================================================
📊 Summary
Cycles executed    : 10
Total success      : 42
Total failures     : 8
Total errors       : 3
Total duration     : 10m 30s
================================================================================
```

---

## Exécution parallèle

Le package supporte l'exécution parallèle des tâches via l'option `--parallel`.

```bash
# Exécution séquentielle (par défaut)
./vendor/bin/directive tasks-watch

# Exécution avec 3 workers parallèles
./vendor/bin/directive tasks-watch --parallel=3

# Parallélisme avec limite
./vendor/bin/directive tasks-watch --parallel=4 --limit=100
```

### Comment ça fonctionne

1. **Verrouillage de ligne** : `lockForUpdate()` garantit qu'une tâche n'est traitée qu'une seule fois
2. **Workers indépendants** : Chaque worker traite un lot de tâches séparé
3. **Agrégation des résultats** : Les résultats de tous les workers sont combinés

### Sortie avec parallélisme

```
🚀 Starting tasks watch loop...
⚡ Parallel execution: 3 workers
Duration: unlimited (Ctrl+C to stop)
Interval: 60 (1m)
Options: --parallel=3

🔄 Cycle #1 (started at 14:30:00):
⚡ Parallel execution: 3 workers
✅ 45 tasks succeeded, ❌ 5 tasks failed
⏱️  Cycle duration: 2.34 seconds
⏳ Next cycle in 57 seconds...
```

---

## Gestion des tâches

### États des tâches

**Tâches uniques :**

```
PENDING ──(succès)──▶ COMPLETED
    │
    ├──(échec max)──▶ FAILED
    │
    └──(annulation)──▶ CANCELED
```

**Tâches récurrentes :**

```
WAITING ──(start_at)──▶ PLAYING
                           │
               ┌───────────┼───────────┐
               │           │           │
               ▼           ▼           ▼
            PAUSED     FINISHED    CANCELED
               │           │           │
               └───────────┘           │
                           │           │
                           ▼           ▼
                       (terminal)  (terminal)
```

### API de gestion

```php
use AndyDefer\Task\Contracts\Services\UniqueTaskServiceInterface;
use AndyDefer\Task\Contracts\Services\RecurringTaskServiceInterface;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;

class TaskManager
{
    public function __construct(
        private readonly UniqueTaskServiceInterface $uniqueService,
        private readonly RecurringTaskServiceInterface $recurringService
    ) {}

    // === Tâches uniques ===

    public function cancelUnique(string $alias): void
    {
        $this->uniqueService->cancel(
            new TaskAliasVO($alias),
            new DescriptionVO('Canceled by admin')
        );
    }

    public function reschedule(string $alias, Carbon $newDate): void
    {
        $this->uniqueService->reschedule(
            new TaskAliasVO($alias),
            new Iso8601DateTimeVO($newDate->toIso8601String())
        );
    }

    public function extendGracePeriod(string $alias, int $seconds): void
    {
        $this->uniqueService->extendGracePeriod(
            new TaskAliasVO($alias),
            new DurationVO($seconds)
        );
    }

    // === Tâches récurrentes ===

    public function pauseRecurring(string $alias): void
    {
        $this->recurringService->pause(new TaskAliasVO($alias));
    }

    public function resumeRecurring(string $alias): void
    {
        $this->recurringService->resume(new TaskAliasVO($alias));
    }

    public function changeInterval(string $alias, int $seconds): void
    {
        $this->recurringService->changeInterval(
            new TaskAliasVO($alias),
            new DurationVO($seconds)
        );
    }

    public function finishRecurring(string $alias): void
    {
        $this->recurringService->finish(new TaskAliasVO($alias));
    }
}
```

---

## Mode test

Le mode `--testing` permet d'exécuter les directives sans environnement Laravel complet. Utile pour le développement et les tests.

```bash
# Exécution en mode test
./vendor/bin/directive tasks-watch --testing --duration=5 --interval=3

# Process-tasks en mode test
./vendor/bin/directive process-tasks --testing --unique-only
```

### Sortie avec mode test

```
🧪 Testing mode enabled
🚀 Starting tasks watch loop...
🔬 Mode: TESTING (in-process execution)
Duration: 5 (5s)
Interval: 3 (3s)
Options: --testing

================================================================================
```

### Avantages du mode test

- ✅ Exécution in-process (pas de processus enfants)
- ✅ Exécution plus rapide
- ✅ Débogage facilité
- ✅ Pas de dépendance système

---

## Bonnes pratiques

### 1. Utiliser les services injectés

```php
// ✅ BON - Injection de service
class UserController
{
    public function __construct(
        private readonly UniqueTaskServiceInterface $taskService
    ) {}
}

// ❌ MAUVAIS - Facade
use AndyDefer\Task\Facades\Task;
Task::register(...); // Éviter
```

### 2. Valider les payloads dans `before()`

```php
protected function before(StrictDataObject $payload): void
{
    if (!$payload->has('user_id')) {
        throw new InvalidArgumentException('user_id is required');
    }
}
```

### 3. Utiliser les logs dans les tâches

```php
protected function process(): void
{
    $this->info(new DescriptionVO('Processing started...'));
    
    try {
        // Logique métier
        $this->info(new DescriptionVO('Processing completed'));
    } catch (\Throwable $e) {
        $this->error(new DescriptionVO("Error: {$e->getMessage()}"));
        throw $e;
    }
}
```

### 4. Structurer les payloads

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

### 5. Utiliser des limites

```bash
# Éviter les surcharges
./vendor/bin/directive process-tasks --limit=100
./vendor/bin/directive tasks-watch --limit=50 --interval=30
```

### 6. Monitorer les exécutions

```bash
# Mode verbeux pour le débogage
./vendor/bin/directive tasks-watch --verbose

# Sortie JSON pour l'intégration
./vendor/bin/directive process-tasks --format=json
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
| `./vendor/bin/directive tasks-watch --parallel=3` | Exécution parallèle (3 workers) |
| `./vendor/bin/directive tasks-watch --testing` | Mode test |
| `./vendor/bin/directive tasks-watch --recurring-only` | Uniquement récurrentes |

### Codes de sortie

| Code | Signification |
|------|---------------|
| `0` | SUCCESS - Toutes les tâches ont réussi |
| `1` | FAILURE - Au moins une tâche a échoué |
| `2` | INVALID_ARGUMENT - Options invalides |

---

## Licence

MIT © [Andy Defer](https://github.com/andydefer)