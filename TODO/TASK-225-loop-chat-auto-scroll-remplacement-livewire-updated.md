---
task_id: TASK-225
title: Loop chat auto-scroll (remplacement livewire:updated)

status: DONE

owner: OPENCODE

contributors: []

branch: TASK-225-loop-chat-auto-scroll-remplacement-livewire-updated

priority: MEDIUM

created_at: 2026-06-08 09:38:23 Europe/Paris
updated_at: 2026-06-08 09:38:23 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: OPENCODE
  since: 2026-06-08 09:38:23 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Fix auto-scroll after sending message in loop chat — Livewire 4 does NOT dispatch `livewire:updated` DOM event (was a Livewire 3 behavior).

# Root Cause

Livewire 4 (v4.2.4) does NOT dispatch `livewire:updated` as a DOM CustomEvent or window event. The Alpine handler `x-on:livewire:updated.window` in loop-chat.blade.php **never fired** regardless of which Livewire action triggered (sendMessage, polling, etc.).

Events confirmed available in LW4 JS source:
- `livewire:init`, `livewire:initializing`, `livewire:initialized`
- `livewire:navigate`, `livewire:navigating`, `livewire:navigated`
- Upload events: `livewire-upload-start`, `livewire-upload-finish`, `livewire-upload-error`, `livewire-upload-cancel`, `livewire-upload-progress`
- No `livewire:updated` or similar component-update DOM event

# Solution

Explicit dispatch from PHP instead of relying on a non-existent DOM event:

1. `app/Livewire/LoopChat.php:46` — Added `$this->dispatch('message-sent')` after `sendMessage()` clears the body
2. `resources/views/livewire/loop-chat.blade.php:5` — Replaced `x-on:livewire:updated.window` with `x-on:message-sent.window`

After sending, always scroll to bottom (no `atBottom` guard — user triggered the action, expects to see the result).

---

# Planned Actions

- [ ] inspect architecture
- [ ] inspect impacted files
- [ ] implement changes
- [ ] run tests
- [ ] validate UI

---
# Progress Log


## 2026-06-08 09:38:23 Europe/Paris

Task created.

Owner:
OPENCODE

Branch:
TASK-225-loop-chat-auto-scroll-remplacement-livewire-updated

Status:
IN_PROGRESS

# Handoffs

# Tests

- [ ] feature tests
- [ ] browser validation
- [ ] responsive validation
- [ ] console inspection
- [ ] tenant validation

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