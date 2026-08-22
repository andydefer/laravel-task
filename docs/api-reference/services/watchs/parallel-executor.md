# ParallelExecutor - Référence Technique

## Description

Exécute des tâches en parallèle en utilisant `pcntl_fork()`. Crée plusieurs processus workers pour exécuter simultanément la directive `tasks:process` et agréger les résultats.

## Hiérarchie

```
ParallelExecutor
```

## Rôle principal

Orchestrer l'exécution parallèle des tâches en créant des processus enfants, en réinitialisant les connexions à la base de données pour éviter les conflits, et en collectant les résultats de chaque worker.

---

## API / Méthodes publiques

### `__construct(int $maxWorkers, DirectiveKernel $kernel, OutputHandler $output)`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$maxWorkers` | `int` | Nombre maximum de workers (minimum 1) |
| `$kernel` | `DirectiveKernel` | Kernel de directives pour exécuter les commandes |
| `$output` | `OutputHandler` | Gestionnaire de sortie pour les logs |

**Exemple :**
```php
$executor = new ParallelExecutor(
    4,
    $kernel,
    $outputHandler
);
```

---

### `execute(bool $uniqueOnly, bool $recurringOnly, ?LimitVO $limit, bool $verbose, bool $muted = false, ?TaskFqcnVOCollection $fqcns = null): array`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$uniqueOnly` | `bool` | Traiter uniquement les tâches uniques |
| `$recurringOnly` | `bool` | Traiter uniquement les tâches récurrentes |
| `$limit` | `LimitVO|null` | Nombre maximum de tâches par worker |
| `$verbose` | `bool` | Mode verbose (logs détaillés) |
| `$muted` | `bool` | Mode muet (pas de sortie) |
| `$fqcns` | `TaskFqcnVOCollection|null` | Filtre optionnel par FQCN |

**Retourne :** `array<TaskExecutionResultRecord>` - Résultats des exécutions

**Comportement :**
1. Vérifie la disponibilité de `pcntl_fork()`
2. Crée N workers en parallèle (processus enfants)
3. Chaque worker exécute `tasks:process` avec les paramètres
4. Les résultats sont transmis via des sockets UNIX
5. Les connexions DB sont réinitialisées dans chaque enfant

**Exemple :**
```php
$results = $executor->execute(
    uniqueOnly: false,
    recurringOnly: true,
    limit: new LimitVO(50),
    verbose: false,
    muted: true,
    fqcns: TaskFqcnVOCollection::from([TestRecurringTask::class])
);

foreach ($results as $result) {
    echo "✅ Succès : " . $result->success->getValue() . "\n";
}
```

---

## Méthodes privées

### `executeSequentially(bool $uniqueOnly, bool $recurringOnly, ?LimitVO $limit, bool $verbose, bool $muted = false, ?TaskFqcnVOCollection $fqcns = null): array`

**Rôle :** Fallback lorsque `pcntl_fork()` n'est pas disponible. Exécute les workers de manière séquentielle.

**Retourne :** `array<TaskExecutionResultRecord>` - Résultats des exécutions

---

### `runWorker(int $workerId, bool $uniqueOnly, bool $recurringOnly, ?LimitVO $limit, bool $verbose, bool $muted = false, ?TaskFqcnVOCollection $fqcns = null): ?TaskExecutionResultRecord`

**Rôle :** Exécute un worker individuel.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$workerId` | `int` | Identifiant du worker |
| `$uniqueOnly` | `bool` | Traiter uniquement les tâches uniques |
| `$recurringOnly` | `bool` | Traiter uniquement les tâches récurrentes |
| `$limit` | `LimitVO|null` | Nombre maximum de tâches |
| `$verbose` | `bool` | Mode verbose |
| `$muted` | `bool` | Mode muet |
| `$fqcns` | `TaskFqcnVOCollection|null` | Filtre FQCN |

**Retourne :** `TaskExecutionResultRecord|null` - Résultat de l'exécution

**Comportement :**
1. Construit les arguments pour `tasks:process`
2. Ajoute les FQCNs si présents
3. Ajoute les flags
4. Exécute la directive via le kernel
5. Récupère le résultat depuis le contexte

---

### `mergeResults(array $results): TaskExecutionResultRecord`

**Rôle :** Fusionne plusieurs résultats en un seul.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$results` | `array<TaskExecutionResultRecord>` | Résultats à fusionner |

**Retourne :** `TaskExecutionResultRecord` - Résultat fusionné

**Comportement :**
1. Additionne les succès, échecs et erreurs
2. Répartit les succès par type dans `type_counts`
3. Répartit les échecs par type dans `failed_counts`
4. Détermine le type principal

---

### `resetDatabaseConnection(): void`

**Rôle :** Réinitialise la connexion à la base de données pour éviter les conflits entre processus.

**Comportement :**
1. `DB::purge()` - Supprime toutes les connexions
2. `DB::reconnect()` - Reconnecte avec une nouvelle connexion
3. `DB::connection()->getPdo()` - Vérifie que la connexion fonctionne

**Exceptions :** `Throwable` - Si la réinitialisation échoue

---

## Cas d'utilisation

### Cas 1 : Exécution parallèle simple

```php
$executor = new ParallelExecutor(4, $kernel, $outputHandler);

$results = $executor->execute(
    uniqueOnly: false,
    recurringOnly: false,
    limit: new LimitVO(100),
    verbose: false,
    muted: true
);

echo "✅ " . count($results) . " workers ont terminé\n";
```

### Cas 2 : Traitement uniquement des tâches récurrentes

```php
$results = $executor->execute(
    uniqueOnly: false,
    recurringOnly: true,
    limit: new LimitVO(50),
    verbose: true,
    muted: false
);
```

### Cas 3 : Filtrage par FQCN

```php
$fqcns = TaskFqcnVOCollection::from([
    SyncUsersTask::class,
    ImportProductsTask::class,
]);

$results = $executor->execute(
    uniqueOnly: true,
    recurringOnly: false,
    limit: new LimitVO(100),
    verbose: false,
    muted: true,
    fqcns: $fqcns
);
```

### Cas 4 : Mode muet pour les environnements de production

```php
$results = $executor->execute(
    uniqueOnly: false,
    recurringOnly: false,
    limit: new LimitVO(200),
    verbose: false,
    muted: true
);
// Aucune sortie console, seulement les résultats
```

### Cas 5 : Exécution avec fusion des résultats mixtes

```php
// Les workers retournent des résultats UNIQUE et RECURRING
$results = $executor->execute(
    uniqueOnly: false,
    recurringOnly: false,
    limit: new LimitVO(100),
    verbose: true,
    muted: false
);

// Chaque résultat contient type_counts et failed_counts
foreach ($results as $result) {
    if ($result->type_counts !== null) {
        $unique = $result->type_counts->get('unique', 0);
        $recurring = $result->type_counts->get('recurring', 0);
        echo "Unique: {$unique}, Recurring: {$recurring}\n";
    }
}
```

---

## Flux d'exécution

### Architecture parallèle

```
Processus Parent
     │
     ├── Fork → Worker 1 (processus enfant)
     │         ├── Réinitialisation DB
     │         ├── Exécution tasks:process
     │         └── Résultat → Socket
     │
     ├── Fork → Worker 2 (processus enfant)
     │         ├── Réinitialisation DB
     │         ├── Exécution tasks:process
     │         └── Résultat → Socket
     │
     └── Fork → Worker N (processus enfant)
               ├── Réinitialisation DB
               ├── Exécution tasks:process
               └── Résultat → Socket
```

### Communication inter-processus

```
Worker (enfant)                      Parent
      │                                   │
      ├── Exécution                       │
      ├── Serialize($result)              │
      ├── socket_write($pipe, $data) ────→ socket_read($pipe)
      │                                   │
      ├── exit(0)                         ├── pcntl_waitpid()
      │                                   ├── Unserialize($data)
      │                                   └── Ajout au tableau de résultats
```

### Fusion des résultats

```
Résultat #1 (UNIQUE) ──┐
                        ├── Fusion → Résultat fusionné
Résultat #2 (RECURRING)┘
                        ↓
            type_counts = ['unique' => 100, 'recurring' => 100]
            failed_counts = ['unique' => 0, 'recurring' => 2]
            type = UNIQUE (hasUnique = true)
            has_unique = true
            has_recurring = true
```

---

## Gestion des erreurs

| Situation | Comportement |
|-----------|--------------|
| `pcntl_fork()` non disponible | Exécution séquentielle (fallback) |
| `socket_create_pair()` échoue | Worker ignoré, erreur loggée |
| `pcntl_fork()` échoue | Worker ignoré, erreur loggée |
| Exception dans l'enfant | Message d'erreur transmis via socket |
| Enfant se termine avec code != 0 | Erreur loggée, socket fermé |
| Désérialisation échoue | Erreur loggée |
| Réinitialisation DB échoue | Exception levée |

---

## Performance

### Points d'attention

| Aspect | Impact |
|--------|--------|
| Nombre de workers | Augmente le parallélisme mais aussi la charge |
| Réinitialisation DB | Chaque worker purge/reconnecte |
| Communication socket | Surcharge de sérialisation/désérialisation |
| Fusion des résultats | O(n) où n = nombre de résultats |

### Recommandations

```php
// ✅ Nombre de workers adapté au CPU
$workers = min(4, (int) shell_exec('nproc'));

// ✅ Utiliser le mode muet en production
$executor->execute(..., muted: true);

// ✅ Limiter les tâches par worker
$limit = new LimitVO(50); // Évite de surcharger

// ✅ Utiliser le filtre FQCN pour réduire la charge
$fqcns = TaskFqcnVOCollection::from([$specificTaskClass]);
$executor->execute(..., fqcns: $fqcns);
```

---

## Compatibilité

| Version | Support |
|---------|---------|
| PHP 8.1+ | ✅ Complet |
| PHP 8.2+ | ✅ Complet |
| Linux/Unix | ✅ Complet (pcntl_fork requis) |
| Windows | ⚠️ Fallback séquentiel |

---

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Services\Watchs\ParallelExecutor;
use AndyDefer\Task\ValueObjects\LimitVO;
use AndyDefer\Task\Collections\TaskFqcnVOCollection;
use AndyDefer\Task\Handlers\OutputHandler;

// 1. Création de l'exécuteur
$executor = new ParallelExecutor(
    maxWorkers: 4,
    kernel: $kernel,
    output: $outputHandler
);

// 2. Configuration des paramètres
$fqcns = TaskFqcnVOCollection::from([
    'App\\Tasks\\SyncUsersTask',
    'App\\Tasks\\ImportProductsTask',
]);

$limit = new LimitVO(100);

// 3. Exécution parallèle
$results = $executor->execute(
    uniqueOnly: false,
    recurringOnly: true,
    limit: $limit,
    verbose: false,
    muted: true,
    fqcns: $fqcns
);

// 4. Agrégation des résultats
$totalSuccess = 0;
$totalFailed = 0;
$totalUniqueSuccess = 0;
$totalRecurringSuccess = 0;

foreach ($results as $result) {
    $totalSuccess += $result->success->getValue();
    $totalFailed += $result->failed->getValue();

    if ($result->type_counts !== null) {
        $totalUniqueSuccess += $result->type_counts->get('unique', 0);
        $totalRecurringSuccess += $result->type_counts->get('recurring', 0);
    }
}

echo "📊 Résultats agrégés :\n";
echo "  ✅ Succès : " . $totalSuccess . "\n";
echo "  ❌ Échecs : " . $totalFailed . "\n";
echo "  🔄 Unique : " . $totalUniqueSuccess . "\n";
echo "  🔁 Recurring : " . $totalRecurringSuccess . "\n";
echo "🔧 Workers exécutés : " . count($results) . "\n";
```

---

## Voir aussi

- `ResultAggregator` - Agrégateur de résultats
- `TasksProcessDirective` - Directive exécutée par les workers
- `DirectiveKernel` - Kernel exécutant les directives
- `OutputHandler` - Gestionnaire de sortie
- `TaskExecutionResultRecord` - Résultat d'exécution
- `CycleHistoryRecord` - Historique des cycles
