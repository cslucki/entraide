---
file: README.md
created_at: 2026-05-27 21:00:46 CEST
updated_at: 2026-05-28 21:00 CEST
type: master_prompt
status: active
---

# OpenCode Orchestrator

This directory is the only writable area for the Windows-launched OpenCode orchestration instance.

## Scope

- Allowed writable path: `/home/cyril/claude-code/sites/test.laravel/.ai-local/orchestrator`
- Do not modify the Laravel project outside this directory without explicit user authorization.
- Do not modify the parent project `opencode.json` for orchestration needs.
- Treat the parent Laravel workspace as read-only unless the user explicitly grants write permission for a specific task.

## Runtime

The current OpenCode process is launched from Windows (`win32`) while the Laravel project lives in WSL at:

`/home/cyril/claude-code/sites/test.laravel`

This means local MCP commands such as `npx` run in the Windows environment unless explicitly wrapped with `wsl.exe`.

For orchestration MCPs, prefer Windows-side tools because this OpenCode instance is started from Windows. Use WSL only when intentionally targeting the Laravel runtime.

## Isolated Launch

From Windows PowerShell, launch the orchestrator with project config disabled and this dedicated config enabled:

```powershell
$env:OPENCODE_DISABLE_PROJECT_CONFIG = "1"
$env:OPENCODE_CONFIG = "\\wsl.localhost\Ubuntu\home\cyril\claude-code\sites\test.laravel\.ai-local\orchestrator\opencode.orchestrator.json"
opencode "\\wsl.localhost\Ubuntu\home\cyril\claude-code\sites\test.laravel\.ai-local\orchestrator"
```

This avoids loading the Laravel project's parent `opencode.json` and keeps orchestration configuration local to this folder.

## TASK File Creation Rule (CRITICAL LESSON — T151-T156 Gap)

**L'ORCHESTRATOR DOIT vérifier que SUPERVISOR créé un TASK file via `create-task.sh` à chaque nouvelle branche.**

Failure mode: durant la chaîne T151-T156, SUPERVISOR a créé 6 branches sans TASK files dans `TODO/`. Gap documentaire : git avait l'historique, les rapports existaient, mais `TODO/` s'arrêtait à T150.

Enforcement :
- L'instruction de création de branche DOIT inclure : `Run ai/scripts/create-task.sh '<title>' SUPERVISOR`
- L'ORCHESTRATOR vérifie : `ls TODO/ | grep TASK-NNN`
- Cette règle s'applique à TOUS les agents

## Scripts-Orchestrator Zone

L'ORCHESTRATOR peut écrire des scripts dans `.ai-local/orchestrator/scripts-orchestrator/` pour des transformations de données exceptionnelles ou des helpers ponctuels.

Règles :
- PHP, Shell, Python — tout langage est autorisé
- **Interdit formellement d'écrire du code applicatif en dehors de ce dossier sauf demande explicite de Cyril**
- Si Cyril demande d'écrire du code ailleurs, l'ORCHESTRATOR doit répondre "pourquoi ?" avant d'exécuter (Cyril peut aussi faire des erreurs)
- Les scripts dans `ai/scripts/` sont l'infrastructure outillage (shell uniquement) — pas du code applicatif

Scripts existants :
- `pg-sync-transform.php` — transformation post-restore prod→Schema 2

## Archive en fin de run
À chaque fin de run, archiver `working/current-run.md` dans `archive/` pour préserver la continuité.

## MCP Policy

- MCPs configured here are for orchestration, research, browser automation, and web access.
- Do not store secrets or tokens in this repository.
- Prefer OAuth or environment variables for credentials.
- GitHub access should use the remote GitHub MCP without hardcoded tokens unless the user explicitly chooses another method.

## Cyril & ORCHESTRATOR Working Agreement

**Read this before anything else on each session.**

```text
WORKING_AGREEMENT_CYRIL_AND_ORCHESTRATOR.md
```

This file records our shared rules, communication patterns, and learnings. It evolves with every correction or feedback Cyril gives me. **Cyril can point to this file to verify I follow what we've agreed.**

**Dates de mise à jour = critique.** Le fichier affiche :
- `updated_at` dans le frontmatter YAML
- "Dernière mise à jour" en haut du contenu
- Un journal des mises à jour complet en annexe

## Working-State Workflow

ORCHESTRATOR maintains its own local working state inside `working/`.

- `WORKING_AGREEMENT_CYRIL_AND_ORCHESTRATOR.md` — our shared rules and learnings (read first)
- `working/current-run.md` describes the immediate action currently being performed.
- `working/current-focus.md` describes the broader active workstream.
- `TODO.md` tracks ORCHESTRATOR tasks and must use real system timestamps only.
- `skills/` stores stabilized reusable learnings.
- `archive/` stores resolved historical material.
- `ideas/` stores interesting ideas that are not active now.
- `map/` stores navigation, indexes, and glossary material.

The working-state files are not project TASK files. They are ORCHESTRATOR's local memory and must not replace official project governance.

## TMUX Supervisor Protocol

The stabilized protocol lives in:

```text
skills/tmux-supervisor-protocol.md
```

Current validated escalation ladder:

1. Observation only with `tmux capture-pane`.
2. `STOP CHECKPOINT` for non-critical correction, handled on the next turn.
3. `Esc Esc` as the validated soft interruption sequence in sandbox `supervisor-test`.
4. `Ctrl+C` as hard emergency interruption.
5. Second `Ctrl+C` or `tmux kill-session` only as last resort.

Protocol tests must stay in sandbox session `supervisor-test`. The real project session `supervisor` remains observation-only unless Cyril explicitly authorizes control.

## Supervisor Report Protocol (Written Reports)

As an alternative to `tmux capture-pane` (which suffers from truncation), PROJECT_SUPERVISOR can write structured markdown reports to a shared directory.

### Directory

```text
.ai-local/supervisor/report-to-orchestrator/
```

### How It Works

1. ORCHESTRATOR asks Supervisor for a written report (instead of relying on tmux output).
2. Supervisor writes a markdown file into `report-to-orchestrator/` following the template in `report-to-orchestrator/README.md`.
3. ORCHESTRATOR reads the file directly.

### When To Use

Use written reports when:

- `tmux capture-pane` output is truncated or unreliable.
- The report needs structured data (frontmatter, sections, timestamps).
- Supervisor needs to share context that ORCHESTRATOR will reference later.
- A persistent audit trail is needed.

### Request Template

When ORCHESTRATOR needs a report:

> Superviseur, fais-moi un rapport dans ".ai-local/supervisor/report-to-orchestrator/"
> et respecte le README.md qui explique comment rediger ton rapport.

### File Format

See the full template at:

```text
.ai-local/supervisor/report-to-orchestrator/README.md
```

## Live Documentation Sync

ORCHESTRATOR is responsible for syncing documentation from the standalone live-doc project to `test.laravel/live-documentation/`.

See `test.laravel/live-documentation/README.md` for the full protocol.

Key rules:
- Only sync when the main project is on `develop`.
- Never sync during active feature branches.
- The live-doc project is independent (`/home/cyril/claude-code/sites/live-documentation-for-bouclepro/`).
