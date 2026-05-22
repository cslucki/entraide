# Prod/Local Sync Strategy

## Executive Summary

Production-to-local synchronization is a controlled operational workflow, not a default development shortcut.

No production database dump, local import, storage sync, migration, cache clear, or Laravel Cloud command is authorized by this document alone. Every real operation requires a dedicated task, explicit approval from Cyril / COCKPIT ROADMAP, and confirmation of the exact command class before execution.

The safe model is phased:

1. Dry-run and preflight checks.
2. Production database dump as a read-only operation.
3. Local PostgreSQL import as a destructive local operation.
4. Optional local stabilization and validation.
5. Targeted media pull, not blind storage mirroring.

---

## Security Principles

- Production is read-only by default.
- Local destructive operations require separate confirmation.
- Secrets must never be printed, committed, logged, copied into TASK files, or stored in tracked docs.
- `.env` must not be read into chat output.
- Dumps and downloaded media must stay in gitignored paths.
- `prod-mirror` must not become the default one-command workflow.
- Rollback instructions must exist before local import starts.

---

## Command Matrix

### Allowed During Read-Only Audit

```bash
git status --short --branch
git branch --show-current
git log --oneline --decorate -20
find ai/scripts -maxdepth 2 -type f
rg -n "dump|sync|Laravel Cloud|pg_dump|pg_restore|storage|PostgreSQL|production" ai docs @DOCS config .env.example
sed -n '1,220p' ai/scripts/pg-dump.sh
sed -n '1,180p' ai/scripts/media-pull.sh
sed -n '1,140p' ai/scripts/switch-db.sh
```

### Requires Explicit Authorization

```bash
./ai/scripts/switch-db.sh status
./ai/scripts/pg-dump.sh list
```

These commands may inspect local environment or filesystem state and must not be used if output could expose secrets.

### Production-Read Authorization Required

```bash
./ai/scripts/pg-dump.sh prod-dump
pg_dump --host=<prod-host> ...
php artisan cloud:db:show
```

### Local-Destructive Authorization Required

```bash
./ai/scripts/pg-dump.sh import <file>
./ai/scripts/pg-dump.sh reset
./ai/scripts/pg-dump.sh prod-mirror
pg_restore --clean ...
php artisan migrate --force
php artisan optimize:clear
```

### Forbidden Without A Dedicated Sync Task

```bash
./ai/scripts/pg-dump.sh prod-mirror
cloud db:*
cloud artisan *
rsync production:...
curl production database endpoints
```

---

## Database Protocol

### Phase 0 - Dry Run

Read-only preflight:

- Confirm current branch is the dedicated task branch.
- Confirm `git status --short --branch`.
- Confirm PostgreSQL client tools are available.
- Confirm local PostgreSQL target exists without printing secrets.
- Confirm `.env.pgsql` exists without printing values.
- Confirm `storage/app/dumps/` is gitignored.
- Confirm production credential source exists and has safe permissions.
- Confirm the exact next operation class: read-only, production-read, or local-destructive.

No production connection is opened in Phase 0 unless explicitly authorized.

Use the safe preflight guard before any future sync task:

```bash
./ai/scripts/safe-sync-preflight.sh --dry-run
```

This command is non-destructive. It reports `OK`, `WARN`, and `FAIL` checks and rejects action arguments by default.

### Phase 1 - Production Dump

Production operation type: read-only.

Allowed only after explicit approval:

- Use Laravel Cloud dashboard connection details or a private credential file.
- Create a timestamped custom-format dump under `storage/app/dumps/production_<timestamp>.sql`.
- Never print credentials.
- Never commit dump files.

Do not combine first production dump with import.

### Phase 2 - Local Import

Local operation type: destructive.

Allowed only after a second explicit confirmation:

- Target local PostgreSQL only.
- Import into a clearly named local database.
- Assume existing local data may be replaced.
- Keep the source dump until validation completes.

`ai/scripts/pg-dump.sh import`, `reset`, and `prod-mirror` are destructive because they can run `pg_restore --clean`.

### Phase 3 - Local Stabilization

Only after local import and only if authorized:

- Run local migrations if needed.
- Clear local cache.
- Run selected local validation.

Migrations after production import are local-only but still require explicit authorization.

---

## Storage Protocol

Storage sync should not start as a full mirror.

Preferred order:

1. Inventory missing media paths from local UI or database references.
2. Pull only required public media by URL.
3. Store files under `storage/app/public/`.
4. Preserve relative paths expected by the app.
5. Confirm `public/storage` symlink status without creating it unless authorized.

`ai/scripts/media-pull.sh` downloads one URL at a time and writes into local storage. Treat it as local-write and network-read, not read-only.

Future batch media automation requires:

- URL allowlist.
- Destination path guard against traversal.
- Explicit overwrite policy.
- Failure logging that does not delete unrelated local files.

---

## Secrets

Allowed:

- Confirming whether a private credential file exists.
- Confirming permissions without printing values.
- Using environment variables inside bounded commands that do not echo them.

Forbidden:

- Reading `.env` into chat output.
- Printing `DATABASE_URL`, `DB_PASSWORD`, API keys, tokens, or Laravel Cloud credentials.
- Copying production credentials into tracked files.
- Adding secrets to `@DOCS/`, TASK files, logs, screenshots, or shell snippets.

Preferred private credential file:

```text
/home/cyril/.config/bouclepro/prod-db.env
```

Required permissions:

```text
600
```

---

## Rollback

Before local import:

1. Create or identify a local pre-import dump.
2. Record current branch and commit.
3. Record local runtime target without printing secrets.
4. Confirm the pre-import dump path is gitignored.
5. Confirm the restore command for that pre-import dump.

After a failed import:

1. Stop.
2. Do not run migrations again.
3. Restore the pre-import local dump only if authorized.
4. Clear local cache only if authorized.
5. Document the failure in the TASK file.

There is no production rollback step because this protocol must not write to production.

---

## Laravel Cloud Prerequisites

Before any production dump:

- Confirm access to Laravel Cloud dashboard or CLI.
- Confirm the environment is production and the database is PostgreSQL.
- Confirm credentials are copied only into a private local file or entered interactively.
- Confirm private credential file permissions are `600`.
- Confirm the operation is production-read only.
- Confirm the dump destination is local and gitignored.

Do not rely on undocumented Laravel Cloud commands. Verify CLI availability with help output first, and do not execute database commands during help discovery.

---

## Validation Post-Sync

Post-sync validation is selected per task, but the normal order is:

1. Confirm local runtime target.
2. Confirm local app boots.
3. Run focused PHPUnit tests.
4. Run PostgreSQL parity tests if schema behavior is involved.
5. Run Playwright only when UI behavior depends on the imported data.
6. Document gaps, failures, and rollback decisions in the TASK file.

---

## Limits

This document does not authorize:

- Real production dump.
- Local import.
- Storage sync.
- Migration.
- Cache clear.
- Laravel Cloud database command.
- Production write.
- ALPHA operation.

---

## Automation Decision

Can be automated later:

- Preflight checklist.
- Git state checks.
- Tool availability checks.
- Gitignore/path safety checks.
- Credential file existence and permission checks.
- Dump file inventory.
- Media URL allowlist validation.

Must remain manually confirmed:

- Any production DB connection.
- Any production dump.
- Any local destructive import/reset.
- Any local migration after import.
- Any storage batch download.

Do not automate `prod-mirror` as a one-command default workflow until dry-run, rollback, and confirmation gates are split into separate commands.

---

## Handoff

Recommended next task:

- `T080.2` for guarded preflight/dry-run tooling only.

Alternative:

- `BUG_BACKLOG_TRIAGE` if COCKPIT ROADMAP prioritizes product/runtime defects before sync automation.
