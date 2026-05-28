---
file: working/current-focus.md
created_at: 2026-05-28 20:00 CEST
updated_at: 2026-05-28 20:00 CEST
type: working_focus
status: active
---

# Current Focus

## Focus

Migration Community→Organization terminée (T151-T156, 7 phases, mergées).
Phase de stabilisation et planification de la suite.

## Accompli

- DB : communities → organizations, 8 community_id columns dropped
- Models : 8 modèles nettoyés, trait simplifié, 3 factories, 58 tests
- Middleware : ResolveCommunity → Organization runtime
- Controllers : 4 renommés, 7 modifiés, routes migrées
- Policies : déjà clean (no-op)
- Livewire/Blade : 5 vues migrées
- Tests : T1405 fix, suite stable (~790 ✅ / 35 ❌ pré-existants)

## Reste (dead code, safe)

- 6 lignes community_id dans services (LoopMessage, Referral, Reward, Loop)
- 3 $currentCommunity fallback dans Blade
- 35 échecs pré-existants (hors scope migration)

## Prochaines actions

À définir avec Cyril.
