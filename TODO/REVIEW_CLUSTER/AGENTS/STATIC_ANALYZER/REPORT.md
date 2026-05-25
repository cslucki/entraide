# REPORT — STATIC_ANALYZER

**Agent** : STATIC_ANALYZER  
**Date** : 2026-05-25  
**Scope** : T140.5A-D Static Analysis

## Objectif

PHPStan sur T140.5 code, détection de problèmes de typage, détection de bugs potentiels.

## Analyse

L'analyse statique a été effectuée sur les fichiers T140.5 modifiés :

- `app/Services/LoopService.php`
- `app/Services/LoopMessageService.php`
- `app/Services/ReferralService.php`
- `app/Services/RewardDispatcher.php`
- `app/Http/Controllers/LoopController.php`
- `app/Models/User.php`
- `routes/channels.php`
- `app/Http/Middleware/ResolveApiOrganization.php`

## PHPStan Results

**Total** : 10 erreurs détectées

### Erreurs par fichier

#### LoopController.php (2 erreurs)
- Ligne 30 : Méthode `resolveCommunity()` devrait retourner `App\Models\Community` mais retourne `Illuminate\Database\Eloquent\Model` (return.type)
- Ligne 39 : Méthode `resolveCommunity()` devrait retourner `App\Models\Community` mais retourne `Illuminate\Database\Eloquent\Model` (return.type)

#### LoopService.php (1 erreur)
- Ligne 101 : Paramètre #2 `$user` de la méthode `addMember()` attend `App\Models\User`, mais reçoit `Illuminate\Database\Eloquent\Model` (argument.type)

#### ReferralService.php (2 erreurs)
- Ligne 29 : Accès à une propriété non définie `App\Models\User::$organization_id` (property.notFound)
- Ligne 33 : Accès à une propriété non définie `App\Models\User::$organization_id` (property.notFound)

#### RewardDispatcher.php (5 erreurs)
- Ligne 27 : Accès à une propriété non définie `App\Models\User::$organization_id` (property.notFound)
- Ligne 31 : Accès à une propriété non définie `App\Models\User::$organization_id` (property.notFound)
- Ligne 80 : Paramètre #2 `$user` de la méthode `award()` attend `App\Models\User`, mais reçoit `Illuminate\Database\Eloquent\Model|null` (argument.type)
- Ligne 109 : Paramètre #2 `$user` de la méthode `award()` attend `App\Models\User`, mais reçoit `Illuminate\Database\Eloquent\Model|null` (argument.type)
- Ligne 127 : Paramètre #2 `$user` de la méthode `award()` attend `App\Models\User`, mais reçoit `Illuminate\Database\Eloquent\Model|null` (argument.type)

## Rector Dry-Run Results

**Avertissement** : Rector n'a aucune règle configurée.

```
[WARNING] Register rules or sets in your "rector.php" config
```

**Note** : Le fichier `rector.php` ne contient que la configuration de base (chemins, version PHP) mais aucune règle de transformation n'est activée. Ceci est intentionnel pour éviter toute modification automatique du code.

## Findings

### Critique (Urgent)

1. **Typage Eloquent incomplet** (7 erreurs)
   - Problème : Eloquent retourne `Illuminate\Database\Eloquent\Model` au lieu du type spécifique attendu
   - Impact : Perte de sécurité des types, bugs potentiels non détectés
   - Fichiers concernés : `LoopController.php` (2), `LoopService.php` (1), `RewardDispatcher.php` (3)

2. **Propriété `$organization_id` non déclarée** (4 erreurs)
   - Problème : PHPStan ne détecte pas la propriété `organization_id` sur `User`
   - Hypothèse : La propriété existe en DB mais n'est pas explicitement typée dans le modèle
   - Impact : Analyse statique incomplète, mais probablement pas de bug runtime
   - Fichiers concernés : `ReferralService.php` (2), `RewardDispatcher.php` (2)

### Style (Laravel Pint)

**Fichiers avec violations Pint** :

1. **LoopService.php** (3 violations)
   - `concat_space` : Espacement incorrect dans concaténation de chaînes
   - `unary_operator_spaces` : Espacement incorrect autour d'opérateurs unaires
   - `not_operator_with_successor_space` : Espacement après l'opérateur `!`

2. **User.php** (4 violations)
   - `fully_qualified_strict_types` : Utilisation excessive de FQCN (Fully Qualified Class Names)
   - `unary_operator_spaces` : Espacement incorrect autour d'opérateurs unaires
   - `not_operator_with_successor_space` : Espacement après l'opérateur `!`
   - `ordered_imports` : Imports non ordonnés

### Outil Rector

**Statut** : Non utilisable pour l'audit

- Configuration incomplète (aucune règle activée)
- Intentionnel pour éviter modifications automatiques
- Peut être utilisé pour détecter du code mort ou améliorer la qualité, mais nécessite configuration

## Recommandations

### Priorité 1 (Critique - Typage Eloquent)

**Action recommandée** : Corriger les types Eloquent manquants

1. **LoopController.php** : Lignes 30, 39
   ```php
   return $community; // Devrait être typé explicitement
   ```
   
2. **LoopService.php** : Ligne 101
   ```php
   $this->addMember($loop, $user); // $user est-il bien de type User ?
   ```

3. **RewardDispatcher.php** : Lignes 80, 109, 127
   ```php
   $this->award($loop, $user); // Vérifier le type de $user
   ```

**Approche** :
- Ajouter des casts PHPDoc sur les méthodes Eloquent concernées
- Utiliser `@return static` sur les méthodes chainées
- Vérifier les relations Eloquent pour garantir les types corrects

### Priorité 2 (Typage propriété organization_id)

**Action recommandée** : Documenter explicitement la propriété `organization_id`

1. **User.php** : Ajouter une propriété explicite
   ```php
   public int $organization_id; // ou via PHPDoc
   ```

**Approche** :
- Vérifier si `organization_id` est dans `$fillable`
- Ajouter un cast PHPDoc pour PHPStan
- Considérer d'utiliser un trait pour les models tenant-scoped

### Priorité 3 (Style Laravel Pint)

**Action recommandée** : Corriger les violations Pint

1. **LoopService.php** : 3 violations mineures
2. **User.php** : 4 violations mineures (dont `fully_qualified_strict_types` qui pourrait réduire la verbosité)

**Approche** :
- Exécuter `vendor/bin/pint` pour corriger automatiquement
- Vérifier que les corrections n'introduisent pas de bugs
- Considérer de configurer Pint avec des règles plus strictes

### Priorité 4 (Configuration Rector)

**Action recommandée** : Configurer Rector pour la détection de code mort

1. **Activer les règles de détection de code mort**
2. **Activer les règles de qualité de code**
3. **Laisser désactivé** les refactors automatiques (types, readonly, visibility)

**Approche** :
- Ajouter des règles comme `Rector\CodeQuality\Rector\Class_\InlineConstructorDefaultToPropertyRector`
- Ajouter des règles de dead code detection
- Garder `--dry-run` comme option par défaut

## Prochaines étapes

1. **Prioriser** les corrections de typage Eloquent (Priority 1)
2. **Vérifier** la compatibilité runtime avant toute correction
3. **Tester** les corrections sur un environnement de développement
4. **Documenter** les décisions d'architecture dans les TASK files
5. **Coordiner** avec TENANT_SAFETY_REVIEWER pour vérifier l'impact sur l'isolation tenant