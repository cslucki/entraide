---
task_id: TASK-242
title: Fix admin AI lab provider UX and Ollama-first behavior

status: DONE

owner: OPENCODE

contributors: []

branch: TASK-242-fix-admin-ai-lab-provider-ux-and-ollama-first-behavior

priority: MEDIUM

created_at: 2026-06-11 06:27:09 Europe/Paris
updated_at: 2026-06-11 06:27:09 Europe/Paris

labels: []

lock:
  status: UNLOCKED
  agent: OPENCODE
  since: 2026-06-11 06:35:00 Europe/Paris

handoff: false

pr:
  status: NOT_READY
  url: null
---

# Objective

Fixer les incohérences UX du lab IA admin suite à TASK-241. L'interface affiche encore OpenAI par défaut, clarify_help_request reste le scénario par défaut alors qu'il ne fonctionne qu'avec OpenAI, et le routing ne montre pas clairement quel provider est prioritaire / fallback.

---

# Résultats de l'inspection

1. **.env / config** : `OLLAMA_ENABLED=false`, `OPENROUTER_ENABLED=false`. Donc OpenAI est le seul provider actif = fallback par défaut. C'est correct quand Ollama n'est pas configuré.
2. **SupervisionProviderResolver** : Déjà correct — `defaultProvider()` retourne `ollama` si enabled, puis `openrouter`, puis `openai`. `availableProviders()` aussi.
3. **Controller** : `index()` passe `'scenario' => 'clarify_help_request'` comme défaut. PROBLÈME : clarify_help_request ne fonctionne qu'avec OpenAI (bloque Ollama/OpenRouter). Si Ollama est le provider par défaut, le scénario par défaut est incompatible.
4. **Controller** : `analyze()` hardcode `openai` pour `runClarifyHelpRequest()`, avec un `SupervisionException` si provider ≠ openai. Pas de contrainte proactive dans l'UI.
5. **View** : Texte d'intro déjà neutre ("Testez un scénario avec un provider et un modèle configurés"). Pas de label "(local)" / "(cloud, payant)" / "(fallback)". Pas de désactivation dynamique de clarify_help_request quand provider ≠ openai.
6. **View** : Le modèle selector ne se met pas à jour dynamiquement quand le provider change (pas de JS).

---

# Actions planifiées

- [x] inspecter état réel du controller, view, resolver, config
- [x] vérifier config .env / ollama.enabled
- [x] expliquer pourquoi OpenAI apparaît par défaut (OLLAMA_ENABLED=false)
- [ ] Corriger controller: scenario par défaut = supervision_content (universel), pas clarify_help_request (OpenAI-only)
- [ ] Corriger controller: passer infos de compatibilité scenario/provider à la vue
- [ ] Corriger resolver: ajouter méthode scenarioSupportsProvider() ou supportedScenarios()
- [ ] Corriger view: labels provider (local/gratuit, cloud/payant, fallback), contrainte clarify_help_request visible, JS dynamique provider↔model↔scenario
- [ ] Corriger view: Alerty info si provider par défaut est fallback (OpenAI fallback quand Ollama disabled)
- [ ] Ajouter tests: default scenario adaptatif, compatibilité scenario/provider, UI labels
- [ ] Lancer tous les tests AI supervision
- [ ] Valider via check-task.sh

---
# Progress Log


## 2026-06-11 06:27:09 Europe/Paris

Task created.

Owner:
OPENCODE

Branch:
TASK-242-fix-admin-ai-lab-provider-ux-and-ollama-first-behavior

Status:
IN_PROGRESS

# Handoffs

# Tests

- [ ] feature tests
- [ ] browser validation
- [ ] responsive validation
- [ ] console inspection
- [ ] tenant validation

---

# Test Results

32 tests, 116 assertions — all green.
- default scenario is supervision_content
- index passes scenario compatibility to view
- fallback banner shown when no local provider
- no fallback banner when ollama enabled
- scenario options include supported providers
- clarify_help_request is not supported with ollama (updated message)
- clarify_help_request is not supported with openrouter (updated message)
- all existing tests pass unchanged

---

# Review Notes

Browser validated:
- Default scenario = supervision_content (universal, works with all providers)
- Fallback banner "aucun provider local configuré" when only OpenAI available
- Provider type labels: "Local · Gratuit" / "Cloud proxy · Payant" / "Cloud · Payant"
- JS dynamic: model list updates on provider change, compatibility warning on incompatible scenario/provider combos
- clarify_help_request shows clear error message when used with non-OpenAI provider

---

# Version Notes

**IMPORTANT:**
- Do NOT edit `VERSION` file manually
- Do NOT edit footer version manually
- `finalize-task.sh` does NOT update `VERSION`
- Version bump is automatic at merge time via `merge-task.sh`
- Footer always displays `config('app.version')`