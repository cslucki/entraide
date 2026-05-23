# BUG_BACKLOG_TRIAGE.md

**Tâche** : TASK-117-bug-backlog-triage
**Type** : Documentaire / Triage
**Date** : 2026-05-22
**Responsable** : OPENCODE

---

## Objectif

Créer un backlog unique des bugs et petites modifications BouclePro, classés P0/P1/P2, afin de sortir les retours utilisateurs et irritants produit des conversations ChatGPT.

---

## Tableau de triage

| ID | Surface | Signalé par | Description courte | Type | Impact utilisateur | Priorité | Décision | Notes techniques |
|----|---------|-------------|---------------------|------|-------------------|----------|----------|------------------|
| BUG-001 | Demande d'aide | Éric | Multi-photos non fonctionnel dans la demande d'aide | bug | Haut - blocage workflow | P0 | Corriger maintenant | Vérifier l'upload multi-images dans ServiceRequest / demande d'aide |
| BUG-002 | Profil public | LT-001-AUDIT | Avatar sans lien vers le profil complet | UX | Moyen - confusion navigation | P1 | Qualifier | Vérifier structure profile/{user} et lien avatar |
| BUG-003 | Messagerie mobile | T074.0 | Desktop split pane sur tous les écrans - breakage mobile | bug | Haut - UX mobile cassée | P0 | Corriger maintenant | `resources/views/messages/index.blade.php` - responsive stacking requis |
| BUG-004 | Messagerie mobile | T074.0 | Viewport height lock - overflow caché | bug | Haut - contenu tronqué mobile | P0 | Corriger maintenant | `height: calc(100vh - 64px)` + nested scroll à remplacer |
| BUG-005 | API Tenant | LT-002-Audit-OPS | API routes sans scope tenant | bug | Critique - cross-tenant exposure | P0 | Corriger maintenant | Toutes routes `routes/api.php` - middleware manquant |
| BUG-006 | Dashboard | LT-002-Audit-OPS | Dashboard sans filtre tenant | bug | Critique - cross-tenant data | P0 | Corriger maintenant | `app/Http/Controllers/DashboardController.php` |
| BUG-007 | Policies | LT-002-Audit-OPS | Policies sans vérification tenant | bug | Critique - cross-tenant action | P0 | Corriger maintenant | TransactionPolicy, ServicePolicy, ServiceRequestPolicy, MessagePolicy, ReviewPolicy, BlogPostPolicy |
| BUG-008 | API Transaction | LT-002-Audit-OPS | TransactionController::store with withoutGlobalScope | bug | Moyen - risque cross-tenant | P1 | Qualifier | Vérifier bypass justifié mais valider sécurité |
| BUG-009 | Legacy Community | T075.10 | Messages d'erreur contenant "community" | cosmétique | Bas - irritant produit | P2 | Reporter (T075.7) | LoopService, LoopMessageService, ReferralService, RewardDispatcher |
| BUG-010 | Routes Legacy | T075.10 | Routes `/{community}` prefix legacy | tech debt | Bas - dette technique | P2 | Reporter (T075.10) | ~20+ routes concernées - nécessite tâche dédiée |
| BUG-011 | Reverb Readiness | T074.0 | Runtime broadcasting en mode 'log' | bug | Moyen - fonctionnalité attendue | P1 | Reporter (T074) | Pas de `config/reverb.php`, pas de `routes/channels.php` |
| BUG-012 | Boucles Visibility | T077.2 | DB ne différencie pas Loop interne vs publiquement listable | data | Moyen - limitation produit | P1 | Qualifier | Champ de visibilité requis si T077.3 expose records DB |
| BUG-013 | Avatar Upload | Legacy | Avatar upload dans profil édition - état actuel ? | UX | Bas - irritant | P2 | Qualifier | Vérifier si fonctionnel - signalé dans transaction matrix |
| BUG-014 | Ghost Refs | Context | Ghost refs avatars/images si encore pertinentes | bug | Moyen - UX cassée | P1 | Qualifier | Rechercher les refs fantômes dans le codebase |
| BUG-015 | Scripts ALPHA | Context | Scripts ALPHA incompatibles si encore à garder | tooling | Bas - dette technique | P2 | Reporter (tooling debt) | Vérifier compatibilité avec architecture actuelle |
| BUG-016 | Dashboard Admin | Cyril | Admin connecté, `/dashboard` retourne 404 | bug | Haut - blocage admin | P0 | Tâche runtime dédiée après triage | Vérifier routes, middleware, controller, tenant resolution |
| BUG-017 | Footer Version | Cyril | Footer affiche `v0.1-alpha` au lieu de version courante ex: `v0.117-alpha` | modif | Bas - irritant produit | P2 | Tâche runtime dédiée après triage | Vérifier config/app ou helper simple, pas de système de release complet |

---

## Synthèse par priorité

### P0 (Critique - Blocage / Sécurité)

| ID | Description | Surface | Décision |
|----|-------------|---------|----------|
| BUG-001 | Multi-photos demande d'aide | Demande d'aide | Corriger maintenant |
| BUG-003 | Desktop split pane mobile | Messagerie | Corriger maintenant |
| BUG-004 | Viewport height lock | Messagerie | Corriger maintenant |
| BUG-005 | API routes sans scope tenant | API | Corriger maintenant |
| BUG-006 | Dashboard sans filtre tenant | Dashboard | Corriger maintenant |
| BUG-007 | Policies sans vérification tenant | Policies | Corriger maintenant |
| BUG-016 | Dashboard admin 404 | Dashboard | Tâche runtime dédiée après triage |

**Total P0 : 7 bugs**

### P1 (Haut / Moyen - Irritant important)

| ID | Description | Surface | Décision |
|----|-------------|---------|----------|
| BUG-002 | Avatar sans lien profil | Profil | Qualifier |
| BUG-008 | TransactionController withoutGlobalScope | API Transaction | Qualifier |
| BUG-011 | Reverb readiness | Broadcasting | Reporter (T074) |
| BUG-012 | Boucles visibility DB | Boucles | Qualifier |
| BUG-014 | Ghost refs avatars/images | Images | Qualifier |

**Total P1 : 5 bugs**

### P2 (Bas - Dette technique / Cosmétique)

| ID | Description | Surface | Décision |
|----|-------------|---------|----------|
| BUG-009 | Messages erreur "community" | Services | Reporter (T075.7) |
| BUG-010 | Routes `/{community}` legacy | Routes | Reporter (T075.10) |
| BUG-013 | Avatar upload état | Profil | Qualifier |
| BUG-015 | Scripts ALPHA incompatibles | Tooling | Reporter (tooling) |
| BUG-017 | Footer version hardcodée | Footer | Tâche runtime dédiée après triage |

**Total P2 : 5 bugs**

---

## Recommandations de lots de correction

### Lot 1 - Sécurité & Tenant Isolation (P0)
- BUG-005 : API routes sans scope tenant
- BUG-006 : Dashboard sans filtre tenant
- BUG-007 : Policies sans vérification tenant
- BUG-008 : TransactionController withoutGlobalScope

**Raison** : Bloqueur sécurité critique, cross-tenant exposure.

### Lot 2 - Mobile & UX Critique (P0)
- BUG-001 : Multi-photos demande d'aide
- BUG-003 : Desktop split pane mobile
- BUG-004 : Viewport height lock

**Raison** : Bloqueur UX utilisateur, impact conversion/engagement.

### Lot 3 - Irritants Produit (P1)
- BUG-002 : Avatar sans lien profil
- BUG-012 : Boucles visibility DB
- BUG-014 : Ghost refs avatars/images

**Raison** : Irritants produit, conversion améliorée.

### Lot 4 - Dette Technique & Future (P2)
- BUG-009 : Messages erreur "community"
- BUG-010 : Routes `/{community}` legacy
- BUG-013 : Avatar upload état
- BUG-015 : Scripts ALPHA incompatibles

**Raison** : Dette technique, préparer T075.7 et T075.10.

---

## Conversion en Features ROADMAP

Aucun item identifié pour conversion immédiate en feature ROADMAP. Tous les items sont des bugs ou modifications mineures.

---

## Notes de progression

- 2026-05-22 : Création initiale du backlog - 15 items recensés
- 2026-05-23 : Ajout BUG-016 (Dashboard admin 404) et BUG-017 (Footer version hardcodée) - 17 items
- À compléter : Échanges avec Cyril pour ~13 bugs/modifications restants (cible ~30)
- À compléter : Retours utilisateurs directement documentés

---

## Prochaine étape

1. Validation du backlog par GLOBAL/Cyril
2. Ajout des ~15 items manquants (retours utilisateurs non documentés)
3. Démarrage Lot 1 (Sécurité & Tenant Isolation)