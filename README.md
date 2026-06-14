# Laravel Task

**Un système de tâches asynchrones et récurrentes pour Laravel, basé sur des fichiers JSONL.**

[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-blue)](https://php.net)
[![Laravel Version](https://img.shields.io/badge/Laravel-12.x%20%7C%2013.x%20%7C%2014.x%20%7C%2015.x-blue)](https://laravel.com)
[![License](https://img.shields.io/badge/License-MIT-green)](LICENSE)

---

## Table des matières

1. [Introduction](#introduction)
2. [Installation](#installation)
3. [Configuration](#configuration)
4. [Concepts fondamentaux](#concepts-fondamentaux)
5. [Créer votre première tâche](#créer-votre-première-tâche)
6. [Le payload : passer des paramètres](#le-payload--passer-des-paramètres)
7. [Types de tâches](#types-de-tâches)
8. [Période de grâce (Grace Period)](#période-de-grâce-grace-period)
9. [Traitement par lots](#traitement-par-lots)
10. [Traitement des erreurs et réessais](#traitement-des-erreurs-et-réessais)
11. [Logging structuré](#logging-structuré)
12. [Tests](#tests)
13. [Architecture technique](#architecture-technique)
14. [Licence](#licence)

---

## Introduction

### Le problème

Laravel propose des solutions pour les tâches asynchrones, mais chacune a ses limites :

| Solution | Problème |
|----------|----------|
| **Queues** | Nécessitent Redis/Beanstalkd/Database, configuration lourde |
| **Task Scheduling** | Exécution via cron, pas de gestion des échecs intégrée |
| **Jobs** | Lourds, difficilement testables unitairement |

### La solution : Laravel Task

**Laravel Task** est un système de tâches asynchrones et récurrentes basé sur des fichiers **JSONL** (JSON Lines).

| Problème | Solution Laravel Task |
|----------|----------------------|
| Dépendance à Redis/Beanstalkd | Stockage JSONL - pas de base de données |
| Configuration complexe | Zéro configuration, prêt à l'emploi |
| Tests difficiles | Testable unitairement (pas de queue mock) |
| Pas de récurrence native | `delay_seconds` pour les tâches récurrentes |
| Pas de gestion des échecs | Retry automatique avec `max_attempts` |
| Logs non structurés | Logging via `laravel-logger` |

---

## Installation

```bash
composer require andydefer/laravel-task
```

Le package s'enregistre automatiquement via Laravel.

### Publication de la configuration (optionnel)

```bash
php artisan vendor:publish --tag=task-config
```

---

## Configuration

```php
// config/task.php
return [
    // Chemin de stockage des tâches
    'storage_path' => env('TASK_STORAGE_PATH', storage_path('tasks')),

    // Période de grâce
    'grace_period' => [
        'enabled' => env('TASK_GRACE_PERIOD_ENABLED', true),
        'seconds' => env('TASK_GRACE_PERIOD_SECONDS', 86400), // 24 heures
    ],

    // Traitement par lots
    'batch' => [
        'limit' => env('TASK_BATCH_LIMIT', 1000),   // null ou 0 = illimité
        'order' => env('TASK_BATCH_ORDER', 'oldest'), // 'oldest' ou 'newest'
    ],
];
```

### Variables d'environnement

```env
TASK_STORAGE_PATH=/custom/tasks/path
TASK_GRACE_PERIOD_ENABLED=true
TASK_GRACE_PERIOD_SECONDS=86400
TASK_BATCH_LIMIT=500
TASK_BATCH_ORDER=newest
```

---

## Concepts fondamentaux

### Une tâche = un fichier JSONL

```
storage/tasks/
├── pending/                          # Tâches uniques en attente
│   └── {uuid}.jsonl
├── recurring/                        # Tâches récurrentes (une par signature)
│   └── clear-unconfirmed-orders.jsonl
├── completed/                        # Archive par date
│   └── Y-m-d/
│       └── {uuid}.jsonl
└── grace_period/                     # Traces des exécutions tardives
    └── {uuid}.json
```

| Dossier | Format | Cycle de vie |
|---------|--------|--------------|
| **pending/** | JSONL | Création → Exécution → Archivage |
| **recurring/** | JSONL | Création → Exécution → Mise à jour (append) |
| **completed/** | JSONL | Archive historique pour audit |
| **grace_period/** | JSON | Traces des exécutions tardives |

### Structure d'une tâche (TaskRecord)

```json
{
    "id": "550e8400-e29b-41d4-a716-446655440000",
    "signature": "clear-unconfirmed-orders",
    "class": "App\\Tasks\\ClearUnconfirmedOrdersTask",
    "payload": {
        "type": "clear_orders",
        "data": {
            "minutes": 30,
            "force": false
        }
    },
    "status": "pending",
    "created_at": "2026-05-24T10:00:00+00:00",
    "start_at": "2026-05-24T10:00:00+00:00",
    "end_at": null,
    "delay_seconds": 0,
    "attempts": 0,
    "max_attempts": 3,
    "last_error": null,
    "enforce_exact_schedule": false
}
```

### Structure d'une tâche récurrente (RecurringTaskRecord)

```json
{
    "signature": "clean-logs",
    "class": "App\\Tasks\\CleanLogsTask",
    "payload": {
        "type": "clean",
        "data": {
            "days": 30,
            "backup": true
        }
    },
    "start_at": "2026-05-24T10:00:00+00:00",
    "end_at": null,
    "delay_seconds": 3600,
    "last_run_at": "2026-05-24T11:00:00+00:00",
    "next_run_at": "2026-05-24T12:00:00+00:00",
    "success_count": 42,
    "failure_count": 3,
    "last_error": null
}
```

### Value Objects

Le package utilise des **Value Objects** pour un typage fort et sécurisé :

| Value Object | Description | Validation |
|--------------|-------------|------------|
| `TaskIdVO` | Identifiant unique de tâche | Format UUID v4 |
| `TaskSignatureVO` | Signature lisible de la tâche | Minuscules avec traits d'union |
| `CounterVO` | Compteur (attempts, max_attempts, etc.) | Non négatif, avec incrémentation |
| `UnixTimestampVO` | Timestamp Unix | Comparaisons `isAfter()`/`isBefore()` |
| `Iso8601DateTimeVO` | Date ISO 8601 | Format `Y-m-d\TH:i:sP` |
| `TaskDirectoryVO` | Chemin de dossier | Construction sécurisée des chemins |

---

## Créer votre première tâche

### 1. Créer la classe de la tâche

```php
<?php

// app/Tasks/ClearUnconfirmedOrdersTask.php

declare(strict_types=1);

namespace App\Tasks;

use AndyDefer\Task\AbstractTask;
use AndyDefer\Task\Records\TaskConfigRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use App\Models\Order;

final class ClearUnconfirmedOrdersTask extends AbstractTask
{
    public function getConfig(): TaskConfigRecord
    {
        return new TaskConfigRecord(
            signature: new TaskSignatureVO('clear-unconfirmed-orders'),
            description: 'Clear orders not confirmed after N minutes',
            delay_seconds: new CounterVO(300),  // Toutes les 5 minutes
            max_attempts: new CounterVO(3),
            start_at: null,                     // Maintenant
            end_at: null,                       // Jamais (récurrente)
        );
    }

    protected function process(): void
    {
        // Récupérer les paramètres du payload
        $data = $this->context->getPayload()->data;
        $minutes = $data->minutes ?? 30;
        $force = $data->force ?? false;
        
        // Logique métier
        $query = Order::where('status', 'pending')
            ->where('created_at', '<', now()->subMinutes($minutes));
        
        if ($force) {
            $deleted = $query->forceDelete();
        } else {
            $deleted = $query->delete();
        }
        
        $this->info("Deleted {$deleted} unconfirmed orders");
    }
}
```

### 2. Enregistrer la tâche

Vous pouvez enregistrer une tâche depuis n'importe où (commande, contrôleur, événement) :

```php
<?php

namespace App\Console\Commands;

use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Services\TaskRegistryService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use App\Tasks\ClearUnconfirmedOrdersTask;
use Illuminate\Console\Command;

final class ScheduleTaskCommand extends Command
{
    protected $signature = 'task:schedule';
    protected $description = 'Schedule the clear unconfirmed orders task';

    public function __construct(
        private readonly TaskRegistryService $registry,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // Créer le payload avec un objet StrictDataObject
        $payload = new TaskPayloadRecord(
            type: 'clear_orders',
            data: StrictDataObject::from([
                'minutes' => 30,
                'force' => false,
            ]),
        );

        // Enregistrer comme tâche récurrente (delay_seconds > 0)
        $signature = $this->registry->register(
            taskClass: ClearUnconfirmedOrdersTask::class,
            payload: $payload,
        );
        
        $this->info("Task registered with signature: {$signature}");
        
        return 0;
    }
}
```

### 3. Exécuter le traitement par lots

```bash
# Exécuter toutes les tâches en attente
./vendor/bin/directive process-tasks

# Exécuter jusqu'à 50 tâches
./vendor/bin/directive process-tasks --limit=50

# Exécuter uniquement les tâches uniques
./vendor/bin/directive process-tasks --unique-only --limit=20

# Exécuter uniquement les tâches récurrentes
./vendor/bin/directive process-tasks --recurring-only --limit=10

# Avec affichage détaillé des erreurs
./vendor/bin/directive process-tasks --verbose
```

### 4. Automatiser le traitement (Cron)

Ajoutez ceci à votre `crontab` pour exécuter toutes les minutes :

```bash
* * * * * cd /path/to/project && ./vendor/bin/directive process-tasks --limit=50 >> /dev/null 2>&1
```

---

## Le payload : passer des paramètres

### Qu'est-ce qu'un payload ?

Le payload est une structure typée qui transporte les paramètres de la tâche. Il se compose de :
- un `type` (string) : identifie le type de payload
- un `data` (`StrictDataObject`) : les données proprement dites

```php
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\DomainStructures\Utils\StrictDataObject;

$payload = new TaskPayloadRecord(
    type: 'clear_orders',
    data: StrictDataObject::from([
        'minutes' => 30,
        'force' => true,
        'notify' => 'admin@example.com',
    ]),
);
```

### Accéder aux paramètres dans la tâche

```php
protected function process(): void
{
    $data = $this->context->getPayload()->data;
    
    $minutes = $data->minutes ?? 30;
    $force = $data->force ?? false;
    $notify = $data->notify ?? null;
    
    $this->info("Clearing orders older than {$minutes} minutes");
    
    if ($force) {
        $this->info("Force delete enabled");
    }
    
    if ($notify) {
        $this->info("Will notify: {$notify}");
    }
}
```

### Structure imbriquée (optionnel)

Le `StrictDataObject` peut contenir des données imbriquées :

```php
$payload = new TaskPayloadRecord(
    type: 'advanced',
    data: StrictDataObject::from([
        'user' => [
            'id' => 123,
            'name' => 'John Doe',
        ],
        'settings' => [
            'timeout' => 30,
            'retries' => 3,
        ],
    ]),
);

// Accès
$userId = $payload->data->user->id;
$timeout = $payload->data->settings->timeout;
```

---

## Types de tâches

### Tâche unique

S'exécute une seule fois, puis est archivée dans `completed/`.

```php
final class SendWelcomeEmailTask extends AbstractTask
{
    public function getConfig(): TaskConfigRecord
    {
        return new TaskConfigRecord(
            signature: new TaskSignatureVO('send-welcome-email'),
            description: 'Send welcome email to new user',
            delay_seconds: new CounterVO(0),              // Pas de récurrence
            max_attempts: new CounterVO(3),
            end_at: new Iso8601DateTimeVO('2026-05-24T23:59:59+00:00'), // Expire
        );
    }
}
```

**Caractéristiques :**
- `delay_seconds->value === 0`
- `end_at` dans le futur (ou `null`)
- Exécutée une fois puis archivée

### Tâche récurrente

S'exécute à intervalles réguliers. Une seule instance par signature.

```php
final class CleanLogsTask extends AbstractTask
{
    public function getConfig(): TaskConfigRecord
    {
        return new TaskConfigRecord(
            signature: new TaskSignatureVO('clean-logs'),
            description: 'Clean old log files',
            delay_seconds: new CounterVO(3600),   // Toutes les heures
            max_attempts: new CounterVO(3),
            end_at: null,                          // Jamais (récurrente à vie)
        );
    }
}
```

**Caractéristiques :**
- `delay_seconds->value > 0`
- `end_at = null`
- Une seule instance par signature
- Exécutée indéfiniment
- Les statistiques (`success_count`, `failure_count`) sont conservées

---

## Période de grâce (Grace Period)

### Qu'est-ce que c'est ?

La période de grâce permet d'exécuter une tâche unique même si elle a dépassé sa date de fin (`end_at`), dans une limite configurable (par défaut 24 heures).

### Pourquoi ?

Une tâche peut ne pas s'exécuter exactement à l'heure prévue :
- Le processeur de tâches n'a pas été appelé
- Le serveur était en maintenance
- La charge système a retardé l'exécution

Sans période de grâce, ces tâches seraient définitivement perdues.

### Comportement par défaut

| Type de tâche | Période de grâce |
|---------------|------------------|
| Unique (`delay_seconds->value === 0`) | ✅ Activée (24h) |
| Récurrente (`delay_seconds->value > 0`) | ❌ Désactivée |
| Avec `enforce_exact_schedule = true` | ❌ Désactivée |

### Configuration

```php
// config/task.php
'grace_period' => [
    'enabled' => env('TASK_GRACE_PERIOD_ENABLED', true),
    'seconds' => env('TASK_GRACE_PERIOD_SECONDS', 86400),
],
```

### Exemple d'utilisation

```php
// Tâche unique avec période de grâce (par défaut)
$taskId = $registry->register(
    taskClass: SendReportTask::class,
    payload: $payload,
);

// Tâche qui exige une exécution stricte (pas de grâce)
$overrideConfig = new TaskConfigRecord(
    signature: new TaskSignatureVO('critical-task'),
    description: 'Critical task - no grace period',
    delay_seconds: new CounterVO(0),
    max_attempts: new CounterVO(1),
    start_at: null,
    end_at: new Iso8601DateTimeVO('2026-05-24T23:59:59+00:00'),
);

$taskId = $registry->register(
    taskClass: CriticalTask::class,
    payload: $payload,
    override_config: $overrideConfig,
);
```

---

## Traitement par lots (Batch Processing)

### Directive CLI

```bash
# Traiter toutes les tâches (limite configurée par défaut)
./vendor/bin/directive process-tasks

# Traiter jusqu'à 50 tâches
./vendor/bin/directive process-tasks --limit=50

# Uniquement les tâches uniques
./vendor/bin/directive process-tasks --unique-only --limit=20

# Uniquement les tâches récurrentes
./vendor/bin/directive process-tasks --recurring-only --limit=10

# Avec affichage détaillé des erreurs
./vendor/bin/directive process-tasks --verbose --limit=100
```

### Options de la directive

| Option | Description | Défaut |
|--------|-------------|--------|
| `--limit` | Nombre maximum de tâches à traiter | Config `batch.limit` |
| `--unique-only` | Traite uniquement les tâches uniques | `false` |
| `--recurring-only` | Traite uniquement les tâches récurrentes | `false` |
| `--verbose` | Affiche les détails des erreurs | `false` |

### Utilisation programmatique

```php
use AndyDefer\Task\Services\TaskBatchService;

class TaskController
{
    public function process(TaskBatchService $batch): JsonResponse
    {
        // Traitement standard
        $result = $batch->process(50);
        
        // Ou filtrage
        $uniqueOnly = $batch->processUniqueOnly(20);
        $recurringOnly = $batch->processRecurringOnly(10);
        
        return response()->json([
            'unique_success' => $result->unique_success->value,
            'unique_failed' => $result->unique_failed->value,
            'recurring_success' => $result->recurring_success->value,
            'recurring_failed' => $result->recurring_failed->value,
            'unique_errors' => $result->unique_errors->toArray(),
            'recurring_errors' => $result->recurring_errors->toArray(),
        ]);
    }
}
```

### Ordre de traitement

L'ordre de traitement est configurable :

```php
// config/task.php
'batch' => [
    'order' => 'oldest',  // FIFO : le plus ancien d'abord
    // ou 'newest'        // LIFO : le plus récent d'abord
],
```

---

## Traitement des erreurs et réessais

### Configuration des tentatives

```php
public function getConfig(): TaskConfigRecord
{
    return new TaskConfigRecord(
        signature: new TaskSignatureVO('my-task'),
        description: 'My task',
        delay_seconds: new CounterVO(300),
        max_attempts: new CounterVO(5),  // 5 tentatives max
    );
}
```

### Comportement en cas d'échec

```
Tentative 1 → Échec → attempts = 1, réenregistrée
Tentative 2 → Échec → attempts = 2, réenregistrée
Tentative 3 → Échec → attempts = 3, réenregistrée
Tentative 4 → Échec → attempts = 4, réenregistrée
Tentative 5 → Échec → ARCHIVE (FAILED)
```

### Types d'erreur (ErrorType)

L'enum `ErrorType` catégorise les erreurs :

| Type | Description | Terminal |
|------|-------------|----------|
| `INVALID_TASK_CLASS` | Classe de tâche invalide | ✅ |
| `TASK_VALIDATION_FAILED` | Validation échouée (état, expiration, tentatives) | ❌ |
| `TASK_EXECUTION_FAILED` | Erreur pendant l'exécution | ❌ |
| `TASK_EXPIRED` | Tâche expirée | ✅ |
| `MAX_ATTEMPTS_REACHED` | Nombre max de tentatives atteint | ✅ |
| `GRACE_PERIOD_EXPIRED` | Période de grâce expirée | ✅ |
| `RECURRING_NOT_READY` | Tâche récurrente pas prête | ❌ |
| `STORAGE_ERROR` | Erreur de stockage | ❌ |

### Tâche expirée

Si `end_at` est dépassé et qu'il n'y a pas de période de grâce, la tâche est immédiatement archivée sans nouvelle tentative.

---

## Logging structuré

### Logs automatiques

Le package logue automatiquement via `laravel-logger` :

| Événement | Description |
|-----------|-------------|
| `task_started` | Début de l'exécution |
| `task_completed` | Exécution réussie |
| `task_failed` | Exécution échouée |
| `task_output` | Messages `info()` et `error()` |
| `batch_started` | Début du traitement par lots |
| `batch_completed` | Fin du traitement par lots |
| `task_executed_during_grace_period` | Exécution pendant période de grâce |

### Logs personnalisés

```php
protected function process(): void
{
    $this->info("Processing started");
    $this->info("Step 1 complete");
    
    if ($error) {
        $this->error("Something went wrong: " . $error->getMessage());
    }
}
```

### Consulter les logs

```bash
# Afficher les logs d'exécution d'une tâche
grep "clear-unconfirmed-orders" storage/logs/structured/*/*.jsonl

# Afficher les erreurs
grep "task_failed" storage/logs/structured/*/*.jsonl

# Afficher les logs de batch
grep "batch" storage/logs/structured/*/*.jsonl

# Afficher les exécutions pendant période de grâce
grep "grace_period" storage/logs/structured/*/*.jsonl
```

---

## Tests

### Tester une tâche

```php
<?php

namespace Tests\Unit\Tasks;

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Task\Contexts\TaskContext;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use App\Tasks\ClearUnconfirmedOrdersTask;
use Tests\TestCase;
use App\Models\Order;

final class ClearUnconfirmedOrdersTaskTest extends TestCase
{
    private ClearUnconfirmedOrdersTask $task;

    protected function setUp(): void
    {
        parent::setUp();
        
        $logger = $this->createMock(LoggerInterface::class);
        $hydration = new HydrationService();
        
        $context = new TaskContext();
        $context->setTaskId(new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'));
        $context->setSignature(new TaskSignatureVO('clear-unconfirmed-orders'));
        $context->setLaravelApp(app());
        
        $this->task = new ClearUnconfirmedOrdersTask($context, $logger, $hydration);
    }

    public function test_execute_deletes_unconfirmed_orders(): void
    {
        // Arrange
        Order::create([
            'status' => 'pending',
            'created_at' => now()->subMinutes(40),
        ]);
        
        $payload = new TaskPayloadRecord(
            type: 'clear_orders',
            data: StrictDataObject::from([
                'minutes' => 30,
            ]),
        );
        
        // Act
        $this->task->execute($payload);
        
        // Assert
        $this->assertDatabaseCount('orders', 0);
    }
}
```

### Tester le TaskBatchService

```php
use AndyDefer\Task\Services\TaskBatchService;

public function test_process_returns_batch_result(): void
{
    $result = $this->batch->process(10);
    
    $this->assertIsInt($result->unique_success->value);
    $this->assertIsInt($result->unique_failed->value);
    $this->assertInstanceOf(TaskErrorCollection::class, $result->unique_errors);
}
```

---

## Architecture technique

### Composants principaux

| Composant | Rôle |
|-----------|------|
| `AbstractTask` | Classe de base avec template method (`before()`, `process()`, `after()`) |
| `TaskContext` | Contexte d'exécution (payload, taskId, signature, app Laravel) |
| `TaskStorageContext` | Contexte de stockage (chemins des dossiers pending/recurring/completed) |
| `TaskRepositoryInterface` | Interface pour le CRUD des tâches uniques |
| `RecurringTaskRepositoryInterface` | Interface pour le CRUD des tâches récurrentes |
| `TaskRunnerService` | Exécution des tâches et gestion des tentatives |
| `TaskValidatorService` | Validation (dates, statuts, classes, période de grâce) |
| `TaskRegistryService` | Enregistrement des nouvelles tâches |
| `TaskBatchService` | Traitement par lots (orchestration) |
| `BatchResultService` | Construction immuable des résultats de batch |
| `ProcessTasksDirective` | Directive CLI pour le traitement par lots |

### Dépendances

```
TaskBatchService
    ├── TaskRepositoryInterface
    ├── RecurringTaskRepositoryInterface
    ├── TaskRunnerService
    │       ├── TaskRepositoryInterface
    │       ├── RecurringTaskRepositoryInterface
    │       ├── TaskValidatorService
    │       └── HydrationService
    ├── TaskValidatorService
    │       ├── TaskConfigInterface
    │       └── HydrationService
    ├── BatchResultService
    └── LoggerInterface
```

### Flux d'exécution

```
1. Enregistrement
   TaskRegistryService → Repository::save() → Fichier JSONL

2. Traitement par lots
   ProcessTasksDirective → TaskBatchService
       ├── TaskRepository::findAll() → Fichiers pending/*.jsonl
       └── RecurringTaskRepository::findAll() → Fichiers recurring/*.jsonl

3. Exécution
   TaskRunnerService → AbstractTask::execute()
       ├── before() hook
       ├── process() hook (logique métier)
       ├── after() hook
       └── Logs → LoggerInterface

4. Mise à jour
   - Tâche unique : Repository::moveToCompleted() → completed/{date}/{id}.jsonl
   - Tâche récurrente : Repository::updateAfterRun() → Append au fichier JSONL
```

---

## Licence

MIT © [Andy Defer](https://github.com/andydefer)