---
task_id: ALPHA-SETUP-01
title: Alpha1 local worktree setup from production main

status: IN_PROGRESS

owner: OPENCODE

contributors: []

branch: ALPHA-SETUP-01-alpha1-setup

priority: HIGH

created_at: 2026-05-18 11:33:02 Europe/Paris
updated_at: 2026-05-18 12:05:06 Europe/Paris

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

# Handoffs

None.

# Tests

- [ ] feature tests
- [ ] browser validation
- [ ] responsive validation
- [ ] console inspection
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

---

# Review Notes

ALPHA-SETUP-01 is an OPS/setup task only. Runtime behavior remains unchanged.
