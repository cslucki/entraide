# Entraide — AGENTS.md

> **Contexte technique complet : voir `CLAUDE.md`.**
> Ce fichier décrit uniquement le workflow multi-agent et les règles de coordination.

---

## Fichiers de référence

| Fichier | Rôle | Qui écrit |
|---|---|---|
| `CLAUDE.md` | Stack, archi, conventions, commandes | Orchestrateur / humain uniquement |
| `AGENTS.md` | Ce fichier — rôles et workflow multi-agent | Orchestrateur / humain uniquement |
| `TASKS.md` | Tableau de bord global (statuts, PRs) | Orchestrateur uniquement |
| `TODO_Jules.md` | Backlog Jules (frontend) | **Jules uniquement** |
| `TODO_WSL.md` | Backlog Claude Code WSL (backend) | **Claude Code WSL uniquement** |
| `TODO_ClaudeOnline.md` | Backlog Claude Code en ligne (rare) | **Claude Code en ligne uniquement** |
| `TODO_OpenCode.md` | Backlog OpenCode (futur) | **OpenCode uniquement** |
| `TODO_GLM.md` | Backlog GLM (fixes UI/vues) | **GLM uniquement** |

**Règle absolue : chaque agent ne modifie QUE son propre fichier TODO.**
Cela élimine tous les conflits de merge.

---

## Rôles par agent

| Agent | Domaine | Fichier TODO |
|---|---|---|
| **Jules** | Frontend, vues Blade, Alpine.js, CSS, Chart.js, SEO vues | `TODO_Jules.md` |
| **Claude Code WSL** | Backend PHP/Laravel, controllers, migrations, tests, routes, API | `TODO_WSL.md` |
| **Claude Code en ligne** | Architecture, backup (rarement utilisé) | `TODO_ClaudeOnline.md` |
| **OpenCode** | Futur | `TODO_OpenCode.md` |
| **GLM** | Fixes UI/vues, corrections visuelles | `TODO_GLM.md` |
| **Claude Cowork** | Orchestration, merge PRs, validation, mise à jour TASKS.md | — |

---

## Workflow obligatoire pour chaque agent

### Avant de commencer
1. Lire **son fichier TODO** (ex : `TODO_Jules.md` pour Jules)
2. Lire `CLAUDE.md` pour les conventions techniques
3. Prendre une tâche en statut `TODO`
4. Mettre la tâche en `IN_PROGRESS` dans **son fichier TODO** (noter la branche)
5. Créer la branche : `git checkout -b <agent>/TASK-XXX` depuis un `main` à jour

### Pendant le travail
- Toucher **uniquement les fichiers listés** dans la tâche
- Si d'autres fichiers sont nécessaires → noter l'écart dans le corps de la PR

### Quand la tâche est terminée
1. Mettre la tâche en `IN_REVIEW` dans **son fichier TODO**
2. Ouvrir une PR vers `main` avec un titre clair
3. **Ne jamais pousser directement sur `main`**

---

## Conventions de branches

| Agent | Préfixe de branche |
|---|---|
| Claude Code WSL | `claude/TASK-XXX` |
| Jules | `jules/TASK-XXX` |
| GLM | `glm/TASK-XXX` |
| OpenCode | `opencode/TASK-XXX` |

---

## Pourquoi des fichiers TODO séparés ?

- Un seul fichier partagé + deux agents = conflit de merge garanti
- Chaque agent écrit uniquement dans son fichier → zéro conflit
- L'orchestrateur (Claude Cowork) maintient `TASKS.md` comme vue globale

---

## Pour Gemini CLI

Lire `CLAUDE.md` pour le contexte technique complet.
Ce fichier (`AGENTS.md`) couvre uniquement les règles de coordination.
