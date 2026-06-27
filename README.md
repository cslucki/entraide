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

## Seed Data

After running `php artisan migrate --seed`, the following demo accounts are available for local development:

| Email | Name | Organization | Role | Password |
|-------|------|-------------|------|----------|
| `admin@bouclepro.test` | Demo Admin | Main | Super Admin | `password` |
| `main.member1@bouclepro.test` | Demo Main Member 1 | Main | Member | `password` |
| `main.member2@bouclepro.test` | Demo Main Member 2 | Main | Member | `password` |
| `launchpals.member1@bouclepro.test` | Demo LaunchPals Member 1 | LaunchPals | Admin | `password` |
| `launchpals.member2@bouclepro.test` | Demo LaunchPals Member 2 | LaunchPals | Member | `password` |

### Organizations

| Property | Main | LaunchPals |
|----------|------|------------|
| Slug | `main` | `launchpals` |
| Platform name | BouclePro | LaunchPals |
| Locale | `fr` | `en` |
| Loop mode | `multi` (multiple loops) | `mono` (single loop) |
| Primary loop | — | LaunchPalsCircle |
| Default org | Yes | No |
| Admin | admin@bouclepro.test | launchpals.member1@bouclepro.test |
| Demo language | French | English |

All demo emails use the reserved `.test` domain and are safe for public documentation.  
Dashboard seeders are skipped in production environments (`app()->environment('production')`).

## License

BouclePro is released under the MIT License. See [LICENSE](LICENSE).

## Links

- Production: https://bouclepro.com
- Association AMT: https://amteletravail.fr
