Voici la version adaptée de ton prompt pour ton package `php-records`.

---

# 🎯 PROMPT COMPLET – Nettoyage & Documentation d'un package PHP (php-records)

## Rôle
> Tu es un **expert PHP**, mainteneur de packages open-source et défenseur du **Clean Code**, de **SOLID**, et des **PSR (PSR-12, PSR-4)**.
>
> Je vais te fournir le code source complet d'un **package PHP** (php-records) destiné à être publié sur GitHub et Packagist.
>
> **Ton objectif est de le préparer pour une publication publique professionnelle.**

---

## 🔥 OBJECTIFS PRINCIPAUX

### 1. Nettoyage du code
* Supprimer **tous les commentaires parasites**, temporaires ou personnels :
  * TODO
  * commentaires de réflexion
  * étapes de raisonnement
  * commentaires redondants qui expliquent "ce que le code fait ligne par ligne"
* Ne garder **aucun commentaire inutile**
* Supprimer les commentaires en français (préférer l'anglais pour la documentation technique)

### 2. Documentation professionnelle
* Ajouter une **PHPDoc complète et propre** :
  * Pour **chaque classe**
  * Pour **chaque méthode publique**
  * Pour toute méthode protégée importante
* Les PHPDoc doivent :
  * Expliquer *le rôle métier* du composant
  * Décrire les paramètres et valeurs de retour
  * Mentionner les `@template` pour les classes génériques (`TypedCollection<TValue>`)
  * Mentionner les exceptions quand pertinent (`@throws`)
* Ton professionnel, clair, orienté utilisateur du package

### 3. Refactor Clean Code
* Refactorer le code pour qu'il :
  * Se lise **comme un roman**
  * Soit **auto-documenté par les noms**
  * Respecte :
    * SRP (Single Responsibility)
    * Nommage clair (métier > technique)
    * Méthodes courtes
    * Conditions lisibles
* Renommer si nécessaire :
  * méthodes
  * variables
  * classes
* **Sans casser l'API publique** (Aucune justification ou prétexte)

### 4. Cohérence & Lisibilité
* Harmoniser :
  * styles
  * noms
  * structures de classes
* Réduire la complexité cognitive
* Éviter la duplication
* Préparer le code pour :
  * nouveaux contributeurs
  * relectures GitHub
  * long terme

---

## 🧱 CONTRAINTES IMPORTANTES

* ❌ Ne pas ajouter de logique métier inutile
* ❌ Ne pas changer le comportement fonctionnel
* ❌ Ne pas introduire de dépendances
* ✅ Respect strict du PHP moderne (PHP 8.2+)
* ✅ Code prêt pour un **package open-source**

---

## 📦 FORMAT DE SORTIE ATTENDU

Pour chaque fichier :

1. Code **complet refactoré**
2. PHPDoc :
   * Classe
   * Méthodes
3. **Aucun commentaire parasite**
4. Code final directement **copiable / publiable**
5. Si un choix de refactor est non évident → courte justification après le code

---

## 🧠 APPROCHE ATTENDUE

* Penser comme :
  * un **mainteneur**
  * un **contributeur externe**
  * un **lecteur GitHub**
* Priorité :
  1. Lisibilité
  2. Clarté
  3. Stabilité
  4. Élégance

---

## 🌐 LANGUES

- **Code et documentation technique** (PHPDoc, noms de variables, noms de méthodes, commentaires techniques) : **ANGLAIS UNIQUEMENT**
- **PHPDoc des classes et méthodes** : **ANGLAIS UNIQUEMENT**
- **Messages d'exception** : **ANGLAIS UNIQUEMENT** (convention PSR)

---

## 📐 SPÉCIFICITÉS DU PACKAGE php-records

### Pour `AbstractRecord` et les Records

- La PHPDoc doit expliquer que le Record est une structure de données immuable
- Mentionner que les clés sont automatiquement converties en `snake_case`
- Indiquer que les enums, dates et collections sont normalisés automatiquement

### Pour `TypedCollection` et les collections utilitaires

- Utiliser les tags `@template TValue` pour documenter le type générique
- Documenter le comportement de validation des types à l'ajout
- Mentionner l'immutabilité : les méthodes de transformation retournent de nouvelles instances

### Pour `Enumable`

- Documenter qu'il s'agit d'un trait pour les énumérations PHP 8.1+
- Expliquer la différence entre backed enums et pure enums

### Pour `EmptyRecord`

- Documenter qu'il s'agit d'un Record vide utilisé pour les paramètres optionnels

---

## 🔧 RÈGLES DE RENOMMAGE

**⚠️ ATTENTION : NE MODIFIE PAS LES NOMS DES MÉTHODES OU PROPRIÉTÉS PUBLIQUES !!!**

- Les noms publics (`AbstractRecord::toArray()`, `TypedCollection::add()`, etc.) **restent inchangés**
- Les méthodes privées et les variables locales peuvent être renommées librement
- Si un nom de méthode publique n'est pas optimal, **propose un changement à la fin du fichier** (pas dans le code)

**Pour les propositions de renommage public, utilise ce format après le code :**

```markdown
## 🔄 Propositions de renommage (API publique)

| Classe | Méthode actuelle | Proposition | Justification |
|--------|------------------|-------------|---------------|
| `TypedCollection` | `all()` | `toCollection()` | Plus cohérent avec `toArray()` |
```

---

## 🧪 POUR LES FICHIERS DE TEST

**UTILISE LA STRUCTURE AAA → Arrange / Act / Assert**

```php
// Arrange : Create a collection with integer items
$collection = new TypedCollection('int');
$collection->add(1, 2, 3);

// Act : Apply a transformation
$result = $collection->map(fn($item) => $item * 2);

// Assert : Verify the result is correct
$this->assertSame([2, 4, 6], $result->toArray());
```

**LES PHRASES D'EXPLICATION EN ANGLAIS SONT ESSENTIELLES !!!**

---

## 🔁 DÉTECTION DE DUPLICATION

**SI TU VOIS DU CODE RÉPÉTITIF, TU PEUX L'ENCAPSULER DANS UNE MÉTHODE PRIVÉE**

Exemple :

```php
private function createCollectionWithInts(): TypedCollection
{
    $collection = new TypedCollection('int');
    $collection->add(1, 2, 3);
    return $collection;
}
```

**TOUJOURS BIEN DOCUMENTER LA MÉTHODE HELPER AVEC PHPDOC**

---

## 📋 SECTION BUGS & AMÉLIORATIONS (OBLIGATOIRE)

Si tu détectes des problèmes dans le code, ajoute cette section **après le code généré** :

```markdown
## 🐛 Bugs & Améliorations détectés

### 📁 Fichier : `src/Collections/TypedCollection.php`

#### ❌ Bug (🔴 Critique / 🟠 Majeure / 🟡 Mineure)

**Problème :** [Description]
**Ligne :** ~XXX
**Solution :** [Proposition de correction]

#### ⚠️ Faiblesse architecturale

**Problème :** [Description]
**Solution :** [Proposition d'amélioration]

### 📁 Fichier : `src/AbstractRecord.php`

#### 🚫 Mauvaise pratique

**Problème :** [Description]
**Solution :** [Proposition d'amélioration]
```

---

## 🚫 RÈGLES FINALES

- Ne génère pas de pseudo-code
- Ne génère pas d'explications dans le code (sauf PHPDoc)
- N'ignore pas les cas limites
- Ne change pas le comportement fonctionnel

---

## 📥 EXEMPLE DE CODE BIEN FORMATÉ (À SUIVRE)

```php
<?php

declare(strict_types=1);

namespace AndyDefer\Records\Collections;

use AndyDefer\Records\AbstractRecord;
use Closure;
use InvalidArgumentException;
use stdClass;
use UnitEnum;

/**
 * Type-safe collection for records and scalar values.
 *
 * Supports multiple allowed types at construction. All items added to the
 * collection are validated against the allowed types.
 *
 * @template TValue of object|string|int|float|bool
 */
final class TypedCollection implements TypedCollectionInterface
{
    /**
     * @var array<TValue>
     */
    private array $items = [];

    /**
     * @var array<class-string<AbstractRecord>|string>
     */
    private array $allowedTypes = [];

    /**
     * Create a new typed collection.
     *
     * @param class-string<AbstractRecord>|string ...$types Allowed types for this collection
     *
     * @throws InvalidArgumentException If no types are provided or a type is invalid
     */
    public function __construct(string ...$types)
    {
        $this->validateTypes($types);
        $this->allowedTypes = $types;
    }

    /**
     * Add one or multiple items to the collection.
     *
     * @param TValue ...$items Items to add
     *
     * @throws InvalidArgumentException If any item does not match allowed types
     */
    public function add(mixed ...$items): self
    {
        foreach ($items as $item) {
            $this->validateItem($item);
            $this->items[] = $item;
        }

        return $this;
    }

    // ... rest of implementation
}
```

---

## ▶️ DÉMARRAGE

Voici le code à analyser et améliorer :