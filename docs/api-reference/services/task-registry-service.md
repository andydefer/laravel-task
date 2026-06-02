# TaskRegistryService - Référence Technique

## Description

Service d'enregistrement des tâches (uniques et récurrentes) dans le système de tâches. Gère la validation, la résolution de configuration et la persistance.

## Hiérarchie

```
TaskRegistryService
```

La classe n'étend aucune classe parente et n'implémente aucune interface.

## Rôle principal

Fournir un point d'entrée unique pour l'enregistrement de tâches, en déterminant automatiquement si une tâche doit être enregistrée comme unique ou récurrente. Le service retourne un identifiant UUID pour les tâches uniques ou une signature pour les tâches récurrentes.

## API / Méthodes publiques

### `__construct(TaskStorageService $storage, TaskValidatorService $validator): void`

Injecte les dépendances nécessaires à l'enregistrement.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$storage` | `TaskStorageService` | Service de persistance des tâches |
| `$validator` | `TaskValidatorService` | Service de validation des classes de tâches |

### `register(string $taskClass, TaskPayloadRecord $payload, ?string $startAt = null, ?string $endAt = null, ?int $delaySeconds = null, bool $enforceExactSchedule = false): string`

Enregistre une nouvelle tâche (unique ou récurrente).

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$taskClass` | `string` | Nom qualifié complet de la classe (doit étendre `AbstractTask`) |
| `$payload` | `TaskPayloadRecord` | Données de la tâche |
| `$startAt` | `string|null` | Date ISO 8601 de début d'exécution (optionnel) |
| `$endAt` | `string|null` | Date ISO 8601 d'expiration (optionnel) |
| `$delaySeconds` | `int|null` | Délai entre les exécutions récurrentes (optionnel) |
| `$enforceExactSchedule` | `bool` | Désactive la période de grâce (défaut: `false`) |

**Retourne :** `string` - UUID pour une tâche unique, signature pour une tâche récurrente

**Exceptions :** 
- `InvalidArgumentException` - Si la classe de tâche n'étend pas `AbstractTask`
- `RuntimeException` - Si une tâche récurrente avec la même signature existe déjà

**Exemple :**
```php
$registry = new TaskRegistryService($storage, $validator);

// Enregistrer une tâche unique
$taskId = $registry->register(
    taskClass: SendEmailTask::class,
    payload: $payload,
    startAt: '2024-01-01T10:00:00+00:00',
    endAt: '2024-01-01T12:00:00+00:00',
);

// Enregistrer une tâche récurrente (toutes les heures)
$signature = $registry->register(
    taskClass: CleanupTask::class,
    payload: $payload,
    delaySeconds: 3600,
);
```

### `unregisterRecurring(string $signature): void`

Supprime une tâche récurrente par sa signature.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$signature` | `string` | Signature unique de la tâche récurrente |

**Exemple :**
```php
$registry->unregisterRecurring('cleanup-task');
```

## Cas d'utilisation

### Cas 1 : Enregistrement d'une tâche unique avec date limite

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Services\TaskRegistryService;
use AndyDefer\Task\Records\TaskPayloadRecord;

$payload = new TaskPayloadRecord(
    type: 'email',
    payload: new StrictDataObjectCollection()
);

$taskId = $registry->register(
    taskClass: SendWelcomeEmailTask::class,
    payload: $payload,
    startAt: date('c'),                          // Maintenant
    endAt: date('c', strtotime('+24 hours')),   // Dans 24h
);

echo "Tâche créée avec l'ID : {$taskId}";
```

### Cas 2 : Enregistrement d'une tâche récurrente

```php
<?php

declare(strict_types=1);

// Tâche qui s'exécute toutes les 5 minutes (300 secondes)
$signature = $registry->register(
    taskClass: ProcessQueueTask::class,
    payload: $payload,
    delaySeconds: 300,
);

echo "Tâche récurrente créée avec la signature : {$signature}";
```

### Cas 3 : Enregistrement avec désactivation de la période de grâce

```php
<?php

declare(strict_types=1);

// Tâche qui ne bénéficie PAS de période de grâce
// Si elle n'est pas exécutée avant endAt, elle est définitivement ignorée
$taskId = $registry->register(
    taskClass: TimeCriticalTask::class,
    payload: $payload,
    startAt: date('c'),
    endAt: date('c', strtotime('+1 hour')),
    enforceExactSchedule: true,
);
```

### Cas 4 : Utilisation des valeurs par défaut de la tâche

```php
<?php

declare(strict_types=1);

// La tâche elle-même définit sa configuration via getConfig()
class MyTask extends AbstractTask
{
    public function getConfig(): TaskConfigRecord
    {
        return new TaskConfigRecord(
            signature: 'my-task',
            description: 'My custom task',
            delaySeconds: 60,
            maxAttempts: 5,
            startAt: '2024-01-01T00:00:00+00:00',
        );
    }
}

// Les paramètres sont optionnels - la config de la tâche est utilisée
$signature = $registry->register(
    taskClass: MyTask::class,
    payload: $payload,
);
// Utilise startAt et delaySeconds de la config de la tâche
```

## Flux d'exécution

```
register()
    │
    ├─→ validateTaskClass()
    │   └─→ validator->validateTaskClass()
    │
    ├─→ getTaskConfig()
    │   └─→ Instancie la tâche et appelle getConfig()
    │
    ├─→ Résolution des paramètres (startAt, endAt, delaySeconds)
    │   (Priorité: paramètre > config de la tâche > valeur par défaut)
    │
    ├─→ isRecurringTask()
    │   └─→ endAt === null && delaySeconds > 0
    │
    ├─→ Si récurrente → registerRecurringTask()
    │   ├─→ Vérifie si la signature existe déjà
    │   ├─→ Crée RecurringTaskRecord
    │   └─→ storage->saveRecurring()
    │
    └─→ Si unique → registerUniqueTask()
        ├─→ Génère un UUID
        ├─→ Crée TaskRecord
        └─→ storage->savePending()
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
    ├── TaskStorageService (persistance)
    └── TaskValidatorService (validation)
```

### Avec un contrôleur Laravel

```php
<?php

namespace App\Http\Controllers;

use AndyDefer\Task\Services\TaskRegistryService;
use AndyDefer\Task\Records\TaskPayloadRecord;

class TaskController extends Controller
{
    public function store(Request $request, TaskRegistryService $registry): JsonResponse
    {
        $payload = TaskPayloadRecord::from($request->input('payload'));
        
        $taskId = $registry->register(
            taskClass: $request->input('class'),
            payload: $payload,
            startAt: $request->input('start_at'),
            endAt: $request->input('end_at'),
            delaySeconds: $request->input('delay_seconds'),
            enforceExactSchedule: $request->input('enforce_exact_schedule', false),
        );
        
        return response()->json(['id' => $taskId], 201);
    }
}
```

## Performance

| Opération | Complexité | Notes |
|-----------|------------|-------|
| `register()` pour tâche unique | O(1) + UUID | La validation est constante |
| `register()` pour tâche récurrente | O(1) + vérification | Vérification d'existence via fichier |
| `unregisterRecurring()` | O(1) | Suppression directe du fichier |

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

use AndyDefer\Task\Services\TaskRegistryService;
use AndyDefer\Task\Services\TaskStorageService;
use AndyDefer\Task\Services\TaskValidatorService;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Records\TaskConfigRecord;
use AndyDefer\Task\AbstractTask;

// 1. Définir une tâche personnalisée
final class BackupDatabaseTask extends AbstractTask
{
    public function getConfig(): TaskConfigRecord
    {
        return new TaskConfigRecord(
            signature: 'backup-database',
            description: 'Sauvegarde la base de données',
            maxAttempts: 3,
        );
    }
    
    protected function process(): void
    {
        $this->info("Starting database backup...");
        // Logique de sauvegarde
        $this->info("Database backup completed");
    }
}

// 2. Créer le payload
$payload = new TaskPayloadRecord(
    type: 'backup',
    payload: StrictDataObjectCollection::from([
        'database' => 'mysql',
        'destination' => '/backups',
    ])
);

// 3. Enregistrer une tâche unique
$registry = new TaskRegistryService(
    new TaskStorageService(new TaskConfig()),
    new TaskValidatorService(new TaskConfig())
);

$taskId = $registry->register(
    taskClass: BackupDatabaseTask::class,
    payload: $payload,
    startAt: date('c', strtotime('tomorrow 02:00')),
    enforceExactSchedule: true,
);

echo "Tâche programmée pour demain 02h00 : {$taskId}\n";

// 4. Enregistrer une tâche récurrente
$signature = $registry->register(
    taskClass: CleanupLogsTask::class,
    payload: $payload,
    delaySeconds: 86400, // Une fois par jour
);

echo "Tâche récurrente enregistrée : {$signature}\n";

// 5. Désenregistrer une tâche récurrente
$registry->unregisterRecurring('cleanup-logs');
```

## Voir aussi

- `TaskStorageService` - Service de persistance
- `TaskValidatorService` - Service de validation
- `TaskRecord` - Record pour les tâches uniques
- `RecurringTaskRecord` - Record pour les tâches récurrentes
- `TaskConfigRecord` - Configuration des tâches
- `AbstractTask` - Classe de base des tâches