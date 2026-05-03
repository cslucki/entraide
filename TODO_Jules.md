# TODO_Jules.md — Backlog Jules (frontend / UI / vues)

> **Règle absolue :** Seul Jules lit et modifie ce fichier.
> Avant de commencer : lire AGENTS.md et CLAUDE.md.
> Branche : `jules/TASK-XXX` depuis main à jour. PR vers main quand terminé.

---

## Statuts

| Statut | Signification |
|---|---|
| `TODO` | Disponible, à prendre |
| `IN_PROGRESS` | En cours — noter la branche |
| `IN_REVIEW` | PR ouverte |
| `DONE` | Fusionné dans main |

---

## 🟢 Prêt à lancer

### TASK-017 — Dark mode toggle persistant
- **Statut** : `TODO`
- **Branche** : `jules/TASK-017`
- **Fichiers** :
  - `resources/views/layouts/navigation.blade.php`
  - `resources/js/app.js`
- **Description** : Bouton toggle soleil/lune dans la navbar. Persistance `localStorage`.
  Script d'init anti-flash dans `app.js` (avant rendu DOM).
  Classe `dark` sur `<html>` (Tailwind CSS v4). Ne pas modifier `app.blade.php`.
- **Quand terminé** : `npm run build` sans erreur → mettre `IN_REVIEW` → ouvrir PR :
  `gh pr create --title "feat(TASK-017): dark mode toggle persistant"`

---

### TASK-018 — Graphique historique du solde de points
- **Statut** : `IN_REVIEW`
- **Branche** : `jules/TASK-018`
- **Fichiers** :
  - `resources/views/points/index.blade.php`
- **Description** : Graphique linéaire Chart.js (CDN cdnjs.cloudflare.com) sur la page `/points`.
  Lire `app/Http/Controllers/PointController.php` pour la structure de `$ledger`.
  Calculer le solde cumulatif. Max 60 derniers points. Adapter dark/light mode.
  Insérer avant le tableau existant.
- **Quand terminé** : mettre `IN_REVIEW` → ouvrir PR :
  `gh pr create --title "feat(TASK-018): graphique historique des points"`

---

## 🟡 Backlog à venir

### TASK-020 — Pagination infinie automatique (Intersection Observer)
- **Statut** : `TODO`
- **Fichiers** : `app/Livewire/Explorer.php`, `resources/views/livewire/explorer.blade.php`
- **Description** : Remplacer le bouton "Charger plus" par un scroll automatique
  via Intersection Observer. Pas de changement de logique backend.

### TASK-021 — Mode liste / grille dans l'explorateur
- **Statut** : `TODO`
- **Fichiers** : `resources/views/livewire/explorer.blade.php`
- **Description** : Toggle liste/grille dans l'en-tête de l'explorateur.
  Persistance `localStorage`. Aucun changement backend.

### TASK-022 — Page FAQ / Aide
- **Statut** : `TODO`
- **Fichiers** : `resources/views/faq.blade.php` (à créer)
- **Description** : Page statique `/faq` avec accordéon Alpine.js.
  La route sera ajoutée par Claude Code WSL (coordonner pour éviter conflit `routes/web.php`).

### TASK-023 — Vue carte Leaflet pour services onsite
- **Statut** : `TODO`
- **Fichiers** : `resources/views/livewire/explorer.blade.php`
- **Description** : Onglet carte (Leaflet.js CDN) dans l'explorateur.
  Markers sur les services `mode=onsite` avec localisation renseignée.

### TASK-024 — Affichage badges sur le profil
- **Statut** : `TODO` (dépend TASK-015, déjà mergé)
- **Fichiers** : `resources/views/profile/show.blade.php`
- **Description** : Afficher les badges gagnés sur la page profil publique.
  Données disponibles via `$user->badges`.

---

## ✅ DONE

| Tâche | Fusionné |
|---|---|
| Avatar upload + redimensionnement 300x300 | 2026-04-30 |
| Bio + localisation profil | 2026-04-30 |
| Images de service (max 5, 2 Mo, galerie) | 2026-04-30 |
