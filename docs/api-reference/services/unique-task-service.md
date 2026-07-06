# UniqueTaskService - Référence Technique

## Description

Service central de gestion des tâches uniques. Il orchestre l'enregistrement, l'exécution, le cycle de vie et le traitement par lots des tâches qui ne doivent s'exécuter qu'une seule fois.

## Hiérarchie / Implémentations

```
UniqueTaskServiceInterface
    └── UniqueTaskService
```

## Rôle principal

Fournir une API complète pour la gestion du cycle de vie des tâches uniques :
- Enregistrement de nouvelles tâches avec planification
- Exécution individuelle ou par lots
- Gestion des états (PENDING → COMPLETED/FAILED/CANCELED)
- Gestion des tentatives et de l'expiration
- Reprise d'exécution (reschedule) et prolongation de la période de grâce

## API / Méthodes publiques

### `register(UniqueTaskFqcnVO $fqcn, StrictDataObject $payload, UniqueTaskConfigRecord $config): TaskAliasVO`

Enregistre une nouvelle tâche unique.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$fqcn` | `UniqueTaskFqcnVO` | Nom complet de la classe de la tâche |
| `$payload` | `StrictDataObject` | Données d'entrée de la tâche |
| `$config` | `UniqueTaskConfigRecord` | Configuration (planification, grâce, tentatives) |

**Retourne :** `TaskAliasVO` - Alias unique de la tâche créée

**Exceptions :** `InvalidArgumentException` - Si la classe n'existe pas ou n'hérite pas de `AbstractUniqueTask`

**Exemple :**
```php
$config = UniqueTaskConfigRecord::from([
    'scheduled_at' => Carbon::now()->addHours(2)->toIso8601String(),
    'grace_period' => 86400,
    'max_attempts' => 3,
]);

$alias = $service->register(
    new UniqueTaskFqcnVO(SendEmailTask::class),
    StrictDataObject::from(['email' => 'user@example.com']),
    $config
);
```

---

### `run(TaskAliasVO $alias): TaskRunResultRecord`

Exécute une tâche unique spécifique par son alias.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche à exécuter |

**Retourne :** `TaskRunResultRecord` - Résultat de l'exécution (succès/échec, temps, erreur)

**Exemple :**
```php
$result = $service->run($alias);

if ($result->success) {
    echo "Tâche exécutée avec succès en {$result->execution_time_ms}ms";
} else {
    echo "Échec : {$result->error}";
}
```

---

### `process(LimitVO $limit = new LimitVO): ProcessResultRecord`

Traite un lot de tâches uniques prêtes à être exécutées.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `LimitVO` | Nombre maximum de tâches à traiter |

**Retourne :** `ProcessResultRecord` - Résumé du traitement (succès, échecs)

**Exemple :**
```php
$result = $service->process(new LimitVO(50));

echo "Succès : {$result->success->getValue()}\n";
echo "Échecs : {$result->failed->getValue()}\n";
```

---

### `cancel(TaskAliasVO $alias, ?DescriptionVO $reason = null): bool`

Annule une tâche unique en attente.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche à annuler |
| `$reason` | `DescriptionVO|null` | Raison de l'annulation (optionnelle) |

**Retourne :** `bool` - `true` si l'annulation a réussi, `false` sinon

**Exemple :**
```php
$reason = new DescriptionVO('Commande annulée par le client');
if ($service->cancel($alias, $reason)) {
    echo "Tâche annulée";
}
```

---

### `reschedule(TaskAliasVO $alias, Iso8601DateTimeVO $newScheduledAt): bool`

Reporte l'exécution d'une tâche à une nouvelle date.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche |
| `$newScheduledAt` | `Iso8601DateTimeVO` | Nouvelle date de planification |

**Retourne :** `bool` - `true` si le report a réussi, `false` sinon

**Exemple :**
```php
$newDate = new Iso8601DateTimeVO(Carbon::now()->addDays(3)->toIso8601String());
$service->reschedule($alias, $newDate);
```

---

### `extendGracePeriod(TaskAliasVO $alias, DurationVO $extraSeconds): bool`

Prolonge la période de grâce d'une tâche.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche |
| `$extraSeconds` | `DurationVO` | Nombre de secondes supplémentaires |

**Retourne :** `bool` - `true` si la prolongation a réussi, `false` sinon

**Exemple :**
```php
$service->extendGracePeriod($alias, new DurationVO(3600));
```

---

### `find(TaskAliasVO $alias): ?UniqueTaskRecord`

Recherche une tâche par son alias.

---

### `findPending(LimitVO $limit = new LimitVO): UniqueTaskRecordCollection`

Retourne les tâches en attente (statut PENDING).

---

### `findCompleted(LimitVO $limit = new LimitVO): UniqueTaskRecordCollection`

Retourne les tâches terminées avec succès (statut COMPLETED).

---

### `findFailed(LimitVO $limit = new LimitVO): UniqueTaskRecordCollection`

Retourne les tâches en échec (statut FAILED).

---

### `findCanceled(LimitVO $limit = new LimitVO): UniqueTaskRecordCollection`

Retourne les tâches annulées (statut CANCELED).

---

### `exists(TaskAliasVO $alias): bool`

Vérifie si une tâche existe.

---

### `delete(TaskAliasVO $alias): bool`

Supprime une tâche.

---

### `count(): CounterVO`, `countPending(): CounterVO`, etc.

Compteurs par statut.

## Cycle de vie des états

```
                    ┌──────────────────┐
                    │                  ▼
PENDING ──────────────────────────▶ COMPLETED
    │                                ▲
    │                                │
    ├─────────────▶ FAILED ──────────┘
    │
    └─────────────▶ CANCELED
```

### Conditions de transition

| Transition | Condition |
|------------|-----------|
| PENDING → COMPLETED | Exécution réussie |
| PENDING → FAILED | Échec après max_attempts OU expiration |
| PENDING → CANCELED | Annulation manuelle |

## Cas d'utilisation

### Cas 1 : Enregistrement d'une tâche planifiée

**Problème :** Créer une tâche qui s'exécute demain à 8h.

```php
$config = UniqueTaskConfigRecord::from([
    'scheduled_at' => Carbon::tomorrow()->setTime(8, 0)->toIso8601String(),
    'grace_period' => 3600,
    'max_attempts' => 3,
]);

$alias = $service->register(
    new UniqueTaskFqcnVO(DailyReportTask::class),
    StrictDataObject::from(['date' => '2026-01-01']),
    $config
);
```

---

### Cas 2 : Traitement par lots avec gestion des expirations

**Problème :** Traiter toutes les tâches prêtes et nettoyer celles qui ont expiré.

```php
$result = $service->process(new LimitVO(100));

// Les tâches expirées sont automatiquement marquées comme FAILED
foreach ($result->errors as $error) {
    if (str_contains($error->description, 'expired')) {
        echo "Tâche expirée : {$error->alias->getValue()}\n";
    }
}
```

---

### Cas 3 : Reprise après échec

**Problème :** Une tâche a échoué et doit être réessayée plus tard.

```php
// Vérifier si la tâche a échoué
$task = $service->find($alias);
if ($task->status === UniqueTaskStatus::FAILED) {
    // Recréer une nouvelle tâche
    $newAlias = $service->register(
        $task->fqcn,
        $task->payload,
        UniqueTaskConfigRecord::from([
            'scheduled_at' => Carbon::now()->addHour()->toIso8601String(),
            'grace_period' => 3600,
            'max_attempts' => 3,
        ])
    );
}
```

---

### Cas 4 : Prolongation de la période de grâce

**Problème :** Une tâche critique est sur le point d'expirer et a besoin de plus de temps.

```php
$task = $service->find($alias);
$remaining = $task->grace_period_seconds->getValue() - Carbon::now()->diffInSeconds($task->scheduled_at);

if ($remaining < 3600) {
    $service->extendGracePeriod($alias, new DurationVO(86400));
    echo "Période de grâce prolongée de 24h";
}
```

---

### Cas 5 : Report d'une tâche

**Problème :** Une tâche doit être reportée en raison d'une indisponibilité.

```php
$newDate = new Iso8601DateTimeVO(Carbon::now()->addWeek()->toIso8601String());
if ($service->reschedule($alias, $newDate)) {
    echo "Tâche reportée d'une semaine";
}
```

## Gestion des erreurs

| Situation | Exception/Retour | Message |
|-----------|------------------|---------|
| Classe de tâche inexistante | `InvalidArgumentException` | `Task class "X" does not exist.` |
| Classe non valide | `InvalidArgumentException` | `Class "X" must extend AbstractUniqueTask` |
| Tâche non trouvée | `false` / `null` | - |
| Tâche non en PENDING pour annulation | `false` | - |
| Tâche non en PENDING pour reschedule | `false` | - |
| Grace period ≤ 0 | `false` | - |
| Échec d'exécution | `TaskRunResultRecord` avec `success = false` | Message d'erreur de l'exception |

## Comportement des tentatives

```
Tentative 1 → Échec → attempts = 1
Tentative 2 → Échec → attempts = 2
Tentative 3 → Échec → attempts = 3 = max_attempts → FAILED
```

## Intégration

### Dépendances

- `UniqueTaskRepositoryInterface` : Accès aux données
- `LoggerInterface` : Logging
- `HydrationService` : Hydratation des objets
- `Application` : Conteneur Laravel

### Points d'extension

- Le repository peut être remplacé pour utiliser un stockage différent
- Les loggers peuvent être personnalisés

## Performance

- **Recherches** : Indexées sur `alias` et `status`
- **Expiration** : Calculée en mémoire, pas de requête supplémentaire
- **Traitement par lots** : Atomicité garantie par les transactions
- **Recommandation** : Utiliser `LimitVO` pour les gros volumes

## Compatibilité

| Version PHP | Support |
|-------------|---------|
| PHP 8.2+ | ✅ Complet |
| PHP 8.1 | ✅ Complet |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Services\UniqueTaskService;
use AndyDefer\Task\ValueObjects\UniqueTaskFqcnVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\LimitVO;
use Illuminate\Support\Carbon;

/** @var UniqueTaskService $service */
$service = app(UniqueTaskService::class);

// 1. Enregistrement d'une tâche
$config = UniqueTaskConfigRecord::from([
    'scheduled_at' => Carbon::now()->addHour()->toIso8601String(),
    'grace_period' => 86400,
    'max_attempts' => 3,
]);

$alias = $service->register(
    new UniqueTaskFqcnVO(ExportTask::class),
    StrictDataObject::from(['format' => 'csv']),
    $config
);

echo "Tâche créée : {$alias->getValue()}\n";

// 2. Vérification
if ($service->exists($alias)) {
    $task = $service->find($alias);
    echo "Statut : {$task->status->value}\n";
}

// 3. Traitement par lots
$result = $service->process(new LimitVO(10));
echo "Traitement : {$result->success->getValue()} succès, {$result->failed->getValue()} échecs\n";

// 4. Report si nécessaire
if (!$result->success->isPositive()) {
    $service->reschedule($alias, new Iso8601DateTimeVO(Carbon::now()->addDay()->toIso8601String()));
}

// 5. Annulation
$service->cancel($alias, new DescriptionVO('Demande annulée'));
```

## Voir aussi

- `RecurringTaskService` - Service de tâches récurrentes
- `UniqueTaskRepositoryInterface` - Repository des tâches uniques
- `UniqueTaskRecord` - Structure de données
- `UniqueTaskStatus` - États des tâches
---