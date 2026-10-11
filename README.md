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
Local Apache server (as an example for testing) : https://test.laravel 

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

## Jumeau local de la PROD pour QA / rehearsal

Pour reproduire un bug de production, auditer des données réelles ou répéter une release, on travaille sur un **jumeau local** de la PROD — jamais sur la PROD.

### Principe

```
PROD  ──(pg_dump lecture seule)──▶  baseline locale  ──(TEMPLATE)──▶  bouclepro_prod_work
      aucune écriture               neutralisée, immuable             jetable, recréable
```

- **La PROD n'est jamais modifiée.** Le seul contact est un `pg_dump` en lecture seule.
- La **baseline** est une copie locale dont les secrets sont neutralisés (sessions, jetons d'API, jetons de réinitialisation, `remember_token`, clés IA, jetons d'invitation). Elle est scellée `IS_TEMPLATE = true` avec `CONNECTION LIMIT = 0` : elle ne sert que de modèle, **on ne travaille jamais dessus**.
- Le travail se fait sur **`bouclepro_prod_work`**, jetable et recréable en quelques secondes.
- Les **comptes QA sont injectés uniquement dans `bouclepro_prod_work`**.
- `Organization = Tenant` reste la règle : le seed QA n'alimente que `main` et `launchpals` et ne touche aucune autre Organization.

Les empreintes de mot de passe de la PROD sont **conservées** : personne ne connaît les mots de passe des comptes réels. C'est précisément pourquoi l'étape QA est nécessaire pour ouvrir une session sur le jumeau.

### Procédure

**1. Confirmer la version déployée en PROD.** Elle est affichée dans le pied de page du site de production. C'est elle qui nommera la baseline — *pas* la version locale de `develop`, qui peut être plus récente.

**2. Produire un dump PROD frais, en lecture seule**, avec un chemin explicite :

```bash
ai/scripts/pg-dump.sh prod-dump _local/sync_prod/dumps/production_<version>_<horodatage>.dump
```

Toujours passer le chemin : la destination par défaut n'est pas l'emplacement canonique.

**3. Choisir une baseline, ou en créer une.** Lister celles qui existent déjà :

```bash
psql -h 127.0.0.1 -U bouclepro -d postgres \
  -c "SELECT datname, datistemplate, datconnlimit FROM pg_database
       WHERE datname LIKE 'bouclepro_prod_baseline_%' ORDER BY datname;"
```

Pour en créer une depuis le dump — `--dry-run` d'abord, puis la même commande sans :

```bash
export DATABASE_TARGET=bouclepro_prod_baseline_<version_prod>_<AAAAMMJJ>

./_local/sync_prod/create-prod-baseline.sh \
    --dump _local/sync_prod/dumps/<le dump>.dump \
    --name "$DATABASE_TARGET" \
    --dry-run
```

`DATABASE_TARGET` doit valoir exactement `--name`. Ne pas utiliser `--yes` : la confirmation demande de saisir le nom de la base, et c'est la garde.

**4. Recréer le jumeau**, `BASE=` toujours explicite :

```bash
BASE=<la baseline choisie> ./_local/sync_prod/reset-prod-work.sh
```

**5. Vérifier les migrations**, et ne migrer que s'il y en a en attente :

```bash
php artisan migrate:status --env=prodwork
php artisan migrate --env=prodwork        # seulement si Pending > 0
```

**6. Injecter les comptes QA** — dans le jumeau uniquement :

```bash
php artisan db:seed --class=UserSeeder --env=prodwork
```

**7. Vérifier les comptes QA**, en lecture seule : les cinq comptes du tableau *Seed Data* présents, non bannis, dans les Organizations attendues. Un seeder qui sort en succès ne garantit pas un compte connectable — `UserSeeder` utilise `firstOrCreate`, donc il n'écrase rien si l'email existe déjà.

**8. Lancer le rehearsal :**

```bash
./_local/sync_prod/rehearsal-serve.sh      # http://127.0.0.1:8097
```

**9. Tester les connexions et l'isolation tenant** : un membre d'une Organization ne doit atteindre aucune surface d'une autre.

### Garde-fous

- Ne jamais lancer `reset-prod-work.sh` **sans `BASE=`** : son défaut historique pointe encore vers une baseline ancienne, et le jumeau obtenu passerait pour un jumeau courant.
- Ne jamais figer un nom de baseline : plusieurs coexistent, il faut les lister et choisir explicitement.
- Le numéro dans le nom de la baseline est la **version PROD du snapshot**. Que le code de `develop` soit plus récent est normal : c'est `migrate:status` qui tranche.
- Ne jamais écrire sur une baseline, ni sur `bouclepro` (environnement de développement), ni sur `bouclepro_test`.
- Une connexion refusée par une redirection muette vers `/login` n'est pas forcément un défaut : vérifier `banned_at` avant de conclure.
- Un 500 sur le banc peut venir d'un désaccord entre `APP_URL` et le port servi plutôt que du produit. Le script le rappelle au démarrage.

### Outils locaux, hors dépôt public

Ce workflow s'appuie sur deux répertoires **volontairement laissés hors du dépôt** — non suivis par git, absents du dépôt public :

- `_local/sync_prod/` — outils de référence du workflow PROD → LOCAL (`create-prod-baseline.sh`, `reset-prod-work.sh`, `rehearsal-serve.sh`), ainsi que les dumps, rapports et journaux ;
- `ai/scripts/` — outillage d'exploitation, dont `pg-dump.sh`.

Les chemins cités sont relatifs à la racine du dépôt. Les identifiants de production vivent hors du dépôt et ne sont jamais affichés.

### À ne pas utiliser pour le jumeau

- `pg-dump.sh mirror-import`, `prod-mirror`, `import`, `reset` — ces sous-commandes écrasent la base de développement locale ; elles relèvent de l'ancien modèle « restauration par-dessus `bouclepro` », remplacé par le couple baseline / jumeau.
- `QaAccountsSeeder` — déprécié, délègue à `UserSeeder`.
- `LegacyDataOrganizationSeeder` — hors de ce workflow : il réécrit `organization_id` en masse sur plusieurs tables.

## License

BouclePro Core is licensed under the GNU Affero General Public License v3.0 or later (AGPL-3.0-or-later).

Commercial licenses, plugin exceptions, and integration exceptions may be discussed on a case-by-case basis.

Existing third-party dependencies keep their own licenses.

## Links

- Production: https://bouclepro.com
- Association AMT: https://amteletravail.fr
