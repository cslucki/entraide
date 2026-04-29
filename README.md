# Entraide — Plateforme de troc de services

Plateforme permettant à des professionnels indépendants d'échanger leurs compétences sans argent, via un système de points.

**Stack :** Laravel 11 · PHP 8.3+ · MySQL ou SQLite · Blade · Livewire 3 · Alpine.js · Tailwind CSS

---

## Installation rapide (WAMP / XAMPP / Windows)

### Prérequis

- PHP 8.3+ (inclus dans WAMP)
- [Composer](https://getcomposer.org/) installé et dans le PATH
- MySQL (inclus dans WAMP) **ou** SQLite (aucun serveur requis)

### Option A — Script automatique (recommandé)

1. Copiez le dossier du projet dans `C:\wamp64\www\entraide\`
2. Double-cliquez sur **`install.bat`**
3. Ouvrez `http://localhost/entraide/public`

### Option B — Installation manuelle

```bash
# 1. Installer les dépendances PHP
composer install

# 2. Créer le fichier de configuration
copy .env.example .env

# 3. Générer la clé de l'application
php artisan key:generate

# 4. (SQLite uniquement) Créer le fichier de base de données
echo.> database\database.sqlite

# 5. Lancer les migrations et peupler la base
php artisan migrate --seed

# 6. Créer le lien de stockage des fichiers
php artisan storage:link
```

---

## Configuration de la base de données

Ouvrez `.env` et choisissez :

**MySQL (WAMP) :**
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=entraide
DB_USERNAME=root
DB_PASSWORD=
```
Créez la base `entraide` dans phpMyAdmin avant de lancer les migrations.

**SQLite (plus simple, aucun serveur) :**
```env
DB_CONNECTION=sqlite
```
Le fichier `database/database.sqlite` est créé automatiquement.

---

## Comptes de test

Après `php artisan migrate --seed` :

| Email | Mot de passe |
|---|---|
| test@example.com | password |
| alice@example.com | password |

Chaque compte démarre avec **100 points**.

---

## Fonctionnalités MVP

- Inscription / Connexion (Laravel Breeze) + bonus 100 pts à l'inscription
- Publication et gestion de **services** (skills, tags, mode de prestation, points)
- Publication et gestion de **demandes** avec fourchettes indicatives
- **Explorateur** avec recherche full-text, filtres catégorie (multiselect) et mode
- **Transactions** : cycle de vie complet (pending → accepted → buyer_done → completed)
- Transfert de points atomique via `point_ledger`
- **Messagerie** contextuelle par transaction (Livewire, polling 3s, messages système)
- **Dashboard** : 5 métriques, mes annonces, échanges en cours, messages récents
- **Profil public** avec services actifs, note et disponibilité

---

## Démarrage rapide (dev)

```bash
php artisan serve
# Ouvrir http://localhost:8000
```
