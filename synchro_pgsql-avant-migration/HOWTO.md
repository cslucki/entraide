# HOWTO — Synchronisation PostgreSQL → Local

Ce dossier contient tout ce qui servait à importer les données de la base
PostgreSQL de production vers la base PostgreSQL locale.

> **⚠ Important :** Ce script date de l'époque où la stack tournait en
> PostgreSQL. Depuis la migration vers SQLite, cette procédure n'est plus
> à jour. Elle est conservée pour référence.

---

## Pré-requis

- PostgreSQL 18 installé en local
- Un fichier `.env.pgsql` à la racine du projet (voir `.env.pgsql.example`)
- Le fichier de credentials PROD : `~/.config/bouclepro/prod-db.env`
- Le mot de passe admin PostgreSQL (demander à Cyril)

---

## Lancer la synchro complète (dump PROD → import → seeders)

```bash
LOCAL_PG_ADMIN_PASSWORD='password' \
  ./synchro_pgsql-avant-migration/sync-prod-to-local.sh
```

## Tester l'environnement sans dump PROD (--self-test)

Vérifie que tout est bien configuré (outils, connexions, permissions)
**sans toucher à la base de production** :

```bash
LOCAL_PG_ADMIN_PASSWORD='<mot_de_passe>' \
  ./synchro_pgsql-avant-migration/sync-prod-to-local.sh --self-test
```

---

## Ce que fait le script (en résumé)

1. **Dump** de la base de production (lecture seule)
2. **Restaure** le dump dans une base temporaire `bouclepro_prod_import_tmp`
3. **Importe** les données de la base temporaire vers `bouclepro` (ta base locale)
4. **Backfill** : les utilisateurs sans organisation sont rattachés à `main`
5. **Crée les comptes QA** : qa-admin, qa-member1/2, qa-cpme1/2
6. **Nettoie** (supprime la base temporaire)

---

## Résultat attendu

Après la synchro, la base locale `bouclepro` contient :

| Table | Données |
|-------|---------|
| `users` | 19 utilisateurs PROD + 5 comptes QA |
| `services` | 7 services |
| `categories` | 11 catégories |
| `skills` | 54 compétences |
| `transactions` | 2 transactions |
| `messages` | 2 messages |
| `settings` | 3 réglages |
| `blog_posts` | 1 article |
| ... | ~24 autres tables |

Les tables locales (`loops`, `loop_members`, `sessions`, etc.) ne sont **pas touchées**.

---

## Organisation des comptes

| Organisation | Utilisateurs |
|---|---|
| **main** | Tous les utilisateurs PROD + QA Admin + QA Member 1/2 |
| **cpme** | QA CPME 1 + QA CPME 2 |

Tous les comptes QA ont le mot de passe : `password123`

---

## Structure du dossier

```
synchro_pgsql-avant-migration/
├── sync-prod-to-local.sh      # Script principal (shell)
├── sync-prod-to-local.php     # Moteur ETL (PHP)
├── HOWTO.md                   # Ce fichier
├── README.md                  # Documentation complète
├── dumps/                     # Sauvegardes PROD (gitignoré)
└── logs/                      # Rapports d'exécution (gitignoré)
```

---

## Dépannage rapide

**"Organization 'main' does not exist"** → Lance :
```bash
php artisan tinker --execute="\App\Models\Organization::create([
  'name' => 'Main', 'slug' => 'main', 'is_active' => true, 'is_default' => true
]);"
```

**"Cannot connect to local database"** → Vérifie que PostgreSQL tourne :
```bash
sudo service postgresql status
```

**ETL en erreur** → La base temporaire est conservée pour enquêter.

---

## Relancer seulement l'import (sans dump PROD)

Si le dump PROD existe déjà dans `dumps/` :

```bash
LOCAL_DB_PASSWORD='bouclepro_local_2026' \
  php synchro_pgsql-avant-migration/sync-prod-to-local.php
```

---

## Questions ?

Demander à Cyril.
