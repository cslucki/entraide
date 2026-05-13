---
task_id: TASK-073G
title: Referral Future-Proofing / Contribution Architecture Notes

status: MERGED

owner: OPENCODE

contributors:

* OPS

branch: TASK-073G-referral-future-proofing-contribution-architecture-notes

priority: MEDIUM

created_at: 2026-05-13 22:40:55 Europe/Paris
updated_at: 2026-05-13 22:42:30 Europe/Paris

labels:

* referral
* contribution
* architecture
* documentation
* future-proofing
* anti-drift

lock:
  status: UNLOCKED
  agent: null
  since: null

handoff: false

pr:
  status: NOT_READY
  url: null
---------

# Objective

Documenter l'architecture future du système de referral / contribution sans implémenter de nouvelle fonctionnalité.

T073G est une tâche documentation / architecture uniquement.

Objectif produit :
Stabiliser ce que le referral prépare pour plus tard, tout en empêchant la dérive vers :

* moteur de réputation prématuré,
* contribution engine complet prématuré,
* graph social,
* IA relationnelle non cadrée,
* leaderboard,
* gamification agressive,
* CRM de prospection,
* MLM,
* rôles techniques complexes non nécessaires au MVP.

---

# Current Context

TASK-073A/B/C/D/E/F sont DONE / MERGED.

Le système actuel dispose déjà de :

* fondations referral,
* logique d'attribution,
* rewards niveau 1 / niveau 2,
* configuration des valeurs de rewards,
* UX membre minimale,
* UX admin minimale,
* visibilité membre améliorée via dashboard, /points et navigation.

T073G ne doit rien ajouter au runtime.

T073G doit seulement documenter ce que cette architecture prépare pour plus tard.

---

# Documentation Goals

T073G doit clarifier :

1. Ce que le système actuel permet déjà.
2. Ce qu'il prépare sans l'implémenter.
3. Les limites anti-dérive.
4. La séparation entre :

   * points transactionnels,
   * rewards d'invitation,
   * reconnaissance symbolique,
   * badges futurs,
   * contributions futures.
5. La place possible future des badges :

   * Pioneer
   * Connector
   * Ambassador
   * Looper
6. La définition du Looper :

   * facilitateur humain,
   * membre moteur,
   * pas un rôle hiérarchique,
   * pas un grade commercial,
   * pas un statut MLM.
7. Les futures extensions possibles :

   * Contribution Engine,
   * IA Connector,
   * Welcome Agent,
   * aide à la reconnexion humaine,
   * synthèse d'activité collective,
   * recommandations douces.
8. Les garde-fous :

   * pas de leaderboard public agressif,
   * pas de classement des parrains,
   * pas de commissions,
   * pas de revenu passif,
   * pas de niveaux MLM,
   * pas de pression sociale,
   * pas de captation d'attention,
   * pas de gamification toxique.
9. Le lien avec la vision produit :

   * entraide,
   * transmission,
   * progression,
   * mouvement collectif,
   * qualité relationnelle,
   * réduction de charge mentale.
10. Le lien avec l'architecture cible :

* Organization = Tenant,
* Loop != Tenant,
* Member appartient à une Organization,
* les contributions peuvent être contextualisées dans une Loop mais ne doivent pas redéfinir le tenant.

---

# Absolute Constraints

* Documentation uniquement.
* Ne pas modifier de code applicatif.
* Ne pas créer de migration.
* Ne pas créer de tests.
* Ne pas modifier RewardDispatcher.
* Ne pas modifier config/referral.php.
* Ne pas modifier les rewards.
* Ne pas modifier le point ledger.
* Ne pas modifier l'UX.
* Ne pas ajouter de package.
* Ne pas ajouter Livewire.
* Ne pas introduire "Community" côté produit/utilisateur, sauf pour parler du legacy technique.
* Préserver le vocabulaire officiel :

  * Organization = Tenant
  * Loop != Tenant
  * Member
  * Contribution
  * Interaction

---

# Planned Actions

* [ ] inspect existing documentation structure
* [ ] decide best documentation location
* [ ] create future-proofing architecture note
* [ ] document anti-drift rules
* [ ] document future extensions without implementation
* [ ] document boundaries between points, rewards, badges and contributions
* [ ] update TASK progress log
* [ ] prepare review notes
* [ ] keep all changes documentation-only

---

# Candidate Documentation Location

To be decided by CODE after inspection.

Possible locations:

* docs/
* ai/context/
* TODO/ only if the note should remain task-scoped

Preferred direction:
Create a stable human/product architecture document under docs/ if consistent with existing documentation.

---

# Progress Log

## 2026-05-13 22:40:55 Europe/Paris

TASK-073G created manually by OPS.

Owner:
OPENCODE

Branch:
TASK-073G-referral-future-proofing-contribution-architecture-notes

Status:
IN_PROGRESS

Initial scope:
Documentation / architecture only. No application code.

---

## 2026-05-13 22:41:00 Europe/Paris

OPENCODE started.

Actions:
- Read TASK file
- Read AGENTS.md, CLAUDE.md, ai/environment.md
- Read docs/02-PRODUCT_PRINCIPLES.md
- Read docs/06-DOMAIN_ARCHITECTURE_V2.md
- Read docs/07-GLOSSARY.md
- Inspected docs/ directory structure

Findings:
- docs/ contains 09 files: 01 through 09-ORGANIZATION_MIGRATION_EXECUTION_PLAN.md
- Next available number: 10
- Documentation style: sober, structured, English terminology, product/architecture oriented
- Preferred vocabulary: Organization, Loop, Member, Contribution, Tenant

Decision:
- Location: docs/10-REFERRAL_CONTRIBUTION_FUTURE_PROOFING.md
- Document type: Architecture Note — Future-Proofing
- Scope: Referral & Contribution architecture boundaries, anti-drift rules, product intent

---

## 2026-05-13 22:41:30 Europe/Paris

Document created: docs/10-REFERRAL_CONTRIBUTION_FUTURE_PROOFING.md

Sections written:
1. Purpose — establishes the document as a stabilization note, not a spec
2. Current Referral MVP — describes existing functionality with assumed limitations
3. Product Intent — frames referral as mutual aid, not acquisition
4. Conceptual Boundaries — separates points, rewards, badges, contributions, reputation
5. Anti-Drift Rules — explicitly forbids MLM, commissions, leaderboards, gamification
6. Future Contribution Engine — conceptual shape only, no implementation
7. Symbolic Recognition & Badges — Pioneer, Connector, Ambassador, Looper concepts
8. Looper Definition — human facilitator, not commercial grade or hierarchy
9. AI Future Extensions — possible capabilities with strict design rules
10. Architecture Alignment — Organization=Tenant, Loop≠Tenant, Contribution scoping
11. Implementation Boundaries — explicit out-of-scope list
12. Future Decision Gates — validation gates for any future implementation
Appendices — vocabulary reference, product philosophy alignment

Validation:
- No application code modified ✓
- No migration created ✓
- No test created ✓
- No runtime behavior changed ✓
- Vocabulary respects Organization/Loop/Member/Contribution canonical terms ✓
- No "Community" used as product concept (only legacy reference in section 10) ✓
- No anti-drift rules violated ✓
- No MLM, commission, passive income, or aggressive leaderboard introduced ✓

---

## 2026-05-13 22:42:30 Europe/Paris

TASK-073G merged into develop by OPS.

Actions:
- check-task.sh PASSED
- finalize-task.sh PASSED
- merge-task.sh: --no-ff merge commit 357618a
- origin develop pushed
- CI develop: queued (PostgreSQL CI)
- TASK status updated to MERGED
- TASK-073-STATUS.md updated: T073G moved to Completed section

Validation:
- No application code modified ✓
- No migration created ✓
- No test created ✓
- No runtime behavior changed ✓
- YAML frontmatter indentation fixed ✓

---

# Handoffs

No handoff. OpenCode completed the full task.

---

# Tests

* [x] not applicable — documentation-only task
* [x] no application code modified
* [x] no migration created
* [x] no runtime behavior changed

---

# Test Results

Documentation-only validation:

- [x] No application file modified (verified: git diff shows only docs/ and TODO/)
- [x] No migration created
- [x] No test created
- [x] No runtime behavior changed
- [x] Document does not introduce contradictory concept: Loop = Tenant
- [x] Document does not introduce MLM
- [x] Document does not introduce commission
- [x] Document does not introduce passive income
- [x] Document does not introduce aggressive leaderboard
- [x] Document respects canonical vocabulary: Organization, Loop, Member, Contribution, Tenant
- [x] Document uses "Community" only for legacy technical reference (section 10)

---

# Review Notes

Document style matches existing docs/ conventions:
- Sober, structured markdown
- English terminology for system concepts
- Architecture-oriented, not marketing
- References existing architecture documents
- Clear conceptual boundaries

Vocabulary confirms:
- Organization = Tenant (explicitly stated in section 10)
- Loop ≠ Tenant (explicitly stated in section 10)
- Member belongs to one Organization
- Contributions stay Organization-scoped

Anti-drift rules are explicit and comprehensive (section 5).

Future directions are marked as NOT IMPLEMENTED consistently.

Decision gates in section 12 provide clear validation requirements for future work.

This document is stable for human and AI consumption.
