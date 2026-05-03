# TASKS.md — Tableau de bord global Entraide

> **Ce fichier est maintenu par l'orchestrateur (Claude Cowork) uniquement.**
> Les agents ne modifient pas ce fichier — ils mettent à jour leur propre `TODO_[Agent].md`.
>
> Pour les détails de chaque tâche (fichiers, description, règles) :
> voir `TODO_Jules.md`, `TODO_WSL.md`, `TODO_ClaudeOnline.md`.

---

## En cours / À faire

| ID | Tâche | Agent | Branche | Statut |
|---|---|---|---|---|
| TASK-016 | SEO avancé : OG meta tags + JSON-LD schema.org | Claude Code WSL | `claude/TASK-016` | `TODO` |
| TASK-019 | Export CSV historique transactions | Claude Code WSL | `claude/TASK-016` (même branche) | `TODO` |
| TASK-017 | Dark mode toggle persistant | Jules | `jules/TASK-017` | `TODO` |
| TASK-018 | Graphique historique du solde de points | Jules | `jules/TASK-018` | `TODO` |

---

## Bloqué

| ID | Tâche | Raison |
|---|---|---|
| TASK-014 | Notifications temps réel (broadcast) | Nécessite serveur WebSocket en prod |

---

## ✅ Fusionné dans main

| ID | Tâche | Agent | PR | Date |
|---|---|---|---|---|
| — | MVP complet (services, transactions, messagerie, dashboard) | Claude Code | — | 2026-04-28 |
| — | Système de notation (reviews) | Claude Code | — | 2026-04-29 |
| — | Favoris + historique points + signalements | Claude Code | — | 2026-04-29 |
| — | Back office admin complet | Claude Code | — | 2026-04-30 |
| — | Avatar upload + redimensionnement | Jules | PR #1 | 2026-04-30 |
| — | Bio + localisation profil | Jules | PR #1 | 2026-04-30 |
| — | Images de service (max 5, 2 Mo) | Jules | PR #1 | 2026-04-30 |
| TASK-001 | Middleware bannissement `banned_at` | Claude Code WSL | PR #2 | 2026-05-01 |
| TASK-002 | Thumbnail automatique images service | Claude Code WSL | PR #4 | 2026-05-01 |
| TASK-003 | Tests Livewire Explorer + MessageThread | Claude Code WSL | PR #3 | 2026-05-01 |
| TASK-004 | Tests panneau admin (25 tests) | Claude Code WSL | PR #5 | 2026-05-01 |
| TASK-005 | Notifications email (bienvenue, transaction, message) | Claude Code WSL | PR #10 | 2026-05-01 |
| TASK-007 | Sitemap XML dynamique + robots.txt | Claude Code WSL | PR #6 | 2026-05-01 |
| TASK-008 | Marquer messages comme lus + badge navbar | Claude Code WSL | PR #7 | 2026-05-01 |
| TASK-009 | Filtre note minimum dans l'explorateur | Claude Code WSL | PR #8 | 2026-05-01 |
| TASK-011 | Recherche globale dans la navbar | Claude Code WSL | PR #13 | 2026-05-01 |
| TASK-013 | API REST authentifiée (Sanctum) | Claude Code WSL | PR #11 | 2026-05-01 |
| TASK-015 | Gamification — badges automatiques | Claude Code WSL | PR #14 | 2026-05-01 |
| — | Tests API, badges, auth, search (47 tests) | Claude Code WSL | PR #15 | 2026-05-01 |

---

> **État actuel :** 169 tests passent · 0 échec · `main` propre (commit `37b95c9`)
