# AbstractUniqueTask - Référence Technique

## Description

Classe abstraite de base pour les tâches qui doivent s'exécuter une seule fois à une date planifiée. Elle fournit un workflow d'exécution standardisé avec logging, gestion des erreurs et hooks de cycle de vie.

## Hiérarchie / Implémentations

```
TaskInterface
    └── AbstractUniqueTask
            └── [Vos tâches uniques]
```

## Rôle principal

Fournir une structure standardisée pour l'exécution des tâches uniques en :
- Gérant le cycle de vie complet (before → process → after)
- Assurant un logging cohérent (début, succès, échec)
- Gérant les exceptions et la propagation
- Fournissant des hooks pour la logique métier personnalisée

## API / Méthodes publiques

### `execute(StrictDataObject $payload): void`

Point d'entrée principal de la tâche. Orchestre l'exécution complète.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$payload` | `StrictDataObject` | Données d'entrée de la tâche |

**Exceptions :** `Throwable` - Toute exception levée par `process()` est propagée

**Exemple :**
```php
class MyUniqueTask extends AbstractUniqueTask
{
    protected function process(): void
    {
        // Logique métier
    }
}

$task = new MyUniqueTask($context, $logger, $hydration);
$task->execute(StrictDataObject::from(['user_id' => 123]));
```

---

### `info(DescriptionVO $message): void`

Journalise un message d'information de la tâche.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$message` | `DescriptionVO` | Message à journaliser |

**Exemple :**
```php
$task->info(new DescriptionVO('Processing user 123'));
```

---

### `error(DescriptionVO $message): void`

Journalise un message d'erreur de la tâche.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$message` | `DescriptionVO` | Message d'erreur à journaliser |

**Exemple :**
```php
$task->error(new DescriptionVO('Failed to connect to API'));
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
    $userId = $data->user_id;
    
    if (!$this->sendEmail($userId)) {
        throw new RuntimeException('Email sending failed');
    }
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
    if (!isset($payload->user_id)) {
        throw new InvalidArgumentException('user_id is required');
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
    if ($success) {
        $this->notifySuccess();
    } else {
        $this->notifyFailure($error);
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
    │   └── Log: unique_task → task_started
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
| `unique_task` | `task_started`, `task_completed`, `task_failed` |
| `unique_task_output` | `info`, `error` |

### Structure des logs

```json
{
    "type": "unique_task",
    "payload": {
        "event": "task_started",
        "task_id": "...",
        "alias": "unique@...",
        "scheduled_at": "..."
    }
}
```

## Cas d'utilisation

### Cas 1 : Tâche simple

**Problème :** Envoyer un email à un utilisateur.

```php
class SendWelcomeEmailTask extends AbstractUniqueTask
{
    protected function process(): void
    {
        $payload = $this->context->getPayload();
        $email = $payload->email;
        
        $this->info(new DescriptionVO("Sending email to {$email}"));
        
        // Logique d'envoi
        $sent = Mail::to($email)->send(new WelcomeEmail());
        
        if (!$sent) {
            throw new RuntimeException('Failed to send email');
        }
        
        $this->info(new DescriptionVO("Email sent to {$email}"));
    }
}
```

---

### Cas 2 : Tâche avec validation

**Problème :** Exporter des données avec validation préalable.

```php
class ExportDataTask extends AbstractUniqueTask
{
    protected function before(StrictDataObject $payload): void
    {
        if (!isset($payload->format)) {
            throw new InvalidArgumentException('Format is required');
        }
        
        if (!in_array($payload->format, ['csv', 'json'])) {
            throw new InvalidArgumentException('Invalid format');
        }
    }
    
    protected function process(): void
    {
        $payload = $this->context->getPayload();
        $data = $this->fetchData();
        $file = $this->export($data, $payload->format);
        
        $this->info(new DescriptionVO("Export completed: {$file}"));
    }
    
    protected function after(bool $success, ?DescriptionVO $error = null): void
    {
        if ($success) {
            $this->notifyAdmin('Export successful');
        } else {
            $this->notifyAdmin("Export failed: {$error->getValue()}");
        }
    }
}
```

---

### Cas 3 : Tâche avec gestion d'échec

**Problème :** Traiter une commande avec retry.

```php
class ProcessOrderTask extends AbstractUniqueTask
{
    protected function process(): void
    {
        $payload = $this->context->getPayload();
        $orderId = $payload->order_id;
        
        $this->info(new DescriptionVO("Processing order #{$orderId}"));
        
        $order = Order::find($orderId);
        if (!$order) {
            throw new RuntimeException("Order #{$orderId} not found");
        }
        
        // Processus métier
        $order->process();
        $order->save();
        
        $this->info(new DescriptionVO("Order #{$orderId} completed"));
    }
    
    protected function after(bool $success, ?DescriptionVO $error = null): void
    {
        if (!$success) {
            $this->error($error ?? new DescriptionVO('Unknown error'));
            // Notification d'échec
        }
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
    "type": "unique_task",
    "payload": {
        "event": "task_failed",
        "task_id": "...",
        "status": "failed",
        "error": "Connection timeout"
    }
}
```

## Intégration

### Dépendances injectées

| Dépendance | Rôle |
|------------|------|
| `UniqueTaskContext` | Contexte d'exécution (ID, alias, planification) |
| `LoggerInterface` | Journalisation des événements |
| `HydrationService` | Hydratation des objets pour les logs |

### Accès au contexte

```php
$this->context->getTaskId();     // UuidVO
$this->context->getAlias();      // TaskAliasVO
$this->context->getScheduledAt(); // Iso8601DateTimeVO
$this->context->getPayload();    // StrictDataObject
```

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

use AndyDefer\Task\Abstract\AbstractUniqueTask;
use AndyDefer\Task\ValueObjects\DescriptionVO;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use RuntimeException;

final class CleanupTask extends AbstractUniqueTask
{
    protected function before(StrictDataObject $payload): void
    {
        if (!$payload->has('days')) {
            throw new InvalidArgumentException('Missing "days" parameter');
        }
    }
    
    protected function process(): void
    {
        $payload = $this->context->getPayload();
        $days = (int) $payload->days;
        
        $this->info(new DescriptionVO("Cleaning up records older than {$days} days"));
        
        $deleted = DB::table('logs')
            ->where('created_at', '<', Carbon::now()->subDays($days))
            ->delete();
        
        $this->info(new DescriptionVO("Deleted {$deleted} records"));
        
        if ($deleted === 0) {
            $this->info(new DescriptionVO('No records to delete'));
        }
    }
    
    protected function after(bool $success, ?DescriptionVO $error = null): void
    {
        $this->logger->info('Cleanup task completed', [
            'success' => $success,
            'payload' => $this->context->getPayload()->toArray(),
        ]);
    }
}

// Utilisation
$context = new UniqueTaskContext();
$context->setTaskId(new UuidVO('...'));
$context->setAlias(new TaskAliasVO('unique@...'));
$context->setScheduledAt(new Iso8601DateTimeVO(Carbon::tomorrow()->toIso8601String()));

$task = new CleanupTask($context, $logger, $hydration);
$task->execute(StrictDataObject::from(['days' => 30]));
```

## Voir aussi

- `AbstractRecurringTask` - Classe de base pour les tâches récurrentes
- `TaskInterface` - Interface commune à toutes les tâches
- `UniqueTaskContext` - Contexte des tâches uniques
- `UniqueTaskService` - Service de gestion des tâches uniques
---