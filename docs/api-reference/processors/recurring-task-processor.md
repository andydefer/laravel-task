# RecurringTaskProcessor - Référence Technique

## Description

Processeur de tâches récurrentes qui orchestre l'exécution d'un lot de tâches. Il gère le cycle de vie complet des tâches récurrentes : démarrage, exécution périodique et terminaison.

## Hiérarchie / Implémentations

```
RecurringTaskProcessorInterface
    └── RecurringTaskProcessor
```

## Rôle principal

Ce processeur est le cœur du traitement des tâches récurrentes. Il :

1. **Récupère** les tâches en attente (`WAITING`) et actives (`PLAYING`)
2. **Détermine** les actions à effectuer (démarrer, exécuter, terminer)
3. **Orchestre** l'exécution via le `RecurringTaskRunner`
4. **Gère** les transitions d'état (WAITING → PLAYING → FINISHED)
5. **Calcule** les prochaines exécutions selon les intervalles

## API

### `process(?int $limit = null): ProcessResultRecord`

Point d'entrée principal du processeur.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `?int` | Nombre maximum de tâches à exécuter (`null` = illimité) |

**Retourne :** `ProcessResultRecord` - Résultat du traitement (succès, échecs, finitions)

**Exemple :**
```php
$processor = new RecurringTaskProcessor($repository, $runner, $validator);
$result = $processor->process(10);
```

---

### `shouldRunAgain(RecurringTaskRecord $record): bool`

Vérifie si une tâche en `PLAYING` doit être exécutée à nouveau selon son intervalle.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `RecurringTaskRecord` | Tâche à vérifier |

**Retourne :** `bool` - `true` si la tâche doit être ré-exécutée

**Conditions :**
- Statut = `PLAYING`
- Non expirée (`end_at` non dépassé)
- Dernière exécution + intervalle ≤ maintenant

**Exemple :**
```php
$lastRun = new Iso8601DateTimeVO('2026-06-22T10:00:00+00:00');
$record = new RecurringTaskRecord(
    // ...
    last_run_at: $lastRun,
    interval_seconds: new CounterVO(3600),
    status: RecurringTaskStatus::PLAYING,
);

$shouldRun = $processor->shouldRunAgain($record);
// true si maintenant >= 11:00:00
```

---

### `modelToRecord(RecurringTask $model): RecurringTaskRecord`

Convertit un modèle Eloquent en Record DTO.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$model` | `RecurringTask` | Modèle Eloquent à convertir |

**Retourne :** `RecurringTaskRecord` - DTO de la tâche

**Exemple :**
```php
$model = RecurringTask::find(1);
$record = $processor->modelToRecord($model);
// $record est un RecurringTaskRecord immuable
```

## Cas d'utilisation

### Cas 1 : Traitement standard des tâches récurrentes

```php
$processor = app(RecurringTaskProcessor::class);
$result = $processor->process();

echo "Succès: {$result->success->value}\n";
echo "Échecs: {$result->failed->value}\n";
echo "Terminées: {$result->finished->value}\n";
```

### Cas 2 : Traitement avec limite

```php
// Traiter uniquement les 5 premières tâches
$result = $processor->process(5);
```

### Cas 3 : Tâche en PLAYING avec intervalle

```php
// Une tâche avec last_run_at = 10:00, interval = 3600 (1h)
// À 10:30 → ne sera pas exécutée (intervalle non atteint)
// À 11:00 → sera exécutée (intervalle atteint)
```

### Cas 4 : Tâche en WAITING qui expire avant de démarrer

```php
// Tâche avec start_at = 10:00, end_at = 09:00
// Le processeur la termine directement sans l'exécuter
```

## Flux d'exécution

```
┌─────────────────────────────────────────────────────────────────────┐
│                    RecurringTaskProcessor                          │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  ÉTAPE 1 : Récupérer les tâches                                    │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  findWaiting()  → Tâches à démarrer                        │   │
│  │  findPlaying()  → Tâches déjà actives                     │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  ÉTAPE 2 : Analyser les WAITING                                   │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  Pour chaque tâche WAITING :                                │   │
│  │  ├─ end_at dépassé ? → tasksToFinish                       │   │
│  │  └─ start_at atteint ? → tasksToPlay                       │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  ÉTAPE 3 : Analyser les PLAYING                                   │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  Pour chaque tâche PLAYING :                                │   │
│  │  ├─ end_at dépassé ? → tasksToFinish                       │   │
│  │  └─ intervalle dépassé ? → tasksToPlay                     │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  ÉTAPE 4 : Terminer les tâches (moveToFinished)                   │
│                              │                                      │
│                              ▼                                      │
│  ÉTAPE 5 : Appliquer la limite                                    │
│                              │                                      │
│                              ▼                                      │
│  ÉTAPE 6 : Exécuter les tâches                                    │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  Pour chaque tâche dans tasksToPlay :                       │   │
│  │  ├─ WAITING → moveToPlaying()                              │   │
│  │  ├─ Récupérer la tâche mise à jour                         │   │
│  │  ├─ Exécuter via le runner                                 │   │
│  │  └─ Vérifier si doit être terminée après exécution         │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  SORTIE : ProcessResultRecord                                      │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  success: nombre de succès                                  │   │
│  │  failed: nombre d'échecs                                    │   │
│  │  finished: nombre terminées                                 │   │
│  │  errors: collection des erreurs                             │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## Gestion des erreurs

| Situation | Exception | Message |
|-----------|-----------|---------|
| Tâche non trouvée après move | `RuntimeException` | `Task not found: {alias}` |
| Runner échoue | ❌ Non bloquant | L'erreur est ajoutée à `$errors` |
| Erreur de validation | ❌ Non bloquant | L'erreur est ajoutée à `$errors` |

## Transitions d'état

```
┌─────────────────────────────────────────────────────────────────────┐
│                    Cycle de vie d'une tâche récurrente             │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  WAITING ──────────────────────────────────────────────────────────►│
│     │                                                               │
│     │  start_at <= now                                              │
│     ▼                                                               │
│  PLAYING ──────────────────────────────────────────────────────────►│
│     │                                                               │
│     │  Chaque cycle :                                               │
│     │  1. shouldRunAgain() vérifie l'intervalle                    │
│     │  2. Exécution via le runner                                  │
│     │  3. updateAfterRun() met à jour last_run_at                  │
│     │                                                               │
│     │  end_at <= now                                                │
│     ▼                                                               │
│  FINISHED ◄─────────────────────────────────────────────────────────│
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## Performance

- **Complexité** : O(n) où n = nombre de tâches récupérées
- **Mémoire** : Les tâches sont chargées en mémoire via les collections
- **Base de données** : 2 requêtes (`findWaiting`, `findPlaying`) + requêtes pour les mises à jour
- **Limite** : Permet de contrôler la charge

## Compatibilité

| Version | Support |
|---------|---------|
| PHP 8.1+ | ✅ Complet |
| Laravel 10+ | ✅ Complet |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Processors\RecurringTaskProcessor;
use AndyDefer\Task\Records\ProcessResultRecord;

// Récupérer le processeur
$processor = app(RecurringTaskProcessor::class);

// Exécuter avec limite de 10 tâches
$result = $processor->process(10);

// Afficher les résultats
echo "Traitement terminé.\n";
echo "✅ Succès: {$result->success->value}\n";
echo "❌ Échecs: {$result->failed->value}\n";
echo "🏁 Terminées: {$result->finished->value}\n";

// Afficher les erreurs
foreach ($result->errors as $error) {
    echo "Erreur: {$error->identifier} - {$error->error}\n";
}

// Vérifier le statut global
$hasErrors = $result->failed->value > 0 || $result->finished->value > 0;
echo $hasErrors ? "⚠️ Des erreurs sont survenues" : "✅ Tout s'est bien passé";
```

## Voir aussi

- `RecurringTaskRunner` - Exécuteur de tâches récurrentes
- `RecurringTaskValidator` - Validation des tâches
- `RecurringTaskRepository` - Accès aux données
- `ProcessResultRecord` - Structure de résultat
- `UniqueTaskProcessor` - Processeur pour les tâches uniques