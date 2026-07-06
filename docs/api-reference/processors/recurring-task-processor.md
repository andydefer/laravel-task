# RecurringTaskProcessor - Référence Technique

## Description

Processeur de lots de tâches récurrentes. Il orchestre la récupération, la validation, l'exécution et la gestion des transitions d'état pour un ensemble de tâches récurrentes prêtes à être exécutées.

## Hiérarchie / Implémentations

```
RecurringTaskProcessorInterface
    └── RecurringTaskProcessor
```

## Rôle principal

Traiter un lot de tâches récurrentes en :
- Récupérant les tâches prêtes à être exécutées
- Gérant les transitions d'état automatiques (WAITING → PLAYING, PLAYING → FINISHED)
- Exécutant chaque tâche via le `RecurringTaskRunner`
- Gérant les post-traitements (passage en FINISHED si expiré)
- Agrégeant les résultats (succès/échecs/finis)
- Retournant un rapport de traitement

## API / Méthodes publiques

### `process(LimitVO $limit = new LimitVO): ProcessResultRecord`

Traite un lot de tâches récurrentes.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `LimitVO` | Nombre maximum de tâches à traiter (défaut : illimité) |

**Retourne :** `ProcessResultRecord` - Résumé du traitement (succès, échecs, finis, erreurs)

**Exemple :**
```php
$processor = new RecurringTaskProcessor($repository, $runner, $validator);
$result = $processor->process(new LimitVO(50));

echo "Succès : {$result->success->getValue()}\n";
echo "Échecs : {$result->failed->getValue()}\n";
echo "Finis : {$result->finished->getValue()}\n";

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
    │   ├── counters = {success: 0, failed: 0, finished: 0}
    │   └── errors = new Collection
    │
    ├── 2. Récupération des tâches
    │   └── repository->findReadyToRun(now, limit)
    │       ├── fresh_state (transitions automatiques)
    │       │   ├── WAITING → PLAYING
    │       │   ├── PLAYING → FINISHED
    │       │   └── PLAYING → CANCELED
    │       └── tasks (PLAYING)
    │
    ├── 3. Mise à jour des finis automatiques
    │   └── counters->finished += playing_to_finished
    │
    ├── 4. Traitement de chaque tâche
    │   ├── shouldProcessTask(record) ?
    │   │   ├── Non → skip
    │   │   └── Oui → processSingleTask()
    │   │       ├── runner->run(record)
    │   │       ├── success → success++
    │   │       └── failed → failed++ + add error
    │   ├── handlePostProcessing()
    │   │   ├── findByAlias()
    │   │   ├── shouldMoveToFinished() ?
    │   │   └── Oui → moveToFinished() + finished++
    │   └── (continue)
    │
    └── 5. Construction du résultat
        └── buildResult(startedAt, counters, errors)
```

## Transitions d'état automatiques

Le repository effectue automatiquement les transitions suivantes avant le traitement :

```
WAITING ──(start_at atteint)──▶ PLAYING
PLAYING ──(end_at atteint)────▶ FINISHED
PLAYING ──(failed_attempts >= max) ──▶ CANCELED
```

### Impact sur les compteurs

| Transition | Compteur |
|------------|----------|
| PLAYING → FINISHED | `finished++` |

## Cas d'utilisation

### Cas 1 : Traitement standard

**Problème :** Traiter toutes les tâches récurrentes prêtes.

```php
$result = $processor->process();

echo "Tâches traitées :\n";
echo "✅ Succès : {$result->success->getValue()}\n";
echo "❌ Échecs : {$result->failed->getValue()}\n";
echo "🏁 Finis : {$result->finished->getValue()}\n";
```

---

### Cas 2 : Traitement avec limite

**Problème :** Traiter uniquement les 10 premières tâches pour éviter une surcharge.

```php
$result = $processor->process(new LimitVO(10));
```

---

### Cas 3 : Tâches avec intervalle non atteint

**Problème :** Une tâche a déjà été exécutée récemment et l'intervalle n'est pas atteint.

```php
$result = $processor->process();

// La tâche est ignorée (skipped)
// Aucune erreur, aucun compteur incrémenté
```

---

### Cas 4 : Tâches expirées (end_at atteint)

**Problème :** Des tâches ont atteint leur date de fin.

```php
$result = $processor->process();

// Les tâches expirées sont automatiquement marquées FINISHED
// Le compteur finished est incrémenté
echo "Tâches finies automatiquement : {$result->finished->getValue()}\n";
```

## Gestion des erreurs

| Situation | Action | Compteur |
|-----------|--------|----------|
| Échec de validation | - | - |
| Échec d'exécution | `runner->run()` échoue | `failed++` |
| Exception inattendue | - | `failed++` |
| Expiration automatique | `moveToFinished()` | `finished++` |

### Cas d'erreur typiques

| Cause | Comportement |
|-------|--------------|
| Tâche non PLAYING | Ignorée (shouldProcessTask = false) |
| Intervalle non atteint | Ignorée (shouldProcessTask = false) |
| Échec d'exécution | `failed++`, erreur ajoutée |
| Expiration après exécution | `moveToFinished()`, `finished++` |

## Intégration

### Dépendances injectées

| Dépendance | Rôle |
|------------|------|
| `RecurringTaskRepositoryInterface` | Récupération et mise à jour des tâches |
| `RecurringTaskRunnerInterface` | Exécution individuelle des tâches |
| `RecurringTaskValidatorInterface` | Validation des tâches |

### Flux de données

```
Repository ──(tasks + fresh_state)──▶ Processor
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
- **Optimisation** : Les transitions automatiques sont effectuées en une seule requête
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

use AndyDefer\Task\Processors\RecurringTaskProcessor;
use AndyDefer\Task\Repositories\RecurringTaskRepository;
use AndyDefer\Task\Runners\RecurringTaskRunner;
use AndyDefer\Task\Validators\RecurringTaskValidator;
use AndyDefer\Task\ValueObjects\LimitVO;

// 1. Construction du processor
$repository = app(RecurringTaskRepository::class);
$runner = app(RecurringTaskRunner::class);
$validator = app(RecurringTaskValidator::class);

$processor = new RecurringTaskProcessor($repository, $runner, $validator);

// 2. Traitement
$result = $processor->process(new LimitVO(100));

// 3. Affichage des statistiques
echo "=== Résumé du traitement ===\n";
echo "✅ Succès : {$result->success->getValue()}\n";
echo "❌ Échecs : {$result->failed->getValue()}\n";
echo "🏁 Finis : {$result->finished->getValue()}\n";
echo "📦 Total : " . ($result->success->getValue() + $result->failed->getValue() + $result->finished->getValue()) . "\n";

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

- `UniqueTaskProcessor` - Processeur de tâches uniques
- `RecurringTaskRunner` - Exécuteur de tâches récurrentes
- `RecurringTaskValidator` - Validation des tâches
- `ProcessResultRecord` - Structure de résultat
- `RecurringTaskRepositoryInterface` - Repository des tâches