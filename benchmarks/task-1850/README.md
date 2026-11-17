# TASK-1850 — outils de verification

Resultats et lecture complete : voir le TASK file
(`TODO/TASK-1850-securisation-finale-reverb-avant-production.md`, hors
depot) et le rapport de commit. Ce fichier documente seulement comment
reproduire les mesures.

## Pre-requis

Reverb local demarre (`./ai/scripts/reverb-local.sh start &`), les 4
drapeaux `*_REALTIME_ENABLED` a `true` dans `.env` + `npm run build` +
`php artisan view:clear`, et les donnees de benchmark semees
(`php artisan db:seed --class=Database\Seeders\Task1848BenchmarkSeeder`
— regenere `storage/app/benchmarks/task-1848-fixtures.json`, LU par ces
trois scripts).

**Toujours re-semer avant de relancer ces scripts** si la base locale a
pu changer depuis le dernier run (jumeau PROD, restauration, etc.) : un
`fixtures.json` perime pointe vers des identifiants qui n'existent plus
et produit des 404 silencieux cote Playwright.

## Scripts

- `reconnection-test.mjs --feature=loop|direct|entraide|badge` — ouvre un
  VRAI onglet Chromium headless authentifie, instrumente
  `window.Echo.connector.pusher.connection` (vrai client, pas le mock
  Node de TASK-1848), coupe Reverb, envoie un message reel pendant la
  coupure (`fire-messages.php`, TASK-1848), redemarre Reverb, mesure si
  la reconnexion automatique de `pusher-js` suffit SANS rechargement de
  page.
- `reconnection-extra-test.mjs` — deux scenarios supplementaires :
  navigation vers une autre page pendant la coupure, et retour d'un
  onglet mis en arriere-plan (Page Visibility API) pendant la coupure.
- `chatloop-polling-check.mjs` — observe les requetes reelles
  `/livewire-*/update` d'un onglet Boucle sur une fenetre de 50s, pour
  verifier EMPIRIQUEMENT que `chatloop-adaptive-polling.js` reduit bien
  3s→20s une fois abonne (verification de la divergence signalee par
  MASTER entre TASK-1848 et le code — voir le TASK file, le defaut
  constate etait dans le RAPPORT, pas dans le code).

## Mesures de reference obtenues (17/11/2026, ce jumeau)

- Timeout HTTP de diffusion (connexion refusee/figee) : 30019ms avant
  correctif, 2017ms apres (connexion etablie sans reponse), 1017ms
  (connexion refusee/inaccessible) — voir `config/broadcasting.php`
  (TASK-1850) pour le detail des deux reglages necessaires.
- Reconnexion automatique : 4/4 features reussies sans rechargement de
  page (loop, direct, entraide, badge), ~11s pour detecter la coupure,
  ~5s pour se reconnecter apres redemarrage de Reverb. Navigation et
  retour d'arriere-plan pendant la coupure : aucune exception JS
  bloquante, reconnexion reussie dans les deux cas.
- ChatLoop polling : 20,0s / 20,0s observes entre deux requetes apres
  les deux premieres (pendant la connexion) — confirme la reduction
  attendue, fenetre de 50s necessaire pour l'observer clairement au-dela
  du bruit de connexion initiale.
