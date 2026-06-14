# AbstractTask - Référence Technique

## Description

Classe abstraite de base pour toutes les tâches du système. Fournit les fonctionnalités communes d'exécution, de journalisation structurée, les hooks de cycle de vie et l'accès au contexte via `TaskContext`. Utilise l'injection de dépendances dans le constructeur (immutable).

## Hiérarchie

```
AbstractTask
```

La classe est abstraite et doit être étendue par toutes les tâches concrètes. Le constructeur est `final` pour garantir l'immutabilité.

## Rôle principal

Définir le contrat et le comportement standard pour l'exécution des tâches, incluant la journalisation automatique des événements (démarrage, succès, échec), les points d'extension via les méthodes `before()` et `after()`, et l'accès au payload via `$this->context->getPayload()`.

## API / Méthodes publiques

### `__construct(TaskContext $context, LoggerInterface $logger, HydrationService $hydration): void` (final)

Constructeur avec injection des dépendances. Le constructeur est `final` pour garantir l'immutabilité.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$context` | `TaskContext` | Contexte d'exécution (payload, taskId, signature, app) |
| `$logger` | `LoggerInterface` | Service de journalisation |
| `$hydration` | `HydrationService` | Service d'hydratation pour la création d'objets |

**Exemple :**
```php
$context = new TaskContext();
$context->setTaskId(new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'));
$context->setSignature(new TaskSignatureVO('backup-database'));
$context->setLaravelApp(app());

$task = new BackupTask($context, $logger, $hydration);
```

### `getConfig(): TaskConfigRecord` (abstraite)

Retourne la configuration de la tâche avec les Value Objects.

**Retourne :** `TaskConfigRecord` - Configuration incluant signature (`TaskSignatureVO`), délai (`CounterVO`), tentatives max (`CounterVO`), etc.

**Exemple :**
```php
public function getConfig(): TaskConfigRecord
{
    return new TaskConfigRecord(
        signature: new TaskSignatureVO('backup-database'),
        description: 'Sauvegarde la base de données',
        delay_seconds: new CounterVO(0),
        max_attempts: new CounterVO(3),
        start_at: null,
        end_at: null,
    );
}
```

### `execute(TaskPayloadRecord $payload): void` (final)

Point d'entrée principal de la tâche. Gère automatiquement la journalisation et les hooks. Le payload est stocké dans le `TaskContext`.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$payload` | `TaskPayloadRecord` | Données d'entrée de la tâche (type + StrictDataObject) |

**Retourne :** `void`

**Exceptions :** `\Throwable` - Re-lève toute exception de `process()`

**Exemple :**
```php
$payload = new TaskPayloadRecord(
    type: 'backup',
    data: new StrictDataObject(['database' => 'mysql']),
);

$task->execute($payload);
```

### `info(string $message): void`

Enregistre un message d'information dans les logs. Crée automatiquement un log de type `task_output` avec l'événement 'info'.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$message` | `string` | Message à logger |

**Exemple :**
```php
$this->info("Traitement de l'utilisateur {$userId}");
```

### `error(string $message): void`

Enregistre un message d'erreur dans les logs. Crée automatiquement un log de type `task_output` avec l'événement 'error'.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$message` | `string` | Message d'erreur à logger |

**Exemple :**
```php
$this->error("Impossible de connecter à l'API : {$error}");
```

## Propriétés protégées

| Propriété | Type | Description |
|-----------|------|-------------|
| `$context` | `TaskContext` | Contexte d'exécution (payload, IDs, app Laravel) |
| `$logger` | `LoggerInterface` | Service de journalisation |
| `$hydration` | `HydrationService` | Service d'hydratation |

## Hooks protégés

### `before(): void`

Hook appelé avant l'exécution de `process()`. À surcharger pour des actions de pré-traitement.

**Exemple :**
```php
protected function before(): void
{
    $this->info("Préparation de la tâche...");
    $this->context->getLaravelApp()->make(DatabaseService::class)->beginTransaction();
}
```

### `after(bool $success, ?string $error = null): void`

Hook appelé après l'exécution de `process()` (succès ou échec).

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$success` | `bool` | Indique si la tâche a réussi |
| `$error` | `string|null` | Message d'erreur si échec |

**Exemple :**
```php
protected function after(bool $success, ?string $error = null): void
{
    if ($success) {
        $this->info("Tâche terminée avec succès");
    } else {
        $this->error("Tâche échouée : {$error}");
    }
}
```

### `process(): void` (abstraite)

Logique métier principale de la tâche. À implémenter dans les classes filles. Accès au payload via `$this->context->getPayload()`.

## Pattern : Dependency Injection (Injection de dépendances via constructeur)

Le pattern d'injection de dépendances est utilisé pour fournir à la tâche ses dépendances externes (logger, contexte) via le constructeur plutôt que par des setters.

### Problème résolu

```php
// ❌ Sans injection - Couplage fort, setters requis
class BadTask extends AbstractTask
{
    protected function process(): void
    {
        // Dépend du logger injecté via setter
        $this->logger->info("Processing...");
        // Impossible de garantir que le logger est défini
    }
}

// Nécessite 3 appels avant execute()
$task->setLogger($logger)->setTaskId($id)->setSignature($signature)->execute($payload);
```

### Solution avec injection dans le constructeur

```php
// ✅ Avec injection dans le constructeur - Garantie d'initialisation
class GoodTask extends AbstractTask
{
    // Le constructeur est final et accepte toutes les dépendances
    // Toutes les propriétés sont disponibles dès l'instanciation
}

// Une seule ligne pour créer la tâche
$task = new GoodTask($context, $logger, $hydration);
$task->execute($payload);  // Toutes les dépendances sont déjà présentes
```

### Avantages du pattern

| Aspect | Sans injection (setters) | Avec injection (constructeur) |
|--------|--------------------------|-------------------------------|
| **État** | Mutable (setters appelables à tout moment) | Immutable (dépendances figées) |
| **Testabilité** | Bonne | Excellente (tout est injecté) |
| **Sécurité** | Risque d'utilisation avant setter | Garantie d'initialisation |
| **Complexité d'utilisation** | 3-4 appels avant execute() | 1 appel constructeur |
| **Nombre de lignes de code** | Plus | Moins |

## Pattern : Template Method

`AbstractTask` utilise le pattern **Template Method** : l'algorithme d'exécution est défini dans `execute()` (final), et les étapes personnalisables sont déléguées aux méthodes `before()`, `process()`, `after()`.

## Flux d'exécution

```
execute(TaskPayloadRecord $payload)
    │
    ├── context->setPayload($payload)
    │
    ├── Log "task_started"
    │   ├── signature (depuis context)
    │   └── task_id (si présent)
    │
    ├── before() hook
    │
    ├── try
    │   ├── process() hook (logique métier)
    │   ├── after(true)
    │   └── Log "task_completed" (success)
    │
    └── catch (\Throwable $e)
        ├── after(false, $e->getMessage())
        ├── Log "task_failed" (error)
        └── throw $e
```

## Logs automatiques

| Événement | Type de log | Niveau | Contenu |
|-----------|-------------|--------|---------|
| `task_started` | `task` | `info` | signature, task_id (optionnel) |
| `task_completed` | `task` | `info` | signature, task_id (optionnel), status=success |
| `task_failed` | `task` | `error` | signature, task_id (optionnel), status=failed, error |
| `info` | `task_output` | `info` | event=info, message |
| `error` | `task_output` | `error` | event=error, message |

## Cas d'utilisation

### Cas 1 : Tâche simple avec log et accès payload

```php
final class SendEmailTask extends AbstractTask
{
    public function getConfig(): TaskConfigRecord
    {
        return new TaskConfigRecord(
            signature: new TaskSignatureVO('send-email'),
            description: 'Envoie un email',
            delay_seconds: new CounterVO(0),
            max_attempts: new CounterVO(3),
        );
    }
    
    protected function process(): void
    {
        $data = $this->context->getPayload()->data;
        $email = $data->email;
        $subject = $data->subject ?? 'Welcome';
        
        $this->info("Début de l'envoi d'email à {$email}");
        
        // Logique d'envoi
        if (mail($email, $subject, $data->body)) {
            $this->info("Email envoyé avec succès à {$email}");
        } else {
            $this->error("Échec de l'envoi d'email à {$email}");
            throw new \RuntimeException("Email sending failed");
        }
    }
}
```

### Cas 2 : Tâche avec hooks et accès au container Laravel

```php
final class BackupTask extends AbstractTask
{
    private DatabaseService $db;
    
    protected function before(): void
    {
        // Accès au container Laravel via le contexte
        $this->db = $this->context->getLaravelApp()->make(DatabaseService::class);
        $this->info("Préparation de la sauvegarde");
        $this->db->beginTransaction();
    }
    
    protected function process(): void
    {
        $data = $this->context->getPayload()->data;
        $database = $data->database ?? 'default';
        
        $this->info("Sauvegarde de la base {$database}");
        // Logique de sauvegarde
    }
    
    protected function after(bool $success, ?string $error = null): void
    {
        if ($success) {
            $this->db->commit();
            $this->info("Sauvegarde terminée avec succès");
        } else {
            $this->db->rollBack();
            $this->error("Sauvegarde échouée : {$error}");
        }
    }
}
```

## Intégration

### Avec TaskRunnerService

```php
// TaskRunnerService instancie la tâche avec le constructeur
private function instantiateTask(string $className, TaskRecord $task): AbstractTask
{
    $context = new TaskContext();
    $context->setTaskId($task->id);
    $context->setSignature($task->signature);
    $context->setLaravelApp($this->app);
    
    return new $className($context, $this->logger, $this->hydration);
}
```

### Avec le système de logging

Les logs sont structurés au format JSON et incluent automatiquement :
- `signature` - Type de tâche (via `TaskContext`)
- `task_id` - Identifiant unique de la tâche (si présent dans le contexte)
- `event` - Type d'événement (started/completed/failed/info/error)
- `status` - Succès/échec (pour completed/failed)
- `error` - Message d'erreur (pour failed)

## Performance

| Opération | Complexité | Notes |
|-----------|------------|-------|
| `execute()` | O(1) + temps de `process()` | Dépend de l'implémentation |
| `info()` / `error()` | O(1) | Écriture synchrone via LoggerInterface |

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

use AndyDefer\DomainStructures\Services\HydrationService;
use AndyDefer\DomainStructures\Utils\StrictDataObject;
use AndyDefer\Logger\Contracts\LoggerInterface;
use AndyDefer\Task\AbstractTask;
use AndyDefer\Task\Contexts\TaskContext;
use AndyDefer\Task\Records\TaskConfigRecord;
use AndyDefer\Task\Records\TaskPayloadRecord;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\TaskIdVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

final class ProcessOrderTask extends AbstractTask
{
    private int $orderId;
    
    public function getConfig(): TaskConfigRecord
    {
        return new TaskConfigRecord(
            signature: new TaskSignatureVO('process-order'),
            description: 'Traite une commande client',
            delay_seconds: new CounterVO(0),
            max_attempts: new CounterVO(3),
            start_at: null,
            end_at: null,
        );
    }
    
    protected function before(): void
    {
        $payload = $this->context->getPayload();
        $this->orderId = $payload->data->order_id;
        $this->info("Début du traitement de la commande {$this->orderId}");
    }
    
    protected function process(): void
    {
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
$context = new TaskContext();
$context->setTaskId(new TaskIdVO('550e8400-e29b-41d4-a716-446655440000'));
$context->setSignature(new TaskSignatureVO('process-order'));
$context->setLaravelApp(app());

$logger = app(LoggerInterface::class);
$hydration = new HydrationService();

$task = new ProcessOrderTask($context, $logger, $hydration);

$payload = new TaskPayloadRecord(
    type: 'order',
    data: new StrictDataObject(['order_id' => 12345]),
);

$task->execute($payload);
```

## Voir aussi

- `TaskContext` - Contexte d'exécution (payload, IDs, app Laravel)
- `TaskRunnerService` - Service qui instancie et exécute les tâches
- `TaskConfigRecord` - Record de configuration (avec Value Objects)
- `TaskPayloadRecord` - Record de payload (type + StrictDataObject)
- `LoggerInterface` - Interface de journalisation
- `HydrationService` - Service d'hydratation
- `TaskRecord` - Record de persistance pour tâches uniques
- `RecurringTaskRecord` - Record de persistance pour tâches récurrentes
- `CounterVO` - Value Object pour les compteurs
- `TaskIdVO` / `TaskSignatureVO` - Value Objects d'identifiants

---