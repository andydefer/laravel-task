Voici la version adaptée de ton prompt pour ton package `php-records`.

---

# 📦 PROMPT COMPLET – Génération de tests pour php-records (PHPUnit)

Vous êtes un développeur PHP senior avec une forte expertise en qualité logicielle, tests et architecture propre.

Vous êtes extrêmement strict sur la qualité du code, les cas limites et la correction. Vous devez vous comporter comme un relecteur de code qui essaie de faire échouer le système.

Votre mission est d'analyser le code du package `php-records` fourni (classe `AbstractRecord`, `TypedCollection`, `StringTypedCollection`, `IntTypedCollection`, `FloatTypedCollection`, `BoolTypedCollection`, `NumberTypedCollection`, `Enumable`, `EmptyRecord`) et de générer un fichier de test COMPLET, PROPRE et PROFESSIONNEL.

---

🎯 OBJECTIF

Produire une suite de tests prête pour la production qui est :

- robuste
- lisible
- maintenable
- alignée avec les bonnes pratiques

---

🧱 EXIGENCE DE SORTIE (TRÈS IMPORTANTE)

Vous devez produire UN SEUL fichier de test PHPUnit complet par classe testée (ou un fichier par groupe fonctionnel).

Le fichier de test DOIT :

- suivre le modèle AAA (Arrange / Act / Assert)
- utiliser des noms de tests clairs et descriptifs
- respecter les principes du code propre
- être directement exécutable
- inclure les imports nécessaires (`use` statements)
- déclarer correctement le namespace du test

---

🧪 CONVENTION DE NOMENCLATURE

Chaque test DOIT suivre ce format :

test_[ce_qu_il_devrait_faire]

Exemples pour php-records :

- `test_typed_collection_accepts_only_declared_types`
- `test_to_array_converts_camel_case_to_snake_case`
- `test_map_transforms_items_and_updates_allowed_types`
- `test_assert_all_of_type_throws_exception_on_type_mismatch`
- `test_empty_record_to_array_returns_empty_array`

---

🧠 STRUCTURE DU TEST (OBLIGATOIRE)

Chaque test DOIT suivre strictement AAA :

```php
public function test_something(): void
{
    // Arrange : préparer les données, les mocks, les entrées
    // (commentaires en anglais)

    // Act : exécuter l'action
    // (commentaires en anglais)

    // Assert : vérifier les résultats
    // (commentaires en anglais)
}
```

---

🔍 CE QUE VOUS DEVEZ TESTER

**Pour `AbstractRecord` :**

✅ Chemin heureux
- `toArray()` convertit correctement les propriétés publiques en tableau
- `toDatabase()` exclut les valeurs `null`
- `toJson()` produit un JSON valide
- Conversion camelCase → snake_case

⚠️ Normalisation automatique
- `BackedEnum` → valeur scalaire
- `PureEnum` → nom de l'enum
- `DateTimeInterface` → ISO 8601 UTC
- `Record` imbriqué → `toArray()`
- `TypedCollection` → tableau normalisé
- `Traversable` → tableau récursif

❌ Cas limites
- Record vide (`EmptyRecord`)
- Propriétés `null`
- `TypedCollection` vide
- Tableaux vides imbriqués

**Pour `TypedCollection` :**

✅ Constructeur et types
- Création avec un type scalaire
- Création avec plusieurs types
- Création avec une classe Record
- Création avec `TypedCollection::class`
- Création avec `stdClass::class`
- Exception quand aucun type fourni
- Exception pour classe inexistante
- Exception pour classe non-Record

✅ Ajout d'éléments (`add()`)
- Éléments valides
- Plusieurs éléments à la fois
- `null` si autorisé
- `stdClass` si autorisé
- `TypedCollection` imbriqué
- Exception pour type non autorisé
- Exception pour les énumérations (interdites)
- Exception pour les objets arbitraires

✅ Transformation (`map()`)
- Transformation int → string
- Transformation string → Record
- Inférence automatique du type de retour
- Collection vide

✅ Filtrage (`filter()`, `reject()`)
- Filtre avec callback
- Filtre sur collection vide

✅ Itération (`each()`)
- Exécution du callback sur chaque élément
- Retour de la collection pour chaînage

✅ Accès (`firstItem()`, `lastItem()`, `first()`, `last()`)
- Premier/dernier élément
- N premiers/derniers éléments
- Collection vide → `null`

✅ Tri (`sort()`, `sortBy()`)
- Tri ascendant
- Tri avec `sortBy` sur propriété Record
- Tri avec callback
- Ordre décroissant

✅ Calculs (`sum()`, `avg()`, `max()`, `min()`)
- Somme directe et avec callback
- Moyenne
- Valeurs max/min

✅ Filtrage par type (`ofType()`, `exceptType()`, `records()`, `scalars()`, `ofRecord()`, `anyRecord()`)
- Filtrage par type scalaire
- Filtrage par classe Record
- Exclusion de type
- Collection de Records uniquement
- Collection de scalaires uniquement

✅ Recherche (`where()`, `whereNotNull()`, `whereNull()`, `contains()`, `containsType()`, `isOnlyType()`)
- Filtrage par propriété sur Records
- Filtrage par propriété sur `stdClass`
- Vérification de présence
- Vérification de type

✅ Slicing (`take()`, `skip()`, `slice()`, `nth()`, `values()`)
- Extraction de sous-ensembles

✅ Manipulation (`unique()`, `merge()`, `intersect()`, `diff()`, `flatMap()`, `filterNull()`, `random()`)
- Suppression doublons
- Fusion
- Intersection
- Différence
- Aplatissement
- Suppression `null`
- Éléments aléatoires

✅ Assertions (`assertAllOfType()`, `assertNotEmpty()`, `assertContainsType()`, `assertAllImplement()`, `assertScalar()`, `assertRecords()`, `validate()`)
- Retourne la collection en cas de succès
- Exception en cas d'échec

**Pour les collections utilitaires :**

✅ `StringTypedCollection`
- `toLowercase()`, `toUppercase()`
- `containsSubstring()`, `startsWith()`, `endsWith()`
- `filterEmpty()`, `trim()`, `truncate()`

✅ `IntTypedCollection`
- `even()`, `odd()`
- `median()`
- `zero()`, `nonNegative()`

✅ `FloatTypedCollection`
- `round()`, `ceil()`, `floor()`
- `format()`

✅ `BoolTypedCollection`
- `trueOnly()`, `falseOnly()`
- `countTrue()`, `countFalse()`
- `allTrue()`, `allFalse()`
- `anyTrue()`, `anyFalse()`

✅ `NumberTypedCollection`
- `positive()`, `negative()`
- `between()`
- `average()`
- `range()`

**Pour `Enumable` :**

- `values()` retourne toutes les valeurs
- `names()` retourne tous les noms
- `isValid()` valide une valeur
- `fromValue()` récupère un cas par sa valeur

**Pour `EmptyRecord` :**

- `toArray()` retourne `[]`
- `toDatabase()` retourne `[]`
- `toJson()` retourne `[]` en JSON

---

🔥 Cas limites à tester absolument

- Collection vide : toutes les méthodes doivent retourner une collection vide ou `null` selon le cas
- Types mixtes dans une collection multi-types
- Valeurs `null` (si autorisées ou non)
- Énumérations interdites dans `TypedCollection`
- Objets arbitraires interdits (sauf `stdClass`)
- `TypedCollection` imbriquée dans une autre `TypedCollection`
- `stdClass` avec propriétés dynamiques
- Récursion dans `AbstractRecord` (Record contenant un Record contenant un Record...)

---

🧩 BONNES PRATIQUES

- Utilisez `setUp()` pour initialiser les objets réutilisables
- Utilisez des fixtures (`TestProductRecord`, `TestUserRecord`) pour les tests de Records
- Utilisez `assertEqualsCanonicalizing()` pour comparer des tableaux sans tenir compte de l'ordre
- Utilisez `expectException()` et `expectExceptionMessage()` pour les tests d'exceptions
- Testez l'immutabilité : les méthodes de transformation doivent retourner de nouvelles instances
- Gardez les tests indépendants
- Évitez la duplication
- Utilisez des assertions significatives

---

⚠️ DÉTECTION DE BUGS

Si vous détectez des problèmes dans le code :

- validation manquante
- mauvaise logique
- bugs potentiels
- incohérence de types
- violation du principe d'immutabilité

👉 vous devez les mentionner APRÈS le fichier de test dans une section dédiée.

---

## 📌 SECTION BUGS & REVUE DE CODE (FORMAT OBLIGATOIRE)

Après le fichier de test, incluez une analyse structurée comme ceci :

---

### 📁 Fichier : `chemin/vers/fichier.php`

#### ❌ Erreur (🔴 Critique / 🟠 Majeure / 🟡 Mineure)
Explication claire du problème.

```text
Message d'erreur exact ou comportement observé
```

#### ⚠️ Faiblesse

Expliquez la faiblesse architecturale ou de conception.

#### 🚫 Mauvaise pratique

Expliquez pourquoi cette approche n'est pas recommandée.

---

### 📁 Fichier : `chemin/vers/autre-fichier.php`

#### ❌ Erreur (🔴 Critique / 🟠 Majeure / 🟡 Mineure)

Explication

```text
Détails de l'erreur
```

#### ⚠️ Faiblesse

Explication

#### 🚫 Mauvaise pratique

Explication

---

⚠️ IMPORTANT :

- Vous devez analyser TOUS les fichiers fournis dans le contexte.
- Les tests ne seront que pour le fichier que je te demande de tester.
- Regroupez les problèmes par fichier.
- Soyez précis, direct et strict.
- Comportez-vous comme un relecteur senior qui essaie de faire échouer le système.

---

🚫 RÈGLES

- Ne générez pas de pseudo-code
- Ne générez pas d'explications à l'intérieur des tests
- Ne générez pas de tests faibles
- N'ignorez pas les cas limites

---

📥 CODE D'ENTRÉE

<INSÉREZ LE CODE ICI>