<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TASK-1213 : provisioning deploy-safe du prompt `loop_knowledge_answer` v1
 * (reponse documentaire sourcee). Meme regle que TASK-1211 : une ligne deja
 * presente est une donnee administrable, la migration n'y retouche jamais ;
 * seul un scenario totalement absent est active automatiquement.
 */
return new class extends Migration
{
    private const SCENARIO = 'loop_knowledge_answer';

    private const VERSION = 1;

    /**
     * Copie volontairement immuable du prompt v1.
     */
    private const PROMPT = <<<'PROMPT'
Tu réponds à la question d'un membre de BouclePro UNIQUEMENT à partir des SOURCES DOCUMENTAIRES fournies, qui viennent des Dossiers de son Organization auxquels il a accès.

Règles :
- Appuie chaque affirmation sur une source en citant sa référence entre crochets, par exemple [S1] ou [S2]. Ne cite jamais une référence qui ne figure pas dans les sources fournies.
- N'invente aucune information, aucun chiffre, aucun nom, aucune citation. N'ajoute pas de connaissance générale présentée comme provenant des sources.
- Si les sources ne permettent pas de répondre, réponds exactement : « Je n'ai pas trouvé cette information dans les sources auxquelles j'ai accès. » puis, si utile, indique en une phrase ce que les sources abordent réellement.
- Si les sources ne répondent que partiellement, dis clairement ce qui est documenté et ce qui ne l'est pas.
- Réponds dans la langue de la question, de manière concise (au plus 6 phrases), en Markdown léger sans titres.
- Tu ne crées, ne modifies et ne publies rien : tu informes, la personne décide.
PROMPT;

    public function up(): void
    {
        DB::transaction(function (): void {
            $scenarioExists = DB::table('admin_ai_prompts')
                ->where('scenario_id', self::SCENARIO)
                ->exists();

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
                'name' => 'Réponse documentaire sourcée (Boucle) — v1',
                'description' => 'Prompt RAG V1 : répondre uniquement à partir des sources documentaires autorisées, avec citations [Sn].',
                'prompt_text' => self::PROMPT,
                'version' => self::VERSION,
                'is_active' => ! $scenarioExists,
                'metadata' => null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        });
    }

    public function down(): void
    {
        // No-op volontaire : ligne administrable apres deploiement (voir
        // TASK-1211). Toute correction est forward-only.
    }
};
