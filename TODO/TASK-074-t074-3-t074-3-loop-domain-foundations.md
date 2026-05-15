---
task_id: TASK-074.3
title: T074.3 Loop Domain Foundations

status: MERGED

owner: OPENCODE

contributors: []

branch: T074.3-t074-3-t074-3-loop-domain-foundations

priority: MEDIUM

created_at: 2026-05-15 17:47:25 Europe/Paris
updated_at: 2026-05-15 18:30:00 Europe/Paris

labels:
  - loop
  - domain
  - foundations
  - migration
  - model

lock:
  status: UNLOCKED
  agent: OPENCODE
  since: 2026-05-15 17:47:25 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Définir et implémenter les fondations domaine minimales de Loop :

- tables `loops` et `loop_members`
- modèles Eloquent `Loop` et `LoopMember`
- relations minimales (Community→loops, User→loopMemberships, Loop→members, LoopMember→loop/user)
- factories
- tests de validation (SQLite + PostgreSQL)
- sans IA, sans UI, sans Reverb, sans migration Community→Organization

---

# Planned Actions

- [x] inspect architecture
- [x] inspect impacted files
- [x] implement changes
- [x] run tests
- [x] validate SQLite + PostgreSQL

---

# Progress Log

## 2026-05-15 17:47:25 Europe/Paris

Task created.
Owner: OPENCODE
Branch: T074.3-t074-3-t074-3-loop-domain-foundations
Status: IN_PROGRESS

## 2026-05-15 18:30:00 Europe/Paris

Phase 1 — Audit terminé :

- Modèles inspectés : Community, User, Organization (27 models existants)
- Conventions : UUID PK via HasUuids, casts() méthode, factories avec $model explicite
- Pas de code Loop existant
- PHPUnit (pas Pest)
- SQLite par défaut, PostgreSQL disponible via switch-db.sh
- Organization = Tenant, Community = legacy technique (Organization extends Community)
- HasOrganizationId trait mirror community_id → organization_id
- BelongsToTenantScope filtre par community_id

Phase 2 — Implémentation terminée :

1. Migration `2026_05_15_000001_create_loops_table.php`
   - id UUID PK
   - community_id FK → communities (cascade) — legacy tenant
   - name, slug (unique par community_id), description nullable
   - type: custom/system (prépare "Mes invités")
   - status: active/archived
   - created_by FK → users (set null)
   - unique(community_id, slug)

2. Migration `2026_05_15_000002_create_loop_members_table.php`
   - id UUID PK
   - loop_id FK → loops (cascade)
   - user_id FK → users (cascade)
   - role: owner/moderator/member
   - status: active/invited/left
   - joined_at nullable
   - unique(loop_id, user_id)

3. Modèle `App\Models\Loop`
   - HasUuids, HasFactory
   - Relations : community() BelongsTo, creator() BelongsTo, members() HasMany, activeMembers() scope

4. Modèle `App\Models\LoopMember`
   - HasUuids, HasFactory
   - Relations : loop() BelongsTo, user() BelongsTo
   - casts : datetime pour joined_at

5. Relations ajoutées :
   - Community::loops() → hasMany(Loop::class)
   - User::loopMemberships() → hasMany(LoopMember::class)

6. Factories :
   - LoopFactory (with system(), archived() states)
   - LoopMemberFactory (with owner(), moderator(), invited() states)

Phase 3 — Validation terminée :

- SQLite (default) : 442 tests pass, dont 19 LoopModelTest
- PostgreSQL : 19 LoopModelTest pass (migration fraîche OK)
- Retour à SQLite propre

---

# Handoffs

Aucun handoff nécessaire — tâche complétée par OPENCODE.

# Tests

- [x] migration SQLite OK (via phpunit RefreshDatabase)
- [x] migration PostgreSQL OK (via switch-db.sh + migrate:fresh)
- [x] Loop appartient bien au legacy community_id tenant
- [x] LoopMember relie User à Loop
- [x] Unicité membership loop_id/user_id
- [x] Unicité slug par community_id
- [x] Loop n'est pas un tenant boundary
- [x] Relation traversal Community→loops, User→loopMemberships
- [x] Cascade delete Loop→LoopMember
- [x] Valeurs par défaut (type, status, role)
- [x] Nullable creator/joined_at
- [ ] browser validation (hors scope — pas de UI)

---

# Test Results

## SQLite (via `php artisan test`)

442 passed (959 assertions), dont 19 LoopModelTest (32 assertions). Durée: 12.37s

## PostgreSQL (via `php artisan test tests/Feature/LoopModelTest.php`)

19 passed (32 assertions). Durée: 0.60s

## Liste des tests LoopModelTest

1. loop_belongs_to_community_via_legacy_community_id
2. loop_belongs_to_creator
3. loop_creator_can_be_null
4. loop_has_many_members
5. loop_member_belongs_to_loop
6. loop_member_belongs_to_user
7. user_has_many_loop_memberships
8. community_has_many_loops
9. loop_membership_is_unique_per_loop_and_user
10. loop_slug_is_unique_per_community
11. same_slug_allowed_in_different_communities
12. loop_is_not_tenant_boundary
13. loop_member_has_default_role
14. loop_member_has_default_status
15. loop_has_default_type
16. loop_has_default_status
17. loop_member_joined_at_is_nullable
18. loop_active_members_scope
19. loop_on_delete_cascade_removes_members

---

# Review Notes

## Fichiers modifiés

- `database/migrations/2026_05_15_000001_create_loops_table.php` (AJOUTÉ)
- `database/migrations/2026_05_15_000002_create_loop_members_table.php` (AJOUTÉ)
- `app/Models/Loop.php` (AJOUTÉ)
- `app/Models/LoopMember.php` (AJOUTÉ)
- `database/factories/LoopFactory.php` (AJOUTÉ)
- `database/factories/LoopMemberFactory.php` (AJOUTÉ)
- `tests/Feature/LoopModelTest.php` (AJOUTÉ)
- `app/Models/Community.php` (MODIFIÉ — ajout relation loops() + import Str)
- `app/Models/User.php` (MODIFIÉ — ajout relation loopMemberships())

## Limites assumées

- Community/community_id reste legacy technique — Loop utilise community_id comme FK tenant
- Pas de BelongsToTenantScope sur Loop (vérifié : Loop n'est pas un tenant boundary)
- Pas de SoftDeletes sur Loop/LoopMember (cohérent avec les règles produit)
- Pas de politique RBAC complète (sera fait dans T074.4+)
- Pas d'index fulltext ou recherche
- LoopMember est autorisation applicative uniquement
- type='system' prêt pour "Mes invités" mais pas utilisé (T074.4)
- Organization n'a pas de relation loops() directe — passe par Community

## Hors scope confirmé

- ❌ Pas de ChatLoop UI
- ❌ Pas d'IA / FakeAIProvider
- ❌ Pas de Reverb / WebSocket
- ❌ Pas de migration Community → Organization
- ❌ Pas de Livewire components
- ❌ Pas de routes
- ❌ Pas de policies RBAC avancé
- ❌ Pas de "Mes invités" (T074.4)
- ❌ Pas de création de Loop UI
- ❌ Pas de messages dans Loop (T074.5)
- ❌ Aucun fichier hors scope modifié

## Risques

- Aucun risque identifié — migration additive uniquement, aucune table/modèle existant modifié structurellement, toutes les conventions projet respectées.
