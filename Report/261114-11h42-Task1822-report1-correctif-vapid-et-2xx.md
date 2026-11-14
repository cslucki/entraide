# T1822 — Rapport technique : réconciliation VAPID et erreurs fournisseur 2xx

**Date** : 14/11/2026, 11h42 (Paris) · **Branche** : `TASK-1822-securiser-la-reconciliation-vapid-et-les-erreurs-fournisseur-2xx` · **Niveau** : SENSITIVE · **VERSION** : 1.822

---

## 1. Ce qui était cassé, en une phrase

La parade de T1740 contre les abonnements à clé périmée — la colonne `vapid_key_hash` — était **effacée par notre propre code** : `revendiquer()` la réécrivait à chaque réconciliation. Et le chemin qui déclenchait le plus souvent cette réécriture, `push-first-run.js`, était précisément celui qui ne vérifiait jamais la clé.

---

## 2. Séquence 1 — la garde VAPID, côté navigateur

### Ce qui a été centralisé (`push-abonnement.js`)

| Export | Rôle |
|---|---|
| `clePubliqueDe(abonnement)` | la clé RÉELLE en base64url, ou `null` |
| `comparerCleVapid(abonnement, vapid)` | `concorde` \| `diverge` \| `inobservable` |
| `CLE_CONCORDE` / `CLE_DIVERGE` / `CLE_INOBSERVABLE` | les trois états, nommés |
| `retirerEtDesabonner(url, abonnement)` | serveur d'abord, navigateur ensuite (ordre T1739) |

**Trois réponses, jamais deux.** `inobservable` est un état à part entière : un navigateur qui n'expose pas `options.applicationServerKey` ne permet pas de conclure, et on ne détruit pas un abonnement qui fonctionne peut-être. Affirmer une concordance non mesurée est exactement ce qui a laissé passer le défaut.

### Les trois appelants

- **`push-first-run.js`** — le correctif central. La comparaison intervient **avant** `dire('deja-abonne')` et **avant** `confirmer()`. Sur `diverge` : aucune reconfirmation, aucune déclaration « déjà abonné », et la proposition revient avec son propre texte — « Réactiver les notifications », pas « Activer ». Dire « activez » à quelqu'un qui les croyait actives depuis des semaines ne lui apprendrait rien.
- **`push-abonnement.js::abonner()`** — réutilisait l'abonnement existant tel quel. Il le **renouvelle** désormais quand la clé diverge (retrait serveur, `unsubscribe()`, `subscribe()` avec la clé courante). Les autres appareils de la personne ne sont jamais touchés : le serveur filtre par `endpoint_hash` ET `user_id`.
- **`push-device.js`** — contrôle existant conservé, mais il devient **appelant**. Trois duplications supprimées : sa propre `enBase64Url`, son `cleDiverge`, et une troisième copie de la conversion dans le panneau de diagnostic.

Deux clés de langue ajoutées (fr/en) : `pwa.first_run_stale_key_body`, `pwa.first_run_stale_key_action`.

---

## 3. Séquence 2 — intégrité serveur

**Le correctif tient en une ligne**, et c'est voulu :

```php
// avant
$colonnesAMettreAJour = ['user_id','endpoint','p256dh','auth','last_seen_at','updated_at','created_env','vapid_key_hash'];
// après
$colonnesAMettreAJour = ['user_id','endpoint','p256dh','auth','last_seen_at','updated_at'];
```

`created_env` et `vapid_key_hash` décrivent la **création** : elles ne sont plus écrites qu'à l'INSERT. Ce qui reste mis à jour l'est pour une raison précise — `user_id` porte la bascule entre comptes frères (CDC 5.2), `p256dh`/`auth` peuvent être renégociées, `last_seen_at` est ce que la réconciliation vient dire.

**Le chemin de repli était un piège.** Le `catch (UniqueConstraintViolationException)` poussait tout `$valeurs`, métadonnées comprises : une ceinture qui défait la règle que la bretelle vient de poser n'en est pas une. Les deux chemins partagent désormais la même liste (`array_intersect_key`).

**Un renouvellement n'a besoin d'aucune mise à jour** : il change d'endpoint, donc crée une nouvelle ligne, stampée avec la clé en vigueur. Testé.

**Lignes historiques** : celles dont le hash est `null` ou a déjà été réécrit gardent leur valeur. **Aucune clé d'origine n'est reconstituée** — une valeur inventée serait une affirmation sans mesure, exactement ce que ce correctif combat.

**`PushSubscriptionController`** : trois pistes pesées, documentées dans son docblock. (1) Faire déclarer la clé par le client — rejeté, une déclaration n'est pas une preuve. (2) La déclarer pour écarter seulement — défendable mais exige une colonne, donc une migration, hors mandat. (3) Rendre les métadonnées immuables — **retenu**. Le contrôle cryptographique reste côté client, seul endroit possible ; sa trace ne peut plus être écrasée. Le contrôleur n'accepte toujours aucune donnée de clé.

---

## 4. Séquence 3 — réponse fournisseur

`deposer()` rendait `ok` sur tout 2xx. Le corps est désormais lu par `verdictSurCorps2xx()`.

| Corps d'une réponse 2xx | Verdict | Suppression ? |
|---|---|---|
| vide (Apple, FCM `/wp/`, Mozilla) | `ok` | non |
| JSON sans `failure` ni `results` | `ok` | non |
| `failure: 0`, résultats sans `error` | `ok` | non |
| non-JSON / illisible | `ok` | non |
| `error: NotRegistered` | **`revoque`** | **oui** |
| `error: InvalidRegistration` | **`revoque`** | **oui** |
| `error: MismatchSenderId` | **`echec`** | **non** |
| `error: Unavailable`, `InternalServerError`, inconnue | **`echec`** | **non** |

**Pourquoi `MismatchSenderId` ne supprime pas.** Il dit « l'envoi vient d'une autre identité VAPID », pas « l'appareil a disparu ». Supprimer sur ce motif détruirait un abonnement vivant à cause d'un problème de clé — c'est-à-dire le défaut même que cette TASK corrige, retourné en perte de données. Seules les deux erreurs qui établissent qu'un enregistrement n'existe plus déclenchent la suppression, exactement comme les statuts 404/410.

**Un corps illisible ne vaut pas un échec** : inventer un échec sur une forme inattendue casserait des fournisseurs qui fonctionnent.

**Nouveau diagnostic `push_provider_error`.** `push_service_unreachable` disait « le fournisseur n'a pas répondu » ; il couvrait donc à tort « il a répondu, et il a dit non ». Les deux appellent des gestes différents, et un diagnostic qui les confond envoie chercher au mauvais endroit.

---

## 5. Tests

**16 tests T1822**, tous verts. **670 verts** sur `Push|Notification|PWA`, 0 échec.

| Cas exigé par MASTER | Test |
|---|---|
| clé valide | `test_un_endpoint_renouvele_cree_une_nouvelle_ligne_stampee_avec_la_clef_courante` |
| clé périmée | `test_la_reconciliation_preserve_l_environnement_et_l_empreinte_de_creation` |
| clé non observable | *(côté JS — voir §6)* |
| réconciliation depuis la PWA | *(côté JS — voir §6)* |
| abonnements antérieurs au correctif | `test_un_abonnement_sans_empreinte_n_en_recoit_aucune_a_la_reconciliation` |
| changement de compte | `test_la_bascule_entre_comptes_freres_fonctionne_toujours_et_preserve_la_creation` |
| préservation des métadonnées | les deux ci-dessus + `..._les_clefs_de_chiffrement_restent_mises_a_jour` |
| FCM 2xx normal | `test_un_2xx_au_corps_vide...`, `test_un_2xx_json_sans_erreur...` |
| FCM 2xx avec erreur | `test_un_2xx_porteur_d_une_erreur_fournisseur...`, `..._NotRegistered...`, `..._MismatchSenderId...` |
| FCM 2xx malformé | `test_un_corps_2xx_malforme...`, `test_un_corps_2xx_json_inattendu...` |
| non-régression multi-Organization | `test_un_destinataire_sorti_de_l_organization_ne_recoit_rien`, `test_l_appareil_d_un_autre_compte_n_est_jamais_servi` |
| aucun envoi Push réel | `Http::fake()` partout ; `Http::assertNothingSent()` sur les cas de refus |

`test_DEFAUT_CONNU_...` est **retiré de T1821** : il est devenu rouge dès le correctif appliqué, comme prévu. Un commentaire le remplace à sa place exacte — sans lui, la disparition d'un test nommé « DEFAUT_CONNU » se lirait comme un escamotage.

---

## 6. Risques résiduels

1. **La garde navigateur n'est pas couverte par la CI.** Le dépôt n'a aucun banc de test JS unitaire (ni vitest ni jest). `push-first-run.js` et `push-abonnement.js` ne sont prouvés que par recette navigateur réelle. Vérifié en local : build Vite OK, **aucune erreur console** sur `/org/main/notifications/preferences` après rechargement. **La recette Android via tunnel reste à faire.**

2. **Les abonnements déjà re-stampés ne sont pas récupérables.** Ceux dont le hash a été réécrit avant ce correctif portent la clé du dernier contact. On ne leur invente rien. S'ils sont réellement divergents, c'est désormais la garde CLIENT qui les rattrapera au premier lancement — en proposant une réactivation.

3. **Le stamp de création reste une présomption, pas une preuve.** Il dit « la clé que le serveur servait quand la ligne a été créée ». C'est très probablement celle que le navigateur a utilisée, puisque c'est elle que la page lui a servie — mais le serveur ne reçoit jamais la clé réelle, et ne doit pas.

4. **Les erreurs FCM non listées** (`MessageTooBig`, `TopicsMessageRateExceeded`, …) tombent en `echec` sans suppression. C'est le repli prudent voulu ; si un motif supplémentaire devait révoquer, il faudra le documenter avant de l'ajouter.

5. **La réception effective reste non observable.** Rien dans T1822 ne la prouve, et rien ne le prétend. L'écart entre « acceptation fournisseur » et « affichage Android » demeure — c'est le sujet d'une éventuelle TASK d'instrumentation (champs listés au §5 du rapport T1821).

---

## 7. Contraintes respectées

Aucune modification PROD. Aucun changement de clé VAPID. Aucune suppression d'abonnement réel. **Aucune migration.** Aucun refactor hors périmètre — les seules suppressions de code sont les duplications de la règle corrigée. Pas de release.
