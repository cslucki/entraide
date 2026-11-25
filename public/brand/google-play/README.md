# Kit graphique Google Play — BouclePro

Ressources de la fiche Google Play de l'application Android BouclePro (TASK-1902).
Elles ne sont référencées par aucune page du site : elles sont téléversées à la main dans la Play Console.

## Fichiers

| Fichier | Format | Champ de la Play Console |
|---|---|---|
| `icon-512.png` | 512 × 512, PNG 32 bits | Icône de l'application (commune FR et EN) |
| `fr/feature-graphic-1024x500.png` | 1024 × 500, PNG 24 bits, sans transparence | Image de présentation (français) |
| `en/feature-graphic-1024x500.png` | 1024 × 500, PNG 24 bits, sans transparence | Feature graphic (English, en-US) |
| `fr/screenshots/01..05.png` | 1080 × 1920 (9:16), PNG 24 bits | Captures d'écran du téléphone (français) |
| `en/screenshots/01..05.png` | 1080 × 1920 (9:16), PNG 24 bits | Phone screenshots (English, en-US) |

Ordre des captures :

| # | Écran | Accroche FR | Accroche EN |
|---|---|---|---|
| 01 | Flux | Un collectif qui avance ensemble | A community moving forward |
| 02 | Boucle « Atelier numérique du quartier » | Échangez et entraidez-vous | Connect and help each other |
| 03 | Entraide | Demandez. Proposez. Progressez. | Ask. Offer. Grow. |
| 04 | Annuaire | Découvrez les compétences du collectif | Discover community skills |
| 05 | Agenda | Retrouvez-vous autour de projets | Come together around projects |

Feature graphic : « Ne plus avancer seul » (FR), « Move forward together » (EN).

## Provenance

- **Captures réelles** de l'interface BouclePro 1.901, thème sombre, prises le 25/11/2026 avec Playwright (fenêtre 360 × 640, densité 3) sur un environnement local, à partir d'une copie de la production anonymisée.
- Organisation **fictive** « Collectif Horizon » et membre **fictif** Julien Lefèvre (membre simple). Aucune donnée personnelle réelle.
- Version anglaise : interface en anglais (langue choisie dans l'application) et contenus fictifs traduits en anglais dans l'environnement local seulement (organisation « Horizon Collective », messages, annonces, événements, demandes, catégorie « Mutual aid »). Les noms des membres fictifs sont conservés.
- Mise en forme : accroche en Inter 800 et capture d'origine réduite à 840 px de large dans un cadre arrondi. Le dégradé reprend celui de la Feature Graphic du 24/11 (`#1b1fcc` → `#0c1135`).
- Icône : celle de l'application Android (`store_icon.png` du projet TWA), même dessin que les icônes PWA `public/brand/icon-512.png`.

## Contrôles (25/11/2026)

Dimensions, format PNG, absence de transparence (sauf l'icône) et poids, vérifiés contre les limites de la Play Console :
- icône ≤ 1 Mo ;
- image de présentation ≤ 15 Mo ;
- captures ≤ 8 Mo, ratio ≤ 2:1.

Tous les fichiers sont conformes. Le plus lourd pèse 541 Ko.

## Limites connues

- Thème sombre uniquement. Pas de capture tablette ni de vidéo (facultatives).
- L'icône contient déjà ses coins arrondis et son ombre ; Google Play applique aussi son propre masque.
- La barre de navigation du bas suit l'écran actif : ses onglets visibles varient d'une capture à l'autre (comportement réel de l'application).
