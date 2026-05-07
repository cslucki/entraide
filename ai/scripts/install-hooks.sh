#!/bin/bash

echo ""
echo "Installing git hooks..."
echo ""

mkdir -p .git/hooks

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