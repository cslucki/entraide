#!/usr/bin/env bash
#
# ai/scripts/ai-local-provider-check.sh
#
# Vérifie l'état de configuration des providers IA pour le lab admin.
# Lit la config Laravel réelle (pas les secrets API, pas les clés).
# Ne lit AUCUNE clé API, ne dump AUCUN secret.
#
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PHP="${PHP:-php}"

echo "========================================="
echo " AI Provider Configuration Check"
echo "========================================="
echo ""

echo "--- Ollama (local, prioritaire) ---"
OLLAMA_ENABLED=$("$PHP" artisan tinker --execute="echo config('ai.ollama.enabled') ? 'true' : 'false';" 2>/dev/null | tail -1)
OLLAMA_BASE_URL=$("$PHP" artisan tinker --execute="echo config('ai.ollama.base_url');" 2>/dev/null | tail -1)
OLLAMA_MODEL=$("$PHP" artisan tinker --execute="echo config('ai.ollama.model');" 2>/dev/null | tail -1)

if [ "$OLLAMA_ENABLED" = "true" ]; then
    echo "  Statut    : ACTIF (enabled=true)"
    echo "  Base URL  : $OLLAMA_BASE_URL"
    echo "  Modèle    : $OLLAMA_MODEL"

    if command -v ollama &>/dev/null; then
        echo "  Ollama CLI : installé"
        echo "  Modèles locaux :"
        ollama list 2>/dev/null | head -10 || echo "    (impossible de lister les modèles — le serveur est-il actif ?)"
    else
        echo "  Ollama CLI : NON installé"
        echo "  Installez : https://ollama.com/download"
    fi
else
    echo "  Statut    : DÉSACTIVÉ (OLLAMA_ENABLED=false)"
    echo "  Activez dans .env : OLLAMA_ENABLED=true"
fi

echo ""
echo "--- OpenRouter (cloud proxy, alternative) ---"
OPENROUTER_ENABLED=$("$PHP" artisan tinker --execute="echo config('ai.openrouter.enabled') ? 'true' : 'false';" 2>/dev/null | tail -1)
OPENROUTER_MODEL=$("$PHP" artisan tinker --execute="echo config('ai.openrouter.model');" 2>/dev/null | tail -1)

if [ "$OPENROUTER_ENABLED" = "true" ]; then
    echo "  Statut    : ACTIF (enabled=true)"
    echo "  Modèle    : $OPENROUTER_MODEL"
    echo "  Clé API   : (non affichée)"
else
    echo "  Statut    : DÉSACTIVÉ (OPENROUTER_ENABLED=false)"
    echo "  Activez dans .env : OPENROUTER_ENABLED=true + OPENROUTER_API_KEY=sk-..."
fi

echo ""
echo "--- OpenAI (cloud, désactivé par défaut) ---"
OPENAI_SUPERVISION_ENABLED=$("$PHP" artisan tinker --execute="echo config('ai.openai.supervision_enabled') ? 'true' : 'false';" 2>/dev/null | tail -1)
OPENAI_MODEL=$("$PHP" artisan tinker --execute="echo config('ai.openai.model');" 2>/dev/null | tail -1)

if [ "$OPENAI_SUPERVISION_ENABLED" = "true" ]; then
    echo "  Statut    : ACTIF (OPENAI_SUPERVISION_ENABLED=true)"
    echo "  Modèle    : $OPENAI_MODEL"
    echo "  Clé API   : (non affichée)"
    echo "  ⚠️  Attention : OpenAI consomme le quota quotidien de l'API."
else
    echo "  Statut    : DÉSACTIVÉ (OPENAI_SUPERVISION_ENABLED=false)"
    echo "  Activez uniquement si nécessaire : OPENAI_SUPERVISION_ENABLED=true"
fi

echo ""
echo "--- Résumé ---"
ACTIVE_COUNT=0
[ "$OLLAMA_ENABLED" = "true" ] && ACTIVE_COUNT=$((ACTIVE_COUNT + 1)) && echo "  ✓ Ollama"
[ "$OPENROUTER_ENABLED" = "true" ] && ACTIVE_COUNT=$((ACTIVE_COUNT + 1)) && echo "  ✓ OpenRouter"
[ "$OPENAI_SUPERVISION_ENABLED" = "true" ] && ACTIVE_COUNT=$((ACTIVE_COUNT + 1)) && echo "  ✓ OpenAI"

if [ "$ACTIVE_COUNT" -eq 0 ]; then
    echo "  ✗ AUCUN provider actif"
    echo ""
    echo "  ⚠️  Le lab admin ne fonctionnera pas sans provider actif."
    echo "  Recommandation : activez Ollama localement (OLLAMA_ENABLED=true)."
else
    echo "  Total providers actifs : $ACTIVE_COUNT"
fi

echo ""
echo "========================================="