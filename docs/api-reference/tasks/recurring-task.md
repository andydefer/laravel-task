# AbstractRecurringTask - Référence Technique

## Description

Classe abstraite de base pour les tâches qui s'exécutent de manière répétée selon un intervalle planifié. Elle fournit un workflow d'exécution standardisé avec logging, gestion des erreurs et hooks de cycle de vie.

## Hiérarchie / Implémentations

```
TaskInterface
    └── AbstractRecurringTask
            └── [Vos tâches récurrentes]
```

## Rôle principal

Fournir une structure standardisée pour l'exécution des tâches récurrentes en :
- Gérant le cycle de vie complet (before → process → after)
- Assurant un logging cohérent (début, succès, échec)
- Gérant les exceptions et la propagation
- Fournissant des hooks pour la logique métier personnalisée
- Intégrant le contexte récurrent (intervalle, prochaine exécution)

## API / Méthodes publiques

### `execute(StrictDataObject $payload): void`

Point d'entrée principal de la tâche. Orchestre l'exécution complète.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$payload` | `StrictDataObject` | Données d'entrée de la tâche |

**Exceptions :** `Throwable` - Toute exception levée par `process()` est propagée

**Exemple :**
```php
class MyRecurringTask extends AbstractRecurringTask
{
    protected function process(): void
    {
        // Logique métier
    }
}

$task = new MyRecurringTask($context, $logger, $hydration);
$task->execute(StrictDataObject::from(['batch_size' => 100]));
```

---

### `info(DescriptionVO $message): void`

Journalise un message d'information de la tâche.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$message` | `DescriptionVO` | Message à journaliser |

**Exemple :**
```php
$task->info(new DescriptionVO('Processing batch #42'));
```

---

### `error(DescriptionVO $message): void`

Journalise un message d'erreur de la tâche.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$message` | `DescriptionVO` | Message d'erreur à journaliser |

**Exemple :**
```php
$task->error(new DescriptionVO('Database connection lost'));
```

## Méthodes protégées à implémenter

### `process(): void`

Contient la logique métier principale de la tâche.

**Exceptions :** `Throwable` - Doit être levée en cas d'échec

**Exemple :**
```php
protected function process(): void
{
    $data = $this->context->getPayload();
    $batchSize = $data->batch_size ?? 50;
    
    $this->processBatch($batchSize);
}
```

## Méthodes protégées optionnelles

### `before(StrictDataObject $payload): void`

Hook exécuté avant `process()`. Utile pour la validation ou la préparation.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$payload` | `StrictDataObject` | Données d'entrée de la tâche |

**Exemple :**
```php
protected function before(StrictDataObject $payload): void
{
    if (!$this->isMaintenanceMode()) {
        throw new RuntimeException('Maintenance mode required');
    }
}
```

---

### `after(bool $success, ?DescriptionVO $error = null): void`

Hook exécuté après `process()`. Utile pour le nettoyage ou les notifications.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$success` | `bool` | Indique si l'exécution a réussi |
| `$error` | `DescriptionVO|null` | Message d'erreur en cas d'échec |

**Exemple :**
```php
protected function after(bool $success, ?DescriptionVO $error = null): void
{
    $this->cleanupTemporaryFiles();
    
    if (!$success) {
        $this->alertAdmin('Recurring task failed', $error);
    }
}
```

## Flux d'exécution

```
execute(payload)
    │
    ├── 1. setPayload(payload)
    │
    ├── 2. logTaskStarted()
    │   └── Log: recurring_task → task_started
    │
    ├── 3. before(payload) ← Surchargeable
    │
    ├── 4. process() ← À implémenter
    │   ├── Succès → continue
    │   └── Échec → Throwable capturé
    │
    ├── 5. after(success) ← Surchargeable
    │
    ├── 6. Log
    │   ├── Succès → logTaskCompleted()
    │   └── Échec → logTaskFailed() + throw
    │
    └── 7. Fin
```

## Journalisation

### Types de logs

| Type | Événements |
|------|------------|
| `recurring_task` | `task_started`, `task_completed`, `task_failed` |
| `recurring_task_output` | `info`, `error` |

### Structure des logs

```json
{
    "type": "recurring_task",
    "payload": {
        "event": "task_started",
        "alias": "recurring@...",
        "interval_seconds": 3600,
        "next_run_at": "2026-01-01T12:00:00+00:00"
    }
}
```

## Cas d'utilisation

### Cas 1 : Nettoyage périodique

**Problème :** Nettoyer les logs obsolètes toutes les heures.

```php
class CleanupLogsTask extends AbstractRecurringTask
{
    protected function process(): void
    {
        $payload = $this->context->getPayload();
        $days = $payload->days ?? 30;
        
        $this->info(new DescriptionVO("Cleaning logs older than {$days} days"));
        
        $deleted = DB::table('logs')
            ->where('created_at', '<', Carbon::now()->subDays($days))
            ->delete();
        
        $this->info(new DescriptionVO("Deleted {$deleted} log entries"));
    }
}

// Enregistrement avec intervalle de 1 heure
$config = RecurringTaskConfigRecord::from([
    'interval_seconds' => 3600,
    'start_at' => Carbon::now()->toIso8601String(),
]);
```

---

### Cas 2 : Synchronisation de données

**Problème :** Synchroniser les données avec une API externe toutes les 5 minutes.

```php
class SyncDataTask extends AbstractRecurringTask
{
    protected function before(StrictDataObject $payload): void
    {
        if (empty($payload->api_url)) {
            throw new InvalidArgumentException('API URL is required');
        }
    }
    
    protected function process(): void
    {
        $payload = $this->context->getPayload();
        $url = $payload->api_url;
        
        $this->info(new DescriptionVO("Syncing from {$url}"));
        
        $data = $this->fetchFromApi($url);
        $this->saveData($data);
        
        $this->info(new DescriptionVO("Synced " . count($data) . " records"));
    }
}
```

---

### Cas 3 : Tâche avec état

**Problème :** Traiter des fichiers en attente avec suivi de progression.

```php
class ProcessFilesTask extends AbstractRecurringTask
{
    private int $processed = 0;
    
    protected function before(StrictDataObject $payload): void
    {
        $this->processed = 0;
    }
    
    protected function process(): void
    {
        $payload = $this->context->getPayload();
        $limit = $payload->limit ?? 100;
        
        $files = File::where('status', 'pending')->limit($limit)->get();
        
        foreach ($files as $file) {
            $this->processFile($file);
            $this->processed++;
        }
        
        $this->info(new DescriptionVO("Processed {$this->processed} files"));
    }
    
    protected function after(bool $success, ?DescriptionVO $error = null): void
    {
        if ($success && $this->processed > 0) {
            $this->logger->info("Files processed", ['count' => $this->processed]);
        }
    }
}
```

---

### Cas 4 : Tâche avec gestion d'erreur et retry

**Problème :** Envoyer des notifications avec retry en cas d'échec.

```php
class SendNotificationsTask extends AbstractRecurringTask
{
    protected function process(): void
    {
        $payload = $this->context->getPayload();
        $limit = $payload->limit ?? 50;
        
        $notifications = Notification::where('status', 'pending')
            ->limit($limit)
            ->get();
        
        $failed = 0;
        
        foreach ($notifications as $notification) {
            try {
                $this->sendNotification($notification);
                $notification->markAsSent();
            } catch (Throwable $e) {
                $failed++;
                $this->error(new DescriptionVO("Failed: {$e->getMessage()}"));
            }
        }
        
        if ($failed > 0) {
            throw new RuntimeException("Failed to send {$failed} notifications");
        }
        
        $this->info(new DescriptionVO("Sent " . $notifications->count() . " notifications"));
    }
}
```

## Gestion des erreurs

| Situation | Action |
|-----------|--------|
| Exception dans `process()` | `after(false, error)` + `logTaskFailed()` + propagation |
| Exception dans `before()` | Propagation immédiate (sans logging de fin) |
| Exception dans `after()` | Non gérée (s'ajoute à l'exception originale) |

### Messages de log d'erreur

```json
{
    "type": "recurring_task",
    "payload": {
        "event": "task_failed",
        "alias": "recurring@...",
        "status": "failed",
        "error": "Connection timeout"
    }
}
```

## Intégration

### Dépendances injectées

| Dépendance | Rôle |
|------------|------|
| `RecurringTaskContext` | Contexte d'exécution (alias, intervalle, dates) |
| `LoggerInterface` | Journalisation des événements |
| `HydrationService` | Hydratation des objets pour les logs |

### Accès au contexte

```php
$this->context->getAlias();           // TaskAliasVO
$this->context->getIntervalSeconds(); // DurationVO
$this->context->getStartAt();         // Iso8601DateTimeVO
$this->context->getEndAt();           // Iso8601DateTimeVO
$this->context->getLastRunAt();       // Iso8601DateTimeVO
$this->context->getNextRunAt();       // Iso8601DateTimeVO
$this->context->getPayload();         // StrictDataObject
```

## Différences avec AbstractUniqueTask

| Aspect | AbstractRecurringTask | AbstractUniqueTask |
|--------|----------------------|-------------------|
| Context | `RecurringTaskContext` | `UniqueTaskContext` |
| Planification | Intervalle répété | Date unique |
| Logs | `recurring_task` | `unique_task` |
| État | PLAYING/PAUSED/FINISHED | PENDING/COMPLETED/FAILED |

## Performance

- **Logging** : Écriture synchrone (configurable via logger)
- **Mémoire** : Context et payload sont conservés pendant l'exécution
- **Recommandation** : Éviter les opérations longues (> 5 min) dans `process()`

## Compatibilité

| Version PHP | Support |
|-------------|---------|
| PHP 8.2+ | ✅ Complet |
| PHP 8.1 | ✅ Complet |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Abstract\AbstractRecurringTask;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use RuntimeException;

final class BackupTask extends AbstractRecurringTask
{
    private string $backupPath;
    
    protected function before(StrictDataObject $payload): void
    {
        $this->backupPath = $payload->path ?? '/var/backups';
        
        if (!is_writable($this->backupPath)) {
            throw new RuntimeException("Backup path is not writable: {$this->backupPath}");
        }
    }
    
    protected function process(): void
    {
        $this->info(new DescriptionVO("Starting backup to {$this->backupPath}"));
        
        $database = config('database.connections.mysql.database');
        $filename = "{$this->backupPath}/{$database}_" . date('Y-m-d_H-i-s') . '.sql';
        
        $command = "mysqldump -u root -p" . env('DB_PASSWORD') . " {$database} > {$filename}";
        exec($command, $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new RuntimeException("Backup failed with code {$returnCode}");
        }
        
        $this->info(new DescriptionVO("Backup completed: {$filename}"));
        
        // Nettoyer les anciens backups
        $this->cleanupOldBackups();
    }
    
    private function cleanupOldBackups(): void
    {
        $files = glob("{$this->backupPath}/*.sql");
        $keep = $this->context->getPayload()->keep ?? 7;
        
        usort($files, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });
        
        $toDelete = array_slice($files, 0, -$keep);
        
        foreach ($toDelete as $file) {
            unlink($file);
            $this->info(new DescriptionVO("Deleted old backup: " . basename($file)));
        }
    }
}

// Utilisation
$context = new RecurringTaskContext();
$context->setAlias(new TaskAliasVO('recurring@...'));
$context->setIntervalSeconds(new DurationVO(86400)); // Une fois par jour

$task = new BackupTask($context, $logger, $hydration);
$task->execute(StrictDataObject::from([
    'path' => '/var/backups',
    'keep' => 30,
]));
```

## Voir aussi

- `AbstractUniqueTask` - Classe de base pour les tâches uniques
- `TaskInterface` - Interface commune à toutes les tâches
- `RecurringTaskContext` - Contexte des tâches récurrentes
- `RecurringTaskService` - Service de gestion des tâches récurrentes
---