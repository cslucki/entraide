# TASK-1785 — recette détaillée à faire, consignée pour plus tard

**08/11/2026, 02h15 (Paris)** · `TASK-1785` fusionnée dans `develop` · VERSION **1.785**

Cyril a validé une recette courte (« Recette ok ») et demandé que la recette
détaillée soit **consignée pour plus tard**. Ce fichier est cette liste. Il est
versionné, donc retrouvable sur GitHub.

---

## 0. Préalables — à lire avant de commencer

| Quoi | Valeur |
|---|---|
| Écran | `https://test.laravel/admin/outils/import-rss` |
| Qui | un compte **SuperAdmin** (`is_admin`) — la route est dans le groupe `['auth', 'admin']` |
| Flux d'exemple | `https://cpformation.com/feed/` (WordPress public, RSS 2.0) |
| **Drapeau obligatoire** | `RECETTE_IMPORT_RSS=true` dans le `.env` de `test.laravel` |

⚠️ **Sans ce drapeau, aucun chargement de flux ne fonctionnera** et l'écran
rendra un refus de sortie. Ce n'est pas un défaut : `ExternalEgressGuard` (T1724)
bloque toute sortie réseau quand la base vise un jumeau de production, et le
drapeau est la dérogation prévue (`SortieDeRecette`). Il est **faux par défaut**
et **inerte hors `local`** — donc inopérant en production, par construction.

Le `.env` n'est pas versionné : si le fichier a été réinitialisé, la ligne a
disparu et il faut la remettre.

---

## 1. Ce qui est DÉJÀ prouvé — ne pas le refaire

Mesuré en recette réelle le 08/11, captures dans `_local/captures/TASK-1785/` :

- le parcours complet URL → lecture → sélection → import, sur un vrai flux ;
- **anti-doublons à l'écran** : second import des mêmes articles →
  « 8 créé(s), **2 déjà importé(s)**, 0 erreur(s) », les deux premiers listés
  sous « DÉJÀ IMPORTÉS, IGNORÉS » ;
- en base après import : statut `draft`, `published_at` NULL,
  `source_published_at` = la vraie date du flux, identité `guid:`, Boucle liée,
  attribution présente, **aucun `<script>`** ;
- la navigation réciproque entre l'administration du blog et l'écran d'import ;
- les 10 articles importés puis **publiés** dans le blog d'OFSH (voir §4).

Détail complet dans `Report/261108-01h06-Task1785-report4.md`.

---

## 2. Ce qui reste à éprouver — par ordre d'utilité

### 2.1 Les refus de la garde SSRF, **à l'écran** — le plus important

Ils sont couverts par des tests automatisés, mais **jamais vus dans
l'interface**. C'est là que se cache le genre de panne silencieuse que T1785 a
déjà produite deux fois (une clé de langue brute affichée telle quelle).

Coller chaque adresse dans le champ, charger, et vérifier que le message est
**en français et lisible** — jamais une clé brute type
`admin.import_rss.erreurs.…`, jamais un message de transport :

| Adresse à saisir | Message attendu |
|---|---|
| `http://localhost/feed` | « Cette adresse désigne la machine locale : elle est refusée. » |
| `http://127.0.0.1/feed` | idem |
| `http://192.168.1.1/feed` | « Cette adresse pointe vers un réseau privé ou réservé… » |
| `http://169.254.169.254/latest/meta-data/` | idem — **c'est la plage des métadonnées d'instance**, celle qui compte vraiment |
| `ftp://exemple.fr/feed` | « Seules les adresses http et https sont acceptées. » |
| `https://user:motdepasse@exemple.fr/feed` | « Une adresse de flux ne doit pas contenir d'identifiants. » |
| `https://ce-domaine-n-existe-vraiment-pas-12345.fr/feed` | « Le nom de domaine du flux n'a pas pu être résolu. » |
| `https://cpformation.com/` (page HTML, pas un flux) | « Aucun article n'a été trouvé… » ou « pas un XML lisible » |

**Ce qu'on cherche** : un refus **lisible**. Un refus qui s'affiche en clé brute
est un défaut à signaler, même si la garde a bien fait son travail.

### 2.2 Isolation multi-tenant, à l'écran

C'est l'invariant le plus coûteux à casser, et le moins visible.

1. charger le flux, choisir l'Organization **A**, importer 2 articles ;
2. **sans recharger le flux**, changer pour l'Organization **B** — le sélecteur
   de Boucle doit **se réinitialiser** et ne proposer que les Boucles de **B**.
   Aucune Boucle de **A** ne doit rester visible ni sélectionnable ;
3. importer les **mêmes** 2 articles dans **B** → ils doivent être **créés**
   (l'unicité est **par Organization**, pas globale), et leur `slug` doit être
   **suffixé** côté B, parce que l'index unique sur `blog_posts.slug` est
   **GLOBAL** ;
4. vérifier dans chaque blog que « Voir l'article » pointe vers l'URL canonique
   de **SON** Organization — jamais `/org/main/blog/…` pour un article d'OFSH.

### 2.3 Les deux cases de publication

La publication exige **les DEUX** : l'intention de publier **et** la confirmation
des droits de republication. Vérifier les trois combinaisons :

| Publier | Droits confirmés | Résultat attendu |
|---|---|---|
| ☐ | ☐ | brouillon |
| ☑ | ☐ | **brouillon** — l'une sans l'autre ne publie pas |
| ☑ | ☑ | publié |

### 2.4 Un flux **Atom**

Le flux réel éprouvé est du RSS 2.0. L'Atom n'est couvert qu'en fixture — or
c'est précisément sur du réel que les quatre défauts du 08/11 sont sortis
(un espace avant `<?xml`, invisible en fixture). Trouver un WordPress servant de
l'Atom, ou `?feed=atom` sur un WordPress, et vérifier titre, auteur, lien et date.

### 2.5 Refus d'accès

Avec un compte **non SuperAdmin** (un membre ordinaire), ouvrir
`/admin/outils/import-rss` → **403**, en LECTURE comme en écriture.

### 2.6 Responsive et console — **non faites, assumé**

Les cases correspondantes du taskfile sont restées **décochées** exprès. C'est un
outil SuperAdmin de bureau, mais personne n'a regardé :

- l'écran sur un mobile (la grille de sélection, le sélecteur d'Organization) ;
- la console du navigateur pendant un chargement et un import (0 erreur attendue).

### 2.7 La limite assumée, à confirmer acceptable

Les URL de flux mémorisées le sont **par session** : elles se perdent à la
déconnexion. Se déconnecter, se reconnecter, vérifier que l'historique est vide,
et **dire si c'est acceptable**. Une mémoire durable tiendrait en une table de
deux colonnes — ce n'est pas écrit, c'est une décision produit qui attend.

---

## 3. Ce qui ne pourra PAS être éprouvé en production

Le drapeau `RECETTE_IMPORT_RSS` est **inerte hors `local`** : en production,
`SortieDeRecette` refuse la dérogation avant même de regarder le drapeau. L'outil
y fonctionnera parce qu'`ExternalEgressGuard` n'y bloque rien (la base n'est pas
un jumeau) — mais **la recette de la dérogation elle-même ne se rejoue pas en
production**, et c'est voulu.

---

## 4. État laissé derrière, et nettoyage

### 4.1 Les 10 articles de recette — **PUBLIÉS** dans OFSH sur le jumeau

Sur décision de Cyril le 08/11, les 10 articles importés depuis
`cpformation.com` ont été **publiés** dans le blog d'OFSH
(`https://test.laravel/org/ofsh/blog`), pour pouvoir être vus.

- repérables sans ambiguïté par **`source_guid` non nul** — critère sûr, qui ne
  touche aucun article écrit à la main ;
- `published_at` **espacé d'une seconde** dans l'ordre chronologique du flux :
  la colonne est à la seconde, et dix écritures successives n'avaient produit que
  **2 valeurs distinctes**, ce qui rendait l'ordre d'affichage arbitraire — le
  blog trie par `latest('published_at')` ;
- `source_published_at` **intact** : la date du flux n'a jamais été reprise dans
  `published_at`, qui dit quand **nous** publions.

**À décider plus tard** : les garder comme jeu de recette, ou les retirer. Ce
sont des articles **tiers** (contenu de cpformation.com, attribution et lien
vers la source présents). Si on les retire :
`BlogPost::whereNotNull('source_guid')` borné à l'Organization OFSH.

### 4.2 Le drapeau `.env`

`RECETTE_IMPORT_RSS=true` est dans le `.env` de `test.laravel` (non versionné).
Le **retirer** quand la recette détaillée est terminée. Il ne présente aucun
risque en lui-même — `local` seulement, hôte public exigé, et la garde SSRF
continue de refuser le bouclage — mais il n'a aucune raison de rester armé.

---

## 5. Dette signalée, non traitée dans T1785

`BlogSnapshotController:13,23` porte ses **propres copies** de
`ALLOWED_HTML_TAGS` et `ALLOWED_IFRAME_DOMAINS`. La sanitation a été extraite en
`App\Services\Blog\BlogHtmlSanitizer` pour `BlogController`, **pas pour
celui-là** : la duplication est **PRÉEXISTANTE** à T1785 et était hors périmètre.
Deux jeux de constantes qui doivent rester d'accord sans que rien ne le garantisse
— à traiter dans une TASK à part.
