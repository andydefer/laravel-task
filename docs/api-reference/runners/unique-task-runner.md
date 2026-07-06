# UniqueTaskRunner - Référence Technique

## Description

Exécuteur de tâches uniques. Il orchestre la validation, l'instanciation, l'exécution et la journalisation d'une tâche unique spécifique, avec gestion des tentatives et du débogage.

## Hiérarchie / Implémentations

```
UniqueTaskRunnerInterface
    └── UniqueTaskRunner
```

## Rôle principal

Assurer l'exécution d'une tâche unique en :
- Validant que la tâche peut être exécutée (`canRun`)
- Instanciant la classe de tâche
- Exécutant la tâche avec son payload
- Journalisant le résultat (succès/échec)
- Mettant à jour l'état (COMPLETED/FAILED)
- Ajoutant des informations de débogage

## API / Méthodes publiques

### `run(UniqueTaskRecord $record): ExecutionResultRecord`

Exécute une tâche unique.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `UniqueTaskRecord` | Record de la tâche à exécuter |

**Retourne :** `ExecutionResultRecord` - Résultat de l'exécution (succès, erreur, temps)

**Exemple :**
```php
$record = $repository->findByAlias($alias);
$result = $runner->run($record);

if ($result->success) {
    echo "Tâche exécutée en {$result->execution_time->format()}";
} else {
    echo "Échec : {$result->error->description}";
}
```

## Flux d'exécution

```
run(record)
    │
    ├── 1. Validation (validateTask)
    │   └── canRun(record) ?
    │       ├── Oui → Continuer
    │       └── Non → ValidationErrorResult
    │
    ├── 2. Exécution (executeTask)
    │   ├── logStart()
    │   ├── instantiateTask()
    │   ├── task->execute(payload)
    │   │   ├── Succès → logSuccess()
    │   │   └── Échec → logFailure()
    │   ├── updateTaskState()
    │   │   ├── Succès → moveToCompleted()
    │   │   └── Échec → moveToFailed()
    │   ├── addDebugInfo()
    │   └── Retour ExecutionResultRecord
    │
    └── 3. Retour du résultat
```

## Cas d'utilisation

### Cas 1 : Exécution d'une tâche valide

**Problème :** Une tâche unique est prête à être exécutée.

```php
$record = $repository->findByAlias($alias);
$result = $runner->run($record);

// La tâche est exécutée, loggée et marquée COMPLETED
```

---

### Cas 2 : Tâche non valide

**Problème :** Une tâche ne peut pas être exécutée (statut invalide, expirée, etc.).

```php
$record = $repository->findByAlias($alias);
$result = $runner->run($record);

// $result->success = false
// $result->error->description = "Validation failed: Task is in COMPLETED state, not PENDING"
```

---

### Cas 3 : Tâche avec échec et débogage

**Problème :** Une tâche échoue et doit être déboguée.

```php
$record = $repository->findByAlias($alias);
$result = $runner->run($record);

// La tâche est marquée FAILED
// Une entrée de débogage est ajoutée avec le statut FAILED
// Le logger enregistre l'erreur

// Consultation des logs de débogage
$debugRecords = $debugService->findByAlias($alias);
foreach ($debugRecords as $debug) {
    echo "{$debug->status->value}: {$debug->info->getValue()}\n";
}
```

---

### Cas 4 : Tâche avec payload volumineux

**Problème :** Exécuter une tâche avec des données complexes.

```php
$record = UniqueTaskRecord::from([
    'alias' => $alias,
    'payload' => StrictDataObject::from([
        'users' => array_fill(0, 1000, ['id' => 1, 'name' => 'User']),
        'config' => ['batch_size' => 100],
    ]),
    // ... autres propriétés
]);

$result = $runner->run($record);
```

## Validation

### Méthodes de validation utilisées

| Méthode | Rôle | Échec → |
|---------|------|---------|
| `canRun()` | Vérifie si la tâche peut être exécutée | ValidationErrorResult |

### Erreurs de validation possibles

| Situation | Message |
|-----------|---------|
| Classe invalide | `Validation failed: Invalid task class: X does not exist or does not extend AbstractUniqueTask` |
| Tâche en COMPLETED | `Validation failed: Task is in COMPLETED state, not PENDING` |
| Tâche en FAILED | `Validation failed: Task is in FAILED state, not PENDING` |
| Tâche en CANCELED | `Validation failed: Task is in CANCELED state, not PENDING` |
| Tentatives max atteintes | `Validation failed: Maximum attempts reached` |
| Tâche expirée | `Validation failed: Task has expired` |
| Planifiée dans le futur | `Validation failed: Task is not ready to run (scheduled_at in the future)` |

## États après exécution

```
PENDING ──────▶ COMPLETED (succès)
     │
     └─────────▶ FAILED (échec)
```

### Comportement des tentatives

Les tentatives sont gérées par le service (`UniqueTaskService`), pas par le runner. Le runner exécute simplement la tâche et met à jour son état.

## Débogage

Chaque exécution ajoute automatiquement une entrée de débogage via `addDebugInfo()` :

| Statut | Message | Action |
|--------|---------|--------|
| SUCCEEDED | `Task executed successfully` | Ajout via `repository->addDebug()` |
| FAILED | `{message d'erreur}` | Ajout via `repository->addDebug()` |

## Intégration

### Dépendances injectées

| Dépendance | Rôle |
|------------|------|
| `UniqueTaskValidatorInterface` | Validation de la tâche |
| `UniqueTaskLoggerInterface` | Journalisation des événements |
| `HydrationService` | Hydratation des objets |
| `Application` | Conteneur Laravel |
| `UniqueTaskRepositoryInterface` | Mise à jour de l'état et débogage |

### Points d'extension

- Le validator peut être personnalisé pour des règles métier spécifiques
- Le logger peut être remplacé pour un format de log différent
- L'instanciation des tâches peut être modifiée via le conteneur
- Le débogage peut être enrichi avec des données supplémentaires

## Performance

- **Temps d'exécution** : Dépend de la tâche elle-même
- **Mémoire** : Une seule tâche instanciée à la fois
- **Débogage** : Écriture synchrone (configurable)
- **Recommandation** : Utiliser pour des tâches de courte durée (< 5 min)

## Compatibilité

| Version PHP | Support |
|-------------|---------|
| PHP 8.2+ | ✅ Complet |
| PHP 8.1 | ✅ Complet |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Runners\UniqueTaskRunner;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\UuidVO;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\UniqueTaskFqcnVO;
use Illuminate\Support\Carbon;

/** @var UniqueTaskRunner $runner */
$runner = app(UniqueTaskRunner::class);

// 1. Création du record
$alias = new TaskAliasVO('unique@abc-123');
$record = UniqueTaskRecord::from([
    'id' => new UuidVO('550e8400-e29b-41d4-a716-446655440000'),
    'alias' => $alias,
    'fqcn' => new UniqueTaskFqcnVO(MyUniqueTask::class),
    'payload' => StrictDataObject::from(['email' => 'user@example.com']),
    'scheduled_at' => new Iso8601DateTimeVO(Carbon::now()->subHour()->toIso8601String()),
    'grace_period_seconds' => 86400,
    'status' => UniqueTaskStatus::PENDING,
    'attempts' => new CounterVO(0),
    'max_attempts' => new CounterVO(3),
]);

// 2. Exécution
$result = $runner->run($record);

// 3. Traitement du résultat
if ($result->success) {
    echo "✅ Tâche exécutée\n";
    echo "Durée : {$result->execution_time->format()}\n";
} else {
    echo "❌ Échec : {$result->error->description}\n";
}

// 4. Consultation du débogage
$debugService = app(TaskExecutionDebugService::class);
$debugRecords = $debugService->findByAlias($alias);
echo "Nombre d'entrées de débogage : {$debugRecords->count()}\n";
```

## Voir aussi

- `RecurringTaskRunner` - Exécuteur de tâches récurrentes
- `UniqueTaskValidatorInterface` - Validation des tâches
- `UniqueTaskLoggerInterface` - Journalisation
- `TaskExecutionDebugService` - Service de débogage
- `ExecutionResultRecord` - Structure de résultat
- `UniqueTaskRecord` - Données de la tâche
---