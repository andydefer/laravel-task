# ParallelExecutor - Référence Technique

## Description

Le `ParallelExecutor` orchestre l'exécution parallèle des tâches en utilisant le forking de processus (`pcntl_fork`) avec communication inter-processus via des sockets Unix. Il sert de moteur d'exécution pour la directive `tasks:watch`.

## Hiérarchie / Implémentations

```
ParallelExecutor (final)
    └── Aucune interface implémentée
```

**Classe finale :** Ne peut pas être étendue

## Rôle principal

Ce service agit comme un orchestrateur d'exécution parallèle qui :

1. **Fork des processus workers** pour exécuter des tâches en parallèle
2. **Gère la communication inter-processus** via des paires de sockets Unix
3. **Collecte les résultats** des workers et les agrège
4. **Fonctionne en mode séquentiel** si `pcntl_fork` n'est pas disponible

## API / Méthodes publiques

### `execute(bool $uniqueOnly, bool $recurringOnly, ?LimitVO $limit, bool $verbose, bool $muted = false): array`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$uniqueOnly` | `bool` | Exécuter uniquement les tâches uniques |
| `$recurringOnly` | `bool` | Exécuter uniquement les tâches récurrentes |
| `$limit` | `LimitVO|null` | Nombre maximum de tâches à traiter par worker |
| `$verbose` | `bool` | Activer les logs détaillés |
| `$muted` | `bool` | Désactiver toute sortie (sauf erreurs critiques) |

**Retourne :** `array<TaskExecutionResultRecord>` - Tableau des résultats d'exécution

**Exceptions :** Aucune exception propagée (les erreurs sont capturées et journalisées)

**Comportement :**
1. Si `pcntl_fork` n'est pas disponible → exécution séquentielle
2. Sinon → fork de `maxWorkers` processus
3. Communication via sockets Unix pour récupérer les résultats
4. Collecte et agrège les résultats

**Exemple :**
```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Services\Watchs\ParallelExecutor;
use AndyDefer\Task\ValueObjects\LimitVO;

$executor = new ParallelExecutor(4, $kernel, $output);

$results = $executor->execute(
    uniqueOnly: false,
    recurringOnly: false,
    limit: new LimitVO(10),
    verbose: true,
    muted: false
);

echo "✅ " . count($results) . " workers ont terminé\n";
foreach ($results as $result) {
    echo "  - " . $result->alias->getValue() . "\n";
}
```

---

### `__construct(int $maxWorkers, DirectiveKernel $kernel, OutputHandler $output)`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$maxWorkers` | `int` | Nombre maximum de workers parallèles (minimum: 1) |
| `$kernel` | `DirectiveKernel` | Noyau de directives pour exécuter les sous-processus |
| `$output` | `OutputHandler` | Gestionnaire de sortie pour les logs |

**Comportement :** Assure que `$maxWorkers` est toujours ≥ 1

## Méthodes privées

### `executeSequentially(bool $uniqueOnly, bool $recurringOnly, ?LimitVO $limit, bool $verbose, bool $muted = false): array`

**Utilisation :** Fallback lorsque `pcntl_fork` n'est pas disponible

**Comportement :** Exécute les workers les uns après les autres dans le même processus

---

### `runWorker(int $workerId, bool $uniqueOnly, bool $recurringOnly, ?LimitVO $limit, bool $verbose, bool $muted = false): ?TaskExecutionResultRecord`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$workerId` | `int` | Identifiant unique du worker |
| Autres | `bool`/`LimitVO` | Les mêmes que `execute()` |

**Retourne :** `TaskExecutionResultRecord|null` - Résultat de l'exécution ou null si aucun

**Comportement :**
1. Construit les arguments pour la directive `tasks:process`
2. Ajoute `--mute` pour désactiver les sorties des workers
3. Exécute le kernel avec les arguments
4. Extrait le résultat du contexte du kernel
5. Journalise le code de sortie

---

## Cas d'utilisation

### Cas 1 : Exécution parallèle avec 4 workers

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Services\Watchs\ParallelExecutor;
use AndyDefer\Directive\DirectiveKernel;
use AndyDefer\Task\Handlers\OutputHandler;
use AndyDefer\Task\ValueObjects\LimitVO;

$kernel = DirectiveKernel::init($app);
$output = new OutputHandler($console, $logger);

// Créer un exécuteur avec 4 workers parallèles
$executor = new ParallelExecutor(4, $kernel, $output);

// Exécuter les tâches (uniques et récurrentes) avec une limite de 20 tâches
$limit = new LimitVO(20);
$results = $executor->execute(
    uniqueOnly: false,
    recurringOnly: false,
    limit: $limit,
    verbose: true,
    muted: false
);

echo "🔍 Résultats :\n";
foreach ($results as $result) {
    $status = $result->success ? '✅' : '❌';
    echo "  {$status} {$result->alias->getValue()}\n";
}
```

### Cas 2 : Exécution séquentielle (fallback)

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Services\Watchs\ParallelExecutor;

// Dans un environnement sans pcntl (ex: Windows)
$executor = new ParallelExecutor(4, $kernel, $output);

// L'exécuteur détecte automatiquement l'absence de pcntl
// et bascule en mode séquentiel
$results = $executor->execute(
    uniqueOnly: true,
    recurringOnly: false,
    limit: null,
    verbose: false,
    muted: true
);

// Les workers s'exécutent les uns après les autres
// Le nombre de workers n'a pas d'impact sur la performance
```

### Cas 3 : Exécution uniquement des tâches récurrentes

```php
<?php

$executor = new ParallelExecutor(2, $kernel, $output);

// Seules les tâches récurrentes seront exécutées
$results = $executor->execute(
    uniqueOnly: false,
    recurringOnly: true,
    limit: new LimitVO(50),
    verbose: true,
    muted: false
);

echo "📊 " . count($results) . " tâches récurrentes traitées\n";
```

### Cas 4 : Mode silencieux pour les workers

```php
<?php

$executor = new ParallelExecutor(8, $kernel, $output);

// Les workers s'exécutent en silence
// Seuls les logs du parent sont affichés
$results = $executor->execute(
    uniqueOnly: false,
    recurringOnly: false,
    limit: null,
    verbose: false,
    muted: true  // ← Désactive les sorties des workers
);

// Les résultats sont toujours collectés
foreach ($results as $result) {
    // Traiter les résultats...
}
```

## Flux d'exécution

```
┌─────────────────────────────────────────────────────────────────┐
│                    ParallelExecutor::execute()                  │
└────────────────────────┬────────────────────────────────────────┘
                         │
                         ▼
          ┌──────────────┴──────────────┐
          │                             │
          ▼                             ▼
┌─────────────────────┐     ┌─────────────────────────────────────┐
│ pcntl_fork dispo?   │     │ Fork non disponible                 │
│ → OUI               │     │ → executeSequentially()             │
└──────────┬──────────┘     │   Boucle séquentielle sur workers   │
           │                └─────────────────────────────────────┘
           ▼
┌─────────────────────────────────────────────────────────────────┐
│  Création des sockets Unix (socket_create_pair)                 │
│  Pour chaque worker :                                           │
│  1. Créer une paire de sockets (pipe[0], pipe[1])               │
│  2. pcntl_fork()                                                │
│     - Parent : ferme pipe[1], garde pipe[0] pour lecture        │
│     - Enfant : ferme pipe[0], exécute runWorker()               │
│  3. Enfant écrit le résultat dans pipe[1]                       │
│  4. Parent lit pipe[0] et attend la fin du processus            │
└────────────────────────┬────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────┐
│  Collecte des résultats                                         │
│  Pour chaque PID :                                              │
│  1. pcntl_waitpid() - Attendre la fin                           │
│  2. socket_read() - Lire les données                            │
│  3. unserialize() - Désérialiser le résultat                    │
│  4. Ajouter au tableau des résultats                            │
└─────────────────────────────────────────────────────────────────┘
```

## Communication inter-processus

```
┌─────────────┐                           ┌───────────────┐
│  Processus  │                           │  Processus    │
│   Parent    │                           │   Enfant      │
│             │                           │               │
│  ┌───────┐  │     socket_pair()         │  ┌───────┐    │ 
│  │ pipe[0]│◄┼───────────────────────────┼──│ pipe[1]│   │
│  │ (lecture)│ │                         │  │ (écriture) │
│  └───────┘  │                           │  └───────┘    │
│             │                           │               │
│  1. fork() ─┼─────────────────────────► │  1. runWorker()
│  2. close pipe[1]                       │  2. close pipe[0]
│  3. pcntl_waitpid()                     │  3. socket_write(pipe[1], serialize($result))
│  4. socket_read(pipe[0])                │  s4. socket_close(pipe[1])
│  5. unserialize()                       │  5. exit(0)
└─────────────┘                           └─────────────┘
```

**Protocole de communication :**
- Données normales : `serialize(TaskExecutionResultRecord)` → "O:..."
- Résultat nul : `'null'` → "null"
- Erreur : `'error:' . $message` → "error:Failed to execute task"

## Gestion des erreurs

| Situation | Comportement | Message |
|-----------|--------------|---------|
| `pcntl_fork` non disponible | Exécution séquentielle | `⚠️ pcntl_fork() not available. Workers will run sequentially.` |
| Échec `socket_create_pair` | Skip du worker | `❌ Failed to create socket pair for worker X` |
| Échec `pcntl_fork` | Skip du worker | `❌ Failed to fork worker X` |
| Erreur dans worker enfant | Écriture de `error:message` | `❌ Worker failed: message` |
| Données corrompues | Ignorées | (aucun message) |
| Résultat nul | Ignoré | (aucun message) |

**Note :** Aucune exception n'est propagée. Toutes les erreurs sont :
1. Journalisées via le gestionnaire de sortie
2. Le worker est ignoré et le processus continue

## Performance

| Opération | Complexité | Description |
|-----------|-----------|-------------|
| `execute()` | O(workers) | Boucle sur le nombre de workers |
| `runWorker()` | O(n) | Dépend de la directive `tasks:process` |
| Communication socket | O(1) | Transfert de données sérialisées |

**Facteurs d'impact :**
- Nombre de workers : plus de workers = plus de parallélisme mais plus de surcharge
- Surcharge de fork : chaque fork crée un nouveau processus (~2-5ms)
- Communication socket : latence minime (~0.1ms par message)
- Sérialisation : dépend de la taille des données

**Recommandations :**
- Worker count idéal : `cpu_cores * 1.5` à `cpu_cores * 2`
- Éviter > 32 workers (surcharge de contexte)
- Mode séquentiel : dégrade les performances linéairement

## Compatibilité

| Version/Environnement | Support | Note |
|-----------------------|---------|------|
| PHP 8.1+ | ✅ | Avec extension `pcntl` |
| PHP 8.0 | ✅ | Avec extension `pcntl` |
| PHP 7.4 | ✅ | Avec extension `pcntl` |
| Windows | ⚠️ | Pas de `pcntl`, mode séquentiel uniquement |
| Linux | ✅ | Support complet |
| macOS | ✅ | Support complet |
| Serverless (Lambda) | ⚠️ | `pcntl_fork` souvent désactivé |

**Prérequis :**
- Extension `pcntl` activée
- Extension `sockets` activée
- Environnement POSIX (Unix/Linux/macOS)

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Services\Watchs\ParallelExecutor;
use AndyDefer\Directive\DirectiveKernel;
use AndyDefer\Task\Handlers\OutputHandler;
use AndyDefer\Task\ValueObjects\LimitVO;
use AndyDefer\Task\Records\TaskExecutionResultRecord;

// 1. Initialisation du kernel et du gestionnaire de sortie
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = DirectiveKernel::init($app);
$output = new OutputHandler($console, $logger);

// 2. Création de l'exécuteur avec 4 workers
$executor = new ParallelExecutor(4, $kernel, $output);

// 3. Configuration de l'exécution
$limit = new LimitVO(100);
$uniqueOnly = false;
$recurringOnly = false;
$verbose = true;
$muted = false;

$startTime = microtime(true);

// 4. Exécution parallèle
$results = $executor->execute(
    $uniqueOnly,
    $recurringOnly,
    $limit,
    $verbose,
    $muted
);

$elapsed = microtime(true) - $startTime;

// 5. Analyse des résultats
$successCount = 0;
$failureCount = 0;
$skipCount = 0;

foreach ($results as $result) {
    if ($result->success) {
        if ($result->skipped ?? false) {
            $skipCount++;
        } else {
            $successCount++;
        }
    } else {
        $failureCount++;
    }
}

// 6. Rapport final
echo "\n📊 Rapport d'exécution :\n";
echo "   ⏱️  Temps écoulé : " . number_format($elapsed, 2) . "s\n";
echo "   🔢 Workers : 4\n";
echo "   📦 Résultats collectés : " . count($results) . "\n";
echo "   ✅ Succès : $successCount\n";
echo "   ⏭️  Ignorés (skipped) : $skipCount\n";
echo "   ❌ Échecs : $failureCount\n";

// 7. Détail des résultats
if ($verbose) {
    echo "\n📋 Détail :\n";
    foreach ($results as $i => $result) {
        $status = $result->success ? '✅' : '❌';
        if ($result->skipped ?? false) {
            $status = '⏭️';
        }
        echo sprintf(
            "  %s #%d: %s (%.2fms)\n",
            $status,
            $i + 1,
            $result->alias->getValue(),
            $result->execution_time_ms?->getValue() ?? 0
        );
        if ($result->error !== null) {
            echo "     Erreur : " . $result->error->getValue() . "\n";
        }
    }
}

// 8. Mode silencieux
echo "\n🔇 Exécution silencieuse :\n";
$mutedResults = $executor->execute(
    false,
    false,
    null,
    false,
    true  // ← mode muet
);
echo "   " . count($mutedResults) . " tâches traitées en silence\n";
```

## Voir aussi
- `DirectiveKernel` - Noyau exécutant les directives
- `OutputHandler` - Gestionnaire de sortie pour les logs
- `TasksWatchDirective` - Directive utilisant cet exécuteur
- `TaskExecutionResultRecord` - Résultat d'exécution
- `pcntl_fork()` - Documentation PHP sur le forking
- `socket_create_pair()` - Documentation PHP sur les sockets