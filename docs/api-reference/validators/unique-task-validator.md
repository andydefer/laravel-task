# UniqueTaskValidator - Référence Technique

## Description

Validateur pour les tâches uniques. Fournit des méthodes de validation pour déterminer si une tâche unique peut être exécutée, si elle est prête, expirée, ou si elle a atteint le nombre maximum de tentatives.

## Hiérarchie / Implémentations

```
UniqueTaskValidatorInterface
    └── UniqueTaskValidator
```

## Rôle principal

Assurer l'intégrité et la cohérence des tâches uniques en validant :
- L'éligibilité à l'exécution (`canRun`)
- La préparation à l'exécution (`isReadyToRun`)
- L'expiration (`isExpired`)
- Le dépassement des tentatives maximales (`hasReachedMaxAttempts`)

## API / Méthodes publiques

### `canRun(UniqueTaskRecord $record): bool`

Vérifie si une tâche peut être exécutée.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `UniqueTaskRecord` | Record de la tâche à valider |

**Retourne :** `bool` - `true` si la tâche peut être exécutée, `false` sinon

**Conditions :**
- Classe de tâche valide
- Prête à être exécutée (`isReadyToRun`)
- Max attempts non atteint
- Non expirée

**Exemple :**
```php
if ($validator->canRun($record)) {
    $runner->run($record);
}
```

---

### `isReadyToRun(UniqueTaskRecord $record): bool`

Vérifie si une tâche est prête à être exécutée.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `UniqueTaskRecord` | Record de la tâche à valider |

**Retourne :** `bool` - `true` si la tâche est prête, `false` sinon

**Conditions :**
- Classe de tâche valide
- Statut = PENDING
- `scheduled_at` atteint

**Exemple :**
```php
if ($validator->isReadyToRun($record)) {
    echo "La tâche est prête à être exécutée\n";
}
```

---

### `isExpired(UniqueTaskRecord $record): bool`

Vérifie si une tâche est expirée (scheduled_at + grace_period dépassé).

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `UniqueTaskRecord` | Record de la tâche à valider |

**Retourne :** `bool` - `true` si la tâche est expirée, `false` sinon

**Calcul :**
```
expiration = scheduled_at + grace_period_seconds
expirée si now > expiration
```

**Exemple :**
```php
if ($validator->isExpired($record)) {
    $repository->moveToFailed($record);
}
```

---

### `hasReachedMaxAttempts(UniqueTaskRecord $record): bool`

Vérifie si le nombre maximum de tentatives est atteint.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `UniqueTaskRecord` | Record de la tâche à valider |

**Retourne :** `bool` - `true` si max attempts atteint, `false` sinon

**Condition :**
```
attempts >= max_attempts
```

**Exemple :**
```php
if ($validator->hasReachedMaxAttempts($record)) {
    $repository->moveToFailed($record);
}
```

---

### `getValidationErrors(UniqueTaskRecord $record): StringTypedCollection`

Retourne la liste des erreurs de validation pour une tâche.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `UniqueTaskRecord` | Record de la tâche à valider |

**Retourne :** `StringTypedCollection` - Collection des messages d'erreur

**Exemple :**
```php
$errors = $validator->getValidationErrors($record);
if ($errors->count() > 0) {
    echo "Erreurs : " . $errors->join(', ');
}
```

## Règles de validation

### canRun()

| Vérification | Méthode | Condition d'échec |
|--------------|---------|-------------------|
| Classe valide | `isValidTaskClass()` | `false` |
| Prête à exécuter | `isReadyToRun()` | `false` |
| Max attempts atteint | `hasReachedMaxAttempts()` | `true` |
| Expirée | `isExpired()` | `true` |
| **Résultat** | - | `true` si toutes les conditions sont remplies |

### isReadyToRun()

| Vérification | Condition d'échec |
|--------------|-------------------|
| Classe valide | `false` |
| Statut PENDING | `false` |
| scheduled_at ≤ now | `false` si scheduled_at > now |
| **Résultat** | `true` si toutes les conditions sont remplies |

### isExpired()

| Vérification | Calcul |
|--------------|--------|
| Classe valide | `false` si invalide |
| Expiration | `scheduled_at + grace_period_seconds` |
| **Résultat** | `true` si now > expiration, `false` sinon |

### hasReachedMaxAttempts()

| Vérification | Calcul |
|--------------|--------|
| Attempts | `record->attempts->getValue()` |
| Max attempts | `record->max_attempts->getValue()` |
| **Résultat** | `true` si attempts >= max_attempts, `false` sinon |

## Messages d'erreur

| Situation | Message |
|-----------|---------|
| Classe invalide | `Invalid task class: {fqcn} does not exist or does not extend AbstractUniqueTask` |
| Statut non PENDING | `Task is in {status} state, not PENDING` |
| Max attempts atteint | `Maximum attempts reached` |
| Tâche expirée | `Task has expired` |
| Pas prête | `Task is not ready to run (scheduled_at in the future)` |

## Cas d'utilisation

### Cas 1 : Validation avant exécution

**Problème :** Vérifier qu'une tâche peut être exécutée.

```php
if (!$validator->canRun($record)) {
    $errors = $validator->getValidationErrors($record);
    throw new RuntimeException($errors->join(', '));
}

$runner->run($record);
```

---

### Cas 2 : Vérification d'expiration

**Problème :** Nettoyer les tâches expirées.

```php
if ($validator->isExpired($record)) {
    $repository->moveToFailed($record);
    echo "Tâche expirée : {$record->alias->getValue()}\n";
}
```

---

### Cas 3 : Vérification des tentatives

**Problème :** Vérifier si une tâche peut être réessayée.

```php
if ($validator->hasReachedMaxAttempts($record)) {
    $repository->moveToFailed($record);
    echo "Max attempts atteint pour {$record->alias->getValue()}\n";
} else {
    // Incrémenter les tentatives et réessayer
    $newAttempts = $record->attempts->increment();
    $repository->updateAttempts($record, $newAttempts);
}
```

---

### Cas 4 : Validation détaillée pour débogage

**Problème :** Comprendre pourquoi une tâche ne s'exécute pas.

```php
$errors = $validator->getValidationErrors($record);
if ($errors->count() > 0) {
    echo "=== Erreurs de validation ===\n";
    foreach ($errors as $error) {
        echo "- {$error}\n";
    }
}
```

## Validation de la classe

La méthode `isValidTaskClass()` vérifie :

```php
private function isValidTaskClass(UniqueTaskRecord $record): bool
{
    $className = $record->fqcn->getValue();
    
    // 1. La classe existe-t-elle ?
    if (!class_exists($className)) {
        return false;
    }
    
    // 2. Est-ce une sous-classe de AbstractUniqueTask ?
    if (!is_subclass_of($className, AbstractUniqueTask::class)) {
        return false;
    }
    
    return true;
}
```

## Schéma de validation

```
canRun()
    │
    ├── isValidTaskClass()
    │   ├── class_exists() ?
    │   └── is_subclass_of() ?
    │
    ├── isReadyToRun()
    │   ├── status = PENDING ?
    │   └── scheduled_at ≤ now ?
    │
    ├── hasReachedMaxAttempts()
    │   └── attempts ≥ max_attempts ?
    │
    └── isExpired()
        └── now > scheduled_at + grace_period ?
```

## Intégration

### Dépendances

- Aucune dépendance externe (utilise uniquement Carbon pour les dates)

### Points d'utilisation

| Composant | Utilisation |
|-----------|-------------|
| `UniqueTaskRunner` | Validation avant exécution |
| `UniqueTaskProcessor` | Filtrage des tâches |
| `UniqueTaskRepository` | Validation des transitions |

## Performance

- **Complexité** : O(1) - calculs simples
- **Mémoire** : Aucune allocation mémoire significative
- **Recommandation** : Peut être appelé fréquemment sans impact

## Compatibilité

| Version PHP | Support |
|-------------|---------|
| PHP 8.2+ | ✅ Complet |
| PHP 8.1 | ✅ Complet |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Validators\UniqueTaskValidator;
use AndyDefer\Task\Records\UniqueTaskRecord;
use AndyDefer\Task\Enums\UniqueTaskStatus;
use AndyDefer\Task\ValueObjects\CounterVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\UniqueTaskFqcnVO;
use AndyDefer\Task\ValueObjects\UuidVO;
use Illuminate\Support\Carbon;

$validator = new UniqueTaskValidator();

// Création d'un record de test
$record = UniqueTaskRecord::from([
    'id' => new UuidVO('550e8400-e29b-41d4-a716-446655440000'),
    'alias' => new TaskAliasVO('unique@test'),
    'fqcn' => new UniqueTaskFqcnVO(MyUniqueTask::class),
    'scheduled_at' => new Iso8601DateTimeVO(Carbon::now()->subHour()->toIso8601String()),
    'grace_period_seconds' => 86400,
    'status' => UniqueTaskStatus::PENDING,
    'attempts' => new CounterVO(1),
    'max_attempts' => new CounterVO(3),
]);

// Validation complète
if ($validator->canRun($record)) {
    echo "✅ La tâche peut être exécutée\n";
}

// Vérification d'expiration
if ($validator->isExpired($record)) {
    echo "❌ La tâche est expirée\n";
}

// Vérification des tentatives
if ($validator->hasReachedMaxAttempts($record)) {
    echo "⚠️ Max attempts atteint\n";
}

// Récupération des erreurs
$errors = $validator->getValidationErrors($record);
if ($errors->count() > 0) {
    echo "⚠️ Erreurs de validation :\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}
```

## Voir aussi

- `RecurringTaskValidator` - Validateur de tâches récurrentes
- `UniqueTaskRunner` - Exécuteur de tâches uniques
- `UniqueTaskProcessor` - Processeur de lots
- `UniqueTaskStatus` - États des tâches
---