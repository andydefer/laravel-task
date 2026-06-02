# AbstractTask - Référence Technique

## Description

Classe abstraite de base pour toutes les tâches du système. Fournit les fonctionnalités communes d'exécution, de journalisation structurée et les hooks de cycle de vie.

## Hiérarchie

```
AbstractTask
```

La classe est abstraite et doit être étendue par toutes les tâches concrètes.

## Rôle principal

Définir le contrat et le comportement standard pour l'exécution des tâches, incluant la journalisation automatique des événements (démarrage, succès, échec) et les points d'extension via les méthodes `before()` et `after()`.

## API / Méthodes publiques

### `getConfig(): TaskConfigRecord` (abstraite)

Retourne la configuration de la tâche.

**Retourne :** `TaskConfigRecord` - Configuration incluant signature, délai, tentatives max, etc.

**Exemple :**
```php
public function getConfig(): TaskConfigRecord
{
    return new TaskConfigRecord(
        signature: 'backup-database',
        description: 'Sauvegarde la base de données',
        maxAttempts: 3,
        delaySeconds: 0,
    );
}
```

### `execute(TaskPayloadRecord $payload): void`

Point d'entrée principal de la tâche. Gère automatiquement la journalisation et les hooks.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$payload` | `TaskPayloadRecord` | Données d'entrée de la tâche |

**Retourne :** `void`

**Exceptions :** `\Throwable` - Re-lève toute exception de `process()`

**Exemple :**
```php
$task = new SendEmailTask();
$task->setLogger($logger);
$task->setTaskId('uuid-123');
$task->setSignature('send-welcome-email');
$task->execute($payload);
```

### `info(string $message): void`

Enregistre un message d'information dans les logs.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$message` | `string` | Message à logger |

**Exemple :**
```php
$this->info("Traitement de l'utilisateur {$userId}");
```

### `error(string $message): void`

Enregistre un message d'erreur dans les logs.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$message` | `string` | Message d'erreur à logger |

**Exemple :**
```php
$this->error("Impossible de connecter à l'API : {$error}");
```

### `setLogger(Logger $logger): self`

Injecte l'instance du logger dans la tâche (Dependency Injection).

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$logger` | `Logger` | Instance du logger à utiliser |

**Retourne :** `self` - Retourne l'instance pour le chaînage

**Exemple :**
```php
$task->setLogger($logger)->setTaskId($id)->execute($payload);
```

### `setTaskId(string $id): self`

Définit l'identifiant unique de la tâche pour le traçage des logs.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$id` | `string` | Identifiant unique (généralement UUID) |

**Retourne :** `self` - Retourne l'instance pour le chaînage

### `setSignature(string $signature): self`

Définit la signature lisible de la tâche pour l'identification dans les logs.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$signature` | `string` | Signature unique de la tâche (ex: 'send-email') |

**Retourne :** `self` - Retourne l'instance pour le chaînage

## Hooks protégés

### `before(): void`

Hook appelé avant l'exécution de `process()`. À surcharger pour des actions de pré-traitement.

### `after(bool $success, ?string $error = null): void`

Hook appelé après l'exécution de `process()`.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$success` | `bool` | Indique si la tâche a réussi |
| `$error` | `string|null` | Message d'erreur si échec |

### `process(): void` (abstraite)

Logique métier principale de la tâche. À implémenter dans les classes filles.

## Pattern : Dependency Injection (Injection de dépendances)

Le pattern d'injection de dépendances est utilisé pour fournir à la tâche ses dépendances externes (logger, configuration) plutôt que de les créer en interne.

### Problème résolu

```php
// ❌ Sans injection - Couplage fort
class BadTask extends AbstractTask
{
    protected function process(): void
    {
        // La tâche crée son propre logger
        $logger = new Logger();  // ← Problème !
        $logger->info("Processing...");
        // Impossible de tester, logger réel utilisé
    }
}
```

### Solution avec injection

```php
// ✅ Avec injection - Couplage faible
class GoodTask extends AbstractTask
{
    protected function process(): void
    {
        // La tâche utilise le logger injecté
        $this->logger->info("Processing...");  // ← Mockable !
    }
}

// Dans le service
$task = new GoodTask();
$task->setLogger($this->logger);  // ← Injection
$task->setTaskId($taskId);        // ← Injection
$task->setSignature($signature);  // ← Injection
$task->execute($payload);
```

### Avantages du pattern

| Aspect | Sans injection | Avec injection |
|--------|----------------|----------------|
| **Testabilité** | Difficile (logger réel) | Facile (logger mocké) |
| **Flexibilité** | Faible | Haute |
| **Couplage** | Fort | Faible |
| **Réutilisabilité** | Limitée | Maximale |

## Pattern : Chaînage de méthodes (Fluent Interface)

Les setters retournent `$this`, permettant le chaînage :

```php
// Syntaxe fluide
$task
    ->setLogger($logger)
    ->setTaskId($id)
    ->setSignature($signature)
    ->execute($payload);
```

## Flux d'exécution

```
execute($payload)
    │
    ├─→ Log 'task_started'
    │
    ├─→ before()
    │
    ├─→ try
    │   ├─→ process()
    │   ├─→ after(true)
    │   └─→ Log 'task_completed'
    │
    └─→ catch
        ├─→ after(false, $error)
        ├─→ Log 'task_failed'
        └─→ throw $e (re-throw)
```

## Cas d'utilisation

### Cas 1 : Tâche simple avec log

```php
final class SendEmailTask extends AbstractTask
{
    public function getConfig(): TaskConfigRecord
    {
        return new TaskConfigRecord(
            signature: 'send-email',
            description: 'Envoie un email',
            maxAttempts: 3,
        );
    }
    
    protected function process(): void
    {
        $this->info("Début de l'envoi d'email");
        
        // Logique d'envoi
        $result = mail(...);
        
        if ($result) {
            $this->info("Email envoyé avec succès");
        } else {
            $this->error("Échec de l'envoi d'email");
            throw new \RuntimeException("Email sending failed");
        }
    }
}
```

### Cas 2 : Tâche avec hooks

```php
final class BackupTask extends AbstractTask
{
    private bool $backupSuccessful = false;
    
    protected function before(): void
    {
        $this->info("Préparation de la sauvegarde");
        // Créer un dossier temporaire
    }
    
    protected function process(): void
    {
        // Logique de sauvegarde
        $this->backupSuccessful = true;
    }
    
    protected function after(bool $success, ?string $error = null): void
    {
        if ($success) {
            $this->info("Sauvegarde terminée avec succès");
        } else {
            $this->error("Sauvegarde échouée : {$error}");
            // Nettoyer les fichiers temporaires
        }
    }
}
```

## Gestion des erreurs

| Situation | Comportement |
|-----------|--------------|
| Exception dans `process()` | Catchée, log 'task_failed', re-throw |
| Succès de `process()` | Log 'task_completed' |
| Erreur avant `process()` | Log non effectué (hors scope) |

## Intégration

### Avec TaskRunnerService

```php
// TaskRunnerService utilise les setters
$taskInstance = new $className();
$taskInstance->setLogger($this->logger);
$taskInstance->setTaskId($task->id);
$taskInstance->setSignature($task->signature);
$taskInstance->execute($task->payload);
```

### Avec le système de logging

Les logs sont structurés au format JSON et incluent automatiquement :
- `task_id` - Identifiant unique de la tâche
- `signature` - Type de tâche
- `event` - Type d'événement (started/completed/failed)
- `status` - Succès/échec (pour completed/failed)

## Performance

| Opération | Complexité | Notes |
|-----------|------------|-------|
| `execute()` | O(1) + temps de `process()` | Dépend de l'implémentation |
| `info()` / `error()` | O(1) | Écriture synchrone (bufferisable) |

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

use AndyDefer\Task\AbstractTask;
use AndyDefer\Task\Records\TaskConfigRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;

final class ProcessOrderTask extends AbstractTask
{
    private int $orderId;
    
    public function getConfig(): TaskConfigRecord
    {
        return new TaskConfigRecord(
            signature: 'process-order',
            description: 'Traite une commande client',
            maxAttempts: 3,
        );
    }
    
    protected function before(): void
    {
        $payload = $this->payload->payload->first();
        $this->orderId = $payload->order_id;
        $this->info("Début du traitement de la commande {$this->orderId}");
    }
    
    protected function process(): void
    {
        // Simuler le traitement
        if ($this->orderId <= 0) {
            throw new \InvalidArgumentException("Invalid order ID");
        }
        
        $this->info("Traitement de la commande {$this->orderId}");
        // Logique métier...
    }
    
    protected function after(bool $success, ?string $error = null): void
    {
        if ($success) {
            $this->info("Commande {$this->orderId} traitée avec succès");
        } else {
            $this->error("Échec du traitement de la commande {$this->orderId} : {$error}");
        }
    }
}

// Utilisation
$task = new ProcessOrderTask();
$task
    ->setLogger($logger)
    ->setTaskId(Uuid::uuid4()->toString())
    ->setSignature('process-order')
    ->execute($payload);
```

## Voir aussi

- `TaskRunnerService` - Service qui instancie et exécute les tâches
- `TaskConfigRecord` - Record de configuration
- `TaskPayloadRecord` - Record de payload
- `Logger` - Service de journalisation
- `TaskRecord` - Record de persistance

---
