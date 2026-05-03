# Entraide — TODO / Roadmap

> Dernière mise à jour : 2026-05-01
> Tests : 74 passent · Branche active : `main`

---

## ✅ Fait (MVP complet)

### Fonctionnalités core
- [x] Services CRUD (titre, description, catégorie, skills, tags, mode, points, statut, soft-delete)
- [x] Images de service (max 5, 2 Mo, galerie sur la fiche)
- [x] Demandes CRUD (budget min/max, deadline, statut)
- [x] Transactions — cycle de vie complet (pending → accepted → buyer_done → completed)
- [x] Ledger de points atomique à la complétion (DB::transaction)
- [x] Messagerie Livewire split-view + polling 3s + messages système
- [x] Badge messages non lus dans la navbar
- [x] Explorateur Livewire (recherche, filtres catégorie/mode, tri, tags, charger plus)
- [x] Dashboard (métriques, mes services, mes demandes, échanges en cours, messages récents)
- [x] Système de notation ⭐ (1-5 étoiles après échange complété, recalcul automatique)
- [x] Favoris ❤️ (toggle + page /favorites)
- [x] Historique des points (page /points, ledger append-only)
- [x] Signalements (services, demandes, utilisateurs — polymorphique)
- [x] Rate limiting sur les routes sensibles

### Profil utilisateur
- [x] Avatar upload + redimensionnement 300×300 (intervention/image)
- [x] Avatar par défaut ui-avatars.com
- [x] Bio (500 chars) et localisation (ville/département)
- [x] Page profil public : services actifs, demandes ouvertes, avis reçus, stats

### Back office admin
- [x] Layout sidebar `/admin` (x-admin-layout)
- [x] Dashboard : 7 statistiques plateforme
- [x] Utilisateurs : recherche, filtre, ban/unban, toggle admin, ajustement de points
- [x] Services : liste avec soft-deleted, force-delete, restauration
- [x] Transactions : liste avec filtre par statut
- [x] Demandes : liste avec filtre, clôture forcée
- [x] Catégories : CRUD complet + gestion des compétences inline
- [x] Signalements : traiter ou ignorer

### Qualité
- [x] 74 tests automatisés (policies, state machine, controllers, flux complet)
- [x] Factories pour tous les modèles
- [x] Toast notifications globales (success/error/info)
- [x] CLAUDE.md + AGENTS.md à jour

---

## 🔴 Critique — À faire en priorité

### Tests manquants
- [ ] Tests des composants Livewire (Explorer, MessageThread)
- [ ] Tests du panneau admin (ban, ajustement points, CRUD catégories)

### Images
- [ ] Thumbnail automatique pour les images de service (resize côté serveur à l'upload)

### Sécurité / robustesse
- [ ] Vérifier que les utilisateurs bannis ne peuvent plus se connecter (check `banned_at` dans le middleware auth)
- [ ] Valider la cohérence du solde de points (guard négatif dans les transactions)

---

## 🟡 Important

### Notifications email
- [ ] Configurer mailer (dev : log / prod : SMTP ou Resend)
- [ ] Mail de bienvenue à l'inscription
- [ ] Mail quand une transaction est acceptée / refusée / complétée
- [ ] Mail quand on reçoit un nouveau message
- [ ] Mail de récap hebdomadaire (optionnel)

### Notifications temps réel
- [ ] Remplacer le polling Livewire (3s) par broadcast ou Server-Sent Events
- [ ] Toast global quand un nouveau message est reçu
- [ ] Badge navbar mis à jour sans rechargement
- [ ] Notification quand une transaction change d'état

### Messagerie améliorée
- [ ] Marquer les messages comme lus (lire = ouvrir la conversation)
- [ ] Upload de fichiers/images dans les messages
- [ ] Indicateur "en train d'écrire"
- [ ] Recherche dans les conversations
- [ ] Support Markdown dans les messages

### SEO
- [ ] Meta tags dynamiques (title, description, og:image) sur chaque service et demande
- [ ] Sitemap XML généré automatiquement (`spatie/laravel-sitemap`)
- [ ] Données structurées JSON-LD (Service, Person, Review)
- [ ] Robots.txt

---

## 🟢 Confort / UX

### Explorateur
- [ ] Pagination infinie automatique (Intersection Observer, sans bouton)
- [ ] Filtre par note minimum (ex : ≥ 4/5)
- [ ] Filtre par localisation (même ville/département)
- [ ] Mode liste / grille (toggle)
- [ ] Vue carte (Leaflet.js) pour les services onsite

### Profil
- [ ] Affichage des badges sur le profil et les cartes service
- [ ] Statistiques enrichies : taux de complétion, temps de réponse moyen

### Gamification
- [ ] Badges automatiques :
  - "Premier service publié"
  - "10 échanges réalisés"
  - "50 échanges réalisés"
  - "Note 5/5 sur 5 avis"
  - "Membre depuis 1 an"
- [ ] Classement des meilleurs contributeurs (page dédiée, optionnel)

### UX diverses
- [ ] Dark mode toggle persistant (localStorage ou colonne `users.dark_mode`)
- [ ] Recherche globale dans la navbar (services + demandes + utilisateurs)
- [ ] Graphique d'évolution du solde de points (page /points)
- [ ] Export de l'historique des transactions (CSV)
- [ ] Page FAQ / Aide

---

## 🔵 Long terme / Roadmap

### API REST
- [ ] Routes API (prefix `/api/v1`) avec Sanctum tokens
- [ ] Endpoints : services, requests, transactions, messages, profile
- [ ] Rate limiting par token
- [ ] Documentation OpenAPI/Swagger

### Paiement
- [ ] Intégration Stripe (achat de packs de points)
- [ ] Complément en argent réel si solde insuffisant
- [ ] Factures automatiques (PDF)

### Planning / Disponibilité
- [ ] Créneaux de disponibilité par utilisateur
- [ ] Affichage sur le profil public
- [ ] Suggestion de créneaux à l'initiation d'une transaction

### Modération automatisée
- [ ] Détection de mots-clés abusifs dans descriptions/messages
- [ ] Anti-spam : limite de publication par heure
- [ ] Blocage de liens externes suspects

### Multi-langue
- [ ] Package i18n Laravel
- [ ] Traduction FR/EN des vues et des emails
- [ ] Détection de la langue du navigateur
