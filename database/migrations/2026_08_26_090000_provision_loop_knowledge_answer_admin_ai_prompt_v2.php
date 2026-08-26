<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TASK-1307 (revue) : v2 du prompt `loop_knowledge_answer`.
 *
 * Pourquoi une v2 plutot qu'une modification de la v1 : la ligne v1
 * (migration 2026_08_15_210000) est une donnee administrable — un admin a pu
 * la personnaliser, cette migration ne la touche jamais (meme regle que
 * TASK-1211/1213). La v2 distingue deux familles de references desormais
 * fournies au modele : [Mn] (`dossier.manifest`, TASK-1307) atteste
 * l'EXISTENCE d'un element du Dossier sans jamais en decrire le contenu ;
 * [Sn] (`dossier.retrieval`, inchange) cite un extrait documentaire reel. La
 * v1 ne connaissait que [Sn] et limitait toute reponse a six phrases — une
 * limite qui, appliquee sans distinction a une enumeration structurelle
 * legitime (« quels fichiers ? »), pouvait tronquer un inventaire complet
 * alors que rien n'imposait cette coupe (constat TASK-1307 : reponse reelle
 * a 3 elements sur 4). La v2 garde le grounding STRICT (aucune invention,
 * aucune reference hors provenance, refus explicite si rien n'est
 * disponible) et ne relache que la forme de la reponse pour un inventaire.
 *
 * Idempotent comme la v1 : n'insere que si cette VERSION precise est absente.
 * Devient la version active de ce scenario (la v1 est desactivee, jamais
 * supprimee ni modifiee) — c'est une vraie promotion de prompt, pas une
 * insertion inerte en attente d'activation manuelle : le bug qu'elle corrige
 * (troncature d'inventaire) est deja en production des que loop_knowledge_answer
 * repond.
 */
return new class extends Migration
{
    private const SCENARIO = 'loop_knowledge_answer';

    private const VERSION = 2;

    private const PROMPT = <<<'PROMPT'
Tu réponds à la question d'un membre de BouclePro à partir de deux familles de sources fournies, qui viennent des Dossiers de son Organization auxquels il a accès :
- les ELEMENTS DU DOSSIER (références [M1], [M2], ...) : une liste de métadonnées — l'existence, le nom et le type de chaque Article ou Fichier accessible dans cette Boucle. Ces références ne donnent AUCUNE information sur le contenu de ces documents.
- les SOURCES DOCUMENTAIRES (références [S1], [S2], ...) : des extraits réels du contenu de certains de ces documents.

Règles :
- Chaque affirmation, y compris dans une liste à puces, doit être suivie IMMÉDIATEMENT de sa référence entre crochets — jamais une référence isolée en fin de réponse. Exemple : « - 01-Manifeste v1.pdf — Fichier PDF [M3] ».
- Pour affirmer qu'un fichier ou un article existe ou fait partie de cette Boucle, cite sa référence [Mn] juste après l'avoir mentionné. N'utilise jamais une référence [Mn] pour prétendre connaître ou décrire le contenu du document qu'elle désigne.
- Pour affirmer ce qu'un document dit ou contient, cite sa référence [Sn] juste après l'affirmation qu'elle appuie. N'utilise jamais une référence [Sn] pour prétendre qu'elle constitue, à elle seule, l'inventaire complet des documents de la Boucle.
- Pour une question d'inventaire (« quels fichiers ? », « quels documents ? »), énumère TOUS les éléments du DOSSIER qui correspondent à la demande, dans la limite de ce qui t'est fourni — ne raccourcis jamais une liste légitime pour respecter une contrainte de longueur, et ne cite jamais un élément qui ne correspond pas à la demande (par exemple une image si la question porte uniquement sur des PDF ou des fichiers Markdown).
- N'invente aucune information, aucun chiffre, aucun nom, aucune citation. N'ajoute pas de connaissance générale présentée comme provenant des sources. Ne cite jamais une référence qui ne figure pas dans les sources fournies.
- Si aucune source fournie ne permet de répondre, réponds exactement : « Je n'ai pas trouvé cette information dans les sources auxquelles j'ai accès. » puis, si utile, indique en une phrase ce que les sources abordent réellement.
- Si les sources ne répondent que partiellement, dis clairement ce qui est documenté et ce qui ne l'est pas.
- Réponds dans la langue de la question, en Markdown léger sans titres. Pour une réponse en prose, vise au plus 6 phrases ; une liste nécessaire à un inventaire complet peut contenir tous les éléments autorisés sans être tronquée pour respecter cette limite.
- Tu ne crées, ne modifies et ne publies rien : tu informes, la personne décide.
PROMPT;

    public function up(): void
    {
        DB::transaction(function (): void {
            $versionExists = DB::table('admin_ai_prompts')
                ->where('scenario_id', self::SCENARIO)
                ->where('version', self::VERSION)
                ->exists();

            if ($versionExists) {
                return;
            }

            $timestamp = now();

            DB::table('admin_ai_prompts')->insert([
                'id' => (string) Str::uuid(),
                'scenario_id' => self::SCENARIO,
                'name' => 'Réponse documentaire sourcée (Boucle) — v2',
                'description' => 'Prompt RAG V2 : distingue [Mn] (inventaire du Dossier, dossier.manifest) et [Sn] (extraits documentaires, dossier.retrieval) ; une enumeration d\'inventaire n\'est plus tronquee par la limite de concision.',
                'prompt_text' => self::PROMPT,
                'version' => self::VERSION,
                'is_active' => true,
                'metadata' => null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            // v2 devient LA version active de ce scenario : la v1 (et toute
            // autre version) est desactivee, jamais supprimee ni modifiee —
            // une ligne administrable reste modifiable par un admin apres
            // deploiement (TASK-1211), cette migration ne fait que changer
            // laquelle repond par defaut.
            DB::table('admin_ai_prompts')
                ->where('scenario_id', self::SCENARIO)
                ->where('version', '!=', self::VERSION)
                ->update(['is_active' => false, 'updated_at' => $timestamp]);
        });
    }

    public function down(): void
    {
        // No-op volontaire : ligne administrable apres deploiement (voir
        // TASK-1211). Toute correction est forward-only.
    }
};
