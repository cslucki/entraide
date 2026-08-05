# BouclePro — Architecture des Boucles

Statut : ACTIVE PRODUCT DOCTRINE - doctrine principale Boucles
Version : v0.1
Portée : produit, UX, modèle métier, architecture fonctionnelle
Implémentation : à découper en tâches dédiées

Documents liés pour le lancement :

* `docs/product/INTERACTION_MODEL.md` - modèle Interaction canonique court.
* `docs/product/LAUNCH_READINESS_2026-06-22.md` - état lancement, limites et promesses interdites.

---

## 1. Pourquoi ce document existe

BouclePro évolue vers une architecture plus simple et plus puissante.

Jusqu’ici, nous avons parfois raisonné en “types de boucles” :

* boucle d’aide ;
* boucle de coordination ;
* boucle de progression ;
* boucle de transmission ;
* boucle de rebond ;
* etc.

Cette approche a permis de clarifier les usages, mais elle risque de rigidifier le produit.

La nouvelle doctrine est différente :

> Une Boucle n’est pas un type métier figé.
> Une Boucle est un conteneur social vivant, situé à l’intérieur d’une Organization.

Le métier n’est pas porté par le type de boucle, mais par :

* les composants activés dans la boucle ;
* les interactions publiées dans la boucle ;
* les processus déclenchés par ces interactions ;
* la mémoire collective produite au fil du temps ;
* l’assistance IA validée par les humains.

Cette évolution permet de garder BouclePro simple, modulaire et extensible.

---

## 2. Doctrine fondamentale

### Organization = Tenant

L’Organization est la frontière de sécurité, de gouvernance, de configuration, de facturation et d’isolation des données.

Toutes les données métier doivent être rattachées à une Organization.

Une route publique peut être Organization-scopée.

Public ne veut pas dire global.

### Loop ≠ Tenant

Une Loop n’est pas un tenant.

Une Loop est un espace collaboratif interne à une Organization.

Elle ne doit jamais devenir une frontière d’isolation principale.

Elle peut organiser des conversations, des documents, des interactions, des événements, des journaux et des processus, mais elle reste toujours sous l’Organization.

### Community = dette legacy temporaire

Le vocabulaire Community / community_id reste une dette technique temporaire.

Aucun nouveau développement ne doit introduire de logique Community.

La documentation produit doit utiliser Organization, Loop, Member, Interaction, Component, Process.

### Mono-boucle = mode de lancement, pas modèle cible

Une Organization peut démarrer avec une Boucle principale unique. Ce mode mono-boucle reste acceptable pour une Organization qui debute.

Le modèle cible reste multi-boucles. Une Organization adulte peut avoir plusieurs Boucles (Piliers, projet, pair-aidance, apprentissage, etc.).

La Boucle principale n'est jamais le Tenant. Organization = Tenant reste la règle absolue d'isolation et de sécurité.

---

## 3. Hiérarchie conceptuelle

La hiérarchie produit cible est :

```text
Platform
└── Organization
    └── Loops
        └── Components
        └── Interactions
        └── Members
        └── Knowledge
        └── AI Assistance
```

Une Organization peut avoir une ou plusieurs Boucles.

Une Boucle peut activer différents composants.

Les membres publient des interactions.

Les interactions peuvent déclencher des processus.

L’IA assiste les humains, mais ne remplace pas la validation humaine.

---

## 4. Définition d’une Boucle

Une Boucle est un espace social structuré.

Elle permet à un groupe de personnes de :

* discuter ;
* demander de l’aide ;
* proposer de l’aide ;
* documenter ;
* décider ;
* suivre une progression ;
* organiser des événements ;
* partager des ressources ;
* conserver une mémoire ;
* produire des synthèses ;
* coopérer avec une IA.

Une Boucle doit rester lisible, calme et utile.

BouclePro ne cherche pas à créer un Slack, un Discord, un WhatsApp ou un réseau social généraliste.

La Boucle est un espace d’intelligence collective situé, limité, humainement gouvernable.

---

## 5. Boucle Piliers

La Boucle Piliers est la Boucle fondatrice d’une Organization.

Elle est destinée aux personnes qui portent la vision, la gouvernance ou la co-construction de l’Organization.

Exemples :

* fondateur ;
* coordinateur ;
* administrateur ;
* assistant de recherche ;
* contributeur clé ;
* membre du cercle de confiance initial.

La Boucle Piliers peut contenir :

* manifeste vivant ;
* roadmap ;
* journal ;
* décisions ;
* documents fondateurs ;
* pièces jointes ;
* discussions de cadrage ;
* synthèses IA validées ;
* base de connaissances de l’assistant IA.

La Boucle Piliers n’est pas forcément publique.

Elle est souvent privée ou accessible sur invitation.

Elle sert à construire la mémoire initiale de l’Organization.

Dans le cas d'une Organization co-brandee, la Boucle Piliers pourrait regrouper le fondateur, le responsable scientifique et les premiers membres du cercle de co-construction.

---

## 6. Composants de Boucle

Une Boucle peut activer plusieurs composants.

Un composant est une capacité fonctionnelle ajoutée à une Boucle.

Exemples de composants :

```text
Chat
Flux
Journal
Manifeste
Roadmap
Todo
Kanban
Agenda
Documents
Pièces jointes
Annonces
Blog lié
Visio / rencontres
Recommandations
IA
Base de connaissances
```

Tous les composants ne sont pas nécessaires dans toutes les Boucles.

Une Boucle simple peut n’avoir qu’un chat et quelques interactions.

Une Boucle Piliers peut activer manifeste, roadmap, journal, documents et IA.

Une Boucle d’apprentissage peut activer roadmap, journal, ressources, check-ins et événements.

Une Boucle de pair-aidance peut privilégier la confidentialité, les petits groupes et les prochaines étapes.

---

## 7. Roadmap comme composant transversal

La roadmap n’est pas un type de Boucle.

La roadmap est un composant.

Elle peut prendre plusieurs formes selon l’usage :

```text
Now / Next / Later
Todo
Kanban
Agenda
Timeline
Jalons
Sessions
```

Le même concept peut donc servir :

* à une Boucle Piliers pour structurer une co-construction ;
* à une Boucle projet pour suivre des tâches ;
* à une Boucle formation pour suivre des séances ;
* à une Boucle pair-aidance pour garder des prochaines étapes simples ;
* à une Organization pour afficher ses priorités.

La forme affichée doit dépendre du besoin réel, pas d’un modèle figé.

---

## 8. Manifeste comme composant

Le manifeste est un composant documentaire vivant.

Il peut être utilisé par une Organization ou par une Boucle.

Il doit permettre :

* rédaction ;
* versionnement ;
* historique ;
* restauration d’une version ;
* comparaison simple entre versions ;
* commentaire ou discussion ;
* validation humaine ;
* éventuellement résumé IA des changements.

Le manifeste ne doit pas être conçu comme un simple article de blog.

Il représente les principes, les valeurs, les règles de contribution et l’identité vivante d’une communauté.

Dans une Organization co-brandee, le manifeste peut devenir le premier objet collectif produit dans BouclePro.

> **Generalise par TASK-1080.** Ce que cette section decrit reste exact, mais le
> concept a change de nom pour cesser d'imposer un mot a tous les types. Le
> composant generique est le **document racine** (`root_document`), unique par
> Dossier racine. « Manifeste » devient le **libelle que le type Projets** lui
> donne ; Dialogue l'appelle *Cadre du dialogue*, Pair-Aidance *Cadre de
> confiance*, Formation *Programme*. Les capacites enumerees ci-dessus —
> versionnement, historique, restauration, comparaison, discussion, validation
> humaine, resume IA — sont celles du document racine, quel que soit son
> libelle. Voir
> `docs/specs/TASK-1080-types-boucles-dossier-racine-cards-metiers-product-spec.md`, §5.

---

## 9. Journal comme composant

Le journal conserve la mémoire d’une Boucle.

Il ne remplace pas le chat.

Il en extrait les moments importants.

Fonctionnement cible :

1. Les membres discutent dans la Boucle.
2. Un membre demande une entrée de journal.
3. L’IA propose un résumé depuis la dernière entrée.
4. Les humains valident, corrigent ou refusent.
5. L’entrée validée devient une trace stable.

La première entrée peut être appelée “Entrée 0”.

Elle résume l’intention initiale, les personnes présentes et le contexte de création de la Boucle.

Le journal doit permettre de retrouver :

* décisions ;
* désaccords ;
* hypothèses ;
* prochaines étapes ;
* contributions ;
* évolutions du manifeste ;
* apprentissages collectifs.

---

## 10. Documents, pièces jointes et mémoire IA

Les pièces jointes ne sont pas de simples fichiers.

Elles peuvent devenir la mémoire documentaire d’une Boucle.

Exemples :

* PDF ;
* images ;
* notes ;
* comptes rendus ;
* exports ;
* documents de recherche ;
* supports de formation ;
* liens ;
* fichiers HTML ;
* fichiers Markdown.

À terme, ces documents peuvent alimenter l’assistant IA de la Boucle ou de l’Organization.

Formulation utilisateur recommandée :

> Les documents de cette Boucle peuvent être utilisés comme base de connaissances par l’assistant IA.

Le terme RAG doit rester principalement technique.

Dans l’interface utilisateur, il vaut mieux parler de :

* base documentaire ;
* mémoire IA ;
* assistant appuyé sur les documents ;
* connaissances de la Boucle.

---

## 11. Interactions

Une interaction est une unité d’action publiée dans une Boucle ou dans une Organization.

Elle peut être affichée dans plusieurs vues :

* chat ;
* flux ;
* profil ;
* annuaire ;
* journal ;
* dashboard ;
* notification ;
* email ;
* assistant IA.

Une interaction n’est pas forcément un message.

Elle peut représenter :

```text
Demande d’aide
Proposition d’aide
Question
Annonce
Article
Événement
Document
Décision
Recommandation
Mise en relation
Statut
Fascination
Mission
Feedback
Journal entry
```

L’objectif est d’éviter de multiplier les modèles métier rigides.

Le produit doit permettre de créer des interactions structurées, claires et utiles.

---

## 12. Les quatre échanges LaunchPals

Le responsable scientifique a propose quatre formes simples d'echange :

```text
I can help with...
I am looking for help with...
I am currently fascinated by...
I think these two people should meet...
```

Ces quatre formes sont très alignées avec BouclePro.

Elles ne doivent cependant pas toutes devenir des types de Boucles.

### I can help with...

Cette forme correspond à une proposition d’aide.

Elle peut être une interaction publiée dans une Boucle ou visible sur un profil.

Elle peut alimenter :

* annuaire ;
* carte membre ;
* agent IA de profil ;
* recommandations ;
* flux de l’Organization.

### I am looking for help with...

Cette forme correspond à une demande d’aide.

C’est une interaction centrale de BouclePro.

Flux cible :

```text
Intention floue
→ clarification IA
→ validation humaine
→ publication
→ réponses humaines
→ suivi
→ journal ou clôture
```

### I am currently fascinated by...

Cette forme ne doit pas devenir une Boucle.

Elle doit être traitée comme un statut vivant.

Elle peut apparaître :

* dans l’annuaire ;
* sur les cartes membres ;
* sur le profil ;
* dans un futur flux d’activité ;
* éventuellement dans le blog.

Ce statut doit être facile à mettre à jour.

Il permet de montrer ce qui occupe l’attention intellectuelle ou créative d’une personne à un moment donné.

### I think these two people should meet...

Cette forme ne doit pas devenir une Boucle.

Elle doit être traitée comme une recommandation ou une mise en relation.

Elle peut évoluer vers un module dédié :

* recommander une personne à une autre ;
* expliquer pourquoi la rencontre serait utile ;
* proposer une visio ;
* proposer une rencontre IRL ;
* garder trace de la mise en relation ;
* éventuellement créer une interaction de suivi.

Ce module peut être dérivé de la proposition de services et des mécanismes de recommandation.

---

## 13. Processus

Une interaction peut déclencher un processus.

Un processus est une séquence guidée.

Exemple : demande d’aide.

```text
L’utilisateur écrit une intention floue.
L’IA reformule.
L’utilisateur valide.
La demande est publiée.
Des membres répondent.
Une aide est proposée.
Un échange a lieu.
Une synthèse peut être ajoutée au journal.
La demande peut être clôturée.
```

Exemple : manifeste.

```text
Un membre propose une modification.
La modification est discutée.
L’IA peut résumer les changements.
Les humains valident.
Une nouvelle version est enregistrée.
Le journal peut noter le changement.
```

Exemple : mise en relation.

```text
Un membre suggère une rencontre.
Il explique pourquoi.
Les personnes concernées sont notifiées.
Elles acceptent ou refusent.
Une visio ou rencontre peut être organisée.
Une note de suivi peut être créée.
```

---

## 14. Rôle de l’IA

L’IA est une couche d’assistance.

Elle ne doit pas devenir le centre du produit.

Elle peut :

* clarifier ;
* reformuler ;
* résumer ;
* traduire ;
* classer ;
* suggérer ;
* préparer ;
* comparer ;
* extraire ;
* guider.

Elle ne doit pas :

* décider seule ;
* publier sans validation ;
* remplacer les humains ;
* transformer BouclePro en chatbot ;
* produire du bruit conversationnel ;
* masquer les responsabilités.

Principe central :

> L’IA aide à clarifier.
> Les humains restent au centre.

---

## 15. Vues multiples, donnée unique

Une interaction doit pouvoir apparaître dans plusieurs endroits sans duplication métier.

Exemple :

Une demande d’aide peut être visible :

* dans la Boucle ;
* dans le flux ;
* dans le profil de l’auteur ;
* dans le dashboard ;
* dans le journal si elle devient significative ;
* dans l’assistant IA si elle est indexée.

Le système doit éviter de copier les mêmes informations dans plusieurs modèles sans logique claire.

Une interaction est une donnée structurée.

Les vues affichent cette donnée selon le contexte.

---

## 16. Conséquences d’architecture

À terme, le modèle cible peut évoluer vers :

```text
Loop
LoopComponent
Interaction
InteractionType
InteractionProcess
LoopDocument
LoopJournalEntry
LoopRoadmapItem
KnowledgeSource
```

Mais ce document ne déclenche pas automatiquement une migration.

La règle reste :

> Pas de gros refactor sans tâche dédiée.

La transition doit être progressive, testée, compatible avec l’existant, et Organization-scopée.

### Statut du type de Boucle (révisé — TASK-1079 CP5ter)

Le type de Boucle est un **profil de configuration par défaut**. Il peut déterminer un socle initial de Cards et des variations de permissions par rôle. Il ne constitue ni une frontière tenant, ni une application métier rigide, ni la source exhaustive du comportement de la Boucle. Les capacités effectives résultent des composants actifs, des rôles, des permissions et du contexte Organization.

Cette formulation remplace la règle antérieure — « `loops.type` peut rester temporairement comme information de compatibilité ou de présentation ; il ne doit pas piloter toute l'architecture produit future » — dont le sens est conservé sur l'essentiel : le type **ne pilote pas** l'architecture. Ce qui change est son statut : il passe de purement présentationnel à **profil de configuration**, porteur d'un socle de Cards et de variations de permissions.

Restent inchangés et non négociables :

* Organization = Tenant ;
* Loop ≠ Tenant ;
* type ≠ tenant ;
* type ≠ application métier rigide ;
* les **composants activés** restent les capacités réelles de la Boucle ;
* on ne crée pas une nouvelle catégorie pour chaque cas d'usage.

Un type déclare **uniquement ses différences** par rapport au socle de permissions défini par rôle. Ajouter un type ne duplique aucune matrice et n'oblige à modifier ni les policies, ni les contrôleurs, ni les vues.

### Doctrine Loop / Type / Card (arrêtée — TASK-1080)

La révision ci-dessus reste valable. TASK-1080 l'étend sur deux dimensions qui manquaient — la présentation et le comportement par défaut — et fixe la frontière entre un type et une Card.

**Un type de Boucle est un preset déclaratif portant quatre dimensions, et rien d'autre :**

1. **composition** — le socle de Cards appliqué à la création et lors d'un changement de type, additif et idempotent ;
2. **présentation** — les libellés que le type donne aux composants génériques, au premier rang desquels le document racine ;
3. **comportement par défaut** — les réglages d'usage qu'il propose, notamment `conversation_mode` ;
4. **permissions** — ses différences par rapport au socle défini par rôle.

**Un type ne crée aucune table, aucun modèle, aucune route, aucune frontière de sécurité ni de stockage.** Toute donnée d'une Boucle appartient à `loops` et à ses tables satellites, quel que soit son type. Retirer un type d'une Boucle ne doit jamais rendre une donnée inaccessible.

**Le type est lu, jamais branché.** Aucun `match ($loop->type)` ne doit apparaître dans un contrôleur, une policy ou une vue : `LoopTypeRegistry` est le seul lecteur. Une Card qui doit se comporter différemment selon le type expose un **réglage** que le preset renseigne — elle ne porte pas la condition.

**Type et Card :**

* une **Card** est un composant réutilisable, déclaré au catalogue, autonome, utilisable par plusieurs types ;
* une **Card métier** peut être centrale dans un type sans lui appartenir — rien n'interdit de l'activer ailleurs ;
* une **Card transversale** n'a pas de type de prédilection : Membres, Drive, Méthode, Sondage, Événements ;
* **ChatLoop n'est pas une Card.** C'est la surface centrale de toute Boucle, jamais désactivable. Une Boucle sans conversation n'existe pas.

**Personnalisation locale.** Une Card ajoutée ou désactivée dans une Boucle donnée survit à toute évolution du socle de son type. La synchronisation d'un preset est strictement additive : elle ajoute ce qui manque et ne réactive jamais ce qu'une Boucle a délibérément désactivé.

La spécification complète — Dossier racine, document racine, cycle de vie des résumés IA, Cards Sondage et Événements, Atelier 10 pour 1, matrice des types — figure dans `docs/specs/TASK-1080-types-boucles-dossier-racine-cards-metiers-product-spec.md`.

---

## 17. Non-objectifs immédiats

Ce document ne demande pas :

* une réécriture complète du modèle Loop ;
* une migration massive ;
* une refonte totale des routes ;
* la suppression immédiate des anciennes vues ;
* la création d’un moteur de plugin complet ;
* une migration Community → Organization non dédiée ;
* une transformation de BouclePro en réseau social ;
* une transformation de BouclePro en marketplace classique.

---

## 18. Priorités MVP

> **Section historique.** Ces priorites ont ete posees pour la V1.4 et sont
> depassees. L'ordre d'implementation en vigueur figure au §12 de
> `docs/specs/TASK-1080-types-boucles-dossier-racine-cards-metiers-product-spec.md`.
> Conservee pour la tracabilite de l'intention initiale.

Pour la V1.4 et les tâches suivantes, les priorités étaient :

1. Boucle Piliers.
2. Manifeste simple versionné.
3. Roadmap simple.
4. Journal de Boucle.
5. Pièces jointes de Boucle.
6. Assistant IA appuyé sur la mémoire de la Boucle.
7. Statut “currently fascinated by” sur profil / annuaire.
8. Préparation du module de recommandation / mise en relation.

Chaque point doit être traité par tâche séparée.

> Voir `LOOP_BUILDER_MVP.md` pour la spec d'implémentation du SuperAdmin Loop Management et du Member Loop Builder.

---

## 19. Boucle V1 — périmètre de lancement

> **Section historique.** Le perimetre V1 decrit ici a ete defini pour le
> lancement du 22/06/2026, desormais passe. Il est conserve parce qu'il eclaire
> les choix suivants — et parce qu'il **anticipait deja** deux composants que
> TASK-1080 formalise : les sondages simples (§19.1) et la distinction entre un
> resume IA et un contenu valide par un humain.

Pour le lancement du 22/06/2026, une Boucle V1 a été définie comme une **conversation augmentée** accessible aux membres d'une Organization.

Ce périmètre n'est pas le modèle cible définitif. Il permet de lancer avec un produit cohérent sans attendre le moteur complet de composants dynamiques.

### 19.1 Ce qu'une Boucle V1 peut contenir

- conversation structurée (chat de Boucle) ;
- journal de bord (entrées validées humainement, résumé IA assisté) ;
- résumé IA validé humainement ;
- document / manifeste simple (texte versionné) ;
- sondages simples (votes, préférences) ;
- bibliothèque d'images et fichiers (pièces jointes) ;
- membres et facilitateur (owner + participants) ;
- intention et brief de la Boucle.

### 19.2 Ce qui reste ouvert pour le futur

Le périmètre V1 ne ferme pas l'évolution future vers :
- des composants dynamiques (Kanban, Agenda, Roadmap interactive) ;
- un moteur de plugins ou `LoopComponent` en base ;
- des processus automatisés déclenchés par interactions ;
- une IA plus autonome (avec validation humaine maintenue).

Toute évolution au-delà du périmètre V1 nécessite une tâche dédiée.

### 19.3 Principe

> Une Boucle V1 est plus qu'un chat, moins qu'un OS.
> Elle est une conversation augmentée par des artefacts légers.
> L'humain reste au centre ; l'IA clarifie et structure.

---

## 20. Règle de documentation

Ce document devient la référence produit pour la notion de Boucle.

Pour le lancement du 22/06/2026, il reste la doctrine produit principale. Les documents `INTERACTION_MODEL.md` et `LAUNCH_READINESS_2026-06-22.md` le complètent sans le remplacer.

Les anciens documents parlant de “types de boucles” doivent être considérés comme documents exploratoires ou historiques.

Ils ne doivent pas être supprimés brutalement.

Ils peuvent être déplacés vers une zone d’archive ou annotés comme “superseded by BOUCLE_ARCHITECTURE.md”.

---

## 21. Formule courte

BouclePro ne crée pas des salons de discussion.

BouclePro crée des espaces humains structurés où :

* les intentions deviennent des interactions ;
* les interactions déclenchent des processus ;
* les processus produisent de la mémoire ;
* la mémoire nourrit l’intelligence collective ;
* l’IA clarifie ;
* les humains décident.
