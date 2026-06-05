---
task_id: TASK-211
title: Refonte categories B2B B2C naming blog naming org

status: DONE

owner: CODEUR

contributors: []

branch: TASK-211-refonte-categories-b2b-b2c-naming-blog-naming-org

priority: MEDIUM

created_at: 2026-06-04 16:44:10 Europe/Paris
updated_at: 2026-06-05 10:45:00 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: null
  since: null

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Refonte complète du système de catégories :
1. BDD : ajouter `name_b2b`, `name_b2c` dans `categories` (remplacer `name`)
2. BDD : ajouter `blog_naming`, `transactions_naming` dans `organizations`
3. BDD : ajouter `category_id` dans `blog_posts`, supprimer `blog_post_category` pivot
4. Admin : nouveau contrôleur dédié + CRUD catégories avec champs B2B/B2C/5 services
5. Ajouter le skill manquant "Amélioration photo / image" à la catégorie "Créer des supports"

---

# Planned Actions

## Phase A : Migration categories
- [x] Créer migration : rename `categories.name` → `name_b2c`
- [x] Ajouter `name_b2b` (nullable, puis rempli)
- [x] Ajouter `service_1` à `service_5` (nullable string)
- [x] Remplir `name_b2b` via mapping

## Phase B : Migration organizations
- [x] Ajouter `blog_naming` (string, default 'b2b')
- [x] Ajouter `transactions_naming` (string, default 'b2c')

## Phase C : Migration blog_posts
- [x] Ajouter `category_id` (UUID FK → categories, SET NULL)
- [x] Migrer données depuis blog_post_category pivot
- [x] Supprimer la table `blog_post_category`

## Phase D : Models
- [x] Category : name_b2b, name_b2c, service_1..5 aux fillable
- [x] Category : méthode `displayName(string $context)`
- [x] BlogPost : `categories()` BelongsToMany → `category()` BelongsTo
- [x] Organization : blog_naming, transactions_naming aux fillable

## Phase E : Admin Controller
- [x] Créer `AdminCategoryController` dédié (extrait de AdminController)
- [x] CRUD complet org-scopé avec name_b2b, name_b2c, service_1..5
- [x] Gestion des skills (storeSkill, destroySkill) avec org scope
- [x] destroySkill scope check via category->organization_id

## Phase F : Admin Vues
- [x] Nouvelle vue index : tableau avec B2B/B2C, services, skills
- [x] Nouvelle vue create : formulaire complet
- [x] Nouvelle vue edit : formulaire complet

## Phase G : Org settings
- [x] blog_naming / transactions_naming dans formulaire édition org
- [x] AdminOrganizationController validation

## Phase H : Skill manquant
- [x] Ajouté "Amélioration photo / image" à "Créer des supports"

## Phase I : Seed placeholder
- [x] CategorySeeder firstOrCreate (idempotent, 11 catégories)

---
# Progress Log

## 2026-06-05 10:45:00 Europe/Paris — Final commit pre-merge

Commit final incluant toutes corrections ORCHESTRATOR + UI improvements:
- AdminCategoryController: org selector + skills Alpine.js interface (create/edit/index)
- Explorer: displayName('transactions') + org scope filtering + scrollable chip bar (catégories et compétences)
- AdminCategoriesTest: multi-org tests, PATCH→PUT, fixtures org-scoped (16/16 PASS)
- TestCase: RuntimeException guard against bouclepro DB wipe (RefreshDatabase protection)
- phpunit.xml/pgsql.xml: PostgreSQL bouclepro_test config with APP_CONFIG_CACHE
- AGENTS.md: Test Database Safety section, forbidden actions documented
- sync-prod-to-local: categories compatibility mapping (name→name_b2c/name_b2b), org default fallback
- Vues admin categories: org selector, skills interface (chips + add/delete forms)
- Runtime DB restored via sync: 11 catégories, 54 skills, 25 users, 2 orgs
- Explorer validation: Playwright desktop/mobile confirmation (chip bar scrollable, no scrollbar desktop)

Fichiers modifiés (15, +341/-102 lignes):
- AGENTS.md
- app/Http/Controllers/Admin/AdminCategoryController.php
- app/Livewire/Explorer.php
- opencode.json
- phpunit.pgsql.xml
- phpunit.xml
- public/build/manifest.json
- resources/views/admin/categories/create.blade.php
- resources/views/admin/categories/edit.blade.php
- resources/views/admin/categories/index.blade.php
- resources/views/livewire/explorer.blade.php
- synchro_pgsql-avant-migration/sync-prod-to-local.php
- synchro_pgsql-avant-migration/sync-prod-to-local.sh
- tests/Feature/Admin/AdminCategoriesTest.php
- tests/TestCase.php

Tests:
- AdminCategoriesTest: 16/16 PASS
- ExplorerTest: 17/17 PASS
- T0756BlogOrganizationScopingTest: 11/11 PASS
- Runtime DB préservée après tests

Prêt pour merge sur develop.

## 2026-06-05 01:58:00 Europe/Paris — CODEUR final corrections

Fix 3 corrections ORCHESTRATOR:
1. destroySkill: ajout vérification org scope via skill->category->organization_id
2. AdminCategoriesTest: réécriture complète — admin avec org, PATCH→PUT, fixtures org-scopées, 3 nouveaux tests cross-org (update, delete category, delete skill)
3. CategoryFactory: ajout organization_id via Organization::factory()

Résultat: 15/15 AdminCategoriesTest, 814/816 total (2 échecs pré-existants T0755 non liés).

## 2026-06-04 — CODEUR implementation

Phases A-I implémentées. 3 rounds de corrections (VERIFICATOR). Org-scoping complet sur BlogController, AdminCategoryController, CategorySeeder.

# Handoffs

# Tests

- [x] feature tests (AdminCategoriesTest 16/16, AdminSettingTest 7/7, T0756 4/4)
- [x] Explorer validation (ExplorerTest 17/17)
- [x] browser validation (Playwright desktop/mobile chip bar)
- [x] responsive validation (scrollable chips, no scrollbar desktop)
- [x] console inspection (no errors)
- [x] tenant validation (cross-org category rejection tests)
- [x] test DB safety (RuntimeException guard against bouclepro wipe)

---

# Test Results

Final: ExplorerTest 17/17 PASS + AdminCategoriesTest 16/16 PASS + T0756 4/4 PASS = 37/37 feature tests PASS.
Runtime DB preserved after tests (25 users, 11 catégories, 54 skills, 2 orgs).
2 échecs pré-existants dans T0755ServicesRequestsTenantSafetyTest (service_store_fails_safe_when_no_organization_resolved + request_store_fails_safe_when_no_organization_resolved) — non liés à TASK-211.

---

# Review Notes

## Round 1 : VERIFICATOR
- Migration irrationnelle: categories name → name_b2c/name_b2b avec perte historique pivot blog_post_category
- Views: blog vues encore utilisent name_b2c au lieu de displayName('blog')
- BlogController: validation exists mais global, sans org scope
- AdminCategoryController: non org-scoped dans index/store/destroySkill
- Seeders/factory: pas de placeholder pour new orgs

## Round 2 : VERIFICATOR
- BlogController: toujours non org-scoped dans index/byCategory/create/edit/store/update
- Routes: legacy Admin\AdminController encore pointé via route cache
- AdminCategoryController: encore partiellement non-scoped (index/store/binding actions/skills)
- Blog create/edit forms: encore name_b2c au lieu de displayName('blog')

## Round 3 : VERIFICATOR
- Conflit architecture: CODEUR assertait categories globales/shared, mais AGENTS.md T075.1 exige Organization-scoped
- Schema categories.organization_id présent + FK → catégories DOIVENT être org-scoped
- Verdict: categories Organization-scoped, corrections requises

## Round 4 : ORCHESTRATOR post-debug (test DB wipe incident)
- Tests avec RefreshDatabase ont vidé bouclepro car config cached pointait vers pgsql/bouclepro
- Garde-fou ajouté dans TestCase.php: RuntimeException si pgsql ET database≠bouclepro_test ou si database=bouclepro
- AGENTS.md: section Test Database Safety, forbidden actions
- phpunit.xml/pgsql.xml: PostgreSQL bouclepro_test config with APP_CONFIG_CACHE

## Round 5 : ORCHESTRATOR final
- AdminCategoryController: org selector + skills Alpine.js interface (create/edit/index)
- Explorer: displayName('transactions') + org scope filtering + scrollable chip bar
- sync-prod-to-local: compatibility mapping categories (name→name_b2c/name_b2b), org default fallback
- Runtime DB restaurée et préservée après tests

Corrections finales appliquées:
- destroySkill: scope check ajouté (vérifie skill->category->organization_id === auth()->user()->organization_id)
- AdminCategoriesTest: admin créé avec org, HTTP PUT (pas PATCH), fixtures org-scopées, 3 tests cross-org ajoutés, org selector tests
- CategoryFactory: organization_id ajouté via Organization::factory()
- Explorer::render(): catégories filtrées par orgId
- Explorer view: displayName('transactions') au lieu de name_b2c
- AdminCategoryController::index(): org selector + org filtering
- AdminCategoryController::create/edit/store/update/destroy/destroySkill(): org-scoped complet
- Admin views: org selector displayed, skills Alpine.js interface
- sync-prod-to-local: compatibilité mapping catégories, org default fallback

2 échecs pré-existants T0755 (non liés à cette tâche) — attendent fix séparé.

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`