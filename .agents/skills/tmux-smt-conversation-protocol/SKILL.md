# Skill — Tmux SMT Conversation Protocol

## Purpose

Define the local multi-session communication protocol for ORCHESTRATOR, CODEUR and VERIFICATOR.

This skill is used when agents coordinate through tmux and `ai-local/conversations/`.

## Core Rule

Tmux is only for short signals.

Long prompts, execution reports, verification reports and decisions are written in the current conversation file.

## Vocabulary

* SMT = Short Message via Tmux
* ORCHESTRATOR = context keeper and workflow coordinator
* CODEUR = execution agent
* VERIFICATOR = verification agent
* Conversation = one markdown file per TASK in `ai-local/conversations/`
* TASK file = operational source of truth in `TODO/`

## Source hierarchy

1. `AGENTS.md`
2. Current TASK file in `TODO/`
3. Current git branch and real code
4. Current conversation in `ai-local/conversations/`
5. Role README in `ai-local/roles/`
6. `docs/` and `ai/` as needed

Note:
`docs/` can contain historical material. Always check the TASK file, the current branch and the real code before treating a document as current.

## Folder convention

Conversation files live in:

`ai-local/conversations/`

Filename pattern:

`YYYYMMDD-HHhMM-TASK-XXX-slug-court.md`

Template:

`ai-local/conversations/00000000-00h00-TEMPLATE-conversation.md`

## SMT format

Use this format:

`[YYYY-MM-DD HH:MM][TASK-XXX][branch:TASK-XXX-slug][FROM→TO][STATUS]`
`Short action message. Read/write in ai-local/conversations/<file>.md`

Allowed statuses:

* ACTION
* ACK
* DONE
* BLOCKED
* REVIEW
* OK
* OK_WITH_RESERVES
* NEED_DECISION
* MERGE_READY

## Conversation entry format

Each conversation entry must contain:

* SMT
* FROM
* TO
* DATE
* HOUR
* OBJECT
* ATTACH
* MESSAGE

## ORCHESTRATOR rules

ORCHESTRATOR:

* receives Cyril's instructions
* creates or identifies the TASK context
* initiates the conversation file
* writes long prompts in the conversation
* sends short SMT signals via tmux
* reads CODEUR and VERIFICATOR answers in the conversation
* maintains Current state, Next action and Open decisions
* coordinates check, finalize and merge when appropriate

ORCHESTRATOR must not:

* code directly
* run heavy verification directly
* replace CODEUR
* replace VERIFICATOR
* use Cyril as a message bus when tmux sessions can communicate
* launch destructive DB commands without explicit Cyril approval

## CODEUR rules

CODEUR:

* reads `AGENTS.md`
* reads the current TASK file
* reads the current conversation
* follows only the latest instruction addressed to CODEUR
* executes the bounded work
* updates the TASK file if the task is tracked
* runs only proportionate tests
* replies in the same conversation
* sends a short SMT to ORCHESTRATOR

CODEUR must not:

* expand scope
* refactor unrelated files
* merge without validation
* launch destructive DB commands without explicit Cyril approval

## VERIFICATOR rules

VERIFICATOR:

* reads `AGENTS.md`
* reads the current TASK file
* reads the current conversation
* verifies only the requested perimeter
* may consult `docs/`, `ai/`, `codebase`, current branch and real code
* replies in the same conversation
* gives a clear verdict: OK, OK_WITH_RESERVES or BLOCKED
* sends a short SMT to ORCHESTRATOR

VERIFICATOR must not:

* fix code unless explicitly asked
* refactor
* expand the verification scope
* launch destructive DB commands without explicit Cyril approval

## Examples

ORCHESTRATOR to CODEUR:

`[2026-06-03 22:30][TASK-207][branch:TASK-207-smt-protocol][ORCH→CODEUR][ACTION]`
`Lis la section ORCH→CODEUR dans ai-local/conversations/20260603-22h30-TASK-207-smt-protocol.md. Travail borné. Réponds dans ce fichier.`

CODEUR to ORCHESTRATOR:

`[2026-06-03 23:00][TASK-207][branch:TASK-207-smt-protocol][CODEUR→ORCH][DONE]`
`Travail terminé. Réponse ajoutée dans la conversation. TASK file mis à jour.`

ORCHESTRATOR to VERIFICATOR:

`[2026-06-03 23:05][TASK-207][branch:TASK-207-smt-protocol][ORCH→VERIF][REVIEW]`
`Vérifie la dernière réponse CODEUR dans la conversation. Ne corrige pas.`

VERIFICATOR to ORCHESTRATOR:

`[2026-06-03 23:20][TASK-207][branch:TASK-207-smt-protocol][VERIF→ORCH][OK_WITH_RESERVES]`
`Verdict ajouté dans la conversation. Lecture ORCH requise avant suite.`

## Anti-patterns

Never:

* paste long prompts into tmux
* paste long reports into tmux
* create separate report files when the task conversation is the active thread
* let ORCHESTRATOR code directly
* let VERIFICATOR correct directly
* use Cyril as the relay between sessions
* treat `docs/` as always up to date without checking branch/TASK/code
* run destructive DB commands without explicit Cyril approval

## DB safety

Forbidden without explicit Cyril approval:

* `php artisan migrate:fresh`
* `php artisan db:wipe`
* `php artisan migrate --force`
* `pg_restore --clean`
* production dump/import/sync commands
* local destructive PostgreSQL reset
* any script that can overwrite local or production data