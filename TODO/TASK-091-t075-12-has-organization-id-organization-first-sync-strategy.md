---
task_id: TASK-091
title: t075-12-has-organization-id-organization-first-sync-strategy

status: DONE

owner: OPENCODE

contributors:
  - OPENCODE
  - OPENAI

branch: TASK-091-t075-12-has-organization-id-organization-first-sync-strategy

priority: MEDIUM

created_at: 2026-05-17 20:25:50 Europe/Paris
updated_at: 2026-05-17 21:00:00 Europe/Paris

labels:
  - organization-migration
  - has-organization-id
  - sync-strategy

lock:
  status: UNLOCKED
  agent: OPENCODE
  since: 2026-05-17 21:00:00 Europe/Paris

handoff: true

pr:
  status: NOT_READY
  url: null
---

# Objective

Clarify and test the HasOrganizationId trait strategy in Organization-first mode, without DB migration and without global refactor.

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] implement changes
- [x] run tests
- [ ] validate UI

---

# Progress Log

## 2026-05-17 21:00:00 Europe/Paris

### Architecture Inspection

#### Current HasOrganizationId behavior (BEFORE patch)

File: `app/Models/Traits/HasOrganizationId.php`

```php
public function syncOrganizationId(): void
{
    $this->organization_id = $this->community_id; // community_id est SOURCE
}
```

- On `creating`: always sets `organization_id = community_id`
- On `updating`: only if `community_id` dirty, sets `organization_id = community_id`

**Risks identifiés :**
1. `organization_id` est traité comme esclave de `community_id` — inversé par rapport à la cible
2. Si `organization_id` est défini mais pas `community_id`, `organization_id` est écrasé à null
3. Si les deux divergent, `community_id` gagne silencieusement (inversion dangereuse)
4. Sur update, un changement legacy `community_id` écrase silencieusement `organization_id`
5. Pas de null safety explicite

#### Modèles utilisant HasOrganizationId

| Modèle | Factory définit `community_id` ? |
|--------|----------------------------------|
| `User` | Non |
| `Service` | Non |
| `ServiceRequest` | Non |
| `Transaction` | Non |
| `BlogPost` | Pas de factory |
| `Referral` | Oui (via `forOrganization`) |
| `ReferralReward` | Oui (via `forOrganization`) |

#### Fichiers inspectés

- `app/Models/Traits/HasOrganizationId.php`
- `app/Models/User.php`
- `app/Models/Service.php`
- `app/Models/Scopes/BelongsToTenantScope.php`
- `database/migrations/2026_05_12_101622_add_organization_id_to_tables.php`
- `tests/Feature/OrganizationCompatibilityTest.php`
- `tests/Feature/BelongsToTenantScopeTest.php`
- `tests/Feature/CurrentOrganizationTest.php`
- `database/factories/*`

### Stratégie retenue — Organization-first sync

**Principe :** `organization_id` est la source canonique côté code. `community_id` reste une colonne DB legacy synchronisée automatiquement.

**Règles de synchronisation :**

Sur `creating` :
1. `organization_id` fourni → canonique, `community_id` backfillé
2. Seul `community_id` fourni (legacy) → `organization_id` backfillé
3. Les deux présents et identiques → no-op (déjà cohérent)
4. Les deux présents et divergents → `organization_id` gagne, `community_id` écrasé
5. Aucun fourni → null safety, skip

Sur `updating` :
1. `organization_id` dirty → canonique, sync `community_id`
2. Seul `community_id` dirty (legacy) → backfill `organization_id`
3. Les deux dirty → `organization_id` gagne
4. Aucun dirty → no-op

### Patch appliqué

`app/Models/Traits/HasOrganizationId.php` :
- `syncOrganizationId()` totalement réécrite avec logique Organization-first
- `bootHasOrganizationId()` updating trigger étendu pour capturer les changements `organization_id`
- Voir le fichier pour le détail complet

### Tests ajoutés

`tests/Feature/HasOrganizationIdTest.php` — 15 tests, 35 assertions :

**Creation :**
1. `test_creating_with_organization_id_syncs_community_id` ✓
2. `test_creating_with_community_id_legacy_backfills_organization_id` ✓
3. `test_creating_with_both_consistent` ✓
4. `test_creating_with_both_divergent_organization_wins` ✓ (test critique)
5. `test_creating_with_neither_set` ✓ (null safety)

**Update :**
6. `test_updating_organization_id_syncs_community_id` ✓
7. `test_updating_community_id_legacy_backfills_organization_id` ✓
8. `test_updating_both_divergent_organization_wins` ✓ (test critique)
9. `test_updating_neither_does_not_sync` ✓
10. `test_multiple_updates_maintain_consistency` ✓

**Null safety :**
11. `test_null_organization_id_does_not_clear_community_id_on_create` ✓
12. `test_null_community_id_does_not_clear_organization_id_on_create` ✓
13. `test_update_setting_organization_id_to_null_clears_community_id` ✓
14. `test_update_setting_community_id_to_null_clears_organization_id` ✓

**Edge case :**
15. `test_organization_id_is_not_overwritten_when_community_id_changes_on_create` ✓ (divergence critique)

### Résultats des tests

- HasOrganizationIdTest : 15/15 passed (35 assertions)
- OrganizationCompatibilityTest : 18/18 passed
- BelongsToTenantScopeTest : 14/14 passed
- CurrentOrganizationTest : 11/11 passed
- Full Feature suite : 649/649 passed (0 failures, 0 regressions)

### Dette technique restante

1. **Factories** : User, Service, ServiceRequest, Transaction ne définissent pas `community_id`/`organization_id` dans leur definition(). Ce n'est pas un bug (HasOrganizationId gère), mais les enregistrements créés via factories sans org explicite n'auront pas d'org assigné. À traiter si besoin par ticket dédié.
2. **BlogPost** : pas de factory. À créer si nécessaire.
3. **Migration DB dédiée** : la colonne `community_id` dans le code n'a plus de raison d'être à terme. Une future migration devrait :
   - Supprimer `community_id` des fillable
   - Remplacer les requêtes DB `community_id` → `organization_id`
   - Supprimer les relations `community()` (remplacées par `organization()`)
   - Nettoyer `BelongsToTenantScope` pour utiliser `organization_id` directement
4. **Referral/ReferralReward** : factories utilisent `forOrganization(Community $org)` — signature à migrer vers `Organization` à terme
5. **LoopFactory** : utilise `community_id` dans sa definition — à faire évoluer vers `organization_id`

---

# Handoffs

## Handoff vers future migration DB

Les points suivants nécessitent une migration DB dédiée (hors scope T075.12) :

1. Standardiser les factories pour utiliser `organization_id` comme valeur par défaut
2. Ajouter une contrainte NOT NULL sur `organization_id` après backfill
3. Nettoyer `community_id` des fillable des modèles
4. Remplacer `BelongsToTenantScope` pour utiliser `organization_id` au lieu de `community_id`
5. Supprimer les relations `community()` legacy
6. Mettre à jour `LoopFactory` pour utiliser `organization_id`

---

# Tests

- [x] HasOrganizationIdTest — 15 tests (35 assertions)
- [x] OrganizationCompatibilityTest — 18 tests
- [x] BelongsToTenantScopeTest — 14 tests
- [x] CurrentOrganizationTest — 11 tests
- [x] Full Feature suite — 649 tests (0 failures)
- [ ] browser validation (non applicable — trait uniquement)
- [ ] responsive validation (non applicable)
- [ ] console inspection (non applicable)

---

# Test Results

```
HasOrganizationIdTest     : 15 passed (35 assertions)
OrganizationCompatibilityTest : 18 passed
BelongsToTenantScopeTest  : 14 passed
CurrentOrganizationTest   : 11 passed
Full Feature suite        : 649 passed (1399 assertions)
```

**Total : 0 failures, 0 regressions.**

---

# Review Notes

## Questions résolues

1. **Que fait actuellement HasOrganizationId ?**
   → Il synchronise `organization_id = community_id` avec `community_id` comme source.

2. **Le trait synchronise-t-il community_id → organization_id ?**
   → Oui, c'était le comportement AVANT le patch.

3. **Le trait synchronise-t-il organization_id → community_id ?**
   → Non, pas avant le patch. C'est maintenant le cas (Organization-first).

4. **Quelle propriété doit être considérée comme source canonique côté code ?**
   → `organization_id`. C'est maintenant implémenté.

5. **Quels modèles utilisent ce trait ?**
   → User, Service, ServiceRequest, Transaction, BlogPost, Referral, ReferralReward.

6. **Quels cas peuvent créer une incohérence silencieuse ?**
   → Les deux colonnes divergentes (résolu : `organization_id` gagne). L'écrasement silencieux par `community_id` (résolu).

7. **Peut-on rendre la stratégie Organization-first sans migration DB ?**
   → Oui, c'est exactement ce qui a été fait. Aucune migration nécessaire.

8. **Qu'est-ce qui doit attendre une migration DB dédiée ?**
   → Voir dette technique ci-dessus : contrainte NOT NULL, suppression `community_id` des fillable, refactor `BelongsToTenantScope`.

## Fichiers modifiés

- `app/Models/Traits/HasOrganizationId.php` — patch Organization-first sync
- `tests/Feature/HasOrganizationIdTest.php` — nouveau fichier de test (15 tests)
- `TODO/TASK-091-t075-12-has-organization-id-organization-first-sync-strategy.md` — mise à jour

## Hors scope (non modifié)

- Pas de migration DB
- Pas de remplacement global `community_id` → `organization_id`
- Pas de changement contrôleur / API / Policy / Route / UI
- Pas de modification PROD
- Pas de changement Loop / ChatLoop

## OPENAI Review Verdict

- **Reviewer :** OPENAI / Codex GPT-5.5
- **Verdict :** APPROVE WITH NOTES
- **Blocking issues :** none

### Notes non bloquantes OPENAI (à documenter)

1. Le test qui valide la mise à null de `organization_id` et la propagation vers `community_id` verrouille un comportement sensible : un appel applicatif mal validé pourrait rendre un record tenantless si la colonne est mass-assignable. Ce n’est pas bloquant dans T075.12, mais cela doit rester une dette future de validation / fillable.

2. Le chemin "update `community_id` seul → backfill `organization_id`" est acceptable comme compat legacy, mais ne doit pas être interprété comme une source canonique produit.
