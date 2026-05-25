---
task_id: TASK-144-post-t140-5
title: Post-T140.5 Stabilization — PHPStan + organization_id + Referral queries + Pint

status: IN_PROGRESS

owner: OpenCode
branch: TASK-144-post-t140-5-stabilization
created_at: 2026-05-25 14:41:31 Europe/Paris

lock:
  status: UNLOCKED
  agent: OpenCode
  since: 2026-05-25 14:41:31 Europe/Paris

---

# Post-T140.5 Stabilization

## Objectif
Corriger les 4 priorités identifiées par le Review Cluster post-audit T140.5A-D.

## Ordre
1. **P1** — PHPStan typage Eloquent (10 erreurs)
2. **P2** — organization_id PHPDoc dans User.php
3. **P2B** — 3 Referral queries defense-in-depth (ré-évaluer d'abord)
4. **P3** — Pint (7 violations, après fixes fonctionnels)

## Périmètre autorisé
- Modifications de type PHPDoc uniquement (P1, P2)
- Requêtes Referral scoping optionnel (P2B)
- Corrections de style Pint (P3, si temps)

## Interdit
- Nouveaux features
- Migrations DB
- Refactoring global
- Changements de logique métier

## Sources
- Review Cluster report: `TODO/REVIEW_CLUSTER/REPORT_T1405_A_D.md`
- PHPStan errors: 10 (LoopController:2, LoopService:1, ReferralService:2, RewardDispatcher:5)
