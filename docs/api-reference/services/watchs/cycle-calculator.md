# CycleCalculator - Référence Technique

## Description

Calcule le timing des cycles pour les boucles de surveillance des tâches. Détermine le nombre de cycles, la durée estimée et la décision de continuer en fonction de l'intervalle et de la durée totale configurés.

## Hiérarchie

```
CycleCalculator
```

## Rôle principal

Fournir des calculs temporels pour les boucles de surveillance : nombre total de cycles, durée estimée, cycles restants, et temps d'attente entre les cycles.

Un cycle s'exécute à t=0, t=intervalle, t=2*intervalle, etc. Le nombre total de cycles est `(durée / intervalle) + 1` (pour inclure le cycle initial).

---

## API / Méthodes publiques

### `__construct(DurationVO $interval, ?DurationVO $duration = null)`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$interval` | `DurationVO` | Temps entre les cycles en secondes |
| `$duration` | `DurationVO|null` | Durée totale d'exécution, ou `null` pour illimité |

**Exemple :**
```php
$interval = new DurationVO(3);
$duration = new DurationVO(30);
$calculator = new CycleCalculator($interval, $duration);
```

---

### `getInterval(): DurationVO`

Retourne l'intervalle entre les cycles.

| Paramètre | Type | Description |
|-----------|------|-------------|
| Aucun | - | - |

**Retourne :** `DurationVO` - L'intervalle en secondes

**Exemple :**
```php
$interval = $calculator->getInterval(); // 3s
```

---

### `getDuration(): ?DurationVO`

Retourne la durée totale d'exécution.

| Paramètre | Type | Description |
|-----------|------|-------------|
| Aucun | - | - |

**Retourne :** `DurationVO|null` - La durée, ou `null` si illimité

**Exemple :**
```php
$duration = $calculator->getDuration(); // 30s ou null
```

---

### `getTotalCycles(): int`

Calcule le nombre total de cycles.

**Formule :** `floor(duration / interval) + 1`

**Exemple :**
- Intervalle = 3s, Durée = 30s
- Cycle #1: t=0s
- Cycle #2: t=3s
- ...
- Cycle #10: t=27s
- Cycle #11: t=30s
- **Total = 11 cycles**

| Paramètre | Type | Description |
|-----------|------|-------------|
| Aucun | - | - |

**Retourne :** `int` - Nombre total de cycles, ou `PHP_INT_MAX` si illimité

**Exemple :**
```php
$totalCycles = $calculator->getTotalCycles(); // 11
```

---

### `getEstimatedDuration(): float`

Calcule la durée totale estimée d'exécution.

Cette durée est la durée requise pour compléter tous les cycles. Elle peut être légèrement inférieure à la durée configurée en raison de l'arrondi inférieur.

| Paramètre | Type | Description |
|-----------|------|-------------|
| Aucun | - | - |

**Retourne :** `float` - Durée estimée en secondes, ou `PHP_FLOAT_MAX` si illimité

**Exemple :**
```php
$estimated = $calculator->getEstimatedDuration(); // 30s
```

---

### `getRemainingCycles(int $currentCycle): int`

Calcule le nombre de cycles restants.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$currentCycle` | `int` | Numéro du cycle actuel (1-indexé) |

**Retourne :** `int` - Nombre de cycles restants (minimum 0)

**Exemple :**
```php
$remaining = $calculator->getRemainingCycles(5); // 6 cycles restants
```

---

### `shouldContinue(int $currentCycle, bool $shouldStop): bool`

Détermine si la boucle de surveillance doit continuer.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$currentCycle` | `int` | Numéro du cycle actuel (1-indexé) |
| `$shouldStop` | `bool` | Si un signal d'arrêt a été reçu |

**Retourne :** `bool` - `true` si la boucle doit continuer

**Exemple :**
```php
if ($calculator->shouldContinue($cycleNumber, $signalHandler->shouldStop())) {
    // Continuer l'exécution
}
```

---

### `getNextWaitTime(int $currentCycle): DurationVO`

Calcule le temps d'attente avant le prochain cycle.

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$currentCycle` | `int` | Numéro du cycle actuel (1-indexé) |

**Retourne :** `DurationVO` - Temps d'attente en secondes, ou 0 si c'est le dernier cycle

**Exemple :**
```php
$waitTime = $calculator->getNextWaitTime(5); // 3s
$waitTime = $calculator->getNextWaitTime(11); // 0s (dernier cycle)
```

---

## Cas d'utilisation

### Cas 1 : Surveillance avec durée limitée

```php
$interval = new DurationVO(5);
$duration = new DurationVO(60);
$calculator = new CycleCalculator($interval, $duration);

$cycleNumber = 0;
while ($calculator->shouldContinue($cycleNumber, false)) {
    $cycleNumber++;
    echo "Cycle #{$cycleNumber}\n";
    
    // Exécuter les tâches...
    
    $waitTime = $calculator->getNextWaitTime($cycleNumber);
    if ($waitTime->getValue() > 0) {
        sleep($waitTime->getValue());
    }
}
// Sortie : Cycles 1 à 13
```

### Cas 2 : Surveillance illimitée

```php
$interval = new DurationVO(10);
$calculator = new CycleCalculator($interval); // Durée = null

$cycleNumber = 0;
while ($calculator->shouldContinue($cycleNumber, $signalHandler->shouldStop())) {
    $cycleNumber++;
    echo "Cycle #{$cycleNumber}\n";
    
    // Exécuter les tâches...
    
    // Attend l'intervalle
    sleep($calculator->getInterval()->getValue());
}
```

### Cas 3 : Affichage des statistiques

```php
$interval = new DurationVO(3);
$duration = new DurationVO(30);
$calculator = new CycleCalculator($interval, $duration);

echo "📊 Statistiques des cycles:\n";
echo "  Intervalle: " . $calculator->getInterval()->getValue() . "s\n";
echo "  Durée: " . $calculator->getDuration()->getValue() . "s\n";
echo "  Cycles totaux: " . $calculator->getTotalCycles() . "\n";
echo "  Durée estimée: " . $calculator->getEstimatedDuration() . "s\n";

// À mi-parcours
$currentCycle = 5;
echo "  Cycles restants: " . $calculator->getRemainingCycles($currentCycle) . "\n";
echo "  Prochain cycle dans: " . $calculator->getNextWaitTime($currentCycle)->getValue() . "s\n";
```

### Cas 4 : Arrêt propre

```php
$calculator = new CycleCalculator(new DurationVO(5), new DurationVO(60));
$cycleNumber = 0;
$shouldStop = false;

// Signal handler pour Ctrl+C
pcntl_signal(SIGINT, function() use (&$shouldStop) {
    $shouldStop = true;
});

while ($calculator->shouldContinue($cycleNumber, $shouldStop)) {
    $cycleNumber++;
    
    // Exécuter les tâches...
    
    $waitTime = $calculator->getNextWaitTime($cycleNumber);
    if ($waitTime->getValue() > 0) {
        // Attente avec vérification des signaux
        $seconds = $waitTime->getValue();
        $elapsed = 0;
        while ($elapsed < $seconds) {
            pcntl_signal_dispatch();
            if ($shouldStop) {
                break;
            }
            usleep(100000);
            $elapsed += 0.1;
        }
    }
}
```

---

## Flux d'exécution

```
1. Création avec interval et duration
   ↓
2. getTotalCycles() calcule floor(duration / interval) + 1
   ↓
3. getEstimatedDuration() = (totalCycles - 1) * interval
   ↓
4. shouldContinue() vérifie cycle < totalCycles
   ↓
5. getNextWaitTime() retourne interval ou 0
```

---

## Gestion des erreurs

| Situation | Comportement |
|-----------|--------------|
| `$duration` = null | `getTotalCycles()` → `PHP_INT_MAX` |
| `$duration` = null | `getEstimatedDuration()` → `PHP_FLOAT_MAX` |
| `$duration` = null | `shouldContinue()` → `true` (sauf si stop) |
| `$duration` = null | `getNextWaitTime()` → `interval` |
| `$duration` < interval | `getTotalCycles()` → 1 (minimum) |

---

## Performance

| Opération | Complexité | Description |
|-----------|------------|-------------|
| `getTotalCycles()` | O(1) | Calcul simple |
| `getEstimatedDuration()` | O(1) | Calcul simple |
| `getRemainingCycles()` | O(1) | Soustraction |
| `shouldContinue()` | O(1) | Comparaison |
| `getNextWaitTime()` | O(1) | Comparaison |

---

## Compatibilité

| Version | Support |
|---------|---------|
| PHP 8.1+ | ✅ Complet |
| PHP 8.2+ | ✅ Complet |

---

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Services\Watchs\CycleCalculator;
use AndyDefer\Task\ValueObjects\DurationVO;

// 1. Création du calculateur
$interval = new DurationVO(10);
$duration = new DurationVO(120); // 2 minutes
$calculator = new CycleCalculator($interval, $duration);

// 2. Affichage des informations
echo "📊 Cycle Calculator\n";
echo "====================\n";
echo "Intervalle: " . $calculator->getInterval()->getValue() . "s\n";
echo "Durée: " . $calculator->getDuration()->getValue() . "s\n";
echo "Cycles totaux: " . $calculator->getTotalCycles() . "\n";
echo "Durée estimée: " . $calculator->getEstimatedDuration() . "s\n";
echo "\n";

// 3. Simulation des cycles
$cycleNumber = 0;
echo "🔄 Simulation des cycles:\n";
while ($calculator->shouldContinue($cycleNumber, false)) {
    $cycleNumber++;
    
    $remaining = $calculator->getRemainingCycles($cycleNumber);
    $waitTime = $calculator->getNextWaitTime($cycleNumber);
    
    echo "  Cycle #{$cycleNumber}";
    echo " (reste: {$remaining} cycles, ";
    echo "prochain cycle dans: " . $waitTime->getValue() . "s)\n";
    
    // Simuler l'exécution
    usleep(100000);
}

echo "\n✅ Simulation terminée après {$cycleNumber} cycles\n";
```

---

## Voir aussi

- `DurationVO` - Value Object pour les durées
- `ResultAggregator` - Agrégateur de résultats
- `TasksWatchDirective` - Directive de surveillance