# RecurringTaskValidator - Référence Technique

## Description

Validateur pour les tâches récurrentes. Fournit des méthodes de validation pour déterminer si une tâche récurrente peut être exécutée, si elle est prête, expirée, doit passer en FINISHED, ou doit être exécutée à nouveau.

## Hiérarchie / Implémentations

```
RecurringTaskValidatorInterface
    └── RecurringTaskValidator
```

## Rôle principal

Assurer l'intégrité et la cohérence des tâches récurrentes en validant :
- L'éligibilité à l'exécution (`canRun`)
- La préparation à l'exécution (`isReadyToRun`)
- L'expiration (`isExpired`)
- La nécessité de passer en FINISHED (`shouldMoveToFinished`)
- La nécessité de ré-exécution (`shouldRunAgain`)

## API / Méthodes publiques

### `canRun(RecurringTaskRecord $record): bool`

Vérifie si une tâche peut être exécutée.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `RecurringTaskRecord` | Record de la tâche à valider |

**Retourne :** `bool` - `true` si la tâche peut être exécutée, `false` sinon

**Conditions :**
- Classe de tâche valide
- Statut = PLAYING
- Non expirée

**Exemple :**
```php
if ($validator->canRun($record)) {
    $runner->run($record);
}
```

---

### `isReadyToRun(RecurringTaskRecord $record): bool`

Vérifie si une tâche est prête à être exécutée (transition WAITING → PLAYING).

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `RecurringTaskRecord` | Record de la tâche à valider |

**Retourne :** `bool` - `true` si la tâche est prête, `false` sinon

**Conditions :**
- Classe de tâche valide
- Statut = WAITING
- `start_at` atteint (ou null)

**Exemple :**
```php
if ($validator->isReadyToRun($record)) {
    $repository->moveToPlaying($record);
}
```

---

### `isExpired(RecurringTaskRecord $record): bool`

Vérifie si une tâche est expirée (end_at dépassé).

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `RecurringTaskRecord` | Record de la tâche à valider |

**Retourne :** `bool` - `true` si la tâche est expirée, `false` sinon

**Conditions :**
- Classe de tâche valide
- `end_at` défini et dépassé

**Exemple :**
```php
if ($validator->isExpired($record)) {
    $repository->moveToFinished($record);
}
```

---

### `shouldMoveToFinished(RecurringTaskRecord $record): bool`

Détermine si une tâche doit passer en FINISHED.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `RecurringTaskRecord` | Record de la tâche à valider |

**Retourne :** `bool` - `true` si la tâche doit être marquée FINISHED

**Condition :** Identique à `isExpired()`

**Exemple :**
```php
if ($validator->shouldMoveToFinished($record)) {
    $repository->moveToFinished($record);
}
```

---

### `shouldRunAgain(RecurringTaskRecord $record): bool`

Détermine si une tâche doit être exécutée à nouveau.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `RecurringTaskRecord` | Record de la tâche à valider |

**Retourne :** `bool` - `true` si la tâche doit être exécutée à nouveau, `false` sinon

**Conditions :**
- Classe de tâche valide
- Statut = PLAYING
- Non expirée
- Intervalle atteint (ou jamais exécutée)

**Exemple :**
```php
if ($validator->shouldRunAgain($record)) {
    $runner->run($record);
}
```

---

### `getValidationErrors(RecurringTaskRecord $record): StringTypedCollection`

Retourne la liste des erreurs de validation pour une tâche.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$record` | `RecurringTaskRecord` | Record de la tâche à valider |

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

| Vérification | Condition d'échec |
|--------------|-------------------|
| Classe valide | `false` |
| Statut PLAYING | `false` |
| Non expirée | `false` |
| **Résultat** | `true` si toutes les conditions sont remplies |

### isReadyToRun()

| Vérification | Condition d'échec |
|--------------|-------------------|
| Classe valide | `false` |
| Statut WAITING | `false` |
| start_at atteint | `false` si start_at > now |
| **Résultat** | `true` si toutes les conditions sont remplies |

### isExpired()

| Vérification | Condition d'échec |
|--------------|-------------------|
| Classe valide | `false` |
| end_at défini | `false` si null |
| end_at dépassé | `true` si end_at < now |
| **Résultat** | `true` si expiré, `false` sinon |

### shouldRunAgain()

| Vérification | Condition d'échec |
|--------------|-------------------|
| Classe valide | `false` |
| Statut PLAYING | `false` |
| Non expirée | `false` |
| Intervalle atteint | `false` si last_run_at + interval > now |
| **Résultat** | `true` si toutes les conditions sont remplies |

## Messages d'erreur

| Situation | Message |
|-----------|---------|
| Classe invalide | `Invalid task class: {fqcn}` |
| Statut WAITING | `Task is in WAITING state, not PLAYING` |
| Statut PAUSED | `Task is in PAUSED state` |
| Statut FINISHED | `Task is already FINISHED` |
| Statut CANCELED | `Task is CANCELED` |
| Statut inconnu | `Task is in {status} state, not PLAYING` |
| Expiration | `Task has expired (end_at reached)` |
| Start_at non atteint | `Task is not ready to run (start_at not reached)` |

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
    $repository->moveToFinished($record);
    echo "Tâche expirée : {$record->alias->getValue()}\n";
}
```

---

### Cas 3 : Déterminer si une tâche doit s'exécuter

**Problème :** Vérifier si l'intervalle est atteint.

```php
if ($validator->shouldRunAgain($record)) {
    $runner->run($record);
} else {
    echo "Intervalle non atteint pour {$record->alias->getValue()}\n";
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
private function isValidTaskClass(RecurringTaskRecord $record): bool
{
    $className = $record->fqcn->getValue();
    
    // 1. La classe existe-t-elle ?
    if (!class_exists($className)) {
        return false;
    }
    
    // 2. Est-ce une sous-classe de AbstractRecurringTask ?
    if (!is_subclass_of($className, AbstractRecurringTask::class)) {
        return false;
    }
    
    return true;
}
```

## Intégration

### Dépendances

- Aucune dépendance externe (utilise uniquement Carbon pour les dates)

### Points d'utilisation

| Composant | Utilisation |
|-----------|-------------|
| `RecurringTaskRunner` | Validation avant exécution |
| `RecurringTaskProcessor` | Filtrage des tâches |
| `RecurringTaskRepository` | Validation des transitions |

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

use AndyDefer\Task\Validators\RecurringTaskValidator;
use AndyDefer\Task\Records\RecurringTaskRecord;
use AndyDefer\Task\Enums\RecurringTaskStatus;
use AndyDefer\Task\ValueObjects\DurationVO;
use AndyDefer\Task\ValueObjects\Iso8601DateTimeVO;
use AndyDefer\Task\ValueObjects\TaskAliasVO;
use AndyDefer\Task\ValueObjects\RecurringTaskFqcnVO;
use Illuminate\Support\Carbon;

$validator = new RecurringTaskValidator();

// Création d'un record de test
$record = RecurringTaskRecord::from([
    'alias' => new TaskAliasVO('recurring@test'),
    'fqcn' => new RecurringTaskFqcnVO(MyRecurringTask::class),
    'interval_seconds' => new DurationVO(3600),
    'start_at' => new Iso8601DateTimeVO(Carbon::now()->subHour()->toIso8601String()),
    'end_at' => new Iso8601DateTimeVO(Carbon::now()->addDay()->toIso8601String()),
    'status' => RecurringTaskStatus::PLAYING,
    'last_run_at' => new Iso8601DateTimeVO(Carbon::now()->subMinutes(30)->toIso8601String()),
]);

// Validation complète
if ($validator->canRun($record)) {
    echo "✅ La tâche peut être exécutée\n";
}

if ($validator->shouldRunAgain($record)) {
    echo "✅ La tâche doit être exécutée à nouveau\n";
}

// Vérification d'expiration
if ($validator->isExpired($record)) {
    echo "❌ La tâche est expirée\n";
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

- `UniqueTaskValidator` - Validateur de tâches uniques
- `RecurringTaskRunner` - Exécuteur de tâches récurrentes
- `RecurringTaskProcessor` - Processeur de lots
- `RecurringTaskStatus` - États des tâches
---