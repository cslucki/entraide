# =========================================================
# ENVIRONNEMENT LOCAL POSTGRESQL
# Usage : cp .env.pgsql .env && php artisan migrate:fresh --seed
# Retour SQLite : cp .env .env.bak && cp .env.example .env
# =========================================================

APP_NAME=Entraide
APP_ENV=local
APP_KEY=base64:2Ne8LY/rCJMaQJNeWA6C51B/8bgdl2XIfeAHBfjRwHk=
APP_DEBUG=true
APP_URL=https://test.laravel

APP_LOCALE=fr
APP_FALLBACK_LOCALE=fr
APP_FAKER_LOCALE=fr_FR

APP_MAINTENANCE_DRIVER=file

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=debug

# ---------------------------------------------------------
# BASE DE DONNÉES — PostgreSQL (parité Laravel Cloud)
# ---------------------------------------------------------
# PostgreSQL 18 — même version que Laravel Cloud production
# Utiliser pg_dump/pg_restore pour la synchro prod ↔ local
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=bouclepro
DB_USERNAME=bouclepro
DB_PASSWORD=bouclepro_local_2026

# ---------------------------------------------------------
# SESSION / CACHE / QUEUE
# ---------------------------------------------------------
# Local : database (SQLite-compatible)
# Prod (Laravel Cloud) : Redis
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database

CACHE_STORE=database

# ---------------------------------------------------------
# EMAILS
# ---------------------------------------------------------
# Dev : Resend (test mode)
# Prod (Laravel Cloud) : Resend (même config)
MAIL_MAILER=resend
RESEND_API_KEY=re_iSeEN5UJ_KrPifHWGxuS6w4bsuYGofcrx
MAIL_FROM_ADDRESS=noreply@bouclepro.com
MAIL_FROM_NAME="BouclePro"

VITE_APP_NAME="${APP_NAME}"

# ---------------------------------------------------------
# TEST USERS
# ---------------------------------------------------------
# Super-Admin
TEST_ADMIN_LOGIN=admin@example.com
TEST_ADMIN_PASSWORD=password123

# Members globaux (hors CPME)
TEST_MEMBER1_LOGIN= alice@example.com
TEST_MEMBER1_PASSWORD=password123
TEST_MEMBER2_LOGIN=cyril@teletravailleurs.com
TEST_MEMBER2_PASSWORD=password123

# Members CPME (isolation test)
TEST_MEMBER_OF_CPME1_LOGIN="bob@example.com"
TEST_MEMBER_OF_CPME1_PASSWORD="password123"
TEST_MEMBER_OF_CPME2_LOGIN="john@example.com"
TEST_MEMBER_OF_CPME2_PASSWORD="password123"
