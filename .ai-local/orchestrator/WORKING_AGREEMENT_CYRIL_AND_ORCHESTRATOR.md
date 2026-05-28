---
file: WORKING_AGREEMENT_CYRIL_AND_ORCHESTRATOR.md
created_at: 2026-05-28 12:30 CEST
updated_at: 2026-05-28 20:00 CEST
type: working_agreement
status: active
---

# Working Agreement — Cyril & ORCHESTRATOR

> **Dernière mise à jour : 2026-05-28 20:00 CEST**
>
> Consulte l'annexe [Journal des mises à jour](#-journal-des-mises-à-jour) pour l'historique complet.

Ce fichier enregistre nos apprentissages communs et nos règles de collaboration.
Il évolue à chaque fois que Cyril corrige ou affine ma façon de travailler.

---

## Communication

- **Les rapports écrits sont préférés** au scraping tmux. SUPERVISOR écrit dans `.ai-local/supervisor/report-to-orchestrator/` avec frontmatter YAML (horodatage, branche, TASK), fichier horodaté.
- **ORCHESTRATOR lit, valide, et demande. SUPERVISOR exécute.** Pas d'action sans demande claire.
- **Les rendez-vous sont fixes.** On se cale un créneau pour les points de synchronisation. Je ne pars pas sans savoir quand on se retrouve.
- **Tmux capture est utile pour l'observation** mais insuffisant pour des rapports longs (troncature). Toujours demander un rapport écrit.

---

## Exécution

- **Migration layer-by-layer** : DB → Models → Middleware → Routes → Controllers → Policies → Livewire/Blade → Tests. Jamais de search/replace géant.
- **Checkpoint après chaque phase** (C0 à C7) : rapport, validation, go de Cyril avant la phase suivante.
- **Cyril donne le go explicite** avant chaque phase. Je ne lance rien sans validation.
- **Vérifier la branche AVANT d'ordonner l'exécution.** SUPERVISOR peut être sur `develop`. Si une branche est nécessaire, le dire explicitement dans l'instruction.
- **Toujours laisser un temps tampon** entre l'envoi d'instruction à SUPERVISOR et l'exécution. SUPERVISOR peut avoir besoin d'analyser avant d'agir.
- **CRITICAL : TASK file obligatoire à chaque branche.** L'ORCHESTRATOR doit vérifier que SUPERVISOR créé un TASK file via `create-task.sh` à chaque nouvelle branche. Vérifier avec `ls TODO/ | grep TASK-NNN` après annonce. Cette règle s'applique à TOUS les agents.
- **Archive en fin de run.** Quand une run est terminée, archiver `working/current-run.md` dans `archive/` pour préserver la continuité entre runs.

---

## Rôles

| Qui | Fait |
|---|---|
| **Cyril** | Décide, valide, donne le cap |
| **ORCHESTRATOR (moi)** | Planifie, demande des rapports, valide les checkpoints, met à jour les procédures |
| **SUPERVISOR** | Exécute le code dans le projet Laravel |
| **REVIEW** | Audit indépendant (lecture seule) |

---

## Mise à jour

Ce fichier est mis à jour par ORCHESTRATOR à chaque nouvel apprentissage issu des retours de Cyril.
Si Cyril pointe un comportement à corriger, ça finit ici.

**Les dates de mise à jour sont cruciales.** Elles figurent :
1. Dans le frontmatter YAML (created_at, updated_at)
2. Dans l'en-tête "Dernière mise à jour" en haut du fichier
3. Dans le journal ci-dessous

---

## 📋 Journal des mises à jour

| Date | Changement |
|---|---|
| 2026-05-28 12:30 CEST | Création initiale |
| 2026-05-28 12:35 CEST | Ajout dates visibles, en-tête "Dernière mise à jour", journal des mises à jour. Appris : les dates doivent être proéminentes et tracées. |
| 2026-05-28 13:00 CEST | Ajout leçons Phase 1 : vérifier branche avant exécution, demander rapport écrit plutôt que tmux, laisser temps tampon. |
| 2026-05-28 20:00 CEST | Ajout leçon CRITICAL : ORCHESTRATOR doit vérifier que SUPERVISOR créé TASK file pour chaque nouvelle branche. Ajout archive en fin de run. Migration T151-T156 terminée. |
