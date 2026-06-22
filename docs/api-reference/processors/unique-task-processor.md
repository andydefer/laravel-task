# UniqueTaskProcessor - Référence Technique

## Description

Processeur de tâches uniques qui orchestre l'exécution d'un lot de tâches en une seule fois. Il récupère les tâches prêtes, les valide, les exécute et gère les expirations.

## Hiérarchie / Implémentations

```
UniqueTaskProcessorInterface
    └── UniqueTaskProcessor
```

## Rôle principal

Ce processeur est le cœur du traitement des tâches uniques. Il :

1. **Récupère** les tâches prêtes (`findReadyToRun`)
2. **Valide** chaque tâche avant exécution (`validator->canRun`)
3. **Orchestre** l'exécution via le `UniqueTaskRunner`
4. **Gère** les tâches expirées (grace period dépassée)
5. **Agrège** les résultats et les erreurs

## API

### `process(?int $limit = null): ProcessResultRecord`

Point d'entrée principal du processeur.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$limit` | `?int` | Nombre maximum de tâches à exécuter (`null` = illimité) |

**Retourne :** `ProcessResultRecord` - Résultat du traitement (succès, échecs, finitions)

**Exemple :**
```php
$processor = new UniqueTaskProcessor($repository, $runner, $validator);
$result = $processor->process(10);
```

---

### `modelToRecord(UniqueTask $model): UniqueTaskRecord`

Convertit un modèle Eloquent en Record DTO.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$model` | `UniqueTask` | Modèle Eloquent à convertir |

**Retourne :** `UniqueTaskRecord` - DTO de la tâche

**Exemple :**
```php
$model = UniqueTask::find('550e8400-e29b-41d4-a716-446655440000');
$record = $processor->modelToRecord($model);
// $record est un UniqueTaskRecord immuable
```

## Cas d'utilisation

### Cas 1 : Traitement standard des tâches uniques

```php
$processor = app(UniqueTaskProcessor::class);
$result = $processor->process();

echo "Succès: {$result->success->value}\n";
echo "Échecs: {$result->failed->value}\n";
```

### Cas 2 : Traitement avec limite

```php
// Traiter uniquement les 5 premières tâches
$result = $processor->process(5);
```

### Cas 3 : Tâche invalidée par le validator

```php
// Une tâche avec attempts = max_attempts
// Le validator la rejette → la tâche est marquée FAILED
// L'erreur "Validation failed: Maximum attempts reached" est enregistrée
```

### Cas 4 : Tâche expirée

```php
// Une tâche avec scheduled_at = now - 48h, grace_period = 3600 (1h)
// Le processeur la détecte et la marque FAILED
// L'erreur "Task expired" est enregistrée
```

## Flux d'exécution

```
┌─────────────────────────────────────────────────────────────────────┐
│                    UniqueTaskProcessor                             │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  ÉTAPE 1 : Récupérer les tâches prêtes                            │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  findReadyToRun(date('c'))                                  │   │
│  │  → Collection<UniqueTask> (modèles Eloquent)               │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  ÉTAPE 2 : Appliquer la limite                                     │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  if ($limit !== null) { $tasks = $tasks->take($limit); }   │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  ÉTAPE 3 : Exécuter chaque tâche                                   │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  Pour chaque tâche :                                        │   │
│  │  ├─ modelToRecord($task) → UniqueTaskRecord                │   │
│  │  │                                                          │   │
│  │  ├─ validator->canRun($taskRecord) ?                       │   │
│  │  │  ├─ NON → moveToFailed() + error                       │   │
│  │  │  └─ OUI → runner->run($taskRecord)                     │   │
│  │  │                                                          │   │
│  │  └─ runner->run() → ExecutionResultRecord                  │   │
│  │     ├─ success → success++                                 │   │
│  │     └─ failure → failed++ + error                          │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  ÉTAPE 4 : Traiter les tâches expirées                            │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  findExpired(date('c')) → Collection<UniqueTask>           │   │
│  │  Pour chaque tâche :                                        │   │
│  │  ├─ modelToRecord($task) → UniqueTaskRecord                │   │
│  │  ├─ validator->isExpired($taskRecord) ?                    │   │
│  │  └─ moveToFailed($taskRecord) + error                      │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                              │                                      │
│                              ▼                                      │
│  SORTIE : ProcessResultRecord                                      │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │  success: nombre de succès                                  │   │
│  │  failed: nombre d'échecs (validations + expirations)       │   │
│  │  finished: toujours 0 (tâches uniques)                     │   │
│  │  errors: collection des erreurs                             │   │
│  └─────────────────────────────────────────────────────────────┘   │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

## Gestion des erreurs

### Cas de validation échouée

| Condition | Action | Erreur |
|-----------|--------|--------|
| Statut ≠ PENDING | `moveToFailed()` | `Validation failed: Task is not in PENDING state` |
| `attempts >= max_attempts` | `moveToFailed()` | `Validation failed: Maximum attempts reached` |
| Tâche expirée | `moveToFailed()` | `Validation failed: Task has expired` |
| `scheduled_at > now` | `moveToFailed()` | `Validation failed: Task is not ready to run` |

### Cas d'exécution échouée

| Situation | Action | Erreur |
|-----------|--------|--------|
| Exception dans le runner | `moveToFailed()` | Message de l'exception |
| Erreur d'instanciation | `moveToFailed()` | `Failed to instantiate task: ...` |

### Cas d'expiration

| Condition | Action | Erreur |
|-----------|--------|--------|
| `now > scheduled_at + grace_period` | `moveToFailed()` | `Task expired` |

## Validation avant exécution

```php
// Le validator vérifie 4 conditions
if (! $this->validator->canRun($taskRecord)) {
    // 1. Statut PENDING
    // 2. scheduled_at <= now
    // 3. attempts < max_attempts
    // 4. non expiré (grace_period)
    
    // Récupération des erreurs détaillées
    $errors = $this->validator->getValidationErrors($taskRecord);
    // Exemple: "Task is not in PENDING state, Maximum attempts reached"
}
```

## Performance

- **Complexité** : O(n) où n = nombre de tâches récupérées
- **Mémoire** : Les tâches sont chargées en mémoire via les collections
- **Base de données** : 2 requêtes (`findReadyToRun`, `findExpired`) + requêtes pour les mises à jour
- **Limite** : Permet de contrôler la charge

## Compatibilité

| Version | Support |
|---------|---------|
| PHP 8.1+ | ✅ Complet |
| Laravel 10+ | ✅ Complet |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Processors\UniqueTaskProcessor;
use AndyDefer\Task\Records\ProcessResultRecord;

// Récupérer le processeur
$processor = app(UniqueTaskProcessor::class);

// Exécuter avec limite de 10 tâches
$result = $processor->process(10);

// Afficher les résultats
echo "Traitement terminé.\n";
echo "✅ Succès: {$result->success->value}\n";
echo "❌ Échecs: {$result->failed->value}\n";

// Afficher les erreurs
foreach ($result->errors as $error) {
    echo "Erreur: {$error->identifier} - {$error->error}\n";
    if ($error->context) {
        echo "  Contexte: {$error->context}\n";
    }
}

// Vérifier le statut global
$hasErrors = $result->failed->value > 0;
echo $hasErrors ? "⚠️ Des erreurs sont survenues" : "✅ Tout s'est bien passé";
```

## Voir aussi

- `UniqueTaskRunner` - Exécuteur de tâches uniques
- `UniqueTaskValidator` - Validation des tâches
- `UniqueTaskRepository` - Accès aux données
- `ProcessResultRecord` - Structure de résultat
- `RecurringTaskProcessor` - Processeur pour les tâches récurrentes