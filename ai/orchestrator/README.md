# CAO — CLI Agent Orchestrator

CAO (CLI Agent Orchestrator) est le système d'orchestration multi-agent du projet.

## Installation

Installé via `uv` (gestionnaire Python). Binaires dans `~/.local/bin/` :

```
~/.local/bin/cao          → CLI client
~/.local/bin/cao-server   → Serveur HTTP (port 9889 par défaut)
```

Les binaires sont des symlinks vers `~/.local/share/uv/tools/cli-agent-orchestrator/`.

## Arborescence

```
~/.aws/cli-agent-orchestrator/
├── agent-context/       → Contextes Markdown des agents (frontmatter + instructions)
├── agent-store/         → Store local des profils d'agents
├── db/                  → Base SQLite (sessions, mémoire)
├── logs/                → Logs serveur
├── skills/              → Skills installés (6 skills)
│   ├── cao-session-management/
│   ├── cao-supervisor-protocols/
│   ├── cao-worker-protocols/
│   ├── entraide-domain/
│   ├── git-workflow/
│   └── laravel-conventions/
└── settings.json        → Configuration des dossiers d'agents

~/.kiro/agents/          → Profils d'agents au format JSON (kiro)
```

## Profils d'agents installés

| Profil | Rôle | Provider | Usage |
|--------|------|----------|-------|
| `developer` | Agent développeur générique | — | Multi-agent, reçoit des tâches via assign/handoff |
| `code_supervisor` | Superviseur | kiro_cli | Pilotage de workers |
| `laravel_developer` | Worker Laravel strict | codex | Validation Laravel Boost, skills maison |
| `laravel_supervisor` | Superviseur Laravel | kiro_cli | Test CAO Laravel Boost (assign → laravel_developer) |
| `skill_probe` | Sonde skills | — | Inspection des skills installés |
| `claude_glm_probe` | Sonde GLM | — | Test provider GLM |
| `hello_supervisor` | Superviseur test | kiro_cli | Test basique assign/handoff |
| `audit-blog-scope` | Audit CAO Blog scope | opencode_cli | Inspecte BlogPost, BlogController, AdminBlogController, routes, tests tenant |
| `audit-public-surfaces` | Audit CAO surfaces publiques | opencode_cli | Inspecte ProfileController, routes publiques, /membres, /services, /explorer |
| `audit-scope-policies` | Audit CAO scope/policies | opencode_cli | Inspecte BelongsToTenantScope, withoutGlobalScope, policies |
| `audit-doctrine` | Audit CAO doctrine/docs | opencode_cli | Vérifie cohérence Organization=Tenant dans la documentation |

Les 4 profils `audit-*` ont été créés le 2026-05-23 pour TASK-123. Source : `_bash_cyril/cao/_cao-agents/`.

## Skills installés (6)

- `cao-session-management` — Gestion des sessions CAO (launch, status, shutdown)
- `cao-supervisor-protocols` — Patterns d'orchestration supervisor (assign, handoff)
- `cao-worker-protocols` — Complétion et callbacks worker
- `entraide-domain` — Règles domaine BouclePro (Organization, Loop, Member, legacy Community)
- `git-workflow` — Workflow git et lifecycle des TASK
- `laravel-conventions` — Conventions Laravel (PSR-12, controllers minces, Form Requests)

## Utilisation

### Démarrer le serveur

Via le script dédié (recommandé — kill auto, health check, env GLM) :

```bash
_bash_cyril/cao/cao-glm-start
```

Ou manuellement :

```bash
cao-server &
cao-server --port 9889
```

### Lister les sessions

```bash
cao session list
```

### Lancer un agent (mode headless + async)

```bash
cao launch \
  --agents audit-blog-scope \
  --session-name "audit-mission-1" \
  --headless \
  --async \
  --auto-approve \
  --provider opencode_cli \
  "Instructions pour l'agent..."
```

### Installer un agent depuis un fichier

```bash
cao install .agents/audit-blog-scope.md
```

## Formats de profil

### Fichier agent Markdown (agent-context/)

```yaml
---
name: mon-agent
description: Description courte
role: developer                # developer | supervisor
provider: opencode_cli         # opencode_cli | kiro_cli | codex | claude_code | claude_glm | gemini_cli | etc.

mcpServers:
  cao-mcp-server:              # OBLIGATOIRE — communication avec l'orchestrateur
    type: stdio
    command: uvx
    args:
      - "--from"
      - "git+https://github.com/awslabs/cli-agent-orchestrator.git@main"
      - "cao-mcp-server"

  laravel-boost:               # Optionnel — outils Laravel (DB, routes, logs, docs)
    type: stdio
    command: php
    args:
      - artisan
      - boost:mcp
---

Instructions Markdown pour l'agent...
```

**Règles :**
- `cao-mcp-server` est **obligatoire** dans `mcpServers` — sans lui l'agent ne peut pas communiquer avec l'orchestrateur ni recevoir/envoyer des messages.
- `laravel-boost` est optionnel mais recommandé pour les agents Laravel.
- Le frontmatter doit être en YAML valide (attention aux espaces).
- Les instructions Markdown après le `---` sont le contexte de l'agent (ce qu'il lit au démarrage).

### Fichier agent JSON (kiro)

Généré automatiquement par `cao install`. Stocké dans `~/.kiro/agents/<name>.json`.

Points clés :
- `allowedTools` contrôle les outils accessibles
- `resources` référence le contexte Markdown et les skills
- `mcpServers` définit les serveurs MCP disponibles

## Problèmes connus

### `--auto-approve` ne fonctionne PAS en non-TTY

Dans un environnement non-interactif (OpenCode, shell sans PTY), la confirmation "Proceed? [Y/n]" bloque **même avec `--auto-approve`** et **même avec `yes | cao launch`** car cao lit directement depuis le terminal, pas depuis stdin.

✅ **Solution fiable** : utiliser `--yolo` (supprime toutes les restrictions + confirmation, dangereux — réservé aux agents read-only/audit).

```bash
cao launch --agents "mon-agent" --headless --async --yolo --provider opencode_cli
```

**Important :** `--yolo` n'a pas d'effet sur `opencode_cli` — les permissions sont figées au `cao install`. Pour un vrai déverrouillage, mettre `allowedTools: ["*"]` dans le profil et réinstaller.

**Attention lors de lancements multiples :** cao-server peut saturer si on lance plusieurs agents en parallèle. Certaines sessions peuvent passer en statut `error`. **Lancer un agent à la fois** pour éviter ce problème.

✅ **Alternative** : lancer depuis un terminal TTY interactif (pas depuis OpenCode).

❌ **Ne fonctionne PAS** :
- `--auto-approve` (ignoré en non-TTY)
- `yes | cao launch ...` (stdin est ignoré par cao)
- `echo "Y" | cao launch ...` (idem, bloque quand même)

### `--agents` prend un nom, pas un chemin

`--agents` attend le **nom** de l'agent (stem du fichier .md installé), pas un chemin absolu.

```bash
# ✅ Correct
cao install _bash_cyril/cao/_cao-agents/mon-agent.md
cao launch --agents "mon-agent" ...

# ❌ Faux
cao launch --agents "/chemin/absolu/mon-agent.md" ...
```

### cao-server doit tourner

`cao session list`, `cao launch`, `cao session view` nécessitent que `cao-server` soit en cours d'exécution. Il écoute par défaut sur `127.0.0.1:9889`.

## Source des profils

Les profils d'agents CAO sont éditables dans :

```
_bash_cyril/cao/_cao-agents/   → Profils d'agents (sources .md)
_bash_cyril/cao/_cao-skills/   → Skills personnalisés
```

Ces fichiers sont copiés manuellement vers `~/.aws/cli-agent-orchestrator/agent-context/` (et `agent-store/` pour les JSON kiro) pour activation. Voir le settings.json pour la liste complète des sources additionnelles.

Tout nouveau profil créé doit laisser une trace dans `_bash_cyril/cao/_cao-agents/`.

## Références externes

Un autre projet (`multi-agent.test.laravel`) a des agents CAO additionnels dans `_cao-agents/`. Voir `~/.aws/cli-agent-orchestrator/settings.json` pour la liste complète des sources.
