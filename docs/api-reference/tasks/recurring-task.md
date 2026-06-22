# Tâches Récurrentes - Référence Technique

## Description

Les tâches récurrentes sont des tâches qui s'exécutent périodiquement selon un intervalle défini. Elles restent actives (`PLAYING`) entre les exécutions et se terminent automatiquement à une date de fin (`end_at`).

## Architecture

```
┌─────────────────────────────────────────────────────────────────────┐
│                    Architecture d'une tâche récurrente             │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │                  AbstractRecurringTask                      │   │
│  │  - Classe abstraite de base                                 │   │
│  │  - Définit le cycle de vie (before, process, after)        │   │
│  │  - Gère la journalisation automatique                       │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              ▲                                      │
│                              │                                      │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │                  TestRecurringTask (Fixture)                │   │
│  │  - Implémentation concrète pour les tests                   │   │
│  │  - Définit la configuration via getConfig()                │   │
│  │  - Contient la logique métier dans process()               │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │                  RecurringTaskContext                       │   │
│  │  - Contexte d'exécution de la tâche                         │   │
│  │  - Contient : alias, interval, start_at, end_at, etc.      │   │
│  │  - Injecté dans la tâche via le constructeur                │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## Cycle de vie

```
┌─────────────────────────────────────────────────────────────────────┐
│                    Cycle de vie d'une tâche récurrente              │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  Création                                                          │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  status = WAITING                                           │   │
│  │  start_at = date de début                                   │   │
│  │  interval_seconds = période d'exécution                     │   │
│  │  end_at = date de fin (optionnelle)                        │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  Démarrage (start_at atteint)                                     │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  status = PLAYING                                           │   │
│  │  La tâche devient active                                    │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  Exécution périodique                                             │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  Chaque cycle :                                             │   │
│  │  1. Vérifier si intervalle atteint                          │   │
│  │  2. Exécuter la tâche                                       │   │
│  │  3. Mettre à jour last_run_at                               │   │
│  │  4. Vérifier si end_at atteint                              │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  Fin (end_at atteint)                                             │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  status = FINISHED                                          │   │
│  │  finished_at = date de fin                                  │   │
│  │  La tâche est terminée                                      │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## Modèle Eloquent

### `RecurringTask`

```php
final class RecurringTask extends Model
{
    use SoftDeletes;

    protected $table = 'recurring_tasks';

    protected $fillable = [
        'alias',          // Identifiant unique
        'fqcn',           // Nom complet de la classe
        'payload',        // Données de la tâche
        'interval_seconds', // Intervalle en secondes
        'start_at',       // Date de début
        'end_at',         // Date de fin
        'status',         // WAITING, PLAYING, PAUSED, FINISHED
        'last_run_at',    // Dernière exécution
        'finished_at',    // Date de fin effective
    ];
}
```

### Accesseurs

| Méthode | Retour | Description |
|---------|--------|-------------|
| `getId(): int` | `int` | ID auto-incrémenté |
| `getAlias(): TaskSignatureVO` | `TaskSignatureVO` | Alias de la tâche |
| `getIntervalSeconds(): CounterVO` | `CounterVO` | Intervalle en secondes |
| `getStartAt(): ?Iso8601DateTimeVO` | `?Iso8601DateTimeVO` | Date de début |
| `getLastRunAt(): ?Iso8601DateTimeVO` | `?Iso8601DateTimeVO` | Dernière exécution |
| `getFinishedAt(): ?Iso8601DateTimeVO` | `?Iso8601DateTimeVO` | Date de fin |
| `getStatus(): RecurringTaskStatus` | `RecurringTaskStatus` | Statut actuel |
| `getPayload(): StrictDataObject` | `StrictDataObject` | Données de la tâche |
| `getFqcn(): string` | `string` | Nom de la classe |

## Classe Abstraite

### `AbstractRecurringTask`

```php
abstract class AbstractRecurringTask implements RecurringTaskInterface
{
    protected RecurringTaskContext $context;
    protected LoggerInterface $logger;
    protected HydrationService $hydration;

    // Méthodes abstraites à implémenter
    abstract public function getConfig(): RecurringTaskConfigInterface;
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

### `RecurringTaskContext`

```php
class RecurringTaskContext implements RecurringTaskContextInterface
{
    // Propriétés
    private StrictDataObject $payload;
    private TaskSignatureVO $alias;
    private CounterVO $intervalSeconds;
    private ?Iso8601DateTimeVO $startAt;
    private ?Iso8601DateTimeVO $endAt;
    private ?Iso8601DateTimeVO $lastRunAt;
    private ?Iso8601DateTimeVO $nextRunAt;
    private ?Application $app;

    // Getters / Setters
    public function setPayload(StrictDataObject $payload): void;
    public function getPayload(): StrictDataObject;

    public function setAlias(TaskSignatureVO $alias): void;
    public function getAlias(): TaskSignatureVO;

    public function setIntervalSeconds(CounterVO $intervalSeconds): void;
    public function getIntervalSeconds(): CounterVO;

    public function setStartAt(?Iso8601DateTimeVO $startAt): void;
    public function getStartAt(): ?Iso8601DateTimeVO;

    public function setEndAt(?Iso8601DateTimeVO $endAt): void;
    public function getEndAt(): ?Iso8601DateTimeVO;

    public function setLastRunAt(?Iso8601DateTimeVO $lastRunAt): void;
    public function getLastRunAt(): ?Iso8601DateTimeVO;

    public function setNextRunAt(?Iso8601DateTimeVO $nextRunAt): void;
    public function getNextRunAt(): ?Iso8601DateTimeVO;

    public function setLaravelApp(Application $app): void;
    public function getLaravelApp(): ?Application;
}
```

## Configuration

### `RecurringTaskConfig`

```php
class RecurringTaskConfig implements RecurringTaskConfigInterface
{
    public function __construct(
        public readonly TaskSignatureVO $alias,
        public readonly string $description,
        public readonly CounterVO $interval_seconds,
        public readonly ?Iso8601DateTimeVO $start_at = null,
        public readonly ?Iso8601DateTimeVO $end_at = null,
        public readonly CounterVO $max_attempts = new CounterVO(3),
    ) {}
}
```

## Statuts

### `RecurringTaskStatus`

| Statut | Valeur | Description |
|--------|--------|-------------|
| `WAITING` | `'waiting'` | En attente de démarrage |
| `PLAYING` | `'playing'` | Active, peut être exécutée |
| `PAUSED` | `'paused'` | Mise en pause |
| `FINISHED` | `'finished'` | Terminée |

```php
enum RecurringTaskStatus: string
{
    case WAITING = 'waiting';
    case PLAYING = 'playing';
    case PAUSED = 'paused';
    case FINISHED = 'finished';

    public function isWaiting(): bool { /* ... */ }
    public function isPlaying(): bool { /* ... */ }
    public function isPaused(): bool { /* ... */ }
    public function isFinished(): bool { /* ... */ }
}
```

## Cas d'utilisation

### Cas 1 : Créer une tâche récurrente

```php
$task = new TestRecurringTask(
    $context,
    $logger,
    $hydration
);

$config = $task->getConfig();
echo $config->getAlias()->value; // 'test-recurring'
echo $config->getIntervalSeconds()->value; // 3600
```

### Cas 2 : Exécuter une tâche récurrente

```php
$task = new TestRecurringTask(
    $context,
    $logger,
    $hydration
);

$payload = StrictDataObject::from(['data' => 'value']);
$task->execute($payload);

$log = $task->getExecutionLog();
// [['time' => '...', 'payload' => ['data' => 'value']]]
```

### Cas 3 : Journalisation

```php
$task = new TestRecurringTask(
    $context,
    $logger,
    $hydration
);

$task->info('Processing started');
$task->error('An error occurred');

// Les messages sont automatiquement journalisés
```

### Cas 4 : Tâche avec échec

```php
$task = new TestRecurringTask(
    $context,
    $logger,
    $hydration
);

$task->setFailOn('Planned failure');
$payload = StrictDataObject::from([]);

try {
    $task->execute($payload);
} catch (RuntimeException $e) {
    echo $e->getMessage(); // 'Planned failure'
    // Une entrée de log 'task_failed' a été créée
}
```

## Journalisation

Les tâches récurrentes produisent automatiquement les logs suivants :

| Événement | Type | Description |
|-----------|------|-------------|
| `task_started` | `recurring_task` | Début de l'exécution |
| `task_completed` | `recurring_task` | Exécution réussie |
| `task_failed` | `recurring_task` | Échec de l'exécution |
| `info` | `recurring_task_output` | Message d'information |
| `error` | `recurring_task_output` | Message d'erreur |

## Bonnes pratiques

1. **Configurer l'intervalle** : Utiliser `CounterVO` pour garantir l'immutabilité
2. **Gérer les dates** : Utiliser `Iso8601DateTimeVO` pour les dates
3. **Journaliser** : Utiliser `$this->info()` et `$this->error()`
4. **Surcharger `before()` et `after()`** : Pour les actions pré/post-exécution
5. **Utiliser `StrictDataObject`** : Pour le payload, garantit l'intégrité des données

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Abstract\AbstractRecurringTask;
use AndyDefer\Task\Contexts\RecurringTaskContext;
use AndyDefer\Task\Configs\RecurringTaskConfig;

class BackupTask extends AbstractRecurringTask
{
    public function getConfig(): RecurringTaskConfig
    {
        return new RecurringTaskConfig(
            alias: new TaskSignatureVO('database-backup'),
            description: 'Backup the database',
            interval_seconds: new CounterVO(86400),
            start_at: new Iso8601DateTimeVO('2026-01-01T00:00:00+00:00'),
            max_attempts: new CounterVO(3),
        );
    }

    protected function process(): void
    {
        $config = $this->context->getPayload()->toArray();
        $database = $config['database'] ?? 'default';

        $this->info("Starting backup for database: {$database}");

        // Logique de backup
        $success = $this->performBackup($database);

        if (!$success) {
            throw new \RuntimeException('Backup failed');
        }

        $this->info("Backup completed successfully");
    }

    private function performBackup(string $database): bool
    {
        // Implémentation du backup
        return true;
    }
}
```

## Voir aussi

- `AbstractRecurringTask` - Classe abstraite de base
- `RecurringTaskContext` - Contexte d'exécution
- `RecurringTaskConfig` - Configuration des tâches
- `RecurringTaskStatus` - Énumération des statuts
- `RecurringTaskRepository` - Repository des tâches récurrentes
- `RecurringTaskService` - Service de gestion des tâches récurrentes