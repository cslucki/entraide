> **LEGACY COMMUNITY / DO NOT USE AS CURRENT SOURCE**
>
> Historical Community-era transaction matrix. Use for legacy evidence only. Current work must follow Organization = Tenant, Loop != Tenant, Public != Global, and the active specs in `docs/specs/`.

# Community Transaction Matrix

**Document**: Source de vérité métier des transactions entre membres au sein d'une communauté
**Date**: 2026-05-10 - 15h14 
**Projet**: BouclePro.com
**Version**: 2.0 - Complétée

---

## Table des matières

1. [Architecture des Communautés](#architecture-des-communautes)
2. [State Machine: Transaction](#state-machine-transaction)
3. [State Machine: Service](#state-machine-service)
4. [State Machine: ServiceRequest](#state-machine-servicerequest)
5. [Workflow 1: Transaction de Service](#workflow-1-transaction-de-service)
6. [Workflow 2: Transaction de Demande](#workflow-2-transaction-de-demande)
7. [Workflow 3: Messagerie](#workflow-3-messagerie)
8. [Workflow 4: Review/Avis](#workflow-4-reviewavis)
9. [Workflow 5: Favoris](#workflow-5-favoris)
10. [Workflow 6: Signalement](#workflow-6-signalement)
11. [Workflow 7: Blog](#workflow-7-blog)
12. [Matrice des Permissions](#matrice-des-permissions)
13. [Contraintes Multi-Tenant](#contraintes-multi-tenant)
14. [Risques Métier](#risques-metier)
15. [Risques Sécurité](#risques-securite)
16. [Cas Edge / Race Conditions](#cas-edge-race-conditions)
17. [Incohérences et Workflows Incomplets](#incoherences-et-workflows-incomplets)
18. [Protections Manquantes](#protections-manquantes)
19. [Mapping Scénarios QA](#mapping-scenarios-qa)

---

<a id="architecture-des-communautes"></a>

## Architecture des Communautés

### Modèles et Relations

| Modèle | Relations principales | Scope | Statuts possibles |
|--------|----------------------|-------|-------------------|
| **Community** | users, services, serviceRequests, transactions | - | `is_active`, `is_public` |
| **User** | community, services, serviceRequests, buyerTransactions, sellerTransactions, pointLedger, badges | - | `is_available`, `is_admin`, `banned_at` |
| **Service** | community, user, category, skills, tags, transactions, images, favorites, reports | `BelongsToTenantScope` | `active`, `paused`, `deleted` |
| **ServiceRequest** | community, user, category, transactions, attachments | `BelongsToTenantScope` | `open`, `in_progress`, `closed` |
| **Transaction** | community, service, serviceRequest, buyer, seller, messages, pointLedgerEntries, reviews | `BelongsToTenantScope` | `pending`, `accepted`, `buyer_done`, `completed`, `refused`, `cancelled` |
| **Message** | transaction, sender | - | `type: user|system` |
| **Review** | transaction, reviewer, reviewed | - | - |
| **PointLedger** | user, transaction | - | `reason: exchange_spent|exchange_earned|adjustment|welcome_bonus` |
| **BlogPost** | user, community, categories, tags, comments, likes | - | `draft`, `published` |
| **BlogComment** | blogPost, user, parent | - | `is_approved: true|false` |
| **Favorite** | user, service | - | - |
| **Report** | reporter, reportable (Service/ServiceRequest/User) | - | `status: pending|dismissed|reviewed` |
| **Like** | user, likeable (BlogPost) | - | - |

### Middlewares Appliqués

| Middleware | Routes concernées | Contrôle |
|------------|-------------------|----------|
| `web` | Toutes les routes web | Session, CSRF |
| `community` | `/{community}/*` | Résolution community via slug |
| `auth` | Routes protégées | User connecté |
| `verified` | Routes sensibles | Email vérifié |
| `profile.complete` | Services, Requests création | bio, location, phone remplis |
| `admin` | `/admin/*` | `is_admin === true` |
| `EnsureUserIsNotBanned` | - | Déconnexion si `banned_at !== null` |

### Throttling (Rate Limiting)

| Route | Limite | Période |
|-------|--------|---------|
| `POST /transactions` | 10 | 1 minute |
| `POST /transactions/{id}/review` | 5 | 1 minute |
| `POST /favorites/{service}/toggle` | 30 | 1 minute |
| `POST /reports/*` | 5 | 1 minute |

---

<a id="state-machine-transaction"></a>

## State Machine: Transaction

### États et Transitions

```
                    ┌─────────────────────────────────────────────────────────────────┐
                    │                          PENDING                                 │
                    │  Transaction créée, en attente d'acceptation                  │
                    │  ┌─────────────────────────────────────────────────────────┐  │
                    │  │ Notifications: -                                      │  │
                    │  │ Side effects: -                                       │  │
                    │  │ ServiceRequest: → in_progress (si applicable)        │  │
                    │  └─────────────────────────────────────────────────────────┘  │
                    └───────────────────────────┬─────────────────────────────────────┘
                                                │
                    ┌───────────────────────────┼─────────────────────────────────────┐
                    │                           │                                     │
                    │ (approve) par seller      │ (refuse) par seller               │
                    │ Policy: seller_id         │ Policy: seller_id                 │
                    │         + status=pending  │         + status=pending          │
                    ▼                           ▼                                     │
        ┌─────────────────────┐   ┌─────────────────────────────────────────────┐   │
        │      ACCEPTED       │   │              REFUSED                         │   │
        │ Transaction acceptée│   │ Transaction refusée                         │   │
        │ Notifications:      │   │ Notifications: buyer (TransactionStatusChanged)│
        │   buyer (status)    │   │ Side effects: -                             │   │
        │ Side effects: -     │   │ ServiceRequest: → open (si applicable)       │   │
        └──────────┬──────────┘   └─────────────────────────────────────────────┘   │
                   │                                                          ▲
                   │                                                          │
                   │ (cancel) par buyer ou seller                        │
                   │ Policy: buyer_id OR seller_id + status∈{pending,accepted}│
                   ▼                                                          │
        ┌─────────────────────┐                                             │
        │      CANCELLED      │                                             │
        │ Transaction annulée│                                             │
        │ Notifications: -    │                                             │
        │ Side effects: -     │                                             │
        │ ServiceRequest:     │                                             │
        │   → open (si appl.) │                                             │
        └─────────────────────┘                                             │
                   ▲                                                          │
                   │                                                          │
                   │ (complete) par buyer         (adjust) par buyer ou seller │
                   │ Policy: buyer_id             Policy: buyer_id OR seller_id │
                   │         + status=accepted            + status=pending    │
                   │                                 Side effects: Message sys │
                   │                                                          │
                   │                                                          │
                   ▼                                                          │
        ┌─────────────────────────────────────────────────────────────────┐    │
        │                      BUYER_DONE                                 │    │
        │ Acheteur déclare terminé, attente confirmation vendeur           │    │
        │ Notifications: seller (TransactionStatusChanged)                 │    │
        │ Side effects: -                                                 │    │
        └───────────────────────────┬─────────────────────────────────────┘    │
                                    │                                          │
                    ┌───────────────┴───────────────┐                          │
                    │                               │                          │
                    │ (confirm) par seller          │ (contest) par seller     │
                    │ Policy: seller_id             │ Policy: seller_id        │
                    │         + status=buyer_done          + status=buyer_done │
                    ▼                               ▼                          │
        ┌─────────────────────┐   ┌─────────────────────────────────────────────┐
        │     COMPLETED       │   │             BACK TO ACCEPTED                  │
        │ Transaction terminée│   │ Prestation contestée, échange relancé        │
        │ Notifications:      │   │ Notifications: -                            │
        │   buyer (status)    │   │ Side effects: Message système               │
        │   seller (status)   │   │ Note: `contest` met status → accepted       │
        │ Side effects:       │   └─────────────────────────────────────────────┘
        │   PointLedger:      │
        │     buyer: -points  │
        │     seller: +points │
        │   ServiceRequest:   │
        │     → closed        │
        └─────────────────────┘
```

### Transitions Interdites

| De → Vers | Pourquoi |
|-----------|----------|
| pending → completed | Doit passer par accepted → buyer_done |
| pending → buyer_done | Doit être accepté d'abord |
| accepted → completed | Acheteur doit marquer terminé d'abord |
| completed → * | État final, aucune transition possible |
| refused → * | État final |
| cancelled → * | État final |

---

<a id="state-machine-service"></a>

## State Machine: Service

### États et Transitions

```
        ┌─────────────────────────────────────────────────────────────────┐
        │                        ACTIVE                                    │
        │ Service visible, recevable en transactions                      │
        └───────────────────────────┬─────────────────────────────────────┘
                                    │
                                    │ (update: paused)
                                    │ Policy: user_id = current_user
                                    ▼
                        ┌─────────────────────────┐
                        │         PAUSED           │
                        │ Service non visible pub. │
                        │ Propriétaire peut voir  │
                        └───────────┬─────────────┘
                                    │
                                    │ (update: active)
                                    │ Policy: user_id = current_user
                                    ▼
                        ┌─────────────────────────┐
                        │         ACTIVE           │
                        └─────────────────────────┘
                                    │
                                    │ (destroy) via status → deleted
                                    │ Policy: user_id = current_user
                                    │ Contrôle: !hasActiveTransaction()
                                    ▼
                        ┌─────────────────────────┐
                        │        DELETED           │
                        │ Soft deleted via trait   │
                        │ Admin peut restaurer    │
                        └─────────────────────────┘
```

### Contrôles d'Accès

| Action | Policy | Middleware |
|--------|--------|------------|
| `show` | - | Si status ≠ active: seul propriétaire |
| `create` | `profile.complete` | auth + `profile.complete` |
| `update` | `user_id = current_user` | auth |
| `delete` | `user_id = current_user` + `!hasActiveTransaction()` | auth |

---

<a id="state-machine-servicerequest"></a>

## State Machine: ServiceRequest

### États et Transitions

```
        ┌─────────────────────────────────────────────────────────────────┐
        │                          OPEN                                     │
        │ Demande publiée, visible, recevant des propositions              │
        └───────────────────────────┬─────────────────────────────────────┘
                                    │
                                    │ (Transaction créée)
                                    │ via Transaction::store()
                                    ▼
                        ┌─────────────────────────┐
                        │       IN_PROGRESS         │
                        │ Transaction en cours     │
                        └───────────┬─────────────┘
                                    │
                    ┌───────────────┴───────────────┐
                    │                               │
                    │ (Transaction refused/cancelled)│
                    ▼                               │
        ┌─────────────────────────┐                │
        │         OPEN             │                │
        │ Retour à l'état ouvert   │                │
        └─────────────────────────┘                │
                                                    │
                                                    │ (Transaction completed)
                                                    ▼
                                    ┌─────────────────────────┐
                                    │         CLOSED           │
                                    │ Demande clôturée        │
                                    └─────────────────────────┘
```

### Note Importante: Inversion de Rôles

Dans le contexte d'une **ServiceRequest**:
- Le **"vendeur"** de la Transaction est le créateur de la demande (`request.user_id`)
- L'**"acheteur"** de la Transaction est celui qui répond à la demande

C'est l'inverse d'une transaction de service normale!

---

<a id="workflow-1-transaction-de-service"></a>

## Workflow 1: Transaction de Service

### Scénario

User A (acheteur) propose un échange pour le service de User B (vendeur)

### Étapes Détaillées

| # | Action | Route | Controller | Policy | Préconditions | Side Effects | Notifications |
|---|--------|-------|------------|--------|---------------|--------------|----------------|
| 1 | Voir service | `GET /{community}/services/{service}` | `ServiceController::show` | - | - | - | - |
| 2 | Créer transaction | `POST /{community}/transactions` | `TransactionController::store` | - | auth + `profile.complete`<br>throttle: 10/min | Transaction: `status: pending`<br>Message système | - |
| 3 | Accepter | `PATCH /{community}/transactions/{id}/approve` | `TransactionController::approve` | `TransactionPolicy::approve` | seller + status=pending | Transaction: `status: accepted`<br>`points_agreed = points_proposed`<br>Message système | buyer: `TransactionStatusChanged` |
| 4 | Ajuster points | `PATCH /{community}/transactions/{id}/adjust` | `TransactionController::adjust` | `TransactionPolicy::adjust` | buyer/seller + status=pending<br>throttle: 10/min | `points_proposed` mis à jour<br>Message système | - |
| 5 | Annuler | `PATCH /{community}/transactions/{id}/cancel` | `TransactionController::cancel` | `TransactionPolicy::cancel` | buyer/seller + status∈{pending,accepted} | Transaction: `status: cancelled`<br>Message système | - |
| 6 | Refuser | `PATCH /{community}/transactions/{id}/refuse` | `TransactionController::refuse` | `TransactionPolicy::refuse` | seller + status=pending | Transaction: `status: refused`<br>Message système | buyer: `TransactionStatusChanged` |
| 7 | Marquer terminé | `PATCH /{community}/transactions/{id}/complete` | `TransactionController::complete` | `TransactionPolicy::complete` | buyer + status=accepted | Transaction: `status: buyer_done`<br>`buyer_confirmed_at = now`<br>Message système | seller: `TransactionStatusChanged` |
| 8 | Confirmer | `PATCH /{community}/transactions/{id}/confirm` | `TransactionController::confirm` | `TransactionPolicy::confirm` | seller + status=buyer_done | **DB Transaction**:<br>• PointLedger: buyer: -points<br>• PointLedger: seller: +points<br>• buyer: `points_balance -= points`<br>• seller: `points_balance += points`<br>• Transaction: `status: completed`<br>• `seller_confirmed_at = now`<br>• `completed_at = now`<br>• ServiceRequest: `status: closed`<br>Message système | buyer: `TransactionStatusChanged`<br>seller: `TransactionStatusChanged` |
| 9 | Contester | `PATCH /{community}/transactions/{id}/contest` | `TransactionController::contest` | `TransactionPolicy::contest` | seller + status=buyer_done | Transaction: `status: accepted`<br>Message système | - |

### Validation `TransactionController::store`

```php
// 1. Détermination buyer/seller/service
service_id → buyer = auth(), seller = service.user
request_id → buyer = service_request.user, seller = auth()

// 2. Validation
- buyer.id !== seller.id (auto-transaction)
- buyer.points_balance >= points_proposed
- !exists(pending/accepted transaction pour même service/request)

// 3. Création
Transaction::create([
    'status' => 'pending',
    'community_id' => from service/request,
    'service_id' / 'request_id',
    'buyer_id', 'seller_id',
    'points_proposed',
])
Message::create([..., 'type' => 'system'])
if request_id → ServiceRequest::status = 'in_progress'
```

---

<a id="workflow-2-transaction-de-demande"></a>

## Workflow 2: Transaction de Demande

### Scénario

User A crée une demande, User B y répond par une proposition d'échange

### Étapes Détaillées

| # | Action | Route | Controller | Préconditions | Side Effects |
|---|--------|-------|------------|---------------|--------------|
| 1 | Créer demande | `POST /{community}/requests` | `RequestController::store` | auth + `profile.complete`<br>throttle: 10/min | ServiceRequest: `status: open`<br>Attachments stockés |
| 2 | Voir demande | `GET /{community}/requests/{request}` | `RequestController::show` | - | - |
| 3 | Proposer échange | `POST /{community}/transactions` | `TransactionController::store` | auth + `profile.complete`<br>throttle: 10/min | Transaction: `status: pending`<br>**Attention**: buyer = request.user, seller = auth()<br>ServiceRequest: `status: in_progress` |
| 4 | Accepter | `PATCH /{community}/transactions/{id}/approve` | `TransactionController::approve` | seller = request.user + status=pending | Transaction: `status: accepted` |
| 5-9 | Suite identique au Workflow Service | ... | ... | ... | ... |

### Inversion de Rôles

| Normale (Service) | Inversée (Request) |
|-------------------|-------------------|
| Acheteur = celui qui veut le service | Acheteur = créateur de la demande |
| Vendeur = propriétaire du service | Vendeur = celui qui répond à la demande |

---

<a id="workflow-3-messagerie"></a>

## Workflow 3: Messagerie

### Scénario

Communication entre participants d'une transaction

### Étapes Détaillées

| # | Action | Route | Controller | Policy | Préconditions | Side Effects | Notifications |
|---|--------|-------|------------|--------|---------------|--------------|----------------|
| 1 | Liste conversations | `GET /{community}/messages` | `MessageController::index` | - | auth | Charge transactions du user + unread counts | - |
| 2 | Voir thread | `GET /{community}/messages/{transaction}` | `MessageController::show` | `MessagePolicy::view` | user ∈ {buyer, seller} | Messages chargés + marqués comme lus | - |
| 3 | Envoyer message | `POST /{community}/transactions/{id}/messages` | `MessageThread::sendMessage` (Livewire) | `MessagePolicy::store` | user ∈ {buyer, seller}<br>status ∉ {completed, refused, cancelled} | Message: `type: user`<br>transaction.updated_at | destinataire: `NewMessageReceived` |
| 4 | Marquer lus | Auto dans `MessageThread::mount/markRead` | - | - | - | Messages du destinataire: `read_at = now()` | - |

### `MessageThread::sendMessage` Validation

```php
if (!in_array(user.id, [buyer_id, seller_id])) return; // Pas participant
if (in_array(status, ['completed', 'refused', 'cancelled'])) return; // Transaction terminée

Message::create([
    'transaction_id', 'sender_id', 'body', 'type' => 'user'
])
recipient.notify(new NewMessageReceived(transaction, message))
```

### `MessagePolicy`

| Action | Condition |
|--------|-----------|
| `view` | `user.id === buyer_id OR user.id === seller_id` |
| `store` | (participant) AND `status NOT IN {completed, refused, cancelled}` |

---

<a id="workflow-4-reviewavis"></a>

## Workflow 4: Review/Avis

### Scénario

Évaluation mutuelle après transaction complétée

### Étapes Détaillées

| # | Action | Route | Controller | Policy | Préconditions | Side Effects |
|---|--------|-------|------------|--------|---------------|--------------|
| 1 | Soumettre avis | `POST /{community}/transactions/{id}/review` | `ReviewController::store` | `ReviewPolicy::create` | user ∈ {buyer, seller}<br>status = completed<br>!hasReviewFrom(user)<br>throttle: 5/min | Review: rating, comment<br>reviewed.recalculateRating() |

### `ReviewPolicy::create`

```php
isParticipant = user.id === buyer_id OR user.id === seller_id
return isParticipant
    AND transaction.status === 'completed'
    AND !transaction.hasReviewFrom(user.id)
```

### `ReviewController::store`

```php
reviewed_id = (user.id === buyer_id) ? seller_id : buyer_id

Review::create([transaction_id, reviewer_id, reviewed_id, rating, comment])

// Recalcul rating du noté
avg = reviewed.reviewsReceived()->avg('rating')
reviewed.rating = round(avg, 2)
```

---

<a id="workflow-5-favoris"></a>

## Workflow 5: Favoris

### Scénario

User A ajoute le service de User B en favoris

### Étapes Détaillées

| # | Action | Route | Controller | Préconditions | Side Effects |
|---|--------|-------|------------|---------------|--------------|
| 1 | Toggle favori | `POST /{community}/favorites/{service}/toggle` | `FavoriteController::toggle` | auth<br>throttle: 30/min | Favorite: create OR delete<br>JSON: `{favorited, count}` |
| 2 | Voir favoris | `GET /{community}/favorites` | `FavoriteController::index` | auth | Liste paginée |

### Pas de contrôle empêchant l'auto-favori

---

<a id="workflow-6-signalement"></a>

## Workflow 6: Signalement

### Scénario

User A signale le contenu de User B

### Étapes Détaillées

| Type | Route | Policy | Validation | Side Effects |
|------|-------|--------|------------|--------------|
| Signaler service | `POST /{community}/reports/service/{service}` | - | `reporter_id !== service.user_id`<br>throttle: 5/min | Report: `firstOrCreate` (pas de doublon) |
| Signaler demande | `POST /{community}/reports/request/{request}` | - | `reporter_id !== request.user_id`<br>throttle: 5/min | Report: `firstOrCreate` |
| Signaler user | `POST /{community}/reports/user/{user}` | - | `reporter_id !== user.id`<br>throttle: 5/min | Report: `firstOrCreate` |

### Gestion Admin

| Route | Action | Side Effects |
|-------|--------|--------------|
| `PATCH /admin/reports/{id}/dismiss` | `Report::status = 'dismissed'` | - |
| `PATCH /admin/reports/{id}/review` | `Report::status = 'reviewed'` | - |

---

<a id="workflow-7-blog"></a>

## Workflow 7: Blog

### Scénario

Articles communautaires, likes, commentaires

### Étapes Détaillées

| # | Action | Route | Controller | Policy | Préconditions | Side Effects |
|---|--------|-------|------------|--------|---------------|--------------|
| 1 | Créer article | `POST /{community}/blog` | `BlogController::store` | `BlogPostPolicy::create` | auth + `profile.complete`<br>!banned | BlogPost: `status: draft` |
| 2 | Publier article | `PATCH /{community}/blog/{slug}/publier` | `BlogController::publish` | `BlogPostPolicy::update` | user_id OR is_admin | BlogPost: `status: published`<br>`published_at = now()` |
| 3 | Lire article | `GET /{community}/blog/{slug}` | `BlogController::show` | - | - | - |
| 4 | Liker article | `POST /likes/toggle` | `LikeController::toggle` | - | auth | Like: create OR delete |
| 5 | Commenter | `POST /{community}/blog/{slug}/commentaires` | `BlogCommentController::store` | - | auth | BlogComment: `is_approved: true` |
| 6 | Supprimer commentaire | `DELETE /commentaires/{comment}` | `BlogCommentController::destroy` | user_id OR is_admin | - | - |

### `BlogPostPolicy`

| Action | Condition |
|--------|-----------|
| `create` | `!user.banned_at` |
| `update` | `user.id === post.user_id OR user.is_admin` |
| `delete` | `user.id === post.user_id OR user.is_admin` |

---

<a id="matrice-des-permissions"></a>

## Matrice des Permissions

### Transaction Policy

| Action | Qui peut | Condition |
|--------|----------|-----------|
| `view` | Buyer OU Seller | `user.id ∈ {buyer_id, seller_id}` |
| `approve` | Seller uniquement | `user.id === seller_id AND status === 'pending'` |
| `refuse` | Seller uniquement | `user.id === seller_id AND status === 'pending'` |
| `adjust` | Buyer OU Seller | `user.id ∈ {buyer_id, seller_id} AND status === 'pending'` |
| `cancel` | Buyer OU Seller | `user.id ∈ {buyer_id, seller_id} AND status IN {pending, accepted}` |
| `complete` | Buyer uniquement | `user.id === buyer_id AND status === 'accepted'` |
| `confirm` | Seller uniquement | `user.id === seller_id AND status === 'buyer_done'` |
| `contest` | Seller uniquement | `user.id === seller_id AND status === 'buyer_done'` |

### Message Policy

| Action | Qui peut | Condition |
|--------|----------|-----------|
| `view` | Buyer OU Seller | `user.id ∈ {buyer_id, seller_id}` |
| `store` | Buyer OU Seller | `user.id ∈ {buyer_id, seller_id} AND status NOT IN {completed, refused, cancelled}` |

### Service Policy

| Action | Qui peut | Condition |
|--------|----------|-----------|
| `update` | Propriétaire | `user.id === service.user_id` |
| `delete` | Propriétaire | `user.id === service.user_id` |

### Review Policy

| Action | Qui peut | Condition |
|--------|----------|-----------|
| `create` | Buyer OU Seller | `user.id ∈ {buyer_id, seller_id} AND status === 'completed' AND !hasReviewFrom(user.id)` |

### ServiceRequest Policy

| Action | Qui peut | Condition |
|--------|----------|-----------|
| `delete` | Propriétaire | `user.id === request.user_id` |

### BlogPost Policy

| Action | Qui peut | Condition |
|--------|----------|-----------|
| `create` | User non banni | `!user.banned_at` |
| `update` | Auteur OU Admin | `user.id === post.user_id OR user.is_admin` |
| `delete` | Auteur OU Admin | `user.id === post.user_id OR user.is_admin` |

---

<a id="contraintes-multi-tenant"></a>

## Contraintes Multi-Tenant

### Isolation par Community

1. **Scope Global**: `BelongsToTenantScope` appliqué automatiquement sur:
   - `Service`
   - `ServiceRequest`
   - `Transaction`

2. **User → Community**: Chaque User appartient à **une seule** Community (`community_id`)

3. **Résolution Community**: Middleware `ResolveCommunity`
   - Résout community via slug
   - Stocke dans `app('current_community')`
   - 404 si community non trouvée ou `!is_active`
   - Redirection vers login si `!is_public` et non authentifié

4. **Routes Scoped**: Toutes les routes fonctionnelles sont sous `/{community}/`

### Risques d'Isolation

| Risque | État | Atténuation |
|--------|------|-------------|
| Cross-community service access | ⚠️ Partiel | Scope sur Service mais routes globales existent aussi |
| User dans multiple communities | ✅ Empêché | `community_id` est nullable mais pas de relation many-to-many |
| Transaction cross-community | ✅ Empêché | `community_id` sur Transaction + scope |

---

<a id="risques-metier"></a>

## Risques Métier

### 1. Double-dépense de Points

**Scénario**: Confirm est appelé deux fois simultanément

**État**: ⚠️ **Risque modéré**

**Attaque en cours**:
```php
DB::transaction(function () use ($transaction) {
    PointLedger::create([..., 'delta' => -$points]); // Buyer
    PointLedger::create([..., 'delta' => +$points]); // Seller
    $transaction->buyer()->update(['points_balance' => DB::raw('points_balance - ' . $points)]);
    $transaction->seller()->update(['points_balance' => DB::raw('points_balance + ' . $points)]);
});
```

**Protection**: DB transaction + DB::raw() atomic update

**Résiduel**: Si deux confirmations rapides, la deuxième échouerait sur le status (déjà completed)

### 2. Points Négatifs

**Scénario**: Ajustement admin négatif + dépenses

**État**: ⚠️ **Possible**

**Vérification dans store**: `buyer.points_balance >= points_proposed`

**Pas de vérification**: Sur `AdminController::adjustPoints`

### 3. Transaction avec Service Supprimé

**Scénario**: Service soft-deleted pendant transaction active

**État**: ⚠️ **Possible**

**Contrôle**: ServiceController::destroy empêche si `hasActiveTransaction()`

**Manque**: Aucun contrôle lors de l'acceptation/confirmation

### 4. Message après Transaction Terminée

**Scénario**: Envoyer message sur transaction completed

**État**: ✅ **Protégé**

**Contrôle**: `MessagePolicy::store` + `MessageThread::sendMessage`

### 5. Review Sans Transaction Complétée

**Scénario**: Soumettre avis sur transaction non complétée

**État**: ✅ **Protégé**

**Contrôle**: `ReviewPolicy::create` vérifie `status === 'completed'`

### 6. Self-Transaction

**Scénario**: Créer transaction avec soi-même

**État**: ✅ **Protégé**

**Contrôle**: `TransactionController::store` vérifie `buyer.id !== seller.id`

### 7. Transaction Doublon

**Scénario**: Deux transactions pour le même service

**État**: ✅ **Protégé**

**Contrôle**: `TransactionController::store` vérifie `!exists(pending/accepted)` pour même service/request

### 8. Points Non Transférés en Cas d'Annulation

**Scénario**: Transaction annulée après que des points ont été débités

**État**: ✅ **Correct**

**Comportement**: Points transférés SEULEMENT lors de `confirm`. Annulation ne touche pas aux points.

---

<a id="risques-securite"></a>

## Risques Sécurité

### 1. IDOR (Insecure Direct Object Reference)

**Risque**: Accéder à une transaction d'un autre utilisateur

**État**: ✅ **Protégé**

| Route | Policy |
|-------|--------|
| `GET /messages/{transaction}` | `MessagePolicy::view` |
| `PATCH /transactions/{id}/*` | `TransactionPolicy::*` |

### 2. Cross-Community Access

**Risque**: Accéder aux données d'une autre communauté

**État**: ⚠️ **Partiel**

**Protégé**:
- Services, Requests, Transactions ont `BelongsToTenantScope`

**À vérifier**:
- Routes globales: `/services/{service}`, `/requests/{request}`
- Comment ces routes sont-elles scopées ?

### 3. Banned User Actions

**Risque**: Utilisateur banni continue d'agir

**État**: ✅ **Protégé**

**Middleware**: `EnsureUserIsNotBanned` - déconnecte automatiquement

### 4. Admin Privilege Escalation

**Risque**: Utilisateur s'octroie des droits admin

**État**: ✅ **Protégé**

**Contrôle**: `AdminController::toggleUserAdmin` empêche l'auto-modification

### 5. Report Spam

**Risque**: Spam de signalements

**État**: ✅ **Protégé**

**Throttling**: 5/minute + `firstOrCreate` (pas de doublons)

### 6. Mass Point Creation

**Risque**: Créer des points illégitimes

**État**: ⚠️ **Possible via Admin**

**Contrôle**: Aucune vérification sur `AdminController::adjustPoints` ou `createUser`

---

<a id="cas-edge-race-conditions"></a>

## Cas Edge / Race Conditions

### 1. Accept + Adjust Simultanés

**Scénario**: Seller accepte pendant que buyer ajuste

**État**: ⚠️ **Race condition**

**Résultat**: Le dernier write gagne

**Impact**: Points ajustés après acceptation - pas de re-vérification

### 2. Confirm + Contest Simultanés

**Scénario**: Seller confirme pendant qu'il conteste

**État**: ⚠️ **Race condition**

**Résultat**: Le dernier write gagne

**Impact**: Si contest gagne après confirm, les points sont transférés mais la transaction revient à accepted

### 3. Complete + Cancel Simultanés

**Scénario**: Buyer marque terminé pendant qu'une annulation est en cours

**État**: ⚠️ **Race condition**

**Résultat**: Le dernier write gagne

**Impact**: Si cancel gagne après complete, l'acheteur perd la possibilité de confirmer

### 4. Message après Status Change

**Scénario**: Message envoyé pendant transition vers completed

**État**: ✅ **Géré**

**Comportement**: `MessagePolicy::store` bloque si status IN {completed, refused, cancelled}

### 5. Review Après Status Revert

**Scénario**: Review soumis, puis transaction contestée (revert à accepted)

**État**: ⚠️ **Possible**

**Impact**: Review existe mais transaction n'est plus completed

**Pas de vérification**: Aucun contrôle empêchant cela

---

<a id="incoherences-et-workflows-incomplets"></a>

## Incohérences et Workflows Incomplets

### 1. Welcome Points Non Attribués

**Observation**: `Community.welcome_points` existe mais pas de logique d'attribution

**Impact**: Nouveaux membres ne reçoivent pas de points de bienvenue

**État**: 🔴 **Incohérence**

### 2. Budget de Request Non Utilisé

**Observation**: `ServiceRequest.budget_min/max` est stocké mais pas utilisé dans `TransactionController::store`

**Impact**: Le prix proposé n'est pas validé contre le budget de la demande

**État**: 🟡 **Incohérence partielle**

### 3. Community Admin vs Global Admin

**Observation**:
- `Community.admin_id` existe
- `BlogPostPolicy` vérifie `user.is_admin` (global)

**Impact**: Un admin de communauté ne peut pas modérer les articles de sa communauté via la policy

**État**: 🟡 **Incohérence**

**Note**: `AdminController::authorizeServiceEdit` gère ce cas pour les services

### 4. Routes Globales vs Scoped

**Observation**: Les routes `/services/{service}` et `/requests/{request}` existent hors du préfixe `/{community}/`

**Questions**:
- Comment ces routes sont-elles scopées en communauté ?
- Sont-elles une redondance ?

**État**: 🟡 **À clarifier**

### 5. Dashboard Community vs Global

**Observation**: `GET /{community}/dashboard` existe mais contenu à vérifier

**État**: 🟡 **À documenter**

### 6. ServiceRequest Status après Cancel

**Observation**: Lors d'un `cancel`, la request passe à `open` mais lors d'un `refuse`, aussi

**Impact**: Comportement cohérent mais pas documenté

**État**: 🟢 **OK**

### 7. Review Permanence

**Observation**: Un review ne peut pas être modifié ou supprimé

**Impact**: Pas de possibilité de corriger une erreur

**État**: 🟡 **Manque**

---

<a id="protections-manquantes"></a>

## Protections Manquantes

### 1. Max Transactions par User

**État**: ❌ **Manquant**

**Impact**: Un utilisateur pourrait spammer de propositions de transaction

**Recommandation**: Ajouter une limite quotidienne/mensuelle

### 2. Review Time Window

**État**: ❌ **Manquant**

**Impact**: Un review peut être ajouté des mois après la transaction

**Recommandation**: Limiter à X jours après `completed_at`

### 3. Points Balance Check on Admin Adjust

**État**: ❌ **Manquant**

**Impact**: Un admin peut rendre un solde négatif

**Recommandation**: Vérifier `points_balance + delta >= 0` pour les ajustements négatifs

### 4. Service Delete Protection on Active Transactions

**État**: ✅ **Présent**

**Note**: `ServiceController::destroy` vérifie `!hasActiveTransaction()`

### 5. Message Content Moderation

**État**: ❌ **Manquant**

**Impact**: Pas de modération automatique des messages

**Recommandation**: Ajouter filtre de mots interdits ou système de signalement

### 6. Transaction Re-opening Protection

**État**: ⚠️ **Partiel**

**Observation**: Après `contest`, la transaction revient à `accepted`

**Impact**: Possibilité d'abuser du mécanisme de contestation

### 7. Rating Display without Reviews

**État**: ❌ **Manquant**

**Observation**: `User::recalculateRating()` retourne `null` si 0 reviews

**Impact**: Affichage potentiellement cassé si pas de gestion du null

---

<a id="mapping-scenarios-qa"></a>

## Mapping Scénarios QA

### Scénarios Prioritaires (Happy Paths)

| ID | Scénario | Priorité | Complexité | Couverture |
|----|----------|----------|------------|------------|
| QA-01 | Transaction service complète (pending → accepted → buyer_done → completed) | P0 | Moyenne | ✅ À créer |
| QA-02 | Transaction demande complète (open → in_progress → accepted → ...) | P0 | Moyenne | ✅ À créer |
| QA-03 | Échange de messages entre participants | P0 | Faible | ✅ À créer |
| QA-04 | Avis mutuels post-transaction | P0 | Faible | ✅ À créer |
| QA-05 | Ajustement de points (pending) | P1 | Faible | ✅ À créer |
| QA-06 | Annulation par buyer (pending/accepted) | P1 | Faible | ✅ À créer |
| QA-07 | Refus par seller (pending) | P1 | Faible | ✅ À créer |
| QA-08 | Contestation par seller (buyer_done) | P1 | Faible | ✅ À créer |
| QA-09 | Toggle favori | P2 | Très faible | ✅ À créer |
| QA-10 | Signalement de service/request/user | P2 | Faible | ✅ À créer |
| QA-11 | Création et publication d'article blog | P2 | Moyenne | ✅ À créer |
| QA-12 | Like et commentaire sur article | P2 | Faible | ✅ À créer |

### Scénarios Négatifs (Edge Cases)

| ID | Scénario | Priorité | Attendu |
|----|----------|----------|---------|
| QA-N01 | Auto-transaction | P0 | Erreur "vous-même" |
| QA-N02 | Solde insuffisant | P0 | Erreur "solde insuffisant" |
| QA-N03 | Transaction doublon | P0 | Erreur "déjà en cours" |
| QA-N04 | Accepter sa propre transaction | P1 | Erreur 403 |
| QA-N05 | Marquer terminé en tant que seller | P1 | Erreur 403 |
| QA-N06 | Confirmer en tant que buyer | P1 | Erreur 403 |
| QA-N07 | Avis sur transaction non complétée | P1 | Erreur 403 |
| QA-N08 | Message sur transaction terminée | P1 | Message non envoyé |
| QA-N09 | Avis déjà laissé | P1 | Erreur 403 |
| QA-N10 | Self-signalement | P2 | Erreur "pas vous-même" |
| QA-N11 | Signalement doublon | P2 | Pas de duplication |
| QA-N12 | Supprimer service avec transaction active | P1 | Erreur "transactions en cours" |
| QA-N13 | Accéder à message d'autre utilisateur | P0 | Erreur 403 |
| QA-N14 | Action en tant qu'utilisateur banni | P1 | Déconnexion automatique |
| QA-N15 | Ajustement hors état pending | P1 | Erreur "impossible" |

### Scénarios de Race Conditions

| ID | Scénario | Priorité | Atténuation |
|----|----------|----------|-------------|
| QA-R01 | Confirm + Contest simultanés | P1 | Dernier write gagne |
| QA-R02 | Accept + Adjust simultanés | P1 | Dernier write gagne |
| QA-R03 | Double confirm (throttling) | P0 | Status déjà completed |
| QA-R04 | Message pendant status change | P1 | Bloqué par policy |

### Scénarios Multi-Tenant

| ID | Scénario | Priorité | Attendu |
|----|----------|----------|---------|
| QA-MT01 | Voir services d'une autre communauté | P0 | Non visible (scope) |
| QA-MT02 | Accéder à transaction d'une autre communauté | P0 | Erreur 404/403 |
| QA-MT03 | Créer service dans une autre communauté | P0 | Impossible (user.community_id) |
| QA-MT04 | User sans communauté | P2 | Comportement à définir |

### Scénarios Admin

| ID | Scénario | Priorité | Attendu |
|----|----------|----------|---------|
| QA-A01 | Ajustement de points utilisateur | P1 | Solde modifié + PointLedger |
| QA-A02 | Bannissement utilisateur | P1 | Utilisateur déconnecté |
| QA-A03 | Affectation communauté | P1 | User.community_id modifié |
| QA-A04 | Clôture de signalement | P2 | Status modifié |

---

---

<a id="audit-de-couverture"></a>

## Audit de Couverture - Workflows Manquants

Domaines audités:
- ✅ Modèles Eloquent (22 modèles identifiés)
- ✅ Routes web (80+ routes)
- ✅ Controllers (15+ contrôleurs)
- ✅ Policies (6 politiques)
- ✅ Notifications (3 types)
- ✅ Livewire (2 composants)
- ✅ Vues Blade (40+ fichiers)

### Workflows Manquants (Non Implémentés)

| Domaine | Modèles concernés | Routes concernées | État d'implémentation | Impact UX | Impact QA | Impact Sécurité |
|----------|------------------|-------------------|------------------------|------------|------------|------------------|
| **Parrainage / Referral** | Aucun modèle | Aucune route | ❌ AUCUN | ❌ Pas de mécanisme d'acquisition | N/A | N/A |
| **Invitations** | Aucun modèle | Aucune route | ❌ AUCUN | ❌ Impossibilité d'inviter des amis | N/A | N/A |
| **Contacts** | Aucun modèle | Aucune route | ❌ AUCUN | ⚠️ Pas de gestion de contacts | N/A | N/A |
| **Événements** | Aucun modèle | Aucune route | ❌ AUCUN | N/A | N/A | N/A |
| **Groupes internes** | Aucun modèle | Aucune route | ❌ AUCUN | N/A | N/A | N/A |
| **IA BouclePro** | Aucun modèle | Aucune route | ❌ AUCUN | N/A | N/A | N/A |

### Workflows Partiellement Implémentés

| Domaine | Modèles concernés | Implémentation | Manque |
|----------|------------------|-----------------|---------|
| **Welcome Points** | `Community.welcome_points`, `PointLedger` | ✅ 100 pts hardcodés dans `RegisteredUserController` | ❌ N'utilise pas `Community.welcome_points` |
| **Badges** | `Badge`, `BadgeUser`, `BadgeService` | ⚠️ 5 badges codés en dur | ❌ Pas d'UI admin pour créer/éditer des badges |
| **Community Request** | `CommunityRequest` | ⚠️ Création uniquement | ❌ Pas de workflow de traitement/approval |
| **Notifications DB** | `Notification` (Laravel) | ⚠️ Seulement email | ❌ Pas de persistance en base de données |
| **Profile Visibility** | `User.show_email`, `User.show_phone` | ✅ Champs existent | ⚠️ Utilisation UI non documentée |
| **Community Admin** | `Community.admin_id` | ⚠️ Identification uniquement | ❌ Aucun workflow d'administration de communauté |

---

<a id="ux-surface-mapping"></a>

## UX Surface Mapping

### Mapping Écran → Workflows

| Écran | Composants visibles | Workflows associés | Transitions d'état | Notifications | Actions utilisateur | Liens critiques |
|--------|---------------------|-------------------|---------------------|----------------|--------------------|------------------|
| **Home (/)** | Stats globales, Featured services, CTA boucles | Exploration, Navigation | - | - | Voir services, Rejoindre | explorer, boucles.index, register |
| **Explorer (/explorer)** | Filtres catégories, Liste services/requests, Tab toggle | Exploration, Filtrage | - | - | Filtrer, Voir service, Proposer échange | services.show, requests.show, transactions.store |
| **Dashboard (/dashboard)** | Solde, Metrics, Mes services, Mes demandes, Échanges en cours, Messages récents | Accès rapide, Gestion personnelle | - | - | Créer service/request, Voir échanges, Modifier service | services.create, requests.create, messages.show, services.edit |
| **Messages (/messages)** | Liste conversations, Compteurs non-lus | Communication transactionnelle | - | NewMessageReceived | Voir thread, Écrire message | messages.show |
| **Message Thread** | Historique messages, Formulaire envoi | Communication transactionnelle | pending ↔ accepted ↔ buyer_done | NewMessageReceived | Envoyer message | - |
| **Profil Public (/profile/{user})** | Avatar, Bio, Services, Demandes, Reviews, Badges | Réputation, Découverte | - | - | Voir services, Contacter (via transaction) | services.show, requests.show |
| **Profil Édition (/profile/edit)** | Formulaire complet, Avatar upload | Gestion profil | - | - | Modifier profil, Supprimer compte | - |
| **Service Show (/services/{id})** | Détails service, Infos vendeur, Bouton proposer | Proposition transaction | → pending | - | Proposer échange, Favori, Signaler | transactions.store, favorites.toggle, reports.service |
| **Service Create/Edit** | Formulaire complet, Catégories, Skills, Tags | Gestion services | ↔ active ↔ paused | - | Créer/Modifier service | - |
| **Request Show (/requests/{id})** | Détails demande, Infos créateur, Bouton répondre | Réponse demande | → in_progress | - | Proposer échange, Signaler | transactions.store, reports.request |
| **Blog (/blog)** | Liste articles, Filtres catégorie/tag | Consultation contenu | - | - | Lire article, Liker, Commenter | blog.show, likes.toggle, blog.comment.store |
| **Members (/membres)** | Liste membres, Filtres services/requests | Découverte membres | - | - | Voir profil, Voir services | profile.show, services.show |
| **Exchanges (/echanges)** | Liste échanges complétés | Découverte activité | - | - | Voir participants | profile.show |
| **Boucles (/boucles)** | Liste communautés, Formulaire demande | Création communauté | - | - | Voir communauté, Créer boucle | community.home, boucles.request.create |
| **Admin Dashboard** | Stats globales, Utilisateurs récents, Rapports | Administration | - | - | Gérer users, services, transactions | admin.users, admin.services, admin.transactions |

### Incohérences UX Identifiées

| Écran | Problème | Impact |
|--------|-----------|---------|
| **Dashboard → Service** | Pas de lien direct vers le service depuis "Mes services" | Navigation indirecte |
| **Dashboard → Request** | Pas de lien direct vers la demande depuis "Mes demandes" | Navigation indirecte |
| **Profil Public** | Avatar sans lien vers le profil complet | UX confusion |
| **Messages Index** | Pas de filtre par statut de transaction | Difficulté de gestion |
| **Service Show** | Pas de lien vers le profil du vendeur | Découverte limitée |
| **Explorer** | Pas de tri par "pertinence" | UX basique |
| **Blog** | Pas de pagination visible sur la liste | Navigation limitée |
| **Admin → Users** | Pas de recherche multi-critères | Usabilité réduite |

### Actions UX Non Retournées

| Action | État attendu | État actuel |
|---------|----------------|---------------|
| Suppression service avec transactions actives | Erreur bloquante | ✅ Protégé |
| Création transaction avec solde insuffisant | Erreur bloquante | ✅ Protégé |
| Avis sur transaction non complétée | Erreur 403 | ✅ Protégé |
| Message sur transaction terminée | Message non envoyé | ✅ Bloqué |
| Auto-favori | ? | ⚠️ Non contrôlé |
| Auto-signalement | Erreur "pas vous-même" | ✅ Protégé |
| Modification review | ❌ Interdit | ✅ Non implémenté (bloqué) |

---

<a id="domain-coverage"></a>

## Domain Coverage

| Domaine | Coverage | Niveau de confiance | Notes |
|----------|-----------|-------------------|-------|
| **Transactions** | HIGH | ✅ | State machine complète, 6 états, transitions documentées |
| **Services** | HIGH | ✅ | CRUD complet, state machine (active/paused/deleted) |
| **ServiceRequests** | HIGH | ✅ | CRUD complet, state machine (open/in_progress/closed) |
| **Messagerie** | HIGH | ✅ | Live component, notifications email, lecture auto |
| **Reviews** | MEDIUM | ⚠️ | Création OK, mais pas de modification/suppression |
| **Favoris** | MEDIUM | ✅ | Toggle fonctionnel, liste accessible |
| **Signalements** | MEDIUM | ✅ | CRUD complet, workflow admin traitement |
| **Blog** | MEDIUM | ✅ | CRUD complet, likes, commentaires, admin moderation |
| **Badges** | LOW | ⚠️ | Attribution auto, mais pas d'UI admin |
| **Welcome Points** | LOW | ⚠️ | Implémenté mais hardcodé (100 pts), pas configurable par communauté |
| **Parrainage/Referral** | NONE | ❌ | Aucun modèle, aucune route |
| **Invitations** | NONE | ❌ | Aucun modèle, aucune route |
| **Contacts** | NONE | ❌ | Aucun modèle, aucune route |
| **Événements** | NONE | ❌ | Aucun modèle, aucune route |
| **Groupes internes** | NONE | ❌ | Communautés = seule structure de groupe |
| **IA BouclePro** | NONE | ❌ | Aucune fonctionnalité IA détectée |
| **Notifications DB** | LOW | ⚠️ | Seulement email, pas de centre de notifications |
| **Community Admin** | LOW | ⚠️ | `admin_id` existe mais workflow inexistant |
| **Profils privés** | LOW | ⚠️ | `show_email/show_phone` mais workflow non documenté |

### Summary Coverage

- **Domaines complets (HIGH)**: 4/20
- **Domaines partiels (MEDIUM)**: 5/20
- **Domaines absents (NONE)**: 7/20
- **Domaines faibles (LOW)**: 4/20

**Couverture globale**: 60% (12/20 domaines significatifs)

---

<a id="nouvelles-incoherences-metier"></a>

## Nouvelles Incohérences Métier Découvertes (Post-Audit)

| # | Observation | Impact | État |
|---|-------------|---------|-------|
| 8 | **Welcome points hardcodés** | `RegisteredUserController` attribue 100 pts en dur au lieu d'utiliser `Community.welcome_points` | 🔴 |
| 9 | **Badges sans UI admin** | `BadgeService` attribue automatiquement mais pas d'interface pour définir de nouveaux badges | 🟡 |
| 10 | **Community admin role** | `Community.admin_id` existe mais aucun workflow d'administration spécifique | 🟡 |
| 11 | **Profile visibility** | `show_email`/`show_phone` stockés mais pas de champ de toggling dans le formulaire de profil | 🟡 |
| 12 | **Community Request workflow** | `CommunityRequest` créé mais pas de table de traitement/approval | 🟡 |
| 13 | **Routes globales non scopées** | `/services/{service}` et `/requests/{request}` hors `/{community}/` sans vérification de scope | 🟡 |
| 14 | **PointLedger reason enumeration** | `reason` stocké comme string, pas d'enum ou validation stricte | 🟡 |

---

<a id="nouvelles-protections-manquantes"></a>

## Nouvelles Protections Manquantes (Post-Audit)

| # | Protection | État | Recommandation |
|---|-------------|--------|----------------|
| 8 | **Auto-favori control** | ❌ | Empêcher un user de favoriser son propre service |
| 9 | **Welcome points max** | ❌ | Limiter les welcome points pour éviter abus multi-comptes |
| 10 | **Profile completion timeout** | ❌ | Exiger complétion profil sous X jours sinon désactivation |
| 11 | **Inactive account cleanup** | ❌ | Désactiver les comptes inactifs depuis > X jours |
| 12 | **Mass export protection** | ❌ | Limiter l'export CSV par utilisateur |
| 13 | **Message content length** | ⚠️ | Limité à 5000 mais pas de rate limit sur messages |
| 14 | **Badge expiration** | ❌ | Pas de mécanisme d'expiration ou révocation de badges |

---

<a id="routes-globales-vs-scoped"></a>

## Routes Globales vs Scoped - Analyse

### Routes Doubles (Global + Community)

| Route globale | Route communautaire | Différence |
|---------------|---------------------|--------------|
| `/services/{service}` | `/{community}/services/{service}` | Scope community incertain sur version globale |
| `/requests/{request}` | `/{community}/requests/{request}` | Scope community incertain sur version globale |
| `/messages` | `/{community}/messages` | Version globale utilise community_id de l'user |
| `/favorites` | `/{community}/favorites` | Version globale utilise community_id de l'user |
| `/points` | `/{community}/points` | Version globale utilise community_id de l'user |
| `/transactions/*` | `/{community}/transactions/*` | Version globale n'existe pas (export seulement) |

**Risque**: Les routes globales pourraient permettre l'accès cross-community si la validation de scope n'est pas correcte.

---

<a id="conclusion"></a>

## Conclusion

Cette matrice documente de manière exhaustive les workflows métier de BouclePro.com, incluant:

✅ **7 workflows principaux** documentés
✅ **3 state machines** détaillées (Transaction, Service, ServiceRequest)
✅ **Matrice de permissions** complète
✅ **Contraintes multi-tenant** identifiées
✅ **Risques métier** (8 identifiés)
✅ **Risques sécurité** (6 identifiés)
✅ **Race conditions** (4 identifiées)
✅ **Incohérences** (14 identifiées) → **7 nouvelles**
✅ **Protections manquantes** (14 identifiées) → **7 nouvelles**
✅ **30+ scénarios QA** mappés pour génération
✅ **Audit UX surface** complet
✅ **Domain coverage** évalué (60%)

### Points d'Attention Critiques

1. **Race condition Confirm/Contest** - Points transférés mais transaction peut revenir à accepted
2. **Welcome points hardcodés** - 100 pts en dur au lieu d'utiliser `Community.welcome_points`
3. **Budget de Request non utilisé** - Données stockées mais non validées
4. **Ajustement admin non contraint** - Solde peut devenir négatif
5. **Review permanent** - Pas de modification/suppression possible
6. **Routes globales vs scoped** - Doubles routes avec validation de scope incertaine
7. **Badges sans UI admin** - Attribution automatique mais pas de gestion

### Prochaine Étape

Utiliser cette matrice pour générer **mécaniquement** les scénarios Playwright couvrant tous les cas identifiés.

### Recommandations Produit

1. **Implémenter `Community.welcome_points`** au lieu du hardcode 100 pts
2. **Créer UI admin pour les badges** (CRUD)
3. **Clarifier le scope des routes globales** vs communautaires
4. **Implémenter workflow Community Admin** pour la délégation de modération
5. **Ajouter UI pour la visibilité du profil** (show_email, show_phone)
6. **Implémenter workflow de traitement des CommunityRequest**
7. **Ajouter un centre de notifications** en base de données
