# Entraide — Plateforme de troc de services

Plateforme permettant à des professionnels indépendants d'échanger leurs compétences sans argent, via un système de points.

Un projet porté par l'association **[AMT](https://amteletravail.fr)** (RNA W133002043), initié par **Cyril Slucki**.

**Stack :**

| Couche | Technologie |
|--------|-------------|
| Backend | Laravel 13.7 · PHP 8.4 |
| Base de données | SQLite (dev) · PostgreSQL (prod via Laravel Cloud) |
| Frontend | Blade · Alpine.js · Tailwind CSS v4 |
| UI réactive | Livewire 3 |
| Auth | Laravel Breeze (Blade + dark mode) |
| Traitement image | `intervention/image` v4 (avatar 300×300, miniatures services) |
| Déploiement | Laravel Cloud (auto-deploy sur `main`) |

---

## Installation (dev)

### Prérequis

- PHP 8.4+
- Composer
- Node.js + npm

### Étapes

```bash
composer install
npm install && npm run build
cp .env.example .env
php artisan key:generate
touch database/database.sqlite   # SQLite uniquement
php artisan migrate --seed
php artisan storage:link
php artisan serve
```

Ouvrir `http://localhost:8000`.

---

## Comptes de test

Après `php artisan migrate --seed` :

| Email | Mot de passe | Rôle |
|-------|-------------|------|
| test@example.com | password | Utilisateur |
| alice@example.com | password | Utilisateur |
| admin@example.com | password | Super-admin |

Chaque compte démarre avec **100 points**.

---

## Fonctionnalités

### Utilisateurs
- Inscription / Connexion + bonus 100 pts à l'inscription
- Profil public : bio, localisation, site web, LinkedIn, badges, note, disponibilité
- Upload avatar (redimensionné 300×300)
- Obligation de renseigner une présentation avant de publier (middleware)

### Services
- Publication avec compétences, tags, mode de prestation (remote / présentiel / les deux)
- Barème de points unifié : **Essentiel 40–60 pts** · **Standard 60–80 pts** · **Complet 80–100 pts**
- Miniatures générées en tâche de fond (queue)
- Mise en pause / suppression (bloquée si transaction active)

### Demandes
- Budget min/max, délai, pièces jointes (images, PDF, Word, Excel)
- Statuts : open / closed

### Transactions
- Cycle complet : pending → accepted → buyer_done → completed
- Transfert de points atomique via `point_ledger`
- Évaluations (note + commentaire) à la clôture

### Messagerie
- Messagerie contextuelle par transaction (Livewire, polling 3 s)
- Messages système automatiques à chaque changement de statut

### Explorateur
- Recherche full-text, filtres catégorie (multiselect) et mode de prestation

### Communautés (multi-tenant)
- Espaces dédiés via `/{community}` avec landing publique ou privée
- Portail d'auth propre à chaque communauté
- Données isolées par `community_id` (global scope)

### Administration
- Gestion utilisateurs, services, demandes, communautés
- Édition services : super-admin (tous) ou admin de communauté (les siens)
- Signalements, badges, paramètres

---

## Structure des points

Un service coûte entre **40 et 100 points** :

| Niveau | Points | Durée estimée |
|--------|--------|---------------|
| Essentiel | 40 – 60 pts | 20 à 30 min |
| Standard | 60 – 80 pts | 30 à 45 min |
| Complet | 80 – 100 pts | 45 à 60 min |

---

## Déploiement (prod)

Le déploiement est automatique via **Laravel Cloud** sur chaque push sur `main`. Les migrations sont exécutées automatiquement (`migrate --force`). Les seeders ne tournent pas en prod — les données de référence (catégories, fourchettes de points) sont embarquées dans les migrations elles-mêmes.

---

## Liens

- Production : [bouclepro.com](https://bouclepro.com)
- Association AMT : [amteletravail.fr](https://amteletravail.fr)
- Code source : [github.com/cslucki/entraide](https://github.com/cslucki/entraide)
- Mentions légales : `/mentions-legales`
