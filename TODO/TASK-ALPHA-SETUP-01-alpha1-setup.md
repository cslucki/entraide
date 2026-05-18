---
task_id: ALPHA-SETUP-01
title: Alpha1 local worktree setup from production main

status: IN_PROGRESS

owner: OPENCODE

contributors: []

branch: ALPHA-SETUP-01-alpha1-setup

priority: HIGH

created_at: 2026-05-18 11:33:02 Europe/Paris
updated_at: 2026-05-18 19:30:00 Europe/Paris

labels:
  - alpha
  - ops
  - worktree

lock:
  status: LOCKED
  agent: OPENCODE
  since: 2026-05-18 11:33:02 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Prepare the isolated local alpha1 worktree from the exact production deployment base:
`main` commit `b392a134e85a26a5018d2c371aeaebe20802bb63`.

This task is setup-only. No runtime patch, migration, Apache configuration, PostgreSQL production credential copy, or T074/T075/T076 backport is allowed.

---

# Guardrails

- ALPHA base: `main` at `b392a134e85a26a5018d2c371aeaebe20802bb63`.
- Forbidden base: `develop`.
- Forbidden base: current T076 work branch.
- Forbidden: modifying `main` / production.
- Forbidden: backporting T074/T075/T076.
- Forbidden: runtime patch during ALPHA-SETUP-01.
- Forbidden: storing production database secrets in this repository or TASK file.
- Local alpha database name: `bouclepro_alpha1`.
- Local alpha URL: `https://alpha1.test.laravel`.

---

# Planned Actions

- [x] confirm Cyril GO decision and production SHA
- [x] inspect official task script behavior before use
- [x] create dedicated branch from production SHA
- [x] create alpha1 worktree at `/home/cyril/claude-code/sites/alpha1.test.laravel`
- [x] create local alpha `.env` from `.env.pgsql`
- [x] verify branch, base SHA, worktree, and local alpha env keys
- [x] prepare PostgreSQL local alpha database configuration
- [x] inspect existing `test.laravel` Apache method without privileged writes
- [x] prepare exact manual Apache alpha vhost report
- [x] privileged Apache alpha vhost creation by Cyril
- [x] verify manual Apache alpha vhost apply without runtime changes
- [x] install Composer dependencies from lock in alpha worktree
- [x] verify Laravel CLI boot after Composer install
- [x] document manual Laravel permissions fix and empty alpha database state
- [x] check for local production dump before authorized alpha import
- [x] locate existing local production dump candidate outside alpha worktree
- [x] import authorized local production dump into alpha database
- [x] install Node dependencies and build Vite assets in alpha worktree
- [x] restore generated Vite manifest to avoid committing build output
- [x] inject configured alpha test users without documenting secrets
- [x] validate admin/member login flow reaches dashboard
- [x] create secrets-free alpha PostgreSQL/test-user runbook
- [x] perform real browser validation after cockpit reported broken site
- [x] diagnose Vite manifest/assets mismatch and storage media 403 errors
- [x] inspect tracked Vite manifest after real browser validation
- [x] commit Vite manifest and required referenced CSS asset for local alpha
- [x] create public storage link and audit referenced avatar files
- [x] perform final alpha1 validation and consolidate local setup documentation

---

# Progress Log

## 2026-05-18 11:33:02 Europe/Paris

Task created manually instead of using `ai/scripts/create-task.sh` because the official script creates branches from the current checkout. Current checkout was not the approved alpha base, so manual branch/worktree creation was required to preserve Cyril's base constraint.

Production base confirmed by Cyril:

- Branch: `main`
- Commit: `b392a134e85a26a5018d2c371aeaebe20802bb63`
- Short SHA: `b392a13`
- Deployment message: `chore: release T073 pre-T074 to main`

Created branch/worktree:

- Branch: `ALPHA-SETUP-01-alpha1-setup`
- Worktree: `/home/cyril/claude-code/sites/alpha1.test.laravel`
- Base: `b392a134e85a26a5018d2c371aeaebe20802bb63`

Production database credential file was only inspected for allowed non-secret keys. No production secret was displayed, copied, or stored.

## 2026-05-18 11:37:00 Europe/Paris

Created local alpha `.env` inside the alpha worktree from `.env.pgsql` with these alpha-specific non-secret settings verified:

- `APP_URL=https://alpha1.test.laravel`
- `DB_CONNECTION=pgsql`
- `DB_HOST=127.0.0.1`
- `DB_PORT=5432`
- `DB_DATABASE=bouclepro_alpha1`
- `DB_USERNAME=bouclepro`

Verified `.env` is ignored by git. No production database password or credential file was copied into the repository.

Verified branch/worktree state:

- Alpha worktree path: `/home/cyril/claude-code/sites/alpha1.test.laravel`
- Alpha branch: `ALPHA-SETUP-01-alpha1-setup`
- Alpha HEAD: `b392a134e85a26a5018d2c371aeaebe20802bb63`
- Alpha HEAD message: `chore: release T073 pre-T074 to main`
- Original worktree remains on `develop` and was not modified.

## 2026-05-18 11:37:30 Europe/Paris

Initial checkpoint before commit/push:

- Current worktree: `/home/cyril/claude-code/sites/alpha1.test.laravel`.
- Current branch: `ALPHA-SETUP-01-alpha1-setup`.
- Current base before TASK commit: `b392a134e85a26a5018d2c371aeaebe20802bb63`.
- Laravel Cloud production decision recorded: latest successful production deployment is short SHA `b392a13` from `main` with message `chore: release T073 pre-T074 to main`.
- Git status before commit contains only `TODO/ALPHA-SETUP-01-alpha1-setup.md` as an untracked file.
- Alpha `.env` was created locally from `.env.pgsql`, remains gitignored, and is not part of the commit.
- No runtime files were modified.
- No migration was run.
- No PostgreSQL alpha database was created or imported in this micro-step.
- No Apache configuration was modified in this micro-step.

Next OPS step: prepare PostgreSQL local alpha database (`bouclepro_alpha1`) and Apache alpha vhost configuration, still without production import until explicitly authorized.

## 2026-05-18 11:41:55 Europe/Paris

Local infrastructure setup micro-step executed with OPS constraints:

- Git branch confirmed: `ALPHA-SETUP-01-alpha1-setup`.
- Git status before infra step was clean.
- Non-secret `.env` keys confirmed: `APP_URL=https://alpha1.test.laravel`, `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_PORT=5432`, `DB_DATABASE=bouclepro_alpha1`, `DB_USERNAME=bouclepro`.
- `DB_PASSWORD` presence confirmed without displaying the value.
- PostgreSQL server readiness confirmed with `pg_isready`.
- Local PostgreSQL database `bouclepro_alpha1` was created using local `.env` credentials.
- PostgreSQL access to `bouclepro_alpha1` confirmed.
- `php artisan about --only=environment` could not run because `vendor/autoload.php` is absent in the alpha worktree; no migration was attempted.
- Apache `test.laravel` vhost inspected.
- Existing HTTPS certificate `/etc/apache2/certs/test.laravel/cert.pem` has SAN `DNS:test.laravel` only.
- Apache alpha vhost was not created because the existing certificate is not compatible with `alpha1.test.laravel`.
- Apache current configtest returned `Syntax OK`.
- Apache reload was not run because no alpha vhost was created.
- WSL resolution for `alpha1.test.laravel` is missing.
- Windows hosts entry for `alpha1.test.laravel` is missing or not readable.
- Existing Windows hosts reference for `test.laravel` uses `172.27.130.89`.
- HTTPS check for `https://alpha1.test.laravel` did not return an HTTP response.
- No production dump was imported.
- No migration was run.
- No Laravel runtime file was modified.
- No production secret was displayed, copied, or committed.

Manual prerequisites before Apache HTTPS activation:

- Create or provide a local trusted certificate for `alpha1.test.laravel` with SAN `DNS:alpha1.test.laravel`.
- Add Windows hosts entry if still missing: `172.27.130.89 alpha1.test.laravel`.
- After certificate and hosts are ready, create/enable Apache vhost files for `alpha1.test.laravel` using the `test.laravel` structure and alpha public path.

## 2026-05-18 11:51:15 Europe/Paris

Alpha1 HTTPS setup retry after Cyril provided mkcert files and Windows hosts entry:

- Current branch confirmed: `ALPHA-SETUP-01-alpha1-setup`.
- Git status before HTTPS step was clean.
- Windows mkcert directory inspected at `/mnt/d/mkcert`.
- Alpha certificate found: `alpha1.test.laravel.pem`.
- Alpha key found: `alpha1.test.laravel-key.pem`.
- Certificate SAN verified: `DNS:alpha1.test.laravel`.
- Vendor remains absent in the alpha worktree (`vendor/autoload.php` missing); composer was not run.
- Windows hosts entry for `alpha1.test.laravel` is present: `172.27.130.89 alpha1.test.laravel`.
- WSL resolution for `alpha1.test.laravel` is present and currently resolves to `127.0.0.1`.
- Apache current configtest returned `Syntax OK`.
- `curl -k -I https://alpha1.test.laravel` returned HTTP `302 Found`.
- No production dump was imported.
- No migration was run.
- No Laravel runtime file was modified.
- No secret was displayed, copied into git, or committed.

Blocked system-level Apache changes:

- Copy to `/etc/apache2/certs/alpha1.test.laravel` was not performed because `sudo -n` returned `sudo: a password is required`.
- `/etc/apache2/certs/alpha1.test.laravel` is still missing.
- `/etc/apache2/sites-available/alpha1.test.laravel.conf` is still missing.
- Apache alpha vhost was not created or enabled.
- Apache reload was not run.

Manual command sequence required from a privileged shell:

```bash
sudo mkdir -p /etc/apache2/certs/alpha1.test.laravel
sudo cp /mnt/d/mkcert/alpha1.test.laravel.pem /mnt/d/mkcert/alpha1.test.laravel-key.pem /etc/apache2/certs/alpha1.test.laravel/
sudo chmod 644 /etc/apache2/certs/alpha1.test.laravel/alpha1.test.laravel.pem
sudo chmod 600 /etc/apache2/certs/alpha1.test.laravel/alpha1.test.laravel-key.pem
```

Then create Apache vhost files for `alpha1.test.laravel` using the `test.laravel` structure, with document root `/home/cyril/claude-code/sites/alpha1.test.laravel/public`, enable the site, run configtest, and reload Apache.

## 2026-05-18 11:59:03 Europe/Paris

Non-privileged Apache inspection report completed as requested. No Apache file was created, no certificate was copied, and Apache was not reloaded.

Observed existing `test.laravel` method:

- HTTP vhost file: `/etc/apache2/sites-available/test.laravel.conf`.
- HTTPS vhost file: `/etc/apache2/sites-available/test.laravel-ssl.conf`.
- Both files are enabled through symlinks in `/etc/apache2/sites-enabled`.
- HTTP uses `<VirtualHost *:80>` with `ServerName test.laravel` and document root `/home/cyril/claude-code/sites/test.laravel/public`.
- HTTPS uses `<VirtualHost *:443>`, `SSLEngine on`, the same document root, and certificates under `/etc/apache2/certs/test.laravel/`.
- Existing certificate references are `SSLCertificateFile /etc/apache2/certs/test.laravel/cert.pem` and `SSLCertificateKeyFile /etc/apache2/certs/test.laravel/cert.key`.
- `test.laravel` does not reference certificates via `/mnt/d/...`; therefore the alpha manual commands below reproduce the current `/etc/apache2/certs/...` method.

Current blocker:

- `/etc/apache2/sites-available` is not writable by the current user.
- `/etc/apache2/sites-enabled` is not writable by the current user.
- `sudo -n` returns `sudo: a password is required`.
- `/etc/apache2/sites-available/alpha1.test.laravel.conf` is still absent.
- No dedicated `alpha1.test.laravel` vhost is active according to non-privileged inspection.
- `vendor/autoload.php` is absent in alpha; composer was intentionally not run.

Exact HTTP vhost content to create at `/etc/apache2/sites-available/alpha1.test.laravel.conf`:

```apache
<VirtualHost *:80>
    ServerName alpha1.test.laravel
    DocumentRoot /home/cyril/claude-code/sites/alpha1.test.laravel/public

    <Directory /home/cyril/claude-code/sites/alpha1.test.laravel/public>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/alpha1.test.laravel_error.log
    CustomLog ${APACHE_LOG_DIR}/alpha1.test.laravel_access.log combined
</VirtualHost>
```

Exact HTTPS vhost content to create at `/etc/apache2/sites-available/alpha1.test.laravel-ssl.conf`:

```apache
<VirtualHost *:443>
    ServerName alpha1.test.laravel
    DocumentRoot /home/cyril/claude-code/sites/alpha1.test.laravel/public

    SSLEngine on
    SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1
    SSLCipherSuite HIGH:!aNULL:!MD5
    SSLCertificateFile /etc/apache2/certs/alpha1.test.laravel/alpha1.test.laravel.pem
    SSLCertificateKeyFile /etc/apache2/certs/alpha1.test.laravel/alpha1.test.laravel-key.pem

    <Directory /home/cyril/claude-code/sites/alpha1.test.laravel/public>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/alpha1.test.laravel_ssl_error.log
    CustomLog ${APACHE_LOG_DIR}/alpha1.test.laravel_ssl_access.log combined
</VirtualHost>
```

Exact privileged commands for Cyril to execute manually:

```bash
sudo mkdir -p /etc/apache2/certs/alpha1.test.laravel
sudo cp /mnt/d/mkcert/alpha1.test.laravel.pem /etc/apache2/certs/alpha1.test.laravel/alpha1.test.laravel.pem
sudo cp /mnt/d/mkcert/alpha1.test.laravel-key.pem /etc/apache2/certs/alpha1.test.laravel/alpha1.test.laravel-key.pem
sudo chmod 644 /etc/apache2/certs/alpha1.test.laravel/alpha1.test.laravel.pem
sudo chmod 600 /etc/apache2/certs/alpha1.test.laravel/alpha1.test.laravel-key.pem
sudo tee /etc/apache2/sites-available/alpha1.test.laravel.conf >/dev/null <<'EOF'
<VirtualHost *:80>
    ServerName alpha1.test.laravel
    DocumentRoot /home/cyril/claude-code/sites/alpha1.test.laravel/public

    <Directory /home/cyril/claude-code/sites/alpha1.test.laravel/public>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/alpha1.test.laravel_error.log
    CustomLog ${APACHE_LOG_DIR}/alpha1.test.laravel_access.log combined
</VirtualHost>
EOF
sudo tee /etc/apache2/sites-available/alpha1.test.laravel-ssl.conf >/dev/null <<'EOF'
<VirtualHost *:443>
    ServerName alpha1.test.laravel
    DocumentRoot /home/cyril/claude-code/sites/alpha1.test.laravel/public

    SSLEngine on
    SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1
    SSLCipherSuite HIGH:!aNULL:!MD5
    SSLCertificateFile /etc/apache2/certs/alpha1.test.laravel/alpha1.test.laravel.pem
    SSLCertificateKeyFile /etc/apache2/certs/alpha1.test.laravel/alpha1.test.laravel-key.pem

    <Directory /home/cyril/claude-code/sites/alpha1.test.laravel/public>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/alpha1.test.laravel_ssl_error.log
    CustomLog ${APACHE_LOG_DIR}/alpha1.test.laravel_ssl_access.log combined
</VirtualHost>
EOF
sudo a2ensite alpha1.test.laravel.conf
sudo a2ensite alpha1.test.laravel-ssl.conf
sudo apache2ctl configtest
sudo systemctl reload apache2
```

Validation commands after Cyril executes the privileged sequence:

```bash
apache2ctl -S 2>/dev/null | grep -A3 -B3 "alpha1.test.laravel" || true
curl -k -I https://alpha1.test.laravel
```

Expected result after the dedicated vhost points to the alpha worktree: because `vendor/autoload.php` is absent, Laravel may return HTTP 500 until dependencies are installed. That would still indicate Apache is reaching the alpha document root, not the previous fallback vhost.

## 2026-05-18 12:05:06 Europe/Paris

Manual Apache alpha vhost apply verification completed in read-only mode after Cyril executed the privileged setup commands.

Observed state:

- Current branch: `ALPHA-SETUP-01-alpha1-setup`.
- Git status before documentation update: clean.
- `apache2ctl -S` lists `alpha1.test.laravel` as active on `*:443` via `/etc/apache2/sites-enabled/alpha1.test.laravel-ssl.conf:1`.
- `apache2ctl -S` lists `alpha1.test.laravel` as active on `*:80` via `/etc/apache2/sites-enabled/alpha1.test.laravel.conf:1`.
- `alpha1.test.laravel` is currently the default server for local `*:443`.
- `alpha1.test.laravel` is currently the default server for local `*:80`.
- `test.laravel` is still present in Apache on `*:443` via `/etc/apache2/sites-enabled/test.laravel-ssl.conf:1`.
- `vendor/autoload.php` is still absent in the alpha worktree.
- `curl -k -I https://alpha1.test.laravel` returns `HTTP/1.0 500 Internal Server Error`.
- Attempts to read `/var/log/apache2/alpha1.test.laravel_ssl_error.log` and `/var/log/apache2/alpha1.test.laravel_error.log` with sudo returned no visible log output in this session.

Interpretation:

- Apache alpha is active: yes.
- Alpha default server local: yes. This is documented as non-blocking for the alpha check, but should be kept in mind because unmatched local hosts on `*:80` or `*:443` may now fall through to alpha.
- The HTTP 500 strongly indicates Apache is reaching the alpha Laravel document root at `/home/cyril/claude-code/sites/alpha1.test.laravel/public`, replacing the earlier fallback behavior that returned `302 Found` before a dedicated alpha vhost was active.
- The 500 is coherent with the known missing dependency state: `vendor/autoload.php` is absent.
- Recommended next step: run `composer install` in `/home/cyril/claude-code/sites/alpha1.test.laravel` when Cyril authorizes dependency installation.
- No production import was performed.
- No migration was run.
- No Laravel runtime file was modified.

## 2026-05-18 12:08:34 Europe/Paris

Composer dependency installation verification completed for alpha1.

Pre-install checks:

- Current branch: `ALPHA-SETUP-01-alpha1-setup`.
- Git status before Composer install: clean.
- Composer version: `Composer version 2.9.7 2026-04-14 13:31:52`.
- PHP CLI version: `PHP 8.4.21`.
- `composer.lock` present.

Composer install:

- Executed `composer install --no-interaction --prefer-dist`.
- Composer installed from lock file with 123 package installs, 0 updates, and 0 removals.
- Composer generated optimized autoload files and ran Laravel package discovery.
- `composer update` was not run.
- `composer.json` was not modified.
- `composer.lock` was not modified.
- Git status after Composer install remained clean; `vendor/` is not committed.

Post-install verification:

- `vendor/autoload.php` is present.
- `php artisan --version` returned `Laravel Framework 13.7.0`.
- `php artisan about --only=environment` succeeded and reported: application `Entraide`, Laravel `13.7.0`, PHP `8.4.21`, Composer `2.9.7`, environment `local`, debug mode enabled, URL `alpha1.test.laravel`, maintenance mode off, timezone `UTC`, locale `fr`.
- `curl -k -I https://alpha1.test.laravel` returned `HTTP/1.1 500 Internal Server Error`.
- `tail -50 storage/logs/laravel.log 2>/dev/null || true` returned no visible log output in this session.

Interpretation:

- Composer install OK: yes.
- Vendor present: yes.
- Artisan OK: yes.
- Laravel now boots from CLI.
- The remaining HTTP 500 is no longer caused by missing `vendor/autoload.php`.
- No short Laravel log entry was available from `storage/logs/laravel.log` to identify the exact web-request error in this micro-step.
- Recommended next step: inspect the web error path/logs or HTTP response body in a dedicated diagnostic step, then proceed toward the authorized production import only when Cyril explicitly approves it.
- No production import was performed.
- No migration was run.
- No Laravel runtime file was modified.

## 2026-05-18 12:18:12 Europe/Paris

Manual Laravel local permissions fix documented after Cyril applied it outside this session.

Permissions actions reported by Cyril:

- Created missing local directories under `storage/framework/views`, `storage/framework/cache/data`, `storage/framework/sessions`, `storage/logs`, and `bootstrap/cache`.
- Applied `chown -R cyril:www-data storage bootstrap/cache`.
- Applied directory permissions `2775`.
- Applied file permissions `664`.

Observed browser/runtime state reported by Cyril:

- Previous `tempnam` error has disappeared.
- Current Chrome error is `SQLSTATE[42P01]: Undefined table: relation "sessions" does not exist`.
- Database shown in the error: `bouclepro_alpha1`.
- Route shown in the error: `GET /`.
- Controller shown in the error: `HomeController@index`.
- Middleware shown in the error: `web`.

Read-only verification executed in the alpha worktree:

- `git status --short` returned clean.
- `vendor/autoload.php` is present.
- `php artisan --version` returned `Laravel Framework 13.7.0`.
- Non-secret `.env` keys verified: `APP_URL=https://alpha1.test.laravel`, `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_PORT=5432`, `DB_DATABASE=bouclepro_alpha1`, `DB_USERNAME=bouclepro`, `SESSION_DRIVER=database`.
- `DB_PASSWORD` presence was confirmed without displaying the value.

Interpretation:

- Apache points to the alpha worktree.
- Laravel boots.
- Composer/vendor is OK.
- PostgreSQL is reachable.
- The alpha database is empty for the current application schema because the configured database session table `sessions` is missing.
- Cause probable: `bouclepro_alpha1` has not received a production dump import yet.
- Recommended next step: import the production dump into `bouclepro_alpha1` when Cyril explicitly authorizes the import step.
- No migration was run.
- No production import was performed.
- No Laravel runtime file was modified.

## 2026-05-18 15:39:19 Europe/Paris

Authorized production dump import micro-step started after explicit Cyril GO for destructive local reset limited to `bouclepro_alpha1`.

Pre-drop safety checks completed:

- Current branch: `ALPHA-SETUP-01-alpha1-setup`.
- Git status before import attempt: clean.
- Non-secret `.env` keys verified: `APP_ENV=local`, `APP_DEBUG=true`, `APP_URL=https://alpha1.test.laravel`, `DB_CONNECTION=pgsql`, `DB_HOST=127.0.0.1`, `DB_PORT=5432`, `DB_DATABASE=bouclepro_alpha1`, `DB_USERNAME=bouclepro`, `SESSION_DRIVER=database`.
- `DB_PASSWORD` presence confirmed without displaying the value.
- DB target guard passed from `.env`: `DB_DATABASE` is exactly `bouclepro_alpha1`.

Dump discovery result:

- Checked `storage/app/dumps` for local dump files.
- No local dump file was listed by `find storage/app/dumps -maxdepth 1 -type f`.
- No recent dump file was listed by `ls -lt storage/app/dumps`.
- No production dump was selected.
- Dump format: not identified because no dump file is present.

Import result:

- BLOCKED before any destructive database action because no existing local production dump was found in `storage/app/dumps`.
- `drop schema public cascade` was not run.
- No dump import was run.
- No post-import table verification was possible.
- No HTTP post-import validation was run.
- No migration was run.
- No seed was run.
- No Laravel runtime file was modified.

Documentation note:

- Requested `@DOCS/ALPHA-POSTGRES-SYNC-AND-TEST-USERS.md` was not created because the `@DOCS` directory is absent in this worktree.
- The import blockage and safety result are documented in this TASK file instead.

Next required action:

- Place or identify an existing local production dump under `storage/app/dumps` and rerun the authorized import micro-step.

## 2026-05-18 17:08:38 Europe/Paris

Existing local production dump location search completed without import or destructive database action.

Reason alpha worktree had no dump:

- The alpha worktree path `/home/cyril/claude-code/sites/alpha1.test.laravel/storage/app/dumps` did not list dump files in the previous import attempt.
- This is coherent with `storage/` being local/ignored and not shared across Git worktrees.

Locations inspected:

- `/home/cyril/claude-code/sites/test.laravel/storage/app/dumps`.
- `/home/cyril/claude-code/sites/*/storage/app/dumps/*`.
- `/home/cyril` up to depth 6 for `production_*.sql`, `*.dump`, `*.backup`, and `*.sql`.

Production dumps found in the existing `test.laravel` worktree:

- `/home/cyril/claude-code/sites/test.laravel/storage/app/dumps/production_2026-05-13_12-45-00.sql`.
- `/home/cyril/claude-code/sites/test.laravel/storage/app/dumps/production_2026-05-16_16-25-55.sql`.
- `/home/cyril/claude-code/sites/test.laravel/storage/app/dumps/production_2026-05-16_17-15-13.sql`.
- `/home/cyril/claude-code/sites/test.laravel/storage/app/dumps/production_2026-05-16_18-42-25.sql`.
- `/home/cyril/claude-code/sites/test.laravel/storage/app/dumps/production_2026-05-16_18-42-55.sql`.

Other local dump-like files were also present in broader `/home/cyril` search results, including VS Code history files and unrelated backup trees, but they were not selected because the explicit production dump candidates in `test.laravel/storage/app/dumps` are more relevant to BouclePro alpha setup.

Retained production dump candidate:

- Path: `/home/cyril/claude-code/sites/test.laravel/storage/app/dumps/production_2026-05-16_18-42-55.sql`.
- Size: `110561 bytes`.
- Modification date: `2026-05-16 18:43:13.856356456 +0200`.
- `file` result: `PostgreSQL custom database dump - v1.16-0`.
- `pg_restore -l` check: succeeded, so format is `custom`.

Planned import command for next GO only:

```bash
PGPASSWORD="$DB_PASS" pg_restore --no-owner --no-acl -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" "/home/cyril/claude-code/sites/test.laravel/storage/app/dumps/production_2026-05-16_18-42-55.sql"
```

Safety status:

- No dump content was displayed.
- No personal data was displayed.
- No secret was displayed.
- No import was run.
- `drop schema public cascade` was not run.
- No migration was run.
- No Laravel runtime file was modified.

Next required action:

- Wait for explicit Cyril GO before resetting `bouclepro_alpha1` and importing the retained dump candidate.

## 2026-05-18 17:23:47 Europe/Paris

Authorized local production dump import completed against alpha database `bouclepro_alpha1`.

Safety checks before database action:

- Current branch: `ALPHA-SETUP-01-alpha1-setup`.
- Git status before import: clean.
- Authorized dump path: `/home/cyril/claude-code/sites/test.laravel/storage/app/dumps/production_2026-05-16_18-42-55.sql`.
- Authorized dump size/date: `110561 bytes`, modified `2026-05-16 18:43:13.856356456 +0200`.
- DB target guard from `.env`: `DB target confirmed: bouclepro_alpha1`.
- Non-secret DB target: `127.0.0.1:5432/bouclepro_alpha1` as `bouclepro`.
- `DB_PASSWORD` was read only into process environment for PostgreSQL commands and was not displayed.

Pre-import DB state:

- PostgreSQL connection to `bouclepro_alpha1` succeeded.
- `tables_before=0`.
- Attempted `drop schema public cascade; create schema public;` was refused by PostgreSQL because schema `public` is owned by `postgres`.
- Effective `drop schema` execution: no.
- Follow-up privilege check showed current user `bouclepro`, schema owner `postgres`, and `can_create_in_public=true`.
- Because `tables_before=0` and `bouclepro` can create in `public`, import proceeded into the empty alpha schema.

Import:

- Executed `pg_restore --no-owner --no-acl` from the authorized dump into `bouclepro_alpha1`.
- Import result: OK.
- No dump content was displayed.
- No personal data was displayed.

Post-import verification:

- `tables_after=40`.
- `sessions` table present: yes.
- `users` table present: yes.
- `users_count=18`.
- `php artisan about --only=environment` succeeded with Laravel `13.7.0`, PHP `8.4.21`, environment `local`, URL `alpha1.test.laravel`.
- HTTPS check for `https://alpha1.test.laravel` returned `HTTP/1.1 200 OK` with `http_code=200`.

Safety status:

- No migration was run.
- No `migrate:fresh` was run.
- No seed was run.
- No Composer update was run.
- No npm install/build was run.
- No new remote dump was created.
- No Laravel runtime file was modified.
- No production/main/develop branch was touched.

Remaining note:

- The requested destructive schema reset did not execute due local PostgreSQL ownership of `public`, but the target DB was empty before import and the authorized dump import succeeded with the expected tables present and HTTP `200 OK` afterward.

## 2026-05-18 17:48:00 Europe/Paris

Node/Vite alpha setup completed without application runtime patching.

Pre-checks:

- Git status before Node setup: clean.
- `package.json` present.
- `package-lock.json` present.
- `node_modules` was absent.
- npm version: `10.9.7`.
- Node version: `v22.22.2`.

Dependency install:

- Executed `npm ci` without sudo.
- Result: OK.
- npm installed 169 packages from lock and reported 0 vulnerabilities.
- `npm install` was not run because `npm ci` succeeded.
- `package.json` was not modified.
- `package-lock.json` was not modified.

Build:

- Executed `npm run build`.
- Result: OK.
- Vite reported `vite v8.0.10` and built `public/build/manifest.json`, `public/build/assets/app-CosGWsUZ.css`, and `public/build/assets/app-BE0FrHm7.js`.
- Local Vite executable exists at `node_modules/.bin/vite` as symlink to `../vite/bin/vite.js`.

Git state after build:

- `git status --short` shows `M public/build/manifest.json`.
- `node_modules/` is not tracked.
- No application code under `app/`, `routes/`, `resources/`, `database/`, or `config/` was modified by this step.
- No migration was run.
- No Composer update was run.
- No sudo npm command was run.

Interpretation:

- The prior Vite failure was caused by missing local Node dependencies / missing local Vite install in the alpha worktree.
- The local Node/Vite setup is now usable for alpha builds.

## 2026-05-18 18:20:00 Europe/Paris

Alpha test-user setup and validation completed without runtime patching.

Safety checks:

- Active Laravel database confirmed as `pgsql` / `bouclepro_alpha1`.
- Local alpha `.env` contains the five expected test login/password variable pairs.
- Test variable values were not displayed, copied, or documented.
- Existing imported schema was inspected before injection: `users` contains `community_id`, `organization_id`, and `is_admin`; `communities` exists; `organizations`, `roles`, and `model_has_roles` are absent.
- Existing imported users had no `community_id` or `organization_id` assignments.

Test users:

- Five configured test users were created or updated by email.
- Passwords were stored with `Hash::make`.
- `email_verified_at` was set.
- Admin test user was assigned `is_admin=true`.
- Member test users were assigned `is_admin=false`.
- `community_id=null` and `organization_id=null` were kept intentionally to match the imported production snapshot shape.
- User count increased from 18 after production import to 23 after test-user injection.

Login validation:

- Admin login returned HTTP 200, ended at `/dashboard`, and dashboard content was detected.
- Member 1 login returned HTTP 200, ended at `/dashboard`, and dashboard content was detected.
- Member 2 login returned HTTP 200, ended at `/dashboard`, and dashboard content was detected.

Documentation and generated files:

- Created secrets-free local runbook `@DOCS/ALPHA-POSTGRES-SYNC-AND-TEST-USERS.md`.
- Git ignore check shows `@DOCS/` is ignored by `.git/info/exclude`, so this runbook will not be included in normal commits unless force-added intentionally.
- `public/build/manifest.json` generated by `npm run build` was restored and is not intended for commit.
- No `.env` file, password, production database secret, or generated build output is intended for commit.

Safety status:

- No migration was run.
- No `migrate:fresh` was run.
- No seed was run.
- No Laravel runtime file was modified.
- No Community to Organization migration was performed.
- No `sudo npm` was used.
- No Apache file was modified by this agent session.

## 2026-05-18 18:25:00 Europe/Paris

Mandatory real browser validation performed after cockpit reported the site still looked broken.

Start state:

- Git status before validation: `M TODO/TASK-ALPHA-SETUP-01-alpha1-setup.md`.
- TASK status remained `IN_PROGRESS`; it was not marked `DONE`.

HTTP checks before rebuilding:

- `curl -k -I https://alpha1.test.laravel` returned `HTTP/1.1 200 OK`.
- `curl -k -L https://alpha1.test.laravel` returned final HTTP `200`.
- `curl -k -I https://alpha1.test.laravel/dashboard` returned `HTTP/1.1 302 Found` to `/login`, expected while unauthenticated.

Root cause found for broken visual rendering:

- `public/build/manifest.json` had been restored to a stale tracked version referencing `assets/app-DQMOgrmh.css`.
- The actual generated CSS file present in `public/build/assets` was `app-CosGWsUZ.css`.
- The generated JS file `app-BE0FrHm7.js` existed and matched both stale and rebuilt manifests.
- Because the stale manifest referenced a missing CSS file, the browser could render the page without the expected CSS.

Temporary Vite rebuild:

- Re-ran `npm run build` without sudo.
- Build succeeded.
- The manifest now references `assets/app-CosGWsUZ.css` and `assets/app-BE0FrHm7.js`, both present.
- Retesting after rebuild returned home HTTP `200` and dashboard HTTP `302` to login while unauthenticated.
- `public/build/manifest.json` is now modified again and must not be committed automatically without cockpit validation.

Runtime and logs:

- `php artisan about` reports Laravel `13.7.0`, environment `local`, URL `alpha1.test.laravel`, database `pgsql`, session driver `database`, views previously cached.
- Active DB confirmed as `pgsql` / `bouclepro_alpha1`.
- App URL confirmed as `https://alpha1.test.laravel`, environment `local`.
- `php artisan optimize:clear` was run; config, cache, compiled, events, routes, and views were cleared.
- Laravel log tail still includes the earlier pre-import `SQLSTATE[42P01]` missing `sessions` error from before the production dump import; no new 500 was observed during this validation.
- Apache `/var/log/apache2/error.log` was readable without sudo and showed only normal restart notices, no current alpha error.

Real browser validation after rebuild and cache clear:

- Home page opened in a browser at `https://alpha1.test.laravel/`, rendered page title `Entraide`, and displayed BouclePro home content.
- Browser network showed Vite CSS `app-CosGWsUZ.css` HTTP `200` and JS `app-BE0FrHm7.js` HTTP `200`.
- Login page opened at `/login`, rendered the login form, and had no console errors.
- Secret-safe headless browser login using local `.env` variables internally validated admin login to `/dashboard` with dashboard content detected and no console errors.
- Secret-safe headless browser login using local `.env` variables internally validated member 1 login to `/dashboard` with dashboard content detected and no console errors.

Remaining browser issue:

- Home page console reports two HTTP `403 Forbidden` image loads under `/storage/avatars/...`.
- `php artisan about` reports `public/storage` is `NOT LINKED`.
- Filesystem check confirms `public/storage` does not exist.
- `storage/app/public` exists but contains only `.gitignore`; imported production avatar files are not present in this alpha worktree.
- This explains broken/missing avatar media independently from the Vite CSS issue.

Safety status:

- No migration was run.
- No `migrate:fresh` was run.
- No seed was run.
- No application code was patched.
- No Community to Organization migration was performed.
- No T074/T075/T076 backport was performed.
- No sudo command was used.
- No `.env` secret or test credential value was displayed.

## 2026-05-18 18:45:00 Europe/Paris

OPS Git stabilization check performed for the Vite manifest after real browser validation.

Build inspection:

- Current branch confirmed: `ALPHA-SETUP-01-alpha1-setup`.
- Git status before stabilization contained only `M TODO/TASK-ALPHA-SETUP-01-alpha1-setup.md` and `M public/build/manifest.json`.
- `git status --short public/build` showed only `M public/build/manifest.json` under `public/build`.
- Manifest diff changes only the CSS reference from stale `assets/app-DQMOgrmh.css` to generated `assets/app-CosGWsUZ.css`.
- `public/build/assets` contains `app-CosGWsUZ.css` and `app-BE0FrHm7.js`.
- PHP manifest asset existence check returned `exists` for both referenced files.
- Git tracking check shows `app-BE0FrHm7.js` and `public/build/manifest.json` are tracked, but `app-CosGWsUZ.css` is not tracked.

Reason for BLOCK:

- The restored manifest was stale and pointed to a CSS asset absent from the alpha build directory.
- The rebuilt manifest points to assets that exist locally and were loaded by browser validation.
- Home, login, unauthenticated dashboard redirect, admin login dashboard, and member login dashboard were validated after the rebuild.
- The rebuilt manifest cannot be committed alone because it would reference `assets/app-CosGWsUZ.css`, which exists locally but is ignored/untracked.
- Per OPS rule, necessary untracked assets require BLOCK instead of committing only the manifest.
- Commit and push were not performed.

Out of scope / safety status:

- `/storage/avatars` 403 and missing `public/storage` remain known issues and were not touched in this micro-step.
- No migration was run.
- No `migrate:fresh` was run.
- No seed was run.
- No additional dump import was run.
- No application runtime code under `app/`, `routes/`, `resources/`, `database/`, or `config/` was modified.
- No T074/T075/T076 backport was performed.

## 2026-05-18 19:05:00 Europe/Paris

Cyril/RUN decision received to stabilize the alpha Vite build by committing the manifest and only the required ignored CSS asset referenced by that manifest.

Tracking and existence checks:

- `public/build/assets/app-CosGWsUZ.css`: exists and was not tracked before this micro-step.
- `public/build/assets/app-BE0FrHm7.js`: exists and is already tracked.
- The JS asset was not force-added because it is already tracked.

Commit scope authorized and prepared:

- Keep modified `public/build/manifest.json`.
- Force-add only `public/build/assets/app-CosGWsUZ.css` from ignored build assets.
- Include this TASK update.
- Do not add all of `public/build`.
- Do not treat `public/storage` or avatar files in this micro-step.

Safety status:

- No migration was run.
- No `migrate:fresh` was run.
- No seed was run.
- No additional dump import was run.
- No application runtime code under `app/`, `routes/`, `resources/`, `database/`, or `config/` was modified.
- No main/production branch was touched.
- No T074/T075/T076 backport was performed.

## 2026-05-18 19:15:00 Europe/Paris

Storage link and avatar audit micro-step completed after Vite stabilization.

Initial state:

- Current branch confirmed: `ALPHA-SETUP-01-alpha1-setup`.
- Git status before storage step: clean.
- `public/storage` was absent.
- `storage/app/public` existed and contained only `.gitignore` within the inspected depth.
- `php artisan about --only=environment` succeeded with Laravel `13.7.0`, environment `local`, URL `alpha1.test.laravel`.

Storage link:

- Executed `php artisan storage:link`.
- Result: OK, Laravel connected `public/storage` to `storage/app/public`.
- `public/storage` now symlinks to `/home/cyril/claude-code/sites/alpha1.test.laravel/storage/app/public`.
- Git status after creating the link remained clean before this TASK update; `public/storage` was not added to git.

HTTP validation after link:

- `curl -k -I https://alpha1.test.laravel` returned `HTTP/1.1 200 OK`.
- `curl -k -L https://alpha1.test.laravel` returned final HTTP `200`.

Avatar audit:

- Home HTML references 2 unique `/storage/avatars/...` URLs.
- Both referenced avatar files are missing under alpha `storage/app/public/avatars`.
- Both referenced avatar requests still return HTTP `403` after the storage link.
- `storage/app/public/avatars` is absent in alpha; `storage/app/public` permissions are `drwxrwsr-x` with owner/group `cyril:www-data`.
- The original repo storage directory `/home/cyril/claude-code/sites/test.laravel/storage/app/public` contains some media files, but the two exact referenced avatar files were not found there.
- No media files were copied.

Interpretation / next recommendation:

- `public/storage` link is now present, so the Laravel storage symlink prerequisite is satisfied.
- Remaining avatar failures are not fixed by the symlink alone because the referenced avatar files are absent locally.
- Next micro-step should decide whether to locate/copy only the required avatar media from an approved local source, or accept missing avatars in alpha.

Safety status:

- No migration was run.
- No `migrate:fresh` was run.
- No seed was run.
- No additional dump import was run.
- No npm build was run.
- No application runtime code under `app/`, `routes/`, `resources/`, `database/`, or `config/` was modified.
- No main/production branch was touched.
- No T074/T075/T076 backport was performed.
- No production media sync was performed.

## 2026-05-18 19:30:00 Europe/Paris

Final alpha1 validation and documentation consolidation completed.

Git and environment:

- Current branch confirmed: `ALPHA-SETUP-01-alpha1-setup`.
- Git status before final validation was clean.
- Recent commits include Vite asset stabilization `edc5aa7` and storage audit documentation `87e8384`.
- `.env` non-secret keys confirm `APP_ENV=local`, `APP_DEBUG=true`, `APP_URL=https://alpha1.test.laravel`, `DB_CONNECTION=pgsql`, `DB_DATABASE=bouclepro_alpha1`, and `SESSION_DRIVER=database`.
- `DB_PASSWORD` is present and was not displayed.

HTTP and DB validation:

- Home returned `HTTP/1.1 200 OK`.
- Login returned `HTTP/1.1 200 OK`.
- Dashboard while unauthenticated returned `HTTP/1.1 302 Found` to `/login`.
- Database validation returned `users=23`, `sessions_table=yes`, and `users_table=yes`.

Test account validation:

- No `TEST_*EMAIL` variables are present; this alpha `.env` uses `TEST_*_LOGIN` variables.
- `TEST_ADMIN_LOGIN`: exists in DB.
- `TEST_MEMBER1_LOGIN`: exists in DB.
- `TEST_MEMBER2_LOGIN`: exists in DB.
- `TEST_MEMBER_OF_CPME1_LOGIN`: exists in DB.
- `TEST_MEMBER_OF_CPME2_LOGIN`: exists in DB.
- TEST password variables are present and values were not displayed.
- Five configured test users are present; previous headless browser validation confirmed admin/member dashboard access.

Storage and avatar validation:

- `public/storage` is linked to `/home/cyril/claude-code/sites/alpha1.test.laravel/storage/app/public`.
- Final home fetch returned HTTP `200`.
- Home references 2 unique `/storage/avatars/...` URLs.
- RUN decision accepts the two locally missing avatars as a non-blocking alpha limitation.
- No avatars or media files were copied.

Documentation:

- Updated `@DOCS/ALPHA-POSTGRES-SYNC-AND-TEST-USERS.md` with the alpha objective, exact base commit, local URL/worktree, Apache/mkcert notes, PostgreSQL database and dump, no-migration rule, permissions/storage link/Vite procedures, test user status, known avatar limitation, and security rules.

Safety status:

- No migration was run.
- No `migrate:fresh` was run.
- No seed was run.
- No additional dump import was run.
- No npm build was run.
- No application runtime code under `app/`, `routes/`, `resources/`, `database/`, or `config/` was modified.
- No production media sync was performed.
- No main/production branch was touched.
- No T074/T075/T076 backport was performed.

# Handoffs

None.

# Tests

- [ ] feature tests
- [x] browser validation
- [ ] responsive validation
- [x] console inspection
- [ ] tenant validation

---

# Test Results

Setup verification completed without runtime patching:

- `git rev-parse HEAD` in alpha worktree returned `b392a134e85a26a5018d2c371aeaebe20802bb63`.
- `git log -1 --format='%h %s'` returned `b392a13 chore: release T073 pre-T074 to main`.
- `git worktree list` shows alpha worktree at `/home/cyril/claude-code/sites/alpha1.test.laravel` on branch `ALPHA-SETUP-01-alpha1-setup`.
- Non-secret local `.env` keys confirm alpha URL and alpha database name.
- `.env` appears as ignored in git status.
- PostgreSQL local database `bouclepro_alpha1` was created and connection verified.
- Apache alpha HTTPS setup is blocked pending a certificate whose SAN includes `alpha1.test.laravel`.
- Hosts resolution for `alpha1.test.laravel` is not active yet.
- Alpha mkcert files are now available and valid for `alpha1.test.laravel`.
- Apache alpha dedicated vhost setup remains blocked by non-interactive sudo privileges.
- `curl -k -I https://alpha1.test.laravel` currently returns HTTP `302 Found`, but Apache does not yet list a dedicated `alpha1.test.laravel` vhost.
- Non-privileged Apache report prepared with exact alpha vhost contents and manual sudo command sequence.
- After Cyril's manual Apache apply, `alpha1.test.laravel` is active on `*:443` and `*:80`, is the local default server for both ports, and `curl -k -I https://alpha1.test.laravel` returns `HTTP/1.0 500 Internal Server Error`.
- The 500 is consistent with Apache reaching the alpha Laravel document root while `vendor/autoload.php` is absent.
- Composer install from lock completed successfully; `vendor/autoload.php` is now present and Artisan reports `Laravel Framework 13.7.0`.
- After Composer install, `curl -k -I https://alpha1.test.laravel` still returns `HTTP/1.1 500 Internal Server Error`; no short `storage/logs/laravel.log` output was visible in this session.
- After Cyril's manual local permissions fix, the browser error changed from `tempnam` to PostgreSQL `SQLSTATE[42P01]` for missing relation `sessions` on database `bouclepro_alpha1`.
- Read-only checks confirm `vendor/autoload.php` is present, Artisan reports `Laravel Framework 13.7.0`, and `.env` points to PostgreSQL database `bouclepro_alpha1` with `SESSION_DRIVER=database`.
- Current blocker is expected empty alpha database state; no migration or production import has been run yet.
- Authorized production dump import was blocked before destructive action because no existing local dump file was found in `storage/app/dumps`; `drop schema public cascade` was not run.
- Existing local production dump candidate found in the original `test.laravel` worktree: `/home/cyril/claude-code/sites/test.laravel/storage/app/dumps/production_2026-05-16_18-42-55.sql`, PostgreSQL custom dump, 110561 bytes, modified `2026-05-16 18:43:13.856356456 +0200`.
- Authorized local production dump import succeeded into `bouclepro_alpha1`: `tables_before=0`, effective `drop schema` no due schema owner `postgres`, `tables_after=40`, `sessions` present, `users` present, `users_count=18`, HTTPS alpha returned `HTTP/1.1 200 OK` / `http_code=200`.
- Node/Vite setup completed: `npm ci` OK, `npm run build` OK, local `node_modules/.bin/vite` present, `public/build/manifest.json` modified by generated build output.
- Generated `public/build/manifest.json` was restored and should remain out of the documentation commit.
- Active Laravel database confirmed as `pgsql` / `bouclepro_alpha1`.
- Five expected local alpha test variable pairs are present in `.env`; values were not documented.
- Five configured test users were created or updated; `users_count=23` after injection.
- Admin, member 1, and member 2 login checks returned HTTP 200, landed on `/dashboard`, and detected dashboard content.
- Secrets-free local runbook created at `@DOCS/ALPHA-POSTGRES-SYNC-AND-TEST-USERS.md`; it is ignored by `.git/info/exclude` under the `@DOCS/` rule.
- Cockpit-requested browser validation found stale restored Vite manifest as the primary visual breakage: manifest referenced missing `assets/app-DQMOgrmh.css`; actual generated CSS was `assets/app-CosGWsUZ.css`.
- After temporary `npm run build`, manifest matches existing Vite assets and browser loads CSS/JS with HTTP `200`.
- Home browser validation after rebuild: page renders `Entraide` / BouclePro content, with CSS/JS loaded.
- Login browser validation after rebuild: login form renders, no login-page console errors.
- Admin and member 1 secret-safe headless browser logins both land on `/dashboard`, dashboard content detected, CSS/JS loaded, no console errors.
- Remaining browser console issue: two `/storage/avatars/...` requests return HTTP `403 Forbidden`; `public/storage` is missing and `storage/app/public` has no imported avatar files.
- Current modified files after validation: `TODO/TASK-ALPHA-SETUP-01-alpha1-setup.md` and `public/build/manifest.json`.
- Vite manifest stabilization check confirmed `public/build/manifest.json` is the only reported build modification and all referenced assets exist on disk (`app-CosGWsUZ.css`, `app-BE0FrHm7.js`).
- Git tracking check found referenced CSS `app-CosGWsUZ.css` is ignored/untracked while the JS asset is tracked, so the manifest-only commit was blocked.
- RUN decision authorized committing the manifest plus only the ignored referenced CSS asset; JS was already tracked and not force-added.
- Storage link audit created `public/storage` symlink successfully; home remained HTTP `200`; two referenced avatar files are missing locally and were not found at the exact paths in the original repo storage inspected.
- Final validation: home/login OK, unauthenticated dashboard redirects to login, DB has `users=23` with `sessions` and `users` tables, five `TEST_*_LOGIN` users exist, `public/storage` is linked, and two missing avatars are accepted as a local alpha limitation.

---

# Review Notes

ALPHA-SETUP-01 is an OPS/setup task only. Runtime behavior remains unchanged.
