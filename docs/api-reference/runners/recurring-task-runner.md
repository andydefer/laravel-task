# RecurringTaskRunner - Référence Technique

## Description

Exécuteur de tâches récurrentes. Il orchestre la validation, l'instanciation, l'exécution et la journalisation d'une tâche récurrente spécifique.

## Hiérarchie / Implémentations

```
RecurringTaskRunnerInterface
    └── RecurringTaskRunner
```

## Rôle principal

Assurer l'exécution d'une tâche récurrente unique en :
- Validant que la tâche peut être exécutée (`canRun`)
- Vérifiant si elle doit être exécutée à nouveau (`shouldRunAgain`)
- Instanciant la classe de tâche
- Exécutant la tâche avec son payload
- Journalisant le résultat (succès/échec)
- Mettant à jour l'état dans le repository

## API / Méthodes publiques

### `run(RecurringTaskRecord $record): ExecutionResultRecord`

Exécute une tâche récurrente.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `RecurringTaskRecord` | Record de la tâche à exécuter |

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
    ├── 2. Vérification de ré-exécution
    │   └── shouldRunAgain(record) ?
    │       ├── Oui → Continuer
    │       └── Non → SkippedResult
    │
    ├── 3. Exécution (executeTask)
    │   ├── logStart()
    │   ├── instantiateTask()
    │   ├── task->execute(payload)
    │   │   ├── Succès → logSuccess()
    │   │   └── Échec → logFailure()
    │   ├── repository->updateAfterRun()
    │   └── Retour ExecutionResultRecord
    │
    └── 4. Retour du résultat
```

## Cas d'utilisation

### Cas 1 : Exécution d'une tâche valide

**Problème :** Une tâche récurrente est prête à être exécutée.

```php
$record = $repository->findByAlias($alias);
$result = $runner->run($record);

// La tâche est exécutée, loggée et mise à jour
```

---

### Cas 2 : Tâche non valide

**Problème :** Une tâche ne peut pas être exécutée (statut invalide, expirée, etc.).

```php
$record = $repository->findByAlias($alias);
$result = $runner->run($record);

// $result->success = false
// $result->error->description = "Validation failed: Task has expired (end_at reached)"
```

---

### Cas 3 : Tâche déjà exécutée dans l'intervalle

**Problème :** La tâche a déjà été exécutée et l'intervalle n'est pas atteint.

```php
$record = $repository->findByAlias($alias);
$result = $runner->run($record);

// $result->success = true (skip, pas d'erreur)
// $result->execution_time = 0.0
```

---

### Cas 4 : Tâche avec payload complexe

**Problème :** Exécuter une tâche avec des données structurées.

```php
$record = RecurringTaskRecord::from([
    'alias' => $alias,
    'payload' => StrictDataObject::from([
        'users' => [1, 2, 3],
        'config' => ['notify' => true],
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
| `shouldRunAgain()` | Vérifie si l'intervalle est atteint | SkippedResult |

### Erreurs de validation possibles

| Situation | Message |
|-----------|---------|
| Tâche en WAITING | `Validation failed: Task is in WAITING state, not PLAYING` |
| Tâche en PAUSED | `Validation failed: Task is in PAUSED state` |
| Tâche FINISHED | `Validation failed: Task is already FINISHED` |
| Tâche CANCELED | `Validation failed: Task is CANCELED` |
| Tâche expirée | `Validation failed: Task has expired (end_at reached)` |
| Start_at non atteint | `Validation failed: Task is not ready to run (start_at not reached)` |

## Intégration

### Dépendances injectées

| Dépendance | Rôle |
|------------|------|
| `RecurringTaskValidatorInterface` | Validation de la tâche |
| `RecurringTaskLoggerInterface` | Journalisation des événements |
| `HydrationService` | Hydratation des objets |
| `Application` | Conteneur Laravel |
| `RecurringTaskRepositoryInterface` | Mise à jour de l'état |

### Points d'extension

- Le validator peut être personnalisé pour des règles métier spécifiques
- Le logger peut être remplacé pour un format de log différent
- L'instanciation des tâches peut être modifiée via le conteneur

## Performance

- **Temps d'exécution** : Dépend de la tâche elle-même
- **Mémoire** : Une seule tâche instanciée à la fois
- **Logging** : Écriture synchrone (configurable)
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

use AndyDefer\Task\Runners\RecurringTaskRunner;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\DomainStructures\Utils\StrictDataObject;

/** @var RecurringTaskRunner $runner */
$runner = app(RecurringTaskRunner::class);

// 1. Création du record
$alias = new TaskAliasVO('recurring@abc-123');
$record = RecurringTaskRecord::from([
    'alias' => $alias,
    'fqcn' => MyRecurringTask::class,
    'payload' => StrictDataObject::from(['key' => 'value']),
    'interval_seconds' => 3600,
    'start_at' => Carbon::now()->subHour(),
    'status' => RecurringTaskStatus::PLAYING,
    'last_run_at' => Carbon::now()->subMinutes(30),
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
```

## Voir aussi

- `UniqueTaskRunner` - Exécuteur de tâches uniques
- `RecurringTaskValidatorInterface` - Validation des tâches
- `RecurringTaskLoggerInterface` - Journalisation
- `ExecutionResultRecord` - Structure de résultat
- `RecurringTaskRecord` - Données de la tâche