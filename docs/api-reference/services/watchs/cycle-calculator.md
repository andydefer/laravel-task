# CycleCalculator - Référence Technique

## Description

Le `CycleCalculator` est un service utilitaire qui calcule et gère les cycles d'exécution pour les boucles de surveillance. Il détermine le nombre total de cycles, les temps d'attente et les conditions d'arrêt en fonction d'un intervalle et d'une durée configurés.

## Hiérarchie / Implémentations

```
CycleCalculator (final)
    └── Aucune interface implémentée
```

**Classe finale :** Ne peut pas être étendue

## Rôle principal

Ce service agit comme un calculateur de cycle pour les boucles d'exécution :

1. **Calcul du nombre total de cycles** basé sur l'intervalle et la durée
2. **Estimation de la durée totale** d'exécution
3. **Détermination des cycles restants**
4. **Conditions de continuation** (arrêt ou poursuite)
5. **Calcul du temps d'attente** entre les cycles

## API / Méthodes publiques

### `__construct(DurationVO $interval, ?DurationVO $duration = null)`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$interval` | `DurationVO` | Intervalle entre les cycles en secondes |
| `$duration` | `DurationVO|null` | Durée totale d'exécution (null = illimité) |

**Exemple :**
```php
<?php

use AndyDefer\Task\Services\Watchs\CycleCalculator;
use AndyDefer\Task\ValueObjects\DurationVO;

// Cycle toutes les 5 secondes pendant 30 secondes
$calculator = new CycleCalculator(
    new DurationVO(5),
    new DurationVO(30)
);

// Cycle toutes les 10 secondes indéfiniment
$infiniteCalculator = new CycleCalculator(
    new DurationVO(10)
);
```

---

### `getInterval(): DurationVO`

**Retourne :** `DurationVO` - L'intervalle configuré

**Exemple :**
```php
$interval = $calculator->getInterval();
echo "Intervalle : " . $interval->getValue() . "s\n";
```

---

### `getDuration(): ?DurationVO`

**Retourne :** `DurationVO|null` - La durée configurée ou null si illimitée

**Exemple :**
```php
$duration = $calculator->getDuration();
if ($duration === null) {
    echo "Exécution illimitée\n";
} else {
    echo "Durée : " . $duration->getValue() . "s\n";
}
```

---

### `getTotalCycles(): int`

**Retourne :** `int` - Nombre total de cycles à exécuter

**Comportement :**
- Si `duration` est null → retourne `PHP_INT_MAX` (illimité)
- Sinon, calcule : `floor(duration / interval) + 1`
- Le `+1` compense le premier cycle à t=0

**Exemple :**
```php
$calculator = new CycleCalculator(
    new DurationVO(3),
    new DurationVO(30)
);

echo "Cycles : " . $calculator->getTotalCycles() . "\n";
// Résultat : 11 (cycles à t=0, 3, 6, 9, 12, 15, 18, 21, 24, 27, 30)
```

**Schéma des cycles (interval=3s, duration=30s) :**
```
Cycle #1  : t=0s   ← start
Cycle #2  : t=3s
Cycle #3  : t=6s
Cycle #4  : t=9s
Cycle #5  : t=12s
Cycle #6  : t=15s
Cycle #7  : t=18s
Cycle #8  : t=21s
Cycle #9  : t=24s
Cycle #10 : t=27s
Cycle #11 : t=30s ← fin (duration atteinte)
```

---

### `getEstimatedDuration(): float`

**Retourne :** `float` - Durée estimée en secondes

**Comportement :**
- Si `duration` est null → retourne `PHP_FLOAT_MAX`
- Sinon : `(totalCycles - 1) * interval`
- La durée estimée est légèrement inférieure à la durée configurée

**Exemple :**
```php
$calculator = new CycleCalculator(
    new DurationVO(3),
    new DurationVO(30)
);

echo "Durée estimée : " . $calculator->getEstimatedDuration() . "s\n";
// Résultat : 30s (11 cycles × 3s = 30s)
```

---

### `getRemainingCycles(int $currentCycle): int`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$currentCycle` | `int` | Numéro du cycle actuel (commence à 1) |

**Retourne :** `int` - Nombre de cycles restants

**Comportement :**
- Calcule : `totalCycles - currentCycle`
- Ne retourne jamais une valeur négative

**Exemple :**
```php
$calculator = new CycleCalculator(
    new DurationVO(3),
    new DurationVO(30)
);

echo "Restant après cycle #5 : " . $calculator->getRemainingCycles(5) . "\n";
// Résultat : 6 (cycles #6 à #11)

echo "Restant après cycle #11 : " . $calculator->getRemainingCycles(11) . "\n";
// Résultat : 0
```

---

### `shouldContinue(int $currentCycle, bool $shouldStop): bool`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$currentCycle` | `int` | Numéro du cycle actuel |
| `$shouldStop` | `bool` | Signal d'arrêt externe (ex: Ctrl+C) |

**Retourne :** `bool` - `true` si l'exécution doit continuer

**Comportement :**
1. Si `$shouldStop` est `true` → retourne `false`
2. Si `$duration` est null → retourne `true` (illimité)
3. Sinon : `$currentCycle < $totalCycles`

**Exemple :**
```php
$calculator = new CycleCalculator(
    new DurationVO(3),
    new DurationVO(30)
);

// Boucle principale
$cycleNumber = 0;
while ($calculator->shouldContinue($cycleNumber, false)) {
    $cycleNumber++;
    echo "Cycle #$cycleNumber\n";
    
    // Exécuter les tâches...
    
    // Attendre avant le prochain cycle
    $waitTime = $calculator->getNextWaitTime($cycleNumber);
    sleep($waitTime->getValue());
}
// Affichera 11 cycles (1 à 11)
```

---

### `getNextWaitTime(int $currentCycle): DurationVO`

| Paramètre | Type | Description |
|-----------|------|-------------|
| `$currentCycle` | `int` | Numéro du cycle actuel |

**Retourne :** `DurationVO` - Temps d'attente avant le prochain cycle

**Comportement :**
- Si `duration` est null → retourne l'intervalle (illimité)
- Si `currentCycle < totalCycles` → retourne l'intervalle
- Sinon (dernier cycle) → retourne `DurationVO(0)`

**Exemple :**
```php
$calculator = new CycleCalculator(
    new DurationVO(3),
    new DurationVO(30)
);

echo "Attente après cycle #5 : " . $calculator->getNextWaitTime(5)->getValue() . "s\n";
// Résultat : 3s

echo "Attente après cycle #11 : " . $calculator->getNextWaitTime(11)->getValue() . "s\n";
// Résultat : 0s (dernier cycle)
```

---

## Cas d'utilisation

### Cas 1 : Boucle avec durée limitée

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Services\Watchs\CycleCalculator;
use AndyDefer\Task\ValueObjects\DurationVO;

$calculator = new CycleCalculator(
    new DurationVO(5),   // Toutes les 5 secondes
    new DurationVO(60)   // Pendant 60 secondes
);

$cycleNumber = 0;

while ($calculator->shouldContinue($cycleNumber, false)) {
    $cycleNumber++;
    
    echo "🔄 Cycle #{$cycleNumber} de " . $calculator->getTotalCycles() . "\n";
    echo "   Restant : " . $calculator->getRemainingCycles($cycleNumber) . " cycles\n";
    
    // Exécuter les tâches...
    // ...
    
    // Attendre avant le prochain cycle
    $waitTime = $calculator->getNextWaitTime($cycleNumber);
    if ($waitTime->getValue() > 0) {
        echo "   Attente : {$waitTime->getValue()}s\n";
        sleep($waitTime->getValue());
    }
}

echo "✅ Terminé après {$cycleNumber} cycles\n";
```

### Cas 2 : Boucle infinie avec gestion des signaux

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Services\Watchs\CycleCalculator;
use AndyDefer\Task\ValueObjects\DurationVO;

$calculator = new CycleCalculator(
    new DurationVO(10)  // Durée illimitée
);

$cycleNumber = 0;
$shouldStop = false; // Modifié par le gestionnaire de signaux

while ($calculator->shouldContinue($cycleNumber, $shouldStop)) {
    $cycleNumber++;
    
    echo "🔄 Cycle #{$cycleNumber} (illimité)\n";
    
    // Exécuter les tâches...
    // ...
    
    // Attendre 10 secondes
    $waitTime = $calculator->getNextWaitTime($cycleNumber);
    sleep($waitTime->getValue());
}

echo "⏹️ Arrêt demandé par l'utilisateur\n";
```

### Cas 3 : Estimation de la durée pour l'affichage

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Services\Watchs\CycleCalculator;
use AndyDefer\Task\ValueObjects\DurationVO;

$interval = new DurationVO(3);
$duration = new DurationVO(30);

$calculator = new CycleCalculator($interval, $duration);

$totalCycles = $calculator->getTotalCycles();
$estimatedDuration = $calculator->getEstimatedDuration();

echo "📊 Configuration :\n";
echo "   Intervalle : " . $interval->getValue() . "s\n";
echo "   Durée configurée : " . $duration->getValue() . "s\n";
echo "   Durée estimée : " . $estimatedDuration . "s\n";
echo "   Cycles : {$totalCycles}\n";

// Affichage de la progression
for ($cycle = 1; $cycle <= $totalCycles; $cycle++) {
    $remaining = $calculator->getRemainingCycles($cycle);
    $progress = round(($cycle / $totalCycles) * 100, 1);
    
    echo sprintf(
        "   Cycle #%2d | Progression: %5.1f%% | Restant: %d cycles\n",
        $cycle,
        $progress,
        $remaining
    );
}
```

**Sortie :**
```
📊 Configuration :
   Intervalle : 3s
   Durée configurée : 30s
   Durée estimée : 30s
   Cycles : 11
   Cycle # 1 | Progression:   9.1% | Restant: 10 cycles
   Cycle # 2 | Progression:  18.2% | Restant: 9 cycles
   Cycle # 3 | Progression:  27.3% | Restant: 8 cycles
   Cycle # 4 | Progression:  36.4% | Restant: 7 cycles
   Cycle # 5 | Progression:  45.5% | Restant: 6 cycles
   Cycle # 6 | Progression:  54.5% | Restant: 5 cycles
   Cycle # 7 | Progression:  63.6% | Restant: 4 cycles
   Cycle # 8 | Progression:  72.7% | Restant: 3 cycles
   Cycle # 9 | Progression:  81.8% | Restant: 2 cycles
   Cycle #10 | Progression:  90.9% | Restant: 1 cycles
   Cycle #11 | Progression: 100.0% | Restant: 0 cycles
```

## Flux de décision

```
┌─────────────────────────────────────────────────────────────────────┐
│                    CycleCalculator::shouldContinue()                │
└────────────────────────┬────────────────────────────────────────────┘
                         │
                         ▼
              ┌──────────┴──────────┐
              │                     │
              ▼                     ▼
   ┌──────────────────┐   ┌─────────────────────────────────────────┐
   │ shouldStop = true│   │ shouldStop = false                      │
   │ → Retourne false │   │ → Vérifier la durée                     │ 
   └──────────────────┘   └────────────────┬────────────────────────┘
                                           │
                                           ▼
                              ┌────────────┴────────────┐
                              │                         │
                              ▼                         ▼
                    ┌─────────────────┐     ┌─────────────────────────────┐
                    │ duration = null │     │ duration défini             │
                    │ → Retourne true │     │ → currentCycle < totalCycles│
                    └─────────────────┘     └─────────────────────────────┘
```

```
┌─────────────────────────────────────────────────────────────────────┐
│                    CycleCalculator::getNextWaitTime()               │
└────────────────────────┬────────────────────────────────────────────┘
                         │
                         ▼
              ┌──────────┴──────────┐
              │                     │
              ▼                     ▼
    ┌─────────────────┐   ┌─────────────────────────────────────────┐
    │ duration = null │   │ duration défini                         │
    │ → interval      │   │ → Vérifier la position dans le cycle    │
    └─────────────────┘   └────────────────┬────────────────────────┘
                                           │
                                           ▼
                              ┌────────────┴────────────┐
                              │                         │
                              ▼                         ▼
                    ┌─────────────────┐     ┌───────────────────────────┐
                    │ currentCycle <  │     │ currentCycle >=           │
                    │ totalCycles     │     │ totalCycles               │
                    │ → interval      │     │ → DurationVO(0)           │
                    └─────────────────┘     └───────────────────────────┘
```

## Gestion des erreurs

| Situation | Comportement | Explication |
|-----------|--------------|-------------|
| Intervalle > Durée | `shouldContinue()` faux après cycle #1 | La durée est inférieure à l'intervalle, un seul cycle s'exécute |
| Cycle négatif | `getRemainingCycles()` retourne total | Le paramètre est ignoré, traité comme 0 |
| Durée = 0 | `getTotalCycles()` retourne 1 | `floor(0/interval) + 1 = 1` |

## Performance

| Opération | Complexité | Description |
|-----------|-----------|-------------|
| `getTotalCycles()` | O(1) | Calcul simple |
| `getEstimatedDuration()` | O(1) | Calcul simple |
| `getRemainingCycles()` | O(1) | Soustraction |
| `shouldContinue()` | O(1) | Comparaison |
| `getNextWaitTime()` | O(1) | Retourne intervalle ou 0 |

**Aucun cache nécessaire** - Tous les calculs sont triviaux.

## Compatibilité

| Version | Support |
|---------|---------|
| PHP 8.1+ | ✅ Complet |
| PHP 8.0 | ✅ Complet |
| Tous environnements | ✅ |

## Exemple complet

```php
<?php

declare(strict_types=1);

use AndyDefer\Task\Services\Watchs\CycleCalculator;
use AndyDefer\Task\ValueObjects\DurationVO;

class WatchService
{
    private CycleCalculator $calculator;
    
    public function __construct(float $interval, ?float $duration = null)
    {
        $this->calculator = new CycleCalculator(
            new DurationVO($interval),
            $duration !== null ? new DurationVO($duration) : null
        );
    }
    
    public function run(): void
    {
        $cycleNumber = 0;
        $shouldStop = false; // À connecter au gestionnaire de signaux
        
        // Afficher la configuration
        $this->displayConfiguration();
        
        // Boucle principale
        while ($this->calculator->shouldContinue($cycleNumber, $shouldStop)) {
            $cycleNumber++;
            
            // Début du cycle
            $cycleStart = microtime(true);
            
            echo sprintf(
                "\n🔄 Cycle #%d/%d\n",
                $cycleNumber,
                $this->calculator->getTotalCycles() === PHP_INT_MAX ? '∞' : $this->calculator->getTotalCycles()
            );
            
            // Exécuter les tâches...
            $this->executeTasks();
            
            // Fin du cycle
            $cycleDuration = microtime(true) - $cycleStart;
            echo sprintf("   ⏱️  Cycle exécuté en %.2fs\n", $cycleDuration);
            
            // Temps restant estimé
            $remaining = $this->calculator->getRemainingCycles($cycleNumber);
            if ($remaining > 0 && $remaining < PHP_INT_MAX) {
                $estimatedRemaining = $remaining * $this->calculator->getInterval()->getValue();
                echo sprintf("   ⏳ Temps restant estimé : %.0fs\n", $estimatedRemaining);
            }
            
            // Attendre avant le prochain cycle
            $waitTime = $this->calculator->getNextWaitTime($cycleNumber);
            if ($waitTime->getValue() > 0) {
                echo sprintf("   💤 Attente : %.1fs\n", $waitTime->getValue());
                
                // Attente avec vérification des signaux
                $this->waitWithSignals($waitTime);
            }
        }
        
        echo "\n✅ Terminé après {$cycleNumber} cycles\n";
    }
    
    private function displayConfiguration(): void
    {
        echo "📊 Configuration :\n";
        echo "   Intervalle : " . $this->calculator->getInterval()->getValue() . "s\n";
        
        $duration = $this->calculator->getDuration();
        if ($duration !== null) {
            echo "   Durée : " . $duration->getValue() . "s\n";
            echo "   Cycles : " . $this->calculator->getTotalCycles() . "\n";
            echo "   Durée estimée : " . $this->calculator->getEstimatedDuration() . "s\n";
        } else {
            echo "   Durée : Illimitée\n";
            echo "   Cycles : ∞\n";
        }
        echo "\n";
    }
    
    private function executeTasks(): void
    {
        // Simuler l'exécution des tâches
        sleep(1);
    }
    
    private function waitWithSignals(DurationVO $waitTime): void
    {
        $seconds = $waitTime->getValue();
        $elapsed = 0.0;
        
        while ($elapsed < $seconds) {
            // Vérifier les signaux (Ctrl+C)
            pcntl_signal_dispatch();
            
            $sleepTime = min(0.1, $seconds - $elapsed);
            if ($sleepTime > 0) {
                usleep((int) ($sleepTime * 1000000));
            }
            
            $elapsed += $sleepTime;
        }
    }
}

// Utilisation
$service = new WatchService(5, 30);
$service->run();
```

## Voir aussi
- `DurationVO` - Value Object pour les durées
- `TasksWatchDirective` - Directive utilisant ce calculateur
- `ParallelExecutor` - Exécuteur de tâches parallèles