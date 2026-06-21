# BouclePro

Pair-aidance, mutual help and human/AI cooperation platform.

This repository is under active cleaning for public publication. Internal documentation, agent workflows, tests and operational tooling have been archived locally and are not published here.

## Stack

- **Backend:** Laravel · PHP
- **Frontend:** Blade · Alpine.js · Tailwind CSS · Livewire
- **Database:** SQLite *(dev)* · PostgreSQL *(production)*
- **Deployment:** Laravel Cloud

## Development Setup

```bash
composer install
npm install && npm run build
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan storage:link
php artisan serve
```

## Status

Public repository — progressive cleanup phase.
Full product documentation is not yet published here.

## Links

- Production: https://bouclepro.com
- Association AMT: https://amteletravail.fr
