# Entraide — Note de développement

## Contexte du projet

**Entraide** est une plateforme de troc de services entre pairs. Les utilisateurs gagnent des points en fournissant des services et les dépensent pour recevoir de l'aide d'autres membres. L'interface est entièrement en français.

### Stack technique

| Couche | Technologie |
|---|---|
| Backend | Laravel 11 · PHP 8.4 |
| Database | SQLite (dev) / MySQL (prod) |
| Frontend | Blade · Alpine.js · Tailwind CSS v4 |
| UI réactive | Livewire 3 |
| Auth | Laravel Breeze (Blade + mode sombre) |

### Architecture existante

```
app/
├── Http/
│   ├── Controllers/          ← Service, Request, Transaction, Message,
│   │                         ← Favorite, Point, Report, Review, Dashboard,
│   │                         ← Admin/AdminController, + HomeController,
│   │                         ← ExplorerController, ProfileController, Auth/*
│   └── Middleware/
│       └── AdminMiddleware.php
├── Livewire/
│   ├── Explorer.php          ← recherche/filtrage avec #[Url]
│   └── MessageThread.php     ← composant de messagerie
├── Models/                   ← 13 modèles, tous avec HasUuids (PK UUID)
│   └── User, Category, Skill, Tag, PointGuideline, Service, ServiceRequest,
│       Transaction, PointLedger, Message, Review, Favorite, Report
├── Policies/
│   └── ServicePolicy, ServiceRequestPolicy, TransactionPolicy
└── Providers/
    └── AppServiceProvider.php ← Gate::define() pour MessagePolicy, ReviewPolicy
resources/views/
├── layouts/                  ← app.blade.php, navigation.blade.php, admin.blade.php
├── admin/                    ← 7 vues back-office
├── livewire/                 ← explorer.blade.php, message-thread.blade.php
├── services/, requests/, messages/, favorites/, points/, profile/
```

### Fonctionnalités déjà implémentées

- **Système de points** : bonus de bienvenue +100, échanges atomiques (buyer -N, seller +N), point_ledger en append-only, reason enum (welcome_bonus, exchange_earned, exchange_spent, adjustment)
- **Machine d'état des transactions** : pending → accepted → buyer_done → completed, avec branches refused et cancelled — toutes les transitions sont protégées par des policies
- **Panel admin complet** : stats, gestion users (search, ban, toggle admin/availability, ajustement points), gestion services (liste, force-delete, restore), transactions, demandes, catégories + skills, signalements — avec auto-protection (un admin ne peut pas se bannir lui-même)
- **CRUD complet** : services (avec SoftDeletes), demandes, favoris, signalements, reviews, messages
- **Explorateur** : recherche, filtrage par catégorie/skill/tag/tri via Livewire `#[Url]`
- **Messagerie** : conversations liées aux transactions, via Livewire + contrôleur
- **Convention de code** : validation inline dans les contrôleurs, tags max 5 avec slug + firstOrCreate, services bloqués en édition/suppression si transaction active, tous les contrôleurs avec type hints de retour

---

## Tâches à réaliser

### 1. ⚠️ Activer le polling Livewire sur la messagerie (priorité haute)

**Fichier concerné** : `resources/views/livewire/message-thread.blade.php`

Le composant `MessageThread.php` est prêt pour le polling, mais la directive `wire:poll` est absente du template Blade. La messagerie ne se met pas à jour automatiquement.

**Ce qu'il faut faire** : Ajouter `wire:poll.3s` (ou `wire:poll.3s="refreshMessages"`) sur le conteneur principal de la conversation pour que les nouveaux messages apparaissent automatiquement toutes les 3 secondes, comme prévu dans la spec.

---

### 2. ⚠️ Uniformiser l'enregistrement des policies Message et Review

**Fichiers concernés** : `app/Providers/AppServiceProvider.php`

Actuellement `MessagePolicy` et `ReviewPolicy` sont enregistrées via `Gate::define()` au lieu de `Gate::policy()`. Elles fonctionnent, mais ce n'est pas conforme à l'architecture documentée (fichiers `Policies/`).

**Ce qu'il faut faire** :

- Créer `app/Policies/MessagePolicy.php` et `app/Policies/ReviewPolicy.php` comme classes Policy Laravel standard (étendant ou suivant le pattern des autres policies existantes)
- Les enregistrer via `Gate::policy(Message::class, MessagePolicy::class)` dans `AppServiceProvider`
- Adapter les appels `@can()` / `$this->authorize()` dans les contrôleurs et vues pour utiliser le pattern standard (`$this->authorize('view', $message)` au lieu de `@can('view-message', ...)`)
- Supprimer les `Gate::define()` correspondants

---

### 3. ℹ️ Nettoyage des gates inutilisées

**Fichier concerné** : `app/Providers/AppServiceProvider.php`

Les gates `view-transaction` et `store-message` sont définies mais jamais utilisées dans les contrôleurs.

**Ce qu'il faut faire** : Soit les supprimer, soit les intégrer dans une `TransactionPolicy` / `MessagePolicy` standard si elles ont un intérêt métier. À discuter selon le besoin.

---

### 4. ℹ️ Mettre à jour CLAUDE.md

Le fichier `CLAUDE.md` ne mentionne pas tous les contrôleurs existants (`HomeController`, `ExplorerController`, `ProfileController`, `Auth/*`).

**Ce qu'il faut faire** : Compléter l'arborescence dans CLAUDE.md pour refléter l'état réel du projet, ou au minimum ajouter une note indiquant les contrôleurs additionnels.

---

## Notes importantes pour le développeur

- **Toutes les clés primaires sont des UUIDs** via le trait `HasUuids`. Ne jamais utiliser `->id()` auto-increment.
- **Le système de points est append-only** : on ne modifie jamais une entrée du `point_ledger`, on en crée de nouvelles. Le solde est maintenu sur `users.points_balance` pour la lecture.
- **Les services avec des transactions actives (pending/accepted) ne peuvent pas être modifiés ou supprimés** — c'est vérifié dans `ServicePolicy`.
- **Les actions admin ne doivent jamais affecter l'admin lui-même** (impossible de se bannir ou de retirer ses propres droits admin).
- **Le projet est en français** côté interface utilisateur.

## Commandes utiles

```bash
# Lancer le serveur de dev
php artisan serve

# Fresh database avec données de test
php artisan migrate:fresh --seed

# Watch assets (Node 20+)
npm run dev

# Lancer les tests
php artisan test
```
