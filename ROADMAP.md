# ROADMAP.md — Cahier des charges global Entraide

> Vision : plateforme d'échange de services entre particuliers, sans argent, via un système de points.
> Stack : Laravel 13.7 · PHP 8.4 · Blade · Alpine.js · Livewire 3 · Tailwind CSS v4 · SQLite/MySQL
>
> **Ce fichier est la référence humaine.** Les IAs ne le modifient pas.
> Pour les tâches assignées, voir `TODO_WSL.md` et `TODO_Jules.md`.

---

## ✅ MVP — Livré (main, avril-mai 2026)

### Core
- Services CRUD (titre, description, catégorie, skills, tags, mode, points, soft-delete)
- Demandes CRUD (budget min/max, deadline, statut)
- Transactions : cycle complet pending → accepted → buyer_done → completed
- Ledger de points atomique (DB::transaction)
- Messagerie Livewire split-view + polling 3s + messages système
- Badge messages non lus navbar
- Explorateur Livewire (recherche, filtres, tri, tags, charger plus)
- Dashboard (métriques, mes services, demandes, échanges en cours)
- Système de notation ⭐ 1-5 après échange complété
- Favoris ❤️ (toggle + page /favorites)
- Historique des points (page /points, ledger append-only)
- Signalements polymorphiques (services, demandes, utilisateurs)
- Rate limiting sur routes sensibles

### Profil utilisateur
- Avatar upload + redimensionnement 300×300 (intervention/image)
- Bio (500 chars) + localisation (ville/département)
- Page profil public : services actifs, demandes, avis, stats
- Badges automatiques (premier service, 10 échanges, 50 échanges, note 5/5)

### Back-office admin
- Dashboard : 7 statistiques plateforme
- Gestion utilisateurs : recherche, ban/unban, toggle admin, ajustement points
- Gestion services : liste, soft-delete, restauration
- Gestion transactions : liste avec filtre statut
- Gestion demandes : liste, clôture forcée
- Catégories CRUD + compétences inline
- Traitement des signalements

### Qualité
- 169 tests automatisés (policies, state machine, controllers, Livewire, admin, API)
- Factories pour tous les modèles
- Middleware bannissement (banned_at)
- Thumbnails automatiques images service
- Notifications email (bienvenue, transaction, message)
- Messagerie : marquer comme lu + badge navbar temps réel
- Recherche globale navbar
- API REST Sanctum (routes /api/v1)
- Sitemap XML dynamique + robots.txt
- SEO : OG meta tags + JSON-LD schema.org (en cours PR #16)
- Export CSV historique transactions (en cours PR #16)

---

## 🔴 Priorité haute

### Admin étendu (TASK-020)
- Table `settings` clé/valeur : nom plateforme, tagline, maintenance
- Page admin "Configuration" pour modifier ces paramètres
- Créer un compte utilisateur depuis l'admin
- Changer le mot de passe d'un utilisateur depuis l'admin

---

## 🟡 Priorité moyenne

### UX Explorateur
- Pagination infinie automatique (Intersection Observer)
- Mode liste / grille (toggle)
- Filtre par localisation (ville/département)
- Filtre par note minimum (déjà livré ✅)

### Messagerie améliorée
- Recherche dans les conversations
- Upload fichiers/images dans les messages
- Indicateur "en train d'écrire"
- Support Markdown

### Profil enrichi
- Statistiques : taux de complétion, temps de réponse moyen
- Affichage badges sur cartes service

### SEO complémentaire
- Données structurées JSON-LD sur les demandes (RequestController)
- URLs canoniques

---

## 🟢 Confort / UX

- Dark mode toggle persistant localStorage (TASK-017 — Jules)
- Graphique évolution solde de points (TASK-018 — Jules)
- Page FAQ / Aide
- Vue carte Leaflet pour services onsite
- Export PDF historique transactions

---

## 🔵 Long terme

### API & intégrations
- Documentation OpenAPI/Swagger (endpoints /api/v1 déjà en place)
- App mobile (React Native ou Flutter) consommant l'API

### Paiement
- Intégration Stripe : achat de packs de points
- Complément argent réel si solde insuffisant
- Factures PDF automatiques

### Temps réel
- Remplacer polling Livewire 3s par broadcast (Reverb/Pusher)
- Notifications push navigateur

### Multi-langue
- i18n Laravel (FR/EN minimum)
- Détection langue navigateur

### Modération automatisée
- Détection mots-clés abusifs
- Anti-spam publication
- Blocage liens externes suspects

### Planning / Disponibilité
- Créneaux de disponibilité par utilisateur
- Suggestion de créneaux à l'initiation d'une transaction
