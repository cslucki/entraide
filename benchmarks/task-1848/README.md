# TASK-1848 — Outils de benchmark Reverb

Résultats et lecture : `report.md`. Ce fichier documente comment
reproduire chaque mesure.

## Pré-requis

- Jumeau local PostgreSQL deja opérationnel (`.env` pointe sur
  `bouclepro_prod_work`).
- `node` (le dépôt utilise déjà `laravel-echo`, `pusher-js`, `playwright`
  comme dépendances npm — `npm install` suffit).
- Reverb local démarré : `./ai/scripts/reverb-local.sh start` (en arrière
  plan, ex. `... start &`).

## 1. Semer les données de test

```
php artisan db:seed --class=Database\\Seeders\\Task1848BenchmarkSeeder
```

Idempotent (relançable sans effet). Crée une Organization
`benchmark-task-1848`, 100 comptes `benchmark1848.userNNN@example.test`
(mot de passe `password`), une Boucle avec les 100 comme membres, et 50
paires conversation-directe + échange-d'entraide. Écrit
`storage/app/benchmarks/task-1848-fixtures.json` (hors dépôt), lu par tous
les scripts ci-dessous.

## 2. Scripts

- `ws-clients.mjs` — N clients WebSocket RÉELS (`laravel-echo`/`pusher-js`,
  authentification HTTP réelle). `--config=A` ne se connecte jamais
  (attendu, les 4 pilotes sont éteints) ; `--config=B` se connecte et
  écoute les 4 canaux réels. Nécessite `--reverbKey=<REVERB_APP_KEY>`.
- `http-polling.mjs` — N onglets Chromium headless RÉELS (Playwright),
  connexion par le VRAI formulaire de login, navigation vers la Boucle,
  une conversation directe, un échange d'entraide, ou le tableau de bord
  selon le rôle. Mesure le trafic `wire:poll` via le log d'accès Apache.
- `fire-messages.php` — envoie des messages RÉELS par les mêmes chemins de
  code que `LoopChat`/`MessageThread` (vérifiés ligne à ligne avant
  réutilisation), avec diffusion Reverb explicite. `notifierApresCommit()`
  (email) est délibérément exclu — voir `report.md` section 10.
- `sample-resources.sh` — échantillonne CPU/RAM (Apache, Reverb) + delta
  `pg_stat_database` toutes les 2s jusqu'à `SIGTERM`.
- `run-scenario.sh` — orchestre un scénario complet (échantillonnage +
  clients WS + onglets HTTP + rafale de messages à mi-fenêtre).
- `analyze.mjs` — agrège les fichiers de `results/` en chiffres (lecture
  seule, ne relance rien).

## 3. Reproduire un scénario

```bash
# Config A (pilotes OFF — etat par defaut, aucune action necessaire)
./benchmarks/task-1848/run-scenario.sh A 10 45 benchmarks/task-1848/results A-S1

# Config B : poser les 4 drapeaux a true dans .env (LOOP_CHAT_REALTIME_ENABLED,
# NOTIFICATIONS_REALTIME_ENABLED, CONVERSATION_REALTIME_ENABLED,
# MESSAGES_UNREAD_REALTIME_ENABLED), PUIS reconstruire les assets :
#   php artisan config:clear && npm run build && php artisan view:clear
./benchmarks/task-1848/run-scenario.sh B 10 45 benchmarks/task-1848/results B-S1

# Restaurer A ensuite (les 4 drapeaux a false, rebuild) pour revenir a
# l'etat par defaut livre.
```

Pour un test WebSocket seul à plus grande échelle (sans Playwright, donc
sans le plafond Apache documenté dans `report.md` section 1) :

```bash
node benchmarks/task-1848/ws-clients.mjs --count=100 --duration=30 \
  --config=B --reverbKey=<REVERB_APP_KEY> --out=results/custom.ws.jsonl
```

## 4. Nettoyage

```bash
# Arreter Reverb local
./ai/scripts/reverb-local.sh stop

# Supprimer les donnees de benchmark (optionnel — elles sont inertes et
# clairement identifiees, aucune obligation de les retirer)
php artisan tinker
>>> $org = \App\Models\Organization::where('slug', 'benchmark-task-1848')->first();
>>> \App\Models\User::where('organization_id', $org->id)->get()->each(fn($u) => $u->delete());
>>> $org->delete(); // cascade sur Loop/Transaction/Message par les FK du schema
```

## 5. Limites

Voir `report.md` section 10 (limites de validité) et section 6 (non
mesurable — `pg_stat_statements` absent, plafond Apache partagé).
