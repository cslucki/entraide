---
task_id: TASK-128
title: t128-tenant-id-source-of-truth-strategy

status: DONE

owner: CODEX

contributors: []

branch: TASK-128-t128-tenant-id-source-of-truth-strategy

priority: HIGH

created_at: 2026-05-24 00:15:05 Europe/Paris
updated_at: 2026-05-24 01:00:00 Europe/Paris

labels:
  - tenant
  - organization
  - strategy
  - audit
  - community-migration

lock:
  status: UNLOCKED
  agent: CODEX
  since: 2026-05-24 01:00:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Clarifier la stratégie de source de vérité tenant pendant la période dual-write community_id / organization_id.

Produire un document stratégique (docs/audits/T128-tenant-id-source-of-truth-strategy.md) répondant aux 10 questions clés, après lecture complète de l'architecture runtime, des scopes, policies, models, migrations, seeders et tests existants.

Aucun patch runtime dans cette tâche.

---

# Planned Actions

- [x] vérifier git status (sur develop après merge T127)
- [x] créer TASK-128 depuis develop
- [x] lire T124 audit
- [x] lire TASK-126 et TASK-127 task files
- [x] inspecter BelongsToTenantScope, HasOrganizationId, CurrentOrganization
- [x] inspecter toutes les policies (community_id vs organization_id)
- [x] inventorier les modèles (scope / trait / colonnes)
- [x] inspecter les migrations pour cartographier dual-write vs community_id-only
- [x] inspecter les seeders
- [x] inventorier withoutGlobalScope dans app/
- [x] trancher les 10 questions stratégiques
- [x] produire docs/audits/T128-tenant-id-source-of-truth-strategy.md
- [x] ajouter section Go/No-Go T129
- [x] mettre à jour TASK-128
- [x] commit + push

---

# Progress Log

## 2026-05-24 00:15:05 Europe/Paris

Task créée. Branch TASK-128-t128-tenant-id-source-of-truth-strategy depuis develop (post-merge T127).

## 2026-05-24 01:00:00 Europe/Paris

Audit complet. Document stratégique produit.

### Verdicts clés

**Source de vérité par couche :**
- Résolution tenant : `CurrentOrganization::get()` — préfère `current_organization`, fallback `current_community`
- Scope global : `community_id` (BelongsToTenantScope)
- Policies : `organization_id` (toutes les policies, même helper)
- Synchronisation : `HasOrganizationId` sur Eloquent creating/updating — `DB::table()` bypasse
- Tests de désync : couverts par T126 (8 tests verts)

**Divergence scope/policy** : confirmée, documentée, non exploitable via les listings normaux si les données sont synchronisées.

**Inventaire withoutGlobalScope :**
- Explorer.php : corrigé T127 ✓
- TransactionController.php : bypass ciblé légitime mais non testé cross-org ⚠
- AdminReferralController.php : plateforme-admin intentionnel ✓
- HomeController.php : dette legacy, re-filtre community_id ⚠

**Go/No-Go T129** : GO — voir document stratégique section 10.

---

# Tests

- [x] aucun test ajouté (audit stratégique uniquement)
- [x] tests existants vérifiés : T126Desync (8 verts), BelongsToTenantScopeTest, HasOrganizationIdTest

---

# Test Results

Aucun test exécuté dans cette tâche. Tests de référence T126/T127 restent verts (confirmés session T127).

---

# Review Notes

## Livrables T128

- `docs/audits/T128-tenant-id-source-of-truth-strategy.md` — document stratégique complet avec Go/No-Go T129

## Fichiers runtime modifiés

Aucun.

## Risques confirmés vs écartés

| Risque | Verdict |
|---|---|
| Désync community_id != organization_id | Documenté, non exploitable en conditions normales |
| Explorer tampering | Corrigé T127 |
| Profile reviews cross-org | Corrigé T127 |
| Transaction POST cross-org | Non testé — à couvrir T129 |
| HomeController withoutGlobalScopes legacy | Dette legacy — à traiter étape 3 migration |

## Recommandation suite

**T129 — GO** : withoutGlobalScope Allowlist & Guard Tests
Scope : allowlist documentée + tests de garde. Pas de refactor runtime.
