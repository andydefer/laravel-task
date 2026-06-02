Voici la version adaptée de ton prompt pour générer des tickets techniques à partir de l'analyse du code de `php-records`.

---

# 🎯 PROMPT COMPLET – Génération de tickets techniques (Issues) pour php-records

Agis en tant qu'**expert en analyse de code PHP** spécialisé dans les packages open-source, la sécurité des données et l'architecture propre.

Je vais te fournir le code source d'un package PHP (`php-records`) ainsi qu'une liste de **bugs, faiblesses et correctifs** identifiés.

Ta mission est de générer une **liste de tickets techniques (issues)** au format standardisé ci-dessous.

---

## 📁 NOM DU FICHIER

Propose un nom de fichier selon ce format :

```
TECH_DEBT_[DATE]_[COMPOSANT]_[TYPE].md
```

Exemples :
- `TECH_DEBT_2026-05-22_TypedCollection_TypeSafety.md`
- `TECH_DEBT_2026-05-22_AbstractRecord_Normalization.md`
- `TECH_DEBT_2026-05-22_Enumable_Utility.md`

---

## 🏷️ FORMAT DE SORTIE ATTENDU

```markdown
# [TITRE DU DOCUMENT] – Technical Debt & Issues

## 🔴 Tickets Critiques (Sécurité & Bugs Bloquants)

> Priorité : bloquante, sécurité, corruption de données

### TICKET-CRIT-001 : [Titre clair et actionnable - max 10 mots]
- **Fichier** : `src/Collections/TypedCollection.php`
- **Méthode** : `validateItem()`
- **Description** : [1-2 phrases expliquant le problème]
- **Impact** : [Sécurité, performance, maintenance, données]
- **Action corrective** : [Solution proposée]
- **Priorité** : 🔴 **Critique**

---

## 🟠 Tickets Majeurs (Fonctionnalités à risque)

> Priorité : performance, fiabilité, bugs fonctionnels

### TICKET-MAJ-001 : [Titre clair et actionnable - max 10 mots]
- **Fichier** : `src/Collections/TypedCollection.php`
- **Méthode** : `map()`
- **Description** : [1-2 phrases]
- **Impact** : [Description]
- **Action corrective** : [Solution]
- **Priorité** : 🟠 **Haute**

---

## 🟡 Tickets Mineurs (Améliorations & Clean Code)

> Priorité : refactoring, dette technique, documentation

### TICKET-MIN-001 : [Titre clair et actionnable - max 10 mots]
- **Fichier** : `src/Traits/ArrayableTrait.php`
- **Description** : [1-2 phrases]
- **Impact** : [Description]
- **Action corrective** : [Solution]
- **Priorité** : 🟡 **Basse**

---

## 📊 Résumé par priorité

| Priorité | Nombre de tickets |
|----------|-------------------|
| 🔴 Critique | X |
| 🟠 Haute | X |
| 🟡 Basse | X |

**Total** : X tickets

---

## 🚀 Suggestions pour la planification

**Sprint 1 (Sécurité & Fiabilité)** :
- TICKET-CRIT-XXX
- TICKET-CRIT-XXX

**Sprint 2 (Performance & Stabilité)** :
- TICKET-MAJ-XXX
- TICKET-MAJ-XXX

**Backlog (Refactoring & Dette technique)** :
- TICKET-MIN-XXX
- TICKET-MIN-XXX
```

---

## 🔍 CE QUE TU DOIS ANALYSER

Pour chaque composant du package `php-records`, identifie :

### Pour `TypedCollection`
- Validation des types à l'ajout (`validateItem()`)
- Gestion des énumérations (interdites)
- Gestion des objets arbitraires (uniquement `stdClass`, `AbstractRecord`, `TypedCollection`)
- Comportement de `map()` avec déduction automatique du type de retour
- Immuabilité des opérations
- Gestion des cas extrêmes (collection vide, null, types mixtes)

### Pour `AbstractRecord`
- Normalisation des types (`DateTimeInterface`, `UnitEnum`, `Traversable`)
- Conversion camelCase → snake_case
- Gestion récursive des Records imbriqués
- Comportement de `toDatabase()` (suppression des `null`)
- Gestion des `TypedCollection` vides

### Pour les collections utilitaires (`StringTypedCollection`, `IntTypedCollection`, etc.)
- Cohérence des méthodes
- Gestion des valeurs limites
- Comportement sur collection vide

### Pour `Enumable`
- Compatibilité avec `BackedEnum` et pure enums
- Gestion des valeurs inexistantes

### Pour `EmptyRecord`
- Comportement attendu (tableau vide)

### Pour `ArrayableTrait`
- Implémentation des interfaces (`ArrayAccess`, `IteratorAggregate`, `JsonSerializable`)
- Validation dans `offsetSet()`

---

## 🧠 CONTEXTE D'ANALYSE

- **Package** : `php-records` (andydefer/php-records)
- **PHP version** : 8.1+
- **Type** : Bibliothèque de structures de données typées
- **Public cible** : Développeurs PHP, projets Laravel ou non

---

## 🚫 RÈGLES

- Ne génère pas de tickets pour des problèmes inexistants
- Sois précis, direct et strict
- Propose des actions correctives concrètes
- Classe correctement la priorité (🔴 bloquant, 🟠 important, 🟡 amélioration)

---

## 📥 CODE À ANALYSER

[COLLER LE CODE PHP ICI]

---

## ✅ EXEMPLE DE TICKET BIEN RÉDIGÉ

```markdown
### TICKET-CRIT-001 : Les énumérations sont rejetées mais pas leurs valeurs scalaires
- **Fichier** : `src/Collections/TypedCollection.php`
- **Méthode** : `validateItem()`
- **Description** : `TypedCollection` interdit explicitement les énumérations (`UnitEnum`), mais un développeur pourrait vouloir stocker la valeur scalaire d'un `BackedEnum`.
- **Impact** : Le développeur doit appeler manuellement `$enum->value` avant d'ajouter, ce qui est source d'erreurs.
- **Action corrective** : Ajouter une méthode `addEnum()` qui accepte un `BackedEnum` et extrait automatiquement sa valeur, ou modifier `validateItem()` pour accepter les `BackedEnum` et les convertir silencieusement.
- **Priorité** : 🟠 **Haute**
```

---

## ▶️ DÉMARRAGE

Voici le code à analyser :