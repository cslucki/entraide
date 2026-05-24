---
task_id: TASK-141
title: Bascule BelongsToTenantScope vers organization_id

status: IN_PROGRESS

owner: OPCODE

contributors: []

branch: TASK-141-bascule-belongstotenantscope-vers-organization-id

priority: MEDIUM

created_at: 2026-05-24 20:08:00 Europe/Paris
updated_at: 2026-05-24 20:08:00 Europe/Paris

labels: []

lock:
  status: LOCKED
  agent: OPCODE
  since: 2026-05-24 20:08:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Basculer la clause WHERE de `BelongsToTenantScope` de `community_id` vers `organization_id`.
Les 7+1 tables scopées ont déjà `organization_id` backfillé (T140.2 pour loops, migration
`2026_05_12_101622` pour les autres). Aucune modification de controller, middleware,
service ou route.

---

# Implementation Plan

## 1. Pre-flight checks (sous-agents A/B/C/D en parallèle)

### Sous-agent A — DB state verifier (read-only)

```bash
php artisan tinker --execute="
\$tables = ['users','services','service_requests','transactions','blog_posts','referrals','referral_rewards','loops'];
\$driver = DB::connection()->getDriverName();

foreach (\$tables as \$t) {
    \$total = DB::table(\$t)->count();
    \$null  = DB::table(\$t)->whereNull('organization_id')->count();

    if (\$driver === 'pgsql') {
        \$drift = DB::table(\$t)
            ->whereRaw('organization_id IS DISTINCT FROM community_id')
            ->count();
    } else {
        \$drift = DB::table(\$t)
            ->whereRaw('
                (organization_id IS NULL AND community_id IS NOT NULL)
                OR (organization_id IS NOT NULL AND community_id IS NULL)
                OR (organization_id <> community_id)
            ')
            ->count();
    }

    echo \"\$t: total=\$total, null=\$null, drift=\$drift\n\";
}
"
```

**Critère GO/NO-GO :** un seul `null > 0` OU un seul `drift > 0` → STOP. Reporter à Cyril.

Vérifier aussi : `HasOrganizationId.php` trait actif, `organization_id` dans `$fillable` de tous les modèles scopés.

### Sous-agent B — Scope usage auditor (read-only)

Inspecter :
- `app/Models/Scopes/BelongsToTenantScope.php`
- `app/Support/Tenancy/CurrentOrganization.php`
- Tous les `withoutGlobalScope(BelongsToTenantScope::class)` (HomeController, TransactionController, AdminReferralController, etc.)

Livrer : fichier:ligne des bypass, colonne utilisée dans requêtes manuelles, modèles avec `booted()` scope.

### Sous-agent C — Tests auditor (read-only)

Inspecter :
- `tests/Feature/T1392LegacyCharacterizationTest.php` — caractérisation scope `community_id`
- `tests/Feature/T1392KnownRisksTest.php` — known-risk à unskip
- `tests/Feature/Scopes/BelongsToTenantScopeTest.php`
- `tests/Feature/Livewire/T126ExplorerTenantScopingTest.php`

Livrer : liste des tests à inverser, known-risks à unskip, commande validation.

### Sous-agent D — Gates & smoke reviewer (read-only)

Inspecter :
- `ai/scripts/smoke-critical-routes.sh`
- `ai/workflows/tenant-safety.md`
- `tests/Feature/T1392RouteSmokeGatesTest.php`

Livrer : routes critiques passant par le scope, risque régression invisible, recommandation assertions contenu.

## 2. Synthèse — `_temp/T140.1-pre-flight.md`

- Outputs des 4 sous-agents
- GO/NO-GO explicite
- Plan exact des modifications
- Validation gates prévues

## 3. Code modification

### 3a. Créer le test divergent (AVANT de modifier le scope)

`tests/Feature/T1401ScopeDivergentCaseTest.php` — prouve que le scope actuel filtre sur `community_id`.

- Vérifier qu'il **échoue** sur le code actuel (scope sur `community_id`)
- C'est la preuve que le problème est réel

### 3b. Modifier `app/Models/Scopes/BelongsToTenantScope.php`

```php
// AVANT
$builder->where($model->getTable().'.community_id', $organization->id);

// APRÈS
$builder->where($model->getTable().'.organization_id', $organization->id);
```

### 3c. Vérifier que le test divergent **passe** (scope sur `organization_id`)

### 3d. Adapter les tests T139.2

- Inverser caractérisation `community_id` → `organization_id`
- Unskip known-risk tenant
- Documenter `// Migrated by T140.1` dans chaque test modifié

### 3e. Vérifier la cohérence des bypass `withoutGlobalScope`

Ne pas modifier les controllers. HasOrganizationId garantit `community_id = organization_id`.

## 4. Gates obligatoires avant DONE

```bash
# Test divergent — prouve que le scope filtre bien organization_id
php artisan test --filter=T1401ScopeDivergentCaseTest

# Smoke routes
bash ai/scripts/smoke-critical-routes.sh

# Caractérisation T139.2 adaptée
php artisan test --filter=T1392

# Scope dédiés
php artisan test --filter=BelongsToTenantScope
php artisan test --filter=T126Explorer

# Suite complète
php artisan test
```

---

# Contraintes absolues

- Un seul `null > 0` OU un seul `drift > 0` → STOP avant toute modif
- Le test divergent (`T1401ScopeDivergentCaseTest`) doit être créé **avant** de modifier `BelongsToTenantScope.php`
- Vérifier qu'il échoue sur le code actuel (preuve que le scope filtre encore `community_id`)
- Puis modifier `BelongsToTenantScope.php`
- Vérifier qu'il passe (preuve que le scope filtre désormais `organization_id`)
- Aucune modification de controller, service, middleware, route, DB
- `community_id` reste NOT NULL et canonique en DB
- Sync bidirectionnel `HasOrganizationId` reste actif
- Diff limité à : `BelongsToTenantScope.php` + tests + TASK file
- Un seul agent modifie le code après synthèse des sous-agents

---

# Progress Log

## 2026-05-24 20:08:00 Europe/Paris

Task created. Prompt intégré avec patches GPT :
- Patch 1 : drift check NULL-safe (IS DISTINCT FROM / fallback portable)
- Patch 2 : T1401ScopeDivergentCaseTest ajouté au plan + gates + contraintes absolues

Prochaine étape : exécuter les 4 sous-agents en parallèle.

---

# Handoffs

# Tests

- [x] T1401ScopeDivergentCaseTest — test divergent avant/après scope
- [ ] caracterisation T139.2 adaptée
- [ ] known-risk tenant unskipped
- [ ] BelongsToTenantScope tests
- [ ] T126Explorer tenant scoping
- [ ] T1392RouteSmokeGates — 29 gates
- [ ] Suite complète

---

# Test Results

Pending.

---

# Review Notes

Pending.

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`