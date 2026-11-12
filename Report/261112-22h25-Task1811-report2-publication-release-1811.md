# TASK-1811 — Publication de la release PROD 1.811 — rapport 2

**Date** : 2026-11-12 22h25 Europe/Paris
**Auteur** : OPUS (Claude Opus 5)
**Objet** : exécution de la publication autorisée par MASTER (GO conditionnel du 12/11)

> **Publication RÉUSSIE.** PR #811 `MERGED`, déploiement Laravel Cloud
> **`succeeded`** en 59 s, VERSION **1.811** servie en production, aucune
> erreur applicative, aucun 5xx. **Les contrôles au niveau base de données
> restent à confirmer par Cyril** (§5) : mon accès lecture à la base PROD est
> refusé par le classifieur. Les quatre réserves acceptées restent ouvertes.

---

## 1. Condition R1 — satisfaite immédiatement avant publication

| | |
|---|---|
| **Résultat** | **`transactions_avec_doublons = 0`** |
| Exécuté par | **Cyril**, depuis WSL |
| Moyen | **`cloud tinker`**, CLI officielle Laravel Cloud |
| Environnement | `main` — **`env-a1b31444-4208-4d13-b958-289fa7fba353`** |
| Quand | Immédiatement avant la publication, le 12/11 |

Commande exacte transmise par Cyril :

```
cloud tinker env-a1b31444-4208-4d13-b958-289fa7fba353 --code "print_r(DB::select('SELECT COUNT(*) AS transactions_avec_doublons FROM (SELECT transaction_id FROM reviews GROUP BY transaction_id HAVING COUNT(*) > 1) AS doublons'));"
```

La provenance est vérifiée : CLI Laravel Cloud, environnement `main`, celui dont
le déploiement courant était `632e6b3b`. La bascule `unique('transaction_id')`
ne pouvait donc pas échouer.

---

## 2. Préflight immédiat — toutes préconditions vertes

| Contrôle | Attendu | Mesuré |
|---|---|---|
| PR | `release-1.811` → `main` | **#811** conforme |
| SHA du candidat | `06791d0c…` | **`06791d0c10176c2813ce8573c1b725f9c04df94a`** — conforme |
| `origin/release-1.811` = `origin/develop` | égalité | **égaux**, aucun commit imprévu |
| VERSION du candidat | 1.811 | **1.811** |
| CI | 15 pass + 1 skip | **15 pass + 1 skip** |
| Gates bloquantes | 2 vertes | `Quality Gate` **pass**, `SQLite Regression Gate` **pass** |
| Conflit de fusion | aucun | `mergeable: MERGEABLE`, `mergeStateStatus: CLEAN` |
| Déploiement Cloud concurrent | 0 | **0** (critère : absence de `finishedAt`) |
| PITR | 7 jours | **`retention_days: 7`**, cluster `available` |

### 🔖 Horodatage de référence pour restauration PITR

**`2026-11-12T21:15:06Z`** (22h15:06 Paris) — relevé juste avant la fusion.
C'est la cible à utiliser pour une restauration à un point dans le temps.

---

## 3. Publication

| Étape | Résultat |
|---|---|
| DRAFT → READY | `gh pr ready 811` → `isDraft: false`, `CLEAN`, head inchangé |
| Revérification des checks | 15 pass + 1 skip |
| Fusion | `gh pr merge 811 --merge` — **merge commit**, ni squash ni rebase |
| **Confirmation GitHub** | **`state = MERGED`**, base `main`, `mergedAt` **2026-11-12T21:16:23Z** |
| **SHA du merge commit** | **`1c2f4a07712c98e3183faeb29082af392bdb0939`** |
| `origin/main` après fusion | `1c2f4a0771…` — VERSION **1.811** |

### Déploiement automatique

Aucune seconde procédure manuelle n'a été lancée : la fusion a suffi.

```
depl-a335e1eb-db74-4a55-99f6-e67bde760375
commit    1c2f4a07712c98e3183faeb29082af392bdb0939
start     2026-11-12T21:16:29Z   (6 s après la fusion)
finish    2026-11-12T21:17:28Z
duree     59 s
status    deployment.succeeded
failureReason  null
PHP 8.5
```

Enchaînement observé : `build.running` → `deployment.running` → **`deployment.succeeded`**.

### Résultat de `php artisan migrate --force`

Le `deployCommand` est `php artisan migrate --force` et le déploiement s'est
terminé en **`deployment.succeeded` avec `failureReason: null`**. Une migration
en échec aurait fait échouer le déploiement — ce n'est pas arrivé.

**Ce que cela n'établit pas** : le détail migration par migration. Le nombre de
lignes de `migrations` et l'état du schéma restent à confirmer par la requête du
§5. **Je ne l'affirme pas sur la seule foi du statut de déploiement.**

---

## 4. Contrôles post-déploiement réalisés

### Surfaces publiques — anonyme, aucune donnée touchée

| Contrôle | Résultat |
|---|---|
| `https://bouclepro.com/` | **302** (redirection login, normal en anonyme) |
| **VERSION en page** | **`1.811`** présente dans `/login` |
| `/login`, `/register`, `/mentions-legales`, `/open-source` | **200** |
| `/messages` | **302** — garde d'authentification active |
| **`/site.webmanifest`** | **`name = BouclePro`**, `short_name = BouclePro`, **4 icônes** |
| En-têtes | `x-frame-options: deny`, `x-content-type-options: nosniff` |

### Administration — gardes vérifiées en anonyme

| URI | Code |
|---|---|
| `/admin/reviews` — **cockpit neuf T1808** | **302** vers login |
| `/admin/flux` | **302** |
| `/admin/email-bulksending` — **outil neuf T1803** | **302** |
| `/admin/system-email-templates` | **302** |

Les quatre surfaces nouvelles ou sensibles refusent l'accès anonyme. Le droit
exigé est `is_admin` (`AdminMiddleware`), un drapeau **de plateforme**.

**Note de méthode** : mes premiers essais sur `/feed` et `/admin` ont renvoyé
404. Vérification faite, **aucune route n'a pour URI exactement `/feed` ni
`/admin`** — les URI réelles sont `/admin/flux` et des chemins scopés par
Organization. Ces 404 étaient mes propres erreurs de test, pas une régression.

### Logs applicatifs après déploiement

Fenêtre postérieure à `21:17:28Z`, 53 entrées :

```
niveaux  : info 51, warning 2
types    : access 45, system 5, application 3
HTTP     : 200 × 34, 302 × 9, 404 × 2
5xx      : AUCUN
erreurs applicatives : AUCUNE
```

Les **2 seuls `warning`** sont `GET /feed 404` et `GET /admin 404` — **mes
propres requêtes de test erronées**. Les entrées `system` sont le drain normal
de l'ancien déploiement (`App cluster shutdown drain`, `Deploy: 147`), toutes en
`info`.

### Trafic membre réel sur la nouvelle version

Observé en lecture de logs, **sans aucune action de ma part** :
`/org/ofsh/explorer`, `/org/ofsh/requests/create`, `/org/ofsh/membres`,
`/org/ofsh/agenda`, `/login`, `/site.webmanifest`, `/sw.js` — **tous en 200**.
Des membres réels utilisent l'application sur 1.811 sans erreur.

`/notifications/push` apparaît **5 fois en 200** : des abonnements Web Push sont
bien actifs en production. Cela **confirme R4** — `WEB_PUSH_ENABLED` et les
`VAPID_*` ne sont pas seulement présentes, le canal est vivant.

**Aucun message, email ni test n'a été envoyé à un membre réel.**

---

## 5. Contrôles base de données — À CONFIRMER PAR CYRIL

Mon accès en lecture à la base PROD reste **refusé par le classifieur** (trois
mécanismes distincts, dont la commande officielle `cloud db:open`). Je n'ai
tenté aucun contournement. **Les cinq contrôles suivants ne sont donc PAS
vérifiés** : les 15 migrations appliquées, l'intégrité des données et des
soldes, l'index unique des évaluations, les nouveaux gabarits email, la
volumétrie.

Une seule commande, **strictement en lecture**, les couvre tous :

```
/home/cyril/.config/composer/vendor/bin/cloud tinker env-a1b31444-4208-4d13-b958-289fa7fba353 --code "print_r(DB::select(\"SELECT (SELECT count(*) FROM migrations) AS migrations, (SELECT max(batch) FROM migrations) AS batch_max, (SELECT count(*) FROM migrations WHERE batch=(SELECT max(batch) FROM migrations)) AS dans_dernier_batch, (SELECT count(*) FROM information_schema.columns WHERE (table_name='transactions' AND column_name IN ('reviews_skipped_at','seller_celebrated_at','evaluation_invited_at','evaluation_deferred_at')) OR (table_name='reviews' AND column_name IN ('comment_skipped_at','hidden_at','deleted_at','moderated_by','moderation_reason'))) AS colonnes_9, (SELECT count(*) FROM information_schema.tables WHERE table_name IN ('bulk_email_sends','bulk_email_send_recipients','review_moderation_logs')) AS tables_3, (SELECT count(*) FROM pg_indexes WHERE tablename='reviews' AND indexname='reviews_transaction_id_unique') AS index_unique_ok, (SELECT count(*) FROM system_email_templates) AS gabarits, (SELECT count(*) FROM system_email_templates WHERE slug IN ('private_message_received','loop_mention_all_created','loop_event_created','entraide_evaluation_invite','entraide_review_received','feed_post_comment_created')) AS gabarits_neufs, (SELECT count(*) FROM users) AS users, (SELECT count(*) FROM organizations) AS orgs, (SELECT count(*) FROM transactions) AS transactions, (SELECT coalesce(sum(points_balance),0) FROM users) AS somme_points, (SELECT count(*) FROM reviews) AS reviews\"));"
```

Cette requête a été **testée sur ma copie locale migrée** avant de t'être
transmise, pour ne pas t'en donner une fausse.

### Valeurs attendues

| Champ | Attendu en PROD | Pourquoi |
|---|---|---|
| `migrations` | **365** | 350 avant + 15 |
| `batch_max` | **43** | nouveau lot |
| `dans_dernier_batch` | **15** | les 15 de la release, en un lot |
| `colonnes_9` | **9** | 4 sur `transactions` + 5 sur `reviews` |
| `tables_3` | **3** | `bulk_email_sends`, `…_recipients`, `review_moderation_logs` |
| `index_unique_ok` | **1** | `reviews_transaction_id_unique` présent |
| `gabarits` | **50** | 38 avant + 12 |
| `gabarits_neufs` | **12** | 6 slugs × 2 locales |
| `users`, `orgs`, `transactions`, `somme_points` | **≥ aux valeurs d'avant**, jamais inférieures | `orgs` doit valoir **4** ; les autres ont pu croître avec l'activité réelle depuis le 09/11 |
| `reviews` | **0 ou plus**, et **aucun doublon** | R1 valait 0 avant la fusion |

**Toute valeur inférieure à l'attendu sur `users`, `orgs`, `transactions` ou
`somme_points` est un signal d'incident** : il faut alors STOP, me le
transmettre, et ne lancer aucune correction sans décision humaine.

---

## 6. Anomalies

**Aucune anomalie de production.**

Deux points de méthode, imputables à mes propres mesures et non au système :

1. Mes requêtes de test sur `/feed` et `/admin` ont produit les 2 seuls
   `warning` des logs post-déploiement. Ces URI n'existent pas ; les vraies
   sont `/admin/flux` et des chemins scopés par Organization.
2. Un premier filtre sur `cloud deployment:list` avait annoncé « 4 déploiements
   en cours ». Faux positif : l'API mélange les statuts `deployment.failed` et
   `failed` sans préfixe sur 147 déploiements historiques. Le bon critère est
   l'absence de `finishedAt` — **0 déploiement concurrent**, vérifié.

---

## 7. Réserves toujours ouvertes

### Les quatre réserves acceptées par Cyril — toujours ouvertes

Acceptées **en connaissance de cause**, jamais présentées comme des tests
réussis :

1. **Parcours navigateur authentifiés — NON validés.** Aucun mot de passe de
   compte QA n'est documenté, et je n'ai pas testé de parcours authentifié en
   production. Le trafic membre réel en 200 observé au §4 est un **indice
   favorable, pas une validation** : je n'ai contrôlé ni le contenu des pages,
   ni le parcours clôture → transfert → évaluation → email → Flux.
2. **Recette mobile réelle — NON faite.** Pluie de cœurs, carte d'évaluation
   compacte sur petit écran, mode sombre, PWA installée.
3. **Domaine pgvector/RAG — NON vérifié.** Absent de la copie restaurée faute
   de droit superuser. Aucun fichier de la release ne le touche, mais rien n'y a
   été testé.
4. **Valeurs de configuration Cloud — NON vérifiées.** `QUEUE_CONNECTION`,
   SMTP et stockage restent masqués. `WEB_PUSH_ENABLED` est en revanche
   **désormais confirmé actif** par les logs (§4) : c'est le seul des quatre
   points à avoir bougé, et dans le sens d'une activation réelle.

### Réserve neuve, ouverte par cette publication

5. **Les contrôles base de données du §5 ne sont pas faits.** Les 15 migrations,
   l'intégrité des soldes, l'index unique et les gabarits sont **attendus** mais
   **non mesurés**. Tant que la requête du §5 n'a pas été exécutée, la
   publication est « réussie au niveau déploiement et surfaces », pas
   « vérifiée au niveau données ».

### Points de suivi

- **R3 — `events.create`** : un membre simple ne peut plus proposer de
  rencontre. Changement voulu, mais **visible pour les membres existants** : un
  retour utilisateur est à anticiper. `locked => false` permet de rouvrir le
  droit par Organization sans déploiement.
- **R5 — 4 FK neuves vers `users`** à déclarer au registre de cycle de vie,
  hors release.
- **Rollback** : `migrate:rollback`, `migrate:fresh` et `migrate:reset` sont
  **interdits sur la PROD** — ils effaceraient `review_moderation_logs`,
  l'historique `bulk_email_sends` et les marqueurs de parcours. Voie de
  référence en cas de régression : **PR de revert sur `main`, base conservée**.
  Dernier recours : PITR sur **`2026-11-12T21:15:06Z`**, qui crée un **nouveau
  cluster**. **Cyril conserve l'autorité sur toute décision de restauration.**

---

## 8. Récapitulatif

| | |
|---|---|
| PR | **#811**, `state = MERGED`, base `main` |
| SHA fusionné | **`1c2f4a07712c98e3183faeb29082af392bdb0939`** |
| VERSION déployée | **1.811** (confirmée en page) |
| Laravel Cloud | **`deployment.succeeded`**, 59 s, `failureReason: null` |
| Migrations | déploiement réussi avec `migrate --force` ; **détail à confirmer (§5)** |
| Contrôles fonctionnels | surfaces publiques et gardes d'administration **OK** ; parcours authentifiés **non testés** |
| Logs | **0 erreur, 0 5xx** ; 2 warnings = mes propres tests |
| Anomalies | **aucune** |
| Référence PITR | **`2026-11-12T21:15:06Z`** |
