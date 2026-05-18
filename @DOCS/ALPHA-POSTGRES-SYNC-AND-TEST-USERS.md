# Alpha1 PostgreSQL Sync And Test Users

Date: 2026-05-18

Scope: local alpha1 worktree only.

## Objective

Document the local alpha1 environment used to validate the BouclePro/Cyberworkers T073 pre-T074 production snapshot without touching production, running migrations, or backporting later work.

## Baseline

- Worktree: `/home/cyril/claude-code/sites/alpha1.test.laravel`
- Branch: `ALPHA-SETUP-01-alpha1-setup`
- Base commit: `b392a134e85a26a5018d2c371aeaebe20802bb63`
- Base meaning: production `main`, T073 pre-T074
- Local URL: `https://alpha1.test.laravel`
- Local database: `bouclepro_alpha1`
- Database connection: PostgreSQL

`develop`, T074, T075, T076, and later work were intentionally not used.

## Apache And HTTPS

- Apache HTTPS alpha vhost is active for `alpha1.test.laravel`.
- The vhost points to `/home/cyril/claude-code/sites/alpha1.test.laravel/public`.
- mkcert certificate files were provided locally by Cyril and applied manually with privileges outside the agent session.
- The agent did not write Apache config files directly and did not use sudo.

## Database Import

- Local PostgreSQL target: `127.0.0.1:5432/bouclepro_alpha1`.
- Authorized local dump source: `/home/cyril/claude-code/sites/test.laravel/storage/app/dumps/production_2026-05-16_18-42-55.sql`.
- Dump type: PostgreSQL custom dump.
- Import command used `pg_restore --no-owner --no-acl`.
- No remote production dump was created.
- No dump content, database password, user password, or personal data was displayed or documented.

Post-import verification:

- `tables_before=0`.
- `drop schema public cascade; create schema public;` was attempted but refused because schema `public` is owned by `postgres`.
- Import proceeded because the target database was empty and the alpha database user could create in `public`.
- `tables_after=40`.
- `sessions` table present.
- `users` table present.
- `users_count=18` immediately after import.

## Migration Rule

No migration is part of this alpha reproduction path.

This alpha database intentionally reproduces the imported production snapshot. Do not run `php artisan migrate`, `php artisan migrate:fresh`, or seeds unless a later task explicitly authorizes it.

## Permissions

Cyril manually fixed local Laravel writable paths outside the agent session:

- Created missing directories under `storage/framework/views`, `storage/framework/cache/data`, `storage/framework/sessions`, `storage/logs`, and `bootstrap/cache`.
- Applied ownership compatible with local user and Apache group.
- Applied writable directory/file permissions for local alpha runtime.

## Storage Link

- `php artisan storage:link` was executed after Vite validation.
- `public/storage` now points to `storage/app/public`.
- `public/storage` was not committed.
- `storage/app/public` exists locally but does not contain the two avatar files referenced by the current home page.

## Node And Vite

- `npm ci` was run without sudo.
- `npm run build` succeeded with local Vite.
- Local Vite version reported by build: `v8.0.10`.
- Generated manifest initially fixed the stale CSS reference from missing `assets/app-DQMOgrmh.css` to existing `assets/app-CosGWsUZ.css`.
- RUN decision later authorized committing `public/build/manifest.json` plus only the required ignored CSS asset `public/build/assets/app-CosGWsUZ.css`.
- `public/build/assets/app-BE0FrHm7.js` was already tracked and was not force-added.
- `node_modules/` remains local-only and untracked.

## Test Users

Five test login/password pairs are configured in local alpha `.env` variables:

- `TEST_ADMIN_LOGIN` / `TEST_ADMIN_PASSWORD`
- `TEST_MEMBER1_LOGIN` / `TEST_MEMBER1_PASSWORD`
- `TEST_MEMBER2_LOGIN` / `TEST_MEMBER2_PASSWORD`
- `TEST_MEMBER_OF_CPME1_LOGIN` / `TEST_MEMBER_OF_CPME1_PASSWORD`
- `TEST_MEMBER_OF_CPME2_LOGIN` / `TEST_MEMBER_OF_CPME2_PASSWORD`

Safety rules:

- Test password values are not documented.
- Database password values are not documented.
- Personal emails from the imported dump are not documented.
- The test login variables use controlled local QA addresses.

Test user status:

- `TEST_ADMIN_LOGIN`: exists in DB.
- `TEST_MEMBER1_LOGIN`: exists in DB.
- `TEST_MEMBER2_LOGIN`: exists in DB.
- `TEST_MEMBER_OF_CPME1_LOGIN`: exists in DB.
- `TEST_MEMBER_OF_CPME2_LOGIN`: exists in DB.
- `users_count=23` after local test-user injection.

Validation already performed:

- Admin login reached `/dashboard` in headless browser validation.
- Member login reached `/dashboard` in headless browser validation.
- Unauthenticated `/dashboard` redirects to `/login`.

## Final HTTP Validation

- Home: `HTTP/1.1 200 OK`.
- Login: `HTTP/1.1 200 OK`.
- Dashboard unauthenticated: `HTTP/1.1 302 Found` to `/login`.
- Final home fetch returned HTTP `200`.

## Known Local Limitation

- The home page references 2 unique `/storage/avatars/...` URLs.
- `public/storage` is linked correctly.
- The referenced avatar files are absent in local alpha storage.
- The exact referenced avatar files were not found in the inspected original repo storage path.
- RUN decision: do not copy avatars now; accept the 2 missing avatars as a non-blocking local alpha limitation.

## Explicit Non-Actions

- No `php artisan migrate`.
- No `php artisan migrate:fresh`.
- No seed.
- No additional dump import after the authorized alpha import.
- No Laravel runtime patch.
- No application code changes under `app/`, `routes/`, `resources/`, `database/`, or `config/`.
- No Community to Organization migration.
- No T074/T075/T076 backport.
- No production media sync.
- No `.env` commit.
- No secret documentation.
- No production/main branch modification.

## Operational Pitfalls

- Do not run migrations on this alpha database unless a future task explicitly authorizes it.
- Do not replace this database with seed data; it is a local production snapshot for alpha validation.
- Do not commit `.env` or copy test passwords into docs, TASK files, or shell output.
- Do not copy production media unless a future RUN decision explicitly authorizes the exact source and scope.
- Do not infer tenant isolation from `Loop`; the tenant boundary is `Organization`, while this snapshot still contains legacy compatibility columns.
