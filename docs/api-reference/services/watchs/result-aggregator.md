# ResultAggregator - Référence Technique

## Description

Agrège les résultats d'exécution des tâches sur plusieurs cycles. Maintient un historique détaillé des succès, échecs et erreurs pour les tâches uniques et récurrentes.

## Hiérarchie

```
ResultAggregator
```

## Rôle principal

Collecte et résume les résultats de plusieurs cycles d'exécution de tâches, en maintenant des compteurs séparés pour les tâches uniques et récurrentes. Permet de suivre la progression globale et d'analyser les performances par type de tâche.

---

## API / Méthodes publiques

### `startNewCycle(): void`

Démarre un nouveau cycle d'exécution.

| Paramètre | Type | Description |
|-----------|------|-------------|
| Aucun | - | - |

**Retourne :** `void`

**Comportement :**
- Incrémente le compteur de cycles
- Initialise un nouvel enregistrement d'historique pour le cycle

**Exemple :**
```php
$aggregator = new ResultAggregator();
$aggregator->startNewCycle();
// Cycle #1 initialisé
```

---

### `addResults(array $results): void`

Ajoute les résultats d'un cycle d'exécution.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$results` | `array<TaskExecutionResultRecord>` | Résultats à agréger |

**Retourne :** `void`

**Comportement :**
1. Extrait les compteurs de succès, échecs et erreurs
2. Répartit les résultats entre UNIQUE et RECURRING
3. Met à jour les totaux globaux
4. Met à jour l'historique du cycle

**Exemple :**
```php
$results = [
    $uniqueResult,   // TaskExecutionResultRecord
    $recurringResult // TaskExecutionResultRecord
];
$aggregator->addResults($results);
```

---

### `getCycleCount(): int`

Retourne le nombre total de cycles exécutés.

| Paramètre | Type | Description |
|-----------|------|-------------|
| Aucun | - | - |

**Retourne :** `int` - Nombre de cycles

**Exemple :**
```php
$cycleCount = $aggregator->getCycleCount(); // 5
```

---

### `getTotalSuccess(): CounterVO`

Retourne le nombre total de succès.

| Paramètre | Type | Description |
|-----------|------|-------------|
| Aucun | - | - |

**Retourne :** `CounterVO` - Nombre total de succès

---

### `getTotalFailed(): CounterVO`

Retourne le nombre total d'échecs.

| Paramètre | Type | Description |
|-----------|------|-------------|
| Aucun | - | - |

**Retourne :** `CounterVO` - Nombre total d'échecs

---

### `getTotalErrors(): CounterVO`

Retourne le nombre total d'erreurs.

| Paramètre | Type | Description |
|-----------|------|-------------|
| Aucun | - | - |

**Retourne :** `CounterVO` - Nombre total d'erreurs

---

### `getUniqueSuccess(): CounterVO`

Retourne le nombre de succès pour les tâches uniques.

| Paramètre | Type | Description |
|-----------|------|-------------|
| Aucun | - | - |

**Retourne :** `CounterVO` - Succès des tâches uniques

---

### `getUniqueFailed(): CounterVO`

Retourne le nombre d'échecs pour les tâches uniques.

| Paramètre | Type | Description |
|-----------|------|-------------|
| Aucun | - | - |

**Retourne :** `CounterVO` - Échecs des tâches uniques

---

### `getRecurringSuccess(): CounterVO`

Retourne le nombre de succès pour les tâches récurrentes.

| Paramètre | Type | Description |
|-----------|------|-------------|
| Aucun | - | - |

**Retourne :** `CounterVO` - Succès des tâches récurrentes

---

### `getRecurringFailed(): CounterVO`

Retourne le nombre d'échecs pour les tâches récurrentes.

| Paramètre | Type | Description |
|-----------|------|-------------|
| Aucun | - | - |

**Retourne :** `CounterVO` - Échecs des tâches récurrentes

---

### `getCycleSuccess(int $cycleNumber): CounterVO`

Retourne le nombre de succès pour un cycle spécifique.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$cycleNumber` | `int` | Numéro du cycle (1-indexé) |

**Retourne :** `CounterVO` - Succès du cycle

**Exemple :**
```php
$success = $aggregator->getCycleSuccess(3);
```

---

### `getCycleFailed(int $cycleNumber): CounterVO`

Retourne le nombre d'échecs pour un cycle spécifique.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$cycleNumber` | `int` | Numéro du cycle (1-indexé) |

**Retourne :** `CounterVO` - Échecs du cycle

---

### `getCycleErrors(int $cycleNumber): CounterVO`

Retourne le nombre d'erreurs pour un cycle spécifique.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$cycleNumber` | `int` | Numéro du cycle (1-indexé) |

**Retourne :** `CounterVO` - Erreurs du cycle

---

### `getCycleUniqueSuccess(int $cycleNumber): CounterVO`

Retourne les succès uniques pour un cycle spécifique.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$cycleNumber` | `int` | Numéro du cycle (1-indexé) |

**Retourne :** `CounterVO` - Succès uniques du cycle

---

### `getCycleUniqueFailed(int $cycleNumber): CounterVO`

Retourne les échecs uniques pour un cycle spécifique.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$cycleNumber` | `int` | Numéro du cycle (1-indexé) |

**Retourne :** `CounterVO` - Échecs uniques du cycle

---

### `getCycleRecurringSuccess(int $cycleNumber): CounterVO`

Retourne les succès récurrents pour un cycle spécifique.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$cycleNumber` | `int` | Numéro du cycle (1-indexé) |

**Retourne :** `CounterVO` - Succès récurrents du cycle

---

### `getCycleRecurringFailed(int $cycleNumber): CounterVO`

Retourne les échecs récurrents pour un cycle spécifique.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$cycleNumber` | `int` | Numéro du cycle (1-indexé) |

**Retourne :** `CounterVO` - Échecs récurrents du cycle

---

### `hasFailures(): bool`

Vérifie si des échecs ou erreurs ont été enregistrés.

| Paramètre | Type | Description |
|-----------|------|-------------|
| Aucun | - | - |

**Retourne :** `bool` - `true` s'il y a des échecs ou erreurs

**Exemple :**
```php
if ($aggregator->hasFailures()) {
    echo "⚠️ Des échecs ont été détectés\n";
}
```

---

### `getDetailedSummary(): DetailedSummaryRecord`

Retourne un résumé détaillé des résultats.

| Paramètre | Type | Description |
|-----------|------|-------------|
| Aucun | - | - |

**Retourne :** `DetailedSummaryRecord` - Résumé structuré

**Structure :**
```php
DetailedSummaryRecord {
    total: SummaryTotalsRecord {
        success: int,
        failed: int,
        errors: int,
    },
    unique: SummaryTypeRecord {
        success: int,
        failed: int,
    },
    recurring: SummaryTypeRecord {
        success: int,
        failed: int,
    },
}
```

**Exemple :**
```php
$summary = $aggregator->getDetailedSummary();
echo "Total succès: " . $summary->total->success;
echo "Succès uniques: " . $summary->unique->success;
echo "Succès récurrents: " . $summary->recurring->success;
```

---

### `getCycleHistory(): array`

Retourne l'historique complet des cycles.

| Paramètre | Type | Description |
|-----------|------|-------------|
| Aucun | - | - |

**Retourne :** `array<int, CycleHistoryRecord>` - Historique des cycles

**Structure :**
```php
CycleHistoryRecord {
    success: int,
    failed: int,
    errors: int,
    unique_success: int,
    unique_failed: int,
    recurring_success: int,
    recurring_failed: int,
}
```

**Exemple :**
```php
$history = $aggregator->getCycleHistory();
foreach ($history as $cycle => $data) {
    echo "Cycle #{$cycle}: {$data->success} succès\n";
}
```

---

### `reset(): void`

Réinitialise toutes les données agrégées.

| Paramètre | Type | Description |
|-----------|------|-------------|
| Aucun | - | - |

**Retourne :** `void`

**Effet :**
- Vide l'historique des cycles
- Remet tous les compteurs à zéro

**Exemple :**
```php
$aggregator->reset(); // Tout est réinitialisé
```

---

## Cas d'utilisation

### Cas 1 : Suivi de l'exécution des tâches

```php
$aggregator = new ResultAggregator();

while ($shouldContinue) {
    $aggregator->startNewCycle();
    
    $results = executeTasks();
    $aggregator->addResults($results);
    
    $cycleNumber = $aggregator->getCycleCount();
    $success = $aggregator->getCycleSuccess($cycleNumber)->getValue();
    $failed = $aggregator->getCycleFailed($cycleNumber)->getValue();
    
    echo "Cycle #{$cycleNumber}: {$success} succès, {$failed} échecs\n";
}
```

### Cas 2 : Affichage du résumé final

```php
$summary = $aggregator->getDetailedSummary();

echo "📊 Résumé final:\n";
echo "   Total: {$summary->total->success} succès, {$summary->total->failed} échecs\n";
echo "   Unique: {$summary->unique->success} succès, {$summary->unique->failed} échecs\n";
echo "   Recurring: {$summary->recurring->success} succès, {$summary->recurring->failed} échecs\n";
```

### Cas 3 : Détection des échecs

```php
if ($aggregator->hasFailures()) {
    $history = $aggregator->getCycleHistory();
    foreach ($history as $cycle => $data) {
        if ($data->failed > 0) {
            echo "⚠️ Cycle #{$cycle}: {$data->failed} échecs\n";
        }
    }
}
```

---

## Flux d'exécution

```
1. startNewCycle()
   ↓
2. Initialisation du cycle
   ↓
3. addResults($results)
   ↓
4. Extraction des compteurs
   ↓
5. Répartition UNIQUE / RECURRING
   ↓
6. Mise à jour des totaux globaux
   ↓
7. Mise à jour de l'historique
   ↓
8. getCycleSuccess() / getCycleFailed() / etc.
```

---

## Gestion des erreurs

| Situation | Comportement |
|-----------|--------------|
| Cycle non initialisé | Retourne `CounterVO(0)` |
| Résultat non valide | Ignoré (type-check) |
| `type_counts` null | Traitement comme UNIQUE ou RECURRING pur |
| `failed_counts` null | Les échecs sont attribués au type principal |

---

## Performance

| Opération | Complexité | Description |
|-----------|------------|-------------|
| `addResults()` | O(n) | n = nombre de résultats |
| `getCycleHistory()` | O(1) | Retourne le tableau |
| `getDetailedSummary()` | O(1) | Construction d'un record |
| `getCycleSuccess()` | O(1) | Accès tableau |

---

## Compatibilité

| Version | Support |
|---------|---------|
| PHP 8.1+ | ✅ Complet |
| PHP 8.2+ | ✅ Complet |

---

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Services\Watchs\ResultAggregator;
use AndyDefer\Task\Enums\TaskType;
use AndyDefer\Task\Records\TaskExecutionResultRecord;

$aggregator = new ResultAggregator();

// Cycle 1
$aggregator->startNewCycle();
$results = [
    // Résultats du cycle
];
$aggregator->addResults($results);

// Cycle 2
$aggregator->startNewCycle();
$results = [
    // Résultats du cycle
];
$aggregator->addResults($results);

// Cycle 3
$aggregator->startNewCycle();
$results = [
    // Résultats du cycle
];
$aggregator->addResults($results);

// Affichage des résultats
$cycleCount = $aggregator->getCycleCount();
echo "📊 Cycles exécutés: {$cycleCount}\n";

$summary = $aggregator->getDetailedSummary();
echo "\n📈 Résumé final:\n";
echo "   ✅ Total succès: {$summary->total->success}\n";
echo "   ❌ Total échecs: {$summary->total->failed}\n";
echo "   ⚠️ Total erreurs: {$summary->total->errors}\n";

echo "\n🔄 Unique tasks:\n";
echo "   ✅ Succès: {$summary->unique->success}\n";
echo "   ❌ Échecs: {$summary->unique->failed}\n";

echo "\n🔁 Recurring tasks:\n";
echo "   ✅ Succès: {$summary->recurring->success}\n";
echo "   ❌ Échecs: {$summary->recurring->failed}\n";

// Détection des échecs
if ($aggregator->hasFailures()) {
    echo "\n⚠️ Des échecs ont été détectés dans les cycles suivants:\n";
    $history = $aggregator->getCycleHistory();
    foreach ($history as $cycle => $data) {
        if ($data->failed > 0) {
            echo "   Cycle #{$cycle}: {$data->failed} échecs\n";
        }
    }
}
```

---

## Voir aussi

- `CycleHistoryRecord` - Enregistrement d'un cycle
- `DetailedSummaryRecord` - Résumé détaillé
- `SummaryTotalsRecord` - Totaux globaux
- `SummaryTypeRecord` - Résumé par type
- `TaskExecutionResultRecord` - Résultat d'exécution
- `ParallelExecutor` - Exécuteur parallèle