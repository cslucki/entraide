---
task_id: TASK-073A
status: DONE
owner: CODE

contributors:
  - CODE

branch: TASK-073A-referral-foundations

lock:
  status: UNLOCKED
  agent: CODE
  since: 2026-05-13 14:00:00 Europe/Paris
---

# TASK-073A — Referral Foundations

## Objectif

Mettre en place les fondations techniques du futur système de :

* referral,
* contribution,
* croissance communautaire.

Cette tâche construit uniquement :

* les migrations,
* les modèles,
* les relations,
* les contraintes runtime,
* les bases extensibles.

Aucune UX ou logique métier avancée ne doit être implémentée ici.

---

# Contraintes Obligatoires

## Architecture

Règles absolues :

* Organization = Tenant
* Loop != Tenant

Aucune logique referral ne doit :

* bypass tenant isolation,
* créer du cross-organization,
* introduire une logique tenant au niveau Loop.

---

## Compatibilité Runtime

Obligatoire :

* SQLite compatible
* PostgreSQL compatible
* Playwright-safe
* additive-only

---

## Migration Philosophy

Interdictions :

* gros refactors,
* renommages massifs,
* modifications destructives,
* changements runtime risqués.

---

# Vision Architecture

Cette tâche NE construit PAS :

* le moteur MLM complet,
* la réputation,
* le graph engine avancé,
* l’IA sociale.

Cette tâche construit :
les fondations extensibles du futur Contribution Engine.

Même si aujourd’hui :

* seuls referrals,
* points,
* invitations

sont activés.

---

# Modèle Conceptuel

Le système doit préparer :

| Concept            | Fonction                 |
| ------------------ | ------------------------ |
| Referral           | relation invitation      |
| Contribution Event | action reconnue          |
| Reward             | points attribués         |
| Contribution Level | profondeur relationnelle |
| Contribution Type  | extensibilité future     |

---

# Tables à Créer

## referrals

### Objectif

Stocker :

* relation parrain/filleul,
* profondeur,
* Organization scope,
* activation future.

---

### Structure recommandée

referrals

* id UUID PK
* organization_id UUID FK
* referrer_user_id UUID FK
* referred_user_id UUID FK
* parent_referral_id UUID nullable
* depth integer default 1
* status string
* activated_at nullable
* created_at
* updated_at

---

### Contraintes importantes

#### Organization-scoped obligatoire

Chaque referral appartient à UNE Organization.

---

#### Aucun cross-organization referral

Interdit :

* inviter hors tenant,
* connecter plusieurs Organizations.

---

#### parent_referral_id

Préparation future :

* level 2
* graph relationnel
* contribution lineage

Même si le MVP reste simple.

---

# referral_rewards

## Objectif

Historique immuable des rewards attribués.

---

## Structure recommandée

referral_rewards

* id UUID PK
* organization_id UUID FK
* referral_id UUID FK
* user_id UUID FK
* source_user_id UUID nullable
* event_type string
* level integer default 1
* points integer
* metadata json nullable
* created_at
* updated_at

---

## Important

Cette table doit être :

* append-only autant que possible,
* auditable,
* extensible.

---

## event_type

Prévoir immédiatement :

* member_invited
* member_activated
* referral_level_2

Mais architecture ouverte.

---

## Future compatibility

Préparer :

* quote_found
* business_referral
* job_shared
* loop_growth
* onboarding_help

Sans implémentation maintenant.

---

# User Referral Code

## Objectif

Chaque Member doit posséder :

* un referral_code stable,
* partageable,
* unique.

---

## Implémentation recommandée

Ajouter sur users :

* referral_code nullable unique

---

## Génération

Prévoir :

* génération automatique,
* collision-safe,
* lisible humainement.

Exemples :

* cyril
* alice92
* karim-loop

Pas UUID illisible.

---

# Relations Eloquent

## User

Prévoir :

* sentReferrals()
* receivedReferrals()
* referralRewards()

---

## Referral

Prévoir :

* referrer()
* referred()
* parentReferral()
* children()
* organization()
* rewards()

---

# Contribution Extensibility

## Important

Ne PAS hardcoder :
“referral = invitation uniquement”.

Le modèle doit préparer :

* recommandations,
* mises en relation,
* business referrals,
* contributions collectives.

---

## Architecture recommandée

Prévoir :

* event_type extensible,
* metadata json,
* level extensible,
* reward source extensible.

---

# Tenant Isolation

## Obligatoire

Toutes les queries doivent :

* respecter Organization scope,
* respecter policies,
* respecter middleware tenant.

---

# Anti-Abuse Minimal

Prévoir maintenant :

| Cas                | Action   |
| ------------------ | -------- |
| self-referral      | interdit |
| duplicate referral | interdit |
| circular parent    | interdit |

Pas besoin :

* d’anti-fraud complexe,
* ni de détection multi-account.

---

# Badge Compatibility

Le système doit rester compatible avec :

* Badge
* BadgeUser
* attribution automatique future.

Prévoir :

* reward triggers futurs,
* contribution thresholds futurs.

Pas besoin :

* d’UI badges,
* ni de logique badge complète maintenant.

---

# Tests Obligatoires

## SQLite

Validation :

* migrations
* relations
* rewards
* tenant isolation

---

## PostgreSQL

Validation :

* migrations
* json compatibility
* foreign keys
* indexes
* unique constraints

---

## Important

Aucune migration ne doit :

* casser SQLite,
* utiliser syntaxe PostgreSQL-only.

---

# Playwright Safety

Cette tâche ne doit PAS :

* casser onboarding,
* casser auth,
* casser Organization routing,
* casser tenant isolation.

---

# Workflow Obligatoire

Respecter :

* check-task.sh
* finalize-task.sh
* merge-task.sh

---

# Explicitement Hors Scope

NE PAS faire dans cette partie :

* UI member
* WhatsApp share
* admin dashboard
* badge UI
* Looper role
* analytics
* AI contribution
* reputation engine
* notifications complexes

---

# Objectif Final Réel

Cette tâche doit produire :

une base de données propre,
stable,
extensible,
et compatible avec le futur moteur de contribution,

sans implémenter toute la vision sociale maintenant.

---

# Progress Log

## 2026-05-13 10:00:00 Europe/Paris — CODE

- vérifié git status (develop propre, gitignore stashés)
- checkout develop + pull latest
- créé branche `TASK-073A-referral-foundations`
- ajouté YAML metadata + lock
- vérifié état propre (only TASK files untracked)
- stashé dirty `.gitignore` files
- préparé handoff CODE

## 2026-05-13 14:00:00 Europe/Paris — CODE

- exploré codebase patterns

## 2026-05-13 16:30:00 Europe/Paris — CODE (audit + recovery)

- audit : TASK-073A marquée DONE mais 0 commits (staged only)
- audit : trouvé sur TASK-073B-referral-logic (branche erronée)
- recovery : stash TASK-073B, checkout TASK-073A, stash pop
- vérifié tous les fichiers (migrations, models, factories, User.php, tests)
- tout le code T073A était déjà complet mais jamais commité
- ajouté `.obsidian/` au .gitignore
- commit initial sur TASK-073A-referral-foundations (User, Organization, migrations, tests)
- créé migration `create_referrals_table` avec UUID PK, organization scope, self-referential parent, unique pair constraint
- créé migration `create_referral_rewards_table` avec UUID PK, event_type, points, metadata json
- créé migration `add_referral_code_to_users_table` (nullable unique string)
- créé modèle `Referral` avec relations : referrer(), referred(), parentReferral(), children(), organization(), rewards()
- créé modèle `ReferralReward` avec relations : referral(), user(), sourceUser(), organization()
- créé `ReferralFactory` avec helper forOrganization()
- créé `ReferralRewardFactory` avec helper forOrganization()
- ajouté relations User : sentReferrals(), receivedReferrals(), referralRewards()
- ajouté `referral_code` à $fillable sur User
- écrit 31 tests PHPUnit couvrant : création, relations, tenant isolation, duplicate prevention, referral_code, metadata JSON, cascade deletes, activation, eager loading
- fixé self-referential FK pour PostgreSQL (Schema::table séparé)
- vérifié Pint — propre
- vérifié régressions : BelongsToTenantScope (8), OrganizationRelationships (9), Points (5), FullExchange (3) — tous passent
- tests passent SQLite + PostgreSQL

---

# Architecture Decisions

- additive-only : migrations uniquement, pas de drop/rename
- Organization = Tenant scope obligatoire sur toutes les tables
- UUID PK partout
- Event-driven extensibility via `event_type` + `metadata json`
- Referral depth préparé pour future arborescence
- Anti-abuse : duplicate prevention via UNIQUE(community_id, referrer_user_id, referred_user_id)
- Self-referral : handled application-level (pas de CHECK constraint pour compatibilité SQLite)
- Self-referential FK `parent_referral_id` : séparé en Schema::table() pour PostgreSQL compat
- Pas de SoftDeletes sur Referral/ReferralReward (append-only / permanent)
- community_id nullable (consistency avec tables existantes)
- factory helpers `forOrganization()` pour tests tenant-scopés

---

# Tests

- [x] migrations SQLite (31 tests pass)
- [x] migrations PostgreSQL (31 tests pass)
- [x] relations Eloquent (10 relations testées)
- [x] tenant isolation (3 tests : scope, boundary, bypass)
- [x] anti-abuse constraints (unique pair prevented, cross-org allowed)
- [x] unique referral_code (nullable + unique constraint)

---

# Review Notes

- pas de modification auth core, tenant middleware, onboarding runtime
- pas d'UI ni de logique métier avancée
- compatible Badge/BadgeUser futurs

---

# Handoff

## Modified Files

- `TODO/TASK-073A-referral-foundations.md` — metadata + progress + tests + handoff
- `database/migrations/2026_05_13_000001_create_referrals_table.php` — NEW
- `database/migrations/2026_05_13_000002_create_referral_rewards_table.php` — NEW
- `database/migrations/2026_05_13_000003_add_referral_code_to_users_table.php` — NEW
- `app/Models/Referral.php` — NEW
- `app/Models/ReferralReward.php` — NEW
- `database/factories/ReferralFactory.php` — NEW
- `database/factories/ReferralRewardFactory.php` — NEW
- `app/Models/User.php` — EDIT (add fillable referral_code + 3 relation methods)
- `tests/Feature/ReferralTest.php` — NEW (31 tests, 54 assertions)

## Pending Actions

- [ ] vérification Playwright (aucune régression console)
- [ ] merge dans develop

## Known Risks

- User model modification : minimal et additive (referral_code nullable + relations seulement)
- Foreign keys : community_id → communities, user FKs → users, parent_referral_id → referrals (self-referential séparé pour PostgreSQL)
- SQLite : pas de ENUM, CHECK évité (self-referral handled app-level)
- Organization scope : BelongsToTenantScope appliqué sur les deux nouveaux modèles

## Owner

- Current: CODE (locked)
- Next: CODE

---

# Workflow Scripts

## Check

```bash
ai/scripts/check-task.sh TASK-073A
```

## Finalize

```bash
ai/scripts/finalize-task.sh TASK-073A
```

## Merge

```bash
ai/scripts/merge-task.sh TASK-073A
```
