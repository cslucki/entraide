---
task_id: TASK-074.4
title: Loop Creation Mes invites Referral Bridge

status: MERGED

owner: OPENCODE

contributors: []

branch: T074.4-t074-4-loop-creation-mes-invites-referral-bridge

priority: MEDIUM

created_at: 2026-05-15 18:29:23 Europe/Paris
updated_at: 2026-05-15 19:45:00 Europe/Paris

labels:
  - loop
  - creation
  - referral
  - bridge
  - domain

lock:
  status: UNLOCKED
  agent: OPENCODE
  since: 2026-05-15 18:29:23 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Implémenter la création minimale de Loop et le bridge minimal avec Mes invités / Referral.

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] implement changes
- [x] run tests
- [x] validate full test suite

---

# Progress Log

## 2026-05-15 18:55:00 Europe/Paris

Phase 1 — Audit terminé :

- Modèles existants utiles :
  - Loop, LoopMember (T074.3) : community_id, name, slug, type, status, created_by
  - Community : legacy tenant, extends Organization
  - User : referral_code, sentReferrals(), receivedReferrals(), loopMemberships()
  - Referral : community_id, referrer_user_id, referred_user_id, status, depth
  - ReferralReward : points, event_type, metadata

- Système Referral : EXISTANT et mature
  - ReferralService::attributeByCode() avec validation cross-community déjà en place
  - ReferralCodeGenerator pour génération auto de code
  - Registration flow avec paramètre `ref`
  - Dashboard affiche lien d'invitation, stats invités, points
  - BelongsToTenantScope pour isolation tenant

- Routes existantes utiles :
  - /boucles (public, landing page)
  - Dashboard, services, requests, profile (auth)
  - Admin /referrals (admin referral panel)

- Livewire existants : Explorer, MessageThread

Phase 2 — Implémentation terminée :

A. LoopService (app/Services/LoopService.php) — Service centralisé :
  - createLoop(User, name, description?) → crée Loop + ajoute créateur comme owner member
  - addMember(Loop, User, role?) → validation community_id invariant
  - getEligibleReferrals(User, Loop) → referrals same-community, non-déjà-membres
  - addReferralToLoop(Loop, User, Referral) → ajoute referred_user à la Loop
  - generateUniqueSlug → slug auto avec déduplication par community

B. LoopController (app/Http/Controllers/LoopController.php) :
  - index() → liste des loops de l'utilisateur (même community)
  - create() → formulaire de création
  - store() → validation + création via LoopService
  - show() → détails + membres + referrals éligibles
  - addMember() → ajout d'un referral à la Loop

C. Routes (routes/web.php) :
  - Root-level : GET|POST /loops, /loops/create, /loops/{loop}, /loops/{loop}/members
  - Community-prefixed : mêmes routes sous /{community}/loops/...
  - Toutes sous middleware auth

D. Vues Blade minimales (resources/views/loops/) :
  - index.blade.php — liste des loops
  - create.blade.php — formulaire (nom requis, description optionnelle)
  - show.blade.php — détails + membres + mes invités éligibles (avec bouton Ajouter)

E. Tests :
  - LoopCreationTest (10 tests) : création service, auto-membership owner, slug auto, web route, validation, guest block
  - LoopMemberInvariantTest (16 tests) : same-community add, cross-community reject (service + web), duplicate reject, eligible referrals filtering, addReferralToLoop, web route referral add

## 2026-05-15 19:25:00 Europe/Paris

Blocker corrections applied. All 3 blockers fixed, 6 new tests added.

## 2026-05-15 19:45:00 Europe/Paris

Review OPENAI : APPROVE WITH NOTES.
- Aucun blocker.
- Recommandation merge : oui.
- Note non bloquante : éventuel test HTTP réel sur les routes community.loops.*.
  → Reportée dans QA future (T074.11 — QA & Tenant Safety).

Blocker corrections OPENAI — 3 blockers corrigés :

### Blocker 1 — Routes préfixées /{community}
- Ajout de resolveCommunity() : utilise app('current_community') si disponible (résolu par ResolveCommunity middleware), sinon fallback auth()->user()->community
- Ajout de assertUserBelongsToCommunity() : vérifie que l'utilisateur appartient à la communauté résolue
- Toutes les méthodes du controller (index, create, store, show, addMember) vérifient maintenant la communauté
- show/addMember valident en plus que loop.community_id === community->id

### Blocker 2 — LoopMember comme autorisation applicative
- show() exige que l'utilisateur soit LoopMember actif (status=active)
- addMember() exige que l'utilisateur soit LoopMember avec role owner/moderator
- Ces vérifications s'ajoutent aux vérifications de communauté existantes
- Pas de RBAC complète documentée comme limite assumée

### Blocker 3 — Referral filtrage incomplet
- getEligibleReferrals() filtre maintenant explicitement referred_user.community_id === loop.community_id
- Ajouté dans la whereHas('referred') closure

### Décision 403/404
- 404 utilisé systématiquement pour masquer l'existence des ressources (cohérent avec les conventions existantes du projet)

### Tests ajoutés (6)
1. test_cross_community_route_prefix_is_blocked — community context forcé, accès 404
2. test_cross_community_creation_is_blocked — POST sous mauvais community context → 404
3. test_same_community_non_member_cannot_view_loop_show — même community, pas membre → 404
4. test_same_community_non_member_cannot_add_loop_member — même community, pas membre → 404
5. test_non_owner_member_cannot_add_loop_member — membre avec role=member, pas owner/moderator → 404
6. test_eligible_referrals_excludes_referred_user_from_wrong_community — referral.community_id match mais referred_user.community_id diffère → exclu

---

# Handoffs

Aucun.

# Tests

- [x] LoopModelTest (19) — toujours verts
- [x] LoopCreationTest (10) — création, auto-membership, slug, validation
- [x] LoopMemberInvariantTest (16) — invariant sécurité, referral bridge
- [x] Suite complète SQLite : 468 passed (1023 assertions)
- [x] Aucune migration modifiée → pas besoin de PostgreSQL

---

# Test Results

## SQLite (via `php artisan test`)

474 passed (1029 assertions). Durée: 13.65s

Dont :
- LoopModelTest: 19 tests (32 assertions) — inchangé, toujours vert
- LoopCreationTest: 10 tests (20 assertions)
- LoopMemberInvariantTest: 22 tests (50 assertions) — 6 nouveaux

### Nouveaux tests LoopMemberInvariantTest (6)

17. test_cross_community_route_prefix_is_blocked
18. test_cross_community_creation_is_blocked
19. test_same_community_non_member_cannot_view_loop_show
20. test_same_community_non_member_cannot_add_loop_member
21. test_non_owner_member_cannot_add_loop_member
22. test_eligible_referrals_excludes_referred_user_from_wrong_community

## Tests LoopCreationTest

1. service_creates_loop_in_users_community
2. service_auto_generates_unique_slug_from_name
3. service_auto_adds_creator_as_owner_member
4. service_throws_if_user_has_no_community
5. authenticated_user_can_create_loop_via_web_route
6. authenticated_user_can_view_their_loops
7. create_form_is_accessible
8. guest_cannot_create_loop
9. create_requires_name
10. slug_is_unique_per_community_in_service

## Tests LoopMemberInvariantTest

1. can_add_member_from_same_community
2. cannot_add_member_from_different_community (service)
3. cannot_add_member_from_different_community_via_web_route
4. cannot_add_duplicate_member
5. cannot_directly_create_cross_community_membership
6. loop_show_is_blocked_for_cross_community_user
7. eligible_referrals_returns_same_community_referrals
8. eligible_referrals_excludes_existing_members
9. eligible_referrals_excludes_cross_community
10. add_referral_to_loop_creates_member
11. add_referral_rejects_wrong_owner
12. add_referral_rejects_cross_community
13. add_referral_via_web_route
14. add_referral_via_web_route_rejects_cross_community
15. referral_bridge_loop_show_shows_eligible_referrals
16. referral_bridge_loop_show_hides_cross_community_referrals

---

# Review Notes

## Fichiers modifiés

- `app/Services/LoopService.php` (AJOUTÉ)
- `app/Http/Controllers/LoopController.php` (AJOUTÉ)
- `resources/views/loops/index.blade.php` (AJOUTÉ)
- `resources/views/loops/create.blade.php` (AJOUTÉ)
- `resources/views/loops/show.blade.php` (AJOUTÉ)
- `routes/web.php` (MODIFIÉ — ajout import LoopController + routes loops)
- `tests/Feature/LoopCreationTest.php` (AJOUTÉ)
- `tests/Feature/LoopMemberInvariantTest.php` (AJOUTÉ)

## Décisions architecturales

- LoopService est le point d'entrée centralisé pour toute création/ajout
- La règle same-community_id est garantie dans addMember() du service
- Aucune route directe ne permet de bypasser le service
- Le Referral bridge utilise les relations Referral existantes (sentReferrals)
- getEligibleReferrals filtre par community_id + exclude existing members
- Views utilisent `$currentLoop` pour éviter conflit avec variable Blade `$loop`
- Routes présentes à la fois au niveau root ET sous /{community}/
- Aucune migration modifiée (schema existant déjà compatible)

## Limites assumées

- Pas d'UI de modification/suppression de Loop (hors scope)
- Pas de pagination sur la liste des loops
- Pas de recherche/filtre sur les loops
- Pas de gestion des rôles avancée (owner peut tout faire)
- Pas de community-prefixed routes pour les vues (seulement le show protège par community_id)
- Pas de validation navigateur/Playwright (UI minimale, pas de comportement interactif critique)
- Pas de validation PostgreSQL nécessaire (aucune migration modifiée)

## QA Future — T074.11

- [ ] Ajouter un test HTTP réel (feature test) sur les routes community.loops.* pour valider le contexte community de bout en bout
  Source : note OPENAI review T074.4

## Hors scope confirmé

- ❌ Pas de ChatLoop complet
- ❌ Pas de LoopMessage
- ❌ Pas d'IA / FakeAIProvider
- ❌ Pas de Reverb / WebSocket
- ❌ Pas de migration Community → Organization
- ❌ Pas de leaderboard, score, badges, CRM, relance automatique, gamification
- ❌ Pas de modification des transactions, points, messaging existants
- ❌ Pas de modification des modèles Loop/LoopMember existants

## Risques

- Aucun risque identifié — code additif uniquement, pas de modification des modèles/migrations existants, tests complets de l'invariant cross-community, full suite toujours verte.

---

