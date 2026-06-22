```markdown
# TasksWatchDirective - Référence Technique

## Description

Directive CLI qui surveille et exécute en continu les tâches en attente dans une boucle configurable, avec gestion des signaux et mode de test intégré.

## Hiérarchie

```
AbstractDirective
    └── TasksWatchDirective
```

## Rôle principal

Orchestrer l'exécution cyclique du traitement des tâches (`process-tasks`) avec :
- Intervalle configurable entre chaque cycle
- Durée d'exécution limitée ou illimitée
- Gestion des signaux (SIGINT, SIGTERM) pour un arrêt propre
- Mode test (`--testing`) pour le développement sans environnement complet

## API / Méthodes publiques

### `getSignature(): string`

| Paramètre | Type | Description |
|-----------|------|-------------|
| Aucun | - | - |

**Retourne :** `string` - Signature de la directive au format Laravel/Console

**Exemple :**
```php
$signature = $directive->getSignature();
// 'tasks-watch {--duration=} {--interval=60} {--unique-only} {--recurring-only} {--limit=} {--verbose} {--testing}'
```

---

### `shouldBootLaravel(): bool`

**Retourne :** `bool` - Toujours `true`, car la directive nécessite Laravel

**Exemple :**
```php
$boot = $directive->shouldBootLaravel(); // true
```

---

### `getDescription(): string`

**Retourne :** `string` - Description lisible de la directive

**Exemple :**
```php
$description = $directive->getDescription();
// 'Watch and process tasks in a continuous loop with configurable interval (in seconds, min 3) and duration. Use --testing for development without full Laravel environment.'
```

---

### `getAliases(): StringTypedCollection`

**Retourne :** `StringTypedCollection` - Collection des alias de la directive

**Exemple :**
```php
$aliases = $directive->getAliases();
// Collection contenant 'task-watch' et 'tasks-watch'
```

---

### `handleSignal(int $signal): void`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$signal` | `int` | Signal reçu (SIGINT, SIGTERM, etc.) |

**Retourne :** `void`

**Description :** Gère les signaux système pour arrêter proprement la directive.

**Exemple :**
```php
$directive->handleSignal(SIGINT);
// Affiche un message d'arrêt et arrête la boucle
```

## Cas d'utilisation

### Cas 1 : Surveillance en production

```bash
./vendor/bin/directive tasks-watch --interval=30 --duration=3600 --verbose
```

**Explication :** Exécute les tâches toutes les 30 secondes pendant 1 heure, avec affichage détaillé.

### Cas 2 : Mode test pour le développement

```bash
./vendor/bin/directive tasks-watch --testing --duration=5 --interval=3
```

**Explication :** Exécute en mode test (sans CLI externe) pendant 5 secondes avec un intervalle de 3 secondes.

### Cas 3 : Tâches uniques uniquement

```bash
./vendor/bin/directive tasks-watch --unique-only --limit=10 --interval=60
```

**Explication :** Traite uniquement les tâches uniques, maximum 10 par cycle, toutes les 60 secondes.

### Cas 4 : Tâches récurrentes uniquement avec verbose

```bash
./vendor/bin/directive tasks-watch --recurring-only --verbose --duration=1800
```

**Explication :** Traite uniquement les tâches récurrentes pendant 30 minutes avec logs détaillés.

## Flux d'exécution

```
execute()
    │
    ├── initializeServices() → WatchService + WatchRendererService
    │
    ├── handleTestingMode() → Active le mode test si --testing
    │
    ├── validateOptions() → Valide les options (duration, interval, limit)
    │
    ├── installSignalHandlers() → Installe les handlers SIGINT/SIGTERM
    │
    └── while (shouldContinue())
            │
            ├── cycleCount++
            │
            ├── executeCycle()
            │       │
            │       ├── buildArguments()
            │       ├── executeCycle() → WatchService
            │       └── renderCycleEnd() → WatchRendererService
            │
            ├── Mise à jour des totaux
            │
            └── waitForInterval() → Pause entre les cycles
```

## Gestion des erreurs

| Situation | Exception | Message |
|-----------|-----------|---------|
| --unique-only et --recurring-only ensemble | `ExitCode::INVALID_ARGUMENT` | `Cannot use both --unique-only and --recurring-only` |
| Duration négative ou nulle | `ExitCode::INVALID_ARGUMENT` | `Duration must be a positive integer (in seconds)` |
| Interval inférieur à 3 secondes | `ExitCode::INVALID_ARGUMENT` | `Interval must be at least 3 seconds` |
| Limit négative ou nulle | `ExitCode::INVALID_ARGUMENT` | `Limit must be a positive integer` |

## Intégration

### Dépendances

| Service | Interface | Rôle |
|---------|-----------|------|
| `WatchService` | `WatchServiceInterface` | Logique métier (exécution des cycles) |
| `WatchRendererService` | `WatchRendererServiceInterface` | Rendu des messages utilisateur |
| `DirectiveTestingService` | - | Mode test (si activé) |

### Enregistrement dans le conteneur Laravel

```php
$this->app->singleton(
    abstract: TasksWatchDirective::class,
    concrete: function (Application $app) {
        return new TasksWatchDirective(
            context: $app->make(DirectiveContext::class),
            interaction: $app->make(DirectiveInteractionService::class)
        );
    }
);
```

## Performance

| Aspect | Considération |
|--------|---------------|
| **Boucle** | Tourne indéfiniment jusqu'à l'arrêt (duration ou signal) |
| **Intervalle** | `sleep(1)` en boucle pour respecter l'intervalle |
| **Consommation CPU** | Négligeable pendant les pauses (`sleep`) |
| **Mémoire** | Utilisation constante (quelques KB pour les compteurs) |
| **Signaux** | Utilise `pcntl_signal_dispatch()` pour la réactivité |

## Compatibilité

| Version | Support |
|---------|---------|
| PHP 8.1+ | ✅ Complet |
| PHP 8.2+ | ✅ Complet |
| Laravel 12.x | ✅ Complet |
| Laravel 13.x | ✅ Complet |
| Laravel 14.x | ✅ Complet |
| Laravel 15.x | ✅ Complet |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Directive\Contexts\DirectiveContext;
use AndyDefer\Directive\Services\DirectiveInteractionService;
use AndyDefer\Task\Directives\TasksWatchDirective;

// Création de la directive
$context = new DirectiveContext();
$interaction = new DirectiveInteractionService();
$directive = new TasksWatchDirective($context, $interaction);

// Exécution en mode test (simulé en CLI)
$argv = ['directive', 'tasks-watch', '--testing', '--duration=5', '--interval=3'];
$exitCode = $directive->run($argv);
// Sortie:
// 🚀 Starting tasks watch loop...
//    🔬 Mode: TESTING (in-process execution)
//    Duration: 5 seconds (5s)
//    Interval: 3 seconds (3s)
//
// ================================================================================
// 🔄 Cycle #1 (started at 14:30:15):
//    ⏳ No tasks to process
//    ⏱️  Cycle duration: 0.00 seconds
//    ⏳ Next cycle in 3 seconds...
// ================================================================================
// 📊 === Summary ===
//    Cycles executed:  1
//    Total success:    0
//    Total failures:   0
//    Total errors:     0
//    Total duration:   5s
//
// ⏰ Duration reached. Stopping gracefully...
// ================================================================================
```

## Voir aussi

- `WatchService` - Service de logique métier pour la surveillance
- `WatchRendererService` - Service de rendu des messages
- `ProcessTasksDirective` - Directive de traitement des tâches
- `DirectiveTestingService` - Service de test pour les directives
```