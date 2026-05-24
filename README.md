# Laravel Task

**A lightweight, file-based task system for Laravel with async execution, recurring tasks, and JSONL storage.**

[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-blue)](https://php.net)
[![Laravel Version](https://img.shields.io/badge/Laravel-12.x%20%7C%2013.x%20%7C%2014.x%20%7C%2015.x-blue)](https://laravel.com)
[![License](https://img.shields.io/badge/License-MIT-green)](LICENSE)

---

## Table des matières

- [Introduction](#introduction)
- [Installation](#installation)
- [Configuration](#configuration)
- [Concepts fondamentaux](#concepts-fondamentaux)
- [Créer votre première tâche](#créer-votre-première-tâche)
- [Le cycle de vie d'une tâche](#le-cycle-de-vie-dune-tâche)
- [Types de tâches](#types-de-tâches)
- [Le payload : passer des paramètres typés](#le-payload--passer-des-paramètres-typés)
- [Enregistrer une tâche](#enregistrer-une-tâche)
- [Exécuter les tâches (Poller)](#exécuter-les-tâches-poller)
- [Traitement des erreurs et réessais](#traitement-des-erreurs-et-réessais)
- [Logging structuré](#logging-structuré)
- [Tests unitaires](#tests-unitaires)
- [Architecture technique](#architecture-technique)
- [API Reference](#api-reference)
- [Bonnes pratiques](#bonnes-pratiques)
- [FAQ](#faq)
- [Licence](#licence)

---

## Introduction

### Le problème

Laravel propose des solutions pour les tâches asynchrones :
- **Queues** : Nécessitent Redis/Beanstalkd/Database, configuration lourde
- **Task Scheduling** : Exécution via cron, pas de gestion des échecs intégrée
- **Jobs** : Lourds, difficilement testables unitairement

### La solution : Laravel Task

**Laravel Task** est un système de tâches asynchrones et récurrentes basé sur des fichiers JSONL.

| Problème | Solution Laravel Task |
|----------|----------------------|
| Dépendance à Redis/Beanstalkd | Stockage JSONL - pas de base de données |
| Configuration complexe | Zéro configuration, prêt à l'emploi |
| Tests difficiles | Testable unitairement (pas de queue mock) |
| Pas de récurrence native | `delaySeconds` pour les tâches récurrentes |
| Pas de gestion des échecs | Retry automatique avec `maxAttempts` |
| Logs non structurés | Logging via `laravel-logger` |

---

## Installation

```bash
composer require andydefer/laravel-task
```

Le package s'enregistre automatiquement via Laravel.

### Prérequis

- PHP 8.1 ou supérieur
- Laravel 12.x, 13.x, 14.x ou 15.x
- Dépendances automatiques :
  - `andydefer/php-records` (structures typées)
  - `andydefer/laravel-directive` (CLI)
  - `andydefer/laravel-logger` (logging structuré)
  - `ramsey/uuid` (identifiants uniques)

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
    'storage_path' => env('TASKS_STORAGE_PATH', storage_path('tasks')),

    // Valeurs par défaut
    'defaults' => [
        'max_attempts' => 3,      // Nombre de tentatives avant échec
        'delay_seconds' => 300,   // 5 minutes par défaut
    ],

    // Configuration du poller
    'poller' => [
        'default_duration' => 60,      // Durée par défaut (secondes)
        'graceful_timeout' => 30,      // Attente max avant kill
    ],
];
```

### Variables d'environnement

```env
TASKS_STORAGE_PATH=/custom/tasks/path
```

---

## Concepts fondamentaux

### Une tâche = un fichier JSONL

```
storage/tasks/
├── pending/          # Tâches uniques en attente
│   └── {uuid}.json
├── recurring/        # Tâches récurrentes (une par signature)
│   └── clear-unconfirmed-orders.json
└── completed/        # Archive par date
    └── 2026-05-24/
        └── {uuid}.json
```

| Dossier | Contenu | Cycle de vie |
|---------|---------|--------------|
| **pending/** | Tâches uniques | Création → Exécution → Archivage |
| **recurring/** | Tâches récurrentes | Création → Exécution → Mise à jour |
| **completed/** | Archive historique | Conservation pour audit |

### Structure d'une tâche

```json
{
    "id": "550e8400-e29b-41d4-a716-446655440000",
    "signature": "clear-unconfirmed-orders",
    "class": "App\\Tasks\\ClearUnconfirmedOrdersTask",
    "payload": {
        "type": "clear_unconfirmed_orders",
        "payload": ["minutes", 30]
    },
    "mode": "defer",
    "status": "pending",
    "created_at": "2026-05-24T10:00:00Z",
    "start_at": "2026-05-24T10:00:00Z",
    "end_at": "2030-01-01T00:00:00Z",
    "delay_seconds": 300,
    "attempts": 0,
    "max_attempts": 3,
    "last_error": null
}
```

### Champs clés

| Champ | Description |
|-------|-------------|
| `id` | Identifiant unique (UUID) |
| `signature` | Identifiant lisible (ex: `clear-unconfirmed-orders`) |
| `class` | Classe PHP de la tâche |
| `payload` | Données typées de la tâche |
| `mode` | `sync` (immédiat) ou `defer` (asynchrone) |
| `status` | `pending`, `running`, `success`, `failed` |
| `start_at` | Date de début de validité |
| `end_at` | Date de fin (passée = tâche terminée) |
| `delay_seconds` | Délai entre deux exécutions (pour récurrence) |
| `attempts` | Nombre de tentatives effectuées |
| `max_attempts` | Nombre max de tentatives |
| `last_error` | Dernière erreur rencontrée |

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
use App\Models\Order;

final class ClearUnconfirmedOrdersTask extends AbstractTask
{
    public function getConfig(): TaskConfigRecord
    {
        return new TaskConfigRecord(
            signature: 'clear-unconfirmed-orders',
            description: 'Clear orders not confirmed after 30 minutes',
            delaySeconds: 300,  // Toutes les 5 minutes
            maxAttempts: 3,
            endAt: null,        // null = récurrente jusqu'à suppression
        );
    }

    protected function process(): void
    {
        // Récupérer les paramètres du payload
        $minutes = $this->payload->get('minutes') ?? 30;
        
        // Logique métier
        $deleted = Order::where('status', 'pending')
            ->where('created_at', '<', now()->subMinutes($minutes))
            ->delete();
        
        $this->info("Deleted {$deleted} unconfirmed orders");
    }
}
```

### 2. Enregistrer la tâche

```php
<?php

namespace App\Console\Commands;

use AndyDefer\Task\Enums\TaskMode;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Services\TaskRegistry;
use App\Tasks\ClearUnconfirmedOrdersTask;

class ScheduleTaskCommand extends Command
{
    public function __construct(
        private readonly TaskRegistry $registry,
    ) {}

    public function handle(): void
    {
        $payload = new TaskPayloadRecord(
            type: 'clear_unconfirmed_orders',
            payload: (new MixedPayloadCollection())->add('minutes', 30),
        );

        $this->registry->register(
            taskClass: ClearUnconfirmedOrdersTask::class,
            mode: TaskMode::DEFER,
            payload: $payload,
        );
    }
}
```

### 3. Exécuter le poller

```bash
# Exécuter les tâches pendant 60 secondes
./vendor/bin/directive run-task --duration=60
```

---

## Le cycle de vie d'une tâche

### Template method

`AbstractTask` utilise le pattern **Template Method** pour définir le cycle de vie :

```
execute()
    ├── log('task_started')
    ├── before()      ← Hook optionnel
    ├── process()     ← Logique métier (obligatoire)
    ├── after(true)   ← Hook optionnel
    └── log('task_completed')
```

### Hooks disponibles

```php
use AndyDefer\Task\AbstractTask;

final class MyTask extends AbstractTask
{
    // Avant l'exécution - initialisation, vérifications
    protected function before(): void
    {
        if (!$this->hasLaravel()) {
            $this->error('Laravel is not available!');
            throw new \RuntimeException('Laravel required');
        }
    }
    
    // Logique métier (obligatoire)
    protected function process(): void
    {
        // Votre code ici
    }
    
    // Après l'exécution - nettoyage, notifications
    protected function after(bool $success, ?string $error = null): void
    {
        if ($success) {
            $this->info('Task completed successfully');
        } else {
            $this->error("Task failed: {$error}");
        }
    }
}
```

### Accès à Laravel

Les tâches peuvent accéder à Laravel via `hasLaravel()` et `getLaravel()` :

```php
protected function before(): void
{
    if (!$this->hasLaravel()) {
        $this->error('Laravel is not available!');
        return;
    }
    
    $app = $this->getLaravel();
    $version = $app->version();
    $this->info("Running on Laravel {$version}");
}
```

---

## Types de tâches

### Tâche unique

S'exécute une seule fois, puis est archivée.

```php
final class SendWelcomeEmailTask extends AbstractTask
{
    public function getConfig(): TaskConfigRecord
    {
        return new TaskConfigRecord(
            signature: 'send-welcome-email',
            description: 'Send welcome email to new user',
            delaySeconds: 0,           // Pas de récurrence
            endAt: date('c', strtotime('+1 hour')), // Expire dans 1 heure
        );
    }
}
```

**Caractéristiques :**
- `delaySeconds = 0`
- `endAt` dans le futur
- Exécutée une fois puis archivée dans `completed/`

### Tâche récurrente

S'exécute à intervalles réguliers.

```php
final class CleanLogsTask extends AbstractTask
{
    public function getConfig(): TaskConfigRecord
    {
        return new TaskConfigRecord(
            signature: 'clean-logs',
            description: 'Clean old log files',
            delaySeconds: 3600,   // Toutes les heures
            endAt: null,          // Jamais (récurrente à vie)
        );
    }
}
```

**Caractéristiques :**
- `delaySeconds > 0`
- `endAt = null`
- Une seule instance par signature
- Exécutée indéfiniment

### Tâche avec date de fin

```php
final class PromoTask extends AbstractTask
{
    public function getConfig(): TaskConfigRecord
    {
        return new TaskConfigRecord(
            signature: 'promo-2024',
            description: 'Promo campaign',
            delaySeconds: 86400,   // Une fois par jour
            endAt: '2026-12-31T23:59:59Z', // Arrêt à cette date
        );
    }
}
```

---

## Le payload : passer des paramètres typés

### Qu'est-ce qu'un payload ?

Le payload est une structure typée qui transporte les paramètres de la tâche.

```php
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Logger\Collections\MixedPayloadCollection;

$payload = new TaskPayloadRecord(
    type: 'clear_unconfirmed_orders',
    payload: (new MixedPayloadCollection())->add('minutes', 30, 'force', true),
);
```

### Accéder aux paramètres dans la tâche

```php
protected function process(): void
{
    $minutes = $this->payload->get('minutes') ?? 30;
    $force = $this->payload->get('force') ?? false;
    
    $this->info("Clearing orders older than {$minutes} minutes");
}
```

### Types supportés dans le payload

| Type | Exemple |
|------|---------|
| `int` | `->add('user_id', 123)` |
| `float` | `->add('price', 99.99)` |
| `string` | `->add('name', 'John')` |
| `bool` | `->add('force', true)` |
| `null` | `->add('optional', null)` |
| `Record` | `->add('user', $userRecord)` |
| `TypedCollection` | `->add('tags', $tags)` |

### Exemple avec Record personnalisé

```php
use AndyDefer\Records\AbstractRecord;

final class OrderFilterRecord extends AbstractRecord
{
    public function __construct(
        public readonly ?string $status = null,
        public readonly ?int $minAmount = null,
        public readonly ?int $maxAmount = null,
    ) {}
}

// Création du payload
$filter = new OrderFilterRecord(status: 'pending', minAmount: 100);
$payload = new TaskPayloadRecord(
    type: 'process_orders',
    payload: (new MixedPayloadCollection())->add('filter', $filter),
);

// Dans la tâche
protected function process(): void
{
    $filter = $this->payload->get('filter');
    $orders = Order::where('status', $filter->status)
        ->where('amount', '>=', $filter->minAmount)
        ->get();
}
```

---

## Enregistrer une tâche

### Via le TaskRegistry

```php
use AndyDefer\Task\Enums\TaskMode;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Services\TaskRegistry;
use AndyDefer\Logger\Collections\MixedPayloadCollection;

class TaskScheduler
{
    public function __construct(
        private readonly TaskRegistry $registry,
    ) {}
    
    public function schedule(): void
    {
        $payload = new TaskPayloadRecord(
            type: 'clear_orders',
            payload: (new MixedPayloadCollection())->add('minutes', 30),
        );
        
        $taskId = $this->registry->register(
            taskClass: ClearUnconfirmedOrdersTask::class,
            mode: TaskMode::DEFER,
            payload: $payload,
            startAt: now()->toIso8601ZuluString(),
            endAt: null,
            delaySeconds: 300,
        );
        
        echo "Task registered with ID: {$taskId}\n";
    }
}
```

### Dans une commande Artisan

```php
<?php

namespace App\Console\Commands;

use AndyDefer\Task\Enums\TaskMode;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Services\TaskRegistry;
use AndyDefer\Logger\Collections\MixedPayloadCollection;
use App\Tasks\ClearUnconfirmedOrdersTask;

final class RegisterTaskCommand extends Command
{
    protected $signature = 'task:register {--minutes=30}';
    
    public function handle(TaskRegistry $registry): void
    {
        $minutes = (int) $this->option('minutes');
        
        $payload = new TaskPayloadRecord(
            type: 'clear_orders',
            payload: (new MixedPayloadCollection())->add('minutes', $minutes),
        );
        
        $registry->register(
            taskClass: ClearUnconfirmedOrdersTask::class,
            mode: TaskMode::DEFER,
            payload: $payload,
        );
        
        $this->info("Task registered!");
    }
}
```

### Dans un contrôleur

```php
<?php

namespace App\Http\Controllers\Admin;

use AndyDefer\Task\Enums\TaskMode;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Services\TaskRegistry;
use App\Tasks\GenerateReportTask;

final class ReportController extends Controller
{
    public function generate(ReportRequest $request, TaskRegistry $registry): JsonResponse
    {
        $payload = new TaskPayloadRecord(
            type: 'generate_report',
            payload: (new MixedPayloadCollection())->add(
                'format', $request->format,
                'date_from', $request->date_from,
                'date_to', $request->date_to,
            ),
        );
        
        $taskId = $registry->register(
            taskClass: GenerateReportTask::class,
            mode: TaskMode::DEFER,
            payload: $payload,
        );
        
        return response()->json([
            'message' => 'Report generation started',
            'task_id' => $taskId,
        ]);
    }
}
```

---

## Exécuter les tâches (Poller)

### Commande de base

```bash
# Exécuter pendant 60 secondes (valeur par défaut)
./vendor/bin/directive run-task

# Exécuter pendant 120 secondes
./vendor/bin/directive run-task --duration=120

# Simulation (dry-run) - ne rien exécuter
./vendor/bin/directive run-task --dry-run

# Avec alias
./vendor/bin/directive task-run --duration=60
```

### Fonctionnement du poller

```
┌─────────────────────────────────────────────────────────────────────┐
│                          POLLER CYCLE                               │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  ┌──────────────┐     ┌──────────────────┐     ┌───────────────┐  │
│  │ DÉMARRAGE    │────▶│  SCAN DES TÂCHES │────▶│ FORK + EXEC   │  │
│  │ (duration)   │     │  (pending/)      │     │ (child proc)  │  │
│  └──────────────┘     └──────────────────┘     └───────────────┘  │
│         │                       │                       │          │
│         ▼                       ▼                       ▼          │
│  ┌──────────────┐     ┌──────────────────┐     ┌───────────────┐  │
│  │ TIME LIMIT ? │     │  SCAN RÉCURRENTES│     │ WAIT & CLEAN  │  │
│  │ (duration)   │     │  (recurring/)    │     │ (child proc)  │  │
│  └──────────────┘     └──────────────────┘     └───────────────┘  │
│         │                                                          │
│         ▼                                                          │
│  ┌──────────────┐                                                  │
│  │ FIN (exit)   │                                                  │
│  └──────────────┘                                                  │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

### Gestion du timeout

```php
// Dans ProcessManager
private function canStartNewTask(): bool
{
    if ($this->shuttingDown) {
        return false;
    }
    
    return (time() - $this->startTime) < $this->maxDuration;
}
```

- Ne démarre PAS de nouvelle tâche si le temps est écoulé
- Attend que les tâches en cours se terminent (timeout 30s)
- Force l'arrêt (SIGKILL) après le délai de grâce

### Installation en production

Pour une exécution continue, utilisez **Supervisor** :

```ini
; /etc/supervisor/conf.d/laravel-task.conf
[program:laravel-task]
command=/usr/bin/php /var/www/html/vendor/bin/directive run-task --duration=60
process_name=%(program_name)s_%(process_num)02d
numprocs=1
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/html/storage/logs/task-poller.log
```

Ou avec **cron** (exécution toutes les minutes) :

```cron
* * * * * cd /var/www/html && php vendor/bin/directive run-task --duration=55
```

---

## Traitement des erreurs et réessais

### Configuration des tentatives

```php
public function getConfig(): TaskConfigRecord
{
    return new TaskConfigRecord(
        signature: 'my-task',
        description: 'My task',
        delaySeconds: 300,
        maxAttempts: 5,  // 5 tentatives max
    );
}
```

### Comportement en cas d'échec

```
Tentative 1 → Échec → attempts = 1
Tentative 2 → Échec → attempts = 2
Tentative 3 → Échec → attempts = 3
Tentative 4 → Échec → attempts = 4
Tentative 5 → Échec → ARCHIVE (FAILED)
```

### Gestion personnalisée des erreurs

```php
protected function process(): void
{
    try {
        // Logique métier
    } catch (\Exception $e) {
        $this->logError($e);
        
        if ($this->shouldRetry()) {
            $this->warn("Will retry later...");
            throw $e;  // Déclenche le retry
        }
        
        $this->error("Giving up after {$this->attempts} attempts");
        return;  // Pas de retry
    }
}

private function shouldRetry(): bool
{
    // Logique personnalisée
    return $this->attempts < 3;
}
```

### Expiration des tâches

```php
protected function process(): void
{
    if ($this->isExpired()) {
        $this->error("Task expired, skipping...");
        return;
    }
}

private function isExpired(): bool
{
    $endAt = strtotime($this->endAt ?? '+1 year');
    return time() > $endAt;
}
```

---

## Logging structuré

### Logs automatiques

Le package logue automatiquement :
- `task_started` - Début de l'exécution
- `task_completed` - Exécution réussie
- `task_failed` - Exécution échouée
- `task_output` - Messages `info()` et `error()`

### Format des logs (JSONL)

```json
{"time":"2026-05-24T10:05:00Z","level":"info","data":{"type":"task","payload":["task_started","550e8400-e29b-41d4-a716-446655440000","clear-unconfirmed-orders","defer"]}}

{"time":"2026-05-24T10:05:01Z","level":"info","data":{"type":"task_output","payload":["info","Deleted 42 unconfirmed orders"]}}

{"time":"2026-05-24T10:05:02Z","level":"info","data":{"type":"task","payload":["task_completed","550e8400-e29b-41d4-a716-446655440000","clear-unconfirmed-orders","success"]}}
```

### Logs du poller

```json
{"time":"2026-05-24T10:00:00Z","level":"info","data":{"type":"poller","payload":["poller_started",60,false]}}
{"time":"2026-05-24T10:00:05Z","level":"info","data":{"type":"poller","payload":["waiting_for_tasks",1]}}
{"time":"2026-05-24T10:00:30Z","level":"info","data":{"type":"poller","payload":["poller_finished",30]}}
```

### Logs personnalisés

```php
protected function process(): void
{
    $this->info("Processing started");
    $this->info("Step 1 complete");
    $this->info("Step 2 complete");
    
    if ($error) {
        $this->error("Something went wrong");
    }
}
```

### Consulter les logs

```bash
# Afficher les logs d'exécution d'une tâche
grep "clear-unconfirmed-orders" storage/logs/structured/2026-05-24/*.jsonl

# Afficher les erreurs
grep "task_failed" storage/logs/structured/2026-05-24/*.jsonl

# Afficher les logs du poller
grep "poller" storage/logs/structured/2026-05-24/*.jsonl
```

---

## Tests unitaires

### Tester une tâche

```php
<?php

namespace Tests\Unit\Tasks;

use AndyDefer\Logger\Collections\MixedPayloadCollection;
use AndyDefer\Logger\Logger;
use AndyDefer\Task\Enums\TaskMode;
use AndyDefer\Task\Records\TaskPayloadRecord;
use App\Tasks\ClearUnconfirmedOrdersTask;
use Tests\UnitTestCase;
use App\Models\Order;

final class ClearUnconfirmedOrdersTaskTest extends UnitTestCase
{
    private ClearUnconfirmedOrdersTask $task;

    protected function setUp(): void
    {
        parent::setUp();
        
        $logger = $this->createMock(Logger::class);
        $this->task = new ClearUnconfirmedOrdersTask();
        $this->task->setLogger($logger);
        $this->task->setTaskId('test-123');
        $this->task->setSignature('clear-unconfirmed-orders');
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
            payload: (new MixedPayloadCollection())->add('minutes', 30),
        );
        
        // Act
        $this->task->execute(TaskMode::SYNC, $payload);
        
        // Assert
        $this->assertDatabaseCount('orders', 0);
    }
}
```

### Tester une tâche avec mocks

```php
public function test_execute_logs_success(): void
{
    $logger = $this->createMock(Logger::class);
    $logger->expects($this->once())
        ->method('info')
        ->with($this->callback(fn($record) => 
            $record->type === 'task_output' 
            && $record->payload->contains('info')
        ));
    
    $this->task->setLogger($logger);
    $this->task->execute(TaskMode::SYNC, $payload);
}
```

### Tester le TaskRegistry

```php
use AndyDefer\Task\Services\TaskRegistry;

public function test_register_creates_task(): void
{
    $payload = new TaskPayloadRecord(
        type: 'test',
        payload: new MixedPayloadCollection(),
    );
    
    $taskId = $this->registry->register(
        taskClass: TestTask::class,
        mode: TaskMode::DEFER,
        payload: $payload,
    );
    
    $this->assertIsString($taskId);
    $this->assertTrue(Uuid::isValid($taskId));
}
```

---

## Architecture technique

### Diagramme d'architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           LARAVEL TASK PACKAGE                              │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │                         STORAGE LAYER                                │   │
│  │                                                                      │   │
│  │   storage/tasks/                                                     │   │
│  │   ├── pending/      ← TaskStorage                                    │   │
│  │   ├── recurring/    ← TaskStorage                                    │   │
│  │   └── completed/    ← TaskStorage                                    │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                    │                                       │
│                                    ▼                                       │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │                         BUSINESS LAYER                              │   │
│  │                                                                      │   │
│  │  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐                  │   │
│  │  │ TaskRegistry│  │ TaskRunner  │  │TaskValidator│                  │   │
│  │  │(enregistrer)│  │ (exécuter)  │  │ (valider)   │                  │   │
│  │  └─────────────┘  └─────────────┘  └─────────────┘                  │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                    │                                       │
│                                    ▼                                       │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │                         EXECUTION LAYER                              │   │
│  │                                                                      │   │
│  │  ┌─────────────────────────────────────────────────────────────┐    │   │
│  │  │                    ProcessManager                            │    │   │
│  │  │  - Fork() pour chaque tâche                                  │    │   │
│  │  │  - Gestion des timeouts                                      │    │   │
│  │  │  - Gestion des signaux (SIGTERM, SIGINT)                     │    │   │
│  │  │  - Attente des processus enfants                             │    │   │
│  │  └─────────────────────────────────────────────────────────────┘    │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                    │                                       │
│                                    ▼                                       │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │                         DOMAIN LAYER                                │   │
│  │                                                                      │   │
│  │  ┌─────────────────────────────────────────────────────────────┐    │   │
│  │  │                    AbstractTask                              │    │   │
│  │  │  - Template method: execute()                                │    │   │
│  │  │  - Hooks: before(), after()                                  │    │   │
│  │  │  - Logging automatique                                       │    │   │
│  │  └─────────────────────────────────────────────────────────────┘    │   │
│  │                           │                                          │   │
│  │                           ▼                                          │   │
│  │  ┌─────────────────────────────────────────────────────────────┐    │   │
│  │  │                    Your Tasks                               │    │   │
│  │  │  - process() (votre logique)                                │    │   │
│  │  └─────────────────────────────────────────────────────────────┘    │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                    │                                       │
│                                    ▼                                       │
│  ┌─────────────────────────────────────────────────────────────────────┐   │
│  │                           CLI LAYER                                 │   │
│  │                                                                      │   │
│  │  ┌─────────────────────────────────────────────────────────────┐    │   │
│  │  │                 RunTaskDirective                             │    │   │
│  │  │  - Signature: run-task {--duration} {--dry-run}              │    │   │
│  │  │  - Alias: task-run, tasks:run                                │    │   │
│  │  └─────────────────────────────────────────────────────────────┘    │   │
│  └─────────────────────────────────────────────────────────────────────┘   │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

### Composants

| Composant | Rôle |
|-----------|------|
| `AbstractTask` | Classe de base avec template method et hooks |
| `TaskStorage` | Stockage JSONL (pending/, recurring/, completed/) |
| `TaskRunner` | Exécution des tâches et gestion des tentatives |
| `TaskValidator` | Validation des tâches (dates, statuts, classes) |
| `TaskRegistry` | Enregistrement des nouvelles tâches |
| `ProcessManager` | Gestion du polling (fork, signaux, timeouts) |
| `RunTaskDirective` | Directive CLI pour l'exécution |
| `TaskCollection` | Collection typée pour les tâches |
| `ProcessInfoCollection` | Collection pour les processus enfants |

---

## API Reference

### AbstractTask

| Méthode | Retour | Description |
|---------|--------|-------------|
| `getConfig(): TaskConfigRecord` | `TaskConfigRecord` | Configuration de la tâche (obligatoire) |
| `execute(TaskMode $mode, TaskPayloadRecord $payload): void` | `void` | Template method (à ne pas surcharger) |
| `before(): void` | `void` | Hook avant exécution (optionnel) |
| `process(): void` | `void` | Logique métier (obligatoire) |
| `after(bool $success, ?string $error): void` | `void` | Hook après exécution (optionnel) |
| `info(string $message): void` | `void` | Log de niveau INFO |
| `error(string $message): void` | `void` | Log de niveau ERROR |
| `hasLaravel(): bool` | `bool` | Laravel disponible ? |
| `getLaravel(): ?object` | `?object` | Instance Laravel |
| `setLogger(Logger $logger): self` | `self` | Injecte le logger |
| `setTaskId(string $id): self` | `self` | Injecte l'ID de la tâche |
| `setSignature(string $signature): self` | `self` | Injecte la signature |

### TaskConfigRecord

| Propriété | Type | Description | Défaut |
|-----------|------|-------------|--------|
| `signature` | `string` | Identifiant lisible | Obligatoire |
| `description` | `string` | Description | Obligatoire |
| `delaySeconds` | `int` | Délai entre exécutions | `300` |
| `maxAttempts` | `int` | Nombre max de tentatives | `3` |
| `startAt` | `?string` | Date de début (ISO 8601) | `null` (maintenant) |
| `endAt` | `?string` | Date de fin (ISO 8601) | `null` (jamais) |

### TaskMode (enum)

| Valeur | Constante | Description |
|--------|-----------|-------------|
| `sync` | `TaskMode::SYNC` | Exécution immédiate (synchrone) |
| `defer` | `TaskMode::DEFER` | Exécution asynchrone via poller |

### TaskStatus (enum)

| Valeur | Constante | Description |
|--------|-----------|-------------|
| `pending` | `TaskStatus::PENDING` | En attente d'exécution |
| `running` | `TaskStatus::RUNNING` | En cours d'exécution |
| `success` | `TaskStatus::SUCCESS` | Terminée avec succès |
| `failed` | `TaskStatus::FAILED` | Échec après max tentatives |

### TaskRegistry

| Méthode | Description |
|---------|-------------|
| `register(string $taskClass, TaskMode $mode, TaskPayloadRecord $payload, ?string $startAt, ?string $endAt, ?int $delaySeconds): string` | Enregistre une nouvelle tâche, retourne l'ID/signature |
| `unregisterRecurring(string $signature): void` | Supprime une tâche récurrente |

### RunTaskDirective (CLI)

| Option | Description | Défaut |
|--------|-------------|--------|
| `--duration` | Durée d'exécution (secondes) | `60` |
| `--dry-run` | Simulation (n'exécute rien) | `false` |

---

## Bonnes pratiques

### 1. Une signature unique et explicite

```php
// ✅ BON
signature: 'clear-unconfirmed-orders'

// ❌ MAUVAIS
signature: 'task1'
```

### 2. Définir une date de fin pour les tâches temporaires

```php
// ✅ BON
public function getConfig(): TaskConfigRecord
{
    return new TaskConfigRecord(
        signature: 'promo-2024',
        description: 'Promo campaign',
        delaySeconds: 86400,
        endAt: '2026-12-31T23:59:59Z',
    );
}
```

### 3. Utiliser les hooks pour la maintenance

```php
protected function before(): void
{
    $this->info("Starting at " . now());
}

protected function after(bool $success, ?string $error = null): void
{
    $this->info("Finished at " . now());
    
    if (!$success) {
        $this->notifyAdmin($error);
    }
}
```

### 4. Gérer les erreurs proprement

```php
protected function process(): void
{
    $minutes = $this->payload->get('minutes');
    
    if (!is_int($minutes) || $minutes <= 0) {
        $this->error("Invalid minutes: {$minutes}");
        return;  // Échec silencieux (pas de retry)
    }
    
    try {
        // Logique
    } catch (\Exception $e) {
        $this->error($e->getMessage());
        throw $e;  // Déclenche le retry
    }
}
```

### 5. Utiliser le payload pour les paramètres variables

```php
// ✅ BON - Paramètres externalisés
$payload = new TaskPayloadRecord(
    type: 'send_email',
    payload: (new MixedPayloadCollection())->add('user_id', 123, 'template', 'welcome'),
);

// ❌ MAUVAIS - Paramètres codés en dur
protected function process(): void
{
    $userId = 123;  // Impossible à modifier
}
```

### 6. Tester unitairement vos tâches

```php
public function test_execute_deletes_old_orders(): void
{
    // Arrange
    Order::factory()->create(['created_at' => now()->subHours(2)]);
    
    // Act
    $this->task->execute(TaskMode::SYNC, $payload);
    
    // Assert
    $this->assertDatabaseCount('orders', 0);
}
```

### 7. Monitorer l'exécution des tâches

```php
protected function after(bool $success, ?string $error = null): void
{
    // Envoyer une notification en cas d'échec
    if (!$success) {
        Notification::route('slack', config('services.slack.webhook'))
            ->notify(new TaskFailedNotification($this->signature, $error));
    }
}
```

### 8. Utiliser les collections typées pour les payloads complexes

```php
$tags = new TypedCollection('string');
$tags->add('urgent', 'important');

$payload = new TaskPayloadRecord(
    type: 'process_orders',
    payload: (new MixedPayloadCollection())->add('tags', $tags),
);

// Dans la tâche
protected function process(): void
{
    $tags = $this->payload->get('tags');
    foreach ($tags as $tag) {
        $this->info("Processing tag: {$tag}");
    }
}
```

---

## FAQ

### Q: Quelle est la différence entre une tâche unique et récurrente ?

**R:** Une tâche unique a `delaySeconds = 0` et s'exécute une seule fois. Une tâche récurrente a `delaySeconds > 0` et s'exécute indéfiniment (ou jusqu'à `endAt`).

### Q: Comment arrêter une tâche récurrente ?

**R:** Définissez `endAt` dans le passé ou supprimez le fichier dans `recurring/` :

```bash
rm storage/tasks/recurring/clear-unconfirmed-orders.json
```

### Q: Que se passe-t-il si une tâche échoue ?

**R:** La tâche est réessayée jusqu'à `maxAttempts` fois, avec le même délai `delaySeconds` entre chaque tentative. Après le dernier échec, la tâche est archivée avec le statut `failed`.

### Q: Peut-on exécuter une tâche immédiatement (sans poller) ?

**R:** Oui, utilisez `TaskMode::SYNC` :

```php
$task->execute(TaskMode::SYNC, $payload);
```

### Q: Comment exécuter le poller en continu ?

**R:** Utilisez Supervisor ou cron :

```bash
# Supervisor
command=/usr/bin/php vendor/bin/directive run-task --duration=60

# Cron (toutes les minutes)
* * * * * cd /var/www/html && php vendor/bin/directive run-task --duration=55
```

### Q: Les tâches sont-elles persistantes après redémarrage ?

**R:** Oui, toutes les tâches sont stockées dans des fichiers JSONL. Après un redémarrage, le poller reprend là où il s'était arrêté.

### Q: Peut-on avoir plusieurs pollers en parallèle ?

**R:** Oui, mais cela peut créer des conflits. Il est recommandé d'avoir **un seul poller** par projet, géré par Supervisor.

### Q: Comment visualiser les tâches en attente ?

**R:** Utilisez `ls` ou consultez le dossier `pending/` :

```bash
ls -la storage/tasks/pending/
```

### Q: Peut-on modifier une tâche en attente ?

**R:** Oui, modifiez directement le fichier JSON dans `pending/` ou `recurring/`. Le poller lira les modifications au prochain cycle.

### Q: Comment forcer l'exécution d'une tâche immédiatement ?

**R:** Copiez la tâche dans `pending/` avec la date `start_at` dans le passé et exécutez le poller.

---

## Licence

MIT © [Andy Defer](https://github.com/andydefer)
```