# UniqueTaskService - Référence Technique

## Description

Service de gestion des tâches uniques. Orchestre l'enregistrement, l'exécution, les transitions d'état et la gestion du cycle de vie complet des tâches uniques (exécution unique, planifiée, avec tentatives et expiration).

## Hiérarchie

```
UniqueTaskService
    └── UniqueTaskServiceInterface
```

## Rôle principal

Fournit une couche d'abstraction métier pour les tâches uniques, coordonnant les opérations du repository, la journalisation, l'hydratation et l'instanciation des tâches.

---

## API / Méthodes publiques

### `register(UniqueTaskFqcnVO $fqcn, StrictDataObject $payload, UniqueTaskConfigRecord $config): TaskAliasVO`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$fqcn` | `UniqueTaskFqcnVO` | Classe de la tâche (doit étendre `AbstractUniqueTask`) |
| `$payload` | `StrictDataObject` | Données de la tâche |
| `$config` | `UniqueTaskConfigRecord` | Configuration (planification, tentatives, grâce) |

**Retourne :** `TaskAliasVO` - Alias de la tâche créée (format `unique@{uuid}`)

**Exceptions :**
- `InvalidArgumentException` - Classe non trouvée ou n'étend pas `AbstractUniqueTask`

**Exemple :**
```php
$fqcn = new UniqueTaskFqcnVO(SyncUsersTask::class);
$payload = StrictDataObject::from(['batch_size' => 100]);
$config = UniqueTaskConfigRecord::from([
    'scheduled_at' => new Iso8601DateTimeVO('2026-12-25T10:00:00+00:00'),
    'max_attempts' => 3,
    'grace_period' => 3600,
]);

$alias = $service->register($fqcn, $payload, $config);
echo "Tâche enregistrée : " . $alias->getValue();
```

---

### `run(TaskAliasVO $alias): TaskRunResultRecord`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche à exécuter |

**Retourne :** `TaskRunResultRecord` - Résultat de l'exécution

**Comportement :**
1. Recherche la tâche par alias
2. Effectue les vérifications pré-exécution
3. Instancie la tâche et l'exécute
4. Gère les erreurs et les tentatives
5. Met à jour l'état de la tâche

**Exemple :**
```php
$alias = new TaskAliasVO('unique@550e8400-e29b-41d4-a716-446655440000');
$result = $service->run($alias);

if ($result->success) {
    echo "✅ Tâche exécutée avec succès\n";
} else {
    echo "❌ Échec : " . $result->error . "\n";
}
```

---

### `process(LimitVO $limit = new LimitVO, ?callable $onProgress = null, ?TaskFqcnVOCollection $fqcns = null): ProcessResultRecord`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `LimitVO` | Nombre maximum de tâches à traiter |
| `$onProgress` | `callable|null` | Callback de progression (`function($processed, $total, $type, $record)`) |
| `$fqcns` | `TaskFqcnVOCollection|null` | Filtre optionnel par FQCN |

**Retourne :** `ProcessResultRecord` - Résultats du traitement (succès, échecs, erreurs)

**Comportement :**
1. Récupère les tâches prêtes via le repository
2. Exécute chaque tâche en série
3. Compte les succès, échecs et tâches ignorées
4. Traite les tâches expirées
5. Appelle le callback de progression si fourni

**Exemple :**
```php
$limit = new LimitVO(50);
$callback = function($processed, $total, $type, $record) {
    echo "Progression : {$processed}/{$total}\n";
};

$result = $service->process($limit, $callback);
echo "✅ Succès : " . $result->success->getValue() . "\n";
echo "❌ Échecs : " . $result->failed->getValue() . "\n";
```

---

### `cancel(TaskAliasVO $alias, ?DescriptionVO $reason = null): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche |
| `$reason` | `DescriptionVO|null` | Raison de l'annulation |

**Retourne :** `bool` - `true` si l'annulation a réussi

**Préconditions :** La tâche doit être en statut `PENDING`

**Exemple :**
```php
$reason = new DescriptionVO('Annulé par l\'utilisateur');
$service->cancel($alias, $reason);
```

---

### `reschedule(TaskAliasVO $alias, Iso8601DateTimeVO $newScheduledAt): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche |
| `$newScheduledAt` | `Iso8601DateTimeVO` | Nouvelle date planifiée |

**Retourne :** `bool` - `true` si la replanification a réussi

**Préconditions :** La tâche doit être en statut `PENDING`

**Exemple :**
```php
$newDate = new Iso8601DateTimeVO('2026-12-26T10:00:00+00:00');
$service->reschedule($alias, $newDate);
```

---

### `extendGracePeriod(TaskAliasVO $alias, DurationVO $extraSeconds): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche |
| `$extraSeconds` | `DurationVO` | Secondes supplémentaires à ajouter |

**Retourne :** `bool` - `true` si la période de grâce a été prolongée

**Préconditions :** La tâche doit être en statut `PENDING`

**Exemple :**
```php
$extra = new DurationVO(3600); // +1 heure
$service->extendGracePeriod($alias, $extra);
```

---

### `find(TaskAliasVO $alias): ?UniqueTaskRecord`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche |

**Retourne :** `UniqueTaskRecord|null` - Record de la tâche ou `null`

---

### `findPending(LimitVO $limit = new LimitVO): UniqueTaskRecordCollection`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `LimitVO` | Nombre maximum de résultats |

**Retourne :** `UniqueTaskRecordCollection` - Collection des tâches en attente

---

### `findCompleted(LimitVO $limit = new LimitVO): UniqueTaskRecordCollection`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `LimitVO` | Nombre maximum de résultats |

**Retourne :** `UniqueTaskRecordCollection` - Collection des tâches terminées avec succès

---

### `findFailed(LimitVO $limit = new LimitVO): UniqueTaskRecordCollection`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `LimitVO` | Nombre maximum de résultats |

**Retourne :** `UniqueTaskRecordCollection` - Collection des tâches en échec

---

### `findCanceled(LimitVO $limit = new LimitVO): UniqueTaskRecordCollection`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `LimitVO` | Nombre maximum de résultats |

**Retourne :** `UniqueTaskRecordCollection` - Collection des tâches annulées

---

### `exists(TaskAliasVO $alias): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche |

**Retourne :** `bool` - `true` si la tâche existe

---

### `delete(TaskAliasVO $alias): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche |

**Retourne :** `bool` - `true` si la suppression a réussi

---

### Méthodes de comptage

Chaque méthode de comptage accepte un filtre FQCN optionnel :

```php
public function count(): CounterVO
public function countPending(?TaskFqcnVOCollection $fqcns = null): CounterVO
public function countCompleted(?TaskFqcnVOCollection $fqcns = null): CounterVO
public function countFailed(?TaskFqcnVOCollection $fqcns = null): CounterVO
public function countCanceled(?TaskFqcnVOCollection $fqcns = null): CounterVO
```

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$fqcns` | `TaskFqcnVOCollection|null` | Filtre optionnel par FQCN |

**Retourne :** `CounterVO` - Le nombre de tâches

**Exemple :**
```php
$fqcns = TaskFqcnVOCollection::from([SyncUsersTask::class]);
$count = $service->countPending($fqcns);
echo "Tâches en attente : " . $count->getValue();
```

---

## Cas d'utilisation

### Cas 1 : Enregistrement et exécution d'une tâche

```php
// 1. Enregistrer
$fqcn = new UniqueTaskFqcnVO(ProcessDataTask::class);
$payload = StrictDataObject::from(['file' => 'data.csv']);
$config = UniqueTaskConfigRecord::from([
    'scheduled_at' => new Iso8601DateTimeVO,
    'max_attempts' => 3,
    'grace_period' => 3600,
]);

$alias = $service->register($fqcn, $payload, $config);

// 2. Exécuter
$result = $service->run($alias);

if (!$result->success) {
    echo "Erreur : " . $result->error . "\n";
}
```

### Cas 2 : Traitement par lot

```php
$limit = new LimitVO(100);
$results = $service->process($limit);

echo "📊 Résultats du batch :\n";
echo "  ✅ Succès : " . $results->success->getValue() . "\n";
echo "  ❌ Échecs : " . $results->failed->getValue() . "\n";
echo "  ⏭️ Ignorées : " . $results->skipped->getValue() . "\n";
echo "  🗑️ Expirées : " . $results->finished->getValue() . "\n";

foreach ($results->errors as $error) {
    echo "  - {$error->alias->getValue()} : {$error->description}\n";
}
```

### Cas 3 : Filtrage par FQCN

```php
$fqcns = TaskFqcnVOCollection::from([
    SyncUsersTask::class,
    ImportProductsTask::class,
]);

$limit = new LimitVO(50);
$results = $service->process($limit, null, $fqcns);

echo "Traitement des tâches spécifiées : " . $results->success->getValue() . "\n";
```

### Cas 4 : Gestion des tentatives

```php
$alias = new TaskAliasVO('unique@550e8400-e29b-41d4-a716-446655440000');
$result = $service->run($alias);

if (!$result->success) {
    $task = $service->find($alias);
    if ($task) {
        echo "Tentatives : " . $task->attempts->getValue() . "/" . $task->max_attempts->getValue() . "\n";
        
        if ($task->attempts->getValue() >= $task->max_attempts->getValue()) {
            echo "⚠️ Nombre maximal de tentatives atteint\n";
        }
    }
}
```

### Cas 5 : Gestion des tâches expirées

```php
// Le service gère automatiquement les tâches expirées dans process()
$results = $service->process();

if ($results->finished->getValue() > 0) {
    echo "🗑️ " . $results->finished->getValue() . " tâches expirées ont été marquées en échec\n";
}
```

---

## Flux d'exécution

### Processus d'enregistrement

```
1. register() est appelée
   ↓
2. Validation de la classe (existe, étend AbstractUniqueTask)
   ↓
3. Génération d'un UUID et d'un alias
   ↓
4. Création du record UniqueTaskRecord
   ↓
5. Persistance via le repository
   ↓
6. Retour de l'alias
```

### Processus d'exécution d'une tâche

```
1. run() est appelée
   ↓
2. Recherche de la tâche par alias
   ↓
3. Vérifications pré-exécution :
   ├── Statut PENDING ou IN_PROGRESS ?
   ├── Scheduled_at <= now ?
   └── Attempts < max_attempts ?
   ↓
4. Instanciation de la tâche
   ↓
5. Exécution (try/catch)
   ↓
6. Succès → moveToCompleted()
   Échec → handleExecutionFailure()
   ↓
7. Ajout des informations de débogage
   ↓
8. Retour du résultat
```

### Processus de traitement par lot

```
1. process() est appelée
   ↓
2. findReadyToRun() → tâches PENDING avec scheduled_at <= now
   ↓
3. Pour chaque tâche :
   ├── run() (exécution)
   ├── Comptage succès/échecs/ignorées
   └── Appel du callback de progression
   ↓
4. findExpired() → tâches expirées
   ↓
5. Pour chaque tâche expirée :
   └── moveToFailed() + addDebug()
   ↓
6. Retour du ProcessResultRecord
```

---

## Gestion des erreurs

| Situation | Exception | Log | Message |
|-----------|-----------|-----|---------|
| Classe non trouvée | `InvalidArgumentException` | - | `Task class "X" does not exist` |
| Classe invalide | `InvalidArgumentException` | - | `Class "X" must extend AbstractUniqueTask` |
| Tâche non trouvée | Aucune (retourne `false`) | - | `Task not found` (dans le résultat) |
| Échec d'exécution | `Throwable` attrapé | `error` | `unique_task_cancelled` / `unique_task_rescheduled` |
| Max attempts atteint | Aucune | - | `Maximum attempts reached (X/Y) - skipped` |

---

## Performance

### Points d'attention

| Opération | Complexité | Risque |
|-----------|------------|--------|
| `process()` | O(n) | ⚠️ Dépend du nombre de tâches |
| `run()` | O(1) | ✅ Recherche par alias (indexé) |
| `countPending()` | O(1) | ✅ Indexé sur `status` |

### Optimisations

- Le repository utilise `lockForUpdate()` pour éviter les exécutions concurrentes
- Les comptages passent par le repository (indexés)
- Le callback `$onProgress` permet un suivi sans bloquer

### Recommandations

```php
// ✅ Utiliser un limit pour éviter de traiter trop de tâches
$results = $service->process(new LimitVO(100));

// ✅ Utiliser le filtre FQCN pour réduire le jeu de données
$fqcns = TaskFqcnVOCollection::from([$specificTaskClass]);
$results = $service->process($limit, $callback, $fqcns);

// ✅ Utiliser le callback de progression pour les longs traitements
$service->process($limit, function($processed, $total) {
    echo "Progression : " . round($processed/$total*100) . "%\n";
});
```

---

## Compatibilité

| Version | Support |
|---------|---------|
| PHP 8.1+ | ✅ Complet |
| PHP 8.2+ | ✅ Complet |
| Laravel 10+ | ✅ Complet |
| Laravel 11+ | ✅ Complet |

---

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Services\UniqueTaskService;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\Collections\TaskFqcnVOCollection;
use AndyDefer\Task\Tests\Fixtures\Tasks\TestUniqueTask;

$service = app(UniqueTaskService::class);

// 1. Enregistrement d'une tâche
$fqcn = new UniqueTaskFqcnVO(TestUniqueTask::class);
$payload = StrictDataObject::from(['data' => 'test']);
$config = UniqueTaskConfigRecord::from([
    'scheduled_at' => (new Iso8601DateTimeVO)->addSeconds(3600),
    'max_attempts' => 3,
    'grace_period' => 3600,
]);

$alias = $service->register($fqcn, $payload, $config);
echo "✅ Tâche enregistrée : " . $alias->getValue() . "\n";

// 2. Vérification de l'existence
if ($service->exists($alias)) {
    echo "La tâche existe\n";
}

// 3. Exécution de la tâche
$result = $service->run($alias);

if ($result->success) {
    echo "✅ Tâche exécutée avec succès\n";
    echo "⏱️ Temps : " . $result->execution_time_ms . "ms\n";
} else {
    echo "❌ Échec : " . $result->error . "\n";
}

// 4. Traitement par lot avec filtrage FQCN
$limit = new LimitVO(50);
$fqcns = TaskFqcnVOCollection::from([TestUniqueTask::class]);

$batchResult = $service->process(
    $limit,
    function($processed, $total) {
        echo "Progression : {$processed}/{$total}\n";
    },
    $fqcns
);

echo "📊 Résultats :\n";
echo "  ✅ Succès : " . $batchResult->success->getValue() . "\n";
echo "  ❌ Échecs : " . $batchResult->failed->getValue() . "\n";
echo "  ⏭️ Ignorées : " . $batchResult->skipped->getValue() . "\n";

// 5. Statistiques
echo "📈 Statistiques :\n";
echo "  Total : " . $service->count()->getValue() . "\n";
echo "  PENDING : " . $service->countPending()->getValue() . "\n";
echo "  COMPLETED : " . $service->countCompleted()->getValue() . "\n";
echo "  FAILED : " . $service->countFailed()->getValue() . "\n";
echo "  CANCELED : " . $service->countCanceled()->getValue() . "\n";
```

---

## Voir aussi

- `UniqueTaskRepository` - Repository pour les tâches uniques
- `RecurringTaskService` - Service pour les tâches récurrentes
- `AbstractUniqueTask` - Classe de base pour les tâches uniques
- `UniqueTaskConfigRecord` - Configuration des tâches
- `UniqueTaskStatus` - Énumération des statuts
- `TaskRunResultRecord` - Résultat d'une exécution
- `ProcessResultRecord` - Résultat d'un traitement par lot
