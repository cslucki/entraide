# Documentation IA — Index

`docs/ai/` documente l'état **réellement livré** de l'infrastructure
d'observabilité IA de BouclePro : ce que le code fait aujourd'hui, pas une
vision cible. Elle ne recopie aucune TASK — elle en dérive.

## P1 — Observabilité (complète, mergée dans `develop`)

| Tranche | TASK produit | État |
|---|---|---|
| P1-1 — corrélation et process | TASK-1131 | **Mergée** — commit `4791a95` |
| P1-2 — catalogue tarifaire et coût inconnu | TASK-1132 | **Mergée** — commit `b1c76e1` |
| P1-3 — instrumentation Laravel AI SDK | TASK-1200 (ex-TASK-1133) | **Mergée** — commit `c6230d0` |

Les trois TASK sources, avec leur récit complet (décisions, pièges, tests,
neutralisations), restent la référence opérationnelle :
`TODO/ARCHIVES/TASK-1131-*.md`, `TODO/ARCHIVES/TASK-1132-*.md`,
`TODO/ARCHIVES/TASK-1200-*.md` (worktree local, gitignoré — pas accessible
hors de ce worktree).

## Pages

- [`ARCHITECTURE.md`](./ARCHITECTURE.md) — modèle de corrélation,
  `correlation_id` vs `invocationId` SDK, `process`/`scenario_id`, les trois
  tables de trace, limites connues du SDK v0.7.2, règle pour P3.
- [`OBSERVABILITE-COUTS.md`](./OBSERVABILITE-COUTS.md) — catalogue tarifaire,
  `cost_unknown` tri-état, instrumentation des invocations SDK, isolation
  Organization.

## Hors périmètre de cette documentation

- P1-Bench (TASK-1201, environnement de validation IA autonome) —
  **BLOQUÉ**, en attente du GO explicite de Cyril pour créer
  `bouclepro_ai_validation`. Non documenté ici : rien n'a encore tourné en
  conditions réelles.
- P2 (garde économique `loop_summary`) — non commencée, hors périmètre
  absolu de cette TASK documentaire.
- P3 (migration des capabilities legacy vers le SDK, `CapabilityRegistry`)
  — non commencée. `ARCHITECTURE.md` documente la règle que P3 devra
  respecter, pas une implémentation.

## Hiérarchie de vérité

En cas de contradiction : le code d'abord, puis les TASK archivées
(`TODO/ARCHIVES/TASK-1131/1132/1200`), puis cette documentation. Cette
documentation ne doit jamais devenir une source parallèle — si le code
change, elle doit être mise à jour ou retirée, pas laissée à dériver.
