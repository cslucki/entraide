# TASK-1851 — Checklist opérationnelle (préparation uniquement)

**STOP avant tout provisionnement, déploiement ou changement de
configuration Cloud — mandat explicite. Ce fichier ne déclenche rien, il
prépare la décision de Cyril.** Détail complet (architecture, tarifs
sourcés, procédure pas à pas) : `benchmarks/task-1849/runbook-reverb-laravel-cloud.md`.

## Avant de commencer

- [ ] Décision humaine explicite de Cyril pour démarrer TASK-1851
- [ ] Cyril au tableau de bord Cloud (jamais en autonomie — même principe
      que la Managed Queue T1829/T1830)

## 1. Ressource WebSocket

- [ ] Resources → WebSockets → + New WebSocket cluster
- [ ] **Région : `us-east-2`** (même région que l'app et la base PROD —
      latence minimale, pas de nouveau goulot réseau)
- [ ] **Palier recommandé pour démarrer : 100 connexions simultanées**

## 2. Coût attendu (tarifs officiels, `laravel.com/cloud/docs/pricing.md`, 17/11/2026)

| Palier | Prix |
|---|---:|
| 100 connexions | **5 $/mois** |
| 200 connexions | 10 $/mois |
| 500 connexions | 25 $/mois |

Attention au comptage : chaque message livré à chaque abonné compte
séparément (une diffusion à 50 membres d'une Boucle = 50 messages, pas
1) — vérifier le volume RÉEL de membres simultanés par Boucle active
avant de considérer ce palier comme définitivement suffisant.

## 3. Vérification de la configuration actuelle (avant tout changement)

- [ ] Reconfirmer `QUEUE_CONNECTION` (lecture seule, jamais affichée en
      clair) — `php artisan reverb:diagnose-queue-guard` en PROD si
      possible, sinon via le CLI Cloud comme fait par TASK-1849
- [ ] Reconfirmer `websocketApplicationId: null` (rien n'a changé depuis)
- [ ] Vérifier que le correctif TASK-1850 (`client_options`/`options.timeout`
      sur la connexion `reverb`) est bien dans la version déployée

## 4. Provisionnement et attachement

- [ ] Créer le cluster (région + palier ci-dessus)
- [ ] Application canvas de `main` → Add resource → WebSockets →
      sélectionner le cluster
- [ ] Vérifier dans `environment:get` que `REVERB_*`/`VITE_REVERB_*`
      apparaissent dans les variables injectées — **avant** de redéployer

## 5. Déploiement initial — TOUS les pilotes restent OFF

- [ ] Redéployer une première fois avec les 4 drapeaux
      `*_REALTIME_ENABLED` encore à `false` — ce déploiement ne doit
      changer AUCUN comportement visible, seulement rendre la connexion
      Reverb disponible
- [ ] Confirmer que le site fonctionne normalement après ce déploiement
      (aucune régression, aucune erreur liée à Reverb dans les logs)

## 6. Contrôles de fonctionnement

- [ ] Dashboard Cloud → Resources → WebSockets → cluster → Metrics :
      connexions = 0 (attendu, aucun pilote actif encore)
- [ ] Logs Cloud : aucune erreur `BroadcastException` inattendue

## 7. Marche arrière

- [ ] Détacher la ressource WebSocket de l'environnement si besoin —
      **attention : ne supprime PAS le cluster, ne stoppe PAS la
      facturation** (confirmé par la documentation officielle)
- [ ] Pour arrêter réellement les frais : Resources → WebSockets → … →
      Delete cluster — **irréversible, jamais sans confirmation explicite
      de Cyril**

## Suite (hors TASK-1851, après décision explicite)

TASK-1852 (proposée) : activation progressive pilote par pilote, selon
la checklist détaillée de `benchmarks/task-1849/runbook-reverb-laravel-cloud.md`
section 3.3.
