# Release 1.831 — PUBLIEE le 15/11/2026, 10h20 Europe/Paris

## Résumé

PR #833 (`release-1.830 → main`) fusionnée avec GO MASTER explicite, après correction du flake calendaire bloquant (TASK-1831). `main` est désormais à `c3545c48`, VERSION **1.831**. Déploiement Laravel Cloud automatique (push-to-deploy) réussi.

## Contenu de la release

T1823 → T1830 (voir PR #833 pour le détail par tâche) + **T1831** (correctif de test, découvert pendant le préflight) :

- T1823 diagnostic Web Push admin · T1824 fiabiliser changement de mot de passe + page Sécurité · T1825 confirmation premium · T1826 aperçus premium ChatLoop · T1827 nav Articles↔Boucles · T1828 nav ChatLoop→Dossiers
- **T1829/T1830 — P0 notifications asynchrones** : prérequis + routage conditionnel vers une Managed Queue Cloud, **dormant en PROD** (`NOTIFICATIONS_MANAGED_QUEUE_CONNECTION` absent de l'environnement), montée Laravel **13.7.0 → 13.42.0** + `aws/aws-sdk-php`
- **T1831** : `TASK1802AgendaCalendarViewTest::test_clicking_a_calendar_event_opens_its_detail_popup` échouait tout samedi/dimanche réel (grille semaine lundi-dimanche + `now()->addDay()` non figé) — confirmé préexistant sur `main` 1.822 avant correction, aucun lien avec T1823-T1830. Horloge figée sur ce seul test.

0 migration dans tout le lot.

## Préflight et CI (voir PR #833 pour le détail complet)

- Diff réel `main → develop` : 73 fichiers (+6897/−576), 0 migration.
- Isolation Organization, uploads d'images, routage notifications : re-vérifiés sains à neuf (SQLite **et** PostgreSQL réel) sur la branche de release.
- CI complète de la PR de release (après intégration de T1831) : Feature (6/6), SQLite (4/4), Unit (PostgreSQL), **Quality Gate (blocking)**, **SQLite Regression Gate (blocking)** — tous verts.

## Déploiement

- PR #833 mergée (`gh pr merge --merge`) : merge commit `c3545c4814a347b673277609b23cc164c83be10a`.
- Déploiement Laravel Cloud `depl-a33b0013-ce5c-446e-8076-0c30e934d9fc` : **deployment.succeeded**, démarré 10:19:57 UTC, terminé 10:20:59 UTC (~62 s — même profil que les releases précédentes).
- Cutover propre dans les logs : `[Deploy: 151] App cluster starting...` à 10:20:57 UTC, `[Deploy: 150]` drainé puis arrêté (`shutdown drain, still accepting requests`, 5 s) — aucune requête perdue observée.
- `bouclepro.com` : HTTP 200, **VERSION 1.831 confirmée en page** (lecture seule).
- Laravel 13.42 confirmé par construction : le build a réussi sur le `composer.lock` qui fixe `laravel/framework` à `v13.42.0` (vérifié dans le diff avant merge) ; aucune intervention PROD supplémentaire (pas de `tinker` distant) pour re-vérifier la version installée.

## Logs applicatifs post-déploiement

- Fenêtre de 20 minutes couvrant le cutover : **0 erreur 5xx, 0 exception**. Les seules entrées `level: error` sont du bruit nginx habituel (scan de bots sur `.env*`, `backup.sql`, etc., 403/404) — aucune n'est applicative.
- Trafic réel observé avant ET après le cutover sans discontinuité : `GET /org/main 200`, `POST /livewire-*/update 200`, `GET /org/.../login 200`, `POST /org/.../logout 302` — fonctions critiques (session, Livewire, auth) opérationnelles en vif.
- Fenêtre des 5 dernières minutes au moment du contrôle : uniquement 200/302, 0 erreur.

## Conformité au mandat

- Aucune Managed Queue créée, aucun Scheduler activé, `QUEUE_CONNECTION`/`NOTIFICATIONS_MANAGED_QUEUE_CONNECTION` non modifiés (confirmé absent de la liste des variables d'environnement Cloud).
- Aucune écriture PROD au-delà du déploiement lui-même (git state, déploiement, logs — tout en lecture).
- STOP respecté : aucune suite donnée après validation de la release.

## Reste à faire (hors session, repris de 1.822)

- Recette fonctionnelle authentifiée non faite, pgvector/RAG non vérifié, recette Android réelle « Mes appareils Push » (T1820).
- Activation future des notifications asynchrones (Managed Queue réelle + Scheduler + drapeau) : opération **distincte**, avec Cyril au tableau de bord Laravel Cloud, jamais en autonomie.
