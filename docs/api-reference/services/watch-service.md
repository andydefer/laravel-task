# WatchService - Référence Technique

## Description

Service de surveillance et d'exécution continue des tâches. Il orchestre les cycles d'exécution du `tasks-watch` en appelant la directive `process-tasks` à intervalles réguliers, avec support du mode test et de l'exécution parallèle.

## Hiérarchie / Implémentations

```
WatchInterface
    └── WatchService
```

## Rôle principal

Assurer l'exécution continue des tâches en :
- Construisant les arguments CLI pour `process-tasks`
- Exécutant des cycles de traitement via la directive
- Gérant le mode test (exécution in-process)
- Déterminant si le watch doit continuer (durée, signaux)
- Gérant les intervalles d'attente entre les cycles
- Formattant les durées pour l'affichage

## API / Méthodes publiques

### `enableTestingMode(DirectiveTestingService $testingService): void`

Active le mode test pour exécuter les tâches in-process.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$testingService` | `DirectiveTestingService` | Service de test pour l'exécution in-process |

**Exemple :**
```php
$service->enableTestingMode(new DirectiveTestingService($app));
```

---

### `disableTestingMode(): void`

Désactive le mode test.

---

### `isTestingMode(): bool`

Vérifie si le mode test est actif.

**Retourne :** `bool` - `true` si le mode test est actif

---

### `buildArguments(bool $uniqueOnly, bool $recurringOnly, ?LimitVO $limit, bool $verbose, ?int $parallelWorkers = null): StringTypedCollection`

Construit les arguments CLI pour `process-tasks`.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$uniqueOnly` | `bool` | Option `--unique-only` |
| `$recurringOnly` | `bool` | Option `--recurring-only` |
| `$limit` | `LimitVO|null` | Option `--limit` |
| `$verbose` | `bool` | Option `--verbose` |
| `$parallelWorkers` | `int|null` | Option `--parallel` (si > 1) |

**Retourne :** `StringTypedCollection` - Collection des arguments CLI

**Exemple :**
```php
$args = $service->buildArguments(
    uniqueOnly: true,
    recurringOnly: false,
    limit: new LimitVO(10),
    verbose: true,
    parallelWorkers: 3
);
// ['--unique-only', '--limit=10', '--verbose', '--parallel=3']
```

---

### `executeCycle(CounterVO $cycleNumber, StringTypedCollection $arguments, Iso8601DateTimeVO $cycleStartedAt): CycleResultRecord`

Exécute un cycle de traitement.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$cycleNumber` | `CounterVO` | Numéro du cycle |
| `$arguments` | `StringTypedCollection` | Arguments CLI |
| `$cycleStartedAt` | `Iso8601DateTimeVO` | Date/heure de début du cycle |

**Retourne :** `CycleResultRecord` - Résultat du cycle (succès, échecs, erreurs)

**Exemple :**
```php
$cycleNumber = new CounterVO(1);
$arguments = $service->buildArguments(true, false, null, false);
$startedAt = new Iso8601DateTimeVO();

$result = $service->executeCycle($cycleNumber, $arguments, $startedAt);
echo "Succès : {$result->success->getValue()}\n";
```

---

### `shouldContinue(bool $shouldStop, ?DurationVO $duration, ?Iso8601DateTimeVO $startedAt): bool`

Détermine si le watch doit continuer.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$shouldStop` | `bool` | Signal d'arrêt reçu |
| `$duration` | `DurationVO|null` | Durée maximale (null = illimité) |
| `$startedAt` | `Iso8601DateTimeVO|null` | Date/heure de début |

**Retourne :** `bool` - `true` si le watch doit continuer

---

### `waitForInterval(DurationVO $interval, callable $shouldContinueCallback): void`

Attend l'intervalle spécifié en vérifiant périodiquement si le watch doit s'arrêter.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$interval` | `DurationVO` | Intervalle d'attente |
| `$shouldContinueCallback` | `callable` | Fonction de vérification d'arrêt |

---

### `calculateElapsedSeconds(?Iso8601DateTimeVO $start): float`

Calcule le temps écoulé depuis le début.

---

### `formatDuration(DurationVO $duration): string`

Formate une durée en format lisible.

**Exemple :**
```php
echo $service->formatDuration(new DurationVO(5445));
// "1h 30m 45s"
```

## Flux d'exécution

```
executeCycle()
    │
    ├── 1. Affichage du début du cycle
    │
    ├── 2. Construction des arguments JSON
    │   └── arguments + '--format=json'
    │
    ├── 3. Appel de process-tasks
    │   ├── Mode test → DirectiveTestingService
    │   └── Mode réel → Process Symfony
    │
    ├── 4. Parsing du JSON
    │   ├── FullBatchResponse → FullBatchJsonResultRecord
    │   └── TaskExecutionResponse → TaskExecutionJsonResultRecord
    │
    ├── 5. Calcul des statistiques
    │
    ├── 6. Affichage du résumé
    │
    └── 7. Retour du CycleResultRecord
```

## Mode test vs Mode réel

### Mode test (testing mode)

```
DirectiveTestingService::run()
    └── Exécution in-process de ProcessTasksDirective
        └── Pas de processus enfant
```

**Avantages :**
- Exécution plus rapide
- Débogage facilité
- Pas de dépendance système

### Mode réel (production)

```
PHP_BINARY directive process-tasks --format=json
    └── Process Symfony
        └── Exécution en processus séparé
```

**Avantages :**
- Isolation mémoire
- Comportement identique à la production

## Cas d'utilisation

### Cas 1 : Watch continu

**Problème :** Surveiller et traiter les tâches en continu.

```php
$service = new WatchService($console);
$service->enableTestingMode($testingService);

$cycleNumber = new CounterVO(1);
$arguments = $service->buildArguments(
    uniqueOnly: false,
    recurringOnly: false,
    limit: null,
    verbose: false
);
$startedAt = new Iso8601DateTimeVO();

while ($service->shouldContinue(false, null, null)) {
    $result = $service->executeCycle($cycleNumber, $arguments, $startedAt);
    $cycleNumber = $cycleNumber->increment();
    
    $service->waitForInterval(new DurationVO(60), function() {
        return $service->shouldContinue(false, null, null);
    });
}
```

---

### Cas 2 : Watch avec durée limitée

**Problème :** Exécuter le watch pendant 5 minutes.

```php
$duration = new DurationVO(300);
$startedAt = new Iso8601DateTimeVO();

while ($service->shouldContinue(false, $duration, $startedAt)) {
    // Exécution du cycle
    $service->waitForInterval(new DurationVO(10), function() use ($duration, $startedAt) {
        return $service->shouldContinue(false, $duration, $startedAt);
    });
}
```

---

### Cas 3 : Watch avec options spécifiques

**Problème :** Surveiller uniquement les tâches uniques avec limite.

```php
$arguments = $service->buildArguments(
    uniqueOnly: true,
    recurringOnly: false,
    limit: new LimitVO(50),
    verbose: false,
    parallelWorkers: 3
);

$result = $service->executeCycle($cycleNumber, $arguments, $startedAt);
```

## Gestion des erreurs

| Situation | Comportement | Retour |
|-----------|--------------|--------|
| Échec de `process-tasks` | Log d'erreur | `CycleResultRecord` avec succès=0, erreur=1 |
| JSON invalide | Log d'erreur | `CycleResultRecord` avec succès=0, erreur=1 |
| Directive non trouvée | `RuntimeException` | Exception levée |

## Intégration

### Dépendances

- `Console` : Affichage console
- `DirectiveTestingService` : Mode test
- `Process` : Exécution en mode réel

### Points d'utilisation

| Composant | Utilisation |
|-----------|-------------|
| `TasksWatchDirective` | Point d'entrée principal |
| `CycleExecutor` | Exécution des cycles |
| `LoopRunner` | Boucle de watch |

## Performance

- **Mode test** : Exécution rapide, pas de processus enfant
- **Mode réel** : Overhead de processus, mais isolation mémoire
- **Attente** : `sleep()` avec logs périodiques (toutes les 10s)

## Compatibilité

| Version PHP | Support |
|-------------|---------|
| PHP 8.2+ | ✅ Complet |
| PHP 8.1 | ✅ Complet |
| Windows | ⚠️ `pcntl` non disponible, mais `Process` fonctionne |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Services\WatchService;
use AndyDefer\ConsoleWriter\Console\Console;
use AndyDefer\Directive\Services\DirectiveTestingService;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;

$console = new Console();
$service = new WatchService($console);
$testingService = new DirectiveTestingService($app);

// Activation du mode test
$service->enableTestingMode($testingService);

// Arguments
$arguments = $service->buildArguments(
    uniqueOnly: true,
    recurringOnly: false,
    limit: new LimitVO(10),
    verbose: true,
    parallelWorkers: 3
);

// Exécution d'un cycle
$cycleNumber = new CounterVO(1);
$startedAt = new Iso8601DateTimeVO();

$result = $service->executeCycle($cycleNumber, $arguments, $startedAt);

echo "Résultat du cycle :\n";
echo "  Succès : {$result->success->getValue()}\n";
echo "  Échecs : {$result->failed->getValue()}\n";
echo "  Erreurs : {$result->errors->getValue()}\n";

// Vérification de continuation
$duration = new DurationVO(300);
$shouldContinue = $service->shouldContinue(false, $duration, $startedAt);

if ($shouldContinue) {
    // Attente avant le prochain cycle
    $service->waitForInterval(new DurationVO(10), function() use ($service, $duration, $startedAt) {
        return $service->shouldContinue(false, $duration, $startedAt);
    });
}

// Formatage de durée
$elapsed = $service->calculateElapsedSeconds($startedAt);
echo "Temps écoulé : " . $service->formatDuration(new DurationVO($elapsed));
```

## Voir aussi

- `TasksWatchDirective` - Directive utilisant ce service
- `WatchInterface` - Interface du service
- `CycleExecutor` - Utilisateur du service
- `ProcessTasksDirective` - Directive appelée
- `DirectiveTestingService` - Service de test
---