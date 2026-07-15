Je comprends, je vais corriger le README pour utiliser les **signatures exactes** des services avec les **records** appropriés (`UniqueTaskConfigRecord` et `RecurringTaskConfigRecord`), et non des tableaux PHP.

Voici la version corrigée :

---

# Laravel Task

**Un moteur de tâches persistantes pour Laravel. Planification dynamique, exécution récurrente, état, retry, pause, reprise - avec un simple cron.**

[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-blue)](https://php.net)
[![Laravel Version](https://img.shields.io/badge/Laravel-12.x%20%7C%2013.x%20%7C%2014.x%20%7C%2015.x-blue)](https://laravel.com)
[![License](https://img.shields.io/badge/License-MIT-green)](LICENSE)

---

## Table des matières

1. [Installation](#installation)
2. [Pourquoi Laravel Task ?](#pourquoi-laravel-task-)
3. [Architecture et concepts clés](#architecture-et-concepts-clés)
4. [Créer une tâche unique](#créer-une-tâche-unique)
5. [Créer une tâche récurrente](#créer-une-tâche-récurrente)
6. [Le noyau de directives (DirectiveKernel)](#le-noyau-de-directives-directivekernel)
7. [Exécuter les tâches](#exécuter-les-tâches)
8. [Surveillance continue](#surveillance-continue)
9. [Exécution parallèle](#exécution-parallèle)
10. [Gestion des tâches](#gestion-des-tâches)
11. [Mode test et fixtures](#mode-test-et-fixtures)
12. [Cas d'usage concrets](#cas-dusage-concrets)
13. [Intégration avec les cron jobs](#intégration-avec-les-cron-jobs)
14. [Bonnes pratiques](#bonnes-pratiques)
15. [Référence des commandes](#référence-des-commandes)

---

## Installation

```bash
composer require andydefer/laravel-task

php artisan vendor:publish --tag=task-migrations
php artisan migrate
```

**Prérequis :** PHP 8.2+ | Laravel 12.x, 13.x, 14.x ou 15.x

---

## Pourquoi Laravel Task ?

**Le problème :** Vous devez envoyer un email 30 minutes après chaque inscription. Avec Laravel Queue, il vous faut un worker permanent, Supervisor, et généralement un VPS. Sur un hébergement mutualisé, c'est impossible.

**La solution :** Laravel Task. Des tâches persistantes avec un cycle de vie complet, qui fonctionnent avec un simple cron.

```bash
# Un seul cron suffit
* * * * * cd /chemin/projet && ./bin/task tasks:watch --mute
```

### Comparatif rapide

| Besoin | Scheduler | Queue | Laravel Task |
|--------|-----------|-------|--------------|
| Tâche "dans 5 minutes" | ❌ | ✅ | ✅ |
| Tâche récurrente avec date de fin | ❌ | ❌ | ✅ |
| Pause / Reprise | ❌ | ❌ | ✅ |
| Retry automatique | ❌ | ✅ | ✅ |
| État et historique | ❌ | ❌ | ✅ |
| Exécution parallèle | ❌ | ✅ | ✅ |
| Fonctionne sur hébergement SHARED | ✅ | ❌ | ✅ |

---

## Architecture et concepts clés

### Le noyau (DirectiveKernel)

Le package repose sur un **noyau de directives** (DirectiveKernel) qui orchestre toute l'exécution. Il agit comme un micro-framework de commandes intégré à Laravel.

```php
use AndyDefer\Directive\DirectiveKernel;

$kernel = DirectiveKernel::init($app);
$kernel->addSource('/path/to/directives');

// Exécution d'une directive
$exitCode = $kernel->run(['directive', 'tasks:process']);
```

**Fonctionnalités clés :**
- ✅ Découverte automatique des directives
- ✅ Indexation BK-Tree pour les suggestions de commandes
- ✅ Contexte partagé entre les directives
- ✅ Journalisation des exécutions en JSONL
- ✅ Mode verbose pour le débogage
- ✅ Détection de circularité

### Les directives

Le package fournit trois directives principales :

| Directive | Description | Utilisation |
|-----------|-------------|-------------|
| `tasks:process` | Exécution unique en lot | `./bin/task tasks:process` |
| `tasks:watch` | Surveillance continue | `./bin/task tasks:watch` |
| `fixture:register-tasks` | Création de tâches de test | `./bin/task fixture:register-tasks` |

### Les services de tâches

Le package expose deux services principaux qui utilisent des **records** pour la configuration :

```php
use AndyDefer\Task\Contracts\Services\UniqueTaskServiceInterface;
use AndyDefer\Task\Contracts\Services\RecurringTaskServiceInterface;
use AndyDefer\Task\Records\UniqueTaskConfigRecord;
use AndyDefer\Task\Records\RecurringTaskConfigRecord;

class MyService
{
    public function __construct(
        private readonly UniqueTaskServiceInterface $uniqueService,
        private readonly RecurringTaskServiceInterface $recurringService
    ) {}
}
```

---

## Créer une tâche unique

### 1. Créer la classe

```php
<?php

namespace App\Tasks;

use AndyDefer\Task\Abstract\AbstractUniqueTask;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\DomainStructures\Utils\StrictDataObject;

class SendWelcomeEmailTask extends AbstractUniqueTask
{
    // ✅ Hook exécuté avant process() - idéal pour la validation
    protected function before(StrictDataObject $payload): void
    {
        if (!$payload->has('email')) {
            throw new \InvalidArgumentException('Email is required');
        }
    }

    // ✅ La logique métier de votre tâche
    protected function process(): void
    {
        $payload = $this->context->getPayload();
        
        $this->info(new DescriptionVO("Sending email to {$payload->email}..."));
        
        // Votre code métier ici
        // Mail::to($payload->email)->send(new WelcomeEmail($payload->name));
        
        $this->info(new DescriptionVO("Email sent to {$payload->email}"));
    }

    // ✅ Hook exécuté après process() - idéal pour la notification
    protected function after(bool $success, ?DescriptionVO $error = null): void
    {
        if ($success) {
            $this->info(new DescriptionVO('Task completed successfully'));
        } else {
            $this->error(new DescriptionVO("Task failed: {$error->getValue()}"));
            // Envoyer une alerte, logger, etc.
        }
    }
}
```

### 2. Enregistrer la tâche via le service

```php
<?php

namespace App\Http\Controllers;

use AndyDefer\Task\Contracts\Services\UniqueTaskServiceInterface;
use AndyDefer\Task\Records\UniqueTaskConfigRecord;
use AndyDefer\Task\ValueObjects\UniqueTaskFqcnVO;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\MaxAttemptsVO;
use AndyDefer\Task\ValueObjects\DurationVO;

class UserController extends Controller
{
    public function __construct(
        private readonly UniqueTaskServiceInterface $taskService
    ) {}

    public function store(Request $request)
    {
        // Création de l'utilisateur...
        
        // ✅ Enregistrement de la tâche avec UniqueTaskConfigRecord
        $config = UniqueTaskConfigRecord::from([
            'scheduled_at' => new Iso8601DateTimeVO(now()->addMinutes(5)),
            'max_attempts' => new MaxAttemptsVO(3),
            'grace_period' => new DurationVO(3600), // 1h
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
            'message' => 'Email planifié dans 5 minutes',
            'task_alias' => $alias->getValue(),
        ]);
    }
}
```

---

## Créer une tâche récurrente

### 1. Créer la classe

```php
<?php

namespace App\Tasks;

use AndyDefer\Task\Abstract\AbstractRecurringTask;
use AndyDefer\Task\ValueObjects\DescriptionVO;

class CleanExpiredCacheTask extends AbstractRecurringTask
{
    protected function process(): void
    {
        $this->info(new DescriptionVO('Starting cache cleanup...'));
        
        // ✅ Ici votre code métier exécuté à chaque intervalle
        // Cache::cleanExpired();
        
        $this->info(new DescriptionVO('Cache cleaned successfully'));
    }

    protected function after(bool $success, ?DescriptionVO $error = null): void
    {
        if (!$success) {
            $this->error(new DescriptionVO("Cleanup failed: {$error->getValue()}"));
            // ✅ Alerter l'équipe, envoyer un email, etc.
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
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\MaxFailedAttemptsVO;

class SetupTasksCommand extends Command
{
    public function __construct(
        private readonly RecurringTaskServiceInterface $taskService
    ) {
        parent::__construct();
    }

    public function handle()
    {
        // ✅ Nettoyage toutes les heures, pendant 30 jours
        $config = RecurringTaskConfigRecord::from([
            'interval_seconds' => new DurationVO(3600), // Toutes les heures
            'start_at' => new Iso8601DateTimeVO(now()->toIso8601String()),
            'end_at' => new Iso8601DateTimeVO(now()->addDays(30)->toIso8601String()),
            'max_attempts' => new MaxFailedAttemptsVO(3),
        ]);

        $alias = $this->taskService->register(
            new RecurringTaskFqcnVO(CleanExpiredCacheTask::class),
            StrictDataObject::from(['enabled' => true]),
            $config
        );

        $this->info("Task registered: {$alias->getValue()}");
    }
}
```

---

## Le noyau de directives (DirectiveKernel)

Le `DirectiveKernel` est le cœur de l'exécution. Il permet de :

### 1. Exécuter une directive programmatiquement

```php
<?php

use AndyDefer\Directive\DirectiveKernel;
use AndyDefer\Directive\Enums\ExitCode;

$kernel = DirectiveKernel::init($app);

// Par signature complète
$exitCode = $kernel->runSignature('tasks:process 50 --unique-only --verbose');

// Par FQCN
$exitCode = $kernel->runDirective(
    'AndyDefer\Task\Directives\TasksProcessDirective',
    ['50', '--unique-only']
);

// Par arguments bruts (comme en ligne de commande)
$exitCode = $kernel->run(['directive', 'tasks:process', '50', '--unique-only']);
```

### 2. Utiliser le contexte partagé

```php
<?php

$kernel = DirectiveKernel::init($app);

// Définir des données dans le contexte
$context = $kernel->getContext();
$context->put('user_id', 12345);
$context->put('batch_id', 'batch-abc-123');

// Exécuter une directive qui utilise le contexte
$kernel->run(['directive', 'process:user']);

// Récupérer les résultats du contexte
$result = $context->get('process_result');
```

---

## Exécuter les tâches

### Une seule fois (tasks:process)

```bash
# Traiter toutes les tâches
./bin/task tasks:process

# Traiter jusqu'à 50 tâches
./bin/task tasks:process 50

# Uniquement les tâches uniques
./bin/task tasks:process --unique-only

# Uniquement les tâches récurrentes
./bin/task tasks:process --recurring-only

# Mode verbeux (voir les erreurs)
./bin/task tasks:process --verbose

# Mode silencieux (pour cron)
./bin/task tasks:process --mute
```

---

## Surveillance continue (tasks:watch)

La directive `tasks:watch` exécute `tasks:process` en boucle avec un intervalle configurable.

```bash
# Toutes les 60 secondes (illimité)
./bin/task tasks:watch

# Pendant 1 heure, toutes les 30 secondes
./bin/task tasks:watch 30 3600

# Avec 4 workers parallèles
./bin/task tasks:watch 5 600 100 4 --verbose
```

### Arguments

| Argument | Description | Défaut |
|----------|-------------|--------|
| `interval` | Intervalle entre les cycles (minimum 2s) | 60 |
| `duration` | Durée totale d'exécution en secondes | Illimité |
| `limit` | Nombre max de tâches par cycle | 100 |
| `parallel` | Nombre de workers parallèles | 1 |

---

## Exécution parallèle

Le package supporte l'exécution parallèle des tâches via l'option `--parallel`.

```bash
# Exécution séquentielle (par défaut)
./bin/task tasks:watch

# Exécution avec 4 workers parallèles
./bin/task tasks:watch 10 300 100 4 --verbose
```

### Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                    tasks:watch (parent)                        │
│              CycleCalculator + ResultAggregator                │
└────────────────────────┬────────────────────────────────────────┘
                         │
          ┌──────────────┼──────────────┐
          │              │              │
          ▼              ▼              ▼
┌─────────────────┐┌─────────────────┐┌─────────────────┐
│    Worker 1     ││    Worker 2     ││    Worker 3     │
│   tasks:process ││   tasks:process ││   tasks:process │
│   --unique-only ││   --unique-only ││   --unique-only │
│   --limit=33    ││   --limit=33    ││   --limit=34    │
│   --mute        ││   --mute        ││   --mute        │
└─────────────────┘└─────────────────┘└─────────────────┘
```

---

## Gestion des tâches

### États des tâches uniques

```
PENDING ──(verrouillage)──▶ IN_PROGRESS
    │                           │
    ├──(succès)───────────────▶ COMPLETED
    │
    ├──(échec max)───────────▶ FAILED
    │
    ├──(annulation)──────────▶ CANCELED
    │
    └──(expiration)──────────▶ FAILED
```

### États des tâches récurrentes

```
WAITING ──(start_at)──▶ PLAYING
                           │
               ┌───────────┼───────────┐
               │           │           │
               ▼           ▼           ▼
            PAUSED     FINISHED    CANCELED
```

### API de gestion

```php
<?php

namespace App\Services;

use AndyDefer\Task\Contracts\Services\UniqueTaskServiceInterface;
use AndyDefer\Task\Contracts\Services\RecurringTaskServiceInterface;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;

class TaskManager
{
    public function __construct(
        private readonly UniqueTaskServiceInterface $uniqueService,
        private readonly RecurringTaskServiceInterface $recurringService
    ) {}

    // === TÂCHES UNIQUES ===

    // ✅ Annuler une tâche unique
    public function cancelUnique(string $alias): void
    {
        $this->uniqueService->cancel(
            new TaskAliasVO($alias),
            new DescriptionVO('Canceled by admin')
        );
    }

    // ✅ Reprogrammer à une autre date
    public function reschedule(string $alias, Iso8601DateTimeVO $newDate): void
    {
        $this->uniqueService->reschedule(
            new TaskAliasVO($alias),
            $newDate
        );
    }

    // ✅ Prolonger la période de grâce
    public function extendGracePeriod(string $alias, DurationVO $extraSeconds): void
    {
        $this->uniqueService->extendGracePeriod(
            new TaskAliasVO($alias),
            $extraSeconds
        );
    }

    // ✅ Exécuter une tâche unique manuellement
    public function runUnique(string $alias): TaskRunResultRecord
    {
        return $this->uniqueService->run(new TaskAliasVO($alias));
    }

    // === TÂCHES RÉCURRENTES ===

    // ✅ Mettre en pause
    public function pause(string $alias): void
    {
        $this->recurringService->pause(new TaskAliasVO($alias));
    }

    // ✅ Reprendre
    public function resume(string $alias): void
    {
        $this->recurringService->resume(new TaskAliasVO($alias));
    }

    // ✅ Changer l'intervalle
    public function changeInterval(string $alias, DurationVO $interval): void
    {
        $this->recurringService->changeInterval(
            new TaskAliasVO($alias),
            $interval
        );
    }

    // ✅ Terminer définitivement
    public function finish(string $alias): void
    {
        $this->recurringService->finish(new TaskAliasVO($alias));
    }

    // ✅ Prolonger la date de fin
    public function extendEndAt(string $alias, Iso8601DateTimeVO $newEndAt): void
    {
        $this->recurringService->extendEndAt(
            new TaskAliasVO($alias),
            $newEndAt
        );
    }

    // === INSPECTION ===

    // ✅ Récupérer une tâche
    public function findUnique(string $alias): ?UniqueTaskRecord
    {
        return $this->uniqueService->find(new TaskAliasVO($alias));
    }

    public function findRecurring(string $alias): ?RecurringTaskRecord
    {
        return $this->recurringService->find(new TaskAliasVO($alias));
    }

    // ✅ Compter les tâches
    public function getStats(): array
    {
        return [
            'unique_pending' => $this->uniqueService->countPending()->getValue(),
            'unique_completed' => $this->uniqueService->countCompleted()->getValue(),
            'unique_failed' => $this->uniqueService->countFailed()->getValue(),
            'unique_canceled' => $this->uniqueService->countCanceled()->getValue(),
            'recurring_waiting' => $this->recurringService->countWaiting()->getValue(),
            'recurring_playing' => $this->recurringService->countPlaying()->getValue(),
            'recurring_paused' => $this->recurringService->countPaused()->getValue(),
            'recurring_finished' => $this->recurringService->countFinished()->getValue(),
            'recurring_canceled' => $this->recurringService->countCanceled()->getValue(),
        ];
    }
}
```

---

## Cas d'usage concrets

### 1. SaaS - Abonnements et facturation

```php
<?php

namespace App\Services;

use AndyDefer\Task\Contracts\Services\UniqueTaskServiceInterface;
use AndyDefer\Task\Records\UniqueTaskConfigRecord;
use AndyDefer\Task\ValueObjects\UniqueTaskFqcnVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\MaxAttemptsVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\DomainStructures\Utils\StrictDataObject;

class SubscriptionService
{
    public function __construct(
        private readonly UniqueTaskServiceInterface $taskService
    ) {}

    public function createSubscription(User $user, Plan $plan): void
    {
        // ✅ Rappel J-1 avant expiration
        $this->taskService->register(
            new UniqueTaskFqcnVO(RenewalReminderTask::class),
            StrictDataObject::from([
                'user_id' => $user->id,
                'email' => $user->email,
                'plan' => $plan->name,
            ]),
            UniqueTaskConfigRecord::from([
                'scheduled_at' => new Iso8601DateTimeVO($user->subscription_end_at->subDay()),
                'max_attempts' => new MaxAttemptsVO(2),
                'grace_period' => new DurationVO(3600),
            ])
        );

        // ✅ Désactivation à la date d'expiration
        $this->taskService->register(
            new UniqueTaskFqcnVO(ExpireSubscriptionTask::class),
            StrictDataObject::from([
                'user_id' => $user->id,
            ]),
            UniqueTaskConfigRecord::from([
                'scheduled_at' => new Iso8601DateTimeVO($user->subscription_end_at),
                'max_attempts' => new MaxAttemptsVO(3),
                'grace_period' => new DurationVO(7200),
            ])
        );

        // ✅ Relance en cas de paiement échoué
        $this->taskService->register(
            new UniqueTaskFqcnVO(PaymentRetryTask::class),
            StrictDataObject::from([
                'user_id' => $user->id,
                'payment_id' => $payment->id,
            ]),
            UniqueTaskConfigRecord::from([
                'scheduled_at' => new Iso8601DateTimeVO(now()->addHours(24)),
                'max_attempts' => new MaxAttemptsVO(3),
                'grace_period' => new DurationVO(86400),
            ])
        );
    }
}
```

### 2. E-commerce - Paniers abandonnés

```php
<?php

namespace App\Services;

use AndyDefer\Task\Contracts\Services\UniqueTaskServiceInterface;
use AndyDefer\Task\Records\UniqueTaskConfigRecord;
use AndyDefer\Task\ValueObjects\UniqueTaskFqcnVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\MaxAttemptsVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\DomainStructures\Utils\StrictDataObject;

class AbandonedCartService
{
    public function __construct(
        private readonly UniqueTaskServiceInterface $uniqueService
    ) {}

    public function handleAbandonedCart(Cart $cart, User $user): void
    {
        // ✅ Email de relance 30 min après abandon
        $this->uniqueService->register(
            new UniqueTaskFqcnVO(AbandonedCartReminderTask::class),
            StrictDataObject::from([
                'cart_id' => $cart->id,
                'user_id' => $user->id,
                'items' => $cart->items,
            ]),
            UniqueTaskConfigRecord::from([
                'scheduled_at' => new Iso8601DateTimeVO(now()->addMinutes(30)),
                'max_attempts' => new MaxAttemptsVO(2),
                'grace_period' => new DurationVO(3600),
            ])
        );

        // ✅ Email de suivi J+3
        $this->uniqueService->register(
            new UniqueTaskFqcnVO(FollowUpEmailTask::class),
            StrictDataObject::from([
                'user_id' => $user->id,
                'cart_id' => $cart->id,
            ]),
            UniqueTaskConfigRecord::from([
                'scheduled_at' => new Iso8601DateTimeVO(now()->addDays(3)),
                'max_attempts' => new MaxAttemptsVO(2),
                'grace_period' => new DurationVO(3600),
            ])
        );
    }
}
```

### 3. Intégrations API - Webhooks avec retry

```php
<?php

namespace App\Services;

use AndyDefer\Task\Contracts\Services\UniqueTaskServiceInterface;
use AndyDefer\Task\Records\UniqueTaskConfigRecord;
use AndyDefer\Task\ValueObjects\UniqueTaskFqcnVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\MaxAttemptsVO;
use AndyDefer\Task\ValueObjects\DurationVO;

class WebhookService
{
    public function __construct(
        private readonly UniqueTaskServiceInterface $uniqueService
    ) {}

    public function sendWebhook($event, $data): string
    {
        // ✅ Appel API avec retry automatique
        $config = UniqueTaskConfigRecord::from([
            'scheduled_at' => new Iso8601DateTimeVO(now()->addSeconds(5)),
            'max_attempts' => new MaxAttemptsVO(5),
            'grace_period' => new DurationVO(7200), // 2h
        ]);

        $alias = $this->uniqueService->register(
            new UniqueTaskFqcnVO(SendWebhookTask::class),
            StrictDataObject::from([
                'url' => config('webhooks.endpoint'),
                'event' => $event,
                'data' => $data,
            ]),
            $config
        );

        return $alias->getValue();
    }
}
```

### 4. Maintenance - Nettoyage et backups

```php
<?php

namespace App\Services;

use AndyDefer\Task\Contracts\Services\RecurringTaskServiceInterface;
use AndyDefer\Task\Contracts\Services\UniqueTaskServiceInterface;
use AndyDefer\Task\Records\RecurringTaskConfigRecord;
use AndyDefer\Task\Records\UniqueTaskConfigRecord;
use AndyDefer\Task\ValueObjects\RecurringTaskFqcnVO;
use AndyDefer\Task\ValueObjects\UniqueTaskFqcnVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\MaxFailedAttemptsVO;
use AndyDefer\Task\ValueObjects\MaxAttemptsVO;

class MaintenanceService
{
    public function __construct(
        private readonly RecurringTaskServiceInterface $recurringService,
        private readonly UniqueTaskServiceInterface $uniqueService
    ) {}

    public function scheduleMaintenance(): void
    {
        // ✅ Nettoyage toutes les heures
        $this->recurringService->register(
            new RecurringTaskFqcnVO(CacheCleanTask::class),
            StrictDataObject::from(['enabled' => true]),
            RecurringTaskConfigRecord::from([
                'interval_seconds' => new DurationVO(3600),
                'start_at' => new Iso8601DateTimeVO(now()->toIso8601String()),
                'max_attempts' => new MaxFailedAttemptsVO(3),
            ])
        );

        // ✅ Backup DB à 2h du matin
        $this->uniqueService->register(
            new UniqueTaskFqcnVO(BackupDatabaseTask::class),
            StrictDataObject::from([
                'database' => config('database.connections.mysql.database'),
                'backup_path' => storage_path('backups'),
            ]),
            UniqueTaskConfigRecord::from([
                'scheduled_at' => new Iso8601DateTimeVO(Carbon::now()->setTime(2, 0)),
                'max_attempts' => new MaxAttemptsVO(1),
                'grace_period' => new DurationVO(3600),
            ])
        );
    }
}
```

### 5. Workflow - Orchestration en plusieurs étapes

```php
<?php

namespace App\Services;

use AndyDefer\Task\Contracts\Services\UniqueTaskServiceInterface;
use AndyDefer\Task\Records\UniqueTaskConfigRecord;
use AndyDefer\Task\ValueObjects\UniqueTaskFqcnVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\MaxAttemptsVO;
use AndyDefer\Task\ValueObjects\DurationVO;

class OrderWorkflowService
{
    public function __construct(
        private readonly UniqueTaskServiceInterface $uniqueService
    ) {}

    public function processOrder(Order $order): void
    {
        $payload = StrictDataObject::from([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'total' => $order->total,
        ]);

        $steps = [
            ValidateOrderTask::class => now()->addSeconds(10),
            ProcessPaymentTask::class => now()->addSeconds(30),
            GenerateInvoiceTask::class => now()->addMinutes(1),
            SendConfirmationTask::class => now()->addMinutes(2),
        ];

        foreach ($steps as $class => $scheduledAt) {
            $this->uniqueService->register(
                new UniqueTaskFqcnVO($class),
                $payload,
                UniqueTaskConfigRecord::from([
                    'scheduled_at' => new Iso8601DateTimeVO($scheduledAt),
                    'max_attempts' => new MaxAttemptsVO(2),
                    'grace_period' => new DurationVO(3600),
                ])
            );
        }
    }
}
```

### 6. Campaigns marketing - Newsletters temporaires

```php
<?php

namespace App\Services;

use AndyDefer\Task\Contracts\Services\RecurringTaskServiceInterface;
use AndyDefer\Task\Records\RecurringTaskConfigRecord;
use AndyDefer\Task\ValueObjects\RecurringTaskFqcnVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\MaxFailedAttemptsVO;

class CampaignService
{
    public function __construct(
        private readonly RecurringTaskServiceInterface $recurringService
    ) {}

    public function startCampaign(Campaign $campaign): void
    {
        // ✅ Newsletter hebdomadaire sur 4 semaines
        $this->recurringService->register(
            new RecurringTaskFqcnVO(NewsletterTask::class),
            StrictDataObject::from([
                'campaign_id' => $campaign->id,
                'template' => $campaign->template,
            ]),
            RecurringTaskConfigRecord::from([
                'interval_seconds' => new DurationVO(604800), // 7 jours
                'start_at' => new Iso8601DateTimeVO(now()->toIso8601String()),
                'end_at' => new Iso8601DateTimeVO(now()->addWeeks(4)->toIso8601String()),
                'max_attempts' => new MaxFailedAttemptsVO(2),
            ])
        );
    }
}
```

---

## Intégration avec les cron jobs

### Configuration de base

```bash
# Exécution toutes les minutes
* * * * * cd /var/www/project && ./bin/task tasks:watch 30 --mute >> /var/log/tasks-watch.log 2>&1

# Exécution des tâches uniques toutes les 5 minutes
*/5 * * * * cd /var/www/project && ./bin/task tasks:process 100 --unique-only --mute >> /var/log/tasks-unique.log 2>&1

# Exécution des tâches récurrentes toutes les heures
0 * * * * cd /var/www/project && ./bin/task tasks:process --recurring-only --mute >> /var/log/tasks-recurring.log 2>&1
```

---

## Bonnes pratiques

### ✅ Injection de services

```php
// BON
class UserController
{
    public function __construct(
        private readonly UniqueTaskServiceInterface $taskService
    ) {}
}
```

### ✅ Utiliser des Value Objects pour les dates

```php
// BON
new Iso8601DateTimeVO(now()->addMinutes(5))

// ÉVITER
$config['scheduled_at'] = now()->addMinutes(5);
```

### ✅ Structure des payloads avec StrictDataObject

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

### ✅ Utiliser des limites

```bash
# Éviter les surcharges
./bin/task tasks:process 100
./bin/task tasks:watch 30 300 50
```

---

## Référence des commandes

| Commande | Description |
|----------|-------------|
| `./bin/task tasks:process` | Traite toutes les tâches en un lot |
| `./bin/task tasks:process 50` | Limite à 50 tâches |
| `./bin/task tasks:process --unique-only` | Uniquement les uniques |
| `./bin/task tasks:process --recurring-only` | Uniquement les récurrentes |
| `./bin/task tasks:process --verbose` | Affiche les erreurs |
| `./bin/task tasks:process --mute` | Mode silencieux |
| `./bin/task tasks:watch` | Surveillance continue (60s) |
| `./bin/task tasks:watch 30` | Toutes les 30s |
| `./bin/task tasks:watch 30 3600` | Pendant 1 heure |
| `./bin/task tasks:watch 10 300 50 4` | 4 workers parallèles |
| `./bin/task fixture:register-tasks` | Créer 1 tâche de chaque type |
| `./bin/task fixture:register-tasks 10 5` | Créer 10 uniques + 5 récurrentes |

### Codes de sortie

| Code | Signification |
|------|---------------|
| `0` | SUCCESS |
| `1` | FAILURE |
| `2` | INVALID_ARGUMENT |
| `3` | RUNTIME_ERROR |

---

## Licence

MIT © [Andy Defer](https://github.com/andydefer)