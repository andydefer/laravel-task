# TaskRepository - Référence Technique

## Description

Repository pour la persistance des tâches uniques (non récurrentes). Gère le stockage, la lecture, la suppression et l'archivage des tâches au format JSONL dans l'arborescence `pending/` et `completed/`.

## Hiérarchie

```
TaskRepositoryInterface
    └── TaskRepository (implements)
```

## Rôle principal

Fournir un accès unifié aux tâches uniques stockées dans des fichiers JSONL, avec prise en charge de l'ordre de tri (`TaskOrder`), des limites de requête, et de l'archivage vers le répertoire `completed/` avec structure par date.

## Interface : TaskRepositoryInterface

### Description

Définit le contrat pour la persistance des tâches uniques.

### Méthodes

#### `save(TaskRecord $task): void`

Sauvegarde une tâche unique dans le répertoire `pending/`.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `TaskRecord` | Tâche à sauvegarder (avec Value Objects) |

**Comportement :**
- Crée le dossier `pending/` s'il n'existe pas
- Supprime l'ancien fichier si une tâche avec le même ID existe déjà (écrasement)
- Écrit la tâche au format JSONL (une ligne)

#### `find(TaskIdVO $id): ?TaskRecord`

Recherche une tâche unique par son identifiant.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$id` | `TaskIdVO` | Identifiant UUID de la tâche |

**Retourne :** `TaskRecord|null` - La tâche si elle existe et est en statut `PENDING`, `null` sinon

#### `findAll(?int $limit = null, TaskOrder $order = TaskOrder::OLDEST): TaskRecordCollection`

Retourne une collection de tâches uniques en attente.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `int|null` | Nombre maximum de tâches à retourner (0 = aucun résultat) |
| `$order` | `TaskOrder` | Ordre de tri : `OLDEST` (FIFO) ou `NEWEST` (LIFO) |

**Retourne :** `TaskRecordCollection` - Collection typée de `TaskRecord`

**Comportement :**
- Ne retourne que les tâches avec `status === TaskStatus::PENDING`
- Trie les fichiers par date de modification selon `$order`
- Applique la limite si spécifiée

#### `delete(TaskIdVO $id): void`

Supprime une tâche unique du répertoire `pending/`.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$id` | `TaskIdVO` | Identifiant UUID de la tâche à supprimer |

#### `moveToCompleted(TaskRecord $task, bool $success = true): void`

Archive une tâche dans le répertoire `completed/` après exécution.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `TaskRecord` | Tâche à archiver |
| `$success` | `bool` | `true` pour un succès, `false` pour un échec |

**Comportement :**
- Crée le dossier `completed/{Y-m-d}/` si nécessaire
- Met à jour le statut (`SUCCESS` ou `FAILED`)
- Ajoute un timestamp `completed_at`
- Supprime la tâche du répertoire `pending/`

## Implémentation : TaskRepository

### Dépendances

```php
public function __construct(
    private readonly TaskStorageContext $context,   // Gestion des chemins
    private readonly JsonlService $jsonl,           // Lecture/écriture JSONL
    private readonly HydrationService $hydration,   // Hydratation des objets
    private readonly FileSystemInterface $fs,       // Opérations fichiers
) {}
```

### Arborescence des fichiers

```
storage/tasks/
├── pending/                          # Tâches en attente
│   └── {task_id}.jsonl               # Une tâche par fichier
│
└── completed/                        # Tâches archivées
    └── Y-m-d/                        # Partition par date
        └── {task_id}.jsonl           # Une tâche par fichier
```

## Méthodes internes

### `save(TaskRecord $task): void`

```php
public function save(TaskRecord $task): void
{
    $pendingDir = $this->context->getPendingDir();
    $pendingDir->ensureExists($this->fs);
    
    // Écrasement sécurisé (delete + write)
    $path = $pendingDir->filePath($task->id);
    if ($this->fs->exists($path)) {
        $this->fs->delete($path);
    }
    
    $this->jsonl->write($task);
}
```

### `find(TaskIdVO $id): ?TaskRecord`

```php
public function find(TaskIdVO $id): ?TaskRecord
{
    $path = $this->context->getPendingDir()->filePath($id);
    
    if (!$this->fs->exists($path)) {
        return null;
    }
    
    $lines = $this->jsonl->readAll($path);
    
    if (empty($lines)) {
        return null;
    }
    
    $task = $this->hydration->hydrate(TaskRecord::class, $lines[0]);
    
    // Seules les tâches PENDING sont accessibles
    return $task->status === TaskStatus::PENDING ? $task : null;
}
```

### `findAll(?int $limit = null, TaskOrder $order = TaskOrder::OLDEST): TaskRecordCollection`

```php
public function findAll(?int $limit = null, TaskOrder $order = TaskOrder::OLDEST): TaskRecordCollection
{
    if ($limit === 0) {
        return new TaskRecordCollection();
    }
    
    $files = $pendingDir->allFiles($this->fs);
    
    // Tri par date de modification selon l'ordre
    usort($files, function ($a, $b) use ($order) {
        $timeA = $this->fs->lastModified($a);
        $timeB = $this->fs->lastModified($b);
        return $order->compare($timeA, $timeB);
    });
    
    // Application de la limite
    if ($limit !== null && $limit > 0) {
        $files = array_slice($files, 0, $limit);
    }
    
    // Hydratation et filtrage par statut PENDING
    foreach ($files as $file) {
        $lines = $this->jsonl->readAll($file);
        foreach ($lines as $line) {
            $task = $this->hydration->hydrate(TaskRecord::class, $line);
            if ($task->status === TaskStatus::PENDING) {
                $tasks->add($task);
            }
        }
    }
    
    return $tasks;
}
```

### `moveToCompleted(TaskRecord $task, bool $success = true): void`

```php
public function moveToCompleted(TaskRecord $task, bool $success = true): void
{
    $source = $this->context->getPendingDir()->filePath($task->id);
    
    if (!$this->fs->exists($source)) {
        return;
    }
    
    $lines = $this->jsonl->readAll($source);
    
    if (empty($lines)) {
        return;
    }
    
    $taskData = $lines[0];
    $taskData['status'] = $success ? TaskStatus::SUCCESS->value : TaskStatus::FAILED->value;
    $taskData['completed_at'] = (new Iso8601DateTimeVO())->value;
    
    $date = new TaskDateVO(date('Y-m-d'));
    $target = $this->context->getCompletedDir()->filePathWithDate($task->id, $date);
    
    $this->fs->put($target, json_encode($taskData) . "\n");
    $this->fs->delete($source);
}
```

## Cas d'utilisation

### Cas 1 : Sauvegarde d'une nouvelle tâche

```php
<?php

use AndyDefer\Task\Repositories\TaskRepository;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\Enums\TaskStatus;

$task = new TaskRecord(
    id: new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'),
    signature: new TaskSignatureVO('backup'),
    class: BackupTask::class,
    payload: $payload,
    status: TaskStatus::PENDING,
);

$repository->save($task);
// Fichier créé : storage/tasks/pending/550e8400-e29b-41d4-a716-446655440000.jsonl
```

### Cas 2 : Récupération des tâches en attente (FIFO)

```php
<?php

// Récupère les 10 tâches les plus anciennes
$tasks = $repository->findAll(10, TaskOrder::OLDEST);

foreach ($tasks as $task) {
    echo $task->id->value . "\n";
}
```

### Cas 3 : Récupération des tâches en attente (LIFO)

```php
<?php

// Récupère les 10 tâches les plus récentes
$tasks = $repository->findAll(10, TaskOrder::NEWEST);
```

### Cas 4 : Archivage d'une tâche réussie

```php
<?php

$task = $repository->find(new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'));
$repository->moveToCompleted($task, true);
// Fichier déplacé vers : storage/tasks/completed/2026-06-14/550e8400-e29b-41d4-a716-446655440000.jsonl
// Statut : SUCCESS
```

## Flux d'exécution

```
save()
    │
    ├── ensureExists(pendingDir)
    │
    ├── delete(existing file if any)
    │
    └── jsonl->write()

find()
    │
    ├── filePath(id)
    ├── fs->exists()
    ├── jsonl->readAll()
    ├── hydration->hydrate(TaskRecord::class)
    └── filtrer par status === PENDING

findAll()
    │
    ├── allFiles()
    ├── usort() avec TaskOrder::compare()
    ├── array_slice() pour limit
    ├── Pour chaque fichier
    │   ├── jsonl->readAll()
    │   └── hydration->hydrate()
    └── Filtrer status === PENDING

moveToCompleted()
    │
    ├── readAll(source)
    ├── Modifier status (SUCCESS/FAILED)
    ├── Ajouter completed_at
    ├── ensureExists(completedDir)
    ├── target = filePathWithDate()
    ├── fs->put(target)
    └── fs->delete(source)
```

## Gestion des erreurs

| Situation | Comportement |
|-----------|--------------|
| Fichier non trouvé dans `find()` | Retourne `null` |
| Dossier `pending/` inexistant dans `findAll()` | Retourne collection vide |
| Fichier source inexistant dans `moveToCompleted()` | Retour silencieux (pas d'erreur) |
| Fichier source vide dans `moveToCompleted()` | Retour silencieux |
| Fichier avec statut non `PENDING` dans `find()` | Retourne `null` |

## Intégration

### Dépendances

```
TaskRepository
    ├── TaskStorageContext (chemins)
    ├── JsonlService (lecture/écriture JSONL)
    ├── HydrationService (hydratation)
    └── FileSystemInterface (opérations fichiers)
```

### Avec TaskBatchService

```php
class TaskBatchService
{
    public function processUniqueOnly(?int $limit = null): BatchResultRecord
    {
        $order = $this->config->isOldestOrder() ? TaskOrder::OLDEST : TaskOrder::NEWEST;
        
        foreach ($this->taskRepository->findAll($limit, $order) as $task) {
            // Exécuter la tâche
        }
    }
}
```

## Performance

| Opération | Complexité | Notes |
|-----------|------------|-------|
| `save()` | O(1) | Écriture d'un fichier JSONL |
| `find()` | O(1) | Lecture d'un fichier |
| `findAll()` | O(n log n) | Tri + lecture de n fichiers |
| `delete()` | O(1) | Suppression d'un fichier |
| `moveToCompleted()` | O(1) | Lecture + écriture + suppression |

## Compatibilité

| Version PHP | Support |
|-------------|---------|
| PHP 8.2+ | ✅ Requis (readonly properties) |
| PHP 8.1 | ✅ Complet |
| PHP 8.0 | ❌ |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Repositories\TaskRepository;
use AndyDefer\Task\Records\TaskRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Enums\TaskStatus;
use AndyDefer\Task\Enums\TaskOrder;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use AndyDefer\DomainStructures\Utils\StrictDataObject;

// 1. Initialiser le repository (via le container Laravel)
$repository = app(TaskRepositoryInterface::class);

// 2. Créer une nouvelle tâche
$payload = new TaskPayloadRecord(
    type: 'backup',
    data: new StrictDataObject(['database' => 'mysql']),
);

$task = new TaskRecord(
    id: new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'),
    signature: new TaskSignatureVO('backup-database'),
    class: BackupTask::class,
    payload: $payload,
    status: TaskStatus::PENDING,
    created_at: new Iso8601DateTimeVO(),
    start_at: new Iso8601DateTimeVO(),
    end_at: new Iso8601DateTimeVO(date('c', strtotime('+1 hour'))),
    delay_seconds: new CounterVO(0),
    attempts: new CounterVO(0),
    max_attempts: new CounterVO(3),
);

// 3. Sauvegarder
$repository->save($task);
echo "Tâche sauvegardée\n";

// 4. Récupérer par ID
$found = $repository->find(new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'));
if ($found) {
    echo "Tâche trouvée : {$found->signature->value}\n";
}

// 5. Lister les tâches en attente (FIFO, sans limite)
$allPending = $repository->findAll();
echo "Nombre de tâches en attente : {$allPending->count()}\n";

// 6. Lister les 5 tâches les plus récentes
$recent = $repository->findAll(5, TaskOrder::NEWEST);
foreach ($recent as $t) {
    echo "- {$t->id->value} ({$t->signature->value})\n";
}

// 7. Archiver une tâche réussie
$repository->moveToCompleted($task, true);
echo "Tâche archivée\n";

// 8. Vérifier qu'elle n'est plus dans pending
$notFound = $repository->find(new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'));
if ($notFound === null) {
    echo "Tâche déplacée vers completed/\n";
}
```

## Voir aussi

- `TaskRepositoryInterface` - Interface implémentée
- `RecurringTaskRepository` - Repository pour les tâches récurrentes
- `TaskStorageContext` - Contexte de stockage (chemins)
- `JsonlService` - Service de lecture/écriture JSONL
- `HydrationService` - Service d'hydratation
- `TaskRecord` - Record de tâche
- `TaskOrder` - Enum pour l'ordre de tri (OLDEST/NEWEST)
- `TaskDateVO` - Value Object pour la date d'archivage
- `TaskDirectoryVO` - Value Object pour les chemins de dossiers
---