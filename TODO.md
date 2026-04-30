# Entraide — TODO / Évolutions

## 🔴 Critique

### Tests automatisés
- [x] Configurer PHPUnit/Pest
- [x] Tests des policies (Service, ServiceRequest, Transaction, Message, Review)
- [x] Tests du système de points (welcome_bonus, exchange_earned/exchange_spent, adjustment)
- [x] Tests de la machine d'état des transactions (pending → accepted → buyer_done → completed)
- [x] Tests des contrôleurs (ServiceController, RequestController, TransactionController)
- [ ] Tests des composants Livewire (Explorer, MessageThread)
- [x] Tests d'intégration (flux complet : créer service → initier transaction → compléter → points échangés)

### Upload d'avatars
- [x] Permettre aux utilisateurs d'uploader une photo de profil
- [x] Stockage via Laravel Storage (local / S3)
- [x] Redimensionnement et crop (intervention/image ou spatie/laravel-medialibrary)
- [x] Avatar par défaut généré (initiales ou placeholder)

### Images pour les services
- [x] Permettre d'ajouter des images à un service (relation polymorphique ou table dédiée)
- [x] Galerie d'images sur la page show
- [x] Limitation : max 5 images, taille max 2 Mo
- [ ] Thumbnail automatique

---

## 🟡 Important

### Notifications temps réel
- [ ] Remplacer le polling Livewire (3s) par des événements broadcast ou Server-Sent Events
- [ ] Toast global quand nouveau message reçu
- [ ] Badge navbar mis à jour en temps réel (messages non lus)
- [ ] Notification quand une transaction change d'état

### Notifications email
- [ ] Configurer mailer (dev : log / prod : SMTP ou Resend)
- [ ] Mail de bienvenue à l'inscription
- [ ] Mail quand un utilisateur répond à mon service/demande
- [ ] Mail quand une transaction est acceptée / refusée / complétée
- [ ] Mail quand on reçoit un nouveau message
- [ ] Mail de récap hebdomadaire (optionnel)

### Messagerie améliorée
- [ ] Upload de fichiers dans les messages (images, documents)
- [ ] Support Markdown dans les messages
- [ ] Indicateur "en train d'écrire"
- [ ] Marquer un message comme lu
- [ ] Recherche dans les conversations

### Profil utilisateur enrichi
- [x] Bio / description (textarea, max 500 caractères)
- [x] Localisation (ville, département — utile pour services onsite)
- [x] Page de profil public listant tous les services actifs
- [x] Affichage des compétences sur le profil
- [x] Statistiques : nombre d'échanges réalisés, note moyenne, membre depuis

---

## 🟢 Confort / UX

### Explorer
- [x] Bouton "Charger plus" (pagination infinie partielle)
- [ ] Vraie pagination infinie (scroll observer automatique)
- [ ] Filtre par localisation (proximité)
- [ ] Filtre par note minimum
- [ ] Mode liste / grille
- [ ] Vue map pour les services onsite

### Planning / Disponibilité
- [ ] L'utilisateur peut définir des créneaux de disponibilité
- [ ] Affichage sur le profil public
- [ ] Suggestion de créneaux quand on initie une transaction

### Gamification
- [ ] Badges automatiques :
  - "Premier service publié"
  - "10 échanges réalisés"
  - "50 échanges réalisés"
  - "Note 5/5"
  - "Membre depuis 1 an"
- [ ] Affichage des badges sur le profil et dans les cartes service
- [ ] Classement des meilleurs contributeurs (optionnel, page dédiée)

### SEO
- [ ] Meta tags dynamiques (title, description, og:image) sur chaque service
- [ ] Sitemap XML généré automatiquement
- [ ] Données structurées JSON-LD (Service, Person, Review)
- [ ] URLs canoniques
- [ ] Robots.txt

### Améliorations diverses
- [ ] Dark mode toggle persistant (stocké en localStorage ou via profil)
- [ ] Recherche globale (services + demandes + utilisateurs) dans la navbar
- [ ] Historique des points avec graphique (évolution du solde)
- [ ] Export de l'historique des transactions (CSV/PDF)
- [ ] Page FAQ / Aide

---

## 🔵 Long terme

### API REST
- [ ] Préparer routes API (prefix `/api/v1`)
- [ ] Authentification via Sanctum tokens
- [ ] Endpoints : services, requests, transactions, messages, profile
- [ ] Rate limiting par token
- [ ] Documentation API (OpenAPI/Swagger)

### Paiement en complément
- [ ] Intégrer Stripe ou autre PSP
- [ ] Possibilité de payer un complément en argent réel si le service coûte plus de points que le solde
- [ ] Achat de points (packs)
- [ ] Factures automatiques

### Modération automatisée
- [ ] Détection de mots-clés abusifs dans les descriptions/messages
- [ ] Anti-spam : limite de publication par heure
- [ ] Signalement de contenu avec revue automatique
- [ ] Blocage de liens externes suspects

### Multi-langue
- [ ] Installer le package Laravel i18n
- [ ] Traduire toutes les vues (FR, EN minimum)
- [ ] Détection de la langue du navigateur
- [ ] Traduction des emails

---

## 📝 Notes techniques

- Toutes les PK sont des UUIDs (`HasUuids`)
- SQLite en dev / MySQL en prod
- Livewire 3 pour les composants réactifs
- Tailwind CSS v4 pour le styling
- Les controllers utilisent `AuthorizesRequests` (policies)
- Le système de points est append-only (`point_ledger`)
