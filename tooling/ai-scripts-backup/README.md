# Sauvegarde des scripts d'orchestration `ai/`

`ai/` (racine du depot) est entierement gitignore — voir `.gitignore`,
section « Private agent/orchestration files ». `docs/` y est egalement
verrouille pour toute nouvelle addition (section « Public lockdown ») :
seuls les fichiers deja suivis avant cette regle y restent trackes. Les
scripts qui pilotent le cycle TASK (`create-task.sh`, `check-task.sh`,
`finalize-task.sh`, `merge-task.sh`, `bump-version.sh`) et leur template
(`ai/tasks/templates/TASK_TEMPLATE.md`) n'existaient donc jusqu'ici que
sur les machines locales : sans copie versionnee, une perte machine
efface tout le tooling d'orchestration.

`tooling/` est un nouveau dossier racine, hors de tout perimetre
gitignore existant, cree pour heberger cette copie de secours. Ce ne sont
**pas** des fichiers executables actifs :

- Le bit `+x` a ete retire sur les `.sh` de ce dossier — ils ne sont pas
  destines a etre lances d'ici, et rien ne les invoque en CI.
- La version qui fait foi et que les agents executent au quotidien reste
  `ai/scripts/` (et `ai/tasks/templates/`) sur chaque machine locale,
  gitignoree.
- Ces copies sont un instantane pris a une date donnee, pas une source
  synchronisee automatiquement. Pour rafraichir la sauvegarde apres une
  evolution des scripts locaux, recopier manuellement et committer.

Pour restaurer le tooling sur une machine neuve : copier ces fichiers vers
`ai/scripts/` et `ai/tasks/templates/` a la racine du depot, puis
redonner le bit executable aux `.sh` (`chmod +x`).
