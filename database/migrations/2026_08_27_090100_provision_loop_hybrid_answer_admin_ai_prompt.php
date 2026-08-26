<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TASK-1309 : provisioning deploy-safe du prompt `loop_hybrid_answer` v1 —
 * l'instruction du mode « IA + Dossiers ».
 *
 * AUCUN CHANGEMENT DE SCHEMA : une ligne de donnees dans `admin_ai_prompts`,
 * meme idiome que `loop_knowledge_answer` v1/v2/v3. Sans cette ligne, la
 * capability `loop_hybrid_answer` refuse explicitement de repondre
 * (`loops.knowledge_prompt_missing`) — aucun prompt metier n'est code en dur
 * dans le service.
 *
 * ## Pourquoi une instruction SEPAREE de `loop_knowledge_answer`
 *
 * Le mode Dossiers est un grounding STRICT : sans source, il refuse, et c'est
 * sa valeur. Le mode IA + Dossiers doit pouvoir apporter la connaissance
 * generale du modele quand les Dossiers ne disent rien — donc ne PAS appliquer
 * ce refus. Les deux instructions sont incompatibles : les loger dans le meme
 * prompt reproduirait exactement la contradiction que la v3 de
 * `loop_knowledge_answer` corrige par ailleurs.
 *
 * ## L'invariant que ce prompt doit tenir
 *
 * Trois couches ne doivent JAMAIS se confondre : le fil de la conversation,
 * la connaissance generale du modele, les Dossiers de la Boucle. Une
 * reference [Mn]/[Sn] n'appuie qu'une affirmation documentaire. Le code garde
 * cette propriete independamment du prompt — `citedSources()` n'accepte que
 * les references REELLEMENT fournies — mais le prompt doit aussi la porter,
 * pour que la reponse soit lisible, pas seulement sure.
 *
 * Idempotent : n'insere que si cette VERSION precise est absente.
 */
return new class extends Migration
{
    private const SCENARIO = 'loop_hybrid_answer';

    private const VERSION = 1;

    private const PROMPT = <<<'PROMPT'
Tu réponds à la question d'un membre de BouclePro en CROISANT deux natures de savoir, sans jamais les confondre :
- tes connaissances générales de modèle de langage ;
- les connaissances documentaires de sa Boucle, fournies ci-dessus sous deux familles de références : les ELEMENTS DU DOSSIER ([M1], [M2], ...) qui attestent l'existence, le nom et le type d'un Article ou d'un Fichier — jamais son contenu — et les SOURCES DOCUMENTAIRES ([S1], [S2], ...) qui sont des extraits réels du contenu de ces documents.

Règles :
- Une référence entre crochets n'appuie QUE des affirmations documentaires. Cite [Sn] juste après une affirmation sur ce qu'un document dit ; cite [Mn] juste après une affirmation sur l'existence d'un document. Ne cite jamais une référence qui ne figure pas dans les sources fournies.
- N'attache JAMAIS de référence à une affirmation qui vient de tes connaissances générales, ni à ce qui a été dit plus haut dans la conversation. Une connaissance générale n'est pas une source documentaire, et un échange précédent n'en est pas une non plus.
- Commence par ce que disent les Dossiers quand ils disent quelque chose, avec leurs références ; ajoute ensuite, séparément et sans référence, ce que tu apportes comme connaissance générale. Le membre doit toujours pouvoir distinguer les deux d'un coup d'œil — par exemple « D'après vos Dossiers, ... [S1]. En complément, et sans que vos Dossiers le documentent, ... ».
- Si aucun élément des Dossiers accessibles ne concerne la question, dis-le explicitement en une phrase — « Les Dossiers accessibles de cette Boucle n'apportent rien sur ce point. » — puis réponds quand même depuis tes connaissances générales, sans aucune référence. N'invente jamais de référence pour habiller une réponse générale.
- Si les Dossiers contredisent ou nuancent ce que tu sais, signale-le : ce sont les documents du membre qui font autorité chez lui.
- N'invente aucune citation, aucun chiffre attribué à un document, aucun nom de fichier. Ne présente jamais une connaissance générale comme provenant des sources.
- Réponds dans la langue de la question, en Markdown léger sans titres, au plus 10 phrases.
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
                'name' => 'Réponse croisée IA + Dossiers (Boucle) — v1',
                'description' => 'Prompt du mode « IA + Dossiers » : croise connaissance générale et connaissances documentaires de la Boucle, avec citations [Mn]/[Sn] réservées aux seules affirmations documentaires.',
                'prompt_text' => self::PROMPT,
                'version' => self::VERSION,
                'is_active' => true,
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
