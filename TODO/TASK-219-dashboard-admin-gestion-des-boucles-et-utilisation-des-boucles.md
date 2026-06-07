---
task_id: TASK-219
title: Dashboard Admin — Gestion des boucles et utilisation des boucles

status: IN_PROGRESS

owner: CODEUR

contributors:
  - OPENCODE

branch: TASK-219-dashboard-admin-gestion-des-boucles-et-utilisation-des-boucles

priority: HIGH

created_at: 2026-06-07 09:30:54 Europe/Paris
updated_at: 2026-06-07 09:48:00 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: null
  since: null

handoff: true

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

## Phase 1 — Admin CRUD boucles ✅ (OPENCODE)

- [x] Routes admin : create, store, edit, update, members.add, members.remove, files, destroy
- [x] AdminLoopController : create, store, edit, update, addMember, removeMember, files, destroy
- [x] Vue admin/loops/create.blade.php
- [x] Vue admin/loops/edit.blade.php + bug fix `$loop` → `$boucle` (Blade conflict)
- [x] Vue admin/loops/files.blade.php (stub)

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

- [ ] Migration : créer `loop_message_attachments`
- [ ] Model LoopMessageAttachment
- [ ] Storage : public disk, loop-attachments/
- [ ] LoopMessageService : upload files + create attachments
- [ ] Validation : mimes jpg,png,gif,webp,pdf,doc,docx,xls,xlsx, max 10MB
- [ ] Vue loops.show : input fichier + zone drop/paste
- [ ] Alpine.js paste handler pour coller images
- [ ] Affichage attachments dans les messages

## Phase 5 — Link previews (YouTube + Open Graph)

- [ ] Service LinkPreviewService
- [ ] YouTube oEmbed
- [ ] Sites web OG parse
- [ ] Stocker preview dans metadata
- [ ] Affichage carte preview

## Phase 6 — Chat UI WhatsApp-like

- [ ] Layout full-height responsive
- [ ] Header sticky
- [ ] Zone messages scrollable
- [ ] Bulles alternées
- [ ] Input bar sticky
- [ ] Sidebar membres desktop
- [ ] Drawer membres mobile
- [ ] Scroll to bottom
- [ ] Dark mode

## Phase 7 — Admin files list

- [ ] Vue admin/loops/files.blade.php complète

## Phase 8 — Tests

- [ ] Tous les tests definis dans le brief CODEUR

## Phase 9 — Finalisation

- [ ] check-task.sh
- [ ] finalize-task.sh
- [ ] merge-task.sh

---

# Progress Log

## 2026-06-07 09:30:54 Europe/Paris
Task created. Branch: TASK-219-dashboard-admin-gestion-des-boucles-et-utilisation-des-boucles.

## 2026-06-07 09:48:00 Europe/Paris
Phase 1 terminée par OPENCODE.
Bug fix: `$loop` → `$boucle` dans AdminLoopController::edit() et edit.blade.php (conflit avec variable Blade `$loop` dans @foreach).
Handoff à CODEUR pour phases 2-8.
Conversation file: ai-local/conversations/20260607-09h48-TASK-220-loops-chat-frontend-et-fichiers.md

---

# Handoffs

## OPENCODE → CODEUR (2026-06-07 09:48)
TASK-219 phases 2-8 confiées à CODEUR.
Brief complet dans ai-local/conversations/20260607-09h48-TASK-220-loops-chat-frontend-et-fichiers.md

---

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
