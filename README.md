# Laravel Task

**Un moteur de tâches persistantes pour Laravel. Planification dynamique, exécution récurrente, état, retry, pause, reprise - avec un simple cron.**

[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-blue)](https://php.net)
[![Laravel Version](https://img.shields.io/badge/Laravel-12.x%20%7C%2013.x%20%7C%2014.x%20%7C%2015.x-blue)](https://laravel.com)
[![License](https://img.shields.io/badge/License-MIT-green)](LICENSE)

---

## Table des matières

1. [Installation](#installation)
2. [Pourquoi Laravel Task ?](#pourquoi-laravel-task-)
3. [Créer une tâche unique](#créer-une-tâche-unique)
4. [Créer une tâche récurrente](#créer-une-tâche-récurrente)
5. [Exécuter les tâches](#exécuter-les-tâches)
6. [Surveillance continue](#surveillance-continue)
7. [Exécution parallèle](#exécution-parallèle)
8. [Gestion des tâches](#gestion-des-tâches)
9. [Mode test](#mode-test)
10. [Cas d'usage concrets](#cas-dusage-concrets)
11. [Bonnes pratiques](#bonnes-pratiques)
12. [Référence des commandes](#référence-des-commandes)

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
* * * * * cd /chemin/projet && ./vendor/bin/directive tasks-watch
```

### Comparatif rapide

| Besoin | Scheduler | Queue | Laravel Task |
|--------|-----------|-------|--------------|
| Tâche "dans 5 minutes" | ❌ | ✅ | ✅ |
| Tâche récurrente avec date de fin | ❌ | ❌ | ✅ |
| Pause / Reprise | ❌ | ❌ | ✅ |
| Retry automatique | ❌ | ✅ | ✅ |
| État et historique | ❌ | ❌ | ✅ |
| Fonctionne sur hébergement SHARED | ✅ | ❌ | ✅ |

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

### 2. Enregistrer la tâche

```php
<?php

namespace App\Http\Controllers;

use AndyDefer\Task\Contracts\Services\UniqueTaskServiceInterface;
use AndyDefer\Task\Records\UniqueTaskConfigRecord;
use AndyDefer\Task\ValueObjects\UniqueTaskFqcnVO;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;

class UserController extends Controller
{
    public function __construct(
        private readonly UniqueTaskServiceInterface $taskService
    ) {}

    public function store(Request $request)
    {
        // Création de l'utilisateur...
        
        // ✅ Enregistrement de la tâche
        $config = UniqueTaskConfigRecord::from([
            'scheduled_at' => new Iso8601DateTimeVO(now()->addMinutes(5)),
            'max_attempts' => 3,        // 3 tentatives max
            'grace_period' => 3600,      // 1h pour réessayer
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
            'interval_seconds' => 3600,               // Toutes les heures
            'start_at' => now()->toIso8601String(),
            'end_at' => now()->addDays(30)->toIso8601String(),
            'max_attempts' => 3,
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

### 3. Gérer la tâche en cours d'exécution

```php
<?php

namespace App\Services;

use AndyDefer\Task\Contracts\Services\RecurringTaskServiceInterface;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\DurationVO;

class TaskManager
{
    public function __construct(
        private readonly RecurringTaskServiceInterface $taskService
    ) {}

    // ✅ Mettre en pause une tâche récurrente
    public function pause(string $alias): void
    {
        $this->taskService->pause(new TaskAliasVO($alias));
        // La tâche ne sera plus exécutée jusqu'à reprise
    }

    // ✅ Reprendre une tâche mise en pause
    public function resume(string $alias): void
    {
        $this->taskService->resume(new TaskAliasVO($alias));
    }

    // ✅ Changer l'intervalle d'exécution
    public function changeInterval(string $alias, int $seconds): void
    {
        $this->taskService->changeInterval(
            new TaskAliasVO($alias),
            new DurationVO($seconds)
        );
    }

    // ✅ Terminer prématurément une tâche récurrente
    public function finish(string $alias): void
    {
        $this->taskService->finish(new TaskAliasVO($alias));
        // La tâche passe en état FINISHED et ne sera plus exécutée
    }
}
```

---

## Exécuter les tâches

### Une seule fois (process-tasks)

```bash
# Traiter toutes les tâches
./vendor/bin/directive process-tasks

# Uniquement les tâches uniques
./vendor/bin/directive process-tasks --unique-only

# Uniquement les tâches récurrentes
./vendor/bin/directive process-tasks --recurring-only

# Limiter à 10 tâches
./vendor/bin/directive process-tasks --limit=10

# Sortie JSON (idéal pour les scripts)
./vendor/bin/directive process-tasks --format=json

# Mode verbeux (voir les erreurs)
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
  Recurring tasks:
    ❌ recurring@def-456: Connection timeout (attempts: 2/3)
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
        "description": "Connection timeout",
        "context": "attempts: 2/3"
      }
    ]
  }
}
```

---

## Surveillance continue

La directive `tasks-watch` exécute `process-tasks` en boucle.

```bash
# Toutes les 60 secondes (illimité)
./vendor/bin/directive tasks-watch

# Pendant 1 heure, toutes les 30 secondes
./vendor/bin/directive tasks-watch --duration=3600 --interval=30

# Uniquement les tâches uniques, limité à 10
./vendor/bin/directive tasks-watch --unique-only --limit=10

# Avec sortie détaillée
./vendor/bin/directive tasks-watch --verbose
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

================================================================================
📊 Summary
Cycles executed    : 10
Total success      : 42
Total failures     : 8
Total errors       : 3
Total duration     : 10m 30s
================================================================================
```

**Arrêt gracieux :** Appuyez sur `Ctrl+C`. La tâche en cours finit son exécution, puis le système s'arrête proprement.

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

### États

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
<?php

namespace App\Services;

use AndyDefer\Task\Contracts\Services\UniqueTaskServiceInterface;
use AndyDefer\Task\Contracts\Services\RecurringTaskServiceInterface;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;

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
    public function reschedule(string $alias, Carbon $newDate): void
    {
        $this->uniqueService->reschedule(
            new TaskAliasVO($alias),
            new Iso8601DateTimeVO($newDate->toIso8601String())
        );
    }

    // ✅ Prolonger la période de grâce (pour les retry)
    public function extendGracePeriod(string $alias, int $seconds): void
    {
        $this->uniqueService->extendGracePeriod(
            new TaskAliasVO($alias),
            new DurationVO($seconds)
        );
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
    public function changeInterval(string $alias, int $seconds): void
    {
        $this->recurringService->changeInterval(
            new TaskAliasVO($alias),
            new DurationVO($seconds)
        );
    }

    // ✅ Terminer définitivement
    public function finish(string $alias): void
    {
        $this->recurringService->finish(new TaskAliasVO($alias));
    }

    // === INSPECTION ===

    // ✅ Obtenir le debug d'une tâche
    public function getDebug(string $alias): array
    {
        return $this->uniqueService->getDebug(new TaskAliasVO($alias));
        // Retourne l'historique complet des exécutions
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

## Cas d'usage concrets

### 1. SaaS et abonnements

```php
// ✅ Envoyer un rappel J-1 avant expiration
$taskService->register(RenewalReminderTask::class, $payload, [
    'scheduled_at' => $user->subscription_end_at->subDay(),
    'max_attempts' => 2,
]);

// ✅ Désactiver l'abonnement à la date d'expiration
$taskService->register(ExpireSubscriptionTask::class, $payload, [
    'scheduled_at' => $user->subscription_end_at,
    'max_attempts' => 3,
]);

// ✅ Relance de paiement échoué (3 tentatives)
$taskService->register(PaymentRetryTask::class, $payload, [
    'scheduled_at' => now()->addHours(24),
    'max_attempts' => 3,
]);
```

### 2. E-commerce et paniers abandonnés

```php
// ✅ Email de relance 30 min après abandon
$taskService->register(AbandonedCartReminder::class, $payload, [
    'scheduled_at' => now()->addMinutes(30),
    'max_attempts' => 2,
]);

// ✅ Email de suivi J+3
$taskService->register(FollowUpEmail::class, $payload, [
    'scheduled_at' => now()->addDays(3),
    'max_attempts' => 2,
]);

// ✅ Email de suivi J+7 avec offre spéciale
$taskService->register(SpecialOfferTask::class, $payload, [
    'scheduled_at' => now()->addDays(7),
    'max_attempts' => 2,
]);

// ✅ Si le panier est récupéré, annuler les rappels
$taskService->cancel($reminderAlias, new DescriptionVO('Cart recovered'));
```

### 3. Intégrations API et Webhooks

```php
// ✅ Appel API avec retry automatique
$taskService->register(ApiCallTask::class, $payload, [
    'scheduled_at' => now()->addSeconds(5),
    'max_attempts' => 3,
    'grace_period' => 3600, // 1h pour réessayer
]);

// ✅ Visualisation des tentatives
$debug = $taskService->getDebug($alias);
// [
//   { attempt: 1, status: 'failed', error: 'Timeout' },
//   { attempt: 2, status: 'failed', error: 'Rate limit' },
//   { attempt: 3, status: 'succeeded', duration: 1200 },
// ]

// ✅ Envoi d'un webhook externe
$taskService->register(SendWebhookTask::class, $payload, [
    'scheduled_at' => now()->addSeconds(10),
    'max_attempts' => 5,
    'grace_period' => 7200, // 2h
]);
```

### 4. Campagnes marketing temporaires

```php
// ✅ Newsletter hebdomadaire sur 4 semaines
$campaign = $taskService->register(NewsletterTask::class, $payload, [
    'interval_seconds' => 604800, // 7 jours
    'end_at' => now()->addWeeks(4),
    'max_attempts' => 2,
]);

// ✅ Pause si désabonnement
$taskService->pause($campaign);

// ✅ Reprise si réabonnement
$taskService->resume($campaign);

// ✅ Campagne de relance sur 3 jours
$taskService->register(DailyReminderTask::class, $payload, [
    'interval_seconds' => 86400, // 1 jour
    'end_at' => now()->addDays(3),
    'max_attempts' => 2,
]);
```

### 5. Maintenance et nettoyage

```php
// ✅ Nettoyage nocturne uniquement (23h - 6h)
$taskService->register(CacheCleanTask::class, $payload, [
    'interval_seconds' => 3600,
    'start_at' => Carbon::now()->setTime(23, 0),
    'end_at' => Carbon::now()->setTime(6, 0),
]);

// ✅ Archivage des logs toutes les heures
$taskService->register(ArchiveLogsTask::class, $payload, [
    'interval_seconds' => 3600,
    'max_attempts' => 2,
]);

// ✅ Backup de la base de données à 2h du matin
$taskService->register(BackupDatabaseTask::class, $payload, [
    'scheduled_at' => Carbon::now()->setTime(2, 0),
    'max_attempts' => 1,
]);
```

### 6. Workflows métier complexes

```php
// ✅ Orchestrer un workflow en plusieurs étapes
$steps = [
    ValidateOrderTask::class => now()->addSeconds(10),
    ProcessPaymentTask::class => now()->addSeconds(30),
    GenerateInvoiceTask::class => now()->addMinutes(1),
    SendConfirmationTask::class => now()->addMinutes(2),
];

$taskAliases = [];
foreach ($steps as $class => $scheduledAt) {
    $taskAliases[] = $taskService->register($class, $payload, [
        'scheduled_at' => $scheduledAt,
        'max_attempts' => 2,
    ]);
}

// ✅ Si une étape échoue, annuler les suivantes
try {
    // Exécution...
} catch (\Exception $e) {
    foreach ($taskAliases as $alias) {
        $taskService->cancel($alias, new DescriptionVO('Workflow failed'));
    }
}
```

### 7. CRM et gestion des leads

```php
// ✅ Relance automatisée selon le statut du lead
$status = $lead->status;

if ($status === 'cold') {
    // Relance dans 7 jours
    $scheduledAt = now()->addDays(7);
} elseif ($status === 'warm') {
    // Relance dans 2 jours
    $scheduledAt = now()->addDays(2);
} else {
    // Relance dans 24h
    $scheduledAt = now()->addHours(24);
}

$taskService->register(FollowUpLeadTask::class, $payload, [
    'scheduled_at' => $scheduledAt,
    'max_attempts' => 3,
]);

// ✅ Envoi d'un sondage après 15 jours
$taskService->register(SendSurveyTask::class, $payload, [
    'scheduled_at' => now()->addDays(15),
    'max_attempts' => 2,
]);
```

### 8. Notifications et alertes

```php
// ✅ Alerte si une tâche critique échoue
$taskService->register(CriticalTask::class, $payload, [
    'scheduled_at' => now()->addSeconds(30),
    'max_attempts' => 3,
]);

// ✅ Dans after() de la tâche
protected function after(bool $success, ?DescriptionVO $error = null): void
{
    if (!$success) {
        // ✅ Notifier l'équipe
        Notification::send($admins, new TaskFailedNotification($this->context));
    }
}

// ✅ Envoyer un rapport quotidien
$taskService->register(SendDailyReportTask::class, $payload, [
    'scheduled_at' => Carbon::now()->setTime(8, 0),
    'max_attempts' => 2,
]);
```

### 9. Systèmes de files d'attente personnalisées

```php
// ✅ Traitement par lots avec retry
$batchId = Uuid::uuid4();

foreach ($items as $item) {
    $taskService->register(ProcessItemTask::class, [
        'batch_id' => $batchId,
        'item_id' => $item->id,
        'data' => $item->toArray(),
    ], [
        'scheduled_at' => now()->addSeconds(5),
        'max_attempts' => 3,
        'grace_period' => 3600,
    ]);
}

// ✅ Suivi de l'avancement du batch
$remaining = $taskService->countPending();
$completed = $taskService->countCompleted();
```

### 10. Automatisation des processus métier

```php
// ✅ Génération de factures récurrentes
$taskService->register(GenerateInvoicesTask::class, $payload, [
    'scheduled_at' => Carbon::now()->setTime(0, 0), // Minuit
    'interval_seconds' => 86400, // Tous les jours
    'end_at' => now()->addMonths(12),
    'max_attempts' => 2,
]);

// ✅ Synchronisation avec un ERP externe
$taskService->register(SyncErpTask::class, $payload, [
    'interval_seconds' => 3600, // Toutes les heures
    'max_attempts' => 3,
]);

// ✅ Calcul des commissions des vendeurs
$taskService->register(CalculateCommissionsTask::class, $payload, [
    'scheduled_at' => Carbon::now()->setTime(23, 59), // Fin de journée
    'max_attempts' => 2,
]);
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

// ÉVITER (facade)
use AndyDefer\Task\Facades\Task;
Task::register(...);
```

### ✅ Validation dans before()

```php
protected function before(StrictDataObject $payload): void
{
    if (!$payload->has('user_id')) {
        throw new InvalidArgumentException('user_id is required');
    }
}
```

### ✅ Logging informatif

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

### ✅ Structure des payloads

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
./vendor/bin/directive process-tasks --limit=100
./vendor/bin/directive tasks-watch --limit=50 --interval=30
```

### ✅ Monitorer les exécutions

```bash
# Mode verbeux pour le débogage
./vendor/bin/directive tasks-watch --verbose

# Sortie JSON pour l'intégration
./vendor/bin/directive process-tasks --format=json
```

### ✅ Gérer les erreurs dans after()

```php
protected function after(bool $success, ?DescriptionVO $error = null): void
{
    if (!$success) {
        // ✅ Envoyer une notification
        // ✅ Logger l'erreur
        // ✅ Incrémenter un compteur
        // ✅ Alerter l'équipe
        
        $this->error(new DescriptionVO("Task failed after {$this->context->getAttempts()} attempts"));
    }
}
```

---

## Référence des commandes

| Commande | Description |
|----------|-------------|
| `./vendor/bin/directive process-tasks` | Traite toutes les tâches en un lot |
| `./vendor/bin/directive process-tasks --unique-only` | Uniquement les uniques |
| `./vendor/bin/directive process-tasks --recurring-only` | Uniquement les récurrentes |
| `./vendor/bin/directive process-tasks --limit=10` | Limite à 10 tâches |
| `./vendor/bin/directive process-tasks --format=json` | Sortie JSON |
| `./vendor/bin/directive process-tasks --verbose` | Affiche les erreurs |
| `./vendor/bin/directive tasks-watch` | Surveillance continue |
| `./vendor/bin/directive tasks-watch --duration=3600` | Pendant 1 heure |
| `./vendor/bin/directive tasks-watch --interval=30` | Toutes les 30s |
| `./vendor/bin/directive tasks-watch --parallel=3` | 3 workers parallèles |
| `./vendor/bin/directive tasks-watch --testing` | Mode test |

### Codes de sortie

| Code | Signification |
|------|---------------|
| `0` | SUCCESS - Toutes les tâches ont réussi |
| `1` | FAILURE - Au moins une tâche a échoué |
| `2` | INVALID_ARGUMENT - Options invalides |

---

## Licence

MIT © [Andy Defer](https://github.com/andydefer)