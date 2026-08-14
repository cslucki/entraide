# Documentation IA — Index

`docs/ai/` documente l'état **réellement livré** de l'infrastructure
d'observabilité IA de BouclePro : ce que le code fait aujourd'hui, pas une
vision cible. Elle ne recopie aucune TASK — elle en dérive.

## État livré

| Tranche | TASK produit | État |
|---|---|---|
| P1-1 — corrélation et process | TASK-1131 | **Mergée** — commit `4791a95` |
| P1-2 — catalogue tarifaire et coût inconnu | TASK-1132 | **Mergée** — commit `b1c76e1` |
| P1-3 — instrumentation Laravel AI SDK | TASK-1200 (ex-TASK-1133) | **Mergée** — commit `c6230d0` |
| P2 — garde économique `loop_summary` | TASK-1205 | **Mergée** |
| P3 — fondation `App\Ai` | TASK-1206 | **Mergée** |
| P3 — première capability sur le SDK texte | TASK-1207 | **Mergée** — commit `9a34a85` |

Les TASK sources, avec leur récit complet (décisions, pièges, tests,
neutralisations), restent la référence opérationnelle : `TODO/ARCHIVES/`
(worktree local, gitignoré — pas accessible hors de ce worktree).

## Pages

- [`ARCHITECTURE.md`](./ARCHITECTURE.md) — modèle de corrélation,
  `correlation_id` vs `invocationId` SDK, `process`/`scenario_id`, les trois
  tables de trace, la fondation `App\Ai`, limites connues du SDK v0.7.2.
- [`OBSERVABILITE-COUTS.md`](./OBSERVABILITE-COUTS.md) — catalogue tarifaire,
  `cost_unknown` tri-état, garde économique, instrumentation des invocations
  SDK, isolation Organization.

## Prochaine cible

**Context Builder minimal.** La cartographie TASK-1208 a établi que le
verrou n'est pas la migration vers le SDK — mécanique, faite en une TASK pour
`loop_summary` — mais le contexte : aucune capability ne croise aujourd'hui
deux sources de données. Chacune voit ses messages, ou son article, ou son
profil.

Le Context Builder répondra à une seule question : « de quelles informations
autorisées cette capability a-t-elle besoin maintenant ? ». Il consomme les
policies existantes, il ne les réimplémente pas.

## Hors périmètre de cette documentation

- P4 (configuration et budget IA par Organization) — non commencée.
- P5 (mémoire structurée) — non commencée.
- La vision cible long terme (mycélium, fédération) — voir
  `docs/architecture/05-AI_MYCELIUM_ARCHITECTURE.md` (DRAFT, non autoritaire).

## Hiérarchie de vérité

En cas de contradiction : le code d'abord, puis les TASK archivées
(`TODO/ARCHIVES/TASK-1131/1132/1200`), puis cette documentation. Cette
documentation ne doit jamais devenir une source parallèle — si le code
change, elle doit être mise à jour ou retirée, pas laissée à dériver.
