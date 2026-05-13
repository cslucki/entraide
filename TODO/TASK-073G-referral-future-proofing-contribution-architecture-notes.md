---
task_id: TASK-073G
title: Referral Future-Proofing / Contribution Architecture Notes

status: IN_PROGRESS

owner: OPENCODE

contributors:

* OPS

branch: TASK-073G-referral-future-proofing-contribution-architecture-notes

priority: MEDIUM

created_at: 2026-05-13 22:40:55 Europe/Paris
updated_at: 2026-05-13 22:40:55 Europe/Paris

labels:

* referral
* contribution
* architecture
* documentation
* future-proofing
* anti-drift

lock:
status: LOCKED
agent: OPENCODE
since: 2026-05-13 22:40:55 Europe/Paris

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

# Handoffs

Pending.

---

# Tests

* [ ] not applicable — documentation-only task
* [ ] no application code modified
* [ ] no migration created
* [ ] no runtime behavior changed

---

# Test Results

Pending.

---

# Review Notes

Pending.
