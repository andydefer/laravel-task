# WatchRendererService - Référence Technique

## Description

Service de rendu des sorties console pour la directive `tasks-watch`. Il gère l'affichage des messages de démarrage, des progrès des cycles, des résumés et des notifications de signaux.

## Hiérarchie / Implémentations

```
WatchRendererInterface
    └── WatchRendererService
```

## Rôle principal

Assurer l'affichage clair et informatif de l'exécution du watch en :
- Affichant les messages de démarrage avec les options
- Rendant les progressions des cycles
- Produisant des résumés détaillés
- Notifiant les signaux d'interruption
- Formattant les données pour une lecture facile

## API / Méthodes publiques

### `renderStartMessage(?DurationVO $duration, DurationVO $intervalSeconds, StringTypedCollection $options, bool $testingMode, ?int $parallelWorkers = null): void`

Affiche le message de démarrage du watch.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$duration` | `DurationVO|null` | Durée maximale (null = illimité) |
| `$intervalSeconds` | `DurationVO` | Intervalle entre les cycles |
| `$options` | `StringTypedCollection` | Options actives de la commande |
| `$testingMode` | `bool` | Mode test actif |
| `$parallelWorkers` | `int|null` | Nombre de workers parallèles |

**Exemple d'affichage :**
```
╔══════════════════════════════════════════════╗
        🚀 Starting tasks watch loop...        
╚══════════════════════════════════════════════╝
🔬 Mode: TESTING (in-process execution)
⚡ Parallel execution: 3 workers
Duration: 300 (5m)
Interval: 60 (1m)
Options: --unique-only --limit=10

================================================================================
```

---

### `renderCycleStart(CounterVO $cycleNumber, Iso8601DateTimeVO $startedAt): void`

Affiche le début d'un cycle.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$cycleNumber` | `CounterVO` | Numéro du cycle |
| `$startedAt` | `Iso8601DateTimeVO` | Date/heure de début du cycle |

**Exemple d'affichage :**
```
🔄 Cycle #5 (started at 14:30:15):
```

---

### `renderCycleEnd(CycleResultRecord $result, Iso8601DateTimeVO $startedAt, DurationVO $intervalSeconds): void`

Affiche la fin d'un cycle.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$result` | `CycleResultRecord` | Résultat du cycle |
| `$startedAt` | `Iso8601DateTimeVO` | Date/heure de début du cycle |
| `$intervalSeconds` | `DurationVO` | Intervalle entre les cycles |

**Exemple d'affichage :**
```
✅ 5 tasks succeeded, ❌ 2 tasks failed
⏱️  Cycle duration: 2.34 seconds
⏳ Next cycle in 57 seconds...
```

---

### `renderSummary(CounterVO $cycleCount, CounterVO $totalSuccess, CounterVO $totalFailed, CounterVO $totalErrors, Iso8601DateTimeVO $startedAt, bool $testingMode, bool $stoppedBySignal, bool $durationReached, ?DescriptionVO $exception = null): void`

Affiche le résumé final du watch.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$cycleCount` | `CounterVO` | Nombre de cycles exécutés |
| `$totalSuccess` | `CounterVO` | Total des succès |
| `$totalFailed` | `CounterVO` | Total des échecs |
| `$totalErrors` | `CounterVO` | Total des erreurs |
| `$startedAt` | `Iso8601DateTimeVO` | Date/heure de début |
| `$testingMode` | `bool` | Mode test actif |
| `$stoppedBySignal` | `bool` | Arrêt par signal utilisateur |
| `$durationReached` | `bool` | Durée maximale atteinte |
| `$exception` | `DescriptionVO|null` | Dernière exception |

**Exemple d'affichage :**
```
================================================================================
╔═══════════════╗
   📊 Summary   
╚═══════════════╝
🔬 Mode: TESTING
Cycles executed    : 5
Total success      : 42
Total failures     : 8
Total errors       : 3
Total duration     : 5m 30s
⏰ Duration reached. Stopping gracefully...
================================================================================
```

---

### `renderInterruptSignal(SignalName $signalName): void`

Affiche une notification de signal d'interruption.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$signalName` | `SignalName` | Nom du signal reçu |

**Exemple d'affichage :**
```
⚠️  Received SIGINT, stopping after current cycle...
```

---

### `renderTestingModeEnabled(): void`

Affiche la notification d'activation du mode test.

**Exemple d'affichage :**
```
🧪 Testing mode enabled
```

---

### `renderParallelExecution(int $workerCount): void`

Affiche la notification d'exécution parallèle.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$workerCount` | `int` | Nombre de workers parallèles |

**Exemple d'affichage :**
```
⚡ Parallel execution: 3 workers
```

## Formats d'affichage

### Emojis utilisés

| Emoji | Utilisation |
|-------|-------------|
| 🚀 | Démarrage du watch |
| 🔬 | Mode test |
| ⚡ | Exécution parallèle |
| 🔄 | Début de cycle |
| ✅ | Succès |
| ❌ | Échec |
| ⏱️ | Durée |
| ⏳ | Attente |
| 📊 | Résumé |
| 🛑 | Arrêt |
| ⏰ | Durée atteinte |
| 🧪 | Mode test activé |
| ⚠️ | Signal d'interruption |

### Structure de l'affichage

```
╔══════════════════════════════════════════════╗
        🚀 Starting tasks watch loop...        
╚══════════════════════════════════════════════╝
🔬 Mode: TESTING (in-process execution)
⚡ Parallel execution: 3 workers
Duration: 300 (5m)
Interval: 60 (1m)
Options: --unique-only --limit=10

================================================================================


🔄 Cycle #1 (started at 14:30:00):
✅ 3 tasks succeeded, ❌ 0 tasks failed
⏱️  Cycle duration: 0.45 seconds
⏳ Next cycle in 59 seconds...

🔄 Cycle #2 (started at 14:31:00):
✅ 2 tasks succeeded, ❌ 0 tasks failed
⏱️  Cycle duration: 0.12 seconds
⏳ Next cycle in 60 seconds...

... (cycles répétés)

================================================================================
╔═══════════════╗
   📊 Summary   
╚═══════════════╝
🔬 Mode: TESTING
Cycles executed    : 5
Total success      : 42
Total failures     : 8
Total errors       : 3
Total duration     : 5m 30s
⏰ Duration reached. Stopping gracefully...
================================================================================
```

## Cas d'utilisation

### Cas 1 : Watch standard

**Problème :** Afficher un watch standard sans options spéciales.

```php
$renderer->renderStartMessage(
    duration: null,
    intervalSeconds: new DurationVO(60),
    options: new StringTypedCollection(),
    testingMode: false,
    parallelWorkers: null
);
```

---

### Cas 2 : Watch avec mode test

**Problème :** Afficher un watch en mode test.

```php
$renderer->renderStartMessage(
    duration: new DurationVO(300),
    intervalSeconds: new DurationVO(30),
    options: StringTypedCollection::from(['--verbose']),
    testingMode: true,
    parallelWorkers: 3
);
```

---

### Cas 3 : Cycle avec résultats

**Problème :** Afficher un cycle avec des tâches traitées.

```php
$result = CycleResultRecord::from([
    'success' => new CounterVO(5),
    'failed' => new CounterVO(2),
    'errors' => new CounterVO(0),
    'has_errors' => false,
]);

$renderer->renderCycleStart(new CounterVO(1), new Iso8601DateTimeVO());
$renderer->renderCycleEnd($result, new Iso8601DateTimeVO(), new DurationVO(60));
```

---

### Cas 4 : Résumé complet

**Problème :** Afficher un résumé après plusieurs cycles.

```php
$renderer->renderSummary(
    cycleCount: new CounterVO(5),
    totalSuccess: new CounterVO(42),
    totalFailed: new CounterVO(8),
    totalErrors: new CounterVO(3),
    startedAt: new Iso8601DateTimeVO(),
    testingMode: true,
    stoppedBySignal: false,
    durationReached: true,
    exception: null
);
```

## Intégration

### Dépendances

- `Console` : Service d'affichage console

### Points d'utilisation

| Composant | Utilisation |
|-----------|-------------|
| `TasksWatchDirective` | Affichage principal |
| `CycleExecutor` | Affichage des cycles |
| `LoopRunner` | Affichage du résumé |

## Performance

- **Complexité** : O(1) - affichage simple
- **Mémoire** : Aucune allocation mémoire significative
- **Recommandation** : Peut être appelé fréquemment sans impact

## Compatibilité

| Version PHP | Support |
|-------------|---------|
| PHP 8.2+ | ✅ Complet |
| PHP 8.1 | ✅ Complet |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Services\WatchRendererService;
use AndyDefer\ConsoleWriter\Console\Console;
use AndyDefer\DomainStructures\Collections\Utility\StringTypedCollection;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\Records\CycleResultRecord;
use AndyDefer\Task\Enums\SignalName;

$console = new Console();
$renderer = new WatchRendererService($console);

// 1. Message de démarrage
$options = new StringTypedCollection();
$options->add('--unique-only');
$options->add('--limit=10');

$renderer->renderStartMessage(
    duration: new DurationVO(300),
    intervalSeconds: new DurationVO(60),
    options: $options,
    testingMode: true,
    parallelWorkers: 3
);

// 2. Simulation de cycles
for ($i = 1; $i <= 5; $i++) {
    $startedAt = new Iso8601DateTimeVO();
    $renderer->renderCycleStart(new CounterVO($i), $startedAt);
    
    // Simulation de résultat
    $result = CycleResultRecord::from([
        'success' => new CounterVO(rand(1, 5)),
        'failed' => new CounterVO(rand(0, 2)),
        'errors' => new CounterVO(rand(0, 1)),
        'has_errors' => false,
    ]);
    
    $renderer->renderCycleEnd($result, $startedAt, new DurationVO(60));
    
    // Attente simulée
    usleep(100000);
}

// 3. Signal d'interruption simulé
$renderer->renderInterruptSignal(SignalName::SIGINT);

// 4. Résumé
$renderer->renderSummary(
    cycleCount: new CounterVO(5),
    totalSuccess: new CounterVO(42),
    totalFailed: new CounterVO(8),
    totalErrors: new CounterVO(3),
    startedAt: new Iso8601DateTimeVO(),
    testingMode: true,
    stoppedBySignal: true,
    durationReached: false,
    exception: new DescriptionVO('Une erreur est survenue')
);
```

## Voir aussi

- `WatchService` - Service de watch
- `WatchRendererInterface` - Interface du renderer
- `TasksWatchDirective` - Directive utilisant le renderer
- `Console` - Service d'affichage
- `KeyValue` - Composant d'affichage clé/valeur
---