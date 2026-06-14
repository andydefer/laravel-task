# RecurringTaskRepository - Référence Technique

## Description

Repository pour la persistance des tâches récurrentes. Gère le stockage, la lecture, la suppression et la mise à jour des tâches récurrentes au format JSONL avec versioning (append). Chaque fichier contient l'historique complet des versions successives de la tâche récurrente.

## Hiérarchie

```
RecurringTaskRepositoryInterface
    └── RecurringTaskRepository (implements)
```

## Rôle principal

Fournir un accès unifié aux tâches récurrentes stockées dans des fichiers JSONL, avec prise en charge des requêtes, du tri (`TaskOrder`), de la versioning (append à la fin du fichier) et de la mise à jour atomique après chaque exécution.

## Interface : RecurringTaskRepositoryInterface

### Description

Définit le contrat pour la persistance des tâches récurrentes.

### Méthodes

#### `save(RecurringTaskRecord $task): void`

Sauvegarde une tâche récurrente dans le répertoire `recurring/`.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `RecurringTaskRecord` | Tâche récurrente à sauvegarder (avec Value Objects) |

**Comportement :**
- Crée le dossier `recurring/` s'il n'existe pas
- Écrit la tâche au format JSONL (append à la fin du fichier)
- Conserve l'historique des versions précédentes

#### `find(TaskSignatureVO $signature): ?RecurringTaskRecord`

Recherche la dernière version d'une tâche récurrente par sa signature.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$signature` | `TaskSignatureVO` | Signature unique de la tâche récurrente |

**Retourne :** `RecurringTaskRecord|null` - La dernière version de la tâche, `null` si inexistante

#### `findAll(?int $limit = null, ?TaskOrder $order = TaskOrder::OLDEST): RecurringTaskRecordCollection`

Retourne une collection des dernières versions de toutes les tâches récurrentes.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `int|null` | Nombre maximum de tâches à retourner (0 = aucun résultat) |
| `$order` | `TaskOrder` | Ordre de tri : `OLDEST` (FIFO) ou `NEWEST` (LIFO) |

**Retourne :** `RecurringTaskRecordCollection` - Collection typée de `RecurringTaskRecord`

#### `delete(TaskSignatureVO $signature): void`

Supprime une tâche récurrente (l'intégralité du fichier).

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$signature` | `TaskSignatureVO` | Signature de la tâche à supprimer |

#### `updateAfterRun(RecurringTaskRecord $task, bool $success, ?string $error = null): void`

Met à jour une tâche récurrente après son exécution.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$task` | `RecurringTaskRecord` | Version actuelle de la tâche |
| `$success` | `bool` | `true` si l'exécution a réussi |
| `$error` | `string|null` | Message d'erreur si échec |

**Comportement :**
- Met à jour `last_run_at` avec la date/heure actuelle
- Calcule `next_run_at` = maintenant + `delay_seconds`
- Incrémente `success_count` ou `failure_count` via `CounterVO`
- Sauvegarde une nouvelle version (append)

## Implémentation : RecurringTaskRepository

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
└── recurring/                        # Tâches récurrentes
    └── {signature}.jsonl             # Versioning : append à chaque exécution
```

### Versioning (Append)

Chaque exécution d'une tâche récurrente ajoute une nouvelle ligne JSONL à la fin du fichier :

```jsonl
{"signature":"cleanup","class":"CleanupTask","success_count":0,"failure_count":0,...}
{"signature":"cleanup","class":"CleanupTask","last_run_at":"2026-06-14T10:00:00+00:00","next_run_at":"2026-06-14T11:00:00+00:00","success_count":1,"failure_count":0,...}
{"signature":"cleanup","class":"CleanupTask","last_run_at":"2026-06-14T11:00:00+00:00","next_run_at":"2026-06-14T12:00:00+00:00","success_count":2,"failure_count":0,...}
```

La méthode `find()` retourne **toujours la dernière ligne** (la version la plus récente).

## Méthodes internes

### `save(RecurringTaskRecord $task): void`

```php
public function save(RecurringTaskRecord $task): void
{
    $recurringDir = $this->context->getRecurringDir();
    $recurringDir->ensureExists($this->fs);
    $this->jsonl->write($task);  // Append à la fin du fichier
}
```

### `find(TaskSignatureVO $signature): ?RecurringTaskRecord`

```php
public function find(TaskSignatureVO $signature): ?RecurringTaskRecord
{
    $path = $this->context->getRecurringDir()->filePath($signature);
    
    if (!$this->fs->exists($path)) {
        return null;
    }
    
    $lines = $this->jsonl->readAll($path);
    
    if (empty($lines)) {
        return null;
    }
    
    // Prendre la dernière ligne (la plus récente)
    $lastLine = end($lines);
    
    return $this->hydration->hydrate(RecurringTaskRecord::class, $lastLine);
}
```

### `updateAfterRun(RecurringTaskRecord $task, bool $success, ?string $error = null): void`

```php
public function updateAfterRun(RecurringTaskRecord $task, bool $success, ?string $error = null): void
{
    $now = new Iso8601DateTimeVO();
    $next_run_at = new Iso8601DateTimeVO(
        date('c', strtotime($now->value) + $task->delay_seconds->value)
    );
    
    $new_success_count = $success 
        ? $task->success_count->increment()   // CounterVO::increment()
        : $task->success_count;
    
    $new_failure_count = $success 
        ? $task->failure_count 
        : $task->failure_count->increment();   // CounterVO::increment()
    
    $updated = new RecurringTaskRecord(
        signature: $task->signature,
        class: $task->class,
        payload: $task->payload,
        start_at: $task->start_at,
        end_at: $task->end_at,
        delay_seconds: $task->delay_seconds,
        last_run_at: $now,
        next_run_at: $next_run_at,
        success_count: $new_success_count,
        failure_count: $new_failure_count,
        last_error: $error,
    );
    
    $this->save($updated);  // Append la nouvelle version
}
```

## Cas d'utilisation

### Cas 1 : Enregistrement d'une nouvelle tâche récurrente

```php
<?php

use AndyDefer\Task\Repositories\RecurringTaskRepository;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

$task = new RecurringTaskRecord(
    signature: new TaskSignatureVO('cleanup-logs'),
    class: CleanupLogsTask::class,
    payload: $payload,
    start_at: new Iso8601DateTimeVO(),
    end_at: null,
    delay_seconds: new CounterVO(3600),  // Toutes les heures
    last_run_at: null,
    next_run_at: new Iso8601DateTimeVO(),  // Première exécution immédiate
    success_count: new CounterVO(0),
    failure_count: new CounterVO(0),
);

$repository->save($task);
// Fichier créé : storage/tasks/recurring/cleanup-logs.jsonl
```

### Cas 2 : Récupération de la dernière version d'une tâche

```php
<?php

$task = $repository->find(new TaskSignatureVO('cleanup-logs'));

if ($task) {
    echo "Signature: {$task->signature->value}\n";
    echo "Dernière exécution: {$task->last_run_at?->value}\n";
    echo "Prochaine exécution: {$task->next_run_at->value}\n";
    echo "Succès: {$task->success_count->value}, Échecs: {$task->failure_count->value}\n";
}
```

### Cas 3 : Mise à jour après exécution réussie

```php
<?php

// Après exécution réussie
$repository->updateAfterRun($task, true, null);

// Une nouvelle ligne est ajoutée au fichier avec :
// - last_run_at = maintenant
// - next_run_at = maintenant + 3600
// - success_count = ancien + 1
```

### Cas 4 : Mise à jour après exécution échouée

```php
<?php

// Après exécution échouée
$repository->updateAfterRun($task, false, 'Connection timeout');

// Une nouvelle ligne est ajoutée avec :
// - last_run_at = maintenant
// - next_run_at = maintenant + 3600
// - failure_count = ancien + 1
// - last_error = 'Connection timeout'
```

### Cas 5 : Suppression d'une tâche récurrente

```php
<?php

$repository->delete(new TaskSignatureVO('cleanup-logs'));
// Fichier storage/tasks/recurring/cleanup-logs.jsonl supprimé
```

## Flux d'exécution

```
save()
    │
    ├── ensureExists(recurringDir)
    │
    └── jsonl->write() (append)

find()
    │
    ├── filePath(signature)
    ├── fs->exists()
    ├── jsonl->readAll()
    ├── end() → dernière ligne
    └── hydration->hydrate()

findAll()
    │
    ├── allFiles()
    ├── usort() avec TaskOrder::compare()
    ├── array_slice() pour limit
    ├── Pour chaque fichier
    │   ├── jsonl->readAll()
    │   └── end() → dernière ligne
    └── hydration->hydrate() pour chaque

updateAfterRun()
    │
    ├── now = new Iso8601DateTimeVO()
    ├── next_run_at = now + delay_seconds
    ├── success_count = success ? increment() : current
    ├── failure_count = success ? current : increment()
    ├── Créer nouveau RecurringTaskRecord
    └── save() (append nouvelle version)
```

## Gestion des erreurs

| Situation | Comportement |
|-----------|--------------|
| Fichier non trouvé dans `find()` | Retourne `null` |
| Dossier `recurring/` inexistant dans `findAll()` | Retourne collection vide |
| Fichier vide dans `find()` | Retourne `null` |
| Suppression d'une tâche inexistante | Rien ne se passe (pas d'erreur) |

## Versioning : Notes importantes

| Concept | Explication |
|---------|-------------|
| **Append uniquement** | On n'écrase jamais, on ajoute à la fin |
| **Dernière ligne = état actuel** | `find()` retourne toujours la ligne la plus récente |
| **Historique conservé** | Permet l'audit et le débogage |
| **Pas de limite de versions** | Le fichier peut grossir indéfiniment |

## Intégration

### Dépendances

```
RecurringTaskRepository
    ├── TaskStorageContext (chemins)
    ├── JsonlService (lecture/écriture JSONL avec append)
    ├── HydrationService (hydratation)
    └── FileSystemInterface (opérations fichiers)
```

### Avec TaskBatchService

```php
class TaskBatchService
{
    public function processRecurringOnly(?int $limit = null): BatchResultRecord
    {
        $order = $this->config->isOldestOrder() ? TaskOrder::OLDEST : TaskOrder::NEWEST;
        
        foreach ($this->recurringTaskRepository->findAll($limit, $order) as $task) {
            // Exécuter la tâche
            $success = $this->runner->runRecurringTask($task);
            
            // Mettre à jour après exécution
            $this->recurringTaskRepository->updateAfterRun($task, $success, $error);
        }
    }
}
```

### Avec TaskRunnerService

```php
class TaskRunnerService
{
    private function markRecurringSuccess(RecurringTaskRecord $task): void
    {
        $this->recurringTaskRepository->updateAfterRun($task, true, null);
    }
    
    private function markRecurringFailed(RecurringTaskRecord $task, ErrorType $error_type, ?string $details = null): void
    {
        $error_message = $details ?? $error_type->getMessage();
        $this->recurringTaskRepository->updateAfterRun($task, false, $error_message);
    }
}
```

## Performance

| Opération | Complexité | Notes |
|-----------|------------|-------|
| `save()` | O(1) | Append à la fin du fichier |
| `find()` | O(1) | Lecture du fichier + dernière ligne |
| `findAll()` | O(n log n) | Tri + lecture de n fichiers |
| `delete()` | O(1) | Suppression d'un fichier |
| `updateAfterRun()` | O(1) | Lecture + création + append |

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

use AndyDefer\Task\Repositories\RecurringTaskRepository;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\Enums\TaskOrder;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;
use AndyDefer\DomainStructures\Utils\StrictDataObject;

// 1. Initialiser le repository (via le container Laravel)
$repository = app(RecurringTaskRepositoryInterface::class);

// 2. Créer une nouvelle tâche récurrente
$payload = new TaskPayloadRecord(
    type: 'cleanup',
    data: new StrictDataObject(['days' => 30]),
);

$task = new RecurringTaskRecord(
    signature: new TaskSignatureVO('cleanup-logs'),
    class: CleanupLogsTask::class,
    payload: $payload,
    start_at: new Iso8601DateTimeVO(),
    end_at: null,  // Jamais
    delay_seconds: new CounterVO(3600),  // Toutes les heures
    last_run_at: null,
    next_run_at: new Iso8601DateTimeVO(),
    success_count: new CounterVO(0),
    failure_count: new CounterVO(0),
);

// 3. Sauvegarder
$repository->save($task);
echo "Tâche récurrente enregistrée\n";

// 4. Simuler plusieurs exécutions
for ($i = 1; $i <= 5; $i++) {
    // Simuler une exécution
    $success = $i % 2 === 0 ? true : false;
    $error = $success ? null : "Erreur à l'exécution #{$i}";
    
    // Récupérer la dernière version
    $current = $repository->find(new TaskSignatureVO('cleanup-logs'));
    
    // Mettre à jour
    $repository->updateAfterRun($current, $success, $error);
    
    echo "Exécution #{$i} : " . ($success ? "SUCCÈS" : "ÉCHEC") . "\n";
}

// 5. Afficher les statistiques finales
$final = $repository->find(new TaskSignatureVO('cleanup-logs'));
echo "\nStatistiques finales:\n";
echo "  Succès: {$final->success_count->value}\n";
echo "  Échecs: {$final->failure_count->value}\n";
echo "  Dernière erreur: {$final->last_error}\n";
echo "  Dernière exécution: {$final->last_run_at->value}\n";
echo "  Prochaine exécution: {$final->next_run_at->value}\n";

// 6. Lister toutes les tâches récurrentes
$all = $repository->findAll();
echo "\nTâches récurrentes: {$all->count()}\n";

// 7. Supprimer la tâche
$repository->delete(new TaskSignatureVO('cleanup-logs'));
echo "Tâche supprimée\n";
```

## Structure du fichier JSONL (exemple)

```json
{"signature":"cleanup-logs","class":"CleanupLogsTask","start_at":"2026-06-14T10:00:00+00:00","end_at":null,"delay_seconds":3600,"last_run_at":null,"next_run_at":"2026-06-14T10:00:00+00:00","success_count":0,"failure_count":0}
{"signature":"cleanup-logs","class":"CleanupLogsTask","start_at":"2026-06-14T10:00:00+00:00","end_at":null,"delay_seconds":3600,"last_run_at":"2026-06-14T10:05:00+00:00","next_run_at":"2026-06-14T11:05:00+00:00","success_count":1,"failure_count":0}
{"signature":"cleanup-logs","class":"CleanupLogsTask","start_at":"2026-06-14T10:00:00+00:00","end_at":null,"delay_seconds":3600,"last_run_at":"2026-06-14T11:05:00+00:00","next_run_at":"2026-06-14T12:05:00+00:00","success_count":2,"failure_count":0,"last_error":null}
{"signature":"cleanup-logs","class":"CleanupLogsTask","start_at":"2026-06-14T10:00:00+00:00","end_at":null,"delay_seconds":3600,"last_run_at":"2026-06-14T12:05:00+00:00","next_run_at":"2026-06-14T13:05:00+00:00","success_count":2,"failure_count":1,"last_error":"Connection timeout"}
```

## Voir aussi

- `RecurringTaskRepositoryInterface` - Interface implémentée
- `TaskRepository` - Repository pour les tâches uniques
- `TaskStorageContext` - Contexte de stockage (chemins)
- `JsonlService` - Service de lecture/écriture JSONL (append)
- `HydrationService` - Service d'hydratation
- `RecurringTaskRecord` - Record de tâche récurrente
- `TaskOrder` - Enum pour l'ordre de tri (OLDEST/NEWEST)
- `TaskSignatureVO` - Value Object pour la signature
- `CounterVO` - Value Object pour les compteurs (incrémentation)
- `Iso8601DateTimeVO` - Value Object pour les dates/heures
---