# ROADMAP.md — Cahier des charges global BouclePro

> **Date et heure de mise à jour : 2026-05-05 - 22h30 (heure du système)**
>
> Vision : plateforme d'échange de compétences entre professionnels, sans argent, via un système de points.
> Nom commercial : **BouclePro** · Domaine : `bouclepro.com`
> Tagline homepage : *"Échangez vos compétences entre professionnels, sans argent, et **créez votre propre boucle**"*
>
> Stack : Laravel 13.7 · PHP 8.4 · Blade · Alpine.js · Livewire 3 · Tailwind CSS v3 · SQLite (dev) / PostgreSQL (prod)
> Hébergement prod : **Laravel Cloud** · Mail : **Resend** (free tier 3 000 emails/mois)
>
> **Ce fichier est la référence humaine.** Les IAs ne le modifient pas sauf sur instruction explicite.
> Pour les tâches assignées, voir `TODO_ClaudeWSL.md` et `TODO_OpenCode.md`.

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
- Dashboard "Tableau de bord" (métriques, mes services, demandes, échanges en cours)
- Système de notation ⭐ 1-5 après échange complété
- Favoris ❤️ (toggle + page /favorites)
- Historique des points (page /points, ledger append-only)
- Signalements polymorphiques (services, demandes, utilisateurs)
- Rate limiting sur routes sensibles
- API REST Sanctum (routes /api/v1)
- Export CSV historique transactions
- Sitemap XML dynamique + robots.txt
- SEO : OG meta tags + JSON-LD schema.org (Service, Person)
- Recherche globale navbar

### Système de communautés multi-tenant (TASK-030→037)
- Routing `/{community}/...` avec middleware `ResolveCommunity`
- Global scope `BelongsToTenantScope` (isolation des données par communauté)
- Migration `communities` + `community_id` sur users/services/requests/transactions
- Seeder : 3 communautés démo (cpme, bni, 60000rebonds)
- Page d'accueil communautaire avec hero personnalisable (image, titre, description, couleur d'accent)
- Redirection intelligente post-login selon `community_id` de l'utilisateur
- Admin : CRUD communautés complet (créer, éditer, activer/désactiver, supprimer)
- Admin : affectation d'utilisateurs à des communautés
- Champs de personnalisation communauté : admin_id, hero_image, hero_title, hero_description, accent_color, welcome_points, is_public

### Profil utilisateur
- Avatar upload + redimensionnement 300×300 (intervention/image)
- Bio (500 chars) + localisation (ville/département)
- Page profil public : services actifs, demandes, avis, stats
- Badges automatiques (premier service, 10 échanges, 50 échanges, note 5/5)
- Bouton "Modifier mon profil" sur la fiche publique (propriétaire uniquement)
- Liens vers profil public depuis l'annuaire, l'explorateur, les favoris

### Verrous et qualité de contenu
- Verrou profil complet avant publication : bio ET localisation obligatoires
- Validation minimum : titre 10 chars, description 100 chars
- UI pédagogique sur le formulaire service (explication micro-service vs annonce emploi)
- Rappel bandeau orange si profil incomplet

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
- Meta-Communauté : choix du mode dark/light pour le site global
- CRUD communautés : créer, éditer, activer/désactiver, supprimer

### UX & Frontend
- Dark mode serveur-side : classe `dark` sur `<html>` contrôlée par DB (Setting `global_color_mode`)
- Mode dark par défaut sur le site global, désactivé sur les pages communauté
- Favicon BouclePro + logo dans la navbar
- Pages d'authentification redessinées : français complet, charte graphique BouclePro, fond dark
- Bouton "Publier" avec sous-menu (Faire une demande / Proposer un service) dans la navbar desktop
- Bouton "Se connecter" visible sur mobile pour les visiteurs non connectés
- Page d'accueil globale avec section "Créez votre boucle" + CTA + formulaire de demande de création
- Page `/boucles` avec bouton "Créer ma boucle" et formulaire de demande communauté
- Graphique évolution solde de points (Chart.js, 60 dernières entrées)

### Qualité & Outillage
- 204 tests automatisés (policies, state machine, controllers, Livewire, admin, API)
- 3 tests Playwright E2E (login, dashboard, admin messages)
- Factories pour tous les modèles
- Middleware bannissement (banned_at)
- Thumbnails automatiques images service
- Notifications email (bienvenue, transaction, message) · driver Resend configuré
- Configuration mail Resend (`resend/resend-php` intégré, config/mail.php prêt)

---

## 🚨 Priorité absolue

### Compléter le système de communautés

| Priorité | Tâche | Assigné |
|---|---|---|
| 🔴 P0 | Page d'accueil globale (`/`) : redirection si connecté + section "Comment ça marche" | OpenCode |
| 🔴 P0 | Admin : vue des demandes de création de boucle (`/boucles/creer` → table `community_requests`) | OpenCode |
| 🟡 P1 | Checkbox "Publier dans la communauté globale" sur les formulaires service/demande | OpenCode |
| 🟡 P1 | Super-admin peut publier dans toutes les communautés | OpenCode |
| 🟡 P1 | Community admin : personnaliser son hero, catégories, couleur depuis `/{community}/settings` | OpenCode |
| 🟡 P1 | Catégories communautaires (community_id nullable sur categories) | OpenCode |

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
- Filtre par localisation (ville/département)

### Messagerie améliorée
- Recherche dans les conversations (par nom ou contenu)
- Signalement d'un message depuis la messagerie
- Upload fichiers/images dans les messages
- Indicateur "en train d'écrire"
- Support Markdown

### Profil enrichi
- Statistiques : taux de complétion, temps de réponse moyen
- Affichage badges sur cartes service

### Géolocalisation avancée
- Tri des services onsite par distance réelle (remplace tri par ville/département)

### Flux d'activité public
- Page /activite : nouveaux services publiés, échanges réussis, badges gagnés

### Intégration calendrier
- Depuis une transaction : bouton "Ajouter au calendrier" (export iCal .ics)

### SEO complémentaire
- Données structurées JSON-LD sur les demandes (RequestController)
- URLs canoniques

---

## 🟢 Confort / UX

- Page FAQ / Aide
- Vue carte Leaflet pour services onsite
- Export PDF historique transactions
- Documentation API OpenAPI/Swagger (endpoints /api/v1 déjà en place)

---

## 🔵 Long terme

### Sous-domaines et marque blanche
- Sous-domaines personnalisés (`cpme.bouclepro.com`) — nécessite VPS/infra dédiée
- Marque blanche complète (CSS custom, domaine custom)

### Gamification avancée
- Niveaux d'utilisateur basés sur l'expérience accumulée
- Classement mensuel des membres les plus actifs (leaderboard)

### Confiance & Sécurité
- Badge "Vérifié" (upload pièce d'identité ou validation manuelle admin)
- Système de litige formel avec médiateur admin (chat tripartite)

### Paiement
- Intégration Stripe : achat de packs de points
- Complément argent réel si solde insuffisant
- Factures PDF automatiques

### Temps réel
- Remplacer polling Livewire 3s par broadcast (Reverb/Pusher)
- Notifications push navigateur / PWA installable

### Extension du modèle d'échange
- Abonnements de services récurrents (ex : 1h de tutorat chaque semaine)
- Enchères de points pour les demandes urgentes

### Multi-langue
- i18n Laravel (FR/EN minimum)
- Détection langue navigateur

### Modération automatisée
- Détection mots-clés abusifs · Anti-spam publication · Blocage liens externes suspects

### App mobile
- React Native ou Flutter consommant l'API REST /api/v1

---

> Suggestions intégrées de : Jules (agent IA frontend) · Cyril (fondateur)
