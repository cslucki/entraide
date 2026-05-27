# T146 Interaction Matrix — Product Flows

## Legend

| Priority | Meaning |
|----------|---------|
| **P0** | Vital — test now |
| P1 | Important — test next |
| P2 | Future variant |
| Legacy | Pre-existing, not in scope |
| ✗ | Out of scope / deprecated |

---

## 1. Visiteur Public

| # | Flow | Priority | T146 Spec |
|---|------|----------|-----------|
| 1.1 | Homepage `/` | P0 | Visual smoke — counters, layout |
| 1.2 | Login page `/login` | P0 | Form present |
| 1.3 | Register page `/register` | P1 | Registration form |
| 1.4 | Explorer `/explorer` | P0 | Category filters, service listing |
| 1.5 | Members list `/membres` | P1 | Directory display |
| 1.6 | Blog `/blog` | P1 | Article listing |
| 1.7 | Forgot password | P1 | Password reset flow |

## 2. Membre Connecté

| # | Flow | Priority | T146 Spec |
|---|------|----------|-----------|
| 2.1 | Login with .env credentials | **P0** | qa-member1@bouclepro.local |
| 2.2 | Dashboard `/dashboard` | **P0** | Stats, navigation, quick actions |
| 2.3 | Profile view `/profile/{id}` | P1 | Public profile |
| 2.4 | Profile edit | P1 | Settings/profile page |
| 2.5 | Messages `/messages` | P1 | Conversation list |
| 2.6 | Favorites `/favorites` | P1 | Favorite services list |
| 2.7 | Points `/points` | P1 | Points history |
| 2.8 | Logout | **P0** | POST logout, redirect `/` |

## 3. Membre 1 → Membre 2

| # | Flow | Priority | T146 Spec |
|---|------|----------|-----------|
| 3.1 | Transaction M1 → M2 | **P0** | Full buy/sell cycle |
| 3.2 | Transaction status (buyer) | **P0** | Dashboard verification |
| 3.3 | Transaction status (seller) | **P0** | Dashboard verification |
| 3.4 | Transaction messaging | **P0** | Chat linked to transaction |
| 3.5 | Help request between members | **P0** | Create + respond |

## 4. Admin

| # | Flow | Priority | T146 Spec |
|---|------|----------|-----------|
| 4.1 | Login with admin credentials | **P0** | qa-admin@bouclepro.local |
| 4.2 | Admin dashboard `/admin/dashboard` | **P0** | Stats, navigation |
| 4.3 | Users list `/admin/users` | **P0** | User management |
| 4.4 | Services list `/admin/services` | **P0** | Service moderation |
| 4.5 | Requests `/admin/requests` | **P0** | Help requests |
| 4.6 | Transactions `/admin/transactions` | **P0** | Transaction management |
| 4.7 | Blog `/admin/blog` | **P0** | Article management |
| 4.8 | Loops `/admin/loops` | **P0** | Loop management |
| 4.9 | Settings `/admin/settings` | P1 | Platform settings |
| 4.10 | Logs/supervision | P2 | System health |

## 5. Boucle (Loop)

| # | Flow | Priority | T146 Spec |
|---|------|----------|-----------|
| 5.1 | Create loop | **P0** | Member creates a loop |
| 5.2 | Verify loop in dashboard | **P0** | Loop appears |
| 5.3 | Loop member management | P1 | Add/remove members |
| 5.4 | Loop messaging | P2 | Loop chat |

## 6. Blog

| # | Flow | Priority | T146 Spec |
|---|------|----------|-----------|
| 6.1 | Create article | **P0** | Publish blog post |
| 6.2 | Verify on `/blog` | **P0** | Public listing |
| 6.3 | Verify in admin | **P0** | Admin article management |
| 6.4 | Edit article | P1 | Update published content |

## 7. Service (Micro-service)

| # | Flow | Priority | T146 Spec |
|---|------|----------|-----------|
| 7.1 | Create service (M1) | **P0** | Full creation form |
| 7.2 | Verify in dashboard (M1) | **P0** | My services |
| 7.3 | Verify in /explorer | **P0** | Public listing |
| 7.4 | Show service | **P0** | Detail page |
| 7.5 | Edit service | **P0** | Update form |
| 7.6 | Delete service | P1 | Soft delete / unpublish |

## 8. Demande d'Aide

| # | Flow | Priority | T146 Spec |
|---|------|----------|-----------|
| 8.1 | Create help request | **P0** | Member creates a request |
| 8.2 | Verify in dashboard | **P0** | My requests |
| 8.3 | Browse available requests | P1 | Explorer requests |

## 9. Transaction

| # | Flow | Priority | T146 Spec |
|---|------|----------|-----------|
| 9.1 | Initiate transaction | **P0** | M1 buys from M2 |
| 9.2 | Buyer status | **P0** | Dashboard verification |
| 9.3 | Seller status | **P0** | Dashboard verification |
| 9.4 | Messaging | **P0** | Transaction-linked chat |

## 10. Dashboard Membre

| # | Flow | Priority | T146 Spec |
|---|------|----------|-----------|
| 10.1 | Stats display | **P0** | Points, exchanges counters |
| 10.2 | My services | **P0** | List with status |
| 10.3 | My requests | **P0** | List with status |
| 10.4 | Recent activity | P1 | Activity feed |
| 10.5 | Navigation links | **P0** | All sidebar/header links |

## 11. Dashboard Admin

| # | Flow | Priority | T146 Spec |
|---|------|----------|-----------|
| 11.1 | Stats overview | **P0** | Platform metrics |
| 11.2 | Users | **P0** | User management |
| 11.3 | Services | **P0** | Service moderation |
| 11.4 | Requests | **P0** | Request management |
| 11.5 | Transactions | **P0** | Transaction oversight |
| 11.6 | Blog | **P0** | Article management |
| 11.7 | Loops | **P0** | Loop management |
| 11.8 | Settings | P1 | Platform configuration |
| 11.9 | Logs/supervision | P2 | System monitoring |
