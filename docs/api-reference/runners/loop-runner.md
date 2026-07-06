# LoopRunner - Référence Technique

## Description

Exécuteur de la boucle principale de watch. Il orchestre l'exécution continue des cycles de traitement, gère les signaux, et agrège les résultats sur plusieurs itérations.

## Hiérarchie / Implémentations

```
LoopRunner
```

## Rôle principal

Assurer l'exécution continue du watch en :
- Gérant la boucle principale (`while`)
- Vérifiant les conditions de continuation/arrêt
- Exécutant les cycles via `CycleExecutor`
- Agrégeant les résultats de chaque acycle
- Gérant les signaux d'interruption
- Retournant un résultat global du watch

## API / Méthodes publiques

### `run(WatchLoopStrategyInterface $strategy, bool $hasOptionUniqueOnly, bool $hasOptionRecurringOnly, ?LimitVO $limit, bool $verbose, ?DurationVO $duration, ?Iso8601DateTimeVO $startedAt, DurationVO $intervalSeconds, ?int $parallelWorkers = null): LoopResultRecord`

Exécute la boucle principale de watch.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$strategy` | `WatchLoopStrategyInterface` | Stratégie de boucle (production/test) |
| `$hasOptionUniqueOnly` | `bool` | Option `--unique-only` active |
| `$hasOptionRecurringOnly` | `bool` | Option `--recurring-only` active |
| `$limit` | `LimitVO|null` | Limite de tâches (optionnelle) |
| `$verbose` | `bool` | Mode verbeux actif |
| `$duration` | `DurationVO|null` | Durée maximale (null = illimité) |
| `$startedAt` | `Iso8601DateTimeVO|null` | Date/heure de début |
| `$intervalSeconds` | `DurationVO` | Intervalle entre les cycles |
| `$parallelWorkers` | `int|null` | Nombre de workers parallèles (null = séquentiel) |

**Retourne :** `LoopResultRecord` - Résultat global du watch

**Exemple :**
```php
$runner = new LoopRunner($cycleExecutor, $signalHandler, $renderer);

$result = $runner->run(
    strategy: new ProductionWatchStrategy(),
    hasOptionUniqueOnly: false,
    hasOptionRecurringOnly: false,
    limit: null,
    verbose: false,
    duration: new DurationVO(300),
    startedAt: new Iso8601DateTimeVO(),
    intervalSeconds: new DurationVO(60),
    parallelWorkers: 3
);

echo "Cycles : {$result->cycle_count->getValue()}\n";
echo "Succès : {$result->total_success->getValue()}\n";
echo "Échecs : {$result->total_failed->getValue()}\n";
```

## Flux d'exécution

```
run()
    │
    ├── while (shouldContinueLoop)
    │   │
    │   ├── 1. Incrémentation
    │   │   ├── iteration++
    │   │   └── cycleCount++
    │   │
    │   ├── 2. Exécution du cycle
    │   │   └── executeCycle()
    │   │       └── cycleExecutor->execute()
    │   │
    │   ├── 3. Agrégation des résultats
    │   │   └── aggregateCycleResult()
    │   │       ├── hasErrors = hasErrors || cycleResult->hasErrors
    │   │       ├── totalSuccess += cycleResult->success
    │   │       ├── totalFailed += cycleResult->failed
    │   │       ├── totalErrors += cycleResult->errors
    │   │       └── lastException = cycleResult->message
    │   │
    │   ├── 4. Vérification d'arrêt
    │   │   └── shouldStopLoop() ?
    │   │       ├── Oui → break
    │   │       └── Non → continue
    │   │
    │   └── 5. Attente
    │       └── strategy->waitForInterval()
    │
    └── 6. Construction du résultat
        └── buildLoopResult()
```

## Conditions de continuation

### `shouldContinueLoop()`

La boucle continue si :
1. `strategy->shouldContinue()` retourne `true`
2. Les signaux sont dispatchés
3. Aucun signal d'arrêt n'est reçu

### `shouldStopLoop()`

La boucle s'arrête si :
1. Un signal d'arrêt est reçu (`signalHandler->shouldStop()`)
2. `shouldContinueLoop()` retourne `false`

## Agrégation des résultats

### `aggregateCycleResult()`

| Agrégation | Source | Destination |
|------------|--------|-------------|
| Erreurs | `cycleResult->has_errors` | `this->hasErrors` |
| Succès | `cycleResult->success` | `this->totalSuccess` |
| Échecs | `cycleResult->failed` | `this->totalFailed` |
| Erreurs | `cycleResult->errors` | `this->totalErrors` |
| Exception | `cycleResult->message` | `this->lastException` |

## Cas d'utilisation

### Cas 1 : Watch illimité

**Problème :** Exécuter le watch indéfiniment jusqu'à signal.

```php
$result = $runner->run(
    strategy: new ProductionWatchStrategy(),
    hasOptionUniqueOnly: false,
    hasOptionRecurringOnly: false,
    limit: null,
    verbose: false,
    duration: null,  // Illimité
    startedAt: new Iso8601DateTimeVO(),
    intervalSeconds: new DurationVO(60),
    parallelWorkers: null
);

// La boucle s'arrête uniquement sur signal (Ctrl+C)
```

---

### Cas 2 : Watch avec durée limitée

**Problème :** Exécuter le watch pendant 5 minutes.

```php
$duration = new DurationVO(300);
$startedAt = new Iso8601DateTimeVO();

$result = $runner->run(
    strategy: new ProductionWatchStrategy(),
    hasOptionUniqueOnly: false,
    hasOptionRecurringOnly: false,
    limit: null,
    verbose: false,
    duration: $duration,
    startedAt: $startedAt,
    intervalSeconds: new DurationVO(60),
    parallelWorkers: null
);

// S'arrête automatiquement après 5 minutes
```

---

### Cas 3 : Watch avec parallélisme

**Problème :** Exécuter le watch avec 3 workers parallèles.

```php
$result = $runner->run(
    strategy: new ProductionWatchStrategy(),
    hasOptionUniqueOnly: true,
    hasOptionRecurringOnly: false,
    limit: new LimitVO(50),
    verbose: true,
    duration: new DurationVO(300),
    startedAt: new Iso8601DateTimeVO(),
    intervalSeconds: new DurationVO(30),
    parallelWorkers: 3
);
```

---

### Cas 4 : Watch en mode test

**Problème :** Exécuter le watch en mode test (in-process).

```php
$result = $runner->run(
    strategy: new TestingWatchStrategy(),
    hasOptionUniqueOnly: false,
    hasOptionRecurringOnly: false,
    limit: null,
    verbose: false,
    duration: new DurationVO(60),
    startedAt: new Iso8601DateTimeVO(),
    intervalSeconds: new DurationVO(10),
    parallelWorkers: null
);
```

## Gestion des signaux

### Flux des signaux

```
Signal (SIGINT/SIGTERM)
    │
    ├── SignalHandler::install()
    │   └── Définit shouldStop = true
    │
    ├── LoopRunner::shouldContinueLoop()
    │   ├── signalHandler->dispatch()
    │   └── signalHandler->shouldStop() → false
    │
    ├── Exécution du cycle en cours
    │   └── Le cycle se termine
    │
    ├── LoopRunner::shouldStopLoop()
    │   └── signalHandler->shouldStop() → true → break
    │
    └── Arrêt de la boucle
```

## Résultat final

### `LoopResultRecord`

```php
new LoopResultRecord(
    cycle_count: CounterVO,      // Nombre de cycles
    total_success: CounterVO,    // Total des succès
    total_failed: CounterVO,     // Total des échecs
    total_errors: CounterVO,     // Total des erreurs
    has_errors: bool,            // Au moins une erreur
    last_exception: ?DescriptionVO // Dernière exception
)
```

## Intégration

### Dépendances

| Dépendance | Rôle |
|------------|------|
| `CycleExecutor` | Exécution des cycles |
| `SignalHandler` | Gestion des signaux |
| `WatchRendererInterface` | Affichage des informations |
| `WatchLoopStrategyInterface` | Stratégie de boucle |

### Points d'utilisation

| Composant | Utilisation |
|-----------|-------------|
| `TasksWatchDirective` | Point d'entrée principal |

## Performance

- **Complexité** : O(n × m) où n = cycles, m = tâches par cycle
- **Mémoire** : Agrégation incrémentale, pas de stockage des historiques
- **Attente** : Utilisation de `sleep()` entre les cycles

## Compatibilité

| Version PHP | Support |
|-------------|---------|
| PHP 8.2+ | ✅ Complet |
| PHP 8.1 | ✅ Complet |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Runners\LoopRunner;
use AndyDefer\Task\Executors\CycleExecutor;
use AndyDefer\Task\Handlers\SignalHandler;
use AndyDefer\Task\Services\WatchService;
use AndyDefer\Task\Services\WatchRendererService;
use AndyDefer\Task\Strategies\ProductionWatchStrategy;
use AndyDefer\ConsoleWriter\Console\Console;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;

// 1. Création des dépendances
$console = new Console();
$service = new WatchService($console);
$renderer = new WatchRendererService($console);

$signalHandler = new SignalHandler($renderer);
$signalHandler->install();

$cycleExecutor = new CycleExecutor($service, $renderer);
$runner = new LoopRunner($cycleExecutor, $signalHandler, $renderer);

// 2. Exécution du watch
$strategy = new ProductionWatchStrategy();
$startedAt = new Iso8601DateTimeVO();

$result = $runner->run(
    strategy: $strategy,
    hasOptionUniqueOnly: true,
    hasOptionRecurringOnly: false,
    limit: new LimitVO(50),
    verbose: true,
    duration: new DurationVO(300),  // 5 minutes
    startedAt: $startedAt,
    intervalSeconds: new DurationVO(30),
    parallelWorkers: 3
);

// 3. Affichage du résumé
echo "\n=== Watch terminé ===\n";
echo "Cycles : {$result->cycle_count->getValue()}\n";
echo "Succès : {$result->total_success->getValue()}\n";
echo "Échecs : {$result->total_failed->getValue()}\n";
echo "Erreurs : {$result->total_errors->getValue()}\n";

if ($result->has_errors) {
    echo "⚠️ Des erreurs sont survenues\n";
    if ($result->last_exception !== null) {
        echo "Dernière exception : {$result->last_exception->getValue()}\n";
    }
}
```

## Voir aussi

- `CycleExecutor` - Exécuteur de cycles
- `SignalHandler` - Gestionnaire de signaux
- `WatchLoopStrategyInterface` - Stratégies de boucle
- `ProductionWatchStrategy` - Stratégie production
- `TestingWatchStrategy` - Stratégie test
- `LoopResultRecord` - Structure de résultat
---