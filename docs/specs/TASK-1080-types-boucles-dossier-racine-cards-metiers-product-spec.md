# TASK-1080 — Product Spec : types de Boucles, Dossier racine et Cards métiers

> **Statut** : SPEC VALIDÉE (produit). Document **normatif**, aucun code, aucune migration, aucune implémentation livrés par cette tâche.
> **Validee** le 2026-08-05 par le fondateur, sur la base des checkpoints CP1 et CP2 de TASK-1080.
> **Portée** : ce document est la référence pour toutes les tâches d'implémentation qui suivront. En cas de divergence avec le TASK file de TASK-1080, **ce document fait foi**.
> **Doctrine de référence** : `docs/product/BOUCLE_ARCHITECTURE.md`, dont la section « Doctrine Loop / Type / Card » a été amendée par cette même tâche.
> **Ce document ne refait pas l'audit.** Les investigations figurent dans le TASK file de TASK-1080 ; seules les décisions finales sont reprises ici.

---

## 1. Doctrine Loop / Type / Card

`Loop` reste le **modèle fondamental unique**. Le type ne crée aucune frontière d'architecture, de stockage ou de sécurité.

**Un type est un preset déclaratif portant quatre dimensions, et rien d'autre :**

| Dimension | Contenu |
|---|---|
| Composition | le socle de Cards, appliqué à la création et lors d'un changement de type |
| Présentation | les libellés que le type donne aux composants génériques, au premier rang le document racine |
| Comportement par défaut | les réglages proposés, notamment `conversation_mode` |
| Permissions | ses **différences** par rapport au socle défini par rôle |

**Règles non négociables :**

* un type ne crée aucune table, aucun modèle, aucune route ;
* toute donnée d'une Boucle appartient à `loops` et à ses tables satellites, quel que soit son type ;
* retirer un type d'une Boucle ne rend jamais une donnée inaccessible ;
* **le type est lu, jamais branché** — aucun `match ($loop->type)` hors de `LoopTypeRegistry` ;
* une Card qui doit varier selon le type expose un **réglage**, elle ne porte pas la condition.

**Cards :**

* une **Card** est un composant réutilisable, autonome, utilisable par plusieurs types ;
* une **Card métier** peut être centrale dans un type sans lui appartenir ;
* une **Card transversale** n'a pas de type de prédilection : Membres, Drive, Méthode, Sondage, Événements ;
* **ChatLoop n'est pas une Card** — voir §2.

**Personnalisation locale.** Une Card ajoutée ou désactivée dans une Boucle survit à toute évolution du socle de son type.

---

## 2. ChatLoop commun et modes de conversation

ChatLoop est la **surface centrale de toute Boucle**. Il n'est jamais désactivable et ne figure jamais au catalogue des Cards : une Boucle sans conversation n'existe pas.

L'implémentation actuelle le traite déjà ainsi — les permissions du module `chatloop` ne portent **aucun** `requires_card`.

**`conversation_mode` est une propriété du type**, surchargeable par Boucle :

| Mode | Comportement | Types visés |
|---|---|---|
| `stream` | flux continu, chronologique | Dialogue, Coaching, Pair-Aidance |
| `threads` | chaque message racine ouvre un fil ; la vue liste les fils | Atelier 10 pour 1, Recherche |
| `hybrid` | flux continu, un message peut être promu en fil suivi | Projets, Formation |

Le stockage existant suffit à un premier `threads` : `loop_messages.reply_to_id` et `pinned_at` existent. Ce qui manque est la présentation et deux compteurs dérivés. **Aucune migration n'est requise** pour un premier mode `threads` fonctionnel.

La surcharge par Boucle prend la forme d'une valeur nullable, `null` signifiant « suivre le type » — même principe d'épargne que `loop_type_settings` et `organizations.loop_permissions`.

**Indépendance technologique.** `conversation_mode` est une propriété produit, sans lien avec Redis, WebSocket ou tout transport temps réel. Un futur transport changera la latence, jamais le modèle.

---

## 3. Propagation additive déterministe

**Pas de versionnement de preset.** Décision arrêtée.

**Aucun write-on-read.** Ouvrir une Boucle ne déclenche aucune écriture.

| Origine du changement | Déclencheur | Portée |
|---|---|---|
| Administration — modification d'un socle sur `/admin/loop-types` | synchronisation additive **immédiate**, dans la transaction de l'enregistrement | les Boucles du type modifié |
| Configuration — évolution de `config/loop_types.php` par déploiement | **commande explicite** `loops:sync-presets [--type=] [--dry-run]` | au choix de l'opérateur |
| Ouverture d'une Boucle | **aucun effet** | — |

Les deux entrées appellent le même service. `--dry-run` répond « ce que cela ajouterait, et à combien de Boucles », sans rien écrire.

L'écran d'administration annonce l'effet **avant** l'enregistrement. Rien n'est silencieux.

### Règles de propagation

| Évolution du type | Effet sur les Boucles existantes |
|---|---|
| Ajout d'une Card au socle | automatique, additif, idempotent |
| Changement de libellé | immédiat |
| Changement de permission | immédiat |
| Amélioration d'une Card | immédiate |
| Retrait d'une Card du socle | **jamais automatique** |
| Contenu existant | **jamais supprimé** |
| Personnalisation locale | **préservée** |
| Migration destructive | **interdite sans tâche dédiée** |

### Trois états d'une Card dans une Boucle

| État | Représentation | Effet d'une synchronisation |
|---|---|---|
| Jamais ajoutée | aucune ligne dans `loop_cards` | ajoutée si le socle la prescrit |
| Ajoutée et active | ligne, `enabled = true` | inchangée |
| **Désactivée localement** | ligne, `enabled = false` | **inchangée — jamais réactivée** |

Cette garantie est acquise par construction : `LoopTypeRegistry::applyPreset()` compare les clés **sans filtrer sur `enabled`**. Elle doit être couverte par un test de garde.

**Lacune connue** : la permission `loops.manage_cards` est déclarée sans consommateur. Aucune interface ne permet aujourd'hui de désactiver une Card localement. L'état est représentable et respecté par le moteur, mais inatteignable par le produit. À construire avec l'écran de composition d'une Boucle.

---

## 4. Dossier racine

**Toute Boucle possède un Dossier racine.**

**Modèle retenu** : `dossiers.loop_id` nullable **et** `dossiers.owner_id` nullable. Un Dossier appartient **exactement** à un utilisateur **ou** à une Boucle.

**Ce qui en découle :**

* les droits suivent le porteur — `loop_members` quand `loop_id` est renseigné ;
* `dossier_members` reste **vide** pour un Dossier de Boucle : l'adhésion est celle de la Boucle, jamais dupliquée ;
* `Dossier::syncVisibility()`, qui dérive la visibilité de la présence de membres, **ne s'exécute pas** sur un Dossier de Boucle — sa visibilité est celle de la Boucle ;
* le Dossier de Boucle bénéficie sans modification de l'upload, du quota d'Organization, de l'indexation RAG (`dossier_chunks`) et de l'écran Dossiers existants.

**Cycle de vie :**

* archiver une Boucle ne fait **rien** au Dossier ;
* supprimer une Boucle soft-delete son Dossier racine, **jamais** ne purge les fichiers.

**Dépôt de fichiers** : ouvert aux **membres actifs** de la Boucle. Le quota d'Organization protège de l'abus.

---

## 5. Document racine

**Chaque Dossier racine possède un document racine**, utilisant le moteur Article/Blog existant.

**Modèle retenu** : `dossiers.root_blog_post_id`, avec invariante d'appartenance au même Dossier.

Ce motif n'est pas une invention : `article_series.root_blog_post_id` l'applique déjà aux Séries, avec unicité et vérification d'appartenance au conteneur (`DossierSeriesController::ensureBlogPostBelongsToDossier()`).

**Le concept technique est générique. Seul son libellé dépend du type :**

| Type | Libellé |
|---|---|
| Dialogue | Cadre du dialogue |
| Projets | Manifeste, ou Charte du projet |
| Atelier 10 pour 1 | Situation accompagnée |
| Formation | Programme, ou Cadre pédagogique |
| Recherche | Question de recherche |
| Coaching | Cadre d'accompagnement |
| Pair-Aidance | Cadre de confiance |

**Le mot « Manifeste » cesse d'être imposé.** Il devient le libellé que le type Projets donne au document racine.

### Contrainte documentaire réelle

Un article ne peut être rattaché qu'à **un seul Dossier** (`dossier_blog_posts.unique('blog_post_id')`).

Une Série **vit dans** un Dossier (`article_series.dossier_id`, FK obligatoire) et l'appartenance au Dossier en est le **prérequis**. Dossier et Série ne sont donc pas concurrents.

La seule exclusivité est ailleurs : un article ne peut être à la fois **racine** et **annexe** d'une Série.

**Conséquence** : un article destiné à devenir document racine, s'il est déjà rattaché à **un autre** Dossier, doit en être détaché. **L'ampleur est à mesurer avant migration, jamais à supposer.**

### Compatibilité avec le Manifeste

* `core.manifesto` devient `core.root_document`, l'ancienne clé restant un **alias** — mécanisme déjà éprouvé par `legacy_aliases` sur les types ;
* les quatre permissions `manifesto.*` deviennent `root_document.*`, avec le même mécanisme d'alias ;
* la règle de confidentialité posée en TASK-1079 est portée telle quelle : **le document racine publié d'une Boucle privée n'est lisible que par ses membres actifs**, y compris en accès direct ;
* `loops.manifesto_blog_post_id` devient vestigial. **Conservé** le temps d'une migration vérifiée, retiré dans une tâche dédiée.

---

## 6. Cycle de vie des résumés IA

Trois états distincts :

| État | Nature | Durabilité | Qui décide |
|---|---|---|---|
| **A — Artefact provisoire** | message IA dans le fil | éphémère | l'IA, sur demande |
| **B — Sous-article validé** | `BlogPost` du Dossier racine | durable, versionné | un humain |
| **C — Fusion dans le document racine** | modification du `root_document` | durable, versionnée par `BlogSnapshot` | un humain |

**Aujourd'hui, seul A existe** : un résumé est un `LoopMessage` de `type = 'ai'` avec `metadata->action = 'summarize'`.

### Règle produit

* l'IA **peut** produire un résumé automatiquement ;
* elle **ne publie jamais** ;
* elle **ne remplace jamais silencieusement** le document racine ;
* un humain valide **toujours** la transformation en contenu durable.

### Parcours de fusion — normatif, sans raccourci

```
Résumé IA (message, éphémère)
  └─> Proposition de fusion         l'IA prépare, n'applique rien
        └─> Aperçu / diff           ce qui change, avant et après
              └─> Validation humaine
                    └─> Contrôle de concurrence
                        le document a-t-il changé depuis l'aperçu ?
                        si oui : refus, nouvel aperçu obligatoire
                          └─> Snapshot BlogSnapshot
                                └─> Application
```

**Aucune modification silencieuse. Aucune modification immédiate.** Le snapshot rend le dégât réparable ; le contrôle de concurrence l'empêche. Les deux sont requis.

`BlogSnapshot`, `BlogPost` et `blog_post_annotations` couvrent le besoin : **aucune table nouvelle**.

---

## 7. Card Sondage — MVP

**Card transversale**, activée par défaut dans Dialogue, utilisable partout.

Cette Card ne cree pas un besoin nouveau : `BOUCLE_ARCHITECTURE.md` §19.1
listait deja « sondages simples (votes, preferences) » au perimetre d'une
Boucle V1. TASK-1080 la specifie, elle ne l'invente pas.

**MVP nominatif et simple :**

| Point | Décision |
|---|---|
| Choix | **unique par défaut**, multiple en option |
| Vote modifiable | **oui**, jusqu'à la clôture |
| Clôture | manuelle **ou** programmée |
| Sondages simultanés | **plusieurs**, le plus récent ouvert en tête |
| Identité des votants | **visible aux membres de la Boucle** |
| Anonymat | **hors MVP** |

**L'anonymat est explicitement reporté à une tâche dédiée.** Un anonymat tenu par l'affichage est un faux anonymat : tant qu'il n'est pas garanti par le schéma, il ne doit pas être promis.

### Permissions — module `poll`, `requires_card => 'core.poll'`

| Permission | Propriétaire | Animateur | Membre |
|---|---|---|---|
| `poll.view` | oui | oui | oui |
| `poll.vote` | oui | oui | oui |
| `poll.create` | oui | oui | **oui** |
| `poll.close` | oui | oui | le sien |
| `poll.delete` | oui | oui | non |

Un sondage est un acte de conversation, pas de gouvernance.

---

## 8. Card Événements et page Organization

**Card transversale**, activée par défaut dans Dialogue.

> **Homonymie a lever.** `TASK-1051-propositions-aide-evenements-product-spec.md`
> emploie deja le mot « evenement » pour un objet different : une **offre
> d'aide** en mode evenement, payante en points, a places limitees, ouvrant une
> Boucle privee. Ce sont deux objets distincts qui partagent un nom.
>
> | | Offre d'aide en mode evenement (TASK-1051) | **Evenement de Boucle (TASK-1080)** |
> |---|---|---|
> | Domaine | place de marche | Boucle |
> | Economie | debit de points | gratuit |
> | Inscription | transaction confirmee | reponse `going` / `not_going` / `maybe` |
> | Effet | cree une Boucle privee | aucun effet sur l'adhesion |
>
> TASK-1051 **n'est pas implementee** : l'enum `services.delivery_mode` ne
> contient pas `event` et aucune table d'inscription n'existe. Aucune collision
> de code aujourd'hui. Les deux concepts devront neanmoins etre nommes
> distinctement le jour ou TASK-1051 sera reprise — voir les follow-ups
> documentaires du TASK file de TASK-1080.

### Contenu

Titre · description · date et heures · **physique avec adresse** ou **en ligne avec URL** · créateur · Boucle d'origine · participants · refus explicites.

Deux vues : **liste** et **calendrier**.

### Statuts de participation

| Statut | Stockage |
|---|---|
| Participe | ligne, `going` |
| Ne participe pas | ligne, `not_going` |
| Peut-être | ligne, `maybe` |
| **Sans réponse** | **absence de ligne** |

« Sans réponse » est l'absence d'une réponse, pas une réponse.

### Visibilité — MVP

| Règle | MVP |
|---|---|
| Événement d'une Boucle **privée** | **reste `loop_only`** — l'option Organization n'est pas offerte |
| Publication `organization` | seulement depuis une **Boucle visible dans l'Organization** |
| Page Organization | affiche le **nombre** de participants, **jamais les noms** |

Il n'existe **aucun troisième niveau**. L'agrégation reste strictement à l'intérieur d'une Organization. Aucun mécanisme inter-Organization n'existe et aucun ne doit être improvisé.

### Policy de participation — normative

Un utilisateur peut **répondre** à un événement si :

* il est **membre actif de la Boucle organisatrice** ;

**ou**

* l'événement est `visibility = organization`, **et**
* la Boucle est **visible dans l'Organization**, **et**
* l'utilisateur est **membre actif de cette Organization**.

**Un membre de l'Organization extérieur à la Boucle :**

* peut créer ou modifier **uniquement sa propre réponse** ;
* **ne voit pas** la liste nominative des participants ;
* **ne reçoit aucun rôle implicite** dans `loop_members`.

La **création**, la **modification**, l'**annulation** et la **publication** d'un événement continuent de dépendre des permissions de la Boucle.

**Conséquence assumée** : une ligne de `loop_event_participants` peut exister sans adhésion correspondante dans `loop_members`. Ce n'est pas une anomalie. Toute requête joignant participants et membres doit en tenir compte.

### Autres décisions

| Point | Décision |
|---|---|
| Qui crée | Propriétaire, Animateur, **et membre** |
| Qui modifie | le créateur, le Propriétaire, l'Animateur |
| Annuler ou supprimer | **annuler**, jamais supprimer, dès qu'une participation existe |
| Fuseau horaire | stocker **UTC et le fuseau d'origine** |
| Capacité maximale | optionnelle, **sans liste d'attente** |
| Récurrence | **hors MVP** |
| Rappels et notifications | **hors MVP** |
| Google Calendar, export `.ics` | **hors MVP** |

### Permissions — module `event`, `requires_card => 'core.events'`

| Permission | Propriétaire | Animateur | Membre |
|---|---|---|---|
| `event.view` | oui | oui | oui |
| `event.respond` | oui | oui | oui |
| `event.create` | oui | oui | oui |
| `event.update` | oui | oui | le sien |
| `event.cancel` | oui | oui | le sien |
| `event.publish_to_organization` | oui | oui | **non** |

Porter un événement au niveau de l'Organization engage l'Organization.

---

## 9. Atelier 10 pour 1

**Promesse** : une personne expose une situation, **dix personnes** apportent chacune une piste concrète.

### Workflow

| # | Étape | Acteur |
|---|---|---|
| 1 | Intention initiale | Porteur |
| 2 | Clarification | IA |
| 3 | Séparation des problèmes | IA |
| 4 | **Validation de la question principale** | **Porteur** |
| 5 | Ouverture de l'atelier | Porteur ou Animateur |
| 6 | Contributions | Pairs |
| 7 | Clôture | Porteur ou Animateur, ou échéance |
| 8 | Synthèse | IA |
| 9 | **Validation de la synthèse** | **Porteur** |
| 10 | Plan d'action | Porteur |

Les étapes 4 et 9 sont **non contournables** : l'IA propose, un humain arrête.

### Décisions

| Point | Décision |
|---|---|
| Objectif affiché | **dix contributeurs distincts** |
| Contribution principale | **une par personne** |
| Compléments et commentaires | possibles ensuite, sans compter comme contribution principale |
| Clôture avant dix | **possible** |
| Affichage | **deux compteurs séparés** — contributeurs, et total des pistes |
| Visibilité avant clôture | **masquée jusqu'à sa propre contribution** |
| Qui peut clore | porteur **ou** Animateur |
| Relance | possible, par le porteur ou l'Animateur |
| Anonymat | **jamais** |
| Confidentialité | **celle de la Boucle**, sans exception |
| Destination du plan d'action | Roadmap **ou** sous-article, au choix du porteur |
| Ateliers simultanés | plusieurs dans le temps, **un seul ouvert à la fois** |

Types de contribution : **idée · contact · ressource · expérience · action possible**.

**Droits du porteur** : le porteur peut clore et valider la synthèse quel que soit son rôle dans la Boucle. Ce droit est **vérifié par la Card**, en plus de la permission — il n'entre pas dans le résolveur global (§11).

---

## 10. Matrice des types

| Type | Clé | ChatLoop | Document racine | Cards du socle | Statut |
|---|---|---|---|---|---|
| Dialogue | `general` | `stream` | Cadre du dialogue | **Sondage, Événements, Membres** | disponible, socle révisé |
| Projets | `project` | `hybrid` | Manifeste | Résumé IA, document racine, Roadmap, Membres | disponible |
| Atelier 10 pour 1 | `workshop_10_for_1` | `threads` | Situation accompagnée | Atelier, Résumé IA, Drive, Membres | à créer — priorité |
| Formation | `training` | `hybrid` | Programme | Programme, Ressources/Drive, Progression, Membres | à créer |
| Recherche | `research` | `threads` | Question de recherche | Question, Corpus/Drive, Journal, Méthode, Membres | à créer |
| Coaching | `coaching` | `stream` | Cadre d'accompagnement | Cadre, Journal de séances, Plan d'action, Membres | socle **provisoire** |
| Pair-Aidance | `peer_support` | `stream` | Cadre de confiance | Cadre, Demandes d'aide, Ressources d'orientation, Membres | à créer |

**Dialogue** cesse d'être le type qui n'a rien pour devenir *le type de la conversation outillée* : on discute, on tranche par un sondage, on se donne rendez-vous par un événement. Les Boucles Dialogue existantes **gagneront** les deux Cards par synchronisation additive, sans rien perdre.

**Coaching** conserve provisoirement Résumé IA, Roadmap et Membres, en attente de sa tâche dédiée.

*Rédaction à plusieurs*, mentionné dans `@DOCS/A faire_Boucle.md`, n'est pas instruit par cette spec.

---

## 11. Permissions, confidentialité et tenant safety

### Trois besoins nouveaux, trois traitements distincts

| Besoin | Traitement | Pourquoi |
|---|---|---|
| **Droit lié à une personne désignée** (porteur d'un atelier) | **dans la Card**, en plus de la permission | la désignation est une propriété de la donnée, pas de l'adhésion. Le résolveur résout par `(type, rôle, permission)` et ne doit pas gagner de dimension |
| **État par personne** (progression, objectifs) | **table dédiée** `(loop_id, user_id, …)` | ce n'est pas une permission mais une donnée. Le « qui voit l'état de qui » se traite comme le premier cas |
| **Confidentialité entre membres** (notes privées) | **portée de données**, filtrée à la requête | une permission qui refuse laisse une trace visible ; un filtre de requête n'en laisse aucune |

### Invariantes garanties par le schéma ou par une écriture transactionnelle

**Contrainte réellement portée par le schéma :**

| Invariante | Mécanisme |
|---|---|
| Un Dossier appartient exactement à un utilisateur **ou** à une Boucle | contrainte `CHECK` : `(owner_id IS NULL) <> (loop_id IS NULL)` |

`owner_id` devient nullable, ce qui affaiblit une garantie existante. Le `CHECK` la remplace par une garantie plus forte : aujourd'hui rien n'empêcherait un Dossier d'avoir les deux, demain la base le refusera.

**Invariantes inter-tables, portées par service transactionnel et par tests** — elles ne sont **pas** exprimables par une simple contrainte `CHECK` :

| Invariante | Mécanisme |
|---|---|
| `dossiers.organization_id = loops.organization_id` | écriture transactionnelle centralisée, plus test de garde |
| `root_blog_post_id` appartient au même Dossier | vérification à l'écriture, sur le motif de `ensureBlogPostBelongsToDossier()` |
| Un événement `organization` provient d'une Boucle visible dans cette Organization | vérification à l'écriture **et** refiltrage à la lecture |
| La liste nominative des participants ne quitte pas la Boucle organisatrice | test d'accès, pas un simple masquage de vue |
| Une Card désactivée localement n'est jamais réactivée | test de garde sur `applyPreset()` |

### Tenant safety

* `Organization = Tenant`, `Loop ≠ Tenant` — inchangé ;
* chaque table nouvelle porte `organization_id`, strictement égal à celui de la Boucle ;
* **l'agrégation d'événements est le premier chemin de lecture qui traverse les Boucles.** Trois garde-fous, testés et non commentés :
  1. le filtre `organization_id` est porté par la **requête**, jamais déduit d'une jointure ;
  2. seuls les événements `visibility = organization` de Boucles actives et visibles remontent ;
  3. **la liste nominative ne quitte jamais la Boucle d'origine** — compteur seul en page Organization ;
* aucun événement inter-Organization sans mécanisme dédié ;
* l'agrégation ne fait pas de l'Organization le propriétaire des événements : elle en offre une lecture.

---

## 12. Ordre d'implémentation

**La première tâche est arrêtée. Les suivantes sont un ordre de travail, pas des numéros réservés.**

### TASK-1081 — Le workspace applique la composition du type

**Défaut à corriger** : `resources/views/loops/show.blade.php` construit la liste des Cards affichées depuis le catalogue filtré sur `default_enabled`, **sans appeler `activeCardsFor()`**. La composition livrée par TASK-1079 n'a donc aucun effet sur le workspace. Une Boucle Dialogue, dont le socle vaut `core.members`, en affiche quatre — dont trois se refusent, la porte `requires_card` niant la lecture.

Périmètre **strict** :

* la composition affichée provient réellement de `activeCardsFor()` ;
* **aucun panneau vide** — une Card affichée est une Card utilisable ;
* **cohérence entre l'affichage et `requires_card`** ;
* tests **par type et par rôle** ;
* validation **desktop, mobile 390 px et mode sombre** ;
* **aucun refactor hors périmètre**.

**Vigilance** : les Boucles anterieures a la refonte ont leurs Cards materialisees par la migration `2026_08_04_090100`. Elles ne doivent **rien perdre**. C'est le premier test a ecrire.

### Ordre de travail ensuite

| Ordre | Objet | Dépend de |
|---|---|---|
| 2 | Card **Sondage** — nominative, sans anonymat | 1081 |
| 3 | Card **Événements** — physique ou en ligne, quatre états | 1081 |
| 4 | **Page Événements de l'Organization** — agrégation, compteur sans noms | Événements |
| 5 | **Socle de Dialogue** porté à Sondage, Événements, Membres + synchronisation additive | Sondage, Événements |
| 6 | **Dossier racine et document racine** — `loop_id`, `root_blog_post_id`, `CHECK`, rattrapage mesuré | 1081 |
| 7 | Card **Atelier 10 pour 1** et son type | 1081 |
| 8 | Card **Drive** | Dossier racine |
| 9 | Card **Méthode** — articles et document racine d'abord | Dossier racine |
| 10 | **Cycle de vie des résumés IA** — proposition, diff, concurrence, snapshot | Dossier racine |
| 11 | **Type Formation** — Programme, Progression, état par personne | Dossier racine |
| — | **Écran de composition d'une Boucle** — consommateur de `loops.manage_cards` | 1081 |

**Deux chemins parallèles.** Sondage, Événements et le socle de Dialogue (ordres 2 à 5) ne dépendent **ni** du Dossier racine **ni** du document racine. Ils peuvent avancer en parallèle du chemin documentaire (6, 8, 9, 10, 11). C'est le chemin le plus court vers du visible : Dialogue est aujourd'hui le type le plus repandu.

### Reporté à des tâches dédiées

Scrutin anonyme ou secret · récurrence des événements, rappels, notifications, Google Calendar, export `.ics` · abstraction polymorphe de la Méthode vers ChatLoop · versionnement des presets · retrait de `loops.manifesto_blog_post_id` · socle définitif de Coaching · type *Rédaction à plusieurs*.

---

## Ce que cette spec ne tranche pas

* **L'ampleur réelle de la migration des documents racine** : à mesurer avant la tâche Dossier racine, jamais à supposer.
* Le socle de Coaching, provisoire depuis TASK-1079.
* Les compositions du §10 restent des hypothèses, **sauf Dialogue**, désormais arrêté.
