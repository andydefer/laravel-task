# Laravel Task - Système de Gestion de Tâches Asynchrones

[![Version PHP](https://img.shields.io/badge/PHP-8.1%2B-blue)](https://php.net)
[![Version Laravel](https://img.shields.io/badge/Laravel-12.x%20|%2013.x%20|%2014.x%20|%2015.x-blue)](https://laravel.com)
[![Licence](https://img.shields.io/badge/Licence-MIT-green)](LICENSE)

---

## 📖 Table des matières

1. [Présentation](#-présentation)
2. [Installation](#-installation)
3. [Premiers pas](#-premiers-pas)
4. [Créer une tâche unique](#-créer-une-tâche-unique)
5. [Créer une tâche récurrente](#-créer-une-tâche-récurrente)
6. [Exécuter des tâches](#-exécuter-des-tâches)
7. [Directive CLI](#-directive-cli)
8. [Concepts avancés](#-concepts-avancés)
9. [Bonnes pratiques](#-bonnes-pratiques)
10. [Référence de l'API](#-référence-de-lapi)
11. [Tests](#-tests)

---

## 🎯 Présentation

**Laravel Task** est un package PHP pour Laravel qui permet de gérer des tâches asynchrones avec support des tâches uniques et récurrentes.

### Fonctionnalités principales

- ✅ **Tâches uniques** : Exécution unique avec système de tentatives et période de grâce
- ✅ **Tâches récurrentes** : Exécution périodique avec gestion des pauses et modification dynamique
- ✅ **Validation intégrée** : Vérification automatique des conditions d'exécution
- ✅ **Journalisation automatique** : Logs structurés des exécutions et erreurs
- ✅ **CLI intuitive** : Directive `process-tasks` pour le traitement par lots
- ✅ **Soft Delete** : Suppression logique des tâches
- ✅ **Debug intégré** : Journal des exécutions pour faciliter le débogage

---

## 📦 Installation

### Prérequis

- PHP 8.1 ou supérieur
- Laravel 12.x, 13.x, 14.x ou 15.x

### 1. Installation

```bash
composer require andydefer/laravel-task
```

### 2. Publier et exécuter les migrations

```bash
php artisan vendor:publish --tag=task-migrations
php artisan migrate
```

---

## 🚀 Premiers pas

### Structure d'une tâche

Une tâche est une classe qui étend `AbstractUniqueTask` ou `AbstractRecurringTask`. Elle doit implémenter :

1. `getConfig()` : Retourne la configuration de la tâche
2. `process()` : Contient la logique métier

```php
use AndyDefer\Task\Abstract\AbstractUniqueTask;
use AndyDefer\Task\Configs\UniqueTaskConfig;

final class SendEmailTask extends AbstractUniqueTask
{
    public function getConfig(): UniqueTaskConfig
    {
        return new UniqueTaskConfig(
            alias: new TaskSignatureVO('send-email'),
            description: 'Send a welcome email',
            scheduled_at: new Iso8601DateTimeVO(now()->addMinutes(5)->toIso8601String()),
            max_attempts: new CounterVO(3),
        );
    }

    protected function process(): void
    {
        $email = $this->context->getPayload()->toArray()['email'];
        $this->info("Sending email to {$email}");
        // Logique d'envoi...
    }
}
```

### Enregistrer et exécuter

```php
use AndyDefer\Task\Services\UniqueTaskService;

$service = app(UniqueTaskService::class);

// Enregistrer
$taskId = $service->register(
    SendEmailTask::class,
    StrictDataObject::from(['email' => 'john@example.com'])
);

// Exécuter
$success = $service->run($taskId);
```

---

## 📧 Créer une tâche unique

### 1. Définir la classe

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Abstract\AbstractUniqueTask;
use AndyDefer\Task\Configs\UniqueTaskConfig;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

final class SendWelcomeEmailTask extends AbstractUniqueTask
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

        // Simuler l'envoi
        if (!$this->sendEmail($email)) {
            throw new \RuntimeException('Failed to send email');
        }

        $this->info("Welcome email sent to {$email}");
    }

    private function sendEmail(string $email): bool
    {
        // Votre logique d'envoi d'email
        return true;
    }
}
```

### 2. Enregistrer la tâche

```php
use AndyDefer\Task\Services\UniqueTaskService;

$service = app(UniqueTaskService::class);

$taskId = $service->register(
    SendWelcomeEmailTask::class,
    StrictDataObject::from(['email' => 'john@example.com'])
);

echo "Tâche enregistrée avec l'ID : {$taskId->value}\n";
```

### 3. Exécuter la tâche

```php
use AndyDefer\Task\Services\UniqueTaskService;

$service = app(UniqueTaskService::class);
$taskId = new TaskIdVO('550e8400-e29b-41d4-a716-446655440000');

$success = $service->run($taskId);

if ($success) {
    echo "✅ Tâche exécutée avec succès\n";
} else {
    echo "❌ Échec de l'exécution\n";
}
```

### 4. Gérer les tentatives

```php
use AndyDefer\Task\Services\UniqueTaskService;

$service = app(UniqueTaskService::class);

// La tâche sera réessayée automatiquement jusqu'à max_attempts
$success = $service->run($taskId);

if (!$success) {
    $task = $service->find($taskId);
    echo "Tentative {$task->attempts->value}/{$task->max_attempts->value}\n";
}
```

---

## 🔄 Créer une tâche récurrente

### 1. Définir la classe

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Abstract\AbstractRecurringTask;
use AndyDefer\Task\Configs\RecurringTaskConfig;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskSignatureVO;

final class DatabaseBackupTask extends AbstractRecurringTask
{
    public function getConfig(): RecurringTaskConfig
    {
        return new RecurringTaskConfig(
            alias: new TaskSignatureVO('db-backup'),
            description: 'Daily database backup',
            interval_seconds: new CounterVO(86400), // 24h
            start_at: new Iso8601DateTimeVO(now()->toIso8601String()),
            end_at: new Iso8601DateTimeVO(now()->addYear()->toIso8601String()),
            max_attempts: new CounterVO(3),
        );
    }

    protected function process(): void
    {
        $this->info("Starting database backup");

        // Logique de backup
        if (!$this->performBackup()) {
            throw new \RuntimeException('Backup failed');
        }

        $this->info("Database backup completed");
    }

    private function performBackup(): bool
    {
        // Votre logique de backup
        return true;
    }
}
```

### 2. Enregistrer la tâche

```php
use AndyDefer\Task\Services\RecurringTaskService;

$service = app(RecurringTaskService::class);

$config = new RecurringTaskConfig(
    alias: new TaskSignatureVO('db-backup'),
    description: 'Daily database backup',
    interval_seconds: new CounterVO(86400),
    start_at: new Iso8601DateTimeVO(now()->toIso8601String()),
    max_attempts: new CounterVO(3),
);

$alias = $service->register(
    DatabaseBackupTask::class,
    StrictDataObject::from(['database' => 'main']),
    $config
);

echo "Tâche enregistrée avec l'alias : {$alias->value}\n";
```

### 3. Gérer l'état de la tâche

```php
use AndyDefer\Task\Services\RecurringTaskService;

$service = app(RecurringTaskService::class);
$alias = new TaskSignatureVO('db-backup');

// Mettre en pause
$service->pause($alias);
echo "Tâche mise en pause\n";

// Reprendre
$service->resume($alias);
echo "Tâche reprise\n";

// Terminer prématurément
$service->finish($alias);
echo "Tâche terminée\n";
```

### 4. Modifier les paramètres

```php
use AndyDefer\Task\Services\RecurringTaskService;

$service = app(RecurringTaskService::class);
$alias = new TaskSignatureVO('db-backup');

// Changer l'intervalle (2h)
$service->changeInterval($alias, 7200);

// Repousser le démarrage
$service->postponeStartAt($alias, now()->addDays(7));

// Avancer le démarrage
$service->advanceStartAt($alias, now()->addHours(2));
```

---

## ⚡ Exécuter des tâches

### Exécution manuelle

```php
// Tâche unique
$service = app(UniqueTaskService::class);
$success = $service->run($taskId);

// Tâche récurrente
$service = app(RecurringTaskService::class);
$success = $service->run($alias);
```

### Exécution par lots

```php
use AndyDefer\Task\Processors\UniqueTaskProcessor;
use AndyDefer\Task\Processors\RecurringTaskProcessor;

// Traiter 10 tâches uniques
$processor = app(UniqueTaskProcessor::class);
$result = $processor->process(10);

echo "Succès: {$result->success->value}, Échecs: {$result->failed->value}\n";

// Traiter 10 tâches récurrentes
$processor = app(RecurringTaskProcessor::class);
$result = $processor->process(10);

echo "Succès: {$result->success->value}, Échecs: {$result->failed->value}, Terminées: {$result->finished->value}\n";
```

### Recherche et consultation

```php
// Tâches uniques
$service = app(UniqueTaskService::class);

$task = $service->find($taskId);
$pending = $service->findPending();
$completed = $service->findCompleted();
$failed = $service->findFailed();

$total = $service->count();
$pendingCount = $service->countPending();

// Tâches récurrentes
$service = app(RecurringTaskService::class);

$task = $service->find($alias);
$waiting = $service->findWaiting();
$playing = $service->findPlaying();
$paused = $service->findPaused();
$finished = $service->findFinished();

$total = $service->count();
$waitingCount = $service->countWaiting();
```

---

## ⌨ Directive CLI

La directive `process-tasks` permet de traiter les tâches par lots depuis la console.

### Commandes de base

```bash
# Traiter toutes les tâches
./vendor/bin/directive process-tasks

# Traiter avec limite
./vendor/bin/directive process-tasks --limit=50

# Tâches uniques uniquement
./vendor/bin/directive process-tasks --unique-only --limit=10

# Tâches récurrentes uniquement
./vendor/bin/directive process-tasks --recurring-only

# Avec affichage détaillé
./vendor/bin/directive process-tasks --verbose --limit=20

# Utiliser un alias
./vendor/bin/directive task:process --limit=10
```

### Options disponibles

| Option | Description |
|--------|-------------|
| `--unique-only` | Traite uniquement les tâches uniques |
| `--recurring-only` | Traite uniquement les tâches récurrentes |
| `--verbose` | Affiche les détails et les erreurs |
| `--limit=N` | Limite le nombre de tâches à N |

### Exemple de sortie

```
Processing tasks...

=== Batch Results ===
  Unique tasks: 15 processed (✅ 12, ❌ 3)
  Recurring tasks: 8 processed (✅ 8, ❌ 0)
  Total:          23 tasks in 1245 ms
```

---

## 🔧 Concepts avancés

### Validation des tâches

```php
use AndyDefer\Task\Validators\UniqueTaskValidator;

$validator = new UniqueTaskValidator();

if (!$validator->canRun($record)) {
    $errors = $validator->getValidationErrors($record);
    throw new RuntimeException('Task cannot run: ' . $errors->join(', '));
}
```

### Journalisation

```php
protected function process(): void
{
    $this->info("Début du traitement");
    $this->info("étape 1 terminée");
    $this->error("Une erreur est survenue");
}
```

### Utilisation des hooks

```php
protected function before(): void
{
    $this->info("Préparation...");
    // Initialisation des ressources
}

protected function after(bool $success, ?string $error = null): void
{
    // Nettoyage
    $this->info($success ? "Succès" : "Échec: {$error}");
}
```

---

## 📋 Bonnes pratiques

### 1. Utiliser les Value Objects

```php
// ✅ BON
$alias = new TaskSignatureVO('send-email');
$date = new Iso8601DateTimeVO('2026-06-22T14:30:00+00:00');

// ❌ MAUVAIS
$alias = 'send-email';
$date = '2026-06-22 14:30:00';
```

### 2. Journaliser les actions importantes

```php
protected function process(): void
{
    $this->info("Début du traitement");
    // Logique métier...
    $this->info("Traitement terminé");
}
```

### 3. Gérer les exceptions

```php
protected function process(): void
{
    try {
        // Logique métier
    } catch (SpecificException $e) {
        $this->error("Erreur: {$e->getMessage()}");
        throw $e;
    }
}
```

### 4. Utiliser `StrictDataObject` pour le payload

```php
// ✅ BON
$payload = StrictDataObject::from(['email' => 'john@example.com']);

// ❌ MAUVAIS
$payload = ['email' => 'john@example.com'];
```

---

## 📚 Référence de l'API

### Services

| Service | Description |
|---------|-------------|
| `UniqueTaskService` | Gestion des tâches uniques |
| `RecurringTaskService` | Gestion des tâches récurrentes |

### Méthodes principales de UniqueTaskService

| Méthode | Description |
|---------|-------------|
| `register($taskClass, $payload, $config = null)` | Enregistre une nouvelle tâche unique |
| `run($taskId)` | Exécute une tâche unique |
| `process($limit = null)` | Exécute toutes les tâches prêtes |
| `find($taskId)` | Trouve une tâche par son UUID |
| `findPending($limit = null)` | Récupère les tâches en attente |
| `findCompleted($limit = null)` | Récupère les tâches terminées |
| `findFailed($limit = null)` | Récupère les tâches en échec |
| `exists($taskId)` | Vérifie si une tâche existe |
| `delete($taskId)` | Supprime une tâche |
| `count()` | Compte le nombre total de tâches |
| `countPending()` | Compte les tâches en attente |
| `countCompleted()` | Compte les tâches terminées |
| `countFailed()` | Compte les tâches en échec |

### Méthodes principales de RecurringTaskService

| Méthode | Description |
|---------|-------------|
| `register($taskClass, $payload, $config)` | Enregistre une nouvelle tâche récurrente |
| `run($alias)` | Exécute une tâche récurrente |
| `process($limit = null)` | Exécute toutes les tâches prêtes |
| `pause($alias)` | Met une tâche en pause |
| `resume($alias)` | Reprend une tâche en pause |
| `finish($alias)` | Termine une tâche prématurément |
| `changeInterval($alias, $seconds)` | Modifie l'intervalle |
| `advanceStartAt($alias, $date)` | Avance la date de début |
| `postponeStartAt($alias, $date)` | Repousse la date de début |
| `find($alias)` | Trouve une tâche par son alias |
| `findWaiting($limit = null)` | Récupère les tâches en attente |
| `findPlaying($limit = null)` | Récupère les tâches actives |
| `findPaused($limit = null)` | Récupère les tâches en pause |
| `findFinished($limit = null)` | Récupère les tâches terminées |
| `exists($alias)` | Vérifie si une tâche existe |
| `delete($alias)` | Supprime une tâche |

---

## 🧪 Tests

### Configuration

```php
// tests/IntegrationTestCase.php
protected function getEnvironmentSetUp($app): void
{
    $app['config']->set('database.default', 'testbench');
    $app['config']->set('database.connections.testbench', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
}
```

### Exécution

```bash
composer test
```

---

## 📖 Voir aussi

- `andydefer/laravel-repository` - Pattern Repository pour Laravel
- `andydefer/laravel-logger` - Système de logging structuré
- `andydefer/php-records` - DTOs immutables

---

## 📄 Licence

MIT © [Andy Defer](https://github.com/andydefer)