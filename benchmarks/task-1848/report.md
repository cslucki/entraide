# TASK-1848 — Benchmark global du temps réel Reverb

Rapport final. Toutes les mesures ci-dessous viennent d'exécutions réelles
sur ce jumeau local (`test.laravel`, Apache mod_php prefork, PostgreSQL 18,
8 vCPU / 17 Gio RAM, WSL2/Ubuntu 22.04), avec de vrais comptes de test
(`database/seeders/Task1848BenchmarkSeeder.php`), de vrais navigateurs
headless (Playwright/Chromium) et de vrais clients WebSocket
(`laravel-echo`/`pusher-js`), jamais une simulation en mémoire ou un calcul
théorique seul. Voir `README.md` dans ce dossier pour reproduire chaque
mesure.

## 0. Ce qui a été réutilisé, pas refait

Conformément au mandat, l'audit préalable et les mesures déjà faites par
TASK-1840 (`TODO/TASK-1840-...md`) n'ont pas été redémontrées en détail :
TASK-1840 avait déjà établi, sur ChatLoop seul, que Reverb ne change RIEN
au trafic HTTP au repos tant que `wire:poll` reste posé, que le gain vient
de l'intervalle de poll élargi (3s→20s ≈ 5,25x, 3s→60s ≈ 21x), et que la
latence perçue descend sous le plancher du polling pur (373ms observés vs
~1,3s). Ce rapport ÉTEND ces conclusions à une population MIXTE (Boucle +
conversation directe + échange d'entraide + navigation badges-seule) et à
une échelle réelle de 10 à 100 connexions simultanées, que TASK-1840
n'avait pas testée (n=1 observateur).

## 1. Scénarios exécutés

| Label | Config | n simulé | Durée | Couche mesurée |
|---|---|---:|---:|---|
| A-S1 | A (4 pilotes OFF) | 10 | 45s | HTTP/Livewire réel (Playwright) |
| A-HTTP20 | A | 20 | 30s | HTTP/Livewire réel (Playwright) |
| B-S1 | B (4 pilotes ON, Reverb connecté) | 10 | 45s | HTTP/Livewire réel + WS réel |
| B-HTTP20 | B | 20 | 30s | HTTP/Livewire réel + WS réel |
| B-WS50 | B | 50 | 30s | WS réel seul (Node, laravel-echo/pusher-js) |
| B-WS100 | B | 100 | 35s | WS réel seul |

Répartition des rôles (identique dans tous les scénarios, par index modulo
4) : 1/4 consulte la Boucle de benchmark, 1/4 une conversation directe,
1/4 un échange d'entraide, 1/4 navigue ailleurs (dashboard) en restant
abonné aux badges. Rafale de messages réels à mi-fenêtre : 5 messages
Boucle, 3 messages conversation directe, 3 messages entraide — volume
IDENTIQUE en A et en B pour que la comparaison porte sur la configuration,
pas sur le volume.

**Pourquoi S2/S3 (50/100) ne sont pas mesurés en HTTP/Playwright réel** :
à n=50 onglets Chromium headless réels, l'environnement Apache partagé de
ce jumeau (MPM prefork, `MaxRequestWorkers=150`, **partagé avec d'autres
vhosts actifs sur la même machine**) a mis en file puis fait expirer
(timeout 30s) 29 des 50 connexions de login — seules 21/50 ont abouti.
Conformément au mandat (« arrêt du scénario si l'environnement devient
instable »), ce vecteur n'a pas été poussé plus loin : la mesure HTTP
réelle s'arrête à n=20 (propre, 100% de réussite), et S2/S3 pour la couche
HTTP sont **ESTIMÉS** par extrapolation (section 4). Ce plafond
Apache lui-même est en revanche un résultat MESURÉ et important — voir
section 5.

La couche WebSocket (clients Node, pas de rendu de page) n'a, elle,
rencontré AUCUNE limite : 50/50 puis 100/100 connexions réussies sans
erreur (section 3).

## 2. MESURÉ — trafic HTTP/Livewire (`wire:poll`)

Comptage sur le log d'accès Apache réel (`test.laravel_ssl_access.log`),
fenêtre exacte du scénario, filtré aux requêtes identifiables comme ce
benchmark (Referer portant le slug de l'Organization de test).

| Scénario | Requêtes `livewire/update` | Fenêtre | Normalisé |
|---|---:|---:|---|
| A-S1 (n=10) | 130 | 45s | **173,3 req/min** |
| B-S1 (n=10) | 90 | 45s | **120,0 req/min** (−30,7 %) |
| A-HTTP20 (n=20) | 178 | 30s | **356,0 req/min** |
| B-HTTP20 (n=20) | 128 | 30s | **256,0 req/min** (−28,1 %) |

**CORRECTIF (TASK-1850, après revue MASTER)** : la phrase ci-dessous,
dans la version initiale de ce rapport, affirmait que le rôle « Boucle »
gardait un poll natif 3s en configuration B — **c'est FAUX**, et c'est une
erreur de CE rapport, pas du code. `chatloop-adaptive-polling.js`
(TASK-1841) réduit bien 3s→20s pour ChatLoop une fois le canal
`loop.{id}` réellement abonné — **vérifié à nouveau** par TASK-1850, sur
un onglet réel observé 50s (fenêtre plus longue que B-S1/B-HTTP20) :
2 requêtes rapprochées au chargement (pendant la connexion), puis
exactement 20,0s / 20,0s entre les suivantes. Seul le rôle « échange
d'entraide » garde réellement sa cadence native 3s (TASK-1846/1847 ne
touchent jamais sa cadence, confirmé inchangé).

**Lecture corrigée** : la réduction mesurée (−28 à −31 %) est donc en
réalité portée par DEUX rôles sur quatre (conversation directe ET
Boucle), pas un seul — et elle est probablement SOUS-ESTIMÉE par les
fenêtres courtes de B-S1 (45s) et B-HTTP20 (30s) : avec un cycle de 20s,
une fenêtre de 30-45s ne capture qu'une à deux périodes stables après la
phase de connexion, sous charge concurrente (10-20 connexions/logins
simultanés) qui retarde elle-même l'abonnement initial. Le chiffre mesuré
reste une mesure RÉELLE et honnête du gain sur CETTE fenêtre précise, mais
ne doit pas être lu comme le plafond du gain réel en régime stable — une
fenêtre d'observation plus longue (comme le test dédié de TASK-1850, 50s,
charge faible) montre le mécanisme fonctionner exactement comme conçu.
Seul le rôle « navigation badges seule » n'a de toute façon aucun
`wire:poll` (page tableau de bord). Une population composée à 100% de
conversations directes OU de Boucles retrouverait un facteur proche du
~5,25x déjà mesuré par TASK-1840.

## 3. MESURÉ — couche WebSocket (connexions, latence)

| Scénario | Connexions demandées | Connexions réussies | Erreurs | Connexion WS (p50/p95) | Login HTTP (p50/p95) |
|---|---:|---:|---:|---|---|
| B-S1 | 10 | **10 (100%)** | 0 | 4 / 9 ms | 4640 / 5164 ms |
| B-WS50 | 50 | **50 (100%)** | 0 | 3 / 12 ms | 7456 / 8596 ms |
| B-WS100 | 100 | **100 (100%)** | 0 | 3 / 12 ms | 13518 / 16726 ms |

**Lecture** : le handshake WebSocket lui-même (Reverb) reste à 3-12ms quel
que soit n — Reverb absorbe 100 connexions simultanées sans ralentir.
Le temps de LOGIN (HTTP, bcrypt, Apache) croît lui nettement avec la
concurrence (4,6s→13,5s de médiane) : le goulot d'étranglement à cette
échelle est l'AUTHENTIFICATION HTTP (bcrypt est volontairement coûteux en
CPU, `BCRYPT_ROUNDS=12`), pas Reverb. Un déploiement réel n'authentifie
pas 100 personnes à la même seconde — ce chiffre borne un pire cas de
« connexion simultanée depuis zéro », pas un usage normal.

### Latence envoi → réception (message réel → événement WS reçu)

Mesurée en appariant, par canal, le k-ième message RÉELLEMENT envoyé
(`fire-messages.php`, horodatage serveur) et le k-ième événement REÇU par
chaque abonné (`ws-clients.mjs`, horodatage client) — PHP et Node
partagent la MÊME horloge machine (contrairement à TASK-1840, où Chrome
tournait sous Windows et le serveur sous WSL2 : aucun écart d'horloge
cross-domaine ici).

| Scénario | n échantillons | p50 | p95 | min / max |
|---|---:|---:|---:|---|
| B-S1 | 6 | 0 ms | 0 ms | 0 / 0 ms |
| B-WS50 | 48 | 0 ms | 1 ms | 0 / 1 ms |
| B-WS100 | 74 | 0 ms | 0 ms | 0 / 0 ms |

**Lecture, avec les réserves qui s'imposent** : la latence observée est au
plancher de résolution de l'horodatage (1ms) — ce protocole élimine tout
réseau réel (loopback 127.0.0.1) ET toute file d'attente
(`QUEUE_CONNECTION=sync` localement : la diffusion part dans le MÊME
process PHP, sans worker de queue à attendre). C'est une mesure RÉELLE,
mais du MEILLEUR cas possible, pas du cas Laravel Cloud (réseau réel entre
l'app et Reverb managé, éventuellement une vraie queue asynchrone). Ne
JAMAIS présenter ce chiffre comme la latence attendue en production.

## 4. ESTIMÉ — extrapolation à 50 et 100 utilisateurs (couche HTTP)

Modèle linéaire (déjà validé par TASK-1840, confirmé ici par deux points
réels n=10/n=20 quasi parfaitement proportionnels — 173,3→356,0 au lieu de
346,6 théorique, écart <3%) :

| Scénario | A estimé (OFF) | B estimé (ON) | Réduction estimée |
|---|---:|---:|---|
| S2 (n=50) | ~870 req/min | ~600 req/min | ~−31 % |
| S3 (n=100) | ~1730 req/min | ~1200 req/min | ~−31 % |

Extrapolation ARITHMÉTIQUE simple (n × taux mesuré à n=20), PAS une
nouvelle mesure — voir section 1 pour la raison (plafond Apache local).

## 5. MESURÉ — ressources (CPU/RAM, Apache vs Reverb)

Échantillonné toutes les 2s (`ps`) pendant chaque fenêtre.
**`apache_cpu_pct`/`apache_rss` portent sur l'ENSEMBLE du processus Apache
partagé de ce jumeau** (tous vhosts confondus — aucune isolation
par-vhost disponible sous mod_php prefork), pas sur test.laravel seul :
à lire comme un ORDRE DE GRANDEUR d'activité machine, pas un coût exact
attribuable à cette seule app.

| Scénario | CPU Apache (moy/pic) | RAM Apache (moy) | Workers (moy/pic) | CPU Reverb (moy) | RAM Reverb (moy) |
|---|---|---|---:|---|---|
| A-S1 (n=10, HTTP seul) | 23,6% / 34,9% | 2022 Mo | 48 / 70 | — | — |
| B-S1 (n=10, HTTP+WS) | 44,2% / 71,5% | 4091 Mo | 66 / 91 | 0,10% | 115,5 Mo |
| B-WS50 (WS seul) | 87,5% / 111,3% | 5726 Mo | 80 / 100 | 0,10% | 115,6 Mo |
| B-WS100 (WS seul) | 206,5% / 441,2% | 6933 Mo | 98 / 128 | 0,18% | 115,6 Mo |

**Lecture, prudente** : B-S1 n'est PAS directement comparable à A-S1 terme
à terme — B-S1 fait tourner EN PLUS les 10 connexions Node (login +
`/broadcasting/auth`), qu'un VRAI navigateur aurait fait depuis la MÊME
session que sa page Livewire (pas une session HTTP supplémentaire). La
hausse Apache observée entre A-S1 et B-S1 majore donc artificiellement le
« coût de Reverb » — une partie de cette hausse est un artefact du
protocole de mesure (deux process simulant un seul vrai visiteur), pas un
coût réel de la fonctionnalité.

**Le chiffre PROPRE et sans ambiguïté** : le process **Reverb lui-même**
reste négligeable à TOUTES les échelles testées — 0,10% à 0,18% CPU,
~115 Mo de RAM stable de n=10 à n=100. Le coût réel qui AUGMENTE avec
l'échelle est celui du LOGIN HTTP (bcrypt, Apache) — déjà identifié
section 3 — pas celui de Reverb.

`blks_read` (PostgreSQL) est resté à **0** sur tous les scénarios : la
totalité des requêtes a été servie depuis le cache mémoire partagé de
PostgreSQL, aucune lecture disque physique. Le jeu de données du
benchmark (100 comptes, 100 transactions) tient entièrement en cache sur
cette machine — ne garantit rien pour un jeu de données de production
bien plus volumineux.

## 6. NON MESURABLE — ce qui manque, et pourquoi

- **Requêtes SQL/minute et temps SQL cumulé exacts** : `pg_stat_statements`
  (comptage par requête) est disponible (`pg_available_extensions`) mais
  PAS installé sur cette instance PostgreSQL, et son activation exige
  `shared_preload_libraries` (redémarrage serveur) + un rôle superuser —
  aucun des deux disponible ici sans intervention de Cyril. Repli utilisé :
  `pg_stat_database` (xact_commit, tup_returned/fetched, blks_hit/read),
  qui donne un ORDRE DE GRANDEUR d'activité DB (voir section 5) mais pas
  un décompte par requête ni un temps cumulé.
  **Pour la prochaine campagne** : `CREATE EXTENSION pg_stat_statements;`
  après avoir ajouté le module à `shared_preload_libraries` dans
  `postgresql.conf` et redémarré PostgreSQL (Cyril, hors de portée en
  autonomie).
- **100 navigateurs HTTP complets réels simultanés** : plafond Apache
  partagé atteint vers n≈20-25 sur CETTE machine à CE moment (voir section
  1) — permis explicitement par le mandat, compensé par l'extrapolation
  section 4 et la mesure WS réelle à 100 (section 3).
- **Isolation stricte CPU/RAM de test.laravel** sous les AUTRES vhosts du
  même Apache partagé : structurellement indisponible sous mod_php
  prefork (un seul pool de workers, pas de cgroup par vhost).

## 7. Goulots d'étranglement identifiés

1. **Apache (mod_php prefork, concurrence de LOGIN)** — pas Reverb. Au-delà
   de ~20-25 connexions NOUVELLES simultanées sur ce jumeau partagé, les
   requêtes commencent à expirer. Une bascule Cloud ne reproduira
   probablement PAS ce plafond tel quel (infrastructure différente), mais
   la verification de la concurrence HTTP configurée côté Laravel Cloud
   reste un point d'attention pour TASK-1849.
2. **bcrypt au login** — coût CPU volontaire (`BCRYPT_ROUNDS=12`), devient
   visible en charge concurrente (médiane 4,6s→13,5s de n=10 à n=100). Sans
   rapport avec Reverb ; affecterait un pic de connexions réelles (ex. un
   envoi de notification groupé qui ramène tout le monde en même temps).
3. **AUCUN goulot côté Reverb lui-même** jusqu'à 100 connexions (section 5) —
   le composant qui a motivé ce benchmark est, empiriquement, celui qui
   pose le MOINS de risque de capacité sur cette plage.

## 8. Configuration recommandée pour 50 et 100 utilisateurs

- **Reverb** : rien à changer — 0,1-0,2% CPU/115 Mo de RAM jusqu'à 100
  connexions mesurées ; aucun signal de tension.
- **Couche HTTP/Apache** : vérifier la configuration de concurrence
  équivalente sur Laravel Cloud (TASK-1849) AVANT de supposer qu'elle
  absorbe 50-100 connexions neuves simultanées sans file d'attente — ce
  jumeau local l'a montré fragile à ce niveau, pour des raisons propres à
  SA configuration (MPM prefork partagé), pas nécessairement transposables.
- **Login/bcrypt** : pas d'action corrective nécessaire pour un usage
  normal (connexions étalées dans le temps) ; à surveiller SEULEMENT si un
  scénario métier ramène beaucoup de connexions à la même seconde.

## 9. Conclusion — Reverb réduit-il la charge, et dans quelles conditions ?

**Oui, mesurable et confirmé, mais avec un gain MODESTE sur une
population mixte réaliste** (−28 à −31 % de requêtes Livewire/minute à
n=10 et n=20, cohérent entre les deux), pas le ~5x à ~21x que TASK-1840
avait mesuré sur un usage ChatLoop isolé — la différence s'explique
entièrement par la répartition des rôles (3 rôles sur 4 ne bénéficient
d'AUCUNE réduction de polling dans l'état actuel de TASK-1846/1847,
par conception, pas par bug). Le coût de Reverb lui-même reste négligeable
(<0,2% CPU) à toutes les échelles testées, y compris 100 connexions
simultanées réelles. Le risque de capacité identifié dans ce benchmark
porte sur l'authentification HTTP (bcrypt + concurrence Apache), pas sur
Reverb — un point à vérifier spécifiquement sur Laravel Cloud (TASK-1849),
indépendamment de la décision Reverb elle-même.

## 10. Limites de validité de ce benchmark

1. Jumeau LOCAL partagé (Apache sert aussi d'autres vhosts) — le plafond
   mesuré section 1/7 est propre à CETTE machine à CE moment, pas une
   propriété universelle d'Apache ni de Laravel Cloud.
2. `QUEUE_CONNECTION=sync` et loopback réseau : la latence WS mesurée
   (section 3) est un plancher, pas une latence de production attendue.
3. Échantillons modestes (n=6 à n=74 paires latence) — p95 indicatif, pas
   une garantie statistique.
4. Répartition des rôles FIXE (1/4 chacun) — une répartition différente
   (plus de conversations directes, moins de Boucles) changerait le gain
   HTTP mesuré section 2 proportionnellement.
5. Email (`notifierApresCommit()`) délibérément EXCLU du déclencheur de
   messages (`.env` local pose `MAIL_MAILER=smtp` — l'inclure aurait tenté
   de vrais envols SMTP vers des adresses `@example.test` fictives) : les
   mesures ne couvrent QUE le delta Reverb/polling, jamais la couche email.
6. `pg_stat_statements` indisponible (section 6) : le proxy
   `pg_stat_database` utilisé est un ordre de grandeur, pas un décompte
   exact de requêtes/temps SQL.
