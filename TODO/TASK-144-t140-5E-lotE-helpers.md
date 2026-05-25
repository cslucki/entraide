---
task_id: TASK-144-t140-5E-lotE
title: T140.5E Lot E — helpers.php organizationRoute param rename

status: DONE

owner: OpenCode

contributors:
  - OpenCode

branch: TASK-144-t140-5E-lotE-helpers

priority: LOW

created_at: 2026-05-25 14:41:31 Europe/Paris
updated_at: 2026-05-25 14:41:31 Europe/Paris

labels:
  - organization
  - helpers
  - routing

lock:
  status: LOCKED
  agent: OpenCode
  since: 2026-05-25 14:41:31 Europe/Paris

---

# T140.5E Lot E — helpers.php organizationRoute param rename

## Objectif

Inverser le mapping de paramètres dans `organizationRoute()` pour préparer la migration des routes `{community}` → `{organization}`. Désormais, les call sites utilisent `['organization' => $slug]` nativement, et le legacy `['community' => $slug]` est normalisé.

## Périmètre

- `app/Support/helpers.php`

## Changements effectués

| Line | Before | After |
|------|--------|-------|
| 14 | `Generate a URL for a community/organization route.` | `Generate a URL for an organization route.` |
| 16 | `'organization' → 'community' parameter mapping` | `'community' → 'organization' parameter mapping` |
| 22-23 | Duplicate `['organization' => $slug]` examples | `['community' => $slug]` + `['organization' => $slug]` |
| 30 | `isset($p['organization']) && !isset($p['community'])` | `isset($p['community']) && !isset($p['organization'])` |
| 31 | `$p['community'] = $p['organization']` | `$p['organization'] = $p['community']` |
| 32 | `unset($p['organization'])` | `unset($p['community'])` |

## Tests

- PHP lint: OK
- Tests: 826 passed, 0 failed, 11 skipped (known risks)
- Call sites: 0 (zéro usage actif de `organizationRoute()`)

## Audit

- `docs/audits/T140.5E-lotE-helpers.md`
