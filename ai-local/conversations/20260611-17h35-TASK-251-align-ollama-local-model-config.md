# TASK-251 — Conversation file

> Branche : `TASK-251-align-ollama-local-model-config`
> ORCH : OPENCODE (Cyril via terminal)
> CODEUR : via tmux
> VERIFICATOR : via tmux

---

## Prompt CODEUR

### Contexte

Tu es CODEUR sur TASK-251.

**Objectif** : Aligner la configuration Ollama locale du projet avec le modèle `ministral-3:3b` qui a été testé et validé sur GPU RTX 4070 en WSL (Windows NVIDIA driver, Ollama 0.23.1).

Actuellement, le défaut partout est `llama3.2`. Le `.env` local a déjà `ministral-3:3b`, mais les défauts dans le code source pointent encore vers `llama3.2`.

**Aucune nouvelle logique métier.** C'est juste un alignement de constantes/valeurs par défaut dans 4 fichiers.

### Fichiers à modifier (exactement 4)

1. **`config/ai.php`** ligne 83 :
   ```php
   'model' => env('OLLAMA_MODEL', 'llama3.2'),
   ```
   → `'model' => env('OLLAMA_MODEL', 'ministral-3:3b'),`

2. **`.env.example`** ligne 71 :
   ```
   OLLAMA_MODEL=llama3.2
   ```
   → `OLLAMA_MODEL=ministral-3:3b`

3. **`resources/views/admin/ai-supervision/index.blade.php`** ligne 40 :
   ```
   <code class="text-xs">OLLAMA_MODEL=votre_modèle</code>.
   ```
   → `<code class="text-xs">OLLAMA_MODEL=ministral-3:3b</code>`

4. **`app/Services/Ai/SupervisionProviderResolver.php`** deux occurrences (lignes 65 et 113) :
   ```php
   config('ai.ollama.model', 'llama3.2')
   ```
   → `config('ai.ollama.model', 'ministral-3:3b')`

### Fichier à vérifier (régression uniquement)

- `tests/Feature/Admin/AdminAiSupervisionTest.php` — doit rester green sans modification

### Procédure

1. **Preflight DB** : AVANT tout test, vérifier que la DB de test est safe :
   ```bash
   APP_ENV=testing APP_CONFIG_CACHE=bootstrap/cache/testing-config.php DB_CONNECTION=pgsql DB_DATABASE=bouclepro_test php artisan config:show database.default
   APP_ENV=testing APP_CONFIG_CACHE=bootstrap/cache/testing-config.php DB_CONNECTION=pgsql DB_DATABASE=bouclepro_test php artisan config:show database.connections.pgsql.database
   ```
   Les deux doivent retourner `bouclepro_test`.

2. **Modifier** les 4 fichiers ci-dessus.

3. **Lancer `php artisan pint --dirty`** pour le style.

4. **Tester** :
   ```bash
   php artisan test --filter=AdminAiSupervisionTest --parallel
   ```
   Lire les résultats. 48 tests, 187 assertions.

5. **Commit** les 4 fichiers modifiés :
   ```bash
   git add config/ai.php .env.example resources/views/admin/ai-supervision/index.blade.php app/Services/Ai/SupervisionProviderResolver.php
   git commit -m "refactor(ollama): align default model to ministral-3:3b"
   ```

6. **Mettre à jour le TASK** (status DONE, progress log, tests section).

7. **Mettre à jour ce fichier conversation** — ajouter ta section CODEUR DONE avec le rapport.

8. **Envoyer SMT à ORCH** via :
   ```bash
   tmux send-keys -t orch ENTER && tmux send-keys -t orch "SMT CODEUR DONE TASK-251 — 4 fichiers modifiés, tests green. Voir conversation." ENTER
   ```

### Interdit

- ❌ NE PAS toucher aux fichiers provider (`app/Services/Ai/Providers/*`)
- ❌ NE PAS modifier `tests/Unit/Services/Ai/OllamaSupervisionProviderTest.php` (références `qwen3.5`)
- ❌ NE PAS créer de nouveaux tests
- ❌ NE PAS lancer `migrate:fresh`, `db:wipe`
- ❌ NE PAS modifier la logique provider-agnostique

---

## Prompt VERIFICATOR (à lire après CODEUR DONE)

### Contexte

CODEUR a modifié 4 fichiers pour aligner la config Ollama locale sur `ministral-3:3b`.

### Checklist de vérification

1. [ ] `config/ai.php` ligne 83 : défaut changé en `ministral-3:3b`
2. [ ] `.env.example` ligne 71 : défaut changé en `ministral-3:3b`
3. [ ] `views/admin/ai-supervision/index.blade.php` ligne 40 : `votre_modèle` → `ministral-3:3b`
4. [ ] `SupervisionProviderResolver.php` lignes 65 et 113 : fallback changé en `ministral-3:3b`
5. [ ] Aucun autre fichier modifié (git diff --stat)
6. [ ] `AdminAiSupervisionTest` — 48 tests green
7. [ ] Aucun fichier provider modifié
8. [ ] Aucune migration/schema DB
9. [ ] Aucun nouveau test créé
10. [ ] Préflight DB `bouclepro_test` OK

### Procédure

1. Lire le rapport CODEUR dans ce fichier conversation
2. Inspecter git diff
3. Lire les 4 fichiers modifiés
4. Lancer la régression si pas déjà faite
5. Signaler OK ou réserves

Si OK, envoyer SMT à ORCH :
```bash
tmux send-keys -t orch ENTER && tmux send-keys -t orch "SMT VERIFICATOR OK TASK-251 — scope strict respecté, tests green, aucun fichier hors scope modifié." ENTER
```

---

## CODEUR DONE

> (CODEUR écrit son rapport ici après exécution)

Date :

Rapport :
- 4 fichiers modifiés : config/ai.php, .env.example, index.blade.php, SupervisionProviderResolver.php
- Résultat tests AdminAiSupervisionTest :
- DB preflight :
- Commit SHA :
- Statut TASK :

---

## VERIFICATOR RESULT

> (VERIFICATOR écrit son verdict ici)

Date :

Verdict : OK / RÉSERVES

Détails :
- Checklist points verts/rouges
- Tests :
- Scope :
- Décision finale :

---

## ORCH NOTES

Date : 2026-06-11 17:38

SMT envoyé à CODEUR. En attente CODEUR DONE.

Décision :
