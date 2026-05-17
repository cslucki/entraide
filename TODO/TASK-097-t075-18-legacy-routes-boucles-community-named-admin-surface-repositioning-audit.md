---
task_id: TASK-097
title: T075.18 — Legacy Routes /boucles & Community-Named Admin Surface Repositioning Audit

status: IN_PROGRESS

owner: OPENCODE

contributors: []

branch: TASK-097-t075-18-legacy-routes-boucles-community-named-admin-surface-repositioning-audit

priority: MEDIUM

created_at: 2026-05-18 00:18:20 Europe/Paris
updated_at: 2026-05-18 00:18:20 Europe/Paris

labels:
  - audit
  - legacy
  - routes
  - repositioning
  - community
  - organization
  - admin

lock:
  status: LOCKED
  agent: OPENCODE
  since: 2026-05-18 00:18:20 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# T075.18 — Legacy Routes /boucles & Community-Named Admin Surface Repositioning Audit

## Objectif

Auditer et cadrer le repositionnement des surfaces legacy visibles :
- `/boucles`
- `/boucles/creer`
- éventuel `/partners`
- routes Admin nommées Community / Communities
- contrôleurs Admin concernés
- vues concernées
- tests AdminCommunities legacy
- textes UI visibles qui confondent Community, Organization, Partner ou Loop

**Règles architecture à préserver :**
- Organization = Tenant.
- Loop ≠ Tenant.
- Partner ≠ Tenant.
- Partner = co-branding / distribution.
- `current_organization` est la source runtime canonique.
- `organization_id` est canonique côté nouveau code.
- `community_id` reste uniquement une colonne DB legacy de transition.
- `current_community` ne doit plus être une dépendance runtime normale.
- `/boucles` est une surface legacy et ne doit pas être confondue avec les vrais Loops.
- Aucun nouveau concept, fichier, test, helper, service, doc ou prompt ne doit introduire Community comme concept actif.
- Les usages legacy restants doivent être documentés avec justification et handoff.

---

## Questions à Résoudre

1. Que fait exactement `/boucles` aujourd'hui ?
2. `/boucles` représente-t-il encore des Organizations, des Partners ou une ancienne surface Community ?
3. `/boucles/creer` doit-il être supprimé, redirigé, ou repositionné en "Devenir partenaire" ?
4. `/partners` existe-t-il déjà ?
5. Faut-il créer `/partners` maintenant ou seulement documenter la cible ?
6. Quelles routes Admin utilisent encore Community dans leur nom ?
7. Ces routes Admin doivent-elles rester legacy jusqu'à migration DB ?
8. Quels textes visibles doivent être modifiés plus tard dans une tâche UI dédiée ?
9. Quels tests doivent rester legacy car ils couvrent encore la table communities ?
10. Quel découpage futur permet de solder proprement cette dette ?

---

## Périmètre Inclus

- audit routes/web.php pour `/boucles`, `/partners`, admin communities
- audit contrôleurs concernés
- audit vues concernées
- audit tests concernés
- TASK file
- documentation courte si nécessaire

## Hors Scope

- pas de migration DB
- pas de suppression du modèle Community
- pas de remplacement global
- pas de création module Partner
- pas de refonte UI
- pas de changement runtime large
- pas de changement API métier
- pas de changement Policy métier
- pas de changement middleware
- pas de ChatLoop
- pas de nouvelle interface
- pas de nouvelle feature métier
- pas de modification PROD
- pas de giant search/replace
- pas de redirection opportuniste non validée

---

## Plan d'Audit

### Phase 1 — Routes & Contrôleurs
1. Lire `routes/web.php` — identifier toutes les routes /boucles, /partners, admin communities
2. Lire les contrôleurs rattachés
3. Documenter le rôle exact de chaque route

### Phase 2 — Vues
4. Lister les vues Blade associées
5. Identifier les textes UI qui utilisent "Community", "Boucle", "Partner" de façon ambigüe

### Phase 3 — Tests
6. Lister les tests couvrant Admin + Community
7. Identifier les tests purement legacy (dépendent encore de la table communities)

### Phase 4 — Synthèse
8. Répondre aux 10 questions ci-dessus
9. Proposer le découpage futur en sous-tâches (T075.19, T076.x, etc.)
10. Documenter les handoffs nécessaires

---

## Critères d'Acceptation

- TASK file créé, status IN_PROGRESS, lock actif selon script.
- Branche dédiée créée depuis develop clean.
- Le TASK file documente clairement que T075.18 est audit / repositioning.
- Le TASK file interdit explicitement toute implémentation massive.
- Les handoffs futurs sont prévus vers T075.19 / T76 selon résultat d'audit.
- Aucun fichier runtime modifié à la création.
- git status final propre ou modifications volontairement commitées.
- Branche poussée sur origin si création commitée.

---

## Tests Attendus

- `php artisan route:list` (validation inventaire)
- `php artisan route:list --path=admin` (routes admin)
- `rg "boucles" routes/` (présence/absence)
- `rg "partners" routes/` (présence/absence)
- `rg "Community" app/Http/Controllers/Admin/` (contrôleurs legacy)
- `rg "Community" resources/views/` (textes UI)
- `rg "AdminCommunities\|admin.*communities\|CommunityController" tests/` (tests legacy)

---

# Planned Actions

- [X] créer TASK + branche (OPS)
- [ ] Phase 1 — audit routes & contrôleurs (CODE)
- [ ] Phase 2 — audit vues & textes UI (CODE)
- [ ] Phase 3 — audit tests legacy (CODE)
- [ ] Phase 4 — synthèse & découpage futur (CODE)
- [ ] documentation légère si nécessaire (CODE)
- [ ] handoff vers T075.19 / T76 (OPS)

---

# Progress Log

## 2026-05-18 00:18:20 Europe/Paris

### OPS — Création tâche

- create-task.sh exécuté avec succès
- Branche créée : `TASK-097-t075-18-legacy-routes-boucles-community-named-admin-surface-repositioning-audit`
- TASK file complété avec objectif, périmètre, hors scope, plan d'audit, critères d'acceptation
- Aucun fichier runtime modifié
- Commit + push effectués pour figer la création de tâche

---

# Handoffs

## Prochains handoffs prévus

Une fois l'audit terminé, handover vers :
- **T075.19** — Implémentation des repositionnements validés
- **T076.x** — Nettoyage UI / textes visibles / docs
- Agent **OPENAI / Codex GPT-5.5** pour review ciblée si nécessaire
- **Claude Code** uniquement si complexité architecture forte (peu probable ici)

---

# Tests

## Tests d'inventaire (Phase 0 — déjà exécutables)

- [ ] `php artisan route:list | grep -E "boucles|partners|community|Community"`
- [ ] `rg -n "boucles" routes/`
- [ ] `rg -n "partners" routes/`
- [ ] `rg -n "Community" app/Http/Controllers/Admin/`
- [ ] `rg -n "Community" resources/views/`
- [ ] `rg -n "AdminCommunities|admin.*communities|CommunityController" tests/`

---

# Test Results

Pending.

---

# Review Notes

## Contraintes OPS

- Aucune modification runtime autorisée pendant T075.18.
- T075.18 est purement audit + documentation.
- Le CODE agent exécutera les phases 1-4 en lecture seule.
- Ne pas créer de nouvelles routes, contrôleurs, vues, policies, middleware, migrations.
- Ne pas supprimer de code existant.
- Ne pas lancer de refactoring.
