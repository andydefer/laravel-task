# TaskExecutionDebugService - Référence Technique

## Description

Service de gestion des informations de débogage pour l'exécution des tâches. Permet de stocker, consulter et nettoyer les traces d'exécution des tâches (uniques et récurrentes) pour faciliter le diagnostic et le monitoring.

## Hiérarchie / Implémentations

```
TaskExecutionDebugServiceInterface
    └── TaskExecutionDebugService
```

## Rôle principal

Fournir une API de gestion des données de débogage :
- Recherche par alias ou FQCN
- Ajout de traces d'exécution avec statut
- Nettoyage des anciennes traces
- Comptage et vérification de présence

## API / Méthodes publiques

### `findByAlias(TaskAliasVO $alias): TaskExecutionDebugRecordCollection`

Recherche toutes les entrées de débogage pour une tâche donnée par son alias.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche |

**Retourne :** `TaskExecutionDebugRecordCollection` - Collection des enregistrements de débogage

**Exemple :**
```php
$records = $service->findByAlias($alias);

foreach ($records as $record) {
    echo "Statut : {$record->status->value}\n";
    echo "Info : {$record->info->getValue()}\n";
    echo "Date : {$record->created_at->getValue()}\n";
}
```

---

### `findByFqcn(TaskFqcnVO $fqcn): TaskExecutionDebugRecordCollection`

Recherche toutes les entrées de débogage pour une classe de tâche donnée.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$fqcn` | `TaskFqcnVO` | Nom complet de la classe de tâche |

**Retourne :** `TaskExecutionDebugRecordCollection` - Collection des enregistrements de débogage

**Exemple :**
```php
$fqcn = new TaskFqcnVO(MyTask::class);
$records = $service->findByFqcn($fqcn);

echo "Nombre d'exécutions : {$records->count()}\n";
```

---

### `findByRecurringTask(TaskAliasVO $alias): TaskExecutionDebugRecordCollection`

Alias de `findByAlias()` pour les tâches récurrentes.

---

### `findByUniqueTask(TaskAliasVO $alias): TaskExecutionDebugRecordCollection`

Alias de `findByAlias()` pour les tâches uniques.

---

### `addDebug(TaskAliasVO $alias, TaskFqcnVO $fqcn, ExecutionStatus $status, DescriptionVO $info, ?StrictDataObject $data = null): bool`

Ajoute une entrée de débogage pour une tâche.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche |
| `$fqcn` | `TaskFqcnVO` | Classe de la tâche |
| `$status` | `ExecutionStatus` | Statut de l'exécution (SUCCEEDED/FAILED) |
| `$info` | `DescriptionVO` | Information descriptive |
| `$data` | `StrictDataObject|null` | Données supplémentaires optionnelles |

**Retourne :** `bool` - `true` si l'ajout a réussi, `false` sinon

**Exemple :**
```php
$service->addDebug(
    $alias,
    new TaskFqcnVO(MyTask::class),
    ExecutionStatus::FAILED,
    new DescriptionVO('Tentative 1 échouée : timeout'),
    StrictDataObject::from(['duration' => 30, 'retry' => 2])
);
```

---

### `addDebugForRecurringTask(...): bool`

Alias de `addDebug()` pour les tâches récurrentes.

---

### `addDebugForUniqueTask(...): bool`

Alias de `addDebug()` pour les tâches uniques.

---

### `clearTaskDebug(TaskAliasVO $alias): bool`

Supprime toutes les entrées de débogage pour une tâche.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche |

**Retourne :** `bool` - `true` si le nettoyage a réussi, `false` sinon

**Exemple :**
```php
if ($service->clearTaskDebug($alias)) {
    echo "Traces de débogage supprimées";
}
```

---

### `clearTaskDebugByFqcn(TaskFqcnVO $fqcn): bool`

Supprime toutes les entrées de débogage pour une classe de tâche.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$fqcn` | `TaskFqcnVO` | Classe de la tâche |

**Retourne :** `bool` - `true` si le nettoyage a réussi, `false` sinon

**Exemple :**
```php
$fqcn = new TaskFqcnVO(MyTask::class);
$service->clearTaskDebugByFqcn($fqcn);
```

---

### `countTaskDebug(TaskAliasVO $alias): CounterVO`

Compte le nombre d'entrées de débogage pour une tâche.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche |

**Retourne :** `CounterVO` - Nombre d'entrées

**Exemple :**
```php
$count = $service->countTaskDebug($alias);
echo "{$count->getValue()} exécutions enregistrées";
```

---

### `countTaskDebugByFqcn(TaskFqcnVO $fqcn): CounterVO`

Compte le nombre d'entrées de débogage pour une classe de tâche.

---

### `hasDebug(TaskAliasVO $alias): bool`

Vérifie si une tâche a des entrées de débogage.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$alias` | `TaskAliasVO` | Alias de la tâche |

**Retourne :** `bool` - `true` si des entrées existent

**Exemple :**
```php
if ($service->hasDebug($alias)) {
    echo "Des traces d'exécution sont disponibles";
}
```

---

### `hasDebugByFqcn(TaskFqcnVO $fqcn): bool`

Vérifie si une classe de tâche a des entrées de débogage.

## Cas d'utilisation

### Cas 1 : Suivi des échecs d'une tâche

**Problème :** Une tâche échoue régulièrement, il faut comprendre pourquoi.

```php
$records = $service->findByAlias($alias);

$failedRecords = $records->filter(fn($r) => $r->status === ExecutionStatus::FAILED);

foreach ($failedRecords as $record) {
    echo "Échec du {$record->created_at->getValue()} : {$record->info->getValue()}\n";
    if ($record->data) {
        var_dump($record->data->toArray());
    }
}
```

---

### Cas 2 : Journalisation des exécutions réussies

**Problème :** Enregistrer chaque exécution réussie pour audit.

```php
$service->addDebug(
    $alias,
    new TaskFqcnVO(BackupTask::class),
    ExecutionStatus::SUCCEEDED,
    new DescriptionVO('Sauvegarde terminée avec succès'),
    StrictDataObject::from([
        'duration' => 120,
        'files' => 45,
        'size' => '2.3GB',
    ])
);
```

---

### Cas 3 : Nettoyage périodique des logs

**Problème :** Les logs de débogage s'accumulent et doivent être nettoyés.

```php
// Nettoyer les logs d'une tâche spécifique
$service->clearTaskDebug($alias);

// Nettoyer les logs d'un type de tâche
$service->clearTaskDebugByFqcn(new TaskFqcnVO(DeprecatedTask::class));
```

---

### Cas 4 : Dashboard de monitoring

**Problème :** Afficher un dashboard des exécutions de tâches.

```php
$fqcn = new TaskFqcnVO(CriticalTask::class);

if ($service->hasDebugByFqcn($fqcn)) {
    $records = $service->findByFqcn($fqcn);
    $success = $records->filter(fn($r) => $r->status === ExecutionStatus::SUCCEEDED);
    $failed = $records->filter(fn($r) => $r->status === ExecutionStatus::FAILED);
    
    echo "Taux de succès : " . round(($success->count() / $records->count()) * 100, 2) . "%\n";
}
```

## Gestion des erreurs

| Situation | Comportement | Message loggé |
|-----------|--------------|---------------|
| Erreur lors de la recherche par alias | Retourne une collection vide | `task_debug_find_by_alias` |
| Erreur lors de la recherche par FQCN | Retourne une collection vide | `task_debug_find_by_fqcn` |
| Erreur lors de l'ajout d'une entrée | Retourne `false` | `task_debug_add_error` |
| Erreur lors du nettoyage | Retourne `false` | `task_debug_clear_error` |
| Erreur lors du comptage | Retourne `CounterVO(0)` | `task_debug_count_error` |

## Flux d'exécution

```
addDebug()
    ├── Tentative d'ajout via repository
    │   ├── Succès → Log DEBUG + retour true
    │   └── Échec → Log ERROR + retour false
    └── (Exception) → Log ERROR + retour false

findByAlias()
    ├── Tentative de recherche via repository
    │   ├── Succès → Conversion models → records
    │   └── Échec → Log ERROR + retour collection vide
    └── (Exception) → Log ERROR + retour collection vide
```

## Intégration

### Dépendances

- `TaskExecutionDebugRepositoryInterface` : Accès aux données de débogage
- `LoggerInterface` : Logging des opérations

### Points d'extension

- Le repository peut être remplacé pour utiliser un stockage différent
- Les logs peuvent être redirigés vers différents canaux

## Performance

- **Recherches** : Indexées sur `alias` et `fqcn`
- **Ajouts** : Écriture atomique en base de données
- **Nettoyage** : Suppression en masse optimisée
- **Recommandation** : Nettoyer périodiquement les anciennes entrées

## Compatibilité

| Version PHP | Support |
|-------------|---------|
| PHP 8.2+ | ✅ Complet |
| PHP 8.1 | ✅ Complet |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Services\TaskExecutionDebugService;
use AndyDefer\Task\Enums\ExecutionStatus;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\TaskFqcnVO;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\DomainStructures\Utils\StrictDataObject;

/** @var TaskExecutionDebugService $service */
$service = app(TaskExecutionDebugService::class);

$alias = new TaskAliasVO('unique@abc-123');
$fqcn = new TaskFqcnVO(MyTask::class);

// 1. Ajout d'une trace de débogage
$service->addDebug(
    $alias,
    $fqcn,
    ExecutionStatus::SUCCEEDED,
    new DescriptionVO('Task executed successfully'),
    StrictDataObject::from(['duration_ms' => 1500, 'memory_usage' => '12MB'])
);

// 2. Ajout d'une trace d'échec
$service->addDebug(
    $alias,
    $fqcn,
    ExecutionStatus::FAILED,
    new DescriptionVO('Connection timeout after 30s'),
    StrictDataObject::from(['attempt' => 2, 'timeout' => 30])
);

// 3. Consultation des logs
$records = $service->findByAlias($alias);
echo "Nombre d'exécutions : {$records->count()}\n";

// 4. Vérification de présence
if ($service->hasDebug($alias)) {
    echo "Des traces existent pour cette tâche\n";
}

// 5. Nettoyage
$service->clearTaskDebug($alias);
```

## Voir aussi

- `TaskExecutionDebugRepositoryInterface` - Repository de débogage
- `TaskExecutionDebugRecord` - Structure de données
- `ExecutionStatus` - Statuts d'exécution
- `UniqueTaskService` - Service de tâches uniques
- `RecurringTaskService` - Service de tâches récurrentes
---
