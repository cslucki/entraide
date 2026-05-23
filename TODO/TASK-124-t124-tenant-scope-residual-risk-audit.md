---
task_id: TASK-124
title: t124-tenant-scope-residual-risk-audit

status: MERGED

owner: CODEX

contributors: []

branch: TASK-124-t124-tenant-scope-residual-risk-audit

priority: MEDIUM

created_at: 2026-05-23 21:20:33 Europe/Paris
updated_at: 2026-05-23 21:59:59 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: null
  since: null

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Produire un audit durable des risques tenant-scope résiduels, sans patch runtime, autour des usages `withoutGlobalScope`, des routes publiques Organization-scoped, de `community_id` vs `organization_id`, de `BelongsToTenantScope`, des gaps de tests et de la cohérence documentaire.

---

# Planned Actions

- [x] Stabiliser et documenter l'utilisation CAO pour supervision locale
- [x] Lancer agents CAO read-only
- [x] Consolider les résultats agents et audit séquentiel si fallback nécessaire
- [x] Produire `docs/audits/T124-tenant-scope-residual-risk-audit.md`
- [x] Mettre à jour TASK-124 avec tests, commandes et notes
- [x] Finaliser commit/push sans merge
- [x] Merge après validation cockpit

---
# Progress Log


## 2026-05-23 21:20:33 Europe/Paris

Task created.

Owner:
CODEX

Branch:
TASK-124-t124-tenant-scope-residual-risk-audit

Status:
IN_PROGRESS

## 2026-05-23 21:29:05 Europe/Paris

CAO stability/terminal diagnosis before residual audit:
- Investigated failed CAO terminal `0033046b`.
- Observed symptom: `open terminal failed: terminal does not support clear` / connection closed.
- Confirmed local shell can expose `TERM=dumb`; `clear` fails there, while `TERM=xterm-256color clear` succeeds.
- Consulted official CAO docs: CAO agents run in tmux; OpenCode provider is documented as experimental; terminal output is available through `/terminals/<id>/output`.
- Verified CAO server was actually healthy outside the Codex network sandbox: 5/5 `GET /health` checks returned `{"status":"ok","service":"cli-agent-orchestrator"}`.
- Identified false-negative risk: sandboxed Codex `curl` can report connection failure although the host CAO server is live.
- Cleaned failed CAO session `cao-t124-withoutglobalscope-audit` via `cao shutdown --session`.
- Documented stabilization procedure in `ai/orchestrator/README.md`.

Modified files:
- `ai/orchestrator/README.md` — CAO Live/Offline and `TERM=dumb`/`clear` troubleshooting.
- `TODO/TASK-124-t124-tenant-scope-residual-risk-audit.md` — progress log update.

## 2026-05-23 21:33:35 Europe/Paris

CAO supervision path validated:
- `cao-server` health OK outside sandbox.
- `cao session list` OK outside sandbox.
- OpenCode provider remains unstable for these sessions (`Unexpected server error` during startup).
- CAO + provider `codex` probe succeeded: session `cao-t124-codex-cao-probe`, terminal `da560eff`, response `CAO_CODEX_PROBE_OK` retrieved through `/terminals/da560eff/output`.
- Worker Codex reported `MCP startup interrupted` for `cao-mcp-server`; supervision will therefore rely on parent-side CAO CLI/API polling and terminal output extraction.
- Probe session cleaned with `cao shutdown --session cao-t124-codex-cao-probe`.

Decision:
- Use CAO explicitly for TASK-124 agents, with `--provider codex`, one launch at a time to avoid server/UI churn.
- Keep all agents read-only and collect results through cao-server.

## 2026-05-23 21:36:16 Europe/Paris

CAO TERM inheritance fix:
- User reported the same `open terminal failed: terminal does not support clear` on successful probe terminal `da560eff`.
- Verified that `env TERM=xterm-256color cao launch ...` is insufficient when `cao-server` itself was started with `TERM=dumb`; tmux still received `-eTERM=dumb`.
- Restarted `cao-server` with `env TERM=xterm-256color COLORTERM=truecolor _bash_cyril/cao/cao-glm-start`.
- Probe session `cao-t124-term-server-probe`, terminal `ee2728bf`, confirmed tmux process contains `-eTERM=xterm-256color`.
- Probe response `TERM_SERVER_PROBE_OK` retrieved via `/terminals/ee2728bf/output`.
- Probe cleaned with `cao shutdown --session cao-t124-term-server-probe`.
- Updated `ai/orchestrator/README.md` to document that TERM must be fixed at `cao-server` startup, not only at `cao launch`.

## 2026-05-23 21:37:30 Europe/Paris

Supervisor diagnostic update after user-provided CAO analysis:
- Re-verified `cao-server` is live: `/health` returns OK.
- Re-verified active `cao-server` environment through `/proc/<pid>/environ`: `TERM=xterm-256color`, `COLORTERM=truecolor`.
- `cao session list` returns no active sessions.
- Checked local tmux version: `/usr/bin/tmux` is `3.2a` (`apt` candidate also `3.2a` on Ubuntu Jammy).
- Documented tmux version as a residual terminal-stability risk in `ai/orchestrator/README.md`.

## 2026-05-23 21:44:00 Europe/Paris

CAO stale terminal/window note:
- User reported lingering CAO input error: `can't find window: audit-scope-policies-4f7a` for terminal `bca22b09`.
- Confirmed `cao session list` is empty while `cao-server` health remains OK, so this is treated as a stale tmux/window reference after the failed Agent 3 launch (`Shell initialization timed out after 10 seconds`), not as current server downtime.
- Documented the cleanup/retry interpretation in `ai/orchestrator/README.md`.
- Continued TASK-124 plan with sequential CAO launches only.

## 2026-05-23 21:54:24 Europe/Paris

TASK-124 audit completed:
- CAO Agent 1 (`cao-t124-agent1-withoutglobalscope`, terminal `f36d73f8`) completed the `withoutGlobalScope` classification.
- CAO Agent 2 (`cao-t124-agent2-public-routes`, terminal `0605570f`) completed the public routes audit.
- CAO Agent 3 first launch (`bca22b09`) failed/staled after shell initialization timeout; relaunch terminal `0e27034c` completed and output was recovered from terminal logs.
- CAO Agent 4 (`cao-t124-agent4-tests-gap`, terminal `73ada517`) completed the tests gap audit.
- CAO Agent 5 (`cao-t124-agent5-docs-consistency`, terminal `1412e79d`) completed the documentation consistency audit.
- Produced `docs/audits/T124-tenant-scope-residual-risk-audit.md`.
- Updated `ai/orchestrator/README.md` with the CAO `TERM=dumb`/`clear` fix and stale tmux window handling.
- No runtime application files modified.

Modified files:
- `docs/audits/T124-tenant-scope-residual-risk-audit.md`
- `TODO/TASK-124-t124-tenant-scope-residual-risk-audit.md`
- `ai/orchestrator/README.md`

Status:
- Marked DONE.
- Lock released for finalization.

## 2026-05-23 21:59:59 Europe/Paris

TASK-124 merged after cockpit validation:
- Verified source branch `TASK-124-t124-tenant-scope-residual-risk-audit`.
- Verified clean git status before merge.
- Verified branch diff limited to:
  - `docs/audits/T124-tenant-scope-residual-risk-audit.md`
  - `TODO/TASK-124-t124-tenant-scope-residual-risk-audit.md`
  - `ai/orchestrator/README.md`
- Merged into `develop` via `./ai/scripts/merge-task.sh TASK-124`.
- Pushed `develop` to origin.
- Marked TASK-124 as MERGED.

# Handoffs

# Tests

- [ ] feature tests
- [ ] browser validation
- [ ] responsive validation
- [ ] console inspection
- [x] tenant validation

---

# Test Results

- 2026-05-23 21:28:54-21:28:58 Europe/Paris — CAO health check outside sandbox: 5/5 OK.
- 2026-05-23 21:54:24 Europe/Paris — Read-only tenant audit completed. No PHPUnit or Playwright suite run because TASK-124 is audit-only and introduced no runtime code changes.
- 2026-05-23 21:54:24 Europe/Paris — CAO read-only agents completed: 4 direct successes + 1 success after stale terminal fallback (`bca22b09` → `0e27034c`).
- 2026-05-23 21:59:59 Europe/Paris — Merge validation completed via `./ai/scripts/merge-task.sh TASK-124`; no runtime tests required because TASK-124 is audit-only.

---

# Review Notes

- 2026-05-23 21:29:05 Europe/Paris — No runtime application files changed. CAO issue documented before continuing TASK-124 audit.
- 2026-05-23 21:54:24 Europe/Paris — Future tasks in the audit report are proposals to arbitrate, not approved TASK-125/TASK-126/TASK-127 work.
- 2026-05-23 21:59:59 Europe/Paris — TASK-124 closed as MERGED on `develop`; main/PROD not touched.
