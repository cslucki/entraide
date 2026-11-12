# TASK-1809 — Simplification finale du parcours d'évaluation (§2-§4) + correctifs écran bento et carte Flux

**12/11/2026, 13h12 Europe/Paris** · branche
`TASK-1809-refonte-premium-des-emails-et-notifications-bouclepro` · PR #808 ·
CI verte au SHA `d41073b7` (Quality Gate + SQLite Regression Gate + tous les
shards)

## 1. GO MASTER — simplification finale du parcours d'évaluation

### §2 — Carte unique

Les deux micro-écrans de T1808 §5 (étoiles PUIS mot) sont fusionnés en UNE
carte compacte `data-entraide-evaluation` : commentaire facultatif, étoiles
sans présélection, UN bouton « Valider mon évaluation », UNE soumission.
Découverte en auditant avant modification : `ReviewController::store()`
acceptait déjà `comment` en plus de `rating` (prévu par T1806 §3.B) — aucun
changement de contrôleur n'a donc été nécessaire, seulement du formulaire.

Retirés du parcours visible, avec leurs routes et contrôleurs : le bouton
« Plus tard » (`reviews.defer`), le renoncement explicite (`reviews.skip`),
l'écran séparé du commentaire (`reviews.comment`/`reviews.comment.skip`).
`ReviewPolicy::complete()` et le gate `complete-review` retirés.
`Transaction::motEnAttente()`/`evaluationDifferee()` retirés. Décision
assumée : les colonnes `evaluation_deferred_at`/`reviews_skipped_at`/
`comment_skipped_at` restent en base (compatibilité historique, T1807 lit
`reviews_skipped_at`) — seules les écritures vivantes sont retirées.

### §3 — Email immédiat à D1

`entraide:invite-to-evaluate` (planifiée à dix minutes après clôture) et sa
classe `EntraideInviteToEvaluate` supprimées. `EvaluationInviteMailer` (même
gabarit `entraide_evaluation_invite`, id 72/73) est désormais appelé
directement depuis `TransactionController::complete()`, juste après les
notifications de clôture, idempotent par la même colonne
`evaluation_invited_at` (garde atomique `whereNull`). Texte mis à jour selon
la copie exacte de MASTER, nouvelle variable `{{ points }}` (migration
`2026_11_12_150000`).

### §4 — Email à D2 à réception d'un avis

Nouveau gabarit Global FR/EN `entraide_review_received` (migration
`2026_11_12_160000`, id 76/77), nouveau service `ReviewReceivedMailer`,
déclenché **uniquement** depuis `EvaluationEntraide::enregistrer()` juste
après `Review::create()` — jamais depuis `ReviewModeration` (vérifié par
test : modifier/masquer n'envoient rien). Étoiles ET chiffre
(`★★★★☆ (4/5)`), commentaire si présent (toujours échappé via `e()`, testé
avec un `<script>` injecté), lien vers la conversation. Idempotent, journalisé,
non bloquant sur échec SMTP.

**Tests §2-§4** : 71 tests (3 fichiers neufs/réécrits, 1 supprimé),
vérifiés SQLite ET PostgreSQL réel, aucune régression sur le cockpit
SuperAdmin T1808 (10 tests). Les deux nouvelles migrations vérifiées
migrate + rollback + re-migrate sur le jumeau PostgreSQL local.

## 2. Correctif Cyril — écran bento allégé, partage automatique, écran de confirmation

Demandé par Cyril pendant que la CI tournait sur §2-§4, ajouté sur la même
branche/PR (son choix explicite, précédent du correctif prénom-aidant plus
tôt dans la session).

- **Ligne de récapitulatif dupliquée retirée** : la phrase
  `cloture_aide_corps`/`cloture_aidant_corps` dit déjà « X min · Y pt
  transférés à/de PRENOM » juste au-dessus ; la seconde ligne
  (`data-entraide-bento-recap`, « durée · points · Aidant : nom ») était une
  pure redite.
- **Partage dans le Flux désormais AUTOMATIQUE.** L'ancien lien explicite
  « Partager cette entraide dans le Flux » rendait une erreur 500 et exigeait
  un clic de plus pour un geste qui devrait être acquis. Retirés :
  `shareInFeed()`/`orgShareInFeed()`, leurs deux routes, et
  `TransactionPolicy::shareInFeed()`. Le partage se déclenche maintenant
  depuis `EvaluationEntraide::enregistrer()`, au moment où l'avis est
  déposé — même émetteur (`FeedEventEmitter`, idempotent), même acteur
  (l'aidant).
- **Nouvel écran « Évaluation effectuée ».** Une fois l'avis déposé, la
  carte cède la place à une confirmation compacte avec un lien direct vers
  l'item du Flux correspondant.
- **Le message de l'aidant change une fois évalué.** Le message fixe
  « 🙏 Merci pour votre aide ! » devient « ⭐ {prénom} vient de vous laisser
  une évaluation », avec la note (étoiles + chiffre) et le commentaire
  affichés directement dans le fil — sans besoin d'ouvrir l'email.
- **Mode sombre éclairci** : palette indigo-900/20 + indigo-300/80 jugée
  trop foncée sur le fond général de l'application ; alignée sur le même
  niveau de contraste déjà utilisé ailleurs dans ce même gabarit
  (bordure/fond un cran plus clairs, texte une nuance plus claire).

Vérifié en navigateur réel (clics réels, pas de données forgées en base) :
clôture → carte d'évaluation (étoiles sans présélection, commentaire
facultatif) → soumission → « Évaluation effectuée » + lien fonctionnel →
côté aidant, message et note visibles dans le fil — en light ET dark mode.

## 3. Correctif Cyril — carte Flux « premium », noms des deux parties

Demande initiale : le nom de la proposition/demande, « entre qui et qui »,
l'évaluation donnée, une icône plus premium — la carte actuelle jugée trop
courte et trop orientée « transaction/troc ».

Point soulevé avant implémentation : « entre qui et qui » impliquait de
nommer le bénéficiaire, ce qui inverse un arbitrage de confidentialité
explicite du 05/11 (gardé par un test dédié T1757 : « seul l'aidant est
nommé, jamais le bénéficiaire »). Question posée à Cyril ; réponse
explicite et motivée : *« Si on nomme les deux, c'est une plateforme de
confiance et ouverte. Et d'ailleurs il faut être connecté et membre accepté.
Donc aucun problème à dire les choses et de façon bienveillante. »*

Implémenté tel quel :

- `FeedEventRenderer` nomme désormais l'**aidant ET l'aidé** (nouveau
  placeholder `:beneficiaire`, même idiome que `:temps` — les six autres
  événements ne le réclament pas).
- L'objet de la ligne devient le **titre** de l'annonce (remplace la seule
  catégorie).
- Une **pièce jointe premium** apparaît quand une évaluation existe :
  l'icône « Entraide » du rail gauche (`config('navigation_icons.exchanges')`
  — réutilisée telle quelle, jamais une icône inventée), les étoiles, le
  chiffre, et le commentaire.
- `TASK1757EntraideDansLeFluxTest` — dont le docbloc et le test de
  confidentialité documentaient explicitement l'ancien arbitrage — réécrits
  pour affirmer et expliquer la décision inverse (« REMPLACE l'arbitrage du
  05/11 », avec la citation de Cyril), plus un nouveau test pour la pièce
  jointe premium.

Vérifié en navigateur réel, light et dark mode : la carte rend
*« D2 compte 2Demo a aidé Demo Admin avec « Moi d2, Je propose de faire ton
logo » (1 min) »* suivie de l'icône, de `★★★★★ (5/5)` et du commentaire
déposé.

## 4. Tests — bilan complet

| Lot | Fichiers | Tests | SQLite | PostgreSQL réel |
|---|---|---|---|---|
| §2-§4 | 3 neufs/réécrits, 1 supprimé | 71 | ✅ | ✅ |
| Correctif écran bento | `TASK1808ParcoursFinalEntraideTest` (+6) | 18 | ✅ | ✅ |
| Correctif carte Flux | `TASK1757EntraideDansLeFluxTest` (+2, réécrit) | 9 | ✅ | ✅ |
| Cockpit SuperAdmin T1808 (non-régression) | `TASK1808ReviewModerationCockpitTest` | 10 | ✅ | ✅ |
| Suite complète locale | — | 777 passés | ✅ (3 échecs préexistants, sans rapport — confirmés identiques hors branche) | — |
| CI PR #808 | — | Quality Gate + SQLite Regression Gate + tous les shards | ✅ | ✅ |

Les deux migrations (`2026_11_12_150000`, `2026_11_12_160000`) vérifiées
migrate + rollback + re-migrate sur le jumeau PostgreSQL local.

## 5. Vérifications navigateur (jumeau, données de test purgées)

Deux transactions de test créées, closes, évaluées et commentées via de
vrais clics (pas d'écriture forgée des états intermédiaires), en light et
dark mode, via impersonation SuperAdmin (« Se connecter sous ») pour
vérifier le côté aidant. Les deux transactions, leurs `Review`, `FeedPost`
et `PointLedger` ont été purgés après vérification ; les soldes de points
des deux comptes de démonstration ont été restaurés à l'identique
(DA : 99, D2 : 32).

## 6. Décisions produit actées cette session

- **Confidentialité du Flux (REMPLACE l'arbitrage du 05/11)** : les deux
  parties d'une entraide sont désormais nommées sur la carte publique.
  Décision explicite de Cyril, motivée par la nature « plateforme de
  confiance, membres connectés et acceptés » de BouclePro.
- **Partage dans le Flux** : devient un geste automatique à l'évaluation,
  plus un geste explicite et distinct.
- **BouclePro n'est pas un troc** : le vocabulaire du Flux évolue vers un
  cadrage pédagogie/entraide plutôt que transactionnel — amorcé ici sur
  l'événement de clôture, à poursuivre si d'autres libellés méritent le même
  traitement.

## 7. Périmètre tenu, aucun dépassement

Aucune nouvelle TASK créée. Tout sur la branche et la PR existantes (#808),
comme demandé. Aucun merge, aucun déploiement.

---

**STATUT : implémentation, tests et rapport livrés. En attente du GO
explicite de MASTER avant tout merge ou déploiement.**
