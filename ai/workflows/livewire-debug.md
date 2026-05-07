# Livewire Debug Workflow

## Goal

Debug Livewire safely while preserving:
- UI responsiveness
- performance
- tenant safety
- predictable state

---

# Standard Workflow

## 1. Inspect Browser First

Use browser/MCP tools when available.

Inspect:
- console errors
- network requests
- Livewire payloads
- DOM state
- Alpine interactions

Do not assume UI behavior without browser verification.

---

## 2. Inspect Component Logic

Inspect:
- component properties
- computed state
- validation rules
- polling
- emitted events

Prefer:
- explicit state
- predictable updates

Avoid:
- hidden state mutations
- duplicated logic

---

# Polling Rules

Use polling carefully.

Avoid:
- unnecessary polling
- excessive refresh rates
- heavy database queries during polling

Keep Livewire components lightweight.

---

# Validation Workflow

Verify:
- validation errors
- loading states
- optimistic UI behavior
- Alpine synchronization

---

# Performance Rules

Inspect:
- N+1 queries
- repeated renders
- large payloads
- unnecessary eager loading

Prefer scalable UI behavior.

---

# Tenant Safety

Always verify:
- tenant filtering
- authenticated user consistency
- scoped queries
- policy enforcement

---

# Final Validation

Before merge:
- test UI manually
- inspect console
- verify responsive behavior
- verify tenant consistency
- run relevant tests
