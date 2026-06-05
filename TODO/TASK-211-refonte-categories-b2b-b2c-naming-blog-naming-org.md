---
task_id: TASK-211
title: Refonte categories B2B B2C naming blog naming org

status: DONE

owner: CODEUR

contributors: []

branch: TASK-211-refonte-categories-b2b-b2c-naming-blog-naming-org

priority: MEDIUM

created_at: 2026-06-04 16:44:10 Europe/Paris
updated_at: 2026-06-05 01:58:00 Europe/Paris

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

- [x] feature tests (AdminCategoriesTest 15/15, AdminSettingTest 7/7, T0756 4/4)
- [ ] browser validation
- [ ] responsive validation
- [ ] console inspection
- [x] tenant validation (cross-org category rejection tests)

---

# Test Results

814/816 pass. 2 échecs pré-existants dans T0755ServicesRequestsTenantSafetyTest (service_store_fails_safe_when_no_organization_resolved + request_store_fails_safe_when_no_organization_resolved) — non liés à TASK-211.

---

# Review Notes

Corrections finales appliquées:
- destroySkill: scope check ajouté (vérifie skill->category->organization_id === auth()->user()->organization_id)
- AdminCategoriesTest: admin créé avec org, HTTP PUT (pas PATCH), fixtures org-scopées, 3 tests cross-org ajoutés
- CategoryFactory: organization_id ajouté via Organization::factory()

2 échecs pré-existants T0755 (non liés à cette tâche) — attendent fix séparé.

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`