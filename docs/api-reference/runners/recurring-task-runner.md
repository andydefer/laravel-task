# RecurringTaskRunner - Référence Technique

## Description

Moteur d'exécution des tâches récurrentes. Prend une tâche en `PLAYING`, vérifie si elle doit être exécutée selon son intervalle, l'exécute et met à jour son état.

## Hiérarchie / Implémentations

```
RecurringTaskRunnerInterface
    └── RecurringTaskRunner
```

## Rôle principal

Ce runner est le moteur d'exécution d'une **seule** tâche récurrente. Il :

1. **Valide** que la tâche peut être exécutée (`canRun`)
2. **Vérifie** si l'intervalle est atteint (`shouldRunAgain`)
3. **Instancie** la classe de tâche concrète
4. **Exécute** la tâche avec son payload
5. **Met à jour** la tâche après exécution (`updateAfterRun`)
6. **Retourne** le résultat de l'exécution

## API

### `run(RecurringTaskRecord $record): ExecutionResultRecord`

Point d'entrée principal du runner.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `RecurringTaskRecord` | Tâche à exécuter |

**Retourne :** `ExecutionResultRecord` - Résultat de l'exécution

**Cas de retour :**
- `success: true, error: null` → Exécution réussie
- `success: false, error: TaskErrorRecord` → Échec de validation ou d'exécution
- `success: true, error: null, execution_time: 0.0` → Intervalle non atteint (skip)

**Exceptions :** `RuntimeException` - Si la tâche n'existe pas

**Exemple :**
```php
$runner = new RecurringTaskRunner($validator, $logger, $hydration, $app, $repository);
$result = $runner->run($record);

if ($result->success) {
    echo "Tâche exécutée en {$result->execution_time}s";
} else {
    echo "Erreur: {$result->error->error}";
}
```

---

### `instantiateTask(RecurringTaskRecord $record): AbstractRecurringTask`

Instancie la classe de tâche concrète.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `RecurringTaskRecord` | Tâche à instancier |

**Retourne :** `AbstractRecurringTask` - Instance de la tâche

**Processus :**
1. Crée un `RecurringTaskContext`
2. Injecte l'alias, l'intervalle, les dates
3. Retourne une nouvelle instance de `$record->fqcn`

---

### `calculateDuration(Iso8601DateTimeVO $start): float`

Calcule la durée d'exécution en secondes.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$start` | `Iso8601DateTimeVO` | Date de début de l'exécution |

**Retourne :** `float` - Durée en secondes (différence entre `$start` et maintenant)

## Cas d'utilisation

### Cas 1 : Exécution d'une tâche récurrente

```php
$runner = app(RecurringTaskRunner::class);

// Tâche en PLAYING, last_run_at = 10:00, interval = 3600 (1h)
// Exécution à 11:00 → intervalle atteint
$result = $runner->run($record);

// $result->success = true
// $result->execution_time = 0.45 (secondes)
// last_run_at mis à jour à 11:00
```

### Cas 2 : Intervalle non atteint (skip)

```php
// Tâche en PLAYING, last_run_at = 10:00, interval = 3600 (1h)
// Exécution à 10:30 → intervalle non atteint
$result = $runner->run($record);

// $result->success = true
// $result->execution_time = 0.0
// last_run_at NON mis à jour
// Aucun debug ajouté
```

### Cas 3 : Échec de validation

```php
// Tâche en WAITING (non exécutable)
$result = $runner->run($record);

// $result->success = false
// $result->error->error = 'Validation failed: Task is in WAITING state, not PLAYING'
```

### Cas 4 : Échec d'exécution avec exception

```php
// Tâche qui lance une exception
$result = $runner->run($record);

// $result->success = false
// $result->error->error = 'Test exception'
// last_run_at mis à jour (même en échec)
// Debug ajouté avec status = 'failed'
```

## Flux d'exécution

```
┌─────────────────────────────────────────────────────────────────────┐
│                    RecurringTaskRunner                             │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  ENTRÉE : RecurringTaskRecord                                      │
│                                                                     │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  ÉTAPE 1 : VALIDATION                                       │   │
│  │  ┌─────────────────────────────────────────────────────┐   │   │
│  │  │  $validator->canRun($record)                        │   │   │
│  │  │  ├─ Statut = PLAYING ?                              │   │   │
│  │  │  └─ end_at pas dépassé ?                           │   │   │
│  │  │  ❌ Échec → retourne ExecutionResultRecord(fail)   │   │   │
│  │  └─────────────────────────────────────────────────────┘   │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  ÉTAPE 2 : VÉRIFICATION INTERVALLE                        │   │
│  │  ┌─────────────────────────────────────────────────────┐   │   │
│  │  │  $validator->shouldRunAgain($record)                │   │   │
│  │  │  ├─ last_run_at null ? → OUI                       │   │   │
│  │  │  └─ now - last_run_at >= interval ? → OUI          │   │   │
│  │  │  ❌ Non → retourne ExecutionResultRecord(skip)     │   │   │
│  │  └─────────────────────────────────────────────────────┘   │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  ÉTAPE 3 : LOG DÉBUT                                       │   │
│  │  ┌─────────────────────────────────────────────────────┐   │   │
│  │  │  $logger->logStart($record)                         │   │   │
│  │  │  → "recurring_task_started"                         │   │   │
│  │  └─────────────────────────────────────────────────────┘   │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  ÉTAPE 4 : INSTANCIATION                                   │   │
│  │  ┌─────────────────────────────────────────────────────┐   │   │
│  │  │  $task = new $record->fqcn($context, ...)          │   │   │
│  │  └─────────────────────────────────────────────────────┘   │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  ÉTAPE 5 : EXÉCUTION                                       │   │
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
│  │  ÉTAPE 6 : MISE À JOUR                                     │   │
│  │  ┌─────────────────────────────────────────────────────┐   │   │
│  │  │  $repository->updateAfterRun($record, $success, $error) │   │   │
│  │  │  → last_run_at = maintenant                          │   │   │
│  │  │  → Ajout d'une entrée de debug                      │   │   │
│  │  │  → Statut reste PLAYING                             │   │   │
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
| Tâche non en PLAYING | ❌ Non bloquant | `Validation failed: Task is in WAITING state` | Retourne `success: false` |
| Tâche expirée | ❌ Non bloquant | `Validation failed: Task has expired` | Retourne `success: false` |
| Intervalle non atteint | ❌ Non bloquant | - | Retourne `success: true, execution_time: 0.0` |
| Exception dans l'exécution | `Throwable` | Message de l'exception | `updateAfterRun` avec `success: false` |

## Dépendances

| Dépendance | Rôle |
|------------|------|
| `RecurringTaskValidatorInterface` | Valide la tâche avant exécution |
| `RecurringTaskLoggerInterface` | Logge le début, succès, échec |
| `HydrationService` | Utilisé pour l'instanciation |
| `Application` (Laravel) | Pour instancier les classes |
| `RecurringTaskRepositoryInterface` | Pour mettre à jour la tâche |

## Performance

- **Complexité** : O(1) - une seule tâche exécutée
- **Mémoire** : Une seule instance de tâche créée
- **Base de données** : 1 requête pour `updateAfterRun`
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

use AndyDefer\Task\Runners\RecurringTaskRunner;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

// Créer une tâche en PLAYING
$record = new RecurringTaskRecord(
    alias: new TaskSignatureVO('email-newsletter'),
    fqcn: EmailNewsletterTask::class,
    payload: StrictDataObject::from(['list' => 'subscribers']),
    interval_seconds: new CounterVO(3600),
    start_at: new Iso8601DateTimeVO('2026-06-22T10:00:00+00:00'),
    end_at: new Iso8601DateTimeVO('2026-12-31T23:59:59+00:00'),
    status: RecurringTaskStatus::PLAYING,
    last_run_at: new Iso8601DateTimeVO('2026-06-22T10:00:00+00:00'),
);

// Exécuter
$runner = app(RecurringTaskRunner::class);
$result = $runner->run($record);

if ($result->success && $result->execution_time > 0) {
    echo "✅ Tâche exécutée avec succès\n";
    echo "⏱️ Temps: {$result->execution_time}s\n";
} elseif ($result->success && $result->execution_time === 0.0) {
    echo "⏭️ Intervalle non atteint, aucune exécution\n";
} else {
    echo "❌ Échec: {$result->error->error}\n";
}
```

## Voir aussi

- `RecurringTaskProcessor` - Processeur de lots
- `RecurringTaskValidator` - Validation des tâches
- `ExecutionResultRecord` - Structure de résultat
- `UniqueTaskRunner` - Runner pour les tâches uniques
- `RecurringTaskLogger` - Logger des tâches récurrentes