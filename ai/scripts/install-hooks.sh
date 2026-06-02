#!/bin/bash

echo ""
echo "Installing git hooks..."
echo ""

mkdir -p .git/hooks

cat > .git/hooks/pre-push << 'EOF'
#!/bin/bash

REMOTE_URL="$1"
REMOTE_NAME="$2"

case "$REMOTE_URL" in
  *github.com*|*github.com:*)
    ;;
  *)
    exit 0
    ;;
esac

SENSITIVE_DIR="synchro_pgsql-avant-migration/"

while read local_ref local_sha remote_ref remote_sha; do
  if [ "$local_sha" = "0000000000000000000000000000000000000000" ]; then
    continue
  fi

  if [ "$remote_sha" = "0000000000000000000000000000000000000000" ]; then
    range="$local_sha"
  else
    range="$remote_sha..$local_sha"
  fi

  if git diff --name-only "$range" 2>/dev/null | grep -q "^$SENSITIVE_DIR"; then
    echo ""
    echo "⛔ PUSH BLOQUÉ vers GitHub"
    echo "   Le dossier '$SENSITIVE_DIR' contient des credentials de production."
    echo "   Il est tracké en git localement mais ne doit PAS être poussé sur GitHub."
    echo ""
    echo "   Solution :"
    echo "   - Pousse vers un remote auto-hébergé (ex: origin) : git push origin <branche>"
    echo "   - Si tu veux vraiment pousser vers GitHub : git push --no-verify github <branche>"
    echo ""
    exit 1
  fi
done

exit 0
EOF

chmod +x .git/hooks/pre-push

cat > .git/hooks/pre-commit << 'EOF'
#!/bin/bash

./ai/scripts/validate-task-update.sh

if [ $? -ne 0 ]; then
    exit 1
fi
EOF

chmod +x .git/hooks/pre-commit

echo ""
echo "Hooks installed successfully."
echo ""