# UniqueTaskProcessor - Référence Technique

## Description

Processeur de lots de tâches uniques. Il orchestre la récupération, la validation, l'exécution et la gestion des expirations pour un ensemble de tâches uniques prêtes à être exécutées.

## Hiérarchie / Implémentations

```
UniqueTaskProcessorInterface
    └── UniqueTaskProcessor
```

## Rôle principal

Traiter un lot de tâches uniques en :
- Récupérant les tâches prêtes à être exécutées
- Validant chaque tâche avant exécution
- Exécutant les tâches via le `UniqueTaskRunner`
- Gérant les tâches expirées
- Agrégeant les résultats (succès/échecs)
- Retournant un rapport de traitement

## API / Méthodes publiques

### `process(LimitVO $limit = new LimitVO): ProcessResultRecord`

Traite un lot de tâches uniques.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `LimitVO` | Nombre maximum de tâches à traiter (défaut : illimité) |

**Retourne :** `ProcessResultRecord` - Résumé du traitement (succès, échecs, erreurs)

**Exemple :**
```php
$processor = new UniqueTaskProcessor($repository, $runner, $validator);
$result = $processor->process(new LimitVO(50));

echo "Succès : {$result->success->getValue()}\n";
echo "Échecs : {$result->failed->getValue()}\n";

foreach ($result->errors as $error) {
    echo "Erreur : {$error->description}\n";
}
```

## Flux d'exécution

```
process(limit)
    │
    ├── 1. Initialisation
    │   ├── startedAt = now
    │   ├── counters = {success: 0, failed: 0}
    │   └── errors = new Collection
    │
    ├── 2. Récupération des tâches
    │   └── getReadyTasks(now, limit)
    │       └── repository->findReadyToRun(now)
    │
    ├── 3. Traitement de chaque tâche
    │   ├── convertModelToRecord()
    │   ├── validator->canRun(record) ?
    │   │   ├── Non → handleValidationFailure()
    │   │   │   ├── moveToFailed()
    │   │   │   ├── failed++
    │   │   │   └── add error
    │   │   └── Oui → processSingleTask()
    │   │       ├── runner->run(record)
    │   │       ├── success → success++
    │   │       └── failed → failed++ + add error
    │   └── (continue)
    │
    ├── 4. Gestion des tâches expirées
    │   └── handleExpiredTasks()
    │       ├── findExpired()
    │       ├── moveToFailed()
    │       ├── failed++
    │       └── add expiration error
    │
    └── 5. Construction du résultat
        └── buildResult(startedAt, counters, errors)
```

## Cas d'utilisation

### Cas 1 : Traitement standard

**Problème :** Traiter toutes les tâches uniques prêtes.

```php
$result = $processor->process();

echo "Tâches traitées :\n";
echo "✅ Succès : {$result->success->getValue()}\n";
echo "❌ Échecs : {$result->failed->getValue()}\n";
```

---

### Cas 2 : Traitement avec limite

**Problème :** Traiter uniquement les 10 premières tâches pour éviter une surcharge.

```php
$result = $processor->process(new LimitVO(10));
```

---

### Cas 3 : Gestion des erreurs de validation

**Problème :** Une tâche ne peut pas être exécutée (statut invalide, expirée, etc.).

```php
$result = $processor->process();

foreach ($result->errors as $error) {
    if (str_contains($error->description, 'Validation failed')) {
        echo "Erreur de validation : {$error->description}\n";
        echo "Contexte : {$error->context}\n";
    }
}
```

---

### Cas 4 : Tâches expirées

**Problème :** Des tâches ont dépassé leur période de grâce.

```php
$result = $processor->process();

foreach ($result->errors as $error) {
    if (str_contains($error->description, 'expired')) {
        echo "Tâche expirée : {$error->alias->getValue()}\n";
        echo "Contexte : {$error->context}\n";
    }
}
```

## Gestion des erreurs

| Situation | Action | Erreur enregistrée |
|-----------|--------|-------------------|
| Validation échouée | `moveToFailed()` | `Validation failed: {raison}` |
| Échec d'exécution | - | Message d'erreur du runner |
| Tâche expirée | `moveToFailed()` | `Task expired` |
| Exception inattendue | - | Message d'exception |

### Messages d'erreur de validation

| Cause | Message |
|-------|---------|
| Classe invalide | `Validation failed: Invalid task class: X` |
| Statut non PENDING | `Validation failed: Task is in X state, not PENDING` |
| Tentatives max atteintes | `Validation failed: Maximum attempts reached` |
| Tâche expirée | `Validation failed: Task has expired` |
| Planifiée dans le futur | `Validation failed: Task is not ready to run (scheduled_at in the future)` |

## Intégration

### Dépendances injectées

| Dépendance | Rôle |
|------------|------|
| `UniqueTaskRepositoryInterface` | Récupération et mise à jour des tâches |
| `UniqueTaskRunnerInterface` | Exécution individuelle des tâches |
| `UniqueTaskValidatorInterface` | Validation des tâches |

### Flux de données

```
Repository ──(tasks)──▶ Processor
                           │
                    ┌──────┴──────┐
                    │             │
              Validator         Runner
                    │             │
                    └──────┬──────┘
                           │
                    ┌──────┴──────┐
                    │             │
              Repository    Errors
                    │             │
                    └──────┬──────┘
                           │
                      Result
```

## Performance

- **Complexité** : O(n) où n est le nombre de tâches traitées
- **Mémoire** : Charge les tâches par lots (limit)
- **Recommandation** : 
  - Utiliser `LimitVO` pour éviter les surcharges
  - Pour > 1000 tâches, utiliser le `--limit` dans la directive

## Compatibilité

| Version PHP | Support |
|-------------|---------|
| PHP 8.2+ | ✅ Complet |
| PHP 8.1 | ✅ Complet |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Processors\UniqueTaskProcessor;
use AndyDefer\Task\Repositories\UniqueTaskRepository;
use AndyDefer\Task\Runners\UniqueTaskRunner;
use AndyDefer\Task\Validators\UniqueTaskValidator;
use AndyDefer\Task\ValueObjects\LimitVO;

// 1. Construction du processor
$repository = app(UniqueTaskRepository::class);
$runner = app(UniqueTaskRunner::class);
$validator = app(UniqueTaskValidator::class);

$processor = new UniqueTaskProcessor($repository, $runner, $validator);

// 2. Traitement
$result = $processor->process(new LimitVO(100));

// 3. Affichage des statistiques
echo "=== Résumé du traitement ===\n";
echo "✅ Succès : {$result->success->getValue()}\n";
echo "❌ Échecs : {$result->failed->getValue()}\n";
echo "📦 Total : " . ($result->success->getValue() + $result->failed->getValue()) . "\n";

// 4. Affichage des erreurs
if ($result->errors->count() > 0) {
    echo "\n=== Détail des erreurs ===\n";
    foreach ($result->errors as $error) {
        echo "❌ {$error->alias->getValue()}\n";
        echo "   Description : {$error->description}\n";
        echo "   Contexte : {$error->context}\n\n";
    }
}
```

## Voir aussi

- `RecurringTaskProcessor` - Processeur de tâches récurrentes
- `UniqueTaskRunner` - Exécuteur de tâches uniques
- `UniqueTaskValidator` - Validation des tâches
- `ProcessResultRecord` - Structure de résultat
- `UniqueTaskRepositoryInterface` - Repository des tâches
