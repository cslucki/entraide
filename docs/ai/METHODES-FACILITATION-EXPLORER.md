# Les quatre méthodes de facilitation de Roger — chat Explorer d'article

État **réellement livré** par TASK-1249 (2026-08-19), complété par TASK-1256
(feedback humain V1 + `method_code` en métadonnées de trace, même jour). Cette
page décrit ce que le code fait ; elle ne remplace ni les références
méthodologiques, ni le TASK file.

## Ce que c'est

Dans l'Explorer d'article (modal « Questionner l'article », Blog), quatre
boutons au-dessus du chat choisissent la **posture de facilitation de
Roger pour la conversation** :

| Bouton | `method_code` | Inspiration | Posture V1 |
|---|---|---|---|
| Explorer | `explorer` | Edward de Bono (fonctions de pensée distinctes) | plusieurs angles, un à la fois : faits, ressentis, risques, opportunités, alternatives, synthèse |
| Ralentir | `slow_down` | F. David Peat (suspension créative) | suspendre la réponse immédiate : système, cadres, hypothèses, signaux faibles, action légère réversible |
| Clarifier | `clarifier` | David Bohm (dialogue) | termes, affirmations, hypothèses, points de vue, vrais désaccords vs malentendus — sans convaincre |
| Inventer | `invent` | Robert & Michèle Root-Bernstein (outils créatifs) | analogies (mécanisme + limites), modèles, inversions, changement d'échelle, rapprochements inattendus |

Les identifiants sont **ceux de `BlogAiService::METHOD_SELECTION_METHODS`**
(déjà utilisés par la suggestion courte sur un passage, T997/T1247) : un
seul nom par notion, aucun nouvel enum.

## Chaîne exacte (V1)

```
bouton (Alpine `blogExplorerModal.methodCode`, état de la conversation,
        remis à zéro à chaque ouverture ; envoyé avec CHAQUE message)
  → POST …/explorer/chat { message, messages[], method_code }
  → BlogExplorerController::chat()  — validation `in:` METHOD_SELECTION_METHODS (422 sinon)
  → buildExplorerSystemPrompt($post, $methodCode)
      ├─ resolvePrompt('blog_explorer_method_{m}_{locale}',
      │                'blog_explorer_method_{m}_fr',
      │                BlogExplorerFacilitation::defaultPrompt($m, $locale))
      │     = repository AdminAiPrompt (version active la plus haute),
      │       puis repli `_fr`, puis fallback CODE — jamais vide
      ├─ + BlogExplorerFacilitation::facilitationRules($locale)  (bloc code, TOUJOURS présent)
      └─ + article (articleContext) + règle impérative (inchangé)
  → callProvider()  — garde économique T1248, ledger, trace : même feature
                      `blog_explorer`, même process `blog.explorer_dialogue` ;
                      depuis TASK-1256 la TRACE `ai_interactions` porte
                      `metadata.method_code` (la méthode, ou `null` pour le
                      dialogue libre — clé toujours présente sur un tour de
                      dialogue, absente sur la note) ; le LEDGER
                      `ai_provider_invocations`, lui, reste identique
  → réponse `{text, ai_interaction_id}` → intervention IA courte, bloc
    « Utile / À améliorer » sous la bulle (TASK-1256) → l'humain répond.
```

Sans `method_code` (ou `null`) : le scénario générique historique
`blog_explorer_dialogue_{locale}` est utilisé tel quel — comportement
antérieur conservé.

## Règles de facilitation (contrainte produit, portées par le prompt système)

Ajoutées **par le code** après la définition de la méthode, quelle que soit
la définition (admin ou défaut) — un admin ne peut donc pas les retirer par
mégarde :

- facilitateur, jamais directif : l'IA propose, l'humain décide et agit ;
- une seule intervention courte par tour (≤ 120 mots) : un constat ancré dans
  l'article, puis une question ou une invitation — jamais toute la méthode en
  un message ;
- ne répond pas à la place de l'humain (ni conclusion, ni verdict, ni
  réécriture à sa place) ;
- ne passe jamais automatiquement à l'étape suivante ;
- la validation humaine est toujours l'étape finale ;
- s'appuie sur l'article fourni par le système, ne le redemande jamais ;
- texte simple, pas de Markdown, ≤ 3 items si liste.

Texte exact : `App\Support\Ai\BlogExplorerFacilitation::facilitationRules()`.

## Où sont les prompts

- **Repository existant** : `admin_ai_prompts` (`AdminAiPrompt`), scénarios
  `blog_explorer_method_{explorer|slow_down|clarifier|invent}_{fr|en}`,
  déclarés à la whitelist de l'admin (`AdminAiPromptController::scenarioLabels()`,
  libellés « Explorer d'article — Facilitation — … »). Aucune ligne n'est
  semée par migration : tant qu'aucune ligne active n'existe, le **fallback
  codé** sert (c'est l'état du banc de la preuve T1249, `method_prompts_in_db = 0`).
  Créer une version admin = la prendre à la place du défaut ; la désactiver =
  retour au défaut.
- **Fallback codé** (définitions courtes FR/EN, ~1 200–1 500 caractères
  chacune) : `App\Support\Ai\BlogExplorerFacilitation::defaultPrompt()`.
  Ce n'est pas un second système de prompts : contenu seulement, la
  résolution reste `BlogExplorerController::resolvePrompt()`.

## Références méthodologiques (NON injectées au runtime)

Les quatre textes de référence fournis par Cyril (T999, juillet 2026 —
« prompt pour analyser un texte et produire une note », 7 à 20 Ko chacun,
inspirés de de Bono / Peat / Bohm / Root-Bernstein) sont des **spécifications
fonctionnelles privées**, conservées inchangées (SHA256SUMS) sous
`_local/task-999-method-references/Version 1 des prompts pour t999/` du
dépôt local `test.laravel` (hors git), avec leur analyse
`RAA-TASK-999-2026-07-12.md` (matrice comparative, `MethodSpecifications`
canoniques proposées, points à préserver obligatoirement).

Les définitions courtes V1 en dérivent (posture, but, manière de faire,
interdits) sans les reproduire : ni la note structurée en 9–15 sections, ni
les 6–13 mouvements, ni le format de sortie. Ce qui est préservé par méthode :

- Explorer — fonctions distinctes sans couleurs propriétaires, séparation
  établi/interprété, risques et opportunités motivés, hypothèses inversées ;
- Ralentir — l'observateur fait partie du système, suspension bornée,
  réactions = information, niveaux de certitude, action réversible avec
  condition d'arrêt ;
- Clarifier — clarifier sans convaincre, vocabulaire, typologie des
  désaccords, sens partagé ≠ consensus, positions minoritaires préservées ;
- Inventer — limites de l'IA (ne prétend pas ressentir), divergence avant
  convergence, analogie avec mécanisme et limites, modèles partiels,
  validation humaine.

Ces références restent la base d'un futur mode `analyze_note` / `facilitate`
plus complet (documenté dans le RAA T999, hors V1).

## Tests

- `tests/Unit/Support/Ai/TASK1249BlogExplorerFacilitationTest.php` — identifiants
  canoniques, scénarios, défauts non vides et distincts FR/EN, règles.
- `tests/Feature/TASK1249BlogExplorerFacilitationMethodsTest.php` — 422 sur
  code inconnu, prompt générique inchangé sans méthode, quatre prompts
  systèmes distincts portant posture + règles + article, override admin /
  version active / fallback `_fr` / fallback codé, locale EN, whitelist admin,
  garde économique intacte (429 avant tout appel), ledger/trace identiques,
  alias route Organization, la méthode vit dans le prompt système (aucun
  message ajouté).
- `tests/e2e-ai-validation/blog-explorer-facilitation-methods.spec.js` —
  banc 8010, desktop + mobile, 4 boutons visibles au-dessus du chat, une
  réponse réelle distincte par méthode, reset à chaque conversation.
- `tests/Feature/TASK1256ExplorerHumanFeedbackTest.php` — contrat
  `{text, ai_interaction_id}`, `method_code` en métadonnées (4 méthodes +
  `null`, note sans clé, aucune colonne nouvelle, ledger identique), création
  / upsert du feedback, contrôle d'accès (tenant, droits, interaction d'un
  autre tenant / article / surface → 404), rétention en cascade (interaction,
  acteur, tenant), schéma fermé, registre de cycle de vie.

## Feedback humain V1 sur une réponse (TASK-1256)

Sous chaque bulle de réponse IA du dialogue Explorer : « Utile » / « À
améliorer », puis, après le clic, une disclosure facultative (pourquoi / quoi
améliorer ; quelle aurait été une meilleure intervention). Rien d'autre : ni
fine-tuning, ni apprentissage automatique, ni export. **Un verdict n'est pas
un consentement d'entraînement** (règle centrale de l'audit T1255).

- `chat()` renvoie `{text, ai_interaction_id}` — même contrat que
  `BlogAiService::methodSelection()` ; c'est cet id que le bloc référence.
- Table fille `ai_interaction_feedbacks` (`App\Models\AiInteractionFeedback`) :
  `ai_interaction_id` FK `ai_interactions` **CASCADE** (le feedback hérite
  exactement la rétention de l'interaction — registre `UserDataLifecycleRegistry`
  DELETE), `organization_id` copie explicite du tenant de l'interaction (FK
  CASCADE), `user_id` FK users CASCADE, `verdict` fermé `helpful | improve`,
  `comment` et `suggested_response` (le contenu de l'HUMAIN), timestamps,
  unique (`ai_interaction_id`, `user_id`). **Aucune** colonne export /
  training / consent ; **aucune** copie de prompt / réponse (liste de colonnes
  fermée par test).
- `POST …/explorer/feedback` (`blog.explorer.feedback.store`, alias
  Organization) → `BlogExplorerController::storeFeedback()` : même contrôle
  d'accès que le dialogue (`canAccessPostExplorer` : auteur, co-auteur, admin
  plateforme du tenant de l'article), puis résolution de l'interaction SOUS
  SCOPE (tenant courant + `feature = blog_explorer` + `metadata.blog_post_id`
  = l'article) — 404 propre sinon, rien d'écrit, rien de révélé. Un feedback
  par (interaction, acteur) : le redonner met à jour la même ligne.
- UI : message deep-chat `html` attaché à la réponse (texte conservé pour
  l'historique), boutons reliés par `htmlClassUtilities`, styles dans
  `auxiliaryStyle` — aucune bibliothèque nouvelle. Un clic enregistre le
  verdict tout de suite ; le formulaire complète la même ligne.
- Attribution par méthode : `ai_interaction_feedbacks → ai_interactions.metadata.method_code`
  (révision volontaire de la décision T1249, métadonnées seulement).

Hors de cette V1 (TASKs dédiées futures) : identifiant de conversation /
index de tour, version `AdminAiPrompt` dans la trace, toute file de relecture,
export, agrégation cross-tenant, extension à ChatLoop / agent de profil,
modèle de consentement.

## Hors périmètre V1

Note d'analyse (`generateNote`) non affectée par la méthode ; suggestion sur
passage (`methodSelection`) inchangée ; pas de `method_code` dans le ledger
(la méthode n'influence que le prompt système et, depuis TASK-1256, une clé de
métadonnées de la trace produit) ; pas de migration vers `PromptRepository` /
Constitution / Doctrine / CapabilityRegistry (chemin hérité assumé,
T1247/T1248).
