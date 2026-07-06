# TasksWatchDirective - Référence Technique

## Description

Console directive permettant de surveiller et traiter les tâches en continu dans une boucle infinie. Contrairement à `ProcessTasksDirective` qui exécute un traitement unique, cette directive reste active et traite les tâches à intervalles réguliers.

## Hiérarchie / Implémentations

```
AbstractDirective
    └── TasksWatchDirective
```

## Rôle principal

Exécuter un processus de surveillance continue qui traite les tâches en attente à intervalles réguliers. La directive supporte l'exécution parallèle, les signaux d'interruption (SIGINT, SIGTERM) et les modes de test.

## API / Méthodes publiques

### `getSignature(): string`

| Élément | Description |
|---------|-------------|
| **Retourne** | `string` - La signature de la commande avec ses options |

**Exemple :**
```php
$signature = $directive->getSignature();
// 'tasks-watch {--duration=} {--interval=60} {--unique-only} {--recurring-only} {--limit=} {--verbose} {--testing} {--parallel=}'
```

---

### `getDescription(): string`

| Élément | Description |
|---------|-------------|
| **Retourne** | `string` - La description de la commande |

**Exemple :**
```php
$description = $directive->getDescription();
// 'Watch and process tasks in a continuous loop with configurable interval (in seconds, min 3) and duration...'
```

---

### `getAliases(): StringTypedCollection`

| Élément | Description |
|---------|-------------|
| **Retourne** | `StringTypedCollection` - Collection des alias de la commande |

**Exemple :**
```php
$aliases = $directive->getAliases();
// ['task-watch', 'tasks-watch']
```

---

### `execute(): ExitCode`

| Élément | Description |
|---------|-------------|
| **Retourne** | `ExitCode` - SUCCESS (0) ou FAILURE (1) |
| **Exceptions** | `RuntimeException` - Si le conteneur Laravel n'est pas disponible |

**Exemple :**
```php
$exitCode = $directive->execute();
// ExitCode::SUCCESS ou ExitCode::FAILURE
```

## Options de la commande

| Option | Type | Description | Valeur par défaut |
|--------|------|-------------|------------------|
| `--duration=N` | Integer | Durée maximale d'exécution en secondes | `null` (illimité) |
| `--interval=N` | Integer | Intervalle entre les cycles (≥ 3s) | `60` |
| `--unique-only` | Flag | Traite uniquement les tâches uniques | `false` |
| `--recurring-only` | Flag | Traite uniquement les tâches récurrentes | `false` |
| `--limit=N` | Integer | Nombre maximum de tâches par cycle | `null` (illimité) |
| `--verbose` | Flag | Affiche les détails des erreurs | `false` |
| `--testing` | Flag | Mode test (exécution in-process) | `false` |
| `--parallel=N` | Integer | Nombre de workers parallèles (≥ 1) | `null` (séquentiel) |

## Cas d'utilisation

### Cas 1 : Surveillance standard

**Problème :** Démarrer un watcher qui traite les tâches toutes les 30 secondes indéfiniment.

```bash
php directive tasks-watch --interval=30
```

**Sortie :**
```
🚀 Starting tasks watch loop...
Duration: unlimited (Ctrl+C to stop)
Interval: 30 (30s)
================================================================================

🔄 Cycle #1 (started at 12:00:00):
✅ 5 tasks succeeded, ❌ 0 tasks failed
⏱️  Cycle duration: 0.45 seconds
⏳ Next cycle in 29 seconds...

🔄 Cycle #2 (started at 12:00:30):
✅ 2 tasks succeeded, ❌ 0 tasks failed
⏱️  Cycle duration: 0.12 seconds
...
```

---

### Cas 2 : Exécution avec durée limitée

**Problème :** Exécuter le watcher pendant 5 minutes puis s'arrêter automatiquement.

```bash
php directive tasks-watch --duration=300 --interval=10
```

**Sortie :**
```
🚀 Starting tasks watch loop...
Duration: 300 (5m)
Interval: 10 (10s)
...
⏰ Duration reached. Stopping gracefully...
```

---

### Cas 3 : Exécution parallèle avec 3 workers

**Problème :** Traiter les tâches plus rapidement en parallèle.

```bash
php directive tasks-watch --parallel=3 --limit=50 --interval=5
```

**Sortie :**
```
🚀 Starting tasks watch loop...
⚡ Parallel execution: 3 workers
Duration: unlimited (Ctrl+C to stop)
Interval: 5 (5s)
Options: --limit=50 --parallel=3

🔄 Cycle #1 (started at 12:00:00):
✅ 45 tasks succeeded, ❌ 5 tasks failed
⏱️  Cycle duration: 2.34 seconds
```

---

### Cas 4 : Mode test pour développement

**Problème :** Tester localement sans environnement Laravel complet.

```bash
php directive tasks-watch --testing --duration=10 --interval=3
```

**Sortie :**
```
🚀 Starting tasks watch loop...
🔬 Mode: TESTING (in-process execution)
Duration: 10 (10s)
Interval: 3 (3s)
```

---

### Cas 5 : Traitement filtré avec débogage

**Problème :** Surveiller uniquement les tâches récurrentes en mode verbeux.

```bash
php directive tasks-watch --recurring-only --verbose --interval=15
```

**Sortie :**
```
🚀 Starting tasks watch loop...
Duration: unlimited (Ctrl+C to stop)
Interval: 15 (15s)
Options: --recurring-only --verbose

🔄 Cycle #1 (started at 12:00:00):
✅ 3 tasks succeeded, ❌ 1 tasks failed

=== Failed Tasks ===
  Recurring tasks:
    ❌ recurring@abc-123: Connection timeout
```

## Gestion des signaux

| Signal | Comportement |
|--------|--------------|
| `SIGINT` (Ctrl+C) | Arrêt après le cycle en cours |
| `SIGTERM` | Arrêt après le cycle en cours |

**Affichage :**
```
⚠️  Received SIGINT, stopping after current cycle...
```

## Gestion des erreurs

| Situation | Exception | Message |
|-----------|-----------|---------|
| Conteneur Laravel indisponible | `RuntimeException` | `Laravel container is not available` |
| Options mutuellement exclusives | `ExitCode::INVALID_ARGUMENT` | `Cannot use both --unique-only and --recurring-only` |
| Limite invalide (≤ 0) | `ExitCode::INVALID_ARGUMENT` | `Limit must be a positive integer` |
| Intervalle < 3 secondes | `ExitCode::INVALID_ARGUMENT` | `Interval must be at least 3 seconds` |
| Durée ≤ 0 | `ExitCode::INVALID_ARGUMENT` | `Duration must be a positive integer` |
| Workers < 1 | `ExitCode::INVALID_ARGUMENT` | `Parallel workers must be at least 1` |

## Flux d'exécution

```
execute()
    ├── Récupération du conteneur Laravel
    │   └── Échec → RuntimeException
    ├── Validation des options
    │   └── Échec → ExitCode::INVALID_ARGUMENT
    ├── Création de la stratégie (Production/Testing)
    ├── Installation du handler de signaux
    ├── Boucle principale (LoopRunner)
    │   ├── Pour chaque cycle
    │   │   ├── Exécution du cycle (CycleExecutor)
    │   │   ├── Agrégation des résultats
    │   │   ├── Vérification des signaux
    │   │   └── Attente (intervalle)
    │   └── Vérification des conditions d'arrêt
    ├── Affichage du résumé
    └── Retour ExitCode::SUCCESS / FAILURE
```

## Architecture interne

```
TasksWatchDirective
    │
    ├── WatchInterface (Service)
    │   ├── buildArguments()
    │   ├── executeCycle()
    │   └── shouldContinue()
    │
    ├── WatchRendererInterface (Renderer)
    │   ├── renderStartMessage()
    │   ├── renderCycleStart()
    │   ├── renderCycleEnd()
    │   └── renderSummary()
    │
    ├── WatchLoopStrategyFactory (Factory)
    │   ├── ProductionWatchStrategy
    │   └── TestingWatchStrategy
    │
    ├── SignalHandler
    │   ├── install()
    │   └── shouldStop()
    │
    ├── CycleExecutor
    │   └── execute()
    │
    └── LoopRunner
        └── run()
```

## Intégration

### Dépendances injectées

- `WatchInterface` : Service de surveillance
- `WatchRendererInterface` : Service de rendu
- `Console` : Service d'affichage console

### Services requis

- `UniqueTaskServiceInterface` : Pour les tâches uniques
- `RecurringTaskServiceInterface` : Pour les tâches récurrentes

## Performance

- **Complexité** : O(n) par cycle, où n est le nombre de tâches traitées
- **Mémoire** : Les résultats sont agrégués, pas de conservation historique
- **CPU** : L'attente entre les cycles utilise `sleep()`, consommation négligeable
- **Recommandation** : 
  - Utiliser `--limit` pour les volumes importants (> 100 tâches/cycle)
  - Utiliser `--parallel` pour accélérer le traitement

## Compatibilité

| Version PHP | Support |
|-------------|---------|
| PHP 8.2+ | ✅ Complet |
| PHP 8.1 | ✅ Complet |
| PHP 8.0 | ⚠️ Nécessite PHP 8.1+ pour les enums |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Directives\TasksWatchDirective;
use AndyDefer\Directive\Enums\ExitCode;

// Création de l'application Laravel
$app = require __DIR__ . '/bootstrap/app.php';

// Exécution du watcher
$directive = $app->make(TasksWatchDirective::class);

// Watcher standard avec arrêt après 10 minutes
$exitCode = $directive->execute();

if ($exitCode === ExitCode::SUCCESS) {
    echo "Watcher terminé avec succès.\n";
} else {
    echo "Des erreurs sont survenues pendant l'exécution.\n";
}
```

## Voir aussi

- `ProcessTasksDirective` - Traitement unique en batch
- `WatchInterface` - Service de surveillance
- `WatchRendererInterface` - Service de rendu
- `LoopRunner` - Exécuteur de la boucle principale
- `SignalHandler` - Gestionnaire de signaux système
---