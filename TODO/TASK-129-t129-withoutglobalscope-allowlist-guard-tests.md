---
task_id: TASK-129
title: t129-withoutglobalscope-allowlist-guard-tests

status: DONE

owner: CODEX

contributors: []

branch: TASK-129-t129-withoutglobalscope-allowlist-guard-tests

priority: HIGH

created_at: 2026-05-24 01:15:00 Europe/Paris
updated_at: 2026-05-24 01:45:00 Europe/Paris

labels:
  - tenant
  - organization
  - allowlist
  - withoutGlobalScope
  - security

lock:
  status: UNLOCKED
  agent: CODEX
  since: 2026-05-24 01:45:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Créer une allowlist documentée des usages withoutGlobalScope / withoutGlobalScopes et confirmer
que le seul bypass P1 non testé (TransactionController POST cross-org) est déjà couvert.

---

# Planned Actions

- [x] merger TASK-128 sur develop (Phase A)
- [x] créer TASK-129 depuis develop propre
- [x] lire T124 et T128 audits
- [x] lire TransactionController.php
- [x] inventorier tous les withoutGlobalScope dans app/ et database/
- [x] rechercher les tests existants TransactionController cross-org
- [x] constater que T07515TransactionTenantSafetyTest couvre déjà le cas
- [x] lancer T07515TransactionTenantSafetyTest (5/5 verts)
- [x] créer docs/architecture/withoutGlobalScope-allowlist.md
- [x] mettre à jour TASK-129
- [x] commit + push

---

# Progress Log

## 2026-05-24 01:15:00 Europe/Paris

Task créée. Branch TASK-129-t129-withoutglobalscope-allowlist-guard-tests depuis develop (post-merge T128, e9c9f12).

## 2026-05-24 01:45:00 Europe/Paris

**Découverte clé :** Les tests de garde TransactionController cross-org existent déjà dans
`tests/Feature/T07515TransactionTenantSafetyTest.php` (5 tests, 5 verts).

Le risque P1 identifié par T128 ("TransactionController POST cross-org non testé") était
basé sur un inventaire incomplet des tests existants. Les tests existaient déjà.

Aucun nouveau test n'a été nécessaire.

### Inventaire withoutGlobalScope

8 surfaces inventoriées dans app/ + database/ :

- TransactionController:38,48 — bypass ciblé + re-validation community_id → SAFE, testé T07515
- AdminReferralController:16-48 — admin plateforme intentionnel → ACCEPTABLE
- HomeController:46-49,76 — legacy avec re-filtre explicite → DETTE, couvert T0754
- Explorer.php:134,190 — legacy corrigé T127 → SAFE, couvert T126
- DemoSeeder:382,386 — seeder hors runtime → N/A

---

# Tests

- [x] aucun nouveau test nécessaire (T07515 couvre déjà le cas)
- [x] T07515TransactionTenantSafetyTest : 5/5 verts confirmés
- [ ] browser validation (hors scope)
- [ ] responsive validation (hors scope)

---

# Test Results

## T07515TransactionTenantSafetyTest

```bash
php artisan test tests/Feature/T07515TransactionTenantSafetyTest.php
```

**Résultat : 5 passed / 0 failed — Duration: 1.22s**

| Test | Résultat |
|---|---|
| web_transaction_store_rejects_service_outside_resolved_organization | ✓ PASS |
| web_transaction_store_rejects_tenantless_service | ✓ PASS |
| web_transaction_store_creates_transaction_when_service_matches_resolved_organization | ✓ PASS |
| web_transaction_store_rejects_service_request_outside_resolved_organization | ✓ PASS |
| web_transaction_store_rejects_tenantless_service_request | ✓ PASS |

---

# Review Notes

## Livrables T129

- `docs/architecture/withoutGlobalScope-allowlist.md` — allowlist complète, 8 surfaces classées
- Aucun fichier runtime modifié
- Aucun nouveau test (déjà couverts par T07515)

## Verdict TransactionController

**SÛR ET TESTÉ.** Le bypass `withoutGlobalScope(BelongsToTenantScope::class)` dans
TransactionController est ciblé, suivi d'une re-validation immédiate sur `community_id`,
et couvert par 5 tests de garde existants (T07515).

## Verdict global withoutGlobalScope

Tous les bypass runtime sont soit :
- correctement testés (Transaction T07515, Explorer T126/T127, Home T0754) ;
- légitimement non testés (admin plateforme intentionnel) ;
- hors runtime utilisateur (DemoSeeder).

Aucun bypass orphelin ou non justifié trouvé.

## Risques restants après T129

- HomeController legacy `withoutGlobalScopes()` (global) → devrait utiliser la forme ciblée — étape 3 roadmap T128, pas de risque actif
- AdminReferralController → à surveiller si admin multi-niveau introduit

## Recommandation suite

CAS 1 atteint : tests verts, TransactionController sûr, aucun patch runtime nécessaire.

Prochaine tâche proposée : **T130 — Branch cleanup + version footer**.
