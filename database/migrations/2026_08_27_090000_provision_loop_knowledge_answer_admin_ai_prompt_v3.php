<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * TASK-1309 : v3 du prompt `loop_knowledge_answer`.
 *
 * AUCUN CHANGEMENT DE SCHEMA. Cette migration insere UNE LIGNE DE DONNEES —
 * c'est le seul chemin de provisioning deploy-safe d'un prompt administrable
 * dans ce depot (idiome etabli par la v1, migration 2026_08_15_210000, et la
 * v2, 2026_08_26_090000) : `AdminAiPrompt` est une table de contenu editable,
 * les seeders ne tournent pas au deploiement.
 *
 * ## Le bug corrige
 *
 * La v2 declare deux regles NON EXCLUSIVES :
 *  - « Si aucune source fournie ne permet de repondre, reponds exactement :
 *    "Je n'ai pas trouve cette information..." » ;
 *  - « Pour une question d'inventaire, enumere TOUS les elements du
 *    DOSSIER... ».
 *
 * Un modele peut appliquer les deux. Reproduit REELLEMENT le 2026-08-26 sur
 * l'organisation `test20260822`, Boucle `01-COMMUNICATION`, question « Que
 * contiennent les dossiers ? » -> reponse auto-contradictoire : « Je n'ai pas
 * trouve cette information dans les sources auxquelles j'ai acces. [...] Voici
 * la liste des elements du dossier : - Cadre du dialogue [M1] - ... ».
 * L'interaction correspondante porte 6 references [Mn] consultees et ZERO
 * [Sn] : le refus etait declenche par l'absence d'extraits documentaires,
 * alors meme que le manifest permettait de repondre.
 *
 * ## Ce que la v3 change, et rien d'autre
 *
 * 1. le refus devient conditionne a « NI [Mn] NI [Sn] » — jamais a la seule
 *    absence de [Sn] — et il est explicitement INTERDIT de le combiner avec
 *    une reponse dans la meme sortie ;
 * 2. une regle dediee aux questions PANORAMIQUES (« que contiennent les
 *    dossiers ? », « de quoi parlent les documents ? ») : s'appuyer sur les
 *    [Sn] pour dire de quoi traitent les documents, sur les [Mn] pour dire
 *    lesquels existent. Le complement de vue d'ensemble de TASK-1309
 *    (`DossierRetrievalSource`) garantit que des [Sn] representatifs de
 *    PLUSIEURS documents sont desormais disponibles pour ces questions.
 *
 * Le grounding reste STRICT : aucune invention, aucune reference hors
 * provenance, [Mn] ne decrit jamais un contenu.
 *
 * Idempotent comme v1/v2 : n'insere que si cette VERSION precise est absente,
 * et devient la version active du scenario (les autres sont desactivees,
 * jamais supprimees ni modifiees — une ligne administrable reste modifiable
 * par un admin apres deploiement, TASK-1211).
 */
return new class extends Migration
{
    private const SCENARIO = 'loop_knowledge_answer';

    private const VERSION = 3;

    private const PROMPT = <<<'PROMPT'
Tu réponds à la question d'un membre de BouclePro à partir de deux familles de sources fournies, qui viennent des Dossiers de son Organization auxquels il a accès :
- les ELEMENTS DU DOSSIER (références [M1], [M2], ...) : une liste de métadonnées — l'existence, le nom et le type de chaque Article ou Fichier accessible dans cette Boucle. Ces références ne donnent AUCUNE information sur le contenu de ces documents.
- les SOURCES DOCUMENTAIRES (références [S1], [S2], ...) : des extraits réels du contenu de certains de ces documents.

Règles :
- Chaque affirmation, y compris dans une liste à puces, doit être suivie IMMÉDIATEMENT de sa référence entre crochets — jamais une référence isolée en fin de réponse. Exemple : « - 01-Manifeste v1.pdf — Fichier PDF [M3] ».
- Pour affirmer qu'un fichier ou un article existe ou fait partie de cette Boucle, cite sa référence [Mn] juste après l'avoir mentionné. N'utilise jamais une référence [Mn] pour prétendre connaître ou décrire le contenu du document qu'elle désigne.
- Pour affirmer ce qu'un document dit ou contient, cite sa référence [Sn] juste après l'affirmation qu'elle appuie. N'utilise jamais une référence [Sn] pour prétendre qu'elle constitue, à elle seule, l'inventaire complet des documents de la Boucle.
- Pour une question d'inventaire (« quels fichiers ? », « quels documents ? »), énumère TOUS les éléments du DOSSIER qui correspondent à la demande, dans la limite de ce qui t'est fourni — ne raccourcis jamais une liste légitime pour respecter une contrainte de longueur, et ne cite jamais un élément qui ne correspond pas à la demande (par exemple une image si la question porte uniquement sur des PDF ou des fichiers Markdown).
- Pour une question d'ensemble (« que contiennent les dossiers ? », « de quoi parlent les documents ? », « résume les principaux sujets »), produis une VRAIE vue d'ensemble : dis de quoi traite chaque document dont tu as un extrait, en citant son [Sn], et complète avec les éléments dont tu n'as que l'existence, en citant leur [Mn]. Ne te contente jamais de compter ou d'énumérer les fichiers quand des extraits te sont fournis.
- N'invente aucune information, aucun chiffre, aucun nom, aucune citation. N'ajoute pas de connaissance générale présentée comme provenant des sources. Ne cite jamais une référence qui ne figure pas dans les sources fournies.
- Ne réponds « Je n'ai pas trouvé cette information dans les sources auxquelles j'ai accès. » QUE si AUCUNE source ne te permet de répondre — ni élément [Mn], ni extrait [Sn]. Cette phrase est alors ta réponse ENTIÈRE : ne la fais jamais suivre d'une liste, d'un inventaire ou d'une explication qui répondrait quand même à la question. Si tu es capable d'énumérer ou de décrire quoi que ce soit à partir des sources fournies, alors tu as trouvé quelque chose : réponds, sans employer cette phrase.
- Si les sources ne répondent que partiellement, dis clairement ce qui est documenté et ce qui ne l'est pas — sans refus préalable.
- Réponds dans la langue de la question, en Markdown léger sans titres. Pour une réponse en prose, vise au plus 6 phrases ; une liste nécessaire à un inventaire complet ou à une vue d'ensemble peut contenir tous les éléments autorisés sans être tronquée pour respecter cette limite.
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
                'name' => 'Réponse documentaire sourcée (Boucle) — v3',
                'description' => 'Prompt RAG V3 : le refus « je n\'ai pas trouvé » est conditionné à l\'absence de TOUTE source ([Mn] comme [Sn]) et ne peut plus coexister avec une réponse ; règle dédiée aux questions d\'ensemble.',
                'prompt_text' => self::PROMPT,
                'version' => self::VERSION,
                'is_active' => true,
                'metadata' => null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

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
