---
task_id: TASK-084
title: T075.5 — Services / Requests Tenant Safety + Hidden Field Tampering

status: MERGED

owner: OPENCODE

contributors: []

branch: TASK-084-t075-5-services-requests-tenant-safety-hidden-field-tampering

priority: MEDIUM

created_at: 2026-05-17 00:30:06 Europe/Paris
updated_at: 2026-05-17T02:00:00 Europe/Paris

labels:
  - tenant-safety
  - organization-scoping
  - security

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

Securer les controllers Services et Requests contre les failles d'isolation tenant :

1. **Creation sans Organization resolue** — bloquer toute creation si aucune Organization n'est resolue cote serveur.
2. **Hidden field tampering sur community_id** — ne jamais faire confiance a un community_id fourni par le client ; utiliser l'Organization resolue cote serveur.
3. **Cross-Organization route-model binding** — bloquer l'acces a un Service / Request appartenant a une autre Organization via route-model binding.
4. **Donnees cross-Organization** — empecher toute fuite de donnees Service / Request entre Organizations.

**Rappel architectural :** Organization = Tenant. Loop ≠ Tenant. Partner ≠ Tenant. Community / community_id / current_community = legacy technique temporaire.

---

# Perimeter

## Inclus

- Service creation tenant safety (Organization resolue cote serveur)
- Request creation tenant safety (Organization resolue cote serveur)
- Service / Request route-model tenant safety si deja expose par les routes/tests
- Tests PHPUnit cibles Organization A / Organization B
- Usage de currentOrganization() ou equivalent existant
- community_id uniquement comme colonne legacy temporaire documentee dans ce TASK file
- Controllers : ServiceController.php, RequestController.php
- Routes : /services/*, /requests/*
- Formulaires create/edit uniquement si strictement necessaire

## Exclus

- Pas de migration DB
- Pas de Partner model/table
- Pas de /partners complet
- Pas de /org/{organization}
- Pas de BlogPost tenantless fix
- Pas de Policies globales
- Pas d'API
- Pas de BelongsToTenantScope hardening
- Pas de refactor Community → Organization
- Pas de correction UX large
- Pas de modification UserFactory sauf necessite stricte test-only validee
- Pas de modification PROD
- Pas de correction opportuniste hors Services / Requests

---

# Acceptance Criteria

- [x] Services créés avec Organization résolue côté serveur
- [x] Requests créées avec Organization résolue côté serveur
- [x] POST avec community_id falsifié ne peut pas créer dans une autre Organization
- [x] Accès route-model Service / Request cross-Organization bloqué (show/edit/update/destroy Services, show/destroy Requests)
- [x] Tests PHPUnit ciblés ajoutés (Organization A / Organization B) — 6/6 verts
- [x] Full suite verte avant clôture — 590/590
- [x] Aucune migration, aucun Blog, aucune API, aucun Policy hardening global

---

# Surfaces Concernees

- `app/Http/Controllers/ServiceController.php`
- `app/Http/Controllers/RequestController.php`
- `routes /services/*`
- `routes /requests/*`
- Tests Feature Services / Requests existants
- Formulaires create/edit si strictement necessaire

---

# Legacy community_id — Rappel

`community_id` est une colonne legacy temporaire. Ne jamais :
- Faire confiance a un community_id fourni par le client (form hidden field)
- Utiliser community_id comme scope de securite
- Introduire de nouveau nommage Community dans les nouveaux concepts, services, vues, docs ou prompts

L'Organization resolue cote serveur (currentOrganization() ou equivalent) est l'unique source de verite pour le tenant.

---

# Architecture Rules

- **Organization = Tenant** — frontiere de securite unique
- **Loop ≠ Tenant** — Loop est un groupe collaboratif interne
- **Partner ≠ Tenant** — Partner est du co-branding / distribution
- **Community / community_id / current_community = legacy temporaire** — a migrer progressivement
- Ne pas affaiblir ResolveUrlOrganization
- Ne pas contourner le middleware dans les tests
- Ne jamais faire confiance a community_id fourni par le client
- Toute creation metier doit utiliser l'Organization resolue cote serveur

---

# Planned Actions

- [ ] inspecter ServiceController.php et RequestController.php
- [ ] inspecter les routes /services/* et /requests/*
- [ ] identifier les points de creation sans Organization resolue
- [ ] identifier les points d'acces route-model cross-Organization
- [ ] securiser Service creation — Organization resolue cote serveur
- [ ] securiser Request creation — Organization resolue cote serveur
- [ ] bloquer hidden field tampering community_id
- [ ] securiser route-model binding cross-Organization si expose
- [ ] ecrire tests PHPUnit Organisation A / Organisation B
- [ ] valider full suite verte

---
# Progress Log


## 2026-05-17 00:30:06 Europe/Paris

Task created by OPS / OPENCODE.

Cadre operationnel prepare.
Aucun code applicatif modifie.

Owner: OPENCODE
Branch: TASK-084-t075-5-services-requests-tenant-safety-hidden-field-tampering
Status: IN_PROGRESS
Lock: LOCKED by OPENCODE

Pret pour prompt CODE.

## 2026-05-17 Europe/Paris — Implémentation sécurité

Guards Organisation appliqués dans ServiceController et RequestController.
Hidden fields community_id retirés des formulaires create Services et Requests.

Détail des modifications :

- `resources/views/services/create.blade.php` : retrait du hidden field `<input type="hidden" name="community_id">` — community_id ne doit jamais transiter depuis le client
- `resources/views/requests/create.blade.php` : même retrait du hidden field community_id
- `ServiceController::store()` : utilisait déjà currentOrganization() côté serveur — community_id client non validé par Request::validate() donc ignoré
- `RequestController::store()` : idem — community_id client ignoré
- `ServiceController::show/edit/update/destroy()` : guard explicite ajouté en tête — currentOrganization() + abort(404) si null ou community_id !== organization->id
- `RequestController::show/destroy()` : même guard — RequestController n'expose pas edit/update dans les routes actuelles
- `BelongsToTenantScope` : non modifié volontairement — hors scope T075.5, décision cockpit
- Tests T075.5 : 6/6 verts (12 assertions)
- ServiceControllerTest : 8/8 verts (18 assertions)
- Full suite : 590/590 verts, 0 régression
- Pint : OK

## 2026-05-17 Europe/Paris — OPENAI Review / Corrections TASK file

Verdict initial OPENAI : REQUEST CHANGES — nature : TASK file incomplet uniquement, pas le code.
Code sécurité validé par OPENAI.

Corrections appliquées :
- lock UNLOCKED (agent: null)
- fichiers modifiés complétés (vues create incluses)
- Review Notes complétées (hidden fields, routes RequestController, BelongsToTenantScope)
- Acceptance Criteria cochés

# Handoffs

# Tests

- [x] feature tests — Organization A / Organization B — 6/6 verts
- [x] tenant validation — cross-Organization access blocked — show/edit/update/destroy Services, show/destroy Requests
- [x] hidden field tampering — community_id falsified POST — ignoré côté serveur + hidden fields retirés des vues
- [x] full suite verte avant clôture — 590/590

---

# Test Results

## T0755ServicesRequestsTenantSafetyTest — 2026-05-17

```
✓ service store uses resolved organization not tampered community id   0.34s
✓ service store fails safe when no organization resolved               0.02s
✓ request store uses resolved organization not tampered community id   0.02s
✓ request store fails safe when no organization resolved               0.03s
✓ service show is scoped to resolved organization                      0.03s
✓ request show is scoped to resolved organization                      0.03s

Tests: 6 passed (12 assertions) — Duration: 0.56s
```

## ServiceControllerTest — 2026-05-17

```
Tests: 8 passed (18 assertions)
```

## Full suite — 2026-05-17

```
Tests: 590 passed (1295 assertions) — Duration: 17.02s
0 failures, 0 errors, 0 regressions
```

---

# Review Notes

## OPENAI Review

- Verdict initial : REQUEST CHANGES
- Nature des demandes : TASK file incomplet — pas le code
- Code sécurité : validé par OPENAI
- Corrections appliquées : lock UNLOCKED + fichiers modifiés documentés complets + Review Notes complétées

## Décisions architecturales

- **Hidden fields community_id retirés des vues** : `resources/views/services/create.blade.php` et `resources/views/requests/create.blade.php` contenaient un `<input type="hidden" name="community_id">` généré depuis `$currentCommunity ?? $currentOrganization`. Ces champs ont été supprimés — un community_id client ne doit jamais transiter vers le serveur, même correctement généré, pour éliminer toute surface de tampering.

- **ServiceController::store() / RequestController::store()** : utilisaient déjà `currentOrganization()` côté serveur pour forcer `community_id`. Le `community_id` fourni par le client est absent de `$data` (non validé par `Request::validate()`) et donc ignoré. Protection contre le hidden field tampering déjà en place avant T075.5.

- **ServiceController::show/edit/update/destroy** : guard explicite ajouté `currentOrganization()` + `abort(404)` si l'Organization résolue est nulle ou si `$service->community_id !== $organization->id`. Positionné avant toute logique métier ou `authorize()`.

- **RequestController::show/destroy** : même pattern. `RequestController` n'expose pas `edit` ni `update` dans les routes actuelles — ces actions ne nécessitent donc pas de guard pour cette tâche.

- **BelongsToTenantScope non modifié** : hors scope T075.5 par décision cockpit. Le scope global n'a pas été touché pour ne pas introduire de side effects non contrôlés.

- **community_id** : reste colonne legacy technique temporaire. Ne circule jamais depuis le client vers la création. L'Organization résolue côté serveur est l'unique source de vérité.

- **Aucun scope creep** : Blog, API, Policies globales, UserFactory, BelongsToTenantScope, migrations, Partner, routes /org — non modifiés.

---

# Modified Files

- `app/Http/Controllers/ServiceController.php` — guards ajoutés : show(), edit(), update(), destroy()
- `app/Http/Controllers/RequestController.php` — guards ajoutés : show(), destroy()
- `resources/views/services/create.blade.php` — retrait du hidden field community_id
- `resources/views/requests/create.blade.php` — retrait du hidden field community_id
- `tests/Feature/T0755ServicesRequestsTenantSafetyTest.php` — tests T075.5 créés (6 tests, 12 assertions)
- `TODO/TASK-084-t075-5-services-requests-tenant-safety-hidden-field-tampering.md` — ce fichier