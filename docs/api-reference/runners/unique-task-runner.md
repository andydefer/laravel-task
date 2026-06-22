# UniqueTaskRunner - Référence Technique

## Description

Moteur d'exécution des tâches uniques. Prend une tâche en `PENDING`, valide son état, l'exécute une seule fois, puis la marque comme `COMPLETED` ou `FAILED`.

## Hiérarchie / Implémentations

```
UniqueTaskRunnerInterface
    └── UniqueTaskRunner
```

## Rôle principal

Ce runner est le moteur d'exécution d'une **seule** tâche unique. Il :

1. **Valide** que la tâche peut être exécutée (`canRun`)
2. **Instancie** la classe de tâche concrète
3. **Exécute** la tâche avec son payload
4. **Ajoute** une entrée de debug
5. **Met à jour** le statut (COMPLETED ou FAILED)
6. **Retourne** le résultat de l'exécution

## API

### `run(UniqueTaskRecord $record): ExecutionResultRecord`

Point d'entrée principal du runner.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `UniqueTaskRecord` | Tâche à exécuter |

**Retourne :** `ExecutionResultRecord` - Résultat de l'exécution

**Cas de retour :**
- `success: true, error: null` → Exécution réussie, tâche marquée COMPLETED
- `success: false, error: TaskErrorRecord` → Échec de validation ou d'exécution, tâche marquée FAILED

**Exceptions :** `RuntimeException` - Si la tâche n'existe pas

**Exemple :**
```php
$runner = new UniqueTaskRunner($validator, $logger, $hydration, $app, $repository);
$result = $runner->run($record);

if ($result->success) {
    echo "✅ Tâche exécutée en {$result->execution_time}s";
} else {
    echo "❌ Erreur: {$result->error->error}";
}
```

---

### `instantiateTask(UniqueTaskRecord $record): AbstractUniqueTask`

Instancie la classe de tâche concrète.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `UniqueTaskRecord` | Tâche à instancier |

**Retourne :** `AbstractUniqueTask` - Instance de la tâche

**Processus :**
1. Crée un `UniqueTaskContext`
2. Injecte l'ID, l'alias, la date planifiée
3. Retourne une nouvelle instance de `$record->fqcn`

**Exceptions :** `Error` - Si la classe n'existe pas ou n'étend pas `AbstractUniqueTask`

---

### `calculateDuration(Iso8601DateTimeVO $start): float`

Calcule la durée d'exécution en secondes.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$start` | `Iso8601DateTimeVO` | Date de début de l'exécution |

**Retourne :** `float` - Durée en secondes (différence entre `$start` et maintenant)

## Cas d'utilisation

### Cas 1 : Exécution réussie d'une tâche

```php
$runner = app(UniqueTaskRunner::class);

// Tâche en PENDING, scheduled_at dans le passé
$result = $runner->run($record);

// $result->success = true
// $result->execution_time = 0.45 (secondes)
// Statut → COMPLETED
// Debug ajouté avec status = 'succeeded'
```

### Cas 2 : Échec de validation

```php
// Tâche avec scheduled_at dans le futur
$result = $runner->run($record);

// $result->success = false
// $result->error->error = 'Validation failed: Task is not ready to run (scheduled_at in the future)'
// Statut → FAILED
// Debug ajouté avec status = 'failed'
```

### Cas 3 : Échec d'exécution avec exception

```php
// Tâche qui lance une exception
$result = $runner->run($record);

// $result->success = false
// $result->error->error = 'Test exception'
// Statut → FAILED
// Debug ajouté avec status = 'failed'
```

### Cas 4 : Tâche avec max_attempts atteint

```php
// Tâche avec attempts = 3, max_attempts = 3
$result = $runner->run($record);

// $result->success = false
// $result->error->error = 'Validation failed: Maximum attempts reached'
// Statut → FAILED
```

## Flux d'exécution

```
┌─────────────────────────────────────────────────────────────────────┐
│                    UniqueTaskRunner                                │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  ENTRÉE : UniqueTaskRecord                                         │
│                                                                     │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  ÉTAPE 1 : VALIDATION                                       │   │
│  │  ┌─────────────────────────────────────────────────────┐   │   │
│  │  │  $validator->canRun($record)                        │   │   │
│  │  │  ├─ Classe existe et étend AbstractUniqueTask ?   │   │   │
│  │  │  ├─ Statut = PENDING ?                              │   │   │
│  │  │  ├─ scheduled_at <= now ?                           │   │   │
│  │  │  ├─ attempts < max_attempts ?                       │   │   │
│  │  │  └─ non expiré (grace_period) ?                    │   │   │
│  │  │  ❌ Échec → retourne ExecutionResultRecord(fail)   │   │   │
│  │  └─────────────────────────────────────────────────────┘   │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  ÉTAPE 2 : LOG DÉBUT                                       │   │
│  │  ┌─────────────────────────────────────────────────────┐   │   │
│  │  │  $logger->logStart($record)                         │   │   │
│  │  │  → "unique_task_started"                            │   │   │
│  │  │  → task_id, alias, scheduled_at, attempts          │   │   │
│  │  └─────────────────────────────────────────────────────┘   │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  ÉTAPE 3 : INSTANCIATION                                   │   │
│  │  ┌─────────────────────────────────────────────────────┐   │   │
│  │  │  $task = new $record->fqcn($context, ...)          │   │   │
│  │  └─────────────────────────────────────────────────────┘   │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  ÉTAPE 4 : EXÉCUTION                                       │   │
│  │  ┌─────────────────────────────────────────────────────┐   │   │
│  │  │  try {                                              │   │   │
│  │  │    $task->execute($payload)                         │   │   │
│  │  │    $success = true                                  │   │   │
│  │  │    $logger->logSuccess()                            │   │   │
│  │  │  } catch (\Throwable $e) {                          │   │   │
│  │  │    $success = false                                 │   │   │
│  │  │    $error = $e->getMessage()                       │   │   │
│  │  │    $logger->logFailure()                            │   │   │
│  │  │  }                                                  │   │   │
│  │  └─────────────────────────────────────────────────────┘   │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  ÉTAPE 5 : AJOUTER LE DEBUG                                │   │
│  │  ┌─────────────────────────────────────────────────────┐   │   │
│  │  │  $repository->addDebug($record, status, info)      │   │   │
│  │  │  → task_type = 'unique'                            │   │   │
│  │  │  → status = 'succeeded' ou 'failed'                │   │   │
│  │  │  → info = message                                  │   │   │
│  │  └─────────────────────────────────────────────────────┘   │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  ÉTAPE 6 : METTRE À JOUR LE STATUT                         │   │
│  │  ┌─────────────────────────────────────────────────────┐   │   │
│  │  │  if ($success) {                                    │   │   │
│  │  │    $repository->moveToCompleted($record)            │   │   │
│  │  │    ✅ status = COMPLETED                            │   │   │
│  │  │    ✅ finished_at = now                             │   │   │
│  │  │  } else {                                           │   │   │
│  │  │    $repository->moveToFailed($record)               │   │   │
│  │  │    ❌ status = FAILED                               │   │   │
│  │  │    ❌ finished_at = now                             │   │   │
│  │  │  }                                                  │   │   │
│  │  └─────────────────────────────────────────────────────┘   │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  SORTIE : ExecutionResultRecord                                   │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  success: true/false,                                      │   │
│  │  error: TaskErrorRecord|null,                              │   │
│  │  execution_time: float                                     │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## Gestion des erreurs

| Situation | Exception | Message | Action |
|-----------|-----------|---------|--------|
| Classe inexistante | ❌ Non bloquant | `Validation failed: Invalid task class...` | Retourne `success: false` |
| Statut ≠ PENDING | ❌ Non bloquant | `Validation failed: Task is not in PENDING state` | Retourne `success: false` |
| scheduled_at > now | ❌ Non bloquant | `Validation failed: Task is not ready to run` | Retourne `success: false` |
| attempts >= max_attempts | ❌ Non bloquant | `Validation failed: Maximum attempts reached` | Retourne `success: false` |
| Tâche expirée | ❌ Non bloquant | `Validation failed: Task has expired` | Retourne `success: false` |
| Exception dans l'exécution | `Throwable` | Message de l'exception | Retourne `success: false` |

## Dépendances

| Dépendance | Rôle |
|------------|------|
| `UniqueTaskValidatorInterface` | Valide la tâche avant exécution |
| `UniqueTaskLoggerInterface` | Logge le début, succès, échec |
| `HydrationService` | Utilisé pour l'instanciation |
| `Application` (Laravel) | Pour instancier les classes |
| `UniqueTaskRepositoryInterface` | Pour ajouter le debug et mettre à jour le statut |

## Cycle de vie d'une tâche unique

```
┌─────────────────────────────────────────────────────────────────────┐
│                    Cycle de vie d'une tâche unique                 │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  1. Création                                                       │
│     status = PENDING                                               │
│     attempts = 0                                                   │
│     scheduled_at = date prévue                                     │
│                                                                     │
│  2. Runner appelé                                                  │
│     ┌─────────────────────────────────────────────────────────┐   │
│     │  Validation :                                           │   │
│     │  ✅ Classe valide                                       │   │
│     │  ✅ status = PENDING                                    │   │
│     │  ✅ scheduled_at <= now                                 │   │
│     │  ✅ attempts < max_attempts                             │   │
│     │  ✅ non expiré                                          │   │
│     └─────────────────────────────────────────────────────────┘   │
│                                                                     │
│  3. Exécution                                                      │
│     ┌─────────────────────────────────────────────────────────┐   │
│     │  SUCCÈS :                                               │   │
│     │  ✅ status = COMPLETED                                  │   │
│     │  ✅ finished_at = now                                   │   │
│     │  ✅ debug status = 'succeeded'                          │   │
│     │                                                          │   │
│     │  ÉCHEC :                                                 │   │
│     │  ❌ status = FAILED                                     │   │
│     │  ❌ finished_at = now                                   │   │
│     │  ❌ debug status = 'failed'                             │   │
│     └─────────────────────────────────────────────────────────┘   │
│                                                                     │
│  4. Fin de vie                                                    │
│     Status terminal : COMPLETED ou FAILED                         │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## Performance

- **Complexité** : O(1) - une seule tâche exécutée
- **Mémoire** : Une seule instance de tâche créée
- **Base de données** : 2 requêtes (addDebug + moveToCompleted/Failed)
- **Temps** : Variable selon la tâche exécutée

## Compatibilité

| Version | Support |
|---------|---------|
| PHP 8.1+ | ✅ Complet |
| Laravel 10+ | ✅ Complet |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Runners\UniqueTaskRunner;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use Ramsey\Uuid\Uuid;

// Créer une tâche en PENDING
$record = new UniqueTaskRecord(
    id: new TaskIdVO((string) Uuid::uuid4()),
    alias: new TaskSignatureVO('send-welcome-email'),
    fqcn: SendWelcomeEmailTask::class,
    payload: StrictDataObject::from(['email' => 'john@example.com']),
    scheduled_at: new Iso8601DateTimeVO(now()->subMinutes(5)->toIso8601String()),
    grace_period_seconds: 86400,
    status: UniqueTaskStatus::PENDING,
    attempts: new CounterVO(0),
    max_attempts: new CounterVO(3),
);

// Exécuter
$runner = app(UniqueTaskRunner::class);
$result = $runner->run($record);

if ($result->success) {
    echo "✅ Tâche exécutée avec succès\n";
    echo "⏱️ Temps: {$result->execution_time}s\n";
    // Statut → COMPLETED
} else {
    echo "❌ Échec: {$result->error->error}\n";
    // Statut → FAILED
}
```

## Voir aussi

- `UniqueTaskProcessor` - Processeur de lots
- `UniqueTaskValidator` - Validation des tâches
- `ExecutionResultRecord` - Structure de résultat
- `RecurringTaskRunner` - Runner pour les tâches récurrentes
- `UniqueTaskLogger` - Logger des tâches uniques