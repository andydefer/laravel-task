# UniqueTaskService - Référence Technique

## Description

Le `UniqueTaskService` est le service métier central pour la gestion des tâches uniques (exécutées une seule fois). Il orchestre l'enregistrement, l'exécution, le cycle de vie et les transitions d'état des tâches qui doivent être exécutées à une date précise.

## Hiérarchie / Implémentations

```
UniqueTaskService (final)
    └── UniqueTaskServiceInterface
```

**Interfaces implémentées :**
- `UniqueTaskServiceInterface` - Contrat définissant toutes les opérations métier

## Rôle principal

Ce service agit comme la couche d'orchestration métier pour les tâches uniques :

1. **Enregistrement** des nouvelles tâches uniques avec date de planification
2. **Exécution** des tâches avec gestion des tentatives et des erreurs
3. **Gestion du cycle de vie** : annulation, reprogrammation, extension du délai de grâce
4. **Détection et gestion des tâches expirées**
5. **Verrouillage** des tâches pour éviter les exécutions concurrentes
6. **Journalisation** des événements et des erreurs

## API / Méthodes publiques

### `register(UniqueTaskFqcnVO $fqcn, StrictDataObject $payload, UniqueTaskConfigRecord $config): TaskAliasVO`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$fqcn` | `UniqueTaskFqcnVO` | Nom complet de la classe de la tâche |
| `$payload` | `StrictDataObject` | Données à transmettre à la tâche |
| `$config` | `UniqueTaskConfigRecord` | Configuration (date, tentatives, grâce) |

**Retourne :** `TaskAliasVO` - Alias unique généré pour la tâche

**Exceptions :** 
- `InvalidArgumentException` si la classe n'existe pas
- `InvalidArgumentException` si la classe n'étend pas `AbstractUniqueTask`

**Exemple :**
```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Services\UniqueTaskService;
use AndyDefer\Task\ValueObjects\UniqueTaskFqcnVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\MaxAttemptsVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\Records\UniqueTaskConfigRecord;
use AndyDefer\DomainStructures\Utils\StrictDataObject;

$service = app(UniqueTaskService::class);

$fqcn = new UniqueTaskFqcnVO(SendWelcomeEmailTask::class);
$payload = StrictDataObject::from([
    'user_id' => 12345,
    'email' => 'newuser@example.com',
]);

$config = UniqueTaskConfigRecord::from([
    'scheduled_at' => new Iso8601DateTimeVO('2026-01-20 10:00:00'),
    'max_attempts' => new MaxAttemptsVO(3),
    'grace_period' => new DurationVO(3600), // 1 heure
]);

$alias = $service->register($fqcn, $payload, $config);
echo "✅ Tâche enregistrée : " . $alias->getValue() . "\n";
```

---

### `run(TaskAliasVO $alias): TaskRunResultRecord`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche à exécuter |

**Retourne :** `TaskRunResultRecord` - Résultat de l'exécution

**Comportement :**
1. Vérifie que la tâche existe
2. Effectue les vérifications pré-exécution (statut, date, tentatives)
3. Instancie la tâche et exécute sa méthode `execute()`
4. Marque la tâche comme `COMPLETED` en cas de succès
5. Gère les échecs avec incrémentation des tentatives
6. Journalise le résultat via `addDebug()`

**Exemple :**
```php
<?php

$alias = new TaskAliasVO('unique@550e8400-e29b-41d4-a716-446655440000');
$result = $service->run($alias);

if ($result->success) {
    echo "✅ Tâche exécutée avec succès\n";
    echo "Temps : " . $result->execution_time_ms->getValue() . "ms\n";
} elseif ($result->skipped ?? false) {
    echo "⏭️ Tâche ignorée : " . ($result->message ?? '') . "\n";
} else {
    echo "❌ Échec : " . $result->error . "\n";
}
```

---

### `process(LimitVO $limit = new LimitVO): ProcessResultRecord`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `LimitVO` | Nombre maximum de tâches à traiter |

**Retourne :** `ProcessResultRecord` - Bilan du traitement

**Comportement :**
1. Récupère les tâches prêtes via `findReadyToRun()` (verrouillées en `IN_PROGRESS`)
2. Exécute chaque tâche récupérée
3. Gère les tâches ignorées (`skipped`)
4. Détecte et traite les tâches expirées via `findExpired()`
5. Agrège les résultats (succès, échecs, ignorés)

**Exemple :**
```php
<?php

$limit = new LimitVO(25);
$result = $service->process($limit);

echo "📊 Bilan :\n";
echo "   ✅ Succès : " . $result->success->getValue() . "\n";
echo "   ❌ Échecs : " . $result->failed->getValue() . "\n";
echo "   ⏭️ Ignorés : " . $result->skipped->getValue() . "\n";
echo "   ⚠️ Erreurs : " . $result->errors->count() . "\n";
```

---

### `cancel(TaskAliasVO $alias, ?DescriptionVO $reason = null): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche à annuler |
| `$reason` | `DescriptionVO|null` | Raison de l'annulation |

**Retourne :** `bool` - `true` si l'opération a réussi

**Condition :** La tâche doit être en statut `PENDING`

**Transition :** `PENDING` → `CANCELED`

**Comportement :** Journalise l'annulation avec la raison

---

### `reschedule(TaskAliasVO $alias, Iso8601DateTimeVO $newScheduledAt): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche |
| `$newScheduledAt` | `Iso8601DateTimeVO` | Nouvelle date de planification |

**Retourne :** `bool` - `true` si l'opération a réussi

**Condition :** La tâche doit être en statut `PENDING`

**Comportement :** Met à jour la date de planification et journalise l'opération

---

### `extendGracePeriod(TaskAliasVO $alias, DurationVO $extraSeconds): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche |
| `$extraSeconds` | `DurationVO` | Secondes supplémentaires à ajouter |

**Retourne :** `bool` - `true` si l'opération a réussi

**Condition :** 
- La tâche doit être en statut `PENDING`
- `$extraSeconds` doit être > 0

**Comportement :** Ajoute les secondes au délai de grâce existant et journalise l'opération

---

### `find(TaskAliasVO $alias): ?UniqueTaskRecord`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche |

**Retourne :** `UniqueTaskRecord|null` - Enregistrement de la tâche ou null

---

### `findPending(LimitVO $limit = new LimitVO): UniqueTaskRecordCollection`
### `findCompleted(LimitVO $limit = new LimitVO): UniqueTaskRecordCollection`
### `findFailed(LimitVO $limit = new LimitVO): UniqueTaskRecordCollection`
### `findCanceled(LimitVO $limit = new LimitVO): UniqueTaskRecordCollection`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `LimitVO` | Nombre maximum de tâches à retourner |

**Retourne :** `UniqueTaskRecordCollection` - Collection d'enregistrements filtrés par statut

---

### `exists(TaskAliasVO $alias): bool`

**Retourne :** `bool` - `true` si la tâche existe

---

### `delete(TaskAliasVO $alias): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche à supprimer |

**Retourne :** `bool` - `true` si la suppression a réussi

---

### `count(): CounterVO`
### `countPending(): CounterVO`
### `countCompleted(): CounterVO`
### `countFailed(): CounterVO`
### `countCanceled(): CounterVO`

**Retourne :** `CounterVO` - Nombre total de tâches ou par statut

---

## Cas d'utilisation

### Cas 1 : Envoi d'un email de bienvenue à la création d'un compte

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Services\UniqueTaskService;
use AndyDefer\Task\ValueObjects\UniqueTaskFqcnVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\MaxAttemptsVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\Records\UniqueTaskConfigRecord;
use AndyDefer\DomainStructures\Utils\StrictDataObject;

$service = app(UniqueTaskService::class);

// 1. Enregistrer un email de bienvenue pour un nouvel utilisateur
$fqcn = new UniqueTaskFqcnVO(SendWelcomeEmailTask::class);
$payload = StrictDataObject::from([
    'user_id' => $newUserId,
    'email' => $userEmail,
    'name' => $userName,
]);

$config = UniqueTaskConfigRecord::from([
    'scheduled_at' => new Iso8601DateTimeVO('now + 5 minutes'),
    'max_attempts' => new MaxAttemptsVO(5),
    'grace_period' => new DurationVO(3600), // 1 heure
]);

$alias = $service->register($fqcn, $payload, $config);

echo "📧 Email programmé pour " . $userEmail . "\n";
echo "   Alias : " . $alias->getValue() . "\n";

// 2. Exécuter le traitement (dans un worker)
$result = $service->process(new LimitVO(10));

echo "📊 Traitement terminé :\n";
echo "   Succès : " . $result->success->getValue() . "\n";
echo "   Échecs : " . $result->failed->getValue() . "\n";
```

### Cas 2 : Annulation et reprogrammation

```php
<?php

use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;

$alias = new TaskAliasVO('unique@550e8400-e29b-41d4-a716-446655440000');

// ✅ Annuler une tâche avec raison
$reason = new DescriptionVO('Commande annulée par le client');
if ($service->cancel($alias, $reason)) {
    echo "🚫 Tâche annulée\n";
}

// ✅ Reprogrammer une tâche à une date ultérieure
if ($service->reschedule($alias, new Iso8601DateTimeVO('2026-02-01 14:00:00'))) {
    echo "📅 Tâche reprogrammée\n";
}

// ✅ Prolonger le délai de grâce de 2 heures
if ($service->extendGracePeriod($alias, new DurationVO(7200))) {
    echo "⏰ Délai de grâce étendu de 2 heures\n";
}
```

### Cas 3 : Supervision des tâches en attente

```php
<?php

use AndyDefer\Task\ValueObjects\LimitVO;

echo "📊 Statistiques des tâches uniques :\n";
echo "   📦 Total : " . $service->count()->getValue() . "\n";
echo "   ⏳ En attente : " . $service->countPending()->getValue() . "\n";
echo "   ✅ Terminées : " . $service->countCompleted()->getValue() . "\n";
echo "   ❌ Échouées : " . $service->countFailed()->getValue() . "\n";
echo "   🚫 Annulées : " . $service->countCanceled()->getValue() . "\n";

// Liste des tâches en attente avec détails
$pendingTasks = $service->findPending(new LimitVO(20));
foreach ($pendingTasks as $task) {
    $now = new Iso8601DateTimeVO;
    $isExpired = $task->scheduled_at->diffInSeconds($now)->getValue() > $task->grace_period_seconds->getValue();
    
    echo sprintf(
        "   - %s (planifiée: %s, grâce: %ds, %s)\n",
        $task->alias->getValue(),
        $task->scheduled_at->getValue(),
        $task->grace_period_seconds->getValue(),
        $isExpired ? '⚠️ EXPIRÉE' : '✅ OK'
    );
}
```

### Cas 4 : Exécution avec gestion des erreurs et des tentatives

```php
<?php

use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\LimitVO;

$alias = new TaskAliasVO('unique@550e8400-e29b-41d4-a716-446655440000');

// Exécuter une tâche spécifique
$result = $service->run($alias);

if ($result->skipped ?? false) {
    echo "⏭️ Tâche ignorée : " . ($result->message ?? '') . "\n";
} elseif ($result->success) {
    echo "✅ Tâche réussie en " . $result->execution_time_ms->getValue() . "ms\n";
} else {
    echo "❌ Échec : " . $result->error . "\n";
    
    // Vérifier l'état pour décider de la suite
    $task = $service->find($alias);
    if ($task) {
        $attempts = $task->attempts->getValue();
        $maxAttempts = $task->max_attempts->getValue();
        
        echo "   Tentatives : {$attempts}/{$maxAttempts}\n";
        
        if ($attempts >= $maxAttempts) {
            echo "   ⚠️ Max tentatives atteint, tâche en échec\n";
        } else {
            // Reprogrammer pour plus tard
            $newDate = (new Iso8601DateTimeVO)->addSeconds(300);
            $service->reschedule($alias, $newDate);
            echo "   📅 Reprogrammée à " . $newDate->getValue() . "\n";
        }
    }
}

// Traitement en lot avec gestion des erreurs
$processResult = $service->process(new LimitVO(50));

if ($processResult->errors->count() > 0) {
    echo "\n⚠️ Erreurs rencontrées :\n";
    foreach ($processResult->errors as $error) {
        echo "   ❌ " . $error->alias->getValue() . "\n";
        echo "      " . $error->description->getValue() . "\n";
    }
}
```

## Flux d'exécution de `process()`

```
┌─────────────────────────────────────────────────────────────────────┐
│                    UniqueTaskService::process()                     │
└────────────────────────┬────────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────────┐
│  1. repository->findReadyToRun($now, $limit)                        │
│     - lockForUpdate() dans une transaction                          │
│     - PENDING → IN_PROGRESS (en lot)                                │
│     - Retourne les tâches verrouillées                              │
└────────────────────────┬────────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────────┐
│  2. Pour chaque tâche récupérée                                     │
│     - $this->run($alias)                                            │
│       ├── Vérifications pré-exécution (performPreExecutionChecks)   │
│       │   ├── Statut PENDING ou IN_PROGRESS ?                       │
│       │   ├── scheduled_at <= now ?                                 │
│       │   └── attempts < max_attempts ?                             │
│       ├── Exécution de la tâche                                     │
│       ├── Succès → moveToCompleted()                                │
│       └── Échec → updateAttempts() ou moveToFailed()                │
└────────────────────────┬────────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────────┐
│  3. repository->findExpired($now, $limit)                           │
│     - Détecte les tâches PENDING dont la grâce est dépassée         │
│     - moveToFailed() pour chaque tâche expirée                      │
└────────────────────────┬────────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────────┐
│  4. Retourner ProcessResultRecord                                   │
│     - success, failed, skipped, errors                              │
└─────────────────────────────────────────────────────────────────────┘
```

## Flux d'exécution de `run()`

```
┌─────────────────────────────────────────────────────────────────────┐
│                      UniqueTaskService::run()                       │
└────────────────────────┬────────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────────┐
│  1. repository->findByAlias($alias)                                 │
│     - Tâche existe ? → Non → createNotFoundResult()                 │
└────────────────────────┬────────────────────────────────────────────┘
                         │ Oui
                         ▼
┌─────────────────────────────────────────────────────────────────────┐
│  2. performPreExecutionChecks()                                     │
│     ├── Statut === IN_PROGRESS → OK                                 │
│     ├── Statut !== PENDING → createSkippedResult()                  │
│     ├── scheduled_at > now → createSkippedResult()                  │
│     └── attempts >= max_attempts → moveToFailed() +                 │
│     createSkippedResult()│                                          │
└────────────────────────┬────────────────────────────────────────────┘
                         │ OK
                         ▼
┌─────────────────────────────────────────────────────────────────────┐
│  3. instantiateTask() + execute()                                   │
│     ├── Succès → moveToCompleted()                                  │
│     └── Échec → handleExecutionFailure()                            │
│         ├── attempts+1 >= max_attempts → moveToFailed()             │
│         └── attempts+1 < max_attempts → updateAttempts()            │
└────────────────────────┬────────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────────┐
│  4. repository->addDebug() (enfin)                                  │
│     - ExecutionStatus::SUCCEEDED ou FAILED                          │
│     - Message descriptif                                            │
└─────────────────────────────────────────────────────────────────────┘
```

## Gestion des erreurs

| Situation | Comportement | Message |
|-----------|--------------|---------|
| Tâche non trouvée (`run`) | `createNotFoundResult()` | `'Task not found'` |
| Statut non `PENDING` ou `IN_PROGRESS` | `createSkippedResult()` | `'Task is not in PENDING or IN_PROGRESS state (current: X) - skipped'` |
| Tâche programmée dans le futur | `createSkippedResult()` | `'Task is scheduled in the future - skipped'` |
| Max tentatives atteint | `moveToFailed()` + `createSkippedResult()` | `'Maximum attempts reached (X/Y) - skipped'` |
| Exception dans l'exécution | `handleExecutionFailure()` | Message d'erreur original |
| Tâche expirée (`process`) | `moveToFailed()` | `'Task expired'` |
| Annulation sur statut non `PENDING` | Retourne `false` | - |
| Reprogrammation sur statut non `PENDING` | Retourne `false` | - |

**Exceptions propagées :**
- `InvalidArgumentException` lors de l'enregistrement si la classe est invalide
- Les autres erreurs sont capturées et retournées via les codes de retour

## Performance

| Opération | Complexité | Description |
|-----------|-----------|-------------|
| `register()` | O(1) | Insertion d'une seule tâche |
| `run()` | O(1) + exécution tâche | Récupération + exécution |
| `process()` | O(n) | n = nombre de tâches traitées (limit) + tâches expirées |
| `find*()` | O(n) | n = limit ou nombre de résultats |
| `count*()` | O(1) | COUNT query |

**Recommandations :**
- Utiliser `process()` avec un `limit` raisonnable (10-100) pour éviter les batchs trop gros
- `findExpired()` charge toutes les tâches PENDING puis filtre en mémoire → à utiliser avec précaution
- Les `count*()` sont légers et peuvent être utilisés fréquemment

## Compatibilité

| Version | Support |
|---------|---------|
| PHP 8.1+ | ✅ Complet |
| PHP 8.0 | ✅ Complet |
| Laravel 10+ | ✅ Complet |
| Laravel 9 | ✅ Complet |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Services\UniqueTaskService;
use AndyDefer\Task\ValueObjects\UniqueTaskFqcnVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\MaxAttemptsVO;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\Task\Records\UniqueTaskConfigRecord;
use AndyDefer\DomainStructures\Utils\StrictDataObject;

$service = app(UniqueTaskService::class);

// ============================================================
// 1. ENREGISTREMENT DE TÂCHES
// ============================================================
echo "📝 Enregistrement de tâches uniques...\n";

// Tâche 1 : Envoi d'email de bienvenue
$fqcn1 = new UniqueTaskFqcnVO(SendWelcomeEmailTask::class);
$payload1 = StrictDataObject::from([
    'user_id' => 123,
    'email' => 'newuser@example.com',
]);
$config1 = UniqueTaskConfigRecord::from([
    'scheduled_at' => new Iso8601DateTimeVO('2026-01-20 10:00:00'),
    'max_attempts' => new MaxAttemptsVO(3),
    'grace_period' => new DurationVO(3600),
]);
$alias1 = $service->register($fqcn1, $payload1, $config1);
echo "   ✅ Email de bienvenue : " . $alias1->getValue() . "\n";

// Tâche 2 : Génération de rapport
$fqcn2 = new UniqueTaskFqcnVO(GenerateReportTask::class);
$payload2 = StrictDataObject::from([
    'report_type' => 'daily_sales',
    'date' => '2026-01-19',
]);
$config2 = UniqueTaskConfigRecord::from([
    'scheduled_at' => new Iso8601DateTimeVO('2026-01-20 06:00:00'),
    'max_attempts' => new MaxAttemptsVO(5),
    'grace_period' => new DurationVO(7200),
]);
$alias2 = $service->register($fqcn2, $payload2, $config2);
echo "   ✅ Génération rapport : " . $alias2->getValue() . "\n\n";

// ============================================================
// 2. PROCESSUS PRINCIPAL (TRAITEMENT DES TÂCHES PRÊTES)
// ============================================================
echo "🔄 Traitement des tâches prêtes...\n";

$limit = new LimitVO(25);
$result = $service->process($limit);

echo "📊 Résultats :\n";
echo "   ✅ Succès : " . $result->success->getValue() . "\n";
echo "   ❌ Échecs : " . $result->failed->getValue() . "\n";
echo "   ⏭️ Ignorés : " . $result->skipped->getValue() . "\n";
echo "   ⚠️ Erreurs : " . $result->errors->count() . "\n\n";

if ($result->errors->count() > 0) {
    echo "Détail des erreurs :\n";
    foreach ($result->errors as $error) {
        echo "   ❌ " . $error->alias->getValue() . "\n";
        echo "      " . $error->description->getValue() . "\n";
        echo "      Contexte : " . $error->context . "\n";
    }
    echo "\n";
}

// ============================================================
// 3. GESTION DES TÂCHES EN ATTENTE
// ============================================================
echo "⏳ Gestion des tâches en attente...\n";

// Vérifier si une tâche existe
if ($service->exists($alias1)) {
    echo "   ✅ Tâche existe\n";
}

// Reprogrammer une tâche
$service->reschedule($alias1, new Iso8601DateTimeVO('2026-01-21 10:00:00'));
echo "   📅 Tâche reprogrammée au 21/01/2026\n";

// Prolonger le délai de grâce
$service->extendGracePeriod($alias1, new DurationVO(1800));
echo "   ⏰ Délai de grâce étendu de 30 min\n\n";

// ============================================================
// 4. ANNULATION
// ============================================================
$reason = new DescriptionVO('Rapport plus nécessaire');
if ($service->cancel($alias2, $reason)) {
    echo "🚫 Tâche annulée : " . $reason->getValue() . "\n\n";
}

// ============================================================
// 5. SUPERVISION
// ============================================================
echo "📊 Supervision :\n";
echo "   📦 Total : " . $service->count()->getValue() . "\n";
echo "   ⏳ En attente : " . $service->countPending()->getValue() . "\n";
echo "   ✅ Terminées : " . $service->countCompleted()->getValue() . "\n";
echo "   ❌ Échouées : " . $service->countFailed()->getValue() . "\n";
echo "   🚫 Annulées : " . $service->countCanceled()->getValue() . "\n";

// ============================================================
// 6. RÉCUPÉRATION D'UNE TÂCHE SPÉCIFIQUE
// ============================================================
$task = $service->find($alias1);
if ($task !== null) {
    echo "\n📋 Détail de la tâche :\n";
    echo "   Alias : " . $task->alias->getValue() . "\n";
    echo "   FQCN : " . $task->fqcn->getValue() . "\n";
    echo "   Statut : " . $task->status->value . "\n";
    echo "   Planifiée : " . $task->scheduled_at->getValue() . "\n";
    echo "   Délai de grâce : " . $task->grace_period_seconds->getValue() . "s\n";
    echo "   Tentatives : " . $task->attempts->getValue() . "/" . $task->max_attempts->getValue() . "\n";
}

// ============================================================
// 7. SUPPRESSION
// ============================================================
if ($service->delete($alias2)) {
    echo "\n🗑️ Tâche supprimée : " . $alias2->getValue() . "\n";
}
```

## Voir aussi
- `UniqueTaskRepository` - Dépôt utilisé pour les opérations de base de données
- `UniqueTaskServiceInterface` - Interface du service
- `AbstractUniqueTask` - Classe abstraite à étendre pour les tâches uniques
- `UniqueTaskRecord` - Data Transfer Object
- `TaskRunResultRecord` - Résultat d'exécution
- `ProcessResultRecord` - Résultat du traitement en lot
- `RecurringTaskService` - Service similaire pour les tâches récurrentes