---
task_id: TASK-073B
title: Referral Logic

status: IN_PROGRESS

owner: CODE

contributors:
  - CODE

branch: TASK-073B-referral-logic

priority: HIGH

created_at: 2026-05-13 16:45:00 Europe/Paris
updated_at: 2026-05-13 16:45:00 Europe/Paris

labels:
  - referral
  - reward
  - event

lock:
  status: LOCKED
  agent: CODE
  since: 2026-05-13 16:45:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objectif

Implémenter la logique métier du système referral :

* Events (MemberInvited, MemberActivated)
* Listeners pour attribution de rewards
* Génération automatique de referral_code
* Anti-abuse runtime (self-referral, duplicate, circular)
* RewardDispatcher

Cette tâche construit sur T073A (migrations, modèles, relations, tests fondations).

---

# Planned Actions

- [ ] inspecter architecture existante (T073A models, User, Organization)
- [ ] créer Events (MemberInvited, MemberActivated)
- [ ] créer Listeners pour reward attribution
- [ ] créer RewardDispatcher service
- [ ] créer ReferralCodeGenerator service (collision-safe, human-readable)
- [ ] auto-generate referral_code on User creation (Observer or booted)
- [ ] anti-abuse: self-referral check, duplicate check, circular parent check
- [ ] tests unitaires (Events, Listeners, RewardDispatcher, ReferralCodeGenerator)
- [ ] tests feature (full referral flow: invite → activate → reward)
- [ ] tests anti-abuse
- [ ] vérifier régressions (BelongsToTenantScope, OrganizationRelationships, ReferralTest)
- [ ] lancer tests SQLite
- [ ] lancer tests PostgreSQL CI (via PR)

---

# Architecture

## Events

- `MemberInvited` — dispatched when referral link used
- `MemberActivated` — dispatched when referred user completes onboarding

## RewardDispatcher

Service responsable de :

- vérifier éligibilité (pas de self-referral, pas de duplicate)
- attribuer points au parrain
- attribuer points au filleul (welcome bonus)
- créer ReferralReward immutable rows
- supporter extensibilité future (level 2, event_type ouverts)

## ReferralCodeGenerator

Service responsable de :

- générer code lisible (pseudo: base username + random suffix)
- collision-safe (unique DB constraint + retry)
- attribué automatiquement à la création User

## Anti-Abuse

- **self-referral**: bloquer si referrer_user_id == referred_user_id
- **duplicate**: bloquer si UNIQUE(community_id, referrer_user_id, referred_user_id) violé
- **circular parent**: bloquer si parent_referral_id referrer == referred (level 2+)

---

# Contraintes

- additive-only : pas de drop/rename
- Organization = Tenant
- Loop != Tenant
- SQLite + PostgreSQL compatible
- Playwright-safe (pas de modification UI/UX)
- Pas de modification auth core, tenant middleware, onboarding runtime

---

# Hors scope

- UI member
- WhatsApp share
- admin dashboard
- badge attribution
- notifications complexes
- analytics

---

# Progress Log

## 2026-05-13 16:45:00 Europe/Paris — CODE

- vérifié git status (clean)
- checkout develop (déjà dessus)
- pull latest develop (up to date)
- supprimé ancienne branche TASK-073B-referral-logic (stale, pre-merge T073A)
- créé branche `TASK-073B-referral-logic` depuis develop `79cf404`
- créé `TODO/TASK-073B-referral-logic.md`
- verrouillé LOCKED by CODE

---

# Handoff

## Modified Files

- `TODO/TASK-073B-referral-logic.md` — NEW (metadata + scope + progress)
- `TODO/TASK-073-STATUS.md` — EDIT (T073A → Completed, T073B → Current)

## Pending Actions

1. créer Events (MemberInvited, MemberActivated)
2. créer RewardDispatcher service
3. créer ReferralCodeGenerator service
4. auto-generate referral_code on User creation
5. anti-abuse runtime checks
6. tests unitaires + feature
7. vérification régressions
8. PR → CI PostgreSQL → merge

## Owner

- Current: CODE (locked)
- Next: CODE

---

# Tests

- [ ] unit: MemberInvited event
- [ ] unit: MemberActivated event
- [ ] unit: RewardDispatcher
- [ ] unit: ReferralCodeGenerator
- [ ] feature: full referral flow (invite → activate → reward)
- [ ] anti-abuse: self-referral blocked
- [ ] anti-abuse: circular parent blocked
- [ ] anti-abuse: duplicate blocked
- [ ] regression: BelongsToTenantScope
- [ ] regression: OrganizationRelationships
- [ ] regression: ReferralTest (31 tests T073A)
- [ ] SQLite all tests pass
- [ ] PostgreSQL all tests pass

---

# Test Results

Pending.

---

# Review Notes

- T073A fournit les fondations (migrations, models, relations, tenant scope)
- T073B ajoute la couche logique (events, listeners, rewards, anti-abuse)
- Pas d'UI, pas de modif runtime existant
- compatible Badge/BadgeUser futurs
