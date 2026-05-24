#!/usr/bin/env bash
# =============================================================================
# T139.2 — Smoke Test des Routes Critiques (non destructif)
# =============================================================================
#
# Usage :
#   ./ai/scripts/smoke-critical-routes.sh                  # DB locale
#   ./ai/scripts/smoke-critical-routes.sh https://staging.example.com   # URL distante
#
# Prérequis :
#   - curl, jq (optionnel mais recommandé)
#   - Ne lance PAS de migration
#   - Ne modifie AUCUNE donnée
# =============================================================================

set -euo pipefail

BASE_URL="${1:-http://localhost}"
PASS=0
FAIL=0
FAILED_ROUTES=()

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

info()  { echo -e "${YELLOW}[INFO]${NC}  $*"; }
ok()    { echo -e "${GREEN}[OK]${NC}    $*"; ((PASS++)); }
fail()  { echo -e "${RED}[FAIL]${NC}  $*"; ((FAIL++)); FAILED_ROUTES+=("$1"); }

echo "========================================="
echo " Smoke Tests — Routes Critiques"
echo " URL: $BASE_URL"
echo " Date: $(date -Iseconds)"
echo "========================================="
echo ""

# ── Routes publiques (root-level) ──────────────────────────────
info "Routes publiques root-level"

ROUTE="${BASE_URL}/"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${ROUTE}" 2>/dev/null || echo "000")
if [ "$HTTP_CODE" = "200" ]; then ok "GET / → $HTTP_CODE"; else fail "GET / → $HTTP_CODE" "/"; fi

ROUTE="${BASE_URL}/explorer"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${ROUTE}" 2>/dev/null || echo "000")
if [ "$HTTP_CODE" = "200" ]; then ok "GET /explorer → $HTTP_CODE"; else fail "GET /explorer → $HTTP_CODE" "/explorer"; fi

ROUTE="${BASE_URL}/membres"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${ROUTE}" 2>/dev/null || echo "000")
if [ "$HTTP_CODE" = "200" ]; then ok "GET /membres → $HTTP_CODE"; else fail "GET /membres → $HTTP_CODE" "/membres"; fi

ROUTE="${BASE_URL}/blog"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${ROUTE}" 2>/dev/null || echo "000")
if [ "$HTTP_CODE" = "200" ]; then ok "GET /blog → $HTTP_CODE"; else fail "GET /blog → $HTTP_CODE" "/blog"; fi

ROUTE="${BASE_URL}/boucles"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${ROUTE}" 2>/dev/null || echo "000")
if [ "$HTTP_CODE" = "200" ]; then ok "GET /boucles → $HTTP_CODE"; else fail "GET /boucles → $HTTP_CODE" "/boucles"; fi

ROUTE="${BASE_URL}/echanges"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${ROUTE}" 2>/dev/null || echo "000")
if [ "$HTTP_CODE" = "200" ]; then ok "GET /echanges → $HTTP_CODE"; else fail "GET /echanges → $HTTP_CODE" "/echanges"; fi

# ── Routes admin ────────────────────────────────────────────────
info "Routes admin (attendu: redirect 302 — non authentifié)"

ROUTE="${BASE_URL}/admin/dashboard"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${ROUTE}" 2>/dev/null || echo "000")
if [ "$HTTP_CODE" = "302" ]; then ok "GET /admin/dashboard → $HTTP_CODE (redirect attendu)"; else fail "GET /admin/dashboard → $HTTP_CODE (attendu: 302)" "/admin/dashboard"; fi

ROUTE="${BASE_URL}/admin/users"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${ROUTE}" 2>/dev/null || echo "000")
if [ "$HTTP_CODE" = "302" ]; then ok "GET /admin/users → $HTTP_CODE (redirect attendu)"; else fail "GET /admin/users → $HTTP_CODE (attendu: 302)" "/admin/users"; fi

ROUTE="${BASE_URL}/admin/services"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${ROUTE}" 2>/dev/null || echo "000")
if [ "$HTTP_CODE" = "302" ]; then ok "GET /admin/services → $HTTP_CODE (redirect attendu)"; else fail "GET /admin/services → $HTTP_CODE (attendu: 302)" "/admin/services"; fi

ROUTE="${BASE_URL}/admin/requests"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${ROUTE}" 2>/dev/null || echo "000")
if [ "$HTTP_CODE" = "302" ]; then ok "GET /admin/requests → $HTTP_CODE (redirect attendu)"; else fail "GET /admin/requests → $HTTP_CODE (attendu: 302)" "/admin/requests"; fi

ROUTE="${BASE_URL}/admin/messages"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${ROUTE}" 2>/dev/null || echo "000")
if [ "$HTTP_CODE" = "302" ]; then ok "GET /admin/messages → $HTTP_CODE (redirect attendu)"; else fail "GET /admin/messages → $HTTP_CODE (attendu: 302)" "/admin/messages"; fi

# ── Dashboard authentifié ───────────────────────────────────────
info "Dashboard (attendu: 302 — non authentifié)"

ROUTE="${BASE_URL}/dashboard"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "${ROUTE}" 2>/dev/null || echo "000")
if [ "$HTTP_CODE" = "302" ]; then ok "GET /dashboard → $HTTP_CODE (redirect attendu)"; else fail "GET /dashboard → $HTTP_CODE (attendu: 302)" "/dashboard"; fi

# ── Résumé ──────────────────────────────────────────────────────
echo ""
echo "========================================="
echo -e " Résultat : ${GREEN}${PASS} OK${NC} / ${RED}${FAIL} FAIL${NC}"
echo "========================================="

if [ "$FAIL" -gt 0 ]; then
    echo ""
    echo "Routes échouées :"
    for route in "${FAILED_ROUTES[@]}"; do
        echo "  - ${route}"
    done
    echo ""
    info "Ces routes peuvent être rouges en dehors du contexte Laravel"
    info "(absence de session, middleware, résolution tenant)."
    info "Les tests Feature Laravel sont la source de vérité."
    exit 1
fi

exit 0
