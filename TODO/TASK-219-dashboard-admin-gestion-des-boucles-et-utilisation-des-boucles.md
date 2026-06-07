---
task_id: TASK-219
title: Dashboard Admin — Gestion des boucles et utilisation des boucles

status: IN_PROGRESS

owner: OPENCODE

contributors: []

branch: TASK-219-dashboard-admin-gestion-des-boucles-et-utilisation-des-boucles

priority: HIGH

created_at: 2026-06-07 09:30:54 Europe/Paris
updated_at: 2026-06-07 09:30:54 Europe/Paris

labels: []

lock:
  status: LOCKED
  agent: OPENCODE
  since: 2026-06-07 09:30:54 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Dashboard Admin — Gestion des boucles et utilisation des boucles.

Backend (`/admin/loops`) :
1. Créer une boucle et affecter un propriétaire sur un org_id donnée
2. Indiquer si la boucle est privée ou publique
3. Sur une boucle existante : inviter des membres, définir public/private, visualiser fichiers liés

Frontend (`/loops`) :
4. Liste des boucles accessibles
5. Si une seule boucle accessible, redirection directe vers le chat
6. Boucle publique : tout user connecté de la même organisation peut rejoindre
7. Boucle privée : uniquement les membres/invités
8. Chat UI WhatsApp-like responsive
9. Joindre fichiers (image/PDF/Office)
10. Copier/coller images
11. Répondre à un message avec mini-bulle de citation

---

# Planned Actions

## Phase 1 — Admin CRUD boucles ✅

- [x] Ajouter routes admin :
  - GET /admin/loops/create
  - POST /admin/loops
  - GET /admin/loops/{loop}/edit
  - PUT /admin/loops/{loop}
  - POST /admin/loops/{loop}/members (inviter)
  - DELETE /admin/loops/{loop}/members/{member} (retirer)
  - GET /admin/loops/{loop}/files (lister pièces jointes)
  - DELETE /admin/loops/{loop}
- [x] Étendre AdminLoopController : create, store, edit, update, addMember, removeMember, files, destroy
- [x] Vue admin/loops/create.blade.php (nom, description, visibilité, propriétaire)
- [x] Vue admin/loops/edit.blade.php (champs + gestion membres + visibilité)
- [x] Index affiche toutes les boucles de l'org avec compteurs

## Phase 2 — Frontend auto-redirect + accès public

- [ ] LoopController.index : rediriger vers show si une seule boucle accessible
- [ ] LoopController.show : auto-join si boucle publique et user connecté non-membre
- [ ] Vérifier que non-connecté est redirigé login sur /loops

## Phase 3 — Reply messages

- [ ] Migration : ajouter `reply_to_id` nullable sur `loop_messages` (FK auto)
- [ ] Model LoopMessage : ajouter `replyTo()` relation BelongsTo(self)
- [ ] LoopMessageService.sendUserMessage : accepter optional reply_to_id
- [ ] Vue loops.show : bouton "Répondre" sur chaque message
- [ ] Afficher mini-bulle avec auteur + début du texte cité (ou indication image)
- [ ] Scroll auto vers le message cité au clic

## Phase 4 — File uploads

- [ ] Migration : créer `loop_message_attachments` (id uuid, message_id uuid FK, path, original_name, mime_type, size, metadata json)
- [ ] Model LoopMessageAttachment
- [ ] Storage : public disk, loop-attachments/
- [ ] LoopMessageService : upload files + create attachments
- [ ] Validation : mimes jpg,png,gif,webp,pdf,doc,docx,xls,xlsx, max 10MB
- [ ] Vue loops.show : input fichier + zone drop/paste
- [ ] Alpine.js paste handler pour coller images
- [ ] Affichage attachments dans les messages (image preview, fichier lien)

## Phase 5 — Link previews (YouTube + Open Graph)

- [ ] Service LinkPreviewService : détection URL dans body
- [ ] YouTube : extract video ID, fetch oEmbed API pour titre/thumbnail
- [ ] Sites web : HTTP client timeout 3s, parse meta OG
- [ ] Stocker preview dans metadata du message
- [ ] Affichage carte preview sous le message

## Phase 6 — Chat UI WhatsApp-like

- [ ] Layout full-height mobile (h-screen, flex-col)
- [ ] Header sticky : nom boucle, statut, icône membres, bouton quitter
- [ ] Zone messages scrollable flex-1 overflow-y-auto, fond gris clair pattern
- [ ] Bulles : max-w-[80%] md:max-w-[60%], arrondies, ombre légère
- [ ] Bulles user à droite (indigo), autres à gauche (blanc/gris)
- [ ] Avatar + nom pour les autres
- [ ] Input bar sticky bottom : textarea auto-resize + bouton envoi + icône fichier
- [ ] Sidebar membres desktop (colonne droite)
- [ ] Drawer membres mobile (slide-in)
- [ ] Scroll to bottom automatique
- [ ] Timestamps relatifs dans les bulles
- [ ] Dark mode partout

## Phase 7 — Admin files list

- [ ] AdminLoopController.files : lister attachments paginés par loop
- [ ] Vue admin/loops/files.blade.php

## Phase 8 — Tests

- [ ] Admin peut créer une boucle avec owner + visibility
- [ ] Admin peut éditer visibility d'une boucle
- [ ] Admin peut inviter un membre de la même org
- [ ] Admin ne peut pas inviter cross-org
- [ ] Frontend : index redirige si 1 boucle
- [ ] Frontend : public loop auto-join
- [ ] Frontend : private loop 404 pour non-member
- [ ] Reply message stocke reply_to_id
- [ ] Upload fichier crée attachment
- [ ] Non-connecté redirigé login
- [ ] Guests sans org 404

## Phase 9 — Finalisation

- [ ] check-task.sh
- [ ] finalize-task.sh
- [ ] merge-task.sh

---

# Progress Log

## 2026-06-07 09:30:54 Europe/Paris

Task created. Branch: TASK-219-dashboard-admin-gestion-des-boucles-et-utilisation-des-boucles.

Owner: OPENCODE
Status: IN_PROGRESS

## 2026-06-07 11:07:00 Europe/Paris

Phase 1 — Admin CRUD boucles terminée (non commitée encore).

Files modified:
- `app/Http/Controllers/Admin/AdminLoopController.php` — Full CRUD: index, create, store, edit, update, addMember, removeMember, files, destroy. Org-scoped via `assertOrgAccess()`.
- `app/Services/LoopService.php` — Added `updateLoop()`, `addMemberByUserId()`, `removeMember()`. `createLoop()` now accepts `$visibility`.
- `routes/web.php` — 8 admin routes for loops CRUD + members + files.
- `resources/views/admin/loops/index.blade.php` — Action buttons (Modifier, Fichiers, Supprimer).
- `resources/views/admin/loops/create.blade.php` — NEW: form with name, description, visibility, owner selection.
- `resources/views/admin/loops/edit.blade.php` — NEW: infos editing, visibility, member management (add/remove), danger zone.
- `resources/views/admin/loops/files.blade.php` — NEW: stub for files list by message.

---

# Handoffs

# Tests

- [ ] feature tests phase 1 (admin CRUD)
- [ ] feature tests phase 2 (frontend redirect/join)
- [ ] feature tests phase 3 (reply)
- [ ] feature tests phase 4 (files)
- [ ] feature tests phase 5 (link previews)
- [ ] feature tests phase 6 (chat UI — Playwright)
- [ ] tenant validation tests
- [ ] regression: existing loop tests still pass

---

# Test Results

Pending.

---

# Review Notes

Pending.

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`
