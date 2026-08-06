# Product Spec — Le type Formation et la progression individuelle

**TASK-1089** · Rédigé le 2026-08-06 sur `develop` à `9be5e98` (contient TASK-1088).

Document de spécification. **Aucun code n'accompagne cette tâche.**

---

## 1. Ce qu'on cherche à faire

Une Boucle Formation permet à un formateur d'organiser un parcours commun tout en
suivant chaque stagiaire individuellement.

> **Stagiaire.** Je consulte les séquences qui me sont ouvertes, je réalise les
> activités demandées, et je sais toujours quelle est ma prochaine étape.
>
> **Formateur.** Je vois où en est chaque stagiaire, je peux valider une étape et
> débloquer la suite.

**La structure est commune. L'état est individuel.** Toute la spécification tient
dans cette phrase : deux stagiaires ouvrent la même Card et n'y voient pas la
même chose, parce que leur avancée diffère.

BouclePro ne devient pas un LMS généraliste. Pas de SCORM, pas de LTI, pas de
xAPI, pas de certificats, pas de catalogue commercial.

---

## 2. Le cadre global des presets

Formation n'est pas un cas isolé : c'est un preset parmi sept, et il doit se lire
dans cette grille. **Cette matrice est la référence fonctionnelle.** Elle est
donnée ici comme contexte ; les six autres presets ne sont pas spécifiés par
cette tâche.

| Preset | Cards communes | Trois Cards distinctives |
|---|---|---|
| Communauté | Manifeste · Membres | Événements · Sondage · Dossiers |
| Projet | Manifeste · Membres | Roadmap · Décisions · Dossiers |
| Pair-aidance | Manifeste · Membres | Engagements · Journal · Sondage |
| **Formation** | Manifeste · Membres | **Support de cours · Progression · Travaux à rendre ou QCM** |
| Réseautage | Manifeste · Membres | Demande-Offre · Roadmap · Événements |
| Coaching | Manifeste · Membres | Engagements · Suivi de coaching · Journal |
| Rédaction | Manifeste · Membres | Article · Roadmap · Dossiers |

### Ce que la matrice dit

**Trois Cards distinctives, pas davantage.** Ce sont elles qui font qu'on
comprend, en ouvrant une Boucle, ce qu'on y fait. Une grille de six ou sept
outils ne dit plus rien : elle donne une boîte à outils au lieu d'une intention.

**Le socle commun n'est pas distinctif.** ChatLoop, Manifeste et Membres sont
partout ; les nommer dans la grille reviendrait à répéter la même chose sept fois
et à noyer les trois qui comptent.

**Manifeste et Membres restent techniquement déclarés dans le registre actuel**,
avec leurs permissions et `core.members` toujours requise. Seule leur présentation
change, et **aucune modification du workspace n'est faite par cette tâche**.

### Le libellé « Communauté » — décision arrêtée

Le code porte la clé `general`, libellée aujourd'hui **« Dialogue »**.

**La clé technique `general` est conservée. Le libellé utilisateur cible devient
« Communauté ».** Aucune clé `community` ne doit être créée : ce serait un second
preset là où il n'y en a qu'un, et il faudrait ensuite migrer les Boucles
existantes pour rien.

Le changement de libellé touche `lang/fr/loops.php` et `lang/en/loops.php` : c'est
du code, donc **hors de cette tâche documentaire**. Il sera fait par la tâche
d'interface ou de presets.

`peer_support` (« Pair-Aidance ») et `coaching` existent déjà. **`Réseautage` et
`Rédaction` n'ont aujourd'hui aucune clé** — leur création relèvera de la tâche
qui traitera ces presets, pas de celle-ci.

---

## 3. La doctrine de présentation

Décision UX et fonctionnelle. **Elle ne demande aucun refactor du registre ni du
workspace dans cette tâche.**

### Le cadre permanent

**ChatLoop reste le centre.** C'est là que la Boucle vit ; tout le reste
l'accompagne.

**Manifeste** s'affiche sous forme compacte — en-tête ou panneau permanent. Le
texte de référence doit être à portée sans occuper une case d'outil : on le
consulte, on ne « l'ouvre » pas comme un instrument de travail.

**Membres** s'affiche sous forme compacte : avatars, nombre, et un accès à la
liste complète. Savoir qui est là est une information de contexte, permanente,
pas une activité.

### La zone des outils

**Seules les trois Cards distinctives occupent la zone principale.** Elles
expliquent immédiatement la fonction du preset.

Elles restent **configurables localement** selon les règles déjà livrées
(TASK-1083, TASK-1086) : un Admin d'Organization en désactive une, et **une Card
désactivée conserve ses données** — vérifié en recette sur Sondage et Événements.

Rien de tout cela ne change le fonctionnement livré : `core.manifesto` et
`core.members` restent des Cards déclarées dans le registre, avec leurs
permissions et `core.members` toujours requise. **C'est leur présentation qui
change**, et cela relèvera d'une tâche d'interface, pas de celle-ci.

---

## 4. Où on en est vraiment

### Le type existe déjà, et il est retenu volontairement

`config/loop_types.php` déclare `training` : libellé « Formation », icône
`academic`, ordre 30, document racine « Programme de formation » avec ses quatre
sections. Son socle porte `core.manifesto`, `core.members`, `core.roadmap`.

Il est marqué `available => false`, et le code dit pourquoi :

> *Not offered anywhere: its pedagogical cards do not exist yet, and a type that
> ships nothing of its own is a promise, not a product.*

**Le rendre disponible sera un drapeau, pas une création.** Aucune tâche ne doit
créer de type Formation.

### Rien de pédagogique n'existe

Aucun modèle, aucune migration, aucune table sur `training`, `course`, `lesson`,
`module`, `sequence`, `assessment`, `quiz`, `assignment`, `submission`,
`progress`, `completion`, `grade`, `cohort`, `enrollment`. Terrain vierge.

### Ce que le socle offre déjà

| Besoin | Brique existante | Livrée par |
|---|---|---|
| Programme narratif | document racine + `LoopRootDocumentService` | TASK-1082 |
| Supports, pièces jointes | Dossier racine + `DossierFile` | TASK-1082 |
| Contenus rédigés | Articles, Dossiers, séries | TASK-1084/1085 |
| Avis, choix d'horaire | Card Sondage (nominative) | TASK-1087 |
| Séances, présence, agenda | Card Événements | TASK-1088 |
| Suivi collectif | Card Roadmap | — |
| Inscrits, rôles | Card Membres | — |
| Déclarer une Card | `LoopCardRegistry` | TASK-1086 |
| Bloquer sans la Card | `requires_card` | TASK-1086 |
| Survivre à l'archivage | drapeau `read` | TASK-1086 |
| Annoncer dans ChatLoop | motif `sendEventMessage` | TASK-1087/1088 |
| Propager un socle | `loops:sync-presets` | TASK-1086 |

**La progression individuelle est la seule capacité *transverse* qui manque.**
Rien dans le socle ne sait dire « cette personne a terminé cette étape », et rien
d'équivalent n'existe ailleurs dans le produit.

Ce n'est pas pour autant le seul travail restant. **Modules, Séquences, Travaux à
rendre et QCM sont entièrement à développer** — ils n'ont aujourd'hui ni modèle,
ni table, ni écran. La différence est ailleurs : ceux-là se construisent sur des
briques existantes (Articles, Dossier racine, la forme du Sondage), là où la
progression n'a aucun précédent dans le produit.

### La doctrine tient

Aucune condition métier sur `$loop->type` n'existe dans le code. La règle de
TASK-1080 — le type ne pilote pas l'architecture — a survécu à huit tâches. **La
Formation ne doit pas être l'occasion de la briser :** aucun `if ($loop->type ===
'training')` nulle part.

---

## 5. Personas et droits

### Stagiaire — membre de la Boucle

Consulte le Programme et les contenus qui lui sont ouverts. Marque une séquence
terminée. Remet un Travail. Répond à un QCM. Voit sa progression et sa
prochaine étape. Reçoit une demande de reprise.

Il ne voit **jamais** la progression nominative des autres.

### Formateur — animateur de la Boucle

Construit le parcours, publie les contenus, crée Travaux et QCM, voit la
progression de chacun, valide ou refuse, débloque manuellement, commente une
remise, archive un contenu.

**Le formateur est l'animateur, pas un rôle nouveau.** Le socle en a déjà un, avec
ses permissions et ses écrans ; en créer un second dédierait la Formation d'un
mécanisme qui marche.

### Propriétaire de la Boucle

**Exactement les droits du formateur, plus les siens propres** (identité,
archivage, gouvernance). Il n'y a pas de raison qu'une personne qui a créé la
formation ne puisse pas la construire.

### Admin d'Organization

Configure les Cards, administre la Boucle. **Ne devient pas correcteur** : voir la
composition d'une Boucle n'est pas y enseigner. S'il veut corriger, il se nomme
animateur.

### SuperAdmin

Autorité plateforme. **Aucun rôle pédagogique automatique.**

### Matrice

| Action | Stagiaire | Formateur/Animateur | Propriétaire | Admin Org | SuperAdmin |
|---|:--:|:--:|:--:|:--:|:--:|
| Voir le Programme | oui | oui | oui | oui | oui |
| Voir les séquences ouvertes **pour soi** | oui | oui | oui | — | — |
| Voir toute la structure | non¹ | oui | oui | — | — |
| Construire modules et séquences | non | oui | oui | non | non |
| Publier un contenu | non | oui | oui | non | non |
| Marquer **sa** séquence terminée | oui | oui | oui | — | — |
| Remettre un Travail | oui | oui | oui | — | — |
| Répondre à un QCM | oui | oui | oui | — | — |
| Voir **sa** progression | oui | oui | oui | — | — |
| Voir la progression de **tous** | non | oui | oui | non | non |
| Valider une étape | non | oui | oui | non | non |
| Débloquer manuellement | non | oui | oui | non | non |
| Demander une reprise | non | oui | oui | non | non |
| Configurer les Cards | non | non | non | oui | oui |
| Archiver la Boucle | non | non | oui | oui | oui |

¹ **Question ouverte Q3** ci-dessous.

Sur une Boucle **archivée**, toute la colonne « écriture » tombe — c'est le
comportement du resolveur depuis TASK-1086, et la Formation n'y déroge pas.

---

## 6. Les objets

### Formation = la Boucle

Pas d'objet « Formation » distinct. La Boucle porte l'identité, la mission, le
Programme racine, les membres, les Cards, la ChatLoop.

**Une Boucle par module, par séquence ou par stagiaire est exclue.** Ce serait
faire de la Loop un tenant, ce qu'elle n'est pas.

### Module — une grande étape

Titre, description, position, statut de publication, règle d'ouverture, durée
indicative, dates facultatives.

### Séquence — une unité de travail, dans un Module

Titre, position, type de contenu, durée indicative, règle d'ouverture.

**Modules et Séquences ne sont pas des Cards.** Ce sont des contenus internes au
parcours, rendus par les Cards pédagogiques. Une Card par module ferait une barre
d'outils illisible dès la troisième semaine.

### Travail — ce qu'on rend

Énoncé, consignes, **date limite facultative**, pièce jointe d'énoncé. Côté
stagiaire : réponse texte, fichier ou image, état de remise, retour du formateur,
validation, demande de reprise.

**Le Travail est le seul objet à porter une date limite** dans le MVP. Ni Module
ni Séquence n'en ont : une échéance générale appellerait rappels, retards et
pénalités, un chantier sans demande. Les séances, ateliers et rendez-vous
pédagogiques passent par la **Card Événements**, déjà livrée — avec ses formats,
ses fuseaux et sa présence.

### QCM — ce qui se corrige tout seul

MVP : choix unique, choix multiple, vrai/faux. Score, seuil de réussite, nombre
de tentatives, correction automatique, validation manuelle facultative.

**Un QCM est informatif ou bloquant, au choix du formateur, QCM par QCM.**
Informatif, il situe ; bloquant, il conditionne le passage — l'échec ferme la
suite, la réussite satisfait la condition. Dans les deux cas le formateur peut
valider ou débloquer à la main, et **l'IA ne débloque jamais**.

**Un QCM n'est pas un Sondage** et ne doit pas partager son modèle. Un Sondage n'a
pas de bonne réponse, pas de score, pas de tentative, et il est nominatif par
construction. Ce qui se réutilise est la *forme* — une question, des options, une
réponse par personne — pas les tables.

### Ce que le stagiaire voit après une tentative

**Immédiatement, toujours :** son score, et s'il a réussi ou échoué.

**Le détail des bonnes réponses est réglé par le formateur**, par QCM, parmi
trois options :

| Réglage | Quand le détail apparaît |
|---|---|
| Immédiat | dès la première tentative |
| Après la dernière tentative | quand il n'en reste plus |
| Après clôture ou validation | quand le formateur l'ouvre |

Trois valeurs, pas un moteur. Le score immédiat est ce que tout le monde attend ;
le détail immédiat rendrait les tentatives multiples sans objet et circulerait
d'un stagiaire à l'autre.

### Ressource — ce qu'on consulte

Article, fichier, lien, vidéo, référence, modèle téléchargeable.

**Ce n'est pas un objet nouveau.** Les ressources vivent dans le **Dossier racine**
de la Boucle, avec ses fichiers et ses Articles (TASK-1082). Une Séquence peut y
pointer ; elle n'en possède pas une copie. Créer une table de ressources
dupliquerait un rangement qui existe et qui marche.

### Progression — l'état individuel

Le seul objet réellement nouveau du point de vue du socle.

---

## 7. Programme narratif contre structure — le risque de doublon

C'est le point le plus délicat de cette spécification.

### La règle

| | Rôle | Répond à |
|---|---|---|
| **Programme** (document racine) | présenter | *De quoi s'agit-il, pour qui, pourquoi ?* |
| **Modules et Séquences** | exécuter | *Que dois-je faire maintenant ?* |

Le Programme reste un Article structuré, publié dans la Boucle, absent du Blog,
rangé dans le Dossier racine — exactement ce que TASK-1082 a livré.

**Ce qui reste au Programme seul :** objectifs pédagogiques, public visé,
prérequis, durée globale, modalités, compétences visées, organisation générale,
intervenants, conditions d'accès.

**Ce qui appartient aux Modules :** titres, ordre, contenus, règles d'ouverture,
durées par étape, activités. Rien de tout cela ne se recopie dans le Programme.

### Aucune synchronisation automatique

Le Programme n'est **jamais** réécrit par la structure. Un formateur qui soigne
son texte ne doit pas le voir écrasé parce qu'il a renommé un module.

**Recommandation :** une vue synthétique, *sous* le document et non dedans,
listant les modules dans l'ordre avec leur durée. Générée à l'affichage, jamais
écrite dans l'Article. Le lecteur voit un tout ; le texte reste au formateur.

Plus tard, l'IA pourra *proposer* une mise à jour du Programme quand la structure
a beaucoup bougé. **Proposer.** Le formateur décide, toujours.

---

## 8. Disponibilité et déblocage

Les règles imaginables sont nombreuses. Un moteur générique serait la faute à ne
pas commettre : il coûte cher, se teste mal, et personne n'en utilise le dixième.

### Le parcours est séquentiel par défaut

**Décision arrêtée.** Un Module reste fermé tant que les conditions du précédent
ne sont pas satisfaites :

> Module 1 → réussite ou validation humaine → Module 2

Une Formation peut être configurée en **parcours libre** — tout ouvert d'emblée —
mais ce n'est pas le défaut.

*Ce que cela change par rapport à ma recommandation initiale.* J'avais proposé
l'inverse, par crainte de frustrer. La décision retenue est celle d'une formation
qui **conduit** quelqu'un quelque part plutôt que de l'abandonner devant une
bibliothèque : c'est ce qui distingue un parcours d'un dossier de documents, et
c'est cohérent avec le troisième emplacement obligatoire — on rend quelque chose,
donc il y a un avant et un après.

La contrepartie est traitée en §12 : **tout reste visible**, seul le contenu
détaillé est fermé. Une étape verrouillée qu'on voit venir n'est pas un mur, c'est
un plan.

### Les quatre règles du MVP

1. **Après l'étape précédente** — **le défaut**, activé pour toute Formation
   nouvelle.
2. **Disponible d'emblée** — le mode parcours libre, choisi explicitement.
3. **Après réussite d'un QCM** — le verrou pédagogique, configurable par QCM.
4. **Déblocage manuel du formateur** — qui prime toujours.

**Reporté :** disponibilité à une date sur un Module ou une Séquence, validation
manuelle systématique, combinaisons booléennes, prérequis croisés entre modules.

### Priorité quand plusieurs règles s'appliquent

Le déblocage manuel **écrase tout**. Un formateur qui ouvre une étape à quelqu'un
sait ce qu'il fait ; aucune règle automatique ne doit le contredire.

Sinon : une étape est disponible quand **toutes** ses conditions sont remplies.
Le « et » plutôt que le « ou » — plus prévisible à expliquer, et l'erreur qu'il
produit (une étape fermée à tort) se corrige d'un clic, alors que l'inverse
(ouverte à tort) ne se rattrape pas.

**L'IA ne débloque jamais.** Elle pourra suggérer, pré-évaluer, résumer. La
décision d'ouvrir une étape à quelqu'un appartient au formateur, sans exception.

---

## 9. La progression individuelle

### Les états

| État | Ce qu'il veut dire |
|---|---|
| `unavailable` | les conditions ne sont pas réunies |
| `available` | ouvert, pas encore commencé |
| `in_progress` | ouvert et abordé |
| `submitted` | remis, en attente du formateur |
| `completed` | terminé — déclaré par le stagiaire |
| `validated` | terminé **et** approuvé par le formateur |
| `redo` | le formateur demande une reprise |

### `completed` et `validated` ne sont pas la même chose

C'est la distinction qui porte tout le suivi.

`completed` est **déclaratif** : le stagiaire dit avoir fait. Pour une lecture,
une vidéo, une ressource, cela suffit — personne ne va corriger une lecture.

`validated` est **prononcé par le formateur**. Un Travail rendu attend un regard.

Une séquence déclare si elle exige une validation. Sans cela, `completed` termine.

### Ce qui déclenche quoi

| Transition | Déclencheur |
|---|---|
| `available` → `in_progress` | première ouverture de la séquence |
| `in_progress` → `completed` | le stagiaire clique « J'ai terminé » |
| `in_progress` → `submitted` | remise d'un Travail, ou tentative de QCM |
| `submitted` → `validated` | le formateur approuve |
| `submitted` → `redo` | le formateur demande une reprise |
| `redo` → `in_progress` | le stagiaire reprend |
| n'importe quoi → `validated` | déblocage manuel du formateur |

**La progression n'est jamais déduite du dernier accès.** Ouvrir n'est pas faire.
Une séquence ouverte passe `in_progress` ; elle ne se termine que si quelqu'un le
dit.

### Les cas qui font mal

**Le formateur modifie un contenu déjà terminé.** L'état ne change pas. Corriger
une faute de frappe ne doit pas réinitialiser vingt personnes. Si le changement
est substantiel, le formateur demande explicitement une reprise.

**Le parcours est réordonné.** Les états suivent leur séquence, pas leur position.
Déplacer un module ne rouvre rien.

**Un stagiaire rejoint en cours de route.** Il commence au début, avec les règles
d'ouverture qui s'appliquent à lui. Le formateur peut le débloquer plus loin.
Aucun rattrapage automatique.

**Il quitte puis revient.** Sa progression est **conservée**. Elle décrit ce qui
s'est passé — même doctrine que les votes de Sondage et les réponses d'Événement,
qui survivent au départ depuis TASK-1087.

**Une séquence est supprimée.** Elle s'archive, elle ne se supprime pas dès qu'un
état existe. Même règle que les Sondages votés et les Événements répondus.

---

## 10. Les Cards de la Formation

### Le cadre commun

| | Clé | Présentation |
|---|---|---|
| ChatLoop | — | le centre, toujours |
| Manifeste | `core.manifesto` (existe) | compact, permanent — porte le Programme |
| Membres | `core.members` (existe) | compact, permanent — avatars, nombre, accès à la liste |

### Les trois Cards distinctives

Conformément à la matrice canonique.

| Emplacement | Card | Clé proposée | Statut |
|---|---|---|---|
| 1 | **Support de cours** | `training.course` | **obligatoire** |
| 2 | **Progression** | `training.progress` | **obligatoire** |
| 3 | **Travaux à rendre** *ou* **QCM** | `training.assignments` / `training.assessments` | **au moins l'un des deux** |

#### Le troisième emplacement est contraint, pas libre

Une Formation doit activer **au minimum l'un des deux**, et peut activer les deux
localement.

*Pourquoi une contrainte plutôt qu'un choix libre.* Une formation où l'on ne rend
rien et où l'on n'est jamais évalué n'est pas une formation : c'est une
bibliothèque, et le Dossier racine y suffit. Le troisième emplacement est
précisément ce qui distingue les deux.

*Pourquoi deux Cards et non une.* **Travaux à rendre et QCM sont deux métiers.**
Un Travail est un dépôt, une lecture humaine, un retour rédigé, une reprise
possible. Un QCM est un barème, des tentatives, un seuil, une correction
automatique. Les réunir sous un seul nom obligerait chaque écran à demander
« lequel des deux ? » avant de savoir quoi montrer.

*Ce que cela implique pour le preset livré.* Le socle du type propose l'un des
deux par défaut — **Travaux à rendre**, le plus universel : toutes les formations
ne notent pas, mais presque toutes font produire quelque chose. Un formateur qui
veut du QCM l'active localement, avec ou sans les Travaux.

### Ce qui n'est pas une Card de Formation

**Ni Module ni Séquence.** Ce sont des contenus internes au parcours, rendus par
le Support de cours. Une Card par module ferait une grille illisible dès la
troisième semaine, et contredirait la règle des trois.

**Ressources — non plus.** Les supports vivent dans le **Dossier racine** de la
Boucle, livré par TASK-1082, avec ses fichiers et ses Articles. Créer une Card
Ressources ajouterait un quatrième outil pour ranger ce qui est déjà rangé.

**Sondage et Événements** restent des Cards génériques, activables localement.
Elles ne font pas partie du preset Formation et ne deviennent jamais
pédagogiques par défaut.

---

## 11. Ce qui se réutilise, et ne se réinvente pas

La matrice fait apparaître les mêmes Cards dans plusieurs presets. Aucune n'a de
version « pédagogique » séparée.

| Card de la matrice | Ce qu'elle réutilise |
|---|---|
| **Manifeste** | le **document racine** et `LoopRootDocumentService` (TASK-1082). En Formation, il porte le libellé « Programme de formation », déjà configuré. |
| **Dossiers** | le système **Dossier** existant et le **Dossier racine** de la Boucle, avec ses fichiers, ses Articles et ses membres (TASK-1082 à TASK-1085). |
| **Article** | le module Article complet : éditeur TipTap, Séries, co-écriture, snapshots, annotations (TASK-1084, TASK-1085). Pas de second éditeur. |
| **Roadmap** | **une seule Card technique.** Les variantes entre presets — « Engagements », « Suivi de coaching » — sont des **presets de vocabulaire et de colonnes**, pas des Cards distinctes. |
| **Sondage** | déjà développé et livré (TASK-1087). Nominatif, sans bonne réponse. |
| **Événements** | déjà développé et livré (TASK-1088). Formats, fuseaux, présence, agenda. |
| **Membres** | la Card existante, présentée en compact. |

**Conséquence pour Formation.** Le Support de cours s'appuie sur les Articles et
le Dossier racine ; les Travaux et le QCM s'inspirent de la forme du Sondage sans
partager son modèle métier. Tous trois restent à écrire — modèles, tables,
écrans — mais avec un précédent sous les yeux.

**La progression, elle, n'a aucun précédent.** C'est la seule capacité transverse
que le produit n'a jamais eue, et c'est ce qui justifie de lui consacrer sa propre
tranche.

---

## 12. L'expérience stagiaire

La Card **« Progression »**, vue stagiaire, répond à une seule question :

> **Que dois-je faire maintenant ?**

Elle montre : un pourcentage indicatif, les modules terminés, le module en cours,
la prochaine séquence, les travaux à rendre, les QCM disponibles, ce qui est
bloqué **et pourquoi**, et un bouton d'action principal.

### Tout le parcours reste visible

**Décision arrêtée.** Le stagiaire voit **tous** les Modules, **toutes** les
Séquences principales, leur état, ce qui est disponible, ce qui est bloqué, **la
raison du blocage** et **la condition d'ouverture**.

Ce qu'il ne voit pas : le **contenu détaillé** d'une Séquence bloquée. La
structure se comprend ; les règles d'accès ne se contournent pas.

**C'est ce qui rend le parcours séquentiel supportable.** Un verrou qu'on voit
venir, avec sa condition écrite, est un plan. Un verrou sans explication est une
impasse — et une étape simplement absente n'est rien du tout.

« Disponible après avoir réussi le QCM du module 2 » est une consigne. C'est ce
que la Card doit écrire, pas « indisponible ».

---

## 13. Le tableau du formateur

**La même Card « Progression », vue formateur.** Liste des stagiaires,
progression globale, module en cours, dernière activité, travaux en attente, QCM
à valider, blocages, prochaine étape, absence d'activité.

C'est la même question — *où en est-on ?* — posée depuis deux places. Deux Cards
obligeraient à choisir laquelle montrer selon le rôle, ce que le socle ne sait pas
faire et n'a pas à apprendre. Et cela consommerait deux des trois emplacements
distinctifs pour une seule idée.

Deux entrées — par stagiaire, par module — et des filtres simples. **Pas d'outil
analytique.** Un formateur veut savoir qui a besoin de lui, pas produire un
rapport.

---

## 14. ChatLoop

ChatLoop reste le centre vivant de la Boucle. Le motif existe depuis TASK-1087 et
a servi deux fois : un `type` propre, un `metadata` structuré, la vue qui branche
dessus, un bouton qui ouvre la Card.

**Annoncé :** ouverture d'un module, publication d'un travail, rappel d'une
évaluation, validation d'une étape, demande de reprise, événement pédagogique.

**Jamais annoncé :** une séquence terminée, une ouverture de contenu, un
changement mineur. Une formation à vingt personnes produirait des centaines de
lignes et personne ne lirait plus rien.

**Une validation est annoncée au stagiaire concerné, pas à la Boucle.** Publier
« Untel a validé le module 3 » exposerait le rythme de chacun devant tout le
monde — un stagiaire en difficulté n'a pas à l'être en public.

> Le rafraîchissement temps réel de ChatLoop depuis les Cards est une dette
> transverse connue (constatée en TASK-1087, toujours ouverte). **Hors scope.**

---

## 15. Sondages et Événements

**Sondage** — recueillir un avis, choisir un horaire, vérifier une compréhension
informelle. Il ne devient pas un QCM : pas de bonne réponse, pas de score,
pas de tentative, et il est nominatif.

**Événement** — classe en ligne, atelier, séance physique, permanence,
soutenance. La Card reste générique.

**Lier un Événement à un Module ?** Non au MVP. La séance apparaît dans l'agenda,
et le module la mentionne dans son texte. Un lien structurel demanderait une
migration sur une Card qui vient d'être livrée, pour un confort qu'on n'a pas
encore mesuré. À rouvrir si l'usage le réclame.

**Aucune modification de ces deux Cards dans les tâches Formation.**

---

## 16. Permissions

Bornées, adossées aux rôles réels, jamais une par bouton.

| Permission | Qui l'a par défaut | Lecture ? |
|---|---|---|
| `training.view` | stagiaire, animateur, propriétaire | **oui** (`read`) |
| `training.manage_program` | animateur, propriétaire | non |
| `training.manage_content` | animateur, propriétaire | non |
| `training.track_progress` | animateur, propriétaire | **oui** (`read`) |
| `training.submit_work` | stagiaire, animateur, propriétaire | non |
| `training.review_work` | animateur, propriétaire | non |
| `training.take_assessment` | stagiaire, animateur, propriétaire | non |
| `training.grade_assessment` | animateur, propriétaire | non |
| `training.override_progress` | animateur, propriétaire | non |

`training.manage_program` est distincte de `manage_content` : le Programme est le
texte de référence, sa modification n'a pas le même poids que renommer une
séquence.

Toutes déclarent `requires_card` sur la Card qui les porte. Seules les deux
lectures portent `read` — le reste échoue fermé sur une Boucle archivée, ce qui
est le bon défaut depuis TASK-1086.

---

## 17. Modèle conceptuel

Conceptuel. **Aucune migration n'accompagne ce document.**

| Objet | Responsabilité | Org | Loop | MVP ? |
|---|---|:--:|:--:|:--:|
| `Loop` | la Formation elle-même | oui | — | existe |
| `TrainingModule` | une grande étape | oui | oui | **T1** |
| `TrainingSequence` | une unité de travail | via module | via module | **T1** |
| `TrainingProgress` | l'état d'une personne sur une séquence | oui | oui | **T2** |
| `TrainingAssignment` | l'énoncé d'un Travail | via séquence | — | **T3** |
| `TrainingSubmission` | ce qu'une personne a rendu | oui | — | **T3** |
| `TrainingAssessment` | un QCM | via séquence | — | **T4** |
| `TrainingQuestion` | une question et ses options | via QCM | — | **T4** |
| `TrainingAttempt` | une tentative | oui | — | **T4** |
| ~~`TrainingResource`~~ | **abandonné** — le Dossier racine range déjà | — | — | non |

### Ce qui n'existera pas

**`TrainingEnrollment` — non.** `LoopMember` inscrit déjà, avec ses rôles, ses
statuts et sa gouvernance. Une seconde table d'inscription ferait deux vérités sur
« qui suit cette formation ».

**Un état par module — non.** Il se calcule depuis ses séquences. Le stocker
créerait une donnée dérivée à maintenir cohérente, donc à désynchroniser.

### Règles communes

`organization_id` sur tout ce qui se lit ou se compte directement — même règle que
`loop_poll_votes` et `loop_event_responses`. Les tables filles n'en portent pas :
elles ne sont atteignables qu'à travers leur parent.

Un état par personne et par séquence, **contrainte d'unicité en base** — pas une
précaution applicative. C'est ce que TASK-1087 et TASK-1088 ont établi et qui a
tenu.

Archivage plutôt que suppression dès qu'un état existe.

---

## 18. Découpage proposé

### Tranche 1 — Programme, Modules et Séquences

Le type devient disponible. Programme racine, constructeur simple, lecture
stagiaire, ordre, disponibilité de base (règles 1 et 2). Card **« Support de
cours »**.

**Pas de progression** : on peut lire un parcours sans le suivre.

À l'issue de cette tranche, le preset Formation ne remplit que deux de ses trois
emplacements. Il ne devrait donc pas être ouvert au public avant la tranche 2 —
un type disponible qui n'apporte pas ce qu'il promet est exactement ce que le
commentaire de `config/loop_types.php` reproche aujourd'hui.

### Tranche 2 — La progression individuelle

Card « Progression ». Marquer terminé, déblocage, tableau formateur, validation
manuelle, règles 3 et 4.

Pas de Card Ressources : les supports restent dans le Dossier racine.

### Tranche 3 — Travaux à rendre · Tranche 4 — QCM

**Recommandation : séparer.** Un Travail est un dépôt et un retour humain. Un QCM
est un barème, des tentatives, un seuil et une correction automatique. Ce sont
deux métiers ; les faire ensemble donnerait une tâche du double de TASK-1088, qui
était déjà la plus lourde de la série.

La tranche 3 remplit le troisième emplacement du preset. La tranche 4 l'enrichit
sans être indispensable au lancement.

---

## 19. Critères d'acceptation

**Créer une Formation.** *Étant donné* un membre autorisé à créer une Boucle,
*quand* il choisit le type Formation, *alors* la Boucle est créée avec son
Programme racine intitulé « Programme de formation » et le socle du type.

**Construire.** *Étant donné* un animateur, *quand* il ajoute un module puis deux
séquences, *alors* elles apparaissent dans l'ordre pour tous les membres.

**Un stagiaire ne construit pas.** *Étant donné* un membre simple, *quand* il
ouvre le Support de cours, *alors* il lit sans pouvoir modifier, et une route
directe est refusée.

**Affichage individualisé.** *Étant donné* deux stagiaires d'avancées
différentes, *quand* chacun ouvre « Progression », *alors* chacun voit son
propre état et **jamais** celui de l'autre.

**Terminer.** *Quand* un stagiaire clique « J'ai terminé », *alors* son état passe
`completed` et la séquence suivante devient disponible.

**Séquentiel par défaut.** *Étant donné* une Formation nouvellement créée sans
réglage particulier, *quand* un stagiaire ouvre le parcours, *alors* seul le
premier module est accessible et les suivants sont fermés.

**Parcours libre.** *Étant donné* une Formation configurée en parcours libre,
*quand* un stagiaire l'ouvre, *alors* tout est accessible d'emblée.

**Voir sans pouvoir entrer.** *Étant donné* un module fermé, *quand* un stagiaire
regarde le parcours, *alors* il voit son titre, son état, la raison du blocage et
la condition d'ouverture — **et pas** son contenu détaillé, y compris par une
route directe.

**Un QCM bloquant.** *Étant donné* un QCM configuré comme condition de passage,
*quand* un stagiaire échoue, *alors* la suite reste fermée ; *quand* il réussit,
*alors* la condition est satisfaite.

**Un QCM informatif.** *Étant donné* un QCM configuré comme informatif, *quand*
un stagiaire échoue, *alors* la suite reste ouverte.

**Le score, tout de suite.** *Quand* un stagiaire termine une tentative, *alors*
il voit son score et sa réussite ou son échec, quel que soit le réglage de
correction.

**Le détail, selon le réglage.** *Étant donné* un QCM réglé sur « après la
dernière tentative », *quand* il reste des tentatives, *alors* le détail des
bonnes réponses n'est pas montré.

**Une date limite sur un Travail.** *Étant donné* un Travail avec échéance,
*quand* elle est dépassée, *alors* la remise est **signalée en retard sans être
refusée**.

> Point mineur à confirmer au moment de la tranche 3 : une échéance qui ferme la
> remise appellerait une procédure de dérogation. Signaler suffit, et laisse au
> formateur le dernier mot — ce qui est la ligne de toute cette spécification.

**Débloquer.** *Quand* un formateur débloque manuellement, *alors* l'étape s'ouvre
**même si** une règle automatique la fermait — y compris un QCM échoué.

**Reprendre.** *Quand* un formateur demande une reprise, *alors* l'état passe
`redo`, l'historique est conservé, et le stagiaire peut re-remettre.

**Modifier sans casser.** *Quand* un formateur corrige le texte d'une séquence
terminée par vingt personnes, *alors* aucun état ne change.

**Réordonner sans casser.** *Quand* un formateur déplace un module, *alors* les
états suivent leur séquence et rien ne se rouvre.

**Rejoindre en cours.** *Quand* un stagiaire rejoint après trois semaines, *alors*
il commence au début, et le formateur peut le placer plus loin.

**Partir et revenir.** *Quand* un stagiaire quitte puis revient, *alors* sa
progression est retrouvée intacte.

**Boucle archivée.** *Alors* tout est lisible et rien n'est écrivable — ni
terminer, ni remettre, ni valider.

**Card désactivée.** *Alors* elle disparaît du workspace, les données sont
conservées, les routes directes refusées, et la réactivation retrouve tout.

**Tenant.** *Quand* quelqu'un d'une autre Organization forge un identifiant,
*alors* il est refusé.

---

## 20. Les six décisions produit — arrêtées

Validées par Cyril le 2026-08-06. Elles ne sont plus ouvertes ; elles sont ici
avec leur raison, pour que les tâches d'implémentation n'aient pas à les
redécouvrir.

### D1 — Une Formation est une session

Une Boucle Formation représente une **session réelle** : ses stagiaires, son
formateur, ses dates éventuelles, sa progression, ses Travaux, ses QCM.

**Le modèle réutilisable, c'est le type `training` et son preset.** Refaire la
formation l'an prochain, c'est une nouvelle Boucle créée depuis le même preset.
Aucune notion de « modèle de formation » distincte dans le MVP.

*Ce que cela évite :* duplication, versions, et désynchronisation entre un modèle
et ses instances — un chantier entier pour un besoin que le preset couvre déjà.

### D2 — Le parcours est séquentiel par défaut

Les Modules s'enchaînent : le suivant reste fermé tant que les conditions du
précédent ne sont pas satisfaites.

> Module 1 → réussite ou validation humaine → Module 2

Le formateur peut débloquer manuellement. Une Formation peut être configurée en
**parcours libre**. Aucune logique directe sur `$loop->type`.

*Cette décision renverse ma recommandation initiale*, qui proposait l'ouverture
par défaut. Elle est la bonne : une formation **conduit** quelqu'un quelque part,
et c'est ce qui la distingue d'une bibliothèque. La frustration que je redoutais
est traitée par D3 — on voit le chemin, même fermé.

### D3 — Toute la structure reste visible

Le stagiaire voit tous les Modules, toutes les Séquences principales, leur état,
ce qui est ouvert, ce qui est bloqué, **la raison** et **la condition
d'ouverture**.

Il ne voit pas le **contenu détaillé** d'une Séquence bloquée.

*L'objectif :* comprendre le parcours sans pouvoir contourner les règles d'accès.

### D4 — Les dates limites ne concernent que les Travaux

Un Travail peut porter une échéance. Ni Module ni Séquence n'en portent dans le
MVP.

Les séances, ateliers et rendez-vous pédagogiques passent par la **Card
Événements**, déjà livrée.

*Ce que cela évite :* rappels, retards, pénalités — une mécanique entière que
personne n'a demandée.

### D5 — Un QCM est informatif ou bloquant, au choix

Configuré **QCM par QCM**. Bloquant, l'échec ferme la suite et la réussite
satisfait la condition. Le formateur peut toujours valider ou débloquer à la
main.

**L'IA ne débloque jamais.** Elle suggère, pré-évalue, résume. La validation
humaine reste prioritaire, sans exception.

### D6 — Score immédiat, correction réglable

Après une tentative, le stagiaire voit **toujours** son score et sa réussite ou
son échec.

Le détail des bonnes réponses suit un réglage du formateur : immédiat, après la
dernière tentative, ou après clôture/validation. **Trois valeurs, pas un
moteur.**

---

## 21. Hors scope

SCORM, LTI, xAPI, certificats, certification, paiement, catalogue commercial,
visioconférence intégrée, IA de correction, notifications email ou push,
synchronisation calendrier externe, badges, classements, forums séparés de
ChatLoop, sous-groupes, promotions multiples dans une même Boucle, migration
Community, correctif du rafraîchissement ChatLoop.

---

## 22. Ce que cette spécification ne change pas

Organization = Tenant. Loop ≠ Tenant. Community reste une dette. ChatLoop reste
le centre vivant. Les Cards sont des capacités, pas des espaces isolés. **Aucune
condition métier sur `$loop->type`** — la Formation se construit avec des Cards et
des permissions, comme tout le reste.

Rien ici ne contredit TASK-1080 à TASK-1088, et rien ne demande de modifier
`docs/product/BOUCLE_ARCHITECTURE.md` : aucune règle architecturale durable
nouvelle n'apparaît.

---

## 23. En une phrase

**La structure est commune, l'état est individuel, et le formateur a toujours le
dernier mot.**
