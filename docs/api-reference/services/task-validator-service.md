# TaskValidatorService - Référence Technique

## Description

Service de validation des tâches qui détermine si une tâche peut être exécutée, si elle a expiré, et si elle bénéficie de la période de grâce. Utilise les Value Objects (`UnixTimestampVO`, `CounterVO`) pour les manipulations temporelles.

## Hiérarchie

```
TaskValidatorService
```

La classe n'étend aucune classe parente et n'implémente aucune interface.

## Rôle principal

Fournir des méthodes de validation pour les tâches uniques et récurrentes, incluant la vérification des fenêtres temporelles avec `UnixTimestampVO`, la gestion des tentatives via `CounterVO`, et le calcul de la période de grâce pour les tâches expirées.

## API / Méthodes publiques

### `__construct(TaskConfig $config, HydrationService $hydration, LoggerInterface $logger, Application $app): void`

Injecte les dépendances nécessaires à la validation.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$config` | `TaskConfig` | Configuration contenant les paramètres de période de grâce |
| `$hydration` | `HydrationService` | Service d'hydratation pour l'instanciation |
| `$logger` | `LoggerInterface` | Service de journalisation |
| `$app` | `Application` | Container Laravel pour l'instanciation |

### `validateTaskClass(string $className): bool`

Valide qu'une classe existe, peut être instanciée avec `TaskContext`, et étend `AbstractTask`.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$className` | `string` | Nom qualifié complet de la classe à valider |

**Retourne :** `bool` - `true` si la classe est une tâche valide, `false` sinon

**Exemple :**
```php
if (!$validator->validateTaskClass(SendEmailTask::class)) {
    throw new \InvalidArgumentException('Invalid task class');
}
```

### `canRunTask(TaskRecord $task): bool`

Vérifie si une tâche unique peut être exécutée. Prend en compte :
- Statut `PENDING`
- Nombre de tentatives < `max_attempts`
- `start_at` ≤ maintenant
- `end_at` avec ou sans période de grâce
- `enforce_exact_schedule`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `TaskRecord` | Tâche à vérifier (avec Value Objects) |

**Retourne :** `bool` - `true` si la tâche peut être exécutée, `false` sinon

**Exemple :**
```php
if ($validator->canRunTask($task)) {
    $runner->runTask($task);
}
```

### `isTaskExpired(TaskRecord $task): bool`

Vérifie si une tâche unique a définitivement expiré (période de grâce incluse).

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `TaskRecord` | Tâche à vérifier |

**Retourne :** `bool` - `true` si la tâche a expiré, `false` sinon

### `shouldRunRecurringNow(RecurringTaskRecord $task): bool`

Vérifie si une tâche récurrente doit être exécutée maintenant.
- `start_at` ≤ maintenant
- `end_at` ≥ maintenant (si défini)
- `next_run_at` ≤ maintenant

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `RecurringTaskRecord` | Tâche récurrente à vérifier |

**Retourne :** `bool` - `true` si la tâche doit être exécutée, `false` sinon

### `shouldRunTaskNow(TaskRecord $task): bool`

Vérifie si une tâche unique doit être exécutée (sans période de grâce). Version stricte pour les besoins internes.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `TaskRecord` | Tâche à vérifier |

**Retourne :** `bool` - `true` si la tâche doit être exécutée, `false` sinon

### `getDelaySecondsForTask(TaskRecord $task): int`

Retourne le délai d'une tâche (extrait du `CounterVO`).

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `TaskRecord` | Tâche source |

**Retourne :** `int` - Délai en secondes (valeur du `CounterVO`)

### `getGracePeriodDelay(TaskRecord $task): int`

Calcule le retard d'une tâche expirée par rapport à sa période de grâce.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `TaskRecord` | Tâche expirée |

**Retourne :** `int` - Nombre de secondes de retard (0 si la tâche n'est pas éligible)

### `isUniqueTaskWithGracePeriod(TaskRecord $task): bool`

Vérifie si une tâche unique est éligible à la période de grâce.
Conditions :
- `delay_seconds->value === 0`
- `gracePeriodEnabled()` dans la configuration
- `enforce_exact_schedule === false`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `TaskRecord` | Tâche à vérifier |

**Retourne :** `bool` - `true` si la tâche bénéficie de la période de grâce, `false` sinon

## Cas d'utilisation

### Cas 1 : Vérification de l'exécutabilité d'une tâche

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Services\TaskValidatorService;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use AndyDefer\Task\Enums\TaskStatus;

$validator = new TaskValidatorService($config, $hydration, $logger, $app);

// Tâche dans sa fenêtre d'exécution
$task = new TaskRecord(
    id: new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'),
    signature: new TaskSignatureVO('my-task'),
    class: MyTask::class,
    payload: $payload,
    status: TaskStatus::PENDING,
    start_at: new Iso8601DateTimeVO(date('c', strtotime('-1 hour'))),
    end_at: new Iso8601DateTimeVO(date('c', strtotime('+1 hour'))),
    delay_seconds: new CounterVO(0),
    attempts: new CounterVO(0),
    max_attempts: new CounterVO(3),
);

if ($validator->canRunTask($task)) {
    echo "La tâche peut être exécutée\n";
}
```

### Cas 2 : Gestion de la période de grâce

```php
<?php

declare(strict_types=1);

// Tâche expirée mais dans la période de grâce (24h après end_at)
$task = new TaskRecord(
    // ...
    start_at: new Iso8601DateTimeVO(date('c', strtotime('-2 days'))),
    end_at: new Iso8601DateTimeVO(date('c', strtotime('-1 day'))),
    delay_seconds: new CounterVO(0),  // Tâche unique
    enforce_exact_schedule: false,
);

if ($validator->canRunTask($task)) {
    // La tâche s'exécute malgré l'expiration
    $runner->runTask($task);
    
    $delay = $validator->getGracePeriodDelay($task);
    echo "Tâche exécutée avec {$delay} secondes de retard\n";
}
```

### Cas 3 : Validation d'une tâche récurrente

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Records\RecurringTaskRecord;

$task = new RecurringTaskRecord(
    signature: new TaskSignatureVO('cleanup'),
    class: CleanupTask::class,
    payload: $payload,
    start_at: new Iso8601DateTimeVO(date('c', strtotime('-1 hour'))),
    next_run_at: new Iso8601DateTimeVO(date('c', strtotime('-5 minutes'))),
    delay_seconds: new CounterVO(3600),
);

if ($validator->shouldRunRecurringNow($task)) {
    $runner->runRecurringTask($task);
    // next_run_at est automatiquement mis à jour
}
```

## Flux d'exécution

```
canRunTask(TaskRecord $task)
    │
    ├── Vérifier status->isPending()
    │   └── false → return false
    │
    ├── Vérifier attempts->value >= max_attempts->value
    │   └── true → return false
    │
    ├── Comparer now avec start_at (UnixTimestampVO)
    │   └── now->isBefore(start_at) → return false
    │
    ├── Si enforce_exact_schedule === true
    │   └── return (end_at === null OR now <= end_at)
    │
    ├── Si delay_seconds->value === 0 ET gracePeriodEnabled()
    │   ├── grace_end = end_at + gracePeriodSeconds()
    │   └── return now <= grace_end
    │
    └── Sinon (comportement normal)
        └── return (end_at === null OR now <= end_at)
```

## Gestion des erreurs (validations)

| Situation | Comportement | Valeur retournée |
|-----------|--------------|------------------|
| Tâche non pendante | Refus d'exécution | `false` |
| Tentatives max atteintes | Refus d'exécution | `false` |
| `start_at` dans le futur | Refus d'exécution | `false` |
| `end_at` dépassé sans grace period | Refus d'exécution | `false` |
| `end_at` dépassé avec grace period | Acceptation | `true` |
| `enforce_exact_schedule = true` | Pas de grace period | `now <= end_at` |
| Classe de tâche invalide | `validateTaskClass()` | `false` |

## Intégration

### Dépendances

```
TaskValidatorService
    ├── TaskConfig (configuration grace period)
    ├── HydrationService (hydratation pour l'instanciation)
    ├── LoggerInterface (journalisation)
    ├── Application (container Laravel)
    └── Carbon (timestamp avec test mock via getCurrentTimestamp())
```

### Avec TaskRunnerService

```php
class TaskRunnerService
{
    public function runTask(TaskRecord $task): bool
    {
        if (!$this->validator->canRunTask($task)) {
            $this->markTaskFailed($task, ErrorType::TASK_VALIDATION_FAILED);
            return false;
        }
        // ... exécution
    }
    
    public function runRecurringTask(RecurringTaskRecord $task): bool
    {
        // Le runner utilise le validator avant exécution
        if (!$this->validator->shouldRunRecurringNow($task)) {
            // ... gestion
        }
    }
}
```

## Performance

| Opération | Complexité | Notes |
|-----------|------------|-------|
| `validateTaskClass()` | O(1) | `class_exists()` + instanciation avec contexte |
| `canRunTask()` | O(1) | Comparaisons `UnixTimestampVO` |
| `shouldRunRecurringNow()` | O(1) | Comparaisons `UnixTimestampVO` |
| `getGracePeriodDelay()` | O(1) | Simple soustraction via `UnixTimestampVO` |
| `getCurrentTimestamp()` | O(1) | `Carbon::getTestNow()` ou `time()` |

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
use AndyDefer\Task\Services\TaskValidatorService;
use AndyDefer\Task\Configs\TaskConfig;
use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use AndyDefer\DomainStructures\Utils\StrictDataObject;

// 1. Configuration avec période de grâce activée (24h)
$config = new TaskConfig(app('config'));
$hydration = new HydrationService();
$logger = app(LoggerInterface::class);
$app = app();

$validator = new TaskValidatorService($config, $hydration, $logger, $app);

// 2. Création du payload
$payload = new TaskPayloadRecord(
    type: 'backup',
    data: new StrictDataObject(['database' => 'mysql']),
);

// 3. Création d'une tâche expirée
$task = new TaskRecord(
    id: new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'),
    signature: new TaskSignatureVO('backup'),
    class: BackupTask::class,
    payload: $payload,
    status: TaskStatus::PENDING,
    created_at: new Iso8601DateTimeVO(),
    start_at: new Iso8601DateTimeVO(date('c', strtotime('-2 days'))),
    end_at: new Iso8601DateTimeVO(date('c', strtotime('-1 day'))),
    delay_seconds: new CounterVO(0),  // Tâche unique
    attempts: new CounterVO(0),
    max_attempts: new CounterVO(3),
    enforce_exact_schedule: false,
);

// 4. Vérification
if ($validator->canRunTask($task)) {
    echo "Tâche exécutable (dans la période de grâce)\n";
    
    $delay = $validator->getGracePeriodDelay($task);
    echo "Retard : {$delay} secondes\n";
} else {
    echo "Tâche non exécutable\n";
}

// 5. Vérification de l'expiration
if ($validator->isTaskExpired($task)) {
    echo "La tâche a définitivement expiré\n";
}

// 6. Vérification de la classe
if (!$validator->validateTaskClass(BackupTask::class)) {
    throw new \InvalidArgumentException('Invalid task class');
}

// 7. Vérification période de grâce éligible
if ($validator->isUniqueTaskWithGracePeriod($task)) {
    echo "La tâche bénéficie de la période de grâce\n";
}
```

## Méthodes utilitaires privées

### `getCurrentTimestamp(): UnixTimestampVO`

Retourne le timestamp courant en respectant les mocks Carbon pour les tests.

- Si `Carbon::getTestNow()` est défini (tests), utilise cette valeur
- Sinon, utilise `time()`

## Voir aussi

- `TaskConfig` - Configuration de la période de grâce
- `TaskRecord` - Record pour les tâches uniques (avec Value Objects)
- `RecurringTaskRecord` - Record pour les tâches récurrentes
- `TaskRunnerService` - Service d'exécution qui utilise ce validateur
- `UnixTimestampVO` - Value Object pour les timestamps
- `CounterVO` - Value Object pour les compteurs
- `ErrorType` - Enum des types d'erreur (utilise les résultats de validation)
---