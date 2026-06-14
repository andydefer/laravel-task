# TaskRegistryService - Référence Technique

## Description

Service d'enregistrement des tâches (uniques et récurrentes) dans le système de tâches. Gère la validation, la résolution de configuration, l'hydratation des objets et la persistance via les repositories.

## Hiérarchie

```
TaskRegistryService
```

La classe n'étend aucune classe parente et n'implémente aucune interface.

## Rôle principal

Fournir un point d'entrée unique pour l'enregistrement de tâches, en déterminant automatiquement si une tâche doit être enregistrée comme unique ou récurrente (`delay_seconds > 0` et `end_at === null` = récurrente). Le service retourne un identifiant UUID pour les tâches uniques ou une signature pour les tâches récurrentes.

## API / Méthodes publiques

### `__construct(TaskRepositoryInterface $taskRepository, RecurringTaskRepositoryInterface $recurringTaskRepository, TaskValidatorService $validator, HydrationService $hydration, UuidFactoryInterface $uuidFactory, Application $laravelApp): void`

Injecte les dépendances nécessaires à l'enregistrement.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$taskRepository` | `TaskRepositoryInterface` | Repository pour les tâches uniques |
| `$recurringTaskRepository` | `RecurringTaskRepositoryInterface` | Repository pour les tâches récurrentes |
| `$validator` | `TaskValidatorService` | Service de validation des classes de tâches |
| `$hydration` | `HydrationService` | Service d'hydratation des objets |
| `$uuidFactory` | `UuidFactoryInterface` | Factory de génération d'UUID |
| `$laravelApp` | `Application` | Container Laravel pour l'instanciation |

### `register(string $taskClass, TaskPayloadRecord $payload, ?TaskConfigRecord $override_config = null): string`

Enregistre une nouvelle tâche (unique ou récurrente).

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$taskClass` | `string` | Nom qualifié complet de la classe (doit étendre `AbstractTask`) |
| `$payload` | `TaskPayloadRecord` | Données de la tâche (type + StrictDataObject) |
| `$override_config` | `TaskConfigRecord|null` | Configuration surchargeant celle de la tâche (optionnel) |

**Retourne :** `string` - UUID pour une tâche unique, signature pour une tâche récurrente

**Détermination du type :**
- **Récurrente** : `$config->end_at === null` ET `$config->delay_seconds->value > 0`
- **Unique** : Sinon

**Exceptions :** 
- `InvalidArgumentException` - Si la classe de tâche n'étend pas `AbstractTask`
- `RuntimeException` - Si une tâche récurrente avec la même signature existe déjà

**Exemple :**
```php
$registry = app(TaskRegistryService::class);

// Enregistrer une tâche unique
$taskId = $registry->register(
    taskClass: SendEmailTask::class,
    payload: $payload,
);

// Enregistrer une tâche récurrente (toutes les heures)
$overrideConfig = new TaskConfigRecord(
    signature: new TaskSignatureVO('cleanup'),
    description: 'Cleanup task',
    delay_seconds: new CounterVO(3600),
    max_attempts: new CounterVO(3),
    start_at: null,
    end_at: null,
);
$signature = $registry->register(
    taskClass: CleanupTask::class,
    payload: $payload,
    override_config: $overrideConfig,
);
```

### `unregisterRecurring(TaskSignatureVO $signature): void`

Supprime une tâche récurrente par sa signature.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$signature` | `TaskSignatureVO` | Signature unique de la tâche récurrente |

**Exemple :**
```php
$registry->unregisterRecurring(new TaskSignatureVO('cleanup-task'));
```

## Cas d'utilisation

### Cas 1 : Enregistrement d'une tâche unique avec surcharge de configuration

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Services\TaskRegistryService;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Records\TaskConfigRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use AndyDefer\DomainStructures\Utils\StrictDataObject;

$payload = new TaskPayloadRecord(
    type: 'email',
    data: new StrictDataObject(['user_id' => 123]),
);

// Surcharge : max_attempts = 5 au lieu de la valeur par défaut de la tâche
$overrideConfig = new TaskConfigRecord(
    signature: new TaskSignatureVO('send-welcome-email'),
    description: 'Send welcome email',
    delay_seconds: new CounterVO(0),
    max_attempts: new CounterVO(5),
    start_at: new Iso8601DateTimeVO(),
    end_at: new Iso8601DateTimeVO(date('c', strtotime('+24 hours'))),
);

$taskId = $registry->register(
    taskClass: SendWelcomeEmailTask::class,
    payload: $payload,
    override_config: $overrideConfig,
);

echo "Tâche unique créée avec l'ID : {$taskId}";
```

### Cas 2 : Enregistrement d'une tâche récurrente

```php
<?php

declare(strict_types=1);

// Tâche qui s'exécute toutes les 5 minutes (300 secondes)
$overrideConfig = new TaskConfigRecord(
    signature: new TaskSignatureVO('process-queue'),
    description: 'Process queue',
    delay_seconds: new CounterVO(300),
    max_attempts: new CounterVO(3),
    start_at: null,
    end_at: null,  // null = récurrente à vie
);

$signature = $registry->register(
    taskClass: ProcessQueueTask::class,
    payload: $payload,
    override_config: $overrideConfig,
);

echo "Tâche récurrente créée avec la signature : {$signature->value}";
```

### Cas 3 : Utilisation des valeurs par défaut de la tâche

```php
<?php

declare(strict_types=1);

// La tâche elle-même définit sa configuration via getConfig()
final class MyTask extends AbstractTask
{
    public function getConfig(): TaskConfigRecord
    {
        return new TaskConfigRecord(
            signature: new TaskSignatureVO('my-task'),
            description: 'My custom task',
            delay_seconds: new CounterVO(60),
            max_attempts: new CounterVO(5),
            start_at: new Iso8601DateTimeVO('2024-01-01T00:00:00+00:00'),
            end_at: null,
        );
    }
}

// Aucune surcharge - la config de la tâche est utilisée
// Comme end_at === null ET delay_seconds > 0 → tâche récurrente
$signature = $registry->register(
    taskClass: MyTask::class,
    payload: $payload,
);
```

### Cas 4 : Désenregistrement d'une tâche récurrente

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\ValueObjects\TaskSignatureVO;

// Supprimer une tâche récurrente
$registry->unregisterRecurring(new TaskSignatureVO('old-cleanup-task'));
```

## Flux d'exécution

```
register($taskClass, $payload, $override_config)
    │
    ├── validateTaskClass()
    │   └── TaskValidatorService::validateTaskClass()
    │
    ├── getTaskConfig()
    │   ├── $laravelApp->make($taskClass)
    │   └── HydrationService::hydrate(TaskConfigRecord::class)
    │
    ├── mergeConfig() [si override_config]
    │   └── HydrationService::hydrate() avec fusion
    │
    ├── Détermination du type
    │   ├── if ($config->end_at === null && $config->delay_seconds->value > 0)
    │   │   └── registerRecurringTask()
    │   └── else
    │       └── registerUniqueTask()
    │
    └── Retourne string (UUID ou signature)
```

### Détail : registerRecurringTask()

```
registerRecurringTask()
    │
    ├── Vérifier l'existence
    │   └── RecurringTaskRepository::find($signature)
    │
    ├── Si existe → RuntimeException
    │
    ├── Créer RecurringTaskRecord
    │   ├── HydrationService::hydrate()
    │   ├── start_at = $config->start_at ?? now()
    │   ├── next_run_at = start_at
    │   ├── success_count = 0
    │   └── failure_count = 0
    │
    ├── RecurringTaskRepository::save()
    │
    └── Retourne $signature->value
```

### Détail : registerUniqueTask()

```
registerUniqueTask()
    │
    ├── Générer UUID v4
    │   └── UuidFactoryInterface::uuid4()
    │
    ├── Créer TaskRecord
    │   ├── HydrationService::hydrate()
    │   ├── id = new TaskIdVO($uuid)
    │   ├── status = TaskStatus::PENDING
    │   ├── created_at = now()
    │   ├── start_at = $config->start_at ?? now()
    │   ├── attempts = 0
    │   └── last_error = null
    │
    ├── TaskRepository::save()
    │
    └── Retourne $task->id->value
```

## Gestion des erreurs

| Situation | Exception | Message |
|-----------|-----------|---------|
| Classe de tâche invalide (n'étend pas `AbstractTask`) | `InvalidArgumentException` | `Task must extend AbstractTask` |
| Tâche récurrente déjà existante | `RuntimeException` | `Recurring task '{$signature}' already exists. Delete it first if you want to re-register.` |

## Intégration

### Dépendances

```
TaskRegistryService
    ├── TaskRepositoryInterface (persistance des tâches uniques)
    ├── RecurringTaskRepositoryInterface (persistance des tâches récurrentes)
    ├── TaskValidatorService (validation des classes)
    ├── HydrationService (création d'objets)
    ├── UuidFactoryInterface (génération d'UUID)
    └── Application (container Laravel)
```

### Avec un contrôleur Laravel

```php
<?php

namespace App\Http\Controllers;

use AndyDefer\Task\Services\TaskRegistryService;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Records\TaskConfigRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TaskController extends Controller
{
    public function __construct(
        private readonly TaskRegistryService $registry
    ) {}

    public function store(Request $request): JsonResponse
    {
        $payload = new TaskPayloadRecord(
            type: $request->input('type'),
            data: new StrictDataObject($request->input('data', [])),
        );

        // Surcharge optionnelle
        $overrideConfig = null;
        if ($request->has('delay_seconds')) {
            $overrideConfig = new TaskConfigRecord(
                signature: new TaskSignatureVO($request->input('signature')),
                description: $request->input('description', ''),
                delay_seconds: new CounterVO((int) $request->input('delay_seconds')),
                max_attempts: new CounterVO((int) $request->input('max_attempts', 3)),
                start_at: $request->input('start_at') ? new Iso8601DateTimeVO($request->input('start_at')) : null,
                end_at: $request->input('end_at') ? new Iso8601DateTimeVO($request->input('end_at')) : null,
            );
        }

        $identifier = $this->registry->register(
            taskClass: $request->input('class'),
            payload: $payload,
            override_config: $overrideConfig,
        );

        return response()->json(['id' => $identifier], 201);
    }

    public function destroy(string $signature): JsonResponse
    {
        $this->registry->unregisterRecurring(new TaskSignatureVO($signature));
        return response()->json(['message' => 'Recurring task deleted']);
    }
}
```

## Performance

| Opération | Complexité | Notes |
|-----------|------------|-------|
| `register()` pour tâche unique | O(1) + UUID | Génération UUID + sauvegarde JSONL |
| `register()` pour tâche récurrente | O(1) + vérification | Vérification d'existence + sauvegarde JSONL |
| `unregisterRecurring()` | O(1) | Suppression directe du fichier JSONL |
| `mergeConfig()` | O(1) | Hydratation avec fusion des tableaux |

## Compatibilité

| Version PHP | Support |
|-------------|---------|
| PHP 8.2+ | ✅ Requis (readonly properties) |
| PHP 8.1 | ✅ Complet |
| PHP 8.0 | ❌ |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\Services\TaskRegistryService;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Records\TaskConfigRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use AndyDefer\Task\AbstractTask;

// 1. Définir une tâche personnalisée
final class BackupDatabaseTask extends AbstractTask
{
    public function getConfig(): TaskConfigRecord
    {
        return new TaskConfigRecord(
            signature: new TaskSignatureVO('backup-database'),
            description: 'Sauvegarde la base de données',
            delay_seconds: new CounterVO(0),  // Tâche unique
            max_attempts: new CounterVO(3),
            start_at: null,
            end_at: null,
        );
    }
    
    protected function process(): void
    {
        $data = $this->context->getPayload()->data;
        $database = $data->database ?? 'default';
        $destination = $data->destination ?? '/backups';
        
        $this->info("Starting database backup: {$database}");
        // Logique de sauvegarde
        $this->info("Database backup completed to {$destination}");
    }
}

// 2. Créer le payload
$payload = new TaskPayloadRecord(
    type: 'backup',
    data: new StrictDataObject([
        'database' => 'mysql',
        'destination' => '/backups/mysql',
    ]),
);

// 3. Enregistrer une tâche unique avec surcharge
$overrideConfig = new TaskConfigRecord(
    signature: new TaskSignatureVO('backup-database'),
    description: 'Sauvegarde MySQL',
    delay_seconds: new CounterVO(0),
    max_attempts: new CounterVO(5),
    start_at: new Iso8601DateTimeVO(date('c', strtotime('tomorrow 02:00'))),
    end_at: new Iso8601DateTimeVO(date('c', strtotime('tomorrow 06:00'))),
);

$registry = app(TaskRegistryService::class);

$taskId = $registry->register(
    taskClass: BackupDatabaseTask::class,
    payload: $payload,
    override_config: $overrideConfig,
);

echo "Tâche programmée pour demain 02h00 : {$taskId}\n";

// 4. Enregistrer une tâche récurrente
$recurringConfig = new TaskConfigRecord(
    signature: new TaskSignatureVO('cleanup-logs'),
    description: 'Nettoyage des logs',
    delay_seconds: new CounterVO(86400), // Une fois par jour
    max_attempts: new CounterVO(3),
    start_at: null,
    end_at: null,
);

$signature = $registry->register(
    taskClass: CleanupLogsTask::class,
    payload: new TaskPayloadRecord('cleanup', new StrictDataObject(['days' => 30])),
    override_config: $recurringConfig,
);

echo "Tâche récurrente enregistrée : {$signature}\n";

// 5. Désenregistrer une tâche récurrente
$registry->unregisterRecurring(new TaskSignatureVO('old-cleanup-task'));
```

## Voir aussi

- `TaskRepositoryInterface` - Repository pour les tâches uniques
- `RecurringTaskRepositoryInterface` - Repository pour les tâches récurrentes
- `TaskValidatorService` - Service de validation
- `HydrationService` - Service d'hydratation
- `TaskRecord` - Record pour les tâches uniques
- `RecurringTaskRecord` - Record pour les tâches récurrentes
- `TaskConfigRecord` - Configuration des tâches
- `AbstractTask` - Classe de base des tâches
- `CounterVO` - Value Object pour les compteurs
- `TaskIdVO` / `TaskSignatureVO` - Value Objects d'identifiants