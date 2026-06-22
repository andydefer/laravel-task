# Tâches Uniques - Référence Technique

## Description

Les tâches uniques sont des tâches qui s'exécutent une seule fois à une date planifiée (`scheduled_at`). Elles disposent d'une période de grâce (`grace_period`) et d'un système de tentatives pour gérer les échecs.

## Architecture

```
┌─────────────────────────────────────────────────────────────────────┐
│                    Architecture d'une tâche unique                 │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │                  AbstractUniqueTask                         │   │
│  │  - Classe abstraite de base                                 │   │
│  │  - Définit le cycle de vie (before, process, after)        │   │
│  │  - Gère la journalisation automatique                       │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              ▲                                      │
│                              │                                      │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │                  TestUniqueTask (Fixture)                   │   │
│  │  - Implémentation concrète pour les tests                   │   │
│  │  - Définit la configuration via getConfig()                │   │
│  │  - Contient la logique métier dans process()               │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │                  UniqueTaskContext                          │   │
│  │  - Contexte d'exécution de la tâche                         │   │
│  │  - Contient : taskId, alias, scheduled_at                  │   │
│  │  - Injecté dans la tâche via le constructeur                │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## Cycle de vie

```
┌─────────────────────────────────────────────────────────────────────┐
│                    Cycle de vie d'une tâche unique                  │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  Création                                                          │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  status = PENDING                                           │   │
│  │  id = UUID généré automatiquement                           │   │
│  │  scheduled_at = date planifiée                              │   │
│  │  attempts = 0                                               │   │
│  │  max_attempts = 3 (par défaut)                             │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  Attente (scheduled_at > now)                                      │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  La tâche attend son heure d'exécution                      │   │
│  │  Statut reste PENDING                                       │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  Exécution (scheduled_at <= now)                                   │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  1. Vérifier les conditions (statut, tentatives, expiration)│   │
│  │  2. Exécuter la tâche                                       │   │
│  │  3. Succès → COMPLETED                                      │   │
│  │  4. Échec → attempts++                                      │   │
│  │  5. attempts >= max_attempts → FAILED                      │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  Fin                                                               │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  Statut terminal : COMPLETED ou FAILED                      │   │
│  │  finished_at = date de fin                                  │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
│  Période de grâce (Grace Period)                                  │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  La tâche peut être exécutée après scheduled_at             │   │
│  │  Délai = grace_period_seconds (défaut: 86400 = 24h)        │   │
│  │  Expiration si now > scheduled_at + grace_period            │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## Modèle Eloquent

### `UniqueTask`

```php
final class UniqueTask extends Model
{
    use SoftDeletes;

    // Configuration UUID
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',                    // UUID
        'alias',                 // Identifiant unique
        'fqcn',                  // Nom complet de la classe
        'payload',               // Données de la tâche
        'scheduled_at',          // Date planifiée
        'grace_period_seconds',  // Période de grâce en secondes
        'status',                // PENDING, COMPLETED, FAILED
        'attempts',              // Nombre de tentatives
        'max_attempts',          // Nombre maximum de tentatives
        'finished_at',           // Date de fin effective
    ];
}
```

### Accesseurs

| Méthode | Retour | Description |
|---------|--------|-------------|
| `getId(): TaskIdVO` | `TaskIdVO` | UUID de la tâche |
| `getAlias(): TaskSignatureVO` | `TaskSignatureVO` | Alias de la tâche |
| `getScheduledAt(): Iso8601DateTimeVO` | `Iso8601DateTimeVO` | Date planifiée |
| `getFinishedAt(): ?Iso8601DateTimeVO` | `?Iso8601DateTimeVO` | Date de fin |
| `getStatus(): UniqueTaskStatus` | `UniqueTaskStatus` | Statut actuel |
| `getAttempts(): CounterVO` | `CounterVO` | Nombre de tentatives |
| `getMaxAttempts(): CounterVO` | `CounterVO` | Nombre maximum de tentatives |
| `getGracePeriodSeconds(): int` | `int` | Période de grâce |
| `getPayload(): StrictDataObject` | `StrictDataObject` | Données de la tâche |
| `getFqcn(): string` | `string` | Nom de la classe |

## Classe Abstraite

### `AbstractUniqueTask`

```php
abstract class AbstractUniqueTask implements UniqueTaskInterface
{
    protected UniqueTaskContext $context;
    protected LoggerInterface $logger;
    protected HydrationService $hydration;

    // Méthodes abstraites à implémenter
    abstract public function getConfig(): UniqueTaskConfigInterface;
    abstract protected function process(): void;

    // Méthodes optionnelles à surcharger
    protected function before(): void {}
    protected function after(bool $success, ?string $error = null): void {}

    // Méthode finale d'exécution
    final public function execute(StrictDataObject $payload): void;

    // Méthodes de journalisation
    public function info(string $message): void;
    public function error(string $message): void;
}
```

### Cycle d'exécution

```
execute()
    ├── setPayload($payload)
    ├── log('task_started')
    ├── before()
    ├── try
    │   ├── process()          ← Implémentée par la tâche concrète
    │   ├── after(true)
    │   └── log('task_completed')
    ├── catch
    │   ├── after(false, $error)
    │   ├── log('task_failed')
    │   └── throw $e
    └── end
```

## Contexte

### `UniqueTaskContext`

```php
class UniqueTaskContext implements UniqueTaskContextInterface
{
    // Propriétés
    private StrictDataObject $payload;
    private TaskIdVO $taskId;
    private TaskSignatureVO $alias;
    private Iso8601DateTimeVO $scheduledAt;
    private ?Application $app;

    // Getters / Setters
    public function setPayload(StrictDataObject $payload): void;
    public function getPayload(): StrictDataObject;

    public function setTaskId(TaskIdVO $taskId): void;
    public function getTaskId(): TaskIdVO;

    public function setAlias(TaskSignatureVO $alias): void;
    public function getAlias(): TaskSignatureVO;

    public function setScheduledAt(Iso8601DateTimeVO $scheduledAt): void;
    public function getScheduledAt(): Iso8601DateTimeVO;

    public function setLaravelApp(Application $app): void;
    public function getLaravelApp(): ?Application;
}
```

## Configuration

### `UniqueTaskConfig`

```php
class UniqueTaskConfig implements UniqueTaskConfigInterface
{
    public function __construct(
        public readonly TaskSignatureVO $alias,
        public readonly string $description,
        public readonly Iso8601DateTimeVO $scheduled_at,
        public readonly CounterVO $max_attempts = new CounterVO(3),
    ) {}
}
```

## Statuts

### `UniqueTaskStatus`

| Statut | Valeur | Description |
|--------|--------|-------------|
| `PENDING` | `'pending'` | En attente d'exécution |
| `COMPLETED` | `'completed'` | Exécutée avec succès |
| `FAILED` | `'failed'` | Échec (tentatives épuisées ou expirée) |

```php
enum UniqueTaskStatus: string
{
    case PENDING = 'pending';
    case COMPLETED = 'completed';
    case FAILED = 'failed';

    public function isPending(): bool { /* ... */ }
    public function isCompleted(): bool { /* ... */ }
    public function isFailed(): bool { /* ... */ }
    public function isTerminal(): bool { /* ... */ }
}
```

## Période de grâce (Grace Period)

La période de grâce permet d'exécuter une tâche après sa date planifiée sans qu'elle soit considérée comme expirée.

```
scheduled_at ────────────────────────────────────────────────► temps
     │                                                            │
     │  Période de grâce (grace_period_seconds)                  │
     │  ┌──────────────────────────────────────────┐              │
     │  │   La tâche peut être exécutée            │              │
     │  │   même si scheduled_at est dépassé       │              │
     │  └──────────────────────────────────────────┘              │
     │                                                            │
     └────────────────────────────────────────────────────────────┘
                          ▲                                ▲
                          │                                │
                    Exécution possible          Expiration
                    (dans la période)           (hors période)
```

## Cas d'utilisation

### Cas 1 : Créer une tâche unique

```php
$task = new TestUniqueTask(
    $context,
    $logger,
    $hydration
);

$config = $task->getConfig();
echo $config->getAlias()->value; // 'test-unique'
echo $config->getScheduledAt()->value; // Date planifiée
echo $config->getMaxAttempts()->value; // 3
```

### Cas 2 : Exécuter une tâche unique

```php
$task = new TestUniqueTask(
    $context,
    $logger,
    $hydration
);

$payload = StrictDataObject::from(['email' => 'john@example.com']);
$task->execute($payload);

$log = $task->getExecutionLog();
// [['time' => '...', 'payload' => ['email' => 'john@example.com']]]
```

### Cas 3 : Journalisation

```php
$task = new TestUniqueTask(
    $context,
    $logger,
    $hydration
);

$task->info('Sending welcome email');
$task->error('Failed to send email');

// Les messages sont automatiquement journalisés
```

### Cas 4 : Tâche avec échec

```php
$task = new TestUniqueTask(
    $context,
    $logger,
    $hydration
);

$task->setFailOn('Email sending failed');
$payload = StrictDataObject::from(['email' => 'john@example.com']);

try {
    $task->execute($payload);
} catch (RuntimeException $e) {
    echo $e->getMessage(); // 'Email sending failed'
    // Une entrée de log 'task_failed' a été créée
}
```

### Cas 5 : Gestion des tentatives

```php
// Le système incrémente automatiquement les tentatives
// après chaque échec
$record = new UniqueTaskRecord(
    // ...
    attempts: new CounterVO(0),
    max_attempts: new CounterVO(3),
);

// Première exécution → échec → attempts = 1
// Deuxième exécution → échec → attempts = 2
// Troisième exécution → échec → attempts = 3 → FAILED
```

## Journalisation

Les tâches uniques produisent automatiquement les logs suivants :

| Événement | Type | Description |
|-----------|------|-------------|
| `task_started` | `unique_task` | Début de l'exécution |
| `task_completed` | `unique_task` | Exécution réussie |
| `task_failed` | `unique_task` | Échec de l'exécution |
| `info` | `unique_task_output` | Message d'information |
| `error` | `unique_task_output` | Message d'erreur |

## Bonnes pratiques

1. **Configurer la date** : Utiliser `Iso8601DateTimeVO` pour `scheduled_at`
2. **Définir les tentatives** : Ajuster `max_attempts` selon la criticité
3. **Période de grâce** : Ajuster `grace_period_seconds` selon les besoins
4. **Journaliser** : Utiliser `$this->info()` et `$this->error()`
5. **Surcharger `before()` et `after()`** : Pour les actions pré/post-exécution
6. **UUID** : L'ID est un UUID généré automatiquement

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Abstract\AbstractUniqueTask;
use AndyDefer\Task\Contexts\UniqueTaskContext;
use AndyDefer\Task\Configs\UniqueTaskConfig;

class SendWelcomeEmailTask extends AbstractUniqueTask
{
    public function getConfig(): UniqueTaskConfig
    {
        return new UniqueTaskConfig(
            alias: new TaskSignatureVO('welcome-email'),
            description: 'Send welcome email to new user',
            scheduled_at: new Iso8601DateTimeVO(now()->addMinutes(5)->toIso8601String()),
            max_attempts: new CounterVO(3),
        );
    }

    protected function process(): void
    {
        $payload = $this->context->getPayload()->toArray();
        $email = $payload['email'] ?? throw new \RuntimeException('Email not provided');

        $this->info("Sending welcome email to {$email}");

        $success = $this->sendEmail($email);

        if (!$success) {
            throw new \RuntimeException('Failed to send email');
        }

        $this->info("Welcome email sent to {$email}");
    }

    private function sendEmail(string $email): bool
    {
        // Implémentation de l'envoi d'email
        return true;
    }
}
```

## Voir aussi

- `AbstractUniqueTask` - Classe abstraite de base
- `UniqueTaskContext` - Contexte d'exécution
- `UniqueTaskConfig` - Configuration des tâches
- `UniqueTaskStatus` - Énumération des statuts
- `UniqueTaskRepository` - Repository des tâches uniques
- `UniqueTaskService` - Service de gestion des tâches uniques