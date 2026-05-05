# ADMIN_HOWTO.md — commandes WSL à lancer
NE PAS TOUCHER CE FICHIER

cp /mnt/c/Users/cyril/AppData/Roaming/Claude/local-agent-mode-sessions/735ea359-774f-4f39-b8f2-3ecaf6586f3c/6d135e28-4145-4f6c-ba5c-64ed8d4a983f/local_8428be3e-143d-4004-bbc0-8caf9d094e69/outputs/HOWTO_ADMIN.md \
   /home/cyril/claude-code/sites/test.laravel/HOWTO_ADMIN.md






cd /home/cyril/claude-code/sites/test.laravel

# 1. Vérifier si ADMIN.md est tracké par git (il ne devrait pas l'être)
git ls-files ADMIN.md

# 2. Ajouter les deux fichiers au .gitignore
echo "ADMIN.md" >> .gitignore
echo "ADMIN_HOWTO.md" >> .gitignore

# 3. Commiter le .gitignore mis à jour
git add .gitignore && git commit -m "chore: ignorer ADMIN.md et ADMIN_HOWTO.md (notes locales)" && git push origin main