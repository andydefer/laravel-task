# CycleExecutor - Référence Technique

## Description

Exécuteur de cycles pour le watch des tâches. Il orchestre l'exécution d'un cycle unique de traitement en déléguant au service de watch et en rendant les résultats.

## Hiérarchie / Implémentations

```
CycleExecutor
```

## Rôle principal

Assurer l'exécution d'un cycle de watch en :
- Vérifiant l'arrêt demandé
- Affichant le début du cycle
- Affichant le mode parallèle (si activé)
- Construisant les arguments via `WatchInterface`
- Exécutant le cycle via `WatchInterface`
- Affichant la fin du cycle avec les résultats

## API / Méthodes publiques

### `execute(CounterVO $cycleCount, bool $hasOptionUniqueOnly, bool $hasOptionRecurringOnly, ?LimitVO $limit, bool $verbose, bool $shouldStop, DurationVO $intervalSeconds, ?int $parallelWorkers = null): ?CycleResultRecord`

Exécute un cycle de traitement.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$cycleCount` | `CounterVO` | Numéro du cycle |
| `$hasOptionUniqueOnly` | `bool` | Option `--unique-only` active |
| `$hasOptionRecurringOnly` | `bool` | Option `--recurring-only` active |
| `$limit` | `LimitVO|null` | Limite de tâches (optionnelle) |
| `$verbose` | `bool` | Mode verbeux actif |
| `$shouldStop` | `bool` | Signal d'arrêt reçu |
| `$intervalSeconds` | `DurationVO` | Intervalle entre les cycles |
| `$parallelWorkers` | `int|null` | Nombre de workers parallèles (null = séquentiel) |

**Retourne :** `CycleResultRecord|null` - Résultat du cycle ou `null` si arrêté

**Exemple :**
```php
$executor = new CycleExecutor($service, $renderer);

$result = $executor->execute(
    cycleCount: new CounterVO(1),
    hasOptionUniqueOnly: true,
    hasOptionRecurringOnly: false,
    limit: new LimitVO(10),
    verbose: false,
    shouldStop: false,
    intervalSeconds: new DurationVO(60),
    parallelWorkers: 3
);

if ($result !== null) {
    echo "Succès : {$result->success->getValue()}\n";
}
```

## Flux d'exécution

```
execute()
    │
    ├── 1. Vérification d'arrêt
    │   └── shouldStop ? → retour null
    │
    ├── 2. Début du cycle
    │   └── renderCycleStart()
    │
    ├── 3. Affichage du parallélisme (si > 1)
    │   └── renderParallelExecution()
    │
    ├── 4. Construction des arguments
    │   └── service->buildArguments()
    │
    ├── 5. Exécution du cycle
    │   └── service->executeCycle()
    │
    ├── 6. Fin du cycle
    │   └── renderCycleEnd()
    │
    └── 7. Retour du résultat
```

## Cas d'utilisation

### Cas 1 : Cycle séquentiel standard

**Problème :** Exécuter un cycle séquentiel (sans parallélisme).

```php
$result = $executor->execute(
    cycleCount: new CounterVO(1),
    hasOptionUniqueOnly: false,
    hasOptionRecurringOnly: false,
    limit: null,
    verbose: false,
    shouldStop: false,
    intervalSeconds: new DurationVO(60),
    parallelWorkers: null
);
```

---

### Cas 2 : Cycle avec parallélisme

**Problème :** Exécuter un cycle avec 3 workers parallèles.

```php
$result = $executor->execute(
    cycleCount: new CounterVO(1),
    hasOptionUniqueOnly: true,
    hasOptionRecurringOnly: false,
    limit: new LimitVO(50),
    verbose: true,
    shouldStop: false,
    intervalSeconds: new DurationVO(30),
    parallelWorkers: 3
);
```

---

### Cas 3 : Cycle avec arrêt demandé

**Problème :** Le watch doit s'arrêter (signal reçu).

```php
$result = $executor->execute(
    cycleCount: new CounterVO(1),
    hasOptionUniqueOnly: false,
    hasOptionRecurringOnly: false,
    limit: null,
    verbose: false,
    shouldStop: true,  // ← Arrêt demandé
    intervalSeconds: new DurationVO(60),
    parallelWorkers: null
);

// $result === null
```

---

### Cas 4 : Cycle filtré (unique seulement)

**Problème :** Traiter uniquement les tâches uniques.

```php
$result = $executor->execute(
    cycleCount: new CounterVO(1),
    hasOptionUniqueOnly: true,
    hasOptionRecurringOnly: false,
    limit: null,
    verbose: false,
    shouldStop: false,
    intervalSeconds: new DurationVO(60),
    parallelWorkers: null
);
```

## Affichage

### Début de cycle
```
🔄 Cycle #1 (started at 14:30:15):
⚡ Parallel execution: 3 workers
```

### Fin de cycle
```
✅ 5 tasks succeeded, ❌ 2 tasks failed
⏱️  Cycle duration: 2.34 seconds
⏳ Next cycle in 57 seconds...
```

## Intégration

### Dépendances

| Dépendance | Rôle |
|------------|------|
| `WatchInterface` | Service de watch (exécution et arguments) |
| `WatchRendererInterface` | Affichage des informations |

### Points d'utilisation

| Composant | Utilisation |
|-----------|-------------|
| `LoopRunner` | Exécution des cycles en boucle |
| `TasksWatchDirective` | Point d'entrée principal |

## Performance

- **Complexité** : O(n) où n est le nombre de tâches traitées
- **Mémoire** : Le résultat du cycle est chargé en mémoire
- **Recommandation** : Les cycles sont exécutés à intervalles réguliers

## Compatibilité

| Version PHP | Support |
|-------------|---------|
| PHP 8.2+ | ✅ Complet |
| PHP 8.1 | ✅ Complet |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Executors\CycleExecutor;
use AndyDefer\Task\Services\WatchService;
use AndyDefer\Task\Services\WatchRendererService;
use AndyDefer\ConsoleWriter\Console\Console;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\LimitVO;

// 1. Création des dépendances
$console = new Console();
$service = new WatchService($console);
$renderer = new WatchRendererService($console);
$executor = new CycleExecutor($service, $renderer);

// 2. Cycle séquentiel
$result1 = $executor->execute(
    cycleCount: new CounterVO(1),
    hasOptionUniqueOnly: false,
    hasOptionRecurringOnly: false,
    limit: null,
    verbose: false,
    shouldStop: false,
    intervalSeconds: new DurationVO(60),
    parallelWorkers: null
);

if ($result1 !== null) {
    echo "Cycle 1 terminé\n";
}

// 3. Cycle avec parallélisme
$result2 = $executor->execute(
    cycleCount: new CounterVO(2),
    hasOptionUniqueOnly: true,
    hasOptionRecurringOnly: false,
    limit: new LimitVO(50),
    verbose: true,
    shouldStop: false,
    intervalSeconds: new DurationVO(30),
    parallelWorkers: 4
);

if ($result2 !== null) {
    echo "Cycle 2 terminé\n";
    echo "Succès : {$result2->success->getValue()}\n";
    echo "Échecs : {$result2->failed->getValue()}\n";
}

// 4. Cycle avec arrêt
$result3 = $executor->execute(
    cycleCount: new CounterVO(3),
    hasOptionUniqueOnly: false,
    hasOptionRecurringOnly: false,
    limit: null,
    verbose: false,
    shouldStop: true,
    intervalSeconds: new DurationVO(60),
    parallelWorkers: null
);

// $result3 === null
```

## Voir aussi

- `WatchService` - Service de watch utilisé
- `WatchRendererService` - Renderer utilisé
- `LoopRunner` - Exécuteur de boucle
- `TasksWatchDirective` - Directive utilisant l'exécuteur
- `CycleResultRecord` - Structure de résultat
```