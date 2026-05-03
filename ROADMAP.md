# ROADMAP.md — Cahier des charges global Entraide

> **Date de mise à jour : 2026-05-03**
>
> Vision : plateforme d'échange de services entre particuliers, sans argent, via un système de points.
> Stack : Laravel 13.7 · PHP 8.4 · Blade · Alpine.js · Livewire 3 · Tailwind CSS v4 · SQLite/MySQL
>
> **Ce fichier est la référence humaine.** Les IAs ne le modifient pas.
> Pour les tâches assignées, voir `TODO_WSL.md` et `TODO_Jules.md`.

---

## ✅ Livré (main, avril-mai 2026)

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
- Créer un utilisateur depuis l'admin + changer son mot de passe
- Gestion services : liste, soft-delete, restauration
- Gestion transactions : liste avec filtre statut
- Gestion demandes : liste, clôture forcée
- Catégories CRUD + compétences inline
- Traitement des signalements
- Configuration plateforme (nom, tagline, mode maintenance) via table settings
- Modération des messages : liste paginée, filtres, détail contextuel, suppression

### UX & Frontend
- Dark mode toggle persistant (localStorage + Alpine.js)
- Graphique évolution solde de points (Chart.js, 60 dernières entrées)

### Qualité & Outillage
- 204 tests automatisés (policies, state machine, controllers, Livewire, admin, API)
- 3 tests Playwright E2E (login, dashboard, admin messages)
- Factories pour tous les modèles
- Middleware bannissement (banned_at)
- Thumbnails automatiques images service
- Notifications email (bienvenue, transaction, message)
- Recherche globale navbar
- API REST Sanctum (routes /api/v1)
- Sitemap XML dynamique + robots.txt
- SEO : OG meta tags + JSON-LD schema.org (Service, Person)
- Export CSV historique transactions

---

## 🔴 Priorité haute

### Parrainage (suggestion Cyril + Jules)
- Code de parrainage unique par utilisateur (affiché sur le profil)
- Récompense en points au parrain ET au filleul à l'inscription
- "Double parrainage" : points supplémentaires si le filleul complète sa 1ère transaction
- Page de suivi des filleuls dans le profil

### Centre de notifications in-app (suggestion Cyril + Jules)
- Panneau latéral ou page /notifications listant toutes les activités
- Événements : nouveau message, transaction acceptée/refusée/complétée, badge gagné, signalement traité
- Marquer comme lu / tout marquer comme lu
- Badge compteur dans la navbar (comme les messages)

---

## 🟡 Priorité moyenne

### UX Explorateur
- Pagination infinie automatique (Intersection Observer)
- Mode liste / grille (toggle)
- Filtre par localisation (ville/département) — TASK-028 WSL

### Messagerie améliorée
- Recherche dans les conversations — TASK-025 WSL
- Signalement d'un message depuis la messagerie
- Upload fichiers/images dans les messages
- Indicateur "en train d'écrire"
- Support Markdown

### Profil enrichi
- Statistiques : taux de complétion, temps de réponse moyen — TASK-026 WSL
- Affichage badges sur cartes service

### Géolocalisation avancée (suggestion Cyril + Jules)
- Utiliser l'API de géolocalisation du navigateur
- Tri des services onsite par distance réelle (remplace tri par ville/département)

### Flux d'activité public (suggestion Cyril + Jules)
- Page /activite : nouveaux services publiés, échanges réussis, badges gagnés
- Rendre la plateforme plus vivante et encourager la participation

### Intégration calendrier (suggestion Cyril + Jules)
- Depuis une transaction : bouton "Ajouter au calendrier"
- Export au format iCal (.ics) compatible Google Calendar, Apple Calendar, Outlook

### SEO complémentaire
- Données structurées JSON-LD sur les demandes (RequestController)
- URLs canoniques

---

## 🟢 Confort / UX

- Page FAQ / Aide
- Vue carte Leaflet pour services onsite
- Export PDF historique transactions
- Documentation API OpenAPI/Swagger (endpoints /api/v1 déjà en place) — TASK-027 WSL

---

## 🔵 Long terme

### Groupes & Marque blanche (suggestion Cyril)
- Cercles / groupes privés : communautés fermées (immeuble, association, entreprise)
- Sous-domaines ou espaces dédiés — les membres échangent entre eux sans voir les autres groupes
- Opportunité de croissance B2B (marque blanche pour collectivités, entreprises)

### Gamification avancée (suggestion Jules)
- Niveaux d'utilisateur basés sur l'expérience accumulée
- Classement mensuel des membres les plus actifs (leaderboard)

### Confiance & Sécurité (suggestion Jules)
- Badge "Vérifié" (upload pièce d'identité ou validation manuelle admin)
- Système de litige formel avec médiateur admin (chat tripartite)

### Paiement
- Intégration Stripe : achat de packs de points
- Complément argent réel si solde insuffisant
- Factures PDF automatiques

### Temps réel
- Remplacer polling Livewire 3s par broadcast (Reverb/Pusher) — TASK-014 BLOQUÉ
- Notifications push navigateur / PWA installable

### Extension du modèle d'échange (suggestion Jules)
- Abonnements de services récurrents (ex : 1h de tutorat chaque semaine)
- Enchères de points pour les demandes urgentes

### Multi-langue
- i18n Laravel (FR/EN minimum)
- Détection langue navigateur

### Modération automatisée
- Détection mots-clés abusifs
- Anti-spam publication
- Blocage liens externes suspects

### App mobile
- React Native ou Flutter consommant l'API REST /api/v1

---

> Suggestions intégrées de : Jules (agent IA frontend) · Cyril (fondateur)
