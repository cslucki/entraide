---
task_id: TASK-087
title: T075.8 — Policies Tenant Checks

status: MERGED

owner: OPENCODE

contributors: []

branch: TASK-087-t075-8-policies-tenant-checks

priority: HIGH

created_at: 2026-05-17 07:50:47 Europe/Paris
updated_at: 2026-05-17 08:30:00 Europe/Paris

labels:
  - tenant-safety
  - policies
  - organization-scoping

lock:
  status: UNLOCKED
  agent: OPENCODE
  since: 2026-05-17 07:50:47 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Sécuriser les Policies critiques pour qu'elles vérifient l'Organization de la ressource en plus des règles métier existantes, afin d'empêcher les autorisations cross-Organization lorsque les checks user_id / buyer_id / seller_id / admin flag seraient insuffisants.

# Context

T075.7 a durci BelongsToTenantScope en fail-closed quand aucune Organization n'est résolue. T075.8 complète cette protection au niveau Policy, sans toucher au scope, au middleware, aux controllers ou à l'API tenant scoping global.

# Policies à auditer

- ServicePolicy
- ServiceRequestPolicy
- TransactionPolicy
- MessagePolicy
- ReviewPolicy
- BlogPostPolicy si existante ou concernée
- LoopPolicy si existante ou concernée

# Scope inclus

- audit des Policies existantes ;
- ajout minimal de checks Organization nécessaires ;
- helpers privés de Policy uniquement si strictement utiles ;
- tests PHPUnit ciblés cross-Organization ;
- adaptation minimale de fixtures si nécessaire ;
- documentation des exceptions.

# Scope exclu

- pas de migration DB ;
- pas de Partner model/table ;
- pas de /partners complet ;
- pas de /org/{organization} ;
- pas de correction controller Services / Requests ;
- pas d'API tenant scoping global ;
- pas de modification BelongsToTenantScope ;
- pas de refactor Community → Organization ;
- pas de correction UX ;
- pas de modification UserFactory sauf nécessité test-only strictement justifiée ;
- pas de modification PROD ;
- pas de correction opportuniste hors Policies.

# Architecture Rules

- Organization = Tenant.
- Loop ≠ Tenant.
- Partner ≠ Tenant.
- /admin reste global intentionnel.
- Community / community_id / current_community restent legacy technique temporaire.
- Ne pas introduire de nouveau vocabulaire ou nommage Community.
- Utiliser Organization / Loop / Member / Interaction côté produit et nouveau code.
- N'utiliser community_id / current_community que si nécessaire pour compatibilité technique temporaire, et documenter toute utilisation legacy.

# Acceptance Criteria

- les Policies critiques vérifient que la ressource appartient à l'Organization résolue ;
- les règles métier existantes restent inchangées ;
- les accès cross-Organization sont refusés ;
- les exceptions admin sont explicites, limitées et documentées ;
- /admin global intentionnel n'est pas cassé ;
- tests PHPUnit ciblés ajoutés ;
- full suite locale verte avant finalisation ;
- Pint passé ;
- check-task.sh passe quand DONE + UNLOCKED ;
- finalize-task.sh exécuté avant clôture/push ;
- merge-task.sh utilisé uniquement après validation Cyril ;
- CI PostgreSQL verte après push/merge.

---

# Planned Actions

- [x] inspecter les Policies existantes (ServicePolicy, ServiceRequestPolicy, TransactionPolicy, MessagePolicy, ReviewPolicy, BlogPostPolicy, LoopPolicy)
- [x] identifier les ressources qui portent community_id / organization_id ou passent par Transaction / Service / ServiceRequest / BlogPost / Loop
- [x] définir le pattern minimal de check Organization
- [x] implémenter uniquement les checks Organization nécessaires dans les Policies
- [x] ajouter tests cross-Organization ciblés
- [x] lancer tests ciblés
- [x] lancer full suite
- [x] lancer Pint
- [x] mettre le TASK file à jour
- [ ] préparer review handoff OPENAI

---

# Progress Log

## 2026-05-17 07:50:47 Europe/Paris

Task created by OPENCODE via create-task.sh.

Branch: TASK-087-t075-8-policies-tenant-checks

Status: IN_PROGRESS

Lock: LOCKED by OPENCODE

## 2026-05-17 07:51:00 Europe/Paris

OPS framing completed. No code modified. Only TASK file updated with full operational context.

Predecessor: T075.7 — BelongsToTenantScope Safety Guard (MERGED, commit bc4f419 on develop).

Next step: prompt CODE for bounded implementation of T075.8.

## 2026-05-17 08:15:00 Europe/Paris

Implementation completed by OPENCODE.

### Policies modified (6 files):
- ServicePolicy: update + delete now check org before user_id
- ServiceRequestPolicy: delete checks org before user_id
- TransactionPolicy: all 8 methods check org before participant+status
- MessagePolicy: view + store check org on Transaction before participant+status
- ReviewPolicy: create checks org on Transaction before participant+completed+hasReviewFrom
- BlogPostPolicy: update+delete check org BUT is_admin bypass fires FIRST. create unchanged (no resource to scope).

### Pattern applied:
Private `resourceBelongsToCurrentOrganization($resource)` helper in each Policy. Fail-closed: no org → deny, org mismatch → deny, null organization_id → deny.

### Key discovery:
HasOrganizationId trait fires on `creating` and syncs organization_id = community_id. Tests set community_id on factories.

### LoopPolicy:
Does NOT exist. Not created — out of scope.

### Tests (6 files, 56 tests, 65 assertions):
- ServicePolicyTest: 6 tests (4 existing updated + 2 new)
- ServiceRequestPolicyTest: 4 tests (2 existing updated + 2 new)
- TransactionPolicyTest: 19 tests (17 existing updated + 2 new)
- MessagePolicyTest: 9 tests (7 existing updated + 2 new)
- ReviewPolicyTest: 7 tests (5 existing updated + 2 new)
- BlogPostPolicyTest (NEW): 11 tests

## 2026-05-17 09:00:00 Europe/Paris

OPENAI CHANGES REQUESTED addressed — BlogPostPolicy::create now fail-closed without resolved Organization.

### Correction applied:
- BlogPostPolicy::create() now checks for resolved current_organization before allowing creation.
- No organization resolved → deny (even for non-banned user).
- Existing business rule preserved: user must not be banned.

### Test added:
- test_non_banned_user_cannot_create_without_organization: non-banned user + no bound org → cannot create.
- Uses app()->forgetInstance('current_organization') to cleanly remove org binding for this single test.

### Files modified:
- app/Policies/BlogPostPolicy.php (create method only)
- tests/Feature/Policies/BlogPostPolicyTest.php (1 new test)

### Validation post-correction:
- BlogPostPolicyTest: 12 passed (15 assertions)
- Policy tests: 57 passed (66 assertions)
- Full suite: 626 passed (1349 assertions)
- Pint on changed files: passed

### Validation:
- `php artisan test --filter=Policy`: 56 passed (65 assertions)
- `php artisan test` (full suite): 625 passed (1348 assertions)
- Pint: passed (3 auto-fixed: not_operator_with_successor_space)

### Modified files:
- app/Policies/ServicePolicy.php
- app/Policies/ServiceRequestPolicy.php
- app/Policies/TransactionPolicy.php
- app/Policies/MessagePolicy.php
- app/Policies/ReviewPolicy.php
- app/Policies/BlogPostPolicy.php
- tests/Feature/ServicePolicyTest.php
- tests/Feature/ServiceRequestPolicyTest.php
- tests/Feature/TransactionPolicyTest.php
- tests/Feature/MessagePolicyTest.php
- tests/Feature/ReviewPolicyTest.php
- tests/Feature/BlogPostPolicyTest.php (NEW)

# Handoffs

Review handoff attendu vers OPENAI :
- vérifier absence de scope creep ;
- vérifier checks Organization réellement appliqués ;
- vérifier pas d'affaiblissement de ResolveUrlOrganization ;
- vérifier pas d'affaiblissement de BelongsToTenantScope ;
- vérifier pas de contournement middleware dans les tests ;
- vérifier admin global préservé ;
- vérifier legacy community_id documenté uniquement si nécessaire.

# Tests

- [x] tests PHPUnit ciblés cross-Organization sur chaque Policy auditée
- [x] full suite locale verte
- [x] Pint passing
- [ ] CI PostgreSQL verte après push

# Test Results

- Policy tests: 57 passed, 66 assertions
- Full suite: 626 passed, 1349 assertions
- Pint on changed files: passed
- CI: pending push

# Review Notes

OPENAI CHANGES REQUESTED addressed — BlogPostPolicy::create now fail-closed without resolved Organization.

Key points:
- Fail-closed design: no org → deny, org mismatch → deny, null organization_id → deny
- BlogPostPolicy: is_admin bypass fires BEFORE org check on update/delete (intentional — admin is global)
- BlogPostPolicy create: NOW checks org resolution (was the OPENAI review finding)
- LoopPolicy: does not exist, not created (out of scope)
- HasOrganizationId trait syncs organization_id from community_id on creating event
- All tests bind current_organization in setUp, set community_id on factories
- Zero regressions in full suite (626 tests)